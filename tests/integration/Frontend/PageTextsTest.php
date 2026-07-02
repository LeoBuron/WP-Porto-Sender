<?php // tests/integration/Frontend/PageTextsTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Frontend;

use PortoSender\Tests\integration\PortoTestCase;
use PortoSender\Frontend\PageRenderer;
use PortoSender\Mail\Mailer;
use PortoSender\Mail\EmailDefaults;
use PortoSender\Postage\PostageProduct;
use PortoSender\Settings\Settings;

/**
 * End-to-end coverage (real WordPress sanitizers, unlike the Brain-Monkey unit tests)
 * for the editable page texts + e-mail default fallback:
 *  - a custom status text saved through Settings::sanitize() renders on the result view;
 *  - a submitted value equal to the built-in default is normalised back to '' so the
 *    install keeps following the plugin default (guards the "frozen defaults" regression);
 *  - the Mailer still falls back to EmailDefaults when no template is stored.
 */
final class PageTextsTest extends PortoTestCase
{
    private function saveSettings(array $input): Settings
    {
        update_option(Settings::OPTION, Settings::sanitize($input));
        return Settings::fromOption();
    }

    public function test_custom_status_text_is_resolved_for_the_result_view(): void
    {
        $settings = $this->saveSettings(['text_status_issued' => 'Juhu, dein Code ist unterwegs!']);
        $renderer = new PageRenderer($settings);
        $this->assertSame('Juhu, dein Code ist unterwegs!', $renderer->message('issued'));
        // Untouched statuses still fall back to their built-in default.
        $this->assertSame('Dieser Bestätigungslink ist ungültig.', $renderer->message('invalid_token'));
        // Unknown status is allow-listed to the invalid-token text.
        $this->assertSame($renderer->message('invalid_token'), $renderer->message('bogus'));
    }

    public function test_custom_sent_text_is_injected_above_override_page_content(): void
    {
        $pageId = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Fast geschafft',
            'post_content' => '<p>Seiteninhalt</p>',
        ]);
        $settings = $this->saveSettings([
            'page_sent' => (string) $pageId,
            'text_page_sent' => 'Schau in dein Postfach!',
        ]);

        $this->go_to(add_query_arg('porto_view', 'sent', get_permalink($pageId)));
        $this->assertTrue(is_page($pageId));

        // Drive the main loop so in_the_loop()/is_main_query() hold, then apply the filter
        // exactly as the_content would.
        $out = '';
        while (have_posts()) {
            the_post();
            $out = (new PageRenderer($settings))->maybeInjectIntoPage(get_the_content());
        }
        $this->assertStringContainsString('Schau in dein Postfach!', $out);
        $this->assertStringContainsString('Seiteninhalt', $out);
        $this->assertLessThan(strpos($out, 'Seiteninhalt'), strpos($out, 'Schau in dein Postfach!'));
    }

    public function test_shortcode_in_status_text_is_not_expanded_on_override_page(): void
    {
        // A registered shortcode typed into a status text must NOT execute when the notice
        // is prepended to the_content (do_shortcode runs at priority 11); the admin's own
        // page shortcodes are unaffected.
        add_shortcode('porto_boom', static fn () => 'EXPANDED_SHORTCODE');
        $pageId = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_content' => '<p>Body [porto_boom]</p>',
        ]);
        $settings = $this->saveSettings([
            'page_result' => (string) $pageId,
            'text_status_issued' => 'Fertig! [porto_boom]',
        ]);

        $this->go_to(add_query_arg('porto_status', 'issued', get_permalink($pageId)));
        $rendered = '';
        while (have_posts()) {
            the_post();
            // Inject the notice, then run do_shortcode as WordPress does on the_content
            // (priority 11, after our priority-10 injection filter).
            $injected = (new PageRenderer($settings))->maybeInjectIntoPage(get_the_content());
            $rendered = do_shortcode($injected);
        }
        // The notice's shortcode is stripped (not expanded); the literal token is gone too.
        $this->assertStringContainsString('Fertig!', $rendered);
        $this->assertStringNotContainsString('Fertig! [porto_boom]', $rendered);
        // The admin's own in-content shortcode still expands.
        $this->assertStringContainsString('EXPANDED_SHORTCODE', $rendered);
        remove_shortcode('porto_boom');
    }

    public function test_submitting_the_unchanged_default_is_stored_as_empty(): void
    {
        // Real sanitize_text_field runs here — the normalisation must still recognise the
        // default so a plain "save" never freezes the shipped copy into the option.
        $this->saveSettings(['text_status_issued' => 'Dein Porto-Code wurde dir per E-Mail zugeschickt.']);
        $stored = get_option(Settings::OPTION);
        $this->assertSame('', $stored['text_status_issued'], 'unchanged default must normalise to empty');
        // …and the effective text is still the default via the accessor.
        $this->assertSame('Dein Porto-Code wurde dir per E-Mail zugeschickt.',
            Settings::fromOption()->text('text_status_issued'));
    }

    public function test_email_body_default_round_trips_to_empty_under_real_sanitizers(): void
    {
        // The delivery body is multi-line; browsers post CRLF. Saving the prefilled
        // default (converted to CRLF) must normalise back to '' so the Mailer default applies.
        $crlf = str_replace("\n", "\r\n", EmailDefaults::get('email_delivery_body'));
        $this->saveSettings(['email_delivery_body' => $crlf]);
        $stored = get_option(Settings::OPTION);
        $this->assertSame('', $stored['email_delivery_body']);
    }

    public function test_mailer_falls_back_to_email_defaults_when_no_template_stored(): void
    {
        $captured = [];
        add_filter('pre_wp_mail', function ($null, $atts) use (&$captured) {
            $captured = $atts;
            return true;
        }, 10, 2);

        $settings = $this->saveSettings(['owner_address' => "Leo Buron\n12345 Musterstadt"]);
        $mailer = new Mailer($settings);
        $product = new PostageProduct('grossbrief', 'Großbrief', 'A4 flach, bis 500 g');

        $this->assertTrue($mailer->sendDelivery('v@example.de', 'Vera', 'AB12CD34', $product));
        $this->assertSame('Dein Porto-Code', $captured['subject']);
        $this->assertStringContainsString('#PORTO AB12CD34', $captured['message']);
        $this->assertStringContainsString('12345 Musterstadt', $captured['message']);
    }
}
