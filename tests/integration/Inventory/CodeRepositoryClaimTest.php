<?php // tests/integration/Inventory/CodeRepositoryClaimTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Inventory;
use PortoSender\Tests\integration\PortoTestCase;
use PortoSender\Inventory\CodeRepository;

final class CodeRepositoryClaimTest extends PortoTestCase
{
    private CodeRepository $repo;
    public function set_up(): void { parent::set_up(); $this->repo = new CodeRepository($GLOBALS['wpdb']); }

    public function test_claims_oldest_first_and_never_twice(): void
    {
        $now = new \DateTimeImmutable('2026-06-24 10:00:00');
        $this->repo->addBatch('grossbrief', new \DateTimeImmutable('2025-01-01'), ['OLD']);
        $this->repo->addBatch('grossbrief', new \DateTimeImmutable('2026-01-01'), ['NEW']);
        $first = $this->repo->claimOne('grossbrief', $now, 30);
        $this->assertSame('OLD', $this->repo->getCode($first)->code);
        $second = $this->repo->claimOne('grossbrief', $now, 30);
        $this->assertSame('NEW', $this->repo->getCode($second)->code);
        $this->assertNull($this->repo->claimOne('grossbrief', $now, 30)); // pool drained
    }

    public function test_does_not_claim_expired(): void
    {
        $now = new \DateTimeImmutable('2026-06-24 10:00:00');
        $this->repo->addBatch('standardbrief', new \DateTimeImmutable('2020-01-01'), ['EXP']); // expires 2023-12-31
        $this->assertNull($this->repo->claimOne('standardbrief', $now, 30));
    }

    public function test_mark_issued_and_release_stale(): void
    {
        $now = new \DateTimeImmutable('2026-06-24 10:00:00');
        $this->repo->addBatch('grossbrief', new \DateTimeImmutable('2026-01-01'), ['X']);
        $id = $this->repo->claimOne('grossbrief', $now, 30);
        $this->assertTrue($this->repo->markIssued($id, 1, str_repeat('a', 64), $now));
        $this->assertSame('issued', $this->repo->getCode($id)->status);

        // a second code reserved then made stale
        $this->repo->addBatch('grossbrief', new \DateTimeImmutable('2026-01-01'), ['Y']);
        $id2 = $this->repo->claimOne('grossbrief', $now, 30);
        $GLOBALS['wpdb']->update($GLOBALS['wpdb']->prefix . 'porto_codes',
            ['reserved_until' => '2026-06-24 09:00:00'], ['id' => $id2]);
        $this->assertSame(1, $this->repo->releaseStaleReservations($now));
        $this->assertSame('available', $this->repo->getCode($id2)->status);
    }
}
