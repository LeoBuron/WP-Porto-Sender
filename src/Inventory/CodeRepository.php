<?php
declare(strict_types=1);
namespace PortoSender\Inventory;

use PortoSender\Persistence\Schema;
use PortoSender\Postage\Expiry;

final class CodeRepository implements CodeStore
{
    public function __construct(private \wpdb $wpdb) {}

    private function table(): string { return Schema::codesTable($this->wpdb); }

    public function addBatch(string $product, int $valueCents, \DateTimeImmutable $purchaseDate, array $codes): int
    {
        $table = $this->table();
        $purchase = $purchaseDate->format('Y-m-d');
        $expires = Expiry::expiresOn($purchaseDate)->format('Y-m-d');
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $inserted = 0;
        foreach ($codes as $raw) {
            $code = trim((string) $raw);
            if ($code === '') { continue; }
            $affected = $this->wpdb->query($this->wpdb->prepare(
                "INSERT IGNORE INTO $table (product,value_cents,purchase_date,expires_on,code,status,created_at,updated_at)
                 VALUES (%s,%d,%s,%s,%s,'available',%s,%s)",
                $product, $valueCents, $purchase, $expires, $code, $now, $now
            ));
            $inserted += $affected ? 1 : 0;
        }
        return $inserted;
    }

    public function availableCount(string $product, \DateTimeImmutable $now): int
    {
        $table = $this->table();
        return (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE product=%s AND status='available' AND expires_on >= %s",
            $product, $now->format('Y-m-d')
        ));
    }

    /** @return array{available:int,reserved:int,issued:int,expired:int} */
    public function countsByStatus(string $product): array
    {
        $table = $this->table();
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT status, COUNT(*) c FROM $table WHERE product=%s GROUP BY status", $product
        ), ARRAY_A);
        $out = ['available' => 0, 'reserved' => 0, 'issued' => 0, 'expired' => 0];
        foreach ($rows as $r) { if (isset($out[$r['status']])) { $out[$r['status']] = (int) $r['c']; } }
        return $out;
    }

    public function getCode(int $id): ?object
    {
        $table = $this->table();
        return $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id)) ?: null;
    }

    public function claimOne(string $product, \DateTimeImmutable $now, int $reservationTtlMinutes): ?int
    {
        $table = $this->table();
        $id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM $table
              WHERE product=%s AND status='available' AND expires_on >= %s
              ORDER BY purchase_date ASC, id ASC LIMIT 1",
            $product, $now->format('Y-m-d')
        ));
        if ($id === null) { return null; }

        $reservedUntil = $now->modify("+{$reservationTtlMinutes} minutes")->format('Y-m-d H:i:s');
        $affected = $this->wpdb->query($this->wpdb->prepare(
            "UPDATE $table SET status='reserved', reserved_until=%s, updated_at=%s
              WHERE id=%d AND status='available'",
            $reservedUntil, $now->format('Y-m-d H:i:s'), (int) $id
        ));
        return $affected === 1 ? (int) $id : null; // 0 => lost the race; caller retries
    }

    public function markIssued(int $codeId, int $requestId, string $issuedToHash, \DateTimeImmutable $now): bool
    {
        $table = $this->table();
        return 1 === $this->wpdb->query($this->wpdb->prepare(
            "UPDATE $table SET status='issued', issued_at=%s, request_id=%d, issued_to_hash=%s, updated_at=%s
              WHERE id=%d AND status='reserved'",
            $now->format('Y-m-d H:i:s'), $requestId, $issuedToHash, $now->format('Y-m-d H:i:s'), $codeId
        ));
    }

    public function releaseStaleReservations(\DateTimeImmutable $now): int
    {
        $table = $this->table();
        return (int) $this->wpdb->query($this->wpdb->prepare(
            "UPDATE $table SET status='available', reserved_until=NULL, updated_at=%s
              WHERE status='reserved' AND reserved_until < %s",
            $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')
        ));
    }

    public function quarantineExpired(\DateTimeImmutable $now): int
    {
        $table = $this->table();
        return (int) $this->wpdb->query($this->wpdb->prepare(
            "UPDATE $table SET status='expired', updated_at=%s
              WHERE status IN ('available','reserved') AND expires_on < %s",
            $now->format('Y-m-d H:i:s'), $now->format('Y-m-d')
        ));
    }

    /** @return array<object> */
    public function findExpiring(\DateTimeImmutable $now, int $withinMonths): array
    {
        $table = $this->table();
        $until = $now->modify("+{$withinMonths} months")->format('Y-m-d');
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM $table WHERE status='available' AND expires_on >= %s AND expires_on <= %s
              ORDER BY expires_on ASC",
            $now->format('Y-m-d'), $until
        )) ?: [];
    }

    /** @return array<object> */
    public function recentIssued(int $limit): array
    {
        $table = $this->table();
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM $table WHERE status='issued' ORDER BY issued_at DESC LIMIT %d", $limit
        )) ?: [];
    }

    /** @return array<object> */
    public function findBelowValue(string $product, int $minCents): array
    {
        $table = $this->table();
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM $table WHERE product=%s AND status='available' AND value_cents < %d ORDER BY value_cents ASC",
            $product, $minCents
        )) ?: [];
    }
}
