<?php
/**
 * Email from the portal. Sending goes through wp_mail(), so the site's mail setup
 * (e.g. WP Mail SMTP connected to Microsoft 365 or Google Workspace) is used.
 * Every email is saved on the record it was sent from.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function prr_list_messages( $object_type, $object_id ) {
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . prr_table( 'messages' ) . ' WHERE object_type = %s AND object_id = %d ORDER BY id DESC LIMIT 200', $object_type, $object_id ) );
    return array_map( 'prr_format_message', $rows );
}
function prr_format_message( $m ) {
    $u = prr_user_brief( $m->sent_by );
    return array( 'id' => (int) $m->id, 'channel' => $m->channel, 'direction' => $m->direction, 'to' => $m->to_addr,
        'subject' => $m->subject, 'body' => $m->body, 'template' => $m->template, 'status' => $m->status, 'error' => $m->error,
        'by' => $u ? $u['name'] : ( $m->direction === 'in' ? 'Contact' : 'System' ), 'created_at' => $m->created_at,
        'object_type' => $m->object_type, 'object_id' => $m->object_id ? (int) $m->object_id : null );
}

/** Placeholder values for templates from any combination of applicant / client / lead. */
function prr_template_vars( $applicant_id = 0, $client_id = 0, $lead_id = 0, $position = '' ) {
    $g = prr_get_general();
    $me = wp_get_current_user();
    $v = array( 'company' => $g['company_name'], 'recruiter' => $me->display_name, 'candidate' => '', 'position' => $position,
                'contact' => 'Hiring Manager', 'skills' => '', 'experience' => '', 'ref' => '', 'name' => '' );
    if ( $applicant_id && ( $a = prr_get_applicant_row( $applicant_id ) ) ) {
        $v['candidate'] = $a->full_name; $v['position'] = $position ?: $a->position; $v['skills'] = str_replace( "\n", ', ', (string) $a->skills );
        $v['experience'] = (string) $a->experience; $v['ref'] = $a->ref;
    }
    if ( $client_id && ( $c = prr_get_client_row( $client_id ) ) ) { if ( $c->contact_name ) $v['contact'] = $c->contact_name; }
    if ( $lead_id && ( $l = prr_get_lead_row( $lead_id ) ) ) { if ( $l->contact_name ) { $v['contact'] = $l->contact_name; $v['name'] = ' ' . $l->contact_name; } }
    return $v;
}

/**
 * Send and log. $attach_doc_ids are prr_documents IDs; they are copied to temp files
 * with their original names so the recipient sees "Reyes_CV.pdf", not a random name.
 */
function prr_send_email( $object_type, $object_id, $to, $subject, $body, $template = '', $cc = '', $attach_doc_ids = array() ) {
    global $wpdb;
    $to = sanitize_email( $to );
    if ( ! is_email( $to ) ) return prr_err( 'Enter a valid email address.' );
    $g = prr_get_general();
    $headers = array( 'Content-Type: text/html; charset=UTF-8' );
    if ( $g['from_email'] ) $headers[] = 'From: ' . $g['from_name'] . ' <' . $g['from_email'] . '>';
    foreach ( array_filter( array_map( 'trim', explode( ',', (string) $cc ) ) ) as $c ) if ( is_email( $c ) ) $headers[] = 'Cc: ' . $c;

    $tmp = array(); $names = array();
    foreach ( array_map( 'intval', (array) $attach_doc_ids ) as $did ) {
        $d = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . prr_table( 'documents' ) . ' WHERE id = %d', $did ) );
        if ( ! $d || ! prr_can_open_document( $d ) ) continue;
        $src = prr_private_dir() . '/' . $d->stored_path;
        if ( ! is_readable( $src ) ) continue;
        $dir = trailingslashit( get_temp_dir() ) . 'prr-mail-' . wp_generate_password( 8, false );
        wp_mkdir_p( $dir );
        $dst = $dir . '/' . sanitize_file_name( $d->file_name );
        if ( @copy( $src, $dst ) ) { $tmp[] = $dst; $names[] = $d->file_name; }
    }

    $html = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.6;color:#1c1f33">' . nl2br( esc_html( $body ) ) . '</div>';
    $error = '';
    $capture = function( $e ) use ( &$error ) { $error = $e->get_error_message(); };
    add_action( 'wp_mail_failed', $capture );
    $sent = wp_mail( $to, $subject, $html, $headers, $tmp );
    remove_action( 'wp_mail_failed', $capture );
    foreach ( $tmp as $f ) { @unlink( $f ); @rmdir( dirname( $f ) ); }

    $wpdb->insert( prr_table( 'messages' ), array(
        'channel' => 'email', 'direction' => 'out', 'object_type' => $object_type, 'object_id' => (int) $object_id ?: null,
        'to_addr' => $to . ( $cc ? ', cc: ' . $cc : '' ), 'subject' => $subject, 'body' => $body, 'template' => $template,
        'status' => $sent ? 'sent' : 'failed', 'error' => $sent ? null : ( $error ?: 'The mail server rejected the message.' ),
        'sent_by' => get_current_user_id() ?: null, 'created_at' => prr_now(),
    ) );
    if ( $object_type && $object_id ) {
        prr_log( $object_type, $object_id, 'email', "Subject: $subject" . ( $names ? ' [' . implode( ', ', $names ) . ' attached]' : '' ) . ( $sent ? ' · Sent' : ' · Failed: ' . ( $error ?: 'mail server error' ) ),
                 array( 'to' => $to, 'status' => $sent ? 'sent' : 'failed' ) );
    }
    if ( ! $sent ) return prr_err( 'The email could not be sent: ' . ( $error ?: 'check the site mail settings.' ), 502 );
    return array( 'sent' => true, 'to' => $to, 'attachments' => $names );
}

/** Email the submission template + candidate CV to the client contact. */
function prr_send_candidate_profile( $submission_id, $override = array() ) {
    global $wpdb;
    $s = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . prr_table( 'submissions' ) . ' WHERE id = %d', $submission_id ) );
    if ( ! $s ) return prr_err( 'Submission not found.', 404 );
    $c = prr_get_client_row( $s->client_id );
    $tpl = prr_email_templates()['submission'] ?? array( 'subject' => 'Candidate submission', 'body' => '' );
    $vars = prr_template_vars( $s->applicant_id, $s->client_id, 0, $s->position );
    $subject = ! empty( $override['subject'] ) ? sanitize_text_field( $override['subject'] ) : prr_fill( $tpl['subject'], $vars );
    $body    = ! empty( $override['body'] ) ? sanitize_textarea_field( $override['body'] ) : prr_fill( $tpl['body'], $vars );
    $docs = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . prr_table( 'documents' ) . " WHERE object_type = 'applicant' AND object_id = %d AND doc_type = 'cv' ORDER BY id DESC LIMIT 1", $s->applicant_id ) );
    $res = prr_send_email( 'client', $s->client_id, $c->contact_email, $subject, $body, 'submission', '', $docs );
    if ( ! is_wp_error( $res ) ) prr_log( 'applicant', $s->applicant_id, 'email', "Profile emailed to {$c->company_name} ({$c->contact_email})." );
    return is_wp_error( $res ) ? array( 'sent' => false, 'error' => $res->get_error_message() ) : $res;
}

add_action( 'rest_api_init', function() {
    $ns = PRR_API_NS;
    register_rest_route( $ns, '/email/render', array( 'methods' => 'POST', 'callback' => 'prr_api_email_render', 'permission_callback' => prr_gate( 'send_email' ) ) );
    register_rest_route( $ns, '/email/send',   array( 'methods' => 'POST', 'callback' => 'prr_api_email_send',   'permission_callback' => prr_gate( 'send_email' ) ) );
    register_rest_route( $ns, '/messages',     array( 'methods' => 'GET',  'callback' => 'prr_api_messages',     'permission_callback' => prr_gate() ) );
} );

function prr_api_email_render( WP_REST_Request $r ) {
    $d = (array) $r->get_json_params();
    $tpls = prr_email_templates();
    $t = $tpls[ $d['template'] ?? '' ] ?? null;
    if ( ! $t ) return prr_err( 'Template not found.' );
    $vars = prr_template_vars( (int) ( $d['applicant_id'] ?? 0 ), (int) ( $d['client_id'] ?? 0 ), (int) ( $d['lead_id'] ?? 0 ), sanitize_text_field( $d['position'] ?? '' ) );
    return prr_ok( array( 'subject' => prr_fill( $t['subject'], $vars ), 'body' => prr_fill( $t['body'], $vars ) ) );
}

function prr_api_email_send( WP_REST_Request $r ) {
    $d = (array) $r->get_json_params();
    $type = sanitize_key( $d['object_type'] ?? '' ); $id = (int) ( $d['object_id'] ?? 0 );
    if ( $type && ! prr_valid_object( $type, $id ) ) return prr_err( 'Record not found.', 404 );
    $res = prr_send_email( $type, $id, $d['to'] ?? '', sanitize_text_field( $d['subject'] ?? '' ), sanitize_textarea_field( $d['body'] ?? '' ),
        sanitize_key( $d['template'] ?? '' ), sanitize_text_field( $d['cc'] ?? '' ), (array) ( $d['attach'] ?? array() ) );
    if ( is_wp_error( $res ) ) return $res;
    // A lead that was emailed counts as contacted.
    if ( $type === 'lead' && ( $l = prr_get_lead_row( $id ) ) ) {
        if ( ! $l->email && is_email( $d['to'] ?? '' ) ) prr_lead_apply( $l, array( 'email' => $d['to'] ) );
        if ( in_array( $l->status, array( 'new', 'not_answering' ), true ) ) prr_lead_apply( prr_get_lead_row( $id ), array( 'status' => 'contacted' ) );
    }
    return prr_ok( $res );
}

function prr_api_messages( WP_REST_Request $r ) {
    global $wpdb;
    if ( $r['object_type'] ) return prr_ok( prr_list_messages( sanitize_key( $r['object_type'] ), (int) $r['object_id'] ) );
    $where = '1=1'; $args = array();
    if ( $r['channel'] ) { $where .= ' AND channel = %s'; $args[] = sanitize_key( $r['channel'] ); }
    if ( ! prr_sees_all() ) { $where .= ' AND sent_by = %d'; $args[] = get_current_user_id(); }
    $sql = 'SELECT * FROM ' . prr_table( 'messages' ) . " WHERE $where ORDER BY id DESC LIMIT 300";
    $rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql );
    return prr_ok( array_map( 'prr_format_message', $rows ) );
}
