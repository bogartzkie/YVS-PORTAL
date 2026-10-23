<?php
/**
 * Applicants: CRUD, search/filter/sort, duplicate detection, bulk import, merge, anonymise.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function prr_applicant_sources() {
    return apply_filters( 'prr_applicant_sources', array(
        'website' => 'Website form', 'import' => 'Excel / CSV import', 'manual' => 'Manual entry',
        'referral' => 'Referral', 'facebook' => 'Facebook', 'linkedin' => 'LinkedIn', 'walk_in' => 'Walk-in', 'other' => 'Other',
    ) );
}

/** Find another applicant with the same email or phone. */
function prr_find_duplicate_applicant( $email, $phone_norm, $exclude_id = 0 ) {
    global $wpdb;
    $t = prr_table( 'applicants' );
    $email = strtolower( trim( (string) $email ) );
    if ( $email === '' && $phone_norm === '' ) return null;
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, ref, full_name, email, phone_norm, created_at FROM $t
         WHERE id <> %d AND anonymised_at IS NULL AND ( (email <> '' AND email = %s) OR (phone_norm <> '' AND phone_norm = %s) )
         ORDER BY id ASC LIMIT 1", (int) $exclude_id, $email, $phone_norm ) );
    if ( ! $row ) return null;
    return array( 'id' => (int) $row->id, 'ref' => $row->ref, 'name' => $row->full_name, 'created_at' => $row->created_at,
                  'match' => ( $email !== '' && strtolower( $row->email ) === $email ) ? 'email' : 'phone' );
}

function prr_format_applicant( $r, $full = false ) {
    if ( ! $r ) return null;
    $sources = prr_applicant_sources();
    $out = array(
        'id' => (int) $r->id, 'ref' => $r->ref, 'full_name' => $r->full_name, 'email' => $r->email, 'phone' => $r->phone,
        'location' => $r->location, 'position' => $r->position, 'skills' => $r->skills, 'experience' => $r->experience,
        'source' => $r->source, 'source_label' => $sources[ $r->source ] ?? $r->source,
        'status' => prr_status_info( prr_applicant_statuses(), $r->status ),
        'recruiter' => prr_user_brief( $r->recruiter_id ),
        'consent' => (bool) $r->consent, 'created_at' => $r->created_at, 'updated_at' => $r->updated_at,
        'anonymised' => ! empty( $r->anonymised_at ),
    );
    if ( $full ) {
        global $wpdb;
        $out['notes']  = $r->notes;
        $out['custom'] = $r->custom ? json_decode( $r->custom, true ) : new stdClass();
        $out['duplicate'] = $r->not_duplicate ? null : prr_find_duplicate_applicant( $r->email, $r->phone_norm, $r->id );
        $out['timeline'] = prr_get_timeline( 'applicant', $r->id );
        $out['submissions'] = prr_list_submissions( array( 'applicant_id' => (int) $r->id ) );
        $out['documents'] = prr_list_documents( 'applicant', $r->id );
        $out['messages'] = prr_list_messages( 'applicant', $r->id );
        $out['picked_by'] = $wpdb->get_results( $wpdb->prepare(
            'SELECT l.id, l.ref, l.company, l.contact_name FROM ' . prr_table( 'lead_picks' ) . ' p JOIN ' . prr_table( 'leads' ) . ' l ON l.id = p.lead_id WHERE p.applicant_id = %d', $r->id ) );
    }
    return $out;
}

function prr_get_applicant_row( $id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . prr_table( 'applicants' ) . ' WHERE id = %d', $id ) );
}

/** Recruiters who only see their own records get 404 on others. */
function prr_applicant_visible( $row ) {
    if ( ! $row ) return false;
    return prr_sees_all() || (int) $row->recruiter_id === get_current_user_id();
}

add_action( 'rest_api_init', function() {
    $ns = PRR_API_NS;
    register_rest_route( $ns, '/applicants', array(
        array( 'methods' => 'GET',  'callback' => 'prr_api_applicants_list',  'permission_callback' => prr_gate( 'view_applicants' ) ),
        array( 'methods' => 'POST', 'callback' => 'prr_api_applicant_create', 'permission_callback' => prr_gate( 'edit_applicants' ) ),
    ) );
    register_rest_route( $ns, '/applicants/check-duplicate', array(
        'methods' => 'POST', 'callback' => 'prr_api_applicant_check_dup', 'permission_callback' => prr_gate( 'view_applicants' ) ) );
    register_rest_route( $ns, '/applicants/import', array(
        'methods' => 'POST', 'callback' => 'prr_api_applicants_import', 'permission_callback' => prr_gate( 'import_data' ) ) );
    register_rest_route( $ns, '/applicants/(?P<id>\d+)', array(
        array( 'methods' => 'GET',    'callback' => 'prr_api_applicant_get',    'permission_callback' => prr_gate( 'view_applicants' ) ),
        array( 'methods' => 'PATCH',  'callback' => 'prr_api_applicant_update', 'permission_callback' => prr_gate( 'edit_applicants' ) ),
        array( 'methods' => 'DELETE', 'callback' => 'prr_api_applicant_delete', 'permission_callback' => prr_gate( 'delete_applicants' ) ),
    ) );
    register_rest_route( $ns, '/applicants/(?P<id>\d+)/activity', array(
        'methods' => 'POST', 'callback' => 'prr_api_applicant_comment', 'permission_callback' => prr_gate( 'view_applicants' ) ) );
    register_rest_route( $ns, '/applicants/(?P<id>\d+)/merge', array(
        'methods' => 'POST', 'callback' => 'prr_api_applicant_merge', 'permission_callback' => prr_gate( 'edit_applicants' ) ) );
    register_rest_route( $ns, '/applicants/(?P<id>\d+)/not-duplicate', array(
        'methods' => 'POST', 'callback' => 'prr_api_applicant_not_dup', 'permission_callback' => prr_gate( 'edit_applicants' ) ) );
    register_rest_route( $ns, '/positions', array(
        'methods' => 'GET', 'callback' => 'prr_api_positions', 'permission_callback' => prr_gate() ) );
} );

function prr_api_applicants_list( WP_REST_Request $r ) {
    global $wpdb;
    $t = prr_table( 'applicants' );
    $where = array( 'anonymised_at IS NULL' ); $args = array();

    $q = sanitize_text_field( (string) $r['q'] );
    if ( $q !== '' ) {
        $like = '%' . $wpdb->esc_like( $q ) . '%';
        $digits = preg_replace( '/\D/', '', $q );
        $w = '(full_name LIKE %s OR email LIKE %s OR ref LIKE %s OR position LIKE %s OR location LIKE %s OR skills LIKE %s';
        array_push( $args, $like, $like, $like, $like, $like, $like );
        if ( strlen( $digits ) >= 4 ) { $w .= ' OR phone_norm LIKE %s'; $args[] = '%' . $digits . '%'; }
        $where[] = $w . ')';
    }
    foreach ( array( 'status' => 'status', 'source' => 'source', 'position' => 'position' ) as $param => $col ) {
        $v = sanitize_text_field( (string) $r[ $param ] );
        if ( $v !== '' ) { $where[] = "$col = %s"; $args[] = $v; }
    }
    if ( $r['recruiter'] ) { $where[] = 'recruiter_id = %d'; $args[] = (int) $r['recruiter']; }
    if ( ! prr_sees_all() ) { $where[] = 'recruiter_id = %d'; $args[] = get_current_user_id(); }
    if ( $r['from'] ) { $where[] = 'created_at >= %s'; $args[] = prr_date_or_null( $r['from'] ) . ' 00:00:00'; }
    if ( $r['to'] )   { $where[] = 'created_at <= %s'; $args[] = prr_date_or_null( $r['to'] ) . ' 23:59:59'; }

    $sortable = array( 'created_at', 'full_name', 'ref', 'position', 'location', 'source', 'status', 'updated_at' );
    $sort  = in_array( $r['sort'], $sortable, true ) ? $r['sort'] : 'created_at';
    $order = strtolower( (string) $r['order'] ) === 'asc' ? 'ASC' : 'DESC';
    $per   = min( 200, max( 1, (int) ( $r['per_page'] ?: 50 ) ) );
    $page  = max( 1, (int) $r['page'] );
    $sqlw  = 'WHERE ' . implode( ' AND ', $where );

    $total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t $sqlw", $args ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM $t $sqlw" ) );
    $sql   = "SELECT * FROM $t $sqlw ORDER BY $sort $order, id DESC LIMIT " . (int) $per . ' OFFSET ' . (int) ( ( $page - 1 ) * $per );
    $rows  = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql );

    // Mark rows that share an email or phone with another applicant (one query, not one per row).
    $emails = array_filter( array_unique( wp_list_pluck( $rows, 'email' ) ) );
    $phones = array_filter( array_unique( wp_list_pluck( $rows, 'phone_norm' ) ) );
    $dupE = $dupP = array();
    if ( $emails ) {
        $in = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
        $dupE = $wpdb->get_col( $wpdb->prepare( "SELECT email FROM $t WHERE anonymised_at IS NULL AND email IN ($in) GROUP BY email HAVING COUNT(*) > 1", array_values( $emails ) ) );
    }
    if ( $phones ) {
        $in = implode( ',', array_fill( 0, count( $phones ), '%s' ) );
        $dupP = $wpdb->get_col( $wpdb->prepare( "SELECT phone_norm FROM $t WHERE anonymised_at IS NULL AND phone_norm IN ($in) GROUP BY phone_norm HAVING COUNT(*) > 1", array_values( $phones ) ) );
    }
    $items = array();
    foreach ( $rows as $row ) {
        $a = prr_format_applicant( $row );
        $a['possible_duplicate'] = ! $row->not_duplicate && ( in_array( $row->email, $dupE, true ) || in_array( $row->phone_norm, $dupP, true ) );
        $items[] = $a;
    }
    $dup_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t a WHERE anonymised_at IS NULL AND not_duplicate = 0 AND EXISTS (SELECT 1 FROM $t b WHERE b.id <> a.id AND b.anonymised_at IS NULL AND ((a.email <> '' AND b.email = a.email) OR (a.phone_norm <> '' AND b.phone_norm = a.phone_norm)))" );
    return prr_ok( array( 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per, 'duplicates' => $dup_total ) );
}

/** Shared by the API, import and website intake. Returns the new ID or WP_Error. */
function prr_insert_applicant( $d, $source = 'manual', $log_text = '' ) {
    global $wpdb;
    $name  = sanitize_text_field( $d['full_name'] ?? '' );
    $email = strtolower( sanitize_email( $d['email'] ?? '' ) );
    $phone = sanitize_text_field( $d['phone'] ?? '' );
    if ( $name === '' )                return prr_err( 'Full name is required.' );
    if ( $email === '' && $phone === '' ) return prr_err( 'Add an email address or phone number.' );
    $status = sanitize_key( $d['status'] ?? 'new' );
    if ( ! isset( prr_applicant_statuses()[ $status ] ) ) $status = 'new';
    $phone_norm = prr_norm_phone( $phone );
    $dup = prr_find_duplicate_applicant( $email, $phone_norm );

    $wpdb->insert( prr_table( 'applicants' ), array(
        'ref' => '', 'full_name' => $name, 'email' => $email, 'phone' => $phone, 'phone_norm' => $phone_norm,
        'location' => sanitize_text_field( $d['location'] ?? '' ), 'position' => sanitize_text_field( $d['position'] ?? '' ),
        'skills' => sanitize_textarea_field( $d['skills'] ?? '' ), 'experience' => sanitize_textarea_field( $d['experience'] ?? '' ),
        'source' => sanitize_key( $d['source'] ?? $source ) ?: $source, 'status' => $status,
        'recruiter_id' => ! empty( $d['recruiter_id'] ) ? (int) $d['recruiter_id'] : null,
        'notes' => sanitize_textarea_field( $d['notes'] ?? '' ),
        'custom' => ! empty( $d['custom'] ) && is_array( $d['custom'] ) ? wp_json_encode( array_map( 'sanitize_text_field', $d['custom'] ) ) : null,
        'consent' => ! empty( $d['consent'] ) ? 1 : 0, 'duplicate_of' => $dup ? $dup['id'] : null,
        'created_by' => get_current_user_id() ?: null, 'created_at' => prr_now(), 'updated_at' => prr_now(),
    ) );
    $id = (int) $wpdb->insert_id;
    if ( ! $id ) return prr_err( 'Could not save the applicant: ' . $wpdb->last_error, 500 );
    $g = prr_get_general();
    $ref = $g['ref_prefix'] . '-' . gmdate( 'y' ) . '-' . str_pad( (string) $id, 5, '0', STR_PAD_LEFT );
    $wpdb->update( prr_table( 'applicants' ), array( 'ref' => $ref ), array( 'id' => $id ) );
    $sources = prr_applicant_sources();
    prr_log( 'applicant', $id, 'system', $log_text ?: 'Registered via ' . ( $sources[ $source ] ?? $source ) . '.' );
    if ( $dup ) prr_log( 'applicant', $id, 'system', sprintf( 'Possible duplicate of %s (%s): same %s.', $dup['ref'], $dup['name'], $dup['match'] === 'email' ? 'email address' : 'phone number' ) );
    return $id;
}

function prr_api_applicant_create( WP_REST_Request $r ) {
    $d = (array) $r->get_json_params();
    if ( empty( $d['recruiter_id'] ) ) $d['recruiter_id'] = get_current_user_id();
    $id = prr_insert_applicant( $d, sanitize_key( $d['source'] ?? 'manual' ) ?: 'manual' );
    if ( is_wp_error( $id ) ) return $id;
    prr_audit( 'applicant_created', 'applicant', $id );
    return prr_ok( prr_format_applicant( prr_get_applicant_row( $id ), true ) );
}

function prr_api_applicant_check_dup( WP_REST_Request $r ) {
    $d = (array) $r->get_json_params();
    return prr_ok( array( 'duplicate' => prr_find_duplicate_applicant( $d['email'] ?? '', prr_norm_phone( $d['phone'] ?? '' ), (int) ( $d['exclude'] ?? 0 ) ) ) );
}

function prr_api_applicant_get( WP_REST_Request $r ) {
    $row = prr_get_applicant_row( (int) $r['id'] );
    if ( ! prr_applicant_visible( $row ) ) return prr_err( 'Applicant not found.', 404 );
    return prr_ok( prr_format_applicant( $row, true ) );
}

function prr_api_applicant_update( WP_REST_Request $r ) {
    global $wpdb;
    $id  = (int) $r['id'];
    $row = prr_get_applicant_row( $id );
    if ( ! prr_applicant_visible( $row ) || $row->anonymised_at ) return prr_err( 'Applicant not found.', 404 );
    $d = (array) $r->get_json_params();
    $f = array(); $changes = array();
    $labels = array( 'full_name' => 'Name', 'email' => 'Email', 'phone' => 'Phone', 'location' => 'Location', 'position' => 'Position',
                     'skills' => 'Skills', 'experience' => 'Experience', 'source' => 'Source' );
    foreach ( $labels as $k => $label ) {
        if ( ! array_key_exists( $k, $d ) ) continue;
        $v = in_array( $k, array( 'skills', 'experience' ), true ) ? sanitize_textarea_field( $d[ $k ] ) : sanitize_text_field( $d[ $k ] );
        if ( $k === 'email' ) $v = strtolower( sanitize_email( $v ) );
        if ( $k === 'source' ) $v = sanitize_key( $v );
        if ( $v !== $row->$k ) { $f[ $k ] = $v; $changes[] = $label; }
    }
    if ( isset( $f['phone'] ) ) $f['phone_norm'] = prr_norm_phone( $f['phone'] );
    if ( array_key_exists( 'notes', $d ) ) $f['notes'] = sanitize_textarea_field( $d['notes'] );
    if ( array_key_exists( 'consent', $d ) ) $f['consent'] = $d['consent'] ? 1 : 0;
    if ( array_key_exists( 'custom', $d ) && is_array( $d['custom'] ) ) $f['custom'] = wp_json_encode( array_map( 'sanitize_text_field', $d['custom'] ) );
    if ( array_key_exists( 'status', $d ) ) {
        $s = sanitize_key( $d['status'] );
        $list = prr_applicant_statuses();
        if ( ! isset( $list[ $s ] ) ) return prr_err( 'Unknown status.' );
        if ( $s !== $row->status ) {
            $f['status'] = $s;
            prr_log( 'applicant', $id, 'status', sprintf( 'Status changed: %s → %s', prr_status_info( $list, $row->status )['label'], $list[ $s ]['label'] ), array( 'from' => $row->status, 'to' => $s ) );
        }
    }
    if ( array_key_exists( 'recruiter_id', $d ) && (int) $d['recruiter_id'] !== (int) $row->recruiter_id ) {
        $f['recruiter_id'] = (int) $d['recruiter_id'] ?: null;
        $u = prr_user_brief( $f['recruiter_id'] );
        prr_log( 'applicant', $id, 'system', 'Assigned to ' . ( $u ? $u['name'] : 'nobody' ) . '.' );
        if ( $f['recruiter_id'] && $f['recruiter_id'] !== get_current_user_id() ) {
            prr_notify_user( $f['recruiter_id'], 'assigned', 'Applicant assigned to you', $row->full_name . ' · ' . $row->ref, 'applicant', $id );
        }
    }
    if ( $changes ) prr_log( 'applicant', $id, 'system', 'Edited: ' . implode( ', ', $changes ) . '.' );
    if ( isset( $f['notes'] ) && $f['notes'] !== $row->notes ) prr_log( 'applicant', $id, 'system', 'Internal notes edited.' );
    if ( $f ) {
        $f['updated_at'] = prr_now();
        $wpdb->update( prr_table( 'applicants' ), $f, array( 'id' => $id ) );
        prr_audit( 'applicant_updated', 'applicant', $id, array( 'fields' => array_keys( $f ) ) );
    }
    return prr_ok( prr_format_applicant( prr_get_applicant_row( $id ), true ) );
}

/** "Delete" removes personal data but keeps the record ID so submission history stays consistent. */
function prr_anonymise_applicant( $id, $user_id = null ) {
    global $wpdb;
    $wpdb->update( prr_table( 'applicants' ), array(
        'full_name' => 'Anonymised applicant', 'email' => '', 'phone' => '', 'phone_norm' => '', 'location' => '',
        'skills' => '', 'experience' => '', 'notes' => '', 'custom' => null, 'anonymised_at' => prr_now(), 'updated_at' => prr_now(),
    ), array( 'id' => $id ) );
    foreach ( prr_list_documents( 'applicant', $id, false ) as $doc ) prr_delete_document( $doc['id'], false );
    $wpdb->delete( prr_table( 'activity' ), array( 'object_type' => 'applicant', 'object_id' => $id ) );
    $wpdb->query( $wpdb->prepare( 'UPDATE ' . prr_table( 'messages' ) . " SET body = '', to_addr = '' WHERE object_type = 'applicant' AND object_id = %d", $id ) );
    prr_audit( 'applicant_anonymised', 'applicant', $id, array(), $user_id );
}
function prr_api_applicant_delete( WP_REST_Request $r ) {
    $row = prr_get_applicant_row( (int) $r['id'] );
    if ( ! $row ) return prr_err( 'Applicant not found.', 404 );
    prr_anonymise_applicant( (int) $row->id );
    return prr_ok( array( 'deleted' => true ) );
}

function prr_api_applicant_comment( WP_REST_Request $r ) {
    $row = prr_get_applicant_row( (int) $r['id'] );
    if ( ! prr_applicant_visible( $row ) ) return prr_err( 'Applicant not found.', 404 );
    $d = (array) $r->get_json_params();
    $body = sanitize_textarea_field( $d['body'] ?? '' );
    if ( $body === '' ) return prr_err( 'Write a comment first.' );
    $kind = in_array( $d['kind'] ?? '', array( 'comment', 'call' ), true ) ? $d['kind'] : 'comment';
    prr_log( 'applicant', $row->id, $kind, $body );
    return prr_ok( prr_get_timeline( 'applicant', $row->id ) );
}

/** Merge $from into $id: moves submissions, timeline, documents and messages, then anonymises $from. */
function prr_api_applicant_merge( WP_REST_Request $r ) {
    global $wpdb;
    $id   = (int) $r['id'];
    $from = (int) ( $r->get_json_params()['from_id'] ?? 0 );
    $keep = prr_get_applicant_row( $id ); $old = prr_get_applicant_row( $from );
    if ( ! $keep || ! $old || $id === $from ) return prr_err( 'Pick two different applicants to merge.' );
    foreach ( array( 'documents', 'messages', 'activity' ) as $t ) {
        $wpdb->update( prr_table( $t ), array( 'object_id' => $id ), array( 'object_type' => 'applicant', 'object_id' => $from ) );
    }
    // Submissions: move unless the kept record already has the same client + position.
    $subs = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . prr_table( 'submissions' ) . ' WHERE applicant_id = %d', $from ) );
    foreach ( $subs as $s ) {
        $wpdb->query( $wpdb->prepare( 'UPDATE IGNORE ' . prr_table( 'submissions' ) . ' SET applicant_id = %d WHERE id = %d', $id, $s->id ) );
    }
    $wpdb->query( $wpdb->prepare( 'UPDATE IGNORE ' . prr_table( 'lead_picks' ) . ' SET applicant_id = %d WHERE applicant_id = %d', $id, $from ) );
    // Fill blanks on the kept record from the old one.
    $fill = array();
    foreach ( array( 'email', 'phone', 'phone_norm', 'location', 'position', 'skills', 'experience' ) as $k ) {
        if ( $keep->$k === '' && $old->$k !== '' ) $fill[ $k ] = $old->$k;
    }
    if ( $fill ) $wpdb->update( prr_table( 'applicants' ), $fill, array( 'id' => $id ) );
    prr_log( 'applicant', $id, 'system', sprintf( 'Merged %s (%s) into this record.', $old->ref, $old->full_name ) );
    $wpdb->update( prr_table( 'applicants' ), array( 'not_duplicate' => 1 ), array( 'id' => $id ) );
    prr_anonymise_applicant( $from );
    prr_audit( 'applicant_merged', 'applicant', $id, array( 'from' => $from ) );
    return prr_ok( prr_format_applicant( prr_get_applicant_row( $id ), true ) );
}

function prr_api_applicant_not_dup( WP_REST_Request $r ) {
    global $wpdb;
    $id = (int) $r['id'];
    $wpdb->update( prr_table( 'applicants' ), array( 'not_duplicate' => 1 ), array( 'id' => $id ) );
    prr_log( 'applicant', $id, 'system', 'Marked as not a duplicate.' );
    return prr_ok( prr_format_applicant( prr_get_applicant_row( $id ), true ) );
}

/**
 * Bulk import. The browser parses Excel/CSV (SheetJS) and posts mapped rows in batches
 * of up to 500, so large sheets never hit PHP upload or memory limits.
 */
function prr_api_applicants_import( WP_REST_Request $r ) {
    global $wpdb;
    $d = (array) $r->get_json_params();
    $rows = array_slice( (array) ( $d['rows'] ?? array() ), 0, 500 );
    $mode = in_array( $d['on_duplicate'] ?? '', array( 'skip', 'update', 'flag' ), true ) ? $d['on_duplicate'] : 'skip';
    $assign = $d['assign'] ?? 'me'; // me | split | <user id> | none
    $team = array_values( array_filter( prr_team(), function( $u ) { return $u['role_slug'] !== 'prr_manager'; } ) );
    $res = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array() );
    $i = (int) ( $d['offset'] ?? 0 );
    foreach ( $rows as $n => $row ) {
        $row = (array) $row;
        $name = sanitize_text_field( $row['full_name'] ?? '' );
        if ( $name === '' ) { $res['errors'][] = array( 'row' => $i + $n + 2, 'error' => 'Missing name' ); continue; }
        $dup = prr_find_duplicate_applicant( $row['email'] ?? '', prr_norm_phone( $row['phone'] ?? '' ) );
        if ( $dup && $mode === 'skip' ) { $res['skipped']++; continue; }
        if ( $dup && $mode === 'update' ) {
            $f = array();
            foreach ( array( 'location', 'position', 'skills', 'experience' ) as $k ) {
                if ( ! empty( $row[ $k ] ) ) $f[ $k ] = sanitize_textarea_field( $row[ $k ] );
            }
            if ( $f ) { $f['updated_at'] = prr_now(); $wpdb->update( prr_table( 'applicants' ), $f, array( 'id' => $dup['id'] ) ); }
            prr_log( 'applicant', $dup['id'], 'system', 'Updated from spreadsheet import.' );
            $res['updated']++; continue;
        }
        if ( $assign === 'split' && $team )      $row['recruiter_id'] = $team[ ( $i + $n ) % count( $team ) ]['id'];
        elseif ( $assign === 'me' )               $row['recruiter_id'] = get_current_user_id();
        elseif ( is_numeric( $assign ) )          $row['recruiter_id'] = (int) $assign;
        $row['source'] = sanitize_key( $row['source'] ?? '' ) ?: 'import';
        $id = prr_insert_applicant( $row, 'import', 'Imported from ' . sanitize_text_field( $d['file_name'] ?? 'spreadsheet' ) . '.' );
        if ( is_wp_error( $id ) ) { $res['errors'][] = array( 'row' => $i + $n + 2, 'error' => $id->get_error_message() ); continue; }
        $res['created']++;
    }
    prr_audit( 'applicants_imported', 'applicant', 0, $res );
    return prr_ok( $res );
}

function prr_api_positions() {
    global $wpdb;
    $a = $wpdb->get_col( 'SELECT DISTINCT position FROM ' . prr_table( 'applicants' ) . " WHERE position <> '' AND anonymised_at IS NULL" );
    $v = $wpdb->get_col( 'SELECT DISTINCT position FROM ' . prr_table( 'vacancies' ) . " WHERE position <> ''" );
    $all = array_unique( array_merge( $a, $v ) );
    sort( $all, SORT_NATURAL | SORT_FLAG_CASE );
    return prr_ok( array_values( $all ) );
}
