<?php
declare(strict_types=1);
namespace PortoSender\Limiting;

interface RateCounterStore
{
    /**
     * Increment the counter at $key (creating it with $ttlSeconds on first hit) and return the
     * new count, or null if the underlying store could not be written. A null return means the
     * store is broken — a missing key still writes fine and returns 1.
     */
    public function hit(string $key, int $ttlSeconds): ?int;
}
