# CalendarPlugin

A drag-and-drop calendar view for Kanboard, enabling visual task management by due date across all projects or per project. Integrates seamlessly into the Kanboard interface with a project-level tab and a global nav link.

## Features

- **Global calendar** at `/calendar` — shows tasks across all projects accessible to the current user (admins see all active projects)
- **Per-project calendar** tab in the project view-switcher — scoped automatically to that project
- **Global nav link** in the user dropdown for quick access
- **Month grid view** with drag-and-drop to reschedule tasks by moving events
- **Week and day time-grid views** (`timeGridWeek`, `timeGridDay`) — hour-by-hour grids toggled via the toolbar; render-only (no resize)
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

## Cross-plugin: `calendarEventSources`

Where `calendarEventDecorators` (above) lets a plugin *mutate* the due-date
events CalendarPlugin already builds, `calendarEventSources` lets a plugin
*contribute new* events. Any plugin can, in its own `initialize()`, append a
callable to a container-registered array:

```php
$this->container['calendarEventSources'] = array_merge(
    isset($this->container['calendarEventSources']) ? $this->container['calendarEventSources'] : array(),
    array(
        function (int $userId, array $filters, int $rangeStart, int $rangeEnd): array {
            // Query your own data, build FullCalendar events for this
            // user/range, and return them. Return [] if nothing applies.
            return array();
        },
    )
);
```

Contract:

- `calendarEventSources` is an **optional** array of callables with signature
  `function(int $userId, array $filters, int $rangeStart, int $rangeEnd): array`.
  `$filters` is the same array `CalendarController::events()` builds
  (`project_ids`, `assignee_id`, `category_id`, `column_id`, `hide_completed`);
  `$rangeStart`/`$rangeEnd` are unix timestamps for the visible range.
- Each callable returns a list of FullCalendar event arrays with the same shape
  as the built-in due-date events (`id`, `title`, `start`, `end`, `allDay`,
  `color`, `url`, `extendedProps`).
- **The source owns access control** — it MUST filter to projects `$userId` may
  access and honor `$filters` where meaningful. CalendarPlugin does NOT
  re-check source events.
- **Only return events overlapping `[$rangeStart, $rangeEnd)`.**
- **`id` MUST be namespaced** (e.g. `timeblock-<taskId>`) — unique across
  sources and distinct from due-date event ids (which are the int task id).
- **Decorators are NOT applied to source events** — a source builds complete
  events itself, including any `extendedProps.badges`.
- CalendarPlugin reads the container entry **defensively**, so with no sources
  registered the calendar renders identically to before this extension point.

## Screenshots

The global calendar and per-project calendar share the same interface — a full-width FullCalendar month grid with a filter bar above and an unscheduled sidebar on the left.

## License

MIT
