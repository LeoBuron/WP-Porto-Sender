<?php // tests/unit/Postage/ExpiryTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Postage;
use PHPUnit\Framework\TestCase;
use PortoSender\Postage\Expiry;

final class ExpiryTest extends TestCase
{
    public function test_expires_end_of_third_year_after_purchase(): void
    {
        $purchase = new \DateTimeImmutable('2026-06-24');
        $this->assertSame('2029-12-31', Expiry::expiresOn($purchase)->format('Y-m-d'));
    }
}
