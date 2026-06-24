<?php

declare(strict_types=1);

namespace PortoSender\Captcha;

use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\ChallengeParameters;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\Solution;
use AltchaOrg\Altcha\VerifySolutionOptions;
use AltchaOrg\Altcha\Algorithm\Pbkdf2;

/**
 * CaptchaVerifier implementation backed by the altcha-org/altcha v2 library.
 *
 * Adapter isolation: this is the ONLY class that touches the library.
 * All application code depends on CaptchaVerifier, never on Altcha directly.
 *
 * Wire format notes (v2 library):
 * - createChallenge() returns a Challenge value object with ->parameters and ->signature.
 * - We expose the challenge array with keys 'challenge' (the parameters array) and
 *   'signature' to match the contract required by the ALTCHA widget and our tests.
 * - Payload(Challenge $challenge, Solution $solution) — no array constructor in v2.
 */
final class AltchaVerifier implements CaptchaVerifier
{
    private Altcha $altcha;
    private Pbkdf2 $algorithm;

    public function __construct(string $hmacSecret)
    {
        $this->altcha = new Altcha(hmacSignatureSecret: $hmacSecret);
        $this->algorithm = new Pbkdf2();
    }

    /**
     * @return array<string, mixed>
     */
    public function challenge(): array
    {
        $challenge = $this->altcha->createChallenge(new CreateChallengeOptions(
            algorithm: $this->algorithm,
            cost: 5000,
            expiresAt: time() + 600,
        ));

        // Map the v2 Challenge structure onto the expected wire format:
        // 'challenge' = the parameters array, 'signature' = the HMAC signature.
        return [
            'challenge' => $challenge->parameters->toArray(),
            'signature' => $challenge->signature,
        ];
    }

    public function verify(string $payload): bool
    {
        if ($payload === '') {
            return false;
        }

        try {
            // Payload from the ALTCHA widget arrives as base64-encoded JSON.
            $json = base64_decode($payload, true);
            if ($json === false) {
                return false;
            }

            /** @var mixed $decoded */
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                return false;
            }

            // Reconstruct typed value objects from the decoded array.
            $challengeArr = $decoded['challenge'] ?? [];
            if (!is_array($challengeArr)) {
                return false;
            }
            $solutionArr = $decoded['solution'] ?? [];
            if (!is_array($solutionArr)) {
                return false;
            }

            $challengeObj = new Challenge(
                parameters: ChallengeParameters::fromArray($challengeArr),
                signature: is_string($decoded['signature'] ?? null) ? $decoded['signature'] : null,
            );

            $counter = is_int($solutionArr['counter'] ?? null) ? $solutionArr['counter'] : null;
            $derivedKey = is_string($solutionArr['derivedKey'] ?? null) ? $solutionArr['derivedKey'] : null;
            $rawTime = $solutionArr['time'] ?? null;
            $time = (is_float($rawTime) || is_int($rawTime)) ? (float) $rawTime : 0.0;

            if ($counter === null || $derivedKey === null) {
                return false;
            }

            $solutionObj = new Solution($counter, $derivedKey, $time);

            $result = $this->altcha->verifySolution(new VerifySolutionOptions(
                algorithm: $this->algorithm,
                payload: new Payload($challengeObj, $solutionObj),
            ));

            return $result->verified;
        } catch (\Throwable) {
            return false;
        }
    }
}
