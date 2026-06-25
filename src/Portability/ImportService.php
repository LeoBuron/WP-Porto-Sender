<?php
declare(strict_types=1);

namespace PortoSender\Portability;

use PortoSender\Inventory\CodeStore;
use PortoSender\Requests\RequestStore;
use PortoSender\Settings\Settings;
use PortoSender\Persistence\Schema;
use PortoSender\Persistence\SchemaVersion;

/**
 * Applies an import bundle.
 *
 * All validation (decrypt + parse + array-type check + schema-version bound)
 * happens BEFORE any destructive step, so a malformed, wrong-version, or
 * structurally-corrupt bundle aborts WITHOUT touching the database.
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

    // Warning codes — the WP/presentation layer (ToolsPage) renders the translated text,
    // so the domain layer stays free of i18n.
    public const WARN_SALT_MISMATCH = 'salt_mismatch';
    public const WARN_ROWS_SKIPPED = 'rows_skipped';

    public function __construct(
        private CodeStore $codes,
        private RequestStore $requests,
        private BundleSerializer $serializer = new BundleSerializer(),
        private BundleCrypto $crypto = new BundleCrypto(),
    ) {
    }

    /**
     * @return array{mode:string, codes:int, requests:int, warnings:array<int,array{code:string,count?:int}>}
     * @throws \RuntimeException on a bad/locked bundle, \InvalidArgumentException on an unknown mode
     */
    public function importBundle(string $blob, ?string $passphrase, string $mode): array
    {
        // --- validation phase: NO destructive side effects until everything here passes ---
        $json = $this->maybeDecrypt($blob, $passphrase);
        $data = $this->serializer->parse($json); // throws on malformed JSON / missing keys / bad format_version
        $codes = $data['codes'];
        $requests = $data['requests'];

        // Type-check BEFORE any deleteAll(): a structurally-valid bundle with a scalar/null
        // codes/requests would otherwise TypeError on insertRows AFTER the tables were wiped.
        if (!is_array($codes) || !is_array($requests)) {
            throw new \RuntimeException('Bundle is malformed: "codes" and "requests" must be arrays.');
        }
        // Refuse a bundle from a newer plugin: its rows/schema may not fit our tables.
        $schemaVersion = (string) $data['schema_version'];
        if (version_compare($schemaVersion, Schema::CURRENT_VERSION, '>')) {
            throw new \RuntimeException(sprintf(
                'Bundle schema version %s is newer than this plugin supports (%s); upgrade the plugin before importing.',
                $schemaVersion,
                Schema::CURRENT_VERSION
            ));
        }

        // --- apply phase ---
        if ($mode === self::MODE_FULL) {
            $this->codes->deleteAll();
            $this->requests->deleteAll();
            $insertedCodes = $this->codes->insertRows($codes);
            $insertedRequests = $this->requests->insertRows($requests);
            update_option(Settings::OPTION, $this->sanitizeImportedSettings($data['settings'])); // restores salt
            (new SchemaVersion())->set($schemaVersion);

            return ['mode' => $mode, 'codes' => $insertedCodes, 'requests' => $insertedRequests, 'warnings' => []];
        }

        if ($mode === self::MODE_MERGE) {
            $attemptedCodes = count($codes);
            $attemptedRequests = count($requests);
            $insertedCodes = $this->codes->insertRows($codes);
            $insertedRequests = $this->requests->insertRows($requests);

            // Imported hashes were computed under the source salt; unless this install shares it,
            // dedup/token matching won't align. (Codes, not text — ToolsPage renders the message.)
            $warnings = [['code' => self::WARN_SALT_MISMATCH]];
            // Surface silently-dropped rows: merging into a non-empty install collides on the
            // primary key / unique code / token, so insertRows skips those rows. Don't report
            // "complete" while rows were dropped.
            $skipped = ($attemptedCodes - $insertedCodes) + ($attemptedRequests - $insertedRequests);
            if ($skipped > 0) {
                $warnings[] = ['code' => self::WARN_ROWS_SKIPPED, 'count' => $skipped];
            }

            return ['mode' => $mode, 'codes' => $insertedCodes, 'requests' => $insertedRequests, 'warnings' => $warnings];
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
