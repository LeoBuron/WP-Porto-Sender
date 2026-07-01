<?php // tests/integration/Persistence/SchemaTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Persistence;
use PortoSender\Tests\integration\PortoTestCase;
use PortoSender\Persistence\Schema;

final class SchemaTest extends PortoTestCase
{
    public function test_install_creates_both_tables_with_key_columns(): void
    {
        global $wpdb;
        $codes = Schema::codesTable($wpdb);
        $requests = Schema::requestsTable($wpdb);
        $this->assertSame($codes, $wpdb->get_var("SHOW TABLES LIKE '$codes'"));
        $this->assertSame($requests, $wpdb->get_var("SHOW TABLES LIKE '$requests'"));
        $cols = $wpdb->get_col("SHOW COLUMNS FROM $codes");
        foreach (['id','product','purchase_date','expires_on','code','status','reserved_until','issued_to_hash','request_id'] as $c) {
            $this->assertContains($c, $cols, "codes.$c missing");
        }
        $this->assertNotContains('value_cents', $cols, 'value_cents was dropped in v0.5.0');
    }
}
