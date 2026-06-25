<?php // tests/integration/Lifecycle/DataEraserTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Lifecycle;
use PortoSender\Tests\integration\PortoTestCase;
use PortoSender\Lifecycle\DataEraser;
use PortoSender\Persistence\Schema;
use PortoSender\Persistence\SchemaVersion;
use PortoSender\Settings\Settings;
use PortoSender\Notifications\WpNotifyThrottleStore;
use PortoSender\Inventory\CodeRepository;
use PortoSender\Requests\RequestRepository;
use PortoSender\Cron\Maintenance;

final class DataEraserTest extends PortoTestCase
{
    protected function tearDown(): void
    {
        // The test drops the REAL tables, so recreate them (as real, not temporary) for the
        // next test's isolation — WP_UnitTestCase otherwise rewrites CREATE/DROP to TEMPORARY.
        remove_filter('query', [$this, '_create_temporary_tables']);
        remove_filter('query', [$this, '_drop_temporary_tables']);
        Schema::install($GLOBALS['wpdb']);
        parent::tearDown();
    }

    public function test_purge_all_removes_tables_options_transients_and_cron(): void
    {
        global $wpdb;
        (new CodeRepository($wpdb))->addBatch('standardbrief', 95, new \DateTimeImmutable('2026-01-15'), ['ERASE1']);
        // Seed a PII-bearing request — the whole point of a DSGVO purge is to drop this table.
        (new RequestRepository($wpdb))->createPending([
            'name' => 'Erase Me', 'email' => 'erase@example.test', 'email_hash' => 'eh', 'name_hash' => 'nh',
            'product' => 'standardbrief', 'token_hash' => 'erase-tok', 'ip_hash' => null, 'created_at' => '2026-01-15 10:00:00',
        ]);

        // Let purgeAll's DROP TABLE hit the real tables — WP_UnitTestCase otherwise rewrites
        // DROP TABLE -> DROP TEMPORARY TABLE, a no-op on the class's real tables.
        remove_filter('query', [$this, '_drop_temporary_tables']);

        update_option(Settings::OPTION, array_merge(Settings::defaults(), ['hash_salt' => 'S']));
        update_option(SchemaVersion::OPTION, '1');
        update_option('porto_sender_lowstock_standardbrief', 'low');
        update_option(WpNotifyThrottleStore::PENDING_OPTION, 3);
        set_transient('porto_rl_ip_abc_123', 2, 3600);
        set_transient(WpNotifyThrottleStore::COOLDOWN_TRANSIENT, 1, 900);
        if (!wp_next_scheduled(Maintenance::HOOK)) {
            wp_schedule_event(time() + 3600, 'daily', Maintenance::HOOK);
        }
        $this->assertNotFalse(wp_next_scheduled(Maintenance::HOOK));

        DataEraser::purgeAll($wpdb);

        $codesTable = Schema::codesTable($wpdb);
        $requestsTable = Schema::requestsTable($wpdb);
        $this->assertNull($wpdb->get_var("SHOW TABLES LIKE '$codesTable'"), 'codes table dropped');
        $this->assertNull($wpdb->get_var("SHOW TABLES LIKE '$requestsTable'"), 'requests (PII) table dropped');
        $this->assertFalse(get_option(Settings::OPTION), 'settings option gone');
        $this->assertFalse(get_option(SchemaVersion::OPTION), 'schema version option gone');
        $this->assertFalse(get_option('porto_sender_lowstock_standardbrief'), 'lowstock flag gone');
        $this->assertFalse(get_option(WpNotifyThrottleStore::PENDING_OPTION), 'notify pending option gone');
        $this->assertFalse(get_transient('porto_rl_ip_abc_123'), 'rate-limit transient gone');
        $this->assertFalse(get_transient(WpNotifyThrottleStore::COOLDOWN_TRANSIENT), 'notify cooldown transient gone');
        $this->assertFalse(wp_next_scheduled(Maintenance::HOOK), 'cron unscheduled');
    }
}
