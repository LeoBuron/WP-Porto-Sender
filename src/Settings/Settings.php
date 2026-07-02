<?php
declare(strict_types=1);
namespace PortoSender\Settings;

use PortoSender\Mail\EmailDefaults;

final class Settings
{
    public const OPTION = 'porto_sender_settings';
    private const MODES = ['email', 'name', 'name_or_email', 'none'];
    private const PRODUCTS = ['standardbrief', 'grossbrief'];
    private const LAYOUTS = ['stacked', 'compact', 'card'];

    /** Editable-text keys → their built-in German defaults (used as fallback). */
    public const TEXT_DEFAULTS = [
        'text_intro' => '',
        'text_label_name' => 'Name',
        'text_label_email' => 'E-Mail',
        'text_legend_products' => 'Was möchtest du senden?',
        'text_consent' => 'Ich bin einverstanden, dass mein Name und meine E-Mail zur Zusendung des Codes verarbeitet werden.',
        'text_button' => 'Porto-Code anfordern',
        // Built-in "sent"/"result" page texts (Seiten tab). PageRenderer shows them on
        // the plugin's themed views and injects them as the notice on override pages.
        'text_page_sent' => 'Bitte bestätige die Anfrage über den Link in deiner E-Mail.',
        'text_status_issued' => 'Dein Porto-Code wurde dir per E-Mail zugeschickt.',
        'text_status_already_issued' => 'Du hast deinen Porto-Code bereits erhalten.',
        'text_status_expired' => 'Dieser Bestätigungslink ist abgelaufen. Bitte stelle eine neue Anfrage.',
        'text_status_out_of_stock' => 'Aktuell sind keine Codes verfügbar. Bitte versuche es später erneut.',
        'text_status_email_failed' => 'Der Versand ist fehlgeschlagen. Bitte versuche es später erneut.',
        'text_status_invalid_token' => 'Dieser Bestätigungslink ist ungültig.',
    ];

    /** E-mail template keys (subject + body per message); default '' = use Mailer's built-in copy. */
    public const EMAIL_KEYS = [
        'email_confirm_subject', 'email_confirm_body',
        'email_delivery_subject', 'email_delivery_body',
        'email_admin_subject', 'email_admin_body',
        'email_lowstock_subject', 'email_lowstock_body',
        'email_outofstock_subject', 'email_outofstock_body',
    ];

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
            'unconfirmed_retention_days' => 30,
            'captcha_provider' => 'altcha',
            'altcha_hmac_secret' => '',
            'confirm_token_ttl_hours' => 48,
            'reservation_ttl_minutes' => 30,
            'expiry_warning_months' => 6,
            'privacy_policy_url' => '',
            // Form appearance (Item 1b) — all with working presets.
            'form_layout' => 'stacked',
            'form_accent_color' => '#0b5fff',
            'form_button_bg' => '#0b5fff',
            'form_button_text' => '#ffffff',
            'form_max_width_px' => 520,
            'form_field_gap_px' => 12,
            // Follow-up + result pages (Item 2) — 0 = plugin built-in view.
            'page_sent' => 0,
            'page_result' => 0,
            'hash_salt' => '',
        ] + self::TEXT_DEFAULTS + array_fill_keys(self::EMAIL_KEYS, '');
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
    public function unconfirmedRetentionDays(): int { return (int) $this->values['unconfirmed_retention_days']; }
    public function captchaProvider(): string { return (string) $this->values['captcha_provider']; }
    public function altchaHmacSecret(): string { return (string) $this->values['altcha_hmac_secret']; }
    public function confirmTokenTtlHours(): int { return (int) $this->values['confirm_token_ttl_hours']; }
    public function reservationTtlMinutes(): int { return (int) $this->values['reservation_ttl_minutes']; }
    public function expiryWarningMonths(): int { return (int) $this->values['expiry_warning_months']; }
    public function privacyPolicyUrl(): string { return (string) $this->values['privacy_policy_url']; }
    public function hashSalt(): string { return (string) $this->values['hash_salt']; }

    // Form appearance (Item 1b)
    public function formLayout(): string { return in_array($this->values['form_layout'] ?? '', self::LAYOUTS, true) ? (string) $this->values['form_layout'] : 'stacked'; }
    public function formAccentColor(): string { return (string) $this->values['form_accent_color']; }
    public function formButtonBg(): string { return (string) $this->values['form_button_bg']; }
    public function formButtonText(): string { return (string) $this->values['form_button_text']; }
    public function formMaxWidthPx(): int { return (int) $this->values['form_max_width_px']; }
    public function formFieldGapPx(): int { return (int) $this->values['form_field_gap_px']; }

    /** Configured UI text for $key, falling back to the built-in default. */
    public function text(string $key): string
    {
        $v = (string) ($this->values[$key] ?? '');
        return $v !== '' ? $v : (string) (self::TEXT_DEFAULTS[$key] ?? '');
    }

    // Pages (Item 2)
    public function pageSent(): int { return (int) $this->values['page_sent']; }
    public function pageResult(): int { return (int) $this->values['page_result']; }

    /** Raw configured e-mail template for $key ('' = caller should use its built-in default). */
    public function emailTemplate(string $key): string { return (string) ($this->values[$key] ?? ''); }

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
        $result['unconfirmed_retention_days'] = max(1, (int) ($input['unconfirmed_retention_days'] ?? $result['unconfirmed_retention_days']));
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

        // Form appearance (Item 1b). Invalid hex/enum keeps the stored/default value.
        $result['form_layout'] = in_array($input['form_layout'] ?? '', self::LAYOUTS, true)
            ? $input['form_layout'] : $result['form_layout'];
        foreach (['form_accent_color', 'form_button_bg', 'form_button_text'] as $ck) {
            $hex = sanitize_hex_color((string) ($input[$ck] ?? ''));
            $result[$ck] = ($hex !== null && $hex !== '') ? $hex : $result[$ck];
        }
        $result['form_max_width_px'] = absint($input['form_max_width_px'] ?? $result['form_max_width_px']);
        $result['form_field_gap_px'] = absint($input['form_field_gap_px'] ?? $result['form_field_gap_px']);

        // Editable UI text (Item 1b) + page texts. Like the e-mail templates below, a
        // submitted value equal to its built-in default is stored as '' so text() keeps
        // following the plugin default (future default/translation improvements still
        // reach the install instead of freezing the prefilled copy on first save). The
        // two multi-line fields are CRLF-normalised; text_intro's default is '' so an
        // empty intro simply stays ''.
        $multiline = ['text_intro', 'text_consent'];
        foreach (array_keys(self::TEXT_DEFAULTS) as $tk) {
            $isMultiline = in_array($tk, $multiline, true);
            $clean = $isMultiline
                ? str_replace(["\r\n", "\r"], "\n", sanitize_textarea_field($input[$tk] ?? $result[$tk]))
                : sanitize_text_field($input[$tk] ?? $result[$tk]);
            $default = $isMultiline
                ? sanitize_textarea_field(self::TEXT_DEFAULTS[$tk])
                : sanitize_text_field(self::TEXT_DEFAULTS[$tk]);
            $result[$tk] = ($clean === $default) ? '' : $clean;
        }

        // Pages (Item 2).
        $result['page_sent'] = absint($input['page_sent'] ?? $result['page_sent']);
        $result['page_result'] = absint($input['page_result'] ?? $result['page_result']);

        // E-mail templates (Item 6): subjects single-line, bodies multi-line. The form
        // prefills empty fields with the built-in defaults, so a submitted value that
        // still equals its default is stored as '' again — the install keeps following
        // the plugin default (incl. the admin mail's dynamic "Anfrage von" PII line).
        // Line endings are normalised because textareas post CRLF while the built-in
        // defaults use LF.
        foreach (self::EMAIL_KEYS as $ek) {
            $isSubject = str_ends_with($ek, '_subject');
            $clean = $isSubject
                ? sanitize_text_field($input[$ek] ?? $result[$ek])
                : str_replace(["\r\n", "\r"], "\n", sanitize_textarea_field($input[$ek] ?? $result[$ek]));
            $default = $isSubject
                ? sanitize_text_field(EmailDefaults::get($ek))
                : sanitize_textarea_field(EmailDefaults::get($ek));
            $result[$ek] = ($clean === $default) ? '' : $clean;
        }

        return $result;
    }
}
