<?php // tests/unit/Settings/GeoSettingsTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Settings;
use PortoSender\Tests\unit\WpUnitTestCase;
use PortoSender\Settings\Settings;

final class GeoSettingsTest extends WpUnitTestCase
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

    public function test_defaults_are_off_and_safe(): void
    {
        $s = new Settings();
        $this->assertFalse($s->geoEnabled());
        $this->assertSame('cloudflare', $s->geoProvider());
        $this->assertSame(['DE'], $s->geoAllowedCountries());
        $this->assertTrue($s->geoFailOpen());      // default fail-open
        $this->assertFalse($s->geoCloudflareAck());
        $this->assertSame('', $s->geoMaxmindDbPath());
        $this->assertSame('', $s->geoApiUrl());
        $this->assertSame('', $s->geoApiKey());
    }

    public function test_overrides(): void
    {
        $s = new Settings([
            'geo_enabled' => true, 'geo_provider' => 'maxmind',
            'geo_allowed_countries' => ['DE', 'AT'], 'geo_fail_mode' => 'closed',
            'geo_cloudflare_ack' => true, 'geo_api_key' => 'k',
        ]);
        $this->assertTrue($s->geoEnabled());
        $this->assertSame('maxmind', $s->geoProvider());
        $this->assertSame(['DE', 'AT'], $s->geoAllowedCountries());
        $this->assertFalse($s->geoFailOpen());
        $this->assertTrue($s->geoCloudflareAck());
        $this->assertSame('k', $s->geoApiKey());
    }

    public function test_sanitize_whitelists_and_parses(): void
    {
        \Brain\Monkey\Functions\when('get_option')->justReturn([]);
        $out = Settings::sanitize([
            'geo_enabled' => '1',
            'geo_provider' => 'bogus',                 // invalid -> retained default
            'geo_allowed_countries' => ' de , at ,xxx,D', // -> DE, AT (xxx/D dropped)
            'geo_fail_mode' => 'closed',
            'geo_cloudflare_ack' => '1',
            'geo_api_url' => 'https://api.example/geo',
            'geo_api_key' => 'secret',
        ]);
        $this->assertTrue($out['geo_enabled']);
        $this->assertSame('cloudflare', $out['geo_provider']); // bogus rejected -> default
        $this->assertSame(['DE', 'AT'], $out['geo_allowed_countries']);
        $this->assertSame('closed', $out['geo_fail_mode']);
        $this->assertTrue($out['geo_cloudflare_ack']);
        $this->assertSame('secret', $out['geo_api_key']);
    }

    public function test_sanitize_empty_country_list_falls_back_to_de(): void
    {
        \Brain\Monkey\Functions\when('get_option')->justReturn([]);
        $out = Settings::sanitize(['geo_allowed_countries' => '  ,  ']);
        $this->assertSame(['DE'], $out['geo_allowed_countries']);
    }

    public function test_sanitize_preserves_hash_salt(): void
    {
        \Brain\Monkey\Functions\when('get_option')->justReturn(['hash_salt' => 'KEEP']);
        $out = Settings::sanitize(['geo_enabled' => '1']);
        $this->assertSame('KEEP', $out['hash_salt']);
        $this->assertFalse($out['geo_cloudflare_ack']); // absent checkbox -> false
    }
}
