<?php
declare(strict_types=1);

namespace PortoSender\Geo;

/**
 * Looks up the country in a local MaxMind GeoLite2 database.
 *
 * SIGN-OFF GATED: this plugin ships NEITHER the reader library (a new runtime
 * dependency) NOR the GeoLite2 .mmdb (a licensed data file). available() is true
 * only if the admin has independently installed both, so in the shipped
 * distribution it is always unavailable and country() returns null.
 */
final class MaxMindGeoProvider implements GeoProvider
{
    private const READER_CLASS = '\\MaxMind\\Db\\Reader';

    public function __construct(private string $dbPath)
    {
    }

    public function available(): bool
    {
        return $this->dbPath !== ''
            && is_readable($this->dbPath)
            && class_exists(self::READER_CLASS);
    }

    public function country(string $ip): ?string
    {
        if (!$this->available()) {
            return null;
        }
        try {
            $readerClass = self::READER_CLASS;
            $reader = new $readerClass($this->dbPath);
            $record = $reader->get($ip);
            $reader->close();
            $cc = is_array($record) ? ($record['country']['iso_code'] ?? null) : null;
            return is_string($cc) && preg_match('/^[A-Z]{2}$/', strtoupper($cc)) === 1 ? strtoupper($cc) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
