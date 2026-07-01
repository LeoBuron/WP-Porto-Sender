# WP-Porto-Sender 0.5.0 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship 0.5.0 — a tabbed, non-technical-friendly settings page; a configurable request-form appearance; built-in "check e-mail" + result pages (optional page override); editable e-mail templates; a masked HMAC-secret field; and removal of the confusing per-code postage-value tracking.

**Architecture:** One WordPress option (`porto_sender_settings`) + one sanitiser remain the source of truth; tabs are presentation-only (all fields posted every save). Front-end appearance is driven by a base stylesheet + CSS custom properties emitted from settings. Post-submit/confirm flows redirect to GET views rendered inside the active theme, overridable by a chosen WP page. E-mails read subject/body templates from settings with `%placeholder%` substitution.

**Tech Stack:** PHP 8.1, WordPress 6.4+, PHPUnit 11 + Brain Monkey (unit) + wp-phpunit (integration), vanilla JS, `wp-color-picker`.

## Global Constraints

- PHP >= 8.1; WordPress >= 6.4; `declare(strict_types=1)` in every PHP file.
- Text domain `wp-porto-sender` on every user-facing string.
- Single option row + `Settings::sanitize()` merge-over-existing model; **all settings fields render in one `<form>`** (tabs via CSS/JS) so no field is ever absent from the POST.
- Backward compatible: new keys default in via `Settings::defaults()`; blank e-mail templates fall back to current copy.
- Escape on output (`esc_html`/`esc_attr`/`esc_url`/`esc_textarea`); sanitize on input; hex via `sanitize_hex_color`.
- All work on branch `develop`; commit per task; release as 0.5.0 at the end.

---

## Task 1: Settings — new keys, accessors, sanitisers

**Files:**
- Modify: `src/Settings/Settings.php`
- Test: `tests/unit/Settings/SettingsAppearanceTest.php` (create), extend `tests/unit/Settings/SettingsTest.php`

**Interfaces — Produces:** new accessors on `Settings`:
`formLayout():string`, `formAccentColor():string`, `formButtonBg():string`, `formButtonText():string`, `formMaxWidthPx():int`, `formFieldGapPx():int`, `text(string $key):string` (returns configured text or default), `pageSent():int`, `pageResult():int`, `emailTemplate(string $key):string` (subject/body by key, '' if unset), plus keys in `defaults()`.

New default keys (see spec tables): `form_layout='stacked'`, `form_accent_color='#0b5fff'`, `form_button_bg='#0b5fff'`, `form_button_text='#ffffff'`, `form_max_width_px=520`, `form_field_gap_px=12`, `text_intro=''`, `text_label_name='Name'`, `text_label_email='E-Mail'`, `text_legend_products='Was möchtest du senden?'`, `text_consent='<current consent sentence>'`, `text_button='Porto-Code anfordern'`, `page_sent=0`, `page_result=0`, and the 10 e-mail keys (`email_*_subject`/`email_*_body`) defaulting to `''`.

- [ ] **Step 1 — failing test:** appearance + text + page + email keys round-trip through `sanitize()`; hex junk is rejected; unknown text falls back to default; `form_layout` outside the enum falls back to `stacked`.

```php
public function test_appearance_keys_sanitize(): void {
    \Brain\Monkey\Functions\when('sanitize_hex_color')->alias(fn($c) => preg_match('/^#[0-9a-fA-F]{6}$/', (string)$c) ? $c : '');
    \Brain\Monkey\Functions\when('get_option')->justReturn([]);
    $out = Settings::sanitize(['form_layout' => 'card', 'form_accent_color' => '#ff0000', 'form_max_width_px' => '640', 'form_accent_junk' => 'x', 'form_button_bg' => 'not-a-color']);
    $this->assertSame('card', $out['form_layout']);
    $this->assertSame('#ff0000', $out['form_accent_color']);
    $this->assertSame(640, $out['form_max_width_px']);
    $this->assertSame('#0b5fff', $out['form_button_bg']); // junk -> default kept
    $out2 = Settings::sanitize(['form_layout' => 'bogus']);
    $this->assertSame('stacked', $out2['form_layout']);
}
```

- [ ] **Step 2:** Run `vendor/bin/phpunit --filter test_appearance_keys_sanitize` → FAIL.
- [ ] **Step 3 — implement:** add keys to `defaults()`; add accessors; extend `sanitize()`:
  - `form_layout`: whitelist `['stacked','compact','card']`.
  - colours: `$c = sanitize_hex_color($input[k] ?? ''); $result[k] = $c ?: $result[k];` (keep stored/default if invalid).
  - `form_max_width_px`,`form_field_gap_px`: `absint`.
  - `text_*`: `sanitize_text_field` (intro+consent `sanitize_textarea_field`).
  - `page_sent`,`page_result`: `absint`.
  - e-mail subjects `sanitize_text_field`, bodies `sanitize_textarea_field`.
  - `text(key)` returns stored value or the default constant; `emailTemplate(key)` returns stored or ''.
- [ ] **Step 4:** Run the filter → PASS; run full unit suite → PASS.
- [ ] **Step 5 — commit:** `feat(settings): appearance, page, e-mail & text keys + sanitisers`

---

## Task 2: Remove `value_cents` / postage-value tracking (Item 8)

**Files:**
- Modify: `src/Persistence/Schema.php` (drop column from CREATE), `src/Persistence/SchemaVersion.php` (add migration to DROP COLUMN), `src/Inventory/CodeRepository.php` (addBatch sig + INSERT + COLUMNS; remove findBelowValue), `src/Inventory/CodeStore.php` (interface), `src/Postage/PostageProduct.php` (remove `valueCents`), `src/Postage/ProductCatalog.php` (drop value arg), `src/Admin/CodeIntakePage.php` (remove field + default), `src/Portability/CodesCsvImporter.php` (drop value handling), `src/Admin/Dashboard.php` (remove valueDrift + notice)
- Test: update `tests/integration/Inventory/*`, `tests/integration/Admin/*`, `tests/**` referencing `value_cents`/`addBatch(...valueCents...)`/`findBelowValue`.

**Interfaces — Produces:** `CodeStore::addBatch(string $product, \DateTimeImmutable $purchaseDate, array $codes): int` (no `$valueCents`); `PostageProduct(string $key, string $label, string $limits)` (no `valueCents`); `findBelowValue` removed.

- [ ] **Step 1 — failing test:** verify `SchemaVersion` migration drops the column and `addBatch` works without a value.

```php
// integration
public function test_addbatch_without_value_and_no_value_column(): void {
    global $wpdb; $repo = new CodeRepository($wpdb);
    $n = $repo->addBatch('standardbrief', new \DateTimeImmutable('2026-01-15'), ['A1','A2']);
    $this->assertSame(2, $n);
    $cols = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}porto_codes");
    $this->assertNotContains('value_cents', $cols);
}
```

- [ ] **Step 2:** Run → FAIL (column still present / signature mismatch).
- [ ] **Step 3 — implement:**
  - `Schema.php`: remove the `value_cents int(11) NOT NULL,` line.
  - `SchemaVersion.php`: bump target version; add migration step `ALTER TABLE {codes} DROP COLUMN value_cents` guarded by `SHOW COLUMNS ... LIKE 'value_cents'`.
  - `CodeRepository`: drop `$valueCents` from `addBatch` + INSERT column list/values; remove `value_cents` from `COLUMNS`; delete `findBelowValue`.
  - `CodeStore`: update `addBatch` sig; remove `findBelowValue`.
  - `PostageProduct`: remove `valueCents` property/param.
  - `ProductCatalog::default()`: `new PostageProduct('standardbrief','Standardbrief','bis 20 g …')` etc.
  - `CodeIntakePage`: remove the "Bezahlter Portowert (ct)" field, the `(NN ct)` suffix, and pass no value to `addBatch`.
  - `CodesCsvImporter`: drop `value_cents` parsing; `addBatch(product, purchase, [code])`.
  - `Dashboard`: delete `valueDrift()` + the notice loop.
- [ ] **Step 4:** update all affected tests to the new signatures; run full suite → PASS.
- [ ] **Step 5 — commit:** `refactor: remove per-code postage-value tracking (value_cents) + drift warning`

---

## Task 3: Mask HMAC secret + show/hide toggle (Item 7)

**Files:** Modify `src/Admin/SettingsPage.php` (field → `type="password"` + "Anzeigen" button; extend the inline script). Test: none (render-only; covered by existing SettingsPageTest smoke).

- [ ] **Step 1 — implement:** render `altcha_hmac_secret` as `type="password" autocomplete="new-password"`; add `<button type="button" id="porto-altcha-reveal">Anzeigen</button>`; script toggles `field.type` password↔text and button label Anzeigen↔Verbergen. Keep the existing generate button/script.
- [ ] **Step 2:** `php -l` + load the settings page in wp-env manually if available; run unit suite → PASS.
- [ ] **Step 3 — commit:** `feat(settings): mask HMAC secret with show/hide toggle`

---

## Task 4: Configurable form appearance (Item 1b)

**Files:** Create `assets/porto-form.css`; Modify `src/Frontend/RequestForm.php` (layout class, scoped style vars, configurable labels; enqueue css). Test: `tests/unit/Frontend/RequestFormTest.php` (create) — uses Brain Monkey to stub WP fns and asserts rendered markup contains the layout class, custom-property style, and configured labels.

- [ ] **Step 1 — failing test:** `RequestForm::render()` output contains `porto-layout-card`, `--porto-accent:#ff0000`, and a custom button label when settings say so.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3 — implement:** `porto-form.css` with base + `.porto-layout-stacked/compact/card` + custom-property usage; `RequestForm` adds `class="porto-request-form porto-layout-<layout>"`, emits `<style>.porto-request-form{--porto-accent:..;--porto-btn-bg:..;--porto-btn-text:..;--porto-max-width:..px;--porto-gap:..px}</style>` (colours already hex-sanitised in Settings; ints cast), and uses `text_*` labels; `enqueueAssets()` enqueues `porto-form.css`.
- [ ] **Step 4:** Run → PASS; full suite → PASS.
- [ ] **Step 5 — commit:** `feat(form): configurable layout, colours & labels`

---

## Task 5: Tabbed settings page (Item 1a)

**Files:** Modify `src/Admin/SettingsPage.php` (split render into section methods; tab nav + panels; enqueue admin assets on this page); Create `assets/admin-settings.js`, `assets/admin-settings.css`. Test: extend `tests/unit/Admin/SettingsPageTest.php` (registerSetting unchanged; add a render smoke assert that all tab panels + the colour-picker enqueue hook are present).

- [ ] **Step 1 — implement:** `render()` prints `<h2 class="nav-tab-wrapper">` tabs (Allgemein, Formular & Layout, Seiten, E-Mails, Missbrauchsschutz, Daten & Aufbewahrung, Geo) + one `<div class="porto-tab-panel" data-tab="…">` per section, all inside the single `options.php` form. Move existing fields into the matching section method; add the appearance fields (Task 4 settings) with `wp-color-picker` inputs + preset-scheme buttons; add Seiten (page dropdowns) + E-Mails (Task 6) panels. Enqueue `wp-color-picker` + `admin-settings.{js,css}` on the settings page hook only. No-JS: CSS shows all panels.
- [ ] **Step 2:** unit suite green; manual wp-env check of tab switching + colour picker.
- [ ] **Step 3 — commit:** `feat(settings): tabbed layout + colour pickers (non-technical UX)`

---

## Task 6: Editable e-mail templates (Item 6)

**Files:** Modify `src/Mail/Mailer.php` (read subject/body from Settings; add `render(string $tpl, array $vars)`; default fallback), `src/Admin/SettingsPage.php` (E-Mails panel UI with placeholder hints). Test: `tests/unit/Mail/MailerTest.php` (create) — placeholder substitution incl. PII on/off; blank template → default.

- [ ] **Step 1 — failing test:** `sendConfirmation` uses a configured body with `%name%`/`%confirm_url%` replaced; blank config → default copy.
```php
public function test_confirmation_uses_custom_template(): void {
    // Settings returns custom body "Hi %name%: %confirm_url%"
    $mailer = new Mailer($settings);
    \Brain\Monkey\Functions\expect('wp_mail')->once()->with('v@e.de', \Mockery::type('string'), \Mockery::on(fn($b) => str_contains($b, 'Hi Vera: https://x/confirm')))->andReturn(true);
    $this->assertTrue($mailer->sendConfirmation('v@e.de','Vera','https://x/confirm'));
}
```
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3 — implement:** each Mailer method builds `$vars`, pulls subject/body via `Settings::emailTemplate()` (falls back to the current hard-coded default when blank), runs `render()` (`str_replace` of `%key%`). Admin notification resolves `%name%/%email%` to '' when PII off. Add the E-Mails panel: subject + textarea per message, with `<code>%placeholder%</code>` hints.
- [ ] **Step 4:** Run → PASS; full suite → PASS.
- [ ] **Step 5 — commit:** `feat(email): editable subjects/bodies with placeholders`

---

## Task 7: Follow-up + result pages (Item 2)

**Files:** Create `src/Frontend/PageRenderer.php`; Modify `src/Frontend/ConfirmHandler.php` (redirect instead of wp_die), `src/Frontend/RequestForm.php` (`data-sent-url`), `assets/porto-form.js` (redirect on confirmation_sent), `src/Plugin.php` (wire PageRenderer), `src/Admin/SettingsPage.php` (Seiten panel already added in Task 5 — confirm page dropdowns). Test: `tests/unit/Frontend/PageRendererTest.php` (status→message map + allow-list); `tests/integration/Rest/…` or a ConfirmHandler test for the redirect target.

**Interfaces — Produces:** `PageRenderer::register()`; query vars `porto_view` (`sent|result`) + `porto_status`.

- [ ] **Step 1 — failing test:** unknown `porto_status` maps to the `invalid_token` message; each known status maps to its message.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3 — implement:**
  - `PageRenderer` on `template_redirect`: for `porto_view=sent|result`, validate `porto_status` against the allow-list, map to message (reuse `ConfirmHandler`'s table + a `sent` message), render `get_header()` + escaped notice + `get_footer()`, `exit`. Also a `the_content` filter that, when `page_sent`/`page_result` is the current page and the query args are present, prepends the escaped notice (auto-inject for override pages).
  - `ConfirmHandler::maybeHandle()`: after `process()`, `wp_safe_redirect(resultUrl($status))` + `exit` (built-in `?porto_view=result&porto_status=` or the selected page + `?porto_status=`).
  - `RequestForm`: add `data-sent-url` (selected page permalink or `?porto_view=sent`).
  - `porto-form.js`: on `confirmation_sent`, `window.location.assign(form.dataset.sentUrl)` (fallback: inline message if absent).
  - `Plugin::wire()`: `(new PageRenderer($s))->register();`.
- [ ] **Step 4:** Run → PASS; full suite → PASS.
- [ ] **Step 5 — commit:** `feat(flow): themed check-email + result pages with optional page override`

---

## Task 8: Version bump + changelog + build

**Files:** Modify `porto-sender.php` (Version 0.5.0), `readme.txt` (Stable tag + changelog). 

- [ ] **Step 1:** bump header to 0.5.0; add readme `= 0.5.0 =` changelog summarising Items 1,2,6,7,8 + the 0.4.x fixes are already listed.
- [ ] **Step 2:** `bash bin/build-zip.sh`; verify the 0.5.0 zip contains the new assets and no `value_cents`.
- [ ] **Step 3 — commit:** `build(release): version 0.5.0 + changelog`

---

## Review & release stages (after all tasks green)

1. **Code review** (workflow): correctness, DRY/YAGNI, WP conventions, i18n, test quality — adversarially verified.
2. **Security review** (workflow): output escaping/XSS in the new admin UI, inline styles & pages; capability/nonce on settings + intake; CSS-injection via colours; page-override injection; the REST bypass unchanged.
3. **Feature review** (workflow): each spec item actually delivered + non-technical UX honoured.
4. Fix any confirmed findings (TDD), re-run suite.
5. **Merge:** fast-forward/merge `develop` → `main`; push (triggers CI release for 0.5.0).
6. Verify the v0.5.0 GitHub release + CI green.

## Self-review (plan vs spec)

- Spec Items 1a,1b,2,6,7,8 → Tasks 5, 4, 7, 6, (3+5), 2 respectively; Item 7 (mask) → Task 3. ✓ all covered.
- Sanitiser single-option model preserved (Task 1 + Task 5 all-fields-in-one-form). ✓
- Backward compat: defaults + blank-template fallback (Tasks 1, 6); bundle import compat (Task 2). ✓
- TDD: each behavioural task has a failing-test-first step. Render-only tasks (3,5) rely on smoke + manual wp-env. ✓
- Type consistency: `addBatch` new signature used identically in Tasks 2 and its callers. ✓
