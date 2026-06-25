# SECURITY findings log

Per-workstream + final whole-branch security review. Append EVERY finding as:

`[crit|high|med|low] file:line — issue — exploit scenario — fix — status(fixed|accepted|deferred + why)`

Rules: fix all crit/high and re-run the relevant tests before checking a workstream done; med/low may be
logged + deferred with a one-line justification. Re-review after fixes. STOP CONDITION requires zero open
crit/high on a final whole-branch review.

## Checklist applied each review (WordPress/plugin)
- AuthZ: every admin action / REST route / AJAX handler enforces `current_user_can()` + nonce
  (`check_admin_referer`/`wp_verify_nonce`/real `permission_callback`). Public REST stays public but gated
  by captcha + geo + rate-limit.
- SQL: all queries via `$wpdb->prepare`; never interpolate identifiers/values from input.
- Output/input: `esc_html/esc_attr/esc_url` on render; `sanitize_*` on input.
- CSV export → formula injection: prefix any cell starting with `= + - @` (or tab/CR) with `'`.
- CSV/file import: validate type+size, never trust columns, no path traversal, reject PHP/serialized
  payloads, cap row count, store/handle uploads outside the web root.
- Export of PII + `hash_salt` = secret + PII leak surface: no unauthenticated/guessable download URLs,
  never web-readable, strong cap gate, treat `hash_salt` as a credential.
- Geo gate not bypassable via spoofed proxy headers; its fail-mode must not disable the other gates.
- Destructive actions: nonce + cap + confirmation; no CSRF path. Secrets never logged; errors never leak
  internals.

---

## Findings

_None yet — populated during PHASE B per-workstream reviews._

### WS2 — reviewed 2026-06-25 (adversarial subagent over the 17-file WS2 diff)

**Result: 0 crit, 0 high, 0 med, 5 low.** No crit/high → WS2 not blocked. Categories with NO issues:
SQL injection (table names from constants; `insertRows` column-allowlisted; `$wpdb->prepare`/`$wpdb->insert`),
reflected XSS (notices `esc_html`'d, `$_GET` cast to int), CSV formula injection (`CsvWriter::escapeCell`
applied to every cell of both exports), object injection / path traversal / file-write (no `unserialize`/
`eval`; json/fgetcsv only), salt/PII leak (exports streamed, never web-readable; salt never logged;
authenticated sodium encryption + `memzero`; unencrypted bundle gated by explicit confirm), CSRF / non-admin
wipe (cap+nonce on all handlers; validation precedes destruction), open redirect (fixed `admin_url` targets).

- [low] src/Portability/ImportService.php:58 — full_restore wrote the bundle's settings array verbatim
  (no key whitelist) — a crafted bundle an admin restores could inject arbitrary option keys / set
  alert_email etc. (admin-authorized, output-escaped downstream → low) — whitelist imported keys against
  `Settings::defaults()`, merge over defaults, keep hash_salt — **status(fixed)**: added
  `sanitizeImportedSettings()` (drops unknown keys) + unit test
  `test_full_restore_drops_unknown_settings_keys_but_keeps_known`; suites green (unit 91, integration 34→re-run 6/6).
- [low] src/Admin/ToolsPage.php:132 — import-failure notice concatenates raw `$e->getMessage()` (then
  `esc_html`'d at render) — messages are plugin-controlled strings (BundleSerializer/BundleCrypto/
  ImportService), no path/secret leakage; they are useful failure reasons for the admin — **status(accepted)**:
  intentional, escaped, controlled, no disclosure.
- [low] src/Portability/CsvReader.php:27 — whole upload read into memory before the 5000-row cap — peak
  memory is already bounded by the byte caps (10 MB bundle / 5 MB CSV) and the action is admin-only —
  **status(accepted)**: byte cap bounds it; negligible DoS for an authenticated admin action.
- [low] src/Admin/CodeIntakePage.php:57,64 — `check_admin_referer` before `current_user_can` (reverse of
  ToolsPage) — not exploitable (nonce wp_die's first; nonces are per-user; the nonce-minting page is
  cap-gated) and consistent with the pre-existing `porto_intake` handler in the same file —
  **status(accepted)**: house-consistent, not exploitable.
- [low] src/Inventory/CodeRepository.php / src/Requests/RequestRepository.php insertRows — `id` is in the
  column allowlist, so data_merge silently skips rows whose source id collides with a local id — correctness
  nit on the explicitly warned best-effort merge path (D15), not a security issue (id is parameterized; no
  injection); full_restore (primary path) inserts into emptied tables so ids are clean —
  **status(deferred)**: merge is a warned secondary path; dropping `id` for merge is a future enhancement.
### WS1 — reviewed 2026-06-25 (adversarial subagent over the WS1 diff)

**Result: 0 crit, 0 high, 1 med, 1 low.** No crit/high → WS1 not blocked. Categories with NO issues:
recipient safety (mail only to `sanitize_email`'d `alert_email`, never visitor-controlled; no new third-party
outbound), header/content injection (plain-text body, no `$headers` arg, visitor `name`/`email` only in body —
no CRLF header injection, no HTML/XSS surface), PII/DSGVO (name/email nulled at the producer when
`admin_notify_include_pii` off, default off), fieldset output escaping (`esc_attr`/`checked`/`esc_html`;
sanitize casts + `absint`), throttle key integrity (constant option/transient names, no user input).

- [med] src/Issuance/IssuanceService.php:126 — an exception thrown in `onIssued` (e.g. an SMTP plugin that
  throws instead of returning false) propagated uncaught out of `confirm()`→`process()`→`maybeHandle()`
  (a `template_redirect` callback) as a fatal to the visitor, AFTER the code was already issued — a
  successful claim would look "failed" (support ticket / wasted retry) though the data state is consistent —
  wrap the `onIssued` call in `try/catch (\Throwable)` that logs and swallows so a non-critical notification
  failure never affects the completed issuance — **status(fixed)**: added try/catch + `error_log`; integration
  test `test_notifier_failure_does_not_break_issuance` (throwing mailer → still `issued`). Suites green.
- [low] uninstall.php — `porto_notify_pending` (option) + `porto_notify_cooldown` (transient) are not yet
  purged on uninstall — orphaned residue after removal (tiny, bounded; transient self-expires) — add to the
  WS4 DataEraser/uninstall — **status(fixed in WS4 Task 14)**: `DataEraser::purgeAll` deletes
  `porto_notify_pending` (option) + `porto_notify_cooldown` (transient); proven by `DataEraserTest` and the
  real-`uninstall.php` `UninstallCompletenessTest`.
### WS4 — reviewed 2026-06-25 (adversarial subagent over the WS4 diff)

**Result: 0 crit, 0 high, 0 med, 0 low.** Fully clean — nothing blocks WS4. Verified:
- **AuthZ on destructive actions**: `handleReset`/`handleWipe` call `assertAllowed()` FIRST → `current_user_can('manage_options')` (wp_die 403) then `check_admin_referer($nonce)`, then the required `confirm` checkbox, then the destructive call. Correct order; a non-admin or forged request is stopped before any wipe.
- **CSRF**: nonce-bound (`porto_reset`/`porto_wipe`); a GET/cross-site request without a valid action nonce dies.
- **SQLi**: DataEraser LIKE prefixes are constants, `esc_like`'d + `$wpdb->prepare`-bound; table names from constants; no input in any query.
- **Confirm bypass**: `empty($_POST['confirm'])` fails safe (`empty('0')===true`); failure branch ends in `exit`.
- **Salt**: reset reads the OLD salt before overwriting and never blanks it (regenerates only if empty); delete-all generates a fresh salt intentionally.
- **Output/JS**: render() forms fully escaped (`esc_html`/`esc_attr`/`esc_url`/`esc_js`/`wp_nonce_field`); per-user notice transient.
- **Post-wipe state**: `purgeAll → Schema::install → reseed(new salt) → SchemaVersion::set` leaves a functional install.

Non-blocking note (applied): clarified that `wp_cache_flush()` in `purgeAll` is intentionally global (also evicts object-cache-backed `porto_rl_*` transients the LIKE DELETE can't reach). The out-of-scope WS2 import-error reflection remains as logged in the WS2 section (low/accepted).
### WS3 — (pending)
### FINAL whole-branch — (pending)
