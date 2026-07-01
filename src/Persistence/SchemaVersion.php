<?php
declare(strict_types=1);

namespace PortoSender\Persistence;

/**
 * Tracks the installed DB schema version and runs ordered migrations.
 *
 * "Surviving updates" is mostly automatic: WordPress updates never touch plugin
 * tables, and dbDelta (Schema::install) is idempotent + additive, so a bumped
 * CREATE TABLE adds new columns/indexes by itself. This runner exists for the
 * changes dbDelta cannot express — data backfills, drops/renames, transforms —
 * applying the steps strictly between the recorded version and CURRENT_VERSION.
 *
 * The current schema is v2; the migration map holds the steps dbDelta cannot
 * express (e.g. the v2 drop of the obsolete value_cents column).
 */
final class SchemaVersion
{
    public const OPTION = 'porto_sender_schema_version';

    public function current(): string
    {
        return (string) get_option(self::OPTION, '');
    }

    public function set(string $version): void
    {
        update_option(self::OPTION, $version);
    }

    /**
     * Apply every migration whose version is in the half-open interval
     * (from, to], in ascending version order. Pure: callables are invoked with
     * no arguments (real migrations capture their dependencies via closure).
     *
     * @param array<string,callable> $migrations version => migration callable
     * @return array<int,string> the versions applied, in the order applied
     */
    public function migrate(string $from, string $to, array $migrations): array
    {
        $versions = array_keys($migrations);
        usort($versions, static fn (string $a, string $b): int => version_compare($a, $b));

        $applied = [];
        foreach ($versions as $version) {
            if (version_compare((string) $version, $from, '>')
                && version_compare((string) $version, $to, '<=')) {
                ($migrations[$version])();
                $applied[] = (string) $version;
            }
        }

        return $applied;
    }

    /**
     * Reconcile the stored version with CURRENT_VERSION. Call after
     * Schema::install() in the activation hook.
     */
    public function run(\wpdb $wpdb): void
    {
        $to = Schema::CURRENT_VERSION;
        $from = $this->current();

        if ($from === '') {
            // No version recorded yet: either a fresh install (dbDelta has just
            // built every table at CURRENT) or a legacy pre-versioning install
            // sitting at the old baseline. We cannot tell them apart from the
            // option alone, so run the whole baseline->CURRENT migration range
            // and rely on each step being self-guarding: the value_cents drop,
            // for instance, checks the column exists first, so on a fresh install
            // (where CURRENT already holds) it is a no-op, while a legacy install
            // converges. '0' is a sentinel below every migration key.
            $from = '0';
        }

        if ($from === $to) {
            return;
        }

        $this->migrate($from, $to, $this->migrations($wpdb));
        $this->set($to);
    }

    /**
     * The built-in migration map, keyed by the version each step produces.
     * Future schema changes register their step here.
     *
     * @return array<string,callable>
     */
    private function migrations(\wpdb $wpdb): array
    {
        return [
            // v2: drop the obsolete per-code postage value column. The "codes
            // below postage value" warning it powered was removed in 0.5.0, and
            // issuance never ordered by value, so the data has no operational
            // use. Guarded by a column check so it no-ops on fresh installs,
            // whose CREATE TABLE already omits the column.
            '2' => static function () use ($wpdb): void {
                $codes = Schema::codesTable($wpdb);
                $exists = $wpdb->get_var("SHOW COLUMNS FROM `{$codes}` LIKE 'value_cents'");
                if ($exists) {
                    $wpdb->query("ALTER TABLE `{$codes}` DROP COLUMN value_cents");
                }
            },
        ];
    }
}
