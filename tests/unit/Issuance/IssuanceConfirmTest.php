<?php // tests/unit/Issuance/IssuanceConfirmTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Issuance;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PortoSender\Issuance\IssuanceService;
use PortoSender\Issuance\ConfirmLinkBuilder;
use PortoSender\Captcha\NullVerifier;
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

final class IssuanceConfirmTest extends MockeryTestCase
{
    private Hasher $hasher;
    private function service(CodeStore $codes, RequestStore $requests, MailerInterface $mailer): IssuanceService
    {
        $this->hasher = new Hasher('salt');
        $clock = Mockery::mock(Clock::class);
        $clock->shouldReceive('now')->andReturn(new \DateTimeImmutable('2026-06-24 10:00:00'));
        $settings = new Settings();
        return new IssuanceService(
            new NullVerifier(), new RequestLimiter(Mockery::mock(RequestStore::class)),
            new RateLimiter(new InMemoryRateCounterStore(), $settings, $clock),
            $codes, $requests, $mailer, $this->hasher, new TokenGenerator(),
            Mockery::mock(ConfirmLinkBuilder::class), $settings, ProductCatalog::default(), $clock
        );
    }

    public function test_unknown_token(): void
    {
        $requests = Mockery::mock(RequestStore::class);
        $requests->shouldReceive('findByTokenHash')->andReturn(null);
        $svc = $this->service(Mockery::mock(CodeStore::class), $requests, Mockery::mock(MailerInterface::class));
        $this->assertSame('invalid_token', $svc->confirm('whatever')['status']);
    }

    public function test_expired_token(): void
    {
        $requests = Mockery::mock(RequestStore::class);
        $requests->shouldReceive('findByTokenHash')->andReturn((object) [
            'id' => 1, 'status' => 'pending', 'product' => 'grossbrief', 'email' => 'v@e.de',
            'name' => 'V', 'email_hash' => 'E', 'created_at' => '2026-06-20 10:00:00',
        ]);
        $svc = $this->service(Mockery::mock(CodeStore::class), $requests, Mockery::mock(MailerInterface::class));
        $this->assertSame('expired', $svc->confirm('t')['status']); // >48h old
    }

    public function test_happy_path_issues_code_and_emails_it(): void
    {
        $requests = Mockery::mock(RequestStore::class);
        $requests->shouldReceive('findByTokenHash')->andReturn((object) [
            'id' => 42, 'status' => 'pending', 'product' => 'grossbrief', 'email' => 'v@e.de',
            'name' => 'Vera', 'email_hash' => 'EHASH', 'created_at' => '2026-06-24 09:30:00',
        ]);
        $requests->shouldReceive('markConfirmed')->once()->andReturn(true);
        $requests->shouldReceive('markIssued')->once()->with(42, 7, Mockery::type(\DateTimeImmutable::class))->andReturn(true);
        $codes = Mockery::mock(CodeStore::class);
        $codes->shouldReceive('claimOne')->once()->andReturn(7);
        $codes->shouldReceive('markIssued')->once()->with(7, 42, 'EHASH', Mockery::type(\DateTimeImmutable::class))->andReturn(true);
        $codes->shouldReceive('getCode')->with(7)->andReturn((object) ['code' => 'AB12CD34']);
        $mailer = Mockery::mock(MailerInterface::class);
        $mailer->shouldReceive('sendDelivery')->once()->andReturn(true);
        $svc = $this->service($codes, $requests, $mailer);
        $this->assertSame('issued', $svc->confirm('t')['status']);
    }

    public function test_email_failure_does_not_spend_code(): void
    {
        // If the delivery email fails, the code must stay 'reserved' (neither codes nor
        // requests are marked issued) so the cron reclaims it and the token can be retried.
        $requests = Mockery::mock(RequestStore::class);
        $requests->shouldReceive('findByTokenHash')->andReturn((object) [
            'id' => 42, 'status' => 'pending', 'product' => 'grossbrief', 'email' => 'v@e.de',
            'name' => 'Vera', 'email_hash' => 'EHASH', 'created_at' => '2026-06-24 09:30:00',
        ]);
        $requests->shouldReceive('markConfirmed')->once()->andReturn(true);
        $requests->shouldNotReceive('markIssued');
        $codes = Mockery::mock(CodeStore::class);
        $codes->shouldReceive('claimOne')->once()->andReturn(7);
        $codes->shouldReceive('getCode')->with(7)->andReturn((object) ['code' => 'AB12CD34']);
        $codes->shouldNotReceive('markIssued');
        $mailer = Mockery::mock(MailerInterface::class);
        $mailer->shouldReceive('sendDelivery')->once()->andReturn(false);
        $svc = $this->service($codes, $requests, $mailer);
        $this->assertSame('email_failed', $svc->confirm('t')['status']);
    }

    public function test_out_of_stock_when_claim_fails(): void
    {
        $requests = Mockery::mock(RequestStore::class);
        $requests->shouldReceive('findByTokenHash')->andReturn((object) [
            'id' => 42, 'status' => 'confirmed', 'product' => 'grossbrief', 'email' => 'v@e.de',
            'name' => 'Vera', 'email_hash' => 'EHASH', 'created_at' => '2026-06-24 09:30:00',
        ]);
        $requests->shouldReceive('markConfirmed')->andReturn(false); // already confirmed
        $codes = Mockery::mock(CodeStore::class);
        $codes->shouldReceive('claimOne')->times(3)->andReturn(null);
        $svc = $this->service($codes, $requests, Mockery::mock(MailerInterface::class));
        $this->assertSame('out_of_stock', $svc->confirm('t')['status']);
    }

    public function test_already_issued_is_idempotent(): void
    {
        $requests = Mockery::mock(RequestStore::class);
        $requests->shouldReceive('findByTokenHash')->andReturn((object) ['id' => 42, 'status' => 'issued']);
        $svc = $this->service(Mockery::mock(CodeStore::class), $requests, Mockery::mock(MailerInterface::class));
        $this->assertSame('already_issued', $svc->confirm('t')['status']);
    }
}
