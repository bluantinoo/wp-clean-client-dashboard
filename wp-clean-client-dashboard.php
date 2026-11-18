<?php
/**
 * Plugin Name:  WP Clean Client Dashboard
 * Plugin URI:   https://github.com/bluantinoo/wp-clean-client-dashboard
 * Description:  Hides notices, blocks updates, hides menu items and cleans the admin UI for all users except designated ones.
 * Version:      1.4.0
 * Author:       Diego Vagnoli
 * Author URI:   https://diegovagnoli.it
 * License:      GPL-2.0-or-later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  wp-clean-client-dashboard
 * Domain Path:  /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class WP_Clean_Client_Dashboard {

    // =========================================================================
    // CONFIGURATION
    // =========================================================================

    /**
     * true = hides ALL admin notices; false = only those specified via CSS selectors.
     * Can be changed with the `wpccd_hide_all_notices` filter.
     */
    private const HIDE_ALL_NOTICES = true;

    /** Database option keys. */
    private const OPT_USERS     = 'wpccd_allowed_users';
    private const OPT_SELECTORS = 'wpccd_hidden_selectors';
    private const OPT_MENUS     = 'wpccd_hidden_menus';
    private const OPT_WIDGETS   = 'wpccd_hidden_widgets';
    private const OPT_ADMIN_BAR = 'wpccd_hidden_admin_bar_nodes';
    private const OPT_FOOTER    = 'wpccd_footer_text';

    /**
     * Transient keys used to cache discovered lists.
     * Both are populated incrementally as an allowed user navigates the site.
     */
    private const TRANSIENT_WIDGETS   = 'wpccd_dashboard_widgets';
    private const TRANSIENT_ADMIN_BAR = 'wpccd_admin_bar_nodes';

    /** Default hidden CSS selectors (used before the first save). */
    private const DEFAULT_SELECTORS = '';

    /**
     * Menu items hidden by default.
     * Format: "top-slug" for top-level menus, "parent-slug::child-slug" for sub-menus.
     */
    private const DEFAULT_HIDDEN_MENUS = [
        'index.php::update-core.php',   // Dashboard → Updates
        'themes.php::theme-editor.php',  // Appearance → Theme File Editor
        'themes.php::site-editor.php',   // Appearance → Site Editor
        'themes.php::customize.php',     // Appearance → Customizer
    ];

    /**
     * Capabilities removed from restricted users.
     * Can be changed with the `wpccd_blocked_caps` filter (e.g. add 'activate_plugins').
     */
    private const DEFAULT_BLOCKED_CAPS = [
        'update_core',
        'update_plugins',
        'update_themes',
        'install_plugins',
        'install_themes',
        'delete_plugins',
        'delete_themes',
        'edit_plugins',
        'edit_themes',
        'edit_files',
        // The Customizer is blocked by handle_customize_redirect(): 'customize' is a
        // meta capability mapped to edit_theme_options, which also covers Menus and Widgets.
    ];

    /** HTML allowed in the custom footer text. */
    private const FOOTER_ALLOWED_HTML = [
        'a'      => [ 'href' => true, 'target' => true, 'rel' => true, 'title' => true ],
        'strong' => [],
        'em'     => [],
        'span'   => [ 'style' => true ],
        'br'     => [],
    ];

    /**
     * Per-request cache of the effective allowed user IDs (null = not computed yet).
     *
     * @var int[]|null
     */
    private ?array $allowed_ids = null;

    /**
     * Per-request cache of the filtered blocked capabilities (null = not computed yet).
     *
     * @var string[]|null
     */
    private ?array $blocked_caps = null;

    // =========================================================================

    public static function init(): void {
        $instance = new self();

        add_action( 'init', static function () {
            load_plugin_textdomain(
                'wp-clean-client-dashboard',
                false,
                dirname( plugin_basename( __FILE__ ) ) . '/languages'
            );
        } );

        // Reset the cached allowed list whenever the option changes.
        $reset = static function () use ( $instance ) {
            $instance->allowed_ids = null;
        };
        add_action( 'add_option_' . self::OPT_USERS,    $reset );
        add_action( 'update_option_' . self::OPT_USERS, $reset );
        add_action( 'delete_option_' . self::OPT_USERS, $reset );

        add_action( 'admin_init',        [ $instance, 'register_settings' ] );
        add_filter( 'option_page_capability_wpccd_group', [ $instance, 'settings_capability' ] );
        // Covers every other write path too (e.g. options.php with option_page=options).
        foreach ( [ self::OPT_USERS, self::OPT_SELECTORS, self::OPT_MENUS, self::OPT_WIDGETS, self::OPT_ADMIN_BAR, self::OPT_FOOTER ] as $option ) {
            add_filter( 'pre_update_option_' . $option, [ $instance, 'keep_option_for_restricted_users' ], 10, 2 );
        }
        add_action( 'admin_notices',     [ $instance, 'maybe_show_unconfigured_notice' ] );
        add_action( 'admin_init',        [ $instance, 'handle_notices' ] );
        add_action( 'admin_init',        [ $instance, 'handle_customize_redirect' ] );
        add_filter( 'user_has_cap',      [ $instance, 'block_update_caps' ], 10, 4 );
        add_action( 'admin_menu',        [ $instance, 'add_settings_page' ], 999 );
        add_action( 'admin_menu',        [ $instance, 'remove_admin_menu_items' ], 99999 );
        add_action( 'admin_bar_menu',    [ $instance, 'remove_admin_bar_items' ], 999 );
        add_action( 'admin_head',        [ $instance, 'hide_help_tab' ] );
        add_filter( 'admin_footer_text',  [ $instance, 'custom_admin_footer' ] );
        add_filter( 'update_footer',      [ $instance, 'custom_admin_footer_version' ], 99 );
        // Priority 998: cache lists (allowed users only) before removal at 999.
        add_action( 'wp_dashboard_setup',          [ $instance, 'cache_dashboard_widgets' ], 998 );
        add_action( 'wp_dashboard_setup',          [ $instance, 'remove_dashboard_widgets' ], 999 );
        // wp_before_admin_bar_render fires after admin_bar_menu, on both frontend and admin.
        add_action( 'wp_before_admin_bar_render',  [ $instance, 'cache_admin_bar_nodes' ], 998 );
        add_action( 'wp_before_admin_bar_render',  [ $instance, 'remove_admin_bar_nodes' ], 999 );
        // Favicon: fires on both admin pages and frontend (for the frontend admin bar).
        add_action( 'admin_head',                  [ $instance, 'replace_wp_logo_with_favicon' ] );
        add_action( 'wp_head',                     [ $instance, 'replace_wp_logo_with_favicon' ] );
        // Import handler: needs to run before any output so it can redirect.
        add_action( 'admin_init',                  [ $instance, 'handle_import' ] );
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Stores the activating user as the first allowed user, so a fresh install
     * never starts with a guessed list. Existing settings are kept.
     * Without a current user (e.g. WP-CLI without --user) an empty list is stored:
     * the plugin starts unconfigured and asks for the excluded users.
     */
    public static function activate(): void {
        if ( false === get_option( self::OPT_USERS, false ) ) {
            $user_id = get_current_user_id();
            add_option( self::OPT_USERS, $user_id ? [ $user_id ] : [] );
        }
    }

    /**
     * Returns the user IDs saved in the options, as integers.
     * Sites upgraded from a version before 1.4.0 that never saved the list fall
     * back to user ID 1, the old default; it is still validated like any saved ID.
     */
    private function get_saved_user_ids(): array {
        $saved = get_option( self::OPT_USERS, false );
        if ( false === $saved ) {
            $saved = [ 1 ];
        }
        return array_values( array_map( 'intval', (array) $saved ) );
    }

    /**
     * Returns the saved user IDs that still belong to an existing administrator.
     *
     * IDs of deleted users, or of users who lost manage_options (e.g. after a
     * database migration that renumbered users), are ignored. Reads the role
     * capabilities directly instead of calling user_can(), because this runs
     * inside the user_has_cap filter and must not recurse into it.
     *
     * @return int[]
     */
    private function get_allowed_user_ids(): array {
        if ( null !== $this->allowed_ids ) {
            return $this->allowed_ids;
        }

        $valid = [];
        foreach ( $this->get_saved_user_ids() as $id ) {
            $user = $id > 0 ? get_userdata( $id ) : false;
            // is_super_admin() is only safe on multisite: on a single site it calls has_cap().
            if ( $user && ( ! empty( $user->allcaps['manage_options'] ) || ( is_multisite() && is_super_admin( $id ) ) ) ) {
                $valid[] = $id;
            }
        }

        $this->allowed_ids = $valid;
        return $valid;
    }

    /**
     * True when no saved user is an existing administrator.
     * In this state the plugin restricts nobody (fail-open): a cleanup tool must
     * never lock every administrator out of its own settings.
     */
    private function is_unconfigured(): bool {
        return empty( $this->get_allowed_user_ids() );
    }

    /** Checks whether a user is exempt from the restrictions. */
    private function is_allowed_id( int $user_id ): bool {
        return $this->is_unconfigured() || in_array( $user_id, $this->get_allowed_user_ids(), true );
    }

    /** Checks whether the current user is exempt from the restrictions. */
    private function is_allowed_user(): bool {
        return $this->is_allowed_id( (int) get_current_user_id() );
    }

    /** Checks whether the current user may view and change the plugin settings. */
    private function can_manage_settings(): bool {
        return current_user_can( 'manage_options' ) && $this->is_allowed_user();
    }

    /** Returns the CSS selectors to hide as a clean array. */
    private function get_hidden_selectors(): array {
        $raw = $this->sanitize_selectors( get_option( self::OPT_SELECTORS, self::DEFAULT_SELECTORS ) );
        return array_values( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) );
    }

    /** Returns the list of menu items to hide. */
    private function get_hidden_menus(): array {
        return (array) get_option( self::OPT_MENUS, self::DEFAULT_HIDDEN_MENUS );
    }

    /** Returns the list of dashboard widget IDs to hide. */
    private function get_hidden_widgets(): array {
        return (array) get_option( self::OPT_WIDGETS, [] );
    }

    /** Returns the list of admin bar node IDs to hide. */
    private function get_hidden_admin_bar_nodes(): array {
        return (array) get_option( self::OPT_ADMIN_BAR, [] );
    }

    /** Returns the custom admin footer HTML (empty string = no custom footer). */
    private function get_footer_text(): string {
        return (string) get_option( self::OPT_FOOTER, '' );
    }

    // =========================================================================
    // SETTINGS PAGE
    // =========================================================================

    /**
     * Adds the settings sub-page under Settings.
     * Only allowed users get the page: a restricted administrator cannot open it
     * and add themselves to the allowed list.
     */
    public function add_settings_page(): void {
        if ( ! $this->is_allowed_user() ) {
            return;
        }

        add_options_page(
            __( 'Clean Dashboard Settings', 'wp-clean-client-dashboard' ),
            __( 'Clean Dashboard', 'wp-clean-client-dashboard' ),
            'manage_options',
            'wpccd-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    /**
     * Capability required by options.php to save the settings group.
     * Restricted users get a capability nobody has, so a forged POST is rejected.
     */
    public function settings_capability( string $capability ): string {
        return $this->is_allowed_user() ? $capability : 'do_not_allow';
    }

    /**
     * Discards changes to the plugin options made by a restricted user, whatever
     * the write path. Requests without a logged-in user (WP-CLI, cron) are not affected.
     */
    public function keep_option_for_restricted_users( mixed $value, mixed $old_value ): mixed {
        if ( is_user_logged_in() && ! $this->is_allowed_user() ) {
            return $old_value;
        }
        return $value;
    }

    /**
     * Warns administrators when the allowed list holds no existing administrator,
     * which switches all restrictions off until the settings are saved again.
     */
    public function maybe_show_unconfigured_notice(): void {
        if ( ! $this->is_unconfigured() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
            esc_html__( 'WP Clean Client Dashboard: no existing administrator is in the list of users excluded from restrictions, so no restriction is applied.', 'wp-clean-client-dashboard' ),
            esc_url( admin_url( 'options-general.php?page=wpccd-settings' ) ),
            esc_html__( 'Choose the excluded users', 'wp-clean-client-dashboard' )
        );
    }

    /**
     * Registers options via the WordPress Settings API.
     */
    public function register_settings(): void {
        register_setting( 'wpccd_group', self::OPT_USERS, [
            'sanitize_callback' => [ $this, 'sanitize_user_ids' ],
            'default'           => [],
        ] );

        register_setting( 'wpccd_group', self::OPT_SELECTORS, [
            'sanitize_callback' => [ $this, 'sanitize_selectors' ],
            'default'           => self::DEFAULT_SELECTORS,
        ] );

        register_setting( 'wpccd_group', self::OPT_MENUS, [
            'sanitize_callback' => [ $this, 'sanitize_menu_list' ],
            'default'           => self::DEFAULT_HIDDEN_MENUS,
        ] );

        register_setting( 'wpccd_group', self::OPT_WIDGETS, [
            'sanitize_callback' => [ $this, 'sanitize_menu_list' ], // same logic: array of strings
            'default'           => [],
        ] );

        register_setting( 'wpccd_group', self::OPT_ADMIN_BAR, [
            'sanitize_callback' => [ $this, 'sanitize_menu_list' ],
            'default'           => [],
        ] );

        register_setting( 'wpccd_group', self::OPT_FOOTER, [
            'sanitize_callback' => [ $this, 'sanitize_footer_html' ],
            'default'           => '',
        ] );

        add_settings_section( 'wpccd_s_users',    __( 'Users excluded from restrictions', 'wp-clean-client-dashboard' ), '__return_false', 'wpccd-settings' );
        add_settings_section( 'wpccd_s_widgets',  __( 'Dashboard widgets to hide', 'wp-clean-client-dashboard' ), [ $this, 'render_section_widgets_intro' ], 'wpccd-settings' );
        add_settings_section( 'wpccd_s_menus',    __( 'Menu items to hide', 'wp-clean-client-dashboard' ), [ $this, 'render_section_menus_intro' ], 'wpccd-settings' );
        add_settings_section( 'wpccd_s_adminbar', __( 'Admin bar nodes to hide', 'wp-clean-client-dashboard' ), [ $this, 'render_section_adminbar_intro' ], 'wpccd-settings' );
        add_settings_section( 'wpccd_s_css',      __( 'CSS selectors to hide', 'wp-clean-client-dashboard' ), '__return_false', 'wpccd-settings' );
        add_settings_section( 'wpccd_s_footer',   __( 'Custom footer text', 'wp-clean-client-dashboard' ), '__return_false', 'wpccd-settings' );

        add_settings_field( 'wpccd_f_users',    '', [ $this, 'render_field_users' ],    'wpccd-settings', 'wpccd_s_users' );
        add_settings_field( 'wpccd_f_widgets',  '', [ $this, 'render_field_widgets' ],  'wpccd-settings', 'wpccd_s_widgets' );
        add_settings_field( 'wpccd_f_menus',    '', [ $this, 'render_field_menus' ],    'wpccd-settings', 'wpccd_s_menus' );
        add_settings_field( 'wpccd_f_adminbar', '', [ $this, 'render_field_adminbar' ], 'wpccd-settings', 'wpccd_s_adminbar' );
        add_settings_field( 'wpccd_f_css',      '', [ $this, 'render_field_selectors' ], 'wpccd-settings', 'wpccd_s_css' );
        add_settings_field( 'wpccd_f_footer',   '', [ $this, 'render_field_footer' ],   'wpccd-settings', 'wpccd_s_footer' );
    }

    /**
     * Sanitises the array of user IDs, keeping only existing users.
     * An empty result is allowed: it leaves the plugin unconfigured (no restrictions)
     * rather than restricting everybody.
     */
    public function sanitize_user_ids( mixed $value ): array {
        if ( ! is_array( $value ) ) {
            return [];
        }
        $ids = array_unique( array_filter( array_map( 'intval', $value ) ) );
        return array_values( array_filter( $ids, static fn( int $id ) => false !== get_userdata( $id ) ) );
    }

    /**
     * Sanitises the CSS selectors textarea.
     * Selectors are printed inside a <style> block, so characters that could close
     * the rule or the tag ({ } < ;), at-rules, backslash escapes and comment markers are removed.
     */
    public function sanitize_selectors( mixed $value ): string {
        if ( ! is_string( $value ) ) {
            return '';
        }
        $value = sanitize_textarea_field( $value );
        // '>' is kept: it is harmless inside <style> and needed for child combinators.
        // Repeated until stable, so removing one character cannot form a new "/*".
        do {
            $before = $value;
            $value  = (string) preg_replace( '#[{}<;@\\\\]|/(?=\*)|\*(?=/)#', '', $value );
        } while ( $value !== $before );
        return $value;
    }

    /** Sanitises the list of menu items. */
    public function sanitize_menu_list( mixed $value ): array {
        if ( ! is_array( $value ) ) {
            return [];
        }
        return array_values( array_map( 'sanitize_text_field', $value ) );
    }

    /** Sanitises the custom footer HTML, allowing only safe inline tags. */
    public function sanitize_footer_html( mixed $value ): string {
        if ( ! is_string( $value ) ) {
            return '';
        }
        return wp_kses( trim( $value ), self::FOOTER_ALLOWED_HTML );
    }

    /**
     * Handles the import form POST: validates nonce, parses JSON, updates options,
     * then redirects back to the settings page with a success/error notice.
     */
    public function handle_import(): void {
        // Bail early if the import form was not submitted.
        if ( empty( $_POST['wpccd_import_nonce'] ) ) {
            return;
        }

        if (
            ! wp_verify_nonce( sanitize_key( $_POST['wpccd_import_nonce'] ), 'wpccd_import' )
            || ! $this->can_manage_settings()
        ) {
            return;
        }

        $redirect = admin_url( 'options-general.php?page=wpccd-settings' );
        $json     = wp_unslash( $_POST['wpccd_import_json'] ?? '' );
        $data     = is_string( $json ) ? json_decode( $json, true ) : null;

        if ( ! is_array( $data ) ) {
            wp_safe_redirect( add_query_arg( 'wpccd_notice', 'import_error', $redirect ) );
            exit;
        }

        $map = [
            self::OPT_USERS     => [ $this, 'sanitize_user_ids' ],
            self::OPT_SELECTORS => [ $this, 'sanitize_selectors' ],
            self::OPT_MENUS     => [ $this, 'sanitize_menu_list' ],
            self::OPT_WIDGETS   => [ $this, 'sanitize_menu_list' ],
            self::OPT_ADMIN_BAR => [ $this, 'sanitize_menu_list' ],
            self::OPT_FOOTER    => [ $this, 'sanitize_footer_html' ],
        ];

        foreach ( $map as $key => $sanitizer ) {
            if ( array_key_exists( $key, $data ) ) {
                update_option( $key, call_user_func( $sanitizer, $data[ $key ] ) );
            }
        }

        wp_safe_redirect( add_query_arg( 'wpccd_notice', 'import_ok', $redirect ) );
        exit;
    }

    public function render_section_widgets_intro(): void {
        echo '<p class="description">'
            . esc_html__( 'Select dashboard widgets to hide from unauthorised users. The list is built the first time an allowed user visits the Dashboard.', 'wp-clean-client-dashboard' )
            . '</p>';
    }

    /**
     * Renders widget checkboxes from the cached transient.
     * The transient is populated by cache_dashboard_widgets() when an allowed
     * user visits the WordPress Dashboard (wp-admin/index.php).
     */
    public function render_field_widgets(): void {
        $cached = get_transient( self::TRANSIENT_WIDGETS );

        if ( empty( $cached ) ) {
            echo '<p class="description" style="color:#d63638">'
                . esc_html__( 'No widgets found yet. Visit the Dashboard page once as an allowed user to populate this list.', 'wp-clean-client-dashboard' )
                . '</p>';
            return;
        }

        $saved = $this->get_hidden_widgets();
        $opt   = self::OPT_WIDGETS;

        echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:6px 20px;max-width:800px">';
        foreach ( $cached as $id => $title ) {
            $checked = in_array( $id, $saved, true );
            printf(
                '<label style="display:flex;align-items:center;gap:6px;cursor:pointer">'
                    . '<input type="checkbox" name="%s[]" value="%s"%s> '
                    . '%s <code style="font-size:11px;color:#888">%s</code>'
                . '</label>',
                esc_attr( $opt ),
                esc_attr( $id ),
                checked( $checked, true, false ),
                esc_html( $title ?: $id ),
                esc_html( $id )
            );
        }
        echo '</div>';
        echo '<p class="description" style="margin-top:8px">'
            . esc_html__( 'The widget ID is shown in grey next to the title.', 'wp-clean-client-dashboard' )
            . '</p>';
    }

    public function render_section_adminbar_intro(): void {
        echo '<p class="description">'
            . esc_html__( 'Select admin bar nodes to hide from unauthorised users, on both frontend and backend.', 'wp-clean-client-dashboard' )
            . ' ' . $this->admin_bar_context_badge( true, false ) . ' '
            . esc_html__( 'visible only in wp-admin', 'wp-clean-client-dashboard' )
            . ' &nbsp; ' . $this->admin_bar_context_badge( false, true ) . ' '
            . esc_html__( 'visible only on the site', 'wp-clean-client-dashboard' )
            . ' &nbsp; ' . $this->admin_bar_context_badge( true, true ) . ' '
            . esc_html__( 'visible everywhere', 'wp-clean-client-dashboard' )
            . '<br><em style="font-size:12px">'
            . esc_html__( 'The list grows automatically as you browse: visit frontend pages to discover plugin nodes that appear there.', 'wp-clean-client-dashboard' )
            . '</em></p>';
    }

    /**
     * Renders admin bar node checkboxes from the cached transient.
     * Shows a coloured context badge next to each node title.
     */
    public function render_field_adminbar(): void {
        $cached = get_transient( self::TRANSIENT_ADMIN_BAR );

        if ( empty( $cached ) ) {
            echo '<p class="description" style="color:#d63638">'
                . esc_html__( 'No nodes found yet. Navigate any page as an allowed user to populate this list.', 'wp-clean-client-dashboard' )
                . '</p>';
            return;
        }

        $saved = $this->get_hidden_admin_bar_nodes();
        $opt   = self::OPT_ADMIN_BAR;

        // Sort alphabetically by title
        uasort( $cached, fn( $a, $b ) => strcmp( (string) ( $a['title'] ?? '' ), (string) ( $b['title'] ?? '' ) ) );

        echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:6px 20px;max-width:900px">';
        foreach ( $cached as $id => $data ) {
            $checked = in_array( $id, $saved, true );
            $badge   = $this->admin_bar_context_badge( $data['admin'] ?? false, $data['frontend'] ?? false );

            printf(
                '<label style="display:flex;align-items:center;gap:6px;cursor:pointer">'
                    . '<input type="checkbox" name="%s[]" value="%s"%s> '
                    . '%s %s <code style="font-size:11px;color:#888">%s</code>'
                . '</label>',
                esc_attr( $opt ),
                esc_attr( $id ),
                checked( $checked, true, false ),
                esc_html( ( $data['title'] ?? '' ) ?: $id ),
                $badge, // already escaped in admin_bar_context_badge()
                esc_html( $id )
            );
        }
        echo '</div>';
        echo '<p class="description" style="margin-top:8px">'
            . esc_html__( 'Hiding a top-level node also removes its dropdown children.', 'wp-clean-client-dashboard' )
            . '</p>';
    }

    /**
     * Returns an HTML badge indicating where an admin bar node was observed.
     * Colours match the WordPress admin palette.
     */
    private function admin_bar_context_badge( bool $admin, bool $frontend ): string {
        $style = 'font-size:11px;padding:1px 6px;border-radius:3px;color:#fff;white-space:nowrap';

        if ( $admin && $frontend ) {
            return '<span style="background:#646970;' . $style . '">' . esc_html__( 'both', 'wp-clean-client-dashboard' ) . '</span>';
        }
        if ( $admin ) {
            return '<span style="background:#72aee6;' . $style . '">' . esc_html__( 'admin', 'wp-clean-client-dashboard' ) . '</span>';
        }
        return '<span style="background:#00a32a;' . $style . '">' . esc_html__( 'frontend', 'wp-clean-client-dashboard' ) . '</span>';
    }

    public function render_section_menus_intro(): void {
        echo '<p class="description">'
            . esc_html__( 'Select items to hide from unauthorised users. ★ = enabled by default.', 'wp-clean-client-dashboard' )
            . '</p>';
    }

    /** Renders a checkbox for each administrator (users with manage_options). */
    public function render_field_users(): void {
        $saved = $this->get_allowed_user_ids();
        $users = get_users( [ 'capability' => 'manage_options', 'orderby' => 'display_name', 'number' => 500 ] );
        $opt   = self::OPT_USERS;

        echo '<fieldset>';
        foreach ( $users as $user ) {
            $checked = in_array( (int) $user->ID, $saved, true );
            printf(
                '<label style="display:block;margin-bottom:6px">'
                    . '<input type="checkbox" name="%s[]" value="%d"%s> '
                    . '<strong>%s</strong> '
                    . '<span style="color:#888;font-size:12px">(ID: %d, %s)</span>'
                . '</label>',
                esc_attr( $opt ),
                (int) $user->ID,
                checked( $checked, true, false ),
                esc_html( $user->display_name ),
                (int) $user->ID,
                esc_html( $user->user_email )
            );
        }
        echo '</fieldset>';
        echo '<p class="description">'
            . esc_html__( 'Selected users will see notices and updates as normal, and are the only ones who can open this page. Keep at least one selected: with none, no restriction is applied.', 'wp-clean-client-dashboard' )
            . '</p>';
    }

    /**
     * Renders a checkbox tree for menus and sub-menus, dynamically built
     * from the WordPress $menu / $submenu globals.
     */
    public function render_field_menus(): void {
        global $menu, $submenu;

        if ( empty( $menu ) ) {
            echo '<p>' . esc_html__( 'No menu items available.', 'wp-clean-client-dashboard' ) . '</p>';
            return;
        }

        $saved    = $this->get_hidden_menus();
        $defaults = self::DEFAULT_HIDDEN_MENUS;
        $opt      = self::OPT_MENUS;

        // Sort by title
        $sorted = $menu;
        usort( $sorted, fn( $a, $b ) => strcmp( strip_tags( $a[0] ?? '' ), strip_tags( $b[0] ?? '' ) ) );

        echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:10px;max-width:960px">';

        foreach ( $sorted as $item ) {
            $top_slug  = $item[2] ?? '';
            $top_title = wp_strip_all_tags( $item[0] ?? '' );

            // Skip separators and empty items.
            // Note: options-general.php (Settings) is intentionally included: allowed users
            // always see the full menu regardless, so hiding it only affects restricted users.
            if ( empty( $top_title ) || str_starts_with( $top_slug, 'separator' ) ) {
                continue;
            }

            $top_checked = in_array( $top_slug, $saved, true );
            $top_star    = in_array( $top_slug, $defaults, true ) ? ' ★' : '';

            echo '<div style="background:#f9f9f9;border:1px solid #e0e0e0;border-radius:4px;padding:10px 12px">';

            // Top-level item
            printf(
                '<label style="display:flex;align-items:center;gap:6px;font-weight:600;cursor:pointer">'
                    . '<input type="checkbox" name="%s[]" value="%s"%s> %s%s'
                . '</label>',
                esc_attr( $opt ),
                esc_attr( $top_slug ),
                checked( $top_checked, true, false ),
                esc_html( $top_title ),
                esc_html( $top_star )
            );

            // Sub-menu items
            if ( ! empty( $submenu[ $top_slug ] ) ) {
                echo '<div style="margin-top:6px;padding-left:14px;border-left:2px solid #ddd">';

                $seen = [];
                foreach ( $submenu[ $top_slug ] as $sub ) {
                    $sub_slug  = $sub[2] ?? '';
                    $sub_title = wp_strip_all_tags( $sub[0] ?? '' );

                    if ( empty( $sub_title ) ) {
                        continue;
                    }

                    // Normalise customize.php (may carry a querystring like customize.php?return=...)
                    if ( str_starts_with( $sub_slug, 'customize.php' ) ) {
                        $sub_slug = 'customize.php';
                    }

                    $sub_value = $top_slug . '::' . $sub_slug;

                    // Deduplicate (e.g. multiple entries normalised to the same slug)
                    if ( isset( $seen[ $sub_value ] ) ) {
                        continue;
                    }
                    $seen[ $sub_value ] = true;

                    $sub_checked = in_array( $sub_value, $saved, true );
                    $sub_star    = in_array( $sub_value, $defaults, true ) ? ' ★' : '';

                    printf(
                        '<label style="display:flex;align-items:center;gap:5px;font-size:13px;margin-bottom:3px;cursor:pointer">'
                            . '<input type="checkbox" name="%s[]" value="%s"%s> %s%s'
                        . '</label>',
                        esc_attr( $opt ),
                        esc_attr( $sub_value ),
                        checked( $sub_checked, true, false ),
                        esc_html( $sub_title ),
                        esc_html( $sub_star )
                    );
                }

                echo '</div>';
            }

            echo '</div>'; // end card
        }

        echo '</div>';
        echo '<p class="description" style="margin-top:10px">'
            . esc_html__( '★ = hidden by default. Checking a top-level item hides it entirely, including its sub-items.', 'wp-clean-client-dashboard' )
            . '</p>';
    }

    /** Renders the CSS selectors textarea, one selector per line. */
    public function render_field_selectors(): void {
        $value = get_option( self::OPT_SELECTORS, self::DEFAULT_SELECTORS );
        printf(
            '<textarea name="%s" rows="10" class="large-text code" spellcheck="false">%s</textarea>',
            esc_attr( self::OPT_SELECTORS ),
            esc_textarea( $value )
        );
        echo '<p class="description">'
            . esc_html__( 'One CSS selector per line. Hidden with display:none for unauthorised users.', 'wp-clean-client-dashboard' )
            . '<br><code>.class-name</code> &nbsp;|&nbsp; <code>#element-id</code> &nbsp;|&nbsp; <code>.class-one, .class-two</code>'
            . '</p>';
    }

    /**
     * Renders the custom footer text field.
     * Shows a live preview if text is already saved.
     */
    public function render_field_footer(): void {
        $value = $this->get_footer_text();

        printf(
            '<textarea name="%s" rows="3" class="large-text" spellcheck="false" placeholder="%s">%s</textarea>',
            esc_attr( self::OPT_FOOTER ),
            esc_attr( 'Developed by <a href="https://yoursite.com" target="_blank" rel="noopener">Your Name</a> with <a href="https://wordpress.org" target="_blank" rel="noopener">WordPress</a>' ),
            esc_textarea( $value )
        );

        echo '<p class="description">'
            . esc_html__( 'Shown in the admin footer for restricted users. Leave empty to display no footer text.', 'wp-clean-client-dashboard' )
            . '<br>'
            . esc_html__( 'Allowed HTML:', 'wp-clean-client-dashboard' )
            . ' <code>&lt;a href=""&gt;</code> <code>&lt;strong&gt;</code> <code>&lt;em&gt;</code> <code>&lt;span style=""&gt;</code> <code>&lt;br&gt;</code>'
            . '</p>';

        if ( ! empty( $value ) ) {
            echo '<p style="margin-top:8px;padding:8px 12px;background:#f6f7f7;border-left:3px solid #72aee6;font-size:13px">'
                . '<em style="color:#646970;margin-right:6px">' . esc_html__( 'Preview:', 'wp-clean-client-dashboard' ) . '</em>'
                . wp_kses( $value, self::FOOTER_ALLOWED_HTML )
                . '</p>';
        }
    }

    /** Renders the settings page. */
    public function render_settings_page(): void {
        if ( ! $this->can_manage_settings() ) {
            return;
        }

        $current_user    = wp_get_current_user();
        $is_allowed      = $this->is_allowed_user();
        $status_colour   = $is_allowed ? '#00a32a' : '#d63638';
        $status_label    = $is_allowed
            ? __( 'Full access', 'wp-clean-client-dashboard' )
            : __( 'Restricted', 'wp-clean-client-dashboard' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Clean Dashboard Settings', 'wp-clean-client-dashboard' ); ?></h1>

            <?php
            // phpcs:disable WordPress.Security.NonceVerification.Recommended
            $notice = isset( $_GET['wpccd_notice'] ) ? sanitize_key( $_GET['wpccd_notice'] ) : '';
            if ( $notice ) {
                if ( 'import_ok' === $notice ) {
                    echo '<div class="notice notice-success is-dismissible"><p>'
                        . esc_html__( 'Settings imported successfully.', 'wp-clean-client-dashboard' )
                        . '</p></div>';
                } elseif ( 'import_error' === $notice ) {
                    echo '<div class="notice notice-error is-dismissible"><p>'
                        . esc_html__( 'Invalid JSON: please check your export and try again.', 'wp-clean-client-dashboard' )
                        . '</p></div>';
                }
            }
            // phpcs:enable
            ?>

            <div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #72aee6;padding:14px 16px;margin:16px 0 24px;max-width:800px">
                <p style="margin:0 0 8px">
                    <?php esc_html_e( 'Cleans up the WordPress admin for non-technical users. Excluded users keep full access. All other admins will have: update capabilities blocked · notices hidden · admin menu and admin bar items selectively removed · dashboard widgets hideable · Help tab suppressed · WP logo replaced with the site favicon · custom footer branding.', 'wp-clean-client-dashboard' ); ?>
                </p>
                <p style="margin:0 0 8px">
                    <?php esc_html_e( 'This is interface cleanup, not access control: restricted users are still administrators. Update notices are hidden from them too, so an excluded user must keep WordPress, plugins and themes up to date.', 'wp-clean-client-dashboard' ); ?>
                </p>
                <p style="margin:0;font-size:13px;color:#646970">
                    <?php
                    printf(
                        /* translators: 1: display name, 2: user ID, 3: coloured status label */
                        esc_html__( 'You are logged in as %1$s (ID: %2$d): %3$s', 'wp-clean-client-dashboard' ),
                        '<strong>' . esc_html( $current_user->display_name ) . '</strong>',
                        (int) $current_user->ID,
                        '<strong style="color:' . esc_attr( $status_colour ) . '">' . esc_html( $status_label ) . '</strong>'
                    );
                    ?>
                </p>
            </div>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'wpccd_group' );
                do_settings_sections( 'wpccd-settings' );
                submit_button( __( 'Save settings', 'wp-clean-client-dashboard' ) );
                ?>
            </form>

            <?php
            /* ---- Export / Import ---- */
            $export_data = [
                self::OPT_USERS     => $this->get_saved_user_ids(),
                self::OPT_SELECTORS => get_option( self::OPT_SELECTORS, self::DEFAULT_SELECTORS ),
                self::OPT_MENUS     => $this->get_hidden_menus(),
                self::OPT_WIDGETS   => $this->get_hidden_widgets(),
                self::OPT_ADMIN_BAR => $this->get_hidden_admin_bar_nodes(),
                self::OPT_FOOTER    => $this->get_footer_text(),
            ];
            $export_json = wp_json_encode( $export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
            ?>
            <details style="margin-top:30px;max-width:800px">
                <summary style="cursor:pointer;font-weight:600;font-size:14px;padding:10px 14px;background:#f6f7f7;border:1px solid #c3c4c7;border-radius:4px;user-select:none">
                    <?php esc_html_e( 'Export / Import settings', 'wp-clean-client-dashboard' ); ?>
                </summary>
                <div style="border:1px solid #c3c4c7;border-top:none;padding:20px 20px 16px;border-radius:0 0 4px 4px">

                    <h3 style="margin-top:0"><?php esc_html_e( 'Export', 'wp-clean-client-dashboard' ); ?></h3>
                    <p class="description"><?php esc_html_e( 'Copy the JSON below to back up or transfer your settings to another site.', 'wp-clean-client-dashboard' ); ?></p>
                    <textarea rows="10" class="large-text code" readonly onclick="this.select()" spellcheck="false"><?php echo esc_textarea( $export_json ); ?></textarea>

                    <h3><?php esc_html_e( 'Import', 'wp-clean-client-dashboard' ); ?></h3>
                    <p class="description"><?php esc_html_e( 'Paste a previously exported JSON to restore settings. This will overwrite current values.', 'wp-clean-client-dashboard' ); ?></p>
                    <form method="post">
                        <?php wp_nonce_field( 'wpccd_import', 'wpccd_import_nonce' ); ?>
                        <textarea name="wpccd_import_json" rows="6" class="large-text code" spellcheck="false" placeholder='{"wpccd_allowed_users":[1],...}'></textarea>
                        <p><?php submit_button( __( 'Import settings', 'wp-clean-client-dashboard' ), 'secondary', 'wpccd_import_submit', false ); ?></p>
                    </form>

                </div>
            </details>

        </div>
        <?php
    }

    // =========================================================================
    // CORE LOGIC
    // =========================================================================

    /**
     * Replaces the left footer text with the configured text for unauthorised users.
     * Allowed users keep the default WordPress footer.
     */
    public function custom_admin_footer( string $text ): string {
        if ( $this->is_allowed_user() ) {
            return $text;
        }
        // Returns the configured text (may be empty: empty = no footer branding).
        return $this->get_footer_text();
    }

    /** Removes the WordPress version string from the right side of the footer for unauthorised users. */
    public function custom_admin_footer_version( string $text ): string {
        return $this->is_allowed_user() ? $text : '';
    }

    /**
     * Hides the Help tab for unauthorised users while leaving Screen Options intact.
     * The tab button (#contextual-help-link-wrap) is suppressed via CSS.
     */
    public function hide_help_tab(): void {
        if ( $this->is_allowed_user() ) {
            return;
        }
        echo '<style>#contextual-help-link-wrap{display:none!important}</style>' . "\n";
    }

    /**
     * Replaces the WordPress logo in the admin bar with the site favicon for
     * unauthorised users. Fires on both admin_head and wp_head so it covers the
     * admin bar on frontend pages too.
     * Graceful fallback: if no site icon is configured, nothing is output and
     * the default WP logo is left intact.
     */
    public function replace_wp_logo_with_favicon(): void {
        if ( ! is_admin_bar_showing() || $this->is_allowed_user() ) {
            return;
        }

        $favicon = get_site_icon_url( 32 );
        if ( empty( $favicon ) ) {
            return;
        }

        // We use ::after instead of background-image on .ab-icon itself because
        // WordPress's own stylesheet resets background-image on .ab-icon with a
        // higher-specificity (3 IDs) !important rule. The ::after pseudo-element
        // is not targeted by that rule, so our image renders correctly.
        printf(
            '<style>'
                . '#wpadminbar #wp-admin-bar-wp-logo>.ab-item .ab-icon::before{'
                    . 'content:""!important;display:none!important'
                . '}'
                . '#wpadminbar #wp-admin-bar-wp-logo>.ab-item .ab-icon::after{'
                    . 'content:""!important;display:block!important;'
                    . 'width:20px!important;height:20px!important;'
                    . 'background-image:url(%s)!important;'
                    . 'background-size:contain!important;'
                    . 'background-repeat:no-repeat!important;'
                    . 'background-position:center!important'
                . '}'
            . '</style>' . "\n",
            esc_url( $favicon )
        );
    }

    /**
     * (1 + 2) Hides admin notices for unauthorised users.
     */
    public function handle_notices(): void {
        if ( $this->is_allowed_user() ) {
            return;
        }

        $selectors = $this->get_hidden_selectors();
        if ( ! empty( $selectors ) ) {
            add_action( 'admin_head', static function () use ( $selectors ) {
                // Selectors went through sanitize_selectors(): no characters left that can break out of the rule.
                $safe = implode( ', ', $selectors );
                echo '<style>' . $safe . '{display:none!important}</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            } );
        }

        /**
         * Filters whether every admin notice is removed for restricted users.
         *
         * @param bool $hide_all Default true. Return false to hide only the configured CSS selectors.
         */
        if ( apply_filters( 'wpccd_hide_all_notices', self::HIDE_ALL_NOTICES ) ) {
            remove_all_actions( 'admin_notices' );
            remove_all_actions( 'all_admin_notices' );
        }
    }

    /**
     * Redirects to the Dashboard if an unauthorised user tries to open
     * customize.php directly via URL.
     */
    public function handle_customize_redirect(): void {
        if ( $this->is_allowed_user() ) {
            return;
        }

        global $pagenow;
        if ( $pagenow === 'customize.php' ) {
            wp_safe_redirect( admin_url() );
            exit;
        }
    }

    /**
     * (3) Blocks update / install / file editing / customizer capabilities for unauthorised users.
     *
     * @param bool[]   $allcaps User capabilities.
     * @param string[] $caps    Required capabilities.
     * @param array    $args    Additional arguments.
     * @param WP_User  $user    User object.
     * @return bool[]
     */
    public function block_update_caps( array $allcaps, array $caps, array $args, WP_User $user ): array {
        if ( empty( $user->ID ) || $this->is_allowed_id( (int) $user->ID ) ) {
            return $allcaps;
        }

        if ( null === $this->blocked_caps ) {
            // Set the defaults first: a callback that calls current_user_can() re-enters
            // this filter and must find a value instead of recursing.
            $this->blocked_caps = self::DEFAULT_BLOCKED_CAPS;

            /**
             * Filters the capabilities removed from restricted users.
             *
             * @param string[] $blocked Capability names. Add 'activate_plugins' to also hide the Plugins screen actions.
             */
            $this->blocked_caps = (array) apply_filters( 'wpccd_blocked_caps', self::DEFAULT_BLOCKED_CAPS );
        }

        foreach ( $this->blocked_caps as $cap ) {
            $allcaps[ $cap ] = false;
        }

        return $allcaps;
    }

    /**
     * (4a) Removes saved menu items from options.
     * Also handles robust Customizer removal (slug may carry a querystring).
     */
    public function remove_admin_menu_items(): void {
        if ( $this->is_allowed_user() ) {
            return;
        }

        global $submenu;
        $hidden           = $this->get_hidden_menus();
        $robust_customize = false;

        foreach ( $hidden as $entry ) {
            if ( str_contains( $entry, '::' ) ) {
                [ $parent, $child ] = explode( '::', $entry, 2 );
                remove_submenu_page( $parent, $child );

                if ( $child === 'customize.php' ) {
                    $robust_customize = true;
                }
            } else {
                remove_menu_page( $entry );
            }
        }

        // Robust removal: delete any sub-menu under themes.php whose slug starts
        // with "customize.php": covers querystring variants like customize.php?return=...
        if ( $robust_customize && ! empty( $submenu['themes.php'] ) ) {
            foreach ( $submenu['themes.php'] as $i => $item ) {
                $slug = $item[2] ?? '';
                if ( is_string( $slug ) && str_starts_with( $slug, 'customize.php' ) ) {
                    unset( $submenu['themes.php'][ $i ] );
                }
            }
        }
    }

    /**
     * (4b) Removes nodes from the admin bar based on hidden menu items.
     *
     * @param WP_Admin_Bar $wp_admin_bar
     */
    public function remove_admin_bar_items( WP_Admin_Bar $wp_admin_bar ): void {
        if ( $this->is_allowed_user() ) {
            return;
        }

        // Remove ALL children of the WP logo node (About WP, external links, any
        // plugin-added items like "Contribute"). We iterate all registered nodes
        // instead of hardcoding IDs because plugins may add their own children.
        // Structure: wp-logo > about (direct)
        //            wp-logo > wp-logo-external (group) > wporg, documentation, support, feedback, …
        foreach ( $wp_admin_bar->get_nodes() as $id => $node ) {
            if ( in_array( $node->parent, [ 'wp-logo', 'wp-logo-external' ], true ) ) {
                $wp_admin_bar->remove_node( $id );
            }
        }
        $wp_admin_bar->remove_node( 'wp-logo-external' );

        // Context-aware href: in wp-admin → go to the site frontend;
        //                     on frontend  → go to the admin dashboard.
        $wp_admin_bar->add_node( [ 'id' => 'wp-logo', 'href' => is_admin() ? home_url( '/' ) : admin_url() ] );

        $hidden = $this->get_hidden_menus();

        foreach ( $hidden as $entry ) {
            // Updates badge (the little number icon)
            if ( $entry === 'index.php::update-core.php' ) {
                $wp_admin_bar->remove_node( 'updates' );
            }

            // Customizer
            if ( str_contains( $entry, '::customize.php' ) ) {
                $wp_admin_bar->remove_node( 'customize' );
            }
        }
    }

    /**
     * Caches the list of registered dashboard widgets into a transient (24 h).
     * Runs at priority 998 on wp_dashboard_setup, only for allowed users,
     * so the full widget list (before any removal) is always captured.
     */
    public function cache_dashboard_widgets(): void {
        if ( ! $this->is_allowed_user() ) {
            return;
        }

        global $wp_meta_boxes;

        $widgets  = [];
        $contexts = $wp_meta_boxes['dashboard'] ?? [];

        foreach ( $contexts as $priorities ) {
            foreach ( $priorities as $items ) {
                foreach ( $items as $id => $widget ) {
                    // WordPress sets removed widgets to false, skip them.
                    if ( ! empty( $widget ) && ! isset( $widgets[ $id ] ) ) {
                        $widgets[ $id ] = wp_strip_all_tags( $widget['title'] ?? $id );
                    }
                }
            }
        }

        if ( ! empty( $widgets ) ) {
            set_transient( self::TRANSIENT_WIDGETS, $widgets, DAY_IN_SECONDS );
        }
    }

    /**
     * Removes selected dashboard widgets for unauthorised users.
     * Tries all known contexts so no prior knowledge of placement is needed.
     */
    public function remove_dashboard_widgets(): void {
        if ( $this->is_allowed_user() ) {
            return;
        }

        $hidden   = $this->get_hidden_widgets();
        $contexts = [ 'normal', 'side', 'column3', 'column4' ];

        foreach ( $hidden as $widget_id ) {
            foreach ( $contexts as $context ) {
                remove_meta_box( $widget_id, 'dashboard', $context );
            }
        }
    }

    /**
     * Caches all top-level admin bar nodes into a transient (24 h).
     * Runs at priority 998 on wp_before_admin_bar_render (after all plugins have
     * added their nodes, before our own removal at 999), on every page load for
     * allowed users. The transient is merged, not overwritten, so the list
     * grows incrementally as the user browses both admin and frontend.
     */
    public function cache_admin_bar_nodes(): void {
        if ( ! $this->is_allowed_user() ) {
            return;
        }

        global $wp_admin_bar;

        $all_nodes = $wp_admin_bar->get_nodes();
        if ( empty( $all_nodes ) ) {
            return;
        }

        // Collect IDs of group nodes whose parent is root: these are the
        // "sections" of the bar (e.g. top-secondary). Their children count
        // as top-level visually.
        $root_groups = [];
        foreach ( $all_nodes as $id => $node ) {
            if ( $node->group && ( empty( $node->parent ) || $node->parent === 'root' ) ) {
                $root_groups[] = $id;
            }
        }

        $context = is_admin() ? 'admin' : 'frontend';
        $cached  = get_transient( self::TRANSIENT_ADMIN_BAR ) ?: [];
        $updated = false;

        foreach ( $all_nodes as $id => $node ) {
            // Skip group nodes, the invisible root, and nodes with no title.
            if ( $node->group || $id === 'root' ) {
                continue;
            }

            $title = wp_strip_all_tags( $node->title ?? '' );
            if ( empty( $title ) ) {
                continue;
            }

            // Only top-level: no parent, parent is 'root', or parent is a root group.
            $parent       = $node->parent ?: '';
            $is_top_level = empty( $parent )
                || $parent === 'root'
                || in_array( $parent, $root_groups, true );

            if ( ! $is_top_level ) {
                continue;
            }

            if ( ! isset( $cached[ $id ] ) ) {
                $cached[ $id ] = [
                    'title'    => $title,
                    'admin'    => $context === 'admin',
                    'frontend' => $context === 'frontend',
                ];
                $updated = true;
            } elseif ( ! $cached[ $id ][ $context ] ) {
                // Node already known: just update the context flag.
                $cached[ $id ][ $context ] = true;
                $updated = true;
            }
        }

        if ( $updated ) {
            set_transient( self::TRANSIENT_ADMIN_BAR, $cached, DAY_IN_SECONDS );
        }
    }

    /**
     * Removes selected admin bar nodes for unauthorised users.
     * Runs on wp_before_admin_bar_render (both frontend and admin) at priority 999.
     */
    public function remove_admin_bar_nodes(): void {
        if ( $this->is_allowed_user() ) {
            return;
        }

        global $wp_admin_bar;

        foreach ( $this->get_hidden_admin_bar_nodes() as $node_id ) {
            $wp_admin_bar->remove_node( $node_id );
        }
    }
}

register_activation_hook( __FILE__, [ 'WP_Clean_Client_Dashboard', 'activate' ] );
WP_Clean_Client_Dashboard::init();
