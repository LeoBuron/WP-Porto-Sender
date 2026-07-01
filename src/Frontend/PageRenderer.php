<?php
declare(strict_types=1);
namespace PortoSender\Frontend;

use PortoSender\Settings\Settings;

/**
 * Renders the post-submit ("check your e-mail") and post-confirmation (result)
 * views. By default both are drawn inside the active theme via get_header()/
 * get_footer(); if the admin selected an override WP page for either, that page
 * renders normally and the matching notice is injected into its content instead.
 *
 * All URLs that reach these views are GET, so a reload only re-renders the same
 * notice — it never re-POSTs or re-issues a code.
 */
final class PageRenderer
{
    /** The "check your e-mail" notice shown after a successful submit. */
    private const SENT_MESSAGE = 'Bitte bestätige die Anfrage über den Link in deiner E-Mail.';

    public function __construct(private Settings $settings) {}

    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybeRenderBuiltIn']);
        add_filter('the_content', [$this, 'maybeInjectIntoPage']);
    }

    /** Map an issuance status to its visitor message; unknown → invalid_token (allow-list). */
    public function message(string $status): string
    {
        return ConfirmHandler::MESSAGES[$status] ?? ConfirmHandler::MESSAGES['invalid_token'];
    }

    /**
     * template_redirect: render the built-in themed view for `porto_view=sent|result`
     * when no override page is configured for that view. When an override page IS set,
     * we bail so WordPress renders that page (see maybeInjectIntoPage()).
     */
    public function maybeRenderBuiltIn(): void
    {
        $view = isset($_GET['porto_view'])
            ? sanitize_key((string) wp_unslash($_GET['porto_view'])) : '';

        if ($view === 'sent') {
            if (self::resolvePageId($this->settings->pageSent()) > 0) { return; }
            $this->renderThemed(self::SENT_MESSAGE);
        }

        if ($view === 'result') {
            if (self::resolvePageId($this->settings->pageResult()) > 0) { return; }
            $this->renderThemed($this->message($this->requestedStatus()));
        }
    }

    /**
     * the_content: prepend the mapped notice to a chosen override page when the
     * visitor arrives there via the flow (matching query args present). Only fires
     * for the singular main-loop render of the exact selected page, so normal
     * visits (no query args) and unrelated pages are untouched.
     */
    public function maybeInjectIntoPage(string $content): string
    {
        if (!is_singular() || !in_the_loop() || !is_main_query()) { return $content; }
        $current = get_queried_object_id();

        $sentPage = self::resolvePageId($this->settings->pageSent());
        if ($sentPage > 0 && $current === $sentPage
            && isset($_GET['porto_view'])
            && sanitize_key((string) wp_unslash($_GET['porto_view'])) === 'sent') {
            return $this->notice(self::SENT_MESSAGE) . $content;
        }

        $resultPage = self::resolvePageId($this->settings->pageResult());
        if ($resultPage > 0 && $current === $resultPage && isset($_GET['porto_status'])) {
            return $this->notice($this->message($this->requestedStatus())) . $content;
        }

        return $content;
    }

    /**
     * A configured page ID resolved to a usable one, or 0 if unset / missing /
     * trashed / not published (callers then fall back to the built-in view).
     */
    public static function resolvePageId(int $id): int
    {
        return ($id > 0 && get_post_status($id) === 'publish') ? $id : 0;
    }

    private function requestedStatus(): string
    {
        return isset($_GET['porto_status'])
            ? sanitize_key((string) wp_unslash($_GET['porto_status'])) : '';
    }

    private function notice(string $message): string
    {
        return '<div class="porto-notice" role="status"><p>' . esc_html($message) . '</p></div>';
    }

    private function renderThemed(string $message): void
    {
        get_header();
        echo '<main class="porto-page">' . $this->notice($message) . '</main>';
        get_footer();
        exit;
    }
}
