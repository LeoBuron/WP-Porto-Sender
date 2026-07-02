<?php // tests/integration/UninstallCompletenessTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration;
use PortoSender\Persistence\Schema;
use PortoSender\Settings\Settings;
use PortoSender\Frontend\PageProvisioner;
use PortoSender\Notifications\WpNotifyThrottleStore;
use PortoSender\Cron\Maintenance;

final class UninstallCompletenessTest extends PortoTestCase
{
    protected function tearDown(): void
    {
        // uninstall.php DROPs the real tables; recreate them for the next test.
        remove_filter('query', [$this, '_create_temporary_tables']);
        remove_filter('query', [$this, '_drop_temporary_tables']);
        Schema::install($GLOBALS['wpdb']);
        parent::tearDown();
    }

    public function test_uninstall_php_leaves_no_plugin_residue(): void
    {
        global $wpdb;
        update_option(Settings::OPTION, array_merge(Settings::defaults(), ['hash_salt' => 'X']));
        update_option(WpNotifyThrottleStore::PENDING_OPTION, 5); // missed by the OLD uninstall.php
        update_option(WpNotifyThrottleStore::CONTEXT_OPTION, ['product_label' => 'X', 'remaining' => 1]);
        set_transient('porto_rl_ip_zzz_1', 1, 3600);
        // Auto-provisioned "sent"/"result" pages must be removed by uninstall too.
        (new PageProvisioner(Settings::fromOption()))->ensure();
        $autoPages = PageProvisioner::ids();
        if (!wp_next_scheduled(Maintenance::HOOK)) {
            wp_schedule_event(time() + 3600, 'daily', Maintenance::HOOK);
        }
        remove_filter('query', [$this, '_drop_temporary_tables']); // let DROP hit real tables

        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', true);
        }
        require dirname(__DIR__, 2) . '/uninstall.php';

        $this->assertFalse(get_option(Settings::OPTION), 'settings gone');
        $this->assertFalse(get_option(WpNotifyThrottleStore::PENDING_OPTION), 'notify pending gone');
        $this->assertFalse(get_option(WpNotifyThrottleStore::CONTEXT_OPTION), 'notify context gone');
        $this->assertFalse(get_transient('porto_rl_ip_zzz_1'), 'rate-limit transient gone');
        $this->assertFalse(wp_next_scheduled(Maintenance::HOOK), 'cron unscheduled');
        $this->assertNull(get_post($autoPages['sent']), 'auto sent page deleted');
        $this->assertNull(get_post($autoPages['result']), 'auto result page deleted');
        $this->assertFalse(get_option(PageProvisioner::OPTION), 'auto-pages option gone');
        $codesTable = Schema::codesTable($wpdb);
        $requestsTable = Schema::requestsTable($wpdb);
        $this->assertNull($wpdb->get_var("SHOW TABLES LIKE '$codesTable'"), 'codes table dropped');
        $this->assertNull($wpdb->get_var("SHOW TABLES LIKE '$requestsTable'"), 'requests (PII) table dropped');
    }
}
