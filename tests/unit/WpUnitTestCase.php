<?php
declare(strict_types=1);
namespace PortoSender\Tests\unit;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Brain\Monkey;

abstract class WpUnitTestCase extends MockeryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Monkey\Functions\when('__')->returnArg(1);
        Monkey\Functions\when('esc_html__')->returnArg(1);
    }
    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }
}
