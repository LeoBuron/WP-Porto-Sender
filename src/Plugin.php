<?php
declare(strict_types=1);

namespace PortoSender;

final class Plugin
{
    public const VERSION = '0.1.0';

    public static function version(): string
    {
        return self::VERSION;
    }

    /** Wires the plugin into WordPress. Expanded in Task 24. */
    public static function boot(string $pluginFile): void
    {
        // Hook registration added in Task 24.
    }
}
