<?php declare(strict_types=1);
namespace PortoSender\Tests\unit\Portability;

use PHPUnit\Framework\TestCase;
use PortoSender\Portability\CsvWriter;

final class CsvWriterTest extends TestCase
{
    public function test_escapes_formula_leading_characters(): void
    {
        $this->assertSame("'=1+1", CsvWriter::escapeCell('=1+1'));
        $this->assertSame("'+1", CsvWriter::escapeCell('+1'));
        $this->assertSame("'-1", CsvWriter::escapeCell('-1'));
        $this->assertSame("'@x", CsvWriter::escapeCell('@x'));
        $this->assertSame("'\tx", CsvWriter::escapeCell("\tx"));
        $this->assertSame("'\rx", CsvWriter::escapeCell("\rx"));
    }

    public function test_leaves_safe_cells_unchanged(): void
    {
        $this->assertSame('safe', CsvWriter::escapeCell('safe'));
        $this->assertSame('a=b', CsvWriter::escapeCell('a=b')); // '=' not leading
        $this->assertSame('', CsvWriter::escapeCell(''));
        $this->assertSame('Ärmel', CsvWriter::escapeCell('Ärmel')); // multibyte lead byte must not match
    }

    public function test_tostring_emits_header_and_rfc4180_quotes_special_fields(): void
    {
        $csv = CsvWriter::toString(['name', 'note'], [
            ['Alice', 'plain'],
            ['Bob', 'has,comma'],
            ['Eve', 'has"quote'],
        ]);
        $expected =
            "name,note\r\n" .
            "Alice,plain\r\n" .
            "Bob,\"has,comma\"\r\n" .
            "Eve,\"has\"\"quote\"\r\n";
        $this->assertSame($expected, $csv);
    }

    public function test_tostring_escapes_then_quotes_formula_with_comma(): void
    {
        $csv = CsvWriter::toString(['v'], [['=SUM(A1,A2)']]);
        // formula-escaped to '=SUM(A1,A2) then RFC-4180-quoted because of the comma.
        $this->assertSame("v\r\n\"'=SUM(A1,A2)\"\r\n", $csv);
    }

    public function test_tostring_casts_non_string_cells(): void
    {
        $csv = CsvWriter::toString(['id', 'cents'], [[42, 80]]);
        $this->assertSame("id,cents\r\n42,80\r\n", $csv);
    }
}
