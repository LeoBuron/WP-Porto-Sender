<?php // tests/integration/Persistence/SchemaVersionTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Persistence;
use PortoSender\Tests\integration\PortoTestCase;
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
        $this->assertSame('1', get_option(SchemaVersion::OPTION));
    }

    public function test_run_is_idempotent_when_already_current(): void
    {
        global $wpdb;
        $sv = new SchemaVersion();
        $sv->run($wpdb);
        $sv->run($wpdb);
        $this->assertSame('1', get_option(SchemaVersion::OPTION));
    }

    public function test_run_reconciles_a_stale_recorded_version(): void
    {
        global $wpdb;
        // Simulate an install recorded behind CURRENT (no real migration at v1,
        // so the empty map applies nothing and the version is bumped to current).
        update_option(SchemaVersion::OPTION, '0');
        (new SchemaVersion())->run($wpdb);
        $this->assertSame('1', get_option(SchemaVersion::OPTION));
    }

    public function test_activate_sets_schema_version(): void
    {
        Plugin::activate();
        $this->assertSame('1', get_option(SchemaVersion::OPTION));
    }
}
