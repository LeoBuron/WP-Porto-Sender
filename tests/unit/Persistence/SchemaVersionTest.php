<?php declare(strict_types=1);
namespace PortoSender\Tests\unit\Persistence;

use PHPUnit\Framework\TestCase;
use PortoSender\Persistence\SchemaVersion;

final class SchemaVersionTest extends TestCase
{
    public function test_migrate_with_empty_map_is_noop(): void
    {
        $applied = (new SchemaVersion())->migrate('', '1', []);
        $this->assertSame([], $applied);
    }

    public function test_migrate_runs_all_pending_in_version_order(): void
    {
        $order = [];
        $migrations = [
            '2' => function () use (&$order) { $order[] = '2'; },
            '3' => function () use (&$order) { $order[] = '3'; },
        ];
        $applied = (new SchemaVersion())->migrate('1', '3', $migrations);
        $this->assertSame(['2', '3'], $applied);
        $this->assertSame(['2', '3'], $order);
    }

    public function test_migrate_skips_versions_at_or_below_from(): void
    {
        $order = [];
        $migrations = [
            '2' => function () use (&$order) { $order[] = '2'; },
            '3' => function () use (&$order) { $order[] = '3'; },
        ];
        $applied = (new SchemaVersion())->migrate('2', '3', $migrations);
        $this->assertSame(['3'], $applied);
        $this->assertSame(['3'], $order);
    }

    public function test_migrate_skips_versions_above_to(): void
    {
        $order = [];
        $migrations = [
            '2' => function () use (&$order) { $order[] = '2'; },
            '3' => function () use (&$order) { $order[] = '3'; },
        ];
        $applied = (new SchemaVersion())->migrate('1', '2', $migrations);
        $this->assertSame(['2'], $applied);
        $this->assertSame(['2'], $order);
    }

    public function test_migrate_orders_numerically_not_lexically(): void
    {
        $order = [];
        $migrations = [
            '3'  => function () use (&$order) { $order[] = '3'; },
            '2'  => function () use (&$order) { $order[] = '2'; },
            '10' => function () use (&$order) { $order[] = '10'; },
        ];
        $applied = (new SchemaVersion())->migrate('1', '10', $migrations);
        // version_compare must place '10' after '3', not before '2'.
        $this->assertSame(['2', '3', '10'], $applied);
        $this->assertSame(['2', '3', '10'], $order);
    }
}
