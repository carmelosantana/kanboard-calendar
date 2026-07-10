<?php

namespace Kanboard\Plugin\CalendarPlugin;

use Kanboard\Core\Plugin\Base;

class Plugin extends Base
{
    public function initialize()
    {
        $this->container['calendarQueryModel'] = function ($c) {
            return new \Kanboard\Plugin\CalendarPlugin\Model\CalendarQueryModel($c);
        };

        // Route: global calendar page.
        $this->route->addRoute('calendar', 'CalendarController', 'show', 'CalendarPlugin');

        // Route: per-project calendar page.
        $this->route->addRoute('project/:project_id/calendar', 'CalendarController', 'project', 'CalendarPlugin');

        // Route: FullCalendar events JSON feed.
        $this->route->addRoute('calendar/events', 'CalendarController', 'events', 'CalendarPlugin');

        // Route: reschedule a task's due date (drag-to-reschedule).
        $this->route->addRoute('calendar/update', 'CalendarController', 'updateDate', 'CalendarPlugin');

        // Route: unscheduled tasks sidebar JSON feed.
        $this->route->addRoute('calendar/unscheduled', 'CalendarController', 'unscheduled', 'CalendarPlugin');

        // Assets. FullCalendar's global build is ~282KB — inject the calendar
        // assets ONLY on calendar pages, never sitewide (the asset hook emits
        // every registered listener unconditionally, so we gate registration
        // on the current route instead). FullCalendar MUST be injected before
        // calendar.js (both are deferred, so document order = execution order).
        if ($this->isCalendarRequest()) {
            $this->hook->on('template:layout:js', ['template' => 'plugins/CalendarPlugin/Assets/vendor/fullcalendar/index.global.min.js']);
            $this->hook->on('template:layout:js', ['template' => 'plugins/CalendarPlugin/Assets/js/calendar.js']);
            $this->hook->on('template:layout:css', ['template' => 'plugins/CalendarPlugin/Assets/css/calendar.css']);
        }

        // Per-project view-switcher tab.
        $this->hook->on('template:project-header:view-switcher', ['template' => 'CalendarPlugin:calendar/tab']);

        // Global nav link in the user dropdown.
        $this->hook->on('template:header:dropdown', ['template' => 'CalendarPlugin:calendar/nav']);
    }

    /**
     * True when the current request targets CalendarController, so the heavy
     * calendar assets load only on calendar pages. Runs at plugin-initialize
     * time (before the router dispatches), so it resolves the target route
     * itself: the query-string form via the `controller` GET param, and the
     * clean-URL form via the request path. Uses Router::getPath() (a pure read)
     * rather than Route::findRoute() to avoid the latter's setParams() side effect.
     */
    private function isCalendarRequest()
    {
        if ($this->request->getStringParam('controller') === 'CalendarController') {
            return true;
        }

        $path = $this->router->getPath();

        return $path === 'calendar'
            || strpos($path, 'calendar/') === 0
            || preg_match('~^project/\d+/calendar$~', $path) === 1;
    }

    public function getPluginName()        { return 'CalendarPlugin'; }
    public function getPluginAuthor()      { return 'Carmelo Santana'; }
    public function getPluginVersion()     { return '1.1.0'; }
    public function getPluginDescription() { return 'Drag-and-drop calendar view: tasks by due date, across all projects or per project.'; }
    public function getPluginHomepage()    { return 'https://github.com/carmelosantana/kanboard-calendar'; }
    public function getPluginLicense()     { return 'MIT'; }
    public function getCompatibleVersion() { return '>=1.2.47'; }
}
