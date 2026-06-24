<?php // tests/unit/Settings/SettingsTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Settings;
use PortoSender\Tests\unit\WpUnitTestCase;
use PortoSender\Settings\Settings;

final class SettingsTest extends WpUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Brain\Monkey\Functions\when('sanitize_textarea_field')->returnArg(1);
        \Brain\Monkey\Functions\when('sanitize_text_field')->returnArg(1);
        \Brain\Monkey\Functions\when('sanitize_email')->returnArg(1);
        \Brain\Monkey\Functions\when('esc_url_raw')->returnArg(1);
        \Brain\Monkey\Functions\when('absint')->alias(static fn($v) => abs((int) $v));
    }

    public function test_defaults_apply_when_unset(): void
    {
        $s = new Settings();
        $this->assertSame('name_or_email', $s->requestLimitMode());
        $this->assertSame(180, $s->piiRetentionDays());
        $this->assertSame(48, $s->confirmTokenTtlHours());
        $this->assertSame(5, $s->lowStockThreshold('grossbrief'));
        $this->assertSame('altcha', $s->captchaProvider());
    }

    public function test_overrides_and_per_product_threshold(): void
    {
        $s = new Settings(['request_limit_mode' => 'email', 'low_stock_thresholds' => ['grossbrief' => 12]]);
        $this->assertSame('email', $s->requestLimitMode());
        $this->assertSame(12, $s->lowStockThreshold('grossbrief'));
        $this->assertSame(5, $s->lowStockThreshold('standardbrief')); // falls back to default
    }

    public function test_sanitize_clamps_and_whitelists(): void
    {
        \Brain\Monkey\Functions\when('get_option')->justReturn([]);
        $out = Settings::sanitize(['request_limit_mode' => 'bogus', 'pii_retention_days' => '-9', 'enabled_products' => ['grossbrief', 'evil']]);
        $this->assertSame('name_or_email', $out['request_limit_mode']);
        $this->assertGreaterThanOrEqual(1, $out['pii_retention_days']);
        $this->assertSame(['grossbrief'], $out['enabled_products']);
    }

    public function test_sanitize_preserves_unrendered_options(): void
    {
        // The admin form never renders hash_salt or confirm_token_ttl_hours; a save that
        // omits them must NOT reset them to defaults (wiping hash_salt is catastrophic).
        \Brain\Monkey\Functions\when('get_option')->justReturn([
            'hash_salt' => 'REALSALT0123456789',
            'confirm_token_ttl_hours' => 72,
            'reservation_ttl_minutes' => 15,
            'default_low_stock' => 9,
            'captcha_provider' => 'none',
            'expiry_warning_months' => 3,
        ]);

        $out = Settings::sanitize([
            'owner_address' => 'Musterstraße 1',
            'enabled_products' => ['grossbrief'],
            'low_stock_thresholds' => ['grossbrief' => '4'],
            'request_limit_mode' => 'email',
            'alert_email' => 'owner@example.de',
            'pii_retention_days' => '90',
            'altcha_hmac_secret' => 'secret',
            'privacy_policy_url' => 'https://example.de/datenschutz',
        ]);

        // Unrendered keys retain their stored values.
        $this->assertSame('REALSALT0123456789', $out['hash_salt']);
        $this->assertSame(72, $out['confirm_token_ttl_hours']);
        $this->assertSame(15, $out['reservation_ttl_minutes']);
        $this->assertSame(9, $out['default_low_stock']);
        $this->assertSame('none', $out['captcha_provider']);
        $this->assertSame(3, $out['expiry_warning_months']);
        // Rendered keys still get the submitted (sanitized) values.
        $this->assertSame('email', $out['request_limit_mode']);
        $this->assertSame(90, $out['pii_retention_days']);
        $this->assertSame(['grossbrief'], $out['enabled_products']);
    }
}
