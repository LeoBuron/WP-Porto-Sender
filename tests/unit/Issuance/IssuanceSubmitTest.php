<?php // tests/unit/Issuance/IssuanceSubmitTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Issuance;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PortoSender\Issuance\IssuanceService;
use PortoSender\Issuance\ConfirmLinkBuilder;
use PortoSender\Captcha\CaptchaVerifier;
use PortoSender\Limiting\RequestLimiter;
use PortoSender\Limiting\RateLimiter;
use PortoSender\Tests\unit\Limiting\InMemoryRateCounterStore;
use PortoSender\Inventory\CodeStore;
use PortoSender\Requests\RequestStore;
use PortoSender\Mail\MailerInterface;
use PortoSender\Support\Hasher;
use PortoSender\Support\TokenGenerator;
use PortoSender\Support\Clock;
use PortoSender\Settings\Settings;
use PortoSender\Postage\ProductCatalog;
use PortoSender\Geo\GeoGate;
use PortoSender\Geo\GeoProvider;

final class IssuanceSubmitTest extends MockeryTestCase
{
    private function service(array $mocks = []): array
    {
        $captcha = $mocks['captcha'] ?? Mockery::mock(CaptchaVerifier::class)->shouldReceive('verify')->andReturn(true)->getMock();
        $requests = $mocks['requests'] ?? Mockery::mock(RequestStore::class);
        $codes = $mocks['codes'] ?? Mockery::mock(CodeStore::class);
        $mailer = $mocks['mailer'] ?? Mockery::mock(MailerInterface::class);
        $limiterStore = Mockery::mock(RequestStore::class)->shouldReceive('hasPriorRequest')->andReturn(false)->getMock();
        $limiter = $mocks['limiter'] ?? new RequestLimiter($limiterStore);
        $settings = $mocks['settings'] ?? new Settings(['enabled_products' => ['grossbrief']]);
        $clock = Mockery::mock(Clock::class);
        $clock->shouldReceive('now')->andReturn(new \DateTimeImmutable('2026-06-24 10:00:00'));
        $rateLimiter = $mocks['rateLimiter'] ?? new RateLimiter(new InMemoryRateCounterStore(), $settings, $clock);
        $svc = new IssuanceService(
            $captcha, $limiter, $rateLimiter, $codes, $requests, $mailer,
            new Hasher('salt'), new TokenGenerator(),
            Mockery::mock(ConfirmLinkBuilder::class)->shouldReceive('build')->andReturn('https://x.test/c?token=t')->getMock(),
            $settings, ProductCatalog::default(), $clock,
            null, // notifier
            $mocks['geo'] ?? null
        );
        return [$svc, compact('captcha', 'requests', 'codes', 'mailer')];
    }

    private function geoReturning(?string $country): GeoProvider
    {
        return new class($country) implements GeoProvider {
            public function __construct(private ?string $c) {}
            public function country(string $ip): ?string { return $this->c; }
        };
    }

    private function input(array $over = []): array
    {
        return array_merge(['name' => 'Vera', 'email' => 'v@example.de', 'product' => 'grossbrief', 'captcha' => 'x', 'ip' => '1.2.3.4'], $over);
    }

    public function test_happy_path_sends_confirmation(): void
    {
        [$svc, $m] = $this->service();
        $m['codes']->shouldReceive('availableCount')->andReturn(3);
        $m['requests']->shouldReceive('createPending')->once()->andReturn(42);
        $m['mailer']->shouldReceive('sendConfirmation')->once()->andReturn(true);
        $this->assertSame('confirmation_sent', $svc->submit($this->input())['status']);
    }

    public function test_invalid_email_rejected(): void
    {
        [$svc] = $this->service();
        $this->assertSame('invalid', $svc->submit($this->input(['email' => 'nope']))['status']);
    }

    public function test_invalid_reports_the_offending_fields(): void
    {
        [$svc] = $this->service();

        // A single bad field is named, so the client can mark exactly it.
        $this->assertSame(['email'], $svc->submit($this->input(['email' => 'foo@bar']))['fields']);
        $this->assertSame(['name'], $svc->submit($this->input(['name' => '   ']))['fields']);
        $this->assertSame(['product'], $svc->submit($this->input(['product' => 'nope']))['fields']);

        // Several at once are reported together, in field order.
        $r = $svc->submit($this->input(['name' => '', 'email' => 'x', 'product' => '']));
        $this->assertSame('invalid', $r['status']);
        $this->assertSame(['name', 'email', 'product'], $r['fields']);
    }

    public function test_captcha_failure(): void
    {
        $captcha = Mockery::mock(CaptchaVerifier::class)->shouldReceive('verify')->andReturn(false)->getMock();
        [$svc, $m] = $this->service(['captcha' => $captcha]);
        $m['requests']->shouldNotReceive('createPending');
        $this->assertSame('captcha_failed', $svc->submit($this->input())['status']);
    }

    public function test_duplicate_blocked(): void
    {
        $limiterStore = Mockery::mock(RequestStore::class)->shouldReceive('hasPriorRequest')->andReturn(true)->getMock();
        [$svc, $m] = $this->service(['limiter' => new RequestLimiter($limiterStore)]);
        $m['requests']->shouldNotReceive('createPending');
        $this->assertSame('duplicate', $svc->submit($this->input())['status']);
    }

    public function test_out_of_stock(): void
    {
        [$svc, $m] = $this->service();
        $m['codes']->shouldReceive('availableCount')->andReturn(0);
        $m['requests']->shouldNotReceive('createPending');
        $m['mailer']->shouldNotReceive('sendConfirmation');
        $this->assertSame('out_of_stock', $svc->submit($this->input())['status']);
    }

    public function test_rate_limited(): void
    {
        // per-IP limit 0 => the very first request is over the cap.
        [$svc, $m] = $this->service(['settings' => new Settings([
            'enabled_products' => ['grossbrief'], 'rate_limit_per_ip_day' => 0,
        ])]);
        $m['requests']->shouldNotReceive('createPending');
        $m['mailer']->shouldNotReceive('sendConfirmation');
        $this->assertSame('rate_limited', $svc->submit($this->input())['status']);
    }

    public function test_geo_blocked_short_circuits_before_rate_limit_and_create(): void
    {
        $denyingGeo = new GeoGate(
            $this->geoReturning('FR'),
            new Settings(['geo_enabled' => true, 'geo_allowed_countries' => ['DE']])
        );
        [$svc, $m] = $this->service(['geo' => $denyingGeo]);
        $m['codes']->shouldNotReceive('availableCount'); // downstream stock check never reached
        $m['requests']->shouldNotReceive('createPending');
        $m['mailer']->shouldNotReceive('sendConfirmation');
        $this->assertSame('geo_blocked', $svc->submit($this->input())['status']);
    }

    public function test_geo_allowing_gate_is_transparent(): void
    {
        $allowGeo = new GeoGate(
            $this->geoReturning('DE'),
            new Settings(['geo_enabled' => true, 'geo_allowed_countries' => ['DE']])
        );
        [$svc, $m] = $this->service(['geo' => $allowGeo]);
        $m['codes']->shouldReceive('availableCount')->andReturn(3);
        $m['requests']->shouldReceive('createPending')->once()->andReturn(1);
        $m['mailer']->shouldReceive('sendConfirmation')->once()->andReturn(true);
        $this->assertSame('confirmation_sent', $svc->submit($this->input())['status']);
    }
}
