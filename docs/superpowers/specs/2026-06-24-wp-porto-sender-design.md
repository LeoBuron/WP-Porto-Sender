# WP-Porto-Sender — Design Spec

- **Date:** 2026-06-24
- **Status:** Draft for review
- **Approach:** A — Minimal self-contained dispenser (chosen over an extensible `PostageSource` abstraction and over reusing TablePress + a form plugin)

## 1. Purpose

A WordPress plugin that lets a website visitor request **prepaid postage** to mail a physical letter to the site owner. After a double opt-in + CAPTCHA, the visitor is emailed a single-use Deutsche Post **Mobile Briefmarke** code (`#PORTO` + 8 characters) drawn from a pool the owner has bought and stocked manually. The visitor handwrites the code in the franking zone of the envelope and posts the letter. No printer is required.

The plugin is, in essence, a **secure inventory dispenser** for single-use, money-bearing codes, fronted by a verified-request gate. The hard part is inventory integrity, not postal integration.

## 2. External constraints (verified by research, 2026-06-23)

These facts drove the architecture and must not be silently revisited:

1. **No API generates a Mobile Briefmarke.** It is obtained **only** via the Post & DHL App ("Als Code zum Beschriften"). The Deutsche Post Internetmarke API (1C4A SOAP and the newer REST API) only emits **printable PDF/PNG** stamps — a different product. *(High confidence; adversarially refuted twice against the official 1C4A spec and product FAQ.)*
2. **Codes are valid until the end of the third year following purchase** (~3–4 years). Per the binding AGB; the former 14-day validity limit was struck down by LG Köln (Az. 33 O 258/21, final 2023-06-13). *(High confidence.)*
3. **Codes are single-use and lock in a postage value at purchase.** A price increase does not shorten validity, but an old code only covers the old value (may need a top-up if rates rise).
4. **2026 letter prices** (Stand 01.01.2026): Postkarte 0,95 € · Standardbrief 0,95 € · Kompaktbrief 1,10 € · Großbrief 1,80 € · Maxibrief 2,90 €.
5. **Refunds only within 14 days** of purchase. After that, pooled codes are non-refundable → the pool size is the owner's committed budget.

**Consequence:** the pre-purchased pool is the *only* viable fulfillment model; restocking is an inherently manual human step (buy in app → enter codes into WordPress).

## 3. Product scope

- **Two products offered:** Standardbrief (0,95 €) and Großbrief (1,80 €). The **visitor selects** which fits what they are sending.
- A **mixed pool** is supported natively: every code is keyed by product, so the owner stocks each product independently.
- **Out of scope for v1:** parcels/DHL labels, printable Internetmarke PDFs, live API generation, payment by the visitor, a waitlist when out of stock, multi-site/network support.

## 4. User-facing flow

1. Visitor opens a page containing the request block / `[porto_request]` shortcode. Form fields: **Name**, **Email**, **Product** (radio with plain-language limits — e.g. "Standardbrief: short letter, ≤ 20 g, folded" / "Großbrief: A4 flat, ≤ 500 g"), **CAPTCHA** (Altcha), **DSGVO consent** checkbox with privacy-policy link.
2. Submit → REST endpoint. Server verifies nonce + CAPTCHA, validates input, applies rate limiting, applies the configured **request limit** (dedup), and does a soft stock check for the chosen product. Then it creates a `pending` request, stores only the **hash** of a high-entropy confirm token, and sends the **confirmation email**.
3. Visitor clicks the opt-in link (`?porto_confirm=<token>&id=<id>`). Server validates the token (hash compare, single-use, not expired).
4. **Critical section — atomic claim** (see §7): reserve one available, unexpired code for the chosen product, oldest-first (FIFO).
5. On success → mark the request issued, send the **delivery email** (code + owner postal address + handwriting instructions + product limits + validity note), show on-screen confirmation.
6. If stock drained between steps, or the email fails to send, the reservation is released and the visitor sees a graceful "temporarily unavailable" message; the owner is alerted.

## 5. Architecture & components

Standard WordPress plugin, PHP 8.x / WP 6.x, PSR-4 namespaced (`PortoSender\`). Each component has one responsibility and is testable in isolation:

1. **Activation / Schema** — creates the two custom tables via `dbDelta`, registers the cron event, seeds default options. Versioned schema migrations.
2. **`CodePool` repository** — the integrity core. All DB access to the code inventory; owns the atomic claim. Methods: `availableCount(product)`, `claimOne(product): ?Code`, `addBatch(product, valueCents, purchaseDate, codes[])`, `markIssued(codeId, requestId, hash)`, `releaseStaleReservations()`, `findExpiring(within)`, `quarantineExpired()`.
3. **`RequestLimiter`** — reads `request_limit_mode` and checks the relevant hash(es) against prior confirmed/issued requests.
4. **`IssuanceService`** — orchestrates the flow in §4: validate → CAPTCHA → limit → pending request → confirmation email; on confirm: re-check → claim → delivery email → record → schedule anonymization.
5. **`Captcha` adapter** — interface with an `AltchaProvider` (default) and room for `FriendlyCaptchaProvider`.
6. **Front-end form** — Gutenberg block (built with `@wordpress/scripts`) + `[porto_request]` shortcode; submits to a REST route.
7. **`Mailer`** — confirmation + delivery templates (editable, placeholder-based); uses `wp_mail`; docs recommend an SMTP plugin for deliverability.
8. **Admin UI** — settings, code intake (paste / CSV), dashboard.
9. **Cron / maintenance** — daily: release stale reservations, quarantine expired codes, purge expired pending requests, anonymize PII past retention, evaluate stock thresholds and send alerts.

## 6. Data model

**`{prefix}porto_codes`**

| column | type | notes |
|---|---|---|
| `id` | BIGINT PK | |
| `product` | VARCHAR(32) | `standardbrief` \| `grossbrief` |
| `value_cents` | INT | locked postage value at purchase (e.g. 95, 180) |
| `purchase_date` | DATE | from the order confirmation |
| `expires_on` | DATE | computed = 31 Dec of (purchase year + 3) |
| `code` | VARCHAR(64) | bearer secret; server-side access only, never rendered on front end |
| `status` | ENUM | `available` \| `reserved` \| `issued` \| `expired` \| `void` |
| `reserved_until` | DATETIME NULL | reservation TTL |
| `issued_to_hash` | CHAR(64) NULL | salted hash of the recipient (audit / dedup) |
| `issued_at` | DATETIME NULL | |
| `request_id` | BIGINT NULL | FK → porto_requests |
| `created_at` / `updated_at` | DATETIME | |

**`{prefix}porto_requests`**

| column | type | notes |
|---|---|---|
| `id` | BIGINT PK | |
| `name` | VARCHAR NULL | raw PII; nulled after retention |
| `email` | VARCHAR NULL | raw PII; nulled after retention |
| `email_hash` | CHAR(64) | salted; persists for dedup |
| `name_hash` | CHAR(64) | salted; persists for dedup |
| `product` | VARCHAR(32) | requested product |
| `status` | ENUM | `pending` \| `confirmed` \| `issued` \| `expired` \| `rejected` |
| `token_hash` | CHAR(64) | hashed confirm token, single-use |
| `ip_hash` | CHAR(64) | salted; rate limiting / abuse audit |
| `code_id` | BIGINT NULL | FK → porto_codes |
| `created_at` / `confirmed_at` / `issued_at` | DATETIME | |

Salt for all hashes is a per-install secret stored in options (or `wp-config.php` constant if present).

## 7. Concurrency & inventory integrity (the core)

Issuing a code is a money-spending, must-not-double-spend operation. The claim is a **single atomic SQL statement**, not read-then-write:

```sql
UPDATE {prefix}porto_codes
   SET status = 'reserved', reserved_until = ?, request_id = ?
 WHERE id = (
   SELECT id FROM {prefix}porto_codes
    WHERE product = ? AND status = 'available' AND expires_on > CURDATE()
    ORDER BY purchase_date ASC      -- FIFO: consume oldest first (value-drift safe)
    LIMIT 1
 );
```

- **No double-issue:** two simultaneous confirmations cannot reserve the same row.
- **Reserve-then-commit:** an abandoned confirmation never burns a code — `releaseStaleReservations()` (cron + on-demand) returns `reserved` rows past `reserved_until` to `available`.
- **Never issue expired:** the `expires_on > CURDATE()` guard is in the claim itself.
- **Idempotent confirm:** if a request is already `issued`, re-hitting the link returns the same result without claiming a second code.

## 8. Abuse control & request limiting (configurable)

- **CAPTCHA** (Altcha) + **rate limiting** (by `ip_hash` and a global ceiling) gate the public form.
- **Request limit / dedup** is a setting, `request_limit_mode`:
  - `email` — one code per email
  - `name` — one code per name (note: name collisions cause false positives)
  - `name_or_email` — blocked if **either** matches *(default)*
  - `none` — no limit
- **Limit scope:** permanent by default (the dedup hashes persist even after raw PII is scrubbed). A rolling window is a future option.
- The **pool size is the hard budget cap** — when empty, no code can be issued regardless of demand.

## 9. Security

- Codes are bearer secrets: never rendered on the front end, never written to logs, never stored in TablePress. Admin views show a redacted code (e.g. last 3 chars) for issued/used entries only.
- All forms use nonces / CSRF protection; all admin actions require the `manage_options` capability.
- Confirm tokens are high-entropy, stored hashed, single-use, and expiring.
- No code encryption at rest (decided): server-side-only access via the repository is sufficient.

## 10. DSGVO / privacy

- **Consent:** explicit checkbox + privacy-policy link at submission; purpose stated ("name + email processed to send you a postage code").
- **Data minimization:** raw `name`/`email` retained only for `pii_retention_days` after issuance (default **180**), then anonymized — the plugin nulls the raw fields and keeps only the salted `email_hash` / `name_hash` needed for the dedup rule.
- **Double opt-in** aligns with German email-consent norms.
- **Subject rights:** integrate with WordPress core privacy export/erase tools (best-effort, v1 nice-to-have).

## 11. CAPTCHA

- **Default: Altcha** — open-source, self-hosted proof-of-work. No third-party calls, no cookies, no PII leaves the server (strongest DSGVO posture). Needs only a per-install HMAC secret.
- **Alternative: Friendly Captcha** (German managed service) via the same adapter interface.
- **Explicitly avoided:** Google reCAPTCHA (third-party data transfer to the US).

## 12. Admin UI & settings

**Settings**
- `owner_postal_address` — printed in the delivery email
- `enabled_products` — subset of {standardbrief, grossbrief}
- `low_stock_threshold` *(per product)* — alert when available ≤ threshold (default 5)
- `alert_email` — recipient for stock alerts (default `admin_email`)
- `request_limit_mode` — see §8 (default `name_or_email`)
- `pii_retention_days` — default 180
- `captcha_provider` + keys/secrets — default `altcha`
- `confirm_token_ttl_hours` — default 48
- `reservation_ttl_minutes` — default 30
- `expiry_warning_months` — flag codes this close to expiry on the dashboard (default 6)
- `privacy_policy_url`
- editable email templates (confirmation, delivery)

**Code intake** — paste codes (one per line) or CSV upload; select product + `value_cents` + `purchase_date` for the batch; the plugin validates format, dedupes against existing codes, computes `expires_on`, inserts as `available`.

**Dashboard** — per-product stock (available / reserved / issued / expired), low-stock indicators, claims log (date, product, redacted code, no raw PII past retention), near-expiry list (codes within `expiry_warning_months` of `expires_on`, default 6), value-drift warnings (codes whose `value_cents` < current product price).

## 13. Stock alerts & out-of-stock behaviour

- **Low-stock alert:** when an issuance drops a product's available count to ≤ its `low_stock_threshold`, send a one-time alert (debounced: fire on threshold crossing, re-arm when restocked above it).
- **Out-of-stock:** when a product reaches 0, the form disables that product (or shows "temporarily unavailable") and the owner gets a distinct "now empty" notice. No waitlist in v1.

## 14. Emails

- **Confirmation email:** opt-in link (hashed token), expiry note, plain-language purpose.
- **Delivery email:** the `#PORTO`-prefixed code, the owner's postal address, handwriting instructions (write it in the top-right franking zone), the chosen product's size/weight limits, and the validity note.

## 15. Error handling & edge cases

- Out of stock for the chosen product → graceful message + owner alert; no unfulfillable pending request created.
- Email send failure → request not marked issued; reservation released; retry/alert.
- Token expired / invalid / reused → friendly error.
- Concurrent confirms / double-click → idempotent (no second claim).
- Value drift (code value < current price) → dashboard warning; delivery email may note a possible top-up.
- Empty pool → form shows the product as unavailable.

## 16. Testing strategy

- **Tooling:** PHPUnit + wp-env (wp-phpunit); Composer (PSR-4 + dev deps); PHPCS with WordPress-Coding-Standards; `@wordpress/scripts` for the block build.
- **Critical tests:** concurrency on `claimOne` (no double-issue under simulated parallel confirms); dedup across all `request_limit_mode` values; `expires_on` computation; FIFO ordering; stale-reservation release; token expiry; idempotent confirm; out-of-stock; low-stock threshold crossing + debounce; PII anonymization after retention.
- **Integration:** REST submit + confirm endpoints; CAPTCHA verification (mocked); `wp_mail` (mocked).

## 17. Tech stack & tooling

PHP 8.x, WordPress 6.x. Namespace `PortoSender\`. German UI shipped, i18n-ready via a text domain. Block built with `@wordpress/scripts`. CAPTCHA: Altcha. Local dev: wp-env. CI-friendly: Composer + npm scripts.

## 18. Open questions / future

1. **Bulk purchase maximum** in the Post & DHL App is unconfirmed — verify before sizing the restock workflow.
2. **Legal/ToS** of distributing prepaid postage codes to arbitrary visitors may have Deutsche Post AGB / commercial implications — owner to review (not verified here).
3. **Rolling dedup window** (re-allow a requester after N months) — deferred; permanent for v1.
4. **WP core privacy export/erase integration** — nice-to-have for v1.
5. **CSV intake format** and data-entry-error guards — finalize during implementation.

## 19. Sources

- Mobile Briefmarke FAQ — https://www.deutschepost.de/de/m/mobile-briefmarke/haeufige-fragen.html
- AGB INTERNETMARKE und Mobile Briefmarke (3-year validity) — https://shop.deutschepost.de/shop/agb/AGB_Deutsche_Post_Shop.pdf
- LG Köln ruling on validity (vzbv) — https://www.vzbv.de/urteile/mobile-briefmarke-darf-nicht-nur-14-tage-gueltig-sein
- INTERNETMARKE 1C4A technical service description — https://developer.dhl.com/sites/default/files/2023-08/quick-Guide%20INTERNETMARKE.pdf
- Deutsche Post INTERNETMARKE REST API reference — https://developer.dhl.com/api-reference/deutsche-post-internetmarke-post-paket-deutschland
- 2026 price sheet — https://www.deutschepost.de/dam/jcr:917c5c99-78af-48c5-835d-acc6b9e0929a/dp-preisblatt-012026.pdf
