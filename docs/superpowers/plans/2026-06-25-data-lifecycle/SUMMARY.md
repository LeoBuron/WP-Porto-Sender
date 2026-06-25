# SUMMARY — Data-lifecycle autonomous build (WS1–WS4)

**Status: COMPLETE.** All four workstreams built, self-tested, and security-reviewed end-to-end across
13 autonomous PHASE-B iterations on bookmark `feat/data-lifecycle`. Not pushed/merged (awaiting sign-off).

## What shipped

| WS | Feature | Key deliverables |
|---|---|---|
| **WS2** | Data portability (the spine) | Schema-version + migration runner; formula-injection-safe CSV writer + strict reader; codes CSV importer; lossless JSON bundle (carries `hash_salt`) with optional libsodium passphrase encryption; ExportService (streamed, never web-readable) + ImportService (full-restore / data-merge); `ToolsPage` Export/Import UI + CodeIntakePage CSV upload. **Salt-portability solved**: a full restore carries+restores the salt so dedup/tokens/abuse-audit survive a migration. |
| **WS1** | Admin notification email | `Mailer::sendAdminNotification` (German, **PII-free by default**); `AdminNotifier` rolling-cooldown throttle (a burst of claims → one mail reporting its true count) behind a `NotifyThrottleStore` seam; wired into `IssuanceService::confirm` on successful issue. |
| **WS4** | Uninstall & data lifecycle | `DataEraser::purgeAll` — single source of truth for "all plugin data" (tables, options, transients, cron), shared by `uninstall.php` (now complete) and the admin delete-all button; reset-settings (preserves salt) / delete-all (purge → recreate → fresh salt); pre-removal `plugin_action_links` link. |
| **WS3** | Geo-restriction (DE only) | Pluggable `GeoProvider` (Null / Cloudflare-header / MaxMind / API) + fail-safe factory + pure `GeoGate`; wired after captcha / before rate-limit → HTTP 403; SettingsPage fieldset. **Default OFF; all external sources disabled / sign-off-gated.** |

## Verification (evidence in PLAN.md per task)

- **Unit:** `composer test:unit` → **OK (129 tests, 351 assertions)** (was 48 at branch start).
- **Integration (wp-env):** `npm run test:integration` → **OK (43 tests, 143 assertions)** (was 23).
- **Live end-to-end smokes (real wp-env runtime, `wp eval-file`):**
  - WS2: export → wipe + change salt → full-restore restored real data **and the source salt** (DR round-trip).
  - WS1: confirm→issue fired exactly one admin mail ("Porto abgerufen"), PII-free.
  - WS4: `deleteAllData` DROP+recreate+new-salt → import full-restore restored real data + original salt (net-zero).
  - WS3: geo gate blocks FR / allows DE; live default geo OFF → transparent allow-all.
- **Security:** per-workstream reviews + a **final whole-branch review → 0 Critical / 0 High / 0 Med / 0 Low.**
  Findings fixed along the way: import settings-key whitelist (WS2), `onIssued` try/catch so a notifier
  failure can't break a completed issuance (WS1), `wp_cache_flush` after raw deletes (WS4),
  `wp_safe_remote_get` SSRF-guard on the geo API (WS3). See SECURITY.md.
- **DSGVO posture:** salted hashes only; PII-free admin mail by default; geo default-off = no IP→country
  processing; export bundle treated as a credential (streamed, optional encryption, explicit confirm);
  complete uninstall (no PII residue). Legal basis for IP geo: Art. 6(1)(f) (documented).

## OPEN — needs sign-off before enabling (shipped DISABLED-BY-DEFAULT)

These are the HARD-STOP items; the gate/flow is built + tested, the source stays off pending your decision:

1. **WS3 MaxMind GeoLite2 provider** — needs a new runtime dependency (the reader library) **and** the
   licensed `.mmdb` data file. Neither is shipped; `available()` stays false until you install both.
2. **WS3 third-party geo API provider** — sends the **visitor IP to a third party** (new outbound flow;
   needs an AVV/DPA + privacy-policy disclosure). Off until you set a URL + key and enable it.
3. **WS3 Cloudflare header provider** — zero-dependency but trusts a spoofable proxy header; requires the
   admin acknowledgement that the origin is locked to Cloudflare. Default off.
4. **WS2 unencrypted bundle** — contains the secret salt + PII; allowed only behind an explicit
   confirmation. Prefer the passphrase-encrypted bundle.

## Deferred (logged, non-blocking)

- WS2 `data_merge` import with colliding source ids silently skips (warned best-effort path; full-restore
  is the supported lossless path) — see SECURITY.md WS2.

## How to integrate

Branch `feat/data-lifecycle` is ready for review. Not pushed/merged per the loop contract. Suggested next
step: open a PR for human review, decide the WS3 sign-off items, then merge.
