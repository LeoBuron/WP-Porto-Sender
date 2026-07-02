<?php
declare(strict_types=1);

namespace PortoSender\Notifications;

/**
 * WordPress implementation of the notification throttle state.
 *
 * The pending count and the pending requester list are autoload=false options (must
 * survive across the window); the cooldown is a transient whose TTL is the window
 * itself. All use the `porto_notify_` prefix so DataEraser/uninstall can purge them.
 * The requester list only holds data while PII notifications are enabled and is cleared
 * as soon as the batch mail goes out.
 */
final class WpNotifyThrottleStore implements NotifyThrottleStore
{
    public const PENDING_OPTION = 'porto_notify_pending';
    public const REQUESTERS_OPTION = 'porto_notify_pending_requesters';
    public const CONTEXT_OPTION = 'porto_notify_pending_context';
    public const COOLDOWN_TRANSIENT = 'porto_notify_cooldown';

    public function pending(): int
    {
        return (int) get_option(self::PENDING_OPTION, 0);
    }

    public function setPending(int $n): void
    {
        update_option(self::PENDING_OPTION, $n, false);
    }

    public function pendingRequesters(): array
    {
        $stored = get_option(self::REQUESTERS_OPTION, []);
        if (!is_array($stored)) {
            return [];
        }
        $out = [];
        foreach ($stored as $r) {
            if (is_array($r)) {
                $out[] = [
                    'name' => (string) ($r['name'] ?? ''),
                    'email' => (string) ($r['email'] ?? ''),
                    'time' => (int) ($r['time'] ?? 0),
                ];
            }
        }
        return $out;
    }

    public function setPendingRequesters(array $requesters): void
    {
        if ($requesters === []) {
            delete_option(self::REQUESTERS_OPTION); // keep no PII at rest once the batch is flushed
            return;
        }
        update_option(self::REQUESTERS_OPTION, array_values($requesters), false);
    }

    public function pendingContext(): ?array
    {
        $stored = get_option(self::CONTEXT_OPTION, null);
        if (!is_array($stored)) {
            return null;
        }
        return [
            'product_label' => (string) ($stored['product_label'] ?? ''),
            'remaining' => (int) ($stored['remaining'] ?? 0),
        ];
    }

    public function setPendingContext(?array $ctx): void
    {
        if ($ctx === null) {
            delete_option(self::CONTEXT_OPTION);
            return;
        }
        update_option(self::CONTEXT_OPTION, [
            'product_label' => (string) ($ctx['product_label'] ?? ''),
            'remaining' => (int) ($ctx['remaining'] ?? 0),
        ], false);
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
