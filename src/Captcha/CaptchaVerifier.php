<?php

declare(strict_types=1);

namespace PortoSender\Captcha;

interface CaptchaVerifier
{
    /** @return array<string, mixed> challenge payload for the widget (JSON-serializable) */
    public function challenge(): array;

    public function verify(string $payload): bool;
}
