<?php
declare(strict_types=1);

namespace PortoSender\Notifications;

/**
 * State seam for the admin-notification throttle: a persisted "pending" count
 * (events seen during a cooldown, carried to the next send), the requester list
 * behind that count (only populated when PII notifications are enabled, so a batch
 * mail can name every claimant), and a cooldown flag that auto-expires after the
 * window. Abstracted so AdminNotifier is unit-testable with an in-memory fake.
 */
interface NotifyThrottleStore
{
    public function pending(): int;
    public function setPending(int $n): void;

    /** @return list<array{name:string,email:string,time:int}> accumulated claimants for the pending batch */
    public function pendingRequesters(): array;

    /** @param list<array{name:string,email:string,time:int}> $requesters */
    public function setPendingRequesters(array $requesters): void;

    /**
     * The last accumulated claim's context (product + remaining stock), so a batch
     * stranded past its window can be flushed as a complete mail by daily maintenance.
     *
     * @return array{product_label:string,remaining:int}|null null when no batch is pending
     */
    public function pendingContext(): ?array;

    /** @param array{product_label:string,remaining:int}|null $ctx null clears it */
    public function setPendingContext(?array $ctx): void;

    public function coolingDown(): bool;
    public function arm(int $seconds): void;
}
