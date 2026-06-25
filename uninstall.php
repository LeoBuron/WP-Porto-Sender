<?php
declare(strict_types=1);
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }
require_once __DIR__ . '/vendor/autoload.php';

global $wpdb;
// Single source of truth for "all plugin data" — shared with the admin "delete all
// data" action so the two paths can never drift (tables, options, transients, cron).
\PortoSender\Lifecycle\DataEraser::purgeAll($wpdb);
