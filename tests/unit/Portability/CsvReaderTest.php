<?php declare(strict_types=1);
namespace PortoSender\Tests\unit\Portability;

use PHPUnit\Framework\TestCase;
use PortoSender\Portability\CsvReader;

final class CsvReaderTest extends TestCase
{
    public function test_parses_header_and_rows_into_maps(): void
    {
        $csv = "product,code\nstandardbrief,AB12\ngrossbrief,CD34\n";
        $rows = (new CsvReader())->parse($csv, ['product', 'code']);
        $this->assertSame([
            ['product' => 'standardbrief', 'code' => 'AB12'],
            ['product' => 'grossbrief', 'code' => 'CD34'],
        ], $rows);
    }

    public function test_column_order_is_irrelevant(): void
    {
        $csv = "code,product\nAB12,standardbrief\n";
        $rows = (new CsvReader())->parse($csv, ['product', 'code']);
        $this->assertSame('standardbrief', $rows[0]['product']);
        $this->assertSame('AB12', $rows[0]['code']);
    }

    public function test_missing_required_header_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        (new CsvReader())->parse("code\nAB12\n", ['product', 'code']);
    }

    public function test_exceeding_max_rows_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        (new CsvReader(2))->parse("code\nA\nB\nC\n", ['code']);
    }

    public function test_blank_and_whitespace_only_lines_skipped(): void
    {
        $rows = (new CsvReader())->parse("code\nA\n\n   \nB\n", ['code']);
        $this->assertSame([['code' => 'A'], ['code' => 'B']], $rows);
    }

    public function test_header_trimmed_lowercased_and_bom_stripped(): void
    {
        $csv = "\xEF\xBB\xBF Product , Code \nstandardbrief,AB12\n";
        $rows = (new CsvReader())->parse($csv, ['product', 'code']);
        $this->assertSame([['product' => 'standardbrief', 'code' => 'AB12']], $rows);
    }

    public function test_quoted_field_with_comma_preserved(): void
    {
        $csv = "code,note\nAB12,\"a,b\"\n";
        $rows = (new CsvReader())->parse($csv, ['code']);
        $this->assertSame('a,b', $rows[0]['note']);
    }
}
