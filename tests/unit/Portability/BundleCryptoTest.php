<?php declare(strict_types=1);
namespace PortoSender\Tests\unit\Portability;

use PHPUnit\Framework\TestCase;
use PortoSender\Portability\BundleCrypto;

final class BundleCryptoTest extends TestCase
{
    protected function setUp(): void
    {
        if (!BundleCrypto::available()) {
            $this->markTestSkipped('ext-sodium not available');
        }
    }

    public function test_encrypt_then_decrypt_round_trips(): void
    {
        $crypto = new BundleCrypto();
        $plain = '{"format_version":1,"settings":{"hash_salt":"SECRET"}}';
        $blob = $crypto->encrypt($plain, 'correct horse battery staple');
        $this->assertNotSame($plain, $blob);
        $this->assertSame($plain, $crypto->decrypt($blob, 'correct horse battery staple'));
    }

    public function test_wrong_passphrase_throws(): void
    {
        $crypto = new BundleCrypto();
        $blob = $crypto->encrypt('secret', 'right-passphrase');
        $this->expectException(\RuntimeException::class);
        $crypto->decrypt($blob, 'wrong-passphrase');
    }

    public function test_non_bundle_blob_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        (new BundleCrypto())->decrypt('not an encrypted bundle at all', 'pw');
    }

    public function test_two_encryptions_of_same_text_differ(): void
    {
        $crypto = new BundleCrypto();
        $a = $crypto->encrypt('same', 'pw');
        $b = $crypto->encrypt('same', 'pw');
        $this->assertNotSame($a, $b); // random per-encryption salt + nonce
        $this->assertSame('same', $crypto->decrypt($a, 'pw'));
        $this->assertSame('same', $crypto->decrypt($b, 'pw'));
    }
}
