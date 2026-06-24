<?php // tests/integration/AdminNotificationFlowTest.php
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
use PortoSender\Notifications\AdminNotifier;
use PortoSender\Notifications\WpNotifyThrottleStore;
use PortoSender\Support\{Hasher, TokenGenerator, SystemClock};
use PortoSender\Settings\Settings;
use PortoSender\Postage\ProductCatalog;

final class AdminNotificationFlowTest extends PortoTestCase
{
    /** @var array<int,array<string,mixed>> */
    private array $mails = [];

    private function captureMail(): void
    {
        $this->mails = [];
        add_filter('pre_wp_mail', function ($null, $atts) {
            $this->mails[] = $atts;
            return true; // short-circuit as "sent"
        }, 10, 2);
    }

    /** @param array<string,mixed> $settingsOverrides */
    private function issueOnce(array $settingsOverrides): string
    {
        global $wpdb;
        $codes = new CodeRepository($wpdb);
        $requests = new RequestRepository($wpdb);
        $settings = new Settings(array_merge(['enabled_products' => ['grossbrief'], 'owner_address' => 'Leo'], $settingsOverrides));
        $hasher = new Hasher('salt');
        $notifier = new AdminNotifier($settings, new Mailer($settings), new WpNotifyThrottleStore());

        $svc = new IssuanceService(
            new NullVerifier(), new RequestLimiter($requests),
            new RateLimiter(new TransientRateCounterStore(), $settings, new SystemClock()),
            $codes, $requests, new Mailer($settings), $hasher, new TokenGenerator(),
            new UrlConfirmLinkBuilder(), $settings, ProductCatalog::default(), new SystemClock(), $notifier
        );

        $codes->addBatch('grossbrief', 180, new \DateTimeImmutable('2026-01-01'), ['NOTIFYCODE1', 'NOTIFYCODE2']);
        $requests->createPending([
            'name' => 'Vera', 'email' => 'v@example.de',
            'email_hash' => $hasher->email('v@example.de'), 'name_hash' => $hasher->name('Vera'),
            'product' => 'grossbrief', 'token_hash' => $hasher->token('NTOK'),
            'ip_hash' => null, 'created_at' => (new SystemClock())->now()->format('Y-m-d H:i:s'),
        ]);

        return $svc->confirm('NTOK')['status'];
    }

    public function test_issuing_a_code_sends_one_admin_notification(): void
    {
        $this->captureMail();
        $status = $this->issueOnce(['alert_email' => 'admin@example.de', 'admin_notify_enabled' => true, 'admin_notify_window_minutes' => 0]);
        $this->assertSame('issued', $status);

        $adminMails = array_filter($this->mails, fn($m) => ($m['to'] ?? '') === 'admin@example.de');
        $this->assertCount(1, $adminMails, 'exactly one admin notification expected');
        $mail = array_values($adminMails)[0];
        $this->assertStringContainsString('Porto abgerufen', (string) $mail['subject']);
    }

    public function test_no_admin_notification_when_disabled(): void
    {
        $this->captureMail();
        $status = $this->issueOnce(['alert_email' => 'admin@example.de', 'admin_notify_enabled' => false]);
        $this->assertSame('issued', $status);

        $adminMails = array_filter($this->mails, fn($m) => ($m['to'] ?? '') === 'admin@example.de');
        $this->assertCount(0, $adminMails);
    }
}
