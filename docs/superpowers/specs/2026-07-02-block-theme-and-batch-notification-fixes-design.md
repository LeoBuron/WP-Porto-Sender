# WP-Porto-Sender — Block-theme built-in pages & batch admin notification

- **Date:** 2026-07-02
- **Status:** Implemented; pending review + release decision
- **Follows:** 0.5.1 (default texts visible & editable)

Two post-0.5.1 bug reports:

1. "Die Plugin-Standardseiten sind leer." The built-in "sent"/result pages render empty/broken.
2. "Das Senden von mehreren Namen und Mailadressen im Batch an den Admin funktioniert nicht."

## Bug 1 — built-in pages empty on block themes

### Root cause (reproduced)
`PageRenderer::renderThemed()` rendered the built-in views with the classic
`get_header()`/`get_footer()`. Block (FSE) themes — the WordPress default since Twenty
Twenty-Two, and what the production site runs — have no `header.php`/`footer.php`, so those
calls fall back to WordPress's ancient **theme-compat** templates: the page shows the
Kubrick-era "proudly powered by WordPress" fallback with none of the site's real header,
navigation, footer or styling, plus a PHP "Theme without header.php is deprecated" notice
leaking into the output. Confirmed against Twenty Twenty-Five in wp-env: the notice text was
present but the page had zero theme chrome.

### Fix
`renderThemed()` delegates to a testable `themedDocument(string): string`:
- **Classic themes:** unchanged (`get_header()` + `<main>` + `get_footer()`).
- **Block themes** (`wp_is_block_theme()`): assemble the document — `<!DOCTYPE html>`,
  `language_attributes()`, `wp_head()`, `body_class('porto-builtin-page')`, `wp_body_open()`,
  `block_header_area()`, a `do_blocks()`-rendered constrained `<main>` holding the notice,
  `block_footer_area()`, `wp_footer()`. This renders the real theme header/footer/styles and
  emits no deprecation notice.

All block-theme functions used are available in WP ≥ 6.1 (plugin requires 6.4). Verified over
HTTP: the page grew from ~44 KB (compat fallback) to ~84 KB with real Twenty Twenty-Five chrome,
no deprecation, no Kubrick fallback.

## Bug 2 — batch admin notification dropped names/emails

### Root cause
The throttle collapses a burst of claims into one mail but only carried a `pending` **count**;
`AdminNotifier` discarded the name/email of each claim seen during the cooldown and passed only
the triggering claim's single name/email. So a batch mail said "count: N" but named ≤ 1 person.

### Fix
- `NotifyThrottleStore` gains `pendingRequesters()` / `setPendingRequesters(list<{name,email}>)`.
- `WpNotifyThrottleStore` persists them in a new **autoload=false** option
  `porto_notify_pending_requesters`; `setPendingRequesters([])` **deletes** the option so no PII
  sits at rest once the batch is flushed. `DataEraser` (and thus uninstall) purges it.
- `AdminNotifier` accumulates a requester **only when `admin_notify_include_pii` is on**, across
  the window, and passes the full list. PII-off path stores/sends nothing (still PII-free).
- `Mailer::sendAdminNotification` takes `data.requesters`; new `%requests%` placeholder renders
  the list (`- Name <email>` per line). `%name%`/`%email%` resolve to the first claimant
  (count=1 templates unchanged). Default body: 1 claimant keeps `Anfrage von: %name% <%email%>`,
  >1 uses `Anfragen:\n%requests%`. Backward-compatible with the legacy single `name`/`email`
  shape (requires both). Mail stays plain-text.

### Privacy note
PII-at-rest is bounded: written only under the admin opt-in, cleared on flush / erase / uninstall.
If the site goes idle mid-window the option persists until the next claim or a data erase — the
same lifetime as the pre-existing `pending` count, and the admin has opted into receiving this PII
by email regardless.

## Testing
- Unit: block-vs-classic branch of `themedDocument` (block uses `block_*_area`, never
  `get_header`); `AdminNotifier` PII-off stores nothing, PII-on burst lists every claimant on
  carry-over; `Mailer` batch lists all, single keeps one-line wording, `%requests%` in custom
  templates, empty list stays PII-free.
- Integration (real WP + real store): built-in view on a block theme has no compat fallback;
  full issuance flow — four distinct claimants in one window produce one carry-over mail naming
  Bob/Cara/Dan.

## Assumptions
1. "Empty pages" = the block-theme compat-fallback rendering (reproduced), not a text-resolution
   bug (text resolution verified correct).
2. Batch = the throttle window collapsing multiple claims; the fix lists all claimants gathered
   in the window, respecting the existing PII opt-in.
3. No version bump here — release is the user's explicit decision.

## Follow-up (0.5.3): content blank on block themes

After 0.5.2, the built-in pages showed the theme header/footer/navigation but **no content** on the
production site. Root cause: `themedDocument()` rendered the notice through `do_blocks()` (a synthetic
`wp:group` wrapping freeform HTML) to pick up the theme's constrained width. `do_blocks()` renders the
notice fine on a clean install (verified in wp-env), but a plugin that disables/filters block rendering
— the production site runs several feature-disabling plugins — can blank that wrapper, while
`block_header_area()`/`block_footer_area()` keep working (they render template parts directly). Symptom:
chrome present, content gone.

Fix: drop `do_blocks()` entirely. The notice is emitted directly inside a plain, inline-centred
`<main class="porto-page" style="max-width:640px;margin:0 auto;padding:…">`, so nothing depends on block
rendering. Also refreshed the default wording (and confirmed every page text stays editable on the Seiten
tab): `text_page_sent` → "Wir haben deine Anfrage erhalten. …" and `text_status_issued` → "Du hast deine
Mail-Adresse erfolgreich bestätigt. …". Because unchanged defaults normalise to `''`, the new wording
reaches existing installs that never customised these fields.

## Review

Adversarial multi-dimension review (correctness / security-privacy / wp-compat, find → verify).
Six findings, all in FIX 2's PII lifecycle, all fixed:
- **Major** — turning the PII opt-in OFF mid-window still sent (and re-persisted) the accumulated
  claimant list, because the gate only covered the current event. Fixed: `AdminNotifier` re-gates the
  accumulated list on `adminNotifyIncludePii()` at use time; when off it neither loads, sends, nor
  re-persists it (the stored option is deleted on the next event).
- **Minor** — switching the window to 0 stranded the accumulated PII. Fixed: the window≤0 branch now
  drains the pending batch after sending the individual claim.
- **Minor** — stranded PII could outlive `piiRetentionDays` on a quiet site. Fixed: the daily
  `Maintenance` cron calls `AdminNotifier::purgeStalePendingBatch()`, which drops any batch whose
  window has elapsed — bounding PII-at-rest to one maintenance cycle.
- **Minor** — `MailerInterface` docblock still advertised the old `name`/`email` shape. Fixed to the
  `requesters` shape.
Trade-off recorded: a batch abandoned before its next carry-over (window changed to 0, or a full quiet
maintenance cycle) is dropped rather than mailed late — its accumulated claims are not reported, which
is preferred over retaining PII. FIX 1 (block-theme rendering) drew no findings.
