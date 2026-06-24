<?php
declare(strict_types=1);

namespace PortoSender\Notifications;

/**
 * State seam for the admin-notification throttle: a persisted "pending" count
 * (events seen during a cooldown, carried to the next send) and a cooldown flag
 * that auto-expires after the window. Abstracted so AdminNotifier is unit-testable
 * with an in-memory fake.
 */
interface NotifyThrottleStore
{
    public function pending(): int;
    public function setPending(int $n): void;
    public function coolingDown(): bool;
    public function arm(int $seconds): void;
}
