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
    public bool $cooling = false;
    public ?int $armedSeconds = null;
    public function pending(): int { return $this->pending; }
    public function setPending(int $n): void { $this->pending = $n; }
    public function coolingDown(): bool { return $this->cooling; }
    public function arm(int $seconds): void { $this->armedSeconds = $seconds; $this->cooling = true; }
    /** Simulate the cooldown transient expiring (the real store auto-expires). */
    public function expire(): void { $this->cooling = false; }
}

final class AdminNotifierTest extends WpUnitTestCase
{
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
        return array_merge(['product_label' => 'Standardbrief', 'remaining' => 5, 'name' => 'Vera', 'email' => 'v@e.de'], $over);
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
        $this->assertNull($this->sent[0]['data']['name']); // PII off
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

    public function test_include_pii_passes_name_and_email(): void
    {
        $store = new FakeNotifyStore();
        $this->notifier(new Settings(['alert_email' => 'a@b.de', 'admin_notify_window_minutes' => 0, 'admin_notify_include_pii' => true]), $store)
            ->onIssued($this->ctx(['name' => 'Vera', 'email' => 'v@e.de']));

        $this->assertSame('Vera', $this->sent[0]['data']['name']);
        $this->assertSame('v@e.de', $this->sent[0]['data']['email']);
    }
}
