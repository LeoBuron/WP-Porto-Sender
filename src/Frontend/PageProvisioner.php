<?php
declare(strict_types=1);
namespace PortoSender\Frontend;

use PortoSender\Settings\Settings;

/**
 * Creates and owns the two real WordPress pages that back the visitor-facing
 * "sent" and "result" views, so the DEFAULT flow renders through the normal
 * theme pipeline (the_content) instead of the hand-rolled themed document —
 * the render path that misbehaves under output-rewriting caching/optimisation
 * plugins on production. The admin can still override either view with their
 * own page on the Seiten tab; those overrides win and suppress provisioning.
 *
 * Storage is deliberately a SEPARATE, site-local option (never the settings
 * option): the settings option is exported/imported and reset, so putting the
 * page IDs there would carry a foreign site's post IDs into an import and let
 * uninstall delete an unrelated page. The per-page meta marker is the durable
 * ownership record used to re-adopt after option loss and to delete safely.
 */
final class PageProvisioner
{
    /** Site-local map of provisioned page IDs: ['sent' => int, 'result' => int]. Autoloaded. */
    public const OPTION = 'porto_sender_pages';
    /** Post-meta marker stamping a page as plugin-owned; value is the view ('sent'|'result'). */
    public const META_KEY = '_porto_sender_page';
    /** Short lock so two concurrent first-loads cannot both insert a page. */
    private const LOCK = 'porto_sender_provisioning';
    private const VIEWS = ['sent', 'result'];

    public function __construct(private Settings $settings) {}

    /**
     * Ensure each non-overridden view has a live owned page. Idempotent and cheap:
     * the steady state does two get_post_status() checks and returns without writing.
     * Safe to call on every admin load (and from activation). Page creation only ever
     * happens on the first load(s) after (re)install, serialised by a short transient.
     */
    public function ensure(): void
    {
        $missing = [];
        foreach (self::VIEWS as $view) {
            if ($this->hasOverride($view)) { continue; }   // admin's own page wins → no auto page
            if (self::autoPageId($view) > 0) { continue; }  // a live owned page already exists
            $missing[] = $view;
        }
        if ($missing === []) { return; }

        if (get_transient(self::LOCK)) { return; }
        set_transient(self::LOCK, 1, 30);
        try {
            $ids = self::ids();
            $changed = false;
            foreach ($missing as $view) {
                if (self::autoPageId($view) > 0) { continue; } // re-check under the lock
                $id = $this->adopt($view) ?: $this->create($view);
                if ($id > 0) { $ids[$view] = $id; $changed = true; }
            }
            if ($changed) { update_option(self::OPTION, $ids, true); }
        } finally {
            delete_transient(self::LOCK);
        }
    }

    /** Raw stored IDs (no publish check) — cheap, autoloaded; used by the SEO filters. */
    public static function ids(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) { $stored = []; }
        return ['sent' => (int) ($stored['sent'] ?? 0), 'result' => (int) ($stored['result'] ?? 0)];
    }

    /** The owned page ID for a view, resolved to 0 unless it exists and is published. */
    public static function autoPageId(string $view): int
    {
        return PageRenderer::resolvePageId(self::ids()[$view] ?? 0);
    }

    /**
     * Force-delete every owned page (any status, incl. accumulated orphans) via the
     * meta marker, then drop the option. Keyed on ownership so it can never delete a
     * page the plugin did not create. Wired only into the terminal purge paths
     * (uninstall + admin "delete all"), never into deactivation.
     */
    public static function purge(): void
    {
        $ids = get_posts([
            'post_type'        => 'page',
            'post_status'      => ['publish', 'draft', 'pending', 'private', 'trash'],
            'meta_key'         => self::META_KEY,
            'fields'           => 'ids',
            'numberposts'      => -1,
            'no_found_rows'    => true,
            'suppress_filters' => true,
        ]);
        foreach ($ids as $id) {
            wp_delete_post((int) $id, true);
        }
        delete_option(self::OPTION);
    }

    /**
     * Draft the owned pages when the plugin is deactivated. The noindex / menu / sitemap
     * exclusion are runtime filters that stop firing once inactive; drafting hides the
     * pages natively (a draft is 404 to visitors and absent from search/sitemap/menus)
     * without destroying them. ensure() re-publishes them on the next activation/admin load.
     */
    public static function unpublish(): void
    {
        $ids = get_posts([
            'post_type'        => 'page',
            'post_status'      => 'publish',
            'meta_key'         => self::META_KEY,
            'fields'           => 'ids',
            'numberposts'      => -1,
            'no_found_rows'    => true,
            'suppress_filters' => true,
        ]);
        foreach ($ids as $id) {
            wp_update_post(['ID' => (int) $id, 'post_status' => 'draft']);
        }
    }

    private function hasOverride(string $view): bool
    {
        $override = $view === 'result' ? $this->settings->pageResult() : $this->settings->pageSent();
        return PageRenderer::resolvePageId($override) > 0;
    }

    /**
     * Re-adopt an existing owned page after the option was lost/reset or the page was drafted
     * (on deactivation), re-publishing it if needed — so we never create a duplicate.
     */
    private function adopt(string $view): int
    {
        $found = get_posts([
            'post_type'        => 'page',
            'post_status'      => ['publish', 'draft', 'pending', 'private'],
            'meta_key'         => self::META_KEY,
            'meta_value'       => $view,
            'fields'           => 'ids',
            'numberposts'      => 1,
            'no_found_rows'    => true,
            'suppress_filters' => true,
        ]);
        if (!$found) { return 0; }
        $id = (int) $found[0];
        if (get_post_status($id) !== 'publish') {
            wp_update_post(['ID' => $id, 'post_status' => 'publish']);
        }
        return $id;
    }

    /** Insert one owned page. Returns 0 on failure (caller falls back to the themed doc). */
    private function create(string $view): int
    {
        $spec = self::spec($view);
        $id = wp_insert_post([
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'post_author'    => 0, // deterministic, independent of whoever triggers the first load
            'post_title'     => $spec['title'],
            'post_name'      => $spec['slug'],
            'post_content'   => $spec['content'],
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
            'meta_input'     => [self::META_KEY => $view],
        ], true);

        return (is_wp_error($id) || (int) $id === 0) ? 0 : (int) $id;
    }

    /**
     * Title/slug/body for an auto page. The body is short, neutral and NON-empty on
     * purpose: a block theme's core/post-content can skip empty content before the
     * the_content filter runs, which would drop the injected notice. The visitor-facing
     * message is the injected notice (text_page_sent / text_status_*); this body only
     * shows below it, and stands alone on a bare visit without the flow's query args.
     */
    private static function spec(string $view): array
    {
        if ($view === 'result') {
            return [
                'title'   => __('Bestätigung', 'wp-porto-sender'),
                'slug'    => 'porto-bestaetigung',
                'content' => '<!-- wp:paragraph --><p>' . __('Vielen Dank für deine Bestätigung.', 'wp-porto-sender') . '</p><!-- /wp:paragraph -->',
            ];
        }
        return [
            'title'   => __('Anfrage erhalten', 'wp-porto-sender'),
            'slug'    => 'porto-anfrage-erhalten',
            'content' => '<!-- wp:paragraph --><p>' . __('Bitte prüfe dein E-Mail-Postfach.', 'wp-porto-sender') . '</p><!-- /wp:paragraph -->',
        ];
    }
}
