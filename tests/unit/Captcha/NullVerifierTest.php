<?php

declare(strict_types=1);

namespace PortoSender\Tests\unit\Captcha;

use PHPUnit\Framework\TestCase;
use PortoSender\Captcha\NullVerifier;

final class NullVerifierTest extends TestCase
{
    public function test_always_passes(): void
    {
        $v = new NullVerifier();
        $this->assertSame([], $v->challenge());
        $this->assertTrue($v->verify(''));
    }
}
