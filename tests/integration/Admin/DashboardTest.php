<?php // tests/integration/Admin/DashboardTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Admin;
use PortoSender\Tests\integration\PortoTestCase;
use PortoSender\Inventory\CodeRepository;
use PortoSender\Admin\Dashboard;
use PortoSender\Settings\Settings;

final class DashboardTest extends PortoTestCase
{
    public function test_stock_summary_counts_available_codes(): void
    {
        global $wpdb;
        $repo = new CodeRepository($wpdb);
        $repo->addBatch('grossbrief', new \DateTimeImmutable('2024-01-01'), ['ONE']);
        $repo->addBatch('grossbrief', new \DateTimeImmutable('2026-01-01'), ['TWO']);
        $dash = new Dashboard($repo, new Settings(['enabled_products' => ['grossbrief']]));

        $summary = $dash->stockSummary();
        $this->assertSame(2, $summary['grossbrief']['available']);
    }
}
