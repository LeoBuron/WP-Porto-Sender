<?php
declare(strict_types=1);
namespace PortoSender\Frontend;

use PortoSender\Issuance\IssuanceService;
use PortoSender\Settings\Settings;

final class ConfirmHandler
{
    /**
     * Allow-list of valid `porto_status` values (PageRenderer consumes it for the
     * themed result view and the override-page injection). The visitor-facing text
     * per status lives in Settings::TEXT_DEFAULTS ('text_status_*') and is editable
     * on the Seiten settings tab.
     */
    public const STATUSES = ['issued', 'already_issued', 'expired', 'out_of_stock', 'email_failed', 'invalid_token'];

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
