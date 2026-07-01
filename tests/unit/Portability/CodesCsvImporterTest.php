<?php declare(strict_types=1);
namespace PortoSender\Tests\unit\Portability;

use PHPUnit\Framework\TestCase;
use PortoSender\Portability\CodesCsvImporter;
use PortoSender\Inventory\CodeStore;
use PortoSender\Postage\ProductCatalog;

final class CodesCsvImporterTest extends TestCase
{
    /** @var array<int,array{product:string,date:string,codes:array<int,string>}> */
    private array $calls = [];

    /** @param array<int,string> $existingCodes codes the (faked) DB already holds */
    private function importer(array $existingCodes = []): CodesCsvImporter
    {
        $this->calls = [];
        $store = $this->createMock(CodeStore::class);
        $store->method('addBatch')->willReturnCallback(
            function (string $product, \DateTimeImmutable $date, array $codes) use ($existingCodes): int {
                $this->calls[] = ['product' => $product, 'date' => $date->format('Y-m-d'), 'codes' => $codes];
                $code = (string) ($codes[0] ?? '');
                return in_array($code, $existingCodes, true) ? 0 : 1; // INSERT IGNORE: dup -> 0
            }
        );
        return new CodesCsvImporter($store, ProductCatalog::default());
    }

    public function test_imports_valid_rows_passing_through_to_addbatch(): void
    {
        $csv = "product,code,purchase_date\n"
             . "standardbrief,AB12,2026-01-15\n"
             . "grossbrief,CD34,2026-02-20\n";
        $result = $this->importer()->import($csv);

        $this->assertSame(2, $result['inserted']);
        $this->assertSame([], $result['skipped']);
        $this->assertSame('standardbrief', $this->calls[0]['product']);
        $this->assertSame('2026-01-15', $this->calls[0]['date']);
        $this->assertSame(['AB12'], $this->calls[0]['codes']);
    }

    public function test_extra_columns_such_as_value_cents_are_ignored(): void
    {
        // A legacy export still carrying value_cents must import unharmed: the
        // column is simply not consumed anymore.
        $result = $this->importer()->import("product,code,value_cents\nstandardbrief,AB12,95\n");
        $this->assertSame(1, $result['inserted']);
        $this->assertSame([], $result['skipped']);
        $this->assertSame(['AB12'], $this->calls[0]['codes']);
    }

    public function test_unknown_product_is_skipped_and_never_hits_store(): void
    {
        $result = $this->importer()->import("product,code\nnope,AB12\n");
        $this->assertSame(0, $result['inserted']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame(1, $result['skipped'][0]['row']);
        $this->assertStringContainsString('nope', $result['skipped'][0]['reason']);
        $this->assertSame([], $this->calls);
    }

    public function test_invalid_date_is_skipped(): void
    {
        $result = $this->importer()->import("product,code,purchase_date\nstandardbrief,AB12,2026/01/15\n");
        $this->assertSame(0, $result['inserted']);
        $this->assertCount(1, $result['skipped']);
        $this->assertStringContainsString('purchase_date', $result['skipped'][0]['reason']);
    }

    public function test_empty_code_is_skipped(): void
    {
        $result = $this->importer()->import("product,code\nstandardbrief,\n");
        $this->assertSame(0, $result['inserted']);
        $this->assertCount(1, $result['skipped']);
        $this->assertStringContainsString('code', $result['skipped'][0]['reason']);
    }

    public function test_db_duplicate_is_reflected_in_skipped(): void
    {
        $result = $this->importer(['DUP1'])->import("product,code\nstandardbrief,DUP1\nstandardbrief,NEW1\n");
        $this->assertSame(1, $result['inserted']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame(1, $result['skipped'][0]['row']);
        $this->assertStringContainsString('exist', strtolower($result['skipped'][0]['reason']));
    }

    public function test_within_file_duplicate_is_skipped_and_store_hit_once(): void
    {
        $result = $this->importer()->import("product,code\nstandardbrief,SAME\nstandardbrief,SAME\n");
        $this->assertSame(1, $result['inserted']);
        $this->assertCount(1, $result['skipped']);
        $this->assertStringContainsString('file', strtolower($result['skipped'][0]['reason']));
        $this->assertCount(1, $this->calls);
    }
}
