<?php
declare(strict_types=1);

namespace PortoSender;

use PortoSender\Persistence\{Schema, SchemaVersion};
use PortoSender\Settings\Settings;
use PortoSender\Postage\ProductCatalog;
use PortoSender\Support\{Hasher, TokenGenerator, SystemClock};
use PortoSender\Inventory\{CodeRepository, StockAlerter};
use PortoSender\Requests\RequestRepository;
use PortoSender\Limiting\{RequestLimiter, RateLimiter, TransientRateCounterStore};
use PortoSender\Captcha\{AltchaVerifier, NullVerifier, CaptchaVerifier};
use PortoSender\Mail\Mailer;
use PortoSender\Notifications\{AdminNotifier, WpNotifyThrottleStore};
use PortoSender\Geo\{GeoGate, GeoProviderFactory};
use PortoSender\Issuance\{IssuanceService, UrlConfirmLinkBuilder};
use PortoSender\Rest\RestController;
use PortoSender\Frontend\{RequestForm, ConfirmHandler, BlockRegistrar, PageRenderer, PageProvisioner};
use PortoSender\Admin\{SettingsPage, CodeIntakePage, Dashboard, ToolsPage};
use PortoSender\Cron\Maintenance;

final class Plugin
{
    public const VERSION = '0.1.0';
    private static string $file = '';

    public static function version(): string { return self::VERSION; }

    public static function boot(string $pluginFile): void
    {
        self::$file = $pluginFile;
        register_activation_hook($pluginFile, [self::class, 'activate']);
        register_deactivation_hook($pluginFile, [self::class, 'deactivate']);
        add_action('init', [self::class, 'wire']);
    }

    private static function captcha(Settings $s): CaptchaVerifier
    {
        return ($s->captchaProvider() === 'altcha' && $s->altchaHmacSecret() !== '')
            ? new AltchaVerifier($s->altchaHmacSecret()) : new NullVerifier();
    }

    private static function issuance(\wpdb $wpdb, Settings $s): IssuanceService
    {
        $codes = new CodeRepository($wpdb);
        $requests = new RequestRepository($wpdb);
        $mailer = new Mailer($s);
        return new IssuanceService(
            self::captcha($s), new RequestLimiter($requests),
            new RateLimiter(new TransientRateCounterStore(), $s, new SystemClock()),
            $codes, $requests, $mailer,
            new Hasher($s->hashSalt()), new TokenGenerator(), new UrlConfirmLinkBuilder(),
            $s, ProductCatalog::default(), new SystemClock(),
            new AdminNotifier($s, $mailer, new WpNotifyThrottleStore()),
            new GeoGate(GeoProviderFactory::make($s), $s)
        );
    }

    public static function wire(): void
    {
        global $wpdb;
        $s = Settings::fromOption();
        $catalog = ProductCatalog::default();
        $issuance = self::issuance($wpdb, $s);

        $form = new RequestForm($catalog, $s);
        add_shortcode('porto_request', fn($atts) => $form->render(is_array($atts) ? $atts : []));
        add_action('wp_enqueue_scripts', [$form, 'enqueueAssets']);

        (new RestController($issuance, self::captcha($s)))->register();
        (new ConfirmHandler($issuance, $s))->register();
        (new PageRenderer($s))->register();
        (new BlockRegistrar($form))->register();

        $codes = new CodeRepository($wpdb);
        $requests = new RequestRepository($wpdb);
        $alerter = new StockAlerter($codes, $s, new Mailer($s), $catalog, new SystemClock());
        $notifier = new AdminNotifier($s, new Mailer($s), new WpNotifyThrottleStore());
        (new Maintenance($codes, $requests, $alerter, $s, new SystemClock(), $notifier))->register();

        if (is_admin()) {
            // WordPress does not fire the activation hook on auto-update (this plugin's
            // update path via GitHub Releases), so run any pending schema migration on the
            // first admin load after an update. run() is version-gated → no-op once current.
            (new SchemaVersion())->run($wpdb);
            // Ensure the built-in "sent"/"result" views are backed by real pages. Runs here
            // too (not only on activation) because GitHub-Release auto-updates never fire the
            // activation hook; idempotent and cheap, so it self-heals on any admin load.
            (new PageProvisioner($s))->ensure();
            (new SettingsPage())->register();
            (new CodeIntakePage($codes, $catalog))->register();
            (new Dashboard($codes, $s))->register();
            (new ToolsPage($codes, $requests))->register();
            // Guide the admin to export before removing the plugin (uninstall.php has no UI).
            add_filter('plugin_action_links_' . plugin_basename(self::$file), static function (array $links): array {
                $url = admin_url('admin.php?page=porto-sender-tools');
                array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Export/Entfernen', 'wp-porto-sender') . '</a>');
                return $links;
            });
        }
    }

    public static function activate(): void
    {
        global $wpdb;
        Schema::install($wpdb);
        (new SchemaVersion())->run($wpdb);

        $existing = get_option(Settings::OPTION, []);
        $defaults = Settings::defaults();
        if (empty($existing['hash_salt'])) {
            $defaults['hash_salt'] = wp_generate_password(64, false, false);
        }
        if (empty($existing['alert_email'])) {
            $defaults['alert_email'] = get_option('admin_email', '');
        }
        update_option(Settings::OPTION, array_merge($defaults, is_array($existing) ? $existing : [], [
            'hash_salt' => ($existing['hash_salt'] ?? '') ?: $defaults['hash_salt'],
            'alert_email' => ($existing['alert_email'] ?? '') ?: $defaults['alert_email'],
        ]));

        if (!wp_next_scheduled(Maintenance::HOOK)) {
            wp_schedule_event(time() + 3600, 'daily', Maintenance::HOOK);
        }

        // Back the built-in "sent"/"result" views with real pages from the first load.
        (new PageProvisioner(Settings::fromOption()))->ensure();
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(Maintenance::HOOK);
        // Hide (don't delete) the auto-provisioned pages while inactive: their noindex/menu/
        // sitemap-exclusion filters only run when the plugin is active, so leaving them
        // published would expose two thin utility pages to search. ensure() republishes them.
        PageProvisioner::unpublish();
    }
}
