<?php
declare(strict_types=1);

namespace PortoSender\Lifecycle;

use PortoSender\Persistence\Schema;
use PortoSender\Persistence\SchemaVersion;
use PortoSender\Settings\Settings;
use PortoSender\Notifications\WpNotifyThrottleStore;
use PortoSender\Cron\Maintenance;

/**
 * The single definition of "all plugin data", shared by uninstall.php and the
 * admin "delete all data" action so the two deletion paths can never drift.
 *
 * Covers: both tables, the settings + schema-version + notify-pending options,
 * the per-product low-stock flags, the rate-limit + notify transients, and the
 * maintenance cron (cleared here too because deleting a plugin WITHOUT
 * deactivating first never calls Plugin::deactivate()).
 */
final class DataEraser
{
    /**
     * Option-name prefixes deleted by LIKE — only the dynamic-key families that
     * cannot be enumerated through the API (per-IP-hash, time-bucketed rate-limit
     * transients). The literal underscores are esc_like'd so they match literally.
     */
    private const LIKE_PREFIXES = [
        'porto_sender_lowstock_',          // StockAlerter per-product flags (any product)
        '_transient_porto_rl_',            // rate-limit counters (TransientRateCounterStore)
        '_transient_timeout_porto_rl_',
    ];

    public static function purgeAll(\wpdb $wpdb): void
    {
        Schema::uninstall($wpdb); // DROP both tables

        // Exact-name options.
        delete_option(Settings::OPTION);
        delete_option(SchemaVersion::OPTION);
        delete_option(WpNotifyThrottleStore::PENDING_OPTION);

        // Fixed-key transient via the API (also correct on object-cache-backed sites).
        delete_transient(WpNotifyThrottleStore::COOLDOWN_TRANSIENT);

        // Dynamic-key transients/options by prefix. Prefixes are compile-time
        // constants; esc_like makes the literal underscores literal (not wildcards).
        foreach (self::LIKE_PREFIXES as $prefix) {
            $like = $wpdb->esc_like($prefix) . '%';
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
        }

        // Cron: a Delete-without-deactivate never runs Plugin::deactivate().
        wp_clear_scheduled_hook(Maintenance::HOOK);

        // The raw LIKE deletes bypass WP's option/transient cache; flush it so nothing
        // reads a stale value after this terminal purge.
        wp_cache_flush();
    }
}
