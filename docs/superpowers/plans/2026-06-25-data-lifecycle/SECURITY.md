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

### WS2 — (pending)
### WS1 — (pending)
### WS4 — (pending)
### WS3 — (pending)
### FINAL whole-branch — (pending)
