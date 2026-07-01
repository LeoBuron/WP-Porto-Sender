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
    /** Has any OTHER request (id != $excludeId) for this identity already been ISSUED a code? */
    public function hasIssuedForIdentity(?string $emailHash, ?string $nameHash, int $excludeId): bool;
    /** Purge never-confirmed (pending) requests created before $cutoff — the unconfirmed-retention window. */
    public function deleteUnconfirmedOlderThan(\DateTimeImmutable $cutoff): int;
    public function anonymizeOlderThan(\DateTimeImmutable $cutoff): int;
    /** @return array<int,array<string,mixed>> every requests row as an associative array (for export) */
    public function allRows(): array;
    /** Delete every row (DML, transaction-safe). @return int rows removed */
    public function deleteAll(): int;
    /** Insert rows from a bundle, whitelisting columns. @param array<int,array<string,mixed>> $rows @return int inserted */
    public function insertRows(array $rows): int;
}
