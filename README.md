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

## Releases
The single source of truth for the version is the `Version:` header in `porto-sender.php`.

- **Local build:** `bash bin/build-zip.sh` → produces `dist/wp-porto-sender-<version>.zip`
  (production `vendor/` via `composer install --no-dev`, editor block rebuilt, dev/test files
  excluded). It builds in a temp dir, so your dev `vendor/` is left untouched.
- **Automated release:** `.github/workflows/release.yml` runs on every push to `main`. When the
  header version is new (no matching `v<version>` tag yet), it runs the unit suite, builds the zip,
  and publishes a GitHub Release with the zip attached. So cutting a release = bump the `Version:`
  header and push to `main`. Pushes that don't change the version are a no-op.

## How it works
See `docs/superpowers/specs/2026-06-24-wp-porto-sender-design.md` (architecture + verified
Deutsche Post constraints) and `docs/superpowers/plans/2026-06-24-wp-porto-sender.md`.
