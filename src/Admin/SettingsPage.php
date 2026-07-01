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
            'pii_retention_days' => [__('Datenaufbewahrung ausgegebener Portos (Tage)', 'wp-porto-sender'), 'number', $s->piiRetentionDays()],
            'unconfirmed_retention_days' => [__('Aufbewahrung unbestätigter Anfragen (Tage)', 'wp-porto-sender'), 'number', $s->unconfirmedRetentionDays()],
            'altcha_hmac_secret' => [__('Altcha HMAC-Secret', 'wp-porto-sender'), 'text', $s->altchaHmacSecret()],
            'privacy_policy_url' => [__('Datenschutz-URL', 'wp-porto-sender'), 'url', $s->privacyPolicyUrl()],
        ];
        $descriptions = [
            'pii_retention_days' => __('Name und E-Mail ausgegebener Portos werden nach so vielen Tagen anonymisiert (der Datensatz und die Hashes bleiben für die Missbrauchsprüfung erhalten).', 'wp-porto-sender'),
            'unconfirmed_retention_days' => __('Nie bestätigte Anfragen werden so viele Tage aufbewahrt (für die Betrugs-/Missbrauchsprüfung) und danach gelöscht. Unabhängig von der Token-Gültigkeit — abgelaufene Links funktionieren weiterhin nicht.', 'wp-porto-sender'),
        ];
        foreach ($fields as $key => [$label, $type, $value]) {
            $idAttr = $key === 'altcha_hmac_secret' ? ' id="porto-altcha-secret"' : '';
            printf('<p><label>%s<br><input type="%s"%s name="%s[%s]" value="%s"></label>',
                esc_html($label), esc_attr($type), $idAttr, esc_attr($opt), esc_attr($key), esc_attr((string) $value));
            if ($key === 'altcha_hmac_secret') {
                printf(' <button type="button" class="button" id="porto-altcha-generate">%s</button>',
                    esc_html__('Generieren', 'wp-porto-sender'));
                printf('<br><span class="description">%s</span>',
                    esc_html__('Erzeugt ein zufälliges 256-Bit-Secret (64 Hex-Zeichen). Danach unten „Änderungen speichern“ klicken. Ohne gesetztes Secret ist die CAPTCHA-Prüfung inaktiv.', 'wp-porto-sender'));
            }
            if (isset($descriptions[$key])) {
                printf('<br><span class="description">%s</span>', esc_html($descriptions[$key]));
            }
            echo '</p>';
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
        // Admin notifications (sent to the "Alarm-E-Mail" address above).
        echo '<fieldset><legend>' . esc_html__('Admin-Benachrichtigung bei Abruf', 'wp-porto-sender') . '</legend>';
        printf('<p><label><input type="checkbox" name="%1$s[admin_notify_enabled]" value="1" %2$s> %3$s</label></p>',
            esc_attr($opt), checked($s->adminNotifyEnabled(), true, false),
            esc_html__('Benachrichtigung an die Alarm-E-Mail senden, wenn ein Porto abgerufen wird', 'wp-porto-sender'));
        printf('<p><label><input type="checkbox" name="%1$s[admin_notify_include_pii]" value="1" %2$s> %3$s</label></p>',
            esc_attr($opt), checked($s->adminNotifyIncludePii(), true, false),
            esc_html__('Name und E-Mail des Anfragenden mitsenden (Datenschutz beachten)', 'wp-porto-sender'));
        printf('<p><label>%2$s<br><input type="number" min="0" name="%1$s[admin_notify_window_minutes]" value="%3$d"></label></p>',
            esc_attr($opt), esc_html__('Sammelfenster in Minuten (0 = jede Anfrage einzeln)', 'wp-porto-sender'), $s->adminNotifyWindowMinutes());
        echo '</fieldset>';
        // Geo restriction (default OFF; external sources require sign-off).
        echo '<fieldset><legend>' . esc_html__('Geo-Beschränkung (nur Deutschland)', 'wp-porto-sender') . '</legend>';
        printf('<p><label><input type="checkbox" name="%1$s[geo_enabled]" value="1" %2$s> %3$s</label></p>',
            esc_attr($opt), checked($s->geoEnabled(), true, false),
            esc_html__('Aktiv (Standard: aus – ohne Aktivierung findet keine IP-Standortverarbeitung statt)', 'wp-porto-sender'));
        echo '<p><label>' . esc_html__('Geo-Quelle', 'wp-porto-sender') . ' <select name="' . esc_attr($opt) . '[geo_provider]">';
        foreach (['cloudflare' => 'Cloudflare CF-IPCountry-Header', 'maxmind' => 'MaxMind GeoLite2 (lokale DB)', 'api' => 'Externe API', 'none' => 'Keine'] as $val => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($val), selected($s->geoProvider(), $val, false), esc_html($label));
        }
        echo '</select></label></p>';
        printf('<p><label>%2$s<br><input type="text" name="%1$s[geo_allowed_countries]" value="%3$s"></label></p>',
            esc_attr($opt), esc_html__('Erlaubte Länder (ISO-2, kommagetrennt)', 'wp-porto-sender'),
            esc_attr(implode(', ', $s->geoAllowedCountries())));
        echo '<p><label>' . esc_html__('Bei unbekanntem Standort', 'wp-porto-sender') . ' <select name="' . esc_attr($opt) . '[geo_fail_mode]">';
        foreach (['open' => 'Durchlassen (empfohlen)', 'closed' => 'Blockieren'] as $val => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($val), selected($s->geoFailOpen() ? 'open' : 'closed', $val, false), esc_html($label));
        }
        echo '</select></label></p>';
        printf('<p><label><input type="checkbox" name="%1$s[geo_cloudflare_ack]" value="1" %2$s> %3$s</label></p>',
            esc_attr($opt), checked($s->geoCloudflareAck(), true, false),
            esc_html__('Ich bestätige: die Seite läuft hinter Cloudflare und der Origin ist gegen Direktzugriff gesperrt (sonst ist der CF-Header fälschbar).', 'wp-porto-sender'));
        echo '<p class="description">' . esc_html__('MaxMind und externe API sind ohne Freigabe deaktiviert (zusätzliche Software bzw. Datenweitergabe an Dritte). Rechtsgrundlage der IP-Verarbeitung: Art. 6 Abs. 1 lit. f DSGVO (Missbrauchsschutz).', 'wp-porto-sender') . '</p>';
        printf('<p><label>%2$s<br><input type="text" name="%1$s[geo_maxmind_db_path]" value="%3$s"></label></p>',
            esc_attr($opt), esc_html__('MaxMind .mmdb-Pfad (nur falls Reader + DB installiert)', 'wp-porto-sender'), esc_attr($s->geoMaxmindDbPath()));
        printf('<p><label>%2$s<br><input type="url" name="%1$s[geo_api_url]" value="%3$s"></label></p>',
            esc_attr($opt), esc_html__('Geo-API URL (sendet IP an Dritte – Freigabe erforderlich)', 'wp-porto-sender'), esc_attr($s->geoApiUrl()));
        printf('<p><label>%2$s<br><input type="password" name="%1$s[geo_api_key]" value="%3$s" autocomplete="new-password"></label></p>',
            esc_attr($opt), esc_html__('Geo-API Schlüssel', 'wp-porto-sender'), esc_attr($s->geoApiKey()));
        echo '</fieldset>';
        submit_button();
        echo '</form></div>';

        // Client-side secret generator — fills the field using the browser's CSPRNG
        // (Web Crypto getRandomValues). No server round-trip, so the plugin stays
        // self-contained and no secret is transmitted; the admin saves via the form.
        $noCryptoMsg = wp_json_encode(
            __('Dein Browser unterstützt keine sichere Zufallserzeugung. Bitte trage das Secret manuell ein.', 'wp-porto-sender')
        );
        ?>
        <script>
        (function () {
            var btn = document.getElementById('porto-altcha-generate');
            var field = document.getElementById('porto-altcha-secret');
            if (!btn || !field) { return; }
            btn.addEventListener('click', function () {
                var rng = window.crypto || window.msCrypto;
                if (!rng || !rng.getRandomValues) {
                    window.alert(<?php echo $noCryptoMsg; ?>);
                    return;
                }
                var bytes = new Uint8Array(32); // 256 bits of entropy
                rng.getRandomValues(bytes);
                field.value = Array.prototype.map.call(bytes, function (b) {
                    return ('0' + b.toString(16)).slice(-2); // each byte -> 2 hex chars
                }).join('');
                field.dispatchEvent(new Event('change', { bubbles: true }));
                field.focus();
            });
        })();
        </script>
        <?php
    }
}
