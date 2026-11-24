<?php
/**
 * Removes the plugin options and cached lists when the plugin is deleted.
 *
 * @package WP_Clean_Client_Dashboard
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

foreach ( [
    'wpccd_allowed_users',
    'wpccd_hidden_selectors',
    'wpccd_hidden_menus',
    'wpccd_hidden_widgets',
    'wpccd_hidden_admin_bar_nodes',
    'wpccd_footer_text',
    'wpccd_protect_elementor',
] as $option ) {
    delete_option( $option );
}

delete_transient( 'wpccd_dashboard_widgets' );
delete_transient( 'wpccd_admin_bar_nodes' );
delete_site_option( 'external_updates-wp-clean-client-dashboard' );
wp_clear_scheduled_hook( 'puc_cron_check_updates-wp-clean-client-dashboard' );
