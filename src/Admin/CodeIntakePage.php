<?php
declare(strict_types=1);
namespace PortoSender\Admin;

use PortoSender\Inventory\CodeStore;
use PortoSender\Postage\ProductCatalog;
use PortoSender\Portability\CodesCsvImporter;

final class CodeIntakePage
{
    public function __construct(private CodeStore $codes, private ProductCatalog $catalog) {}

    /** @return array<int,string> */
    public static function parseCodes(string $raw): array
    {
        $parts = preg_split('/[\r\n,]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $code = trim($part);
            if ($code !== '' && !in_array($code, $out, true)) { $out[] = $code; }
        }
        return $out;
    }

    public function handleSubmit(array $post): int
    {
        $product = (string) ($post['product'] ?? '');
        if ($this->catalog->get($product) === null) { return 0; }
        $valueCents = (int) ($post['value_cents'] ?? $this->catalog->get($product)->valueCents);
        $purchase = \DateTimeImmutable::createFromFormat('Y-m-d', (string) ($post['purchase_date'] ?? ''))
            ?: new \DateTimeImmutable('now');
        return $this->codes->addBatch($product, $valueCents, $purchase, self::parseCodes((string) ($post['codes'] ?? '')));
    }

    /**
     * Import codes from a CSV file (columns: product,code[,value_cents][,purchase_date]).
     *
     * @return array{inserted:int,skipped:array<int,array{row:int,reason:string}>}
     * @throws \RuntimeException if the CSV lacks required columns or is oversized
     */
    public function importCsvFile(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Could not read the uploaded CSV.');
        }
        return (new CodesCsvImporter($this->codes, $this->catalog))->import($contents);
    }

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_submenu_page('porto-sender', __('Codes hinzufügen', 'wp-porto-sender'),
                __('Codes hinzufügen', 'wp-porto-sender'), 'manage_options', 'porto-sender-intake', [$this, 'render']);
        });
        add_action('admin_post_porto_intake', function (): void {
            check_admin_referer('porto_intake');
            if (!current_user_can('manage_options')) { wp_die('forbidden'); }
            $n = $this->handleSubmit(wp_unslash($_POST));
            wp_safe_redirect(add_query_arg('porto_added', $n, admin_url('admin.php?page=porto-sender-intake')));
            exit;
        });
        add_action('admin_post_porto_intake_csv', function (): void {
            check_admin_referer('porto_intake_csv');
            if (!current_user_can('manage_options')) { wp_die('forbidden'); }
            $file = $_FILES['codes_csv'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || ($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > 5 * 1024 * 1024
                || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
                wp_die(esc_html__('Ungültiger CSV-Upload.', 'wp-porto-sender'), '', ['response' => 400]);
            }
            try {
                $r = $this->importCsvFile((string) $file['tmp_name']);
                $args = ['porto_csv_in' => $r['inserted'], 'porto_csv_skip' => count($r['skipped'])];
            } catch (\Throwable $e) {
                $args = ['porto_csv_err' => 1];
            }
            wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=porto-sender-intake')));
            exit;
        });
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) { return; }
        echo '<div class="wrap"><h1>' . esc_html__('Codes hinzufügen', 'wp-porto-sender') . '</h1>';
        if (isset($_GET['porto_added'])) {
            printf('<div class="notice notice-success"><p>%s</p></div>',
                esc_html(sprintf(__('%d Codes hinzugefügt.', 'wp-porto-sender'), (int) $_GET['porto_added'])));
        }
        if (isset($_GET['porto_csv_in'])) {
            printf('<div class="notice notice-success"><p>%s</p></div>',
                esc_html(sprintf(__('CSV-Import: %1$d hinzugefügt, %2$d übersprungen.', 'wp-porto-sender'),
                    (int) $_GET['porto_csv_in'], (int) ($_GET['porto_csv_skip'] ?? 0))));
        }
        if (isset($_GET['porto_csv_err'])) {
            printf('<div class="notice notice-error"><p>%s</p></div>',
                esc_html__('CSV-Import fehlgeschlagen (Format/Spalten prüfen).', 'wp-porto-sender'));
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('porto_intake');
        echo '<input type="hidden" name="action" value="porto_intake">';
        echo '<p><select name="product">';
        foreach ($this->catalog->all() as $p) {
            printf('<option value="%s">%s (%d ct)</option>', esc_attr($p->key), esc_html($p->label), $p->valueCents);
        }
        echo '</select></p>';
        echo '<p><label>' . esc_html__('Kaufdatum', 'wp-porto-sender') . ' <input type="date" name="purchase_date" required></label></p>';
        echo '<p><label>' . esc_html__('Bezahlter Portowert (ct)', 'wp-porto-sender') . ' <input type="number" name="value_cents"></label></p>';
        echo '<p><textarea name="codes" rows="10" cols="40" placeholder="ein Code pro Zeile"></textarea></p>';
        submit_button(__('Hinzufügen', 'wp-porto-sender'));
        echo '</form>';

        echo '<hr><h2>' . esc_html__('CSV-Import', 'wp-porto-sender') . '</h2>';
        echo '<p>' . esc_html__('Spalten: product,code[,value_cents][,purchase_date] — Kopfzeile erforderlich.', 'wp-porto-sender') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
        wp_nonce_field('porto_intake_csv');
        echo '<input type="hidden" name="action" value="porto_intake_csv">';
        echo '<p><input type="file" name="codes_csv" accept=".csv" required></p>';
        submit_button(__('CSV importieren', 'wp-porto-sender'));
        echo '</form></div>';
    }
}
