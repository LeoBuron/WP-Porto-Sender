# Rate Limiting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add per-IP (3/day) and global (20/hour) rate limiting to the public request form, fulfilling spec §8.

**Architecture:** A pure `RateLimiter` policy reads two time-bucketed counters through a `RateCounterStore` interface (WordPress-transient impl in prod, in-memory fake in tests). `IssuanceService::submit()` calls it after CAPTCHA and before dedup; a denial returns `rate_limited`, which `RestController` maps to HTTP 429. Thresholds live in `Settings` (admin-editable).

**Tech Stack:** PHP ≥ 8.1, PHPUnit ^11, Brain\Monkey + Mockery (unit), WP_UnitTestCase via wp-env (integration), WordPress transients.

**Design spec:** `docs/superpowers/specs/2026-06-24-rate-limiting-design.md` — read it before starting.

## Global Constraints

- **PHP ≥ 8.1**, `declare(strict_types=1);` at the top of every PHP file.
- **Autoload:** `PortoSender\` → `src/`, `PortoSender\Tests\` → `tests/` (PSR-4). File path must match namespace.
- **VCS is jj (colocated).** Commit with `jj commit -m "<msg>"` (auto-snapshots all working-copy changes; no `git add`/staging). **Never** stage or commit anything under `.superpowers/` (gitignored scratch). Append this trailer as the last line of every commit message:
  `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`
- **Unit tests** run locally: `vendor/bin/phpunit -c phpunit-unit.xml`. **Integration tests** run in wp-env (start once with `npm run env:start`): `npm run test:integration`. Single test: append `--filter <name>`.
- **Never** introduce raw IPs into storage/logs; the limiter operates only on the salted `ip_hash` from `Hasher::ip()`.
- Follow existing conventions: `final` classes, constructor property promotion, German user-facing copy, interface-per-collaborator for testability.

---

### Task 1: Settings — rate-limit keys

**Files:**
- Modify: `src/Settings/Settings.php` (defaults, accessors, sanitize)
- Test: `tests/unit/Settings/SettingsTest.php` (add tests)

**Interfaces:**
- Consumes: nothing new.
- Produces: `Settings::rateLimitEnabled(): bool`, `Settings::rateLimitPerIpDay(): int`, `Settings::rateLimitGlobalHour(): int`; option keys `rate_limit_enabled` (bool, default `true`), `rate_limit_per_ip_day` (int, default `3`), `rate_limit_global_hour` (int, default `20`).

- [ ] **Step 1: Write the failing tests**

Add to `tests/unit/Settings/SettingsTest.php` (inside the class):

```php
    public function test_rate_limit_defaults(): void
    {
        $s = new Settings();
        $this->assertTrue($s->rateLimitEnabled());
        $this->assertSame(3, $s->rateLimitPerIpDay());
        $this->assertSame(20, $s->rateLimitGlobalHour());
    }

    public function test_rate_limit_overrides(): void
    {
        $s = new Settings([
            'rate_limit_enabled' => false,
            'rate_limit_per_ip_day' => 1,
            'rate_limit_global_hour' => 50,
        ]);
        $this->assertFalse($s->rateLimitEnabled());
        $this->assertSame(1, $s->rateLimitPerIpDay());
        $this->assertSame(50, $s->rateLimitGlobalHour());
    }

    public function test_sanitize_handles_rate_limit_fields(): void
    {
        \Brain\Monkey\Functions\when('get_option')->justReturn([]);

        // Checkbox present + ints provided.
        $on = Settings::sanitize([
            'rate_limit_enabled' => '1',
            'rate_limit_per_ip_day' => '5',
            'rate_limit_global_hour' => '99',
        ]);
        $this->assertTrue($on['rate_limit_enabled']);
        $this->assertSame(5, $on['rate_limit_per_ip_day']);
        $this->assertSame(99, $on['rate_limit_global_hour']);

        // Checkbox absent => disabled; absint mirrors existing threshold handling (abs value).
        $off = Settings::sanitize(['rate_limit_per_ip_day' => '-4']);
        $this->assertFalse($off['rate_limit_enabled']);
        $this->assertSame(4, $off['rate_limit_per_ip_day']);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit -c phpunit-unit.xml --filter 'test_rate_limit_defaults|test_rate_limit_overrides|test_sanitize_handles_rate_limit_fields'`
Expected: FAIL — `Error: Call to undefined method PortoSender\Settings\Settings::rateLimitEnabled()`.

- [ ] **Step 3: Add the defaults**

In `src/Settings/Settings.php`, inside `defaults()`'s returned array, add after `'request_limit_mode' => 'name_or_email',`:

```php
            'rate_limit_enabled' => true,
            'rate_limit_per_ip_day' => 3,
            'rate_limit_global_hour' => 20,
```

- [ ] **Step 4: Add the accessors**

In `src/Settings/Settings.php`, add after the `requestLimitMode()` accessor:

```php
    public function rateLimitEnabled(): bool { return (bool) $this->values['rate_limit_enabled']; }
    public function rateLimitPerIpDay(): int { return (int) $this->values['rate_limit_per_ip_day']; }
    public function rateLimitGlobalHour(): int { return (int) $this->values['rate_limit_global_hour']; }
```

- [ ] **Step 5: Handle the keys in sanitize()**

In `src/Settings/Settings.php`, inside `sanitize()`, add before `return $result;`:

```php
        // Rate limiting (form-rendered; an absent checkbox means "off").
        $result['rate_limit_enabled'] = !empty($input['rate_limit_enabled']);
        $result['rate_limit_per_ip_day'] = absint($input['rate_limit_per_ip_day'] ?? $result['rate_limit_per_ip_day']);
        $result['rate_limit_global_hour'] = absint($input['rate_limit_global_hour'] ?? $result['rate_limit_global_hour']);
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit -c phpunit-unit.xml --filter 'test_rate_limit_defaults|test_rate_limit_overrides|test_sanitize_handles_rate_limit_fields'`
Expected: PASS (3 tests). Then run the full unit suite to confirm no regression:
Run: `vendor/bin/phpunit -c phpunit-unit.xml`
Expected: PASS (all green).

- [ ] **Step 7: Commit**

```bash
jj commit -m "feat(settings): rate-limit thresholds (enabled, per-IP/day, global/hour)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: RateCounterStore interface + TransientRateCounterStore

**Files:**
- Create: `src/Limiting/RateCounterStore.php`
- Create: `src/Limiting/TransientRateCounterStore.php`
- Test: `tests/integration/Limiting/TransientRateCounterStoreTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `interface RateCounterStore { public function hit(string $key, int $ttlSeconds): ?int; }`; `final class TransientRateCounterStore implements RateCounterStore`.

- [ ] **Step 1: Write the interface**

Create `src/Limiting/RateCounterStore.php`:

```php
<?php
declare(strict_types=1);
namespace PortoSender\Limiting;

interface RateCounterStore
{
    /**
     * Increment the counter at $key (creating it with $ttlSeconds on first hit) and return the
     * new count, or null if the underlying store could not be written. A null return means the
     * store is broken — a missing key still writes fine and returns 1.
     */
    public function hit(string $key, int $ttlSeconds): ?int;
}
```

- [ ] **Step 2: Write the failing integration test**

Create `tests/integration/Limiting/TransientRateCounterStoreTest.php`:

```php
<?php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Limiting;

use PortoSender\Tests\integration\PortoTestCase;
use PortoSender\Limiting\TransientRateCounterStore;

final class TransientRateCounterStoreTest extends PortoTestCase
{
    public function test_hit_increments_and_persists(): void
    {
        $store = new TransientRateCounterStore();
        $this->assertSame(1, $store->hit('porto_test_rl', 3600));
        $this->assertSame(2, $store->hit('porto_test_rl', 3600));
        $this->assertSame(3, $store->hit('porto_test_rl', 3600));
        $this->assertSame(3, (int) get_transient('porto_test_rl'));
    }

    public function test_separate_keys_are_independent(): void
    {
        $store = new TransientRateCounterStore();
        $this->assertSame(1, $store->hit('porto_test_a', 60));
        $this->assertSame(1, $store->hit('porto_test_b', 60));
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `npm run test:integration -- --filter TransientRateCounterStoreTest`
Expected: FAIL — `Error: Class "PortoSender\Limiting\TransientRateCounterStore" not found`.

- [ ] **Step 4: Write the implementation**

Create `src/Limiting/TransientRateCounterStore.php`:

```php
<?php
declare(strict_types=1);
namespace PortoSender\Limiting;

final class TransientRateCounterStore implements RateCounterStore
{
    public function hit(string $key, int $ttlSeconds): ?int
    {
        $current = get_transient($key);
        $next = (false === $current ? 0 : (int) $current) + 1;
        // The bucket lives in $key, so the count resets when the caller's bucket rolls over;
        // $ttlSeconds is GC only. $next always differs from $current, so set_transient never
        // returns the "value unchanged" false — a false here means a real write failure.
        return set_transient($key, $next, $ttlSeconds) ? $next : null;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `npm run test:integration -- --filter TransientRateCounterStoreTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
jj commit -m "feat(limiting): RateCounterStore interface + transient-backed impl

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: RateLimiter policy + in-memory test double

**Files:**
- Create: `src/Limiting/RateLimiter.php`
- Create: `tests/unit/Limiting/InMemoryRateCounterStore.php` (test double, reused by Task 4)
- Test: `tests/unit/Limiting/RateLimiterTest.php`

**Interfaces:**
- Consumes: `RateCounterStore` (Task 2), `Settings` rate-limit accessors (Task 1), `PortoSender\Support\Clock`.
- Produces: `final class RateLimiter` with constructor `(RateCounterStore $store, Settings $settings, Clock $clock, bool $failOpen = true)` and method `check(string $ipHash): bool`. Test double `final class InMemoryRateCounterStore implements RateCounterStore` with public `array $counts` and public `bool $broken`.

- [ ] **Step 1: Write the in-memory test double**

Create `tests/unit/Limiting/InMemoryRateCounterStore.php`:

```php
<?php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Limiting;

use PortoSender\Limiting\RateCounterStore;

final class InMemoryRateCounterStore implements RateCounterStore
{
    /** @var array<string,int> */
    public array $counts = [];
    public bool $broken = false;

    public function hit(string $key, int $ttlSeconds): ?int
    {
        if ($this->broken) {
            return null;
        }
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
        return $this->counts[$key];
    }
}
```

- [ ] **Step 2: Write the failing tests**

Create `tests/unit/Limiting/RateLimiterTest.php`:

```php
<?php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Limiting;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PortoSender\Limiting\RateLimiter;
use PortoSender\Settings\Settings;
use PortoSender\Support\Clock;

final class RateLimiterTest extends MockeryTestCase
{
    private function clock(string $t = '2026-06-24 10:00:00'): Clock
    {
        return Mockery::mock(Clock::class)
            ->shouldReceive('now')->andReturn(new \DateTimeImmutable($t))->getMock();
    }

    private function settings(array $over = []): Settings
    {
        return new Settings($over);
    }

    public function test_allows_up_to_per_ip_limit_then_denies(): void
    {
        $store = new InMemoryRateCounterStore();
        $limiter = new RateLimiter($store, $this->settings(['rate_limit_per_ip_day' => 3]), $this->clock());
        $this->assertTrue($limiter->check('IP'));   // 1
        $this->assertTrue($limiter->check('IP'));   // 2
        $this->assertTrue($limiter->check('IP'));   // 3
        $this->assertFalse($limiter->check('IP'));  // 4 > 3
    }

    public function test_disabled_always_allows_without_touching_store(): void
    {
        $store = new InMemoryRateCounterStore();
        $limiter = new RateLimiter($store, $this->settings(['rate_limit_enabled' => false]), $this->clock());
        $this->assertTrue($limiter->check('IP'));
        $this->assertSame([], $store->counts);
    }

    public function test_over_global_ceiling_denies(): void
    {
        $store = new InMemoryRateCounterStore();
        $limiter = new RateLimiter($store, $this->settings(['rate_limit_global_hour' => 2]), $this->clock());
        $this->assertTrue($limiter->check('A'));   // global 1
        $this->assertTrue($limiter->check('B'));   // global 2
        $this->assertFalse($limiter->check('C'));  // global 3 > 2
    }

    public function test_per_ip_block_does_not_consume_global(): void
    {
        $store = new InMemoryRateCounterStore();
        $limiter = new RateLimiter($store, $this->settings(['rate_limit_per_ip_day' => 1]), $this->clock());
        $this->assertTrue($limiter->check('IP'));   // perIp 1, global 1
        $this->assertFalse($limiter->check('IP'));  // perIp 2 > 1 -> deny BEFORE global
        $globalKeys = array_filter(array_keys($store->counts), fn($k) => str_starts_with($k, 'porto_rl_g_'));
        $this->assertCount(1, $globalKeys);
        $this->assertSame(1, $store->counts[array_key_first($globalKeys)]);
    }

    public function test_store_failure_fails_open_by_default(): void
    {
        $store = new InMemoryRateCounterStore();
        $store->broken = true;
        $limiter = new RateLimiter($store, $this->settings(), $this->clock());
        $this->assertTrue($limiter->check('IP'));
    }

    public function test_store_failure_fails_closed_when_configured(): void
    {
        $store = new InMemoryRateCounterStore();
        $store->broken = true;
        $limiter = new RateLimiter($store, $this->settings(), $this->clock(), failOpen: false);
        $this->assertFalse($limiter->check('IP'));
    }

    public function test_window_rollover_resets_per_ip(): void
    {
        $store = new InMemoryRateCounterStore();
        $settings = $this->settings(['rate_limit_per_ip_day' => 1]);
        $day1 = new RateLimiter($store, $settings, $this->clock('2026-06-24 10:00:00'));
        $day2 = new RateLimiter($store, $settings, $this->clock('2026-06-25 10:00:00'));
        $this->assertTrue($day1->check('IP'));   // day-1 bucket: 1
        $this->assertFalse($day1->check('IP'));  // day-1 bucket: 2 > 1
        $this->assertTrue($day2->check('IP'));   // day-2 bucket: fresh 1
    }

    public function test_bucketed_keys_have_expected_shape(): void
    {
        $store = new InMemoryRateCounterStore();
        $t = '2026-06-24 10:00:00';
        $ts = (new \DateTimeImmutable($t))->getTimestamp(); // tz-agnostic: same computation as the impl
        $limiter = new RateLimiter($store, $this->settings(), $this->clock($t));
        $limiter->check('ABC');
        $this->assertArrayHasKey('porto_rl_ip_ABC_' . intdiv($ts, 86400), $store->counts);
        $this->assertArrayHasKey('porto_rl_g_' . intdiv($ts, 3600), $store->counts);
    }
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `vendor/bin/phpunit -c phpunit-unit.xml --filter RateLimiterTest`
Expected: FAIL — `Error: Class "PortoSender\Limiting\RateLimiter" not found`.

- [ ] **Step 4: Write the implementation**

Create `src/Limiting/RateLimiter.php`:

```php
<?php
declare(strict_types=1);
namespace PortoSender\Limiting;

use PortoSender\Settings\Settings;
use PortoSender\Support\Clock;

final class RateLimiter
{
    private const DAY = 86400;
    private const HOUR = 3600;

    public function __construct(
        private RateCounterStore $store,
        private Settings $settings,
        private Clock $clock,
        private bool $failOpen = true,
    ) {}

    public function check(string $ipHash): bool
    {
        if (!$this->settings->rateLimitEnabled()) {
            return true;
        }

        $ts = $this->clock->now()->getTimestamp();

        // Per-IP first; a per-IP denial must NOT increment the global counter, or one IP could
        // exhaust the global ceiling for everyone.
        $perIpKey = 'porto_rl_ip_' . $ipHash . '_' . intdiv($ts, self::DAY);
        $perIp = $this->store->hit($perIpKey, self::DAY);
        if ($perIp === null) {
            return $this->failOpen;
        }
        if ($perIp > $this->settings->rateLimitPerIpDay()) {
            return false;
        }

        $globalKey = 'porto_rl_g_' . intdiv($ts, self::HOUR);
        $global = $this->store->hit($globalKey, self::HOUR);
        if ($global === null) {
            return $this->failOpen;
        }
        if ($global > $this->settings->rateLimitGlobalHour()) {
            return false;
        }

        return true;
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit -c phpunit-unit.xml --filter RateLimiterTest`
Expected: PASS (8 tests).

- [ ] **Step 6: Commit**

```bash
jj commit -m "feat(limiting): RateLimiter policy with time-bucketed per-IP + global counters

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Enforce in IssuanceService + RestController 429 + wiring

This task changes `IssuanceService`'s constructor, so it must atomically update **all four** call sites (production wiring + three test helpers) to keep the codebase compiling.

**Files:**
- Modify: `src/Issuance/IssuanceService.php` (constructor + `submit()`)
- Modify: `src/Rest/RestController.php` (429 mapping)
- Modify: `src/Plugin.php` (build + inject `RateLimiter`)
- Modify: `tests/unit/Issuance/IssuanceSubmitTest.php` (helper + new test)
- Modify: `tests/unit/Issuance/IssuanceConfirmTest.php` (helper)
- Modify: `tests/integration/Rest/RequestFlowTest.php` (helper + new e2e test)

**Interfaces:**
- Consumes: `RateLimiter` (Task 3), `TransientRateCounterStore` (Task 2), `SystemClock`, `InMemoryRateCounterStore` (Task 3, for unit tests).
- Produces: `IssuanceService::__construct` new signature (order below); `submit()` returns `['status' => 'rate_limited']` when blocked; `RestController` returns HTTP 429 for that status.
- **Canonical constructor order (memorize — every call site must match):**
  `(CaptchaVerifier $captcha, RequestLimiter $limiter, RateLimiter $rateLimiter, CodeStore $codes, RequestStore $requests, MailerInterface $mailer, Hasher $hasher, TokenGenerator $tokens, ConfirmLinkBuilder $links, Settings $settings, ProductCatalog $catalog, Clock $clock)` — `RateLimiter` inserted at position 3.

- [ ] **Step 1: Write the failing unit test**

In `tests/unit/Issuance/IssuanceSubmitTest.php`, add the import at the top:

```php
use PortoSender\Limiting\RateLimiter;
use PortoSender\Limiting\TransientRateCounterStore;
use PortoSender\Tests\unit\Limiting\InMemoryRateCounterStore;
```

Add this test method to the class:

```php
    public function test_rate_limited(): void
    {
        // per-IP limit 0 => the very first request is over the cap.
        [$svc, $m] = $this->service(['settings' => new Settings([
            'enabled_products' => ['grossbrief'], 'rate_limit_per_ip_day' => 0,
        ])]);
        $m['requests']->shouldNotReceive('createPending');
        $m['mailer']->shouldNotReceive('sendConfirmation');
        $this->assertSame('rate_limited', $svc->submit($this->input())['status']);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit -c phpunit-unit.xml --filter test_rate_limited`
Expected: FAIL — currently `service()` ignores `settings`/has no rate limiter, and `IssuanceService` has no rate-limit step, so the status is `confirmation_sent`, not `rate_limited` (plus the constructor arity will change in later steps).

- [ ] **Step 3: Update the IssuanceService constructor**

In `src/Issuance/IssuanceService.php`, add the import:

```php
use PortoSender\Limiting\RateLimiter;
```

Change the constructor to insert `RateLimiter $rateLimiter` at position 3:

```php
    public function __construct(
        private CaptchaVerifier $captcha,
        private RequestLimiter $limiter,
        private RateLimiter $rateLimiter,
        private CodeStore $codes,
        private RequestStore $requests,
        private MailerInterface $mailer,
        private Hasher $hasher,
        private TokenGenerator $tokens,
        private ConfirmLinkBuilder $links,
        private Settings $settings,
        private ProductCatalog $catalog,
        private Clock $clock,
    ) {}
```

- [ ] **Step 4: Add the rate-limit step to submit()**

In `src/Issuance/IssuanceService.php`, in `submit()`, immediately after the CAPTCHA block (the `if (!$this->captcha->verify(...)) { return ['status' => 'captcha_failed']; }`), insert:

```php
        if (!$this->rateLimiter->check($this->hasher->ip((string) ($input['ip'] ?? '')))) {
            return ['status' => 'rate_limited'];
        }
```

(Leave the existing `ip_hash` storage line in `createPending()` untouched — storage keeps its null-when-IP-absent semantics.)

- [ ] **Step 5: Map the status to HTTP 429 in RestController**

In `src/Rest/RestController.php`, replace the `$httpStatus` line in `handleRequest()`:

```php
        $httpStatus = match ($result['status']) {
            'confirmation_sent' => 200,
            'rate_limited' => 429,
            default => 422,
        };
```

- [ ] **Step 6: Update production wiring in Plugin.php**

In `src/Plugin.php`, change the `RequestLimiter` import line to also import the new classes:

```php
use PortoSender\Limiting\{RequestLimiter, RateLimiter, TransientRateCounterStore};
```

Replace the `issuance()` method body's `return new IssuanceService(...)` with:

```php
        return new IssuanceService(
            self::captcha($s), new RequestLimiter($requests),
            new RateLimiter(new TransientRateCounterStore(), $s, new SystemClock()),
            $codes, $requests, new Mailer($s),
            new Hasher($s->hashSalt()), new TokenGenerator(), new UrlConfirmLinkBuilder(),
            $s, ProductCatalog::default(), new SystemClock()
        );
```

- [ ] **Step 7: Update the IssuanceSubmitTest service() helper**

In `tests/unit/Issuance/IssuanceSubmitTest.php`, replace the `service()` method with this version (adds a `settings` override, builds a permissive `RateLimiter`, inserts it at position 3):

```php
    private function service(array $mocks = []): array
    {
        $captcha = $mocks['captcha'] ?? Mockery::mock(CaptchaVerifier::class)->shouldReceive('verify')->andReturn(true)->getMock();
        $requests = $mocks['requests'] ?? Mockery::mock(RequestStore::class);
        $codes = $mocks['codes'] ?? Mockery::mock(CodeStore::class);
        $mailer = $mocks['mailer'] ?? Mockery::mock(MailerInterface::class);
        $limiterStore = Mockery::mock(RequestStore::class)->shouldReceive('hasPriorRequest')->andReturn(false)->getMock();
        $limiter = $mocks['limiter'] ?? new RequestLimiter($limiterStore);
        $settings = $mocks['settings'] ?? new Settings(['enabled_products' => ['grossbrief']]);
        $clock = Mockery::mock(Clock::class);
        $clock->shouldReceive('now')->andReturn(new \DateTimeImmutable('2026-06-24 10:00:00'));
        $rateLimiter = $mocks['rateLimiter'] ?? new RateLimiter(new InMemoryRateCounterStore(), $settings, $clock);
        $svc = new IssuanceService(
            $captcha, $limiter, $rateLimiter, $codes, $requests, $mailer,
            new Hasher('salt'), new TokenGenerator(),
            Mockery::mock(ConfirmLinkBuilder::class)->shouldReceive('build')->andReturn('https://x.test/c?token=t')->getMock(),
            $settings, ProductCatalog::default(), $clock
        );
        return [$svc, compact('captcha', 'requests', 'codes', 'mailer')];
    }
```

- [ ] **Step 8: Update the IssuanceConfirmTest service() helper**

In `tests/unit/Issuance/IssuanceConfirmTest.php`, add imports:

```php
use PortoSender\Limiting\RateLimiter;
use PortoSender\Tests\unit\Limiting\InMemoryRateCounterStore;
```

Replace the `return new IssuanceService(...)` in `service()` with (inserts a permissive `RateLimiter` at position 3; `$settings`/`$clock` already exist as locals — note `confirm()` never calls the limiter, so a default `Settings` is fine):

```php
        $settings = new Settings();
        return new IssuanceService(
            new NullVerifier(), new RequestLimiter(Mockery::mock(RequestStore::class)),
            new RateLimiter(new InMemoryRateCounterStore(), $settings, $clock),
            $codes, $requests, $mailer, $this->hasher, new TokenGenerator(),
            Mockery::mock(ConfirmLinkBuilder::class), $settings, ProductCatalog::default(), $clock
        );
```

- [ ] **Step 9: Update the RequestFlowTest service() helper + add the e2e test**

In `tests/integration/Rest/RequestFlowTest.php`, add imports:

```php
use PortoSender\Limiting\RateLimiter;
use PortoSender\Limiting\TransientRateCounterStore;
```

Replace the `return new IssuanceService(...)` in `service()` with (uses the real transient store + `SystemClock`):

```php
        $svc = new IssuanceService(
            new NullVerifier(), new RequestLimiter($requests),
            new RateLimiter(new TransientRateCounterStore(), $settings, new SystemClock()),
            $codes, $requests, new Mailer($settings), new Hasher('salt'), new TokenGenerator(),
            new UrlConfirmLinkBuilder(), $settings, ProductCatalog::default(), new SystemClock()
        );
```

Add this e2e test method to the class:

```php
    public function test_rest_submit_is_rate_limited_after_per_ip_cap(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        [$svc, $codes] = $this->service(); // default settings => 3/day per IP
        $codes->addBatch('grossbrief', 180, new \DateTimeImmutable('2026-01-01'), ['RLCODE1']);
        $controller = new \PortoSender\Rest\RestController($svc, new NullVerifier());
        $controller->register();
        global $wp_rest_server;
        $wp_rest_server = new \WP_REST_Server();
        do_action('rest_api_init');

        $submit = static function () {
            $req = new \WP_REST_Request('POST', '/porto/v1/request');
            $req->set_body_params(['name' => 'Vera', 'email' => 'v@example.de', 'product' => 'grossbrief', 'captcha' => 'x']);
            return rest_do_request($req);
        };

        // Dedup ignores pending rows, so the first three all pass; the 4th trips the per-IP cap.
        $this->assertSame('confirmation_sent', $submit()->get_data()['status']);
        $this->assertSame('confirmation_sent', $submit()->get_data()['status']);
        $this->assertSame('confirmation_sent', $submit()->get_data()['status']);
        $res = $submit();
        $this->assertSame('rate_limited', $res->get_data()['status']);
        $this->assertSame(429, $res->get_status());
    }
```

- [ ] **Step 10: Run the unit suite, then the integration suite**

Run: `vendor/bin/phpunit -c phpunit-unit.xml`
Expected: PASS (all green, including `test_rate_limited` and the unchanged submit/confirm tests).

Run: `npm run test:integration`
Expected: PASS (all green, including `test_rest_submit_is_rate_limited_after_per_ip_cap`).

- [ ] **Step 11: Commit**

```bash
jj commit -m "feat(issuance): enforce rate limiting in submit, map to HTTP 429, wire it up

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Admin settings UI + front-end message

Markup/copy only. `SettingsPage::render()` is intentionally not unit-tested in this codebase (consistent with the existing untested render), and there is no JS test harness — verify both manually in wp-env. The data side (sanitize) is already covered by Task 1.

**Files:**
- Modify: `src/Admin/SettingsPage.php` (add a "Rate limiting" fieldset to `render()`)
- Modify: `assets/porto-form.js` (add the `rate_limited` message)

**Interfaces:**
- Consumes: `Settings::rateLimitEnabled/PerIpDay/GlobalHour` (Task 1).
- Produces: form fields named `porto_sender_settings[rate_limit_enabled|rate_limit_per_ip_day|rate_limit_global_hour]`.

- [ ] **Step 1: Add the fieldset to render()**

In `src/Admin/SettingsPage.php`, in `render()`, immediately before `submit_button();`, insert:

```php
        // Rate limiting
        echo '<fieldset><legend>' . esc_html__('Rate-Limiting (Missbrauchsschutz)', 'wp-porto-sender') . '</legend>';
        printf('<p><label><input type="checkbox" name="%1$s[rate_limit_enabled]" value="1" %2$s> %3$s</label></p>',
            esc_attr($opt), checked($s->rateLimitEnabled(), true, false), esc_html__('Rate-Limiting aktiv', 'wp-porto-sender'));
        printf('<p><label>%2$s<br><input type="number" min="0" name="%1$s[rate_limit_per_ip_day]" value="%3$d"></label></p>',
            esc_attr($opt), esc_html__('Max. Anfragen pro IP/Tag', 'wp-porto-sender'), $s->rateLimitPerIpDay());
        printf('<p><label>%2$s<br><input type="number" min="0" name="%1$s[rate_limit_global_hour]" value="%3$d"></label></p>',
            esc_attr($opt), esc_html__('Max. Anfragen gesamt/Stunde', 'wp-porto-sender'), $s->rateLimitGlobalHour());
        echo '</fieldset>';
```

- [ ] **Step 2: Add the front-end message**

In `assets/porto-form.js`, add to the `messages` object (after the `invalid:` line):

```js
      rate_limited: 'Zu viele Anfragen. Bitte versuche es später erneut.',
```

- [ ] **Step 3: Verify manually in wp-env**

Run: `npm run env:start` (if not already running), then open `http://localhost:8888/wp-admin/` → Porto-Sender settings.
Expected: a "Rate-Limiting (Missbrauchsschutz)" fieldset with a checked "aktiv" box and two number inputs showing `3` and `20`. Save with a changed value (e.g. per-IP `5`) and reload → the value persists (proves the field name + sanitize round-trip).

Then confirm the JS asset is unminified/static (no build needed): `assets/porto-form.js` is enqueued directly, so the edit is live after a hard refresh. There is no automated test for this step.

- [ ] **Step 4: Run both suites (no regressions)**

Run: `vendor/bin/phpunit -c phpunit-unit.xml`
Expected: PASS.
Run: `npm run test:integration`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
jj commit -m "feat(admin): rate-limit settings fieldset + front-end rate_limited message

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage** (each spec section → task):
- §3 thresholds config (Admin UI) → Task 1 (storage) + Task 5 (UI). ✓
- §3 per-IP 3/day, global 20/hour, fixed bucketed window → Task 3. ✓
- §3 placement after CAPTCHA before dedup → Task 4 Step 4. ✓
- §3 fail-mode constructor flag (default open) → Task 3 (`failOpen`), tested both branches. ✓
- §4 RateCounterStore + TransientRateCounterStore + RateLimiter → Tasks 2, 3. ✓
- §5 data flow / per-IP-first short-circuit / bucketed keys → Task 3 impl + tests. ✓
- §6 three settings keys + accessors + sanitize → Task 1. ✓
- §7 `rate_limited` status + 429 + JS message → Task 4 (status/429) + Task 5 (JS). ✓
- §8 wiring in Plugin.php → Task 4 Step 6. ✓
- §9 testing strategy → Tasks 1–4 tests. ✓

**Placeholder scan:** No TBD/TODO; every code step shows complete code; commands have expected output. ✓

**Type consistency:** `hit(string, int): ?int` used identically in Tasks 2, 3, 4. `check(string): bool` used in Tasks 3, 4. Constructor order `(captcha, limiter, rateLimiter, codes, requests, mailer, hasher, tokens, links, settings, catalog, clock)` is identical across IssuanceService + all four call sites in Task 4. Settings accessor names `rateLimitEnabled/PerIpDay/GlobalHour` consistent across Tasks 1, 3, 5. ✓
