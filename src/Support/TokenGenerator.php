<?php
declare(strict_types=1);
namespace PortoSender\Support;
final class TokenGenerator
{
    public function generate(): string { return bin2hex(random_bytes(32)); }
}
