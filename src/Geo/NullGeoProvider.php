<?php
declare(strict_types=1);

namespace PortoSender\Geo;

/** Knows nothing — used when geo is disabled or a source is unavailable/unacknowledged. */
final class NullGeoProvider implements GeoProvider
{
    public function country(string $ip): ?string
    {
        return null;
    }
}
