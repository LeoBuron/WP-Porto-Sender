<?php
declare(strict_types=1);

namespace PortoSender\Updates;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Wires WordPress's plugin-update UI to this repo's GitHub Releases, so an installed
 * site is notified of new versions and can update in place from the admin.
 *
 * It updates from the release ZIP ASSET (wp-porto-sender-x.y.z.zip), which bundles the
 * production vendor/, NOT GitHub's source tarball (which omits the gitignored vendor/
 * and would install a broken plugin). The repo is public, so no auth token is needed.
 */
final class GitHubUpdates
{
    private const REPO = 'https://github.com/LeoBuron/WP-Porto-Sender/';

    public static function register(string $pluginFile): void
    {
        // Updates are only consulted on admin screens and the update cron — skip the
        // front-end (and the test runner, where neither is true) to avoid needless work.
        if (!is_admin() && !(function_exists('wp_doing_cron') && wp_doing_cron())) {
            return;
        }

        // PUC isn't PSR-4 autoloaded (it namespaces by version to avoid clashes between
        // plugins bundling different copies); load its bootstrap once if needed.
        if (!class_exists(PucFactory::class)) {
            $bootstrap = dirname($pluginFile) . '/vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php';
            if (!is_file($bootstrap)) {
                return; // updater not bundled (e.g. a dev checkout without the dep)
            }
            require_once $bootstrap;
        }

        $checker = PucFactory::buildUpdateChecker(self::REPO, $pluginFile, 'wp-porto-sender');
        // Install the built release asset, not the source zipball.
        $checker->getVcsApi()->enableReleaseAssets('/wp-porto-sender-.*\.zip/');
    }
}
