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
    public function __construct(private Settings $settings) {}

    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybeRenderBuiltIn']);
        add_filter('the_content', [$this, 'maybeInjectIntoPage']);
    }

    /** Map an issuance status to its (settings-editable) visitor message; unknown → invalid_token (allow-list). */
    public function message(string $status): string
    {
        $key = in_array($status, ConfirmHandler::STATUSES, true) ? $status : 'invalid_token';
        return $this->settings->text('text_status_' . $key);
    }

    /** The (settings-editable) "check your e-mail" notice shown after a successful submit. */
    private function sentMessage(): string
    {
        return $this->settings->text('text_page_sent');
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
            $this->renderThemed($this->sentMessage());
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
            return $this->notice($this->sentMessage()) . $content;
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
        // Strip shortcodes from the (admin-editable) notice text so it renders identically
        // on both paths: the injected-into-a-page branch feeds the_content, where a later
        // do_shortcode (priority 11) would otherwise expand a token like [gallery]; the
        // themed view echoes directly and never would. esc_html keeps all output inert.
        return '<div class="porto-notice" role="status"><p>' . esc_html(strip_shortcodes($message)) . '</p></div>';
    }

    private function renderThemed(string $message): void
    {
        echo $this->themedDocument($message);
        exit;
    }

    /**
     * Build the full themed HTML document for a built-in view.
     *
     * Classic themes use get_header()/get_footer(). Block themes (the WordPress default
     * since Twenty Twenty-Two, and what most sites now run) have no header.php/footer.php,
     * so those calls fall back to the ancient theme-compat templates — a page with none of
     * the site's real header, navigation, footer or styling (plus a PHP deprecation notice),
     * which looks empty/broken. For block themes we instead assemble the document from the
     * theme's block header/footer template parts and render the notice inside a constrained
     * group so it picks up the theme's content width. Kept separate from the echo/exit so
     * it stays unit-testable.
     */
    private function themedDocument(string $message): string
    {
        // A plain, self-contained container centred with inline styles — deliberately NO
        // do_blocks()/block markup: a plugin that disables or filters block rendering (the
        // production site runs feature-disabling plugins) could blank a do_blocks() wrapper,
        // which showed as a page with the theme header/footer but no content. block_header_area()
        // /block_footer_area() render the template parts directly and are unaffected.
        $main = '<main class="porto-page" style="max-width:640px;margin:0 auto;padding:clamp(2rem,6vw,4rem) 1.25rem;">'
            . $this->notice($message) . '</main>';
        ob_start();
        if (wp_is_block_theme()) {
            ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class('porto-builtin-page'); ?>>
<?php wp_body_open(); ?>
<?php block_header_area(); ?>
<?php echo $main; ?>
<?php block_footer_area(); ?>
<?php wp_footer(); ?>
</body>
</html>
            <?php
        } else {
            get_header();
            echo $main;
            get_footer();
        }
        return (string) ob_get_clean();
    }
}
