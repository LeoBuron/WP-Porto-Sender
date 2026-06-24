<?php
declare(strict_types=1);
namespace PortoSender\Cron;

use PortoSender\Inventory\CodeStore;
use PortoSender\Inventory\StockAlerter;
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
        $this->requests->deleteExpiredPending($now, $this->settings->confirmTokenTtlHours());
        $this->requests->anonymizeOlderThan($now->modify('-' . $this->settings->piiRetentionDays() . ' days'));
        $this->alerter->evaluate();
    }
}
