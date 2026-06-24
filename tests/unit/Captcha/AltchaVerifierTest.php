<?php

declare(strict_types=1);

namespace PortoSender\Tests\unit\Captcha;

use PHPUnit\Framework\TestCase;
use PortoSender\Captcha\AltchaVerifier;

final class AltchaVerifierTest extends TestCase
{
    public function test_challenge_has_signature_and_garbage_fails_verification(): void
    {
        $v = new AltchaVerifier('a-test-secret');
        $challenge = $v->challenge();
        $this->assertArrayHasKey('challenge', $challenge);
        $this->assertArrayHasKey('signature', $challenge);
        $this->assertFalse($v->verify('not-a-valid-payload'));
        $this->assertFalse($v->verify(''));
    }
}
