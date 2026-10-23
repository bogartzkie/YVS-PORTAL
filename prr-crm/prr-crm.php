<?php
/**
 * Plugin Name:  Prestige Recruitment CRM
 * Plugin URI:   https://patjoaragones.com
 * Description:  Recruitment CRM portal for Prestige Recruitment Royale: applicants, client leads, client companies, submissions, documents, email and WhatsApp.
 * Version:      0.1.0
 * Requires PHP: 7.4
 * Author:       Patjo Aragones
 * Author URI:   https://patjoaragones.com
 * Text Domain:  prr-crm
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PRR_VERSION',    '0.1.0' );
define( 'PRR_PATH',       plugin_dir_path( __FILE__ ) );
define( 'PRR_URL',        plugin_dir_url( __FILE__ ) );
define( 'PRR_API_NS',     'prr/v1' );
define( 'PRR_DB_VERSION', '1.0' ); // bump when the schema changes
define( 'PRR_PORTAL_SLUG', 'prr-portal' );

$prr_files = array(
    'settings',   // option getters, statuses, privileges
    'db',         // tables
    'roles',      // WP roles + capability helpers
    'security',   // headers, rate limit, audit log
    'helpers',    // shared formatting / response helpers
    'documents',  // private file storage + download
    'applicants',
    'leads',
    'clients',
    'submissions',
    'email',
    'whatsapp',
    'intake',     // website forms → CRM
    'reports',
    'admin-api',  // settings, team, audit, intake log
    'page',       // /prr-portal route, login, portal shell
);
foreach ( $prr_files as $f ) {
    $file = PRR_PATH . 'includes/' . $f . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    } else {
        error_log( "PRR CRM: missing file $file" );
    }
}

register_activation_hook( __FILE__, 'prr_activate' );
function prr_activate() {
    prr_create_tables();
    prr_register_roles();
    prr_portal_rewrite();
    prr_ensure_private_dir();
    update_option( 'prr_db_version', PRR_DB_VERSION );
    flush_rewrite_rules( false );
}

register_deactivation_hook( __FILE__, function() {
    wp_clear_scheduled_hook( 'prr_daily' );
    flush_rewrite_rules( false );
} );

// Data is kept on uninstall unless the admin turned on "Delete all data on uninstall".
register_uninstall_hook( __FILE__, 'prr_uninstall' );
function prr_uninstall() {
    $g = get_option( 'prr_general', array() );
    if ( empty( $g['delete_on_uninstall'] ) ) return;
    prr_drop_tables();
    prr_remove_roles();
    foreach ( prr_option_names() as $opt ) delete_option( $opt );
    delete_option( 'prr_db_version' );
}

// Upgrade the schema after a plugin update without needing re-activation.
add_action( 'init', function() {
    if ( get_option( 'prr_db_version' ) !== PRR_DB_VERSION ) {
        prr_create_tables();
        prr_register_roles();
        update_option( 'prr_db_version', PRR_DB_VERSION );
    }
}, 5 );

// Daily jobs: document expiry reminders, overdue follow-up digest, retention clean-up.
add_action( 'init', function() {
    if ( ! wp_next_scheduled( 'prr_daily' ) ) {
        wp_schedule_event( strtotime( 'tomorrow 01:00' ), 'daily', 'prr_daily' );
    }
} );

// The portal is logged-in and dynamic: never serve it from a page cache.
add_action( 'init', function() {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if ( strpos( $uri, '/' . PRR_PORTAL_SLUG ) !== false ) {
        if ( ! defined( 'DONOTCACHEPAGE' ) )   define( 'DONOTCACHEPAGE', true );
        if ( ! defined( 'DONOTCACHEOBJECT' ) ) define( 'DONOTCACHEOBJECT', true );
        if ( ! defined( 'DONOTCACHEDB' ) )     define( 'DONOTCACHEDB', true );
    }
}, 1 );

// Link to the portal from the Plugins screen.
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function( $links ) {
    array_unshift( $links, '<a href="' . esc_url( home_url( '/' . PRR_PORTAL_SLUG . '/' ) ) . '">Open portal</a>' );
    return $links;
} );
