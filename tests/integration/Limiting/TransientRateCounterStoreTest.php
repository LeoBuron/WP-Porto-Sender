<?php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Limiting;

use PortoSender\Tests\integration\PortoTestCase;
use PortoSender\Limiting\TransientRateCounterStore;

final class TransientRateCounterStoreTest extends PortoTestCase
{
    public function test_hit_increments_and_persists(): void
    {
        $store = new TransientRateCounterStore();
        $this->assertSame(1, $store->hit('porto_test_rl', 3600));
        $this->assertSame(2, $store->hit('porto_test_rl', 3600));
        $this->assertSame(3, $store->hit('porto_test_rl', 3600));
        $this->assertSame(3, (int) get_transient('porto_test_rl'));
    }

    public function test_separate_keys_are_independent(): void
    {
        $store = new TransientRateCounterStore();
        $this->assertSame(1, $store->hit('porto_test_a', 60));
        $this->assertSame(1, $store->hit('porto_test_b', 60));
    }
}
