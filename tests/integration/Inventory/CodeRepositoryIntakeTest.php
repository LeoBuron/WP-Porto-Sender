<?php // tests/integration/Inventory/CodeRepositoryIntakeTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Inventory;
use PortoSender\Tests\integration\PortoTestCase;
use PortoSender\Inventory\CodeRepository;

final class CodeRepositoryIntakeTest extends PortoTestCase
{
    private CodeRepository $repo;
    public function set_up(): void { parent::set_up(); $this->repo = new CodeRepository($GLOBALS['wpdb']); }

    public function test_add_batch_inserts_and_dedupes(): void
    {
        $now = new \DateTimeImmutable('2026-06-24');
        $this->assertSame(2, $this->repo->addBatch('grossbrief', 180, $now, ['AAA111', 'BBB222', '  ', 'AAA111']));
        $this->assertSame(2, $this->repo->availableCount('grossbrief', $now));
        $this->assertSame(0, $this->repo->availableCount('standardbrief', $now));
    }
}
