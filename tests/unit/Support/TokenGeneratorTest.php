<?php // tests/unit/Support/TokenGeneratorTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Support;
use PHPUnit\Framework\TestCase;
use PortoSender\Support\TokenGenerator;

final class TokenGeneratorTest extends TestCase
{
    public function test_generates_unique_64_char_hex(): void
    {
        $g = new TokenGenerator();
        $a = $g->generate();
        $this->assertSame(64, strlen($a));
        $this->assertNotSame($a, $g->generate());
    }
}
