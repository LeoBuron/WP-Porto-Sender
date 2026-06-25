<?php
declare(strict_types=1);

namespace PortoSender\Geo;

/**
 * Resolves a visitor IP to a country. Implementations are interchangeable so the
 * gate logic stays provider-agnostic and unit-testable with a fake.
 */
interface GeoProvider
{
    /** @return string|null ISO-3166-1 alpha-2 country code (uppercase), or null if unknown */
    public function country(string $ip): ?string;
}
