<?php
/**
 * /prr-portal/ — the full-screen CRM, outside the theme (same approach as the GCPA portal).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'init', 'prr_portal_rewrite', 1 );
function prr_portal_rewrite() {
    add_rewrite_rule( '^' . PRR_PORTAL_SLUG . '/?$', 'index.php?prr_portal=1', 'top' );
    add_rewrite_tag( '%prr_portal%', '([0-9]+)' );
}
add_action( 'init', function() {
    $rules = get_option( 'rewrite_rules', array() );
    if ( is_array( $rules ) && empty( $rules[ '^' . PRR_PORTAL_SLUG . '/?$' ] ) ) flush_rewrite_rules( false );
}, 99 );

add_action( 'template_redirect', 'prr_portal_route', 1 );
function prr_portal_route() {
    if ( ! get_query_var( 'prr_portal' ) ) return;
    nocache_headers();
    $b = prr_get_branding();
    $public_login = ! is_user_logged_in() && $b['allow_indexing'];
    header( 'X-Robots-Tag: ' . ( $public_login ? 'index, follow' : 'noindex, nofollow' ) );
    if ( ! is_user_logged_in() && isset( $_POST['prr_login_nonce'] ) ) { prr_handle_login(); exit; }
    if ( ! is_user_logged_in() || ! prr_can_access() ) { prr_render_login(); exit; }
    prr_render_portal();
    exit;
}

function prr_handle_login() {
    if ( ! wp_verify_nonce( $_POST['prr_login_nonce'] ?? '', 'prr_login' ) ) { prr_render_login( 'The page expired. Please sign in again.' ); return; }
    if ( prr_login_locked() ) { prr_render_login( 'Too many failed attempts. Wait 15 minutes and try again.' ); return; }
    $login = sanitize_user( wp_unslash( $_POST['log'] ?? '' ) );
    $pass  = wp_unslash( $_POST['pwd'] ?? '' );
    if ( $login === '' || $pass === '' ) { prr_render_login( 'Enter your username or email and your password.' ); return; }
    $user = wp_signon( array( 'user_login' => $login, 'user_password' => $pass, 'remember' => ! empty( $_POST['rememberme'] ) ), is_ssl() );
    if ( is_wp_error( $user ) ) { prr_render_login( 'The username or password is incorrect.' ); return; }
    if ( ! prr_user_can_access( $user ) ) {
        wp_logout();
        prr_render_login( 'Your account does not have access to the CRM. Ask an administrator to add you.' );
        return;
    }
    wp_safe_redirect( home_url( '/' . PRR_PORTAL_SLUG . '/' ) );
    exit;
}

function prr_render_login( $error = '' ) {
    $b = prr_get_branding();
    header( 'Content-Type: text/html; charset=utf-8' );
    ?><!DOCTYPE html>
<html lang="en"<?php echo prr_theme_attr( $b ); ?>><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<?php echo prr_head_tags( 'login' ); ?>
<style>
:root{--ink:#4e0d1b;--brass:#b4844e;--bg:#f7f1e7;--surface:#fffdf9;--line:#e5d9c6;--fg:#2b1b1d;--mute:#7b6a63;--bad:#b63a3a;--bad-soft:#f9e1e1}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--bad:#f08484;--bad-soft:#401f24}}:root[data-theme="dark"]{--bad:#f08484;--bad-soft:#401f24}
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px;background:var(--bg);color:var(--fg);font:14px/1.5 var(--f-body)}
.wrap{width:100%;max-width:380px}.brand{display:flex;gap:12px;align-items:center;margin-bottom:22px}
.crest{width:42px;height:42px;border-radius:9px;background:var(--brass);color:var(--ink);display:grid;place-items:center;font:700 17px var(--f-display);overflow:hidden}.crest-img{object-fit:contain;background:transparent;padding:0;border-radius:50%;width:84px;height:84px}
.brand b{font:700 19px/1.2 var(--f-display);display:block}.brand small{color:var(--mute);font-size:12px;letter-spacing:.08em;text-transform:uppercase}
.box{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:26px}
h1{font:700 22px var(--f-display);margin:0 0 4px}p{margin:0 0 18px;color:var(--mute)}
label{display:block;font-size:12.5px;font-weight:600;margin:0 0 6px}input[type=text],input[type=password]{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:8px;background:var(--surface);color:var(--fg);font:inherit;margin-bottom:14px}
input:focus{outline:2px solid var(--brass);outline-offset:1px}.err{background:var(--bad-soft);color:var(--bad);padding:10px 12px;border-radius:8px;margin-bottom:14px;font-size:13px}
button{width:100%;padding:11px;border:0;border-radius:8px;background:var(--ink);color:#fff;font:600 14px var(--f-body);cursor:pointer}
.row{display:flex;justify-content:space-between;align-items:center;margin:-4px 0 16px;font-size:13px;color:var(--mute)}.row a{color:var(--mute)}
<?php echo prr_brand_css(); ?></style></head><body><div class="wrap">
<div class="brand"><?php echo prr_brand_mark( $b ); ?><div><b><?php echo esc_html( $b['portal_title'] ); ?></b><small><?php echo esc_html( $b['portal_subtitle'] ); ?></small></div></div>
<div class="box">
<?php if ( is_user_logged_in() ) : ?>
  <h1>No access</h1><p>Your account is signed in but does not have access to the CRM. Ask an administrator to add you.</p>
  <a href="<?php echo esc_url( wp_logout_url( home_url( '/' . PRR_PORTAL_SLUG . '/' ) ) ); ?>">Sign in with another account</a>
<?php else : ?>
  <h1><?php echo esc_html( $b['login_heading'] ?: 'Sign in' ); ?></h1><?php if ( $b['login_message'] ) : ?><p><?php echo nl2br( esc_html( $b['login_message'] ) ); ?></p><?php endif; ?>
  <?php if ( $error ) : ?><div class="err"><?php echo esc_html( $error ); ?></div><?php endif; ?>
  <form method="post" action="<?php echo esc_url( home_url( '/' . PRR_PORTAL_SLUG . '/' ) ); ?>">
    <?php wp_nonce_field( 'prr_login', 'prr_login_nonce' ); ?>
    <label for="log">Username or email</label><input type="text" id="log" name="log" autocomplete="username" required autofocus value="<?php echo esc_attr( wp_unslash( $_POST['log'] ?? '' ) ); ?>">
    <label for="pwd">Password</label><input type="password" id="pwd" name="pwd" autocomplete="current-password" required>
    <div class="row"><label style="margin:0;font-weight:400"><input type="checkbox" name="rememberme" value="1"> Keep me signed in</label><a href="<?php echo esc_url( wp_lostpassword_url( home_url( '/' . PRR_PORTAL_SLUG . '/' ) ) ); ?>">Forgot password?</a></div>
    <button type="submit">Sign in</button>
  </form>
<?php endif; ?>
</div></div></body></html><?php
}

function prr_render_portal() {
    $config = prr_bootstrap();
    $config['api']    = esc_url_raw( rest_url( PRR_API_NS ) );
    $config['nonce']  = wp_create_nonce( 'wp_rest' );
    $config['logout'] = wp_logout_url( home_url( '/' . PRR_PORTAL_SLUG . '/' ) );
    $config['version']= PRR_VERSION;
    $b = prr_get_branding();
    header( 'Content-Type: text/html; charset=utf-8' );
    ?><!DOCTYPE html>
<html lang="en"<?php echo prr_theme_attr( $b ); ?>><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?php echo prr_head_tags( 'portal' ); ?>
<link rel="stylesheet" href="<?php echo esc_url( PRR_URL . 'assets/portal.css?v=' . PRR_VERSION ); ?>">
<style id="prr-brand"><?php echo prr_brand_css( $b ); ?></style>
</head><body>
<div class="app">
  <aside class="side">
    <div class="brand" id="brand"><?php echo prr_brand_mark( $b ); ?><div><b><?php echo esc_html( $b['portal_title'] ); ?></b><small><?php echo esc_html( $b['portal_subtitle'] ); ?></small></div></div>
    <nav class="nav" id="nav"></nav>
    <div class="me"><div class="avatar" id="me-av"></div><div><div id="me-name"></div><small id="me-role"></small><br><a class="signout" href="<?php echo esc_url( $config['logout'] ); ?>">Sign out</a></div></div>
  </aside>
  <div class="main">
    <header class="top">
      <label class="search"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><input id="global-search" placeholder="Search applicants, leads, phone numbers" aria-label="Search"></label>
      <button class="icon-btn" id="bell" aria-label="Notifications"><svg viewBox="0 0 24 24"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/></svg><span class="dot" id="bell-count" hidden></span></button>
    </header>
    <main class="content" id="view"><div class="loading">Loading…</div></main>
  </div>
</div>
<div id="modal-root"></div>
<div id="toast" class="toast" hidden></div>
<script>window.PRR = <?php echo wp_json_encode( $config ); ?>;</script>
<script src="<?php echo esc_url( PRR_URL . 'assets/portal.js?v=' . PRR_VERSION ); ?>"></script>
</body></html><?php
}
