<?php
declare(strict_types=1);

namespace PortoSender\Geo;

use PortoSender\Settings\Settings;

/**
 * Builds the configured GeoProvider, failing SAFE to NullGeoProvider whenever geo
 * is disabled or the chosen source is not properly set up (Cloudflare not
 * acknowledged, MaxMind lib/db absent, API URL missing). A NullGeoProvider yields
 * country()=null, which under the default fail-open gate means allow-all — so
 * "enabled but unconfigured" degrades to allow-all, never to a broken deny-all.
 */
final class GeoProviderFactory
{
    public static function make(Settings $settings): GeoProvider
    {
        if (!$settings->geoEnabled()) {
            return new NullGeoProvider();
        }

        switch ($settings->geoProvider()) {
            case 'cloudflare':
                return $settings->geoCloudflareAck()
                    ? new CloudflareHeaderGeoProvider()
                    : new NullGeoProvider();
            case 'maxmind':
                $maxmind = new MaxMindGeoProvider($settings->geoMaxmindDbPath());
                return $maxmind->available() ? $maxmind : new NullGeoProvider();
            case 'api':
                return $settings->geoApiUrl() !== ''
                    ? new ApiGeoProvider($settings->geoApiUrl(), $settings->geoApiKey())
                    : new NullGeoProvider();
            default:
                return new NullGeoProvider();
        }
    }
}
