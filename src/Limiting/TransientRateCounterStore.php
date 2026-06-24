<?php
declare(strict_types=1);
namespace PortoSender\Limiting;

final class TransientRateCounterStore implements RateCounterStore
{
    public function hit(string $key, int $ttlSeconds): ?int
    {
        $current = get_transient($key);
        $next = (false === $current ? 0 : (int) $current) + 1;
        // The bucket lives in $key, so the count resets when the caller's bucket rolls over;
        // $ttlSeconds is GC only. $next always differs from $current, so set_transient never
        // returns the "value unchanged" false — a false here means a real write failure.
        return set_transient($key, $next, $ttlSeconds) ? $next : null;
    }
}
