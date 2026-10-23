<?php
/**
 * Dashboard numbers, reports, CSV export and in-app notifications.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function() {
    $ns = PRR_API_NS;
    register_rest_route( $ns, '/stats',   array( 'methods' => 'GET', 'callback' => 'prr_api_stats',   'permission_callback' => prr_gate() ) );
    register_rest_route( $ns, '/reports', array( 'methods' => 'GET', 'callback' => 'prr_api_reports', 'permission_callback' => prr_gate( 'view_reports' ) ) );
    register_rest_route( $ns, '/export/(?P<type>[a-z_]+)', array( 'methods' => 'GET', 'callback' => 'prr_api_export', 'permission_callback' => prr_gate( 'export_data' ) ) );
    register_rest_route( $ns, '/notifications', array(
        array( 'methods' => 'GET',  'callback' => 'prr_api_notifications',      'permission_callback' => prr_gate() ),
        array( 'methods' => 'POST', 'callback' => 'prr_api_notifications_read', 'permission_callback' => prr_gate() ),
    ) );
} );

function prr_api_stats( WP_REST_Request $r ) {
    global $wpdb;
    $A = prr_table( 'applicants' ); $S = prr_table( 'submissions' ); $L = prr_table( 'leads' ); $C = prr_table( 'clients' );
    $days = max( 1, min( 365, (int) ( $r['days'] ?: 30 ) ) );
    $since = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - $days * DAY_IN_SECONDS );
    $mineA = prr_sees_all() ? '' : $wpdb->prepare( ' AND recruiter_id = %d', get_current_user_id() );
    $mineS = prr_sees_all() ? '' : $wpdb->prepare( ' AND s.recruiter_id = %d', get_current_user_id() );
    $live  = ' AND anonymised_at IS NULL';

    $total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $A WHERE 1=1 $live $mineA" );
    $new     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $A WHERE created_at >= %s $live $mineA", $since ) );
    $ready   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $A WHERE status = 'ready' $live $mineA" );
    $subm    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $S s WHERE s.status NOT IN ('ready','withdrawn') $mineS" );
    $intv    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $S s WHERE s.status = 'interview' $mineS" );
    $placed  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $S s WHERE s.status = 'placed' $mineS" );

    $by_pos = $wpdb->get_results( "SELECT IF(position='','(not given)',position) label, COUNT(*) n FROM $A WHERE 1=1 $live $mineA GROUP BY label ORDER BY n DESC LIMIT 10" );
    $by_client = $wpdb->get_results( "SELECT c.id, c.company_name label, COUNT(s.id) sent, SUM(s.status='placed') placed FROM $C c LEFT JOIN $S s ON s.client_id = c.id WHERE c.is_active = 1 GROUP BY c.id ORDER BY sent DESC LIMIT 10" );

    // Funnel: unique candidates reaching each stage.
    $qualified = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT id) FROM $A a WHERE (status IN ('qualified','ready','interviewing','placed') OR EXISTS (SELECT 1 FROM $S s WHERE s.applicant_id = a.id)) $live $mineA" );
    $u_sub  = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT s.applicant_id) FROM $S s WHERE 1=1 $mineS" );
    $u_int  = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT s.applicant_id) FROM $S s WHERE s.status IN ('interview','shortlisted','placed') $mineS" );
    $u_pla  = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT s.applicant_id) FROM $S s WHERE s.status = 'placed' $mineS" );

    // Follow-ups: due lead calls + scheduled interviews.
    $today = current_time( 'Y-m-d' );
    $mineL = prr_sees_all() ? '' : $wpdb->prepare( ' AND owner_id = %d', get_current_user_id() );
    $fu = $wpdb->get_results( $wpdb->prepare( "SELECT id, ref, contact_name, company, phone, next_follow_up, owner_id, status FROM $L
        WHERE next_follow_up IS NOT NULL AND next_follow_up <= DATE_ADD(%s, INTERVAL 7 DAY) AND status NOT IN ('converted','not_interested','junk') $mineL ORDER BY next_follow_up ASC LIMIT 15", $today ) );
    $follow = array();
    foreach ( $fu as $l ) {
        $follow[] = array( 'type' => 'lead', 'id' => (int) $l->id, 'ref' => $l->ref, 'title' => 'Follow up: ' . ( $l->company ?: ( $l->contact_name ?: $l->phone ) ),
            'date' => $l->next_follow_up, 'overdue' => $l->next_follow_up < $today, 'owner' => prr_user_brief( $l->owner_id ) );
    }
    $iv = $wpdb->get_results( "SELECT s.id, s.applicant_id, s.position, s.recruiter_id, a.full_name, c.company_name FROM $S s JOIN $A a ON a.id = s.applicant_id JOIN $C c ON c.id = s.client_id WHERE s.status = 'interview' $mineS ORDER BY s.updated_at DESC LIMIT 10" );
    foreach ( $iv as $x ) {
        $follow[] = array( 'type' => 'applicant', 'id' => (int) $x->applicant_id, 'title' => "Interview: {$x->full_name} with {$x->company_name}", 'date' => null, 'overdue' => false, 'owner' => prr_user_brief( $x->recruiter_id ) );
    }

    $latest = $wpdb->get_results( "SELECT * FROM $A WHERE source = 'website' $live $mineA ORDER BY id DESC LIMIT 5" );
    $latest = array_map( function( $row ) { $a = prr_format_applicant( $row ); $a['possible_duplicate'] = (bool) $row->duplicate_of && ! $row->not_duplicate; return $a; }, $latest );

    return prr_ok( array(
        'days' => $days,
        'kpi' => array( 'total' => $total, 'new' => $new, 'awaiting' => $ready, 'submitted' => $subm, 'interviews' => $intv, 'placed' => $placed,
                        'conversion' => $total ? round( $u_pla / $total * 100, 1 ) : 0,
                        'leads_new' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $L WHERE status = 'new' $mineL" ), 'leads_queue' => count( prr_lead_queue_ids() ) ),
        'funnel' => array( array( 'Registered', $total ), array( 'Qualified', $qualified ), array( 'Submitted', $u_sub ), array( 'Interviewed', $u_int ), array( 'Placed', $u_pla ) ),
        'by_position' => $by_pos, 'by_client' => $by_client, 'follow_ups' => $follow, 'latest_website' => $latest,
    ) );
}

function prr_report_range( WP_REST_Request $r ) {
    $from = prr_date_or_null( $r['from'] ) ?: gmdate( 'Y-m-01' );
    $to   = prr_date_or_null( $r['to'] ) ?: current_time( 'Y-m-d' );
    return array( $from, $to );
}

function prr_api_reports( WP_REST_Request $r ) {
    global $wpdb;
    list( $from, $to ) = prr_report_range( $r );
    $A = prr_table( 'applicants' ); $S = prr_table( 'submissions' ); $L = prr_table( 'leads' ); $ACT = prr_table( 'activity' );
    $f = $from . ' 00:00:00'; $t = $to . ' 23:59:59';
    $team = array();
    foreach ( prr_team() as $u ) {
        $team[] = array( 'name' => $u['name'], 'role' => $u['role'],
            'applicants' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $A WHERE recruiter_id = %d AND created_at BETWEEN %s AND %s", $u['id'], $f, $t ) ),
            'calls'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $ACT WHERE user_id = %d AND kind = 'call' AND created_at BETWEEN %s AND %s", $u['id'], $f, $t ) ),
            'messages'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $ACT WHERE user_id = %d AND kind IN ('whatsapp','email') AND created_at BETWEEN %s AND %s", $u['id'], $f, $t ) ),
            'submissions'=> (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $S WHERE recruiter_id = %d AND submitted_on BETWEEN %s AND %s", $u['id'], $from, $to ) ),
            'placements' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $S WHERE recruiter_id = %d AND status = 'placed' AND updated_at BETWEEN %s AND %s", $u['id'], $f, $t ) ),
        );
    }
    $sources = $wpdb->get_results( $wpdb->prepare( "SELECT source, COUNT(*) n, SUM(status='placed') placed FROM $A WHERE created_at BETWEEN %s AND %s AND anonymised_at IS NULL GROUP BY source ORDER BY n DESC", $f, $t ) );
    $src_labels = prr_applicant_sources();
    foreach ( $sources as $s ) $s->label = $src_labels[ $s->source ] ?? $s->source;
    $statuses = $wpdb->get_results( "SELECT status, COUNT(*) n FROM $A WHERE anonymised_at IS NULL GROUP BY status", OBJECT_K );
    $status_rows = array();
    foreach ( prr_applicant_statuses() as $k => $s ) $status_rows[] = array( 'label' => $s['label'], 'color' => $s['color'], 'n' => isset( $statuses[ $k ] ) ? (int) $statuses[ $k ]->n : 0 );
    $lead_rows = array();
    $lc = $wpdb->get_results( "SELECT status, COUNT(*) n FROM $L GROUP BY status", OBJECT_K );
    foreach ( prr_lead_statuses() as $k => $s ) $lead_rows[] = array( 'label' => $s['label'], 'color' => $s['color'], 'n' => isset( $lc[ $k ] ) ? (int) $lc[ $k ]->n : 0 );
    return prr_ok( array( 'from' => $from, 'to' => $to, 'team' => $team, 'sources' => $sources, 'statuses' => $status_rows, 'leads' => $lead_rows ) );
}

function prr_api_export( WP_REST_Request $r ) {
    global $wpdb;
    list( $from, $to ) = prr_report_range( $r );
    $f = $from . ' 00:00:00'; $t = $to . ' 23:59:59';
    $type = $r['type'];
    prr_audit( 'export', $type, 0, array( 'from' => $from, 'to' => $to ) );
    if ( $type === 'applicants' ) {
        $st = prr_applicant_statuses();
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . prr_table( 'applicants' ) . ' WHERE anonymised_at IS NULL AND created_at BETWEEN %s AND %s ORDER BY id', $f, $t ) );
        $out = array_map( function( $a ) use ( $st ) { $u = prr_user_brief( $a->recruiter_id );
            return array( $a->ref, $a->full_name, $a->email, $a->phone, $a->location, $a->position, $a->source, $st[ $a->status ]['label'] ?? $a->status, $u ? $u['name'] : '', $a->created_at ); }, $rows );
        prr_send_csv( "applicants_{$from}_{$to}.csv", array( 'Ref', 'Name', 'Email', 'Phone', 'Location', 'Position', 'Source', 'Status', 'Recruiter', 'Registered' ), $out );
    }
    if ( $type === 'leads' ) {
        $st = prr_lead_statuses();
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . prr_table( 'leads' ) . ' WHERE created_at BETWEEN %s AND %s ORDER BY id', $f, $t ) );
        $out = array_map( function( $l ) use ( $st ) { $u = prr_user_brief( $l->owner_id );
            return array( $l->ref, $l->contact_name, $l->company, $l->phone, $l->email, $l->country, $l->source, $st[ $l->status ]['label'] ?? $l->status, $u ? $u['name'] : '', $l->next_follow_up, $l->needs, $l->created_at ); }, $rows );
        prr_send_csv( "client_leads_{$from}_{$to}.csv", array( 'Ref', 'Contact', 'Company', 'Phone', 'Email', 'Country', 'Source', 'Status', 'Assigned', 'Next follow-up', 'Needs', 'Created' ), $out );
    }
    if ( $type === 'submissions' ) {
        $rows = prr_list_submissions( array( 'from' => $from, 'to' => $to ) );
        $out = array_map( function( $s ) { return array( $s['applicant']['ref'], $s['applicant']['name'], $s['client']['name'], $s['position'], $s['submitted_on'], $s['status']['label'], $s['recruiter']['name'] ?? '' ); }, $rows );
        prr_send_csv( "submissions_{$from}_{$to}.csv", array( 'Candidate ref', 'Candidate', 'Client', 'Position', 'Submitted', 'Client status', 'Recruiter' ), $out );
    }
    if ( $type === 'clients' ) {
        $rows = $wpdb->get_results( 'SELECT * FROM ' . prr_table( 'clients' ) . ' ORDER BY company_name' );
        $out = array_map( function( $c ) { $x = prr_format_client( $c ); return array( $c->ref, $c->company_name, $c->industry, $c->location, $c->contact_name, $c->contact_email, $c->contact_phone, $x['open_roles'], $x['submitted'], $x['placed'], $c->is_active ? 'Active' : 'Archived' ); }, $rows );
        prr_send_csv( 'clients.csv', array( 'Ref', 'Company', 'Industry', 'Location', 'Contact', 'Email', 'Phone', 'Open roles', 'Submitted', 'Placed', 'State' ), $out );
    }
    if ( $type === 'audit' && prr_can( 'manage_settings' ) ) {
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . prr_table( 'audit' ) . ' WHERE created_at BETWEEN %s AND %s ORDER BY id DESC', $f, $t ) );
        $out = array_map( function( $a ) { $u = prr_user_brief( $a->user_id ); return array( $a->created_at, $u ? $u['name'] : 'System', $a->action, $a->object_type, $a->object_id, $a->details, $a->ip ); }, $rows );
        prr_send_csv( "audit_{$from}_{$to}.csv", array( 'Time', 'User', 'Action', 'Record type', 'Record ID', 'Details', 'IP' ), $out );
    }
    return prr_err( 'Unknown export.', 404 );
}

function prr_api_notifications() {
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . prr_table( 'notifications' ) . ' WHERE user_id = %d ORDER BY id DESC LIMIT 40', get_current_user_id() ) );
    $unread = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . prr_table( 'notifications' ) . ' WHERE user_id = %d AND is_read = 0', get_current_user_id() ) );
    return prr_ok( array( 'unread' => $unread, 'items' => array_map( function( $n ) {
        return array( 'id' => (int) $n->id, 'kind' => $n->kind, 'title' => $n->title, 'body' => $n->body, 'object_type' => $n->object_type,
                      'object_id' => $n->object_id ? (int) $n->object_id : null, 'read' => (bool) $n->is_read, 'created_at' => $n->created_at );
    }, $rows ) ) );
}
function prr_api_notifications_read() {
    global $wpdb;
    $wpdb->update( prr_table( 'notifications' ), array( 'is_read' => 1 ), array( 'user_id' => get_current_user_id() ) );
    return prr_ok( array( 'ok' => true ) );
}
