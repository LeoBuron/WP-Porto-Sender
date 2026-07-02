<?php // tests/unit/Frontend/BlockRegistrarTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Frontend;
use Brain\Monkey\Functions;
use PortoSender\Tests\unit\WpUnitTestCase;
use PortoSender\Frontend\BlockRegistrar;
use PortoSender\Frontend\RequestForm;
use PortoSender\Settings\Settings;
use PortoSender\Postage\ProductCatalog;

final class BlockRegistrarTest extends WpUnitTestCase
{
    public function test_registers_block_with_render_callback(): void
    {
        $captured = null;
        Functions\expect('register_block_type')->once()->andReturnUsing(function ($path, $args) use (&$captured) {
            $captured = $args; return true;
        });
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('rest_url')->alias(fn($p) => 'https://x.test/' . $p);
        Functions\when('sanitize_hex_color')->alias(fn($c) => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $c) ? $c : null);
        // RequestForm::render() now builds a data-sent-url (Task 7).
        Functions\when('home_url')->alias(fn($p = '') => 'https://x.test' . $p);
        Functions\when('get_post_status')->justReturn(false);
        Functions\when('get_option')->justReturn([]); // no auto-provisioned pages
        Functions\when('add_query_arg')->alias(fn($k, $v = null, $u = null) => $u . '?' . $k . '=' . $v);
        $form = new RequestForm(ProductCatalog::default(), new Settings(['enabled_products' => ['grossbrief']]));
        (new BlockRegistrar($form))->register();
        $this->assertIsCallable($captured['render_callback']);
        $this->assertStringContainsString('porto-request-form', ($captured['render_callback'])([], ''));
    }
}
