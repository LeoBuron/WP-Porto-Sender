<?php
declare(strict_types=1);
namespace PortoSender\Persistence;

final class Schema
{
    public const CODES = 'porto_codes';
    public const REQUESTS = 'porto_requests';

    public static function codesTable(\wpdb $wpdb): string { return $wpdb->prefix . self::CODES; }
    public static function requestsTable(\wpdb $wpdb): string { return $wpdb->prefix . self::REQUESTS; }

    public static function install(\wpdb $wpdb): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $codes = self::codesTable($wpdb);
        $requests = self::requestsTable($wpdb);

        dbDelta("CREATE TABLE $codes (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  product varchar(32) NOT NULL,
  value_cents int(11) NOT NULL,
  purchase_date date NOT NULL,
  expires_on date NOT NULL,
  code varchar(64) NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'available',
  reserved_until datetime DEFAULT NULL,
  issued_to_hash char(64) DEFAULT NULL,
  issued_at datetime DEFAULT NULL,
  request_id bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY code (code),
  KEY product_status (product,status)
) $charset;");

        dbDelta("CREATE TABLE $requests (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) DEFAULT NULL,
  email varchar(190) DEFAULT NULL,
  email_hash char(64) NOT NULL,
  name_hash char(64) NOT NULL,
  product varchar(32) NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'pending',
  token_hash char(64) NOT NULL,
  ip_hash char(64) DEFAULT NULL,
  code_id bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  confirmed_at datetime DEFAULT NULL,
  issued_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY email_hash (email_hash),
  KEY name_hash (name_hash)
) $charset;");
    }

    public static function uninstall(\wpdb $wpdb): void
    {
        $wpdb->query('DROP TABLE IF EXISTS ' . self::codesTable($wpdb));
        $wpdb->query('DROP TABLE IF EXISTS ' . self::requestsTable($wpdb));
    }
}
