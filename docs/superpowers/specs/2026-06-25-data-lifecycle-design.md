# Data lifecycle & portability — design (WS1–WS4)

**Status:** approved-autonomously (brainstormed unattended, 2026-06-25; choices logged in
`docs/superpowers/plans/2026-06-25-data-lifecycle/DECISIONS.md`)
**Parent spec:** `docs/superpowers/specs/2026-06-24-wp-porto-sender-design.md`
**Implements four workstreams:** admin notification email, data export/import, uninstall &
data lifecycle, geo-restriction.

DSGVO/Datenschutz is a first-class constraint throughout — it is this plugin's identity.

## 0. Overview, build order, shared infrastructure

Four workstreams, built **WS2 → WS1 → WS4 → WS3**:

- **WS2 — Data portability (the spine).** Export/import of codes, requests, and settings; CSV codes
  intake; a lossless migration bundle that solves the salt-portability problem; a schema-version +
  migration framework so data survives updates.
- **WS1 — Admin notification email.** Notify the admin when a visitor actually claims a porto code,
  throttled so a burst of claims is not a burst of mails.
- **WS4 — Uninstall & data lifecycle.** Make uninstall complete (transients + cron + all options);
  a guided pre-removal export flow; admin reset/delete-all buttons. Depends on WS2 export.
- **WS3 — Geo-restriction (Germany only).** A pluggable, default-OFF geo gate with the external
  sources sign-off-gated. HARD-STOP territory.

### Shared infrastructure (built as WS2/WS4 land, reused everywhere)

| Unit | Responsibility | Used by |
|---|---|---|
| `src/Admin/ToolsPage.php` | New admin page (`porto-sender-tools`, `manage_options`): Export, Import, Data lifecycle (reset/delete), pre-removal flow. | WS2, WS4 |
| `src/Lifecycle/DataEraser.php` | `purgeAll(\wpdb)` — single definition of "all plugin data": tables, options (exact + by-prefix), transients, cron, export files, schema-version. | WS4 (button) + `uninstall.php` |
| `src/Persistence/SchemaVersion.php` (+ `Schema::CURRENT_VERSION`) | schema-version option + ordered migration runner invoked from `activate()`. | WS2 |
| `src/Portability/*` | Exporter/Importer for CSV + bundle, formula-injection escaper, bundle (de)serializer + optional sodium encryption. | WS2, WS4 |

### New settings keys (all via `Settings::defaults()`/`sanitize()`/accessor)

| Key | Type | Default | Workstream |
|---|---|---|---|
| `admin_notify_enabled` | bool | `true` | WS1 |
| `admin_notify_include_pii` | bool | `false` | WS1 |
| `admin_notify_window_minutes` | int | `15` | WS1 |
| `geo_enabled` | bool | `false` | WS3 |
| `geo_provider` | string | `'cloudflare'` | WS3 |
| `geo_allowed_countries` | array | `['DE']` | WS3 |
| `geo_fail_mode` | string `open\|closed` | `'open'` | WS3 |
| `geo_cloudflare_ack` | bool | `false` | WS3 |
| `geo_maxmind_db_path` | string | `''` | WS3 (sign-off) |
| `geo_api_url` / `geo_api_key` | string | `''` | WS3 (sign-off) |

Plus a standalone option `porto_sender_schema_version` (string, not inside the settings array, so
`sanitize()` never touches it and it is semantically distinct).

---

## WS2 — Data portability (the spine)

### 2.1 Problem

Today, codes are added only via a textarea (one product/date/value per batch); there is no export,
no import of requests/settings, and no schema versioning. The owner asked to "export everything and
re-import via CSV," to have data "carried over on update," and (with WS4) to back up before removal.

The hidden correctness hazard: **every hash in the DB depends on `settings.hash_salt`**
(`Hasher` = `sha256(salt|value)` for email/name/ip/token). A fresh install gets a new random salt,
so naively re-importing rows into it silently breaks dedup, confirmation-token lookup, and abuse
audit. Re-hashing on import cannot fix this — raw IP and raw token are never stored, and
retention-anonymized rows have NULL name/email. **The salt must travel with a lossless export.**

### 2.2 Decisions (locked — see DECISIONS D10–D16)

| Decision | Choice |
|---|---|
| Salt portability | Lossless **bundle carries `hash_salt`**; full restore overwrites target salt. |
| Bundle secrecy | Stream-download (never web-root); **optional sodium passphrase encryption** (ext-sodium, no new dep); unencrypted requires explicit confirmation. |
| Schema versioning | `porto_sender_schema_version` + `Schema::CURRENT_VERSION` + migration runner in `activate()`; v1 baseline. |
| CSV codes import | Header CSV `product,code,value_cents,purchase_date`; required `product,code`; `expires_on` derived via `Expiry` (not a column); reuses `addBatch` (rows grouped by product/value/date); reports inserted/skipped. Lives on **CodeIntakePage**. |
| CSV export scope | `porto_codes.csv` + `porto_requests.csv` (incl. raw PII + hashes), formula-injection-escaped. |
| Bundle import | Primary = **Full restore** (settings+salt+data); secondary = data-only merge (warns about salt mismatch). |
| Import safety | MIME+ext+size+row caps; strict text parse (no unserialize/eval); filename sanitised; WP tempfile deleted after. |

### 2.3 Architecture

`src/Portability/`:

| Unit | Responsibility | Depends on |
|---|---|---|
| `CsvWriter` | Build a CSV string from rows; **escape every cell** that begins with `= + - @` / tab / CR by prefixing `'` (formula-injection guard). Pure, unit-testable. | — |
| `CsvReader` | Parse an uploaded CSV into typed rows with a header map; enforce row cap; never trust column order/count. Pure. | — |
| `CodesCsvImporter` | Map CSV rows → `CodeRepository::addBatch`-compatible inserts; per-row validation (product in catalog, dates parseable, dedup by `code`); returns `{inserted, skipped:[{row,reason}]}`. | `CsvReader`, `CodeRepository`, `ProductCatalog` |
| `BundleSerializer` | Build/parse the lossless JSON bundle: `{format_version, schema_version, exported_at, site_url, settings(incl salt), codes[], requests[]}`. | Settings, repos |
| `BundleCrypto` | Optional `sodium_crypto_secretbox` encrypt/decrypt with a passphrase-derived key (`sodium_crypto_pwhash`); feature-detects ext-sodium; no-op fallback flagged in UI. | ext-sodium (core) |
| `ExportService` | Orchestrate: build CSV / bundle, stream to browser with the right headers, never persist in web root. | the above |
| `ImportService` | Orchestrate: validate upload, dispatch to `CodesCsvImporter` or bundle full-restore/merge, run inside a guard so a bad file can't half-apply. | the above, `DataEraser` (for restore) |
| `SchemaVersion` | Read/write `porto_sender_schema_version`; `migrate(from,to)` runs ordered steps; called by `activate()`. | `Schema` |

`src/Admin/ToolsPage.php` renders Export + Import sections and POSTs to nonce-protected
`admin-post` actions (`porto_export`, `porto_import`).

### 2.4 Data flow

**Export (bundle):** ToolsPage form (nonce+cap) → `admin_post_porto_export` → `ExportService`
collects settings+codes+requests → `BundleSerializer` → optional `BundleCrypto` → `ExportService`
streams `Content-Disposition: attachment` (`.json` or `.json.enc`), `exit`. Nothing written to disk.

**Import (CSV codes):** CodeIntakePage upload (nonce+cap) → its own `admin_post` action → validate file →
`CsvReader` → `CodesCsvImporter` → insert via `addBatch` → PRG redirect with `{inserted,skipped}` notice.
(The Tools-page Import section handles the lossless **bundle** restore; codes-CSV import stays on the
codes page — one unambiguous home each.)

**Import (bundle full restore):** validate → optional decrypt (passphrase) → parse →
**confirmation gate** → `DataEraser::purgeAll` (data tables only) + `Schema::install` → bulk-insert
codes+requests → `update_option(settings)` incl. salt → write schema_version → PRG notice. Wrapped so a
parse/validation failure aborts before any destructive step.

### 2.5 Configuration

No always-on config beyond the schema-version option. Bundle encryption is a per-export passphrase
field on the Tools page (not stored). CSV import has no settings.

### 2.6 Security (applied; findings → SECURITY.md)

- Cap + nonce + `manage_options` on every export/import action.
- CSV export formula-injection escaping (`CsvWriter`).
- Import: MIME/extension allow-list, size cap, row cap, no `unserialize`/`eval`, sanitised filename,
  WP tempfile deleted after, no path traversal.
- Bundle = **secret + PII**: streamed (no web-readable file), optional encryption, unencrypted behind
  an explicit "contains secret salt + personal data" confirmation; salt never logged.
- Full-restore overwrites data — explicit confirmation + PRG; never reachable via GET/CSRF.

### 2.7 Testing (TDD — evidence in PLAN.md)

- Unit: `CsvWriter` escapes `=cmd`, `+`, `-`, `@`, leading tab/CR; leaves safe cells alone.
- Unit: `CsvReader` enforces row cap, tolerates column reorder, rejects missing required headers.
- Unit: `CodesCsvImporter` counts inserted/skipped with reasons; dedups by `code`.
- Unit: `BundleSerializer` round-trips settings+codes+requests losslessly incl. salt; rejects an
  unknown `format_version`.
- Unit: `BundleCrypto` encrypt→decrypt round-trips with the right passphrase, fails with the wrong one
  (when sodium present; skipped with a note otherwise).
- Unit: `SchemaVersion.migrate` is a no-op at current version; applies an injected fake migration once.
- Integration (wp-env): export bundle → wipe → import full-restore → dedup + a stored token still
  resolve (proves salt portability); CSV import inserts real `porto_codes` rows.

---

## WS1 — Admin notification email

### 1.1 Problem

The admin wants to know when visitors actually claim porto codes ("wenn Leute ein Porto abrufen"),
without a mail-flood when several claims arrive close together.

### 1.2 Decisions (locked — see DECISIONS D20–D25)

| Decision | Choice |
|---|---|
| Trigger | On **successful issue** (`confirm()` after `markIssued`), not submit. |
| Recipient | Reuse `settings.alert_email`. |
| Default | Enabled (`admin_notify_enabled=true`) — not a HARD-STOP (admin's own address). |
| PII | PII-free by default; `admin_notify_include_pii` (default off) adds name/email. |
| Throttle | Throttled-immediate: one mail per `admin_notify_window_minutes` (default 15), stating the count since last notice; `0` = every issue. |
| Wiring | New `src/Notifications/AdminNotifier.php` injected into `IssuanceService`. |

### 1.3 Architecture

`AdminNotifier` — a thin policy unit: `onIssued(IssuedContext $ctx): void`. It (1) checks
`admin_notify_enabled`; (2) applies a transient window guard (`porto_notify_<bucket>` counter,
time-bucketed like the rate limiter) — within an open window it only increments the pending count
and returns; at window open it sends and re-arms; (3) builds the mail (PII-free unless
`admin_notify_include_pii`) and delegates to `Mailer::sendAdminNotification(...)` (new method).
No WP calls beyond the option/transient/mailer collaborators → unit-testable with fakes.

`Mailer` gains `sendAdminNotification(string $to, AdminNotifyData $data): bool` (German subject/body,
`wp_mail`, consistent with the existing four senders).

### 1.4 Data flow

`IssuanceService::confirm()` success path → `$this->notifier->onIssued($ctx)` after both
`markIssued` calls. `$ctx` carries product, remaining-stock count, masked request id, and
(only if opted in) name/email. Throttle decides send-now vs coalesce. Rate-limit/dedup upstream
already cap submit volume; this is a second backstop against many distinct issues in a short window.

### 1.5 Configuration

Three keys (table above) rendered as a "Admin notifications" fieldset on `SettingsPage`
(checkbox enabled, checkbox include-PII, number window-minutes).

### 1.6 Security

- Mail goes only to the admin-configured `alert_email`; no external recipient.
- PII-free by default (data minimization); opt-in is explicit.
- No secrets in the mail; no user-controlled HTML (text mail, escaped values).

### 1.7 Testing (TDD)

- Unit: enabled=false → never sends. Single issue in an empty window → sends once. Two issues inside
  the window → one mail, count=2 reported. Window=0 → every issue sends. include_pii toggles name/email
  presence. (Fake clock + fake option/transient + spy mailer.)
- Unit: `Mailer::sendAdminNotification` composes subject/body and calls `wp_mail` (brain/monkey).
- Integration (wp-env): drive a real confirm→issue and assert one admin mail captured (MailHog/`wp_mail` spy).

---

## WS4 — Uninstall & data lifecycle

### 4.1 Problem

`uninstall.php` today drops both tables, deletes the settings option, and deletes two hardcoded
`porto_sender_lowstock_*` options — but leaks `porto_rl_*` transients, the `porto_sender_daily` cron
(Delete-without-deactivate never calls `deactivate()`), lowstock flags for any non-default product,
the new schema-version option, and (WS2) any export artefacts. Separately, the owner wants to be
asked to export before removal, and a "delete all settings/data" button — neither of which can live in
the headless `uninstall.php`.

### 4.2 Decisions (locked — see DECISIONS D30–D33)

| Decision | Choice |
|---|---|
| Single purge definition | `src/Lifecycle/DataEraser::purgeAll(\wpdb)` used by both `uninstall.php` and the admin button. |
| uninstall gaps | Add cron clear + `porto_rl_*`/`porto_notify_*`/`porto_sender_lowstock_*` (by prefix) + schema-version + export files. |
| Pre-delete export | Guided Tools-page flow + `plugin_action_links` link + notice; do **not** hijack core Delete. |
| Delete scopes | "Reset settings" (preserve salt) and "Delete all data" (`purgeAll` → recreate empty schema + new salt); both nonce+cap+confirm+PRG. |

### 4.3 Architecture

`DataEraser::purgeAll(\wpdb $wpdb): void`:
1. `Schema::uninstall` (drop both tables).
2. `delete_option` exact names: settings option, `porto_sender_schema_version`.
3. `$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s ...", ...))`
   for `porto_sender_lowstock_%`, `_transient_porto_rl_%`, `_transient_timeout_porto_rl_%`,
   `_transient_porto_notify_%`, `_transient_timeout_porto_notify_%` (LIKE patterns are constants; any
   dynamic part `esc_like`-escaped).
4. `wp_clear_scheduled_hook('porto_sender_daily')`.
5. Delete any persisted export directory (defense-in-depth; WS2 streams, but the uninstall-prep flow
   may have written one).

`ToolsPage` "Data lifecycle" section:
- **Reset settings** → `delete_option(settings)` then re-seed `Settings::defaults()` **with the existing
  `hash_salt` preserved**.
- **Delete all data** → `DataEraser::purgeAll` → `Schema::install` → re-seed defaults with a new salt.

`uninstall.php` becomes: guard `WP_UNINSTALL_PLUGIN` → autoload → `DataEraser::purgeAll($wpdb)`.

### 4.4 Data flow

Tools page button (nonce + cap + confirm checkbox) → `admin_post_porto_reset` / `admin_post_porto_wipe`
→ corresponding action → PRG redirect → result notice. Plugin Delete (WP core) → `uninstall.php` →
`DataEraser::purgeAll`.

### 4.5 Security

- Every destructive action: `manage_options` + `check_admin_referer` nonce + explicit confirm + PRG (no
  GET/CSRF path).
- `DataEraser` LIKE patterns are compile-time constants; no user input interpolated into SQL.
- Reset-settings preserves the salt (avoids silently breaking existing hashes); delete-all's new salt is
  intentional (clean slate).

### 4.6 Testing (TDD)

- Unit/integration: after `purgeAll`, both tables gone, settings + schema-version options gone, a seeded
  `porto_rl_*` and `porto_notify_*` transient gone, cron unscheduled. (wp-env integration with real
  options/transients/cron.)
- Integration: "Reset settings" preserves `hash_salt` (assert same value before/after) and restores other
  defaults; "Delete all data" yields empty tables + a *different* salt.
- Integration: `uninstall.php` path (invoke `DataEraser::purgeAll`) leaves no `porto_*` option/transient.

---

## WS3 — Geo-restriction (Germany only) — HARD-STOP territory

### 3.1 Problem

The owner wants to allow requests only from German IPs. IP→country lookup needs an external source
(proxy header, local licensed DB, or third-party API) — each with a dependency, licensing, or
outbound-data-flow concern. The gate, message, HTTP code, fail-mode, and false-positive policy must be
built and tested now; the external sources stay disabled pending sign-off.

### 3.2 Decisions (locked — see DECISIONS D40–D47)

| Decision | Choice |
|---|---|
| Default | `geo_enabled=false` → no IP→country processing at all. |
| Providers | `GeoProvider::country(ip): ?string`; Null / Cloudflare-header / MaxMind / API impls. |
| Shipped enabled | **None.** Cloudflare = off + ack + warning (no dep, but proxy-header trust). MaxMind = sign-off (new dep + licensed data). API = sign-off (outbound IP). |
| Placement | `validate → captcha → GEO → rate-limit → dedup → stock → create`. |
| Fail mode | `geo_fail_mode=open` default (unknown/error → allow); gate is a pure bool, never short-circuits other gates. |
| Deny response | HTTP **403** + clear German message. |
| Allowed countries | `geo_allowed_countries=['DE']`, editable. |
| Legal basis | Art. 6(1)(f) GDPR; API provider needs an AVV + disclosure (documented). |

### 3.3 Architecture

`src/Geo/`:

| Unit | Responsibility |
|---|---|
| `GeoProvider` (interface) | `country(string $ip): ?string` — ISO-3166-1 alpha-2 or null=unknown. |
| `NullGeoProvider` | Always null (used when disabled / misconfigured). |
| `CloudflareHeaderGeoProvider` | Reads `$_SERVER['HTTP_CF_IPCOUNTRY']`; returns it (uppercased) or null. Carries the proxy-header trust caveat. |
| `MaxMindGeoProvider` | Reads a local `.mmdb`; **availability-detected** (reader class + readable db path) — null/disabled if absent. Ships no lib/data. |
| `ApiGeoProvider` | Outbound lookup via `wp_remote_get` to an admin-configured URL+key; disabled without sign-off. |
| `GeoGate` | `allows(string $ip): bool` — pure policy: if disabled → true; else `c = provider.country(ip)`; null → `fail_mode==open`; `c ∈ allowed` → true else false. Constructor `(GeoProvider, Settings, bool $failOpen)`. No WP calls. |
| `GeoProviderFactory` | Selects the provider from `geo_provider` + availability; returns `NullGeoProvider` when the chosen source is unavailable/unacknowledged. |

### 3.4 Data flow

`IssuanceService::submit()` gains a `GeoGate` dependency and one step after captcha:

```
validate → captcha verify → [GEO GATE] → rate limit → dedup → stock → create pending + confirm mail
```

`submit()` returns `['status' => 'geo_blocked']` when `GeoGate::allows($rawIp)` is false.
`RestController` maps `geo_blocked` → **HTTP 403**; `assets/porto-form.js` shows the German message.
The gate receives the **raw IP** (it needs the address for the provider) but persists nothing new; the
`ip_hash` stored on the row is unchanged.

### 3.5 Configuration

A "Geo restriction" fieldset on `SettingsPage`: enable checkbox, provider select, allowed-countries
text (comma list), fail-mode select, the Cloudflare ack checkbox + warning, and (collapsed, marked
"requires sign-off") the MaxMind db-path and API url/key fields. Disabled providers render a notice
explaining what's needed to enable them.

### 3.6 Security

- Cloudflare header is spoofable unless the origin only accepts CF IPs — surfaced as a strong admin
  warning + the `geo_cloudflare_ack` gate; default off.
- The gate's fail-mode must not disable the other gates — `GeoGate` is a pure boolean inserted as one
  step; tested that a provider error → fail-open on geo only, with captcha/rate-limit/dedup intact.
- API provider sends the visitor IP outbound → off by default, sign-off-gated, AVV/disclosure documented;
  API key treated as a secret (never logged, masked in UI).

### 3.7 Testing (TDD)

- Unit `GeoGate`: disabled → allow. DE → allow. FR → deny. Unknown(null)+open → allow; +closed → deny.
  Provider throws → caught → fail-mode applied. allowed=['DE','AT'] honoured.
- Unit providers: Cloudflare reads `HTTP_CF_IPCOUNTRY` (set/absent); MaxMind availability-detect returns
  Null when unavailable; API parses a faked `wp_remote_get` body and treats a network error as null.
- Unit `IssuanceService`: a denying `GeoGate` → `geo_blocked` and the request never reaches rate-limit/
  create (spies assert downstream gates untouched).
- Integration: REST `geo_blocked` → HTTP 403.

---

## Cross-cutting: new files

```
src/Admin/ToolsPage.php                     (WS2+WS4)
src/Lifecycle/DataEraser.php                (WS4, used by uninstall.php)
src/Persistence/SchemaVersion.php           (WS2)
src/Portability/CsvWriter.php               (WS2)
src/Portability/CsvReader.php               (WS2)
src/Portability/CodesCsvImporter.php        (WS2)
src/Portability/BundleSerializer.php        (WS2)
src/Portability/BundleCrypto.php            (WS2)
src/Portability/ExportService.php           (WS2)
src/Portability/ImportService.php           (WS2)
src/Notifications/AdminNotifier.php         (WS1)
src/Geo/GeoProvider.php (+ Null/Cloudflare/MaxMind/Api impls, GeoGate, GeoProviderFactory)  (WS3)
```

Modified: `Settings` (new keys), `SettingsPage` (3 fieldsets), `CodeIntakePage` (CSV upload),
`Mailer` (+sendAdminNotification), `IssuanceService` (+AdminNotifier, +GeoGate), `RestController`
(geo_blocked→403), `Plugin` (wire new units; migration in activate), `Schema` (CURRENT_VERSION),
`uninstall.php` (→ DataEraser), `assets/porto-form.js` (geo_blocked message).

## HARD-STOP register (shipped disabled-by-default; never enabled without sign-off)

| Item | HARD-STOP category | Mitigation |
|---|---|---|
| WS3 MaxMind provider | new dep + licensed data file | availability-detected; ships no lib/data; disabled |
| WS3 third-party API provider | new outbound data flow (visitor IP) | disabled; needs API key + explicit enable + AVV |
| WS2 unencrypted bundle | secret-salt + PII at rest | streamed; optional encryption; explicit confirmation |
| WS4 delete-all / uninstall | data destruction | explicit user action only; nonce+cap+confirm |

## Out of scope / future

- Bundle import "data-only merge" beyond a warned best-effort (the lossless path is full restore).
- Multisite-network purge (single-site scope; noted as a limitation in `DataEraser`).
- Digest-mode admin notifications (window-throttle covers the requirement; cron digest is future).
- Sliding-window notification throttle (fixed bucket suffices, per the rate-limiter precedent).
- Auto-enabling any geo source; a trusted-proxy list for non-CF reverse proxies.
- A "retry-after"/countdown UI on the 403/429 responses.
