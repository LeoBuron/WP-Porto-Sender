<?php
/**
 * Plugin Name: WP-Porto-Sender
 * Description: Emails website visitors a single-use Deutsche Post Mobile Briefmarke code from a pre-purchased pool so they can mail a letter to the site owner.
 * Version: 0.1.0
 * Requires PHP: 8.1
 * Requires at least: 6.4
 * Text Domain: wp-porto-sender
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

\PortoSender\Plugin::boot(__FILE__);
