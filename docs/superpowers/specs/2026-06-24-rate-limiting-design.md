# Rate limiting for the public request form (spec §8)

**Status:** approved (brainstorming complete, 2026-06-24)
**Implements:** spec §8 — *"CAPTCHA (Altcha) + rate limiting (by `ip_hash` and a global ceiling) gate the public form."*
**Parent spec:** `docs/superpowers/specs/2026-06-24-wp-porto-sender-design.md`

## 1. Problem

The public request endpoint (`POST porto/v1/request` → `IssuanceService::submit()`) is gated
today by CAPTCHA (Altcha PoW), dedup (`request_limit_mode`), and the hard pool-size cap. The
spec §8 rate-limiting requirement is **not implemented**: `ip_hash` is computed (`Hasher::ip()`)
and stored on each `porto_requests` row for "abuse audit", but nothing ever reads it back, and
there is no global ceiling. A scripted client that solves Altcha can therefore drive the pipeline
(pending rows + confirmation emails) as fast as it can issue requests, bounded only by the pool
size and Altcha's per-request PoW cost.

This feature adds a time-windowed rate limiter — **per-IP** and **global** — as an additional gate.

### Rate limiting vs. dedup (not the same thing)

These are orthogonal and must not be conflated:

- **Dedup** (`RequestLimiter`, `request_limit_mode`) — a *permanent* business rule keyed on
  salted name/email hashes: "one code per person, ever." Unchanged by this work.
- **Rate limiting** (this spec) — *transient, time-windowed* abuse control keyed on `ip_hash`
  plus a global counter: "not too many requests per IP per day / site-wide per hour."

## 2. Goals / non-goals

**Goals**
- Throttle per-IP request volume and enforce a site-wide hourly ceiling on the public form.
- Admin-configurable thresholds with an on/off master toggle.
- Reuse the existing salted `ip_hash` so the limiter never holds raw PII (DSGVO-clean).
- Full unit + integration coverage, TDD.

**Non-goals (this iteration)**
- Sliding-window accuracy (a clock-aligned fixed window is sufficient for a backstop; it permits
  a brief burst across the window boundary — accepted).
- Configurable window *units* (fixed: per-IP = day, global = hour).
- Trusting `X-Forwarded-For` / proxy headers (spoofable; would let an attacker bypass the
  per-IP cap). Source stays `$_SERVER['REMOTE_ADDR']`.
- Atomic cross-request increments (read-then-write may over-count by a hair under burst —
  acceptable for an abuse backstop, never for accounting).

## 3. Decisions (locked)

| Decision | Choice | Rationale |
|---|---|---|
| Thresholds config | **Admin Settings UI** | Discoverable for the non-developer site owner. |
| Per-IP limit | **3 requests / 24 h** | Dedup already caps one-code-per-person forever, so the per-IP cap is a pure burst backstop. `3` absorbs CGNAT/DS-Lite *stranger* collisions (mobile + Vodafone-cable users share public IPv4) before a legit visitor is blocked. |
| Global ceiling | **20 requests / 1 h** | Low-volume site ("we don't have a lot of porto") → a tight site-wide brake caps mail volume and premature pool drain even from a botnet of distinct IPs. |
| Window type | **Clock-aligned fixed window via time-bucketed keys** | The window boundary is encoded in the key (`…_<floor(now/window)>`), so the count resets deterministically at each server-time day/hour boundary. The transient TTL is *garbage-collection only* — it must NOT be relied on for the reset, because `set_transient()` pushes the expiry forward on every write (a naive shared key would be a never-closing sliding window that permanently over-blocks a busy site). |
| Placement | **After CAPTCHA, before dedup** | Counting only PoW-paid requests means an attacker cannot cheaply burn a shared-IP victim's quota to grief them — every increment costs an Altcha solve. CAPTCHA-failing floods never reach the pool/mail, so they need no counter. |
| Fail mode (store unavailable) | **Fail-open, both counters** (constructor-parameterized, default fail-open; **not** admin-exposed) | Conventional for rate limiters; avoids turning a cache outage into a self-inflicted form outage. Downside is contained: Altcha PoW + dedup + the **hard pool cap (no overspend ever)** still gate. Only fires on object-cache-backed sites; on DB-backed transients a store-write failure means the later `createPending()` fails too. A constructor flag (`failOpen = true`) keeps the flip to fail-closed-both a one-line wiring change and lets both branches be unit-tested. |
| IP source | **`REMOTE_ADDR` only** | No proxy-header trust (see non-goals). |

## 4. Architecture

Three new units in `src/Limiting/`, mirroring the codebase's interface-per-collaborator
pattern (`CodeStore`, `RequestStore`, `MailerInterface`) so the policy is unit-testable with a
fake store and no WordPress calls.

| File | Responsibility | Depends on |
|---|---|---|
| `RateCounterStore` (interface) | `hit(string $key, int $ttlSeconds): ?int` — increment the counter at `$key`, return the **new** count, or `null` if the underlying store could not be written. | — |
| `TransientRateCounterStore` | WordPress implementation: `get_transient` → `+1` → `set_transient($key, $n, $ttl)`. Returns the new count on a successful `set`, else `null`. A dumb get/incr/set — it does NOT compute windows; the bucket lives in the key the limiter passes, and `$ttl` is GC only. | WP transient API |
| `RateLimiter` | Pure policy: `check(string $ipHash): bool`. Computes the time-bucketed keys from `$clock->now()`, applies the enable toggle, both counters, the limits, and the fail mode. Constructor: `(RateCounterStore $store, Settings $settings, Clock $clock, bool $failOpen = true)`. No WP calls. | `RateCounterStore`, `Settings`, `Clock` |

**`null` disambiguates "down" from "empty":** a missing key still writes successfully and returns
`1`, so a `null` return can *only* mean the store write failed. The limiter branches on `null`
to apply the fail mode — no separate health-probe needed.

## 5. Data flow

`IssuanceService::submit()` gains a `RateLimiter` dependency and one new step:

```
validate input → CAPTCHA verify → [RATE LIMIT] → dedup → stock check → create pending + send confirmation
```

`RateLimiter::check(string $ipHash): bool` — **per-IP first, short-circuit before global:**

Let `ts = $clock->now()->getTimestamp()`, `dayBucket = intdiv(ts, 86400)`, `hourBucket = intdiv(ts, 3600)`.

1. If `rate_limit_enabled` is false → return `true` (allow). The store is never touched.
2. `perIp = store.hit("porto_rl_ip_" . $ipHash . "_" . dayBucket, 86400)`.
   - `perIp === null` (store write failed) → return `$failOpen`.
   - `perIp > perIpLimit` → return `false` (deny) **without touching the global counter** (see note).
3. `global = store.hit("porto_rl_g_" . hourBucket, 3600)`.
   - `global === null` → return `$failOpen`.
   - `global > globalLimit` → return `false` (deny).
4. Return `true` (allow).

Notes:
- **Time-bucketed keys, not TTL, drive the window reset.** The `_<bucket>` suffix changes at each
  server-time day/hour boundary, so each window gets a fresh key counting from zero. The TTL
  (86400 / 3600) is only there to garbage-collect abandoned buckets.
- **Per-IP must be checked and incremented first, and a per-IP denial must return immediately
  without incrementing the global counter.** Otherwise a single abusive IP's *blocked* attempts
  would keep inflating the global tally until it hits the ceiling — letting one IP deny the form
  for everyone (a global DoS). Only per-IP-*passing* requests are allowed to consume global quota.
- **Increment-then-compare.** With `perIpLimit = 3`, hits 1–3 return counts 1,2,3 (all allowed),
  hit 4 returns 4 → denied. A request that passes per-IP but trips the global ceiling has already
  spent 1 of its own per-IP allowance — a harmless self-over-count that resets at the next bucket.
- **IP-less requests** (`REMOTE_ADDR` absent, e.g. CLI/edge): `Hasher::ip('')` yields a single
  shared bucket; all such requests share one per-IP counter. Rare; acceptable.
- The limiter receives the **already-salted `ip_hash`**, computed once in `submit()` via
  `Hasher::ip((string) ($input['ip'] ?? ''))`. It never sees a raw IP. **Storage is unchanged:**
  the `ip_hash` written to the `porto_requests` row keeps its current null-when-IP-absent
  semantics; the limiter's empty-IP shared bucket is computed independently and not persisted.

`submit()` returns `['status' => 'rate_limited']` when `check()` is false.

## 6. Configuration

Three new keys on `Settings` (`porto_sender_settings` option):

| Key | Type | Default | Accessor |
|---|---|---|---|
| `rate_limit_enabled` | bool | `true` | `rateLimitEnabled(): bool` |
| `rate_limit_per_ip_day` | int | `3` | `rateLimitPerIpDay(): int` |
| `rate_limit_global_hour` | int | `20` | `rateLimitGlobalHour(): int` |

- `defaults()` gains the three keys.
- `sanitize()` overwrites them following the existing "only touch form-rendered keys" rule:
  `rate_limit_enabled` cast to bool (checkbox: absent = unchecked = `false`);
  the two ints clamped `≥ 0` via `absint`.
- `SettingsPage::render()` gains a **"Rate limiting"** fieldset: enable checkbox + two number inputs.

A limit of `0` means "block everything" (an explicit, valid admin choice); disabling is done via
the toggle, not by zeroing.

## 7. Error handling / responses

- `IssuanceService::submit()` → `['status' => 'rate_limited']`.
- `RestController::handleRequest()` maps `rate_limited` → **HTTP 429**; every other non-success
  status keeps its current 422.
- `assets/porto-form.js` gets a user-facing message for `rate_limited`:
  *"Too many requests — please try again later."*

## 8. Wiring

`Plugin.php` composition root builds the store + limiter and injects the limiter into
`IssuanceService`:

```php
$rateLimiter = new RateLimiter(new TransientRateCounterStore(), $settings, new SystemClock());
// ... passed to IssuanceService alongside its existing collaborators (failOpen defaults true)
```

## 9. Testing strategy (TDD)

**Unit — `RateLimiter`** (in-memory fake `RateCounterStore`):
- under limit → allow; at the per-IP boundary (3rd) → allow; over per-IP (4th) → deny.
- over global ceiling → deny (even when per-IP is under).
- **over per-IP → the global counter is NOT incremented** (one IP can't exhaust the global ceiling for everyone).
- `rate_limit_enabled = false` → always allow (store never touched).
- store returns `null` → fail-open → allow (plus a fail-closed variant guarding the branch).
- correct time-bucketed keys (`porto_rl_ip_{hash}_{dayBucket}`, `porto_rl_g_{hourBucket}`) and TTLs (86400 / 3600).
- **window rollover** — a `Clock` in the next day-bucket resets the per-IP count (a fixed `Clock` makes this deterministic).
- IP-less request shares one per-IP bucket.

**Unit — `Settings`:** new accessors return defaults + overrides; `sanitize()` clamps ints `≥ 0`
and casts the checkbox; existing keys preserved.

**Integration (real transients, wp-env):**
- 4th same-IP `submit()` within the window → `rate_limited`; 21st global within the hour → `rate_limited`.
- `TransientRateCounterStore` increments and honors TTL.

## 10. Out of scope / future

- Sliding-window accuracy; configurable window units.
- `X-Forwarded-For` trust (would need an admin-declared trusted-proxy list to be safe).
- Atomic increments via `wp_cache_incr()` as a fast path where a persistent object cache exists.
- A "retry-after" header / countdown in the 429 response.
