<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\CalendarPlugin\Plugin;
use KanboardTests\units\Base;

class PluginTest extends Base
{
    public function testPluginNameValue()
    {
        $plugin = new Plugin($this->container);
        $this->assertSame('CalendarPlugin', $plugin->getPluginName());
    }

    public function testPluginVersionComesFromPluginJson()
    {
        $plugin = new Plugin($this->container);
        $json = json_decode(file_get_contents(dirname(__DIR__) . '/plugin.json'), true);
        $this->assertSame($json['version'], $plugin->getPluginVersion());
        $this->assertDoesNotMatchRegularExpression(
            "/function getPluginVersion\(\)[^{]*\{[^}]*'\d+\.\d+/",
            file_get_contents(dirname(__DIR__) . '/Plugin.php'),
            'getPluginVersion() reads plugin.json; the version lives only there'
        );
    }

    public function testPluginMetadataNotEmpty()
    {
        $plugin = new Plugin($this->container);
        $this->assertNotEmpty($plugin->getPluginAuthor());
        $this->assertNotEmpty($plugin->getPluginDescription());
        $this->assertSame('MIT', $plugin->getPluginLicense());
    }

    public function testVendoredFullCalendarMatchesVendorNote()
    {
        $dir = dirname(__DIR__) . '/Assets/vendor/fullcalendar';
        $this->assertSame(1, preg_match('/FullCalendar v(\d+\.\d+\.\d+) global build/', file_get_contents($dir . '/VENDOR.md'), $note));
        $this->assertSame(1, preg_match('/FullCalendar Standard Bundle v(\d+\.\d+\.\d+)/', file_get_contents($dir . '/index.global.min.js'), $bundle));
        $this->assertSame($note[1], $bundle[1], 'index.global.min.js must be the version VENDOR.md documents');
        $this->assertTrue(version_compare($bundle[1], '6.1.22', '>='), "FullCalendar {$bundle[1]} is outdated");
    }
}
