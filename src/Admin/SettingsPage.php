<?php
declare(strict_types=1);
namespace PortoSender\Admin;

use PortoSender\Mail\EmailDefaults;
use PortoSender\Settings\Settings;
use PortoSender\Postage\ProductCatalog;
use PortoSender\Frontend\PageProvisioner;

final class SettingsPage
{
    /** Menu hook suffix, captured from add_menu_page() so we scope asset loading to this page. */
    private ?string $hookSuffix = null;

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_init', [$this, 'registerSetting']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function addMenu(): void
    {
        $this->hookSuffix = add_menu_page(
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

    /**
     * Load the colour picker + tab assets only on this settings screen.
     *
     * @param string $hook Current admin page hook suffix (passed by WordPress).
     */
    public function enqueueAssets(string $hook): void
    {
        if ($hook !== $this->hookSuffix) { return; }
        $base = plugins_url('assets/', dirname(__DIR__, 2) . '/porto-sender.php');
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_style('porto-admin-settings', $base . 'admin-settings.css', [], '1.0.0');
        wp_enqueue_script('porto-admin-settings', $base . 'admin-settings.js', ['wp-color-picker'], '1.0.0', true);
    }

    /**
     * The tabbed settings screen. Tabs are presentation-only: every field is rendered
     * inside the single options.php <form>, so Settings::sanitize() still receives all
     * keys on save (no unshown checkbox is ever silently wiped). A small admin script
     * shows one panel at a time; with JS disabled every panel stays visible.
     */
    public function render(): void
    {
        if (!current_user_can('manage_options')) { return; }
        $s = Settings::fromOption();
        $catalog = ProductCatalog::default();
        $opt = Settings::OPTION;

        $tabs = [
            'general'   => __('Allgemein', 'wp-porto-sender'),
            'form'      => __('Formular & Layout', 'wp-porto-sender'),
            'pages'     => __('Seiten', 'wp-porto-sender'),
            'emails'    => __('E-Mails', 'wp-porto-sender'),
            'abuse'     => __('Missbrauchsschutz', 'wp-porto-sender'),
            'retention' => __('Daten & Aufbewahrung', 'wp-porto-sender'),
            'geo'       => __('Geo', 'wp-porto-sender'),
        ];

        echo '<div class="wrap"><h1>' . esc_html__('Porto-Sender – Einstellungen', 'wp-porto-sender') . '</h1>';

        echo '<h2 class="nav-tab-wrapper">';
        $first = true;
        foreach ($tabs as $slug => $label) {
            printf(
                '<a href="#porto-tab-%1$s" class="nav-tab%2$s" data-porto-tab="%1$s">%3$s</a>',
                esc_attr($slug), $first ? ' nav-tab-active' : '', esc_html($label)
            );
            $first = false;
        }
        echo '</h2>';

        echo '<form method="post" action="options.php">';
        settings_fields('porto_sender');

        $first = true;
        foreach ($tabs as $slug => $label) {
            printf(
                '<div class="porto-tab-panel%2$s" id="porto-tab-%1$s" data-tab="%1$s" role="tabpanel">',
                esc_attr($slug), $first ? ' porto-tab-active' : ''
            );
            echo '<h2 class="porto-tab-title">' . esc_html($label) . '</h2>';
            switch ($slug) {
                case 'general':   $this->renderGeneral($s, $opt, $catalog); break;
                case 'form':      $this->renderFormLayout($s, $opt); break;
                case 'pages':     $this->renderPages($s, $opt); break;
                case 'emails':    $this->renderEmails($s, $opt); break;
                case 'abuse':     $this->renderAbuse($s, $opt); break;
                case 'retention': $this->renderRetention($s, $opt); break;
                case 'geo':       $this->renderGeo($s, $opt); break;
            }
            echo '</div>';
            $first = false;
        }

        submit_button();
        echo '</form></div>';

        $this->renderSecretScript();
    }

    private function renderGeneral(Settings $s, string $opt, ProductCatalog $catalog): void
    {
        printf('<p><label>%s<br><textarea name="%s[owner_address]" rows="4" cols="40">%s</textarea></label></p>',
            esc_html__('Deine Postadresse (steht in der E-Mail)', 'wp-porto-sender'), esc_attr($opt), esc_textarea($s->ownerAddress()));

        printf('<p><label>%s<br><input type="email" name="%s[alert_email]" value="%s"></label>',
            esc_html__('Alarm-E-Mail', 'wp-porto-sender'), esc_attr($opt), esc_attr($s->alertEmail()));
        printf('<br><span class="description">%s</span></p>',
            esc_html__('Empfängt Bestandswarnungen und – falls aktiviert – Abruf-Benachrichtigungen.', 'wp-porto-sender'));

        printf('<p><label>%s<br><input type="url" name="%s[privacy_policy_url]" value="%s"></label></p>',
            esc_html__('Datenschutz-URL', 'wp-porto-sender'), esc_attr($opt), esc_attr($s->privacyPolicyUrl()));

        echo '<fieldset><legend>' . esc_html__('Aktive Produkte & Mindestbestand', 'wp-porto-sender') . '</legend>';
        foreach ($catalog->all() as $p) {
            $checked = in_array($p->key, $s->enabledProducts(), true) ? 'checked' : '';
            printf('<p><label><input type="checkbox" name="%1$s[enabled_products][]" value="%2$s" %3$s> %4$s</label> '
                . '<input type="number" min="0" name="%1$s[low_stock_thresholds][%2$s]" value="%5$d"></p>',
                esc_attr($opt), esc_attr($p->key), $checked, esc_html($p->label), $s->lowStockThreshold($p->key));
        }
        echo '</fieldset>';
    }

    private function renderFormLayout(Settings $s, string $opt): void
    {
        // Layout presets (plain labels + one-line description each).
        $layouts = [
            'stacked' => [__('Gestapelt', 'wp-porto-sender'), __('Übersichtlich, ein Feld pro Zeile (Standard).', 'wp-porto-sender')],
            'compact' => [__('Kompakt', 'wp-porto-sender'), __('Engere Abstände für dichte Seiten oder Sidebars.', 'wp-porto-sender')],
            'card'    => [__('Karte', 'wp-porto-sender'), __('Formular in einer hervorgehobenen Box.', 'wp-porto-sender')],
        ];
        echo '<fieldset><legend>' . esc_html__('Layout', 'wp-porto-sender') . '</legend>';
        foreach ($layouts as $val => [$lbl, $desc]) {
            printf('<p><label><input type="radio" name="%1$s[form_layout]" value="%2$s" %3$s> <strong>%4$s</strong></label> <span class="description">%5$s</span></p>',
                esc_attr($opt), esc_attr($val), checked($s->formLayout(), $val, false), esc_html($lbl), esc_html($desc));
        }
        echo '</fieldset>';

        // Colours — native WP colour picker; no hex typing required.
        echo '<fieldset><legend>' . esc_html__('Farben', 'wp-porto-sender') . '</legend>';
        echo '<p class="description">' . esc_html__('Fertige Farbschemata (ein Klick füllt die Auswahl, danach frei anpassbar):', 'wp-porto-sender') . '</p>';
        $schemes = [
            [__('Blau (Standard)', 'wp-porto-sender'), '#0b5fff', '#0b5fff', '#ffffff'],
            [__('Grün', 'wp-porto-sender'), '#1a7f37', '#1a7f37', '#ffffff'],
            [__('Rot', 'wp-porto-sender'), '#cf222e', '#cf222e', '#ffffff'],
            [__('Neutral', 'wp-porto-sender'), '#444444', '#444444', '#ffffff'],
        ];
        echo '<p class="porto-scheme-presets">';
        foreach ($schemes as [$lbl, $accent, $btnBg, $btnText]) {
            printf('<button type="button" class="button porto-scheme" data-accent="%s" data-btn-bg="%s" data-btn-text="%s">%s</button> ',
                esc_attr($accent), esc_attr($btnBg), esc_attr($btnText), esc_html($lbl));
        }
        echo '</p>';
        $this->renderColorField($opt, 'porto-color-accent', 'form_accent_color', $s->formAccentColor(), '#0b5fff', __('Akzentfarbe (Links, Rahmen, Fokus)', 'wp-porto-sender'));
        $this->renderColorField($opt, 'porto-color-btn-bg', 'form_button_bg', $s->formButtonBg(), '#0b5fff', __('Button-Hintergrund', 'wp-porto-sender'));
        $this->renderColorField($opt, 'porto-color-btn-text', 'form_button_text', $s->formButtonText(), '#ffffff', __('Button-Textfarbe', 'wp-porto-sender'));
        echo '</fieldset>';

        // Sizing.
        echo '<fieldset><legend>' . esc_html__('Größe & Abstände', 'wp-porto-sender') . '</legend>';
        printf('<p><label>%2$s<br><input type="number" min="0" name="%1$s[form_max_width_px]" value="%3$d"></label>',
            esc_attr($opt), esc_html__('Maximale Breite in Pixel (0 = volle Breite)', 'wp-porto-sender'), $s->formMaxWidthPx());
        printf('<br><span class="description">%s</span></p>',
            esc_html__('Begrenzt die Formularbreite; 0 lässt es die volle verfügbare Breite einnehmen.', 'wp-porto-sender'));
        printf('<p><label>%2$s<br><input type="number" min="0" name="%1$s[form_field_gap_px]" value="%3$d"></label></p>',
            esc_attr($opt), esc_html__('Abstand zwischen den Feldern in Pixel', 'wp-porto-sender'), $s->formFieldGapPx());
        echo '</fieldset>';

        // Editable texts.
        echo '<fieldset><legend>' . esc_html__('Texte', 'wp-porto-sender') . '</legend>';
        printf('<p><label>%2$s<br><textarea name="%1$s[text_intro]" rows="2" cols="50">%3$s</textarea></label>',
            esc_attr($opt), esc_html__('Einleitungstext (optional, wird über dem Formular angezeigt)', 'wp-porto-sender'), esc_textarea($s->text('text_intro')));
        printf('<br><span class="description">%s</span></p>',
            esc_html__('Leer lassen, um keinen Einleitungstext anzuzeigen.', 'wp-porto-sender'));
        printf('<p><label>%2$s<br><input type="text" name="%1$s[text_label_name]" value="%3$s"></label></p>',
            esc_attr($opt), esc_html__('Feldbeschriftung Name', 'wp-porto-sender'), esc_attr($s->text('text_label_name')));
        printf('<p><label>%2$s<br><input type="text" name="%1$s[text_label_email]" value="%3$s"></label></p>',
            esc_attr($opt), esc_html__('Feldbeschriftung E-Mail', 'wp-porto-sender'), esc_attr($s->text('text_label_email')));
        printf('<p><label>%2$s<br><input type="text" name="%1$s[text_legend_products]" value="%3$s"></label></p>',
            esc_attr($opt), esc_html__('Überschrift Produktauswahl', 'wp-porto-sender'), esc_attr($s->text('text_legend_products')));
        printf('<p><label>%2$s<br><textarea name="%1$s[text_consent]" rows="2" cols="50">%3$s</textarea></label></p>',
            esc_attr($opt), esc_html__('Einwilligungstext (Checkbox)', 'wp-porto-sender'), esc_textarea($s->text('text_consent')));
        printf('<p><label>%2$s<br><input type="text" name="%1$s[text_button]" value="%3$s"></label></p>',
            esc_attr($opt), esc_html__('Button-Beschriftung', 'wp-porto-sender'), esc_attr($s->text('text_button')));
        echo '</fieldset>';
    }

    private function renderColorField(string $opt, string $id, string $key, string $value, string $default, string $label): void
    {
        printf('<p><label>%1$s<br><input type="text" class="porto-color-field" id="%2$s" name="%3$s[%4$s]" value="%5$s" data-default-color="%6$s"></label></p>',
            esc_html($label), esc_attr($id), esc_attr($opt), esc_attr($key), esc_attr($value), esc_attr($default));
    }

    private function renderPages(Settings $s, string $opt): void
    {
        echo '<p class="description">' . esc_html__('Wähle optional eigene Seiten für die Rückmeldungen. „Plugin-Standard" zeigt eine themenintegrierte Standardseite mit dem unten anpassbaren Text.', 'wp-porto-sender') . '</p>';
        $this->renderPageDropdown($opt, 'page_sent', $s->pageSent(),
            __('Seite „Bitte E-Mail bestätigen" (nach dem Absenden)', 'wp-porto-sender'));
        $this->renderPageDropdown($opt, 'page_result', $s->pageResult(),
            __('Ergebnisseite (nach Klick auf den Bestätigungslink)', 'wp-porto-sender'));

        // Texts of the built-in pages, prefilled with their defaults. On a custom page
        // the same text is injected as the notice above the page content.
        echo '<fieldset><legend>' . esc_html__('Text der Seite „Bitte E-Mail bestätigen"', 'wp-porto-sender') . '</legend>';
        echo '<p class="description">' . esc_html__('Wird nach dem Absenden des Formulars angezeigt – auf der Standardseite bzw. als Hinweis über einer eigenen Seite. Feld leeren und speichern setzt auf den Standardtext zurück.', 'wp-porto-sender') . '</p>';
        $this->renderPageTextField($s, $opt, 'text_page_sent', __('Hinweistext', 'wp-porto-sender'));
        echo '</fieldset>';

        echo '<fieldset><legend>' . esc_html__('Texte der Ergebnisseite', 'wp-porto-sender') . '</legend>';
        echo '<p class="description">' . esc_html__('Je nach Ausgang der Bestätigung wird einer dieser Texte angezeigt. Feld leeren und speichern setzt auf den Standardtext zurück.', 'wp-porto-sender') . '</p>';
        $statusFields = [
            'text_status_issued' => __('Erfolg – Code wurde verschickt', 'wp-porto-sender'),
            'text_status_already_issued' => __('Code wurde bereits abgerufen', 'wp-porto-sender'),
            'text_status_expired' => __('Bestätigungslink abgelaufen', 'wp-porto-sender'),
            'text_status_out_of_stock' => __('Kein Vorrat verfügbar', 'wp-porto-sender'),
            'text_status_email_failed' => __('E-Mail-Versand fehlgeschlagen', 'wp-porto-sender'),
            'text_status_invalid_token' => __('Bestätigungslink ungültig', 'wp-porto-sender'),
        ];
        foreach ($statusFields as $key => $label) {
            $this->renderPageTextField($s, $opt, $key, $label);
        }
        echo '</fieldset>';
    }

    /** A single prefilled page-text input (value falls back to the built-in default). */
    private function renderPageTextField(Settings $s, string $opt, string $key, string $label): void
    {
        printf('<p><label>%1$s<br><input type="text" class="large-text" name="%2$s[%3$s]" value="%4$s"></label></p>',
            esc_html($label), esc_attr($opt), esc_attr($key), esc_attr($s->text($key)));
    }

    private function renderPageDropdown(string $opt, string $key, int $selected, string $label): void
    {
        echo '<p><label>' . esc_html($label) . '<br>';
        echo wp_dropdown_pages([
            'name' => $opt . '[' . $key . ']',
            'id' => 'porto-' . str_replace('_', '-', $key),
            'echo' => 0,
            'show_option_none' => __('— Plugin-Standard —', 'wp-porto-sender'),
            'option_none_value' => '0',
            'selected' => $selected,
            // Don't offer the plugin's own auto-provisioned pages as an override choice.
            'exclude' => implode(',', array_filter(PageProvisioner::ids())),
        ]);
        echo '</label></p>';
    }

    private function renderEmails(Settings $s, string $opt): void
    {
        echo '<p class="description">' . esc_html__('Betreff und Text der automatischen E-Mails, vorbefüllt mit den Standardtexten – einfach direkt hier anpassen. Ein geleertes Feld wird beim Speichern auf den Standardtext zurückgesetzt. Verfügbare Platzhalter stehen unter jedem Feld.', 'wp-porto-sender') . '</p>';
        $messages = [
            'confirm'    => [__('Bestätigung (Double-Opt-In)', 'wp-porto-sender'), ['%name%', '%confirm_url%']],
            'delivery'   => [__('Zustellung (der Code)', 'wp-porto-sender'), ['%name%', '%product%', '%limits%', '%code%', '%owner_address%']],
            'admin'      => [__('Admin-Benachrichtigung', 'wp-porto-sender'), ['%product%', '%count%', '%remaining%', '%name%', '%email%', '%requests%']],
            'lowstock'   => [__('Geringer Bestand', 'wp-porto-sender'), ['%product%', '%remaining%']],
            'outofstock' => [__('Kein Bestand', 'wp-porto-sender'), ['%product%']],
        ];
        foreach ($messages as $key => [$label, $placeholders]) {
            $subjectKey = 'email_' . $key . '_subject';
            $bodyKey = 'email_' . $key . '_body';
            $subject = $this->emailValue($s, $subjectKey);
            $body = $this->emailValue($s, $bodyKey);
            echo '<fieldset><legend>' . esc_html($label) . '</legend>';
            printf('<p><label>%1$s<br><input type="text" class="large-text" name="%2$s[%3$s]" value="%4$s"></label></p>',
                esc_html__('Betreff', 'wp-porto-sender'), esc_attr($opt), esc_attr($subjectKey), esc_attr($subject));
            printf('<p><label>%1$s<br><textarea class="large-text" name="%2$s[%3$s]" rows="%4$d">%5$s</textarea></label></p>',
                esc_html__('Text', 'wp-porto-sender'), esc_attr($opt), esc_attr($bodyKey),
                min(14, max(5, substr_count($body, "\n") + 2)), esc_textarea($body));
            $codes = implode(' ', array_map(
                static fn (string $p): string => '<code>' . esc_html($p) . '</code>',
                $placeholders
            ));
            printf('<p class="description">%s %s</p>', esc_html__('Platzhalter:', 'wp-porto-sender'), $codes);
            if ($key === 'admin') {
                printf('<p class="description">%s</p>', esc_html__('%name% und %email% (erste Anfrage) sowie %requests% (alle Anfragen eines Sammel-Zeitfensters, je Zeile eine) werden nur eingesetzt, wenn im Tab „Missbrauchsschutz" die Option „Name und E-Mail mitsenden" aktiviert ist – sonst bleiben sie leer. Solange dieser Text unverändert bleibt, hängt das Plugin bei aktivierter Option automatisch eine Zeile je Anfrage mit Name und E-Mail an.', 'wp-porto-sender'));
            }
            echo '</fieldset>';
        }
    }

    /** The effective template text for the E-Mails tab: stored value, else built-in default. */
    private function emailValue(Settings $s, string $key): string
    {
        $v = $s->emailTemplate($key);
        return $v !== '' ? $v : EmailDefaults::get($key);
    }

    private function renderAbuse(Settings $s, string $opt): void
    {
        // Per-person request limit (dedup mode).
        echo '<p><label>' . esc_html__('Begrenzung pro Person', 'wp-porto-sender') . ' ';
        echo '<select name="' . esc_attr($opt) . '[request_limit_mode]">';
        foreach (['email' => 'E-Mail', 'name' => 'Name', 'name_or_email' => 'Name oder E-Mail', 'none' => 'Keine'] as $val => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($val), selected($s->requestLimitMode(), $val, false), esc_html($label));
        }
        echo '</select></label></p>';

        // Altcha HMAC secret — masked with generate + show/hide (see renderSecretScript()).
        printf('<p><label>%1$s<br><input type="password" id="porto-altcha-secret" autocomplete="new-password" name="%2$s[altcha_hmac_secret]" value="%3$s"></label>',
            esc_html__('Altcha HMAC-Secret', 'wp-porto-sender'), esc_attr($opt), esc_attr($s->altchaHmacSecret()));
        printf(' <button type="button" class="button" id="porto-altcha-generate">%s</button>',
            esc_html__('Generieren', 'wp-porto-sender'));
        printf(' <button type="button" class="button" id="porto-altcha-reveal">%s</button>',
            esc_html__('Anzeigen', 'wp-porto-sender'));
        printf('<br><span class="description">%s</span></p>',
            esc_html__('Erzeugt ein zufälliges 256-Bit-Secret (64 Hex-Zeichen). Danach unten „Änderungen speichern" klicken. Ohne gesetztes Secret ist die CAPTCHA-Prüfung inaktiv.', 'wp-porto-sender'));

        // Rate limiting.
        echo '<fieldset><legend>' . esc_html__('Rate-Limiting (Missbrauchsschutz)', 'wp-porto-sender') . '</legend>';
        printf('<p><label><input type="checkbox" name="%1$s[rate_limit_enabled]" value="1" %2$s> %3$s</label></p>',
            esc_attr($opt), checked($s->rateLimitEnabled(), true, false), esc_html__('Rate-Limiting aktiv', 'wp-porto-sender'));
        printf('<p><label>%2$s<br><input type="number" min="0" name="%1$s[rate_limit_per_ip_day]" value="%3$d"></label></p>',
            esc_attr($opt), esc_html__('Max. Anfragen pro IP/Tag', 'wp-porto-sender'), $s->rateLimitPerIpDay());
        printf('<p><label>%2$s<br><input type="number" min="0" name="%1$s[rate_limit_global_hour]" value="%3$d"></label></p>',
            esc_attr($opt), esc_html__('Max. Anfragen gesamt/Stunde', 'wp-porto-sender'), $s->rateLimitGlobalHour());
        echo '</fieldset>';

        // Admin notifications (sent to the "Alarm-E-Mail" address on the Allgemein tab).
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
    }

    private function renderRetention(Settings $s, string $opt): void
    {
        printf('<p><label>%s<br><input type="number" min="0" name="%s[pii_retention_days]" value="%d"></label>',
            esc_html__('Datenaufbewahrung ausgegebener Portos (Tage)', 'wp-porto-sender'), esc_attr($opt), $s->piiRetentionDays());
        printf('<br><span class="description">%s</span></p>',
            esc_html__('Name und E-Mail ausgegebener Portos werden nach so vielen Tagen anonymisiert (der Datensatz und die Hashes bleiben für die Missbrauchsprüfung erhalten).', 'wp-porto-sender'));

        printf('<p><label>%s<br><input type="number" min="0" name="%s[unconfirmed_retention_days]" value="%d"></label>',
            esc_html__('Aufbewahrung unbestätigter Anfragen (Tage)', 'wp-porto-sender'), esc_attr($opt), $s->unconfirmedRetentionDays());
        printf('<br><span class="description">%s</span></p>',
            esc_html__('Nie bestätigte Anfragen werden so viele Tage aufbewahrt (für die Betrugs-/Missbrauchsprüfung) und danach gelöscht. Unabhängig von der Token-Gültigkeit — abgelaufene Links funktionieren weiterhin nicht.', 'wp-porto-sender'));
    }

    private function renderGeo(Settings $s, string $opt): void
    {
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
    }

    /**
     * Inline generator + show/hide toggle for the HMAC secret field.
     *
     * The generator uses the browser's CSPRNG (Web Crypto getRandomValues) so no
     * secret is transmitted and the plugin stays self-contained; the admin saves via
     * the form. This stays inline (not in admin-settings.js) because it emits
     * translatable labels via wp_json_encode.
     */
    private function renderSecretScript(): void
    {
        $noCryptoMsg = wp_json_encode(
            __('Dein Browser unterstützt keine sichere Zufallserzeugung. Bitte trage das Secret manuell ein.', 'wp-porto-sender')
        );
        $revealLabel = wp_json_encode(__('Anzeigen', 'wp-porto-sender'));
        $hideLabel = wp_json_encode(__('Verbergen', 'wp-porto-sender'));
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
            // Show/hide toggle: flip the masked field between password/text and
            // swap the button label accordingly.
            var revealBtn = document.getElementById('porto-altcha-reveal');
            if (revealBtn) {
                revealBtn.addEventListener('click', function () {
                    var show = field.type === 'password';
                    field.type = show ? 'text' : 'password';
                    revealBtn.textContent = show ? <?php echo $hideLabel; ?> : <?php echo $revealLabel; ?>;
                });
            }
        })();
        </script>
        <?php
    }
}
