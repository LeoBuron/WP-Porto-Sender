<?php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Admin;

use Brain\Monkey\Functions;
use PortoSender\Tests\unit\WpUnitTestCase;
use PortoSender\Admin\SettingsPage;
use PortoSender\Settings\Settings;

final class SettingsPageTest extends WpUnitTestCase
{
    public function test_registers_setting_with_sanitizer(): void
    {
        Functions\expect('register_setting')
            ->once()
            ->with(
                'porto_sender',
                Settings::OPTION,
                \Mockery::on(fn($a) => is_array($a) && isset($a['sanitize_callback']))
            );
        Functions\when('add_menu_page')->justReturn('toplevel_page_porto');
        (new SettingsPage())->registerSetting();
    }

    /** Stub every WP function the tabbed render() touches (identity escapers). */
    private function stubRender(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_option')->justReturn([]);
        Functions\when('settings_fields')->justReturn(null);
        Functions\when('submit_button')->justReturn(null);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_textarea')->returnArg(1);
        Functions\when('checked')->justReturn('');
        Functions\when('selected')->justReturn('');
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('wp_dropdown_pages')->justReturn('<select name="porto_sender_settings[page_sent]"></select>');
    }

    public function test_hmac_secret_field_is_masked_with_reveal_toggle(): void
    {
        $this->stubRender();

        ob_start();
        (new SettingsPage())->render();
        $html = (string) ob_get_clean();

        // Secret is masked and browser autofill suppressed.
        $this->assertStringContainsString(
            'type="password" id="porto-altcha-secret" autocomplete="new-password" name="porto_sender_settings[altcha_hmac_secret]"',
            $html
        );
        // Both the generate and the reveal buttons are present.
        $this->assertStringContainsString('id="porto-altcha-generate"', $html);
        $this->assertStringContainsString('id="porto-altcha-reveal"', $html);
        // The toggle script flips the field type and swaps the label.
        $this->assertStringContainsString("field.type = show ? 'text' : 'password'", $html);
    }

    public function test_render_outputs_all_tab_panels(): void
    {
        $this->stubRender();

        ob_start();
        (new SettingsPage())->render();
        $html = (string) ob_get_clean();

        // Tab navigation + one panel per section, all inside the single form.
        $this->assertStringContainsString('nav-tab-wrapper', $html);
        foreach (['general', 'form', 'pages', 'emails', 'abuse', 'retention', 'geo'] as $slug) {
            $this->assertStringContainsString('data-porto-tab="' . $slug . '"', $html);
            $this->assertStringContainsString('data-tab="' . $slug . '"', $html);
        }
        // Non-technical appearance UX: colour-picker fields + one-click scheme presets.
        $this->assertStringContainsString('class="porto-color-field"', $html);
        $this->assertStringContainsString('porto-scheme', $html);
        // Seiten panel renders the page dropdowns.
        $this->assertStringContainsString('porto_sender_settings[page_sent]', $html);
        // E-Mails panel renders a template field with a placeholder hint.
        $this->assertStringContainsString('porto_sender_settings[email_confirm_subject]', $html);
        $this->assertStringContainsString('<code>%confirm_url%</code>', $html);
    }

    public function test_email_fields_are_prefilled_with_builtin_defaults(): void
    {
        $this->stubRender();

        ob_start();
        (new SettingsPage())->render();
        $html = (string) ob_get_clean();

        // Nothing stored (get_option => []) → the boxes show the built-in copy
        // instead of rendering empty (esc_attr/esc_textarea are identity stubs).
        $this->assertStringContainsString('value="Bitte bestätige deine Porto-Anfrage"', $html);
        $this->assertStringContainsString('#PORTO %code%', $html);
    }

    public function test_email_fields_show_stored_custom_template(): void
    {
        $this->stubRender();
        Functions\when('get_option')->justReturn(['email_confirm_subject' => 'Mein eigener Betreff']);

        ob_start();
        (new SettingsPage())->render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('value="Mein eigener Betreff"', $html);
        $this->assertStringNotContainsString('value="Bitte bestätige deine Porto-Anfrage"', $html);
    }

    public function test_pages_panel_renders_editable_default_page_texts(): void
    {
        $this->stubRender();

        ob_start();
        (new SettingsPage())->render();
        $html = (string) ob_get_clean();

        // The sent notice + all six result-status texts are editable and prefilled.
        $this->assertStringContainsString('porto_sender_settings[text_page_sent]', $html);
        $this->assertStringContainsString('value="' . Settings::TEXT_DEFAULTS['text_page_sent'] . '"', $html);
        foreach (['issued', 'already_issued', 'expired', 'out_of_stock', 'email_failed', 'invalid_token'] as $status) {
            $this->assertStringContainsString('porto_sender_settings[text_status_' . $status . ']', $html);
        }
        $this->assertStringContainsString('value="' . Settings::TEXT_DEFAULTS['text_status_issued'] . '"', $html);
    }

    public function test_enqueues_color_picker_only_on_settings_page(): void
    {
        Functions\when('add_menu_page')->justReturn('toplevel_page_porto-sender');
        Functions\when('plugins_url')->justReturn('http://example.test/wp-content/plugins/wp-porto-sender/assets/');

        $page = new SettingsPage();
        $page->addMenu(); // captures the hook suffix

        // On an unrelated admin screen nothing is enqueued (early return).
        $page->enqueueAssets('edit.php');

        // On our settings screen the colour picker + admin assets load.
        Functions\expect('wp_enqueue_style')->once()->with('wp-color-picker');
        Functions\expect('wp_enqueue_style')->once()
            ->with('porto-admin-settings', \Mockery::type('string'), [], \Mockery::type('string'));
        Functions\expect('wp_enqueue_script')->once()
            ->with('porto-admin-settings', \Mockery::type('string'), ['wp-color-picker'], \Mockery::type('string'), true);

        $page->enqueueAssets('toplevel_page_porto-sender');
    }
}
