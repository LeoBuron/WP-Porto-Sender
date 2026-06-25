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

    public function test_pending_does_not_block_dedup(): void
    {
        // A merely pending (unconfirmed) request must NOT block — otherwise a mistyped or
        // never-confirmed request would lock the person out for the token TTL.
        $this->pending(str_repeat('e',64), str_repeat('n',64), str_repeat('t',64));
        $this->assertFalse($this->repo->hasPriorRequest(str_repeat('e',64), null));
        $this->assertFalse($this->repo->hasPriorRequest(null, str_repeat('n',64)));
    }

    public function test_dedup_by_either_hash_once_confirmed_or_issued(): void
    {
        $now = new \DateTimeImmutable('2026-06-24 10:05:00');

        // A confirmed request blocks.
        $confirmed = $this->pending(str_repeat('e',64), str_repeat('n',64), str_repeat('t',64));
        $this->repo->markConfirmed($confirmed, $now);
        $this->assertTrue($this->repo->hasPriorRequest(str_repeat('e',64), str_repeat('z',64)));
        $this->assertTrue($this->repo->hasPriorRequest(null, str_repeat('n',64)));

        // An issued request blocks too.
        $issued = $this->pending(str_repeat('x',64), str_repeat('y',64), str_repeat('u',64));
        $this->repo->markConfirmed($issued, $now);
        $this->repo->markIssued($issued, 1, $now);
        $this->assertTrue($this->repo->hasPriorRequest(str_repeat('x',64), null));
        $this->assertTrue($this->repo->hasPriorRequest(null, str_repeat('y',64)));

        // Unknown hashes and the no-hash case never block.
        $this->assertFalse($this->repo->hasPriorRequest(str_repeat('q',64), null));
        $this->assertFalse($this->repo->hasPriorRequest(null, null));
    }

    public function test_delete_unconfirmed_older_than_retains_recent_and_anonymize(): void
    {
        $this->pending(str_repeat('a',64), str_repeat('b',64), str_repeat('c',64), '2026-05-01 10:00:00'); // old
        $this->pending(str_repeat('h',64), str_repeat('i',64), str_repeat('j',64), '2026-06-23 10:00:00'); // recent

        // Retention cutoff 2026-06-01: the May unconfirmed row is purged, the June one is RETAINED for audit.
        $this->assertSame(1, $this->repo->deleteUnconfirmedOlderThan(new \DateTimeImmutable('2026-06-01 00:00:00')));
        $this->assertNull($this->repo->findByTokenHash(str_repeat('c',64)));
        $this->assertNotNull($this->repo->findByTokenHash(str_repeat('j',64)));

        $keep = $this->pending(str_repeat('d',64), str_repeat('f',64), str_repeat('g',64));
        $this->repo->markIssued($keep, 1, new \DateTimeImmutable('2026-01-01 10:00:00'));
        $this->assertSame(1, $this->repo->anonymizeOlderThan(new \DateTimeImmutable('2026-06-01 00:00:00')));
        $this->assertNull($this->repo->findById($keep)->email);
    }
}
