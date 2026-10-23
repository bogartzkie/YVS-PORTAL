<?php
/**
 * Branding & SEO for the portal and its sign-in page: colours, fonts, title, logo,
 * favicon, sign-in text, search/share metadata. Edited in Settings → Branding & SEO.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function prr_font_choices() {
    // label => [Google Fonts family spec or '' for system, CSS fallback stack]
    return array(
        'heading' => array(
            'Cormorant Garamond' => array( 'Cormorant+Garamond:ital,wght@0,500;0,600;0,700;1,500', 'Georgia, "Times New Roman", serif' ),
            'Playfair Display'   => array( 'Playfair+Display:wght@500;600;700', 'Georgia, serif' ),
            'Libre Baskerville'  => array( 'Libre+Baskerville:wght@400;700', 'Georgia, serif' ),
            'Lora'               => array( 'Lora:wght@500;600;700', 'Georgia, serif' ),
            'Montserrat'         => array( 'Montserrat:wght@500;600;700', 'system-ui, sans-serif' ),
            'Jost'               => array( 'Jost:wght@500;600;700', 'system-ui, sans-serif' ),
            'System'             => array( '', 'system-ui, -apple-system, "Segoe UI", sans-serif' ),
        ),
        'body' => array(
            'Jost'       => array( 'Jost:wght@400;500;600', 'system-ui, sans-serif' ),
            'Montserrat' => array( 'Montserrat:wght@400;500;600', 'system-ui, sans-serif' ),
            'Lato'       => array( 'Lato:wght@400;700', 'system-ui, sans-serif' ),
            'Open Sans'  => array( 'Open+Sans:wght@400;500;600', 'system-ui, sans-serif' ),
            'System'     => array( '', 'system-ui, -apple-system, "Segoe UI", sans-serif' ),
        ),
    );
}

function prr_branding_defaults() {
    return array(
        'portal_title'    => 'Prestige Recruitment Royale',
        'portal_subtitle' => 'Recruitment CRM',
        'logo_url'        => '',
        'favicon_url'     => '',
        'color_main'      => '#5a1424', // burgundy: sidebar, primary buttons
        'color_accent'    => '#b8923f', // antique gold: highlights
        'color_bg'        => '#f7f1e7', // cream page background
        'color_text'      => '#2b1b1d',
        'theme_mode'      => 'auto',    // auto (follows the device) | light | dark
        'heading_font'    => 'Cormorant Garamond',
        'body_font'       => 'Jost',
        'login_heading'   => 'Sign in',
        'login_message'   => 'Authorised staff only.',
        'seo_title'       => '{title} · Staff portal',
        'seo_description' => '',
        'og_image_url'    => '',
        'allow_indexing'  => 0,         // sign-in page only; the portal itself is never indexed
    );
}

function prr_get_branding() {
    $saved = get_option( 'prr_branding', null );
    if ( ! is_array( $saved ) ) { // carry over colours/logo set in an earlier version under General
        $g = get_option( 'prr_general', array() );
        $saved = array_filter( array(
            'portal_title' => $g['company_name'] ?? '', 'color_main' => $g['brand_dark'] ?? '',
            'color_accent' => $g['brand_accent'] ?? '', 'logo_url' => $g['logo_url'] ?? '',
        ) );
    }
    $b = wp_parse_args( $saved, prr_branding_defaults() );
    foreach ( array( 'color_main', 'color_accent', 'color_bg', 'color_text' ) as $k ) {
        $b[ $k ] = sanitize_hex_color( $b[ $k ] ) ?: prr_branding_defaults()[ $k ];
    }
    $f = prr_font_choices();
    if ( ! isset( $f['heading'][ $b['heading_font'] ] ) ) $b['heading_font'] = 'Cormorant Garamond';
    if ( ! isset( $f['body'][ $b['body_font'] ] ) )       $b['body_font'] = 'Jost';
    if ( ! in_array( $b['theme_mode'], array( 'auto', 'light', 'dark' ), true ) ) $b['theme_mode'] = 'auto';
    return $b;
}

function prr_sanitize_branding( $v ) {
    $b = prr_get_branding();
    foreach ( array( 'portal_title', 'portal_subtitle', 'login_heading', 'seo_title' ) as $k ) if ( isset( $v[ $k ] ) ) $b[ $k ] = sanitize_text_field( $v[ $k ] );
    foreach ( array( 'login_message', 'seo_description' ) as $k ) if ( isset( $v[ $k ] ) ) $b[ $k ] = sanitize_textarea_field( $v[ $k ] );
    foreach ( array( 'logo_url', 'favicon_url', 'og_image_url' ) as $k ) if ( isset( $v[ $k ] ) ) $b[ $k ] = esc_url_raw( $v[ $k ] );
    foreach ( array( 'color_main', 'color_accent', 'color_bg', 'color_text' ) as $k ) if ( isset( $v[ $k ] ) && sanitize_hex_color( $v[ $k ] ) ) $b[ $k ] = sanitize_hex_color( $v[ $k ] );
    foreach ( array( 'theme_mode', 'heading_font', 'body_font' ) as $k ) if ( isset( $v[ $k ] ) ) $b[ $k ] = sanitize_text_field( $v[ $k ] );
    if ( isset( $v['allow_indexing'] ) ) $b['allow_indexing'] = $v['allow_indexing'] ? 1 : 0;
    if ( $b['portal_title'] === '' ) $b['portal_title'] = prr_branding_defaults()['portal_title'];
    update_option( 'prr_branding', $b );
    return prr_get_branding();
}

/** Google Fonts stylesheet URL for the chosen fonts (plus the mono face used for numbers). */
function prr_font_url( $b = null ) {
    $b = $b ?: prr_get_branding();
    $f = prr_font_choices();
    $fam = array_filter( array_unique( array( $f['heading'][ $b['heading_font'] ][0], $f['body'][ $b['body_font'] ][0], 'IBM+Plex+Mono:wght@400;500' ) ) );
    return 'https://fonts.googleapis.com/css2?family=' . implode( '&family=', $fam ) . '&display=swap';
}

/** CSS variables derived from the four brand colours, for light and dark mode. */
function prr_brand_css( $b = null ) {
    $b = $b ?: prr_get_branding();
    $f = prr_font_choices();
    $m = $b['color_main']; $a = $b['color_accent']; $bg = $b['color_bg']; $t = $b['color_text'];
    $hf = ( $b['heading_font'] === 'System' ? '' : '"' . $b['heading_font'] . '", ' ) . $f['heading'][ $b['heading_font'] ][1];
    $bf = ( $b['body_font'] === 'System' ? '' : '"' . $b['body_font'] . '", ' ) . $f['body'][ $b['body_font'] ][1];
    $light = "--ink:$m;--ink-2:color-mix(in srgb,$m 84%,#fff);--ink-fg:color-mix(in srgb,$bg 90%,#fff);--ink-mute:color-mix(in srgb,$a 70%,#fff);"
           . "--bg:$bg;--surface:color-mix(in srgb,$bg 25%,#fff);--surface-2:color-mix(in srgb,$bg 92%,$t);--line:color-mix(in srgb,$bg 82%,$t);"
           . "--fg:$t;--mute:color-mix(in srgb,$t 62%,$bg);--brass:$a;--brass-soft:color-mix(in srgb,$a 18%,#fff);--brass-fg:color-mix(in srgb,$a 62%,#000);"
           . "--f-display:$hf;--f-body:$bf;";
    $dark  = "--ink:color-mix(in srgb,$m 55%,#000);--ink-2:color-mix(in srgb,$m 75%,#000);--ink-fg:color-mix(in srgb,$bg 90%,#fff);--ink-mute:color-mix(in srgb,$a 75%,#fff);"
           . "--bg:color-mix(in srgb,$m 18%,#0c0a0b);--surface:color-mix(in srgb,$m 22%,#141012);--surface-2:color-mix(in srgb,$m 26%,#1c1618);--line:color-mix(in srgb,$m 30%,#2e2427);"
           . "--fg:color-mix(in srgb,$bg 92%,#fff);--mute:color-mix(in srgb,$bg 62%,#000);--brass:color-mix(in srgb,$a 82%,#fff);--brass-soft:color-mix(in srgb,$a 22%,#151012);--brass-fg:color-mix(in srgb,$a 55%,#fff);color-scheme:dark;";
    $css = ":root{{$light}}";
    if ( $b['theme_mode'] === 'auto' ) $css .= "@media (prefers-color-scheme:dark){:root:not([data-theme=\"light\"]){{$dark}}}";
    $css .= ":root[data-theme=\"dark\"]{{$dark}}";
    return $css;
}

/** <html> attribute that forces light or dark when the admin chose one. */
function prr_theme_attr( $b = null ) {
    $b = $b ?: prr_get_branding();
    return $b['theme_mode'] === 'auto' ? '' : ' data-theme="' . esc_attr( $b['theme_mode'] ) . '"';
}

function prr_brand_mark( $b = null ) {
    $b = $b ?: prr_get_branding();
    if ( $b['logo_url'] ) return '<img class="crest crest-img" src="' . esc_url( $b['logo_url'] ) . '" alt="' . esc_attr( $b['portal_title'] ) . ' logo">';
    $words = preg_split( '/\s+/', trim( $b['portal_title'] ) );
    return '<div class="crest">' . esc_html( strtoupper( mb_substr( $words[0] ?? 'P', 0, 1 ) . mb_substr( $words[1] ?? '', 0, 1 ) ) ) . '</div>';
}

/** Title, description, robots, share card, favicon and theme colour for <head>. */
function prr_head_tags( $page = 'portal' ) {
    $b = prr_get_branding();
    $title = trim( str_replace( '{title}', $b['portal_title'], $b['seo_title'] ) ) ?: $b['portal_title'];
    if ( $page === 'login' && $b['login_heading'] && strpos( $title, $b['login_heading'] ) === false ) $title = $b['login_heading'] . ' · ' . $title;
    $index = $page === 'login' && $b['allow_indexing'];
    $fav = $b['favicon_url'] ?: get_site_icon_url( 64 );
    $url = home_url( '/' . PRR_PORTAL_SLUG . '/' );
    $o  = '<title>' . esc_html( $title ) . "</title>\n";
    $o .= '<meta name="robots" content="' . ( $index ? 'index,follow' : 'noindex,nofollow' ) . "\">\n";
    if ( $b['seo_description'] ) $o .= '<meta name="description" content="' . esc_attr( $b['seo_description'] ) . "\">\n";
    $o .= '<meta property="og:type" content="website"><meta property="og:title" content="' . esc_attr( $title ) . '"><meta property="og:url" content="' . esc_url( $url ) . "\">\n";
    $o .= '<meta property="og:site_name" content="' . esc_attr( $b['portal_title'] ) . "\">\n";
    if ( $b['seo_description'] ) $o .= '<meta property="og:description" content="' . esc_attr( $b['seo_description'] ) . "\">\n";
    if ( $b['og_image_url'] ) $o .= '<meta property="og:image" content="' . esc_url( $b['og_image_url'] ) . '"><meta name="twitter:card" content="summary_large_image">' . "\n";
    if ( $index ) $o .= '<link rel="canonical" href="' . esc_url( $url ) . "\">\n";
    if ( $fav ) $o .= '<link rel="icon" href="' . esc_url( $fav ) . '"><link rel="apple-touch-icon" href="' . esc_url( $fav ) . "\">\n";
    $o .= '<meta name="theme-color" content="' . esc_attr( $b['color_main'] ) . "\">\n";
    $o .= '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    $o .= '<link rel="stylesheet" href="' . esc_url( prr_font_url( $b ) ) . "\">\n";
    return $o;
}

/* ── Image upload for logo / favicon / share image (goes to the public Media Library) ── */
add_action( 'rest_api_init', function() {
    register_rest_route( PRR_API_NS, '/branding/upload', array( 'methods' => 'POST', 'callback' => 'prr_api_branding_upload', 'permission_callback' => prr_gate( 'manage_settings' ) ) );
} );
function prr_api_branding_upload( WP_REST_Request $r ) {
    $files = $r->get_file_params();
    if ( empty( $files['file'] ) ) return prr_err( 'Choose an image first.' );
    if ( $files['file']['size'] > 5 * MB_IN_BYTES ) return prr_err( 'The image is larger than 5 MB.' );
    $allowed = array( 'png' => 'image/png', 'jpg|jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', 'ico' => 'image/x-icon' );
    $check = wp_check_filetype_and_ext( $files['file']['tmp_name'], $files['file']['name'], $allowed );
    if ( empty( $check['type'] ) ) return prr_err( 'Use a PNG, JPG, WEBP, GIF or ICO image.' );
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $id = media_handle_upload( 'file', 0, array(), array( 'test_form' => false, 'mimes' => $allowed ) );
    if ( is_wp_error( $id ) ) return $id;
    prr_audit( 'branding_image_uploaded', '', 0, array( 'kind' => sanitize_key( $r['kind'] ), 'attachment' => $id ) );
    return prr_ok( array( 'url' => wp_get_attachment_url( $id ), 'id' => $id ) );
}
