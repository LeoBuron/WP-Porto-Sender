<?php
declare(strict_types=1);
namespace PortoSender\Admin;

use PortoSender\Settings\Settings;
use PortoSender\Postage\ProductCatalog;

final class SettingsPage
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_init', [$this, 'registerSetting']);
    }

    public function addMenu(): void
    {
        add_menu_page(
            __('Porto-Sender', 'wp-porto-sender'), __('Porto-Sender', 'wp-porto-sender'),
            'manage_options', 'porto-sender', [$this, 'render'], 'dashicons-email-alt'
        );
    }

    public function registerSetting(): void
    {
        register_setting('porto_sender', Settings::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [Settings::class, 'sanitize'],
            'default' => Settings::defaults(),
        ]);
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) { return; }
        $s = Settings::fromOption();
        $catalog = ProductCatalog::default();
        echo '<div class="wrap"><h1>' . esc_html__('Porto-Sender – Einstellungen', 'wp-porto-sender') . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('porto_sender');
        $opt = Settings::OPTION;
        // Owner address
        printf('<p><label>%s<br><textarea name="%s[owner_address]" rows="4" cols="40">%s</textarea></label></p>',
            esc_html__('Deine Postadresse (steht in der E-Mail)', 'wp-porto-sender'), esc_attr($opt), esc_textarea($s->ownerAddress()));
        // Enabled products + per-product threshold
        echo '<fieldset><legend>' . esc_html__('Aktive Produkte & Mindestbestand', 'wp-porto-sender') . '</legend>';
        foreach ($catalog->all() as $p) {
            $checked = in_array($p->key, $s->enabledProducts(), true) ? 'checked' : '';
            printf('<p><label><input type="checkbox" name="%1$s[enabled_products][]" value="%2$s" %3$s> %4$s</label> '
                . '<input type="number" min="0" name="%1$s[low_stock_thresholds][%2$s]" value="%5$d"></p>',
                esc_attr($opt), esc_attr($p->key), $checked, esc_html($p->label), $s->lowStockThreshold($p->key));
        }
        echo '</fieldset>';
        // Dedup mode
        echo '<p><label>' . esc_html__('Begrenzung pro Person', 'wp-porto-sender') . ' ';
        echo '<select name="' . esc_attr($opt) . '[request_limit_mode]">';
        foreach (['email' => 'E-Mail', 'name' => 'Name', 'name_or_email' => 'Name oder E-Mail', 'none' => 'Keine'] as $val => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($val), selected($s->requestLimitMode(), $val, false), esc_html($label));
        }
        echo '</select></label></p>';
        // Simple text/number fields
        $fields = [
            'alert_email' => [__('Alarm-E-Mail', 'wp-porto-sender'), 'email', $s->alertEmail()],
            'pii_retention_days' => [__('Datenaufbewahrung (Tage)', 'wp-porto-sender'), 'number', $s->piiRetentionDays()],
            'altcha_hmac_secret' => [__('Altcha HMAC-Secret', 'wp-porto-sender'), 'text', $s->altchaHmacSecret()],
            'privacy_policy_url' => [__('Datenschutz-URL', 'wp-porto-sender'), 'url', $s->privacyPolicyUrl()],
        ];
        foreach ($fields as $key => [$label, $type, $value]) {
            printf('<p><label>%s<br><input type="%s" name="%s[%s]" value="%s"></label></p>',
                esc_html($label), esc_attr($type), esc_attr($opt), esc_attr($key), esc_attr((string) $value));
        }
        // Rate limiting
        echo '<fieldset><legend>' . esc_html__('Rate-Limiting (Missbrauchsschutz)', 'wp-porto-sender') . '</legend>';
        printf('<p><label><input type="checkbox" name="%1$s[rate_limit_enabled]" value="1" %2$s> %3$s</label></p>',
            esc_attr($opt), checked($s->rateLimitEnabled(), true, false), esc_html__('Rate-Limiting aktiv', 'wp-porto-sender'));
        printf('<p><label>%2$s<br><input type="number" min="0" name="%1$s[rate_limit_per_ip_day]" value="%3$d"></label></p>',
            esc_attr($opt), esc_html__('Max. Anfragen pro IP/Tag', 'wp-porto-sender'), $s->rateLimitPerIpDay());
        printf('<p><label>%2$s<br><input type="number" min="0" name="%1$s[rate_limit_global_hour]" value="%3$d"></label></p>',
            esc_attr($opt), esc_html__('Max. Anfragen gesamt/Stunde', 'wp-porto-sender'), $s->rateLimitGlobalHour());
        echo '</fieldset>';
        submit_button();
        echo '</form></div>';
    }
}
