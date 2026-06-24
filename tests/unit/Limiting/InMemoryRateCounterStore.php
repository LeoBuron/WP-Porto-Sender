<?php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Limiting;

use PortoSender\Limiting\RateCounterStore;

final class InMemoryRateCounterStore implements RateCounterStore
{
    /** @var array<string,int> */
    public array $counts = [];
    public bool $broken = false;

    public function hit(string $key, int $ttlSeconds): ?int
    {
        if ($this->broken) {
            return null;
        }
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
        return $this->counts[$key];
    }
}
