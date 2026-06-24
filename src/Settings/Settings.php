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

    public static function defaults(): array
    {
        return [
            'owner_address' => '',
            'enabled_products' => ['standardbrief', 'grossbrief'],
            'low_stock_thresholds' => [],
            'default_low_stock' => 5,
            'alert_email' => '',
            'request_limit_mode' => 'name_or_email',
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
        $d = self::defaults();
        return [
            'owner_address' => sanitize_textarea_field($input['owner_address'] ?? $d['owner_address']),
            'enabled_products' => array_values(array_intersect(self::PRODUCTS, (array) ($input['enabled_products'] ?? $d['enabled_products']))),
            'low_stock_thresholds' => array_map('absint', (array) ($input['low_stock_thresholds'] ?? [])),
            'default_low_stock' => max(0, (int) ($input['default_low_stock'] ?? $d['default_low_stock'])),
            'alert_email' => sanitize_email($input['alert_email'] ?? ''),
            'request_limit_mode' => in_array($input['request_limit_mode'] ?? '', self::MODES, true) ? $input['request_limit_mode'] : $d['request_limit_mode'],
            'pii_retention_days' => max(1, (int) ($input['pii_retention_days'] ?? $d['pii_retention_days'])),
            'captcha_provider' => in_array($input['captcha_provider'] ?? '', ['altcha', 'none'], true) ? $input['captcha_provider'] : 'altcha',
            'altcha_hmac_secret' => sanitize_text_field($input['altcha_hmac_secret'] ?? ''),
            'confirm_token_ttl_hours' => max(1, (int) ($input['confirm_token_ttl_hours'] ?? $d['confirm_token_ttl_hours'])),
            'reservation_ttl_minutes' => max(1, (int) ($input['reservation_ttl_minutes'] ?? $d['reservation_ttl_minutes'])),
            'expiry_warning_months' => max(1, (int) ($input['expiry_warning_months'] ?? $d['expiry_warning_months'])),
            'privacy_policy_url' => esc_url_raw($input['privacy_policy_url'] ?? ''),
            'hash_salt' => sanitize_text_field($input['hash_salt'] ?? ''),
        ];
    }
}
