<?php
declare(strict_types=1);

namespace PortoSender\Geo;

/**
 * Resolves the country via a third-party IP-geolocation API.
 *
 * SIGN-OFF GATED: this sends the visitor IP to an external processor (a new
 * outbound data flow with DSGVO/AVV implications). It ships OFF — country()
 * makes no request unless an API URL is configured, and the factory only builds
 * it when geo_api_url is set. The API key is treated as a secret (never logged).
 */
final class ApiGeoProvider implements GeoProvider
{
    public function __construct(private string $apiUrl, private string $apiKey)
    {
    }

    public function country(string $ip): ?string
    {
        if ($this->apiUrl === '') {
            return null;
        }
        $url = add_query_arg(['ip' => $ip, 'key' => $this->apiKey], $this->apiUrl);
        $resp = wp_remote_get($url, ['timeout' => 3]);
        if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) {
            return null;
        }
        $body = json_decode((string) wp_remote_retrieve_body($resp), true);
        $cc = is_array($body) ? ($body['country'] ?? $body['country_code'] ?? null) : null;
        return is_string($cc) && preg_match('/^[A-Z]{2}$/', strtoupper($cc)) === 1 ? strtoupper($cc) : null;
    }
}
