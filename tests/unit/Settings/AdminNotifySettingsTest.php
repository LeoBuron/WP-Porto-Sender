<?php // tests/unit/Settings/AdminNotifySettingsTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Settings;
use PortoSender\Tests\unit\WpUnitTestCase;
use PortoSender\Settings\Settings;

final class AdminNotifySettingsTest extends WpUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Brain\Monkey\Functions\when('sanitize_textarea_field')->returnArg(1);
        \Brain\Monkey\Functions\when('sanitize_text_field')->returnArg(1);
        \Brain\Monkey\Functions\when('sanitize_email')->returnArg(1);
        \Brain\Monkey\Functions\when('esc_url_raw')->returnArg(1);
        \Brain\Monkey\Functions\when('absint')->alias(static fn($v) => abs((int) $v));
        \Brain\Monkey\Functions\when('sanitize_hex_color')->alias(static fn($c) => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $c) ? $c : null);
    }

    public function test_defaults(): void
    {
        $s = new Settings();
        $this->assertTrue($s->adminNotifyEnabled());
        $this->assertTrue($s->adminNotifyIncludePii()); // default-on: mail ships with Zeit/Name/E-Mail
        $this->assertSame(15, $s->adminNotifyWindowMinutes());
    }

    public function test_overrides(): void
    {
        $s = new Settings([
            'admin_notify_enabled' => false,
            'admin_notify_include_pii' => true,
            'admin_notify_window_minutes' => 30,
        ]);
        $this->assertFalse($s->adminNotifyEnabled());
        $this->assertTrue($s->adminNotifyIncludePii());
        $this->assertSame(30, $s->adminNotifyWindowMinutes());
    }

    public function test_sanitize_casts_checkboxes_and_clamps_window(): void
    {
        \Brain\Monkey\Functions\when('get_option')->justReturn([]);

        $on = Settings::sanitize([
            'admin_notify_enabled' => '1',
            'admin_notify_include_pii' => '1',
            'admin_notify_window_minutes' => '45',
        ]);
        $this->assertTrue($on['admin_notify_enabled']);
        $this->assertTrue($on['admin_notify_include_pii']);
        $this->assertSame(45, $on['admin_notify_window_minutes']);

        // checkboxes absent => false; window via absint
        $off = Settings::sanitize(['admin_notify_window_minutes' => '-5']);
        $this->assertFalse($off['admin_notify_enabled']);
        $this->assertFalse($off['admin_notify_include_pii']);
        $this->assertSame(5, $off['admin_notify_window_minutes']);
    }

    public function test_sanitize_preserves_hash_salt(): void
    {
        \Brain\Monkey\Functions\when('get_option')->justReturn(['hash_salt' => 'KEEPSALT']);
        $out = Settings::sanitize(['admin_notify_enabled' => '1']);
        $this->assertSame('KEEPSALT', $out['hash_salt']);
    }
}
