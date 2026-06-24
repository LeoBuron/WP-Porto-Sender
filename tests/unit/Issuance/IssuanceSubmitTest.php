<?php // tests/unit/Issuance/IssuanceSubmitTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Issuance;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PortoSender\Issuance\IssuanceService;
use PortoSender\Issuance\ConfirmLinkBuilder;
use PortoSender\Captcha\CaptchaVerifier;
use PortoSender\Limiting\RequestLimiter;
use PortoSender\Inventory\CodeStore;
use PortoSender\Requests\RequestStore;
use PortoSender\Mail\MailerInterface;
use PortoSender\Support\Hasher;
use PortoSender\Support\TokenGenerator;
use PortoSender\Support\Clock;
use PortoSender\Settings\Settings;
use PortoSender\Postage\ProductCatalog;

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
        $clock = Mockery::mock(Clock::class);
        $clock->shouldReceive('now')->andReturn(new \DateTimeImmutable('2026-06-24 10:00:00'));
        $svc = new IssuanceService(
            $captcha, $limiter, $codes, $requests, $mailer,
            new Hasher('salt'), new TokenGenerator(),
            Mockery::mock(ConfirmLinkBuilder::class)->shouldReceive('build')->andReturn('https://x.test/c?token=t')->getMock(),
            new Settings(['enabled_products' => ['grossbrief']]), ProductCatalog::default(), $clock
        );
        return [$svc, compact('captcha', 'requests', 'codes', 'mailer')];
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
}
