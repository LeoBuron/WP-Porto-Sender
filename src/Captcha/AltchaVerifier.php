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
 * - challenge() returns Challenge::toArray() directly, giving top-level keys
 *   'parameters' and 'signature' — the shape expected by the v3 ALTCHA widget.
 * - Payload(Challenge $challenge, Solution $solution) — no array constructor in v2.
 */
final class AltchaVerifier implements CaptchaVerifier
{
    private Altcha $altcha;
    private Pbkdf2 $algorithm;

    // cost = PBKDF2 iterations PER solve attempt. Combined with the 1-byte target prefix
    // (~256 attempts), the browser's total work is ~256 * cost HMAC-SHA256 ops. 100000 was
    // too heavy for phones (the widget never finished solving -> captcha_failed); 10000
    // keeps a real proof-of-work while staying solvable in ~1s on mobile.
    public function __construct(string $hmacSecret, private int $cost = 10000)
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
            cost: $this->cost,
            expiresAt: time() + 600,
        ));

        // Return the library's native Challenge::toArray() shape so the top-level keys
        // are 'parameters' and 'signature' — the shape the v3 ALTCHA widget expects.
        return json_decode(json_encode($challenge->toArray()), true);
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

            // Payload::toArray() shape: { challenge: { parameters: {...}, signature: "..." }, solution: {...} }
            $challengeWrapper = $decoded['challenge'] ?? null;
            if (!is_array($challengeWrapper)) {
                return false;
            }
            $parametersArr = $challengeWrapper['parameters'] ?? [];
            if (!is_array($parametersArr)) {
                return false;
            }
            $solutionArr = $decoded['solution'] ?? [];
            if (!is_array($solutionArr)) {
                return false;
            }

            $challengeObj = new Challenge(
                parameters: ChallengeParameters::fromArray($parametersArr),
                signature: is_string($challengeWrapper['signature'] ?? null) ? $challengeWrapper['signature'] : null,
            );

            $counter = is_int($solutionArr['counter'] ?? null) ? $solutionArr['counter'] : null;
            $derivedKey = is_string($solutionArr['derivedKey'] ?? null) ? $solutionArr['derivedKey'] : null;
            $rawTime = $solutionArr['time'] ?? null;
            $time = (is_float($rawTime) || is_int($rawTime)) ? (float) $rawTime : null;

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
