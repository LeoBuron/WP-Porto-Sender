<?php
declare(strict_types=1);

namespace PortoSender\Portability;

/**
 * Serializes and parses the lossless migration bundle.
 *
 * The bundle is the disaster-recovery / migration artifact: it carries the full
 * settings INCLUDING hash_salt plus every codes/requests row, so a full restore
 * into a fresh install preserves dedup history, active confirmation tokens, and
 * abuse audit — all of which are salted hashes that would otherwise stop
 * matching under a new install's salt. Because it contains the salt and
 * plaintext PII the bundle is a credential; at-rest protection lives in
 * BundleCrypto / ExportService. This class only handles structure + versioning.
 */
final class BundleSerializer
{
    public const FORMAT_VERSION = 1;

    private const REQUIRED_KEYS = ['format_version', 'schema_version', 'settings', 'codes', 'requests'];

    /**
     * @param array<string,mixed> $settings
     * @param array<int,array<string,mixed>> $codes
     * @param array<int,array<string,mixed>> $requests
     */
    public function build(
        array $settings,
        array $codes,
        array $requests,
        string $schemaVersion,
        string $siteUrl,
        string $exportedAt
    ): string {
        return json_encode([
            'format_version' => self::FORMAT_VERSION,
            'schema_version' => $schemaVersion,
            'exported_at' => $exportedAt,
            'site_url' => $siteUrl,
            'settings' => $settings,
            'codes' => $codes,
            'requests' => $requests,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string,mixed>
     * @throws \RuntimeException on malformed JSON, a missing key, or an unsupported version
     */
    public function parse(string $json): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Bundle is not valid JSON: ' . $e->getMessage());
        }
        if (!is_array($data)) {
            throw new \RuntimeException('Bundle is not a JSON object.');
        }
        foreach (self::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $data)) {
                throw new \RuntimeException('Bundle is missing the "' . $key . '" field.');
            }
        }
        if ($data['format_version'] !== self::FORMAT_VERSION) {
            throw new \RuntimeException('Unsupported bundle format version: ' . var_export($data['format_version'], true));
        }
        return $data;
    }
}
