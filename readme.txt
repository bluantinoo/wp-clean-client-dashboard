=== WP Clean Client Dashboard ===
Contributors: bluantinoo
Tags: admin, dashboard, cleanup, client, white label
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.5.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cleans up the WordPress admin for clients and non-technical users, while leaving full access intact for designated administrators.

== Description ==

**WP Clean Client Dashboard** lets you designate one or more trusted administrators who keep the full, unchanged WordPress admin. Everyone else gets a tidied-up admin:

* **Notices hidden**: all admin notices are suppressed, or only the ones you target by CSS selector.
* **Updates blocked**: the capabilities to update, install and delete core, plugins and themes, and to use the file editors, are removed. The Customizer redirects to the Dashboard.
* **Admin menu trimmed**: choose which top-level menu items and sub-pages to hide. Updates, Theme File Editor, Site Editor and Customizer are hidden by default.
* **Admin bar cleaned**: choose which admin bar nodes to remove, on both the frontend and the backend. The WordPress logo is replaced with the site icon and links to the frontend (in admin) or to the dashboard (on the frontend).
* **Dashboard widgets hidden**: tick which widgets to remove from the Dashboard home screen.
* **Help tab suppressed.**
* **Custom footer text** (links and basic HTML allowed).
* **Elementor pages kept in Elementor**: on a page with an Elementor layout, the "Edit with WordPress" button is hidden and the switch to the WordPress editor is refused on the server. On by default, with a setting to switch it off.
* **Export / Import** of all settings as JSON.

No page builders, no upsells, no analytics. No dependencies beyond WordPress itself, apart from the bundled library that checks the GitHub releases for updates; the update check and the download of an update are the only outgoing requests the plugin makes.

= What this plugin is not =

It is interface cleanup, **not access control**. Restricted users are still administrators: they can reach pages by URL, deactivate this plugin from the Plugins screen, or use any capability that is not blocked. If you need to stop an administrator from doing something, give them a different role, or use `DISALLOW_FILE_MODS` and `DISALLOW_FILE_EDIT` in `wp-config.php`.

Update notices are hidden from restricted users too. Someone on the excluded list has to keep WordPress, plugins and themes up to date, or automatic updates have to be on.

== Installation ==

1. Upload the `wp-clean-client-dashboard` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen. The user who activates it becomes the first excluded user (activated from WP-CLI without `--user`, the plugin starts unconfigured and asks for one).
3. Go to **Settings > Clean Dashboard**.
4. Under **Users excluded from restrictions**, tick the administrators who should keep full access.
5. Save settings: restricted users see the cleaned-up interface right away.

**Recommended first steps:**

* Visit **Dashboard** once as an excluded user, so the widget list is populated.
* Browse a few frontend pages as an excluded user, to discover admin bar nodes added by other plugins.
* Set a footer text under **Custom footer text**, or leave it empty for no footer text.

== Frequently Asked Questions ==

= Who can open the settings page? =

Only the excluded users. A restricted administrator does not see the page, and changes to the plugin options made by a logged-in restricted user are discarded whatever the write path, so they cannot add themselves to the list. WP-CLI and cron run without a logged-in user and are not affected.

= What if no excluded user exists any more? =

If none of the saved users is an existing administrator (the user was deleted or demoted, or a database migration renumbered the users), the plugin restricts nobody and shows a warning to every administrator until the list is saved again. It never locks all administrators out of its own settings. This also means a restricted administrator who deletes or demotes the excluded users switches the restrictions off.

= The widget or admin bar list is empty. Why? =

These lists are built as an excluded user browses. Visit the Dashboard and a few frontend pages once, then come back to the settings.

= Does it affect frontend visitors or logged-out users? =

No. Restrictions apply only to logged-in users who are not on the excluded list.

= Can I block more capabilities? =

Yes, with the `wpccd_blocked_caps` filter. For example, to also block plugin activation:

`add_filter( 'wpccd_blocked_caps', fn( $caps ) => array_merge( $caps, [ 'activate_plugins' ] ) );`

= Can I keep some notices visible? =

Return `false` from the `wpccd_hide_all_notices` filter. Only the notices matching the CSS selectors in the settings are then hidden.

= What does the Elementor protection do? =

When a page built with Elementor is switched to the WordPress editor ("Edit with WordPress", in the block editor or the classic editor), Elementor removes the `_elementor_edit_mode` meta and the page loses its layout until someone switches it back. For restricted users the plugin hides that button on pages with an Elementor layout and refuses on the server both the removal of the meta and an empty value, whatever the request. For the same reason, restoring a revision saved before the page was built with Elementor brings back the old text, but the page stays in Elementor. A page opened in Elementor and left empty can still be switched back, and posts not built with Elementor keep the "Edit with Elementor" button. Excluded users, WP-CLI and cron are not affected. Switch it off under **Elementor** in the settings.

= What HTML is allowed in the custom footer? =

`<a>`, `<strong>`, `<em>`, `<span style="">`, `<br>`. Everything else is stripped on save.

== Screenshots ==

1. Settings page: excluded users, menu items and admin bar nodes.
2. Dashboard as seen by a restricted user.

== Changelog ==

= 1.5.0 =
* Added: restricted users can no longer switch a page with an Elementor layout back to the WordPress editor. The "Edit with WordPress" button is hidden on those pages and the removal or emptying of `_elementor_edit_mode` is refused on the server. Pages left empty in Elementor can still be switched back. On by default, with a setting to switch it off; included in export and import.

= 1.4.1 =
* Added: updates from the GitHub releases of the plugin, through the bundled Plugin Update Checker 6.0 (MIT).
* Added: Update URI header, so WordPress never looks for this slug on wordpress.org.
* Added: uninstall also removes the update checker state.

= 1.4.0 =
* Changed: the settings page is available only to excluded users, and changes to the plugin options made by a logged-in restricted user are discarded, whatever the write path.
* Changed: when no saved user is an existing administrator, the plugin restricts nobody and shows a warning, instead of silently restricting everybody.
* Changed: on a new install the activating user is stored as the first excluded user (none when activated without a user, e.g. from WP-CLI). User ID 1 is used only on sites upgraded from an older version that never saved the list.
* Changed: only administrators are listed as candidates for the excluded list.
* Changed: the file editor capabilities (`edit_plugins`, `edit_themes`, `edit_files`) are blocked for restricted users.
* Changed: no default CSS selector, and the Hello Elementor menu is no longer hidden by default. Existing saved settings are not touched.
* Added: `wpccd_blocked_caps` and `wpccd_hide_all_notices` filters.
* Added: uninstall cleanup of options and transients.
* Fixed: CSS selectors are sanitised so they cannot break out of the style block.
* Fixed: importing a malformed request no longer causes a PHP TypeError.
* Fixed: the favicon style is no longer printed for logged-out visitors.
* Fixed: warning when a cached admin bar node had no title.

= 1.3.0 =
* Added: WP logo replaced with site favicon in the admin bar for restricted users.
* Added: Dashboard widget selection (auto-discovered list).
* Added: Admin bar node selection (auto-discovered, with admin/frontend context badges).
* Added: Configurable custom footer text with HTML support and live preview.
* Added: Export / Import settings via JSON.
* Added: Settings menu (options-general.php) now available in the menu removal list.
* Improved: WP logo dropdown children (About WordPress, external links) fully removed.
* Improved: WP logo link is context-aware: it goes to the frontend from admin and vice versa.

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

= 1.5.0 =
Restricted users can no longer switch a page built with Elementor back to the WordPress editor. On by default: switch it off under Settings > Clean Dashboard > Elementor.

= 1.4.1 =
Updates now come from the GitHub releases of the plugin. Install this version by hand once; the next ones arrive in Dashboard > Updates.

= 1.4.0 =
The settings page is now visible only to excluded users, and file editors are blocked for restricted users. Check the excluded list after updating: if it holds no existing administrator, restrictions switch off until you save it.
