<?php // tests/integration/Admin/CodeIntakeHandlerTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Admin;
use PortoSender\Tests\integration\PortoTestCase;
use PortoSender\Admin\CodeIntakePage;
use PortoSender\Inventory\CodeRepository;
use PortoSender\Postage\ProductCatalog;

final class CodeIntakeHandlerTest extends PortoTestCase
{
    public function test_handle_submit_adds_codes(): void
    {
        global $wpdb;
        $repo = new CodeRepository($wpdb);
        $page = new CodeIntakePage($repo, ProductCatalog::default());
        $added = $page->handleSubmit([
            'product' => 'grossbrief',
            'purchase_date' => '2026-06-01', 'codes' => "ONE\nTWO",
        ]);
        $this->assertSame(2, $added);
        $this->assertSame(2, $repo->availableCount('grossbrief', new \DateTimeImmutable('2026-06-24')));
    }
}
