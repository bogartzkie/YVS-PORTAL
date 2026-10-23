<?php
/**
 * Candidate submissions to clients. Each submission has its own status, separate from the
 * applicant's overall status. The same candidate + client + position can only be submitted once.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function prr_format_submission( $s ) {
    return array(
        'id' => (int) $s->id, 'position' => $s->position, 'submitted_on' => $s->submitted_on,
        'status' => prr_status_info( prr_submission_statuses(), $s->status ), 'notes' => $s->notes,
        'recruiter' => prr_user_brief( $s->recruiter_id ), 'updated_at' => $s->updated_at,
        'applicant' => array( 'id' => (int) $s->applicant_id, 'name' => $s->a_name, 'ref' => $s->a_ref ),
        'client' => array( 'id' => (int) $s->client_id, 'name' => $s->c_name, 'ref' => $s->c_ref ),
    );
}

function prr_list_submissions( $filters = array() ) {
    global $wpdb;
    $where = array( '1=1' ); $args = array();
    foreach ( array( 'applicant_id' => 's.applicant_id', 'client_id' => 's.client_id', 'recruiter_id' => 's.recruiter_id' ) as $k => $col ) {
        if ( ! empty( $filters[ $k ] ) ) { $where[] = "$col = %d"; $args[] = (int) $filters[ $k ]; }
    }
    if ( ! empty( $filters['status'] ) ) { $where[] = 's.status = %s'; $args[] = $filters['status']; }
    if ( ! empty( $filters['from'] ) )   { $where[] = 's.submitted_on >= %s'; $args[] = $filters['from']; }
    if ( ! empty( $filters['to'] ) )     { $where[] = 's.submitted_on <= %s'; $args[] = $filters['to']; }
    if ( ! prr_sees_all() && empty( $filters['applicant_id'] ) ) { $where[] = 's.recruiter_id = %d'; $args[] = get_current_user_id(); }
    $sql = 'SELECT s.*, a.full_name a_name, a.ref a_ref, c.company_name c_name, c.ref c_ref FROM ' . prr_table( 'submissions' ) . ' s
            JOIN ' . prr_table( 'applicants' ) . ' a ON a.id = s.applicant_id JOIN ' . prr_table( 'clients' ) . ' c ON c.id = s.client_id
            WHERE ' . implode( ' AND ', $where ) . ' ORDER BY s.submitted_on DESC, s.id DESC LIMIT 1000';
    $rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql );
    return array_map( 'prr_format_submission', $rows );
}

function prr_find_submission( $applicant_id, $client_id, $position ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . prr_table( 'submissions' ) . ' WHERE applicant_id = %d AND client_id = %d AND position = %s', $applicant_id, $client_id, $position ) );
}

add_action( 'rest_api_init', function() {
    $ns = PRR_API_NS;
    register_rest_route( $ns, '/submissions', array(
        array( 'methods' => 'GET',  'callback' => 'prr_api_submissions_list',  'permission_callback' => prr_gate( 'view_applicants' ) ),
        array( 'methods' => 'POST', 'callback' => 'prr_api_submission_create', 'permission_callback' => prr_gate( 'submit_candidates' ) ),
    ) );
    register_rest_route( $ns, '/submissions/check', array(
        'methods' => 'POST', 'callback' => 'prr_api_submission_check', 'permission_callback' => prr_gate( 'view_applicants' ) ) );
    register_rest_route( $ns, '/submissions/(?P<id>\d+)', array(
        'methods' => 'PATCH', 'callback' => 'prr_api_submission_update', 'permission_callback' => prr_gate( 'submit_candidates' ) ) );
} );

function prr_api_submissions_list( WP_REST_Request $r ) {
    return prr_ok( prr_list_submissions( array(
        'applicant_id' => (int) $r['applicant_id'], 'client_id' => (int) $r['client_id'], 'recruiter_id' => (int) $r['recruiter'],
        'status' => sanitize_key( (string) $r['status'] ), 'from' => prr_date_or_null( $r['from'] ), 'to' => prr_date_or_null( $r['to'] ),
    ) ) );
}

function prr_api_submission_check( WP_REST_Request $r ) {
    $d = (array) $r->get_json_params();
    $s = prr_find_submission( (int) ( $d['applicant_id'] ?? 0 ), (int) ( $d['client_id'] ?? 0 ), sanitize_text_field( $d['position'] ?? '' ) );
    return prr_ok( array( 'exists' => (bool) $s, 'submission' => $s ? array( 'id' => (int) $s->id, 'status' => prr_status_info( prr_submission_statuses(), $s->status ), 'submitted_on' => $s->submitted_on ) : null ) );
}

function prr_api_submission_create( WP_REST_Request $r ) {
    global $wpdb;
    $d = (array) $r->get_json_params();
    $aid = (int) ( $d['applicant_id'] ?? 0 ); $cid = (int) ( $d['client_id'] ?? 0 );
    $pos = sanitize_text_field( $d['position'] ?? '' );
    $a = prr_get_applicant_row( $aid ); $c = prr_get_client_row( $cid );
    if ( ! $a || ! prr_applicant_visible( $a ) ) return prr_err( 'Candidate not found.', 404 );
    if ( ! $c || ! $c->is_active ) return prr_err( 'Client not found or archived.', 404 );
    if ( $pos === '' ) return prr_err( 'Choose the position.' );
    if ( $dup = prr_find_submission( $aid, $cid, $pos ) ) {
        return prr_err( sprintf( '%s was already submitted to %s for %s on %s.', $a->full_name, $c->company_name, $pos, mysql2date( 'j M Y', $dup->submitted_on ) ), 409 );
    }
    $status = sanitize_key( $d['status'] ?? 'submitted' );
    if ( ! isset( prr_submission_statuses()[ $status ] ) ) $status = 'submitted';
    $ok = $wpdb->insert( prr_table( 'submissions' ), array(
        'applicant_id' => $aid, 'client_id' => $cid, 'position' => $pos,
        'recruiter_id' => ! empty( $d['recruiter_id'] ) ? (int) $d['recruiter_id'] : get_current_user_id(),
        'status' => $status, 'submitted_on' => prr_date_or_null( $d['submitted_on'] ?? '' ) ?: current_time( 'Y-m-d' ),
        'notes' => sanitize_textarea_field( $d['notes'] ?? '' ), 'created_at' => prr_now(), 'updated_at' => prr_now(),
    ) );
    if ( ! $ok ) return prr_err( 'This submission already exists.', 409 ); // unique key backstop
    $sid = (int) $wpdb->insert_id;
    prr_log( 'applicant', $aid, 'system', "Submitted to {$c->company_name} for $pos.", array( 'submission' => $sid ) );
    prr_log( 'client', $cid, 'system', "Candidate submitted: {$a->full_name} ({$a->ref}) for $pos." );
    prr_audit( 'candidate_submitted', 'applicant', $aid, array( 'client' => $cid, 'position' => $pos ) );

    // Optionally email the profile + CV to the client contact.
    $mail = null;
    if ( ! empty( $d['send_email'] ) && $c->contact_email ) {
        $mail = prr_send_candidate_profile( $sid, $d['email'] ?? array() );
    }
    $sub = prr_list_submissions( array( 'applicant_id' => $aid, 'client_id' => $cid ) );
    return prr_ok( array( 'submission' => $sub ? $sub[0] : null, 'email' => $mail ) );
}

function prr_api_submission_update( WP_REST_Request $r ) {
    global $wpdb;
    $s = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . prr_table( 'submissions' ) . ' WHERE id = %d', (int) $r['id'] ) );
    if ( ! $s ) return prr_err( 'Submission not found.', 404 );
    $d = (array) $r->get_json_params(); $f = array();
    $list = prr_submission_statuses();
    if ( isset( $d['status'] ) && $d['status'] !== $s->status ) {
        $new = sanitize_key( $d['status'] );
        if ( ! isset( $list[ $new ] ) ) return prr_err( 'Unknown status.' );
        $f['status'] = $new;
        $c = prr_get_client_row( $s->client_id );
        $msg = sprintf( '%s submission (%s): %s → %s', $c ? $c->company_name : 'Client', $s->position, prr_status_info( $list, $s->status )['label'], $list[ $new ]['label'] );
        prr_log( 'applicant', $s->applicant_id, 'status', $msg, array( 'submission' => (int) $s->id ) );
        prr_log( 'client', $s->client_id, 'status', $msg );
    }
    if ( isset( $d['notes'] ) ) $f['notes'] = sanitize_textarea_field( $d['notes'] );
    if ( isset( $d['recruiter_id'] ) ) $f['recruiter_id'] = (int) $d['recruiter_id'] ?: null;
    if ( $f ) { $f['updated_at'] = prr_now(); $wpdb->update( prr_table( 'submissions' ), $f, array( 'id' => $s->id ) ); }
    $one = prr_list_submissions( array( 'applicant_id' => $s->applicant_id, 'client_id' => $s->client_id ) );
    foreach ( $one as $x ) if ( $x['id'] === (int) $s->id ) return prr_ok( $x );
    return prr_ok( array() );
}
