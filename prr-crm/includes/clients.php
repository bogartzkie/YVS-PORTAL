<?php
/**
 * Client companies and their vacancies.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function prr_get_client_row( $id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . prr_table( 'clients' ) . ' WHERE id = %d', $id ) );
}

function prr_client_vacancies( $client_id ) {
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . prr_table( 'vacancies' ) . ' WHERE client_id = %d ORDER BY id', $client_id ) );
    $out = array();
    foreach ( $rows as $v ) {
        $filled = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . prr_table( 'submissions' ) . " WHERE client_id = %d AND position = %s AND status = 'placed'", $client_id, $v->position ) );
        $out[] = array( 'id' => (int) $v->id, 'position' => $v->position, 'headcount' => (int) $v->headcount, 'filled' => $filled, 'status' => $v->status, 'notes' => $v->notes );
    }
    return $out;
}

function prr_format_client( $r, $full = false ) {
    if ( ! $r ) return null;
    global $wpdb;
    $s = prr_table( 'submissions' );
    $out = array(
        'id' => (int) $r->id, 'ref' => $r->ref, 'company_name' => $r->company_name, 'industry' => $r->industry, 'location' => $r->location,
        'contact_name' => $r->contact_name, 'contact_email' => $r->contact_email, 'contact_phone' => $r->contact_phone,
        'is_active' => (bool) $r->is_active, 'lead_id' => $r->lead_id ? (int) $r->lead_id : null, 'created_at' => $r->created_at,
        'open_roles' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(headcount),0) FROM ' . prr_table( 'vacancies' ) . " WHERE client_id = %d AND status = 'open'", $r->id ) ),
        'submitted'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $s WHERE client_id = %d", $r->id ) ),
        'placed'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $s WHERE client_id = %d AND status = 'placed'", $r->id ) ),
        'vacancies'  => prr_client_vacancies( $r->id ),
    );
    if ( $full ) {
        $out['terms']       = $r->terms;
        $out['notes']       = $r->notes;
        $out['submissions'] = prr_list_submissions( array( 'client_id' => (int) $r->id ) );
        $out['documents']   = prr_list_documents( 'client', $r->id );
        $out['timeline']    = prr_get_timeline( 'client', $r->id );
        $out['messages']    = prr_list_messages( 'client', $r->id );
    }
    return $out;
}

function prr_insert_client( $d ) {
    global $wpdb;
    $name = sanitize_text_field( $d['company_name'] ?? '' );
    if ( $name === '' ) return prr_err( 'Company name is required.' );
    $wpdb->insert( prr_table( 'clients' ), array(
        'ref' => '', 'company_name' => $name, 'industry' => sanitize_text_field( $d['industry'] ?? '' ),
        'location' => sanitize_text_field( $d['location'] ?? '' ), 'contact_name' => sanitize_text_field( $d['contact_name'] ?? '' ),
        'contact_email' => sanitize_email( $d['contact_email'] ?? '' ), 'contact_phone' => sanitize_text_field( $d['contact_phone'] ?? '' ),
        'terms' => sanitize_textarea_field( $d['terms'] ?? '' ), 'notes' => sanitize_textarea_field( $d['notes'] ?? '' ),
        'lead_id' => ! empty( $d['lead_id'] ) ? (int) $d['lead_id'] : null,
        'created_by' => get_current_user_id() ?: null, 'created_at' => prr_now(), 'updated_at' => prr_now(),
    ) );
    $id = (int) $wpdb->insert_id;
    if ( ! $id ) return prr_err( 'Could not save the client: ' . $wpdb->last_error, 500 );
    $wpdb->update( prr_table( 'clients' ), array( 'ref' => 'CL-' . str_pad( (string) $id, 4, '0', STR_PAD_LEFT ) ), array( 'id' => $id ) );
    foreach ( (array) ( $d['vacancies'] ?? array() ) as $v ) {
        $pos = sanitize_text_field( $v['position'] ?? '' );
        if ( $pos !== '' ) $wpdb->insert( prr_table( 'vacancies' ), array( 'client_id' => $id, 'position' => $pos, 'headcount' => max( 1, (int) ( $v['headcount'] ?? 1 ) ), 'created_at' => prr_now() ) );
    }
    return $id;
}

add_action( 'rest_api_init', function() {
    $ns = PRR_API_NS;
    register_rest_route( $ns, '/clients', array(
        array( 'methods' => 'GET',  'callback' => 'prr_api_clients_list',  'permission_callback' => prr_gate( 'view_clients' ) ),
        array( 'methods' => 'POST', 'callback' => 'prr_api_client_create', 'permission_callback' => prr_gate( 'manage_clients' ) ),
    ) );
    register_rest_route( $ns, '/clients/(?P<id>\d+)', array(
        array( 'methods' => 'GET',   'callback' => 'prr_api_client_get',    'permission_callback' => prr_gate( 'view_clients' ) ),
        array( 'methods' => 'PATCH', 'callback' => 'prr_api_client_update', 'permission_callback' => prr_gate( 'manage_clients' ) ),
    ) );
    register_rest_route( $ns, '/clients/(?P<id>\d+)/vacancies', array(
        'methods' => 'POST', 'callback' => 'prr_api_vacancy_add', 'permission_callback' => prr_gate( 'manage_clients' ) ) );
    register_rest_route( $ns, '/vacancies/(?P<id>\d+)', array(
        array( 'methods' => 'PATCH',  'callback' => 'prr_api_vacancy_update', 'permission_callback' => prr_gate( 'manage_clients' ) ),
        array( 'methods' => 'DELETE', 'callback' => 'prr_api_vacancy_delete', 'permission_callback' => prr_gate( 'manage_clients' ) ),
    ) );
    register_rest_route( $ns, '/clients/(?P<id>\d+)/activity', array(
        'methods' => 'POST', 'callback' => 'prr_api_client_comment', 'permission_callback' => prr_gate( 'view_clients' ) ) );
} );

function prr_api_clients_list( WP_REST_Request $r ) {
    global $wpdb;
    $where = array( '1=1' ); $args = array();
    if ( ! $r['archived'] ) $where[] = 'is_active = 1';
    $q = sanitize_text_field( (string) $r['q'] );
    if ( $q !== '' ) {
        $like = '%' . $wpdb->esc_like( $q ) . '%';
        $where[] = '(company_name LIKE %s OR contact_name LIKE %s OR contact_email LIKE %s OR location LIKE %s OR ref LIKE %s)';
        array_push( $args, $like, $like, $like, $like, $like );
    }
    $sql = 'SELECT * FROM ' . prr_table( 'clients' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY is_active DESC, company_name ASC LIMIT 500';
    $rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql );
    $archived = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . prr_table( 'clients' ) . ' WHERE is_active = 0' );
    return prr_ok( array( 'items' => array_map( 'prr_format_client', $rows ), 'archived' => $archived ) );
}

function prr_api_client_create( WP_REST_Request $r ) {
    $id = prr_insert_client( (array) $r->get_json_params() );
    if ( is_wp_error( $id ) ) return $id;
    prr_log( 'client', $id, 'system', 'Client added.' );
    prr_audit( 'client_created', 'client', $id );
    return prr_ok( prr_format_client( prr_get_client_row( $id ), true ) );
}

function prr_api_client_get( WP_REST_Request $r ) {
    $row = prr_get_client_row( (int) $r['id'] );
    return $row ? prr_ok( prr_format_client( $row, true ) ) : prr_err( 'Client not found.', 404 );
}

function prr_api_client_update( WP_REST_Request $r ) {
    global $wpdb;
    $row = prr_get_client_row( (int) $r['id'] );
    if ( ! $row ) return prr_err( 'Client not found.', 404 );
    $d = (array) $r->get_json_params(); $f = array();
    foreach ( array( 'company_name', 'industry', 'location', 'contact_name', 'contact_phone' ) as $k ) if ( array_key_exists( $k, $d ) ) $f[ $k ] = sanitize_text_field( $d[ $k ] );
    if ( array_key_exists( 'contact_email', $d ) ) $f['contact_email'] = sanitize_email( $d['contact_email'] );
    foreach ( array( 'terms', 'notes' ) as $k ) if ( array_key_exists( $k, $d ) ) $f[ $k ] = sanitize_textarea_field( $d[ $k ] );
    if ( isset( $f['company_name'] ) && $f['company_name'] === '' ) return prr_err( 'Company name is required.' );
    if ( array_key_exists( 'is_active', $d ) && (int) (bool) $d['is_active'] !== (int) $row->is_active ) {
        $f['is_active'] = $d['is_active'] ? 1 : 0;
        prr_log( 'client', $row->id, 'system', $f['is_active'] ? 'Client restored.' : 'Client archived. History is kept.' );
        prr_audit( $f['is_active'] ? 'client_restored' : 'client_archived', 'client', $row->id );
    } elseif ( $f ) {
        prr_log( 'client', $row->id, 'system', 'Details edited.' );
    }
    if ( $f ) { $f['updated_at'] = prr_now(); $wpdb->update( prr_table( 'clients' ), $f, array( 'id' => $row->id ) ); }
    return prr_ok( prr_format_client( prr_get_client_row( $row->id ), true ) );
}

function prr_api_vacancy_add( WP_REST_Request $r ) {
    global $wpdb;
    $cid = (int) $r['id'];
    if ( ! prr_get_client_row( $cid ) ) return prr_err( 'Client not found.', 404 );
    $d = (array) $r->get_json_params();
    $pos = sanitize_text_field( $d['position'] ?? '' );
    if ( $pos === '' ) return prr_err( 'Enter the position.' );
    $wpdb->insert( prr_table( 'vacancies' ), array( 'client_id' => $cid, 'position' => $pos, 'headcount' => max( 1, (int) ( $d['headcount'] ?? 1 ) ), 'notes' => sanitize_textarea_field( $d['notes'] ?? '' ), 'created_at' => prr_now() ) );
    prr_log( 'client', $cid, 'system', "Vacancy added: $pos x " . max( 1, (int) ( $d['headcount'] ?? 1 ) ) . '.' );
    return prr_ok( prr_client_vacancies( $cid ) );
}
function prr_api_vacancy_update( WP_REST_Request $r ) {
    global $wpdb;
    $v = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . prr_table( 'vacancies' ) . ' WHERE id = %d', (int) $r['id'] ) );
    if ( ! $v ) return prr_err( 'Vacancy not found.', 404 );
    $d = (array) $r->get_json_params(); $f = array();
    if ( isset( $d['position'] ) )  $f['position']  = sanitize_text_field( $d['position'] );
    if ( isset( $d['headcount'] ) ) $f['headcount'] = max( 1, (int) $d['headcount'] );
    if ( isset( $d['status'] ) && in_array( $d['status'], array( 'open', 'filled', 'closed' ), true ) ) $f['status'] = $d['status'];
    if ( $f ) $wpdb->update( prr_table( 'vacancies' ), $f, array( 'id' => $v->id ) );
    return prr_ok( prr_client_vacancies( $v->client_id ) );
}
function prr_api_vacancy_delete( WP_REST_Request $r ) {
    global $wpdb;
    $v = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . prr_table( 'vacancies' ) . ' WHERE id = %d', (int) $r['id'] ) );
    if ( ! $v ) return prr_err( 'Vacancy not found.', 404 );
    $wpdb->delete( prr_table( 'vacancies' ), array( 'id' => $v->id ) );
    prr_log( 'client', $v->client_id, 'system', "Vacancy removed: {$v->position}." );
    return prr_ok( prr_client_vacancies( $v->client_id ) );
}
function prr_api_client_comment( WP_REST_Request $r ) {
    $row = prr_get_client_row( (int) $r['id'] );
    if ( ! $row ) return prr_err( 'Client not found.', 404 );
    $body = sanitize_textarea_field( $r->get_json_params()['body'] ?? '' );
    if ( $body === '' ) return prr_err( 'Write a comment first.' );
    prr_log( 'client', $row->id, 'comment', $body );
    return prr_ok( prr_get_timeline( 'client', $row->id ) );
}
