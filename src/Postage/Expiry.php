<?php
declare(strict_types=1);
namespace PortoSender\Postage;

final class Expiry
{
    public static function expiresOn(\DateTimeImmutable $purchase): \DateTimeImmutable
    {
        $year = (int) $purchase->format('Y') + 3;
        return $purchase->setDate($year, 12, 31)->setTime(23, 59, 59);
    }
}
