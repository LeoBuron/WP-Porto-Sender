<?php // tests/integration/Admin/DashboardTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Admin;
use PortoSender\Tests\integration\PortoTestCase;
use PortoSender\Inventory\CodeRepository;
use PortoSender\Admin\Dashboard;
use PortoSender\Postage\ProductCatalog;
use PortoSender\Settings\Settings;

final class DashboardTest extends PortoTestCase
{
    public function test_stock_summary_and_value_drift(): void
    {
        global $wpdb;
        $repo = new CodeRepository($wpdb);
        $repo->addBatch('grossbrief', 150, new \DateTimeImmutable('2024-01-01'), ['CHEAP']); // below current 180
        $repo->addBatch('grossbrief', 180, new \DateTimeImmutable('2026-01-01'), ['OK']);
        $dash = new Dashboard($repo, ProductCatalog::default(), new Settings(['enabled_products' => ['grossbrief']]));

        $summary = $dash->stockSummary();
        $this->assertSame(2, $summary['grossbrief']['available']);

        $drift = $dash->valueDrift();
        $codes = array_map(static fn($r) => $r->code, $drift['grossbrief']);
        $this->assertSame(['CHEAP'], $codes);
    }
}
