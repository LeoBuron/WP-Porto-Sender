<?php // tests/integration/Frontend/FlowPagesTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Frontend;

use PortoSender\Tests\integration\PortoTestCase;
use PortoSender\Frontend\PageProvisioner;
use PortoSender\Frontend\PageRenderer;
use PortoSender\Settings\Settings;

/**
 * Real-WordPress coverage for the auto-provisioned "sent"/"result" pages that back the
 * default confirmation views. The Brain-Monkey unit tests mock every WP function, so they
 * could never catch the original "blank built-in page" bug; these drive the actual
 * the_content filter, real provisioning and real meta-guarded cleanup — the regression
 * guard that was missing.
 */
final class FlowPagesTest extends PortoTestCase
{
    private function provision(array $settings = []): void
    {
        (new PageProvisioner(new Settings($settings)))->ensure();
    }

    /** True if PageRenderer::maybeInjectIntoPage is actually hooked onto the_content. */
    private function theContentInjectionIsWired(): bool
    {
        global $wp_filter;
        if (empty($wp_filter['the_content'])) { return false; }
        foreach ($wp_filter['the_content']->callbacks as $callbacks) {
            foreach ($callbacks as $cb) {
                $fn = $cb['function'] ?? null;
                if (is_array($fn) && ($fn[0] ?? null) instanceof PageRenderer && ($fn[1] ?? '') === 'maybeInjectIntoPage') {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return array<int,int> */
    private function ownedPageIds(): array
    {
        return get_posts([
            'post_type'   => 'page',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'trash'],
            'meta_key'    => PageProvisioner::META_KEY,
            'fields'      => 'ids',
            'numberposts' => -1,
            'no_found_rows' => true,
        ]);
    }

    public function test_ensure_creates_two_published_owned_pages_and_is_idempotent(): void
    {
        $this->provision();
        $ids = PageProvisioner::ids();

        $this->assertGreaterThan(0, $ids['sent']);
        $this->assertGreaterThan(0, $ids['result']);
        $this->assertSame('publish', get_post_status($ids['sent']));
        $this->assertSame('publish', get_post_status($ids['result']));
        $this->assertSame('sent', get_post_meta($ids['sent'], PageProvisioner::META_KEY, true));
        $this->assertSame('result', get_post_meta($ids['result'], PageProvisioner::META_KEY, true));
        $this->assertCount(2, $this->ownedPageIds());
        // Content must be NON-empty: a block theme's core/post-content skips empty content
        // before the_content runs, which would drop the injected notice (the 0.5.2/0.5.3 bug).
        $this->assertNotSame('', trim((string) get_post($ids['sent'])->post_content), 'sent page needs non-empty content');
        $this->assertNotSame('', trim((string) get_post($ids['result'])->post_content), 'result page needs non-empty content');

        // Second run is a no-op: no duplicates, ids unchanged.
        $this->provision();
        $this->assertCount(2, $this->ownedPageIds());
        $this->assertSame($ids, PageProvisioner::ids());
    }

    public function test_default_flow_injects_notice_through_the_content_filter_on_the_auto_page(): void
    {
        $this->provision();
        $sentId = PageProvisioner::ids()['sent'];

        // The injection is only useful if it is actually hooked onto the_content — guard the
        // wiring so removing add_filter() in register() fails a test (it silently didn't before).
        $this->assertTrue($this->theContentInjectionIsWired(), 'maybeInjectIntoPage must be hooked on the_content');

        $this->go_to(add_query_arg('porto_view', 'sent', get_permalink($sentId)));
        $this->assertTrue(is_page($sentId));

        // Drive the REAL the_content filter chain (as a block theme's core/post-content does),
        // not maybeInjectIntoPage() by hand — this is the render-path guard that was missing and
        // is why the blank-page bug shipped three times under a mocked test suite.
        $rendered = '';
        while (have_posts()) {
            the_post();
            $rendered = apply_filters('the_content', get_the_content());
        }
        $this->assertStringContainsString(Settings::TEXT_DEFAULTS['text_page_sent'], $rendered);
        $this->assertStringContainsString('porto-notice', $rendered);
    }

    public function test_admin_override_suppresses_provisioning_of_that_view(): void
    {
        $overrideId = (int) self::factory()->post->create([
            'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<p>x</p>',
        ]);
        $this->provision(['page_sent' => $overrideId]); // 'result' has no override

        // No 'sent' auto page was created; the effective 'sent' page is the admin's override.
        $this->assertSame(0, PageProvisioner::ids()['sent']);
        $this->assertSame($overrideId, PageRenderer::effectivePageId('sent', new Settings(['page_sent' => $overrideId])));
        // The un-overridden 'result' view still gets its auto page.
        $this->assertGreaterThan(0, PageProvisioner::ids()['result']);
    }

    public function test_self_heals_after_an_auto_page_is_trashed(): void
    {
        $this->provision();
        $sentId = PageProvisioner::ids()['sent'];

        wp_trash_post($sentId);
        $this->assertSame(0, PageProvisioner::autoPageId('sent')); // no longer a live owned page

        $this->provision();
        $newSent = PageProvisioner::ids()['sent'];
        $this->assertGreaterThan(0, $newSent);
        $this->assertNotSame($sentId, $newSent);
        $this->assertSame('publish', get_post_status($newSent));
    }

    public function test_deactivation_drafts_the_auto_pages_and_ensure_republishes_them(): void
    {
        $this->provision();
        $ids = PageProvisioner::ids();

        PageProvisioner::unpublish(); // runs on Plugin::deactivate()
        $this->assertSame('draft', get_post_status($ids['sent']));
        $this->assertSame('draft', get_post_status($ids['result']));
        $this->assertSame(0, PageProvisioner::autoPageId('sent')); // drafted → not a live view

        // ensure() re-adopts and re-publishes the SAME pages (no duplicates).
        $this->provision();
        $this->assertSame($ids, PageProvisioner::ids());
        $this->assertSame('publish', get_post_status($ids['sent']));
        $this->assertSame('publish', get_post_status($ids['result']));
        $this->assertCount(2, $this->ownedPageIds());
    }

    public function test_purge_deletes_only_owned_pages_and_drops_the_option(): void
    {
        $this->provision();
        $owned = PageProvisioner::ids();
        $foreign = (int) self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);

        // Even if the option is made to point at a foreign page, ownership meta guards deletion.
        update_option(PageProvisioner::OPTION, ['sent' => $foreign, 'result' => $owned['result']]);

        PageProvisioner::purge();

        $this->assertNull(get_post($owned['sent']), 'owned sent page deleted');
        $this->assertNull(get_post($owned['result']), 'owned result page deleted');
        $this->assertInstanceOf(\WP_Post::class, get_post($foreign), 'foreign page must survive');
        $this->assertFalse(get_option(PageProvisioner::OPTION, false), 'option dropped');
    }
}
