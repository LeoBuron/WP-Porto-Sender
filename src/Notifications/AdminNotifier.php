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

        // Only carry PII (name/email) into the batch when the admin opted in; otherwise the
        // notification stays PII-free and just reports counts.
        $requester = null;
        if ($this->settings->adminNotifyIncludePii()) {
            $name = trim((string) ($ctx['name'] ?? ''));
            $email = trim((string) ($ctx['email'] ?? ''));
            if ($name !== '' || $email !== '') {
                $requester = ['name' => $name, 'email' => $email, 'time' => (int) ($ctx['time'] ?? 0)];
            }
        }

        $windowMinutes = $this->settings->adminNotifyWindowMinutes();
        if ($windowMinutes <= 0) {
            $this->send($to, $ctx, 1, $requester !== null ? [$requester] : []);
            // Throttling is off now; drop any batch stranded by a former windowed period so
            // its accumulated claimant PII is not left at rest.
            $this->discardPending();
            return;
        }

        $pending = $this->store->pending() + 1;
        // Re-gate the accumulated list on the CURRENT setting: if the admin switched PII off
        // mid-window, never carry (or re-persist) previously accumulated claimant PII — the
        // mail stays PII-free per the class contract, and toggling off purges the stored list.
        $requesters = $this->settings->adminNotifyIncludePii() ? $this->store->pendingRequesters() : [];
        if ($requester !== null) {
            $requesters[] = $requester;
        }

        if ($this->store->coolingDown()) {
            $this->store->setPending($pending); // accumulate, don't mail
            $this->store->setPendingRequesters($requesters); // [] when PII off → option deleted
            // Remember this claim's product/remaining so a batch stranded past its window can
            // still be flushed as a COMPLETE mail by daily maintenance (see purgeStalePendingBatch).
            $this->store->setPendingContext([
                'product_label' => (string) $ctx['product_label'],
                'remaining' => (int) $ctx['remaining'],
            ]);
            return;
        }

        // Leading edge: send FIRST, then commit throttle state. If send() throws (e.g. an
        // SMTP plugin that raises rather than returning false), pending stays accumulated and
        // the cooldown is not armed, so the burst remains retryable on the next event instead
        // of being silently swallowed for a whole window.
        $this->send($to, $ctx, $pending, $requesters);
        $this->store->setPending(0);
        $this->store->setPendingRequesters([]);
        $this->store->setPendingContext(null);
        $this->store->arm($windowMinutes * 60);
    }

    /**
     * Daily-maintenance seam: FLUSH a batch left un-flushed after its window elapsed (e.g. the
     * site went quiet before the next claim could carry it over) — send it, then clear it.
     *
     * Previously this DISCARDED the stranded batch, which silently lost every notification
     * after the leading edge on a low-traffic site (the daily cron ran before the next claim).
     * Now it sends the accumulated claims (using the stored product/remaining context) and only
     * then clears, so nothing is lost while retention stays bounded to one maintenance cycle.
     * A batch still inside its cooldown is left alone — the next claim will flush it normally.
     */
    public function purgeStalePendingBatch(): void
    {
        if ($this->store->coolingDown()) {
            return;
        }
        if ($this->store->pending() <= 0) {
            $this->discardPending(); // nothing to send; clear any stray requesters/context
            return;
        }

        if ($this->settings->adminNotifyEnabled() && ($to = $this->settings->alertEmail()) !== '') {
            $ctx = $this->store->pendingContext() ?? ['product_label' => '', 'remaining' => 0];
            // Re-gate on the CURRENT PII setting (like onIssued): PII turned off → counts only.
            $requesters = $this->settings->adminNotifyIncludePii() ? $this->store->pendingRequesters() : [];
            $this->send($to, $ctx, $this->store->pending(), $requesters);
        }

        // Whether or not a mail went out (disabled / no recipient), clear the batch.
        $this->discardPending();
    }

    /** Reset the pending batch state (count + accumulated claimants + context) if anything is stored. */
    private function discardPending(): void
    {
        if ($this->store->pending() !== 0) {
            $this->store->setPending(0);
        }
        if ($this->store->pendingRequesters() !== []) {
            $this->store->setPendingRequesters([]);
        }
        if ($this->store->pendingContext() !== null) {
            $this->store->setPendingContext(null);
        }
    }

    /**
     * @param array{product_label:string,remaining:int,name:?string,email:?string} $ctx
     * @param list<array{name:string,email:string}> $requesters claimants for this batch (empty when PII off)
     */
    private function send(string $to, array $ctx, int $count, array $requesters): void
    {
        $this->mailer->sendAdminNotification($to, [
            'product_label' => (string) $ctx['product_label'],
            'count' => $count,
            'remaining' => (int) $ctx['remaining'],
            'requesters' => $requesters,
        ]);
    }
}
