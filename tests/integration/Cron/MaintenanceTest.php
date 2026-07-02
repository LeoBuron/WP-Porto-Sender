<?php // tests/integration/Cron/MaintenanceTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Cron;
use Mockery;
use PortoSender\Tests\integration\PortoTestCase;
use PortoSender\Cron\Maintenance;
use PortoSender\Inventory\{CodeRepository, StockAlerter};
use PortoSender\Notifications\{AdminNotifier, WpNotifyThrottleStore};
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
        $codes->addBatch('grossbrief', new \DateTimeImmutable('2020-01-01'), ['EXP']); // expires 2023
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

    public function test_run_flushes_a_stale_pending_notification_batch(): void
    {
        global $wpdb;
        $codes = new CodeRepository($wpdb);
        $requests = new RequestRepository($wpdb);
        $settings = new Settings(['enabled_products' => ['grossbrief'], 'alert_email' => 'a@b.de', 'admin_notify_include_pii' => true]);
        $clock = Mockery::mock(Clock::class);
        $clock->shouldReceive('now')->andReturn(new \DateTimeImmutable('2026-06-24 03:00:00'));
        $alerter = new StockAlerter($codes, $settings, new Mailer($settings), ProductCatalog::default(), $clock);

        $mails = [];
        add_filter('pre_wp_mail', function ($null, $atts) use (&$mails) { $mails[] = $atts; return true; }, 10, 2);

        // A batch accumulated claimant PII but was never flushed and its cooldown has elapsed.
        $store = new WpNotifyThrottleStore();
        $store->setPending(2);
        $store->setPendingRequesters([['name' => 'Bob', 'email' => 'b@e.de', 'time' => 0], ['name' => 'Cara', 'email' => 'c@e.de', 'time' => 0]]);
        $store->setPendingContext(['product_label' => 'Großbrief', 'remaining' => 4]);
        delete_transient(WpNotifyThrottleStore::COOLDOWN_TRANSIENT); // not cooling

        $notifier = new AdminNotifier($settings, new Mailer($settings), $store);
        (new Maintenance($codes, $requests, $alerter, $settings, $clock, $notifier))->run();

        // Bug fix: the stranded batch is SENT (not discarded). Filter by body so a possible
        // stock-alert mail to the same address doesn't confuse the assertion.
        $flush = array_values(array_filter($mails, fn($m) => str_contains((string) ($m['message'] ?? ''), '- Bob <b@e.de>')));
        $this->assertCount(1, $flush, 'stranded batch flushed as one mail');
        $this->assertStringContainsString('- Cara <c@e.de>', (string) $flush[0]['message']);

        $this->assertSame(0, $store->pending(), 'count cleared after flush');
        $this->assertSame([], $store->pendingRequesters(), 'claimant PII cleared after flush');
    }
}
