# WP-Porto-Sender

Emails website visitors a single-use Deutsche Post *Mobile Briefmarke* code from a
pre-purchased pool so they can mail a letter to the site owner.

## Setup
1. `composer install`
2. `npm install && npm run build`
3. Vendor the Altcha widget: `cp node_modules/altcha/dist/altcha.min.js assets/altcha.min.js`
4. Activate the plugin, then under **Porto-Sender → Einstellungen** set your postal address,
   enabled products, low-stock thresholds, the Altcha HMAC secret, and privacy URL.
5. Buy Mobile Briefmarke codes in the Post & DHL App and add them under **Codes hinzufügen**.
6. Place the `[porto_request]` shortcode (or the "Porto-Code anfordern" block) on a page.

## Tests
- Unit (no Docker): `composer test:unit`
- Integration (Docker): `npm run env:start && npm run test:integration`

## How it works
See `docs/superpowers/specs/2026-06-24-wp-porto-sender-design.md` (architecture + verified
Deutsche Post constraints) and `docs/superpowers/plans/2026-06-24-wp-porto-sender.md`.
