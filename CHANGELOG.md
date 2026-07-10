# Changelog

All notable changes to the CalendarPlugin will be documented in this file.

## [1.1.0] - 2026-07-05

### Added
- Generic `calendarEventDecorators` extension point (badges[] passthrough) for
  suite plugins — other plugins (e.g. DependencyPlugin) can register a
  container-based list of callables in their own `initialize()` to push
  badges onto calendar events without CalendarPlugin knowing anything about
  them. See `README.md` §Cross-plugin for the contract. No-op (byte-identical
  rendering) when no decorators are registered.

## [1.0.2] - 2026-07-05

### Changed
- Calmer, more minimal calendar UI: removed the filter-bar box, the Unscheduled
  box, and (on per-project pages) the empty Project selector; softened the grid
  to faint hairlines via `--fc-border-color` and muted the "today" tint.
- Events render with dark, readable text on their (pastel) task-color pills in
  the month grid — matching Kanboard's own board — instead of the low-contrast
  default white. List/agenda rows keep the theme's text color.
- On a per-project calendar, events no longer show a project badge (redundant
  with the page title, which already names the project).

### Fixed
- Removed the duplicate calendar page title — Kanboard's app layout already
  renders the "&lt;project&gt; &gt; Calendar" heading, so the template's second
  `<h2>` was dropped.
- Prev/next toolbar buttons now show plain ‹ › chevrons instead of tofu — the
  CSP blocks FullCalendar's embedded (data: URI) icon font, so we fall back to
  unicode glyphs in a normal font.
- Overdue events use a clearer red left-bar accent.

### Verified
- Renders correctly on both a standard Kanboard install and ShadcnTheme
  (month + list views), 0 non-baseline console errors on each.

## [1.0.1] - 2026-07-05

### Changed
- Calendar assets (FullCalendar ~282KB + calendar.js/css) now load **only on
  calendar pages**, no longer on every Kanboard page (M1). Asset-hook
  registration is gated on the current route in `Plugin::initialize()`.
- The global calendar's assignee filter no longer exposes the entire user
  directory to non-admins — it now lists only users assignable on projects the
  viewer can access (M6). Admins still see all active users.

### Fixed
- Checkbox filter labels ("Hide completed", "Show unscheduled") now lay out
  horizontally — the modifier class was out-specified by the base label rule (M11).
- Removed the duplicate "today" button from the calendar's right toolbar (M13).
- Unscheduled sidebar now refreshes when the project filter changes (M10).
- The reschedule endpoint returns `400` for a missing/non-numeric `task_id`
  (malformed request) instead of `403` (M7).

### Internal
- Reschedule authorization moved to a unit-tested
  `CalendarQueryModel::canUserReschedule()` and covered for every project role,
  closing the controller-auth test gap the whole-branch review flagged (M8).
- `FullCalendar.Draggable` is registered once per sidebar instead of on every
  refresh (M9); guard against a missing events-feed URL (M5); simplified the
  admin project-scope query to an unqualified column (M4).

## [1.0.0] - 2026-07-04

### Added
- Global calendar page (`/calendar`) showing tasks across all accessible projects
- Per-project calendar page (`/project/:id/calendar`) scoped to a single project
- Per-project Calendar tab in the project view-switcher header
- Global Calendar link in the user dropdown navigation
- FullCalendar month grid view (`dayGridMonth`) with drag-and-drop to reschedule tasks
- FullCalendar agenda/list view (`listMonth`) toggled via toolbar button
- Unscheduled tasks sidebar — drag unscheduled tasks onto the calendar to set a due date
- Filter bar: filter events by project(s), assignee, category, and hide-completed toggle
- Per-project page auto-scopes events to that project (no manual project filter needed)
- Task popover on event click: shows project, column, assignee, and link to open task
- Overdue task highlighting (red border)
- Assignee avatar initials on each calendar event
- Project and time-estimate badges on each calendar event
- CSRF-protected drag-to-reschedule endpoint
- Admin users see all active projects; other users see only their accessible projects
