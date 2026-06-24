<?php
declare(strict_types=1);
namespace PortoSender\Inventory;

interface CodeStore
{
    public function addBatch(string $product, int $valueCents, \DateTimeImmutable $purchaseDate, array $codes): int;
    public function availableCount(string $product, \DateTimeImmutable $now): int;
    /** @return array{available:int,reserved:int,issued:int,expired:int} */
    public function countsByStatus(string $product): array;
    public function getCode(int $id): ?object;
    public function claimOne(string $product, \DateTimeImmutable $now, int $reservationTtlMinutes): ?int;
    public function markIssued(int $codeId, int $requestId, string $issuedToHash, \DateTimeImmutable $now): bool;
    public function releaseStaleReservations(\DateTimeImmutable $now): int;
    public function quarantineExpired(\DateTimeImmutable $now): int;
    /** @return array<object> */
    public function findExpiring(\DateTimeImmutable $now, int $withinMonths): array;
    /** @return array<object> */
    public function recentIssued(int $limit): array;
    /** @return array<object> */
    public function findBelowValue(string $product, int $minCents): array;
}
