<?php // tests/unit/Admin/CodeIntakeParserTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Admin;
use PHPUnit\Framework\TestCase;
use PortoSender\Admin\CodeIntakePage;

final class CodeIntakeParserTest extends TestCase
{
    public function test_parses_newlines_commas_trims_and_dedupes(): void
    {
        $raw = "AB12\n CD34 ,EF56\nAB12\n\n";
        $this->assertSame(['AB12', 'CD34', 'EF56'], CodeIntakePage::parseCodes($raw));
    }
}
