<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function prr_ok( $data = array() )          { return rest_ensure_response( $data ); }
function prr_err( $msg, $code = 400, $extra = array() ) {
    return new WP_Error( 'prr_error', $msg, array_merge( array( 'status' => $code ), $extra ) );
}

/** permission_callback factory: portal access + a privilege key. */
function prr_gate( $privilege = null ) {
    return function() use ( $privilege ) {
        if ( ! prr_can_access() ) return new WP_Error( 'prr_forbidden', 'Sign in to the portal first.', array( 'status' => 401 ) );
        if ( $privilege && ! prr_can( $privilege ) ) {
            return new WP_Error( 'prr_forbidden', 'Your role does not allow this. Ask an administrator.', array( 'status' => 403 ) );
        }
        return true;
    };
}

function prr_now() { return current_time( 'mysql' ); }

/** Digits only, with a default country code added to local numbers (0501234567 → 971501234567). */
function prr_norm_phone( $phone, $default_cc = null ) {
    $p = trim( (string) $phone );
    if ( $p === '' ) return '';
    $plus   = strpos( $p, '+' ) === 0 || strpos( $p, '00' ) === 0;
    $digits = preg_replace( '/\D/', '', $p );
    if ( strpos( $p, '00' ) === 0 ) $digits = substr( $digits, 2 );
    if ( ! $plus ) {
        if ( $default_cc === null ) $default_cc = prr_whatsapp_settings()['default_cc'];
        $default_cc = preg_replace( '/\D/', '', (string) $default_cc );
        if ( $default_cc && strpos( $digits, '0' ) === 0 ) $digits = $default_cc . ltrim( $digits, '0' );
        elseif ( $default_cc && strlen( $digits ) <= 10 && strpos( $digits, $default_cc ) !== 0 ) $digits = $default_cc . $digits;
    }
    return $digits;
}
function prr_phone_valid( $norm ) { return strlen( $norm ) >= 8 && strlen( $norm ) <= 15; }

/** Next human reference, e.g. PRR-26-00419 / LD-01047 / CL-0015. */
function prr_next_ref( $kind ) {
    global $wpdb;
    $g = prr_get_general();
    $map = array(
        'applicant' => array( 'applicants', $g['ref_prefix'] . '-' . gmdate( 'y' ) . '-', 5 ),
        'lead'      => array( 'leads', 'LD-', 5 ),
        'client'    => array( 'clients', 'CL-', 4 ),
    );
    list( $table, $prefix, $pad ) = $map[ $kind ];
    $max = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . prr_table( $table ) );
    return $prefix . str_pad( (string) ( $max + 1 ), $pad, '0', STR_PAD_LEFT );
}

function prr_user_brief( $id ) {
    if ( ! $id ) return null;
    static $cache = array();
    if ( ! isset( $cache[ $id ] ) ) {
        $u = get_user_by( 'id', (int) $id );
        $cache[ $id ] = $u ? array( 'id' => (int) $u->ID, 'name' => $u->display_name ) : null;
    }
    return $cache[ $id ];
}

function prr_status_info( $list, $key ) {
    return isset( $list[ $key ] ) ? array( 'key' => $key, 'label' => $list[ $key ]['label'], 'color' => $list[ $key ]['color'] )
                                  : array( 'key' => $key, 'label' => ucfirst( str_replace( '_', ' ', $key ) ), 'color' => '#8a8fa8' );
}

/* ── Timeline ────────────────────────────────────────────── */
function prr_log( $object_type, $object_id, $kind, $body, $meta = array(), $user_id = null ) {
    global $wpdb;
    $wpdb->insert( prr_table( 'activity' ), array(
        'object_type' => $object_type,
        'object_id'   => (int) $object_id,
        'user_id'     => $user_id !== null ? $user_id : ( get_current_user_id() ?: null ),
        'kind'        => $kind,
        'body'        => $body,
        'meta'        => $meta ? wp_json_encode( $meta ) : null,
        'created_at'  => prr_now(),
    ) );
    if ( $object_type === 'lead' ) {
        $wpdb->update( prr_table( 'leads' ), array( 'last_activity_at' => prr_now() ), array( 'id' => (int) $object_id ) );
    }
    return (int) $wpdb->insert_id;
}

function prr_get_timeline( $object_type, $object_id, $limit = 200 ) {
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare(
        'SELECT * FROM ' . prr_table( 'activity' ) . ' WHERE object_type = %s AND object_id = %d ORDER BY id DESC LIMIT %d',
        $object_type, $object_id, $limit ) );
    return array_map( 'prr_format_activity', $rows );
}
function prr_format_activity( $r ) {
    $u = prr_user_brief( $r->user_id );
    $meta = $r->meta ? json_decode( $r->meta, true ) : array();
    if ( ! empty( $meta['msg_id'] ) ) { // live delivery status for WhatsApp (sent → delivered → read)
        global $wpdb;
        $st = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . prr_table( 'messages' ) . ' WHERE id = %d', (int) $meta['msg_id'] ) );
        if ( $st ) $meta['status'] = $st;
    }
    return array(
        'id' => (int) $r->id, 'kind' => $r->kind, 'body' => $r->body, 'meta' => $meta,
        'by' => $u ? $u['name'] : ( $meta['by'] ?? 'System' ), 'user_id' => $r->user_id ? (int) $r->user_id : null,
        'created_at' => $r->created_at,
    );
}

/* ── Notifications (in-app + optional email) ─────────────── */
function prr_notify_user( $user_id, $kind, $title, $body = '', $object_type = '', $object_id = 0 ) {
    global $wpdb;
    $wpdb->insert( prr_table( 'notifications' ), array(
        'user_id' => (int) $user_id, 'kind' => $kind, 'title' => mb_substr( $title, 0, 200 ), 'body' => mb_substr( (string) $body, 0, 500 ),
        'object_type' => $object_type, 'object_id' => (int) $object_id ?: null, 'created_at' => prr_now(),
    ) );
}
/** Notify one owner if given, otherwise every portal user. */
function prr_notify_staff( $kind, $title, $body = '', $object_type = '', $object_id = 0, $owner_id = 0 ) {
    $ids = $owner_id ? array( (int) $owner_id ) : wp_list_pluck( prr_team(), 'id' );
    foreach ( $ids as $uid ) prr_notify_user( $uid, $kind, $title, $body, $object_type, $object_id );
}
function prr_owner_of( $object_type, $object_id ) {
    global $wpdb;
    if ( $object_type === 'applicant' ) return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT recruiter_id FROM ' . prr_table( 'applicants' ) . ' WHERE id=%d', $object_id ) );
    if ( $object_type === 'lead' )      return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT owner_id FROM ' . prr_table( 'leads' ) . ' WHERE id=%d', $object_id ) );
    return 0;
}

function prr_date_or_null( $v ) {
    $v = sanitize_text_field( (string) $v );
    return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : null;
}

/** Stream rows as a CSV download (used by the export endpoints). */
function prr_send_csv( $filename, $header, $rows ) {
    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
    $out = fopen( 'php://output', 'w' );
    fwrite( $out, "\xEF\xBB\xBF" ); // BOM so Excel opens UTF-8 correctly
    fputcsv( $out, $header );
    foreach ( $rows as $r ) {
        // Neutralise spreadsheet formula injection.
        $r = array_map( function( $v ) { $v = (string) $v; return preg_match( '/^[=+\-@]/', $v ) ? "'" . $v : $v; }, $r );
        fputcsv( $out, $r );
    }
    fclose( $out );
    exit;
}

/** Fill {placeholders} in a template string. */
function prr_fill( $text, $vars ) {
    return preg_replace_callback( '/\{(\w+)\}/', function( $m ) use ( $vars ) {
        return array_key_exists( $m[1], $vars ) ? (string) $vars[ $m[1] ] : $m[0];
    }, (string) $text );
}
