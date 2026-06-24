# PROGRESS

**Iteration log (newest first). One line: status + what's next.**

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
- WS2 (portability / spine): **code-complete** — Tasks 1–9 ✅; **WS2 SECURITY REVIEW + end-to-end live smoke pending** before WS2 is marked done.
- WS1 (admin notification): not started — Tasks 10–13 + SEC.
- WS4 (uninstall & lifecycle): not started — Tasks 14–16 + SEC.
- WS3 (geo, default-off): not started — Tasks 17–20 + SEC.
- FINAL stop-condition: pending.

## Open sign-off items (shipped disabled; do not enable unattended)
- WS3 MaxMind provider (new dep + licensed data) — D42.
- WS3 third-party API provider (outbound visitor IP) — D42.
- WS2 unencrypted bundle (secret salt + PII) — allowed only behind explicit confirm — D11.
