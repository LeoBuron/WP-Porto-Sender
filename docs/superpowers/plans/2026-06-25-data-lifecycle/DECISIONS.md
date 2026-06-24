# DECISIONS — data-lifecycle autonomous build

Every autonomous choice made while running unattended, with rationale. Format:
`Dnn [workstream] — Question → Decision — Why — (reversible? / HARD-STOP?)`.

The loop runs unattended: where a human would normally be asked, the most reasonable,
**reversible** choice was made and logged here. HARD-STOPS (new dependency, new outbound
third-party data flow, shipping a licensed data file, or a default that destroys data without
explicit user action) are implemented **disabled-by-default behind a setting** and flagged
`OPEN — needs sign-off`.

---

## Cross-cutting

- **D01 [all] — One combined spec + one PLAN.md, or four?** → One combined spec
  (`2026-06-25-data-lifecycle-design.md`) with a section per workstream, and one `PLAN.md`
  grouping tasks by workstream. — The four workstreams form one epic with tight
  interdependencies (WS4 wipe depends on WS2 export; both share a Tools page and a `DataEraser`);
  the loop's state model is a single PLAN.md. — reversible.
- **D02 [all] — Build order** → WS2 → WS1 → WS4 → WS3 (per loop spec). — WS2 is the spine
  (schema-version + export/import) that WS4's wipe/restore story depends on; WS3 is HARD-STOP
  territory built last. — fixed by spec.
- **D03 [all] — Shared admin "Tools" page** → New `src/Admin/ToolsPage.php`
  (slug `porto-sender-tools`, cap `manage_options`) hosts WS2 Export/Import and WS4
  reset/delete + pre-removal flow, rather than scattering them across SettingsPage. — Keeps
  destructive + bulk-data actions in one auditable place with consistent nonce/cap handling;
  mirrors the existing one-page-per-concern admin structure. — reversible.
- **D04 [all] — Settings additions follow the existing pattern** → every new option key goes
  through `Settings::defaults()` + `Settings::sanitize()` (honouring the "only overwrite
  form-rendered keys" rule) + a typed accessor, exactly like the rate-limit keys. — Consistency;
  sanitize() already preserves non-form keys (e.g. `hash_salt`). — reversible.

## WS2 — Data portability (the spine)

- **D10 [WS2] — Salt portability** → The lossless migration **bundle carries `hash_salt`** and a
  full restore overwrites the target salt. — Re-hashing on import is impossible for `ip_hash`
  and `token_hash` (raw IP/token are never stored) and for retention-anonymized rows
  (name/email already NULL); carrying the salt is the only way to keep dedup history, active
  confirmation links, and abuse audit valid across a migration. — reversible (admin chooses the
  bundle path); **security-sensitive** (see D11).
- **D11 [WS2] — Bundle is a credential (salt + plaintext PII)** → (a) primary delivery is a
  **streamed download, never persisted in the web root**; (b) **optional passphrase encryption**
  via libsodium `sodium_crypto_secretbox` (ext-sodium is PHP-core, **no new dependency**),
  feature-detected and gracefully disabled if absent; (c) producing an **unencrypted** bundle
  requires an explicit "I understand this file contains a secret salt + personal data"
  confirmation. — The salt's leak weakens every hash; PII export is a DSGVO surface. Encryption
  is defense-in-depth for the at-rest downloaded file; streaming removes the web-readable-file
  risk. — reversible. NOT a HARD-STOP (no new dep — sodium is core).
- **D12 [WS2] — Schema versioning** → Add option `porto_sender_schema_version` +
  `Schema::CURRENT_VERSION` constant + a migration runner invoked from `activate()`: run
  `dbDelta` (idempotent), then apply ordered migrations from stored→current, then write current.
  Seed v1 as the baseline of today's schema with an empty migration map. — "Survive updates" is
  mostly automatic (WP updates don't touch the DB); the deliverable is the *framework* so future
  schema changes have a safe home, plus it documents the current version for the bundle. — reversible.
- **D13 [WS2] — CSV codes import (richer than the textarea)** → New CSV upload on CodeIntakePage.
  Columns: `product,code,value_cents,purchase_date` with a header row; required = `product`,`code`;
  optional cols fall back to catalog value / today. **No `expires_on` column** — expiry is a derived
  business rule (`Expiry::expiresOn($purchaseDate)`), so the importer reuses `CodeRepository::addBatch`
  by grouping rows by `(product,value_cents,purchase_date)`; `INSERT IGNORE` gives DB-level dedup on the
  `code` UNIQUE key. Reports inserted / skipped-with-reason. Keep the textarea for quick paste. — Per-row
  product/date/value is the real gap vs the textarea (one product/date per batch); letting users set
  arbitrary expiry would contradict the domain model, and reusing addBatch keeps derivation identical
  (DRY). — reversible.
  - **D13.1 refinement (impl, 2026-06-25):** the importer calls `addBatch(product,value,date,[code])`
    **one row at a time** (one-element batch) rather than grouping. `addBatch` uses `INSERT IGNORE` and
    returns 0 for an existing code, so per-row calls give **exact per-row duplicate attribution** for the
    admin's skipped report; grouping only saved prepared statements, negligible for an occasional admin
    import. Within-file duplicates are caught (a `seen` set) before the store is touched. Still reuses
    `addBatch`, so `Expiry::expiresOn` derivation is unchanged. — reversible.
- **D14 [WS2] — Per-table CSV export scope** → `porto_codes.csv` (all columns) and
  `porto_requests.csv` (all columns **including** raw name/email and the hashes), admin-gated.
  Every cell is run through spreadsheet-formula-injection prefixing (`'` before `= + - @` / tab / CR).
  — CSV is the human-editable backup/portability format; the requests CSV is a DSGVO data-portability
  artifact so it must include PII; formula-injection prefixing is mandatory for any CSV that may be
  opened in Excel/LibreOffice. — reversible.
- **D15 [WS2] — Bundle import modes** → Primary supported path is **Full restore** (overwrite
  settings + salt + both tables) for migration/disaster-recovery. A "data-only merge" option is
  offered but explicitly warns that imported hashes won't match the local salt (so dedup/tokens from
  the imported set won't resolve). — Full restore is the only path that is actually lossless; data-only
  merge re-introduces the salt-mismatch the whole design exists to avoid, so it's a clearly-labelled
  secondary. — reversible.
- **D15.1 [WS2] — full_restore clears tables itself, not via DataEraser (impl, 2026-06-25)** → ImportService's
  full_restore empties the two data tables with `DELETE FROM` (via repo `deleteAll()`) and re-inserts, rather
  than calling a DataEraser data-only purge or `Schema::uninstall`+`install`. — (1) DELETE is DML so it is
  transaction-safe (DROP/TRUNCATE implicitly commit in MySQL, which would break the per-test rollback and
  risk a half-applied restore in production); (2) the tables already exist from activation, so a restore only
  needs to replace *contents* + settings + salt + schema_version; (3) avoids a forward dependency on DataEraser
  (Task 14). DataEraser remains the single owner of the FULL purge (options/transients/cron) for uninstall +
  delete-all — no overlap. — reversible.
- **D15.2 [WS2] — Bundle import column allowlist (security, impl)** → repo `insertRows()` intersects each
  bundle row against a per-table column constant before `$wpdb->insert`, so untrusted bundle keys can never
  become SQL column identifiers. — satisfies the "never trust columns" import rule; proven by an integration
  test that feeds a bogus `evil_column` and confirms it is dropped. — reversible.
- **D16 [WS2] — Import safety** → validate uploaded file MIME + extension + size cap + row cap;
  parse strictly as CSV/JSON text (never `unserialize`/`eval`); reject embedded PHP/serialized
  payloads; sanitize the filename (no path traversal); handle the upload via WP's tempfile and
  delete it after processing. — Standard file-import hardening; the loop security checklist mandates it. — reversible.

## WS1 — Admin notification email

- **D20 [WS1] — Trigger event** → Notify on **successful issue** (in `IssuanceService::confirm()`
  after `markIssued`), not on submit. — The user's ask ("wenn Leute ein Porto **abrufen**") means a
  code actually claimed; submit is a noisy pending state (bots/abandoned) already dampened by
  captcha+rate-limit; issue is the meaningful, low-volume event the admin wants to hear about. — reversible.
- **D21 [WS1] — Recipient** → Reuse `settings.alert_email` (already defaults to `admin_email`, already
  used by StockAlerter). No separate recipient field. — YAGNI; the admin already configured where
  operational alerts go. — reversible.
- **D22 [WS1] — Default on/off** → **Enabled by default** (`admin_notify_enabled`, default `true`).
  — The feature request is literally "aktivieren, dass man als Admin ne Email bekommt"; the mail goes
  only to the site's own admin address (not a third party), so it is **not** a HARD-STOP. — reversible
  via the toggle.
- **D23 [WS1] — PII in the mail** → Default mail is **PII-free**: product + timestamp + remaining-stock
  count + masked request reference. Including visitor name/email is an opt-in setting
  (`admin_notify_include_pii`, default `false`). — Data minimization: email inboxes are a poor PII store
  (indefinite retention, phone sync); the admin (controller) can opt in if they need it. — reversible.
- **D24 [WS1] — Burst throttle ("burst of submits ≠ burst of mails")** → A single **throttled-immediate**
  mode: a transient window guard (`porto_notify_*`, time-bucketed like the rate limiter) sends at most
  one notification per configurable window (`admin_notify_window_minutes`, default 15), and the mail
  states "N codes issued since the last notice." A window of `0` = notify every issue. — One mechanism
  satisfies the requirement; separate `each`/`daily_digest` modes are flexibility not yet needed (window=0
  already gives per-event). Issue events are already downstream of captcha+rate-limit, so volume is
  doubly-dampened. — reversible.
- **D24.1 [WS1] — Throttle is a rolling cooldown, not a clock-aligned bucket (impl, 2026-06-25)** →
  AdminNotifier uses a leading-edge send + a cooldown transient (TTL = window) + a carried-over `pending`
  counter, behind a `NotifyThrottleStore` seam. The first event after a cooldown sends `pending+1` (so a
  burst collapses to one mail that still reports its true size); `window=0` sends every event. — A rolling
  cooldown guarantees ≥window between mails (a clock bucket can fire twice across a boundary), and the
  transient TTL removes the need for a Clock dependency. State keys: option `porto_notify_pending`
  (autoload=false) + transient `porto_notify_cooldown`. **Task 14/uninstall MUST purge both** (the option
  is not under the `porto_sender_` prefix). — reversible.
- **D25 [WS1] — Wiring** → New `src/Notifications/AdminNotifier.php` (thin, unit-testable policy:
  enabled? throttled? build + send via Mailer), injected into `IssuanceService` and called on the
  issue-success path. — Constructor injection matches house style (IssuanceService already takes Mailer
  etc.); a `do_action` hook is a future nice-to-have but adds indirection. — reversible.

## WS4 — Uninstall & data lifecycle

- **D30 [WS4] — Single source of truth for "purge all"** → New `src/Lifecycle/DataEraser.php`
  with `purgeAll(\wpdb $wpdb)` (drop tables; delete exact-name options; delete by-prefix options &
  transients via prepared `LIKE` for `porto_rl_*`, `porto_notify_*`, `porto_sender_lowstock_*`;
  clear the `porto_sender_daily` cron; delete persisted export files + schema_version option). Both
  `uninstall.php` and the admin "delete all data" button call it. — Prevents drift between the two
  deletion paths; the loop flags transients/cron as currently-missed in uninstall.php. — reversible.
- **D31 [WS4] — uninstall.php gaps** → Extend (via DataEraser) to also: clear cron (Delete-without-
  deactivate skips `deactivate()`), delete `porto_rl_*` + `porto_notify_*` transients, delete
  `porto_sender_lowstock_*` flags by prefix (not the hardcoded two), delete `porto_sender_schema_version`,
  remove persisted export files. — uninstall.php today leaks all of these. — reversible.
- **D32 [WS4] — Pre-delete export flow** → Do **not** hijack WP core's plugin-Delete click (fragile).
  Instead provide a guided flow: the Tools page's "Export & prepare for removal" section + a
  `plugin_action_links` "Export/Entfernen" link + an admin notice. — uninstall.php is headless (proven
  by code: no UI/admin context), so "ask to export before delete" must be a pre-delete admin screen
  that depends on WS2 export. — reversible.
- **D33 [WS4] — Delete buttons & scopes** → Two explicit, separately-confirmed actions on the Tools
  page: (1) **Reset settings** = delete the settings option and re-seed defaults **but preserve
  `hash_salt`** (so existing hashes still match); (2) **Delete all data** = `DataEraser::purgeAll` then
  `Schema::install` (recreate empty tables) + re-seed defaults with a **new** salt (clean slate). Both:
  `manage_options` + `check_admin_referer` nonce + an explicit confirm checkbox + PRG redirect + result
  notice. — Reset-settings must keep the salt or it silently breaks all existing hashes; delete-all is a
  fresh start so a new salt is correct. These are explicit user actions, so **not** the HARD-STOP
  "destroys data without explicit user action." — reversible.

## WS3 — Geo-restriction (Germany only) — HARD-STOP territory

- **D40 [WS3] — Gate default** → `geo_enabled` default **false** (allow-all). When off, **no IP→country
  processing happens at all**. — Keeps the default DSGVO surface unchanged; geo is opt-in. — reversible.
- **D41 [WS3] — Pluggable providers** → `src/Geo/GeoProvider` interface `country(string $ip): ?string`
  (ISO-3166-1 alpha-2 or null=unknown), with `NullGeoProvider`, `CloudflareHeaderGeoProvider`
  (`HTTP_CF_IPCOUNTRY`), `MaxMindGeoProvider` (local .mmdb), `ApiGeoProvider` (outbound). Provider
  chosen via `geo_provider` setting. — Matches the loop's pluggable-source requirement and keeps the
  gate logic provider-agnostic + unit-testable with a fake provider. — reversible.
- **D42 [WS3] — Which providers ship enabled** → **None enabled by default.**
  - Cloudflare header: zero-dependency, no outbound, but trusts a **proxy header** (spoofable unless the
    origin is locked to CF IPs) and still processes IP→country → default OFF, requires an admin
    acknowledgment (`geo_cloudflare_ack`) + a UI warning. *Not* a HARD-STOP but kept off.
  - MaxMind GeoLite2: needs the reader library (**new dependency — HARD-STOP a**) **and** the licensed
    .mmdb data file (**HARD-STOP c**). Implemented as an availability-detecting provider that is
    selectable only if the admin has independently installed both; we ship **neither** lib nor data.
    `OPEN — needs sign-off`.
  - Third-party API: sends the **visitor IP to a third party** (**HARD-STOP b — new outbound data flow**).
    Implemented disabled, requires an API key + an explicit enable; documents the DSGVO processor/AVV
    implications. `OPEN — needs sign-off`.
  — The loop forbids shipping a new dep, a licensed file, or an enabled outbound flow without sign-off. — reversible.
- **D43 [WS3] — Gate placement** → `validate → captcha → GEO → rate-limit → dedup → stock → create`.
  Geo sits **after captcha, before rate-limit**. — Mirrors the rate-limiter's "after captcha" rationale:
  only PoW-paid requests are geo-evaluated, which caps outbound-API call volume and avoids amplifying an
  external provider into a DoS vector; a coarse eligibility filter belongs before the volume counter. — reversible.
- **D44 [WS3] — Unknown-country / fail mode** → `geo_fail_mode` default **open** (allow on unknown or
  provider error). — False-denying legitimate DE users (VPN/CGNAT/travel/header-absent) is worse than the
  abuse it stops, and captcha+rate-limit+dedup+the hard pool cap still gate. Admin can switch to
  fail-closed. The geo gate is a pure boolean that can never short-circuit the other gates. — reversible.
- **D45 [WS3] — Deny response** → **HTTP 403** + a clear message ("Dieser Dienst ist auf Anfragen aus
  Deutschland beschränkt."). — 403 (eligibility) is distinct from 429 (rate) and 422 (validation); 451 is
  for legal censorship, which this isn't. — reversible.
- **D46 [WS3] — Allowed countries** → `geo_allowed_countries` default `['DE']`, admin-editable list. — The
  ask is "Germany only" but a list costs nothing extra and handles AT/CH-adjacent future needs. — reversible.
- **D47 [WS3] — Legal basis** → Documented as Art. 6(1)(f) GDPR (legitimate interest: abuse prevention +
  serving the intended audience of a free service). When the API provider is used, the visitor IP leaves
  to a processor → requires an AVV/DPA + privacy-policy disclosure → that's why it's sign-off-gated. — reference.

---

## OPEN — needs sign-off (shipped disabled-by-default)

| Ref | Item | Why gated | State |
|---|---|---|---|
| D42 | WS3 MaxMind provider | new runtime dependency + licensed GeoLite2 data file | disabled; availability-detected; ships no lib/data |
| D42 | WS3 third-party API provider | new outbound flow: visitor IP → third party | disabled; requires explicit enable + API key + AVV |
| D11 | WS2 unencrypted bundle | contains secret salt + plaintext PII | allowed only behind an explicit confirmation; encryption offered |
