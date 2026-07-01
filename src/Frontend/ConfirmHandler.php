<?php
declare(strict_types=1);
namespace PortoSender\Frontend;

use PortoSender\Issuance\IssuanceService;
use PortoSender\Settings\Settings;

final class ConfirmHandler
{
    /**
     * Canonical status → visitor message map. Also the allow-list of valid
     * `porto_status` values (PageRenderer consumes this table for the themed
     * result view and the override-page injection).
     */
    public const MESSAGES = [
        'issued' => 'Dein Porto-Code wurde dir per E-Mail zugeschickt.',
        'already_issued' => 'Du hast deinen Porto-Code bereits erhalten.',
        'expired' => 'Dieser Bestätigungslink ist abgelaufen. Bitte stelle eine neue Anfrage.',
        'out_of_stock' => 'Aktuell sind keine Codes verfügbar. Bitte versuche es später erneut.',
        'email_failed' => 'Der Versand ist fehlgeschlagen. Bitte versuche es später erneut.',
        'invalid_token' => 'Dieser Bestätigungslink ist ungültig.',
    ];

    public function __construct(private IssuanceService $issuance, private Settings $settings) {}

    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybeHandle']);
    }

    public function maybeHandle(): void
    {
        if (!isset($_GET['porto_confirm'])) { return; }
        $status = $this->process(sanitize_text_field(wp_unslash($_GET['porto_confirm'])));
        // Redirect (302) to a GET result view so a browser reload never re-POSTs /
        // re-triggers issuance; the token is single-use server-side either way.
        wp_safe_redirect($this->resultUrl($status));
        exit;
    }

    public function process(string $token): string
    {
        return $this->issuance->confirm($token)['status'];
    }

    /**
     * The destination that carries the issuance $status to the visitor:
     * a chosen (published) result page + `?porto_status=`, else the plugin's
     * built-in `?porto_view=result&porto_status=` view on the home URL.
     */
    public function resultUrl(string $status): string
    {
        $pageId = PageRenderer::resolvePageId($this->settings->pageResult());
        if ($pageId > 0) {
            return add_query_arg('porto_status', $status, get_permalink($pageId));
        }
        return add_query_arg(['porto_view' => 'result', 'porto_status' => $status], home_url('/'));
    }
}
