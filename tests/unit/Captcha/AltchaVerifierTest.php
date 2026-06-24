<?php

declare(strict_types=1);

namespace PortoSender\Tests\unit\Captcha;

use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;
use PHPUnit\Framework\TestCase;
use PortoSender\Captcha\AltchaVerifier;

final class AltchaVerifierTest extends TestCase
{
    public function test_challenge_has_signature_and_garbage_fails_verification(): void
    {
        $v = new AltchaVerifier('a-test-secret');
        $challenge = $v->challenge();
        $this->assertArrayHasKey('parameters', $challenge);
        $this->assertArrayHasKey('signature', $challenge);
        $this->assertArrayNotHasKey('challenge', $challenge);
        $this->assertFalse($v->verify('not-a-valid-payload'));
        $this->assertFalse($v->verify(''));
    }

    /**
     * Round-trip: solve a real challenge and assert verify() returns true.
     *
     * Uses cost=1000 for speed in tests while production defaults to 100000.
     */
    public function test_verify_returns_true_for_genuinely_solved_challenge(): void
    {
        $secret = 'round-trip-secret';
        $cost   = 1000;

        // Use the lib directly to create + solve (mirrors what the ALTCHA widget does).
        $algorithm = new Pbkdf2();
        $altcha    = new Altcha(hmacSignatureSecret: $secret);

        $challenge = $altcha->createChallenge(new CreateChallengeOptions(
            algorithm: $algorithm,
            cost: $cost,
            expiresAt: time() + 600,
        ));

        $solution = $altcha->solveChallenge(new SolveChallengeOptions(
            challenge: $challenge,
            algorithm: $algorithm,
        ));

        $this->assertNotNull($solution, 'solveChallenge() should find a solution');

        // Build the base64 payload exactly as the JS widget would (Payload::toBase64()).
        $payloadBase64 = (new Payload($challenge, $solution))->toBase64();

        // Verify using our adapter constructed with the same secret and cost.
        $verifier = new AltchaVerifier($secret, $cost);
        $this->assertTrue($verifier->verify($payloadBase64));
    }
}
