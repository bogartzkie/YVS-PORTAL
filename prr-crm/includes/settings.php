<?php
/**
 * Settings: every list the recruitment team may want to change (statuses, document
 * types, templates, privileges) lives in an option with code defaults, so Settings in
 * the portal can edit it without a code change.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function prr_option_names() {
    return array( 'prr_general', 'prr_applicant_statuses', 'prr_lead_statuses', 'prr_submission_statuses',
        'prr_doc_types', 'prr_email_templates', 'prr_wa_templates', 'prr_privileges', 'prr_whatsapp', 'prr_intake' );
}

/* ── General ─────────────────────────────────────────────── */
function prr_general_defaults() {
    return array(
        'company_name'        => 'Prestige Recruitment Royale',
        'from_name'           => 'Prestige Recruitment Royale',
        'from_email'          => get_option( 'admin_email' ),
        'notify_emails'       => get_option( 'admin_email' ), // new applicant / lead alerts
        'ref_prefix'          => 'PRR',
        'currency'            => 'AED',
        'retention_months'    => 24,  // anonymise inactive applicants after N months (0 = never)
        'expiry_warn_days'    => 90,  // passport / visa / contract expiry reminder
        'recruiter_sees_all'  => 1,   // 0 = recruiters only see records assigned to them
        'delete_on_uninstall' => 0,
        'brand_dark'          => '#5a1424', // sidebar, primary buttons (match the website's main dark colour)
        'brand_accent'        => '#b8923f', // highlights, logo badge, call-to-action buttons
        'logo_url'            => '',
    );
}
function prr_get_general() {
    return wp_parse_args( get_option( 'prr_general', array() ), prr_general_defaults() );
}

/* ── Status lists (key => label, color, flags) ───────────── */
function prr_default_applicant_statuses() {
    return array(
        'new'          => array( 'label' => 'New Applicant',        'color' => '#2b62c9' ),
        'contacted'    => array( 'label' => 'Contacted',            'color' => '#626782' ),
        'screening'    => array( 'label' => 'Screening',            'color' => '#a8630b' ),
        'qualified'    => array( 'label' => 'Qualified',            'color' => '#b8923f' ),
        'ready'        => array( 'label' => 'Ready for Submission', 'color' => '#b8923f' ),
        'interviewing' => array( 'label' => 'Interviewing',         'color' => '#2b62c9' ),
        'placed'       => array( 'label' => 'Placed / Hired',       'color' => '#1d7a4f' ),
        'rejected'     => array( 'label' => 'Rejected',             'color' => '#b63a3a' ),
        'unresponsive' => array( 'label' => 'Unresponsive',         'color' => '#a8630b' ),
        'archived'     => array( 'label' => 'Archived',             'color' => '#8a8fa8' ),
    );
}
function prr_default_lead_statuses() {
    return array(
        'new'            => array( 'label' => 'New lead',            'color' => '#2b62c9', 'queue' => 1, 'help' => 'Imported or registered; nobody has reached out yet' ),
        'contacted'      => array( 'label' => 'Contacted',           'color' => '#626782', 'help' => 'Reached by call, WhatsApp or email; no clear interest yet' ),
        'not_answering'  => array( 'label' => 'Not answering',       'color' => '#a8630b', 'queue' => 1, 'help' => 'Called, no pick-up. Stays in the call queue' ),
        'whatsapp_only'  => array( 'label' => 'WhatsApp only',       'color' => '#1d7a4f', 'no_call' => 1, 'help' => 'Does not take calls; message on WhatsApp only' ),
        'call_back'      => array( 'label' => 'Call back later',     'color' => '#a8630b', 'queue' => 1, 'help' => 'Asked to be called at another time' ),
        'hot'            => array( 'label' => 'Hot lead',            'color' => '#b8923f', 'help' => 'Interested and likely to hire soon' ),
        'needs_received' => array( 'label' => 'Needs received',      'color' => '#b8923f', 'help' => 'Told us the positions and headcount they need' ),
        'converted'      => array( 'label' => 'Converted to client', 'color' => '#1d7a4f', 'terminal' => 1, 'help' => 'Became a client company' ),
        'not_interested' => array( 'label' => 'Not interested',      'color' => '#b63a3a', 'terminal' => 1, 'help' => 'Said no for now' ),
        'junk'           => array( 'label' => 'Junk lead',           'color' => '#b63a3a', 'terminal' => 1, 'help' => 'Wrong number, spam, duplicate or not an employer' ),
    );
}
function prr_default_submission_statuses() {
    return array(
        'ready'         => array( 'label' => 'Ready for Submission', 'color' => '#b8923f' ),
        'submitted'     => array( 'label' => 'Submitted to Client',  'color' => '#2b62c9' ),
        'client_review' => array( 'label' => 'Under Client Review',  'color' => '#2b62c9' ),
        'interview'     => array( 'label' => 'Interview Scheduled',  'color' => '#a8630b' ),
        'shortlisted'   => array( 'label' => 'Shortlisted',          'color' => '#b8923f' ),
        'placed'        => array( 'label' => 'Hired / Placed',       'color' => '#1d7a4f' ),
        'rejected'      => array( 'label' => 'Rejected by Client',   'color' => '#b63a3a' ),
        'withdrawn'     => array( 'label' => 'Withdrawn',            'color' => '#8a8fa8' ),
        'on_hold'       => array( 'label' => 'On Hold',              'color' => '#a8630b' ),
    );
}
function prr_get_list( $option, $defaults ) {
    $saved = get_option( $option, null );
    return ( is_array( $saved ) && $saved ) ? $saved : $defaults;
}
function prr_applicant_statuses()  { return prr_get_list( 'prr_applicant_statuses',  prr_default_applicant_statuses() ); }
function prr_lead_statuses()       { return prr_get_list( 'prr_lead_statuses',       prr_default_lead_statuses() ); }
function prr_submission_statuses() { return prr_get_list( 'prr_submission_statuses', prr_default_submission_statuses() ); }

/* ── Document types ──────────────────────────────────────── */
/* fields: id = number + expiry, contract = signed + valid until, invoice = invoice fields */
function prr_default_doc_types() {
    return array(
        'cv'          => array( 'label' => 'CV / resume',               'for' => array( 'applicant' ) ),
        'passport'    => array( 'label' => 'Passport',                  'for' => array( 'applicant' ), 'sensitive' => 1, 'fields' => 'id' ),
        'national_id' => array( 'label' => 'National ID',               'for' => array( 'applicant' ), 'sensitive' => 1, 'fields' => 'id' ),
        'visa'        => array( 'label' => 'Visa / work permit',        'for' => array( 'applicant' ), 'sensitive' => 1, 'fields' => 'id' ),
        'certificate' => array( 'label' => 'Certificate / licence',     'for' => array( 'applicant' ) ),
        'medical'     => array( 'label' => 'Medical result',            'for' => array( 'applicant' ), 'sensitive' => 1 ),
        'contract'    => array( 'label' => 'Signed contract',           'for' => array( 'applicant', 'lead', 'client' ), 'fields' => 'contract' ),
        'proposal'    => array( 'label' => 'Proposal / quotation',      'for' => array( 'lead', 'client' ) ),
        'invoice'     => array( 'label' => 'Invoice',                   'for' => array( 'lead', 'client' ), 'fields' => 'invoice' ),
        'job_order'   => array( 'label' => 'Job order / demand letter', 'for' => array( 'client', 'lead' ) ),
        'company_lic' => array( 'label' => 'Company licence',           'for' => array( 'lead', 'client' ), 'fields' => 'id' ),
        'contact_id'  => array( 'label' => 'ID of contact person',      'for' => array( 'lead', 'client' ), 'sensitive' => 1, 'fields' => 'id' ),
        'other'       => array( 'label' => 'Other',                     'for' => array( 'applicant', 'lead', 'client' ) ),
    );
}
function prr_doc_types() { return prr_get_list( 'prr_doc_types', prr_default_doc_types() ); }

/* ── Templates ───────────────────────────────────────────── */
function prr_default_email_templates() {
    return array(
        'submission' => array( 'label' => 'Candidate submission', 'subject' => 'Candidate submission: {candidate}, {position}',
            'body' => "Dear {contact},\n\nPlease find attached the profile and CV of {candidate} for the {position} vacancy.\n\nExperience: {experience}\nSkills: {skills}\n\nWe would be glad to arrange an interview at your convenience.\n\nKind regards,\n{recruiter}\n{company}" ),
        'interview'  => array( 'label' => 'Interview invitation', 'subject' => 'Interview invitation: {position}',
            'body' => "Dear {candidate},\n\nWe are pleased to invite you to an interview for the {position} role.\n\nDate: \nTime: \nFormat: \n\nPlease reply to confirm.\n\nBest regards,\n{recruiter}\n{company}" ),
        'follow_up'  => array( 'label' => 'Follow-up', 'subject' => 'Following up on your application',
            'body' => "Dear {candidate},\n\nWe tried to reach you about your application for {position}. Please reply with a good time to talk.\n\nBest regards,\n{recruiter}\n{company}" ),
        'received'   => array( 'label' => 'Application received', 'subject' => 'Your application with {company}',
            'body' => "Dear {candidate},\n\nThank you for applying for {position}. Your reference number is {ref}. A recruiter will contact you soon.\n\n{company}" ),
        'lead_intro' => array( 'label' => 'Employer introduction', 'subject' => 'Staffing support from {company}',
            'body' => "Dear {contact},\n\nThank you for your time. {company} supplies pre-screened staff to employers across the GCC and Asia.\n\nPlease reply with the positions and headcount you need and we will send matching candidate profiles.\n\nKind regards,\n{recruiter}" ),
    );
}
function prr_email_templates() { return prr_get_list( 'prr_email_templates', prr_default_email_templates() ); }

/* WhatsApp templates. "name" must match a template approved in Meta Business Manager
   when the Cloud API is used; "body" is what the staff member sees and edits. */
function prr_default_wa_templates() {
    return array(
        'intro'        => array( 'label' => 'Introduction',            'name' => 'prr_intro',        'body' => 'Hi{name}, this is {recruiter} from {company}. We supply pre-screened staff to employers in the GCC and Asia. Which positions are you hiring for at the moment?' ),
        'missed_call'  => array( 'label' => 'After a missed call',     'name' => 'prr_missed_call',  'body' => 'Hi{name}, we tried to call you today from {company}. When is a good time to talk about your staffing needs?' ),
        'profiles'     => array( 'label' => 'Share candidate profiles', 'name' => 'prr_profiles',    'body' => 'Hi{name}, as discussed, here are candidate profiles for your review. Let us know which ones you would like to interview.' ),
        'follow_up'    => array( 'label' => 'Follow-up',               'name' => 'prr_follow_up',    'body' => 'Hi{name}, following up on our last conversation. Any update on the positions you mentioned?' ),
    );
}
function prr_wa_templates() { return prr_get_list( 'prr_wa_templates', prr_default_wa_templates() ); }

function prr_whatsapp_settings() {
    $s = get_option( 'prr_whatsapp', array() );
    if ( empty( $s['verify_token'] ) ) { // must stay the same once given to Meta
        $s['verify_token'] = wp_generate_password( 24, false );
        update_option( 'prr_whatsapp', $s );
    }
    return wp_parse_args( $s, array(
        'mode'            => 'click',  // click = wa.me link (logged manually) | cloud = WhatsApp Cloud API
        'phone_number_id' => '',
        'access_token'    => '',
        'app_secret'      => '',
        'api_version'     => 'v21.0',
        'business_number' => '',
        'default_cc'      => '971', // country code added to local numbers
    ) );
}

function prr_intake_settings() {
    $s = get_option( 'prr_intake', array() );
    if ( empty( $s['token'] ) ) {
        $s['token'] = wp_generate_password( 32, false );
        update_option( 'prr_intake', $s );
    }
    return wp_parse_args( $s, array(
        'token'         => $s['token'],
        'cf7_applicant' => '',  // Contact Form 7 form ID that creates applicants
        'cf7_employer'  => '',  // Contact Form 7 form ID that creates client leads
        'send_ack'      => 1,   // email "Application received" to the applicant
        'field_map'     => array(), // custom form field name → CRM field
    ) );
}

/* ── Privileges (editable matrix, like GCPA) ─────────────── */
function prr_get_privilege_definitions() {
    $all = array( 'administrator', 'prr_admin', 'prr_manager', 'prr_recruiter' );
    $adm = array( 'administrator', 'prr_admin' );
    return array(
        array( 'key' => 'view_applicants',   'label' => 'View applicants',                      'group' => 'Applicants', 'default' => $all ),
        array( 'key' => 'edit_applicants',   'label' => 'Add and edit applicants',              'group' => 'Applicants', 'default' => array( 'administrator', 'prr_admin', 'prr_recruiter' ) ),
        array( 'key' => 'delete_applicants', 'label' => 'Delete or anonymise applicants',       'group' => 'Applicants', 'default' => $adm ),
        array( 'key' => 'import_data',       'label' => 'Import Excel / CSV',                   'group' => 'Applicants', 'default' => array( 'administrator', 'prr_admin', 'prr_recruiter' ) ),
        array( 'key' => 'view_sensitive',    'label' => 'Open passports, IDs and medical files (any record)', 'group' => 'Documents', 'default' => $adm ),
        array( 'key' => 'upload_documents',  'label' => 'Upload documents',                     'group' => 'Documents', 'default' => array( 'administrator', 'prr_admin', 'prr_recruiter' ) ),
        array( 'key' => 'delete_documents',  'label' => 'Delete documents',                     'group' => 'Documents', 'default' => $adm ),
        array( 'key' => 'view_leads',        'label' => 'View client leads',                    'group' => 'Leads',      'default' => $all ),
        array( 'key' => 'manage_leads',      'label' => 'Work leads (call, comment, edit)',     'group' => 'Leads',      'default' => array( 'administrator', 'prr_admin', 'prr_recruiter' ) ),
        array( 'key' => 'delete_leads',      'label' => 'Delete leads',                         'group' => 'Leads',      'default' => $adm ),
        array( 'key' => 'view_clients',      'label' => 'View client companies',                'group' => 'Clients',    'default' => $all ),
        array( 'key' => 'manage_clients',    'label' => 'Add, edit and archive clients',        'group' => 'Clients',    'default' => array( 'administrator', 'prr_admin', 'prr_recruiter' ) ),
        array( 'key' => 'view_invoices',     'label' => 'View invoices',                        'group' => 'Clients',    'default' => array( 'administrator', 'prr_admin', 'prr_manager' ) ),
        array( 'key' => 'submit_candidates', 'label' => 'Submit candidates to clients',         'group' => 'Submissions','default' => array( 'administrator', 'prr_admin', 'prr_recruiter' ) ),
        array( 'key' => 'send_email',        'label' => 'Send email',                           'group' => 'Communication', 'default' => array( 'administrator', 'prr_admin', 'prr_recruiter' ) ),
        array( 'key' => 'send_whatsapp',     'label' => 'Send WhatsApp',                        'group' => 'Communication', 'default' => array( 'administrator', 'prr_admin', 'prr_recruiter' ) ),
        array( 'key' => 'view_reports',      'label' => 'View reports',                         'group' => 'Reports',    'default' => array( 'administrator', 'prr_admin', 'prr_manager' ) ),
        array( 'key' => 'export_data',       'label' => 'Export CSV',                           'group' => 'Reports',    'default' => array( 'administrator', 'prr_admin', 'prr_manager' ) ),
        array( 'key' => 'manage_settings',   'label' => 'Manage users and settings',            'group' => 'Admin',      'default' => $adm ),
    );
}
function prr_get_privileges() {
    $saved = get_option( 'prr_privileges', array() );
    $out   = array();
    foreach ( prr_get_privilege_definitions() as $def ) {
        $out[ $def['key'] ] = array();
        foreach ( array( 'prr_admin', 'prr_manager', 'prr_recruiter' ) as $role ) {
            $out[ $def['key'] ][ $role ] = isset( $saved[ $def['key'] ][ $role ] )
                ? (bool) $saved[ $def['key'] ][ $role ]
                : in_array( $role, $def['default'], true );
        }
    }
    return $out;
}
/** WordPress administrators can always do everything, so nobody can lock themselves out. */
function prr_can( $privilege, $user = null ) {
    if ( ! $user ) $user = wp_get_current_user();
    if ( ! $user || ! $user->ID ) return false;
    if ( in_array( 'administrator', (array) $user->roles, true ) ) return true;
    $privs = prr_get_privileges();
    if ( empty( $privs[ $privilege ] ) ) return false;
    foreach ( (array) $user->roles as $role ) {
        if ( ! empty( $privs[ $privilege ][ $role ] ) ) return true;
    }
    return false;
}
