<?php
/**
 * Client leads: employers from the Excel master sheet and the website Clients page.
 * Staff call, message and comment on each lead until it becomes a client.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function prr_lead_sources() {
    return apply_filters( 'prr_lead_sources', array(
        'import' => 'Excel import', 'website' => 'Website (Clients page)', 'manual' => 'Manual entry',
        'referral' => 'Referral', 'whatsapp' => 'WhatsApp inbound', 'other' => 'Other',
    ) );
}

/** Call outcome → lead status. */
function prr_call_outcomes() {
    return apply_filters( 'prr_call_outcomes', array(
        'no_answer'      => array( 'label' => 'No answer',                  'status' => 'not_answering' ),
        'general'        => array( 'label' => 'Spoke, general enquiry',     'status' => 'contacted' ),
        'hot'            => array( 'label' => 'Spoke, hot lead',            'status' => 'hot' ),
        'call_back'      => array( 'label' => 'Spoke, call back later',     'status' => 'call_back' ),
        'whatsapp_only'  => array( 'label' => 'Asked for WhatsApp only',    'status' => 'whatsapp_only' ),
        'not_interested' => array( 'label' => 'Spoke, not interested',      'status' => 'not_interested' ),
        'junk'           => array( 'label' => 'Junk: wrong number or spam', 'status' => 'junk' ),
    ) );
}

function prr_get_lead_row( $id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . prr_table( 'leads' ) . ' WHERE id = %d', $id ) );
}
function prr_lead_visible( $row ) {
    return $row && ( prr_sees_all() || (int) $row->owner_id === get_current_user_id() );
}

function prr_format_lead( $r, $full = false ) {
    if ( ! $r ) return null;
    global $wpdb;
    $statuses = prr_lead_statuses();
    $sources  = prr_lead_sources();
    $out = array(
        'id' => (int) $r->id, 'ref' => $r->ref, 'contact_name' => $r->contact_name, 'company' => $r->company,
        'phone' => $r->phone, 'phone_norm' => $r->phone_norm, 'email' => $r->email, 'country' => $r->country,
        'source' => $r->source, 'source_label' => $sources[ $r->source ] ?? $r->source,
        'status' => prr_status_info( $statuses, $r->status ) + array( 'no_call' => ! empty( $statuses[ $r->status ]['no_call'] ), 'help' => $statuses[ $r->status ]['help'] ?? '' ),
        'owner' => prr_user_brief( $r->owner_id ), 'next_follow_up' => $r->next_follow_up,
        'needs' => $r->needs, 'headcount' => $r->headcount !== null ? (int) $r->headcount : null,
        'client_id' => $r->client_id ? (int) $r->client_id : null,
        'last_activity_at' => $r->last_activity_at, 'created_at' => $r->created_at,
    );
    $last = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . prr_table( 'activity' ) . " WHERE object_type = 'lead' AND object_id = %d ORDER BY id DESC LIMIT 1", $r->id ) );
    $out['last'] = $last ? prr_format_activity( $last ) : null;
    if ( $full ) {
        $out['timeline']  = prr_get_timeline( 'lead', $r->id );
        $out['documents'] = prr_list_documents( 'lead', $r->id );
        $out['messages']  = prr_list_messages( 'lead', $r->id );
        $picks = $wpdb->get_results( $wpdb->prepare(
            'SELECT a.* FROM ' . prr_table( 'lead_picks' ) . ' p JOIN ' . prr_table( 'applicants' ) . ' a ON a.id = p.applicant_id WHERE p.lead_id = %d ORDER BY p.created_at', $r->id ) );
        $out['picks'] = array_map( 'prr_format_applicant', $picks );
        if ( $r->client_id ) {
            $c = $wpdb->get_row( $wpdb->prepare( 'SELECT id, ref, company_name FROM ' . prr_table( 'clients' ) . ' WHERE id = %d', $r->client_id ) );
            $out['client'] = $c ? array( 'id' => (int) $c->id, 'ref' => $c->ref, 'name' => $c->company_name ) : null;
        }
    }
    return $out;
}

add_action( 'rest_api_init', function() {
    $ns = PRR_API_NS;
    register_rest_route( $ns, '/leads', array(
        array( 'methods' => 'GET',  'callback' => 'prr_api_leads_list',  'permission_callback' => prr_gate( 'view_leads' ) ),
        array( 'methods' => 'POST', 'callback' => 'prr_api_lead_create', 'permission_callback' => prr_gate( 'manage_leads' ) ),
    ) );
    register_rest_route( $ns, '/leads/import', array( 'methods' => 'POST', 'callback' => 'prr_api_leads_import', 'permission_callback' => prr_gate( 'import_data' ) ) );
    register_rest_route( $ns, '/leads/queue',  array( 'methods' => 'GET',  'callback' => 'prr_api_leads_queue',  'permission_callback' => prr_gate( 'manage_leads' ) ) );
    register_rest_route( $ns, '/leads/(?P<id>\d+)', array(
        array( 'methods' => 'GET',    'callback' => 'prr_api_lead_get',    'permission_callback' => prr_gate( 'view_leads' ) ),
        array( 'methods' => 'PATCH',  'callback' => 'prr_api_lead_update', 'permission_callback' => prr_gate( 'manage_leads' ) ),
        array( 'methods' => 'DELETE', 'callback' => 'prr_api_lead_delete', 'permission_callback' => prr_gate( 'delete_leads' ) ),
    ) );
    register_rest_route( $ns, '/leads/(?P<id>\d+)/activity', array( 'methods' => 'POST', 'callback' => 'prr_api_lead_activity', 'permission_callback' => prr_gate( 'manage_leads' ) ) );
    register_rest_route( $ns, '/leads/(?P<id>\d+)/picks', array( 'methods' => 'POST', 'callback' => 'prr_api_lead_pick_add', 'permission_callback' => prr_gate( 'manage_leads' ) ) );
    register_rest_route( $ns, '/leads/(?P<id>\d+)/picks/(?P<aid>\d+)', array( 'methods' => 'DELETE', 'callback' => 'prr_api_lead_pick_remove', 'permission_callback' => prr_gate( 'manage_leads' ) ) );
    register_rest_route( $ns, '/leads/(?P<id>\d+)/convert', array( 'methods' => 'POST', 'callback' => 'prr_api_lead_convert', 'permission_callback' => prr_gate( 'manage_clients' ) ) );
} );

function prr_leads_where( WP_REST_Request $r, &$args ) {
    global $wpdb;
    $where = array( '1=1' );
    $q = sanitize_text_field( (string) $r['q'] );
    if ( $q !== '' ) {
        $like = '%' . $wpdb->esc_like( $q ) . '%';
        $digits = preg_replace( '/\D/', '', $q );
        $w = '(contact_name LIKE %s OR company LIKE %s OR email LIKE %s OR ref LIKE %s OR country LIKE %s OR needs LIKE %s';
        array_push( $args, $like, $like, $like, $like, $like, $like );
        if ( strlen( $digits ) >= 4 ) { $w .= ' OR phone_norm LIKE %s'; $args[] = '%' . $digits . '%'; }
        $w .= ' OR id IN (SELECT object_id FROM ' . prr_table( 'activity' ) . " WHERE object_type = 'lead' AND body LIKE %s))";
        $args[] = $like;
        $where[] = $w;
    }
    foreach ( array( 'status', 'source', 'country' ) as $col ) {
        $v = sanitize_text_field( (string) $r[ $col ] );
        if ( $v !== '' ) { $where[] = "$col = %s"; $args[] = $v; }
    }
    if ( $r['owner'] )         { $where[] = 'owner_id = %d'; $args[] = (int) $r['owner']; }
    if ( $r['unnamed'] )       { $where[] = "contact_name = ''"; }
    if ( $r['due'] )           { $where[] = 'next_follow_up IS NOT NULL AND next_follow_up <= %s'; $args[] = current_time( 'Y-m-d' ); }
    if ( ! prr_sees_all() )    { $where[] = 'owner_id = %d'; $args[] = get_current_user_id(); }
    return 'WHERE ' . implode( ' AND ', $where );
}

function prr_api_leads_list( WP_REST_Request $r ) {
    global $wpdb;
    $t = prr_table( 'leads' ); $args = array();
    $sqlw = prr_leads_where( $r, $args );
    $sortable = array( 'created_at', 'contact_name', 'company', 'status', 'next_follow_up', 'last_activity_at', 'country' );
    $sort  = in_array( $r['sort'], $sortable, true ) ? $r['sort'] : 'last_activity_at';
    $order = strtolower( (string) $r['order'] ) === 'asc' ? 'ASC' : 'DESC';
    $per   = min( 200, max( 1, (int) ( $r['per_page'] ?: 50 ) ) );
    $page  = max( 1, (int) $r['page'] );
    $total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t $sqlw", $args ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM $t $sqlw" ) );
    $sql   = "SELECT * FROM $t $sqlw ORDER BY ($sort IS NULL), $sort $order, id DESC LIMIT $per OFFSET " . ( ( $page - 1 ) * $per );
    $rows  = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql );

    // Summary tiles for the leads page.
    $mine  = prr_sees_all() ? '' : $wpdb->prepare( ' AND owner_id = %d', get_current_user_id() );
    $today = current_time( 'Y-m-d' );
    $counts = $wpdb->get_results( "SELECT status, COUNT(*) n FROM $t WHERE 1=1 $mine GROUP BY status", OBJECT_K );
    $summary = array(
        'total'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE 1=1 $mine" ),
        'unnamed'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE contact_name = '' $mine" ),
        'website'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE source = 'website' $mine" ),
        'due'        => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE next_follow_up IS NOT NULL AND next_follow_up <= %s AND status NOT IN ('converted','not_interested','junk') $mine", $today ) ),
        'by_status'  => array_map( function( $o ) { return (int) $o->n; }, $counts ),
        'queue'      => count( prr_lead_queue_ids() ),
    );
    return prr_ok( array( 'items' => array_map( 'prr_format_lead', $rows ), 'total' => $total, 'page' => $page, 'per_page' => $per, 'summary' => $summary ) );
}

function prr_find_lead_by_phone( $norm, $exclude = 0 ) {
    global $wpdb;
    if ( $norm === '' ) return null;
    return $wpdb->get_row( $wpdb->prepare( 'SELECT id, ref, contact_name, company FROM ' . prr_table( 'leads' ) . ' WHERE phone_norm = %s AND id <> %d LIMIT 1', $norm, $exclude ) );
}

/** Shared by API, import, website form and inbound WhatsApp. Returns ID or WP_Error. */
function prr_insert_lead( $d, $source = 'manual', $log = '' ) {
    global $wpdb;
    $phone = sanitize_text_field( $d['phone'] ?? '' );
    $norm  = prr_norm_phone( $phone, $d['default_cc'] ?? null );
    $email = sanitize_email( $d['email'] ?? '' );
    if ( $norm === '' && $email === '' ) return prr_err( 'A phone number or email is required.' );
    if ( $norm !== '' && ! prr_phone_valid( $norm ) ) return prr_err( 'The phone number looks too short or too long.' );
    if ( $norm !== '' && ( $dup = prr_find_lead_by_phone( $norm ) ) ) return prr_err( "This number is already lead {$dup->ref}.", 409, array( 'lead_id' => (int) $dup->id ) );
    $status = sanitize_key( $d['status'] ?? 'new' );
    if ( ! isset( prr_lead_statuses()[ $status ] ) ) $status = 'new';
    $wpdb->insert( prr_table( 'leads' ), array(
        'ref' => '', 'contact_name' => sanitize_text_field( $d['contact_name'] ?? '' ), 'company' => sanitize_text_field( $d['company'] ?? '' ),
        'phone' => $norm ? '+' . $norm : '', 'phone_norm' => $norm, 'email' => $email, 'country' => sanitize_text_field( $d['country'] ?? '' ),
        'source' => sanitize_key( $d['source'] ?? $source ) ?: $source, 'status' => $status,
        'owner_id' => ! empty( $d['owner_id'] ) ? (int) $d['owner_id'] : null,
        'next_follow_up' => prr_date_or_null( $d['next_follow_up'] ?? '' ),
        'needs' => sanitize_textarea_field( $d['needs'] ?? '' ), 'headcount' => isset( $d['headcount'] ) && $d['headcount'] !== '' ? (int) $d['headcount'] : null,
        'created_by' => get_current_user_id() ?: null, 'created_at' => prr_now(), 'updated_at' => prr_now(), 'last_activity_at' => prr_now(),
    ) );
    $id = (int) $wpdb->insert_id;
    if ( ! $id ) return prr_err( 'Could not save the lead: ' . $wpdb->last_error, 500 );
    $wpdb->update( prr_table( 'leads' ), array( 'ref' => 'LD-' . str_pad( (string) $id, 5, '0', STR_PAD_LEFT ) ), array( 'id' => $id ) );
    $sources = prr_lead_sources();
    prr_log( 'lead', $id, 'system', $log ?: 'Lead added (' . ( $sources[ $source ] ?? $source ) . ').' . ( empty( $d['contact_name'] ) ? ' Number only.' : '' ) );
    if ( ! empty( $d['first_comment'] ) ) prr_log( 'lead', $id, 'comment', sanitize_textarea_field( $d['first_comment'] ), array( 'by' => 'Imported remark' ), 0 );
    return $id;
}

function prr_api_lead_create( WP_REST_Request $r ) {
    $d = (array) $r->get_json_params();
    if ( empty( $d['owner_id'] ) ) $d['owner_id'] = get_current_user_id();
    $id = prr_insert_lead( $d, sanitize_key( $d['source'] ?? 'manual' ) ?: 'manual' );
    if ( is_wp_error( $id ) ) return $id;
    if ( ! empty( $d['comment'] ) ) prr_log( 'lead', $id, 'comment', sanitize_textarea_field( $d['comment'] ) );
    prr_audit( 'lead_created', 'lead', $id );
    return prr_ok( prr_format_lead( prr_get_lead_row( $id ), true ) );
}

function prr_api_lead_get( WP_REST_Request $r ) {
    $row = prr_get_lead_row( (int) $r['id'] );
    if ( ! prr_lead_visible( $row ) ) return prr_err( 'Lead not found.', 404 );
    return prr_ok( prr_format_lead( $row, true ) );
}

/** Apply edits and log each change on the lead's timeline. */
function prr_lead_apply( $row, $d ) {
    global $wpdb;
    $f = array(); $labels = array( 'contact_name' => 'Contact name', 'company' => 'Company', 'email' => 'Email', 'country' => 'Country' );
    foreach ( $labels as $k => $label ) {
        if ( ! array_key_exists( $k, $d ) ) continue;
        $v = $k === 'email' ? sanitize_email( $d[ $k ] ) : sanitize_text_field( $d[ $k ] );
        if ( $v !== $row->$k ) { $f[ $k ] = $v; prr_log( 'lead', $row->id, 'system', $row->$k ? "$label changed from {$row->$k} to " . ( $v ?: '(blank)' ) . '.' : "$label added: $v." ); }
    }
    if ( array_key_exists( 'phone', $d ) ) {
        $norm = prr_norm_phone( $d['phone'] );
        if ( $norm !== $row->phone_norm ) {
            if ( $norm && ! prr_phone_valid( $norm ) ) return prr_err( 'The phone number looks too short or too long.' );
            if ( $norm && ( $dup = prr_find_lead_by_phone( $norm, $row->id ) ) ) return prr_err( "This number is already lead {$dup->ref}." );
            $f['phone'] = $norm ? '+' . $norm : ''; $f['phone_norm'] = $norm;
            prr_log( 'lead', $row->id, 'system', 'Phone changed to ' . $f['phone'] . '.' );
        }
    }
    if ( array_key_exists( 'needs', $d ) && $d['needs'] !== $row->needs ) {
        $f['needs'] = sanitize_textarea_field( $d['needs'] );
        prr_log( 'lead', $row->id, 'comment', "Needs updated:\n" . $f['needs'] );
    }
    if ( array_key_exists( 'headcount', $d ) ) $f['headcount'] = $d['headcount'] === '' || $d['headcount'] === null ? null : (int) $d['headcount'];
    if ( array_key_exists( 'next_follow_up', $d ) ) {
        $nf = prr_date_or_null( $d['next_follow_up'] );
        if ( $nf !== $row->next_follow_up ) { $f['next_follow_up'] = $nf; prr_log( 'lead', $row->id, 'system', $nf ? 'Next follow-up set for ' . mysql2date( 'j M Y', $nf ) . '.' : 'Follow-up date cleared.' ); }
    }
    if ( array_key_exists( 'owner_id', $d ) && (int) $d['owner_id'] !== (int) $row->owner_id ) {
        $f['owner_id'] = (int) $d['owner_id'] ?: null;
        $u = prr_user_brief( $f['owner_id'] );
        prr_log( 'lead', $row->id, 'system', 'Assigned to ' . ( $u ? $u['name'] : 'nobody' ) . '.' );
        if ( $f['owner_id'] && $f['owner_id'] !== get_current_user_id() ) prr_notify_user( $f['owner_id'], 'assigned', 'Lead assigned to you', ( $row->company ?: $row->phone ) . ' · ' . $row->ref, 'lead', $row->id );
    }
    if ( array_key_exists( 'status', $d ) ) {
        $s = sanitize_key( $d['status'] ); $list = prr_lead_statuses();
        if ( ! isset( $list[ $s ] ) ) return prr_err( 'Unknown status.' );
        if ( $s !== $row->status ) {
            $f['status'] = $s;
            if ( empty( $d['_silent_status'] ) ) prr_log( 'lead', $row->id, 'status', sprintf( 'Status changed: %s → %s', prr_status_info( $list, $row->status )['label'], $list[ $s ]['label'] ), array( 'from' => $row->status, 'to' => $s ) );
        }
    }
    // Saving needs moves an early-stage lead to "Needs received".
    if ( ! empty( $f['needs'] ) && ! isset( $f['status'] ) && in_array( $row->status, array( 'new', 'contacted', 'hot', 'whatsapp_only', 'call_back', 'not_answering' ), true ) ) {
        $f['status'] = 'needs_received';
        prr_log( 'lead', $row->id, 'status', 'Status changed: ' . prr_status_info( prr_lead_statuses(), $row->status )['label'] . ' → Needs received', array( 'from' => $row->status, 'to' => 'needs_received' ) );
    }
    if ( $f ) { $f['updated_at'] = prr_now(); $wpdb->update( prr_table( 'leads' ), $f, array( 'id' => $row->id ) ); }
    return true;
}

function prr_api_lead_update( WP_REST_Request $r ) {
    $row = prr_get_lead_row( (int) $r['id'] );
    if ( ! prr_lead_visible( $row ) ) return prr_err( 'Lead not found.', 404 );
    $res = prr_lead_apply( $row, (array) $r->get_json_params() );
    if ( is_wp_error( $res ) ) return $res;
    return prr_ok( prr_format_lead( prr_get_lead_row( $row->id ), true ) );
}

function prr_api_lead_delete( WP_REST_Request $r ) {
    global $wpdb;
    $id = (int) $r['id'];
    $row = prr_get_lead_row( $id );
    if ( ! $row ) return prr_err( 'Lead not found.', 404 );
    foreach ( prr_list_documents( 'lead', $id, false ) as $doc ) prr_delete_document( $doc['id'], false );
    $wpdb->delete( prr_table( 'activity' ), array( 'object_type' => 'lead', 'object_id' => $id ) );
    $wpdb->delete( prr_table( 'lead_picks' ), array( 'lead_id' => $id ) );
    $wpdb->delete( prr_table( 'leads' ), array( 'id' => $id ) );
    prr_audit( 'lead_deleted', 'lead', $id, array( 'ref' => $row->ref, 'phone' => $row->phone ) );
    return prr_ok( array( 'deleted' => true ) );
}

/**
 * Comment or call log. Body: { kind: comment|call, body, outcome?, contact_name?, company?, next_follow_up? }
 * A call outcome also sets the lead status.
 */
function prr_api_lead_activity( WP_REST_Request $r ) {
    $row = prr_get_lead_row( (int) $r['id'] );
    if ( ! prr_lead_visible( $row ) ) return prr_err( 'Lead not found.', 404 );
    $d = (array) $r->get_json_params();
    $kind = ( $d['kind'] ?? '' ) === 'call' ? 'call' : 'comment';
    $body = sanitize_textarea_field( $d['body'] ?? '' );
    $edits = array_intersect_key( $d, array_flip( array( 'contact_name', 'company', 'next_follow_up' ) ) );
    $edits = array_filter( $edits, function( $v ) { return $v !== '' && $v !== null; } );
    if ( $kind === 'call' ) {
        $outcomes = prr_call_outcomes();
        $o = sanitize_key( $d['outcome'] ?? '' );
        if ( ! isset( $outcomes[ $o ] ) ) return prr_err( 'Pick a call outcome first.' );
        prr_log( 'lead', $row->id, 'call', $outcomes[ $o ]['label'] . '.' . ( $body ? ' ' . $body : '' ), array( 'outcome' => $o ) );
        $edits['status'] = $outcomes[ $o ]['status'];
        $edits['_silent_status'] = true; // the call entry already says what happened
        if ( array_key_exists( 'next_follow_up', $d ) ) $edits['next_follow_up'] = $d['next_follow_up'];
    } else {
        if ( $body === '' ) return prr_err( 'Write a comment first.' );
        prr_log( 'lead', $row->id, 'comment', $body );
    }
    $res = prr_lead_apply( $row, $edits );
    if ( is_wp_error( $res ) ) return $res;
    return prr_ok( prr_format_lead( prr_get_lead_row( $row->id ), true ) );
}

/** Leads to call now: queue statuses, due or no follow-up date, oldest contact first. */
function prr_lead_queue_ids( $owner = null ) {
    global $wpdb;
    $keys = array();
    foreach ( prr_lead_statuses() as $k => $s ) if ( ! empty( $s['queue'] ) ) $keys[] = $k;
    if ( ! $keys ) return array();
    $in = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
    $args = $keys; $args[] = current_time( 'Y-m-d' );
    $own = '';
    if ( $owner ) { $own = ' AND owner_id = %d'; $args[] = (int) $owner; }
    elseif ( ! prr_sees_all() ) { $own = ' AND owner_id = %d'; $args[] = get_current_user_id(); }
    return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
        'SELECT id FROM ' . prr_table( 'leads' ) . " WHERE status IN ($in) AND (next_follow_up IS NULL OR next_follow_up <= %s) $own
         ORDER BY FIELD(status,'call_back','not_answering','new'), last_activity_at ASC LIMIT 500", $args ) ) );
}
function prr_api_leads_queue( WP_REST_Request $r ) {
    $owner = $r['mine'] ? get_current_user_id() : ( $r['owner'] ? (int) $r['owner'] : null );
    return prr_ok( array( 'ids' => prr_lead_queue_ids( $owner ) ) );
}

/** Rows from the browser (SheetJS), up to 500 per request. */
function prr_api_leads_import( WP_REST_Request $r ) {
    $d = (array) $r->get_json_params();
    $rows = array_slice( (array) ( $d['rows'] ?? array() ), 0, 500 );
    $assign = $d['assign'] ?? 'split';
    $cc = preg_replace( '/\D/', '', (string) ( $d['default_cc'] ?? '' ) ) ?: null;
    $team = array_values( array_filter( prr_team(), function( $u ) { return $u['role_slug'] !== 'prr_manager'; } ) );
    $res = array( 'created' => 0, 'duplicates' => 0, 'invalid' => 0, 'errors' => array() );
    $offset = (int) ( $d['offset'] ?? 0 );
    foreach ( $rows as $n => $row ) {
        $row = (array) $row; $line = $offset + $n + 2;
        $norm = prr_norm_phone( $row['phone'] ?? '', $cc );
        if ( $norm === '' && empty( $row['email'] ) ) { $res['invalid']++; $res['errors'][] = array( 'row' => $line, 'error' => 'No phone or email' ); continue; }
        if ( $norm !== '' && ! prr_phone_valid( $norm ) ) { $res['invalid']++; $res['errors'][] = array( 'row' => $line, 'error' => 'Invalid number ' . ( $row['phone'] ?? '' ) ); continue; }
        if ( $norm !== '' && ( $dup = prr_find_lead_by_phone( $norm ) ) ) {
            $res['duplicates']++;
            if ( ( $d['on_duplicate'] ?? 'comment' ) === 'comment' && ! empty( $row['first_comment'] ) ) {
                prr_log( 'lead', $dup->id, 'comment', sanitize_textarea_field( $row['first_comment'] ), array( 'by' => 'Imported remark' ), 0 );
            }
            continue;
        }
        if ( $assign === 'split' && $team ) $row['owner_id'] = $team[ ( $offset + $n ) % count( $team ) ]['id'];
        elseif ( is_numeric( $assign ) )    $row['owner_id'] = (int) $assign;
        elseif ( $assign === 'me' )          $row['owner_id'] = get_current_user_id();
        $row['default_cc'] = $cc;
        $id = prr_insert_lead( $row, 'import', 'Imported from ' . sanitize_text_field( $d['file_name'] ?? 'spreadsheet' ) . ', row ' . $line . '.' . ( empty( $row['contact_name'] ) ? ' Number only.' : '' ) );
        if ( is_wp_error( $id ) ) { $res['errors'][] = array( 'row' => $line, 'error' => $id->get_error_message() ); continue; }
        $res['created']++;
    }
    prr_audit( 'leads_imported', 'lead', 0, array( 'created' => $res['created'], 'duplicates' => $res['duplicates'], 'invalid' => $res['invalid'] ) );
    return prr_ok( $res );
}

function prr_api_lead_pick_add( WP_REST_Request $r ) {
    global $wpdb;
    $row = prr_get_lead_row( (int) $r['id'] );
    $aid = (int) ( $r->get_json_params()['applicant_id'] ?? 0 );
    $a = prr_get_applicant_row( $aid );
    if ( ! prr_lead_visible( $row ) || ! $a ) return prr_err( 'Lead or applicant not found.', 404 );
    $wpdb->replace( prr_table( 'lead_picks' ), array( 'lead_id' => $row->id, 'applicant_id' => $aid, 'added_by' => get_current_user_id(), 'created_at' => prr_now() ) );
    prr_log( 'lead', $row->id, 'comment', "Client picked {$a->full_name} ({$a->ref})." );
    prr_log( 'applicant', $aid, 'system', 'Picked by ' . ( $row->company ?: $row->ref ) . ' (lead ' . $row->ref . ').' );
    return prr_ok( prr_format_lead( prr_get_lead_row( $row->id ), true ) );
}
function prr_api_lead_pick_remove( WP_REST_Request $r ) {
    global $wpdb;
    $row = prr_get_lead_row( (int) $r['id'] ); $a = prr_get_applicant_row( (int) $r['aid'] );
    if ( ! prr_lead_visible( $row ) ) return prr_err( 'Lead not found.', 404 );
    $wpdb->delete( prr_table( 'lead_picks' ), array( 'lead_id' => $row->id, 'applicant_id' => (int) $r['aid'] ) );
    if ( $a ) prr_log( 'lead', $row->id, 'comment', "Removed {$a->full_name} from client picks." );
    return prr_ok( prr_format_lead( prr_get_lead_row( $row->id ), true ) );
}

/** Lead → client company. Copies contact, vacancies parsed from "Position x 12" lines, and documents. */
function prr_api_lead_convert( WP_REST_Request $r ) {
    global $wpdb;
    $row = prr_get_lead_row( (int) $r['id'] );
    if ( ! prr_lead_visible( $row ) ) return prr_err( 'Lead not found.', 404 );
    if ( $row->client_id ) return prr_err( 'This lead is already a client.' );
    if ( trim( $row->company ) === '' ) return prr_err( 'Add the company name before converting.' );
    $cid = prr_insert_client( array(
        'company_name' => $row->company, 'location' => $row->country, 'contact_name' => $row->contact_name,
        'contact_email' => $row->email, 'contact_phone' => $row->phone, 'lead_id' => $row->id,
    ) );
    if ( is_wp_error( $cid ) ) return $cid;
    $n = 0;
    foreach ( preg_split( '/\r?\n/', (string) $row->needs ) as $line ) {
        if ( preg_match( '/^\s*(.+?)\s*[x×]\s*(\d+)/iu', $line, $m ) ) {
            $wpdb->insert( prr_table( 'vacancies' ), array( 'client_id' => $cid, 'position' => sanitize_text_field( $m[1] ), 'headcount' => (int) $m[2], 'created_at' => prr_now() ) );
            $n++;
        }
    }
    $wpdb->query( $wpdb->prepare( 'INSERT INTO ' . prr_table( 'documents' ) . ' (object_type, object_id, doc_type, file_name, stored_path, mime, size_bytes, doc_number, expiry_date, signed_date, valid_until, inv_number, inv_amount, inv_currency, inv_due, inv_status, note, uploaded_by, created_at)
        SELECT %s, %d, doc_type, file_name, stored_path, mime, size_bytes, doc_number, expiry_date, signed_date, valid_until, inv_number, inv_amount, inv_currency, inv_due, inv_status, note, uploaded_by, created_at FROM ' . prr_table( 'documents' ) . " WHERE object_type = 'lead' AND object_id = %d", 'client', $cid, $row->id ) );
    $wpdb->update( prr_table( 'leads' ), array( 'client_id' => $cid, 'status' => 'converted', 'updated_at' => prr_now() ), array( 'id' => $row->id ) );
    $c = $wpdb->get_row( $wpdb->prepare( 'SELECT ref FROM ' . prr_table( 'clients' ) . ' WHERE id = %d', $cid ) );
    prr_log( 'lead', $row->id, 'status', "Converted to client {$c->ref}. $n vacancies copied from needs.", array( 'to' => 'converted' ) );
    prr_log( 'client', $cid, 'system', "Created from lead {$row->ref}." );
    prr_audit( 'lead_converted', 'lead', $row->id, array( 'client' => $cid ) );
    return prr_ok( array( 'client_id' => $cid ) );
}
