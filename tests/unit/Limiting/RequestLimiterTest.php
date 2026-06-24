<?php // tests/unit/Limiting/RequestLimiterTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Limiting;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PortoSender\Limiting\RequestLimiter;
use PortoSender\Requests\RequestStore;

final class RequestLimiterTest extends MockeryTestCase
{
    public function test_modes_query_the_expected_hashes(): void
    {
        $store = Mockery::mock(RequestStore::class);
        $store->shouldReceive('hasPriorRequest')->with('E', null)->andReturn(true);
        $store->shouldReceive('hasPriorRequest')->with(null, 'N')->andReturn(false);
        $store->shouldReceive('hasPriorRequest')->with('E', 'N')->andReturn(true);
        $limiter = new RequestLimiter($store);

        $this->assertFalse($limiter->allow('email', 'E', 'N'));        // prior email => blocked
        $this->assertTrue($limiter->allow('name', 'E', 'N'));          // no prior name => allowed
        $this->assertFalse($limiter->allow('name_or_email', 'E', 'N'));// either matches => blocked
        $this->assertTrue($limiter->allow('none', 'E', 'N'));          // never blocked
    }
}
