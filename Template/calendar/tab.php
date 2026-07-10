<li <?= $this->app->checkMenuSelection('CalendarController', 'project') ?>>
    <?= $this->url->icon('calendar', t('Calendar'), 'CalendarController', 'project', array('plugin' => 'CalendarPlugin', 'project_id' => $project['id']), false, 'view-calendar') ?>
</li>
