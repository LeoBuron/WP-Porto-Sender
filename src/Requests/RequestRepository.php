<?php
declare(strict_types=1);
namespace PortoSender\Requests;

use PortoSender\Persistence\Schema;

final class RequestRepository implements RequestStore
{
    public function __construct(private \wpdb $wpdb) {}

    private function table(): string { return Schema::requestsTable($this->wpdb); }

    /** @return array<int,array<string,mixed>> */
    public function allRows(): array
    {
        $table = $this->table();
        return $this->wpdb->get_results("SELECT * FROM $table ORDER BY id", ARRAY_A) ?: [];
    }

    /** Real columns of porto_requests — the allowlist for untrusted bundle import. */
    private const COLUMNS = [
        'id', 'name', 'email', 'email_hash', 'name_hash', 'product', 'status',
        'token_hash', 'ip_hash', 'code_id', 'created_at', 'confirmed_at', 'issued_at',
    ];

    public function deleteAll(): int
    {
        $table = $this->table();
        return (int) $this->wpdb->query("DELETE FROM $table");
    }

    public function insertRows(array $rows): int
    {
        $table = $this->table();
        $allowed = array_flip(self::COLUMNS);
        $inserted = 0;
        foreach ($rows as $row) {
            $cols = array_intersect_key($row, $allowed); // never trust columns from input
            if ($cols === []) { continue; }
            $inserted += $this->wpdb->insert($table, $cols) ? 1 : 0;
        }
        return $inserted;
    }

    public function createPending(array $data): int
    {
        $this->wpdb->insert($this->table(), [
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'email_hash' => $data['email_hash'],
            'name_hash' => $data['name_hash'],
            'product' => $data['product'],
            'status' => 'pending',
            'token_hash' => $data['token_hash'],
            'ip_hash' => $data['ip_hash'] ?? null,
            'created_at' => $data['created_at'],
        ]);
        return (int) $this->wpdb->insert_id;
    }

    public function findByTokenHash(string $tokenHash): ?object
    {
        return $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE token_hash=%s", $tokenHash
        )) ?: null;
    }

    public function findById(int $id): ?object
    {
        return $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE id=%d", $id
        )) ?: null;
    }

    public function markConfirmed(int $id, \DateTimeImmutable $now): bool
    {
        return 1 === $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table()} SET status='confirmed', confirmed_at=%s WHERE id=%d AND status='pending'",
            $now->format('Y-m-d H:i:s'), $id
        ));
    }

    public function markIssued(int $id, int $codeId, \DateTimeImmutable $now): bool
    {
        return 1 === $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table()} SET status='issued', code_id=%d, issued_at=%s WHERE id=%d",
            $codeId, $now->format('Y-m-d H:i:s'), $id
        ));
    }

    public function hasPriorRequest(?string $emailHash, ?string $nameHash): bool
    {
        $clauses = [];
        $args = [];
        if ($emailHash !== null) { $clauses[] = 'email_hash=%s'; $args[] = $emailHash; }
        if ($nameHash !== null) { $clauses[] = 'name_hash=%s'; $args[] = $nameHash; }
        if ($clauses === []) { return false; }
        $sql = "SELECT COUNT(*) FROM {$this->table()} WHERE status IN ('confirmed','issued') AND (" . implode(' OR ', $clauses) . ')';
        return (int) $this->wpdb->get_var($this->wpdb->prepare($sql, ...$args)) > 0;
    }

    public function deleteExpiredPending(\DateTimeImmutable $now, int $ttlHours): int
    {
        $cutoff = $now->modify("-{$ttlHours} hours")->format('Y-m-d H:i:s');
        return (int) $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->table()} WHERE status='pending' AND created_at < %s", $cutoff
        ));
    }

    public function anonymizeOlderThan(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table()} SET name=NULL, email=NULL
              WHERE issued_at IS NOT NULL AND issued_at < %s AND (name IS NOT NULL OR email IS NOT NULL)",
            $cutoff->format('Y-m-d H:i:s')
        ));
    }
}
