/*! CalendarPlugin — external, CSP-safe. */
(function () {
    'use strict';

    /**
     * Build query-string fragment from #cal-filterbar controls.
     * Each control must have data-cal-filter="<param_name>".
     *
     * Supported control types:
     *   <select multiple> data-cal-filter="project_ids"  → &project_ids=1,2,3
     *   <select>          data-cal-filter="assignee_id"  → &assignee_id=5
     *   <select>          data-cal-filter="category_id"  → &category_id=3
     *   <input[checkbox]> data-cal-filter="hide_completed" → &hide_completed=1
     */
    function buildFilterQuery(root) {
        var bar = document.getElementById('cal-filterbar');
        if (!bar) { return ''; }

        var parts = [];
        var projectFilterSelected = false;
        var controls = bar.querySelectorAll('[data-cal-filter]');
        for (var i = 0; i < controls.length; i++) {
            var el = controls[i];
            var param = el.getAttribute('data-cal-filter');

            if (el.tagName === 'SELECT' && el.multiple) {
                // Multi-select: collect all selected values as comma-separated list.
                var selected = [];
                for (var j = 0; j < el.options.length; j++) {
                    if (el.options[j].selected) {
                        selected.push(encodeURIComponent(el.options[j].value));
                    }
                }
                if (selected.length > 0) {
                    parts.push(encodeURIComponent(param) + '=' + selected.join(','));
                    if (param === 'project_ids') { projectFilterSelected = true; }
                }
            } else if (el.tagName === 'SELECT') {
                if (el.value !== '') {
                    parts.push(encodeURIComponent(param) + '=' + encodeURIComponent(el.value));
                }
            } else if (el.type === 'checkbox') {
                if (el.checked) {
                    parts.push(encodeURIComponent(param) + '=' + encodeURIComponent(el.value));
                }
            }
        }

        // If we're on a per-project page (positive data-project-id) and the
        // user hasn't explicitly picked a project filter, auto-scope to that project.
        if (root) {
            var projectId = parseInt(root.getAttribute('data-project-id'), 10);
            if (projectId > 0 && !projectFilterSelected) {
                parts.push('project_ids=' + projectId);
            }
        }

        return parts.length > 0 ? '&' + parts.join('&') : '';
    }

    function postDate(root, taskId, dateStr, done) {
        var body = new URLSearchParams();
        body.set('task_id', taskId);
        body.set('date_due', dateStr);
        body.set('csrf_token', root.getAttribute('data-csrf'));
        fetch(root.getAttribute('data-update-url'), {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: body.toString()
        }).then(function (r) { return r.json().catch(function () { return { result: false }; }); })
          .then(function (d) { done(!!(d && d.result)); })
          .catch(function () { done(false); });
    }

    // Register the external-event Draggable ONCE per list container. FullCalendar
    // delegates from the container, so items added on later refreshes drag too;
    // re-registering on every refresh would stack duplicate instances.
    function ensureDraggable(list) {
        if (list.dataset.calDraggable) { return; }
        list.dataset.calDraggable = '1';
        new FullCalendar.Draggable(list, {
            itemSelector: '.cal-unscheduled-item',
            eventData: function (el) { return { id: el.getAttribute('data-task-id'), title: el.textContent, allDay: true }; }
        });
    }

    // buildFilterQuery returns '' or '&a=b&c=d'. Append it to a base URL that
    // may or may not already carry a query string (clean-URL vs query-string routing).
    function appendQuery(base, frag) {
        if (!frag) { return base; }
        return base + (base.indexOf('?') >= 0 ? frag : '?' + frag.slice(1));
    }

    function loadUnscheduled(root) {
        var list = document.getElementById('cal-unscheduled-list');
        if (!list) { return; }
        fetch(appendQuery(root.getAttribute('data-unscheduled-url'), buildFilterQuery(root)), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (items) {
                list.textContent = '';
                items.forEach(function (it) {
                    var el = document.createElement('div');
                    el.className = 'cal-unscheduled-item';
                    el.setAttribute('data-task-id', it.id);
                    el.style.borderLeft = '4px solid ' + it.color;
                    el.textContent = it.title;
                    list.appendChild(el);
                });
                ensureDraggable(list);
            });
    }

    function initials(name) {
        var parts = String(name).trim().split(/\s+/);
        var s = (parts[0] ? parts[0][0] : '') + (parts.length > 1 ? parts[parts.length - 1][0] : '');
        return s.toUpperCase();
    }
    function closePopover() { var ex = document.getElementById('cal-popover'); if (ex) { ex.parentNode.removeChild(ex); } }
    function showPopover(info) {
        closePopover();
        var ep = info.event.extendedProps;
        var pop = document.createElement('div');
        pop.id = 'cal-popover';
        pop.className = 'cal-popover';
        function row(label, value) { if (!value) { return; } var d = document.createElement('div'); d.className = 'cal-pop-row'; var b = document.createElement('strong'); b.textContent = label + ': '; d.appendChild(b); d.appendChild(document.createTextNode(value)); pop.appendChild(d); }
        var h = document.createElement('div'); h.className = 'cal-pop-title'; h.textContent = info.event.title; pop.appendChild(h);
        row('Project', ep.project); row('Column', ep.column); row('Assignee', ep.assignee);
        var a = document.createElement('a'); a.href = info.event.url; a.className = 'cal-pop-link'; a.textContent = 'Open task'; pop.appendChild(a);
        document.body.appendChild(pop);
        var r = info.el.getBoundingClientRect();
        pop.style.top = (window.scrollY + r.bottom + 4) + 'px';
        pop.style.left = (window.scrollX + r.left) + 'px';
    }
    document.addEventListener('click', function (e) {
        if (!e.target.closest) { return; }
        if (!e.target.closest('#cal-popover') && !e.target.closest('.fc-event')) { closePopover(); }
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closePopover(); } });

    function init() {
        var root = document.getElementById('cal-root');
        var host = document.getElementById('calendar');
        if (!root || !host || typeof FullCalendar === 'undefined') { return; }
        if (host.dataset.calReady) { return; }
        host.dataset.calReady = '1';

        var eventsUrl = root.getAttribute('data-events-url');
        if (!eventsUrl) { return; } // no feed URL → nothing to render (avoids null.indexOf below)
        // On a per-project calendar every event is the same project, already named
        // in the page title — so the per-event project badge is redundant there.
        var perProject = parseInt(root.getAttribute('data-project-id'), 10) > 0;
        var calendar = new FullCalendar.Calendar(host, {
            initialView: 'dayGridMonth',
            height: 'auto',
            firstDay: 1,
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,listMonth' },
            editable: true,
            droppable: true,
            eventClassNames: function (arg) { return arg.event.extendedProps.overdue ? ['cal-ev-overdue'] : []; },
            eventContent: function (arg) {
                var ep = arg.event.extendedProps;
                var wrap = document.createElement('div');
                wrap.className = 'cal-ev';
                if (ep.assignee) {
                    var av = document.createElement('span');
                    av.className = 'cal-ev-avatar';
                    av.textContent = initials(ep.assignee);
                    av.title = ep.assignee;
                    wrap.appendChild(av);
                }
                var title = document.createElement('span');
                title.className = 'cal-ev-title';
                title.textContent = arg.event.title;
                wrap.appendChild(title);
                if (ep.project && !perProject) {
                    var proj = document.createElement('span');
                    proj.className = 'cal-ev-badge cal-ev-proj';
                    proj.textContent = ep.project;
                    wrap.appendChild(proj);
                }
                if (ep.estimate > 0) {
                    var est = document.createElement('span');
                    est.className = 'cal-ev-badge';
                    est.textContent = '~' + ep.estimate + 'h';
                    wrap.appendChild(est);
                }
                var badges = ep.badges || [];
                badges.forEach(function (b) {
                    var el = document.createElement('span');
                    el.className = 'cal-ev-badge ' + (b.cls || '');
                    el.textContent = b.text;
                    wrap.appendChild(el);
                });
                return { domNodes: [wrap] };
            },
            eventClick: function (info) { info.jsEvent.preventDefault(); showPopover(info); },
            eventReceive: function (info) {
                var el = document.querySelector('.cal-unscheduled-item[data-task-id="' + info.event.id + '"]');
                postDate(root, info.event.id, info.event.startStr, function (ok) {
                    if (ok) { if (el) { el.parentNode.removeChild(el); } }
                    else { info.event.remove(); }
                });
            },
            eventDrop: function (info) {
                postDate(root, info.event.id, info.event.startStr, function (ok) { if (!ok) { info.revert(); } });
            },
            events: function (info, success, failure) {
                var url = eventsUrl + (eventsUrl.indexOf('?') >= 0 ? '&' : '?') +
                    'start=' + encodeURIComponent(info.startStr) + '&end=' + encodeURIComponent(info.endStr) +
                    buildFilterQuery(root);
                fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) { success(data); })
                    .catch(function (e) { failure(e); });
            }
        });
        window.__calInstance = calendar; // handy for E2E assertions
        calendar.render();
        loadUnscheduled(root);

        // Delegated change listener: filter controls refetch events;
        // unscheduled toggle toggles sidebar visibility.
        document.addEventListener('change', function (e) {
            var el = e.target;
            if (el && el.id === 'cal-toggle-unscheduled') {
                var layout = document.getElementById('cal-layout');
                if (layout) { layout.classList.toggle('cal-hide-unscheduled', !el.checked); }
            } else if (el && el.closest && el.closest('#cal-filterbar')) {
                calendar.refetchEvents();
                loadUnscheduled(root); // keep the sidebar in sync with the project filter
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
