# Data-lifecycle autonomous build loop

This is the **loop spec** for building four feature workstreams in WP-Porto-Sender
end-to-end (admin notification email, data export/import, geo-restriction, uninstall &
data lifecycle). It is designed to be run via `/loop` so it resumes across iterations
until everything is implemented, self-tested, and security-reviewed.

## How to run

Start it with this short kickoff (it keeps the per-iteration prompt small and points the
loop at this file):

```
/loop Execute the autonomous build loop specified in docs/superpowers/prompts/2026-06-25-data-lifecycle-loop.md. Re-read that file in full at the start of every iteration (context may have reset) and follow its LOOP PROTOCOL exactly. Persist all state under docs/superpowers/plans/2026-06-25-data-lifecycle/. If no PLAN.md exists yet, begin with PHASE A. Stop the loop (don't schedule another iteration) only when the file's STOP CONDITION is fully met.
```

Let it self-pace (no fixed interval) — each iteration does substantial work. It commits to a
feature branch but never pushes/merges without sign-off.

---

## LOOP SPEC

```
ROLE: Autonomous build loop for WP-Porto-Sender (/Users/leo/work/WP-Porto-Sender). You will
brainstorm, plan, IMPLEMENT, SELF-TEST, and SECURITY-REVIEW four feature workstreams end-to-end,
across repeated loop iterations, until everything is built and verified. You run UNATTENDED: where
you would normally ask the user, instead pick the most reasonable interpretation, record it in a
DECISIONS log, and proceed — except for the explicit HARD-STOPS listed below.

═══ LOOP PROTOCOL (each iteration) ═══
State lives in docs/superpowers/plans/2026-06-25-data-lifecycle/ : PLAN.md (checkbox tasks +
per-task verification command + evidence slot), DECISIONS.md (every autonomous choice + rationale),
SECURITY.md (security findings log), PROGRESS.md (one-line status + what's next), SUMMARY.md
(written only at the very end).
1. Read PLAN.md/PROGRESS.md. If they don't exist → PHASE A. Else → PHASE B.
2. PHASE A (bootstrap, first iteration only):
   - Baseline: ensure you are on a feature branch (feat/data-lifecycle) with a clean tree; never
     work on detached HEAD/main directly.
   - Run the superpowers brainstorming skill, but autonomously: answer each open question yourself
     with the most reasonable, reversible choice; log every choice in DECISIONS.md. Then use the
     superpowers writing-plans skill to write a short spec (docs/superpowers/specs/) and a granular
     PLAN.md broken into small, independently testable tasks, each with an explicit verification
     command and a Definition of Done. End the iteration after the plan exists.
3. PHASE B (execute): take the next unchecked PLAN.md task. Use test-driven-development where it fits
   tests/. Implement it. SELF-TEST it (see harness) and capture real evidence. When a workstream's
   tasks are all functionally green, run its SECURITY REVIEW (see below) before marking the
   workstream done. Check tasks off with pasted evidence, update PROGRESS.md, checkpoint-commit.
   Do 1–3 tasks per iteration as context allows.
4. STOP CONDITION: when every PLAN.md task is checked AND a final full verification passes (whole
   PHPUnit suite green + one live end-to-end smoke per workstream via wp-env/Playwright + a final
   whole-branch security review with zero open Critical/High in SECURITY.md), write SUMMARY.md and
   DO NOT schedule another iteration — the loop is complete.
5. BACKSTOP: if one task fails verification across 3 consecutive iterations, stop fixing it (per
   systematic-debugging: 3+ failures = question the approach). Mark it BLOCKED in PLAN.md, explain
   in PROGRESS.md, and either route around it or end the loop with a clear writeup.
6. Cleanup every iteration: remove seeded test data + porto_rl_* transients; leave the dev DB and
   working tree clean except for intended changes.

═══ PROJECT CONTEXT (read before PHASE A; don't trust this summary blindly) ═══
WordPress plugin that emails visitors a single-use Deutsche Post Briefmarke code from a pre-purchased
pool, gated by ALTCHA + rate limiting + per-person dedup; DSGVO-first (salted hashes for
email/name/IP, PII retention + cron anonymization). PHP 8.1+, PSR-4 under src/PortoSender; tables
porto_codes + porto_requests; settings option porto_sender_settings.
Read: src/Settings/Settings.php · src/Persistence/Schema.php · uninstall.php · src/Plugin.php ·
src/Admin/{SettingsPage,CodeIntakePage,Dashboard}.php · src/Mail/Mailer.php ·
src/Inventory/StockAlerter.php · src/Cron/Maintenance.php · src/Issuance/IssuanceService.php ·
src/Limiting/RateLimiter.php · docs/superpowers/specs + plans (conventions).
DSGVO/Datenschutz is a first-class constraint in every workstream — it is this plugin's identity.

═══ AUTONOMY GUARDRAILS ═══
Decide-and-log for anything reversible. HARD-STOPS you may NOT enable unattended — implement them
DISABLED-BY-DEFAULT behind a setting/flag, document the open decision in DECISIONS.md, and keep
going: (a) adding a new runtime dependency; (b) any new outbound data flow that sends visitor data
to a third party (e.g. a geo API); (c) shipping a licensed data file (e.g. MaxMind GeoLite2);
(d) any default that destroys data without explicit user action. Never ship a third-party data flow
turned on without sign-off.

═══ WORKSTREAMS (build order: WS2 core → WS1 → WS4 → WS3) ═══
WS1 — Admin notification email.  "Aktivieren, dass man als Admin ne Email bekommt, wenn Leute ein
Porto abrufen."  Decide: trigger on submit vs confirm/issue vs both; reuse settings.alert_email or
separate recipient; PII in the mail; throttle/digest so a burst of submits ≠ a burst of mails (note
rate-limiter interaction). Reuse Mailer + StockAlerter.

WS2 — Data portability (the spine).  "Alles exportieren und manuell über CSV neu einlesen." /
"Export aller Daten und Import für neue Version." / "Der Stand muss bei einem Update übernommen
werden."  Must address: scope = porto_codes + porto_requests (incl. raw name/email AND salted
hashes) + settings; CSV import of codes is new/richer than today's one-per-line textarea; THE
SALT-PORTABILITY PROBLEM — all hashes depend on settings.hash_salt, so re-import into a fresh install
with a new salt silently breaks dedup/token matching → export+restore the salt OR re-hash OR accept
the break (decide); DSGVO of a plaintext-PII export (who triggers, where it lands, securing it,
erasure); "survive updates" is mostly automatic (WP updates don't touch the DB; uninstall runs only
on Delete) so the real work is a schema_version option + migration step in activate(), plus not
letting WS4 wipe data — frame export/import as backup+migration+disaster-recovery; formats: per-table
CSV (human edit, mainly codes) + a single lossless bundle incl. salt for round-trip migration.

WS4 — Uninstall & data lifecycle.  "Vollständig entfernen beim Deinstallieren. Button für Löschen
aller Einstellungen." / "Beim Deinstallieren gefragt werden, ob man die Daten exportieren will."
Must address: audit uninstall.php for completeness (it drops tables + deletes the settings option +
legacy lowstock options today — also handle porto_rl_* transients, cron events even if user deletes
without deactivating, any other options/caps); HARD CONSTRAINT — uninstall.php runs with NO UI, so
"ask to export before uninstall" CANNOT live there: build it as a pre-delete UI flow (a Settings/Tools
"Export & prepare for removal" screen, or intercept Delete on the plugins page) that depends on WS2's
export; a "delete all settings/data" admin button (scope, nonce, capability, confirm, post-delete
state). Story: export (WS2) ⇄ wipe (WS4) ⇄ re-import (WS2).

WS3 — Geo-restriction, Germany only.  "IPs nur aus Deutschland zulassen."  HARD-STOP territory:
implement as a pluggable geo-source gate, DEFAULT OFF / allow-all, with providers selectable in
settings — Cloudflare CF-IPCountry header (only valid behind CF; it IS a proxy header → trust note),
MaxMind GeoLite2 local DB (license → don't ship without sign-off), third-party API (outbound IP →
sign-off). Build + test the GATE and the message/HTTP code and fail-open-vs-closed and the
false-positive policy (DE users on VPN/CGNAT/travel); leave the actual external sources disabled
pending sign-off. Decide gate placement vs captcha/rate-limit. Document the IP-processing legal basis.

═══ SELF-TEST HARNESS (functional verification — evidence, not assertions) ═══
- PHPUnit: composer test:unit ; npm run test:integration (wp-env tests-cli phpunit). Add tests with
  each task (TDD).
- Live WordPress: `npx wp-env start`. WP-CLI via `npx wp-env run cli wp ...`. After (re)installing the
  plugin, REACTIVATE it via WP-CLI so the activation hook runs (Schema::install + seeds + hash_salt);
  wp-env's auto-activation does NOT fire it.
- PHP probes: write a temp _verify_*.php in the repo, run `wp eval-file wp-content/plugins/
  wp-porto-sender/_verify_*.php`, then delete it. REST: curl `http://localhost:8888/?rest_route=/...`.
- Front-end: Playwright (installed; chromium installed) drives the real form/admin in a browser and
  captures console + DOM as evidence. A test page with [porto_request] can be created via WP-CLI.
- Pre-clean porto_rl_* transients before rate-path tests; clean all seeded data after.
Each completed task MUST carry a captured command+output as evidence in PLAN.md. No "looks correct."

═══ SECURITY REVIEW (per workstream + final) — write findings to SECURITY.md ═══
After a workstream's tasks pass functional tests, security-review its new/changed code before marking
the workstream done — use the security-review skill (review pending changes on the branch), or
dispatch a security-focused review subagent. Append EVERY finding to SECURITY.md as:
  [crit|high|med|low] file:line — issue — exploit scenario — fix — status(fixed|accepted|deferred + why)
Fix all crit/high and re-run the relevant tests before checking the workstream done; med/low may be
logged + deferred with a one-line justification. Re-review after fixes. Run a final whole-branch
security review as part of the STOP CONDITION; zero open crit/high to finish.
WordPress/plugin checklist to apply (not exhaustive):
- AuthZ: every admin action, REST route, and AJAX handler enforces current_user_can() with the right
  capability AND a nonce (check_admin_referer / wp_verify_nonce / a real permission_callback). Public
  REST stays public by design but gated by captcha + rate-limit + geo.
- SQL: all queries via $wpdb->prepare; never interpolate identifiers/values from input.
- Output/input: esc_html/esc_attr/esc_url on render; sanitize_* on input.
- CSV export → spreadsheet formula injection: prefix any cell starting with = + - @ (or tab/CR) with '.
- CSV/file import: validate type + size, never trust columns, no path traversal, reject
  PHP/serialized payloads, cap row count; store uploads outside the web root.
- Export of PII + hash_salt is a SECRET + PII leak surface: no unauthenticated or guessable download
  URLs, never leave export files web-readable, strong capability gate, and treat hash_salt as a
  credential (its leak weakens every hash → decide encryption/scoping of the bundle).
- Geo gate must not be bypassable via spoofed proxy headers, and its fail-mode must not silently
  disable the other gates.
- Destructive actions (delete-all button, uninstall-export flow): nonce + capability + confirmation;
  no CSRF path. Secrets never logged; error output never leaks internals.

═══ COMMIT / DELIVERABLES ═══
Work on a feature branch; make a checkpoint commit per verified task (end commit messages with the
standard Co-Authored-By line). Do NOT push, open a PR, or merge to main without explicit sign-off.
Final deliverables: working code for all four workstreams (WS3 sources disabled-by-default), tests
green, spec + PLAN.md (all checked, with evidence) + DECISIONS.md + SECURITY.md + SUMMARY.md committed.
```
