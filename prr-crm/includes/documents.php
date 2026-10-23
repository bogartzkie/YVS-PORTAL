<?php
/**
 * Documents: CVs, passports, IDs, contracts, invoices.
 *
 * Files are saved under wp-content/uploads/prr-private/ with random names and a deny-all
 * .htaccess, and are only served through the REST API after a permission check. Every
 * download of a restricted document (passport, ID, visa, medical) is written to the audit log.
 * On Nginx hosts add:  location ^~ /wp-content/uploads/prr-private/ { deny all; }
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function prr_private_dir() {
    $up = wp_upload_dir( null, false );
    return trailingslashit( $up['basedir'] ) . 'prr-private';
}
function prr_ensure_private_dir() {
    $dir = prr_private_dir();
    if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );
    if ( ! file_exists( "$dir/.htaccess" ) ) {
        @file_put_contents( "$dir/.htaccess", "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
    }
    if ( ! file_exists( "$dir/index.php" ) ) @file_put_contents( "$dir/index.php", "<?php // Silence.\n" );
    return $dir;
}

function prr_allowed_mimes() {
    return array(
        'pdf' => 'application/pdf', 'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic',
    );
}
function prr_max_upload_bytes() { return (int) apply_filters( 'prr_max_upload_bytes', 20 * MB_IN_BYTES ); }

/** Save an uploaded file ($_FILES entry) privately. Returns [path, name, mime, size] or WP_Error. */
function prr_store_upload( $file ) {
    if ( empty( $file['tmp_name'] ) || ! empty( $file['error'] ) ) return prr_err( 'The file did not upload. Try again.' );
    if ( $file['size'] > prr_max_upload_bytes() ) return prr_err( 'The file is larger than 20 MB.' );
    $check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], prr_allowed_mimes() );
    if ( empty( $check['ext'] ) || empty( $check['type'] ) ) return prr_err( 'Only PDF, Word, Excel, JPG, PNG, WEBP or HEIC files are allowed.' );
    $dir = prr_ensure_private_dir() . '/' . gmdate( 'Y/m' );
    wp_mkdir_p( $dir );
    $stored = gmdate( 'Y/m' ) . '/' . wp_generate_password( 24, false ) . '.' . $check['ext'];
    $dest = prr_private_dir() . '/' . $stored;
    $moved = is_uploaded_file( $file['tmp_name'] ) ? @move_uploaded_file( $file['tmp_name'], $dest ) : @copy( $file['tmp_name'], $dest );
    if ( ! $moved ) return prr_err( 'The server could not save the file. Check folder permissions.', 500 );
    @chmod( $dest, 0640 );
    return array( 'stored_path' => $stored, 'file_name' => sanitize_file_name( $file['name'] ), 'mime' => $check['type'], 'size_bytes' => (int) $file['size'] );
}

function prr_doc_is_sensitive( $doc_type ) {
    $t = prr_doc_types();
    return ! empty( $t[ $doc_type ]['sensitive'] );
}

/** Who may open a document: invoices need view_invoices, restricted files need view_sensitive or ownership. */
function prr_can_open_document( $d ) {
    if ( $d->doc_type === 'invoice' && ! prr_can( 'view_invoices' ) ) return false;
    if ( prr_doc_is_sensitive( $d->doc_type ) && ! prr_can( 'view_sensitive' ) ) {
        return prr_owner_of( $d->object_type, $d->object_id ) === get_current_user_id();
    }
    if ( $d->object_type === 'applicant' ) return prr_can( 'view_applicants' ) && prr_applicant_visible( prr_get_applicant_row( $d->object_id ) );
    if ( $d->object_type === 'lead' )      return prr_can( 'view_leads' );
    if ( $d->object_type === 'client' )    return prr_can( 'view_clients' );
    return false;
}

function prr_format_document( $d ) {
    $types = prr_doc_types();
    $out = array(
        'id' => (int) $d->id, 'object_type' => $d->object_type, 'object_id' => (int) $d->object_id,
        'doc_type' => $d->doc_type, 'type_label' => $types[ $d->doc_type ]['label'] ?? $d->doc_type,
        'sensitive' => prr_doc_is_sensitive( $d->doc_type ), 'file_name' => $d->file_name, 'mime' => $d->mime,
        'size_bytes' => (int) $d->size_bytes, 'doc_number' => $d->doc_number, 'expiry_date' => $d->expiry_date,
        'signed_date' => $d->signed_date, 'valid_until' => $d->valid_until, 'note' => $d->note,
        'uploaded_by' => prr_user_brief( $d->uploaded_by ), 'created_at' => $d->created_at,
        'can_open' => prr_can_open_document( $d ),
        'download_url' => add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( PRR_API_NS . '/documents/' . $d->id . '/download' ) ),
    );
    if ( $d->doc_type === 'invoice' ) {
        $out['invoice'] = prr_can( 'view_invoices' ) ? array(
            'number' => $d->inv_number, 'amount' => $d->inv_amount !== null ? (float) $d->inv_amount : null,
            'currency' => $d->inv_currency, 'due' => $d->inv_due,
            'status' => ( $d->inv_status === 'unpaid' && $d->inv_due && $d->inv_due < current_time( 'Y-m-d' ) ) ? 'overdue' : $d->inv_status,
        ) : null;
    }
    $exp = $d->expiry_date ?: $d->valid_until;
    if ( $exp ) {
        $days = (int) floor( ( strtotime( $exp ) - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS );
        $warn = (int) prr_get_general()['expiry_warn_days'];
        if ( $days < 0 )          $out['expiry_warning'] = 'Expired ' . abs( $days ) . ' days ago.';
        elseif ( $days <= $warn ) $out['expiry_warning'] = "Expires in $days days.";
    }
    return $out;
}

function prr_list_documents( $object_type, $object_id, $format = true ) {
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . prr_table( 'documents' ) . ' WHERE object_type = %s AND object_id = %d ORDER BY id DESC', $object_type, $object_id ) );
    if ( ! $format ) return array_map( function( $r ) { return array( 'id' => (int) $r->id ); }, $rows );
    return array_map( 'prr_format_document', $rows );
}

function prr_delete_document( $id, $audit = true ) {
    global $wpdb;
    $d = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . prr_table( 'documents' ) . ' WHERE id = %d', $id ) );
    if ( ! $d ) return false;
    $path = prr_private_dir() . '/' . $d->stored_path;
    // A converted lead shares its files with the new client record: keep the file while another row uses it.
    $shared = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . prr_table( 'documents' ) . ' WHERE stored_path = %s AND id <> %d', $d->stored_path, $d->id ) );
    if ( ! $shared && $d->stored_path && file_exists( $path ) && strpos( realpath( $path ), realpath( prr_private_dir() ) ) === 0 ) @unlink( $path );
    $wpdb->delete( prr_table( 'documents' ), array( 'id' => $id ) );
    if ( $audit ) prr_audit( 'document_deleted', $d->object_type, $d->object_id, array( 'file' => $d->file_name, 'type' => $d->doc_type ) );
    return true;
}

/** Insert document metadata after storing the file. Used by the API and website intake. */
function prr_add_document( $object_type, $object_id, $stored, $doc_type, $fields = array() ) {
    global $wpdb;
    $types = prr_doc_types();
    if ( ! isset( $types[ $doc_type ] ) ) $doc_type = 'other';
    $row = array_merge( $stored, array(
        'object_type' => $object_type, 'object_id' => (int) $object_id, 'doc_type' => $doc_type,
        'doc_number'  => sanitize_text_field( $fields['doc_number'] ?? '' ),
        'expiry_date' => prr_date_or_null( $fields['expiry_date'] ?? '' ),
        'signed_date' => prr_date_or_null( $fields['signed_date'] ?? '' ),
        'valid_until' => prr_date_or_null( $fields['valid_until'] ?? '' ),
        'inv_number'  => sanitize_text_field( $fields['inv_number'] ?? '' ),
        'inv_amount'  => isset( $fields['inv_amount'] ) && $fields['inv_amount'] !== '' ? (float) preg_replace( '/[^\d.]/', '', $fields['inv_amount'] ) : null,
        'inv_currency'=> sanitize_text_field( $fields['inv_currency'] ?? '' ),
        'inv_due'     => prr_date_or_null( $fields['inv_due'] ?? '' ),
        'inv_status'  => $doc_type === 'invoice' ? ( in_array( $fields['inv_status'] ?? '', array( 'draft', 'unpaid', 'paid' ), true ) ? $fields['inv_status'] : 'unpaid' ) : '',
        'note'        => sanitize_text_field( $fields['note'] ?? '' ),
        'uploaded_by' => get_current_user_id() ?: null, 'created_at' => prr_now(),
    ) );
    $wpdb->insert( prr_table( 'documents' ), $row );
    $id = (int) $wpdb->insert_id;
    prr_log( $object_type, $object_id, 'system', 'Document uploaded: ' . ( $types[ $doc_type ]['label'] ?? $doc_type ) . ' (' . $stored['file_name'] . ').' );
    return $id;
}

add_action( 'rest_api_init', function() {
    $ns = PRR_API_NS;
    register_rest_route( $ns, '/documents', array(
        array( 'methods' => 'GET',  'callback' => 'prr_api_documents_list',   'permission_callback' => prr_gate() ),
        array( 'methods' => 'POST', 'callback' => 'prr_api_document_upload', 'permission_callback' => prr_gate( 'upload_documents' ) ),
    ) );
    register_rest_route( $ns, '/documents/(?P<id>\d+)', array(
        array( 'methods' => 'PATCH',  'callback' => 'prr_api_document_update', 'permission_callback' => prr_gate( 'upload_documents' ) ),
        array( 'methods' => 'DELETE', 'callback' => 'prr_api_document_delete', 'permission_callback' => prr_gate( 'delete_documents' ) ),
    ) );
    register_rest_route( $ns, '/documents/(?P<id>\d+)/download', array(
        'methods' => 'GET', 'callback' => 'prr_api_document_download', 'permission_callback' => prr_gate() ) );
} );

function prr_valid_object( $type, $id ) {
    global $wpdb;
    $map = array( 'applicant' => 'applicants', 'lead' => 'leads', 'client' => 'clients' );
    if ( ! isset( $map[ $type ] ) ) return false;
    return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . prr_table( $map[ $type ] ) . ' WHERE id = %d', $id ) );
}

function prr_api_documents_list( WP_REST_Request $r ) {
    $type = sanitize_key( $r['object_type'] ); $id = (int) $r['object_id'];
    if ( ! prr_valid_object( $type, $id ) ) return prr_err( 'Record not found.', 404 );
    return prr_ok( prr_list_documents( $type, $id ) );
}

function prr_api_document_upload( WP_REST_Request $r ) {
    $type = sanitize_key( $r['object_type'] ); $oid = (int) $r['object_id'];
    if ( ! prr_valid_object( $type, $oid ) ) return prr_err( 'Record not found.', 404 );
    if ( $type === 'applicant' && ! prr_applicant_visible( prr_get_applicant_row( $oid ) ) ) return prr_err( 'Record not found.', 404 );
    $doc_type = sanitize_key( $r['doc_type'] ?: 'other' );
    if ( $doc_type === 'invoice' && ! prr_can( 'view_invoices' ) ) return prr_err( 'Your role cannot add invoices.', 403 );
    $files = $r->get_file_params();
    if ( empty( $files['file'] ) ) return prr_err( 'Choose a file to upload.' );
    $stored = prr_store_upload( $files['file'] );
    if ( is_wp_error( $stored ) ) return $stored;
    $id = prr_add_document( $type, $oid, $stored, $doc_type, $r->get_params() );
    prr_audit( 'document_uploaded', $type, $oid, array( 'doc' => $id, 'type' => $doc_type ) );
    global $wpdb;
    return prr_ok( prr_format_document( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . prr_table( 'documents' ) . ' WHERE id = %d', $id ) ) ) );
}

/** Edit metadata, e.g. mark an invoice paid or fix an expiry date. */
function prr_api_document_update( WP_REST_Request $r ) {
    global $wpdb;
    $d = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . prr_table( 'documents' ) . ' WHERE id = %d', (int) $r['id'] ) );
    if ( ! $d || ! prr_can_open_document( $d ) ) return prr_err( 'Document not found.', 404 );
    $p = (array) $r->get_json_params(); $f = array();
    foreach ( array( 'doc_number', 'note', 'inv_number', 'inv_currency' ) as $k ) if ( isset( $p[ $k ] ) ) $f[ $k ] = sanitize_text_field( $p[ $k ] );
    foreach ( array( 'expiry_date', 'signed_date', 'valid_until', 'inv_due' ) as $k ) if ( isset( $p[ $k ] ) ) $f[ $k ] = prr_date_or_null( $p[ $k ] );
    if ( isset( $p['inv_status'] ) && in_array( $p['inv_status'], array( 'draft', 'unpaid', 'paid' ), true ) ) $f['inv_status'] = $p['inv_status'];
    if ( isset( $p['inv_amount'] ) ) $f['inv_amount'] = $p['inv_amount'] === '' ? null : (float) $p['inv_amount'];
    if ( $f ) {
        $wpdb->update( prr_table( 'documents' ), $f, array( 'id' => $d->id ) );
        if ( isset( $f['inv_status'] ) && $f['inv_status'] !== $d->inv_status ) prr_log( $d->object_type, $d->object_id, 'system', "Invoice {$d->inv_number} marked {$f['inv_status']}." );
    }
    return prr_ok( prr_format_document( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . prr_table( 'documents' ) . ' WHERE id = %d', $d->id ) ) ) );
}

function prr_api_document_delete( WP_REST_Request $r ) {
    return prr_delete_document( (int) $r['id'] ) ? prr_ok( array( 'deleted' => true ) ) : prr_err( 'Document not found.', 404 );
}

function prr_api_document_download( WP_REST_Request $r ) {
    global $wpdb;
    $d = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . prr_table( 'documents' ) . ' WHERE id = %d', (int) $r['id'] ) );
    if ( ! $d ) return prr_err( 'Document not found.', 404 );
    if ( ! prr_can_open_document( $d ) ) {
        prr_audit( 'document_access_denied', $d->object_type, $d->object_id, array( 'doc' => (int) $d->id ) );
        return prr_err( 'This document is restricted. Ask an administrator for access.', 403 );
    }
    $path = prr_private_dir() . '/' . $d->stored_path;
    $real = realpath( $path );
    if ( ! $real || strpos( $real, realpath( prr_private_dir() ) ) !== 0 || ! is_readable( $real ) ) return prr_err( 'The file is missing on the server.', 410 );
    if ( prr_doc_is_sensitive( $d->doc_type ) || $d->doc_type === 'invoice' ) {
        prr_audit( 'document_viewed', $d->object_type, $d->object_id, array( 'doc' => (int) $d->id, 'type' => $d->doc_type ) );
    }
    nocache_headers();
    $inline = in_array( $d->mime, array( 'application/pdf', 'image/jpeg', 'image/png', 'image/webp' ), true ) && empty( $r['download'] );
    header( 'Content-Type: ' . $d->mime );
    header( 'Content-Length: ' . filesize( $real ) );
    header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . str_replace( '"', '', $d->file_name ) . '"' );
    header( 'X-Content-Type-Options: nosniff' );
    readfile( $real );
    exit;
}
