<?php // tests/integration/Cron/MaintenanceTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Cron;
use Mockery;
use PortoSender\Tests\integration\PortoTestCase;
use PortoSender\Cron\Maintenance;
use PortoSender\Inventory\{CodeRepository, StockAlerter};
use PortoSender\Requests\RequestRepository;
use PortoSender\Mail\Mailer;
use PortoSender\Settings\Settings;
use PortoSender\Postage\ProductCatalog;
use PortoSender\Support\Clock;

final class MaintenanceTest extends PortoTestCase
{
    public function test_run_quarantines_expired_and_deletes_old_pending(): void
    {
        global $wpdb;
        $codes = new CodeRepository($wpdb);
        $requests = new RequestRepository($wpdb);
        $codes->addBatch('grossbrief', 180, new \DateTimeImmutable('2020-01-01'), ['EXP']); // expires 2023
        $requests->createPending([
            'name' => 'X', 'email' => 'x@e.de', 'email_hash' => str_repeat('a', 64), 'name_hash' => str_repeat('b', 64),
            'product' => 'grossbrief', 'token_hash' => str_repeat('c', 64), 'ip_hash' => null, 'created_at' => '2026-06-01 10:00:00',
        ]);

        $clock = Mockery::mock(Clock::class);
        $clock->shouldReceive('now')->andReturn(new \DateTimeImmutable('2026-06-24 03:00:00'));
        $settings = new Settings(['enabled_products' => ['grossbrief'], 'alert_email' => '', 'pii_retention_days' => 180, 'unconfirmed_retention_days' => 7]);
        $alerter = new StockAlerter($codes, $settings, new Mailer($settings), ProductCatalog::default(), $clock);

        (new Maintenance($codes, $requests, $alerter, $settings, $clock))->run();

        $this->assertSame(1, $codes->countsByStatus('grossbrief')['expired']);
        // The unconfirmed request is ~23 days old > the 7-day retention window -> purged.
        $this->assertNull($requests->findByTokenHash(str_repeat('c', 64)));
    }
}
