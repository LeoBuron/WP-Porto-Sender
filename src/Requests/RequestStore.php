<?php
declare(strict_types=1);
namespace PortoSender\Requests;

interface RequestStore
{
    public function createPending(array $data): int;
    public function findByTokenHash(string $tokenHash): ?object;
    public function findById(int $id): ?object;
    public function markConfirmed(int $id, \DateTimeImmutable $now): bool;
    public function markIssued(int $id, int $codeId, \DateTimeImmutable $now): bool;
    public function hasPriorRequest(?string $emailHash, ?string $nameHash): bool;
    public function deleteExpiredPending(\DateTimeImmutable $now, int $ttlHours): int;
    public function anonymizeOlderThan(\DateTimeImmutable $cutoff): int;
}
