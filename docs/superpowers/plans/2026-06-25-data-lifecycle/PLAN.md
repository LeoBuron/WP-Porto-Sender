# Data lifecycle & portability — Implementation Plan (WS1–WS4)

> **For agentic workers (the loop):** this is the single source of truth for PHASE B. Each task has
> Files, Interfaces, a TDD step checklist, an explicit **Verify** command, a **DoD**, and an
> **Evidence** slot. Check a task `[x]` only after pasting real command+output into its Evidence slot.
> Re-read the spec (`docs/superpowers/specs/2026-06-25-data-lifecycle-design.md`) and DECISIONS.md each
> iteration. Implement with TDD (`superpowers:test-driven-development`).

**Goal:** Ship four data-lifecycle workstreams — admin notification (WS1), export/import + schema
versioning (WS2), complete uninstall + reset/delete (WS4), and a default-off geo gate (WS3).

**Architecture:** Small, interface-bounded units under `src/Portability`, `src/Notifications`,
`src/Lifecycle`, `src/Geo`, plus a shared `ToolsPage`. New settings keys follow the existing
`defaults()/sanitize()/accessor` pattern. A `DataEraser` is the single definition of "all plugin data",
shared by `uninstall.php` and the admin delete button.

**Tech Stack:** PHP 8.1+, PSR-4 `PortoSender\`, WordPress APIs, PHPUnit 11 (unit `phpunit-unit.xml`,
integration `phpunit-integration.xml`), brain/monkey, wp-env, Playwright. ext-sodium (PHP core) for
optional bundle encryption.

## Global Constraints

- **DSGVO-first.** Salted hashes only; minimize PII; the export bundle's `hash_salt` is a credential.
- **No new runtime dependency, no enabled third-party outbound flow, no shipped licensed data file, no
  data-destroying default** without sign-off. HARD-STOP items ship **disabled-by-default behind a
  setting** (WS3 MaxMind/API providers; unencrypted bundle behind explicit confirm). See DECISIONS.
- **PHP 8.1+**, `declare(strict_types=1)` in every new file.
- **Security on every admin/REST action:** `current_user_can('manage_options')` + nonce
  (`check_admin_referer`/`wp_verify_nonce`) for admin; public REST stays public but gated by
  captcha + geo + rate-limit. All SQL via `$wpdb->prepare`. `esc_*` on output, `sanitize_*` on input.
- **Settings sanitize rule:** only overwrite form-rendered keys; never clobber `hash_salt` or other
  non-form keys.
- **Verify commands:** unit = `composer test:unit` (or `vendor/bin/phpunit -c phpunit-unit.xml --filter <T>`);
  integration = `npm run test:integration`; live = `npx wp-env run cli wp ...` / Playwright. After
  reinstalling the plugin into wp-env, **reactivate via WP-CLI** so `activate()` runs.
- **Commit** one checkpoint per verified task; end messages with the Co-Authored-By line. Never push/PR/merge without sign-off.

---

# WS2 — Data portability (the spine)  [build first]

### Task 1: Schema versioning + migration runner

**Files:**
- Create: `src/Persistence/SchemaVersion.php`
- Modify: `src/Persistence/Schema.php` (add `const CURRENT_VERSION = '1';`)
- Modify: `src/Plugin.php` (call the runner in `activate()` after `Schema::install`)
- Test: `tests/unit/Persistence/SchemaVersionTest.php`, `tests/integration/SchemaVersionTest.php`

**Interfaces:**
- Produces: `SchemaVersion::OPTION = 'porto_sender_schema_version'`;
  `current(): string` (reads option, '' if unset); `set(string $v): void`;
  `migrate(string $from, string $to, array $migrations): array` (returns the ordered version keys
  applied; pure — `$migrations` is `['2' => callable, ...]`, callables run in version order for
  versions in `(from, to]`). `run(\wpdb $wpdb): void` (reads option, applies built-in migration map up to
  `Schema::CURRENT_VERSION`, writes it). Built-in map is empty at v1 (baseline).
- Consumes: `Schema::CURRENT_VERSION`.

- [x] Step 1: Unit test — `migrate('','1',[])` no-op; `migrate('1','3',...)` runs f2,f3 in order; skips
  at/below `from` and above `to`; orders numerically (version_compare), not lexically (`10` after `3`).
- [x] Step 2: Ran `--filter SchemaVersion` → 5 errors "Class SchemaVersion not found" (RED).
- [x] Step 3: Implemented `src/Persistence/SchemaVersion.php` (pure `migrate`; `current/set/run`; empty
  built-in migration map at v1; closures capture `$wpdb`).
- [x] Step 4: Unit → PASS (5 tests, 9 assertions).
- [x] Step 5: Added `Schema::CURRENT_VERSION='1'`; `Plugin::activate()` calls
  `(new SchemaVersion())->run($wpdb)` after `Schema::install`.
- [x] Step 6: Integration test — fresh→'1', idempotent, reconciles a stale recorded version, activate sets it.
- [x] Step 7: Integration `--filter SchemaVersion` → PASS (4 tests). Committed.

**Verify:** `vendor/bin/phpunit -c phpunit-unit.xml --filter SchemaVersion` and the integration filter.
**DoD:** `porto_sender_schema_version` is `'1'` after activation; migration runner applies ordered steps
for future versions; re-activation idempotent. ✅
**Evidence:**
```
# RED (unit): vendor/bin/phpunit -c phpunit-unit.xml --filter SchemaVersion
ERRORS! Tests: 5, Assertions: 0, Errors: 5.  (Class "PortoSender\Persistence\SchemaVersion" not found)

# GREEN (unit): same command
OK (5 tests, 9 assertions)

# GREEN (integration): npm run test:integration -- --filter SchemaVersion
OK (4 tests, 5 assertions)   [PHP 8.3.31, wp-env tests-cli]

# No regressions:
composer test:unit            -> OK (53 tests, 148 assertions)   (was 48)
npm run test:integration      -> OK (27 tests, 79 assertions)    (was 23)
```

### Task 2: `CsvWriter` — formula-injection-safe CSV building

**Files:** Create `src/Portability/CsvWriter.php`; Test `tests/unit/Portability/CsvWriterTest.php`.

**Interfaces:**
- Produces: `CsvWriter::toString(array $header, array $rows): string` (RFC-4180 quoting);
  `escapeCell(string $value): string` — prefixes a single `'` when the value begins with `=`, `+`, `-`,
  `@`, tab (`\t`), or CR (`\r`).

- [x] Step 1: Unit test — `escapeCell` prefixes `= + - @` tab/CR; leaves safe + multibyte-lead cells;
  `toString` RFC-4180-quotes comma/quote fields, casts non-strings, composes escape-then-quote.
- [x] Step 2: Ran `--filter CsvWriter` → 5 errors "Class CsvWriter not found" (RED).
- [x] Step 3: Implemented `src/Portability/CsvWriter.php`.
- [x] Step 4: Unit → PASS (5 tests, 13 assertions). Committed.

**Verify:** `vendor/bin/phpunit -c phpunit-unit.xml --filter CsvWriter`
**DoD:** every dangerous-leading-character cell is prefixed; RFC-4180 quoting correct. ✅
**Evidence:**
```
# RED: vendor/bin/phpunit -c phpunit-unit.xml --filter CsvWriter
ERRORS! Tests: 5, Assertions: 0, Errors: 5.  (Class "PortoSender\Portability\CsvWriter" not found)
# GREEN: same -> OK (5 tests, 13 assertions)
# Key cases proven: "'=SUM(A1,A2)" -> "\"'=SUM(A1,A2)\"" (escape-then-quote); 'Ärmel' unchanged.
```

### Task 3: `CsvReader` — strict header-mapped parsing with caps

**Files:** Create `src/Portability/CsvReader.php`; Test `tests/unit/Portability/CsvReaderTest.php`.

**Interfaces:**
- Produces: `CsvReader::__construct(int $maxRows = 5000)`;
  `parse(string $csv, array $requiredHeaders): array` — returns `array<int,array<string,string>>`
  (header→value maps, column order irrelevant); throws `\RuntimeException` on a missing required header
  or when data rows exceed `maxRows`.

- [x] Step 1: Unit test — header→value maps; column order irrelevant; missing required header throws;
  `maxRows` exceeded throws; blank/whitespace lines skipped; BOM stripped + header trimmed/lowercased;
  quoted comma field preserved.
- [x] Step 2: Ran `--filter CsvReader` → RED (class missing: 5 errors + 2 expectException mismatches).
- [x] Step 3: Implemented `src/Portability/CsvReader.php` (fgetcsv over php://temp, escape '' = RFC-4180).
- [x] Step 4: Unit → PASS (7 tests, 8 assertions). Committed.

**Verify:** `vendor/bin/phpunit -c phpunit-unit.xml --filter CsvReader`
**DoD:** order-independent mapping; required-header + row-cap enforcement. ✅
**Evidence:**
```
# RED: vendor/bin/phpunit -c phpunit-unit.xml --filter CsvReader  -> ERRORS/FAILURES 7 (class missing)
# GREEN: same -> OK (7 tests, 8 assertions)
# Full unit suite after Tasks 2+3: composer test:unit -> OK (65 tests, 169 assertions)
```

### Task 4: `CodesCsvImporter` — CSV rows → `addBatch`

**Files:** Create `src/Portability/CodesCsvImporter.php`; Test `tests/unit/Portability/CodesCsvImporterTest.php`.

**Interfaces:**
- Consumes: `CsvReader::parse`; `CodeRepository::addBatch(string $product, int $valueCents,
  \DateTimeImmutable $purchaseDate, array $codes): int` (derives `expires_on` via `Expiry`; `INSERT IGNORE`);
  `ProductCatalog::get(string): ?PostageProduct`.
- Produces: `CodesCsvImporter::import(string $csv): array` → `['inserted'=>int, 'skipped'=>array<int,array{row:int,reason:string}>]`.
  CSV columns: **required** `product,code`; **optional** `value_cents` (default catalog value),
  `purchase_date` (`Y-m-d`, default today). **No `expires_on` column** (derived by `Expiry`).
  Rows are validated (product ∈ catalog; date parseable; code non-empty), then **grouped by
  `(product,value_cents,purchase_date)`** and each group sent to `addBatch`. DB-duplicate skips =
  `rows_sent − addBatch_return`; invalid-row skips carry a reason.

- [x] Step 1: Unit test (mocked `CodeStore` recording `addBatch` calls + `ProductCatalog::default()`) —
  valid rows pass through; unknown product/invalid date/invalid value_cents/empty code → skipped(reason,row);
  value_cents defaults to catalog; DB-dup (fake returns 0) → skipped; within-file dup → skipped, store hit once.
- [x] Step 2: Ran `--filter CodesCsvImporter` → RED (8 errors, class missing).
- [x] Step 3: Implemented `src/Portability/CodesCsvImporter.php`. **D13 refinement:** calls
  `addBatch(product,value,date,[code])` **per row** (one-element batch) instead of grouping — exact per-row
  duplicate attribution for the admin report; INSERT IGNORE return 0 = "already in DB". Within-file dedup
  before the store. Reuses `addBatch` (so `Expiry` still derives `expires_on`).
- [x] Step 4: Unit → PASS (8 tests, 30 assertions). Committed.

**Verify:** `vendor/bin/phpunit -c phpunit-unit.xml --filter CodesCsvImporter`
**DoD:** valid rows insert via `addBatch` (per-row); invalid/dup rows reported with reasons; expiry derived. ✅
**Evidence:**
```
# RED -> ERRORS! Tests: 8, Errors: 8 (class missing)
# GREEN -> OK (8 tests, 30 assertions)
# Proven: per-row reason+row for unknown product / 2026/01/15 date / non-numeric value / empty code /
#         within-file dup (store hit once) / DB dup (fake returns 0 -> 'already exists in database').
```

### Task 5: `BundleSerializer` — lossless export bundle (incl. salt)

**Files:** Create `src/Portability/BundleSerializer.php`; Test `tests/unit/Portability/BundleSerializerTest.php`.

**Interfaces:**
- Produces: `BundleSerializer::FORMAT_VERSION = 1`;
  `build(array $settings, array $codes, array $requests, string $schemaVersion, string $siteUrl, string $exportedAt): string`
  (returns JSON: `{format_version, schema_version, exported_at, site_url, settings, codes, requests}`,
  `settings` includes `hash_salt`);
  `parse(string $json): array` → the same associative structure; throws `\RuntimeException` on an
  unknown `format_version` or malformed JSON.

- [x] Step 1: Unit test — `parse(build(...))` round-trips settings (incl. `hash_salt`), codes, requests
  losslessly (assertSame, type-exact); unknown `format_version` throws; non-JSON throws; missing keys throw.
- [x] Step 2: Ran `--filter BundleSerializer` → RED (4 failed, class missing).
- [x] Step 3: Implemented `src/Portability/BundleSerializer.php` (`JSON_THROW_ON_ERROR`; FORMAT_VERSION=1;
  required-keys + version guard on parse).
- [x] Step 4: Unit → PASS (4 tests, 11 assertions). Committed.

**Verify:** `vendor/bin/phpunit -c phpunit-unit.xml --filter BundleSerializer`
**DoD:** lossless round-trip incl. salt; version-guarded parse. ✅
**Evidence:**
```
# RED -> ERRORS/FAILURES 4 (class missing)
# GREEN -> OK (4 tests, 11 assertions)  [salt 'SECRETSALT' survives round-trip; format_version 999 + bad JSON + missing keys all throw]
```

### Task 6: `BundleCrypto` — optional sodium passphrase encryption

**Files:** Create `src/Portability/BundleCrypto.php`; Test `tests/unit/Portability/BundleCryptoTest.php`.

**Interfaces:**
- Produces: `BundleCrypto::available(): bool` (ext-sodium present);
  `encrypt(string $plaintext, string $passphrase): string` (magic header + salt + nonce + secretbox,
  key via `sodium_crypto_pwhash`); `decrypt(string $blob, string $passphrase): string` (throws on bad
  passphrase / tampered blob). When `available()` is false, the Tools UI hides the option.

- [x] Step 1: Unit test (skips if `!available()`) — round-trip; wrong passphrase throws; non-bundle blob
  throws; two encryptions of same text differ (random salt+nonce) yet both decrypt.
- [x] Step 2: Ran `--filter BundleCrypto` → RED (4 errors, class missing).
- [x] Step 3: Implemented `src/Portability/BundleCrypto.php` (MAGIC|pwhash-salt|nonce|secretbox;
  `sodium_crypto_pwhash` key derivation; `sodium_memzero`; `available()` feature-detect).
- [x] Step 4: Unit → PASS (4 tests, 7 assertions) — ext-sodium present on runtime, real coverage.

**Verify:** `vendor/bin/phpunit -c phpunit-unit.xml --filter BundleCrypto`
**DoD:** authenticated round-trip; wrong passphrase rejected; graceful when sodium absent. ✅
**Evidence:**
```
# php -r function_exists checks -> sodium available (true,true,true)
# RED -> ERRORS! Tests: 4, Errors: 4
# GREEN -> OK (4 tests, 7 assertions)   [wrong passphrase + tampered/short blob throw via Poly1305 MAC]
# Full unit suite after Tasks 4-6: composer test:unit -> OK (81 tests, 217 assertions)
```

### Task 7: `ExportService` — collect + stream (CSV per-table + bundle)

**Files:** Create `src/Portability/ExportService.php`; Test `tests/unit/Portability/ExportServiceTest.php`,
`tests/integration/ExportServiceTest.php`.

**Interfaces:**
- Consumes: `CsvWriter`, `BundleSerializer`, `BundleCrypto`, `Settings`, `CodeRepository`,
  `RequestRepository` (add read-all accessors `allRows(): array` to each repo if absent).
- Produces: `ExportService::codesCsv(): string`, `requestsCsv(): string` (both formula-escaped via
  `CsvWriter`), `bundle(?string $passphrase): string` (encrypted iff passphrase non-empty & sodium
  available). Streaming (headers + `echo` + `exit`) lives in `ToolsPage` (Task 9), not here, so the
  builders stay unit-testable.

- [x] Step 1: Unit test (mocked `CodeStore`/`RequestStore` + real `Settings`) — codes/requests CSV have
  headers + formula-escaped cells + PII columns; empty table → empty CSV; `bundle(null)` parseable JSON
  incl. salt + site_url; `bundle('pw')` encrypted (PORTOENC1) and decryptable.
- [x] Step 2: Ran `--filter ExportService` → RED (5 errors, class missing).
- [x] Step 3: Added `allRows()` to `CodeStore`/`RequestStore` + repos (`SELECT * ORDER BY id`, ARRAY_A);
  `Settings::toArray()`; implemented `src/Portability/ExportService.php`.
- [x] Step 4: Unit → PASS (5 tests, 12 assertions).
- [x] Step 5: Integration — seeded a real code + request, asserted CSV/bundle contain them (exercises real
  `allRows()`). PASS (1 test, 9 assertions). Committed.

**Verify:** `vendor/bin/phpunit -c phpunit-unit.xml --filter ExportService` + integration filter.
**DoD:** CSV + bundle builders produce correct, escaped, lossless output; PII/salt present in bundle. ✅
**Evidence:**
```
# unit RED -> 5 errors (class missing); GREEN -> OK (5 tests, 12 assertions)
# integration (wp-env) -> OK (1 test, 9 assertions): seeded EXPORTME1 + Alice present in CSV + bundle;
#   bundle settings.hash_salt == 'REALSALT'
```

### Task 8: `ImportService` — validate + dispatch (CSV codes / bundle restore)

**Files:** Create `src/Portability/ImportService.php`; Test `tests/unit/Portability/ImportServiceTest.php`,
`tests/integration/ImportServiceTest.php`.

**Interfaces:**
- Consumes: `CsvReader`, `CodesCsvImporter`, `BundleSerializer`, `BundleCrypto`, `DataEraser` (Task 14),
  `Schema`, `Settings`, repos.
- Produces: `ImportService::importBundle(string $blob, ?string $passphrase, string $mode): array` where
  `$mode ∈ {'full_restore','data_merge'}`. `full_restore`: decrypt(if needed) → parse → validate →
  (purge data tables via `DataEraser` data-only helper) → `Schema::install` → bulk-insert codes+requests
  → `update_option(Settings::OPTION, settings incl. salt)` → write schema_version. `data_merge`:
  insert-ignore codes+requests, keep local settings/salt (return a `salt_mismatch_warning`). Validation
  (parse/version) happens **before** any destructive step. Returns `['mode','codes','requests','warnings'=>[]]`.

- [x] Step 1: Unit test (`WpUnitTestCase`+Mockery+brain/monkey, shared call-log) — malformed bundle aborts
  with zero side effects (`update_option` never, repos never); `full_restore` order =
  deleteAll×2 → insertRows×2 → update_option(settings) → update_option(schema_version); `data_merge`
  inserts, never clears/overwrites settings, returns salt-mismatch warning; encrypted bundle w/o
  passphrase throws before side effects.
- [x] Step 2: Ran `--filter ImportService` → RED (4 errors, class missing).
- [x] Step 3: Implemented `src/Portability/ImportService.php` + repo `deleteAll()`/`insertRows()`
  (column-allowlisted) + `BundleCrypto::isEncrypted`. **D15.1:** full_restore clears via DELETE (DML,
  txn-safe) not DataEraser/DROP — tables already exist; restore only replaces contents + settings + salt.
- [x] Step 4: Unit → PASS (4 tests, 21 assertions).
- [x] Step 5: Integration — seed+salt → export → wipe + change salt → full_restore → data + SOURCESALT
  back, `findByTokenHash('tok-orig')` resolves (salt portability proven); bonus: bogus bundle column
  ignored by the allowlist. PASS (2 tests, 10 assertions). Committed.

**Verify:** unit filter + integration filter.
**DoD:** validation precedes destruction; full-restore is lossless incl. salt; merge warns about salt. ✅
**Evidence:**
```
# unit RED -> 4 errors; GREEN -> OK (4 tests, 21 assertions) [order spy + no-side-effect aborts]
# integration (wp-env) -> OK (2 tests, 10 assertions): SOURCESALT restored, token row resolves,
#   untrusted 'evil_column' dropped by the per-table allowlist
# full suites after Tasks 7-8: unit OK (90, 250) ; integration OK (30, 98)
```

### Task 9: `ToolsPage` — Export/Import UI + admin-post actions; CodeIntakePage CSV upload

**Files:** Create `src/Admin/ToolsPage.php`; Modify `src/Admin/CodeIntakePage.php` (add CSV upload →
`CodesCsvImporter`); Modify `src/Plugin.php` (register ToolsPage); Test
`tests/integration/Admin/ToolsPageExportImportTest.php`.

**Interfaces:**
- Consumes: `ExportService`, `ImportService`, `CodesCsvImporter`.
- Produces: admin page `porto-sender-tools` (cap `manage_options`); `admin_post_porto_export`,
  `admin_post_porto_import` handlers (nonce + cap + PRG). ToolsPage performs the **streaming**
  (`header('Content-Disposition: attachment; filename=...')` + echo + `exit`); never writes to web root.
  CodeIntakePage gains an `<input type=file>` CSV upload routed through `CodesCsvImporter` with a result
  notice (`inserted`/`skipped`). (Codes-CSV import lives on CodeIntakePage; bundle export/restore + per-table
  CSV export live on ToolsPage — single, unambiguous home each.)

- [x] Step 1: Integration test on the testable business methods (house convention tests these, not the
  wp_die/exit wrappers — cf. CodeIntakeHandlerTest): `exportPayload` codes_csv/requests_csv(+PII)/bundle;
  `importResult` full_restore round-trip; CodeIntakePage `importCsvFile`.
- [x] Step 2: Ran `--filter ToolsPageExportImport` → RED (class missing).
- [x] Step 3: Implemented `src/Admin/ToolsPage.php` (menu + `admin_post_porto_export/import`, streamed
  download, cap+nonce, unencrypted-bundle confirmation, 10 MB upload cap + `is_uploaded_file`); added
  `CodeIntakePage` CSV upload (`importCsvFile` + `admin_post_porto_intake_csv` + form); wired ToolsPage in
  `Plugin::wire`.
- [x] Step 4: Integration → PASS (4 tests, 12 assertions). Full suites: unit 90, integration 30→34.
- [x] Step 5: **Live smoke** via `wp eval-file` in real wp-env (after `wp plugin deactivate/activate`):
  ToolsPage builds codes-CSV + bundle payloads, bundle parses (format_version=1), `schema_version`
  option='1', bundle carries salt. (Guards mirror the existing CodeIntakePage nonce+cap pattern verbatim
  and get scrutinised in the WS2 security review; the browser-driven export-download + upload UX check is
  consolidated into the required WS2 end-to-end live smoke at the STOP CONDITION.) Committed.

**Verify:** `npm run test:integration -- --filter ToolsPageExportImport` + the `wp eval-file` probe output.
**DoD:** export streams (no web-root file); import restores; CSV upload adds codes; every action
nonce+cap-gated. ✅ (browser-UX smoke folded into WS2 end-to-end)
**Evidence:**
```
# integration -> OK (4 tests, 12 assertions): codes/requests CSV contain seeded TOOLS1/bob@; bundle
#   round-trip restores TOOLSALT + tok-tools; CSV import inserts 1 / skips 2 (unknown product + dup)
# live (wp eval-file, real runtime):
#   PROBE bundle_filename=porto-bundle-...json ctype=application/json; format_version=1
#   PROBE schema_version_option=1 ; has_salt=yes ; PROBE OK
# full suites: composer test:unit -> OK (90,250) ; npm run test:integration -> OK (34,110)
```

### WS2 SECURITY REVIEW (gate before WS2 done)
- [x] Adversarial security subagent reviewed the 17-file WS2 diff against the WP/plugin checklist →
  findings appended to SECURITY.md. **0 crit, 0 high, 0 med, 5 low.**
- [x] No crit/high to fix. Fixed the top low (settings-whitelist on full_restore) via TDD; deferred 4 lows
  with justifications. Re-ran suites green. Confirmed: formula-injection escaping on both CSV exports;
  import size/row caps + `is_uploaded_file` + no `unserialize`/`eval` + column allowlist; bundle streamed
  (no web-readable file) + salt never logged + unencrypted-bundle confirmation; every action cap+nonce;
  all SQL prepared/constant-identifier.
- [x] **WS2 end-to-end live smoke** (real wp-env DB, `wp eval-file`): disaster-recovery story — backed up
  the live DB (5 real codes + 1 request) + a marker → wiped + changed salt → full_restore → all real data
  + marker + the original salt came back; cleanup removed only the marker (net-zero). PASS.
**Evidence:**
```
# security review verdict: 0 crit / 0 high / 0 med / 5 low (see SECURITY.md WS2 section)
# fix: ImportService::sanitizeImportedSettings drops unknown keys; unit test added.
#   composer test:unit -> OK (91 tests, 255 assertions) ; npm run test:integration -> OK (34, 110)
# live DR smoke (wp eval-file, real runtime):
#   PROBE pre_codes=5 post_codes=6 ; pre_requests=1 post_requests=2 ; salt_restored=yes
#   PROBE marker_code_back=yes marker_token_resolves=yes ; PROBE PASS=yes
#   PROBE cleanup_codes=5 requests=1  (pre-existing E2E data + settings left intact)
```

> **WS2 (Data portability) — DONE.** Tasks 1–9 ✅ + security review ✅ + live DR smoke ✅.

---

# WS1 — Admin notification email  [build after WS2]

### Task 10: Settings keys for admin notifications

**Files:** Modify `src/Settings/Settings.php` (defaults + sanitize + accessors); Test
`tests/unit/Settings/AdminNotifySettingsTest.php`.

**Interfaces:**
- Produces: defaults `admin_notify_enabled=true`, `admin_notify_include_pii=false`,
  `admin_notify_window_minutes=15`; accessors `adminNotifyEnabled(): bool`,
  `adminNotifyIncludePii(): bool`, `adminNotifyWindowMinutes(): int`. `sanitize()` casts the two
  checkboxes (absent = false) and `absint`s the window; never clobbers non-form keys.

- [x] Step 1: Unit test (`AdminNotifySettingsTest`) — defaults (true/false/15); overrides; `sanitize()`
  casts both checkboxes (absent=false) + `absint` window; preserves `hash_salt`.
- [x] Step 2: RED (accessors missing). 3: Added 3 defaults + 3 accessors + sanitize block. 4: GREEN.

**Verify:** `vendor/bin/phpunit -c phpunit-unit.xml --filter AdminNotifySettings`
**DoD:** three keys with defaults/accessors/sanitize, non-form keys preserved. ✅
**Evidence:**
```
# RED -> 2 errors/1 failure; GREEN -> OK (4 tests, 13 assertions). hash_salt preserved through sanitize.
```

### Task 11: `Mailer::sendAdminNotification`

**Files:** Modify `src/Mail/Mailer.php`; Test `tests/unit/Mail/AdminNotificationMailTest.php`.

**Interfaces:**
- Produces: `Mailer::sendAdminNotification(string $to, array $data): bool` where `$data` =
  `['product_label'=>string,'count'=>int,'remaining'=>int,'name'=>?string,'email'=>?string]`.
  German subject/body; includes name/email only when present; text mail; uses `wp_mail`; returns its bool.

- [x] Step 1: Unit test (`wp_mail` spy) — PII-free body (count+remaining+product, no "Anfrage von"); PII
  body adds `Anfrage von: Vera <vera@example.de>`; returns `wp_mail` result.
- [x] Step 2: RED. 3: Added `sendAdminNotification` to MailerInterface + Mailer. 4: GREEN.

**Verify:** `vendor/bin/phpunit -c phpunit-unit.xml --filter AdminNotificationMail`
**DoD:** new sender mirrors the existing four; PII conditional; `wp_mail`-backed. ✅
**Evidence:**
```
# RED -> 3 errors; GREEN -> OK (3 tests, 11 assertions). German subject "Porto abgerufen"; PII opt-in only.
```

### Task 12: `AdminNotifier` — policy + window throttle

**Files:** Create `src/Notifications/AdminNotifier.php`; Test `tests/unit/Notifications/AdminNotifierTest.php`.

**Interfaces:**
- Consumes: `Settings`, `Mailer`, `Clock`, WP option/transient (behind a tiny store seam for testability —
  reuse a fake in tests). 
- Produces: `AdminNotifier::onIssued(array $ctx): void` where `$ctx` =
  `['product_label'=>string,'remaining'=>int,'name'=>?string,'email'=>?string]`. Behaviour: if
  `!adminNotifyEnabled()` → return. Else apply a time-bucketed window guard
  (`porto_notify_<floor(ts/window)>`): first event in a window sends a mail (count=pending+1) and arms;
  subsequent events in the window only increment the pending count; `window=0` → send every event. PII
  passed to Mailer only when `adminNotifyIncludePii()`.

- [x] Step 1: Unit test (fake `NotifyThrottleStore` + Mockery mailer) — disabled→never; no recipient→never;
  single event→one send (count=1) + cooldown armed 900s; burst→one send + pending accumulates; window=0→every
  event; include_pii toggles name/email.
- [x] Step 2: RED (interface missing). 3: `NotifyThrottleStore` (seam) + `WpNotifyThrottleStore`
  (option+transient) + `AdminNotifier`. 4: GREEN.

**Design note (D24 refinement):** rolling-cooldown + carry-over `pending` (not a clock-aligned time bucket):
leading edge sends, cooldown transient TTL = window, events during cooldown accumulate, first event after
cooldown sends `pending+1`. Guarantees ≥window between mails. No Clock dependency (transient TTL handles timing).

**Verify:** `vendor/bin/phpunit -c phpunit-unit.xml --filter AdminNotifier`
**DoD:** throttle coalesces a burst into one mail per window; toggle + PII honoured. ✅
**Evidence:**
```
# RED -> fatal (interface missing); GREEN -> OK (6 tests, 14 assertions)
# full unit suite after Tasks 10-12: composer test:unit -> OK (104 tests, 293 assertions)
```

### Task 13: Wire `AdminNotifier` into issuance + SettingsPage fieldset

**Files:** Modify `src/Issuance/IssuanceService.php` (inject `AdminNotifier`, call `onIssued` after
`markIssued`), `src/Plugin.php` (construct + inject), `src/Admin/SettingsPage.php` (Admin-notifications
fieldset); Test `tests/integration/AdminNotificationFlowTest.php`.

**Interfaces:**
- Consumes: `AdminNotifier::onIssued`. The `$ctx` is built in `confirm()` from `$product` label +
  `availableCount(product, now)` (remaining) + (if opted in) `$req->name/$req->email`.

- [ ] Step 1: Integration test — a full confirm→issue triggers exactly one admin mail (wp_mail capture);
  with `admin_notify_enabled=false` → none; fieldset renders + saves the three keys.
- [ ] Step 2: Run → FAIL.
- [ ] Step 3: Implement injection + call + fieldset.
- [ ] Step 4: Run → PASS.
- [ ] Step 5: Live smoke — drive a real issue via WP-CLI/REST in wp-env, capture the admin mail. Commit.

**Verify:** integration filter + live-smoke output.
**DoD:** issuing a code notifies the admin once (throttled), gated by the toggle.
**Evidence:**
```
```

### WS1 SECURITY REVIEW (gate before WS1 done)
- [ ] Security-review the WS1 diff → SECURITY.md. Confirm: mail only to configured `alert_email`; PII off
  by default; no secrets/HTML injection in the mail; no new outbound flow.
**Evidence:**
```
```

---

# WS4 — Uninstall & data lifecycle  [build after WS1; depends on WS2]

### Task 14: `DataEraser::purgeAll` — single purge definition

**Files:** Create `src/Lifecycle/DataEraser.php`; Test `tests/integration/Lifecycle/DataEraserTest.php`
(unit-cover the LIKE-pattern builder in `tests/unit/Lifecycle/DataEraserPatternsTest.php`).

**Interfaces:**
- Produces: `DataEraser::purgeAll(\wpdb $wpdb): void` — (1) `Schema::uninstall`; (2) `delete_option`
  `Settings::OPTION` + `SchemaVersion::OPTION`; (3) prepared `DELETE FROM {options} WHERE option_name LIKE`
  for `porto_sender_lowstock_%`, `_transient_porto_rl_%`, `_transient_timeout_porto_rl_%`,
  `_transient_porto_notify_%`, `_transient_timeout_porto_notify_%`; (4) `wp_clear_scheduled_hook('porto_sender_daily')`;
  (5) delete any persisted export dir (defensive). Also `purgeDataTablesOnly(\wpdb): void` (step 1 only) for
  ImportService full-restore. LIKE patterns are compile-time constants (no input interpolation).

- [ ] Step 1: Integration test — seed both tables, the settings + schema-version options, a
  `porto_sender_lowstock_x` option, a `porto_rl_*` and a `porto_notify_*` transient, and the cron event;
  call `purgeAll`; assert all gone (tables, options by-name + by-prefix, transients, cron unscheduled).
- [ ] Step 2: Run → FAIL.
- [ ] Step 3: Implement.
- [ ] Step 4: Run → PASS. Commit.

**Verify:** `npm run test:integration` (filter DataEraser).
**DoD:** one call removes every `porto_*` table/option/transient/cron; reusable by uninstall + button.
**Evidence:**
```
```

### Task 15: `uninstall.php` → `DataEraser`

**Files:** Modify `uninstall.php`; Test `tests/integration/UninstallCompletenessTest.php`.

**Interfaces:** Consumes `DataEraser::purgeAll`.

- [ ] Step 1: Integration test — simulate uninstall (define `WP_UNINSTALL_PLUGIN`, seed data, invoke
  `DataEraser::purgeAll($wpdb)` as `uninstall.php` does) → no `porto_*` option/transient/table remains,
  cron unscheduled.
- [ ] Step 2: Run → FAIL (today's uninstall leaks transients/cron).
- [ ] Step 3: Replace the body of `uninstall.php` with the guard + autoload + `DataEraser::purgeAll($wpdb)`.
- [ ] Step 4: Run → PASS. Commit.

**Verify:** integration filter.
**DoD:** uninstall leaves zero plugin residue, including transients + cron.
**Evidence:**
```
```

### Task 16: ToolsPage data-lifecycle (reset / delete-all) + pre-removal link

**Files:** Modify `src/Admin/ToolsPage.php` (lifecycle section + `admin_post_porto_reset`,
`admin_post_porto_wipe`), `src/Plugin.php` (`plugin_action_links` "Export/Entfernen" link); Test
`tests/integration/Admin/DataLifecycleActionsTest.php`.

**Interfaces:**
- Consumes: `DataEraser::purgeAll`, `Schema::install`, `Settings`.
- Produces: **Reset settings** = `delete_option(Settings::OPTION)` then re-seed `Settings::defaults()`
  **preserving the existing `hash_salt`**. **Delete all data** = `DataEraser::purgeAll` → `Schema::install`
  → re-seed defaults with a **new** salt. Both: cap + `check_admin_referer` + a required confirm checkbox +
  PRG redirect + result notice. `plugin_action_links` adds a link to the Tools page.

- [ ] Step 1: Integration test — reset preserves `hash_salt` (assert equal before/after) + restores other
  defaults; delete-all yields empty tables + a *different* salt; both reject a missing/invalid nonce or a
  non-admin or an unchecked confirm.
- [ ] Step 2: Run → FAIL.
- [ ] Step 3: Implement.
- [ ] Step 4: Run → PASS.
- [ ] Step 5: Live smoke — Playwright: export bundle → delete-all → import bundle restores (the
  export⇄wipe⇄re-import story). Capture evidence. Commit.

**Verify:** integration filter + Playwright smoke.
**DoD:** reset keeps salt; delete-all wipes + re-inits with new salt; both fully gated; round-trip works.
**Evidence:**
```
```

### WS4 SECURITY REVIEW (gate before WS4 done)
- [ ] Security-review the WS4 diff → SECURITY.md. Confirm: every destructive action cap+nonce+confirm+PRG,
  no CSRF/GET path; `DataEraser` SQL uses constant LIKE patterns (no interpolation); reset preserves salt;
  no secrets logged.
**Evidence:**
```
```

---

# WS3 — Geo-restriction (Germany only)  [build last — HARD-STOP territory]

### Task 17: Settings keys for geo

**Files:** Modify `src/Settings/Settings.php`; Test `tests/unit/Settings/GeoSettingsTest.php`.

**Interfaces:**
- Produces: defaults `geo_enabled=false`, `geo_provider='cloudflare'`, `geo_allowed_countries=['DE']`,
  `geo_fail_mode='open'`, `geo_cloudflare_ack=false`, `geo_maxmind_db_path=''`, `geo_api_url=''`,
  `geo_api_key=''`; matching accessors (`geoEnabled(): bool`, `geoProvider(): string`,
  `geoAllowedCountries(): array`, `geoFailOpen(): bool`, `geoCloudflareAck(): bool`,
  `geoMaxmindDbPath(): string`, `geoApiUrl(): string`, `geoApiKey(): string`). `sanitize()`:
  provider ∈ allow-list else retained; countries = uppercased 2-letter codes; fail-mode ∈ {open,closed};
  checkboxes cast; api_key `sanitize_text_field`; never clobber non-form keys.

- [ ] Step 1: Unit test — defaults + accessors + sanitize (country list parsing, provider/fail-mode
  whitelisting, checkbox casts, non-form keys preserved).
- [ ] Step 2: Run unit → FAIL.
- [ ] Step 3: Implement.
- [ ] Step 4: Run unit → PASS. Commit.

**Verify:** `vendor/bin/phpunit -c phpunit-unit.xml --filter GeoSettings`
**DoD:** geo keys default OFF; accessors + sanitize correct.
**Evidence:**
```
```

### Task 18: `GeoProvider` interface + providers + factory

**Files:** Create `src/Geo/GeoProvider.php`, `NullGeoProvider.php`, `CloudflareHeaderGeoProvider.php`,
`MaxMindGeoProvider.php`, `ApiGeoProvider.php`, `GeoProviderFactory.php`; Tests under `tests/unit/Geo/`.

**Interfaces:**
- Produces: `interface GeoProvider { public function country(string $ip): ?string; }`.
  `NullGeoProvider` → always null. `CloudflareHeaderGeoProvider` → uppercased `HTTP_CF_IPCOUNTRY` or null
  (treats `XX`/`T1`/empty as null). `MaxMindGeoProvider::available(): bool` (reader class + readable db
  path); `country()` → null when unavailable. `ApiGeoProvider` → `wp_remote_get` to `geo_api_url` with key;
  parses a 2-letter code; null on network error/bad body. `GeoProviderFactory::make(Settings): GeoProvider`
  → returns `NullGeoProvider` when geo disabled, the provider unavailable, or (Cloudflare) the ack is off.

- [ ] Step 1: Unit tests — Cloudflare reads the header (set/absent/`XX`); Null always null; MaxMind
  unavailable → null; Api parses a faked `wp_remote_get` body and maps a `WP_Error`/non-200 to null;
  factory returns Null when disabled/unacked/unavailable, else the chosen provider.
- [ ] Step 2: Run unit → FAIL.
- [ ] Step 3: Implement (ship **no** MaxMind lib/data; Api off without url+key).
- [ ] Step 4: Run unit → PASS. Commit.

**Verify:** `vendor/bin/phpunit -c phpunit-unit.xml --filter Geo`
**DoD:** provider-agnostic; sign-off providers inert without their prerequisites; factory fails safe to Null.
**Evidence:**
```
```

### Task 19: `GeoGate` — policy

**Files:** Create `src/Geo/GeoGate.php`; Test `tests/unit/Geo/GeoGateTest.php`.

**Interfaces:**
- Consumes: `GeoProvider`, `Settings`.
- Produces: `GeoGate::__construct(GeoProvider $p, Settings $s)`; `allows(string $ip): bool` — if
  `!geoEnabled()` → true; else `c = p.country(ip)` (provider exceptions caught → treated as null);
  `c === null` → `geoFailOpen()`; `c ∈ geoAllowedCountries()` → true else false. Pure; never throws.

- [ ] Step 1: Unit test — disabled → allow; DE → allow; FR → deny; null+open → allow; null+closed → deny;
  provider throws → caught → fail-mode applied; allowed `['DE','AT']` honoured.
- [ ] Step 2: Run unit → FAIL.
- [ ] Step 3: Implement.
- [ ] Step 4: Run unit → PASS. Commit.

**Verify:** `vendor/bin/phpunit -c phpunit-unit.xml --filter GeoGate`
**DoD:** pure boolean policy; fail-mode correct; cannot throw.
**Evidence:**
```
```

### Task 20: Wire `GeoGate` into issuance + REST 403 + UI

**Files:** Modify `src/Issuance/IssuanceService.php` (inject `GeoGate`; step **after captcha, before
rate-limit**; return `['status'=>'geo_blocked']`), `src/Plugin.php` (construct via `GeoProviderFactory`),
`src/Rest/RestController.php` (`geo_blocked` → HTTP 403), `assets/porto-form.js` (geo message),
`src/Admin/SettingsPage.php` (Geo fieldset incl. ack + sign-off notices); Test
`tests/unit/Issuance/GeoGatePlacementTest.php`, `tests/integration/GeoBlockedResponseTest.php`.

**Interfaces:** Consumes `GeoGate::allows($rawIp)`. The gate gets the **raw** IP (`$input['ip']`); nothing
new persisted.

- [ ] Step 1: Unit test — a denying `GeoGate` → `submit()` returns `geo_blocked` and rate-limit/dedup/
  create are never reached (spies); an allowing gate is transparent.
- [ ] Step 2: Run unit → FAIL.
- [ ] Step 3: Implement injection + ordering + REST 403 + JS message + fieldset.
- [ ] Step 4: Run unit → PASS.
- [ ] Step 5: Integration — REST `geo_blocked` → HTTP 403 (force a denying provider/config). Run → PASS.
- [ ] Step 6: Live smoke — with geo disabled (default), a normal submit still works end-to-end (proves the
  gate is transparent when off). Capture. Commit.

**Verify:** unit + integration filters + smoke.
**DoD:** geo gate sits after captcha/before rate-limit, returns 403 when blocking, transparent when off,
never disables other gates.
**Evidence:**
```
```

### WS3 SECURITY REVIEW (gate before WS3 done)
- [ ] Security-review the WS3 diff → SECURITY.md. Confirm: Cloudflare proxy-header trust documented +
  ack-gated + default off; gate fail-mode cannot disable other gates; API provider off by default +
  sign-off + key masked/never logged; no licensed data/lib shipped.
**Evidence:**
```
```

---

# FINAL — STOP CONDITION

- [ ] **Whole unit suite green:** `composer test:unit` → paste summary.
- [ ] **Whole integration suite green (wp-env):** `npm run test:integration` → paste summary.
- [ ] **One live end-to-end smoke per workstream** (wp-env + WP-CLI/Playwright), evidence captured:
  WS2 export⇄wipe⇄re-import round-trip; WS1 issue→admin mail; WS4 delete-all + uninstall residue check;
  WS3 default-off submit works (+ a forced geo_blocked 403).
- [ ] **Final whole-branch security review** (`security-review`) with **zero open Critical/High** in
  SECURITY.md (med/low may be deferred with one-line justification).
- [ ] **Cleanup:** no seeded test data or `porto_rl_*`/`porto_notify_*` transients left; tree clean except
  intended changes.
- [ ] Write `SUMMARY.md` (what shipped, evidence pointers, open sign-off items) and **stop the loop**
  (do not schedule another iteration).
**Evidence:**
```
```
