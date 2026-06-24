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

    public function test_salt_is_preserved_on_reactivation(): void
    {
        // First activation: generates a non-empty salt.
        Plugin::activate();
        $salt1 = get_option(Settings::OPTION)['hash_salt'];
        $this->assertNotSame('', $salt1, 'First activation must produce a non-empty salt');

        // Second activation: must NOT regenerate the salt.
        Plugin::activate();
        $salt2 = get_option(Settings::OPTION)['hash_salt'];
        $this->assertSame($salt1, $salt2, 'Re-activation must preserve an existing salt');
    }

    public function test_empty_string_salt_is_replaced_on_activation(): void
    {
        // Seed the option with a blank salt (simulates a corrupted/legacy record).
        update_option(Settings::OPTION, array_merge(Settings::defaults(), ['hash_salt' => '']));

        Plugin::activate();
        $salt = get_option(Settings::OPTION)['hash_salt'];
        $this->assertNotSame('', $salt, 'Activation must replace an empty-string salt with a generated one');
    }
}
