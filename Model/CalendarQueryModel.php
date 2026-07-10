<?php

namespace Kanboard\Plugin\CalendarPlugin\Model;

use Kanboard\Core\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Model\TaskModel;

class CalendarQueryModel extends Base
{
    /**
     * Whether a user may reschedule (write the due date of) a task in the given
     * project. Admins may reschedule any task; other users must hold a
     * write-capable role (PROJECT_MEMBER or PROJECT_MANAGER) — PROJECT_VIEWER
     * and non-members are denied. Mirrors core's task-modification ACL, which a
     * hand-rolled write route would otherwise bypass.
     *
     * @param  int $userId
     * @param  int $projectId
     * @return bool
     */
    public function canUserReschedule($userId, $projectId)
    {
        if ((int) $projectId <= 0) {
            return false;
        }

        if ($this->userModel->isAdmin($userId)) {
            return true;
        }

        $role = $this->projectUserRoleModel->getUserRole($projectId, $userId);

        return $role === Role::PROJECT_MEMBER || $role === Role::PROJECT_MANAGER;
    }

    /**
     * @return int[] project ids the user may access
     */
    public function accessibleProjectIds($userId)
    {
        // App administrators can see all active projects (disabled projects excluded).
        if ($this->userModel->isAdmin($userId)) {
            return array_map('intval', $this->db->table(\Kanboard\Model\ProjectModel::TABLE)
                ->eq('is_active', \Kanboard\Model\ProjectModel::ACTIVE)
                ->findAllByColumn('id'));
        }

        return array_map('intval', array_keys($this->projectUserRoleModel->getActiveProjectsByUser($userId)));
    }

    /**
     * @return array[] FullCalendar event objects
     */
    public function getEvents($userId, array $filters, $rangeStart, $rangeEnd)
    {
        $projectIds = $this->accessibleProjectIds($userId);

        // If a project filter is set, intersect with what the user may access.
        if (! empty($filters['project_ids'])) {
            $projectIds = array_values(array_intersect($projectIds, array_map('intval', $filters['project_ids'])));
        }

        // Guard: empty id list must NOT match the whole table (PicoDb in([]) footgun).
        if (empty($projectIds)) {
            return array();
        }

        $query = $this->taskFinderModel->getExtendedQuery()
            ->in(TaskModel::TABLE.'.project_id', $projectIds)
            ->neq(TaskModel::TABLE.'.date_due', 0)
            ->gte(TaskModel::TABLE.'.date_due', $rangeStart)
            ->lt(TaskModel::TABLE.'.date_due', $rangeEnd);

        if (! empty($filters['assignee_id'])) {
            $query->eq(TaskModel::TABLE.'.owner_id', (int) $filters['assignee_id']);
        }
        if (! empty($filters['category_id'])) {
            $query->eq(TaskModel::TABLE.'.category_id', (int) $filters['category_id']);
        }
        if (! empty($filters['column_id'])) {
            $query->eq(TaskModel::TABLE.'.column_id', (int) $filters['column_id']);
        }
        if (! empty($filters['hide_completed'])) {
            $query->eq(TaskModel::TABLE.'.is_active', TaskModel::STATUS_OPEN);
        }

        $rows = $query->findAll();
        $events = array();
        $rowsById = array();
        foreach ($rows as $row) {
            $event = $this->mapRowToEvent($row);
            $events[] = $event;
            $rowsById[$event['id']] = $row;
        }

        // Generic extension point: other suite plugins (e.g. DependencyPlugin)
        // may register decorators on the container to push badges onto events.
        // Read defensively — absent when no other plugin is installed, and
        // behavior must be IDENTICAL to before this feature in that case.
        $decorators = isset($this->container['calendarEventDecorators']) ? $this->container['calendarEventDecorators'] : array();
        foreach ($events as $i => $event) {
            if (! isset($event['extendedProps']['badges'])) {
                $events[$i]['extendedProps']['badges'] = array();
            }
            foreach ($decorators as $decorator) {
                $events[$i] = call_user_func($decorator, $events[$i], $rowsById[$event['id']]);
            }
        }

        return $events;
    }

    /**
     * @return array[] [['id','title','color','project'], …] for open tasks with no due date
     */
    public function getUnscheduled($userId, array $filters)
    {
        $projectIds = $this->accessibleProjectIds($userId);
        if (! empty($filters['project_ids'])) {
            $projectIds = array_values(array_intersect($projectIds, array_map('intval', $filters['project_ids'])));
        }
        if (empty($projectIds)) { return array(); }

        $rows = $this->taskFinderModel->getExtendedQuery()
            ->in(TaskModel::TABLE.'.project_id', $projectIds)
            ->beginOr()
            ->isNull(TaskModel::TABLE.'.date_due')
            ->eq(TaskModel::TABLE.'.date_due', 0)
            ->closeOr()
            ->eq(TaskModel::TABLE.'.is_active', TaskModel::STATUS_OPEN)
            ->findAll();

        $out = array();
        foreach ($rows as $row) {
            $out[] = array(
                'id'      => (int) $row['id'],
                'title'   => $row['title'],
                'color'   => $this->colorModel->getBackgroundColor($row['color_id']),
                'project' => isset($row['project_name']) ? $row['project_name'] : '',
            );
        }
        return $out;
    }

    private function mapRowToEvent(array $row)
    {
        $due       = (int) $row['date_due'];
        $estimate  = (float) $row['time_estimated'];
        $todayMidnight = mktime(0, 0, 0);
        $overdue = $due > 0 && $due < $todayMidnight && (int) $row['date_completed'] === 0 && (int) $row['is_active'] === TaskModel::STATUS_OPEN;

        if ($estimate > 0) {
            $start = $due;
            // If the due time is midnight, default the block to 09:00 for a sane timed event.
            if ((int) date('H', $due) === 0 && (int) date('i', $due) === 0) {
                $start = mktime(9, 0, 0, (int) date('n', $due), (int) date('j', $due), (int) date('Y', $due));
            }
            $end = $start + (int) round($estimate * 3600);
            $allDay = false;
            $startIso = date('c', $start);
            $endIso   = date('c', $end);
        } else {
            $allDay = true;
            $startIso = date('Y-m-d', $due);
            $endIso   = null;
        }

        $assignee = ! empty($row['assignee_name']) ? $row['assignee_name'] : (! empty($row['assignee_username']) ? $row['assignee_username'] : null);

        // getExtendedQuery() aliases: project_name = projects.name, column_name = columns.title
        return array(
            'id'    => (int) $row['id'],
            'title' => $row['title'],
            'start' => $startIso,
            'end'   => $endIso,
            'allDay'=> $allDay,
            'color' => $this->colorModel->getBackgroundColor($row['color_id']),
            'url'   => $this->helper->url->to('TaskViewController', 'show', array('task_id' => (int) $row['id'], 'project_id' => (int) $row['project_id'])),
            'extendedProps' => array(
                'project'  => isset($row['project_name']) ? $row['project_name'] : '',
                'column'   => isset($row['column_name']) ? $row['column_name'] : '',
                'assignee' => $assignee,
                'overdue'  => $overdue,
                'estimate' => $estimate,
            ),
        );
    }
}
