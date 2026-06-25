<?php
declare(strict_types=1);

namespace PortoSender\Admin;

use PortoSender\Inventory\CodeStore;
use PortoSender\Requests\RequestStore;
use PortoSender\Settings\Settings;
use PortoSender\Persistence\Schema;
use PortoSender\Persistence\SchemaVersion;
use PortoSender\Portability\ExportService;
use PortoSender\Portability\ImportService;
use PortoSender\Portability\BundleCrypto;
use PortoSender\Lifecycle\DataEraser;

/**
 * "Export & Import" admin page (Werkzeuge): streams per-table CSV and the
 * lossless bundle, and restores/merges an uploaded bundle.
 *
 * Exports are streamed straight to the browser (headers + echo + exit) and
 * never written into the web root, so the secret-bearing bundle never sits as a
 * web-readable file. Every action is capability- + nonce-gated; an unencrypted
 * bundle requires an explicit confirmation. The business logic (exportPayload /
 * importResult) is split from the wp_die/exit wrappers so it stays testable.
 */
final class ToolsPage
{
    private const MAX_UPLOAD_BYTES = 10 * 1024 * 1024; // 10 MB import cap
    private const NONCE_EXPORT = 'porto_export';
    private const NONCE_IMPORT = 'porto_import';
    private const NONCE_RESET = 'porto_reset';
    private const NONCE_WIPE = 'porto_wipe';

    public function __construct(private CodeStore $codes, private RequestStore $requests)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_submenu_page(
                'porto-sender',
                __('Export & Import', 'wp-porto-sender'),
                __('Export & Import', 'wp-porto-sender'),
                'manage_options',
                'porto-sender-tools',
                [$this, 'render']
            );
        });
        add_action('admin_post_porto_export', [$this, 'handleExport']);
        add_action('admin_post_porto_import', [$this, 'handleImport']);
        add_action('admin_post_porto_reset', [$this, 'handleReset']);
        add_action('admin_post_porto_wipe', [$this, 'handleWipe']);
    }

    // ---------- testable builders ----------

    /**
     * @return array{filename:string,contentType:string,body:string}
     */
    public function exportPayload(string $format, ?string $passphrase): array
    {
        $export = $this->exporter();
        $date = gmdate('Ymd-His');

        return match ($format) {
            'requests_csv' => [
                'filename' => "porto-requests-$date.csv",
                'contentType' => 'text/csv; charset=utf-8',
                'body' => $export->requestsCsv(),
            ],
            'bundle' => $this->bundlePayload($export, $passphrase, $date),
            default => [
                'filename' => "porto-codes-$date.csv",
                'contentType' => 'text/csv; charset=utf-8',
                'body' => $export->codesCsv(),
            ],
        };
    }

    /**
     * @return array{mode:string,codes:int,requests:int,warnings:array<int,string>}
     */
    public function importResult(string $contents, ?string $passphrase, string $mode): array
    {
        return $this->importer()->importBundle($contents, $passphrase, $mode);
    }

    /**
     * Reset the configurable settings to defaults but PRESERVE hash_salt — wiping
     * the salt would invalidate every existing email/name/token/IP hash.
     */
    public function resetSettings(): void
    {
        $salt = Settings::fromOption()->hashSalt();
        $defaults = Settings::defaults();
        $defaults['hash_salt'] = $salt !== '' ? $salt : wp_generate_password(64, false, false);
        update_option(Settings::OPTION, $defaults);
    }

    /**
     * Delete ALL plugin data and re-initialise an empty install: purge everything,
     * recreate empty tables, and re-seed defaults with a NEW salt (a clean slate).
     */
    public function deleteAllData(): void
    {
        global $wpdb;
        DataEraser::purgeAll($wpdb);
        Schema::install($wpdb);
        $defaults = Settings::defaults();
        $defaults['hash_salt'] = wp_generate_password(64, false, false);
        update_option(Settings::OPTION, $defaults);
        (new SchemaVersion())->set(Schema::CURRENT_VERSION);
    }

    // ---------- admin-post handlers (thin, guarded) ----------

    public function handleExport(): void
    {
        $this->assertAllowed(self::NONCE_EXPORT);

        $format = isset($_POST['format']) ? sanitize_key((string) wp_unslash($_POST['format'])) : 'codes_csv';
        $passphrase = isset($_POST['passphrase']) ? (string) wp_unslash($_POST['passphrase']) : '';

        // An unencrypted bundle carries the secret salt + PII -> require explicit confirmation.
        if ($format === 'bundle' && $passphrase === '' && empty($_POST['confirm_plain'])) {
            wp_die(
                esc_html__('An unencrypted bundle contains the secret salt and personal data. Set a passphrase or tick the confirmation box.', 'wp-porto-sender'),
                '',
                ['response' => 400]
            );
        }

        $payload = $this->exportPayload($format, $passphrase !== '' ? $passphrase : null);
        $this->stream($payload['filename'], $payload['contentType'], $payload['body']);
    }

    public function handleImport(): void
    {
        $this->assertAllowed(self::NONCE_IMPORT);

        $mode = (isset($_POST['mode']) && (string) $_POST['mode'] === ImportService::MODE_MERGE)
            ? ImportService::MODE_MERGE
            : ImportService::MODE_FULL;
        $passphrase = (isset($_POST['passphrase']) && $_POST['passphrase'] !== '')
            ? (string) wp_unslash($_POST['passphrase'])
            : null;

        $contents = $this->readUpload('import_file');

        try {
            $result = $this->importResult($contents, $passphrase, $mode);
            $msg = sprintf(
                /* translators: 1: codes count, 2: requests count */
                __('Import complete: %1$d codes, %2$d requests.', 'wp-porto-sender'),
                $result['codes'],
                $result['requests']
            );
            if (!empty($result['warnings'])) {
                $msg .= ' ' . implode(' ', $result['warnings']);
            }
            $type = 'success';
        } catch (\Throwable $e) {
            $msg = __('Import failed.', 'wp-porto-sender') . ' ' . $e->getMessage();
            $type = 'error';
        }

        $this->redirectWithNotice($type, $msg);
    }

    public function handleReset(): void
    {
        $this->assertAllowed(self::NONCE_RESET);
        if (empty($_POST['confirm'])) {
            $this->redirectWithNotice('error', __('Bitte das Bestätigungsfeld ankreuzen.', 'wp-porto-sender'));
        }
        $this->resetSettings();
        $this->redirectWithNotice('success', __('Einstellungen auf Standard zurückgesetzt (Salt erhalten).', 'wp-porto-sender'));
    }

    public function handleWipe(): void
    {
        $this->assertAllowed(self::NONCE_WIPE);
        if (empty($_POST['confirm'])) {
            $this->redirectWithNotice('error', __('Bitte das Bestätigungsfeld ankreuzen.', 'wp-porto-sender'));
        }
        $this->deleteAllData();
        $this->redirectWithNotice('success', __('Alle Plugin-Daten gelöscht und neu initialisiert.', 'wp-porto-sender'));
    }

    // ---------- helpers ----------

    private function redirectWithNotice(string $type, string $msg): void
    {
        set_transient('porto_tools_notice_' . get_current_user_id(), ['type' => $type, 'msg' => $msg], 60);
        wp_safe_redirect(admin_url('admin.php?page=porto-sender-tools'));
        exit;
    }

    private function assertAllowed(string $nonceAction): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Forbidden', 'wp-porto-sender'), '', ['response' => 403]);
        }
        check_admin_referer($nonceAction);
    }

    private function readUpload(string $field): string
    {
        $file = $_FILES[$field] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            wp_die(esc_html__('No file uploaded or upload error.', 'wp-porto-sender'), '', ['response' => 400]);
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            wp_die(esc_html__('File is empty or too large.', 'wp-porto-sender'), '', ['response' => 400]);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            wp_die(esc_html__('Invalid upload.', 'wp-porto-sender'), '', ['response' => 400]);
        }
        $contents = file_get_contents($tmp);
        if ($contents === false) {
            wp_die(esc_html__('Could not read the uploaded file.', 'wp-porto-sender'), '', ['response' => 400]);
        }
        return $contents;
    }

    /**
     * @return array{filename:string,contentType:string,body:string}
     */
    private function bundlePayload(ExportService $export, ?string $passphrase, string $date): array
    {
        $encrypted = $passphrase !== null && $passphrase !== '' && BundleCrypto::available();
        return [
            'filename' => $encrypted ? "porto-bundle-$date.json.enc" : "porto-bundle-$date.json",
            'contentType' => $encrypted ? 'application/octet-stream' : 'application/json; charset=utf-8',
            'body' => $export->bundle($passphrase),
        ];
    }

    private function exporter(): ExportService
    {
        $version = (new SchemaVersion())->current() ?: Schema::CURRENT_VERSION;
        return new ExportService(
            $this->codes,
            $this->requests,
            Settings::fromOption(),
            $version,
            (string) get_site_url(),
            (string) current_time('mysql')
        );
    }

    private function importer(): ImportService
    {
        return new ImportService($this->codes, $this->requests);
    }

    private function stream(string $filename, string $contentType, string $body): void
    {
        nocache_headers();
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($body));
        echo $body; // raw export body — a file download, never rendered as HTML
        exit;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $action = esc_url(admin_url('admin-post.php'));

        echo '<div class="wrap"><h1>' . esc_html__('Export & Import', 'wp-porto-sender') . '</h1>';

        $noticeKey = 'porto_tools_notice_' . get_current_user_id();
        $notice = get_transient($noticeKey);
        if (is_array($notice)) {
            delete_transient($noticeKey);
            printf(
                '<div class="notice notice-%s"><p>%s</p></div>',
                esc_attr($notice['type'] === 'error' ? 'error' : 'success'),
                esc_html((string) $notice['msg'])
            );
        }

        // --- Export ---
        echo '<h2>' . esc_html__('Export', 'wp-porto-sender') . '</h2>';
        echo '<form method="post" action="' . $action . '">';
        wp_nonce_field(self::NONCE_EXPORT);
        echo '<input type="hidden" name="action" value="porto_export">';
        echo '<p><label><input type="radio" name="format" value="codes_csv" checked> '
            . esc_html__('Codes (CSV)', 'wp-porto-sender') . '</label><br>';
        echo '<label><input type="radio" name="format" value="requests_csv"> '
            . esc_html__('Anfragen inkl. personenbezogener Daten (CSV)', 'wp-porto-sender') . '</label><br>';
        echo '<label><input type="radio" name="format" value="bundle"> '
            . esc_html__('Vollständiges Backup-Bundle (enthält den geheimen Salt)', 'wp-porto-sender') . '</label></p>';
        echo '<p><label>' . esc_html__('Passphrase für Bundle-Verschlüsselung (optional)', 'wp-porto-sender')
            . ' <input type="password" name="passphrase" autocomplete="new-password"></label></p>';
        echo '<p><label><input type="checkbox" name="confirm_plain" value="1"> '
            . esc_html__('Mir ist bewusst, dass ein unverschlüsseltes Bundle den geheimen Salt und personenbezogene Daten enthält.', 'wp-porto-sender')
            . '</label></p>';
        submit_button(__('Exportieren', 'wp-porto-sender'));
        echo '</form>';

        // --- Import ---
        echo '<hr><h2>' . esc_html__('Import', 'wp-porto-sender') . '</h2>';
        echo '<form method="post" action="' . $action . '" enctype="multipart/form-data">';
        wp_nonce_field(self::NONCE_IMPORT);
        echo '<input type="hidden" name="action" value="porto_import">';
        echo '<p><input type="file" name="import_file" accept=".json,.enc" required></p>';
        echo '<p><label><input type="radio" name="mode" value="full_restore" checked> '
            . esc_html__('Vollständige Wiederherstellung (überschreibt Daten, Einstellungen und Salt)', 'wp-porto-sender') . '</label><br>';
        echo '<label><input type="radio" name="mode" value="data_merge"> '
            . esc_html__('Nur Daten zusammenführen (Salt-Hinweis beachten)', 'wp-porto-sender') . '</label></p>';
        echo '<p><label>' . esc_html__('Passphrase (falls verschlüsselt)', 'wp-porto-sender')
            . ' <input type="password" name="passphrase" autocomplete="new-password"></label></p>';
        submit_button(__('Importieren', 'wp-porto-sender'));
        echo '</form>';

        // --- Data lifecycle (reset / delete-all) ---
        echo '<hr><h2>' . esc_html__('Daten-Lebenszyklus', 'wp-porto-sender') . '</h2>';
        echo '<p>' . esc_html__('Tipp: Vor dem Entfernen oben ein Backup-Bundle exportieren.', 'wp-porto-sender') . '</p>';

        echo '<form method="post" action="' . $action . '">';
        wp_nonce_field(self::NONCE_RESET);
        echo '<input type="hidden" name="action" value="porto_reset">';
        echo '<p><label><input type="checkbox" name="confirm" value="1"> '
            . esc_html__('Einstellungen auf Standard zurücksetzen (Codes/Anfragen und Salt bleiben erhalten)', 'wp-porto-sender') . '</label></p>';
        submit_button(__('Einstellungen zurücksetzen', 'wp-porto-sender'), 'secondary');
        echo '</form>';

        echo '<form method="post" action="' . $action . '" onsubmit="return confirm(\''
            . esc_js(__('Wirklich ALLE Plugin-Daten unwiderruflich löschen?', 'wp-porto-sender')) . '\');">';
        wp_nonce_field(self::NONCE_WIPE);
        echo '<input type="hidden" name="action" value="porto_wipe">';
        echo '<p><label><input type="checkbox" name="confirm" value="1"> '
            . esc_html__('Ja, ALLE Daten (Codes, Anfragen, Einstellungen) unwiderruflich löschen', 'wp-porto-sender') . '</label></p>';
        submit_button(__('Alle Daten löschen', 'wp-porto-sender'), 'delete');
        echo '</form></div>';
    }
}
