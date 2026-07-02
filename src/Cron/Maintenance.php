<?php
declare(strict_types=1);
namespace PortoSender\Cron;

use PortoSender\Inventory\CodeStore;
use PortoSender\Inventory\StockAlerter;
use PortoSender\Notifications\AdminNotifier;
use PortoSender\Requests\RequestStore;
use PortoSender\Settings\Settings;
use PortoSender\Support\Clock;

final class Maintenance
{
    public const HOOK = 'porto_sender_daily';

    public function __construct(
        private CodeStore $codes,
        private RequestStore $requests,
        private StockAlerter $alerter,
        private Settings $settings,
        private Clock $clock,
        private ?AdminNotifier $notifier = null,
    ) {}

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
    }

    public function run(): void
    {
        $now = $this->clock->now();
        $this->codes->releaseStaleReservations($now);
        $this->codes->quarantineExpired($now);
        // Retain never-confirmed requests for the configured window (abuse/fraud audit), then purge.
        // Token EXPIRY is separate (confirm() rejects tokens past confirm_token_ttl_hours).
        $this->requests->deleteUnconfirmedOlderThan($now->modify('-' . $this->settings->unconfirmedRetentionDays() . ' days'));
        $this->requests->anonymizeOlderThan($now->modify('-' . $this->settings->piiRetentionDays() . ' days'));
        // Bound admin-notification PII at rest: drop a batch left un-flushed after its window.
        $this->notifier?->purgeStalePendingBatch();
        $this->alerter->evaluate();
    }
}
