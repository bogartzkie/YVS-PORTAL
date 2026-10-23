<?php
/**
 * WhatsApp.
 *
 * Two modes (Settings → WhatsApp):
 *  - click: opens WhatsApp on the staff member's phone/computer (wa.me link) and records
 *    the message on the record. No Meta account needed; replies are not captured.
 *  - cloud: sends through the WhatsApp Cloud API from the company number. Replies and
 *    delivery receipts arrive at the webhook and are saved on the matching lead/applicant.
 *
 * Meta rules: the first message to a contact (or any message more than 24 hours after
 * their last reply) must be an approved template. Inside the 24-hour window free text is allowed.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function() {
    $ns = PRR_API_NS;
    register_rest_route( $ns, '/whatsapp/send', array( 'methods' => 'POST', 'callback' => 'prr_api_wa_send', 'permission_callback' => prr_gate( 'send_whatsapp' ) ) );
    register_rest_route( $ns, '/whatsapp/log',  array( 'methods' => 'POST', 'callback' => 'prr_api_wa_log',  'permission_callback' => prr_gate( 'send_whatsapp' ) ) );
    register_rest_route( $ns, '/whatsapp/window', array( 'methods' => 'GET', 'callback' => 'prr_api_wa_window', 'permission_callback' => prr_gate( 'send_whatsapp' ) ) );
    // Public endpoint for Meta. GET = verification handshake, POST = events (signature-checked).
    register_rest_route( $ns, '/whatsapp/webhook', array(
        array( 'methods' => 'GET',  'callback' => 'prr_wa_webhook_verify',  'permission_callback' => '__return_true' ),
        array( 'methods' => 'POST', 'callback' => 'prr_wa_webhook_receive', 'permission_callback' => '__return_true' ),
    ) );
} );

/** Phone of the record we are messaging. */
function prr_wa_target( $type, $id ) {
    if ( $type === 'lead' && ( $l = prr_get_lead_row( $id ) ) )           return array( $l->phone_norm, $l->contact_name );
    if ( $type === 'applicant' && ( $a = prr_get_applicant_row( $id ) ) ) return array( $a->phone_norm, $a->full_name );
    if ( $type === 'client' && ( $c = prr_get_client_row( $id ) ) )       return array( prr_norm_phone( $c->contact_phone ), $c->contact_name );
    return array( '', '' );
}

/** Is the contact inside the 24-hour customer-service window (they wrote to us recently)? */
function prr_wa_in_window( $phone_norm ) {
    global $wpdb;
    $last = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(created_at) FROM ' . prr_table( 'messages' ) . " WHERE channel = 'whatsapp' AND direction = 'in' AND to_addr = %s", $phone_norm ) );
    return $last && ( strtotime( current_time( 'mysql' ) ) - strtotime( $last ) ) < DAY_IN_SECONDS;
}
function prr_api_wa_window( WP_REST_Request $r ) {
    list( $phone ) = prr_wa_target( sanitize_key( $r['object_type'] ), (int) $r['object_id'] );
    $s = prr_whatsapp_settings();
    return prr_ok( array( 'mode' => $s['mode'], 'phone' => $phone, 'open' => $phone ? prr_wa_in_window( $phone ) : false ) );
}

function prr_wa_after_send( $type, $id, $body, $status, $msg_db_id, $mode ) {
    prr_log( $type, $id, 'whatsapp', $body, array( 'msg_id' => $msg_db_id, 'mode' => $mode, 'status' => $status ) );
    if ( $type === 'lead' && ( $l = prr_get_lead_row( $id ) ) && in_array( $l->status, array( 'new', 'not_answering', 'call_back' ), true ) ) {
        prr_lead_apply( $l, array( 'status' => 'contacted' ) );
    }
}

/** Click-to-chat: the browser opened wa.me; record what was sent. */
function prr_api_wa_log( WP_REST_Request $r ) {
    global $wpdb;
    $d = (array) $r->get_json_params();
    $type = sanitize_key( $d['object_type'] ?? '' ); $id = (int) ( $d['object_id'] ?? 0 );
    if ( ! prr_valid_object( $type, $id ) ) return prr_err( 'Record not found.', 404 );
    list( $phone ) = prr_wa_target( $type, $id );
    $body = sanitize_textarea_field( $d['body'] ?? '' );
    $wpdb->insert( prr_table( 'messages' ), array( 'channel' => 'whatsapp', 'direction' => 'out', 'object_type' => $type, 'object_id' => $id,
        'to_addr' => $phone, 'body' => $body, 'template' => sanitize_key( $d['template'] ?? '' ), 'status' => 'opened_app',
        'sent_by' => get_current_user_id(), 'created_at' => prr_now() ) );
    prr_wa_after_send( $type, $id, $body . ' · Opened in WhatsApp', 'opened_app', (int) $wpdb->insert_id, 'click' );
    return prr_ok( array( 'logged' => true ) );
}

/** Cloud API send. Uses the template unless the 24-hour window is open and free text was chosen. */
function prr_api_wa_send( WP_REST_Request $r ) {
    global $wpdb;
    $s = prr_whatsapp_settings();
    if ( $s['mode'] !== 'cloud' || ! $s['phone_number_id'] || ! $s['access_token'] ) {
        return prr_err( 'WhatsApp Cloud API is not set up. Ask an administrator to add it in Settings → WhatsApp.' );
    }
    $d = (array) $r->get_json_params();
    $type = sanitize_key( $d['object_type'] ?? '' ); $id = (int) ( $d['object_id'] ?? 0 );
    list( $phone ) = prr_wa_target( $type, $id );
    if ( ! $phone ) return prr_err( 'This record has no phone number.' );
    $body = sanitize_textarea_field( $d['body'] ?? '' );
    $tkey = sanitize_key( $d['template'] ?? '' );
    $tpls = prr_wa_templates();
    $free = ! empty( $d['free_text'] ) && prr_wa_in_window( $phone );

    if ( $free ) {
        $payload = array( 'messaging_product' => 'whatsapp', 'to' => $phone, 'type' => 'text', 'text' => array( 'body' => $body ) );
    } else {
        if ( empty( $tpls[ $tkey ]['name'] ) ) return prr_err( 'Pick an approved template. Free text is only allowed within 24 hours of the contact\'s last reply.' );
        $vars = prr_template_vars( $type === 'applicant' ? $id : 0, $type === 'client' ? $id : 0, $type === 'lead' ? $id : 0 );
        $params = array();
        foreach ( (array) ( $tpls[ $tkey ]['params'] ?? array() ) as $p ) $params[] = array( 'type' => 'text', 'text' => trim( (string) ( $vars[ $p ] ?? '' ) ) ?: '-' );
        $tpl = array( 'name' => $tpls[ $tkey ]['name'], 'language' => array( 'code' => $tpls[ $tkey ]['lang'] ?? 'en' ) );
        if ( $params ) $tpl['components'] = array( array( 'type' => 'body', 'parameters' => $params ) );
        $payload = array( 'messaging_product' => 'whatsapp', 'to' => $phone, 'type' => 'template', 'template' => $tpl );
    }
    $res = wp_remote_post( 'https://graph.facebook.com/' . rawurlencode( $s['api_version'] ) . '/' . rawurlencode( $s['phone_number_id'] ) . '/messages', array(
        'timeout' => 20, 'headers' => array( 'Authorization' => 'Bearer ' . $s['access_token'], 'Content-Type' => 'application/json' ),
        'body' => wp_json_encode( $payload ),
    ) );
    $code = is_wp_error( $res ) ? 0 : wp_remote_retrieve_response_code( $res );
    $json = is_wp_error( $res ) ? array() : json_decode( wp_remote_retrieve_body( $res ), true );
    $ok   = $code >= 200 && $code < 300 && ! empty( $json['messages'][0]['id'] );
    $err  = is_wp_error( $res ) ? $res->get_error_message() : ( $json['error']['message'] ?? ( $ok ? '' : "HTTP $code" ) );
    $wpdb->insert( prr_table( 'messages' ), array( 'channel' => 'whatsapp', 'direction' => 'out', 'object_type' => $type, 'object_id' => $id,
        'to_addr' => $phone, 'body' => $body, 'template' => $free ? '' : $tkey, 'status' => $ok ? 'sent' : 'failed',
        'provider_id' => $ok ? $json['messages'][0]['id'] : '', 'error' => $ok ? null : $err, 'sent_by' => get_current_user_id(), 'created_at' => prr_now() ) );
    $mid = (int) $wpdb->insert_id;
    if ( ! $ok ) {
        prr_log( $type, $id, 'whatsapp', $body . ' · Failed: ' . $err, array( 'msg_id' => $mid, 'status' => 'failed' ) );
        return prr_err( 'WhatsApp did not accept the message: ' . $err, 502 );
    }
    prr_wa_after_send( $type, $id, $body, 'sent', $mid, 'cloud' );
    return prr_ok( array( 'sent' => true, 'message_id' => $mid ) );
}

function prr_wa_webhook_verify( WP_REST_Request $r ) {
    $s = prr_whatsapp_settings();
    if ( $r['hub_mode'] === 'subscribe' && hash_equals( (string) $s['verify_token'], (string) $r['hub_verify_token'] ) ) {
        header( 'Content-Type: text/plain' );
        echo esc_html( (string) $r['hub_challenge'] );
        exit;
    }
    return new WP_Error( 'prr_forbidden', 'Verification failed.', array( 'status' => 403 ) );
}

function prr_wa_webhook_receive( WP_REST_Request $r ) {
    global $wpdb;
    $s = prr_whatsapp_settings();
    if ( $s['app_secret'] ) {
        $sig = (string) $r->get_header( 'x_hub_signature_256' );
        $calc = 'sha256=' . hash_hmac( 'sha256', $r->get_body(), $s['app_secret'] );
        if ( ! $sig || ! hash_equals( $calc, $sig ) ) return new WP_Error( 'prr_forbidden', 'Bad signature.', array( 'status' => 403 ) );
    }
    $data = json_decode( $r->get_body(), true );
    foreach ( (array) ( $data['entry'] ?? array() ) as $entry ) {
        foreach ( (array) ( $entry['changes'] ?? array() ) as $ch ) {
            $v = $ch['value'] ?? array();
            // Delivery receipts: sent → delivered → read / failed.
            foreach ( (array) ( $v['statuses'] ?? array() ) as $st ) {
                $wpdb->update( prr_table( 'messages' ), array( 'status' => sanitize_key( $st['status'] ?? '' ) ), array( 'provider_id' => sanitize_text_field( $st['id'] ?? '' ) ) );
            }
            // Inbound messages.
            $names = array();
            foreach ( (array) ( $v['contacts'] ?? array() ) as $c ) $names[ $c['wa_id'] ?? '' ] = $c['profile']['name'] ?? '';
            foreach ( (array) ( $v['messages'] ?? array() ) as $m ) {
                $from = preg_replace( '/\D/', '', (string) ( $m['from'] ?? '' ) );
                $text = $m['text']['body'] ?? ( $m['button']['text'] ?? ( '[' . ( $m['type'] ?? 'message' ) . ']' ) );
                prr_wa_store_inbound( $from, sanitize_textarea_field( $text ), sanitize_text_field( $m['id'] ?? '' ), sanitize_text_field( $names[ $m['from'] ?? '' ] ?? '' ) );
            }
        }
    }
    return prr_ok( array( 'ok' => true ) );
}

/** Match by phone to a lead, then an applicant; unknown numbers become new leads. */
function prr_wa_store_inbound( $from, $text, $wamid, $profile_name ) {
    global $wpdb;
    if ( $wamid && $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . prr_table( 'messages' ) . ' WHERE provider_id = %s', $wamid ) ) ) return; // retry from Meta
    $type = ''; $id = 0; $owner = 0; $label = '';
    if ( $l = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . prr_table( 'leads' ) . ' WHERE phone_norm = %s LIMIT 1', $from ) ) ) {
        $type = 'lead'; $id = (int) $l->id; $owner = (int) $l->owner_id; $label = $l->company ?: ( $l->contact_name ?: $l->phone );
    } elseif ( $a = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . prr_table( 'applicants' ) . ' WHERE phone_norm = %s AND anonymised_at IS NULL LIMIT 1', $from ) ) ) {
        $type = 'applicant'; $id = (int) $a->id; $owner = (int) $a->recruiter_id; $label = $a->full_name;
    } else {
        $id = prr_insert_lead( array( 'phone' => '+' . $from, 'contact_name' => $profile_name, 'source' => 'whatsapp' ), 'whatsapp', 'New lead from an inbound WhatsApp message.' );
        if ( is_wp_error( $id ) ) return;
        $type = 'lead'; $label = $profile_name ?: '+' . $from;
    }
    $wpdb->insert( prr_table( 'messages' ), array( 'channel' => 'whatsapp', 'direction' => 'in', 'object_type' => $type, 'object_id' => $id,
        'to_addr' => $from, 'body' => $text, 'status' => 'received', 'provider_id' => $wamid, 'created_at' => prr_now() ) );
    prr_log( $type, $id, 'wa_in', $text, array( 'by' => $profile_name ?: '+' . $from ), 0 );
    prr_notify_staff( 'whatsapp', 'WhatsApp reply from ' . $label, mb_substr( $text, 0, 140 ), $type, $id, $owner );
}
