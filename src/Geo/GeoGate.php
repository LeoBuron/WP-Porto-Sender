<?php
declare(strict_types=1);

namespace PortoSender\Geo;

use PortoSender\Settings\Settings;

/**
 * Pure geo-eligibility policy. Inserted as ONE boolean step in issuance so it can
 * never short-circuit the other gates (captcha / rate-limit / dedup / pool cap).
 *
 * - disabled            -> allow (no IP->country processing happens at all)
 * - country known       -> allow iff it is in the admin's allow-list
 * - country unknown/err -> apply the fail mode (default fail-open: allow)
 *
 * Default fail-open is deliberate: false-denying a legitimate DE visitor
 * (VPN / CGNAT / travel / header-absent) is worse than the abuse it prevents, and
 * the other gates still hold. Never throws — a provider error becomes "unknown".
 */
final class GeoGate
{
    public function __construct(private GeoProvider $provider, private Settings $settings)
    {
    }

    public function allows(string $ip): bool
    {
        if (!$this->settings->geoEnabled()) {
            return true;
        }

        try {
            $country = $this->provider->country($ip);
        } catch (\Throwable $e) {
            $country = null;
        }

        if ($country === null) {
            return $this->settings->geoFailOpen();
        }

        return in_array($country, $this->settings->geoAllowedCountries(), true);
    }
}
