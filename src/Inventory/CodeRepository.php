<?php
declare(strict_types=1);
namespace PortoSender\Inventory;

use PortoSender\Persistence\Schema;
use PortoSender\Postage\Expiry;

final class CodeRepository
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
}
