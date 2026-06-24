<?php
declare(strict_types=1);

namespace PortoSender\Notifications;

/**
 * WordPress implementation of the notification throttle state.
 *
 * The pending count is an autoload=false option (must survive across the window),
 * the cooldown is a transient whose TTL is the window itself. Both use the
 * `porto_notify_` prefix so DataEraser/uninstall can purge them.
 */
final class WpNotifyThrottleStore implements NotifyThrottleStore
{
    public const PENDING_OPTION = 'porto_notify_pending';
    public const COOLDOWN_TRANSIENT = 'porto_notify_cooldown';

    public function pending(): int
    {
        return (int) get_option(self::PENDING_OPTION, 0);
    }

    public function setPending(int $n): void
    {
        update_option(self::PENDING_OPTION, $n, false);
    }

    public function coolingDown(): bool
    {
        return get_transient(self::COOLDOWN_TRANSIENT) !== false;
    }

    public function arm(int $seconds): void
    {
        set_transient(self::COOLDOWN_TRANSIENT, 1, $seconds);
    }
}
