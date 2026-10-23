<?php
/**
 * Security: portal headers, rate limiting, login lockout, audit history, retention.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'template_redirect', function() {
    if ( ! get_query_var( 'prr_portal' ) ) return;
    header( 'X-Content-Type-Options: nosniff' );
    header( 'X-Frame-Options: SAMEORIGIN' );
    header( 'Referrer-Policy: strict-origin-when-cross-origin' );
    header( 'Permissions-Policy: camera=(), microphone=(), geolocation=()' );
    header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
    if ( is_ssl() ) header( 'Strict-Transport-Security: max-age=31536000' );
}, 0 );

/** Simple per-IP limiter backed by transients. Returns true or WP_Error. */
function prr_rate_limit( $action, $limit = 60, $window = 60 ) {
    $ip   = prr_client_ip();
    $key  = 'prr_rl_' . $action . '_' . md5( $ip );
    $hits = (int) get_transient( $key );
    if ( $hits >= $limit ) {
        return new WP_Error( 'prr_rate_limited', 'Too many requests. Wait a minute and try again.', array( 'status' => 429 ) );
    }
    set_transient( $key, $hits + 1, $window );
    return true;
}

function prr_client_ip() {
    return sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
}

// Throttle our REST namespace (generous for staff; public intake has its own stricter limit).
add_filter( 'rest_pre_dispatch', function( $result, $server, $request ) {
    if ( strpos( $request->get_route(), '/' . PRR_API_NS . '/' ) !== 0 ) return $result;
    $ok = prr_rate_limit( 'api', 300, 60 );
    return is_wp_error( $ok ) ? $ok : $result;
}, 5, 3 );

/* ── Login lockout: 5 failures in 15 minutes locks the IP for 15 minutes ── */
function prr_login_locked() {
    return (int) get_transient( 'prr_login_fail_' . md5( prr_client_ip() ) ) >= 5;
}
add_action( 'wp_login_failed', function( $username ) {
    $key = 'prr_login_fail_' . md5( prr_client_ip() );
    set_transient( $key, (int) get_transient( $key ) + 1, 15 * MINUTE_IN_SECONDS );
    prr_audit( 'login_failed', '', 0, array( 'username' => sanitize_user( $username ) ) );
} );
add_action( 'wp_login', function( $login, $user ) {
    delete_transient( 'prr_login_fail_' . md5( prr_client_ip() ) );
    if ( prr_user_can_access( $user ) ) prr_audit( 'login', '', 0, array(), $user->ID );
}, 10, 2 );

/* ── Audit history ───────────────────────────────────────── */
function prr_audit( $action, $object_type = '', $object_id = 0, $details = array(), $user_id = null ) {
    global $wpdb;
    $wpdb->insert( prr_table( 'audit' ), array(
        'user_id'     => $user_id !== null ? (int) $user_id : get_current_user_id(),
        'action'      => sanitize_key( $action ),
        'object_type' => sanitize_key( $object_type ),
        'object_id'   => (int) $object_id ?: null,
        'details'     => $details ? wp_json_encode( $details ) : null,
        'ip'          => prr_client_ip(),
        'created_at'  => current_time( 'mysql' ),
    ) );
}

/* ── Daily job ───────────────────────────────────────────── */
add_action( 'prr_daily', 'prr_run_daily' );
function prr_run_daily() {
    global $wpdb;
    $g = prr_get_general();

    // 1. Document expiry reminders (passport, visa, contracts) — once per document.
    $days  = max( 1, (int) $g['expiry_warn_days'] );
    $limit = gmdate( 'Y-m-d', strtotime( "+$days days" ) );
    $docs  = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM " . prr_table( 'documents' ) . " WHERE (expiry_date IS NOT NULL AND expiry_date <= %s AND expiry_date >= CURDATE())
         OR (valid_until IS NOT NULL AND valid_until <= %s AND valid_until >= CURDATE())", $limit, $limit ) );
    foreach ( $docs as $d ) {
        $flag = 'prr_expiry_warned_' . $d->id;
        if ( get_option( $flag ) ) continue;
        $types = prr_doc_types();
        $label = $types[ $d->doc_type ]['label'] ?? 'Document';
        $date  = $d->expiry_date ?: $d->valid_until;
        prr_notify_staff( 'expiry', "$label expires on " . mysql2date( 'j M Y', $date ), $d->file_name, $d->object_type, $d->object_id,
            prr_owner_of( $d->object_type, $d->object_id ) );
        update_option( $flag, 1, false );
    }

    // 2. Retention: anonymise applicants with no activity for N months (0 = off).
    $months = (int) $g['retention_months'];
    if ( $months > 0 ) {
        $cut = gmdate( 'Y-m-d H:i:s', strtotime( "-$months months" ) );
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM " . prr_table( 'applicants' ) . " WHERE anonymised_at IS NULL AND status IN ('rejected','unresponsive','archived') AND updated_at < %s LIMIT 200", $cut ) );
        foreach ( $ids as $id ) prr_anonymise_applicant( (int) $id, 0 );
        if ( $ids ) prr_audit( 'retention_anonymised', 'applicant', 0, array( 'count' => count( $ids ) ), 0 );
    }

    // 3. Clean old notifications.
    $wpdb->query( "DELETE FROM " . prr_table( 'notifications' ) . " WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)" );
}
