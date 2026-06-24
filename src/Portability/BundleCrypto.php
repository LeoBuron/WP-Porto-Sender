<?php
declare(strict_types=1);

namespace PortoSender\Portability;

/**
 * Optional passphrase encryption for the export bundle, using libsodium
 * (ext-sodium ships with PHP core — no new runtime dependency).
 *
 * Wire format: MAGIC | pwhash-salt | secretbox-nonce | secretbox-ciphertext.
 * The key is derived from the admin's passphrase via sodium_crypto_pwhash, so
 * the bundle — which carries hash_salt + plaintext PII — is protected at rest
 * after download. When ext-sodium is unavailable, available() returns false and
 * the Tools UI hides the option (an unencrypted bundle is still possible behind
 * an explicit confirmation). Authenticated encryption (secretbox = XSalsa20 +
 * Poly1305) means a wrong passphrase or any tampering fails the MAC and throws.
 */
final class BundleCrypto
{
    private const MAGIC = 'PORTOENC1';

    public static function isEncrypted(string $blob): bool
    {
        return str_starts_with($blob, self::MAGIC);
    }

    public static function available(): bool
    {
        return function_exists('sodium_crypto_secretbox')
            && function_exists('sodium_crypto_pwhash')
            && defined('SODIUM_CRYPTO_PWHASH_SALTBYTES');
    }

    public function encrypt(string $plaintext, string $passphrase): string
    {
        $this->assertAvailable();
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = $this->deriveKey($passphrase, $salt);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
        sodium_memzero($key);

        return self::MAGIC . $salt . $nonce . $cipher;
    }

    public function decrypt(string $blob, string $passphrase): string
    {
        $this->assertAvailable();
        $magicLen = strlen(self::MAGIC);
        $min = $magicLen
            + SODIUM_CRYPTO_PWHASH_SALTBYTES
            + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
            + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
        if (strlen($blob) < $min || !str_starts_with($blob, self::MAGIC)) {
            throw new \RuntimeException('Not a Porto encrypted bundle.');
        }

        $offset = $magicLen;
        $salt = substr($blob, $offset, SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $offset += SODIUM_CRYPTO_PWHASH_SALTBYTES;
        $nonce = substr($blob, $offset, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $offset += SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        $cipher = substr($blob, $offset);

        $key = $this->deriveKey($passphrase, $salt);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        sodium_memzero($key);

        if ($plain === false) {
            throw new \RuntimeException('Wrong passphrase or corrupted bundle.');
        }
        return $plain;
    }

    private function deriveKey(string $passphrase, string $salt): string
    {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            $passphrase,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
        );
    }

    private function assertAvailable(): void
    {
        if (!self::available()) {
            throw new \RuntimeException('ext-sodium is not available; bundle encryption is unsupported.');
        }
    }
}
