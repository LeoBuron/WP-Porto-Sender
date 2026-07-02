<?php declare(strict_types=1);
namespace PortoSender\Tests\unit\Notifications;

use PortoSender\Tests\unit\WpUnitTestCase;
use PortoSender\Notifications\AdminNotifier;
use PortoSender\Notifications\NotifyThrottleStore;
use PortoSender\Mail\MailerInterface;
use PortoSender\Settings\Settings;

final class FakeNotifyStore implements NotifyThrottleStore
{
    public int $pending = 0;
    /** @var list<array{name:string,email:string,time:int}> */
    public array $requesters = [];
    /** @var array{product_label:string,remaining:int}|null */
    public ?array $context = null;
    public bool $cooling = false;
    public ?int $armedSeconds = null;
    public function pending(): int { return $this->pending; }
    public function setPending(int $n): void { $this->pending = $n; }
    public function pendingRequesters(): array { return $this->requesters; }
    public function setPendingRequesters(array $requesters): void { $this->requesters = array_values($requesters); }
    public function pendingContext(): ?array { return $this->context; }
    public function setPendingContext(?array $ctx): void { $this->context = $ctx; }
    public function coolingDown(): bool { return $this->cooling; }
    public function arm(int $seconds): void { $this->armedSeconds = $seconds; $this->cooling = true; }
    /** Simulate the cooldown transient expiring (the real store auto-expires). */
    public function expire(): void { $this->cooling = false; }
}

final class AdminNotifierTest extends WpUnitTestCase
{
    private const T = 1751463000; // fixed retrieval timestamp used across the fixtures

    /** @var array<int,array{to:string,data:array}> */
    private array $sent = [];

    private function notifier(Settings $s, FakeNotifyStore $store): AdminNotifier
    {
        $this->sent = [];
        $mailer = \Mockery::mock(MailerInterface::class);
        $mailer->shouldReceive('sendAdminNotification')->andReturnUsing(function ($to, $data) {
            $this->sent[] = ['to' => $to, 'data' => $data];
            return true;
        });
        return new AdminNotifier($s, $mailer, $store);
    }

    /** @param array<string,mixed> $over */
    private function ctx(array $over = []): array
    {
        return array_merge(['product_label' => 'Standardbrief', 'remaining' => 5, 'name' => 'Vera', 'email' => 'v@e.de', 'time' => self::T], $over);
    }

    public function test_disabled_never_sends(): void
    {
        $this->notifier(new Settings(['admin_notify_enabled' => false, 'alert_email' => 'a@b.de']), new FakeNotifyStore())
            ->onIssued($this->ctx());
        $this->assertSame([], $this->sent);
    }

    public function test_no_recipient_never_sends(): void
    {
        $this->notifier(new Settings(['admin_notify_enabled' => true, 'alert_email' => '']), new FakeNotifyStore())
            ->onIssued($this->ctx());
        $this->assertSame([], $this->sent);
    }

    public function test_single_event_sends_once_and_arms_cooldown(): void
    {
        $store = new FakeNotifyStore();
        $this->notifier(new Settings(['alert_email' => 'a@b.de', 'admin_notify_window_minutes' => 15, 'admin_notify_include_pii' => false]), $store)
            ->onIssued($this->ctx(['remaining' => 7]));

        $this->assertCount(1, $this->sent);
        $this->assertSame(1, $this->sent[0]['data']['count']);
        $this->assertSame(7, $this->sent[0]['data']['remaining']);
        $this->assertSame(900, $store->armedSeconds); // 15 * 60
        $this->assertSame([], $this->sent[0]['data']['requesters']); // PII off
    }

    public function test_burst_within_window_sends_one_then_accumulates_pending(): void
    {
        $store = new FakeNotifyStore();
        $n = $this->notifier(new Settings(['alert_email' => 'a@b.de', 'admin_notify_window_minutes' => 15]), $store);
        $n->onIssued($this->ctx()); // leading edge -> sends, arms cooldown
        $n->onIssued($this->ctx()); // cooling -> accumulate
        $n->onIssued($this->ctx()); // cooling -> accumulate

        $this->assertCount(1, $this->sent);
        $this->assertSame(2, $store->pending);
    }

    public function test_carry_over_after_window_sends_one_mail_reporting_true_count(): void
    {
        $store = new FakeNotifyStore();
        $n = $this->notifier(new Settings(['alert_email' => 'a@b.de', 'admin_notify_window_minutes' => 15]), $store);
        $n->onIssued($this->ctx()); // leading edge -> send count=1, arm
        $n->onIssued($this->ctx()); // cooling -> pending=1
        $n->onIssued($this->ctx()); // cooling -> pending=2
        $this->assertCount(1, $this->sent);
        $this->assertSame(2, $store->pending);

        $store->expire();            // the cooldown window elapses
        $n->onIssued($this->ctx());  // carry-over leading edge -> ONE mail, count = pending(2)+1

        $this->assertCount(2, $this->sent);
        $this->assertSame(3, $this->sent[1]['data']['count'], 'reports the true burst size'); // 2 carried + 1 now
        $this->assertSame(0, $store->pending, 'pending reset after the carry-over send');
        $this->assertTrue($store->cooling, 're-armed for the next window');

        // A second window proves pending does not leak across windows.
        $store->expire();
        $n->onIssued($this->ctx());
        $this->assertSame(1, $this->sent[2]['data']['count'], 'fresh window starts the count at 1');
    }

    public function test_throwing_mailer_leaves_burst_retryable(): void
    {
        $store = new FakeNotifyStore();
        $store->pending = 2; // a burst already accumulated, window just elapsed (not cooling)
        $mailer = \Mockery::mock(MailerInterface::class);
        $mailer->shouldReceive('sendAdminNotification')->andThrow(new \RuntimeException('smtp down'));
        $n = new AdminNotifier(new Settings(['alert_email' => 'a@b.de', 'admin_notify_window_minutes' => 15]), $mailer, $store);

        // send() throws; confirm() swallows it in production — here we just let it propagate.
        try {
            $n->onIssued($this->ctx());
            $this->fail('expected the throwing mailer to propagate');
        } catch (\RuntimeException $e) {
            // expected
        }

        // State was NOT committed before the failed send -> the burst stays retryable.
        $this->assertSame(2, $store->pending, 'pending not reset when send throws');
        $this->assertFalse($store->cooling, 'cooldown not armed when send throws');
        $this->assertNull($store->armedSeconds);
    }

    public function test_window_zero_sends_every_event(): void
    {
        $store = new FakeNotifyStore();
        $n = $this->notifier(new Settings(['alert_email' => 'a@b.de', 'admin_notify_window_minutes' => 0]), $store);
        $n->onIssued($this->ctx());
        $n->onIssued($this->ctx());

        $this->assertCount(2, $this->sent);
        $this->assertSame(1, $this->sent[0]['data']['count']);
        $this->assertSame(1, $this->sent[1]['data']['count']);
    }

    public function test_include_pii_passes_requester(): void
    {
        $store = new FakeNotifyStore();
        $this->notifier(new Settings(['alert_email' => 'a@b.de', 'admin_notify_window_minutes' => 0, 'admin_notify_include_pii' => true]), $store)
            ->onIssued($this->ctx(['name' => 'Vera', 'email' => 'v@e.de']));

        $this->assertSame([['name' => 'Vera', 'email' => 'v@e.de', 'time' => self::T]], $this->sent[0]['data']['requesters']);
    }

    public function test_pii_off_never_accumulates_requesters(): void
    {
        $store = new FakeNotifyStore();
        $n = $this->notifier(new Settings(['alert_email' => 'a@b.de', 'admin_notify_window_minutes' => 15, 'admin_notify_include_pii' => false]), $store);
        $n->onIssued($this->ctx(['name' => 'Vera', 'email' => 'v@e.de'])); // leading edge
        $n->onIssued($this->ctx(['name' => 'Bob', 'email' => 'b@e.de']));  // cooling

        $this->assertSame([], $this->sent[0]['data']['requesters']);
        $this->assertSame([], $store->requesters, 'no PII stored when the opt-in is off');
    }

    public function test_burst_with_pii_lists_every_claimant_in_the_carry_over_mail(): void
    {
        $store = new FakeNotifyStore();
        $n = $this->notifier(new Settings(['alert_email' => 'a@b.de', 'admin_notify_window_minutes' => 15, 'admin_notify_include_pii' => true]), $store);

        $n->onIssued($this->ctx(['name' => 'Vera', 'email' => 'v@e.de'])); // leading edge -> mail #1 (Vera)
        $n->onIssued($this->ctx(['name' => 'Bob', 'email' => 'b@e.de']));  // cooling -> accumulate
        $n->onIssued($this->ctx(['name' => 'Cara', 'email' => 'c@e.de'])); // cooling -> accumulate

        // Leading-edge mail names only the first claimant; the rest are pending.
        $this->assertSame([['name' => 'Vera', 'email' => 'v@e.de', 'time' => self::T]], $this->sent[0]['data']['requesters']);
        $this->assertSame(
            [['name' => 'Bob', 'email' => 'b@e.de', 'time' => self::T], ['name' => 'Cara', 'email' => 'c@e.de', 'time' => self::T]],
            $store->requesters
        );

        $store->expire();
        $n->onIssued($this->ctx(['name' => 'Dan', 'email' => 'd@e.de'])); // carry-over -> mail #2 lists Bob, Cara, Dan

        $this->assertCount(2, $this->sent);
        $this->assertSame(3, $this->sent[1]['data']['count']);
        $this->assertSame(
            [
                ['name' => 'Bob', 'email' => 'b@e.de', 'time' => self::T],
                ['name' => 'Cara', 'email' => 'c@e.de', 'time' => self::T],
                ['name' => 'Dan', 'email' => 'd@e.de', 'time' => self::T],
            ],
            $this->sent[1]['data']['requesters']
        );
        $this->assertSame([], $store->requesters, 'requester list reset after the batch mail');
    }

    public function test_disabling_pii_mid_window_stops_and_purges_accumulated_pii(): void
    {
        // A burst accumulates claimant PII while the opt-in is on; the admin then turns it off.
        $store = new FakeNotifyStore();
        $store->pending = 2;
        $store->requesters = [['name' => 'Bob', 'email' => 'b@e.de'], ['name' => 'Cara', 'email' => 'c@e.de']];
        $store->cooling = false; // window elapsed → next claim is a carry-over send

        $this->notifier(new Settings(['alert_email' => 'a@b.de', 'admin_notify_window_minutes' => 15, 'admin_notify_include_pii' => false]), $store)
            ->onIssued($this->ctx(['name' => 'Dan', 'email' => 'd@e.de']));

        // The carry-over mail must NOT leak the previously accumulated PII.
        $this->assertCount(1, $this->sent);
        $this->assertSame(3, $this->sent[0]['data']['count'], 'count still reflects the burst');
        $this->assertSame([], $this->sent[0]['data']['requesters'], 'PII off → no claimants sent');
        $this->assertSame([], $store->requesters, 'accumulated PII purged from the store');
    }

    public function test_disabling_pii_mid_window_purges_during_cooldown(): void
    {
        $store = new FakeNotifyStore();
        $store->pending = 1;
        $store->requesters = [['name' => 'Bob', 'email' => 'b@e.de']];
        $store->cooling = true; // still within the window → accumulate, don't send

        $this->notifier(new Settings(['alert_email' => 'a@b.de', 'admin_notify_window_minutes' => 15, 'admin_notify_include_pii' => false]), $store)
            ->onIssued($this->ctx(['name' => 'Cara', 'email' => 'c@e.de']));

        $this->assertCount(0, $this->sent, 'still cooling — no mail');
        $this->assertSame([], $store->requesters, 'PII off purges the accumulated list');
        $this->assertSame(2, $store->pending, 'count still accumulates');
    }

    public function test_window_zero_drains_a_batch_stranded_by_a_former_window(): void
    {
        $store = new FakeNotifyStore();
        $store->pending = 2;
        $store->requesters = [['name' => 'Bob', 'email' => 'b@e.de'], ['name' => 'Cara', 'email' => 'c@e.de']];

        $this->notifier(new Settings(['alert_email' => 'a@b.de', 'admin_notify_window_minutes' => 0, 'admin_notify_include_pii' => true]), $store)
            ->onIssued($this->ctx(['name' => 'Dan', 'email' => 'd@e.de']));

        // The current claim is reported on its own; the stranded batch's PII is dropped.
        $this->assertCount(1, $this->sent);
        $this->assertSame(1, $this->sent[0]['data']['count']);
        $this->assertSame([['name' => 'Dan', 'email' => 'd@e.de', 'time' => self::T]], $this->sent[0]['data']['requesters']);
        $this->assertSame(0, $store->pending);
        $this->assertSame([], $store->requesters);
    }

    public function test_purge_flushes_a_stranded_batch_then_clears_when_not_cooling(): void
    {
        $store = new FakeNotifyStore();
        $store->pending = 2;
        $store->requesters = [['name' => 'Bob', 'email' => 'b@e.de', 'time' => self::T]];
        $store->context = ['product_label' => 'Großbrief', 'remaining' => 4];

        $n = $this->notifier(new Settings(['alert_email' => 'a@b.de', 'admin_notify_include_pii' => true]), $store);

        // Still cooling → leave the batch for the next claim to flush.
        $store->cooling = true;
        $n->purgeStalePendingBatch();
        $this->assertCount(0, $this->sent, 'cooling → not flushed');
        $this->assertSame(2, $store->pending);
        $this->assertSame([['name' => 'Bob', 'email' => 'b@e.de', 'time' => self::T]], $store->requesters);

        // Window elapsed → FLUSH (send) the stranded batch instead of discarding it (the bug fix),
        // using the stored context for product/remaining, then clear everything.
        $store->cooling = false;
        $n->purgeStalePendingBatch();
        $this->assertCount(1, $this->sent, 'flushed the stranded batch');
        $this->assertSame(2, $this->sent[0]['data']['count']);
        $this->assertSame('Großbrief', $this->sent[0]['data']['product_label']);
        $this->assertSame(4, $this->sent[0]['data']['remaining']);
        $this->assertSame([['name' => 'Bob', 'email' => 'b@e.de', 'time' => self::T]], $this->sent[0]['data']['requesters']);
        $this->assertSame(0, $store->pending, 'cleared after flush');
        $this->assertSame([], $store->requesters);
        $this->assertNull($store->context);
    }

    public function test_purge_flush_re_gates_pii_on_the_current_setting(): void
    {
        $store = new FakeNotifyStore();
        $store->pending = 3;
        $store->requesters = [['name' => 'Bob', 'email' => 'b@e.de', 'time' => self::T]];
        $store->context = ['product_label' => 'Standardbrief', 'remaining' => 2];
        $store->cooling = false;

        $this->notifier(new Settings(['alert_email' => 'a@b.de', 'admin_notify_include_pii' => false]), $store)
            ->purgeStalePendingBatch();

        $this->assertCount(1, $this->sent, 'still flushed the count');
        $this->assertSame(3, $this->sent[0]['data']['count']);
        $this->assertSame([], $this->sent[0]['data']['requesters'], 'PII now off → counts only, no claimants');
        $this->assertSame([], $store->requesters);
    }

    public function test_empty_stranded_state_is_not_mailed(): void
    {
        $store = new FakeNotifyStore();
        $store->cooling = false; // nothing pending
        $this->notifier(new Settings(['alert_email' => 'a@b.de']), $store)->purgeStalePendingBatch();
        $this->assertCount(0, $this->sent);
    }

    public function test_retrieval_time_is_carried_into_the_requester_entry(): void
    {
        $store = new FakeNotifyStore();
        $this->notifier(new Settings(['alert_email' => 'a@b.de', 'admin_notify_window_minutes' => 0, 'admin_notify_include_pii' => true]), $store)
            ->onIssued($this->ctx(['name' => 'Vera', 'email' => 'v@e.de', 'time' => 1700000000]));
        $this->assertSame([['name' => 'Vera', 'email' => 'v@e.de', 'time' => 1700000000]], $this->sent[0]['data']['requesters']);
    }

    public function test_accumulating_stores_context_for_a_later_flush(): void
    {
        $store = new FakeNotifyStore();
        $n = $this->notifier(new Settings(['alert_email' => 'a@b.de', 'admin_notify_window_minutes' => 15, 'admin_notify_include_pii' => true]), $store);
        $n->onIssued($this->ctx(['product_label' => 'Großbrief', 'remaining' => 9])); // leading edge → context cleared
        $this->assertNull($store->context, 'leading-edge send clears any stale context');
        $n->onIssued($this->ctx(['product_label' => 'Standardbrief', 'remaining' => 8])); // accumulate → store context
        $this->assertSame(['product_label' => 'Standardbrief', 'remaining' => 8], $store->context);
    }
}
