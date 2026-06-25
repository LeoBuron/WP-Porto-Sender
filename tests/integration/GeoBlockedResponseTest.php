<?php // tests/integration/GeoBlockedResponseTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration;
use PortoSender\Inventory\CodeRepository;
use PortoSender\Requests\RequestRepository;
use PortoSender\Issuance\IssuanceService;
use PortoSender\Issuance\UrlConfirmLinkBuilder;
use PortoSender\Captcha\NullVerifier;
use PortoSender\Limiting\RequestLimiter;
use PortoSender\Limiting\RateLimiter;
use PortoSender\Limiting\TransientRateCounterStore;
use PortoSender\Mail\Mailer;
use PortoSender\Support\{Hasher, TokenGenerator, SystemClock};
use PortoSender\Settings\Settings;
use PortoSender\Postage\ProductCatalog;
use PortoSender\Geo\GeoGate;
use PortoSender\Geo\GeoProvider;
use PortoSender\Geo\GeoProviderFactory;
use PortoSender\Rest\RestController;

final class GeoBlockedResponseTest extends PortoTestCase
{
    private function service(Settings $settings, GeoGate $geo): IssuanceService
    {
        global $wpdb;
        $codes = new CodeRepository($wpdb);
        $requests = new RequestRepository($wpdb);
        $codes->addBatch('grossbrief', 180, new \DateTimeImmutable('2026-01-01'), ['GEOCODE_' . substr(md5((string) $settings->geoProvider() . $settings->geoEnabled()), 0, 5)]);
        return new IssuanceService(
            new NullVerifier(), new RequestLimiter($requests),
            new RateLimiter(new TransientRateCounterStore(), $settings, new SystemClock()),
            $codes, $requests, new Mailer($settings), new Hasher('salt'), new TokenGenerator(),
            new UrlConfirmLinkBuilder(), $settings, ProductCatalog::default(), new SystemClock(),
            null, $geo
        );
    }

    private function post(IssuanceService $svc): \WP_REST_Response
    {
        // Call handleRequest directly: we're testing the status->HTTP-code mapping, and going
        // through rest_do_request would race the plugin's own (geo-disabled) registered route.
        $req = new \WP_REST_Request('POST', '/porto/v1/request');
        $req->set_body_params(['name' => 'Vera', 'email' => 'v@example.de', 'product' => 'grossbrief', 'captcha' => 'x']);
        return (new RestController($svc, new NullVerifier()))->handleRequest($req);
    }

    public function test_geo_blocked_maps_to_http_403(): void
    {
        $settings = new Settings(['enabled_products' => ['grossbrief'], 'geo_enabled' => true, 'geo_allowed_countries' => ['DE']]);
        $denyGeo = new GeoGate(
            new class implements GeoProvider { public function country(string $ip): ?string { return 'FR'; } },
            $settings
        );
        $res = $this->post($this->service($settings, $denyGeo));
        $this->assertSame('geo_blocked', $res->get_data()['status']);
        $this->assertSame(403, $res->get_status());
    }

    public function test_geo_disabled_default_is_transparent(): void
    {
        add_filter('pre_wp_mail', '__return_true');
        $settings = new Settings(['enabled_products' => ['grossbrief']]); // geo_enabled defaults false
        $geo = new GeoGate(GeoProviderFactory::make($settings), $settings); // factory -> NullGeoProvider
        $res = $this->post($this->service($settings, $geo));
        $this->assertSame('confirmation_sent', $res->get_data()['status']);
        $this->assertSame(200, $res->get_status());
    }
}
