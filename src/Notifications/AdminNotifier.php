<?php
declare(strict_types=1);

namespace PortoSender\Notifications;

use PortoSender\Settings\Settings;
use PortoSender\Mail\MailerInterface;

/**
 * Notifies the admin when a visitor claims a porto code, throttled so a burst of
 * claims is not a burst of mails.
 *
 * Throttle (rolling cooldown with carry-over): the first event sends immediately
 * (leading edge) and arms a cooldown for `admin_notify_window_minutes`; events
 * during the cooldown only increment a pending counter; the first event AFTER the
 * cooldown sends a single mail reporting pending+1, so a burst collapses into one
 * mail that still states its true size. A window of 0 disables throttling
 * (every event sends). The mail is PII-free unless `admin_notify_include_pii`.
 */
final class AdminNotifier
{
    public function __construct(
        private Settings $settings,
        private MailerInterface $mailer,
        private NotifyThrottleStore $store,
    ) {
    }

    /**
     * @param array{product_label:string,remaining:int,name:?string,email:?string} $ctx
     */
    public function onIssued(array $ctx): void
    {
        if (!$this->settings->adminNotifyEnabled()) {
            return;
        }
        $to = $this->settings->alertEmail();
        if ($to === '') {
            return; // no recipient configured
        }

        $windowMinutes = $this->settings->adminNotifyWindowMinutes();
        if ($windowMinutes <= 0) {
            $this->send($to, $ctx, 1);
            return;
        }

        $pending = $this->store->pending() + 1;
        if ($this->store->coolingDown()) {
            $this->store->setPending($pending); // accumulate, don't mail
            return;
        }

        // Leading edge: send FIRST, then commit throttle state. If send() throws (e.g. an
        // SMTP plugin that raises rather than returning false), pending stays accumulated and
        // the cooldown is not armed, so the burst remains retryable on the next event instead
        // of being silently swallowed for a whole window.
        $this->send($to, $ctx, $pending);
        $this->store->setPending(0);
        $this->store->arm($windowMinutes * 60);
    }

    /**
     * @param array{product_label:string,remaining:int,name:?string,email:?string} $ctx
     */
    private function send(string $to, array $ctx, int $count): void
    {
        $includePii = $this->settings->adminNotifyIncludePii();
        $this->mailer->sendAdminNotification($to, [
            'product_label' => (string) $ctx['product_label'],
            'count' => $count,
            'remaining' => (int) $ctx['remaining'],
            'name' => $includePii ? ($ctx['name'] ?? null) : null,
            'email' => $includePii ? ($ctx['email'] ?? null) : null,
        ]);
    }
}
