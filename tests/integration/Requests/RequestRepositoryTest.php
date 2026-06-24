<?php // tests/integration/Requests/RequestRepositoryTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Requests;
use PortoSender\Tests\integration\PortoTestCase;
use PortoSender\Requests\RequestRepository;

final class RequestRepositoryTest extends PortoTestCase
{
    private RequestRepository $repo;
    public function set_up(): void { parent::set_up(); $this->repo = new RequestRepository($GLOBALS['wpdb']); }

    private function pending(string $emailHash, string $nameHash, string $tokenHash, string $created = '2026-06-24 10:00:00'): int
    {
        return $this->repo->createPending([
            'name' => 'Max', 'email' => 'max@example.de',
            'email_hash' => $emailHash, 'name_hash' => $nameHash,
            'product' => 'grossbrief', 'token_hash' => $tokenHash, 'ip_hash' => null, 'created_at' => $created,
        ]);
    }

    public function test_create_find_confirm_issue(): void
    {
        $id = $this->pending(str_repeat('e',64), str_repeat('n',64), str_repeat('t',64));
        $this->assertSame($id, (int) $this->repo->findByTokenHash(str_repeat('t',64))->id);
        $now = new \DateTimeImmutable('2026-06-24 10:05:00');
        $this->assertTrue($this->repo->markConfirmed($id, $now));
        $this->assertTrue($this->repo->markIssued($id, 7, $now));
        $this->assertSame('issued', $this->repo->findById($id)->status);
    }

    public function test_dedup_by_either_hash(): void
    {
        $this->pending(str_repeat('e',64), str_repeat('n',64), str_repeat('t',64));
        $this->assertTrue($this->repo->hasPriorRequest(str_repeat('e',64), str_repeat('z',64)));
        $this->assertTrue($this->repo->hasPriorRequest(null, str_repeat('n',64)));
        $this->assertFalse($this->repo->hasPriorRequest(str_repeat('z',64), null));
        $this->assertFalse($this->repo->hasPriorRequest(null, null));
    }

    public function test_delete_expired_pending_and_anonymize(): void
    {
        $old = $this->pending(str_repeat('a',64), str_repeat('b',64), str_repeat('c',64), '2026-06-20 10:00:00');
        $this->assertSame(1, $this->repo->deleteExpiredPending(new \DateTimeImmutable('2026-06-24 10:00:00'), 48));

        $keep = $this->pending(str_repeat('d',64), str_repeat('f',64), str_repeat('g',64));
        $this->repo->markIssued($keep, 1, new \DateTimeImmutable('2026-01-01 10:00:00'));
        $this->assertSame(1, $this->repo->anonymizeOlderThan(new \DateTimeImmutable('2026-06-01 00:00:00')));
        $this->assertNull($this->repo->findById($keep)->email);
    }
}
