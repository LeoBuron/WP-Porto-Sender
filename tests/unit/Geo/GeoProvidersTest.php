<?php declare(strict_types=1);
namespace PortoSender\Tests\unit\Geo;

use PortoSender\Tests\unit\WpUnitTestCase;
use Brain\Monkey\Functions;
use PortoSender\Geo\NullGeoProvider;
use PortoSender\Geo\CloudflareHeaderGeoProvider;
use PortoSender\Geo\MaxMindGeoProvider;
use PortoSender\Geo\ApiGeoProvider;
use PortoSender\Geo\GeoProviderFactory;
use PortoSender\Settings\Settings;

final class GeoProvidersTest extends WpUnitTestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_CF_IPCOUNTRY']);
        parent::tearDown();
    }

    public function test_null_provider_always_null(): void
    {
        $this->assertNull((new NullGeoProvider())->country('1.2.3.4'));
    }

    public function test_cloudflare_reads_and_uppercases_header(): void
    {
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'de';
        $this->assertSame('DE', (new CloudflareHeaderGeoProvider())->country('1.2.3.4'));
    }

    public function test_cloudflare_absent_or_sentinel_is_null(): void
    {
        $p = new CloudflareHeaderGeoProvider();
        unset($_SERVER['HTTP_CF_IPCOUNTRY']);
        $this->assertNull($p->country('1.2.3.4'));
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'XX'; // CF "unknown"
        $this->assertNull($p->country('1.2.3.4'));
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'T1'; // CF "Tor"
        $this->assertNull($p->country('1.2.3.4'));
    }

    public function test_maxmind_unavailable_without_lib_or_db(): void
    {
        $empty = new MaxMindGeoProvider('');
        $this->assertFalse($empty->available());
        $this->assertNull($empty->country('1.2.3.4'));

        $missing = new MaxMindGeoProvider('/nonexistent/GeoLite2-Country.mmdb');
        $this->assertFalse($missing->available());
        $this->assertNull($missing->country('1.2.3.4'));
    }

    public function test_api_provider_parses_country(): void
    {
        Functions\when('add_query_arg')->alias(static fn($args, $url) => $url);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"country":"de"}');
        Functions\expect('wp_remote_get')->once()->andReturn(['fake' => true]);

        $this->assertSame('DE', (new ApiGeoProvider('https://api.example/geo', 'k'))->country('1.2.3.4'));
    }

    public function test_api_provider_empty_url_never_calls(): void
    {
        Functions\expect('wp_remote_get')->never();
        $this->assertNull((new ApiGeoProvider('', ''))->country('1.2.3.4'));
    }

    public function test_api_provider_network_error_is_null(): void
    {
        Functions\when('add_query_arg')->alias(static fn($args, $url) => $url);
        Functions\when('wp_remote_get')->justReturn(['err']);
        Functions\when('is_wp_error')->justReturn(true);
        $this->assertNull((new ApiGeoProvider('https://api.example/geo', 'k'))->country('1.2.3.4'));
    }

    public function test_factory_null_when_disabled(): void
    {
        $this->assertInstanceOf(NullGeoProvider::class, GeoProviderFactory::make(new Settings(['geo_enabled' => false])));
    }

    public function test_factory_cloudflare_requires_ack(): void
    {
        $unacked = GeoProviderFactory::make(new Settings(['geo_enabled' => true, 'geo_provider' => 'cloudflare', 'geo_cloudflare_ack' => false]));
        $this->assertInstanceOf(NullGeoProvider::class, $unacked);
        $acked = GeoProviderFactory::make(new Settings(['geo_enabled' => true, 'geo_provider' => 'cloudflare', 'geo_cloudflare_ack' => true]));
        $this->assertInstanceOf(CloudflareHeaderGeoProvider::class, $acked);
    }

    public function test_factory_maxmind_null_when_unavailable(): void
    {
        $p = GeoProviderFactory::make(new Settings(['geo_enabled' => true, 'geo_provider' => 'maxmind', 'geo_maxmind_db_path' => '']));
        $this->assertInstanceOf(NullGeoProvider::class, $p);
    }

    public function test_factory_api_requires_url(): void
    {
        $noUrl = GeoProviderFactory::make(new Settings(['geo_enabled' => true, 'geo_provider' => 'api', 'geo_api_url' => '']));
        $this->assertInstanceOf(NullGeoProvider::class, $noUrl);
        $withUrl = GeoProviderFactory::make(new Settings(['geo_enabled' => true, 'geo_provider' => 'api', 'geo_api_url' => 'https://api.example/geo']));
        $this->assertInstanceOf(ApiGeoProvider::class, $withUrl);
    }
}
