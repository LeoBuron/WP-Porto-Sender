# WP-Porto-Sender — Default texts visible & editable everywhere

- **Date:** 2026-07-02
- **Status:** Implemented autonomously (assumptions recorded below)
- **Depends on:** 0.5.0 tabbed settings (spec 2026-07-01).

## Problem

Two gaps from the 0.5.0 settings work:

1. The **E-Mails tab** renders empty boxes when no custom template is stored; the built-in
   German copy lives hardcoded in `Mail\Mailer` and is invisible to the admin. To tweak one
   word the admin would have to re-type the whole message from scratch.
2. The **built-in "sent"/"result" pages** show hardcoded strings
   (`PageRenderer::SENT_MESSAGE`, `ConfirmHandler::MESSAGES`) that are not editable at all —
   and there is no documentation of what the default pages contain, so the admin cannot
   recreate/adjust them as custom WP pages either.

## Design

### A. E-mail boxes prefilled with built-in defaults

- **New `src/Mail/EmailDefaults.php`** — the single source of the ten default templates
  (`__()`-wrapped), keyed by the existing `Settings::EMAIL_KEYS`. The admin-notification
  default body stays PII-free (the "Anfrage von" line is appended dynamically by `Mailer`).
- `Mailer::compose()` falls back to `EmailDefaults` instead of inline literals.
- `SettingsPage::renderEmails()` shows the stored value if non-empty, else the default —
  every box always displays the effective text.
- `Settings::sanitize()` **normalizes back to `''`** when a submitted template equals its
  built-in default (compared after identical sanitization). Consequences:
  - Saving without touching a field keeps "use plugin default" semantics: future default
    improvements still arrive, and the admin mail's dynamic PII-line behaviour is preserved.
  - Clearing a field and saving resets it to the default (box shows default again).
- Tab description updated to explain both behaviours.

### B. Page texts editable on the Seiten tab

- `Settings::TEXT_DEFAULTS` gains seven keys (plain German strings, consistent with the
  existing form-text defaults):
  - `text_page_sent` — the "check your e-mail" notice.
  - `text_status_issued`, `text_status_already_issued`, `text_status_expired`,
    `text_status_out_of_stock`, `text_status_email_failed`, `text_status_invalid_token`.
- `ConfirmHandler::MESSAGES` (status → hardcoded string) becomes `ConfirmHandler::STATUSES`
  (allow-list only); `PageRenderer` resolves texts via `Settings::text('text_status_…')`,
  unknown status → `invalid_token` text (allow-list preserved).
- The Seiten tab renders a "Texte der Standardseiten" fieldset with the seven prefilled
  fields; these texts also feed the notice injected into custom override pages.
- `Settings::sanitize()` applies the **same `equals-default → ''` normalization to every
  `TEXT_DEFAULTS` key** as the e-mail templates get (not just the seven new ones — the
  pre-existing form-text fields were prefilled too and had the same freeze risk). Without
  this, prefilled defaults would be frozen into the option on the first save (all tabs post
  in one form), so future default/translation improvements would never reach the install.
  Single-line fields use `sanitize_text_field`; the two multi-line fields (`text_intro`,
  `text_consent`) are CRLF-normalized before comparison.

### C. Template documentation for the default pages

- New `docs/seiten-vorlage.md` (German): what the two default pages render (HTML skeleton),
  the full status → text table, the query-arg flow (`porto_view`, `porto_status`), and
  copy-paste templates for building custom override pages, including the fact that the
  plugin **always prepends** its notice above the whole page content (the result-page
  template makes this explicit rather than implying placement control).
- The Seiten-tab help text does **not** point admins to an in-plugin file path: `docs/` is
  excluded from the release ZIP (`bin/build-zip.sh`), so a production install would not
  contain it. The on-screen hint instead directs admins to the editable fields below; the
  markdown is a repo/handoff reference.

## Non-goals

- No rich-text/HTML e-mails; no new option rows; no migration (new keys default via the
  existing `array_merge` in `Settings::__construct`).

## Testing

- Unit: `EmailDefaults` covers all `EMAIL_KEYS`; sanitize normalization for e-mail templates
  and text keys (default → `''`, CRLF default → `''`, custom kept, cleared → `''`,
  absent-key never freezes an old install's new keys); `text()` fallback; settings screen
  prefills e-mail + page-text boxes and shows stored custom values.
- Integration (`tests/integration/Frontend/PageTextsTest.php`, real WP sanitizers): a saved
  custom status text resolves on the result view; the custom sent text is injected above
  override-page content through a real main-loop render; submitting the unchanged default
  (incl. CRLF e-mail body) normalizes to `''`; Mailer falls back to `EmailDefaults`.

## Assumptions (recorded, not user-confirmed)

1. "All the available boxes" = the e-mail subject/body boxes (the only ones that render
   empty despite having defaults); form texts already prefill.
2. Making the default-page texts editable in settings is the intended fix for "I cannot
   change them otherwise", with the markdown doc as the requested reference template.
3. Defaults normalize back to `''` on save (semantics above) rather than being frozen into
   the option — implemented for **all** `TEXT_DEFAULTS` keys and all `EMAIL_KEYS`.
4. No version bump — that happens in the user's release flow.

## Review

Reviewed adversarially (multi-dimension find → verify). Five confirmed findings, all fixed
before commit: (a) prefilled page texts froze defaults into the option on first save — root
cause fixed by extending the `equals-default → ''` normalization to all `TEXT_DEFAULTS` keys;
(b) admin help text presented a copyable `Anfrage von: %name% <%email%>` whose `<%email%>`
`sanitize_textarea_field` would strip — reworded to a descriptive sentence; (c) Seiten help
pointed to an in-plugin `docs/` path absent from the release ZIP — reworded; (d) spec claimed
integration coverage that did not exist — added `PageTextsTest`; (e) result-page template
implied notice-placement control the code lacks — clarified. A dedicated adversarial security
pass found no defect (all sinks escape via `esc_html`/`esc_attr`/`esc_textarea`, `porto_status`
is allow-list-mapped not reflected, subjects are newline-stripped, auth/nonce intact); its one
informational note — a shortcode in a now-editable status text would expand on an override page
(the_content `do_shortcode` at priority 11) but not on the themed view — was resolved by
`strip_shortcodes()` in `PageRenderer::notice()` so both paths render identically and inertly.
