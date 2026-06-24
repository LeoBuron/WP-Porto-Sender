<?php

declare(strict_types=1);

namespace PortoSender\Captcha;

final class NullVerifier implements CaptchaVerifier
{
    public function challenge(): array
    {
        return [];
    }

    public function verify(string $payload): bool
    {
        return true;
    }
}
