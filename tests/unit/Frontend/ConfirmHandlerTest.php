<?php // tests/unit/Frontend/ConfirmHandlerTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Frontend;
use Brain\Monkey\Functions;
use PortoSender\Tests\unit\WpUnitTestCase;
use PortoSender\Frontend\ConfirmHandler;
use PortoSender\Issuance\IssuanceService;
use PortoSender\Settings\Settings;

final class ConfirmHandlerTest extends WpUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('home_url')->alias(fn($p = '') => 'https://x.test' . $p);
        Functions\when('get_permalink')->alias(fn($id) => 'https://x.test/seite-' . $id);
        Functions\when('get_post_status')->justReturn(false); // no override page by default
        Functions\when('add_query_arg')->alias(function ($key, $value = null, $url = null) {
            if (is_array($key)) { $url = $value; $q = http_build_query($key); }
            else { $q = rawurlencode((string) $key) . '=' . rawurlencode((string) $value); }
            return $url . (str_contains((string) $url, '?') ? '&' : '?') . $q;
        });
    }

    private function handler(array $settings): ConfirmHandler
    {
        // resultUrl() never touches the issuance service, so bypass its constructor.
        $issuance = (new \ReflectionClass(IssuanceService::class))->newInstanceWithoutConstructor();
        return new ConfirmHandler($issuance, new Settings($settings));
    }

    public function test_result_url_uses_built_in_view_when_no_page_selected(): void
    {
        $url = $this->handler(['page_result' => 0])->resultUrl('issued');
        $this->assertStringContainsString('porto_view=result', $url);
        $this->assertStringContainsString('porto_status=issued', $url);
    }

    public function test_result_url_uses_selected_published_page(): void
    {
        Functions\when('get_post_status')->justReturn('publish');
        $url = $this->handler(['page_result' => 12])->resultUrl('already_issued');
        $this->assertStringContainsString('seite-12', $url);
        $this->assertStringContainsString('porto_status=already_issued', $url);
        $this->assertStringNotContainsString('porto_view=result', $url);
    }

    public function test_result_url_falls_back_to_built_in_when_page_trashed(): void
    {
        Functions\when('get_post_status')->justReturn('trash');
        $url = $this->handler(['page_result' => 12])->resultUrl('expired');
        $this->assertStringContainsString('porto_view=result', $url);
        $this->assertStringContainsString('porto_status=expired', $url);
        $this->assertStringNotContainsString('seite-12', $url);
    }
}
