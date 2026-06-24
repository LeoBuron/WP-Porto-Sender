<?php
declare(strict_types=1);
namespace PortoSender\Support;
final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable { return new \DateTimeImmutable('now'); }
}
