<?php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Settings;

use Brain\Monkey\Functions;
use PortoSender\Tests\unit\WpUnitTestCase;
use PortoSender\Settings\Settings;

final class SettingsAppearanceTest extends WpUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('sanitize_textarea_field')->returnArg(1);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('sanitize_email')->returnArg(1);
        Functions\when('esc_url_raw')->returnArg(1);
        Functions\when('absint')->alias(static fn($v) => abs((int) $v));
        Functions\when('sanitize_hex_color')->alias(
            static fn($c) => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $c) ? $c : null
        );
        Functions\when('get_option')->justReturn([]);
    }

    public function test_appearance_and_page_keys_sanitize(): void
    {
        $out = Settings::sanitize([
            'form_layout' => 'card',
            'form_accent_color' => '#ff0000',
            'form_button_bg' => 'not-a-color', // invalid -> default kept
            'form_max_width_px' => '640',
            'page_result' => '17',
        ]);

        $this->assertSame('card', $out['form_layout']);
        $this->assertSame('#ff0000', $out['form_accent_color']);
        $this->assertSame('#0b5fff', $out['form_button_bg']); // invalid hex -> default
        $this->assertSame(640, $out['form_max_width_px']);
        $this->assertSame(17, $out['page_result']);
    }

    public function test_invalid_layout_falls_back_to_stacked(): void
    {
        $out = Settings::sanitize(['form_layout' => 'bogus']);
        $this->assertSame('stacked', $out['form_layout']);
    }

    public function test_email_templates_and_text_round_trip(): void
    {
        $out = Settings::sanitize([
            'email_confirm_subject' => 'Bitte bestätigen',
            'email_confirm_body' => 'Hallo %name%: %confirm_url%',
            'text_label_name' => 'Vorname',
        ]);
        $this->assertSame('Bitte bestätigen', $out['email_confirm_subject']);
        $this->assertSame('Hallo %name%: %confirm_url%', $out['email_confirm_body']);
        $this->assertSame('Vorname', $out['text_label_name']);
    }

    public function test_text_accessor_falls_back_to_default(): void
    {
        $s = new Settings(); // no stored values
        $this->assertSame('Name', $s->text('text_label_name'));
        $this->assertSame('Porto-Code anfordern', $s->text('text_button'));
        $s2 = new Settings(['text_label_name' => 'Vorname']);
        $this->assertSame('Vorname', $s2->text('text_label_name'));
    }
}
