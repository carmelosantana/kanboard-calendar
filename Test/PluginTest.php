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

    public function testPluginVersionValue()
    {
        $plugin = new Plugin($this->container);
        $this->assertSame('1.1.0', $plugin->getPluginVersion());
    }

    public function testPluginMetadataNotEmpty()
    {
        $plugin = new Plugin($this->container);
        $this->assertNotEmpty($plugin->getPluginAuthor());
        $this->assertNotEmpty($plugin->getPluginDescription());
        $this->assertSame('MIT', $plugin->getPluginLicense());
    }
}
