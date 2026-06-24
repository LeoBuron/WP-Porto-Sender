<?php
declare(strict_types=1);
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }
require_once __DIR__ . '/vendor/autoload.php';

global $wpdb;
\PortoSender\Persistence\Schema::uninstall($wpdb);
delete_option(\PortoSender\Settings\Settings::OPTION);
foreach (['standardbrief', 'grossbrief'] as $p) { delete_option('porto_sender_lowstock_' . $p); }
