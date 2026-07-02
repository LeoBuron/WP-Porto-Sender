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

    public function test_known_statuses_map_to_their_messages(): void
    {
        $r = $this->renderer();
        $this->assertSame('Dein Porto-Code wurde dir per E-Mail zugeschickt.', $r->message('issued'));
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
        $this->assertStringContainsString('Dein Porto-Code wurde dir per E-Mail zugeschickt.', $out);
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
        $this->assertStringContainsString('bestätige die Anfrage', $out);
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
}
