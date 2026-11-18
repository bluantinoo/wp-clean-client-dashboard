=== WP Clean Client Dashboard ===
Contributors: diegovagnoli
Tags: admin, dashboard, cleanup, client, restrict
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cleans up the WordPress admin for clients and non-technical users, while leaving full access intact for designated administrators.

== Description ==

**WP Clean Client Dashboard** lets you designate one or more trusted users who keep full, unrestricted access to WordPress. Everyone else gets a tidied-up admin experience:

* **Notices hidden** — all admin notices are suppressed so the dashboard stays clean.
* **Updates blocked** — capabilities for updating core, plugins and themes are removed, preventing accidental breakage.
* **Admin menu trimmed** — choose which top-level menu items and sub-pages to hide. A set of risky pages (Theme Editor, Customizer, Site Editor, Updates) is hidden by default.
* **Admin bar cleaned** — select which admin bar nodes to remove on both the frontend and the backend. The WP logo is replaced with the site favicon and links to the frontend (in admin) or to the dashboard (on the frontend).
* **Dashboard widgets hidden** — tick which widgets to remove from the Dashboard home screen.
* **Help tab suppressed** — the contextual Help tab is hidden.
* **Custom footer branding** — set a custom footer message (supports links and basic HTML) for restricted users.
* **CSS selectors** — hide any element by CSS selector for extra fine-tuning.
* **Export / Import** — back up or transfer all settings between sites via JSON.

No page builders, no upsells, no tracking. One file, zero dependencies beyond WordPress itself.

== Installation ==

1. Upload the `wp-clean-client-dashboard` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen.
3. Go to **Settings → Clean Dashboard**.
4. Under **Users excluded from restrictions**, tick the administrator accounts that should keep full access (your own account first).
5. Save settings — restricted users will immediately see the cleaned-up interface.

**Recommended first steps:**

* Visit **Dashboard** as an allowed user at least once so the widget list is populated.
* Browse a frontend page as an allowed user to discover admin bar nodes added by other plugins.
* Set a custom footer message under **Custom footer text** (or leave it empty for no footer).

== Frequently Asked Questions ==

= Will this plugin affect my own account? =

Only if you do not add yourself to the **Users excluded from restrictions** list. Always add your own user ID before saving.

= Can I hide the plugin's own settings page from clients? =

Yes — tick **Settings** (options-general.php) in the menu removal section. Allowed users will always see it regardless.

= The widget or admin bar list is empty — why? =

These lists are built automatically as an allowed user browses. Visit the Dashboard page and a few frontend pages once, then come back to the settings.

= Does it affect frontend visitors or logged-out users? =

No. All restrictions apply only to logged-in WordPress users who are not on the allowed list.

= Can I transfer settings to another site? =

Yes. Use the **Export / Import** section at the bottom of the settings page to copy a JSON snapshot between installations.

= What HTML is allowed in the custom footer? =

`<a>`, `<strong>`, `<em>`, `<span style="">`, `<br>`. Everything else is stripped on save.

== Screenshots ==

1. Settings page — user exclusion, menu removal and admin bar node selection.
2. Dashboard as seen by a restricted user — clean, no notices, no update badges.

== Changelog ==

= 1.3.0 =
* Added: WP logo replaced with site favicon in the admin bar for restricted users.
* Added: Dashboard widget selection (auto-discovered list).
* Added: Admin bar node selection (auto-discovered, with admin/frontend context badges).
* Added: Configurable custom footer text with HTML support and live preview.
* Added: Export / Import settings via JSON.
* Added: Settings menu (options-general.php) now available in the menu removal list.
* Improved: WP logo dropdown children (About WordPress, external links) fully removed.
* Improved: WP logo link is context-aware — goes to the frontend from admin and vice versa.

= 1.2.0 =
* Added: Admin bar items removal tied to hidden menu entries (Updates, Customizer).
* Added: Robust Customizer removal covering querystring URL variants.
* Added: Help tab hidden for restricted users.
* Improved: Custom admin footer replaces both left text and version string.

= 1.1.0 =
* Added: CSS selector field for hiding arbitrary elements.
* Added: Dashboard menu items hidden by default (Theme Editor, Site Editor, Customizer, Updates).
* Fixed: Customizer redirect for users attempting direct URL access.

= 1.0.0 =
* Initial release. Notices hiding, update capability blocking, basic menu removal, user exclusion list.

== Upgrade Notice ==

= 1.3.0 =
New: favicon replacement, widget/admin bar discovery lists, configurable footer, JSON export/import. No breaking changes — existing settings are preserved.
