<?php // tests/integration/Persistence/SchemaVersionTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Persistence;
use PortoSender\Tests\integration\PortoTestCase;
use PortoSender\Persistence\Schema;
use PortoSender\Persistence\SchemaVersion;
use PortoSender\Plugin;

final class SchemaVersionTest extends PortoTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(SchemaVersion::OPTION);
    }

    public function test_run_records_current_version_on_fresh_install(): void
    {
        global $wpdb;
        $this->assertSame('', (string) get_option(SchemaVersion::OPTION, ''));
        (new SchemaVersion())->run($wpdb);
        $this->assertSame('2', get_option(SchemaVersion::OPTION));
    }

    public function test_run_is_idempotent_when_already_current(): void
    {
        global $wpdb;
        $sv = new SchemaVersion();
        $sv->run($wpdb);
        $sv->run($wpdb);
        $this->assertSame('2', get_option(SchemaVersion::OPTION));
    }

    public function test_run_reconciles_a_stale_recorded_version(): void
    {
        global $wpdb;
        // Simulate an install recorded behind CURRENT; run() applies the pending
        // migrations (each self-guarding) and bumps the version to current.
        update_option(SchemaVersion::OPTION, '0');
        (new SchemaVersion())->run($wpdb);
        $this->assertSame('2', get_option(SchemaVersion::OPTION));
    }

    public function test_run_drops_legacy_value_cents_column(): void
    {
        global $wpdb;
        $codes = Schema::codesTable($wpdb);
        // Simulate a pre-0.5.0 install still carrying the obsolete column, pinned
        // at the old recorded version so run() must apply the v2 drop migration.
        $wpdb->query("ALTER TABLE `{$codes}` ADD COLUMN value_cents int(11) NOT NULL DEFAULT 0");
        $this->assertContains('value_cents', $wpdb->get_col("SHOW COLUMNS FROM `{$codes}`"));
        (new SchemaVersion())->set('1');

        (new SchemaVersion())->run($wpdb);

        $this->assertNotContains('value_cents', $wpdb->get_col("SHOW COLUMNS FROM `{$codes}`"));
        $this->assertSame('2', get_option(SchemaVersion::OPTION));
    }

    public function test_activate_sets_schema_version(): void
    {
        Plugin::activate();
        $this->assertSame('2', get_option(SchemaVersion::OPTION));
    }
}
