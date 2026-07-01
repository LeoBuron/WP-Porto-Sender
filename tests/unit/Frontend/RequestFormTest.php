<?php // tests/unit/Frontend/RequestFormTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Frontend;
use Brain\Monkey\Functions;
use PortoSender\Tests\unit\WpUnitTestCase;
use PortoSender\Frontend\RequestForm;
use PortoSender\Settings\Settings;
use PortoSender\Postage\ProductCatalog;

final class RequestFormTest extends WpUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('rest_url')->alias(fn($p) => 'https://x.test/wp-json/' . $p);
        Functions\when('sanitize_hex_color')->alias(fn($c) => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $c) ? $c : null);
        // data-sent-url plumbing (added in Task 7).
        Functions\when('home_url')->alias(fn($p = '') => 'https://x.test' . $p);
        Functions\when('get_permalink')->alias(fn($id) => 'https://x.test/seite-' . $id);
        Functions\when('get_post_status')->justReturn(false); // no override page by default
        Functions\when('add_query_arg')->alias(function ($key, $value = null, $url = null) {
            if (is_array($key)) { $url = $value; $q = http_build_query($key); }
            else { $q = rawurlencode((string) $key) . '=' . rawurlencode((string) $value); }
            return $url . (str_contains((string) $url, '?') ? '&' : '?') . $q;
        });
    }

    public function test_renders_enabled_products_consent_and_widget(): void
    {
        $form = new RequestForm(ProductCatalog::default(), new Settings([
            'enabled_products' => ['grossbrief'], 'privacy_policy_url' => 'https://x.test/datenschutz',
        ]));
        $html = $form->render([]);
        $this->assertStringContainsString('Großbrief', $html);
        $this->assertStringNotContainsString('Standardbrief', $html); // not enabled
        $this->assertStringContainsString('altcha-widget', $html);
        $this->assertStringContainsString('challenge=', $html);       // v3 widget attribute
        $this->assertStringNotContainsString('challengeurl=', $html); // v2 attribute removed
        $this->assertStringContainsString('name="porto_product"', $html);
        $this->assertStringContainsString('datenschutz', $html); // privacy link
        $this->assertStringContainsString('type="checkbox"', $html); // consent
    }

    public function test_render_applies_layout_class_style_vars_and_custom_labels(): void
    {
        $form = new RequestForm(ProductCatalog::default(), new Settings([
            'form_layout' => 'card',
            'form_accent_color' => '#ff0000',
            'form_button_bg' => '#00ff00',
            'form_button_text' => '#000000',
            'form_max_width_px' => 640,
            'form_field_gap_px' => 20,
            'text_intro' => 'Bitte fülle das Formular aus.',
            'text_label_name' => 'Vollständiger Name',
            'text_button' => 'Jetzt anfordern',
        ]));
        $html = $form->render([]);

        // Layout preset lands on the form as a class.
        $this->assertStringContainsString('porto-layout-card', $html);
        // Scoped custom properties carry the configured colours and spacing.
        $this->assertStringContainsString('--porto-accent:#ff0000', $html);
        $this->assertStringContainsString('--porto-btn-bg:#00ff00', $html);
        $this->assertStringContainsString('--porto-btn-text:#000000', $html);
        $this->assertStringContainsString('--porto-max-width:640px', $html);
        $this->assertStringContainsString('--porto-gap:20px', $html);
        // Configured text overrides the built-in labels/intro/button.
        $this->assertStringContainsString('Bitte fülle das Formular aus.', $html);
        $this->assertStringContainsString('Vollständiger Name', $html);
        $this->assertStringContainsString('Jetzt anfordern', $html);
    }

    public function test_render_defaults_use_stacked_layout_and_built_in_labels(): void
    {
        $form = new RequestForm(ProductCatalog::default(), new Settings([]));
        $html = $form->render([]);

        $this->assertStringContainsString('porto-layout-stacked', $html);
        // Empty intro is not rendered.
        $this->assertStringNotContainsString('class="porto-intro"', $html);
        // Built-in defaults preserved.
        $this->assertStringContainsString('Porto-Code anfordern', $html);
        $this->assertStringContainsString('--porto-accent:#0b5fff', $html);
        $this->assertStringContainsString('--porto-max-width:520px', $html);
    }

    public function test_malicious_colour_cannot_break_out_of_style(): void
    {
        // A colour that reached the option without Settings::sanitize() (e.g. a tampered
        // import bundle) must be neutralised at the output sink, not executed as markup.
        $form = new RequestForm(ProductCatalog::default(), new Settings([
            'form_accent_color' => '}</style><script>alert(1)</script><style>{',
        ]));
        $html = $form->render([]);

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('--porto-accent:#0b5fff', $html); // fell back to preset
    }

    public function test_render_zero_max_width_means_full_width(): void
    {
        $form = new RequestForm(ProductCatalog::default(), new Settings(['form_max_width_px' => 0]));
        $html = $form->render([]);

        $this->assertStringContainsString('--porto-max-width:none', $html);
    }

    public function test_sent_url_defaults_to_built_in_home_view(): void
    {
        $form = new RequestForm(ProductCatalog::default(), new Settings([]));
        $html = $form->render([]);

        $this->assertStringContainsString('data-sent-url="https://x.test/?porto_view=sent"', $html);
    }

    public function test_sent_url_uses_selected_published_page(): void
    {
        Functions\when('get_post_status')->justReturn('publish');
        $form = new RequestForm(ProductCatalog::default(), new Settings(['page_sent' => 9]));
        $html = $form->render([]);

        $this->assertStringContainsString('data-sent-url="https://x.test/seite-9?porto_view=sent"', $html);
    }
}
