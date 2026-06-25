# PROGRESS

**Iteration log (newest first). One line: status + what's next.**

- **2026-06-25 · PHASE B iter 12 — WS3 Task 20 DONE; WS3 code-complete.** Wired `GeoGate` into
  `IssuanceService::submit` (nullable, after captcha / before rate-limit → `geo_blocked`); REST →403;
  `porto-form.js` message; `Plugin` factory wiring; SettingsPage geo fieldset (CF-ack warning + sign-off
  fields + DSGVO note). Unit 127→129, integration 41→43; all green.
  **Next:** WS3 SECURITY REVIEW (proxy-header trust, fail-mode can't disable other gates, API off/key-safe,
  no lib/data shipped) + WS3 live smoke → WS3 done → **STOP CONDITION** (final full verify + whole-branch
  review + SUMMARY.md).
- **2026-06-25 · PHASE B iter 11 — WS3 Tasks 17–19 DONE.** (17) Geo Settings keys (all default OFF/safe);
  (18) GeoProvider interface + Null/Cloudflare/MaxMind/Api providers + factory (ships no MaxMind lib/data,
  Api off without url — HARD-STOPs honoured; factory fails safe to Null); (19) pure `GeoGate` policy
  (fail-open default, catches provider exceptions, never throws). Unit 104→127, all green.
  **Next:** Task 20 (wire GeoGate into `IssuanceService` after captcha/before rate-limit + REST 403 +
  porto-form.js message + SettingsPage geo fieldset + integration), then WS3 SEC → WS3 done → STOP CONDITION.
- **2026-06-25 · PHASE B iter 10 — WS4 DONE.** WS4 security review: **0 crit/high/med/low** (destructive
  actions correctly cap+nonce+confirm-gated, no CSRF, esc_like'd LIKE, salt-safe; clarified the global
  `wp_cache_flush`). WS4 live DR smoke passed on the real DB (export → `deleteAllData` DROP+recreate+new salt
  → import full_restore → all real data + original salt back; net-zero). Suites: unit 104, integration 41.
  **3 of 4 workstreams done (WS2, WS1, WS4).** Next: WS3 (geo-restriction, HARD-STOP territory) — Tasks 17–20,
  all sources default-OFF / sign-off-gated.
- **2026-06-25 · PHASE B iter 9 — WS4 Task 16 DONE; WS4 code-complete.** ToolsPage reset (preserve salt) /
  delete-all (purge → recreate → new salt) business methods + guarded handlers (cap+nonce+confirm+PRG) +
  `plugin_action_links` pre-removal link. Integration 39→41; unit 104; all green.
  **Next:** WS4 SECURITY REVIEW (destructive actions) + WS4 live smoke (export⇄wipe⇄re-import) → WS4 done,
  then WS3 (geo) Tasks 17–20.
- **2026-06-25 · PHASE B iter 8 — WS4 Tasks 14–15 DONE.** (14) `DataEraser::purgeAll` — single purge def
  (tables/options/transients/cron incl. the WS1 notify keys, resolving that deferred low); (15) `uninstall.php`
  now delegates to it (real-file integration test proves zero residue). Two harness fixes: drop WP's
  `_drop_temporary_tables` filter so the real DROP runs; `wp_cache_flush()` after raw LIKE deletes (cache
  coherence). Integration 37→39, all green; unit 104.
  **Next:** Task 16 (ToolsPage reset / delete-all buttons + `plugin_action_links`), then WS4 SEC + live smoke → WS4 done.
- **2026-06-25 · PHASE B iter 7 — WS1 DONE.** Task 13 wired AdminNotifier into `IssuanceService::confirm`
  (nullable observer) + SettingsPage fieldset + integration test (real-WP `pre_wp_mail` capture). WS1 security
  review: 0 crit/high, 1 med (uncaught `onIssued` exception reaching the visitor → **fixed** with try/catch +
  test), 1 low (notify-state uninstall cleanup → deferred to WS4 Task 14, D24.1). Suites: unit 104, integration 37.
  **Next:** WS4 — Task 14 (`DataEraser::purgeAll`, incl. the WS1 notify keys), then Tasks 15–16 + WS4 SEC.
- **2026-06-25 · PHASE B iter 6 — WS1 Tasks 10–12 DONE.** (10) Settings `admin_notify_*` keys; (11)
  `Mailer::sendAdminNotification` (PII-free by default, German); (12) `AdminNotifier` rolling-cooldown
  throttle behind `NotifyThrottleStore` seam (+ `WpNotifyThrottleStore`). Unit 91→104, all green.
  **Next:** Task 13 (wire AdminNotifier into `IssuanceService::confirm` success + SettingsPage fieldset +
  integration test), then WS1 SECURITY REVIEW → WS1 done. NB: Task 14 DataEraser must purge
  `porto_notify_pending` (option) + `porto_notify_cooldown` (transient) — see D24.1.
- **2026-06-25 · PHASE B iter 5 — WS2 DONE.** Security review (adversarial subagent over the 17-file diff):
  0 crit/high/med, 5 low. Fixed the top low (full_restore now whitelists imported settings keys —
  `ImportService::sanitizeImportedSettings`, +1 unit test); deferred 4 lows w/ justifications in SECURITY.md.
  WS2 end-to-end live DR smoke passed on the real wp-env DB (backup→wipe→restore preserved 5 real codes +
  salt; marker round-tripped; net-zero cleanup). Suites green: unit 91, integration 34.
  **Next:** WS1 — Task 10 (Settings keys for admin notifications), then Tasks 11–13 + WS1 SEC.
- **2026-06-25 · PHASE B iter 4 — Task 9 DONE; WS2 code-complete.** ToolsPage (Export/Import admin page:
  streamed downloads, cap+nonce, unencrypted-bundle confirm, 10 MB upload cap + is_uploaded_file) + wired
  in Plugin; CodeIntakePage CSV upload (importCsvFile + admin_post + form). Integration 30→34; full suites
  green (unit 90, integration 34). Live `wp eval-file` smoke OK (bundle/CSV build live; schema_version=1).
  **Next:** WS2 SECURITY REVIEW gate (security-review the WS2 diff → SECURITY.md; fix crit/high) + the WS2
  end-to-end live smoke (export⇄wipe⇄re-import through the UI), then WS2 done → start WS1 (Task 10).
- **2026-06-25 · PHASE B iter 3 — Tasks 7–8 DONE.** (7) ExportService (per-table CSV + lossless bundle
  builders; added repo `allRows()` + `Settings::toArray()`); (8) ImportService (full_restore + data_merge;
  validation-before-destruction; repo `deleteAll()`/`insertRows()` with column allowlist; D15.1/D15.2).
  **Salt-portability round-trip proven** in integration (export→wipe+new salt→full_restore→source salt &
  token restored). Unit 81→90, integration 27→30, all green; no regressions.
  **Next:** Task 9 (ToolsPage export/import UI + admin-post streaming + CodeIntakePage CSV upload), then the
  WS2 SECURITY REVIEW gate → WS2 done.
- **2026-06-25 · PHASE B iter 2 — Tasks 4–6 DONE.** (4) CodesCsvImporter (per-row addBatch, exact skip
  attribution — D13.1); (5) BundleSerializer (lossless JSON incl. salt, version-guarded); (6) BundleCrypto
  (optional libsodium passphrase encryption, no new dep). Unit 65→81, all green; no regressions.
  **Next:** Task 7 (ExportService — collect + CSV/bundle builders + repo allRows accessors), then Task 8
  (ImportService). Both need new repo read-all accessors + integration tests (wp-env up).
- **2026-06-25 · PHASE B iter 1 — Tasks 1–3 DONE.** (1) SchemaVersion + migration runner wired into
  `activate()`; (2) CsvWriter (formula-injection-safe RFC-4180 writer); (3) CsvReader (strict, order-
  tolerant, capped). Unit 48→65, integration 23→27, all green; no regressions. wp-env up.
- **2026-06-25 · PHASE A complete.** Brainstormed all four workstreams autonomously; wrote spec
  (`docs/superpowers/specs/2026-06-25-data-lifecycle-design.md`), `DECISIONS.md` (D01–D47 + HARD-STOP
  register), `PLAN.md` (20 tasks + 4 per-WS security gates + final stop-condition), `SECURITY.md` (empty).
  Baseline confirmed: clean tree on `feat/data-lifecycle` (jj). **Next:** PHASE B — Task 1 (SchemaVersion +
  migration runner). Build order WS2 → WS1 → WS4 → WS3.

## Workstream status
- WS2 (portability / spine): **DONE** ✅ — Tasks 1–9 + security review (5 low, 1 fixed/4 deferred) + live DR smoke.
- WS1 (admin notification): **DONE** ✅ — Tasks 10–13 + security review (1 med fixed, 1 low deferred to WS4).
- WS4 (uninstall & lifecycle): **DONE** ✅ — Tasks 14–16 + security review (0 findings) + live DR smoke.
- WS3 (geo, default-off): **code-complete** — Tasks 17–20 ✅; WS3 SECURITY REVIEW + live smoke pending.
- FINAL stop-condition: pending.

## Open sign-off items (shipped disabled; do not enable unattended)
- WS3 MaxMind provider (new dep + licensed data) — D42.
- WS3 third-party API provider (outbound visitor IP) — D42.
- WS2 unencrypted bundle (secret salt + PII) — allowed only behind explicit confirm — D11.
