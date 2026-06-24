<?php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Admin;

use Brain\Monkey\Functions;
use PortoSender\Tests\unit\WpUnitTestCase;
use PortoSender\Admin\SettingsPage;
use PortoSender\Settings\Settings;

final class SettingsPageTest extends WpUnitTestCase
{
    public function test_registers_setting_with_sanitizer(): void
    {
        Functions\expect('register_setting')
            ->once()
            ->with(
                'porto_sender',
                Settings::OPTION,
                \Mockery::on(fn($a) => is_array($a) && isset($a['sanitize_callback']))
            );
        Functions\when('add_menu_page')->justReturn('toplevel_page_porto');
        (new SettingsPage())->registerSetting();
    }
}
