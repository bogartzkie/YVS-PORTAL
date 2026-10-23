<?php
/**
 * Website forms → CRM.
 *
 * Ways in:
 *  1. Shortcodes [prr_application_form] and [prr_employer_form] (built-in forms).
 *  2. Contact Form 7 or Elementor Pro forms already on the site (set the form ID in Settings → Website forms).
 *  3. A token-protected webhook for any other form tool: POST /wp-json/prr/v1/intake/webhook?token=…
 *
 * Every submission is written to the intake log BEFORE processing, with its files already
 * saved, so a failure can be retried from Settings without losing the candidate's data.
 * Steps: validate → register → save details and files → New status → notify team → duplicate check → history.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/** Map arbitrary form field names onto CRM fields. */
function prr_intake_map( $form, $raw ) {
    $s = prr_intake_settings();
    $norm = array();
    foreach ( (array) $raw as $k => $v ) {
        if ( is_array( $v ) ) $v = implode( ', ', array_map( 'strval', $v ) );
        $key = strtolower( preg_replace( '/^(your[-_])/', '', trim( (string) $k ) ) );
        $key = preg_replace( '/[^a-z0-9]+/', '_', $key );
        $norm[ $key ] = trim( (string) $v );
    }
    $aliases = array(
        'full_name'    => array( 'full_name', 'name', 'fullname', 'applicant_name', 'candidate_name' ),
        'contact_name' => array( 'contact_name', 'contact_person', 'name', 'full_name' ),
        'company'      => array( 'company', 'company_name', 'organization', 'organisation', 'employer' ),
        'email'        => array( 'email', 'email_address', 'e_mail' ),
        'phone'        => array( 'phone', 'mobile', 'phone_number', 'mobile_number', 'whatsapp', 'whatsapp_number', 'contact_number', 'tel' ),
        'location'     => array( 'location', 'country', 'current_location', 'city', 'current_country' ),
        'country'      => array( 'country', 'location' ),
        'position'     => array( 'position', 'position_applied', 'position_applied_for', 'job', 'job_title', 'vacancy', 'role', 'desired_position' ),
        'skills'       => array( 'skills', 'qualifications', 'skills_and_qualifications' ),
        'experience'   => array( 'experience', 'work_experience', 'years_of_experience', 'experience_years' ),
        'notes'        => array( 'message', 'notes', 'comments', 'cover_letter', 'additional_information' ),
        'needs'        => array( 'positions_needed', 'requirements', 'needs', 'message', 'details' ),
        'headcount'    => array( 'headcount', 'number_of_staff', 'quantity', 'how_many' ),
        'consent'      => array( 'consent', 'privacy', 'acceptance', 'gdpr', 'agree' ),
    );
    $out = array(); $used = array();
    foreach ( (array) $s['field_map'] as $from => $to ) { // admin overrides first
        $fk = preg_replace( '/[^a-z0-9]+/', '_', strtolower( $from ) );
        if ( isset( $norm[ $fk ] ) && $norm[ $fk ] !== '' ) { $out[ $to ] = $norm[ $fk ]; $used[ $fk ] = true; }
    }
    foreach ( $aliases as $field => $keys ) {
        if ( isset( $out[ $field ] ) ) continue;
        foreach ( $keys as $k ) if ( isset( $norm[ $k ] ) && $norm[ $k ] !== '' ) { $out[ $field ] = $norm[ $k ]; $used[ $k ] = true; break; }
    }
    if ( empty( $out['full_name'] ) && ( ! empty( $norm['first_name'] ) || ! empty( $norm['last_name'] ) ) ) {
        $out['full_name'] = trim( ( $norm['first_name'] ?? '' ) . ' ' . ( $norm['last_name'] ?? '' ) );
        $used['first_name'] = $used['last_name'] = true;
    }
    // Everything else is kept as custom fields so nothing the applicant typed is lost.
    $skip = array( 'g_recaptcha_response', '_wpcf7', '_wpcf7_version', '_wpcf7_locale', '_wpcf7_unit_tag', '_wpcf7_container_post', 'prr_hp', 'prr_ts', 'action', '_wpnonce', 'form', 'token', 'prr_form' );
    $custom = array();
    foreach ( $norm as $k => $v ) if ( ! isset( $used[ $k ] ) && $v !== '' && ! in_array( $k, $skip, true ) && strpos( $k, '_' ) !== 0 ) $custom[ $k ] = mb_substr( $v, 0, 1000 );
    if ( $custom ) $out['custom'] = $custom;
    return $out;
}

/**
 * Log, then process one submission.
 * $files: list of ['stored' => prr_store_upload() result, 'doc_type' => 'cv'|'passport'|…]
 */
function prr_intake_submit( $form, $raw, $files = array(), $source = 'website' ) {
    global $wpdb;
    $wpdb->insert( prr_table( 'intake_log' ), array( 'form' => $form, 'source' => $source, 'payload' => wp_json_encode( $raw ),
        'files' => wp_json_encode( $files ), 'status' => 'received', 'created_at' => prr_now() ) );
    $log_id = (int) $wpdb->insert_id;
    return prr_intake_process( $log_id );
}

function prr_intake_process( $log_id ) {
    global $wpdb;
    $log = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . prr_table( 'intake_log' ) . ' WHERE id = %d', $log_id ) );
    if ( ! $log ) return prr_err( 'Log entry not found.', 404 );
    $raw = json_decode( (string) $log->payload, true ) ?: array();
    $files = json_decode( (string) $log->files, true ) ?: array();
    $d = prr_intake_map( $log->form, $raw );
    $fail = function( $msg ) use ( $wpdb, $log_id ) {
        $wpdb->update( prr_table( 'intake_log' ), array( 'status' => 'failed', 'error' => $msg ), array( 'id' => $log_id ) );
        prr_notify_staff( 'intake_failed', 'Website form submission could not be saved', $msg . ' Retry it from Settings → Website forms.' );
        return prr_err( $msg );
    };
    try {
        if ( $log->form === 'employer' ) {
            if ( empty( $d['phone'] ) && empty( $d['email'] ) ) return $fail( 'No phone number or email in the submission.' );
            $lead = array( 'contact_name' => $d['contact_name'] ?? '', 'company' => $d['company'] ?? '', 'phone' => $d['phone'] ?? '',
                'email' => $d['email'] ?? '', 'country' => $d['country'] ?? '', 'needs' => $d['needs'] ?? '', 'headcount' => $d['headcount'] ?? '', 'source' => 'website' );
            $id = prr_insert_lead( $lead, 'website', 'Registered through the website Clients page form.' );
            $dup = null;
            if ( is_wp_error( $id ) && $id->get_error_data() && ! empty( $id->get_error_data()['lead_id'] ) ) {
                // Known number: add the enquiry to the existing lead instead of failing.
                $id = (int) $id->get_error_data()['lead_id']; $dup = $id;
                prr_log( 'lead', $id, 'comment', "New website enquiry from this number:\n" . trim( ( $d['company'] ?? '' ) . "\n" . ( $d['needs'] ?? '' ) ), array( 'by' => 'Website form' ), 0 );
            }
            if ( is_wp_error( $id ) ) return $fail( $id->get_error_message() );
            if ( ! empty( $d['custom'] ) ) prr_log( 'lead', $id, 'comment', "Other form answers:\n" . prr_intake_custom_text( $d['custom'] ), array( 'by' => 'Website form' ), 0 );
            foreach ( $files as $f ) prr_add_document( 'lead', $id, $f['stored'], $f['doc_type'] ?? 'other' );
            $wpdb->update( prr_table( 'intake_log' ), array( 'status' => $dup ? 'duplicate' : 'saved', 'object_id' => $id, 'duplicate_of' => $dup, 'error' => null ), array( 'id' => $log_id ) );
            $l = prr_get_lead_row( $id );
            prr_notify_staff( 'new_lead', 'New client lead from the website', trim( ( $l->company ?: $l->contact_name ) . ' · ' . $l->phone, ' ·' ), 'lead', $id );
            prr_intake_email_team( 'New client lead from the website', $l->company . ' ' . $l->contact_name . ' ' . $l->phone . "\n" . $l->needs, 'lead', $id );
            return $id;
        }

        // Applicant form.
        if ( empty( $d['full_name'] ) ) return $fail( 'The submission has no name.' );
        if ( empty( $d['email'] ) && empty( $d['phone'] ) ) return $fail( 'The submission has no email or phone number.' );
        if ( ! empty( $d['email'] ) && ! is_email( $d['email'] ) ) return $fail( 'The email address is not valid: ' . $d['email'] );
        $d['status'] = 'new'; $d['source'] = 'website'; $d['consent'] = ! empty( $d['consent'] );
        $id = prr_insert_applicant( $d, 'website', 'Registered through the website application form.' );
        if ( is_wp_error( $id ) ) return $fail( $id->get_error_message() );
        foreach ( $files as $f ) prr_add_document( 'applicant', $id, $f['stored'], $f['doc_type'] ?? 'cv' );
        $a = prr_get_applicant_row( $id );
        $wpdb->update( prr_table( 'intake_log' ), array( 'status' => $a->duplicate_of ? 'duplicate' : 'saved', 'object_id' => $id, 'duplicate_of' => $a->duplicate_of, 'error' => null ), array( 'id' => $log_id ) );
        $title = 'New applicant from the website';
        $body  = $a->full_name . ' · ' . ( $a->position ?: 'position not given' ) . ( $a->duplicate_of ? ' · possible duplicate' : '' );
        prr_notify_staff( 'new_applicant', $title, $body, 'applicant', $id );
        prr_intake_email_team( $title, $body, 'applicant', $id );
        $s = prr_intake_settings();
        if ( $s['send_ack'] && $a->email ) {
            $tpl = prr_email_templates()['received'] ?? null;
            if ( $tpl ) {
                $vars = prr_template_vars( $id );
                prr_send_email( 'applicant', $id, $a->email, prr_fill( $tpl['subject'], $vars ), prr_fill( $tpl['body'], $vars ), 'received' );
            }
        }
        return $id;
    } catch ( Throwable $e ) {
        return $fail( 'Unexpected error: ' . $e->getMessage() );
    }
}

function prr_intake_custom_text( $custom ) {
    $lines = array();
    foreach ( (array) $custom as $k => $v ) $lines[] = ucfirst( str_replace( '_', ' ', $k ) ) . ': ' . $v;
    return implode( "\n", $lines );
}

function prr_intake_email_team( $title, $body, $type, $id ) {
    $to = array_filter( array_map( 'trim', explode( ',', (string) prr_get_general()['notify_emails'] ) ), 'is_email' );
    if ( ! $to ) return;
    $url = home_url( '/' . PRR_PORTAL_SLUG . '/#' . $type . '-' . $id );
    wp_mail( $to, $title, $body . "\n\nOpen in the CRM: " . $url );
}

/* ── 1. Built-in shortcode forms ─────────────────────────── */
add_shortcode( 'prr_application_form', function() { return prr_render_public_form( 'applicant' ); } );
add_shortcode( 'prr_employer_form',    function() { return prr_render_public_form( 'employer' ); } );

function prr_render_public_form( $form ) {
    $sent = isset( $_GET['prr_sent'] ) && $_GET['prr_sent'] === $form;
    $err  = isset( $_GET['prr_err'] ) ? sanitize_text_field( wp_unslash( $_GET['prr_err'] ) ) : '';
    ob_start(); ?>
<div class="prr-form-wrap">
<?php if ( $sent ) : ?>
  <p class="prr-form-ok" style="padding:14px;border-radius:8px;background:#ddf2e6;color:#1d7a4f"><?php echo $form === 'employer' ? 'Thank you. Our team will contact you shortly.' : 'Thank you for applying. We have received your application and will contact you soon.'; ?></p>
<?php else : ?>
  <?php if ( $err ) : ?><p style="padding:12px;border-radius:8px;background:#f9e1e1;color:#b63a3a"><?php echo esc_html( $err ); ?></p><?php endif; ?>
  <form class="prr-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:grid;gap:12px;max-width:640px">
    <input type="hidden" name="action" value="prr_public_form">
    <input type="hidden" name="prr_form" value="<?php echo esc_attr( $form ); ?>">
    <input type="hidden" name="prr_ts" value="<?php echo esc_attr( time() ); ?>">
    <input type="hidden" name="prr_back" value="<?php echo esc_url( get_permalink() ); ?>">
    <div style="position:absolute;left:-9999px" aria-hidden="true"><label>Leave empty <input type="text" name="prr_hp" tabindex="-1" autocomplete="off"></label></div>
    <?php if ( $form === 'employer' ) : ?>
      <label>Company name *<br><input type="text" name="company" required style="width:100%"></label>
      <label>Contact person *<br><input type="text" name="contact_name" required style="width:100%"></label>
      <label>Phone / WhatsApp *<br><input type="tel" name="phone" required style="width:100%"></label>
      <label>Email<br><input type="email" name="email" style="width:100%"></label>
      <label>Country<br><input type="text" name="country" style="width:100%"></label>
      <label>Positions and headcount you need<br><textarea name="needs" rows="4" style="width:100%" placeholder="e.g. Housekeeping Attendant x 10"></textarea></label>
    <?php else : ?>
      <label>Full name *<br><input type="text" name="full_name" required style="width:100%"></label>
      <label>Email *<br><input type="email" name="email" required style="width:100%"></label>
      <label>Phone / WhatsApp *<br><input type="tel" name="phone" required style="width:100%"></label>
      <label>Current location / country *<br><input type="text" name="location" required style="width:100%"></label>
      <label>Position applied for *<br><input type="text" name="position" required style="width:100%"></label>
      <label>Skills and qualifications<br><textarea name="skills" rows="3" style="width:100%"></textarea></label>
      <label>Work experience<br><textarea name="experience" rows="3" style="width:100%"></textarea></label>
      <label>CV / resume (PDF or Word, max 20 MB) *<br><input type="file" name="cv" required accept=".pdf,.doc,.docx"></label>
      <label>Passport copy (optional)<br><input type="file" name="passport" accept=".pdf,.jpg,.jpeg,.png"></label>
    <?php endif; ?>
    <label><input type="checkbox" name="consent" value="1" required> I agree that <?php echo esc_html( prr_get_general()['company_name'] ); ?> may store my details to process my <?php echo $form === 'employer' ? 'enquiry' : 'application'; ?> and contact me by phone, email or WhatsApp.</label>
    <button type="submit">Submit</button>
  </form>
<?php endif; ?>
</div>
    <?php
    return ob_get_clean();
}

add_action( 'admin_post_nopriv_prr_public_form', 'prr_handle_public_form' );
add_action( 'admin_post_prr_public_form', 'prr_handle_public_form' );
function prr_handle_public_form() {
    $back = esc_url_raw( wp_unslash( $_POST['prr_back'] ?? home_url( '/' ) ) );
    $back = wp_validate_redirect( $back, home_url( '/' ) );
    $form = ( $_POST['prr_form'] ?? '' ) === 'employer' ? 'employer' : 'applicant';
    $go = function( $args ) use ( $back ) { wp_safe_redirect( add_query_arg( $args, remove_query_arg( array( 'prr_sent', 'prr_err' ), $back ) ) ); exit; };
    // Spam checks: honeypot, too-fast submit, rate limit.
    if ( ! empty( $_POST['prr_hp'] ) || ( time() - (int) ( $_POST['prr_ts'] ?? 0 ) ) < 3 ) $go( array( 'prr_sent' => $form ) );
    if ( is_wp_error( prr_rate_limit( 'public_form', 10, HOUR_IN_SECONDS ) ) ) $go( array( 'prr_err' => 'Too many submissions from your connection. Try again later.' ) );

    $raw = wp_unslash( $_POST );
    unset( $raw['prr_hp'], $raw['prr_ts'], $raw['prr_back'], $raw['action'] );
    $files = array();
    foreach ( array( 'cv' => 'cv', 'passport' => 'passport', 'file' => 'other' ) as $field => $type ) {
        if ( empty( $_FILES[ $field ]['name'] ) ) continue;
        $stored = prr_store_upload( $_FILES[ $field ] );
        if ( is_wp_error( $stored ) ) $go( array( 'prr_err' => $stored->get_error_message() ) );
        $files[] = array( 'stored' => $stored, 'doc_type' => $type );
    }
    $res = prr_intake_submit( $form, $raw, $files, 'website_form' );
    // The data is safely logged even if processing failed, so the visitor still gets a thank-you.
    $go( array( 'prr_sent' => $form ) );
}

/* ── 2. Contact Form 7 and Elementor Pro ─────────────────── */
add_action( 'wpcf7_before_send_mail', function( $contact_form ) {
    if ( ! class_exists( 'WPCF7_Submission' ) ) return;
    $s = prr_intake_settings();
    $fid = (string) $contact_form->id();
    $form = $fid === (string) $s['cf7_applicant'] ? 'applicant' : ( $fid === (string) $s['cf7_employer'] ? 'employer' : '' );
    if ( ! $form ) return;
    $sub = WPCF7_Submission::get_instance();
    if ( ! $sub ) return;
    $files = array();
    foreach ( (array) $sub->uploaded_files() as $field => $paths ) {
        foreach ( (array) $paths as $path ) {
            $stored = prr_store_upload( array( 'tmp_name' => $path, 'name' => basename( $path ), 'size' => @filesize( $path ), 'error' => 0 ) );
            if ( ! is_wp_error( $stored ) ) $files[] = array( 'stored' => $stored, 'doc_type' => stripos( $field, 'passport' ) !== false ? 'passport' : ( $form === 'applicant' ? 'cv' : 'other' ) );
        }
    }
    prr_intake_submit( $form, $sub->get_posted_data(), $files, 'contact_form_7' );
} );

add_action( 'elementor_pro/forms/new_record', function( $record ) {
    $s = prr_intake_settings();
    $name = (string) $record->get_form_settings( 'form_name' );
    $id   = (string) $record->get_form_settings( 'id' );
    $form = in_array( (string) $s['cf7_applicant'], array( $name, $id ), true ) ? 'applicant'
          : ( in_array( (string) $s['cf7_employer'], array( $name, $id ), true ) ? 'employer' : '' );
    if ( ! $form ) return;
    $raw = array(); $files = array();
    foreach ( (array) $record->get( 'fields' ) as $key => $f ) {
        if ( ( $f['type'] ?? '' ) === 'upload' && ! empty( $f['raw_value'] ) ) {
            foreach ( (array) explode( ',', $f['raw_value'] ) as $path ) {
                $path = trim( $path );
                if ( ! file_exists( $path ) ) continue;
                $stored = prr_store_upload( array( 'tmp_name' => $path, 'name' => basename( $path ), 'size' => filesize( $path ), 'error' => 0 ) );
                if ( ! is_wp_error( $stored ) ) $files[] = array( 'stored' => $stored, 'doc_type' => stripos( $key, 'passport' ) !== false ? 'passport' : 'cv' );
            }
            continue;
        }
        $raw[ $f['title'] ?: $key ] = $f['value'];
        $raw[ $key ] = $f['value'];
    }
    prr_intake_submit( $form, $raw, $files, 'elementor' );
} );

/* ── 3. Webhook for other form tools ─────────────────────── */
add_action( 'rest_api_init', function() {
    register_rest_route( PRR_API_NS, '/intake/webhook', array( 'methods' => 'POST', 'callback' => 'prr_api_intake_webhook', 'permission_callback' => '__return_true' ) );
} );
function prr_api_intake_webhook( WP_REST_Request $r ) {
    $s = prr_intake_settings();
    $token = (string) ( $r->get_header( 'x_prr_token' ) ?: $r['token'] );
    if ( ! hash_equals( (string) $s['token'], $token ) ) return prr_err( 'Invalid token.', 403 );
    $ok = prr_rate_limit( 'intake_webhook', 120, 60 );
    if ( is_wp_error( $ok ) ) return $ok;
    $data = $r->get_json_params() ?: $r->get_body_params();
    $form = ( $data['form'] ?? $r['form'] ) === 'employer' ? 'employer' : 'applicant';
    $files = array();
    foreach ( (array) $r->get_file_params() as $field => $file ) {
        $stored = prr_store_upload( $file );
        if ( ! is_wp_error( $stored ) ) $files[] = array( 'stored' => $stored, 'doc_type' => stripos( $field, 'passport' ) !== false ? 'passport' : 'cv' );
    }
    // Files given as URLs (e.g. Zapier) are downloaded.
    foreach ( array( 'cv_url' => 'cv', 'resume_url' => 'cv', 'passport_url' => 'passport' ) as $k => $type ) {
        if ( empty( $data[ $k ] ) || ! wp_http_validate_url( $data[ $k ] ) ) continue;
        if ( ! function_exists( 'download_url' ) ) require_once ABSPATH . 'wp-admin/includes/file.php';
        $tmp = download_url( $data[ $k ], 30 );
        if ( is_wp_error( $tmp ) ) continue;
        $stored = prr_store_upload( array( 'tmp_name' => $tmp, 'name' => basename( wp_parse_url( $data[ $k ], PHP_URL_PATH ) ), 'size' => filesize( $tmp ), 'error' => 0 ) );
        @unlink( $tmp );
        if ( ! is_wp_error( $stored ) ) $files[] = array( 'stored' => $stored, 'doc_type' => $type );
        unset( $data[ $k ] );
    }
    $id = prr_intake_submit( $form, $data, $files, 'webhook' );
    return is_wp_error( $id ) ? new WP_Error( 'prr_intake_failed', $id->get_error_message() . ' The submission was logged and can be retried.', array( 'status' => 422 ) ) : prr_ok( array( 'id' => $id ) );
}
