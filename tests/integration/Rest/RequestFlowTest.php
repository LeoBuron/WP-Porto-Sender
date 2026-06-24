<?php // tests/integration/Rest/RequestFlowTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Rest;
use PortoSender\Tests\integration\PortoTestCase;
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
use PortoSender\Frontend\ConfirmHandler;

final class RequestFlowTest extends PortoTestCase
{
    private function service(): array
    {
        global $wpdb;
        $codes = new CodeRepository($wpdb);
        $requests = new RequestRepository($wpdb);
        $settings = new Settings(['enabled_products' => ['grossbrief'], 'owner_address' => 'Leo, 12345 Stadt']);
        $svc = new IssuanceService(
            new NullVerifier(), new RequestLimiter($requests),
            new RateLimiter(new TransientRateCounterStore(), $settings, new SystemClock()),
            $codes, $requests, new Mailer($settings), new Hasher('salt'), new TokenGenerator(),
            new UrlConfirmLinkBuilder(), $settings, ProductCatalog::default(), new SystemClock()
        );
        return [$svc, $codes, $requests];
    }

    public function test_submit_then_confirm_issues_a_code(): void
    {
        // The test container has no mail transport, so short-circuit wp_mail to succeed —
        // otherwise sendDelivery() would fail and confirm() would (correctly) refuse to
        // spend the code. We exercise the success path here.
        add_filter('pre_wp_mail', '__return_true');

        [$svc, $codes, $requests] = $this->service();
        $codes->addBatch('grossbrief', 180, new \DateTimeImmutable('2026-01-01'), ['POOLCODE1']);

        // Seed a pending request with a known token (mirrors what submit() stores).
        $hasher = new Hasher('salt');
        $requests->createPending([
            'name' => 'Vera', 'email' => 'v@example.de',
            'email_hash' => $hasher->email('v@example.de'), 'name_hash' => $hasher->name('Vera'),
            'product' => 'grossbrief', 'token_hash' => $hasher->token('KNOWNTOKEN'),
            'ip_hash' => null, 'created_at' => (new SystemClock())->now()->format('Y-m-d H:i:s'),
        ]);

        $result = $svc->confirm('KNOWNTOKEN');
        $this->assertSame('issued', $result['status']);
        $this->assertSame(0, $codes->availableCount('grossbrief', new \DateTimeImmutable('now')));

        // ConfirmHandler delegates to the service.
        $handler = new ConfirmHandler($svc);
        $this->assertSame('already_issued', $handler->process('KNOWNTOKEN'));
    }

    public function test_confirm_does_not_spend_code_when_email_fails(): void
    {
        // Force wp_mail to fail: the code must stay reserved (still claimable later via
        // releaseStaleReservations) rather than being spent on a delivery that never arrived.
        add_filter('pre_wp_mail', '__return_false');

        [$svc, $codes, $requests] = $this->service();
        $codes->addBatch('grossbrief', 180, new \DateTimeImmutable('2026-01-01'), ['POOLCODE2']);

        $hasher = new Hasher('salt');
        $reqId = $requests->createPending([
            'name' => 'Vera', 'email' => 'v@example.de',
            'email_hash' => $hasher->email('v@example.de'), 'name_hash' => $hasher->name('Vera'),
            'product' => 'grossbrief', 'token_hash' => $hasher->token('FAILTOKEN'),
            'ip_hash' => null, 'created_at' => (new SystemClock())->now()->format('Y-m-d H:i:s'),
        ]);

        $result = $svc->confirm('FAILTOKEN');
        $this->assertSame('email_failed', $result['status']);
        // Request was confirmed but NOT issued; the code was not spent.
        $this->assertSame('confirmed', $requests->findById($reqId)->status);
        $this->assertSame(0, $codes->availableCount('grossbrief', new \DateTimeImmutable('now'))); // reserved, not available
    }

    public function test_rest_submit_creates_pending_request(): void
    {
        [$svc, $codes] = $this->service();
        $codes->addBatch('grossbrief', 180, new \DateTimeImmutable('2026-01-01'), ['RESTCODE1']);
        $controller = new \PortoSender\Rest\RestController($svc, new NullVerifier());
        $controller->register();
        global $wp_rest_server;
        $wp_rest_server = new \WP_REST_Server();
        do_action('rest_api_init');

        $req = new \WP_REST_Request('POST', '/porto/v1/request');
        $req->set_body_params(['name' => 'Vera', 'email' => 'v@example.de', 'product' => 'grossbrief', 'captcha' => 'x']);
        $res = rest_do_request($req);
        $this->assertSame('confirmation_sent', $res->get_data()['status']);
    }

    public function test_rest_submit_is_rate_limited_after_per_ip_cap(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        [$svc, $codes] = $this->service(); // default settings => 3/day per IP
        $codes->addBatch('grossbrief', 180, new \DateTimeImmutable('2026-01-01'), ['RLCODE1']);
        $controller = new \PortoSender\Rest\RestController($svc, new NullVerifier());
        $controller->register();
        global $wp_rest_server;
        $wp_rest_server = new \WP_REST_Server();
        do_action('rest_api_init');

        $submit = static function () {
            $req = new \WP_REST_Request('POST', '/porto/v1/request');
            $req->set_body_params(['name' => 'Vera', 'email' => 'v@example.de', 'product' => 'grossbrief', 'captcha' => 'x']);
            return rest_do_request($req);
        };

        // Dedup ignores pending rows, so the first three all pass; the 4th trips the per-IP cap.
        $this->assertSame('confirmation_sent', $submit()->get_data()['status']);
        $this->assertSame('confirmation_sent', $submit()->get_data()['status']);
        $this->assertSame('confirmation_sent', $submit()->get_data()['status']);
        $res = $submit();
        $this->assertSame('rate_limited', $res->get_data()['status']);
        $this->assertSame(429, $res->get_status());
    }
}
