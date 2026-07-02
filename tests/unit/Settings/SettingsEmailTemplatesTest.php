<?php // tests/unit/Settings/SettingsEmailTemplatesTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Settings;
use PortoSender\Tests\unit\WpUnitTestCase;
use PortoSender\Mail\EmailDefaults;
use PortoSender\Settings\Settings;

/**
 * The E-Mails tab prefills empty template fields with the built-in defaults, so
 * sanitize() must translate "still the default" back to '' (= follow the plugin
 * default) and keep genuinely custom text.
 */
final class SettingsEmailTemplatesTest extends WpUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Brain\Monkey\Functions\when('sanitize_textarea_field')->returnArg(1);
        \Brain\Monkey\Functions\when('sanitize_text_field')->returnArg(1);
        \Brain\Monkey\Functions\when('sanitize_email')->returnArg(1);
        \Brain\Monkey\Functions\when('esc_url_raw')->returnArg(1);
        \Brain\Monkey\Functions\when('absint')->alias(static fn($v) => abs((int) $v));
        \Brain\Monkey\Functions\when('sanitize_hex_color')->alias(static fn($c) => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $c) ? $c : null);
        \Brain\Monkey\Functions\when('get_option')->justReturn([]);
    }

    public function test_submitting_the_unchanged_default_stores_empty_string(): void
    {
        $out = Settings::sanitize([
            'email_confirm_subject' => EmailDefaults::get('email_confirm_subject'),
            'email_confirm_body' => EmailDefaults::get('email_confirm_body'),
        ]);
        $this->assertSame('', $out['email_confirm_subject']);
        $this->assertSame('', $out['email_confirm_body']);
    }

    public function test_default_with_crlf_line_endings_still_normalizes_to_empty(): void
    {
        // Browsers post textarea content with CRLF; that must still count as "default".
        $crlf = str_replace("\n", "\r\n", EmailDefaults::get('email_delivery_body'));
        $out = Settings::sanitize(['email_delivery_body' => $crlf]);
        $this->assertSame('', $out['email_delivery_body']);
    }

    public function test_custom_text_is_kept_with_lf_line_endings(): void
    {
        $out = Settings::sanitize(['email_delivery_body' => "Zeile 1\r\nZeile 2"]);
        $this->assertSame("Zeile 1\nZeile 2", $out['email_delivery_body']);
    }

    public function test_cleared_field_resets_to_default_semantics(): void
    {
        \Brain\Monkey\Functions\when('get_option')->justReturn(['email_confirm_subject' => 'Mein Betreff']);
        $out = Settings::sanitize(['email_confirm_subject' => '']);
        $this->assertSame('', $out['email_confirm_subject']);
    }

    public function test_absent_key_keeps_stored_custom_template(): void
    {
        \Brain\Monkey\Functions\when('get_option')->justReturn(['email_confirm_subject' => 'Mein Betreff']);
        $out = Settings::sanitize([]);
        $this->assertSame('Mein Betreff', $out['email_confirm_subject']);
    }

    public function test_page_text_defaults_and_sanitize(): void
    {
        $s = new Settings();
        $this->assertSame(Settings::TEXT_DEFAULTS['text_page_sent'], $s->text('text_page_sent'));
        $this->assertSame(Settings::TEXT_DEFAULTS['text_status_issued'], $s->text('text_status_issued'));
        $this->assertNotSame('', $s->text('text_page_sent'));

        // Custom value round-trips through sanitize; empty falls back via text().
        $out = Settings::sanitize(['text_status_issued' => 'Juhu, dein Code kommt per E-Mail!']);
        $this->assertSame('Juhu, dein Code kommt per E-Mail!', $out['text_status_issued']);
        $this->assertSame('Juhu!', (new Settings(['text_status_issued' => 'Juhu!']))->text('text_status_issued'));
        $this->assertSame(Settings::TEXT_DEFAULTS['text_status_issued'],
            (new Settings(['text_status_issued' => '']))->text('text_status_issued'));
    }

    public function test_submitting_unchanged_page_text_default_stores_empty(): void
    {
        // The Seiten tab prefills these with their defaults; a plain save (even from an
        // unrelated tab) must NOT freeze the shipped copy into the option — the value is
        // normalised back to '' so future default/translation changes still reach the install.
        $out = Settings::sanitize([
            'text_page_sent' => Settings::TEXT_DEFAULTS['text_page_sent'],
            'text_status_issued' => Settings::TEXT_DEFAULTS['text_status_issued'],
            'text_label_name' => Settings::TEXT_DEFAULTS['text_label_name'],
        ]);
        $this->assertSame('', $out['text_page_sent']);
        $this->assertSame('', $out['text_status_issued']);
        $this->assertSame('', $out['text_label_name']);
    }

    public function test_absent_text_keys_do_not_freeze_defaults_for_existing_installs(): void
    {
        // An old install whose option predates these keys: a save that omits them (get_option
        // has no value, defaults() supplies the string) must resolve to '' not the frozen copy.
        \Brain\Monkey\Functions\when('get_option')->justReturn(['owner_address' => 'x']);
        $out = Settings::sanitize([]);
        $this->assertSame('', $out['text_status_expired']);
        $this->assertSame('', $out['text_page_sent']);
    }
}
