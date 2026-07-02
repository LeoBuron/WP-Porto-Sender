<?php // tests/unit/Frontend/PageRendererTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Frontend;
use Brain\Monkey\Functions;
use PortoSender\Tests\unit\WpUnitTestCase;
use PortoSender\Frontend\PageRenderer;
use PortoSender\Settings\Settings;

final class PageRendererTest extends WpUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('esc_html')->returnArg(1);
        Functions\when('strip_shortcodes')->returnArg(1);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('sanitize_key')->alias(
            fn ($v) => strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v))
        );
    }

    protected function tearDown(): void
    {
        unset($_GET['porto_view'], $_GET['porto_status']);
        parent::tearDown();
    }

    private function renderer(array $settings = []): PageRenderer
    {
        return new PageRenderer(new Settings($settings));
    }

    /** Invoke the private document builder (renderThemed() itself calls exit). */
    private function buildDocument(PageRenderer $r, string $message): string
    {
        // PHP 8.1+ reflection accesses private methods without setAccessible().
        return (string) (new \ReflectionMethod($r, 'themedDocument'))->invoke($r, $message);
    }

    public function test_builtin_view_uses_block_template_parts_for_block_themes(): void
    {
        // Block themes have no header.php/footer.php — get_header()/get_footer() must NOT
        // be used (they fall back to the bare theme-compat page). We assemble the document
        // from the block header/footer areas instead.
        Functions\when('wp_is_block_theme')->justReturn(true);
        Functions\when('language_attributes')->justReturn(null);
        Functions\when('bloginfo')->justReturn(null);
        Functions\when('body_class')->justReturn(null);
        Functions\expect('wp_head')->atLeast()->once();
        Functions\expect('wp_body_open')->atLeast()->once();
        Functions\expect('block_header_area')->once();
        Functions\expect('block_footer_area')->once();
        Functions\expect('wp_footer')->atLeast()->once();
        Functions\expect('get_header')->never();
        Functions\expect('get_footer')->never();

        $html = $this->buildDocument($this->renderer(), 'Bitte bestätigen');
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('porto-notice', $html);
        $this->assertStringContainsString('Bitte bestätigen', $html);
    }

    public function test_builtin_view_uses_get_header_footer_for_classic_themes(): void
    {
        Functions\when('wp_is_block_theme')->justReturn(false);
        Functions\expect('get_header')->once();
        Functions\expect('get_footer')->once();
        Functions\expect('block_header_area')->never();
        Functions\expect('block_footer_area')->never();

        $html = $this->buildDocument($this->renderer(), 'Klassisch');
        $this->assertStringContainsString('porto-notice', $html);
        $this->assertStringContainsString('Klassisch', $html);
    }

    public function test_known_statuses_map_to_their_messages(): void
    {
        $r = $this->renderer();
        $this->assertSame(Settings::TEXT_DEFAULTS['text_status_issued'], $r->message('issued'));
        $this->assertSame('Du hast deinen Porto-Code bereits erhalten.', $r->message('already_issued'));
        $this->assertStringContainsString('abgelaufen', $r->message('expired'));
        $this->assertStringContainsString('keine Codes', $r->message('out_of_stock'));
        $this->assertStringContainsString('fehlgeschlagen', $r->message('email_failed'));
        $this->assertStringContainsString('ungültig', $r->message('invalid_token'));
    }

    public function test_status_message_is_settings_editable(): void
    {
        $r = $this->renderer(['text_status_issued' => 'Juhu, dein Code ist unterwegs!']);
        $this->assertSame('Juhu, dein Code ist unterwegs!', $r->message('issued'));
        // Other statuses keep their defaults.
        $this->assertSame('Du hast deinen Porto-Code bereits erhalten.', $r->message('already_issued'));
    }

    public function test_custom_sent_text_is_injected_into_override_page(): void
    {
        Functions\when('is_singular')->justReturn(true);
        Functions\when('in_the_loop')->justReturn(true);
        Functions\when('is_main_query')->justReturn(true);
        Functions\when('get_queried_object_id')->justReturn(7);
        Functions\when('get_post_status')->justReturn('publish');
        $_GET['porto_view'] = 'sent';

        $out = $this->renderer(['page_sent' => 7, 'text_page_sent' => 'Schau in dein Postfach!'])
            ->maybeInjectIntoPage('<p>Body</p>');
        $this->assertStringContainsString('Schau in dein Postfach!', $out);
    }

    public function test_unknown_status_falls_back_to_invalid_token_message(): void
    {
        $r = $this->renderer();
        $fallback = $r->message('invalid_token');
        $this->assertSame($fallback, $r->message('bogus'));
        $this->assertSame($fallback, $r->message(''));
        $this->assertSame($fallback, $r->message('<script>'));
    }

    public function test_injects_result_notice_before_page_content(): void
    {
        Functions\when('is_singular')->justReturn(true);
        Functions\when('in_the_loop')->justReturn(true);
        Functions\when('is_main_query')->justReturn(true);
        Functions\when('get_queried_object_id')->justReturn(42);
        Functions\when('get_post_status')->justReturn('publish');
        $_GET['porto_status'] = 'issued';

        $out = $this->renderer(['page_result' => 42])->maybeInjectIntoPage('<p>Seiteninhalt</p>');
        $this->assertStringContainsString(Settings::TEXT_DEFAULTS['text_status_issued'], $out);
        $this->assertStringContainsString('<p>Seiteninhalt</p>', $out);
        // Notice is prepended (comes before the original body).
        $this->assertLessThan(strpos($out, 'Seiteninhalt'), strpos($out, 'porto-notice'));
    }

    public function test_injects_sent_notice_on_sent_page(): void
    {
        Functions\when('is_singular')->justReturn(true);
        Functions\when('in_the_loop')->justReturn(true);
        Functions\when('is_main_query')->justReturn(true);
        Functions\when('get_queried_object_id')->justReturn(7);
        Functions\when('get_post_status')->justReturn('publish');
        $_GET['porto_view'] = 'sent';

        $out = $this->renderer(['page_sent' => 7])->maybeInjectIntoPage('<p>Body</p>');
        $this->assertStringContainsString(Settings::TEXT_DEFAULTS['text_page_sent'], $out);
    }

    public function test_unknown_injected_status_is_allowlisted_to_invalid_token(): void
    {
        Functions\when('is_singular')->justReturn(true);
        Functions\when('in_the_loop')->justReturn(true);
        Functions\when('is_main_query')->justReturn(true);
        Functions\when('get_queried_object_id')->justReturn(42);
        Functions\when('get_post_status')->justReturn('publish');
        $_GET['porto_status'] = 'haxxor';

        $out = $this->renderer(['page_result' => 42])->maybeInjectIntoPage('<p>Body</p>');
        $this->assertStringContainsString('ungültig', $out); // invalid_token message
        $this->assertStringNotContainsString('haxxor', $out);
    }

    public function test_does_not_inject_on_unrelated_page(): void
    {
        Functions\when('is_singular')->justReturn(true);
        Functions\when('in_the_loop')->justReturn(true);
        Functions\when('is_main_query')->justReturn(true);
        Functions\when('get_queried_object_id')->justReturn(99); // not the result page
        Functions\when('get_post_status')->justReturn('publish');
        $_GET['porto_status'] = 'issued';

        $out = $this->renderer(['page_result' => 42])->maybeInjectIntoPage('<p>Body</p>');
        $this->assertSame('<p>Body</p>', $out);
    }

    public function test_does_not_inject_outside_the_main_loop(): void
    {
        Functions\when('is_singular')->justReturn(true);
        Functions\when('in_the_loop')->justReturn(false); // e.g. a widget applying the_content
        Functions\when('is_main_query')->justReturn(true);
        $_GET['porto_status'] = 'issued';

        $out = $this->renderer(['page_result' => 42])->maybeInjectIntoPage('<p>Body</p>');
        $this->assertSame('<p>Body</p>', $out);
    }

    public function test_effective_page_id_prefers_override_then_auto_then_zero(): void
    {
        Functions\when('get_post_status')->justReturn('publish');
        Functions\when('get_option')->justReturn(['sent' => 21, 'result' => 22]);
        // Admin override wins (auto ids are never consulted).
        $this->assertSame(7, PageRenderer::effectivePageId('sent', new Settings(['page_sent' => 7])));
        // No override → the auto-provisioned page.
        $this->assertSame(21, PageRenderer::effectivePageId('sent', new Settings(['page_sent' => 0])));
        $this->assertSame(22, PageRenderer::effectivePageId('result', new Settings(['page_result' => 0])));
    }

    public function test_effective_page_id_is_zero_when_nothing_backs_the_view(): void
    {
        Functions\when('get_post_status')->justReturn(false);
        Functions\when('get_option')->justReturn([]);
        $this->assertSame(0, PageRenderer::effectivePageId('result', new Settings(['page_result' => 0])));
    }

    public function test_injects_sent_notice_on_auto_provisioned_page(): void
    {
        Functions\when('is_singular')->justReturn(true);
        Functions\when('in_the_loop')->justReturn(true);
        Functions\when('is_main_query')->justReturn(true);
        Functions\when('get_queried_object_id')->justReturn(21);
        Functions\when('get_post_status')->justReturn('publish');
        Functions\when('get_option')->justReturn(['sent' => 21, 'result' => 22]);
        $_GET['porto_view'] = 'sent';

        $out = $this->renderer(['page_sent' => 0])->maybeInjectIntoPage('<p>Body</p>');
        $this->assertStringContainsString(Settings::TEXT_DEFAULTS['text_page_sent'], $out);
        $this->assertStringContainsString('<p>Body</p>', $out);
    }

    public function test_builtin_redirects_legacy_home_link_onto_the_real_page(): void
    {
        $_GET['porto_view'] = 'sent';
        Functions\when('get_post_status')->justReturn('publish');            // auto page published
        Functions\when('get_option')->justReturn(['sent' => 21, 'result' => 22]);
        Functions\when('get_queried_object_id')->justReturn(2);              // on the home/front page
        Functions\when('is_front_page')->justReturn(true);
        Functions\when('is_home')->justReturn(false);
        Functions\when('get_permalink')->alias(fn ($id) => 'https://x.test/seite-' . $id);
        Functions\when('add_query_arg')->alias(fn ($k, $v = null, $u = null) => $u . (str_contains((string) $u, '?') ? '&' : '?') . $k . '=' . $v);
        $captured = null;
        Functions\when('wp_safe_redirect')->alias(function ($url) use (&$captured) {
            $captured = $url;
            throw new \RuntimeException('redirect'); // halt before the exit
        });

        try {
            $this->renderer(['page_sent' => 0])->maybeRenderBuiltIn();
            $this->fail('expected a redirect');
        } catch (\RuntimeException $e) {
            $this->assertSame('redirect', $e->getMessage());
        }
        $this->assertSame('https://x.test/seite-21?porto_view=sent', $captured);
    }

    public function test_builtin_does_not_redirect_when_already_on_the_real_page(): void
    {
        $_GET['porto_view'] = 'result';
        $_GET['porto_status'] = 'issued';
        Functions\when('get_post_status')->justReturn('publish');
        Functions\when('get_option')->justReturn(['sent' => 21, 'result' => 22]);
        Functions\when('get_queried_object_id')->justReturn(22); // already ON the auto result page
        Functions\expect('wp_safe_redirect')->never();

        // Returns quietly (WordPress renders the page; injection adds the notice).
        $this->renderer(['page_result' => 0])->maybeRenderBuiltIn();
        $this->assertTrue(true);
    }

    public function test_builtin_does_not_redirect_on_an_unrelated_page_with_a_stray_view_arg(): void
    {
        $_GET['porto_view'] = 'sent';
        Functions\when('get_post_status')->justReturn('publish');
        Functions\when('get_option')->justReturn(['sent' => 21, 'result' => 22]);
        Functions\when('get_queried_object_id')->justReturn(99); // some other singular page
        Functions\when('is_front_page')->justReturn(false);
        Functions\when('is_home')->justReturn(false);
        Functions\expect('wp_safe_redirect')->never();

        // pageId=21, queried=99 (≠21, ≠0), not front/home → no redirect, no themed doc.
        $this->renderer(['page_sent' => 0])->maybeRenderBuiltIn();
        $this->assertTrue(true);
    }

    public function test_auto_pages_are_noindexed_and_excluded_from_menus_and_sitemap(): void
    {
        Functions\when('get_option')->justReturn(['sent' => 21, 'result' => 22]);
        Functions\when('get_queried_object_id')->justReturn(21); // on an auto page
        $r = $this->renderer();

        $robots = $r->noindexAutoPages(['index' => true, 'follow' => true, 'max-image-preview' => 'large']);
        $this->assertTrue($robots['noindex'] ?? false);
        $this->assertTrue($robots['nofollow'] ?? false);
        $this->assertArrayNotHasKey('index', $robots);

        $this->assertSame([21, 22], $r->excludeAutoPagesFromMenus([]));
        $this->assertSame(['post__not_in' => [21, 22]], $r->excludeAutoPagesFromSitemap([], 'page'));
        $this->assertSame([], $r->excludeAutoPagesFromSitemap([], 'post')); // non-page untouched
    }

    public function test_noindex_filter_leaves_normal_pages_untouched(): void
    {
        Functions\when('get_option')->justReturn(['sent' => 21, 'result' => 22]);
        Functions\when('get_queried_object_id')->justReturn(99); // a normal page
        $robots = $this->renderer()->noindexAutoPages(['index' => true]);
        $this->assertArrayNotHasKey('noindex', $robots);
        $this->assertSame(['index' => true], $robots);
    }
}
