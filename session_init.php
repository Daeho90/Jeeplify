<?php
// session_init.php
// Shared session bootstrap. Include this instead of calling session_start()
// directly, so every page gets the same hardened cookie settings:
//   - httponly: blocks JavaScript from reading the session cookie (XSS mitigation)
//   - samesite=Lax: blocks the cookie being sent on most cross-site requests (CSRF mitigation)
//   - secure: only sent over HTTPS, when the request itself is HTTPS

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        // Render (and most hosts) terminate TLS at a proxy, so $_SERVER['HTTPS']
        // is often unset even on a live HTTPS site — X-Forwarded-Proto covers that case.

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}