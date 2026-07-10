<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\CalendarPlugin\Controller\CalendarController;
use Kanboard\Plugin\CalendarPlugin\Model\CalendarQueryModel;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\ProjectUserRoleModel;
use Kanboard\Model\TaskCreationModel;
use Kanboard\Model\UserModel;
use Kanboard\Core\Security\Role;
use KanboardTests\units\Base;

class CalendarControllerTest extends Base
{
    public function testEventsReturnsScopedJson()
    {
        $projectModel = new ProjectModel($this->container);
        $taskCreation = new TaskCreationModel($this->container);
        $pid = $projectModel->create(array('name' => 'CtrlP'));
        $due = mktime(12, 0, 0, (int) date('n'), 10);
        $tid = $taskCreation->create(array('project_id' => $pid, 'title' => 'E1', 'date_due' => $due));

        // The controller delegates to calendarQueryModel->getEvents; assert the
        // model contract returns the expected task for an admin user (id 1).
        $model = new CalendarQueryModel($this->container);
        $events = $model->getEvents(
            1, array(),
            mktime(0, 0, 0, (int) date('n'), 1),
            mktime(0, 0, 0, (int) date('n') + 1, 1)
        );
        $ids = array_column($events, 'id');
        $this->assertContains($tid, $ids);
    }

    public function testUpdateDatePersistsDueDateForAccessibleTask()
    {
        $projectModel = new \Kanboard\Model\ProjectModel($this->container);
        $taskCreation = new \Kanboard\Model\TaskCreationModel($this->container);
        $taskFinder   = new \Kanboard\Model\TaskFinderModel($this->container);
        $pid = $projectModel->create(array('name' => 'UD'));
        $tid = $taskCreation->create(array('project_id' => $pid, 'title' => 'move me', 'date_due' => mktime(12,0,0,(int)date('n'),5)));

        $newTs = mktime(0, 0, 0, (int) date('n'), 20);
        $ok = $this->container['taskModificationModel']->update(array('id' => $tid, 'date_due' => $newTs));
        $this->assertTrue($ok);

        $task = $taskFinder->getById($tid);
        $this->assertSame((int) $newTs, (int) $task['date_due']);
    }

    /**
     * Reschedule authorization gate (the C1 fix): the updateDate controller
     * delegates the decision to CalendarQueryModel::canUserReschedule, so we
     * cover every role here — a read-only viewer and a non-member must be
     * denied; write-capable members/managers and admins allowed. This is the
     * path the whole-branch review found unit-untested.
     */
    public function testCanUserRescheduleEnforcesWriteRole()
    {
        $userModel = new UserModel($this->container);
        $projectModel = new ProjectModel($this->container);
        $pur = new ProjectUserRoleModel($this->container);
        $model = new CalendarQueryModel($this->container);

        $pid = $projectModel->create(array('name' => 'AuthP'));

        $viewerId  = $userModel->create(array('username' => 'cal_viewer',  'password' => 'test1234'));
        $memberId  = $userModel->create(array('username' => 'cal_member',  'password' => 'test1234'));
        $managerId = $userModel->create(array('username' => 'cal_manager', 'password' => 'test1234'));
        $strangerId = $userModel->create(array('username' => 'cal_stranger', 'password' => 'test1234'));

        $pur->addUser($pid, $viewerId, Role::PROJECT_VIEWER);
        $pur->addUser($pid, $memberId, Role::PROJECT_MEMBER);
        $pur->addUser($pid, $managerId, Role::PROJECT_MANAGER);

        // Read-only viewer and non-member are denied.
        $this->assertFalse($model->canUserReschedule($viewerId, $pid), 'viewer must not reschedule');
        $this->assertFalse($model->canUserReschedule($strangerId, $pid), 'non-member must not reschedule');

        // Write-capable members/managers are allowed.
        $this->assertTrue($model->canUserReschedule($memberId, $pid), 'member may reschedule');
        $this->assertTrue($model->canUserReschedule($managerId, $pid), 'manager may reschedule');

        // Admin (user id 1 in the test fixture) may reschedule any task.
        $this->assertTrue($model->canUserReschedule(1, $pid), 'admin may reschedule');
    }

    /**
     * A missing / invalid project id (e.g. a non-existent task_id resolving to
     * project 0) is denied outright, without consulting roles.
     */
    public function testCanUserRescheduleDeniesInvalidProject()
    {
        $model = new CalendarQueryModel($this->container);
        $this->assertFalse($model->canUserReschedule(1, 0));
        $this->assertFalse($model->canUserReschedule(1, -5));
    }
}
