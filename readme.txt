=== WP-Porto-Sender ===
Contributors: leoburon
Tags: deutsche post, briefmarke, postage, dsgvo, datenschutz
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.4.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Emails website visitors a single-use Deutsche Post Mobile Briefmarke code from a pre-purchased pool so they can mail a letter to the site owner.

== Description ==

WP-Porto-Sender lets a site owner hand out single-use Deutsche Post *Mobile Briefmarke* codes
from a pre-purchased pool. A visitor requests a code through a shortcode or block, confirms by
email (double opt-in), passes an Altcha proof-of-work captcha and rate limiting, and receives one
code by email. The owner manages the code pool, low-stock alerts, and abuse protection from the
admin.

The plugin is DSGVO-first: personal data is stored only as salted hashes where possible, the admin
notification is PII-free by default, and data retention is configurable and enforced by a daily job.

Features:

* Single-use code issuance with email double opt-in and Altcha captcha
* Per-IP and global rate limiting; per-person de-duplication
* Admin notification email (throttled, PII-free by default) when a code is claimed
* Data export/import (CSV + a lossless, optionally encrypted migration bundle)
* Configurable retention: issued-code PII anonymized after N days; unconfirmed requests purged after N days
* Optional Germany-only geo restriction (default off; external sources require sign-off)
* Complete uninstall and an admin "delete all data" action
* In-place updates from GitHub Releases

== Installation ==

1. Upload the plugin zip via **Plugins → Add New → Upload Plugin**, or extract it into
   `wp-content/plugins/`.
2. Activate the plugin.
3. Under **Porto-Sender → Einstellungen**, set your postal address, enabled products, low-stock
   thresholds, the Altcha HMAC secret, retention windows, and privacy-policy URL.
4. Buy Mobile Briefmarke codes in the Post & DHL App and add them under **Codes hinzufügen**.
5. Place the `[porto_request]` shortcode (or the "Porto-Code anfordern" block) on a page.

Once installed, new versions published on GitHub Releases appear as normal plugin updates in the
WordPress admin.

== Changelog ==

= 0.4.1 =
* Request form: show a processing state on submit (disabled button + status text) and block double submits.
* Fix: one porto per person is now enforced at confirmation time — extra confirmation links for someone who already received a code no longer issue further codes.
* Export: the confirmation checkbox is now also required for the unencrypted requests CSV (personal data), not only the backup bundle.
* Captcha: fix verification failing on smartphones — the proof-of-work was too heavy for phones. Lowered the PBKDF2 cost and the widget now solves automatically while the form is filled; the form waits if the solution isn't ready yet, and shows a clear message if the page is ever opened without HTTPS.

= 0.4.0 =
* Add a "Generieren" button on the settings page to create a strong Altcha HMAC secret (256-bit, in-browser CSPRNG — no data leaves the site).
* Export: the unencrypted-bundle confirmation is now enforced in the browser (only for the plaintext bundle; CSV and encrypted-bundle exports are unaffected). The server-side check is unchanged.

= 0.3.0 =
* Add in-place updates from GitHub Releases.
* Add a configurable retention window for unconfirmed requests (separate from the confirm-token TTL).
* Add a GPL-2.0-or-later license, a CI workflow (unit + integration), and a readme.

= 0.2.0 =
* Data export/import (CSV + lossless encrypted bundle) with a portable hash salt.
* Throttled, PII-free admin notification when a code is claimed.
* Complete uninstall and an admin "delete all data" / "reset settings" action.
* Optional, default-off Germany-only geo restriction.
* Automated release packaging.

= 0.1.0 =
* Initial release: code pool, issuance with double opt-in + Altcha, rate limiting, low-stock alerts.
