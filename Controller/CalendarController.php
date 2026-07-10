<?php

namespace Kanboard\Plugin\CalendarPlugin\Controller;

use Kanboard\Controller\BaseController;

class CalendarController extends BaseController
{
    /**
     * Global calendar page (all of the user's accessible projects).
     */
    public function show()
    {
        $userId = $this->userSession->getId();

        // Admins see all active projects; other users see only their accessible projects.
        if ($this->userModel->isAdmin($userId)) {
            $projectRows = $this->db->table(\Kanboard\Model\ProjectModel::TABLE)
                ->eq('is_active', \Kanboard\Model\ProjectModel::ACTIVE)
                ->columns('id', 'name')
                ->orderBy('name')
                ->findAll();
            $projects = array();
            foreach ($projectRows as $row) {
                $projects[(int) $row['id']] = $row['name'];
            }
        } else {
            $projects = $this->projectUserRoleModel->getActiveProjectsByUser($userId);
        }

        $this->response->html($this->helper->layout->app('CalendarPlugin:calendar/index', array(
            'title'          => t('Calendar'),
            'project_id'     => 0,
            'events_url'     => $this->helper->url->to('CalendarController', 'events', array('plugin' => 'CalendarPlugin')),
            'update_url'     => $this->helper->url->to('CalendarController', 'updateDate', array('plugin' => 'CalendarPlugin')),
            'unscheduled_url'=> $this->helper->url->to('CalendarController', 'unscheduled', array('plugin' => 'CalendarPlugin')),
            'csrf'           => $this->token->getReusableCSRFToken(),
            'projects'       => $projects,
            'users'          => $this->assigneeChoices($userId),
            'categories'     => array(),
        )));
    }

    /**
     * Per-project calendar page (scoped to one project, access-checked).
     */
    public function project()
    {
        $project = $this->getProject(); // BaseController helper: loads + access-checks by project_id
        $this->response->html($this->helper->layout->app('CalendarPlugin:calendar/index', array(
            'title'           => $project['name'] . ' &gt; ' . t('Calendar'),
            'project_id'      => (int) $project['id'],
            'projects'        => array(),
            'users'           => $this->projectUserRoleModel->getAssignableUsersList($project['id']),
            'categories'      => $this->categoryModel->getList($project['id'], false),
            'events_url'      => $this->helper->url->to('CalendarController', 'events', array('plugin' => 'CalendarPlugin')),
            'update_url'      => $this->helper->url->to('CalendarController', 'updateDate', array('plugin' => 'CalendarPlugin')),
            'unscheduled_url' => $this->helper->url->to('CalendarController', 'unscheduled', array('plugin' => 'CalendarPlugin')),
            'csrf'            => $this->token->getReusableCSRFToken(),
        )));
    }

    /**
     * POST — reschedule a task's due date. (calendar.updateTaskDate)
     *
     * Uses a reusable CSRF token (pcsrf), NOT the one-time token, so we must
     * read the raw POST values via getRawValue() instead of getValues() — the
     * latter runs a one-time CSRF check internally and returns [] on failure.
     */
    public function updateDate()
    {
        $csrfToken = $this->request->getRawValue('csrf_token');

        if (! $csrfToken || ! $this->token->validateReusableCSRFToken($csrfToken)) {
            $this->response->status(403);
            return $this->response->json(array('result' => false, 'error' => 'csrf'));
        }

        $taskId = (int) ($this->request->getRawValue('task_id') ?: 0);
        $dueRaw = (string) ($this->request->getRawValue('date_due') ?: '');

        // Missing / non-numeric task id is a malformed request (400), distinct
        // from a well-formed request the user isn't allowed to make (403).
        if ($taskId <= 0) {
            $this->response->status(400);
            return $this->response->json(array('result' => false, 'error' => 'task_id'));
        }

        // Permission: admins may reschedule any task; other users must hold a
        // write-capable role on the task's project (PROJECT_VIEWER / non-members
        // rejected). A missing task resolves to project 0 → denied. Kept 403 for
        // both "no such task" and "not allowed" so we don't leak task existence.
        $projectId = $this->taskFinderModel->getProjectId($taskId);
        if (! $this->container['calendarQueryModel']->canUserReschedule($this->userSession->getId(), $projectId)) {
            $this->response->status(403);
            return $this->response->json(array('result' => false, 'error' => 'forbidden'));
        }

        $ts = is_numeric($dueRaw) ? (int) $dueRaw : (int) strtotime($dueRaw);
        if ($ts <= 0) {
            $this->response->status(400);
            return $this->response->json(array('result' => false, 'error' => 'date'));
        }

        $ok = $this->taskModificationModel->update(array('id' => $taskId, 'date_due' => $ts));
        return $this->response->json(array('result' => (bool) $ok));
    }

    /**
     * JSON list of open tasks with no due date for the unscheduled sidebar.
     */
    public function unscheduled()
    {
        $filters = array('project_ids' => $this->intList($this->request->getStringParam('project_ids')));
        $this->response->json($this->container['calendarQueryModel']->getUnscheduled($this->userSession->getId(), $filters));
    }

    /**
     * JSON feed of FullCalendar events for the visible range + filters.
     * (calendar.getEvents)
     */
    public function events()
    {
        $userId = $this->userSession->getId();
        $start  = $this->parseDate($this->request->getStringParam('start'), strtotime('-1 month'));
        $end    = $this->parseDate($this->request->getStringParam('end'), strtotime('+2 month'));

        $filters = array(
            'project_ids'    => $this->intList($this->request->getStringParam('project_ids')),
            'assignee_id'    => $this->resolveAssignee($this->request->getStringParam('assignee_id'), $userId),
            'category_id'    => (int) $this->request->getIntegerParam('category_id'),
            'column_id'      => (int) $this->request->getIntegerParam('column_id'),
            'hide_completed' => $this->request->getStringParam('hide_completed') === '1',
        );

        $events = $this->container['calendarQueryModel']->getEvents($userId, $filters, $start, $end);
        $this->response->json($events);
    }

    private function parseDate($value, $default)
    {
        if (empty($value)) { return $default; }
        $ts = strtotime($value);
        return $ts !== false ? $ts : $default;
    }

    private function intList($value)
    {
        if (empty($value)) { return array(); }
        return array_values(array_filter(array_map('intval', explode(',', $value))));
    }

    /**
     * Assignee choices for the GLOBAL calendar's filter. Admins get the full
     * active-user list (they can see every project anyway); other users get only
     * the users assignable on projects they can access — the global page must not
     * expose the entire user directory. (The template already offers All + Me.)
     *
     * @param  int $userId
     * @return array<int,string> id => name
     */
    private function assigneeChoices($userId)
    {
        if ($this->userModel->isAdmin($userId)) {
            return $this->userModel->getActiveUsersList();
        }

        $users = array();
        foreach ($this->container['calendarQueryModel']->accessibleProjectIds($userId) as $projectId) {
            // $unassigned = false: the template supplies its own All/Me options.
            foreach ($this->projectUserRoleModel->getAssignableUsersList($projectId, false) as $id => $name) {
                if ((int) $id > 0) {
                    $users[(int) $id] = $name;
                }
            }
        }

        return $users;
    }

    /** '-1' (or 'me') means the current user; '' means no assignee filter. */
    private function resolveAssignee($value, $userId)
    {
        if ($value === 'me' || $value === '-1') { return (int) $userId; }
        return (int) $value;
    }
}
