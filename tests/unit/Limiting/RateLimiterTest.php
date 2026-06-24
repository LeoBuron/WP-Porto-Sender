<?php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Limiting;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PortoSender\Limiting\RateLimiter;
use PortoSender\Settings\Settings;
use PortoSender\Support\Clock;

final class RateLimiterTest extends MockeryTestCase
{
    private function clock(string $t = '2026-06-24 10:00:00'): Clock
    {
        return Mockery::mock(Clock::class)
            ->shouldReceive('now')->andReturn(new \DateTimeImmutable($t))->getMock();
    }

    private function settings(array $over = []): Settings
    {
        return new Settings($over);
    }

    public function test_allows_up_to_per_ip_limit_then_denies(): void
    {
        $store = new InMemoryRateCounterStore();
        $limiter = new RateLimiter($store, $this->settings(['rate_limit_per_ip_day' => 3]), $this->clock());
        $this->assertTrue($limiter->check('IP'));   // 1
        $this->assertTrue($limiter->check('IP'));   // 2
        $this->assertTrue($limiter->check('IP'));   // 3
        $this->assertFalse($limiter->check('IP'));  // 4 > 3
    }

    public function test_disabled_always_allows_without_touching_store(): void
    {
        $store = new InMemoryRateCounterStore();
        $limiter = new RateLimiter($store, $this->settings(['rate_limit_enabled' => false]), $this->clock());
        $this->assertTrue($limiter->check('IP'));
        $this->assertSame([], $store->counts);
    }

    public function test_over_global_ceiling_denies(): void
    {
        $store = new InMemoryRateCounterStore();
        $limiter = new RateLimiter($store, $this->settings(['rate_limit_global_hour' => 2]), $this->clock());
        $this->assertTrue($limiter->check('A'));   // global 1
        $this->assertTrue($limiter->check('B'));   // global 2
        $this->assertFalse($limiter->check('C'));  // global 3 > 2
    }

    public function test_per_ip_block_does_not_consume_global(): void
    {
        $store = new InMemoryRateCounterStore();
        $limiter = new RateLimiter($store, $this->settings(['rate_limit_per_ip_day' => 1]), $this->clock());
        $this->assertTrue($limiter->check('IP'));   // perIp 1, global 1
        $this->assertFalse($limiter->check('IP'));  // perIp 2 > 1 -> deny BEFORE global
        $globalKeys = array_filter(array_keys($store->counts), fn($k) => str_starts_with($k, 'porto_rl_g_'));
        $this->assertCount(1, $globalKeys);
        $this->assertSame(1, $store->counts[reset($globalKeys)]);
    }

    public function test_store_failure_fails_open_by_default(): void
    {
        $store = new InMemoryRateCounterStore();
        $store->broken = true;
        $limiter = new RateLimiter($store, $this->settings(), $this->clock());
        $this->assertTrue($limiter->check('IP'));
    }

    public function test_store_failure_fails_closed_when_configured(): void
    {
        $store = new InMemoryRateCounterStore();
        $store->broken = true;
        $limiter = new RateLimiter($store, $this->settings(), $this->clock(), failOpen: false);
        $this->assertFalse($limiter->check('IP'));
    }

    public function test_window_rollover_resets_per_ip(): void
    {
        $store = new InMemoryRateCounterStore();
        $settings = $this->settings(['rate_limit_per_ip_day' => 1]);
        $day1 = new RateLimiter($store, $settings, $this->clock('2026-06-24 10:00:00'));
        $day2 = new RateLimiter($store, $settings, $this->clock('2026-06-25 10:00:00'));
        $this->assertTrue($day1->check('IP'));   // day-1 bucket: 1
        $this->assertFalse($day1->check('IP'));  // day-1 bucket: 2 > 1
        $this->assertTrue($day2->check('IP'));   // day-2 bucket: fresh 1
    }

    public function test_bucketed_keys_have_expected_shape(): void
    {
        $store = new InMemoryRateCounterStore();
        $t = '2026-06-24 10:00:00';
        $ts = (new \DateTimeImmutable($t))->getTimestamp(); // tz-agnostic: same computation as the impl
        $limiter = new RateLimiter($store, $this->settings(), $this->clock($t));
        $limiter->check('ABC');
        $this->assertArrayHasKey('porto_rl_ip_ABC_' . intdiv($ts, 86400), $store->counts);
        $this->assertArrayHasKey('porto_rl_g_' . intdiv($ts, 3600), $store->counts);
    }
}
