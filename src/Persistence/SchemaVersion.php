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
 * The current schema is the v1 baseline; the built-in migration map is empty.
 */
final class SchemaVersion
{
    public const OPTION = 'porto_sender_schema_version';

    /** The version at which schema versioning was introduced (the baseline). */
    public const BASELINE = '1';

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
            // First time a version is recorded. dbDelta has just brought a fresh
            // install straight to CURRENT, and a legacy pre-versioning install is
            // at the v1 baseline — neither needs a migration up to v1. When
            // CURRENT advances past the baseline, add fresh-vs-legacy detection
            // here before running baseline->CURRENT migrations.
            $this->set($to);
            return;
        }

        if ($from === $to) {
            return;
        }

        $this->migrate($from, $to, $this->migrations($wpdb));
        $this->set($to);
    }

    /**
     * The built-in migration map. Empty at the v1 baseline; future schema
     * changes register their step here, keyed by the version they produce.
     *
     * @return array<string,callable>
     */
    private function migrations(\wpdb $wpdb): array
    {
        return [];
    }
}
