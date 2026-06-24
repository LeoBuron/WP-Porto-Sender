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
        $out = Settings::sanitize(['request_limit_mode' => 'bogus', 'pii_retention_days' => '-9', 'enabled_products' => ['grossbrief', 'evil']]);
        $this->assertSame('name_or_email', $out['request_limit_mode']);
        $this->assertGreaterThanOrEqual(1, $out['pii_retention_days']);
        $this->assertSame(['grossbrief'], $out['enabled_products']);
    }
}
