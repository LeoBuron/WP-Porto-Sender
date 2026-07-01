<?php // tests/integration/Inventory/CodeRepositoryExpiryTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Inventory;
use PortoSender\Tests\integration\PortoTestCase;
use PortoSender\Inventory\CodeRepository;

final class CodeRepositoryExpiryTest extends PortoTestCase
{
    private CodeRepository $repo;
    public function set_up(): void { parent::set_up(); $this->repo = new CodeRepository($GLOBALS['wpdb']); }

    public function test_quarantine_and_find_expiring(): void
    {
        $now = new \DateTimeImmutable('2026-06-24');
        $this->repo->addBatch('grossbrief', new \DateTimeImmutable('2020-01-01'), ['GONE']); // expires 2023
        $this->repo->addBatch('grossbrief', new \DateTimeImmutable('2023-06-01'), ['SOON']); // expires 2026-12-31
        $this->repo->addBatch('grossbrief', new \DateTimeImmutable('2026-01-01'), ['FRESH']); // expires 2029

        $this->assertSame(1, $this->repo->quarantineExpired($now));
        $expiring = $this->repo->findExpiring($now, 12); // within 12 months
        $codes = array_map(static fn($r) => $r->code, $expiring);
        $this->assertContains('SOON', $codes);
        $this->assertNotContains('FRESH', $codes);
        $this->assertNotContains('GONE', $codes);
    }
}
