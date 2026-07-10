<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\CalendarPlugin\Model\CalendarQueryModel;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\TaskCreationModel;
use KanboardTests\units\Base;

class CalendarQueryModelTest extends Base
{
    private function seed()
    {
        $projectModel = new ProjectModel($this->container);
        $taskCreation = new TaskCreationModel($this->container);
        $pid = $projectModel->create(array('name' => 'Cal P1'));
        // admin user id is 1 in the test container; make admin a member implicitly (owner)
        $due = mktime(12, 0, 0, (int) date('n'), 15); // 15th of this month, noon
        $t1 = $taskCreation->create(array('project_id' => $pid, 'title' => 'With estimate', 'date_due' => $due, 'time_estimated' => 2));
        $t2 = $taskCreation->create(array('project_id' => $pid, 'title' => 'No estimate', 'date_due' => $due, 'time_estimated' => 0));
        return array($pid, $t1, $t2, $due);
    }

    public function testGetEventsMapsDueDateAndEstimate()
    {
        list($pid, $t1, $t2, $due) = $this->seed();
        $model = new CalendarQueryModel($this->container);
        $start = mktime(0, 0, 0, (int) date('n'), 1);
        $end   = mktime(0, 0, 0, (int) date('n') + 1, 1);

        $events = $model->getEvents(1, array(), $start, $end);
        $byId = array();
        foreach ($events as $e) { $byId[$e['id']] = $e; }

        $this->assertArrayHasKey($t1, $byId);
        $this->assertArrayHasKey($t2, $byId);
        // estimate>0 => timed, has end, not allDay
        $this->assertFalse($byId[$t1]['allDay']);
        $this->assertNotNull($byId[$t1]['end']);
        $this->assertEqualsWithDelta(2.0, $byId[$t1]['extendedProps']['estimate'], 0.001);
        // estimate==0 => allDay
        $this->assertTrue($byId[$t2]['allDay']);
        $this->assertNull($byId[$t2]['end']);
    }

    public function testGetEventsIsWindowedByDateRange()
    {
        list($pid, $t1, $t2, $due) = $this->seed();
        $model = new CalendarQueryModel($this->container);
        // A window in a different month must exclude both tasks.
        $start = mktime(0, 0, 0, (int) date('n') + 2, 1);
        $end   = mktime(0, 0, 0, (int) date('n') + 3, 1);
        $events = $model->getEvents(1, array(), $start, $end);
        $this->assertCount(0, $events);
    }

    public function testAccessibleProjectIdsExcludesForeignProjects()
    {
        list($pid, $t1, $t2, $due) = $this->seed();
        $model = new CalendarQueryModel($this->container);
        $ids = $model->accessibleProjectIds(1);
        $this->assertContains($pid, $ids);
    }

    public function testEmptyAccessibleProjectsYieldsNoEvents()
    {
        // user id 999 has no projects -> in([]) must NOT leak the whole table
        $this->seed();
        $model = new CalendarQueryModel($this->container);
        $start = mktime(0, 0, 0, (int) date('n'), 1);
        $end   = mktime(0, 0, 0, (int) date('n') + 1, 1);
        $events = $model->getEvents(999, array(), $start, $end);
        $this->assertCount(0, $events);
    }

    // D4: timed event end == start + 2h, ISO-8601 strings
    public function testTimedEventEndIsTwoHoursAfterStart()
    {
        list($pid, $t1, $t2, $due) = $this->seed();
        $model = new CalendarQueryModel($this->container);
        $start = mktime(0, 0, 0, (int) date('n'), 1);
        $end   = mktime(0, 0, 0, (int) date('n') + 1, 1);
        $events = $model->getEvents(1, array(), $start, $end);
        $byId = array();
        foreach ($events as $e) { $byId[$e['id']] = $e; }

        $e1 = $byId[$t1];
        // start and end are valid ISO-8601 strings
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $e1['start']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $e1['end']);
        // end == start + 2 hours
        $startTs = strtotime($e1['start']);
        $endTs   = strtotime($e1['end']);
        $this->assertEquals(7200, $endTs - $startTs, 'end must be exactly 2 hours after start');
    }

    // D4: midnight due + estimate defaults start to 09:00
    public function testMidnightDueWithEstimateDefaultsStartToNineAm()
    {
        $projectModel = new ProjectModel($this->container);
        $taskCreation = new TaskCreationModel($this->container);
        $pid = $projectModel->create(array('name' => 'Midnight Test'));
        $dueMidnight = mktime(0, 0, 0, (int) date('n'), 20);
        $tid = $taskCreation->create(array('project_id' => $pid, 'title' => 'Midnight task', 'date_due' => $dueMidnight, 'time_estimated' => 1));

        $model = new CalendarQueryModel($this->container);
        $start = mktime(0, 0, 0, (int) date('n'), 1);
        $end   = mktime(0, 0, 0, (int) date('n') + 1, 1);
        $events = $model->getEvents(1, array(), $start, $end);
        $byId = array();
        foreach ($events as $e) { $byId[$e['id']] = $e; }

        $this->assertArrayHasKey($tid, $byId, 'midnight task must appear in events');
        $startHour = (int) date('H', strtotime($byId[$tid]['start']));
        $this->assertEquals(9, $startHour, 'midnight due with estimate must default start to 09:00');
    }

    // D4: overdue open task has extendedProps.overdue === true; future task has overdue === false
    public function testOverdueFlagIsSetCorrectly()
    {
        $projectModel = new ProjectModel($this->container);
        $taskCreation = new TaskCreationModel($this->container);
        $pid = $projectModel->create(array('name' => 'Overdue Test'));

        // Past date: fixed Jan 15 2025 — always in the past regardless of when the suite runs.
        $pastDue   = mktime(12, 0, 0, 1, 15, 2025);
        // Future date: fixed Jan 15 next year — always in the future; deterministic regardless of today.
        $futureDue = mktime(12, 0, 0, 1, 15, (int) date('Y') + 1);

        $tOverdue = $taskCreation->create(array('project_id' => $pid, 'title' => 'Overdue task', 'date_due' => $pastDue));
        $tFuture  = $taskCreation->create(array('project_id' => $pid, 'title' => 'Future task',  'date_due' => $futureDue));

        $model = new CalendarQueryModel($this->container);
        // Wide window: from Jan 1 2025 through Mar 1 of next year — includes both seeded dates.
        $winStart = mktime(0, 0, 0, 1, 1, 2025);
        $winEnd   = mktime(0, 0, 0, 3, 1, (int) date('Y') + 1);

        $events = $model->getEvents(1, array(), $winStart, $winEnd);
        $byId = array();
        foreach ($events as $e) { $byId[$e['id']] = $e; }

        $this->assertArrayHasKey($tOverdue, $byId, 'overdue task must appear in events');
        $this->assertArrayHasKey($tFuture, $byId, 'future task must appear in events');
        $this->assertTrue($byId[$tOverdue]['extendedProps']['overdue'], 'overdue open task must have overdue===true');
        $this->assertFalse($byId[$tFuture]['extendedProps']['overdue'], 'future task must have overdue===false');
    }

    // T5-F1: project_ids filter restricts events to the specified project only
    public function testProjectFilterRestrictsEvents()
    {
        $projectModel = new ProjectModel($this->container);
        $taskCreation = new TaskCreationModel($this->container);

        $p1 = $projectModel->create(array('name' => 'FilterProj1'));
        $p2 = $projectModel->create(array('name' => 'FilterProj2'));
        $due = mktime(12, 0, 0, (int) date('n'), 15);
        $t1 = $taskCreation->create(array('project_id' => $p1, 'title' => 'Task P1', 'date_due' => $due));
        $t2 = $taskCreation->create(array('project_id' => $p2, 'title' => 'Task P2', 'date_due' => $due));

        $model = new CalendarQueryModel($this->container);
        $start = mktime(0, 0, 0, (int) date('n'), 1);
        $end   = mktime(0, 0, 0, (int) date('n') + 1, 1);

        // Filter to p1 only — admin user (id=1) can access both, but filter narrows to p1
        $events = $model->getEvents(1, array('project_ids' => array($p1)), $start, $end);
        $ids = array_column($events, 'id');
        $this->assertContains($t1, $ids, 'filtered project task must appear');
        $this->assertNotContains($t2, $ids, 'other project task must be excluded');
    }

    // T5-F2: assignee_id filter restricts events to assigned user only
    public function testAssigneeFilterRestrictsEvents()
    {
        $projectModel = new ProjectModel($this->container);
        $taskCreation = new TaskCreationModel($this->container);
        $userModel    = new \Kanboard\Model\UserModel($this->container);

        $uid = $userModel->create(array('username' => 'cal_assignee_filter', 'password' => 'test1234', 'role' => \Kanboard\Core\Security\Role::APP_USER));
        $pid = $projectModel->create(array('name' => 'AssigneeFilterProj'));
        $due = mktime(12, 0, 0, (int) date('n'), 15);
        $tAssigned   = $taskCreation->create(array('project_id' => $pid, 'title' => 'Assigned',   'date_due' => $due, 'owner_id' => $uid));
        $tUnassigned = $taskCreation->create(array('project_id' => $pid, 'title' => 'Unassigned', 'date_due' => $due));

        $model = new CalendarQueryModel($this->container);
        $start = mktime(0, 0, 0, (int) date('n'), 1);
        $end   = mktime(0, 0, 0, (int) date('n') + 1, 1);

        $events = $model->getEvents(1, array('assignee_id' => $uid), $start, $end);
        $ids = array_column($events, 'id');
        $this->assertContains($tAssigned, $ids, 'assigned task must appear');
        $this->assertNotContains($tUnassigned, $ids, 'unassigned task must be excluded');
    }

    // T5-F3: hide_completed=true excludes closed tasks
    public function testHideCompletedFilterExcludesClosedTasks()
    {
        $projectModel = new ProjectModel($this->container);
        $taskCreation = new TaskCreationModel($this->container);
        $taskStatus   = new \Kanboard\Model\TaskStatusModel($this->container);

        $pid = $projectModel->create(array('name' => 'HideCompletedProj'));
        $due = mktime(12, 0, 0, (int) date('n'), 15);
        $tOpen   = $taskCreation->create(array('project_id' => $pid, 'title' => 'Open task',   'date_due' => $due));
        $tClosed = $taskCreation->create(array('project_id' => $pid, 'title' => 'Closed task', 'date_due' => $due));
        $taskStatus->close($tClosed);

        $model = new CalendarQueryModel($this->container);
        $start = mktime(0, 0, 0, (int) date('n'), 1);
        $end   = mktime(0, 0, 0, (int) date('n') + 1, 1);

        $events = $model->getEvents(1, array('hide_completed' => true), $start, $end);
        $ids = array_column($events, 'id');
        $this->assertContains($tOpen, $ids, 'open task must appear when hide_completed is true');
        $this->assertNotContains($tClosed, $ids, 'closed task must be excluded when hide_completed is true');
    }

    public function testGetUnscheduledReturnsOnlyNoDueDateAccessibleTasks()
    {
        $projectModel = new \Kanboard\Model\ProjectModel($this->container);
        $taskCreation = new \Kanboard\Model\TaskCreationModel($this->container);
        $pid = $projectModel->create(array('name' => 'US'));
        $withDue = $taskCreation->create(array('project_id' => $pid, 'title' => 'has due', 'date_due' => mktime(12,0,0,(int)date('n'),9)));
        $noDue   = $taskCreation->create(array('project_id' => $pid, 'title' => 'no due'));
        $model = new CalendarQueryModel($this->container);
        $ids = array_column($model->getUnscheduled(1, array()), 'id');
        $this->assertContains($noDue, $ids);
        $this->assertNotContains($withDue, $ids);
        // empty-access guard
        $this->assertCount(0, $model->getUnscheduled(999, array()));
    }

    // R2: Non-admin member sees only their own project's tasks
    public function testNonAdminMemberSeesOnlyMemberProjects()
    {
        $projectModel    = new ProjectModel($this->container);
        $taskCreation    = new TaskCreationModel($this->container);
        $userModel       = new \Kanboard\Model\UserModel($this->container);
        $projectUserRole = new \Kanboard\Model\ProjectUserRoleModel($this->container);

        $uid = $userModel->create(array('username' => 'cal_member', 'password' => 'test1234', 'role' => \Kanboard\Core\Security\Role::APP_USER));
        $this->assertNotFalse($uid);
        $mine    = $projectModel->create(array('name' => 'MemberProj'));
        $foreign = $projectModel->create(array('name' => 'ForeignProj'));
        // add the non-admin as a member of $mine only
        $projectUserRole->addUser($mine, $uid, \Kanboard\Core\Security\Role::PROJECT_MEMBER);

        $due = mktime(12, 0, 0, (int) date('n'), 16);
        $tMine    = $taskCreation->create(array('project_id' => $mine,    'title' => 'mine',    'date_due' => $due));
        $tForeign = $taskCreation->create(array('project_id' => $foreign, 'title' => 'foreign', 'date_due' => $due));

        $model = new CalendarQueryModel($this->container);
        $ids = array_column($model->getEvents($uid, array(),
            mktime(0, 0, 0, (int) date('n'), 1), mktime(0, 0, 0, (int) date('n') + 1, 1)), 'id');
        $this->assertContains($tMine, $ids, 'member must see their own project task');
        $this->assertNotContains($tForeign, $ids, 'member must NOT see a project they are not on');
    }

    // Task 0: generic calendarEventDecorators extension point — other plugins
    // (e.g. DependencyPlugin) push badges onto events via a container-registered
    // list of callables. Must be additive and defensive when absent.
    public function testEventDecoratorsAddBadges()
    {
        $projectModel = new \Kanboard\Model\ProjectModel($this->container);
        $taskCreation = new \Kanboard\Model\TaskCreationModel($this->container);
        $pid = $projectModel->create(array('name' => 'DecP'));
        $tid = $taskCreation->create(array('project_id' => $pid, 'title' => 'Dec', 'date_due' => mktime(12,0,0,(int)date('n'),10)));

        // Register a decorator that flags this task.
        $this->container['calendarEventDecorators'] = array(
            function (array $event, array $row) use ($tid) {
                if ((int) $event['id'] === $tid) { $event['extendedProps']['badges'][] = array('text' => 'X', 'cls' => 'dep-blk'); }
                return $event;
            },
        );

        $model = new \Kanboard\Plugin\CalendarPlugin\Model\CalendarQueryModel($this->container);
        $events = $model->getEvents(1, array(), mktime(0,0,0,(int)date('n'),1), mktime(0,0,0,(int)date('n')+1,1));
        $ev = null; foreach ($events as $e) { if ((int) $e['id'] === $tid) { $ev = $e; } }
        $this->assertNotNull($ev);
        $this->assertSame('X', $ev['extendedProps']['badges'][0]['text']);
    }
}
