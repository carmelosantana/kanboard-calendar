# CalendarPlugin

A drag-and-drop calendar view for Kanboard, enabling visual task management by due date across all projects or per project. Integrates seamlessly into the Kanboard interface with a project-level tab and a global nav link.

## Features

- **Global calendar** at `/calendar` — shows tasks across all projects accessible to the current user (admins see all active projects)
- **Per-project calendar** tab in the project view-switcher — scoped automatically to that project
- **Global nav link** in the user dropdown for quick access
- **Month grid view** with drag-and-drop to reschedule tasks by moving events
- **Agenda/list view** toggled via the toolbar button (`listMonth`)
- **Unscheduled tasks sidebar** — drag tasks with no due date onto the calendar to schedule them
- **Filter bar** — filter by project(s), assignee, category, and hide completed tasks
- **Assignee avatars** (initials) and project/estimate badges on each event
- **Overdue highlighting** — overdue tasks shown with a red border
- **Task popover** — click any event to see project, column, assignee, and a link to open the task
- CSRF-protected reschedule endpoint; access control enforced on all endpoints

## Installation

1. Copy or clone the `CalendarPlugin` folder into your Kanboard `plugins/` directory.
2. Kanboard loads plugins automatically — no admin action required.
3. A **Calendar** link appears in the user dropdown (top-right avatar menu) for the global view.
4. Open any project and the **Calendar** tab appears alongside Board, List, and Gantt.

## Requirements

- Kanboard >= 1.2.47
- PHP >= 8.4

## Cross-plugin: `calendarEventDecorators`

CalendarPlugin exposes an optional extension point so other suite plugins can
annotate calendar events with badges without CalendarPlugin knowing anything
about them. Any plugin can, in its own `initialize()`, append a callable to a
container-registered array:

```php
$this->container['calendarEventDecorators'] = array_merge(
    isset($this->container['calendarEventDecorators']) ? $this->container['calendarEventDecorators'] : array(),
    array(
        function (array $event, array $row): array {
            // Inspect $row (the source task row: project_id, owner_id, etc.)
            // and/or $event, then push a badge if applicable.
            if (/* condition */ false) {
                $event['extendedProps']['badges'][] = array('text' => 'Blocked', 'cls' => 'dep-blk');
            }
            return $event;
        },
    )
);
```

Contract:

- `calendarEventDecorators` is an **optional** array of callables with
  signature `function(array $event, array $row): array`.
- `CalendarQueryModel::getEvents()` runs every registered decorator over every
  event it builds, passing the FullCalendar event array and its source DB row.
  Each decorator must return the (possibly modified) event array.
- Every event always has `extendedProps.badges` (default `[]`), whether or not
  any decorators are registered — so `calendar.js` can always safely iterate it.
- CalendarPlugin reads the container entry **defensively**
  (`isset($this->container['calendarEventDecorators']) ? ... : array()`), so
  with no decorators registered the calendar renders identically to before
  this extension point existed.
- Badges render as `<span class="cal-ev-badge {cls}">{text}</span>` after the
  built-in project/estimate badges in `calendar.js`'s `eventContent`.

## Screenshots

The global calendar and per-project calendar share the same interface — a full-width FullCalendar month grid with a filter bar above and an unscheduled sidebar on the left.

## License

MIT
