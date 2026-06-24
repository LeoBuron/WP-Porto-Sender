<?php // tests/unit/Support/HasherTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Support;
use PHPUnit\Framework\TestCase;
use PortoSender\Support\Hasher;

final class HasherTest extends TestCase
{
    public function test_email_is_normalized_before_hashing(): void
    {
        $h = new Hasher('salt');
        $this->assertSame($h->email('  Foo@Bar.de '), $h->email('foo@bar.de'));
        $this->assertSame(64, strlen($h->email('foo@bar.de')));
    }

    public function test_salt_changes_the_hash(): void
    {
        $this->assertNotSame((new Hasher('a'))->email('x@y.de'), (new Hasher('b'))->email('x@y.de'));
    }

    public function test_name_is_case_and_whitespace_insensitive(): void
    {
        $h = new Hasher('salt');
        $this->assertSame($h->name('Max  Mustermann'), $h->name('max mustermann'));
    }
}
