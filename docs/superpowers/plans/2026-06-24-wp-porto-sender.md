# WP-Porto-Sender Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A WordPress plugin that emails a website visitor a single-use Deutsche Post *Mobile Briefmarke* code (from a manually pre-purchased pool) so they can mail a letter to the site owner, gated by double-opt-in + CAPTCHA.

**Architecture:** A self-contained plugin acting as a *secure inventory dispenser*. Two custom tables hold the code pool and the requests. The correctness core is an **atomic SQL claim** that reserves one available code under concurrency without double-issuing. Pure domain logic (expiry, hashing, dedup, the issuance state machine) is isolated from WordPress for fast unit testing; only persistence and HTTP layers touch `$wpdb`/WP APIs.

**Tech Stack:** PHP 8.1+ (8.5 present), WordPress 6.4+, Composer (PSR-4 autoload), PHPUnit + `brain/monkey` (unit, no Docker), `@wordpress/env` + `wp-phpunit/wp-phpunit` + `yoast/phpunit-polyfills` (integration, Docker), `@wordpress/scripts` (block build), `altcha-org/altcha` (self-hosted CAPTCHA).

Full design spec: `docs/superpowers/specs/2026-06-24-wp-porto-sender-design.md`. Read §2 (verified Deutsche Post constraints) and §7 (atomic claim) before starting.

## Global Constraints

Every task's requirements implicitly include this section.

- **Prerequisite — Composer is not installed.** Install it before Task 1: `brew install composer` (or the official installer). Verify with `composer --version`.
- **Prerequisite — Docker is required** for integration tests (it is installed and running). Unit tests need only PHP + Composer.
- **PHP** ≥ 8.1; **WordPress** ≥ 6.4.
- **Namespace** `PortoSender\`, PSR-4 mapped to `src/`. **Text domain** `wp-porto-sender`; UI strings in German, wrapped in i18n functions.
- **Tables:** `{$wpdb->prefix}porto_codes`, `{$wpdb->prefix}porto_requests`.
- **Products & 2026 prices (verbatim):** Standardbrief = 95 ct, Großbrief = 180 ct.
- **Mobile Briefmarke:** code is displayed as `#PORTO ` + the stored 8-char code; validity = **31 Dec of (purchase year + 3)**.
- **Dedup modes:** `email` | `name` | `name_or_email` | `none`; default `name_or_email`.
- **Default settings:** `pii_retention_days`=180, `confirm_token_ttl_hours`=48, `reservation_ttl_minutes`=30, `expiry_warning_months`=6, `low_stock_threshold`=5, `captcha_provider`=`altcha`.
- **CAPTCHA:** Altcha (self-hosted, `altcha-org/altcha`). **Never** Google reCAPTCHA.
- **Security:** codes are bearer secrets — never rendered on the front end, never logged; admin views show only a redacted code (last 3 chars). All admin actions require `manage_options` + nonces. All hashes are salted with a per-install secret stored in the options row.
- **TDD loop, every task:** write failing test → run it & see it fail → minimal implementation → run it & see it pass → commit. Commit messages: `feat:`/`test:`/`chore:` prefix; end with the `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>` trailer. Use `jj` (`jj describe`/`jj commit`) per repo preference; `git commit` shown below is acceptable in the colocated repo.
- **Commands:** `composer test:unit` runs the unit suite; `composer test:integration` runs the integration suite (invoked inside `wp-env run tests-cli`, see Task 2).

## File Structure

```
porto-sender.php                      # Plugin header + bootstrap (require autoload, register activation/deactivation, boot Plugin)
uninstall.php                         # Drops tables + options on uninstall
composer.json                         # PSR-4 + dev deps + test scripts
package.json / .wp-env.json           # @wordpress/env config (Task 2) + block build (Task 22)
phpunit-unit.xml / phpunit-integration.xml
src/
  Plugin.php                          # Wires all hooks; activate()/deactivate()
  Support/
    Clock.php  SystemClock.php        # Injectable time
    Hasher.php                        # Salted sha256 for email/name/ip/token
    TokenGenerator.php                # Random confirm tokens
  Postage/
    PostageProduct.php                # Value object (key, valueCents, label, limits)
    ProductCatalog.php                # Registry of products + prices
    Expiry.php                        # expiresOn(purchaseDate)
  Inventory/
    CodeRepository.php                # Pool table: addBatch/claimOne(atomic)/lifecycle
    StockAlerter.php                  # Low-stock + out-of-stock alerts (debounced)
  Requests/
    RequestRepository.php             # Requests table: pending/confirm/dedup/anonymize
  Limiting/
    RequestLimiter.php                # Dedup policy by mode
  Captcha/
    CaptchaVerifier.php               # Interface (challenge + verify)
    AltchaVerifier.php  NullVerifier.php
  Settings/
    Settings.php                      # Typed option accessor + register/sanitize
  Mail/
    Mailer.php                        # Confirmation/delivery/alert emails
  Issuance/
    IssuanceService.php               # submit()/confirm() orchestration
    ConfirmLinkBuilder.php            # Builds the opt-in URL (injectable)
  Rest/
    RestController.php                # porto/v1 routes → IssuanceService + captcha challenge
  Frontend/
    RequestForm.php                   # [porto_request] shortcode + assets
    block/                            # Gutenberg dynamic block (Task 22)
  Admin/
    SettingsPage.php  CodeIntakePage.php  Dashboard.php
  Cron/
    Maintenance.php                   # Daily: release/quarantine/delete/anonymize/alert
tests/
  unit/ ...                           # brain/monkey, no Docker
  integration/ ...                    # wp-phpunit, real MySQL
```

---

### Task 1: Plugin scaffold + unit-test harness

**Files:**
- Create: `porto-sender.php`
- Create: `composer.json`
- Create: `phpunit-unit.xml`
- Create: `tests/unit/bootstrap.php`
- Create: `src/Plugin.php`
- Create: `tests/unit/PluginBootstrapTest.php`

**Interfaces:**
- Produces: Composer PSR-4 autoload for `PortoSender\` → `src/`; class `PortoSender\Plugin` with `public static function version(): string`.

- [ ] **Step 1: Install Composer** (prerequisite)

Run: `composer --version`
Expected: prints a version. If "command not found", run `brew install composer` first.

- [ ] **Step 2: Write `composer.json`**

```json
{
  "name": "leoburon/wp-porto-sender",
  "description": "Email visitors a single-use Deutsche Post Mobile Briefmarke code from a pre-purchased pool.",
  "type": "wordpress-plugin",
  "require": { "php": ">=8.1" },
  "require-dev": {
    "phpunit/phpunit": "^10",
    "brain/monkey": "^2.6",
    "yoast/phpunit-polyfills": "^2.0"
  },
  "autoload": { "psr-4": { "PortoSender\\": "src/" } },
  "autoload-dev": { "psr-4": { "PortoSender\\Tests\\": "tests/" } },
  "scripts": {
    "test:unit": "phpunit -c phpunit-unit.xml",
    "test:integration": "phpunit -c phpunit-integration.xml"
  },
  "config": { "allow-plugins": { "*": true } }
}
```

- [ ] **Step 3: Write `phpunit-unit.xml`**

```xml
<?xml version="1.0"?>
<phpunit bootstrap="tests/unit/bootstrap.php" colors="true" cacheDirectory=".phpunit.cache">
  <testsuites>
    <testsuite name="unit">
      <directory>tests/unit</directory>
    </testsuite>
  </testsuites>
</phpunit>
```

- [ ] **Step 4: Write `tests/unit/bootstrap.php`**

```php
<?php
require_once __DIR__ . '/../../vendor/autoload.php';
```

- [ ] **Step 5: Write the plugin header `porto-sender.php`**

```php
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
```

- [ ] **Step 6: Write the failing test `tests/unit/PluginBootstrapTest.php`**

```php
<?php
declare(strict_types=1);

namespace PortoSender\Tests\unit;

use PHPUnit\Framework\TestCase;
use PortoSender\Plugin;

final class PluginBootstrapTest extends TestCase
{
    public function test_version_is_semver(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Plugin::version());
    }
}
```

- [ ] **Step 7: Run it & verify it fails**

Run: `composer install && composer test:unit`
Expected: FAIL — `Class "PortoSender\Plugin" not found`.

- [ ] **Step 8: Write minimal `src/Plugin.php`**

```php
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
```

- [ ] **Step 9: Run it & verify it passes**

Run: `composer test:unit`
Expected: PASS (1 test).

- [ ] **Step 10: Commit**

```bash
git add porto-sender.php composer.json composer.lock phpunit-unit.xml tests/unit src/Plugin.php
git commit -m "chore: scaffold plugin + unit-test harness"
```

### Task 2: Integration-test harness (wp-env + wp-phpunit)

**Files:**
- Create: `package.json`
- Create: `.wp-env.json`
- Create: `phpunit-integration.xml`
- Create: `tests/integration/bootstrap.php`
- Create: `tests/integration/SmokeTest.php`
- Modify: `composer.json` (add `wp-phpunit/wp-phpunit` dev dep)

**Interfaces:**
- Produces: a working `composer test:integration` runnable inside `wp-env run tests-cli`; integration `TestCase` base from `WP_UnitTestCase`.

- [ ] **Step 1: Add the wp-phpunit dev dependency**

Run: `composer require --dev wp-phpunit/wp-phpunit:^6.4`
Expected: added to `require-dev`.

- [ ] **Step 2: Write `package.json`**

```json
{
  "name": "wp-porto-sender",
  "private": true,
  "scripts": {
    "env": "wp-env",
    "env:start": "wp-env start",
    "test:integration": "wp-env run tests-cli --env-cwd=wp-content/plugins/wp-porto-sender composer test:integration"
  },
  "devDependencies": { "@wordpress/env": "^10" }
}
```

- [ ] **Step 3: Write `.wp-env.json`**

```json
{
  "core": "WordPress/WordPress",
  "plugins": ["."],
  "config": { "WP_DEBUG": true }
}
```

- [ ] **Step 4: Write `phpunit-integration.xml`**

```xml
<?xml version="1.0"?>
<phpunit bootstrap="tests/integration/bootstrap.php" colors="true" cacheDirectory=".phpunit.cache">
  <testsuites>
    <testsuite name="integration">
      <directory>tests/integration</directory>
    </testsuite>
  </testsuites>
</phpunit>
```

- [ ] **Step 5: Write `tests/integration/bootstrap.php`**

```php
<?php
declare(strict_types=1);

$_tests_dir = getenv('WP_PHPUNIT__DIR') ?: __DIR__ . '/../../vendor/wp-phpunit/wp-phpunit';

require_once __DIR__ . '/../../vendor/autoload.php';
require_once $_tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__, 2) . '/porto-sender.php';
});

require $_tests_dir . '/includes/bootstrap.php';
```

- [ ] **Step 6: Write the failing smoke test `tests/integration/SmokeTest.php`**

```php
<?php
declare(strict_types=1);

namespace PortoSender\Tests\integration;

use WP_UnitTestCase;
use PortoSender\Plugin;

final class SmokeTest extends WP_UnitTestCase
{
    public function test_wordpress_and_plugin_are_loaded(): void
    {
        $this->assertTrue(function_exists('wp_insert_post'));
        $this->assertSame('0.1.0', Plugin::version());
    }
}
```

- [ ] **Step 7: Start the environment & run it**

Run: `npm install && npm run env:start && npm run test:integration`
Expected: PASS (1 test). (First run downloads Docker images; allow a few minutes.)

- [ ] **Step 8: Commit**

```bash
git add package.json package-lock.json .wp-env.json phpunit-integration.xml tests/integration composer.json composer.lock
git commit -m "chore: add wp-env integration-test harness"
```

### Task 3: Postage catalog + expiry

**Files:**
- Create: `src/Postage/PostageProduct.php`
- Create: `src/Postage/ProductCatalog.php`
- Create: `src/Postage/Expiry.php`
- Create: `tests/unit/Postage/ProductCatalogTest.php`
- Create: `tests/unit/Postage/ExpiryTest.php`

**Interfaces:**
- Produces:
  - `PostageProduct` readonly: `string $key, int $valueCents, string $label, string $limits`.
  - `ProductCatalog`: `all(): array<string,PostageProduct>`, `get(string $key): ?PostageProduct`, `enabled(array $keys): array<string,PostageProduct>`, static `default(): self`.
  - `Expiry::expiresOn(\DateTimeImmutable $purchase): \DateTimeImmutable` (31 Dec of year+3).

- [ ] **Step 1: Write the failing tests**

```php
<?php // tests/unit/Postage/ProductCatalogTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Postage;
use PHPUnit\Framework\TestCase;
use PortoSender\Postage\ProductCatalog;

final class ProductCatalogTest extends TestCase
{
    public function test_known_products_and_prices(): void
    {
        $c = ProductCatalog::default();
        $this->assertSame(95, $c->get('standardbrief')->valueCents);
        $this->assertSame(180, $c->get('grossbrief')->valueCents);
        $this->assertNull($c->get('nope'));
    }

    public function test_enabled_filters_to_requested_keys(): void
    {
        $c = ProductCatalog::default();
        $this->assertSame(['grossbrief'], array_keys($c->enabled(['grossbrief', 'nope'])));
    }
}
```

```php
<?php // tests/unit/Postage/ExpiryTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Postage;
use PHPUnit\Framework\TestCase;
use PortoSender\Postage\Expiry;

final class ExpiryTest extends TestCase
{
    public function test_expires_end_of_third_year_after_purchase(): void
    {
        $purchase = new \DateTimeImmutable('2026-06-24');
        $this->assertSame('2029-12-31', Expiry::expiresOn($purchase)->format('Y-m-d'));
    }
}
```

- [ ] **Step 2: Run & verify they fail**

Run: `composer test:unit`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement `PostageProduct`**

```php
<?php
declare(strict_types=1);
namespace PortoSender\Postage;

final class PostageProduct
{
    public function __construct(
        public readonly string $key,
        public readonly int $valueCents,
        public readonly string $label,
        public readonly string $limits,
    ) {}
}
```

- [ ] **Step 4: Implement `ProductCatalog`**

```php
<?php
declare(strict_types=1);
namespace PortoSender\Postage;

final class ProductCatalog
{
    /** @param array<string,PostageProduct> $products */
    public function __construct(private array $products) {}

    public static function default(): self
    {
        return new self([
            'standardbrief' => new PostageProduct('standardbrief', 95, 'Standardbrief', 'bis 20 g, gefaltet (z. B. 3 Seiten)'),
            'grossbrief' => new PostageProduct('grossbrief', 180, 'Großbrief', 'A4 flach, bis 500 g'),
        ]);
    }

    /** @return array<string,PostageProduct> */
    public function all(): array { return $this->products; }

    public function get(string $key): ?PostageProduct { return $this->products[$key] ?? null; }

    /** @return array<string,PostageProduct> */
    public function enabled(array $keys): array
    {
        return array_intersect_key($this->products, array_flip($keys));
    }
}
```

- [ ] **Step 5: Implement `Expiry`**

```php
<?php
declare(strict_types=1);
namespace PortoSender\Postage;

final class Expiry
{
    public static function expiresOn(\DateTimeImmutable $purchase): \DateTimeImmutable
    {
        $year = (int) $purchase->format('Y') + 3;
        return $purchase->setDate($year, 12, 31)->setTime(23, 59, 59);
    }
}
```

- [ ] **Step 6: Run & verify they pass**

Run: `composer test:unit`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Postage tests/unit/Postage
git commit -m "feat: postage product catalog and expiry calculation"
```

### Task 4: Support — Clock, Hasher, TokenGenerator

**Files:**
- Create: `src/Support/Clock.php`, `src/Support/SystemClock.php`
- Create: `src/Support/Hasher.php`
- Create: `src/Support/TokenGenerator.php`
- Create: `tests/unit/Support/HasherTest.php`
- Create: `tests/unit/Support/TokenGeneratorTest.php`

**Interfaces:**
- Produces:
  - `interface Clock { public function now(): \DateTimeImmutable; }`; `SystemClock implements Clock`.
  - `Hasher(__construct(string $salt))` with `email(string): string`, `name(string): string`, `ip(string): string`, `token(string): string` — all return 64-char hex.
  - `TokenGenerator::generate(): string` — 64-char hex (32 random bytes).

- [ ] **Step 1: Write the failing tests**

```php
<?php // tests/unit/Support/HasherTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Support;
use PHPUnit\Framework\TestCase;
use PortoSender\Support\Hasher;

final class HasherTest extends TestCase
{
    public function test_email_is_normalized_before_hashing(): void
    {
        $h = new Hasher('salt');
        $this->assertSame($h->email('  Foo@Bar.de '), $h->email('foo@bar.de'));
        $this->assertSame(64, strlen($h->email('foo@bar.de')));
    }

    public function test_salt_changes_the_hash(): void
    {
        $this->assertNotSame((new Hasher('a'))->email('x@y.de'), (new Hasher('b'))->email('x@y.de'));
    }

    public function test_name_is_case_and_whitespace_insensitive(): void
    {
        $h = new Hasher('salt');
        $this->assertSame($h->name('Max  Mustermann'), $h->name('max mustermann'));
    }
}
```

```php
<?php // tests/unit/Support/TokenGeneratorTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Support;
use PHPUnit\Framework\TestCase;
use PortoSender\Support\TokenGenerator;

final class TokenGeneratorTest extends TestCase
{
    public function test_generates_unique_64_char_hex(): void
    {
        $g = new TokenGenerator();
        $a = $g->generate();
        $this->assertSame(64, strlen($a));
        $this->assertNotSame($a, $g->generate());
    }
}
```

- [ ] **Step 2: Run & verify they fail**

Run: `composer test:unit`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement `Clock` + `SystemClock`**

```php
<?php
declare(strict_types=1);
namespace PortoSender\Support;
interface Clock { public function now(): \DateTimeImmutable; }
```

```php
<?php
declare(strict_types=1);
namespace PortoSender\Support;
final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable { return new \DateTimeImmutable('now'); }
}
```

- [ ] **Step 4: Implement `Hasher`**

```php
<?php
declare(strict_types=1);
namespace PortoSender\Support;

final class Hasher
{
    public function __construct(private string $salt) {}

    public function email(string $email): string { return $this->hash(strtolower(trim($email))); }

    public function name(string $name): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
        return $this->hash(mb_strtolower($normalized));
    }

    public function ip(string $ip): string { return $this->hash(trim($ip)); }

    public function token(string $token): string { return $this->hash($token); }

    private function hash(string $value): string
    {
        return hash('sha256', $this->salt . '|' . $value);
    }
}
```

- [ ] **Step 5: Implement `TokenGenerator`**

```php
<?php
declare(strict_types=1);
namespace PortoSender\Support;
final class TokenGenerator
{
    public function generate(): string { return bin2hex(random_bytes(32)); }
}
```

- [ ] **Step 6: Run & verify they pass**

Run: `composer test:unit`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Support tests/unit/Support
git commit -m "feat: clock, salted hasher, and token generator"
```

### Task 5: Database schema

**Files:**
- Create: `src/Persistence/Schema.php`
- Create: `tests/integration/PortoTestCase.php` (shared integration base)
- Create: `tests/integration/Persistence/SchemaTest.php`

**Interfaces:**
- Produces:
  - `Schema::codesTable(\wpdb $wpdb): string`, `Schema::requestsTable(\wpdb $wpdb): string`.
  - `Schema::install(\wpdb $wpdb): void` (idempotent, via `dbDelta`), `Schema::uninstall(\wpdb $wpdb): void`.
  - `PortoSender\Tests\integration\PortoTestCase` base that installs the schema once per class.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/integration/Persistence/SchemaTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Persistence;
use PortoSender\Tests\integration\PortoTestCase;
use PortoSender\Persistence\Schema;

final class SchemaTest extends PortoTestCase
{
    public function test_install_creates_both_tables_with_key_columns(): void
    {
        global $wpdb;
        $codes = Schema::codesTable($wpdb);
        $requests = Schema::requestsTable($wpdb);
        $this->assertSame($codes, $wpdb->get_var("SHOW TABLES LIKE '$codes'"));
        $this->assertSame($requests, $wpdb->get_var("SHOW TABLES LIKE '$requests'"));
        $cols = $wpdb->get_col("SHOW COLUMNS FROM $codes");
        foreach (['id','product','value_cents','purchase_date','expires_on','code','status','reserved_until','issued_to_hash','request_id'] as $c) {
            $this->assertContains($c, $cols, "codes.$c missing");
        }
    }
}
```

- [ ] **Step 2: Write the integration base `tests/integration/PortoTestCase.php`**

```php
<?php
declare(strict_types=1);
namespace PortoSender\Tests\integration;
use WP_UnitTestCase;
use PortoSender\Persistence\Schema;

abstract class PortoTestCase extends WP_UnitTestCase
{
    public static function wpSetUpBeforeClass($factory): void
    {
        Schema::install($GLOBALS['wpdb']); // DDL auto-commits; per-test DML still rolls back
    }
}
```

- [ ] **Step 3: Run & verify it fails**

Run: `npm run test:integration`
Expected: FAIL — `Class "PortoSender\Persistence\Schema" not found`.

- [ ] **Step 4: Implement `Schema`**

```php
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
```

> Note the **two spaces** after `PRIMARY KEY` — required by `dbDelta`.

- [ ] **Step 5: Run & verify it passes**

Run: `npm run test:integration`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Persistence/Schema.php tests/integration/PortoTestCase.php tests/integration/Persistence
git commit -m "feat: custom table schema for code pool and requests"
```

### Task 6: CodeRepository — intake & counts

**Files:**
- Create: `src/Inventory/CodeRepository.php`
- Create: `tests/integration/Inventory/CodeRepositoryIntakeTest.php`

**Interfaces:**
- Consumes: `Schema`, `Postage\Expiry`.
- Produces (on `CodeRepository(__construct(\wpdb $wpdb))`):
  - `addBatch(string $product, int $valueCents, \DateTimeImmutable $purchaseDate, array $codes): int` — inserts trimmed non-empty codes as `available`, computes `expires_on` via `Expiry`, skips duplicates (UNIQUE `code`); returns number inserted.
  - `availableCount(string $product, \DateTimeImmutable $now): int`
  - `countsByStatus(string $product): array{available:int,reserved:int,issued:int,expired:int}`
  - `getCode(int $id): ?object`

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/integration/Inventory/CodeRepositoryIntakeTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Inventory;
use PortoSender\Tests\integration\PortoTestCase;
use PortoSender\Inventory\CodeRepository;

final class CodeRepositoryIntakeTest extends PortoTestCase
{
    private CodeRepository $repo;
    public function set_up(): void { parent::set_up(); $this->repo = new CodeRepository($GLOBALS['wpdb']); }

    public function test_add_batch_inserts_and_dedupes(): void
    {
        $now = new \DateTimeImmutable('2026-06-24');
        $this->assertSame(2, $this->repo->addBatch('grossbrief', 180, $now, ['AAA111', 'BBB222', '  ', 'AAA111']));
        $this->assertSame(2, $this->repo->availableCount('grossbrief', $now));
        $this->assertSame(0, $this->repo->availableCount('standardbrief', $now));
    }
}
```

- [ ] **Step 2: Run & verify it fails**

Run: `npm run test:integration`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement intake & counts in `CodeRepository`**

```php
<?php
declare(strict_types=1);
namespace PortoSender\Inventory;

use PortoSender\Persistence\Schema;
use PortoSender\Postage\Expiry;

final class CodeRepository
{
    public function __construct(private \wpdb $wpdb) {}

    private function table(): string { return Schema::codesTable($this->wpdb); }

    public function addBatch(string $product, int $valueCents, \DateTimeImmutable $purchaseDate, array $codes): int
    {
        $table = $this->table();
        $purchase = $purchaseDate->format('Y-m-d');
        $expires = Expiry::expiresOn($purchaseDate)->format('Y-m-d');
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $inserted = 0;
        foreach ($codes as $raw) {
            $code = trim((string) $raw);
            if ($code === '') { continue; }
            $affected = $this->wpdb->query($this->wpdb->prepare(
                "INSERT IGNORE INTO $table (product,value_cents,purchase_date,expires_on,code,status,created_at,updated_at)
                 VALUES (%s,%d,%s,%s,%s,'available',%s,%s)",
                $product, $valueCents, $purchase, $expires, $code, $now, $now
            ));
            $inserted += $affected ? 1 : 0;
        }
        return $inserted;
    }

    public function availableCount(string $product, \DateTimeImmutable $now): int
    {
        $table = $this->table();
        return (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE product=%s AND status='available' AND expires_on >= %s",
            $product, $now->format('Y-m-d')
        ));
    }

    /** @return array{available:int,reserved:int,issued:int,expired:int} */
    public function countsByStatus(string $product): array
    {
        $table = $this->table();
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT status, COUNT(*) c FROM $table WHERE product=%s GROUP BY status", $product
        ), ARRAY_A);
        $out = ['available' => 0, 'reserved' => 0, 'issued' => 0, 'expired' => 0];
        foreach ($rows as $r) { if (isset($out[$r['status']])) { $out[$r['status']] = (int) $r['c']; } }
        return $out;
    }

    public function getCode(int $id): ?object
    {
        $table = $this->table();
        return $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id)) ?: null;
    }
}
```

- [ ] **Step 4: Run & verify it passes**

Run: `npm run test:integration`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Inventory/CodeRepository.php tests/integration/Inventory/CodeRepositoryIntakeTest.php
git commit -m "feat: code pool intake and counts"
```

### Task 7: CodeRepository — atomic claim & lifecycle (the correctness core)

**Files:**
- Modify: `src/Inventory/CodeRepository.php`
- Create: `tests/integration/Inventory/CodeRepositoryClaimTest.php`

**Interfaces:**
- Produces (added to `CodeRepository`):
  - `claimOne(string $product, \DateTimeImmutable $now, int $reservationTtlMinutes): ?int` — reserves one available, unexpired code (FIFO by `purchase_date`,`id`) via a **row-level compare-and-swap**; returns its id, or `null` if none was claimable.
  - `markIssued(int $codeId, int $requestId, string $issuedToHash, \DateTimeImmutable $now): bool` — `reserved` → `issued`.
  - `releaseStaleReservations(\DateTimeImmutable $now): int` — `reserved` past `reserved_until` → `available`.

**Design note:** the claim is a two-statement CAS, not an explicit transaction. We SELECT a candidate id, then `UPDATE … WHERE id=? AND status='available'`. The `status='available'` guard is the atomic gate: under a race two callers may pick the same id, but only one UPDATE affects a row (`rows_affected === 1`); the loser gets `0` and returns `null`. This implements §7 of the spec, returns the claimed id, and avoids explicit transactions that would fight `WP_UnitTestCase`'s per-test transaction. Callers (IssuanceService, Task 15) retry a few times before declaring out-of-stock.

- [ ] **Step 1: Write the failing tests**

```php
<?php // tests/integration/Inventory/CodeRepositoryClaimTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Inventory;
use PortoSender\Tests\integration\PortoTestCase;
use PortoSender\Inventory\CodeRepository;

final class CodeRepositoryClaimTest extends PortoTestCase
{
    private CodeRepository $repo;
    public function set_up(): void { parent::set_up(); $this->repo = new CodeRepository($GLOBALS['wpdb']); }

    public function test_claims_oldest_first_and_never_twice(): void
    {
        $now = new \DateTimeImmutable('2026-06-24 10:00:00');
        $this->repo->addBatch('grossbrief', 180, new \DateTimeImmutable('2025-01-01'), ['OLD']);
        $this->repo->addBatch('grossbrief', 180, new \DateTimeImmutable('2026-01-01'), ['NEW']);
        $first = $this->repo->claimOne('grossbrief', $now, 30);
        $this->assertSame('OLD', $this->repo->getCode($first)->code);
        $second = $this->repo->claimOne('grossbrief', $now, 30);
        $this->assertSame('NEW', $this->repo->getCode($second)->code);
        $this->assertNull($this->repo->claimOne('grossbrief', $now, 30)); // pool drained
    }

    public function test_does_not_claim_expired(): void
    {
        $now = new \DateTimeImmutable('2026-06-24 10:00:00');
        $this->repo->addBatch('standardbrief', 95, new \DateTimeImmutable('2020-01-01'), ['EXP']); // expires 2023-12-31
        $this->assertNull($this->repo->claimOne('standardbrief', $now, 30));
    }

    public function test_mark_issued_and_release_stale(): void
    {
        $now = new \DateTimeImmutable('2026-06-24 10:00:00');
        $this->repo->addBatch('grossbrief', 180, new \DateTimeImmutable('2026-01-01'), ['X']);
        $id = $this->repo->claimOne('grossbrief', $now, 30);
        $this->assertTrue($this->repo->markIssued($id, 1, str_repeat('a', 64), $now));
        $this->assertSame('issued', $this->repo->getCode($id)->status);

        // a second code reserved then made stale
        $this->repo->addBatch('grossbrief', 180, new \DateTimeImmutable('2026-01-01'), ['Y']);
        $id2 = $this->repo->claimOne('grossbrief', $now, 30);
        $GLOBALS['wpdb']->update($GLOBALS['wpdb']->prefix . 'porto_codes',
            ['reserved_until' => '2026-06-24 09:00:00'], ['id' => $id2]);
        $this->assertSame(1, $this->repo->releaseStaleReservations($now));
        $this->assertSame('available', $this->repo->getCode($id2)->status);
    }
}
```

- [ ] **Step 2: Run & verify they fail**

Run: `npm run test:integration`
Expected: FAIL — `claimOne` undefined.

- [ ] **Step 3: Implement claim & lifecycle (append methods to `CodeRepository`)**

```php
    public function claimOne(string $product, \DateTimeImmutable $now, int $reservationTtlMinutes): ?int
    {
        $table = $this->table();
        $id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM $table
              WHERE product=%s AND status='available' AND expires_on >= %s
              ORDER BY purchase_date ASC, id ASC LIMIT 1",
            $product, $now->format('Y-m-d')
        ));
        if ($id === null) { return null; }

        $reservedUntil = $now->modify("+{$reservationTtlMinutes} minutes")->format('Y-m-d H:i:s');
        $affected = $this->wpdb->query($this->wpdb->prepare(
            "UPDATE $table SET status='reserved', reserved_until=%s, updated_at=%s
              WHERE id=%d AND status='available'",
            $reservedUntil, $now->format('Y-m-d H:i:s'), (int) $id
        ));
        return $affected === 1 ? (int) $id : null; // 0 => lost the race; caller retries
    }

    public function markIssued(int $codeId, int $requestId, string $issuedToHash, \DateTimeImmutable $now): bool
    {
        $table = $this->table();
        return 1 === $this->wpdb->query($this->wpdb->prepare(
            "UPDATE $table SET status='issued', issued_at=%s, request_id=%d, issued_to_hash=%s, updated_at=%s
              WHERE id=%d AND status='reserved'",
            $now->format('Y-m-d H:i:s'), $requestId, $issuedToHash, $now->format('Y-m-d H:i:s'), $codeId
        ));
    }

    public function releaseStaleReservations(\DateTimeImmutable $now): int
    {
        $table = $this->table();
        return (int) $this->wpdb->query($this->wpdb->prepare(
            "UPDATE $table SET status='available', reserved_until=NULL, updated_at=%s
              WHERE status='reserved' AND reserved_until < %s",
            $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')
        ));
    }
```

- [ ] **Step 4: Run & verify they pass**

Run: `npm run test:integration`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Inventory/CodeRepository.php tests/integration/Inventory/CodeRepositoryClaimTest.php
git commit -m "feat: atomic code claim with CAS, issue, and stale-reservation release"
```

### Task 8: CodeRepository — expiry maintenance

**Files:**
- Modify: `src/Inventory/CodeRepository.php`
- Create: `tests/integration/Inventory/CodeRepositoryExpiryTest.php`

**Interfaces:**
- Produces (added to `CodeRepository`):
  - `quarantineExpired(\DateTimeImmutable $now): int` — `available`/`reserved` with `expires_on < today` → `expired`.
  - `findExpiring(\DateTimeImmutable $now, int $withinMonths): array<object>` — `available` codes expiring within the window.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/integration/Inventory/CodeRepositoryExpiryTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Inventory;
use PortoSender\Tests\integration\PortoTestCase;
use PortoSender\Inventory\CodeRepository;

final class CodeRepositoryExpiryTest extends PortoTestCase
{
    private CodeRepository $repo;
    public function set_up(): void { parent::set_up(); $this->repo = new CodeRepository($GLOBALS['wpdb']); }

    public function test_quarantine_and_find_expiring(): void
    {
        $now = new \DateTimeImmutable('2026-06-24');
        $this->repo->addBatch('grossbrief', 180, new \DateTimeImmutable('2020-01-01'), ['GONE']); // expires 2023
        $this->repo->addBatch('grossbrief', 180, new \DateTimeImmutable('2023-06-01'), ['SOON']); // expires 2026-12-31
        $this->repo->addBatch('grossbrief', 180, new \DateTimeImmutable('2026-01-01'), ['FRESH']); // expires 2029

        $this->assertSame(1, $this->repo->quarantineExpired($now));
        $expiring = $this->repo->findExpiring($now, 12); // within 12 months
        $codes = array_map(static fn($r) => $r->code, $expiring);
        $this->assertContains('SOON', $codes);
        $this->assertNotContains('FRESH', $codes);
        $this->assertNotContains('GONE', $codes);
    }
}
```

- [ ] **Step 2: Run & verify it fails**

Run: `npm run test:integration`
Expected: FAIL — methods undefined.

- [ ] **Step 3: Implement (append to `CodeRepository`)**

```php
    public function quarantineExpired(\DateTimeImmutable $now): int
    {
        $table = $this->table();
        return (int) $this->wpdb->query($this->wpdb->prepare(
            "UPDATE $table SET status='expired', updated_at=%s
              WHERE status IN ('available','reserved') AND expires_on < %s",
            $now->format('Y-m-d H:i:s'), $now->format('Y-m-d')
        ));
    }

    /** @return array<object> */
    public function findExpiring(\DateTimeImmutable $now, int $withinMonths): array
    {
        $table = $this->table();
        $until = $now->modify("+{$withinMonths} months")->format('Y-m-d');
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM $table WHERE status='available' AND expires_on >= %s AND expires_on <= %s
              ORDER BY expires_on ASC",
            $now->format('Y-m-d'), $until
        )) ?: [];
    }
```

- [ ] **Step 4: Run & verify it passes**

Run: `npm run test:integration`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Inventory/CodeRepository.php tests/integration/Inventory/CodeRepositoryExpiryTest.php
git commit -m "feat: expiry quarantine and near-expiry query"
```

### Task 9: RequestRepository

**Files:**
- Create: `src/Requests/RequestRepository.php`
- Create: `tests/integration/Requests/RequestRepositoryTest.php`

**Interfaces:**
- Produces (on `RequestRepository(__construct(\wpdb $wpdb))`):
  - `createPending(array $data): int` — keys: `name,email,email_hash,name_hash,product,token_hash,ip_hash,created_at`(string). Returns insert id.
  - `findByTokenHash(string $tokenHash): ?object`, `findById(int $id): ?object`
  - `markConfirmed(int $id, \DateTimeImmutable $now): bool`
  - `markIssued(int $id, int $codeId, \DateTimeImmutable $now): bool`
  - `hasPriorRequest(?string $emailHash, ?string $nameHash): bool` — any non-`rejected` row matching either provided hash.
  - `deleteExpiredPending(\DateTimeImmutable $now, int $ttlHours): int`
  - `anonymizeOlderThan(\DateTimeImmutable $cutoff): int` — null `name`/`email` for issued rows older than cutoff.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/integration/Requests/RequestRepositoryTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Requests;
use PortoSender\Tests\integration\PortoTestCase;
use PortoSender\Requests\RequestRepository;

final class RequestRepositoryTest extends PortoTestCase
{
    private RequestRepository $repo;
    public function set_up(): void { parent::set_up(); $this->repo = new RequestRepository($GLOBALS['wpdb']); }

    private function pending(string $emailHash, string $nameHash, string $tokenHash, string $created = '2026-06-24 10:00:00'): int
    {
        return $this->repo->createPending([
            'name' => 'Max', 'email' => 'max@example.de',
            'email_hash' => $emailHash, 'name_hash' => $nameHash,
            'product' => 'grossbrief', 'token_hash' => $tokenHash, 'ip_hash' => null, 'created_at' => $created,
        ]);
    }

    public function test_create_find_confirm_issue(): void
    {
        $id = $this->pending(str_repeat('e',64), str_repeat('n',64), str_repeat('t',64));
        $this->assertSame($id, (int) $this->repo->findByTokenHash(str_repeat('t',64))->id);
        $now = new \DateTimeImmutable('2026-06-24 10:05:00');
        $this->assertTrue($this->repo->markConfirmed($id, $now));
        $this->assertTrue($this->repo->markIssued($id, 7, $now));
        $this->assertSame('issued', $this->repo->findById($id)->status);
    }

    public function test_dedup_by_either_hash(): void
    {
        $this->pending(str_repeat('e',64), str_repeat('n',64), str_repeat('t',64));
        $this->assertTrue($this->repo->hasPriorRequest(str_repeat('e',64), str_repeat('z',64)));
        $this->assertTrue($this->repo->hasPriorRequest(null, str_repeat('n',64)));
        $this->assertFalse($this->repo->hasPriorRequest(str_repeat('z',64), null));
        $this->assertFalse($this->repo->hasPriorRequest(null, null));
    }

    public function test_delete_expired_pending_and_anonymize(): void
    {
        $old = $this->pending(str_repeat('a',64), str_repeat('b',64), str_repeat('c',64), '2026-06-20 10:00:00');
        $this->assertSame(1, $this->repo->deleteExpiredPending(new \DateTimeImmutable('2026-06-24 10:00:00'), 48));

        $keep = $this->pending(str_repeat('d',64), str_repeat('f',64), str_repeat('g',64));
        $this->repo->markIssued($keep, 1, new \DateTimeImmutable('2026-01-01 10:00:00'));
        $this->assertSame(1, $this->repo->anonymizeOlderThan(new \DateTimeImmutable('2026-06-01 00:00:00')));
        $this->assertNull($this->repo->findById($keep)->email);
    }
}
```

- [ ] **Step 2: Run & verify it fails**

Run: `npm run test:integration`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `RequestRepository`**

```php
<?php
declare(strict_types=1);
namespace PortoSender\Requests;

use PortoSender\Persistence\Schema;

final class RequestRepository
{
    public function __construct(private \wpdb $wpdb) {}

    private function table(): string { return Schema::requestsTable($this->wpdb); }

    public function createPending(array $data): int
    {
        $this->wpdb->insert($this->table(), [
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'email_hash' => $data['email_hash'],
            'name_hash' => $data['name_hash'],
            'product' => $data['product'],
            'status' => 'pending',
            'token_hash' => $data['token_hash'],
            'ip_hash' => $data['ip_hash'] ?? null,
            'created_at' => $data['created_at'],
        ]);
        return (int) $this->wpdb->insert_id;
    }

    public function findByTokenHash(string $tokenHash): ?object
    {
        return $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE token_hash=%s", $tokenHash
        )) ?: null;
    }

    public function findById(int $id): ?object
    {
        return $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE id=%d", $id
        )) ?: null;
    }

    public function markConfirmed(int $id, \DateTimeImmutable $now): bool
    {
        return 1 === $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table()} SET status='confirmed', confirmed_at=%s WHERE id=%d AND status='pending'",
            $now->format('Y-m-d H:i:s'), $id
        ));
    }

    public function markIssued(int $id, int $codeId, \DateTimeImmutable $now): bool
    {
        return 1 === $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table()} SET status='issued', code_id=%d, issued_at=%s WHERE id=%d",
            $codeId, $now->format('Y-m-d H:i:s'), $id
        ));
    }

    public function hasPriorRequest(?string $emailHash, ?string $nameHash): bool
    {
        $clauses = [];
        $args = [];
        if ($emailHash !== null) { $clauses[] = 'email_hash=%s'; $args[] = $emailHash; }
        if ($nameHash !== null) { $clauses[] = 'name_hash=%s'; $args[] = $nameHash; }
        if ($clauses === []) { return false; }
        $sql = "SELECT COUNT(*) FROM {$this->table()} WHERE status<>'rejected' AND (" . implode(' OR ', $clauses) . ')';
        return (int) $this->wpdb->get_var($this->wpdb->prepare($sql, ...$args)) > 0;
    }

    public function deleteExpiredPending(\DateTimeImmutable $now, int $ttlHours): int
    {
        $cutoff = $now->modify("-{$ttlHours} hours")->format('Y-m-d H:i:s');
        return (int) $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->table()} WHERE status='pending' AND created_at < %s", $cutoff
        ));
    }

    public function anonymizeOlderThan(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table()} SET name=NULL, email=NULL
              WHERE issued_at IS NOT NULL AND issued_at < %s AND (name IS NOT NULL OR email IS NOT NULL)",
            $cutoff->format('Y-m-d H:i:s')
        ));
    }
}
```

- [ ] **Step 4: Run & verify it passes**

Run: `npm run test:integration`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Requests tests/integration/Requests
git commit -m "feat: request repository with dedup, cleanup, and anonymization"
```

### Task 10: Repository interfaces + RequestLimiter

**Files:**
- Create: `src/Inventory/CodeStore.php`, `src/Requests/RequestStore.php`
- Modify: `src/Inventory/CodeRepository.php` (declare `implements CodeStore`)
- Modify: `src/Requests/RequestRepository.php` (declare `implements RequestStore`)
- Create: `src/Limiting/RequestLimiter.php`
- Create: `tests/unit/Limiting/RequestLimiterTest.php`

**Why interfaces:** services depend on these seams so unit tests can mock with Mockery (you cannot mock a `final` concrete class). The repos are the only implementations.

**Interfaces:**
- Produces:
  - `interface CodeStore` and `interface RequestStore` — method sets exactly matching the public methods of `CodeRepository` (Tasks 6–8) and `RequestRepository` (Task 9).
  - `RequestLimiter(__construct(RequestStore $requests))` with `allow(string $mode, string $emailHash, string $nameHash): bool`.

- [ ] **Step 1: Write `src/Inventory/CodeStore.php`**

```php
<?php
declare(strict_types=1);
namespace PortoSender\Inventory;

interface CodeStore
{
    public function addBatch(string $product, int $valueCents, \DateTimeImmutable $purchaseDate, array $codes): int;
    public function availableCount(string $product, \DateTimeImmutable $now): int;
    /** @return array{available:int,reserved:int,issued:int,expired:int} */
    public function countsByStatus(string $product): array;
    public function getCode(int $id): ?object;
    public function claimOne(string $product, \DateTimeImmutable $now, int $reservationTtlMinutes): ?int;
    public function markIssued(int $codeId, int $requestId, string $issuedToHash, \DateTimeImmutable $now): bool;
    public function releaseStaleReservations(\DateTimeImmutable $now): int;
    public function quarantineExpired(\DateTimeImmutable $now): int;
    /** @return array<object> */
    public function findExpiring(\DateTimeImmutable $now, int $withinMonths): array;
}
```

- [ ] **Step 2: Write `src/Requests/RequestStore.php`**

```php
<?php
declare(strict_types=1);
namespace PortoSender\Requests;

interface RequestStore
{
    public function createPending(array $data): int;
    public function findByTokenHash(string $tokenHash): ?object;
    public function findById(int $id): ?object;
    public function markConfirmed(int $id, \DateTimeImmutable $now): bool;
    public function markIssued(int $id, int $codeId, \DateTimeImmutable $now): bool;
    public function hasPriorRequest(?string $emailHash, ?string $nameHash): bool;
    public function deleteExpiredPending(\DateTimeImmutable $now, int $ttlHours): int;
    public function anonymizeOlderThan(\DateTimeImmutable $cutoff): int;
}
```

- [ ] **Step 3: Make the repos implement the interfaces**

In `src/Inventory/CodeRepository.php` change the class line to:
```php
final class CodeRepository implements CodeStore
```
In `src/Requests/RequestRepository.php` change the class line to:
```php
final class RequestRepository implements RequestStore
```

- [ ] **Step 4: Write the failing test**

```php
<?php // tests/unit/Limiting/RequestLimiterTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Limiting;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PortoSender\Limiting\RequestLimiter;
use PortoSender\Requests\RequestStore;

final class RequestLimiterTest extends MockeryTestCase
{
    public function test_modes_query_the_expected_hashes(): void
    {
        $store = Mockery::mock(RequestStore::class);
        $store->shouldReceive('hasPriorRequest')->with('E', null)->andReturn(true);
        $store->shouldReceive('hasPriorRequest')->with(null, 'N')->andReturn(false);
        $store->shouldReceive('hasPriorRequest')->with('E', 'N')->andReturn(true);
        $limiter = new RequestLimiter($store);

        $this->assertFalse($limiter->allow('email', 'E', 'N'));        // prior email => blocked
        $this->assertTrue($limiter->allow('name', 'E', 'N'));          // no prior name => allowed
        $this->assertFalse($limiter->allow('name_or_email', 'E', 'N'));// either matches => blocked
        $this->assertTrue($limiter->allow('none', 'E', 'N'));          // never blocked
    }
}
```

- [ ] **Step 5: Run & verify it fails**

Run: `composer test:unit`
Expected: FAIL — `RequestLimiter` not found.

- [ ] **Step 6: Implement `RequestLimiter`**

```php
<?php
declare(strict_types=1);
namespace PortoSender\Limiting;

use PortoSender\Requests\RequestStore;

final class RequestLimiter
{
    public function __construct(private RequestStore $requests) {}

    public function allow(string $mode, string $emailHash, string $nameHash): bool
    {
        return match ($mode) {
            'none' => true,
            'email' => !$this->requests->hasPriorRequest($emailHash, null),
            'name' => !$this->requests->hasPriorRequest(null, $nameHash),
            default => !$this->requests->hasPriorRequest($emailHash, $nameHash), // name_or_email
        };
    }
}
```

- [ ] **Step 7: Run unit + integration suites**

Run: `composer test:unit && npm run test:integration`
Expected: both PASS (repos still satisfy their interfaces).

- [ ] **Step 8: Commit**

```bash
git add src/Inventory/CodeStore.php src/Requests/RequestStore.php src/Inventory/CodeRepository.php src/Requests/RequestRepository.php src/Limiting tests/unit/Limiting
git commit -m "feat: repository interfaces and configurable request limiter"
```

### Task 11: Settings

**Files:**
- Create: `src/Settings/Settings.php`
- Create: `tests/unit/Settings/SettingsTest.php`

**Interfaces:**
- Produces (on `Settings`):
  - `__construct(array $values = [])` (merged over `defaults()`), static `defaults(): array`, `fromOption(): self`.
  - Typed getters: `ownerAddress(): string`, `enabledProducts(): array`, `lowStockThreshold(string $product): int`, `alertEmail(): string`, `requestLimitMode(): string`, `piiRetentionDays(): int`, `captchaProvider(): string`, `altchaHmacSecret(): string`, `confirmTokenTtlHours(): int`, `reservationTtlMinutes(): int`, `expiryWarningMonths(): int`, `privacyPolicyUrl(): string`, `hashSalt(): string`.
  - `static sanitize(array $input): array`, `const OPTION = 'porto_sender_settings'`.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/unit/Settings/SettingsTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Settings;
use PHPUnit\Framework\TestCase;
use PortoSender\Settings\Settings;

final class SettingsTest extends TestCase
{
    public function test_defaults_apply_when_unset(): void
    {
        $s = new Settings();
        $this->assertSame('name_or_email', $s->requestLimitMode());
        $this->assertSame(180, $s->piiRetentionDays());
        $this->assertSame(48, $s->confirmTokenTtlHours());
        $this->assertSame(5, $s->lowStockThreshold('grossbrief'));
        $this->assertSame('altcha', $s->captchaProvider());
    }

    public function test_overrides_and_per_product_threshold(): void
    {
        $s = new Settings(['request_limit_mode' => 'email', 'low_stock_thresholds' => ['grossbrief' => 12]]);
        $this->assertSame('email', $s->requestLimitMode());
        $this->assertSame(12, $s->lowStockThreshold('grossbrief'));
        $this->assertSame(5, $s->lowStockThreshold('standardbrief')); // falls back to default
    }

    public function test_sanitize_clamps_and_whitelists(): void
    {
        $out = Settings::sanitize(['request_limit_mode' => 'bogus', 'pii_retention_days' => '-9', 'enabled_products' => ['grossbrief', 'evil']]);
        $this->assertSame('name_or_email', $out['request_limit_mode']);
        $this->assertGreaterThanOrEqual(1, $out['pii_retention_days']);
        $this->assertSame(['grossbrief'], $out['enabled_products']);
    }
}
```

- [ ] **Step 2: Run & verify it fails**

Run: `composer test:unit`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `Settings`**

```php
<?php
declare(strict_types=1);
namespace PortoSender\Settings;

final class Settings
{
    public const OPTION = 'porto_sender_settings';
    private const MODES = ['email', 'name', 'name_or_email', 'none'];
    private const PRODUCTS = ['standardbrief', 'grossbrief'];

    private array $values;

    public function __construct(array $values = [])
    {
        $this->values = array_merge(self::defaults(), $values);
    }

    public static function fromOption(): self
    {
        $stored = get_option(self::OPTION, []);
        return new self(is_array($stored) ? $stored : []);
    }

    public static function defaults(): array
    {
        return [
            'owner_address' => '',
            'enabled_products' => ['standardbrief', 'grossbrief'],
            'low_stock_thresholds' => [],
            'default_low_stock' => 5,
            'alert_email' => '',
            'request_limit_mode' => 'name_or_email',
            'pii_retention_days' => 180,
            'captcha_provider' => 'altcha',
            'altcha_hmac_secret' => '',
            'confirm_token_ttl_hours' => 48,
            'reservation_ttl_minutes' => 30,
            'expiry_warning_months' => 6,
            'privacy_policy_url' => '',
            'hash_salt' => '',
        ];
    }

    public function ownerAddress(): string { return (string) $this->values['owner_address']; }
    /** @return array<int,string> */
    public function enabledProducts(): array { return array_values((array) $this->values['enabled_products']); }
    public function lowStockThreshold(string $product): int
    {
        $map = (array) $this->values['low_stock_thresholds'];
        return (int) ($map[$product] ?? $this->values['default_low_stock']);
    }
    public function alertEmail(): string { return (string) $this->values['alert_email']; }
    public function requestLimitMode(): string { return (string) $this->values['request_limit_mode']; }
    public function piiRetentionDays(): int { return (int) $this->values['pii_retention_days']; }
    public function captchaProvider(): string { return (string) $this->values['captcha_provider']; }
    public function altchaHmacSecret(): string { return (string) $this->values['altcha_hmac_secret']; }
    public function confirmTokenTtlHours(): int { return (int) $this->values['confirm_token_ttl_hours']; }
    public function reservationTtlMinutes(): int { return (int) $this->values['reservation_ttl_minutes']; }
    public function expiryWarningMonths(): int { return (int) $this->values['expiry_warning_months']; }
    public function privacyPolicyUrl(): string { return (string) $this->values['privacy_policy_url']; }
    public function hashSalt(): string { return (string) $this->values['hash_salt']; }

    public static function sanitize(array $input): array
    {
        $d = self::defaults();
        return [
            'owner_address' => sanitize_textarea_field($input['owner_address'] ?? $d['owner_address']),
            'enabled_products' => array_values(array_intersect(self::PRODUCTS, (array) ($input['enabled_products'] ?? $d['enabled_products']))),
            'low_stock_thresholds' => array_map('absint', (array) ($input['low_stock_thresholds'] ?? [])),
            'default_low_stock' => max(0, (int) ($input['default_low_stock'] ?? $d['default_low_stock'])),
            'alert_email' => sanitize_email($input['alert_email'] ?? ''),
            'request_limit_mode' => in_array($input['request_limit_mode'] ?? '', self::MODES, true) ? $input['request_limit_mode'] : $d['request_limit_mode'],
            'pii_retention_days' => max(1, (int) ($input['pii_retention_days'] ?? $d['pii_retention_days'])),
            'captcha_provider' => in_array($input['captcha_provider'] ?? '', ['altcha', 'none'], true) ? $input['captcha_provider'] : 'altcha',
            'altcha_hmac_secret' => sanitize_text_field($input['altcha_hmac_secret'] ?? ''),
            'confirm_token_ttl_hours' => max(1, (int) ($input['confirm_token_ttl_hours'] ?? $d['confirm_token_ttl_hours'])),
            'reservation_ttl_minutes' => max(1, (int) ($input['reservation_ttl_minutes'] ?? $d['reservation_ttl_minutes'])),
            'expiry_warning_months' => max(1, (int) ($input['expiry_warning_months'] ?? $d['expiry_warning_months'])),
            'privacy_policy_url' => esc_url_raw($input['privacy_policy_url'] ?? ''),
            'hash_salt' => sanitize_text_field($input['hash_salt'] ?? ''),
        ];
    }
}
```

> The unit test only exercises constructor/getters and `sanitize`. `sanitize` calls WP functions, so its dedicated coverage runs where WP is loaded; the unit test above asserts only the branches that don't require WP (mode whitelist, clamp, product intersection) — for those, stub the WP sanitizers in `set_up()` with `Brain\Monkey\Functions\when('sanitize_textarea_field')->returnArg(1)` etc. Add those stubs in the test's `set_up()` using the `WpUnitTestCase` base introduced in Task 13, or inline `when(...)` calls.

- [ ] **Step 4: Make the Settings test use WP-function stubs**

Update `tests/unit/Settings/SettingsTest.php` to extend the brain/monkey base (created in Task 13) — if implementing Task 11 before 13, add this `set_up()` and extend `Mockery\Adapter\Phpunit\MockeryTestCase`:

```php
    protected function set_up(): void {}
    protected function setUp(): void
    {
        parent::setUp();
        \Brain\Monkey\setUp();
        \Brain\Monkey\Functions\when('sanitize_textarea_field')->returnArg(1);
        \Brain\Monkey\Functions\when('sanitize_text_field')->returnArg(1);
        \Brain\Monkey\Functions\when('sanitize_email')->returnArg(1);
        \Brain\Monkey\Functions\when('esc_url_raw')->returnArg(1);
        \Brain\Monkey\Functions\when('absint')->alias(static fn($v) => abs((int) $v));
    }
    protected function tearDown(): void { \Brain\Monkey\tearDown(); parent::tearDown(); }
```

- [ ] **Step 5: Run & verify it passes**

Run: `composer test:unit`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Settings tests/unit/Settings
git commit -m "feat: typed settings accessor with sanitization"
```

### Task 12: CAPTCHA — interface, Null, Altcha adapter

**Files:**
- Modify: `composer.json` (add `altcha-org/altcha`)
- Create: `src/Captcha/CaptchaVerifier.php`, `src/Captcha/NullVerifier.php`, `src/Captcha/AltchaVerifier.php`
- Create: `tests/unit/Captcha/NullVerifierTest.php`, `tests/unit/Captcha/AltchaVerifierTest.php`

**Interfaces:**
- Produces: `interface CaptchaVerifier { public function challenge(): array; public function verify(string $payload): bool; }`; `NullVerifier` (challenge `[]`, verify `true`); `AltchaVerifier(__construct(string $hmacSecret))`.

> **Adapter isolation note:** `AltchaVerifier` is the *only* code touching the `altcha-org/altcha` library. The fetched README example is slightly inconsistent (static vs instance API), so when implementing, open `vendor/altcha-org/altcha/README.md` and match the installed version's exact `createChallenge`/`verifySolution`/`Payload` signatures. All flow code depends on `CaptchaVerifier`, never on the library, so a signature mismatch is contained to this one class.

- [ ] **Step 1: Require the library**

Run: `composer require altcha-org/altcha`
Expected: package added (requires PHP 8.1+; we have 8.5).

- [ ] **Step 2: Write the failing tests**

```php
<?php // tests/unit/Captcha/NullVerifierTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Captcha;
use PHPUnit\Framework\TestCase;
use PortoSender\Captcha\NullVerifier;

final class NullVerifierTest extends TestCase
{
    public function test_always_passes(): void
    {
        $v = new NullVerifier();
        $this->assertSame([], $v->challenge());
        $this->assertTrue($v->verify(''));
    }
}
```

```php
<?php // tests/unit/Captcha/AltchaVerifierTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Captcha;
use PHPUnit\Framework\TestCase;
use PortoSender\Captcha\AltchaVerifier;

final class AltchaVerifierTest extends TestCase
{
    public function test_challenge_has_signature_and_garbage_fails_verification(): void
    {
        $v = new AltchaVerifier('a-test-secret');
        $challenge = $v->challenge();
        $this->assertArrayHasKey('challenge', $challenge);
        $this->assertArrayHasKey('signature', $challenge);
        $this->assertFalse($v->verify('not-a-valid-payload'));
        $this->assertFalse($v->verify(''));
    }
}
```

- [ ] **Step 3: Run & verify they fail**

Run: `composer test:unit`
Expected: FAIL — classes not found.

- [ ] **Step 4: Implement the interface + NullVerifier**

```php
<?php
declare(strict_types=1);
namespace PortoSender\Captcha;
interface CaptchaVerifier
{
    /** @return array challenge payload for the widget (JSON-serializable) */
    public function challenge(): array;
    public function verify(string $payload): bool;
}
```

```php
<?php
declare(strict_types=1);
namespace PortoSender\Captcha;
final class NullVerifier implements CaptchaVerifier
{
    public function challenge(): array { return []; }
    public function verify(string $payload): bool { return true; }
}
```

- [ ] **Step 5: Implement `AltchaVerifier`** (reconcile method names with `vendor/altcha-org/altcha/README.md`)

```php
<?php
declare(strict_types=1);
namespace PortoSender\Captcha;

use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\VerifySolutionOptions;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\Algorithm\Pbkdf2;

final class AltchaVerifier implements CaptchaVerifier
{
    private Altcha $altcha;

    public function __construct(string $hmacSecret)
    {
        $this->altcha = new Altcha(hmacSignatureSecret: $hmacSecret, hmacKeySignatureSecret: $hmacSecret);
    }

    public function challenge(): array
    {
        $challenge = $this->altcha->createChallenge(new CreateChallengeOptions(
            algorithm: new Pbkdf2(),
            cost: 100000,
            expiresAt: time() + 600,
        ));
        // Coerce to a plain array regardless of the lib's value-object shape.
        return json_decode(json_encode($challenge), true) ?: [];
    }

    public function verify(string $payload): bool
    {
        if ($payload === '') { return false; }
        try {
            $decoded = json_decode(base64_decode($payload, true) ?: $payload, true);
            if (!is_array($decoded)) { return false; }
            $result = $this->altcha->verifySolution(new VerifySolutionOptions(
                algorithm: new Pbkdf2(),
                payload: new Payload($decoded),
            ));
            return (bool) $result->verified;
        } catch (\Throwable) {
            return false;
        }
    }
}
```

- [ ] **Step 6: Run & verify they pass**

Run: `composer test:unit`
Expected: PASS. (If the Altcha class/method names differ in the installed version, fix them here and re-run — this is the expected adapter reconciliation, not a plan defect.)

- [ ] **Step 7: Commit**

```bash
git add composer.json composer.lock src/Captcha tests/unit/Captcha
git commit -m "feat: captcha verifier interface with Altcha and Null implementations"
```

### Task 13: Mailer

**Files:**
- Create: `tests/unit/WpUnitTestCase.php` (brain/monkey base)
- Create: `src/Mail/Mailer.php`
- Create: `tests/unit/Mail/MailerTest.php`

**Interfaces:**
- Consumes: `Settings`, `Postage\PostageProduct`.
- Produces (on `Mailer(__construct(Settings $settings))`):
  - `sendConfirmation(string $email, string $name, string $confirmUrl): bool`
  - `sendDelivery(string $email, string $name, string $code, PostageProduct $product): bool`
  - `sendLowStock(string $to, string $productLabel, int $remaining): bool`
  - `sendOutOfStock(string $to, string $productLabel): bool`

- [ ] **Step 1: Write the brain/monkey base `tests/unit/WpUnitTestCase.php`**

```php
<?php
declare(strict_types=1);
namespace PortoSender\Tests\unit;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Brain\Monkey;

abstract class WpUnitTestCase extends MockeryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Monkey\Functions\when('__')->returnArg(1);
        Monkey\Functions\when('esc_html__')->returnArg(1);
    }
    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }
}
```

- [ ] **Step 2: Write the failing test**

```php
<?php // tests/unit/Mail/MailerTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Mail;
use Brain\Monkey\Functions;
use PortoSender\Tests\unit\WpUnitTestCase;
use PortoSender\Mail\Mailer;
use PortoSender\Settings\Settings;
use PortoSender\Postage\PostageProduct;

final class MailerTest extends WpUnitTestCase
{
    public function test_delivery_email_contains_code_address_and_porto_prefix(): void
    {
        $captured = [];
        Functions\expect('wp_mail')->once()->andReturnUsing(function ($to, $subject, $body) use (&$captured) {
            $captured = compact('to', 'subject', 'body');
            return true;
        });
        $mailer = new Mailer(new Settings(['owner_address' => "Leo Buron\n12345 Musterstadt"]));
        $product = new PostageProduct('grossbrief', 180, 'Großbrief', 'A4 flach, bis 500 g');

        $this->assertTrue($mailer->sendDelivery('v@example.de', 'Vera', 'AB12CD34', $product));
        $this->assertSame('v@example.de', $captured['to']);
        $this->assertStringContainsString('#PORTO AB12CD34', $captured['body']);
        $this->assertStringContainsString('12345 Musterstadt', $captured['body']);
        $this->assertStringContainsString('Großbrief', $captured['body']);
    }

    public function test_confirmation_email_contains_link(): void
    {
        Functions\expect('wp_mail')->once()->with('v@example.de', \Mockery::type('string'),
            \Mockery::on(fn($body) => str_contains($body, 'https://x.test/confirm?token=abc')))->andReturn(true);
        $mailer = new Mailer(new Settings());
        $this->assertTrue($mailer->sendConfirmation('v@example.de', 'Vera', 'https://x.test/confirm?token=abc'));
    }
}
```

- [ ] **Step 3: Run & verify it fails**

Run: `composer test:unit`
Expected: FAIL — class not found.

- [ ] **Step 4: Implement `Mailer`**

```php
<?php
declare(strict_types=1);
namespace PortoSender\Mail;

use PortoSender\Settings\Settings;
use PortoSender\Postage\PostageProduct;

final class Mailer
{
    public function __construct(private Settings $settings) {}

    public function sendConfirmation(string $email, string $name, string $confirmUrl): bool
    {
        $subject = __('Bitte bestätige deine Porto-Anfrage', 'wp-porto-sender');
        $body = sprintf(
            __("Hallo %s,\n\nbitte bestätige deine Anfrage über diesen Link:\n%s\n\nWenn du das nicht warst, ignoriere diese E-Mail.", 'wp-porto-sender'),
            $name, $confirmUrl
        );
        return (bool) wp_mail($email, $subject, $body);
    }

    public function sendDelivery(string $email, string $name, string $code, PostageProduct $product): bool
    {
        $subject = __('Dein Porto-Code', 'wp-porto-sender');
        $body = sprintf(
            __("Hallo %s,\n\nhier ist dein Porto-Code für einen %s (%s):\n\n    #PORTO %s\n\nSchreibe diesen Code oben rechts auf den Umschlag (in das Frankierfeld) und sende den Brief an:\n\n%s\n\nGültig bis Ende des dritten Jahres nach dem Kauf.", 'wp-porto-sender'),
            $name, $product->label, $product->limits, $code, $this->settings->ownerAddress()
        );
        return (bool) wp_mail($email, $subject, $body);
    }

    public function sendLowStock(string $to, string $productLabel, int $remaining): bool
    {
        $subject = __('WP-Porto-Sender: Vorrat wird knapp', 'wp-porto-sender');
        $body = sprintf(__('Nur noch %d Codes für "%s" verfügbar. Bitte nachfüllen.', 'wp-porto-sender'), $remaining, $productLabel);
        return (bool) wp_mail($to, $subject, $body);
    }

    public function sendOutOfStock(string $to, string $productLabel): bool
    {
        $subject = __('WP-Porto-Sender: Vorrat erschöpft', 'wp-porto-sender');
        $body = sprintf(__('Es sind keine Codes für "%s" mehr verfügbar.', 'wp-porto-sender'), $productLabel);
        return (bool) wp_mail($to, $subject, $body);
    }
}
```

- [ ] **Step 5: Run & verify it passes**

Run: `composer test:unit`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add tests/unit/WpUnitTestCase.php src/Mail tests/unit/Mail
git commit -m "feat: mailer for confirmation, delivery, and stock alerts"
```

### Task 14: IssuanceService — submit()

**Files:**
- Create: `src/Issuance/ConfirmLinkBuilder.php`
- Create: `src/Issuance/IssuanceService.php`
- Create: `tests/unit/Issuance/IssuanceSubmitTest.php`

**Interfaces:**
- Consumes: `CaptchaVerifier`, `RequestLimiter`, `CodeStore`, `RequestStore`, `Mailer`, `Hasher`, `TokenGenerator`, `ConfirmLinkBuilder`, `Settings`, `ProductCatalog`, `Clock`.
- Produces:
  - `interface ConfirmLinkBuilder { public function build(string $token): string; }`
  - `IssuanceService::submit(array $input): array` returning `['status' => string]` where status ∈ `invalid|captcha_failed|duplicate|out_of_stock|confirmation_sent`. Input keys: `name,email,product,captcha,ip`.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/unit/Issuance/IssuanceSubmitTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Issuance;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PortoSender\Issuance\IssuanceService;
use PortoSender\Issuance\ConfirmLinkBuilder;
use PortoSender\Captcha\CaptchaVerifier;
use PortoSender\Limiting\RequestLimiter;
use PortoSender\Inventory\CodeStore;
use PortoSender\Requests\RequestStore;
use PortoSender\Mail\Mailer;
use PortoSender\Support\Hasher;
use PortoSender\Support\TokenGenerator;
use PortoSender\Support\Clock;
use PortoSender\Settings\Settings;
use PortoSender\Postage\ProductCatalog;

final class IssuanceSubmitTest extends MockeryTestCase
{
    private function service(array $mocks = []): array
    {
        $captcha = $mocks['captcha'] ?? Mockery::mock(CaptchaVerifier::class)->shouldReceive('verify')->andReturn(true)->getMock();
        $requests = $mocks['requests'] ?? Mockery::mock(RequestStore::class);
        $codes = $mocks['codes'] ?? Mockery::mock(CodeStore::class);
        $mailer = $mocks['mailer'] ?? Mockery::mock(Mailer::class);
        $limiterStore = Mockery::mock(RequestStore::class)->shouldReceive('hasPriorRequest')->andReturn(false)->getMock();
        $limiter = $mocks['limiter'] ?? new RequestLimiter($limiterStore);
        $clock = Mockery::mock(Clock::class);
        $clock->shouldReceive('now')->andReturn(new \DateTimeImmutable('2026-06-24 10:00:00'));
        $svc = new IssuanceService(
            $captcha, $limiter, $codes, $requests, $mailer,
            new Hasher('salt'), new TokenGenerator(),
            Mockery::mock(ConfirmLinkBuilder::class)->shouldReceive('build')->andReturn('https://x.test/c?token=t')->getMock(),
            new Settings(['enabled_products' => ['grossbrief']]), ProductCatalog::default(), $clock
        );
        return [$svc, compact('captcha', 'requests', 'codes', 'mailer')];
    }

    private function input(array $over = []): array
    {
        return array_merge(['name' => 'Vera', 'email' => 'v@example.de', 'product' => 'grossbrief', 'captcha' => 'x', 'ip' => '1.2.3.4'], $over);
    }

    public function test_happy_path_sends_confirmation(): void
    {
        [$svc, $m] = $this->service();
        $m['codes']->shouldReceive('availableCount')->andReturn(3);
        $m['requests']->shouldReceive('createPending')->once()->andReturn(42);
        $m['mailer']->shouldReceive('sendConfirmation')->once()->andReturn(true);
        $this->assertSame('confirmation_sent', $svc->submit($this->input())['status']);
    }

    public function test_invalid_email_rejected(): void
    {
        [$svc] = $this->service();
        $this->assertSame('invalid', $svc->submit($this->input(['email' => 'nope']))['status']);
    }

    public function test_captcha_failure(): void
    {
        $captcha = Mockery::mock(CaptchaVerifier::class)->shouldReceive('verify')->andReturn(false)->getMock();
        [$svc] = $this->service(['captcha' => $captcha]);
        $this->assertSame('captcha_failed', $svc->submit($this->input())['status']);
    }

    public function test_duplicate_blocked(): void
    {
        $limiterStore = Mockery::mock(RequestStore::class)->shouldReceive('hasPriorRequest')->andReturn(true)->getMock();
        [$svc] = $this->service(['limiter' => new RequestLimiter($limiterStore)]);
        $this->assertSame('duplicate', $svc->submit($this->input())['status']);
    }

    public function test_out_of_stock(): void
    {
        [$svc, $m] = $this->service();
        $m['codes']->shouldReceive('availableCount')->andReturn(0);
        $this->assertSame('out_of_stock', $svc->submit($this->input())['status']);
    }
}
```

- [ ] **Step 2: Run & verify it fails**

Run: `composer test:unit`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement `ConfirmLinkBuilder` interface**

```php
<?php
declare(strict_types=1);
namespace PortoSender\Issuance;
interface ConfirmLinkBuilder { public function build(string $token): string; }
```

- [ ] **Step 4: Implement `IssuanceService::submit()`**

```php
<?php
declare(strict_types=1);
namespace PortoSender\Issuance;

use PortoSender\Captcha\CaptchaVerifier;
use PortoSender\Limiting\RequestLimiter;
use PortoSender\Inventory\CodeStore;
use PortoSender\Requests\RequestStore;
use PortoSender\Mail\Mailer;
use PortoSender\Support\Hasher;
use PortoSender\Support\TokenGenerator;
use PortoSender\Support\Clock;
use PortoSender\Settings\Settings;
use PortoSender\Postage\ProductCatalog;

final class IssuanceService
{
    public function __construct(
        private CaptchaVerifier $captcha,
        private RequestLimiter $limiter,
        private CodeStore $codes,
        private RequestStore $requests,
        private Mailer $mailer,
        private Hasher $hasher,
        private TokenGenerator $tokens,
        private ConfirmLinkBuilder $links,
        private Settings $settings,
        private ProductCatalog $catalog,
        private Clock $clock,
    ) {}

    /** @return array{status:string} */
    public function submit(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $product = (string) ($input['product'] ?? '');

        $enabled = $this->settings->enabledProducts();
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($product, $enabled, true)) {
            return ['status' => 'invalid'];
        }
        if (!$this->captcha->verify((string) ($input['captcha'] ?? ''))) {
            return ['status' => 'captcha_failed'];
        }

        $emailHash = $this->hasher->email($email);
        $nameHash = $this->hasher->name($name);
        if (!$this->limiter->allow($this->settings->requestLimitMode(), $emailHash, $nameHash)) {
            return ['status' => 'duplicate'];
        }

        $now = $this->clock->now();
        if ($this->codes->availableCount($product, $now) <= 0) {
            return ['status' => 'out_of_stock'];
        }

        $token = $this->tokens->generate();
        $this->requests->createPending([
            'name' => $name, 'email' => $email,
            'email_hash' => $emailHash, 'name_hash' => $nameHash,
            'product' => $product, 'token_hash' => $this->hasher->token($token),
            'ip_hash' => isset($input['ip']) ? $this->hasher->ip((string) $input['ip']) : null,
            'created_at' => $now->format('Y-m-d H:i:s'),
        ]);
        $this->mailer->sendConfirmation($email, $name, $this->links->build($token));

        return ['status' => 'confirmation_sent'];
    }
}
```

- [ ] **Step 5: Run & verify it passes**

Run: `composer test:unit`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Issuance tests/unit/Issuance
git commit -m "feat: issuance submit flow (validate, captcha, dedup, confirmation email)"
```

### Task 15: IssuanceService — confirm()

**Files:**
- Modify: `src/Issuance/IssuanceService.php` (add `confirm()` + `MAX_CLAIM_ATTEMPTS`)
- Create: `tests/unit/Issuance/IssuanceConfirmTest.php`

**Interfaces:**
- Produces: `IssuanceService::confirm(string $token): array` returning `['status' => string]` where status ∈ `invalid_token|expired|already_issued|out_of_stock|issued`.

**Logic:** look up by token hash → reject unknown/rejected (`invalid_token`); if already `issued` → `already_issued` (idempotent); if confirm token older than `confirm_token_ttl_hours` → `expired`; mark confirmed (pending→confirmed; confirmed rows may re-attempt); **retry `claimOne` up to `MAX_CLAIM_ATTEMPTS`** to absorb the rare CAS race → `out_of_stock` if still none; `markIssued` on code + request; send delivery email; → `issued`.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/unit/Issuance/IssuanceConfirmTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Issuance;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PortoSender\Issuance\IssuanceService;
use PortoSender\Issuance\ConfirmLinkBuilder;
use PortoSender\Captcha\NullVerifier;
use PortoSender\Limiting\RequestLimiter;
use PortoSender\Inventory\CodeStore;
use PortoSender\Requests\RequestStore;
use PortoSender\Mail\Mailer;
use PortoSender\Support\Hasher;
use PortoSender\Support\TokenGenerator;
use PortoSender\Support\Clock;
use PortoSender\Settings\Settings;
use PortoSender\Postage\ProductCatalog;

final class IssuanceConfirmTest extends MockeryTestCase
{
    private Hasher $hasher;
    private function service(CodeStore $codes, RequestStore $requests, Mailer $mailer): IssuanceService
    {
        $this->hasher = new Hasher('salt');
        $clock = Mockery::mock(Clock::class);
        $clock->shouldReceive('now')->andReturn(new \DateTimeImmutable('2026-06-24 10:00:00'));
        return new IssuanceService(
            new NullVerifier(), new RequestLimiter(Mockery::mock(RequestStore::class)),
            $codes, $requests, $mailer, $this->hasher, new TokenGenerator(),
            Mockery::mock(ConfirmLinkBuilder::class), new Settings(), ProductCatalog::default(), $clock
        );
    }

    public function test_unknown_token(): void
    {
        $requests = Mockery::mock(RequestStore::class);
        $requests->shouldReceive('findByTokenHash')->andReturn(null);
        $svc = $this->service(Mockery::mock(CodeStore::class), $requests, Mockery::mock(Mailer::class));
        $this->assertSame('invalid_token', $svc->confirm('whatever')['status']);
    }

    public function test_expired_token(): void
    {
        $requests = Mockery::mock(RequestStore::class);
        $requests->shouldReceive('findByTokenHash')->andReturn((object) [
            'id' => 1, 'status' => 'pending', 'product' => 'grossbrief', 'email' => 'v@e.de',
            'name' => 'V', 'email_hash' => 'E', 'created_at' => '2026-06-20 10:00:00',
        ]);
        $svc = $this->service(Mockery::mock(CodeStore::class), $requests, Mockery::mock(Mailer::class));
        $this->assertSame('expired', $svc->confirm('t')['status']); // >48h old
    }

    public function test_happy_path_issues_code_and_emails_it(): void
    {
        $requests = Mockery::mock(RequestStore::class);
        $requests->shouldReceive('findByTokenHash')->andReturn((object) [
            'id' => 42, 'status' => 'pending', 'product' => 'grossbrief', 'email' => 'v@e.de',
            'name' => 'Vera', 'email_hash' => 'EHASH', 'created_at' => '2026-06-24 09:30:00',
        ]);
        $requests->shouldReceive('markConfirmed')->once()->andReturn(true);
        $requests->shouldReceive('markIssued')->once()->with(42, 7, Mockery::type(\DateTimeImmutable::class))->andReturn(true);
        $codes = Mockery::mock(CodeStore::class);
        $codes->shouldReceive('claimOne')->once()->andReturn(7);
        $codes->shouldReceive('markIssued')->once()->with(7, 42, 'EHASH', Mockery::type(\DateTimeImmutable::class))->andReturn(true);
        $codes->shouldReceive('getCode')->with(7)->andReturn((object) ['code' => 'AB12CD34']);
        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldReceive('sendDelivery')->once()->andReturn(true);
        $svc = $this->service($codes, $requests, $mailer);
        $this->assertSame('issued', $svc->confirm('t')['status']);
    }

    public function test_out_of_stock_when_claim_fails(): void
    {
        $requests = Mockery::mock(RequestStore::class);
        $requests->shouldReceive('findByTokenHash')->andReturn((object) [
            'id' => 42, 'status' => 'confirmed', 'product' => 'grossbrief', 'email' => 'v@e.de',
            'name' => 'Vera', 'email_hash' => 'EHASH', 'created_at' => '2026-06-24 09:30:00',
        ]);
        $requests->shouldReceive('markConfirmed')->andReturn(false); // already confirmed
        $codes = Mockery::mock(CodeStore::class);
        $codes->shouldReceive('claimOne')->times(3)->andReturn(null);
        $svc = $this->service($codes, $requests, Mockery::mock(Mailer::class));
        $this->assertSame('out_of_stock', $svc->confirm('t')['status']);
    }

    public function test_already_issued_is_idempotent(): void
    {
        $requests = Mockery::mock(RequestStore::class);
        $requests->shouldReceive('findByTokenHash')->andReturn((object) ['id' => 42, 'status' => 'issued']);
        $svc = $this->service(Mockery::mock(CodeStore::class), $requests, Mockery::mock(Mailer::class));
        $this->assertSame('already_issued', $svc->confirm('t')['status']);
    }
}
```

- [ ] **Step 2: Run & verify it fails**

Run: `composer test:unit`
Expected: FAIL — `confirm` undefined.

- [ ] **Step 3: Implement `confirm()` (append to `IssuanceService`)**

```php
    private const MAX_CLAIM_ATTEMPTS = 3;

    /** @return array{status:string} */
    public function confirm(string $token): array
    {
        $req = $this->requests->findByTokenHash($this->hasher->token($token));
        if ($req === null || in_array($req->status, ['rejected'], true)) {
            return ['status' => 'invalid_token'];
        }
        if ($req->status === 'issued') {
            return ['status' => 'already_issued'];
        }
        if (!in_array($req->status, ['pending', 'confirmed'], true)) {
            return ['status' => 'invalid_token'];
        }

        $now = $this->clock->now();
        $expiresAt = (new \DateTimeImmutable($req->created_at))->modify('+' . $this->settings->confirmTokenTtlHours() . ' hours');
        if ($now > $expiresAt) {
            return ['status' => 'expired'];
        }

        $this->requests->markConfirmed((int) $req->id, $now); // no-op if already confirmed

        $codeId = null;
        for ($i = 0; $i < self::MAX_CLAIM_ATTEMPTS; $i++) {
            $codeId = $this->codes->claimOne($req->product, $now, $this->settings->reservationTtlMinutes());
            if ($codeId !== null) { break; }
        }
        if ($codeId === null) {
            return ['status' => 'out_of_stock'];
        }

        $this->codes->markIssued($codeId, (int) $req->id, (string) $req->email_hash, $now);
        $this->requests->markIssued((int) $req->id, $codeId, $now);

        $code = $this->codes->getCode($codeId);
        $product = $this->catalog->get($req->product);
        $this->mailer->sendDelivery((string) $req->email, (string) $req->name, (string) $code->code, $product);

        return ['status' => 'issued'];
    }
```

- [ ] **Step 4: Run & verify it passes**

Run: `composer test:unit`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Issuance/IssuanceService.php tests/unit/Issuance/IssuanceConfirmTest.php
git commit -m "feat: issuance confirm flow with atomic claim retry and delivery"
```

### Task 16: StockAlerter

**Files:**
- Create: `src/Inventory/StockAlerter.php`
- Create: `tests/unit/Inventory/StockAlerterTest.php`

**Interfaces:**
- Consumes: `CodeStore`, `Settings`, `Mailer`, `ProductCatalog`, `Clock`.
- Produces: `StockAlerter::evaluate(): void` — per enabled product, debounced low-stock / out-of-stock alerts using a per-product option flag (`''`→`low`→`out`, cleared on recovery).

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/unit/Inventory/StockAlerterTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Inventory;
use Mockery;
use Brain\Monkey\Functions;
use PortoSender\Tests\unit\WpUnitTestCase;
use PortoSender\Inventory\StockAlerter;
use PortoSender\Inventory\CodeStore;
use PortoSender\Mail\Mailer;
use PortoSender\Settings\Settings;
use PortoSender\Postage\ProductCatalog;
use PortoSender\Support\Clock;

final class StockAlerterTest extends WpUnitTestCase
{
    private array $flags;
    protected function setUp(): void
    {
        parent::setUp();
        $this->flags = [];
        Functions\when('get_option')->alias(fn($k, $d = false) => $this->flags[$k] ?? $d);
        Functions\when('update_option')->alias(function ($k, $v) { $this->flags[$k] = $v; return true; });
        Functions\when('delete_option')->alias(function ($k) { unset($this->flags[$k]); return true; });
    }

    private function alerter(CodeStore $codes, Mailer $mailer): StockAlerter
    {
        $clock = Mockery::mock(Clock::class);
        $clock->shouldReceive('now')->andReturn(new \DateTimeImmutable('2026-06-24 10:00:00'));
        $settings = new Settings(['enabled_products' => ['grossbrief'], 'alert_email' => 'owner@e.de', 'default_low_stock' => 5]);
        return new StockAlerter($codes, $settings, $mailer, ProductCatalog::default(), $clock);
    }

    public function test_sends_low_stock_once_then_debounces(): void
    {
        $codes = Mockery::mock(CodeStore::class);
        $codes->shouldReceive('availableCount')->andReturn(3);
        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldReceive('sendLowStock')->once()->andReturn(true); // only once across two evaluates
        $a = $this->alerter($codes, $mailer);
        $a->evaluate();
        $a->evaluate();
        $this->assertSame('low', $this->flags['porto_sender_lowstock_grossbrief']);
    }

    public function test_out_of_stock_and_recovery(): void
    {
        $codes = Mockery::mock(CodeStore::class);
        $codes->shouldReceive('availableCount')->andReturn(0, 9); // empty then refilled
        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldReceive('sendOutOfStock')->once()->andReturn(true);
        $a = $this->alerter($codes, $mailer);
        $a->evaluate(); // out
        $this->assertSame('out', $this->flags['porto_sender_lowstock_grossbrief']);
        $a->evaluate(); // recovered -> flag cleared
        $this->assertArrayNotHasKey('porto_sender_lowstock_grossbrief', $this->flags);
    }
}
```

- [ ] **Step 2: Run & verify it fails**

Run: `composer test:unit`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `StockAlerter`**

```php
<?php
declare(strict_types=1);
namespace PortoSender\Inventory;

use PortoSender\Settings\Settings;
use PortoSender\Mail\Mailer;
use PortoSender\Postage\ProductCatalog;
use PortoSender\Support\Clock;

final class StockAlerter
{
    public function __construct(
        private CodeStore $codes,
        private Settings $settings,
        private Mailer $mailer,
        private ProductCatalog $catalog,
        private Clock $clock,
    ) {}

    public function evaluate(): void
    {
        $to = $this->settings->alertEmail();
        if ($to === '') { return; }
        $now = $this->clock->now();

        foreach ($this->settings->enabledProducts() as $key) {
            $product = $this->catalog->get($key);
            if ($product === null) { continue; }
            $available = $this->codes->availableCount($key, $now);
            $threshold = $this->settings->lowStockThreshold($key);
            $flagKey = 'porto_sender_lowstock_' . $key;
            $flag = (string) get_option($flagKey, '');

            if ($available <= 0) {
                if ($flag !== 'out') {
                    $this->mailer->sendOutOfStock($to, $product->label);
                    update_option($flagKey, 'out');
                }
            } elseif ($available <= $threshold) {
                if ($flag !== 'low' && $flag !== 'out') {
                    $this->mailer->sendLowStock($to, $product->label, $available);
                    update_option($flagKey, 'low');
                }
            } elseif ($flag !== '') {
                delete_option($flagKey);
            }
        }
    }
}
```

- [ ] **Step 4: Run & verify it passes**

Run: `composer test:unit`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Inventory/StockAlerter.php tests/unit/Inventory/StockAlerterTest.php
git commit -m "feat: debounced low-stock and out-of-stock alerter"
```

### Task 17: HTTP entry points — REST, Altcha challenge, confirm handler

**Files:**
- Create: `src/Issuance/UrlConfirmLinkBuilder.php`
- Create: `src/Rest/RestController.php`
- Create: `src/Frontend/ConfirmHandler.php`
- Create: `tests/integration/Rest/RequestFlowTest.php`

**Interfaces:**
- Produces:
  - `UrlConfirmLinkBuilder implements ConfirmLinkBuilder` — `build($token)` → `home_url('/')` with `porto_confirm=<token>`.
  - `RestController(__construct(IssuanceService $issuance, CaptchaVerifier $captcha))` with `register(): void` (hooks `rest_api_init`) registering `POST porto/v1/request` and `GET porto/v1/altcha`.
  - `ConfirmHandler(__construct(IssuanceService $issuance))` with `register(): void` (hooks `template_redirect`) and `process(string $token): string` (returns status).

- [ ] **Step 1: Write the failing end-to-end integration test**

```php
<?php // tests/integration/Rest/RequestFlowTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Rest;
use PortoSender\Tests\integration\PortoTestCase;
use PortoSender\Inventory\CodeRepository;
use PortoSender\Requests\RequestRepository;
use PortoSender\Issuance\IssuanceService;
use PortoSender\Issuance\UrlConfirmLinkBuilder;
use PortoSender\Captcha\NullVerifier;
use PortoSender\Limiting\RequestLimiter;
use PortoSender\Mail\Mailer;
use PortoSender\Support\{Hasher, TokenGenerator, SystemClock};
use PortoSender\Settings\Settings;
use PortoSender\Postage\ProductCatalog;
use PortoSender\Frontend\ConfirmHandler;

final class RequestFlowTest extends PortoTestCase
{
    private function service(): array
    {
        global $wpdb;
        $codes = new CodeRepository($wpdb);
        $requests = new RequestRepository($wpdb);
        $settings = new Settings(['enabled_products' => ['grossbrief'], 'owner_address' => 'Leo, 12345 Stadt']);
        $svc = new IssuanceService(
            new NullVerifier(), new RequestLimiter($requests), $codes, $requests,
            new Mailer($settings), new Hasher('salt'), new TokenGenerator(),
            new UrlConfirmLinkBuilder(), $settings, ProductCatalog::default(), new SystemClock()
        );
        return [$svc, $codes, $requests];
    }

    public function test_submit_then_confirm_issues_a_code(): void
    {
        [$svc, $codes, $requests] = $this->service();
        $codes->addBatch('grossbrief', 180, new \DateTimeImmutable('2026-01-01'), ['POOLCODE1']);

        // Seed a pending request with a known token (mirrors what submit() stores).
        $hasher = new Hasher('salt');
        $requests->createPending([
            'name' => 'Vera', 'email' => 'v@example.de',
            'email_hash' => $hasher->email('v@example.de'), 'name_hash' => $hasher->name('Vera'),
            'product' => 'grossbrief', 'token_hash' => $hasher->token('KNOWNTOKEN'),
            'ip_hash' => null, 'created_at' => (new SystemClock())->now()->format('Y-m-d H:i:s'),
        ]);

        $result = $svc->confirm('KNOWNTOKEN');
        $this->assertSame('issued', $result['status']);
        $this->assertSame(0, $codes->availableCount('grossbrief', new \DateTimeImmutable('now')));

        // ConfirmHandler delegates to the service.
        $handler = new ConfirmHandler($svc);
        $this->assertSame('already_issued', $handler->process('KNOWNTOKEN'));
    }

    public function test_rest_submit_creates_pending_request(): void
    {
        [$svc] = $this->service();
        $controller = new \PortoSender\Rest\RestController($svc, new NullVerifier());
        $controller->register();
        global $wp_rest_server;
        $wp_rest_server = new \WP_REST_Server();
        do_action('rest_api_init');

        $req = new \WP_REST_Request('POST', '/porto/v1/request');
        $req->set_body_params(['name' => 'Vera', 'email' => 'v@example.de', 'product' => 'grossbrief', 'captcha' => 'x']);
        $res = rest_do_request($req);
        $this->assertSame('confirmation_sent', $res->get_data()['status']);
    }
}
```

- [ ] **Step 2: Run & verify it fails**

Run: `npm run test:integration`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement `UrlConfirmLinkBuilder`**

```php
<?php
declare(strict_types=1);
namespace PortoSender\Issuance;

final class UrlConfirmLinkBuilder implements ConfirmLinkBuilder
{
    public function build(string $token): string
    {
        return add_query_arg('porto_confirm', rawurlencode($token), home_url('/'));
    }
}
```

- [ ] **Step 4: Implement `RestController`**

```php
<?php
declare(strict_types=1);
namespace PortoSender\Rest;

use PortoSender\Issuance\IssuanceService;
use PortoSender\Captcha\CaptchaVerifier;

final class RestController
{
    public const NS = 'porto/v1';

    public function __construct(private IssuanceService $issuance, private CaptchaVerifier $captcha) {}

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route(self::NS, '/request', [
                'methods' => 'POST',
                'permission_callback' => '__return_true', // public; CAPTCHA + rate limit are the gate
                'callback' => [$this, 'handleRequest'],
            ]);
            register_rest_route(self::NS, '/altcha', [
                'methods' => 'GET',
                'permission_callback' => '__return_true',
                'callback' => [$this, 'handleChallenge'],
            ]);
        });
    }

    public function handleRequest(\WP_REST_Request $request): \WP_REST_Response
    {
        $result = $this->issuance->submit([
            'name' => (string) $request->get_param('name'),
            'email' => (string) $request->get_param('email'),
            'product' => (string) $request->get_param('product'),
            'captcha' => (string) $request->get_param('captcha'),
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '',
        ]);
        $httpStatus = $result['status'] === 'confirmation_sent' ? 200 : 422;
        return new \WP_REST_Response($result, $httpStatus);
    }

    public function handleChallenge(): \WP_REST_Response
    {
        return new \WP_REST_Response($this->captcha->challenge(), 200);
    }
}
```

- [ ] **Step 5: Implement `ConfirmHandler`**

```php
<?php
declare(strict_types=1);
namespace PortoSender\Frontend;

use PortoSender\Issuance\IssuanceService;

final class ConfirmHandler
{
    private const MESSAGES = [
        'issued' => 'Dein Porto-Code wurde dir per E-Mail zugeschickt.',
        'already_issued' => 'Du hast deinen Porto-Code bereits erhalten.',
        'expired' => 'Dieser Bestätigungslink ist abgelaufen. Bitte stelle eine neue Anfrage.',
        'out_of_stock' => 'Aktuell sind keine Codes verfügbar. Bitte versuche es später erneut.',
        'invalid_token' => 'Dieser Bestätigungslink ist ungültig.',
    ];

    public function __construct(private IssuanceService $issuance) {}

    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybeHandle']);
    }

    public function maybeHandle(): void
    {
        if (!isset($_GET['porto_confirm'])) { return; }
        $status = $this->process(sanitize_text_field(wp_unslash($_GET['porto_confirm'])));
        $message = self::MESSAGES[$status] ?? self::MESSAGES['invalid_token'];
        wp_die(esc_html__($message, 'wp-porto-sender'), esc_html__('Porto-Anfrage', 'wp-porto-sender'), ['response' => 200]);
    }

    public function process(string $token): string
    {
        return $this->issuance->confirm($token)['status'];
    }
}
```

- [ ] **Step 6: Run & verify it passes**

Run: `npm run test:integration`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Issuance/UrlConfirmLinkBuilder.php src/Rest src/Frontend/ConfirmHandler.php tests/integration/Rest
git commit -m "feat: REST submit, altcha challenge, and confirm-link handler"
```

### Task 18: Front-end request form (shortcode + assets)

**Files:**
- Create: `src/Frontend/RequestForm.php`
- Create: `assets/porto-form.js`
- Create: `assets/altcha.min.js` (vendored Altcha widget — see step note)
- Create: `tests/unit/Frontend/RequestFormTest.php`

**Interfaces:**
- Consumes: `ProductCatalog`, `Settings`.
- Produces: `RequestForm(__construct(ProductCatalog $catalog, Settings $settings))` with `render(array $atts): string` (the `[porto_request]` shortcode) and `enqueueAssets(): void`.

- [ ] **Step 1: Vendor the Altcha widget**

Run: `npm install altcha` then copy its built widget to the plugin:
`cp node_modules/altcha/dist/altcha.min.js assets/altcha.min.js`
(Self-hosted — no third-party request, per DSGVO. If the path differs in the installed version, locate the built widget under `node_modules/altcha/dist/`.)

- [ ] **Step 2: Write the failing test**

```php
<?php // tests/unit/Frontend/RequestFormTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Frontend;
use Brain\Monkey\Functions;
use PortoSender\Tests\unit\WpUnitTestCase;
use PortoSender\Frontend\RequestForm;
use PortoSender\Settings\Settings;
use PortoSender\Postage\ProductCatalog;

final class RequestFormTest extends WpUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('rest_url')->alias(fn($p) => 'https://x.test/wp-json/' . $p);
    }

    public function test_renders_enabled_products_consent_and_widget(): void
    {
        $form = new RequestForm(ProductCatalog::default(), new Settings([
            'enabled_products' => ['grossbrief'], 'privacy_policy_url' => 'https://x.test/datenschutz',
        ]));
        $html = $form->render([]);
        $this->assertStringContainsString('Großbrief', $html);
        $this->assertStringNotContainsString('Standardbrief', $html); // not enabled
        $this->assertStringContainsString('altcha-widget', $html);
        $this->assertStringContainsString('name="porto_product"', $html);
        $this->assertStringContainsString('datenschutz', $html); // privacy link
        $this->assertStringContainsString('type="checkbox"', $html); // consent
    }
}
```

- [ ] **Step 3: Run & verify it fails**

Run: `composer test:unit`
Expected: FAIL — class not found.

- [ ] **Step 4: Implement `RequestForm`**

```php
<?php
declare(strict_types=1);
namespace PortoSender\Frontend;

use PortoSender\Postage\ProductCatalog;
use PortoSender\Settings\Settings;

final class RequestForm
{
    public function __construct(private ProductCatalog $catalog, private Settings $settings) {}

    public function enqueueAssets(): void
    {
        $base = plugins_url('assets/', dirname(__DIR__) . '/porto-sender.php');
        wp_enqueue_script('porto-altcha', $base . 'altcha.min.js', [], '1.0.0', true);
        wp_enqueue_script('porto-form', $base . 'porto-form.js', [], '1.0.0', true);
    }

    public function render(array $atts): string
    {
        $products = $this->catalog->enabled($this->settings->enabledProducts());
        $challengeUrl = rest_url('porto/v1/altcha');
        $restUrl = rest_url('porto/v1/request');
        $privacy = $this->settings->privacyPolicyUrl();

        ob_start(); ?>
<form class="porto-request-form" data-endpoint="<?php echo esc_attr($restUrl); ?>">
  <p><label><?php echo esc_html__('Name', 'wp-porto-sender'); ?><br>
    <input type="text" name="porto_name" required></label></p>
  <p><label><?php echo esc_html__('E-Mail', 'wp-porto-sender'); ?><br>
    <input type="email" name="porto_email" required></label></p>
  <fieldset>
    <legend><?php echo esc_html__('Was möchtest du senden?', 'wp-porto-sender'); ?></legend>
    <?php foreach ($products as $p): ?>
      <label><input type="radio" name="porto_product" value="<?php echo esc_attr($p->key); ?>" required>
        <?php echo esc_html($p->label . ' – ' . $p->limits); ?></label><br>
    <?php endforeach; ?>
  </fieldset>
  <altcha-widget challengeurl="<?php echo esc_attr($challengeUrl); ?>"></altcha-widget>
  <p><label><input type="checkbox" name="porto_consent" required>
    <?php echo esc_html__('Ich bin einverstanden, dass mein Name und meine E-Mail zur Zusendung des Codes verarbeitet werden.', 'wp-porto-sender'); ?>
    <?php if ($privacy !== ''): ?><a href="<?php echo esc_url($privacy); ?>" target="_blank"><?php echo esc_html__('Datenschutz', 'wp-porto-sender'); ?></a><?php endif; ?>
  </label></p>
  <button type="submit"><?php echo esc_html__('Porto-Code anfordern', 'wp-porto-sender'); ?></button>
  <div class="porto-message" role="status"></div>
</form>
<?php
        return (string) ob_get_clean();
    }
}
```

- [ ] **Step 5: Write `assets/porto-form.js`** (progressive submit → REST → message)

```js
document.querySelectorAll('.porto-request-form').forEach((form) => {
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const msg = form.querySelector('.porto-message');
    const altcha = form.querySelector('altcha-widget');
    const payload = {
      name: form.porto_name.value,
      email: form.porto_email.value,
      product: (form.querySelector('input[name="porto_product"]:checked') || {}).value,
      captcha: altcha ? (altcha.value || '') : '',
    };
    const res = await fetch(form.dataset.endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    const messages = {
      confirmation_sent: 'Bitte bestätige die Anfrage über den Link in deiner E-Mail.',
      duplicate: 'Du hast bereits einen Code angefordert.',
      out_of_stock: 'Aktuell sind keine Codes verfügbar.',
      captcha_failed: 'Bitte löse die Sicherheitsabfrage erneut.',
      invalid: 'Bitte fülle alle Felder korrekt aus.',
    };
    msg.textContent = messages[data.status] || 'Es ist ein Fehler aufgetreten.';
  });
});
```

- [ ] **Step 6: Run & verify it passes**

Run: `composer test:unit`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Frontend/RequestForm.php assets package.json package-lock.json tests/unit/Frontend
git commit -m "feat: front-end request form shortcode with Altcha widget"
```

### Task 19: Admin settings page

**Files:**
- Create: `src/Admin/SettingsPage.php`
- Create: `tests/unit/Admin/SettingsPageTest.php`

**Interfaces:**
- Produces: `SettingsPage::register(): void` — registers the `Settings::OPTION` setting with `Settings::sanitize` as the sanitize callback and adds the top-level admin menu; `render(): void` prints the settings form.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/unit/Admin/SettingsPageTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Admin;
use Brain\Monkey\Functions;
use PortoSender\Tests\unit\WpUnitTestCase;
use PortoSender\Admin\SettingsPage;
use PortoSender\Settings\Settings;

final class SettingsPageTest extends WpUnitTestCase
{
    public function test_registers_setting_with_sanitizer(): void
    {
        Functions\expect('register_setting')->once()->with('porto_sender', Settings::OPTION, Mockery_anyCallable());
        Functions\when('add_menu_page')->justReturn('toplevel_page_porto');
        (new SettingsPage())->registerSetting();
    }
}
function Mockery_anyCallable() { return \Mockery::on(fn($a) => is_array($a) && isset($a['sanitize_callback'])); }
```

- [ ] **Step 2: Run & verify it fails**

Run: `composer test:unit`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `SettingsPage`**

```php
<?php
declare(strict_types=1);
namespace PortoSender\Admin;

use PortoSender\Settings\Settings;
use PortoSender\Postage\ProductCatalog;

final class SettingsPage
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_init', [$this, 'registerSetting']);
    }

    public function addMenu(): void
    {
        add_menu_page(
            __('Porto-Sender', 'wp-porto-sender'), __('Porto-Sender', 'wp-porto-sender'),
            'manage_options', 'porto-sender', [$this, 'render'], 'dashicons-email-alt'
        );
    }

    public function registerSetting(): void
    {
        register_setting('porto_sender', Settings::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [Settings::class, 'sanitize'],
            'default' => Settings::defaults(),
        ]);
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) { return; }
        $s = Settings::fromOption();
        $catalog = ProductCatalog::default();
        echo '<div class="wrap"><h1>' . esc_html__('Porto-Sender – Einstellungen', 'wp-porto-sender') . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('porto_sender');
        $opt = Settings::OPTION;
        // Owner address
        printf('<p><label>%s<br><textarea name="%s[owner_address]" rows="4" cols="40">%s</textarea></label></p>',
            esc_html__('Deine Postadresse (steht in der E-Mail)', 'wp-porto-sender'), esc_attr($opt), esc_textarea($s->ownerAddress()));
        // Enabled products + per-product threshold
        echo '<fieldset><legend>' . esc_html__('Aktive Produkte & Mindestbestand', 'wp-porto-sender') . '</legend>';
        foreach ($catalog->all() as $p) {
            $checked = in_array($p->key, $s->enabledProducts(), true) ? 'checked' : '';
            printf('<p><label><input type="checkbox" name="%1$s[enabled_products][]" value="%2$s" %3$s> %4$s</label> '
                . '<input type="number" min="0" name="%1$s[low_stock_thresholds][%2$s]" value="%5$d"></p>',
                esc_attr($opt), esc_attr($p->key), $checked, esc_html($p->label), $s->lowStockThreshold($p->key));
        }
        echo '</fieldset>';
        // Dedup mode
        echo '<p><label>' . esc_html__('Begrenzung pro Person', 'wp-porto-sender') . ' ';
        echo '<select name="' . esc_attr($opt) . '[request_limit_mode]">';
        foreach (['email' => 'E-Mail', 'name' => 'Name', 'name_or_email' => 'Name oder E-Mail', 'none' => 'Keine'] as $val => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($val), selected($s->requestLimitMode(), $val, false), esc_html($label));
        }
        echo '</select></label></p>';
        // Simple text/number fields
        $fields = [
            'alert_email' => [__('Alarm-E-Mail', 'wp-porto-sender'), 'email', $s->alertEmail()],
            'pii_retention_days' => [__('Datenaufbewahrung (Tage)', 'wp-porto-sender'), 'number', $s->piiRetentionDays()],
            'altcha_hmac_secret' => [__('Altcha HMAC-Secret', 'wp-porto-sender'), 'text', $s->altchaHmacSecret()],
            'privacy_policy_url' => [__('Datenschutz-URL', 'wp-porto-sender'), 'url', $s->privacyPolicyUrl()],
        ];
        foreach ($fields as $key => [$label, $type, $value]) {
            printf('<p><label>%s<br><input type="%s" name="%s[%s]" value="%s"></label></p>',
                esc_html($label), esc_attr($type), esc_attr($opt), esc_attr($key), esc_attr((string) $value));
        }
        submit_button();
        echo '</form></div>';
    }
}
```

- [ ] **Step 4: Run & verify it passes**

Run: `composer test:unit`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Admin/SettingsPage.php tests/unit/Admin/SettingsPageTest.php
git commit -m "feat: admin settings page"
```

### Task 20: Admin code intake (paste / CSV)

**Files:**
- Create: `src/Admin/CodeIntakePage.php`
- Create: `tests/unit/Admin/CodeIntakeParserTest.php`
- Create: `tests/integration/Admin/CodeIntakeHandlerTest.php`

**Interfaces:**
- Consumes: `CodeStore`, `ProductCatalog`.
- Produces: `CodeIntakePage::parseCodes(string $raw): array` (static; splits on newlines and commas, trims, drops empties, dedupes preserving order); `handleSubmit(array $post): int` (validates nonce/cap externally; returns count added).

- [ ] **Step 1: Write the failing unit test for the parser**

```php
<?php // tests/unit/Admin/CodeIntakeParserTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Admin;
use PHPUnit\Framework\TestCase;
use PortoSender\Admin\CodeIntakePage;

final class CodeIntakeParserTest extends TestCase
{
    public function test_parses_newlines_commas_trims_and_dedupes(): void
    {
        $raw = "AB12\n CD34 ,EF56\nAB12\n\n";
        $this->assertSame(['AB12', 'CD34', 'EF56'], CodeIntakePage::parseCodes($raw));
    }
}
```

- [ ] **Step 2: Write the failing integration test for the handler**

```php
<?php // tests/integration/Admin/CodeIntakeHandlerTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Admin;
use PortoSender\Tests\integration\PortoTestCase;
use PortoSender\Admin\CodeIntakePage;
use PortoSender\Inventory\CodeRepository;
use PortoSender\Postage\ProductCatalog;

final class CodeIntakeHandlerTest extends PortoTestCase
{
    public function test_handle_submit_adds_codes(): void
    {
        global $wpdb;
        $repo = new CodeRepository($wpdb);
        $page = new CodeIntakePage($repo, ProductCatalog::default());
        $added = $page->handleSubmit([
            'product' => 'grossbrief', 'value_cents' => '180',
            'purchase_date' => '2026-06-01', 'codes' => "ONE\nTWO",
        ]);
        $this->assertSame(2, $added);
        $this->assertSame(2, $repo->availableCount('grossbrief', new \DateTimeImmutable('2026-06-24')));
    }
}
```

- [ ] **Step 3: Run & verify they fail**

Run: `composer test:unit && npm run test:integration`
Expected: FAIL — class not found.

- [ ] **Step 4: Implement `CodeIntakePage`**

```php
<?php
declare(strict_types=1);
namespace PortoSender\Admin;

use PortoSender\Inventory\CodeStore;
use PortoSender\Postage\ProductCatalog;

final class CodeIntakePage
{
    public function __construct(private CodeStore $codes, private ProductCatalog $catalog) {}

    /** @return array<int,string> */
    public static function parseCodes(string $raw): array
    {
        $parts = preg_split('/[\r\n,]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $code = trim($part);
            if ($code !== '' && !in_array($code, $out, true)) { $out[] = $code; }
        }
        return $out;
    }

    public function handleSubmit(array $post): int
    {
        $product = (string) ($post['product'] ?? '');
        if ($this->catalog->get($product) === null) { return 0; }
        $valueCents = (int) ($post['value_cents'] ?? $this->catalog->get($product)->valueCents);
        $purchase = \DateTimeImmutable::createFromFormat('Y-m-d', (string) ($post['purchase_date'] ?? ''))
            ?: new \DateTimeImmutable('now');
        return $this->codes->addBatch($product, $valueCents, $purchase, self::parseCodes((string) ($post['codes'] ?? '')));
    }

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_submenu_page('porto-sender', __('Codes hinzufügen', 'wp-porto-sender'),
                __('Codes hinzufügen', 'wp-porto-sender'), 'manage_options', 'porto-sender-intake', [$this, 'render']);
        });
        add_action('admin_post_porto_intake', function (): void {
            check_admin_referer('porto_intake');
            if (!current_user_can('manage_options')) { wp_die('forbidden'); }
            $n = $this->handleSubmit(wp_unslash($_POST));
            wp_safe_redirect(add_query_arg('porto_added', $n, admin_url('admin.php?page=porto-sender-intake')));
            exit;
        });
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) { return; }
        echo '<div class="wrap"><h1>' . esc_html__('Codes hinzufügen', 'wp-porto-sender') . '</h1>';
        if (isset($_GET['porto_added'])) {
            printf('<div class="notice notice-success"><p>%s</p></div>',
                esc_html(sprintf(__('%d Codes hinzugefügt.', 'wp-porto-sender'), (int) $_GET['porto_added'])));
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('porto_intake');
        echo '<input type="hidden" name="action" value="porto_intake">';
        echo '<p><select name="product">';
        foreach ($this->catalog->all() as $p) {
            printf('<option value="%s">%s (%d ct)</option>', esc_attr($p->key), esc_html($p->label), $p->valueCents);
        }
        echo '</select></p>';
        echo '<p><label>' . esc_html__('Kaufdatum', 'wp-porto-sender') . ' <input type="date" name="purchase_date" required></label></p>';
        echo '<p><label>' . esc_html__('Bezahlter Portowert (ct)', 'wp-porto-sender') . ' <input type="number" name="value_cents"></label></p>';
        echo '<p><textarea name="codes" rows="10" cols="40" placeholder="ein Code pro Zeile"></textarea></p>';
        submit_button(__('Hinzufügen', 'wp-porto-sender'));
        echo '</form></div>';
    }
}
```

- [ ] **Step 5: Run & verify they pass**

Run: `composer test:unit && npm run test:integration`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Admin/CodeIntakePage.php tests/unit/Admin/CodeIntakeParserTest.php tests/integration/Admin
git commit -m "feat: admin code intake via paste/CSV"
```

### Task 21: Admin dashboard (stock, claims, near-expiry, value-drift)

**Files:**
- Modify: `src/Inventory/CodeRepository.php` + `src/Inventory/CodeStore.php` (add `recentIssued`, `findBelowValue`)
- Create: `src/Admin/Dashboard.php`
- Create: `tests/integration/Admin/DashboardTest.php`

**Interfaces:**
- Produces (added to `CodeStore`/`CodeRepository`):
  - `recentIssued(int $limit): array<object>` — issued codes newest first.
  - `findBelowValue(string $product, int $minCents): array<object>` — available codes worth less than `minCents`.
- Produces (`Dashboard`): `stockSummary(): array`, `valueDrift(): array`, `nearExpiry(): array`, `claims(int $limit): array` (redacted codes), `render(): void`.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/integration/Admin/DashboardTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Admin;
use PortoSender\Tests\integration\PortoTestCase;
use PortoSender\Inventory\CodeRepository;
use PortoSender\Admin\Dashboard;
use PortoSender\Postage\ProductCatalog;
use PortoSender\Settings\Settings;

final class DashboardTest extends PortoTestCase
{
    public function test_stock_summary_and_value_drift(): void
    {
        global $wpdb;
        $repo = new CodeRepository($wpdb);
        $repo->addBatch('grossbrief', 150, new \DateTimeImmutable('2024-01-01'), ['CHEAP']); // below current 180
        $repo->addBatch('grossbrief', 180, new \DateTimeImmutable('2026-01-01'), ['OK']);
        $dash = new Dashboard($repo, ProductCatalog::default(), new Settings(['enabled_products' => ['grossbrief']]));

        $summary = $dash->stockSummary();
        $this->assertSame(2, $summary['grossbrief']['available']);

        $drift = $dash->valueDrift();
        $codes = array_map(static fn($r) => $r->code, $drift['grossbrief']);
        $this->assertSame(['CHEAP'], $codes);
    }
}
```

- [ ] **Step 2: Run & verify it fails**

Run: `npm run test:integration`
Expected: FAIL — methods/class not found.

- [ ] **Step 3: Add `recentIssued` + `findBelowValue` to `CodeStore` and `CodeRepository`**

Add to `interface CodeStore`:
```php
    /** @return array<object> */
    public function recentIssued(int $limit): array;
    /** @return array<object> */
    public function findBelowValue(string $product, int $minCents): array;
```

Append to `CodeRepository`:
```php
    /** @return array<object> */
    public function recentIssued(int $limit): array
    {
        $table = $this->table();
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM $table WHERE status='issued' ORDER BY issued_at DESC LIMIT %d", $limit
        )) ?: [];
    }

    /** @return array<object> */
    public function findBelowValue(string $product, int $minCents): array
    {
        $table = $this->table();
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM $table WHERE product=%s AND status='available' AND value_cents < %d ORDER BY value_cents ASC",
            $product, $minCents
        )) ?: [];
    }
```

- [ ] **Step 4: Implement `Dashboard`**

```php
<?php
declare(strict_types=1);
namespace PortoSender\Admin;

use PortoSender\Inventory\CodeStore;
use PortoSender\Postage\ProductCatalog;
use PortoSender\Settings\Settings;

final class Dashboard
{
    public function __construct(private CodeStore $codes, private ProductCatalog $catalog, private Settings $settings) {}

    /** @return array<string,array{available:int,reserved:int,issued:int,expired:int}> */
    public function stockSummary(): array
    {
        $out = [];
        foreach ($this->settings->enabledProducts() as $key) { $out[$key] = $this->codes->countsByStatus($key); }
        return $out;
    }

    /** @return array<string,array<object>> */
    public function valueDrift(): array
    {
        $out = [];
        foreach ($this->settings->enabledProducts() as $key) {
            $product = $this->catalog->get($key);
            if ($product !== null) { $out[$key] = $this->codes->findBelowValue($key, $product->valueCents); }
        }
        return $out;
    }

    /** @return array<object> */
    public function nearExpiry(): array
    {
        return $this->codes->findExpiring(new \DateTimeImmutable('now'), $this->settings->expiryWarningMonths());
    }

    /** @return array<array{code:string,product:string,issued_at:?string}> */
    public function claims(int $limit): array
    {
        return array_map(static fn($r) => [
            'code' => str_repeat('•', max(0, strlen($r->code) - 3)) . substr($r->code, -3),
            'product' => $r->product,
            'issued_at' => $r->issued_at,
        ], $this->codes->recentIssued($limit));
    }

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_submenu_page('porto-sender', __('Übersicht', 'wp-porto-sender'),
                __('Übersicht', 'wp-porto-sender'), 'manage_options', 'porto-sender-dashboard', [$this, 'render']);
        });
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) { return; }
        echo '<div class="wrap"><h1>' . esc_html__('Porto-Sender – Übersicht', 'wp-porto-sender') . '</h1>';
        echo '<table class="widefat"><thead><tr><th>Produkt</th><th>Verfügbar</th><th>Reserviert</th><th>Ausgegeben</th><th>Abgelaufen</th></tr></thead><tbody>';
        foreach ($this->stockSummary() as $key => $c) {
            printf('<tr><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td></tr>',
                esc_html($key), $c['available'], $c['reserved'], $c['issued'], $c['expired']);
        }
        echo '</tbody></table>';
        foreach ($this->valueDrift() as $key => $rows) {
            if ($rows === []) { continue; }
            printf('<div class="notice notice-warning"><p>%s</p></div>',
                esc_html(sprintf(__('%d "%s"-Codes liegen unter dem aktuellen Portowert.', 'wp-porto-sender'), count($rows), $key)));
        }
        echo '</div>';
    }
}
```

- [ ] **Step 5: Run & verify it passes**

Run: `composer test:unit && npm run test:integration`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Inventory/CodeStore.php src/Inventory/CodeRepository.php src/Admin/Dashboard.php tests/integration/Admin/DashboardTest.php
git commit -m "feat: admin dashboard with stock, claims, near-expiry, value-drift"
```

### Task 22: Gutenberg block (dynamic, renders the form)

**Files:**
- Create: `src/Frontend/block/block.json`
- Create: `src/Frontend/block/index.js`
- Create: `package.json` build scripts (modify), `webpack`/wp-scripts wiring
- Create: `src/Frontend/BlockRegistrar.php`
- Create: `tests/unit/Frontend/BlockRegistrarTest.php`

**Interfaces:**
- Consumes: `RequestForm`.
- Produces: `BlockRegistrar(__construct(RequestForm $form))::register(): void` registering `porto-sender/request` with a `render_callback` delegating to `RequestForm::render`.

- [ ] **Step 1: Add the build script to `package.json`**

Add to `scripts`: `"build": "wp-scripts build src/Frontend/block/index.js --output-path=build/block"` and add `@wordpress/scripts` to devDependencies (`npm install --save-dev @wordpress/scripts`).

- [ ] **Step 2: Write `src/Frontend/block/block.json`**

```json
{
  "$schema": "https://schemas.wp.org/trunk/block.json",
  "apiVersion": 3,
  "name": "porto-sender/request",
  "title": "Porto-Code anfordern",
  "category": "widgets",
  "icon": "email-alt",
  "editorScript": "file:../../build/block/index.js"
}
```

- [ ] **Step 3: Write `src/Frontend/block/index.js`** (editor shows a static placeholder; front end is server-rendered)

```js
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
registerBlockType('porto-sender/request', {
  edit: () => <p {...useBlockProps()}>Porto-Code Anforderungsformular (wird im Frontend angezeigt).</p>,
  save: () => null, // dynamic: rendered by PHP render_callback
});
```

- [ ] **Step 4: Write the failing test**

```php
<?php // tests/unit/Frontend/BlockRegistrarTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Frontend;
use Brain\Monkey\Functions;
use PortoSender\Tests\unit\WpUnitTestCase;
use PortoSender\Frontend\BlockRegistrar;
use PortoSender\Frontend\RequestForm;
use PortoSender\Settings\Settings;
use PortoSender\Postage\ProductCatalog;

final class BlockRegistrarTest extends WpUnitTestCase
{
    public function test_registers_block_with_render_callback(): void
    {
        $captured = null;
        Functions\expect('register_block_type')->once()->andReturnUsing(function ($path, $args) use (&$captured) {
            $captured = $args; return true;
        });
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('rest_url')->alias(fn($p) => 'https://x.test/' . $p);
        $form = new RequestForm(ProductCatalog::default(), new Settings(['enabled_products' => ['grossbrief']]));
        (new BlockRegistrar($form))->register();
        $this->assertIsCallable($captured['render_callback']);
        $this->assertStringContainsString('porto-request-form', ($captured['render_callback'])([], ''));
    }
}
```

- [ ] **Step 5: Run & verify it fails**

Run: `composer test:unit`
Expected: FAIL — class not found.

- [ ] **Step 6: Implement `BlockRegistrar`**

```php
<?php
declare(strict_types=1);
namespace PortoSender\Frontend;

final class BlockRegistrar
{
    public function __construct(private RequestForm $form) {}

    public function register(): void
    {
        register_block_type(__DIR__ . '/block', [
            'render_callback' => fn(array $attributes, string $content): string => $this->form->render($attributes),
        ]);
    }
}
```

- [ ] **Step 7: Build, run tests & verify pass**

Run: `npm run build && composer test:unit`
Expected: build succeeds; unit tests PASS.

- [ ] **Step 8: Commit**

```bash
git add src/Frontend/block src/Frontend/BlockRegistrar.php build/block package.json package-lock.json tests/unit/Frontend/BlockRegistrarTest.php
git commit -m "feat: dynamic Gutenberg block rendering the request form"
```

### Task 23: Cron maintenance

**Files:**
- Create: `src/Cron/Maintenance.php`
- Create: `tests/integration/Cron/MaintenanceTest.php`

**Interfaces:**
- Consumes: `CodeStore`, `RequestStore`, `StockAlerter`, `Settings`, `Clock`.
- Produces: `const Maintenance::HOOK = 'porto_sender_daily'`; `register(): void` (binds the hook to `run`); `run(): void` (release stale reservations, quarantine expired, delete expired pending, anonymize PII past retention, evaluate stock).

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/integration/Cron/MaintenanceTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Cron;
use Mockery;
use PortoSender\Tests\integration\PortoTestCase;
use PortoSender\Cron\Maintenance;
use PortoSender\Inventory\{CodeRepository, StockAlerter};
use PortoSender\Requests\RequestRepository;
use PortoSender\Mail\Mailer;
use PortoSender\Settings\Settings;
use PortoSender\Postage\ProductCatalog;
use PortoSender\Support\Clock;

final class MaintenanceTest extends PortoTestCase
{
    public function test_run_quarantines_expired_and_deletes_old_pending(): void
    {
        global $wpdb;
        $codes = new CodeRepository($wpdb);
        $requests = new RequestRepository($wpdb);
        $codes->addBatch('grossbrief', 180, new \DateTimeImmutable('2020-01-01'), ['EXP']); // expires 2023
        $requests->createPending([
            'name' => 'X', 'email' => 'x@e.de', 'email_hash' => str_repeat('a', 64), 'name_hash' => str_repeat('b', 64),
            'product' => 'grossbrief', 'token_hash' => str_repeat('c', 64), 'ip_hash' => null, 'created_at' => '2026-06-01 10:00:00',
        ]);

        $clock = Mockery::mock(Clock::class);
        $clock->shouldReceive('now')->andReturn(new \DateTimeImmutable('2026-06-24 03:00:00'));
        $settings = new Settings(['enabled_products' => ['grossbrief'], 'alert_email' => '', 'pii_retention_days' => 180]);
        $alerter = new StockAlerter($codes, $settings, new Mailer($settings), ProductCatalog::default(), $clock);

        (new Maintenance($codes, $requests, $alerter, $settings, $clock))->run();

        $this->assertSame(1, $codes->countsByStatus('grossbrief')['expired']);
        $this->assertNull($requests->findByTokenHash(str_repeat('c', 64))); // pending older than 48h deleted
    }
}
```

- [ ] **Step 2: Run & verify it fails**

Run: `npm run test:integration`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `Maintenance`**

```php
<?php
declare(strict_types=1);
namespace PortoSender\Cron;

use PortoSender\Inventory\CodeStore;
use PortoSender\Inventory\StockAlerter;
use PortoSender\Requests\RequestStore;
use PortoSender\Settings\Settings;
use PortoSender\Support\Clock;

final class Maintenance
{
    public const HOOK = 'porto_sender_daily';

    public function __construct(
        private CodeStore $codes,
        private RequestStore $requests,
        private StockAlerter $alerter,
        private Settings $settings,
        private Clock $clock,
    ) {}

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
    }

    public function run(): void
    {
        $now = $this->clock->now();
        $this->codes->releaseStaleReservations($now);
        $this->codes->quarantineExpired($now);
        $this->requests->deleteExpiredPending($now, $this->settings->confirmTokenTtlHours());
        $this->requests->anonymizeOlderThan($now->modify('-' . $this->settings->piiRetentionDays() . ' days'));
        $this->alerter->evaluate();
    }
}
```

- [ ] **Step 4: Run & verify it passes**

Run: `npm run test:integration`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Cron tests/integration/Cron
git commit -m "feat: daily cron maintenance (reservations, expiry, cleanup, anonymization, alerts)"
```

### Task 24: Plugin wiring, activation, uninstall, README

**Files:**
- Modify: `src/Plugin.php` (composition root + hook wiring + activate/deactivate)
- Create: `uninstall.php`
- Create: `README.md`
- Create: `tests/integration/ActivationTest.php`

**Interfaces:**
- Produces: `Plugin::boot(string $file)` wiring shortcode `[porto_request]`, asset enqueue, `RestController`, `ConfirmHandler`, admin pages, and the cron event; `Plugin::activate()` (schema + default options incl. generated `hash_salt` and `alert_email`, cron schedule); `Plugin::deactivate()` (unschedule).

- [ ] **Step 1: Write the failing activation test**

```php
<?php // tests/integration/ActivationTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration;
use PortoSender\Plugin;
use PortoSender\Persistence\Schema;
use PortoSender\Settings\Settings;
use PortoSender\Cron\Maintenance;

final class ActivationTest extends PortoTestCase
{
    public function test_activate_creates_tables_options_and_schedules_cron(): void
    {
        global $wpdb;
        Plugin::activate();
        $codes = Schema::codesTable($wpdb);
        $this->assertSame($codes, $wpdb->get_var("SHOW TABLES LIKE '$codes'"));
        $opt = get_option(Settings::OPTION);
        $this->assertNotSame('', $opt['hash_salt']); // generated salt
        $this->assertNotFalse(wp_next_scheduled(Maintenance::HOOK));
    }
}
```

- [ ] **Step 2: Run & verify it fails**

Run: `npm run test:integration`
Expected: FAIL — `Plugin::activate` not implemented.

- [ ] **Step 3: Implement the composition root + wiring in `src/Plugin.php`**

```php
<?php
declare(strict_types=1);

namespace PortoSender;

use PortoSender\Persistence\Schema;
use PortoSender\Settings\Settings;
use PortoSender\Postage\ProductCatalog;
use PortoSender\Support\{Hasher, TokenGenerator, SystemClock};
use PortoSender\Inventory\{CodeRepository, StockAlerter};
use PortoSender\Requests\RequestRepository;
use PortoSender\Limiting\RequestLimiter;
use PortoSender\Captcha\{AltchaVerifier, NullVerifier, CaptchaVerifier};
use PortoSender\Mail\Mailer;
use PortoSender\Issuance\{IssuanceService, UrlConfirmLinkBuilder};
use PortoSender\Rest\RestController;
use PortoSender\Frontend\{RequestForm, ConfirmHandler, BlockRegistrar};
use PortoSender\Admin\{SettingsPage, CodeIntakePage, Dashboard};
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
        return new IssuanceService(
            self::captcha($s), new RequestLimiter($requests), $codes, $requests, new Mailer($s),
            new Hasher($s->hashSalt()), new TokenGenerator(), new UrlConfirmLinkBuilder(),
            $s, ProductCatalog::default(), new SystemClock()
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
        (new ConfirmHandler($issuance))->register();
        (new BlockRegistrar($form))->register();

        $codes = new CodeRepository($wpdb);
        $requests = new RequestRepository($wpdb);
        $alerter = new StockAlerter($codes, $s, new Mailer($s), $catalog, new SystemClock());
        (new Maintenance($codes, $requests, $alerter, $s, new SystemClock()))->register();

        if (is_admin()) {
            (new SettingsPage())->register();
            (new CodeIntakePage($codes, $catalog))->register();
            (new Dashboard($codes, $catalog, $s))->register();
        }
    }

    public static function activate(): void
    {
        global $wpdb;
        Schema::install($wpdb);

        $existing = get_option(Settings::OPTION, []);
        $defaults = Settings::defaults();
        if (empty($existing['hash_salt'])) {
            $defaults['hash_salt'] = wp_generate_password(64, false, false);
        }
        if (empty($existing['alert_email'])) {
            $defaults['alert_email'] = get_option('admin_email', '');
        }
        update_option(Settings::OPTION, array_merge($defaults, is_array($existing) ? $existing : [], [
            'hash_salt' => $existing['hash_salt'] ?? $defaults['hash_salt'],
            'alert_email' => $existing['alert_email'] ?? $defaults['alert_email'],
        ]));

        if (!wp_next_scheduled(Maintenance::HOOK)) {
            wp_schedule_event(time() + 3600, 'daily', Maintenance::HOOK);
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(Maintenance::HOOK);
    }
}
```

- [ ] **Step 4: Write `uninstall.php`**

```php
<?php
declare(strict_types=1);
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }
require_once __DIR__ . '/vendor/autoload.php';

global $wpdb;
\PortoSender\Persistence\Schema::uninstall($wpdb);
delete_option(\PortoSender\Settings\Settings::OPTION);
foreach (['standardbrief', 'grossbrief'] as $p) { delete_option('porto_sender_lowstock_' . $p); }
```

- [ ] **Step 5: Write `README.md`** (setup + test instructions)

```markdown
# WP-Porto-Sender

Emails website visitors a single-use Deutsche Post *Mobile Briefmarke* code from a
pre-purchased pool so they can mail a letter to the site owner.

## Setup
1. `composer install`
2. `npm install && npm run build`
3. Vendor the Altcha widget: `cp node_modules/altcha/dist/altcha.min.js assets/altcha.min.js`
4. Activate the plugin, then under **Porto-Sender → Einstellungen** set your postal address,
   enabled products, low-stock thresholds, the Altcha HMAC secret, and privacy URL.
5. Buy Mobile Briefmarke codes in the Post & DHL App and add them under **Codes hinzufügen**.
6. Place the `[porto_request]` shortcode (or the "Porto-Code anfordern" block) on a page.

## Tests
- Unit (no Docker): `composer test:unit`
- Integration (Docker): `npm run env:start && npm run test:integration`

## How it works
See `docs/superpowers/specs/2026-06-24-wp-porto-sender-design.md` (architecture + verified
Deutsche Post constraints) and `docs/superpowers/plans/2026-06-24-wp-porto-sender.md`.
```

- [ ] **Step 6: Run all tests & verify pass**

Run: `composer test:unit && npm run test:integration`
Expected: all PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Plugin.php uninstall.php README.md tests/integration/ActivationTest.php
git commit -m "feat: plugin wiring, activation/deactivation, uninstall, and README"
```

---

## Spec Coverage (self-review)

| Spec section | Covered by |
|---|---|
| §3 two products, visitor chooses | Tasks 3, 18 (radio), 14 (validation) |
| §4 request→confirm→issue flow | Tasks 14, 15, 17 |
| §5 components | Tasks 3–24 (one component per task) |
| §6 data model (both tables) | Task 5 |
| §7 atomic claim (no double-issue) | Task 7 (CAS) + 15 (retry) |
| §8 configurable dedup + rate/captcha | Tasks 10, 11, 12, 14 |
| §9 bearer-secret security, redaction | Tasks 7/9 (server-side only), 21 (redacted claims) |
| §10 DSGVO consent + retention/anonymize | Tasks 9 (anonymize), 18 (consent), 23 (cron), 24 (retention default) |
| §11 Altcha CAPTCHA | Task 12 |
| §12 settings + intake + dashboard | Tasks 11, 19, 20, 21 |
| §13 low-stock (threshold, debounced) + out-of-stock | Task 16 |
| §14 confirmation + delivery emails | Task 13 |
| §15 error handling / idempotency / value-drift | Tasks 15 (idempotent), 16, 21 (drift) |
| §16 testing (unit + integration) | Tasks 1, 2, throughout |
| §17 stack/i18n | Tasks 1, 22, all UI tasks |

**Placeholder scan:** no `TBD`/`TODO`/"implement later" — the only deferred-detail note is the Altcha adapter reconciliation (Task 12), which is an explicit, bounded step, not a missing implementation.

**Type-consistency check:** repository method names are defined once in `CodeStore`/`RequestStore` (Task 10) and reused verbatim by services; `IssuanceService` constructor signature in Task 14 matches its use in Tasks 15 and 24; `Settings` getter names match across Tasks 11, 13, 14, 16, 19, 24.

