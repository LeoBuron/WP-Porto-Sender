<?php // tests/integration/ActivationTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration;
use PortoSender\Plugin;
use PortoSender\Persistence\Schema;
use PortoSender\Settings\Settings;
use PortoSender\Cron\Maintenance;

final class ActivationTest extends PortoTestCase
{
    public function test_activate_creates_tables_options_and_schedules_cron(): void
    {
        global $wpdb;
        Plugin::activate();
        $codes = Schema::codesTable($wpdb);
        $this->assertSame($codes, $wpdb->get_var("SHOW TABLES LIKE '$codes'"));
        $opt = get_option(Settings::OPTION);
        $this->assertNotSame('', $opt['hash_salt']); // generated salt
        $this->assertNotFalse(wp_next_scheduled(Maintenance::HOOK));
    }
}
