# WP-Porto-Sender 0.5.0 — Configurable settings, form appearance, pages & emails

- **Date:** 2026-07-01
- **Status:** Draft for review
- **Target version:** 0.5.0
- **Depends on:** 0.4.2 (REST bypass, autofill-off) already shipped.

## Overview

Make the admin-facing configuration far richer without bloating a single settings screen, and
give the visitor a proper post-submit / post-confirmation experience. Five changes, all in the
"configurable from settings" theme:

- **(1) Tabbed settings** page + **configurable request-form appearance** (preset layout, colours &
  spacing, editable labels/text — each with a sensible preset default).
- **(2) Follow-up + result pages**: after submit, redirect to a reload-safe "check your e-mail"
  page; the e-mail-confirmation link lands on a proper result page. Both plugin-rendered by
  default, each optionally overridable with a chosen WP page.
- **(6) Editable e-mail messages** (subject + body) from settings, with placeholders.
- **(7) Masked HMAC secret** field with a show/hide toggle.

Item 3 (autofill-off) and Item 4 (REST lockout) shipped in 0.4.2. Item 5 (notifications) is being
verified separately and is out of scope here.

### Goals
- One option row (`porto_sender_settings`), one sanitiser — unchanged persistence model.
- Backward compatible: existing installs get defaults for new keys; nothing is wiped.
- Everything degrades gracefully (no-JS, no override page selected, blank template).

### Non-goals
- No new DB tables. No page builder. No per-role settings. No rich-text/HTML e-mails (stay
  plain-text, as today). No redesign of the throttle/notifier.

## Guardrail: tabbed settings must not wipe unshown fields

`Settings::sanitize()` starts from the **stored** option and overwrites only the keys the form
posts — but for checkboxes/arrays, *absent = off/empty* (e.g. `enabled_products`,
`rate_limit_enabled`, `admin_notify_enabled`). Therefore a tab that submits only its own fields
would blank every checkbox on the other tabs.

**Decision:** tabs are a **presentation layer only**. The page renders **all** fields in a single
`<form>`; a small admin script shows one tab-panel at a time. Every field is always in the POST, so
`sanitize()` keeps working exactly as today. No-JS fallback: all panels visible (plain long page).
This keeps the sanitiser untouched and risk-free.

---

## Item 1 — Tabbed settings + configurable form appearance

### 1a. Tabbed `SettingsPage`
- Refactor `Admin/SettingsPage::render()` into one private method per section:
  `renderGeneral()`, `renderFormLayout()`, `renderPages()`, `renderEmails()`, `renderAbuse()`,
  `renderRetention()`, `renderGeo()`. `render()` prints the tab nav + all panels inside the one
  existing `<form action="options.php">`.
- Tab nav: `<h2 class="nav-tab-wrapper">` with `<a>` tabs (WP-native styling); panels are
  `<div class="porto-tab-panel" data-tab="...">`. New `assets/admin-settings.js` +
  `assets/admin-settings.css` (enqueued only on this page via the existing menu hook) toggle the
  active panel and remember the last tab via the URL hash. No-JS: CSS leaves all panels visible.
- Existing behaviour preserved: the generate-secret button, all current fields, `settings_fields`,
  `submit_button`.

**Tabs:** Allgemein · Formular & Layout · Seiten · E-Mails · Missbrauchsschutz · Daten & Aufbewahrung · Geo.

### 1b. Configurable form appearance
New settings keys (all with preset defaults so the form looks good out of the box):

| Key | Type | Default | Sanitiser |
|-----|------|---------|-----------|
| `form_layout` | enum `stacked\|compact\|card` | `stacked` | whitelist |
| `form_accent_color` | hex | `#0b5fff` | `sanitize_hex_color` |
| `form_button_bg` | hex | `#0b5fff` | `sanitize_hex_color` |
| `form_button_text` | hex | `#ffffff` | `sanitize_hex_color` |
| `form_max_width_px` | int (0=full) | `520` | `absint` |
| `form_field_gap_px` | int | `12` | `absint` |
| `text_intro` | string | `''` (hidden if empty) | `sanitize_textarea_field` |
| `text_label_name` | string | `Name` | `sanitize_text_field` |
| `text_label_email` | string | `E-Mail` | `sanitize_text_field` |
| `text_legend_products` | string | `Was möchtest du senden?` | `sanitize_text_field` |
| `text_consent` | string | *(current consent sentence)* | `sanitize_textarea_field` |
| `text_button` | string | `Porto-Code anfordern` | `sanitize_text_field` |

- **Rendering:** ship `assets/porto-form.css` with base styles + `.porto-layout-stacked/compact/card`
  and CSS custom properties (`--porto-accent`, `--porto-btn-bg`, `--porto-btn-text`,
  `--porto-max-width`, `--porto-gap`). `RequestForm` adds the layout class to the form and emits a
  small scoped inline `<style>` that sets those custom properties from settings. Colours pass
  through `sanitize_hex_color` (defence against CSS injection); sizes are integers.
- `RequestForm::render()` reads the `text_*` keys (falling back to defaults) instead of hard-coded
  strings. `enqueueAssets()` also enqueues `porto-form.css`.

**Preset note:** "always give a preset" is satisfied by the defaults above; `form_layout` offers the
three named presets. (No custom-CSS field — deliberately out of scope for safety/simplicity.)

**Non-technical UX (per review):** the whole appearance section must be usable without any CSS/hex
knowledge:
- Colours use the **native WordPress colour picker** (`wp-color-picker`) — click-to-pick swatches,
  no hex typing required (the stored value is still a sanitised hex).
- Offer **named colour-scheme presets** (Blau/Standard · Grün · Rot · Neutral) as one-click buttons
  that fill the pickers; the admin can then fine-tune.
- `form_layout` presets shown as plain labels with a one-line description each (Gestapelt · Kompakt ·
  Karte).
- Every control has short German help text; defaults already look good, so doing nothing is fine.

---

## Item 2 — Follow-up ("check e-mail") + result pages

New settings keys: `page_sent` (int page ID, `0` = plugin built-in), `page_result` (int, `0` =
built-in). Rendered in the **Seiten** tab as `wp_dropdown_pages` selectors (with a "— Plugin-Standard —"
option = 0).

### Sent flow (after submit)
- `RequestForm` outputs `data-sent-url` on the form = the "sent" destination:
  - `page_sent > 0` → `get_permalink(page_sent)`
  - else → `add_query_arg('porto_view','sent', home_url('/'))`
- `porto-form.js`: on `status === 'confirmation_sent'`, instead of only showing the inline message,
  `window.location.assign(form.dataset.sentUrl)`. Because that navigation is a **GET**, reloading the
  sent page never re-POSTs — this is the "cannot re-submit on reload" requirement. (Inline message
  stays as the no-`sent-url` fallback.)

### Result flow (after clicking the e-mail link)
- `ConfirmHandler::maybeHandle()` currently ends in `wp_die()`. Change: after `process($token)`
  returns a status, **redirect** (302) to the result destination carrying the status:
  - `page_result > 0` → `add_query_arg('porto_status', $status, get_permalink(page_result))`
  - else → `add_query_arg(['porto_view'=>'result','porto_status'=>$status], home_url('/'))`
  Then `exit`. No more `wp_die`.

### Rendering the built-in views (theme-integrated)
- New `Frontend/PageRenderer` hooked on `template_redirect`:
  - `porto_view=sent` → render the "Bitte bestätige die Anfrage über den Link in deiner E-Mail."
    notice.
  - `porto_view=result` → map `porto_status` (validated against a known set) to the existing
    `ConfirmHandler` message table and render it.
  - Rendering uses `get_header()` → themed container with the message → `get_footer()` → `exit`, so
    it inherits the active theme (not a bare `wp_die` page).
- `porto_status` is validated against an allow-list (`issued`, `already_issued`, `expired`,
  `out_of_stock`, `email_failed`, `invalid_token`); anything else → `invalid_token`. No user input is
  echoed unescaped.

### Override pages (optional)
- When `page_result`/`page_sent` point to a real WP page, the plugin does **not** render a built-in
  view; it injects the notice into that page via a `the_content` filter that fires only when the
  matching `porto_view`/`porto_status` query args are present, prepending the mapped message
  (escaped). No shortcode required. The admin's page provides the surrounding layout/branding.

### Error handling
- Missing/trashed selected page → fall back to the built-in view (treat as `0`).
- Reload safety: all result/sent URLs are GET; refreshing re-renders the same notice, never re-issues
  (issuance already happened server-side during the redirecting request; the token is single-use via
  the existing `issued`/`already_issued` logic).

---

## Item 6 — Editable e-mail messages

New settings keys — subject + body per message, defaults = the current strings in `Mail/Mailer`:

| Message | Subject key | Body key | Placeholders |
|---------|-------------|----------|--------------|
| Confirmation (double opt-in) | `email_confirm_subject` | `email_confirm_body` | `%name% %confirm_url%` |
| Delivery (the code) | `email_delivery_subject` | `email_delivery_body` | `%name% %product% %limits% %code% %owner_address%` |
| Admin notification | `email_admin_subject` | `email_admin_body` | `%product% %count% %remaining%` (+ `%name% %email%` only if PII opted in) |
| Low stock | `email_lowstock_subject` | `email_lowstock_body` | `%product% %remaining%` |
| Out of stock | `email_outofstock_subject` | `email_outofstock_body` | `%product%` |

- `Mailer` reads each subject/body from `Settings` (falls back to the built-in default when blank),
  then does placeholder substitution via one private `render(string $tpl, array $vars)` helper
  (`str_replace` of `%key%` tokens). Unknown placeholders are left as-is; PII placeholders resolve to
  empty when PII is disabled (mirrors current behaviour).
- **Sanitise:** subjects `sanitize_text_field`, bodies `sanitize_textarea_field` (plain-text mail).
- **UI (E-Mails tab):** each message = a subject text field + a body textarea, with the available
  placeholders listed beneath it as `<code>` hints.
- Backward compatibility: blank body/subject ⇒ current hard-coded default, so upgrades change nothing
  until the admin edits.

---

## Item 7 — Masked HMAC secret with show/hide

- The `altcha_hmac_secret` field becomes `type="password"` (masked `••••`) with
  `autocomplete="new-password"`.
- Add an **"Anzeigen"** toggle button next to it (and the existing "Generieren" button); a small
  script flips the input `type` between `password`/`text` and the button label
  (Anzeigen/Verbergen). Generation still fills the (masked) field.

---

## Item 8 — Remove postage-value (`value_cents`) tracking

**Rationale:** the per-code paid value exists only to power the "N codes below current postage value"
warning. Its intake field stored empty input as `0` (empty `<input>` → `''` → `(int) '' = 0`, so the
`?? catalog-default` fallback never applied), which is exactly what produced the spurious
`10 "standardbrief"-Codes liegen unter dem aktuellen Portowert` notice. Issuance orders by
`purchase_date` (not value — verified in `claimOne`), so nothing operational depends on it: clean to
remove and it also simplifies the admin UX (non-technical goal).

**Remove:**
- `Dashboard::valueDrift()` + the Portowert notice; `CodeRepository::findBelowValue()` +
  `CodeStore::findBelowValue()`.
- `value_cents` param from `addBatch()` (`CodeRepository` + `CodeStore`) and its INSERT; drop it from
  the `COLUMNS` whitelist.
- `PostageProduct::valueCents` + the catalog's value argument; the intake "Bezahlter Portowert (ct)"
  field + the `(NN ct)` product-label suffix; `value_cents` handling in `CodesCsvImporter`.
- Schema: drop the `value_cents` column via a `SchemaVersion` migration; fresh installs omit it.

**Compat:** old export bundles carrying `value_cents` still import (whitelisted out by `insertRows`).
Dropping the column is one-way, but the data had no operational use. Update every test referencing
`value_cents`/`valueCents`/`findBelowValue`/`addBatch(... valueCents ...)`.

## Cross-cutting

- **Settings:** all new keys added to `Settings::defaults()` with accessors; `sanitize()` extended
  with the new keys (all form-rendered, so the merge-over-existing model is preserved). New enum/hex
  validators as above.
- **i18n:** every new string uses the `wp-porto-sender` text domain.
- **Security:** hex colours via `sanitize_hex_color`; sizes/page IDs via `absint`; `porto_status`
  allow-listed; all rendered messages escaped; e-mail bodies plain-text; no untrusted HTML.
- **Assets:** `assets/porto-form.css` (front), `assets/admin-settings.{js,css}` (admin, this page
  only). All local, keeping the plugin self-contained.

## Testing

- **Unit:** `sanitize()` accepts/normalises the new keys and preserves unshown ones; `sanitize_hex_color`
  rejects junk; `Mailer::render()` placeholder substitution (incl. PII on/off); `RequestForm` renders
  custom labels + layout class + scoped style; `PageRenderer` status→message mapping + allow-list.
- **Integration:** confirm-link → 302 redirect to result URL carrying the correct `porto_status`;
  submit → `data-sent-url` present; selected-but-trashed page falls back to built-in.
- All existing tests must stay green (SettingsPage refactor must not change persisted keys).

## Backward compatibility & migration

No migration needed. New keys default in via `Settings::defaults()`/`array_merge`. `page_sent`/
`page_result` default to `0` (built-in). E-mail templates default blank ⇒ current copy. Existing
sanitised option rows keep every current value.

## Build sequence (for the implementation plan)

1. `Settings`: add keys, accessors, sanitiser + new validators (+ unit tests).
2. `SettingsPage`: split into per-tab sections; tab nav + admin JS/CSS; mask toggle (Item 7).
3. Form appearance: `porto-form.css` + `RequestForm` (labels, layout class, scoped style vars).
4. E-mail templates: `Mailer` reads settings + `render()` helper; E-Mails tab UI.
5. Pages: `PageRenderer`, `ConfirmHandler` redirect, `porto-form.js` sent-redirect, override
   `the_content` injection; Seiten tab UI.
6. Version bump 0.5.0, readme changelog, build/verify, release.

## Resolved decisions (2026-07-01 review)

1. **Appearance scope:** minimal **and** non-technical — colours (native picker + named preset
   schemes), max-width, spacing; no font-size/border-radius. Overriding priority: usable by
   non-technical admins (plain labels, help text, good defaults).
2. **Override result page:** auto-inject the status notice into the chosen page's content (no
   shortcode).
3. **E-mail set:** all five messages editable (confirmation, delivery, admin notification,
   low-stock, out-of-stock).
4. **Remove postage-value tracking** entirely — see Item 8.
