<?php
declare(strict_types=1);
namespace PortoSender\Admin;

use PortoSender\Inventory\CodeStore;
use PortoSender\Settings\Settings;

final class Dashboard
{
    public function __construct(private CodeStore $codes, private Settings $settings) {}

    /** @return array<string,array{available:int,reserved:int,issued:int,expired:int}> */
    public function stockSummary(): array
    {
        $out = [];
        foreach ($this->settings->enabledProducts() as $key) { $out[$key] = $this->codes->countsByStatus($key); }
        return $out;
    }

    /** @return array<object> */
    public function nearExpiry(): array
    {
        return $this->codes->findExpiring(new \DateTimeImmutable('now'), $this->settings->expiryWarningMonths());
    }

    /** @return array<array{code:string,product:string,issued_at:?string}> */
    public function claims(int $limit): array
    {
        return array_map(static fn($r) => [
            'code' => str_repeat('•', max(0, strlen($r->code) - 3)) . substr($r->code, -3),
            'product' => $r->product,
            'issued_at' => $r->issued_at,
        ], $this->codes->recentIssued($limit));
    }

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_submenu_page('porto-sender', __('Übersicht', 'wp-porto-sender'),
                __('Übersicht', 'wp-porto-sender'), 'manage_options', 'porto-sender-dashboard', [$this, 'render']);
        });
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) { return; }
        echo '<div class="wrap"><h1>' . esc_html__('Porto-Sender – Übersicht', 'wp-porto-sender') . '</h1>';
        echo '<table class="widefat"><thead><tr><th>Produkt</th><th>Verfügbar</th><th>Reserviert</th><th>Ausgegeben</th><th>Abgelaufen</th></tr></thead><tbody>';
        foreach ($this->stockSummary() as $key => $c) {
            printf('<tr><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td></tr>',
                esc_html($key), $c['available'], $c['reserved'], $c['issued'], $c['expired']);
        }
        echo '</tbody></table>';
        echo '</div>';
    }
}
