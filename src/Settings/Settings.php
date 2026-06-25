<?php
declare(strict_types=1);
namespace PortoSender\Settings;

final class Settings
{
    public const OPTION = 'porto_sender_settings';
    private const MODES = ['email', 'name', 'name_or_email', 'none'];
    private const PRODUCTS = ['standardbrief', 'grossbrief'];

    private array $values;

    public function __construct(array $values = [])
    {
        $this->values = array_merge(self::defaults(), $values);
    }

    public static function fromOption(): self
    {
        $stored = get_option(self::OPTION, []);
        return new self(is_array($stored) ? $stored : []);
    }

    /** The full settings array (defaults merged with stored values), incl. hash_salt. */
    public function toArray(): array
    {
        return $this->values;
    }

    public static function defaults(): array
    {
        return [
            'owner_address' => '',
            'enabled_products' => ['standardbrief', 'grossbrief'],
            'low_stock_thresholds' => [],
            'default_low_stock' => 5,
            'alert_email' => '',
            'request_limit_mode' => 'name_or_email',
            'rate_limit_enabled' => true,
            'rate_limit_per_ip_day' => 3,
            'rate_limit_global_hour' => 20,
            'admin_notify_enabled' => true,
            'admin_notify_include_pii' => false,
            'admin_notify_window_minutes' => 15,
            'geo_enabled' => false,
            'geo_provider' => 'cloudflare',
            'geo_allowed_countries' => ['DE'],
            'geo_fail_mode' => 'open',
            'geo_cloudflare_ack' => false,
            'geo_maxmind_db_path' => '',
            'geo_api_url' => '',
            'geo_api_key' => '',
            'pii_retention_days' => 180,
            'captcha_provider' => 'altcha',
            'altcha_hmac_secret' => '',
            'confirm_token_ttl_hours' => 48,
            'reservation_ttl_minutes' => 30,
            'expiry_warning_months' => 6,
            'privacy_policy_url' => '',
            'hash_salt' => '',
        ];
    }

    public function ownerAddress(): string { return (string) $this->values['owner_address']; }
    /** @return array<int,string> */
    public function enabledProducts(): array { return array_values((array) $this->values['enabled_products']); }
    public function lowStockThreshold(string $product): int
    {
        $map = (array) $this->values['low_stock_thresholds'];
        return (int) ($map[$product] ?? $this->values['default_low_stock']);
    }
    public function alertEmail(): string { return (string) $this->values['alert_email']; }
    public function requestLimitMode(): string { return (string) $this->values['request_limit_mode']; }
    public function rateLimitEnabled(): bool { return (bool) $this->values['rate_limit_enabled']; }
    public function rateLimitPerIpDay(): int { return (int) $this->values['rate_limit_per_ip_day']; }
    public function rateLimitGlobalHour(): int { return (int) $this->values['rate_limit_global_hour']; }
    public function adminNotifyEnabled(): bool { return (bool) $this->values['admin_notify_enabled']; }
    public function adminNotifyIncludePii(): bool { return (bool) $this->values['admin_notify_include_pii']; }
    public function adminNotifyWindowMinutes(): int { return (int) $this->values['admin_notify_window_minutes']; }
    public function geoEnabled(): bool { return (bool) $this->values['geo_enabled']; }
    public function geoProvider(): string { return (string) $this->values['geo_provider']; }
    /** @return array<int,string> ISO-3166-1 alpha-2 codes */
    public function geoAllowedCountries(): array { return array_values((array) $this->values['geo_allowed_countries']); }
    public function geoFailOpen(): bool { return ($this->values['geo_fail_mode'] ?? 'open') !== 'closed'; }
    public function geoCloudflareAck(): bool { return (bool) $this->values['geo_cloudflare_ack']; }
    public function geoMaxmindDbPath(): string { return (string) $this->values['geo_maxmind_db_path']; }
    public function geoApiUrl(): string { return (string) $this->values['geo_api_url']; }
    public function geoApiKey(): string { return (string) $this->values['geo_api_key']; }
    public function piiRetentionDays(): int { return (int) $this->values['pii_retention_days']; }
    public function captchaProvider(): string { return (string) $this->values['captcha_provider']; }
    public function altchaHmacSecret(): string { return (string) $this->values['altcha_hmac_secret']; }
    public function confirmTokenTtlHours(): int { return (int) $this->values['confirm_token_ttl_hours']; }
    public function reservationTtlMinutes(): int { return (int) $this->values['reservation_ttl_minutes']; }
    public function expiryWarningMonths(): int { return (int) $this->values['expiry_warning_months']; }
    public function privacyPolicyUrl(): string { return (string) $this->values['privacy_policy_url']; }
    public function hashSalt(): string { return (string) $this->values['hash_salt']; }

    public static function sanitize(array $input): array
    {
        // Start from defaults merged over the currently stored option, then overwrite
        // ONLY the keys the admin form actually renders. Keys not present in the form
        // (hash_salt, default_low_stock, captcha_provider, confirm_token_ttl_hours,
        // reservation_ttl_minutes, expiry_warning_months) MUST retain their stored
        // values — wiping hash_salt would invalidate every email/name/token hash.
        $existing = get_option(self::OPTION, []);
        $result = array_merge(self::defaults(), is_array($existing) ? $existing : []);

        // Form-rendered fields (see Admin\SettingsPage::render()).
        $result['owner_address'] = sanitize_textarea_field($input['owner_address'] ?? $result['owner_address']);
        // enabled_products is a checkbox group the form always renders; an absent key
        // means "all unchecked" rather than "keep previous".
        $result['enabled_products'] = array_values(array_intersect(self::PRODUCTS, (array) ($input['enabled_products'] ?? [])));
        $result['low_stock_thresholds'] = array_map('absint', (array) ($input['low_stock_thresholds'] ?? $result['low_stock_thresholds']));
        $result['request_limit_mode'] = in_array($input['request_limit_mode'] ?? '', self::MODES, true) ? $input['request_limit_mode'] : $result['request_limit_mode'];
        $result['alert_email'] = sanitize_email($input['alert_email'] ?? $result['alert_email']);
        $result['pii_retention_days'] = max(1, (int) ($input['pii_retention_days'] ?? $result['pii_retention_days']));
        $result['altcha_hmac_secret'] = sanitize_text_field($input['altcha_hmac_secret'] ?? $result['altcha_hmac_secret']);
        $result['privacy_policy_url'] = esc_url_raw($input['privacy_policy_url'] ?? $result['privacy_policy_url']);
        // Rate limiting (form-rendered; an absent checkbox means "off").
        $result['rate_limit_enabled'] = !empty($input['rate_limit_enabled']);
        $result['rate_limit_per_ip_day'] = absint($input['rate_limit_per_ip_day'] ?? $result['rate_limit_per_ip_day']);
        $result['rate_limit_global_hour'] = absint($input['rate_limit_global_hour'] ?? $result['rate_limit_global_hour']);
        // Admin notifications (form-rendered; absent checkbox means "off").
        $result['admin_notify_enabled'] = !empty($input['admin_notify_enabled']);
        $result['admin_notify_include_pii'] = !empty($input['admin_notify_include_pii']);
        $result['admin_notify_window_minutes'] = absint($input['admin_notify_window_minutes'] ?? $result['admin_notify_window_minutes']);
        // Geo restriction (WS3) — default OFF; external sources are sign-off-gated.
        $providers = ['none', 'cloudflare', 'maxmind', 'api'];
        $result['geo_enabled'] = !empty($input['geo_enabled']);
        $result['geo_provider'] = in_array($input['geo_provider'] ?? '', $providers, true)
            ? $input['geo_provider'] : $result['geo_provider'];
        $rawCountries = $input['geo_allowed_countries'] ?? '';
        if (is_array($rawCountries)) { $rawCountries = implode(',', $rawCountries); }
        $countries = array_values(array_filter(
            array_map(static fn ($c): string => strtoupper(trim((string) $c)), explode(',', (string) $rawCountries)),
            static fn (string $c): bool => preg_match('/^[A-Z]{2}$/', $c) === 1
        ));
        $result['geo_allowed_countries'] = $countries !== [] ? $countries : ['DE'];
        $result['geo_fail_mode'] = in_array($input['geo_fail_mode'] ?? '', ['open', 'closed'], true)
            ? $input['geo_fail_mode'] : $result['geo_fail_mode'];
        $result['geo_cloudflare_ack'] = !empty($input['geo_cloudflare_ack']);
        $result['geo_maxmind_db_path'] = sanitize_text_field($input['geo_maxmind_db_path'] ?? $result['geo_maxmind_db_path']);
        $result['geo_api_url'] = esc_url_raw($input['geo_api_url'] ?? $result['geo_api_url']);
        $result['geo_api_key'] = sanitize_text_field($input['geo_api_key'] ?? $result['geo_api_key']);

        return $result;
    }
}
