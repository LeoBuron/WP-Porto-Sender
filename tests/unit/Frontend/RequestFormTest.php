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
}
