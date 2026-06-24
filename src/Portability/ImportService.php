<?php
declare(strict_types=1);

namespace PortoSender\Portability;

use PortoSender\Inventory\CodeStore;
use PortoSender\Requests\RequestStore;
use PortoSender\Settings\Settings;
use PortoSender\Persistence\SchemaVersion;

/**
 * Applies an import bundle.
 *
 * All validation (decrypt + parse + version check) happens BEFORE any
 * destructive step, so a malformed or wrong-version bundle aborts without
 * touching the database.
 *
 * - full_restore: clear both data tables (DELETE — DML, transaction-safe; the
 *   tables already exist from activation), re-insert every row, then replace the
 *   settings INCLUDING hash_salt and record the bundle's schema version. This is
 *   the lossless path: restoring the source salt keeps all salted hashes
 *   (dedup, tokens, abuse audit) valid.
 * - data_merge: insert rows alongside existing data and keep this install's
 *   settings/salt. Returns a salt-mismatch warning because imported hashes were
 *   computed under the source salt and will not align here.
 */
final class ImportService
{
    public const MODE_FULL = 'full_restore';
    public const MODE_MERGE = 'data_merge';

    public function __construct(
        private CodeStore $codes,
        private RequestStore $requests,
        private BundleSerializer $serializer = new BundleSerializer(),
        private BundleCrypto $crypto = new BundleCrypto(),
    ) {
    }

    /**
     * @return array{mode:string, codes:int, requests:int, warnings:array<int,string>}
     * @throws \RuntimeException on a bad/locked bundle, \InvalidArgumentException on an unknown mode
     */
    public function importBundle(string $blob, ?string $passphrase, string $mode): array
    {
        // --- validation phase: no side effects ---
        $json = $this->maybeDecrypt($blob, $passphrase);
        $data = $this->serializer->parse($json); // throws on malformed / unsupported version
        $codes = $data['codes'];
        $requests = $data['requests'];

        // --- apply phase ---
        if ($mode === self::MODE_FULL) {
            $this->codes->deleteAll();
            $this->requests->deleteAll();
            $insertedCodes = $this->codes->insertRows($codes);
            $insertedRequests = $this->requests->insertRows($requests);
            update_option(Settings::OPTION, $this->sanitizeImportedSettings($data['settings'])); // restores salt
            (new SchemaVersion())->set((string) $data['schema_version']);

            return ['mode' => $mode, 'codes' => $insertedCodes, 'requests' => $insertedRequests, 'warnings' => []];
        }

        if ($mode === self::MODE_MERGE) {
            $insertedCodes = $this->codes->insertRows($codes);
            $insertedRequests = $this->requests->insertRows($requests);

            return [
                'mode' => $mode,
                'codes' => $insertedCodes,
                'requests' => $insertedRequests,
                'warnings' => [
                    'Imported rows were hashed under the source install\'s salt. Unless this install '
                    . 'uses the same hash_salt, dedup and confirmation-token matching will not align '
                    . 'with the imported data. Use Full restore for a lossless migration.',
                ],
            ];
        }

        throw new \InvalidArgumentException('Unknown import mode: ' . $mode);
    }

    /**
     * Whitelist imported settings against the known keys before restoring them.
     *
     * A bundle is untrusted input; writing its settings array verbatim would let
     * a crafted bundle inject arbitrary option keys. Keep only keys that exist in
     * Settings::defaults() (including the intentionally-restored hash_salt) and
     * fill any missing key from defaults. Per-field value validation is not
     * applied: a full restore is an admin-authorized destructive action over the
     * admin's own bundle, and all settings are escaped on output downstream.
     *
     * @param mixed $imported
     * @return array<string,mixed>
     */
    private function sanitizeImportedSettings(mixed $imported): array
    {
        $defaults = Settings::defaults();
        if (!is_array($imported)) {
            return $defaults;
        }
        return array_merge($defaults, array_intersect_key($imported, $defaults));
    }

    private function maybeDecrypt(string $blob, ?string $passphrase): string
    {
        if (BundleCrypto::isEncrypted($blob)) {
            if ($passphrase === null || $passphrase === '') {
                throw new \RuntimeException('This bundle is encrypted; a passphrase is required to import it.');
            }
            return $this->crypto->decrypt($blob, $passphrase);
        }
        return $blob;
    }
}
