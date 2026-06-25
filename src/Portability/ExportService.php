<?php
declare(strict_types=1);

namespace PortoSender\Portability;

use PortoSender\Inventory\CodeStore;
use PortoSender\Requests\RequestStore;
use PortoSender\Settings\Settings;

/**
 * Builds the export artifacts: per-table CSV (human-editable backup) and the
 * lossless migration bundle. Pure builders only — the HTTP streaming (headers +
 * echo + exit, so nothing is ever written into the web root) lives in ToolsPage,
 * which keeps this class unit-testable.
 *
 * The requests CSV deliberately includes raw name/email (a DSGVO data-portability
 * artifact) and every cell is formula-injection-escaped by CsvWriter. The bundle
 * carries the full settings incl. hash_salt and is optionally encrypted.
 */
final class ExportService
{
    public function __construct(
        private CodeStore $codes,
        private RequestStore $requests,
        private Settings $settings,
        private string $schemaVersion,
        private string $siteUrl,
        private string $exportedAt,
        private BundleSerializer $serializer = new BundleSerializer(),
        private BundleCrypto $crypto = new BundleCrypto(),
    ) {
    }

    public function codesCsv(): string
    {
        return $this->tableCsv($this->codes->allRows());
    }

    public function requestsCsv(): string
    {
        return $this->tableCsv($this->requests->allRows());
    }

    /**
     * Build the lossless bundle. With a non-empty passphrase the bundle is
     * encrypted; with no passphrase it is the raw (secret-bearing) JSON, which the
     * caller only emits behind an explicit confirmation.
     *
     * Encryption never silently degrades to plaintext: if a passphrase is given but
     * ext-sodium is unavailable, this throws rather than write the secret salt + PII
     * unencrypted. So the result is encrypted IFF a passphrase was supplied.
     */
    public function bundle(?string $passphrase): string
    {
        $json = $this->serializer->build(
            $this->settings->toArray(),
            $this->codes->allRows(),
            $this->requests->allRows(),
            $this->schemaVersion,
            $this->siteUrl,
            $this->exportedAt
        );

        if ($passphrase !== null && $passphrase !== '') {
            if (!BundleCrypto::available()) {
                throw new \RuntimeException(
                    'A passphrase was set but ext-sodium is unavailable on this server; '
                    . 'refusing to export the secret-bearing bundle unencrypted.'
                );
            }
            return $this->crypto->encrypt($json, $passphrase);
        }
        return $json;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function tableCsv(array $rows): string
    {
        if ($rows === []) {
            return '';
        }
        $header = array_keys($rows[0]);
        $ordered = array_map(
            static fn (array $row): array => array_map(static fn (string $k) => $row[$k] ?? '', $header),
            $rows
        );
        return CsvWriter::toString($header, $ordered);
    }
}
