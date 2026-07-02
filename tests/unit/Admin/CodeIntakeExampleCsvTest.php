<?php // tests/unit/Admin/CodeIntakeExampleCsvTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Admin;
use PHPUnit\Framework\TestCase;
use PortoSender\Admin\CodeIntakePage;
use PortoSender\Inventory\CodeStore;
use PortoSender\Postage\ProductCatalog;

final class CodeIntakeExampleCsvTest extends TestCase
{
    private function page(): CodeIntakePage
    {
        return new CodeIntakePage($this->createMock(CodeStore::class), ProductCatalog::default());
    }

    public function test_builds_one_row_per_product_with_valid_keys(): void
    {
        // Header + exactly one example row per catalog product, using the real
        // product keys so the file can never reference an invalid `product`.
        // Only the first row carries a date; the rest leave purchase_date blank,
        // demonstrating within the file that the column is optional.
        $expected =
            "product,code,purchase_date\r\n" .
            "standardbrief,BEISPIEL-CODE-0001,2026-07-02\r\n" .
            "grossbrief,BEISPIEL-CODE-0002,\r\n";

        $this->assertSame($expected, $this->page()->exampleCsv('2026-07-02'));
    }

    public function test_date_comes_from_the_given_today(): void
    {
        $csv = $this->page()->exampleCsv('2025-12-24');
        $this->assertStringContainsString('standardbrief,BEISPIEL-CODE-0001,2025-12-24', $csv);
    }
}
