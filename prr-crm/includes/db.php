<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function prr_table( $name ) {
    global $wpdb;
    return $wpdb->prefix . 'prr_' . $name;
}

function prr_create_tables() {
    global $wpdb;
    $c = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Applicants (candidates)
    dbDelta( "CREATE TABLE " . prr_table( 'applicants' ) . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ref VARCHAR(30) NOT NULL DEFAULT '',
        full_name VARCHAR(200) NOT NULL DEFAULT '',
        email VARCHAR(190) NOT NULL DEFAULT '',
        phone VARCHAR(60) NOT NULL DEFAULT '',
        phone_norm VARCHAR(30) NOT NULL DEFAULT '',
        location VARCHAR(200) NOT NULL DEFAULT '',
        position VARCHAR(200) NOT NULL DEFAULT '',
        skills TEXT NULL,
        experience TEXT NULL,
        source VARCHAR(60) NOT NULL DEFAULT 'manual',
        status VARCHAR(40) NOT NULL DEFAULT 'new',
        recruiter_id BIGINT UNSIGNED NULL,
        notes TEXT NULL,
        custom LONGTEXT NULL,
        consent TINYINT(1) NOT NULL DEFAULT 0,
        duplicate_of BIGINT UNSIGNED NULL,
        not_duplicate TINYINT(1) NOT NULL DEFAULT 0,
        created_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        anonymised_at DATETIME NULL,
        PRIMARY KEY  (id),
        KEY ref (ref),
        KEY email (email),
        KEY phone_norm (phone_norm),
        KEY status (status),
        KEY recruiter_id (recruiter_id),
        KEY created_at (created_at)
    ) $c;" );

    // One timeline table for every record type: comments, calls, emails, WhatsApp, system events.
    dbDelta( "CREATE TABLE " . prr_table( 'activity' ) . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        object_type VARCHAR(20) NOT NULL,
        object_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NULL,
        kind VARCHAR(20) NOT NULL DEFAULT 'comment',
        body TEXT NULL,
        meta LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY object (object_type, object_id),
        KEY kind (kind),
        KEY created_at (created_at)
    ) $c;" );

    dbDelta( "CREATE TABLE " . prr_table( 'clients' ) . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ref VARCHAR(30) NOT NULL DEFAULT '',
        company_name VARCHAR(200) NOT NULL DEFAULT '',
        industry VARCHAR(120) NOT NULL DEFAULT '',
        location VARCHAR(200) NOT NULL DEFAULT '',
        contact_name VARCHAR(150) NOT NULL DEFAULT '',
        contact_email VARCHAR(190) NOT NULL DEFAULT '',
        contact_phone VARCHAR(60) NOT NULL DEFAULT '',
        terms TEXT NULL,
        notes TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        lead_id BIGINT UNSIGNED NULL,
        created_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY is_active (is_active)
    ) $c;" );

    dbDelta( "CREATE TABLE " . prr_table( 'vacancies' ) . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        client_id BIGINT UNSIGNED NOT NULL,
        position VARCHAR(200) NOT NULL DEFAULT '',
        headcount INT NOT NULL DEFAULT 1,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY client_id (client_id)
    ) $c;" );

    // Client leads (employers from the Excel sheet / website Clients page)
    dbDelta( "CREATE TABLE " . prr_table( 'leads' ) . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ref VARCHAR(30) NOT NULL DEFAULT '',
        contact_name VARCHAR(150) NOT NULL DEFAULT '',
        company VARCHAR(200) NOT NULL DEFAULT '',
        phone VARCHAR(60) NOT NULL DEFAULT '',
        phone_norm VARCHAR(30) NOT NULL DEFAULT '',
        email VARCHAR(190) NOT NULL DEFAULT '',
        country VARCHAR(100) NOT NULL DEFAULT '',
        source VARCHAR(60) NOT NULL DEFAULT 'manual',
        status VARCHAR(40) NOT NULL DEFAULT 'new',
        owner_id BIGINT UNSIGNED NULL,
        next_follow_up DATE NULL,
        needs TEXT NULL,
        headcount INT NULL,
        client_id BIGINT UNSIGNED NULL,
        last_activity_at DATETIME NULL,
        created_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY phone_norm (phone_norm),
        KEY status (status),
        KEY owner_id (owner_id),
        KEY next_follow_up (next_follow_up)
    ) $c;" );

    // Candidates a lead / client has picked
    dbDelta( "CREATE TABLE " . prr_table( 'lead_picks' ) . " (
        lead_id BIGINT UNSIGNED NOT NULL,
        applicant_id BIGINT UNSIGNED NOT NULL,
        added_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (lead_id, applicant_id)
    ) $c;" );

    // Candidate → client submissions. The unique key blocks duplicate submissions.
    dbDelta( "CREATE TABLE " . prr_table( 'submissions' ) . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        applicant_id BIGINT UNSIGNED NOT NULL,
        client_id BIGINT UNSIGNED NOT NULL,
        position VARCHAR(190) NOT NULL DEFAULT '',
        recruiter_id BIGINT UNSIGNED NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'submitted',
        submitted_on DATE NULL,
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_sub (applicant_id, client_id, position),
        KEY client_id (client_id),
        KEY status (status)
    ) $c;" );

    // Files are stored outside public access; this table holds their metadata.
    dbDelta( "CREATE TABLE " . prr_table( 'documents' ) . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        object_type VARCHAR(20) NOT NULL,
        object_id BIGINT UNSIGNED NOT NULL,
        doc_type VARCHAR(40) NOT NULL DEFAULT 'other',
        file_name VARCHAR(255) NOT NULL DEFAULT '',
        stored_path VARCHAR(255) NOT NULL DEFAULT '',
        mime VARCHAR(100) NOT NULL DEFAULT '',
        size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
        doc_number VARCHAR(80) NOT NULL DEFAULT '',
        expiry_date DATE NULL,
        signed_date DATE NULL,
        valid_until DATE NULL,
        inv_number VARCHAR(60) NOT NULL DEFAULT '',
        inv_amount DECIMAL(12,2) NULL,
        inv_currency VARCHAR(10) NOT NULL DEFAULT '',
        inv_due DATE NULL,
        inv_status VARCHAR(20) NOT NULL DEFAULT '',
        note VARCHAR(255) NOT NULL DEFAULT '',
        uploaded_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY object (object_type, object_id),
        KEY doc_type (doc_type),
        KEY expiry_date (expiry_date)
    ) $c;" );

    dbDelta( "CREATE TABLE " . prr_table( 'messages' ) . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        channel VARCHAR(20) NOT NULL DEFAULT 'email',
        direction VARCHAR(10) NOT NULL DEFAULT 'out',
        object_type VARCHAR(20) NOT NULL DEFAULT '',
        object_id BIGINT UNSIGNED NULL,
        to_addr VARCHAR(255) NOT NULL DEFAULT '',
        subject VARCHAR(255) NOT NULL DEFAULT '',
        body LONGTEXT NULL,
        template VARCHAR(60) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'sent',
        provider_id VARCHAR(120) NOT NULL DEFAULT '',
        error TEXT NULL,
        sent_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY object (object_type, object_id),
        KEY provider_id (provider_id),
        KEY channel (channel)
    ) $c;" );

    dbDelta( "CREATE TABLE " . prr_table( 'audit' ) . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NULL,
        action VARCHAR(60) NOT NULL,
        object_type VARCHAR(20) NOT NULL DEFAULT '',
        object_id BIGINT UNSIGNED NULL,
        details TEXT NULL,
        ip VARCHAR(64) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY action (action),
        KEY created_at (created_at)
    ) $c;" );

    // Every website form submission is logged first, so a failure never loses data.
    dbDelta( "CREATE TABLE " . prr_table( 'intake_log' ) . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        form VARCHAR(30) NOT NULL DEFAULT 'applicant',
        source VARCHAR(60) NOT NULL DEFAULT '',
        payload LONGTEXT NULL,
        files LONGTEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'received',
        object_id BIGINT UNSIGNED NULL,
        duplicate_of BIGINT UNSIGNED NULL,
        error TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY status (status)
    ) $c;" );

    dbDelta( "CREATE TABLE " . prr_table( 'notifications' ) . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        kind VARCHAR(30) NOT NULL DEFAULT '',
        title VARCHAR(200) NOT NULL DEFAULT '',
        body VARCHAR(500) NOT NULL DEFAULT '',
        object_type VARCHAR(20) NOT NULL DEFAULT '',
        object_id BIGINT UNSIGNED NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY user_read (user_id, is_read)
    ) $c;" );
}

function prr_drop_tables() {
    global $wpdb;
    foreach ( array( 'applicants', 'activity', 'clients', 'vacancies', 'leads', 'lead_picks', 'submissions',
                     'documents', 'messages', 'audit', 'intake_log', 'notifications' ) as $t ) {
        $wpdb->query( 'DROP TABLE IF EXISTS ' . prr_table( $t ) ); // phpcs:ignore
    }
}
