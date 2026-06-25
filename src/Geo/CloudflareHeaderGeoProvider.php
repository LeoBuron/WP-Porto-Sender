<?php
declare(strict_types=1);

namespace PortoSender\Geo;

/**
 * Reads Cloudflare's CF-IPCountry header.
 *
 * TRUST CAVEAT: this is a proxy header. It is only trustworthy when the site is
 * genuinely behind Cloudflare AND the origin refuses direct (non-CF) traffic —
 * otherwise an attacker can set CF-IPCountry: DE to bypass the gate. The plugin
 * cannot verify the deployment, so this provider ships OFF and requires an admin
 * acknowledgement (geo_cloudflare_ack) before the factory will use it.
 */
final class CloudflareHeaderGeoProvider implements GeoProvider
{
    public function country(string $ip): ?string
    {
        $cc = isset($_SERVER['HTTP_CF_IPCOUNTRY'])
            ? strtoupper(trim((string) $_SERVER['HTTP_CF_IPCOUNTRY']))
            : '';
        // CF sends XX (unknown) and T1 (Tor) as non-country sentinels.
        if (preg_match('/^[A-Z]{2}$/', $cc) !== 1 || in_array($cc, ['XX', 'T1'], true)) {
            return null;
        }
        return $cc;
    }
}
