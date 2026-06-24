<?php
declare(strict_types=1);
namespace PortoSender\Limiting;

use PortoSender\Settings\Settings;
use PortoSender\Support\Clock;

final class RateLimiter
{
    private const DAY = 86400;
    private const HOUR = 3600;

    public function __construct(
        private RateCounterStore $store,
        private Settings $settings,
        private Clock $clock,
        private bool $failOpen = true,
    ) {}

    public function check(string $ipHash): bool
    {
        if (!$this->settings->rateLimitEnabled()) {
            return true;
        }

        $ts = $this->clock->now()->getTimestamp();

        // Per-IP first; a per-IP denial must NOT increment the global counter, or one IP could
        // exhaust the global ceiling for everyone.
        $perIpKey = 'porto_rl_ip_' . $ipHash . '_' . intdiv($ts, self::DAY);
        $perIp = $this->store->hit($perIpKey, self::DAY);
        if ($perIp === null) {
            return $this->failOpen;
        }
        if ($perIp > $this->settings->rateLimitPerIpDay()) {
            return false;
        }

        $globalKey = 'porto_rl_g_' . intdiv($ts, self::HOUR);
        $global = $this->store->hit($globalKey, self::HOUR);
        if ($global === null) {
            return $this->failOpen;
        }
        if ($global > $this->settings->rateLimitGlobalHour()) {
            return false;
        }

        return true;
    }
}
