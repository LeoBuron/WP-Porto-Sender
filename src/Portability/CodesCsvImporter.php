<?php
declare(strict_types=1);

namespace PortoSender\Portability;

use PortoSender\Inventory\CodeStore;
use PortoSender\Postage\ProductCatalog;

/**
 * Imports postage codes from a CSV into the code store.
 *
 * CSV columns: required `product`, `code`; optional `value_cents`
 * (defaults to the catalog value) and `purchase_date` (`Y-m-d`, defaults to
 * today). There is deliberately no `expires_on` column — expiry is a derived
 * business rule (Expiry::expiresOn) applied inside the store's addBatch.
 *
 * Each accepted row is inserted via a one-element addBatch call: the store uses
 * INSERT IGNORE keyed on the unique `code`, so a return of 0 means the code
 * already existed. Per-row inserts (vs grouping) keep duplicate attribution
 * exact, which matters for the admin's "what was skipped and why" report; the
 * volume of an occasional admin import makes the extra prepared statements
 * negligible. Within-file duplicates are caught before the store is touched.
 */
final class CodesCsvImporter
{
    public function __construct(
        private CodeStore $codes,
        private ProductCatalog $catalog,
        private CsvReader $reader = new CsvReader(),
    ) {
    }

    /**
     * @return array{inserted:int, skipped:array<int,array{row:int,reason:string}>}
     * @throws \RuntimeException if the CSV lacks the required columns or is oversized
     */
    public function import(string $csv): array
    {
        $rows = $this->reader->parse($csv, ['product', 'code']);

        $inserted = 0;
        $skipped = [];
        $seen = [];

        foreach ($rows as $i => $row) {
            $line = $i + 1; // 1-based data row (header excluded)

            $code = trim($row['code'] ?? '');
            if ($code === '') {
                $skipped[] = ['row' => $line, 'reason' => 'empty code'];
                continue;
            }

            $productKey = trim($row['product'] ?? '');
            $product = $this->catalog->get($productKey);
            if ($product === null) {
                $skipped[] = ['row' => $line, 'reason' => "unknown product '{$productKey}'"];
                continue;
            }

            $valueCents = $product->valueCents;
            $rawValue = trim($row['value_cents'] ?? '');
            if ($rawValue !== '') {
                if (!ctype_digit($rawValue)) {
                    $skipped[] = ['row' => $line, 'reason' => 'invalid value_cents'];
                    continue;
                }
                $valueCents = (int) $rawValue;
            }

            $purchase = new \DateTimeImmutable('now');
            $rawDate = trim($row['purchase_date'] ?? '');
            if ($rawDate !== '') {
                $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate);
                $errors = \DateTimeImmutable::getLastErrors();
                if ($parsed === false
                    || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                    $skipped[] = ['row' => $line, 'reason' => 'invalid purchase_date'];
                    continue;
                }
                $purchase = $parsed;
            }

            if (isset($seen[$code])) {
                $skipped[] = ['row' => $line, 'reason' => 'duplicate code in file'];
                continue;
            }
            $seen[$code] = true;

            if ($this->codes->addBatch($product->key, $valueCents, $purchase, [$code]) >= 1) {
                $inserted++;
            } else {
                $skipped[] = ['row' => $line, 'reason' => 'code already exists in database'];
            }
        }

        return ['inserted' => $inserted, 'skipped' => $skipped];
    }
}
