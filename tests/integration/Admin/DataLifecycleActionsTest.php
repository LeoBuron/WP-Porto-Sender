<?php // tests/integration/Admin/DataLifecycleActionsTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Admin;
use PortoSender\Tests\integration\PortoTestCase;
use PortoSender\Admin\ToolsPage;
use PortoSender\Inventory\CodeRepository;
use PortoSender\Requests\RequestRepository;
use PortoSender\Persistence\Schema;
use PortoSender\Persistence\SchemaVersion;
use PortoSender\Settings\Settings;
use PortoSender\Cron\Maintenance;

final class DataLifecycleActionsTest extends PortoTestCase
{
    protected function tearDown(): void
    {
        // deleteAllData DROPs the real tables; recreate them for the next test.
        remove_filter('query', [$this, '_create_temporary_tables']);
        remove_filter('query', [$this, '_drop_temporary_tables']);
        Schema::install($GLOBALS['wpdb']);
        wp_clear_scheduled_hook(Maintenance::HOOK);
        parent::tearDown();
    }

    private function page(): ToolsPage
    {
        global $wpdb;
        return new ToolsPage(new CodeRepository($wpdb), new RequestRepository($wpdb));
    }

    public function test_reset_settings_preserves_salt_and_restores_defaults(): void
    {
        update_option(Settings::OPTION, array_merge(Settings::defaults(), [
            'hash_salt' => 'KEEPSALT', 'owner_address' => 'changed', 'pii_retention_days' => 999,
        ]));

        $this->page()->resetSettings();

        $opt = get_option(Settings::OPTION);
        $this->assertSame('KEEPSALT', $opt['hash_salt']);     // salt preserved
        $this->assertSame('', $opt['owner_address']);          // back to default
        $this->assertSame(180, $opt['pii_retention_days']);    // back to default
    }

    public function test_delete_all_data_wipes_and_reinitializes_with_new_salt(): void
    {
        global $wpdb;
        remove_filter('query', [$this, '_drop_temporary_tables']); // let purgeAll's DROP hit real tables
        $codes = new CodeRepository($wpdb);
        $codes->addBatch('standardbrief', new \DateTimeImmutable('2026-01-15'), ['WIPEME']);
        update_option(Settings::OPTION, array_merge(Settings::defaults(), ['hash_salt' => 'OLDSALT', 'owner_address' => 'X']));

        (new ToolsPage($codes, new RequestRepository($wpdb)))->deleteAllData();

        $opt = get_option(Settings::OPTION);
        $this->assertNotSame('OLDSALT', $opt['hash_salt']);    // fresh salt
        $this->assertNotSame('', $opt['hash_salt']);
        $this->assertSame('', $opt['owner_address']);          // defaults
        $this->assertSame('1', get_option(SchemaVersion::OPTION));
        $this->assertSame(0, $codes->availableCount('standardbrief', new \DateTimeImmutable('now'))); // empty, recreated
    }

    public function test_delete_all_data_reschedules_the_daily_maintenance_cron(): void
    {
        global $wpdb;
        remove_filter('query', [$this, '_drop_temporary_tables']);
        // purgeAll clears the hook; start from "not scheduled" to prove deleteAllData re-arms it.
        wp_clear_scheduled_hook(Maintenance::HOOK);
        $this->assertFalse(wp_next_scheduled(Maintenance::HOOK));

        (new ToolsPage(new CodeRepository($wpdb), new RequestRepository($wpdb)))->deleteAllData();

        // Without rescheduling, the DSGVO anonymization / pending-cleanup cron silently stops.
        $this->assertNotFalse(wp_next_scheduled(Maintenance::HOOK), 'daily maintenance cron must be re-armed after a clean-slate wipe');
    }
}
