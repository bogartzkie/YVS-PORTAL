<?php
/**
 * Settings, users, permissions, audit history and the website-form intake log.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/** Everything the portal needs at start-up (also printed into the page as JSON). */
function prr_bootstrap() {
    $me = wp_get_current_user();
    $privs = array();
    foreach ( prr_get_privilege_definitions() as $def ) $privs[ $def['key'] ] = prr_can( $def['key'] );
    $wa = prr_whatsapp_settings();
    $intake = prr_intake_settings();
    return array(
        'me' => array( 'id' => (int) $me->ID, 'name' => $me->display_name, 'email' => $me->user_email, 'role' => prr_user_role_label( $me ), 'role_slug' => prr_user_role_slug( $me ) ),
        'can' => $privs, 'sees_all' => prr_sees_all(),
        'general' => array_intersect_key( prr_get_general(), array_flip( array( 'company_name', 'currency', 'expiry_warn_days' ) ) ),
        'applicant_statuses' => prr_applicant_statuses(), 'lead_statuses' => prr_lead_statuses(), 'submission_statuses' => prr_submission_statuses(),
        'doc_types' => prr_doc_types(), 'email_templates' => prr_email_templates(), 'wa_templates' => prr_wa_templates(),
        'call_outcomes' => prr_call_outcomes(), 'applicant_sources' => prr_applicant_sources(), 'lead_sources' => prr_lead_sources(),
        'team' => prr_team(), 'whatsapp' => array( 'mode' => $wa['mode'], 'business_number' => $wa['business_number'], 'default_cc' => $wa['default_cc'] ),
        'today' => current_time( 'Y-m-d' ),
        'branding' => array_intersect_key( prr_get_branding(), array_flip( array( 'portal_title', 'portal_subtitle', 'logo_url' ) ) ),
        'intake_url' => rest_url( PRR_API_NS . '/intake/webhook' ),
    );
}

add_action( 'rest_api_init', function() {
    $ns = PRR_API_NS; $adm = prr_gate( 'manage_settings' );
    register_rest_route( $ns, '/bootstrap', array( 'methods' => 'GET', 'callback' => function() { return prr_ok( prr_bootstrap() ); }, 'permission_callback' => prr_gate() ) );
    register_rest_route( $ns, '/settings', array(
        array( 'methods' => 'GET',  'callback' => 'prr_api_settings_get',  'permission_callback' => $adm ),
        array( 'methods' => 'POST', 'callback' => 'prr_api_settings_save', 'permission_callback' => $adm ),
    ) );
    register_rest_route( $ns, '/settings/test-email', array( 'methods' => 'POST', 'callback' => 'prr_api_test_email', 'permission_callback' => $adm ) );
    register_rest_route( $ns, '/team', array(
        array( 'methods' => 'GET',  'callback' => function() { return prr_ok( prr_team() ); }, 'permission_callback' => prr_gate() ),
        array( 'methods' => 'POST', 'callback' => 'prr_api_team_add', 'permission_callback' => $adm ),
    ) );
    register_rest_route( $ns, '/team/(?P<id>\d+)', array(
        array( 'methods' => 'PATCH',  'callback' => 'prr_api_team_role',   'permission_callback' => $adm ),
        array( 'methods' => 'DELETE', 'callback' => 'prr_api_team_remove', 'permission_callback' => $adm ),
    ) );
    register_rest_route( $ns, '/audit', array( 'methods' => 'GET', 'callback' => 'prr_api_audit', 'permission_callback' => $adm ) );
    register_rest_route( $ns, '/intake-log', array( 'methods' => 'GET', 'callback' => 'prr_api_intake_log', 'permission_callback' => $adm ) );
    register_rest_route( $ns, '/intake-log/(?P<id>\d+)/retry', array( 'methods' => 'POST', 'callback' => 'prr_api_intake_retry', 'permission_callback' => $adm ) );
    register_rest_route( $ns, '/me', array( 'methods' => 'PATCH', 'callback' => 'prr_api_me_update', 'permission_callback' => prr_gate() ) );
} );

function prr_api_settings_get() {
    $wa = prr_whatsapp_settings();
    $wa['access_token'] = $wa['access_token'] ? '••••' . substr( $wa['access_token'], -4 ) : '';
    $wa['app_secret']   = $wa['app_secret'] ? '••••' . substr( $wa['app_secret'], -4 ) : '';
    $wa['webhook_url']  = rest_url( PRR_API_NS . '/whatsapp/webhook' );
    $privs = array();
    foreach ( prr_get_privilege_definitions() as $d ) $privs[] = array( 'key' => $d['key'], 'label' => $d['label'], 'group' => $d['group'] );
    return prr_ok( array(
        'general' => prr_get_general(), 'applicant_statuses' => prr_applicant_statuses(), 'lead_statuses' => prr_lead_statuses(),
        'submission_statuses' => prr_submission_statuses(), 'doc_types' => prr_doc_types(), 'email_templates' => prr_email_templates(),
        'wa_templates' => prr_wa_templates(), 'whatsapp' => $wa, 'intake' => prr_intake_settings() + array( 'webhook_url' => rest_url( PRR_API_NS . '/intake/webhook' ) ),
        'privilege_defs' => $privs, 'privileges' => prr_get_privileges(), 'roles' => prr_role_labels(),
        'private_dir_protected' => prr_private_dir_protected(),
        'branding' => prr_get_branding(), 'fonts' => array_map( 'array_keys', prr_font_choices() ),
        'portal_url' => home_url( '/' . PRR_PORTAL_SLUG . '/' ), 'site_icon' => get_site_icon_url( 64 ),
    ) );
}

/** Quick self-check: is the private folder blocked from direct web access? */
function prr_private_dir_protected() {
    $cached = get_transient( 'prr_private_probe' );
    if ( $cached !== false ) return $cached === 'unknown' ? null : $cached === 'yes';
    $res = prr_private_dir_probe();
    set_transient( 'prr_private_probe', $res === null ? 'unknown' : ( $res ? 'yes' : 'no' ), 12 * HOUR_IN_SECONDS );
    return $res;
}
function prr_private_dir_probe() {
    $dir = prr_ensure_private_dir();
    $probe = $dir . '/prr-probe.txt';
    if ( ! file_exists( $probe ) ) @file_put_contents( $probe, 'probe' );
    $up = wp_upload_dir( null, false );
    $res = wp_remote_get( trailingslashit( $up['baseurl'] ) . 'prr-private/prr-probe.txt', array( 'timeout' => 5, 'sslverify' => false ) );
    if ( is_wp_error( $res ) ) return null; // could not test (e.g. loopback blocked)
    return wp_remote_retrieve_response_code( $res ) !== 200;
}

/** Save one settings section: { section: 'general'|'lead_statuses'|…, value: {...} } */
function prr_api_settings_save( WP_REST_Request $r ) {
    $d = (array) $r->get_json_params();
    $section = sanitize_key( $d['section'] ?? '' ); $v = $d['value'] ?? null;
    $clean_list = function( $list, $extra_flags ) {
        $out = array();
        foreach ( (array) $list as $key => $item ) {
            $k = sanitize_key( $key ); if ( $k === '' || empty( $item['label'] ) ) continue;
            $row = array( 'label' => sanitize_text_field( $item['label'] ), 'color' => sanitize_hex_color( $item['color'] ?? '' ) ?: '#8a8fa8' );
            foreach ( $extra_flags as $f ) if ( ! empty( $item[ $f ] ) ) $row[ $f ] = is_array( $item[ $f ] ) ? array_map( 'sanitize_key', $item[ $f ] ) : ( in_array( $f, array( 'help', 'fields', 'subject', 'body', 'name', 'lang' ), true ) ? sanitize_textarea_field( $item[ $f ] ) : 1 );
            $out[ $k ] = $row;
        }
        return $out;
    };
    switch ( $section ) {
        case 'general':
            $g = prr_get_general();
            foreach ( $g as $k => $old ) if ( isset( $v[ $k ] ) ) $g[ $k ] = is_int( $old ) ? (int) $v[ $k ] : sanitize_text_field( $v[ $k ] );
            update_option( 'prr_general', $g ); break;
        case 'applicant_statuses':  update_option( 'prr_applicant_statuses',  $clean_list( $v, array() ) ); break;
        case 'submission_statuses': update_option( 'prr_submission_statuses', $clean_list( $v, array() ) ); break;
        case 'lead_statuses':       update_option( 'prr_lead_statuses',       $clean_list( $v, array( 'queue', 'no_call', 'terminal', 'help' ) ) ); break;
        case 'doc_types':           update_option( 'prr_doc_types',           $clean_list( $v, array( 'for', 'sensitive', 'fields' ) ) ); break;
        case 'email_templates':
        case 'wa_templates':
            $out = array();
            foreach ( (array) $v as $key => $t ) {
                $k = sanitize_key( $key ); if ( ! $k || empty( $t['label'] ) ) continue;
                $out[ $k ] = array( 'label' => sanitize_text_field( $t['label'] ), 'body' => sanitize_textarea_field( $t['body'] ?? '' ) );
                if ( isset( $t['subject'] ) ) $out[ $k ]['subject'] = sanitize_text_field( $t['subject'] );
                if ( isset( $t['name'] ) )    $out[ $k ]['name'] = sanitize_key( $t['name'] );
                if ( isset( $t['lang'] ) )    $out[ $k ]['lang'] = sanitize_text_field( $t['lang'] );
                if ( isset( $t['params'] ) )  $out[ $k ]['params'] = array_values( array_filter( array_map( 'sanitize_key', (array) $t['params'] ) ) );
            }
            update_option( 'prr_' . $section, $out ); break;
        case 'whatsapp':
            $s = prr_whatsapp_settings();
            foreach ( array( 'mode', 'phone_number_id', 'api_version', 'business_number', 'default_cc', 'verify_token' ) as $k ) if ( isset( $v[ $k ] ) ) $s[ $k ] = sanitize_text_field( $v[ $k ] );
            foreach ( array( 'access_token', 'app_secret' ) as $k ) if ( isset( $v[ $k ] ) && strpos( $v[ $k ], '••••' ) !== 0 ) $s[ $k ] = sanitize_text_field( $v[ $k ] );
            $s['mode'] = $s['mode'] === 'cloud' ? 'cloud' : 'click';
            update_option( 'prr_whatsapp', $s ); break;
        case 'intake':
            $s = prr_intake_settings();
            foreach ( array( 'cf7_applicant', 'cf7_employer' ) as $k ) if ( isset( $v[ $k ] ) ) $s[ $k ] = sanitize_text_field( $v[ $k ] );
            if ( isset( $v['send_ack'] ) ) $s['send_ack'] = $v['send_ack'] ? 1 : 0;
            if ( isset( $v['field_map'] ) ) { $s['field_map'] = array(); foreach ( (array) $v['field_map'] as $from => $to ) if ( $from !== '' ) $s['field_map'][ sanitize_text_field( $from ) ] = sanitize_key( $to ); }
            if ( ! empty( $v['regenerate_token'] ) ) $s['token'] = wp_generate_password( 32, false );
            update_option( 'prr_intake', $s ); break;
        case 'branding':
            prr_sanitize_branding( (array) $v ); break;
        case 'privileges':
            $out = array();
            foreach ( prr_get_privilege_definitions() as $def ) foreach ( array( 'prr_admin', 'prr_manager', 'prr_recruiter' ) as $role ) {
                $out[ $def['key'] ][ $role ] = ! empty( $v[ $def['key'] ][ $role ] );
            }
            update_option( 'prr_privileges', $out ); break;
        default:
            return prr_err( 'Unknown settings section.' );
    }
    prr_audit( 'settings_saved', '', 0, array( 'section' => $section ) );
    return prr_api_settings_get();
}

function prr_api_test_email() {
    $me = wp_get_current_user();
    $res = prr_send_email( '', 0, $me->user_email, 'Test email from the CRM', "This is a test email from the Prestige Recruitment CRM.\nIf you can read this, sending works.", 'test' );
    return is_wp_error( $res ) ? $res : prr_ok( array( 'sent' => true, 'to' => $me->user_email ) );
}

/** Add a staff member: creates a WP user (or upgrades an existing one) and emails a set-password link. */
function prr_api_team_add( WP_REST_Request $r ) {
    $d = (array) $r->get_json_params();
    $email = sanitize_email( $d['email'] ?? '' ); $name = sanitize_text_field( $d['name'] ?? '' );
    $role = sanitize_key( $d['role'] ?? 'prr_recruiter' );
    if ( ! isset( prr_role_labels()[ $role ] ) ) return prr_err( 'Unknown role.' );
    if ( ! is_email( $email ) ) return prr_err( 'Enter a valid email address.' );
    $user = get_user_by( 'email', $email );
    if ( $user ) {
        if ( in_array( 'administrator', (array) $user->roles, true ) ) return prr_err( 'This person is a site administrator and already has full access.' );
        $user->add_role( $role );
    } else {
        $login = sanitize_user( strstr( $email, '@', true ), true );
        if ( username_exists( $login ) ) $login .= wp_rand( 100, 999 );
        $uid = wp_insert_user( array( 'user_login' => $login, 'user_email' => $email, 'display_name' => $name ?: $login, 'first_name' => $name, 'user_pass' => wp_generate_password( 24 ), 'role' => $role ) );
        if ( is_wp_error( $uid ) ) return $uid;
        wp_new_user_notification( $uid, null, 'user' ); // emails a "set your password" link
        $user = get_user_by( 'id', $uid );
    }
    prr_audit( 'user_added', '', 0, array( 'user' => $user->ID, 'role' => $role ) );
    return prr_ok( prr_team() );
}
function prr_api_team_role( WP_REST_Request $r ) {
    $user = get_user_by( 'id', (int) $r['id'] );
    $role = sanitize_key( $r->get_json_params()['role'] ?? '' );
    if ( ! $user || ! isset( prr_role_labels()[ $role ] ) ) return prr_err( 'User or role not found.', 404 );
    if ( in_array( 'administrator', (array) $user->roles, true ) ) return prr_err( 'Site administrators always have full access.' );
    foreach ( array_keys( prr_role_labels() ) as $slug ) $user->remove_role( $slug );
    $user->add_role( $role );
    prr_audit( 'user_role_changed', '', 0, array( 'user' => $user->ID, 'role' => $role ) );
    return prr_ok( prr_team() );
}
function prr_api_team_remove( WP_REST_Request $r ) {
    $user = get_user_by( 'id', (int) $r['id'] );
    if ( ! $user ) return prr_err( 'User not found.', 404 );
    if ( $user->ID === get_current_user_id() ) return prr_err( 'You cannot remove your own access.' );
    if ( in_array( 'administrator', (array) $user->roles, true ) ) return prr_err( 'Site administrators cannot be removed here.' );
    foreach ( array_keys( prr_role_labels() ) as $slug ) $user->remove_role( $slug );
    if ( ! $user->roles ) $user->add_role( 'subscriber' );
    WP_Session_Tokens::get_instance( $user->ID )->destroy_all(); // sign them out everywhere
    prr_audit( 'user_access_removed', '', 0, array( 'user' => $user->ID ) );
    return prr_ok( prr_team() );
}

function prr_api_audit( WP_REST_Request $r ) {
    global $wpdb;
    $rows = $wpdb->get_results( 'SELECT * FROM ' . prr_table( 'audit' ) . ' ORDER BY id DESC LIMIT 200' );
    return prr_ok( array_map( function( $a ) { $u = prr_user_brief( $a->user_id );
        return array( 'id' => (int) $a->id, 'user' => $u ? $u['name'] : 'System', 'action' => $a->action, 'object_type' => $a->object_type,
                      'object_id' => $a->object_id ? (int) $a->object_id : null, 'details' => $a->details ? json_decode( $a->details, true ) : null, 'ip' => $a->ip, 'created_at' => $a->created_at ); }, $rows ) );
}

function prr_api_intake_log() {
    global $wpdb;
    $rows = $wpdb->get_results( 'SELECT id, form, source, payload, status, object_id, duplicate_of, error, created_at FROM ' . prr_table( 'intake_log' ) . ' ORDER BY id DESC LIMIT 100' );
    return prr_ok( array_map( function( $l ) {
        $p = json_decode( (string) $l->payload, true ) ?: array();
        $m = prr_intake_map( $l->form, $p );
        return array( 'id' => (int) $l->id, 'form' => $l->form, 'source' => $l->source, 'status' => $l->status, 'error' => $l->error,
            'object_id' => $l->object_id ? (int) $l->object_id : null, 'created_at' => $l->created_at,
            'name' => $m['full_name'] ?? ( $m['company'] ?? ( $m['contact_name'] ?? ( $m['phone'] ?? '' ) ) ) );
    }, $rows ) );
}
function prr_api_intake_retry( WP_REST_Request $r ) {
    global $wpdb;
    $log = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . prr_table( 'intake_log' ) . ' WHERE id = %d', (int) $r['id'] ) );
    if ( ! $log ) return prr_err( 'Log entry not found.', 404 );
    if ( $log->status !== 'failed' ) return prr_err( 'Only failed submissions can be retried.' );
    // Allow the admin to correct the data before retrying.
    $fix = (array) ( $r->get_json_params()['payload'] ?? array() );
    if ( $fix ) {
        $p = json_decode( (string) $log->payload, true ) ?: array();
        $wpdb->update( prr_table( 'intake_log' ), array( 'payload' => wp_json_encode( array_merge( $p, array_map( 'sanitize_text_field', $fix ) ) ) ), array( 'id' => $log->id ) );
    }
    $id = prr_intake_process( (int) $log->id );
    prr_audit( 'intake_retried', $log->form === 'employer' ? 'lead' : 'applicant', is_wp_error( $id ) ? 0 : $id, array( 'log' => (int) $log->id ) );
    return is_wp_error( $id ) ? $id : prr_ok( array( 'object_id' => $id, 'form' => $log->form ) );
}

function prr_api_me_update( WP_REST_Request $r ) {
    $d = (array) $r->get_json_params();
    $u = wp_get_current_user(); $f = array( 'ID' => $u->ID );
    if ( ! empty( $d['name'] ) ) $f['display_name'] = sanitize_text_field( $d['name'] );
    if ( ! empty( $d['password'] ) ) {
        if ( strlen( $d['password'] ) < 10 ) return prr_err( 'Use at least 10 characters for the password.' );
        $f['user_pass'] = $d['password'];
    }
    $res = wp_update_user( $f );
    return is_wp_error( $res ) ? $res : prr_ok( array( 'ok' => true ) );
}
