<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Roles. WordPress administrators get full access automatically. The three CRM roles
 * only carry `prr_access`; what each can do is decided by the privilege matrix in
 * settings.php (editable in Settings → Permissions).
 */
function prr_role_labels() {
    return array(
        'prr_admin'     => 'CRM Administrator',
        'prr_manager'   => 'Manager',
        'prr_recruiter' => 'Recruiter',
    );
}

function prr_register_roles() {
    foreach ( prr_role_labels() as $slug => $label ) {
        if ( ! get_role( $slug ) ) add_role( $slug, $label, array( 'read' => true, 'prr_access' => true ) );
    }
    $admin = get_role( 'administrator' );
    if ( $admin && ! $admin->has_cap( 'prr_access' ) ) $admin->add_cap( 'prr_access' );
}

function prr_remove_roles() {
    foreach ( array_keys( prr_role_labels() ) as $slug ) remove_role( $slug );
    $admin = get_role( 'administrator' );
    if ( $admin ) $admin->remove_cap( 'prr_access' );
}

function prr_user_can_access( $user = null ) {
    if ( ! $user ) $user = wp_get_current_user();
    if ( ! $user || ! $user->ID ) return false;
    return in_array( 'administrator', (array) $user->roles, true ) || ! empty( $user->allcaps['prr_access'] );
}
function prr_can_access() { return prr_user_can_access(); }

/** Human label of the user's CRM role. */
function prr_user_role_label( $user = null ) {
    if ( ! $user ) $user = wp_get_current_user();
    if ( ! $user || ! $user->ID ) return '';
    if ( in_array( 'administrator', (array) $user->roles, true ) ) return 'Administrator';
    foreach ( prr_role_labels() as $slug => $label ) {
        if ( in_array( $slug, (array) $user->roles, true ) ) return $label;
    }
    return '';
}
function prr_user_role_slug( $user ) {
    if ( in_array( 'administrator', (array) $user->roles, true ) ) return 'administrator';
    foreach ( array_keys( prr_role_labels() ) as $slug ) {
        if ( in_array( $slug, (array) $user->roles, true ) ) return $slug;
    }
    return '';
}

/** Can the user see every record, or only those assigned to them? */
function prr_sees_all( $user = null ) {
    if ( ! $user ) $user = wp_get_current_user();
    $slug = prr_user_role_slug( $user );
    if ( $slug !== 'prr_recruiter' ) return true;
    return (bool) prr_get_general()['recruiter_sees_all'];
}

/** Everyone with portal access (for assignee dropdowns). */
function prr_team() {
    $users = get_users( array( 'capability' => 'prr_access', 'orderby' => 'display_name' ) );
    $admins = get_users( array( 'role' => 'administrator' ) );
    $seen = array(); $out = array();
    foreach ( array_merge( $users, $admins ) as $u ) {
        if ( isset( $seen[ $u->ID ] ) ) continue;
        $seen[ $u->ID ] = true;
        $out[] = array( 'id' => (int) $u->ID, 'name' => $u->display_name, 'email' => $u->user_email,
                        'role' => prr_user_role_label( $u ), 'role_slug' => prr_user_role_slug( $u ) );
    }
    return $out;
}
