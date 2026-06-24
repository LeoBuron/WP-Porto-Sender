<?php
declare(strict_types=1);
namespace PortoSender\Tests\integration;
use WP_UnitTestCase;
use PortoSender\Persistence\Schema;

abstract class PortoTestCase extends WP_UnitTestCase
{
    public static function wpSetUpBeforeClass($factory): void
    {
        Schema::install($GLOBALS['wpdb']); // DDL auto-commits; per-test DML still rolls back
    }
}
