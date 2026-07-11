<?php

session_start();

require_once 'db.php';
require_once 'PHPMailer/src/Exception.php';
require_once 'PHPMailer/src/PHPMailer.php';
require_once 'PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// ── SMTP CONFIG (Gmail) ──────────────────────────────────────
// 1. Use a Gmail address you control.
// 2. Turn on 2-Step Verification on that Google account.
// 3. Create an "App Password": https://myaccount.google.com/apppasswords
//    (choose app "Mail", device "Other" -> name it "Jeeplify") and paste
//    the 16-character password below (no spaces).
define('SMTP_HOST',      'smtp.gmail.com');
define('SMTP_PORT',      587);
define('SMTP_USERNAME',  getenv('SMTP_USERNAME'));
define('SMTP_PASSWORD',  getenv('SMTP_PASSWORD'));             // TODO: 16-char app password
define('SMTP_FROM',      SMTP_USERNAME);
define('SMTP_FROM_NAME', 'Jeeplify BCD');

/**
 * Send an email via SMTP (Gmail). Returns true on success, false on failure.
 * On failure, $errorOut is populated with a human-readable reason.
 */
function sendMailSMTP(string $toEmail, string $subject, string $body, ?string &$errorOut = null): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($toEmail);

        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        $errorOut = $mail->ErrorInfo ?: $e->getMessage();
        return false;
    }
}

const ROLE_REDIRECTS = [
    'operator' => 'operator/dashboard.php',
    'driver'   => 'driver/dashboard.php',
    'user'     => 'commuter/commuter.php',
];

// ── GOOGLE OAuth CONFIG ─────────────────────────────────────
define('GOOGLE_CLIENT_ID',     getenv('GOOGLE_CLIENT_ID'));
define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET'));
define('GOOGLE_REDIRECT_URI',  'https://jeeplify.onrender.com/auth/google_callback.php');

// ── HELPERS ──────────────────────────────────────────────────
function jsonOut(bool $ok, string $msg, array $extra = []): void {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok' => $ok, 'message' => $msg], $extra));
    exit;
}
function clean(string $v): string {
    return trim(htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = clean($_POST['action'] ?? '');

    // ── LOGIN ────────────────────────────────────────────────
    if ($action === 'login') {
        $email    = clean($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!$email || !$password) jsonOut(false, 'Email and password are required.');

        try {
            $db   = $pdo;
            $stmt = $db->prepare(
                'SELECT a.id, a.email, a.password_hash, a.is_active, r.name AS role
                 FROM accounts a
                 JOIN roles r ON r.id = a.role_id
                 WHERE a.email = ? LIMIT 1'
            );
            $stmt->execute([$email]);
            $acc = $stmt->fetch();

            if (!$acc || !password_verify($password, $acc['password_hash']))
                jsonOut(false, 'Invalid email or password.');

            if (!$acc['is_active'])
                jsonOut(false, 'Your account has been deactivated. Contact support.');

            $db->prepare('UPDATE accounts SET last_login_at = NOW() WHERE id = ?')
               ->execute([$acc['id']]);

            session_regenerate_id(true);
            $_SESSION['account_id'] = $acc['id'];
            $_SESSION['email']      = $acc['email'];
            $_SESSION['role']       = $acc['role'];

            jsonOut(true, 'Login successful!', [
                'role'     => $acc['role'],
                'redirect' => ROLE_REDIRECTS[$acc['role']] ?? 'commuter/commuter.php',
            ]);

        } catch (PDOException $e) {
            error_log('Login error: ' . $e->getMessage());
            jsonOut(false, 'Server error. Please try again.');
        }
    }

    // ── REGISTER ─────────────────────────────────────────────
    if ($action === 'register') {
        $fullName  = clean($_POST['full_name'] ?? '');
        $email     = clean($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';
        $password2 = $_POST['password2'] ?? '';

        if (!$fullName)                                   jsonOut(false, 'Full name is required.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))   jsonOut(false, 'Enter a valid email address.');
        if (strlen($password) < 6)                        jsonOut(false, 'Password must be at least 6 characters.');
        if ($password !== $password2)                     jsonOut(false, 'Passwords do not match.');

        try {
            $db  = $pdo;
            $chk = $db->prepare('SELECT id FROM accounts WHERE email = ? LIMIT 1');
            $chk->execute([$email]);
            if ($chk->fetch()) jsonOut(false, 'An account with that email already exists.');

            $roleRow = $db->query("SELECT id FROM roles WHERE name='user' LIMIT 1")->fetch();
            if (!$roleRow) jsonOut(false, 'Registration unavailable right now.');

            $hash = password_hash($password, PASSWORD_BCRYPT);
            $db->beginTransaction();

            $db->prepare('INSERT INTO accounts (email, password_hash, role_id) VALUES (?,?,?)')
               ->execute([$email, $hash, $roleRow['id']]);
            $newId = $db->lastInsertId();

            $db->prepare('INSERT INTO user_profiles (account_id, full_name) VALUES (?,?)')
               ->execute([$newId, $fullName]);

            $db->commit();

            session_regenerate_id(true);
            $_SESSION['account_id'] = $newId;
            $_SESSION['email']      = $email;
            $_SESSION['role']       = 'user';

            jsonOut(true, 'Account created!', [
                'role'     => 'user',
                'redirect' => ROLE_REDIRECTS['user'],
            ]);

        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) $db->rollBack();
            error_log('Register error: ' . $e->getMessage());
            jsonOut(false, 'Server error. Please try again.');
        }
    }

    // ── FORGOT PASSWORD ──────────────────────────────────────
    if ($action === 'forgot_password') {
        $email = clean($_POST['email'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL))
            jsonOut(false, 'Please enter a valid email address.');

        try {
            $db   = $pdo;
            $stmt = $db->prepare('SELECT id FROM accounts WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $acc  = $stmt->fetch();

            if (!$acc) jsonOut(true, 'If that email exists, a reset link has been sent.');

            $token     = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $db->prepare('DELETE FROM password_resets WHERE account_id = ?')
               ->execute([$acc['id']]);
            $db->prepare('INSERT INTO password_resets (account_id, token, expires_at) VALUES (?,?,?)')
               ->execute([$acc['id'], $token, $expiresAt]);

            $resetLink = 'https://jeeplify.onrender.com/reset_password.php?token=' . $token;

            $subject = 'Reset Your Jeeplify Password';
            $body    = "Hello,\n\nClick the link below to reset your password (expires in 1 hour):\n\n$resetLink\n\nIf you did not request this, you can safely ignore this email.\n\n— Jeeplify Team";

            $mailError = null;
            $sent = sendMailSMTP($email, $subject, $body, $mailError);

            if (!$sent) {
                error_log('Forgot password mail error: ' . $mailError);
                // TEMP DEBUG — remove this "debug" key once email sending works
                jsonOut(false, 'Could not send the reset email right now. Please try again later.', ['debug' => $mailError]);
            }

            jsonOut(true, 'If that email exists, a reset link has been sent.');

        } catch (PDOException $e) {
            error_log('Forgot password error: ' . $e->getMessage());
            jsonOut(false, 'Server error. Please try again.');
        }
    }

    jsonOut(false, 'Invalid action.');
}

// ── GOOGLE OAuth REDIRECT ────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'google_auth') {
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;

    $params = http_build_query([
        'client_id'             => GOOGLE_CLIENT_ID,
        'redirect_uri'          => GOOGLE_REDIRECT_URI,
        'response_type'         => 'code',
        'scope'                 => 'openid email profile',
        'state'                 => $state,
        'access_type'           => 'online',
        'prompt'                => 'select_account',
    ]);

    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"/>
  <title>Jeeplify — Login</title>
  <link rel="icon" type="image/png" href="fav.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
    html, body {
      width:100%; min-height:100%;
      font-family:'Montserrat', sans-serif;
      background:#0b1220;
    }
    body { position:relative; color:#fff; }
    body.splash-active { overflow:hidden; height:100vh; }

    /* ── SPLASH / LOADING SCREEN ── */
    #splashScreen {
      position:fixed; inset:0; z-index:9999;
      background:#000;
      display:flex; align-items:center; justify-content:center;
      overflow:hidden;
      opacity:1;
      transition:opacity .6s ease;
    }
    #splashScreen.fade-out { opacity:0; pointer-events:none; }
    #splashScreen.gone { display:none; }
    #splashVideo {
      display:block;
      width:100%; height:100%;
      min-width:100%; min-height:100%;
      object-fit:cover;
      object-position:center center;
    }
    .splash-loader {
      position:absolute; bottom:28px; left:50%; transform:translateX(-50%);
      width:140px; height:3px; border-radius:3px;
      background:rgba(255,255,255,0.18);
      overflow:hidden;
      z-index:2;
    }
    .splash-loader-bar {
      height:100%; width:0%;
      background:#60a5fa;
      border-radius:3px;
      transition:width .1s linear;
    }

    @media (max-width: 480px) {
      .splash-loader { bottom:24px; width:110px; }
    }

    .hero-bg { position:fixed; inset:0; z-index:0; }
    .hero-bg img {
      width:100%; height:100%;
      object-fit:cover;
      object-position:center center;
    }
    .hero-overlay {
      position:absolute; inset:0;
      background:linear-gradient(
        90deg,
        rgba(8,12,25,.90) 0%,
        rgba(8,12,25,.60) 40%,
        rgba(8,12,25,.20) 70%,
        rgba(8,12,25,.10) 100%
      );
    }

    .page {
      position:relative; z-index:2;
      width:100%; min-height:100vh;
      display:flex;
      justify-content:flex-start;
      align-items:center;
      padding:40px clamp(32px, 6vw, 100px);
    }

    .card-wrap {
      width:100%;
      max-width:380px;
      position:relative;
      border-radius:20px;
      background:rgba(255,255,255,0.10);
      border:1px solid rgba(255,255,255,0.16);
      backdrop-filter:blur(22px);
      -webkit-backdrop-filter:blur(22px);
      box-shadow:0 16px 56px rgba(0,0,0,0.40);
      transition:height 0.42s cubic-bezier(.77,0,.18,1);
      overflow:visible;
    }
    .card-inner { border-radius:20px; overflow:hidden; }

    .slider {
      display:flex; width:200%;
      transition:transform 0.42s cubic-bezier(.77,0,.18,1);
    }
    .panel {
      width:50%; min-width:50%;
      padding:28px 26px 22px;
      flex-shrink:0;
      box-sizing:border-box;
    }

    .panel h1 { font-size:26px; font-weight:800; margin-bottom:4px; letter-spacing:-0.5px; }
    .subtitle  { color:rgba(255,255,255,0.60); font-size:11.5px; margin-bottom:16px; line-height:1.5; }

    .field { margin-bottom:10px; }
    .input-wrap { position:relative; }
    .input-icon {
      position:absolute; left:12px; top:50%; transform:translateY(-50%);
      width:16px; height:16px;
      display:flex; align-items:center; justify-content:center;
      pointer-events:none; opacity:0.55;
    }
    .input-icon svg { width:15px; height:15px; fill:none; stroke:#fff; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; }
    .glass-input {
      width:100%; height:42px;
      padding:0 40px 0 38px;
      border-radius:10px;
      border:1px solid rgba(255,255,255,0.15);
      background:rgba(255,255,255,0.07);
      color:#fff;
      font-size:13px; font-family:'Montserrat',sans-serif;
      outline:none;
      transition:border-color .2s, box-shadow .2s, background .2s;
    }
    .glass-input::placeholder { color:rgba(255,255,255,0.35); }
    .glass-input:focus {
      border-color:#60a5fa;
      box-shadow:0 0 0 3px rgba(96,165,250,0.15);
      background:rgba(255,255,255,0.09);
    }
    .glass-input.error {
      border-color:#f87171;
      box-shadow:0 0 0 3px rgba(248,113,113,0.13);
    }
    .pw-toggle {
      position:absolute; right:10px; top:50%; transform:translateY(-50%);
      background:none; border:none; cursor:pointer;
      color:rgba(255,255,255,0.45); padding:4px;
      display:flex; align-items:center; justify-content:center;
      transition:color .2s;
    }
    .pw-toggle:hover { color:rgba(255,255,255,0.85); }
    .pw-toggle svg { width:15px; height:15px; fill:none; stroke:currentColor; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; }

    .btn-primary {
      width:100%; height:42px; margin-top:8px;
      border:none; border-radius:10px;
      background:#2563eb; color:#fff;
      font-size:13px; font-weight:700; font-family:'Montserrat',sans-serif;
      cursor:pointer;
      box-shadow:0 5px 18px rgba(37,99,235,0.38);
      position:relative;
      transition:background .2s, box-shadow .2s, transform .1s;
      display:flex; align-items:center; justify-content:center; gap:8px;
      overflow:hidden;
    }
    .btn-primary:hover:not(:disabled) {
      background:#1d4ed8;
      box-shadow:0 7px 22px rgba(37,99,235,0.48);
      transform:translateY(-1px);
    }
    .btn-primary:active:not(:disabled) { transform:translateY(0); }
    .btn-primary:disabled { opacity:.60; cursor:not-allowed; transform:none; }

    .spinner {
      width:15px; height:15px;
      border:2px solid rgba(255,255,255,0.3);
      border-top-color:#fff;
      border-radius:50%;
      animation:spin .7s linear infinite;
      display:none; flex-shrink:0;
    }
    @keyframes spin { to { transform:rotate(360deg); } }
    .btn-primary.loading .spinner   { display:block; }
    .btn-primary.loading .btn-label { opacity:.7; }

    .switch-link {
      display:block; margin-top:10px; text-align:center;
      color:rgba(255,255,255,0.50); font-size:11.5px;
    }
    .switch-link span {
      color:#60a5fa; cursor:pointer; font-weight:600;
      transition:color .2s;
    }
    .switch-link span:hover { color:#93c5fd; }

    .forgot {
      display:block; margin-top:8px; text-align:center;
      color:rgba(255,255,255,0.42); font-size:11px;
      cursor:pointer; background:none; border:none; width:100%;
      font-family:'Montserrat',sans-serif;
      transition:color .2s;
    }
    .forgot:hover { color:rgba(255,255,255,0.80); }

    .divider {
      display:flex; align-items:center; gap:10px;
      margin:12px 0;
    }
    .divider::before,.divider::after {
      content:''; flex:1; height:1px;
      background:rgba(255,255,255,0.14);
    }
    .divider span { font-size:10px; color:rgba(255,255,255,0.35); white-space:nowrap; }

    /* ── GOOGLE BUTTON ── */
    .btn-google {
      width:100%; height:42px;
      border:1px solid rgba(255,255,255,0.20);
      border-radius:10px;
      background:#fff;
      color:#3c4043;
      font-size:13px; font-weight:600; font-family:'Montserrat',sans-serif;
      cursor:pointer;
      display:flex; align-items:center; justify-content:center; gap:9px;
      box-shadow:0 2px 10px rgba(0,0,0,0.25);
      transition:background .2s, box-shadow .2s, transform .1s;
    }
    .btn-google:hover {
      background:#f5f5f5;
      box-shadow:0 4px 16px rgba(0,0,0,0.30);
      transform:translateY(-1px);
    }
    .btn-google:active { transform:translateY(0); }
    .btn-google svg { width:18px; height:18px; flex-shrink:0; }

    .btn-guest {
      width:100%; height:42px; margin-top:8px;
      border:1px solid rgba(255,255,255,0.16);
      border-radius:10px;
      background:rgba(255,255,255,0.06);
      color:rgba(255,255,255,0.80);
      font-size:13px; font-weight:600; font-family:'Montserrat',sans-serif;
      cursor:pointer;
      display:flex; align-items:center; justify-content:center; gap:9px;
      transition:background .2s, border-color .2s, transform .1s;
    }
    .btn-guest:hover {
      background:rgba(255,255,255,0.11);
      border-color:rgba(255,255,255,0.28);
      transform:translateY(-1px);
    }
    .btn-guest:active { transform:translateY(0); }
    .btn-guest svg { width:15px; height:15px; fill:none; stroke:currentColor; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; opacity:0.75; }

    .ripple-el {
      position:absolute; border-radius:50%; transform:scale(0);
      background:rgba(255,255,255,0.25);
      animation:ripple .55s linear;
      pointer-events:none;
    }
    @keyframes ripple { to { transform:scale(5); opacity:0; } }

    .success-overlay {
      position:absolute; inset:0;
      background:rgba(8,12,25,0.65);
      backdrop-filter:blur(10px);
      display:flex; flex-direction:column;
      align-items:center; justify-content:center;
      border-radius:20px;
      opacity:0; pointer-events:none;
      transition:opacity .35s; z-index:10;
    }
    .success-overlay.show { opacity:1; pointer-events:all; }
    .check-circle {
      width:58px; height:58px; border-radius:50%;
      background:#22c55e;
      display:flex; align-items:center; justify-content:center;
      font-size:26px;
      animation:popIn .4s cubic-bezier(.34,1.56,.64,1) both;
    }
    @keyframes popIn {
      from { transform:scale(0); opacity:0; }
      to   { transform:scale(1); opacity:1; }
    }
    .success-overlay p     { margin-top:13px; font-size:14px; font-weight:700; color:#fff; }
    .success-overlay small { margin-top:5px;  font-size:11px; color:rgba(255,255,255,0.55); }

    .toast {
      position:fixed;
      bottom:24px; left:50%;
      transform:translateX(-50%) translateY(16px);
      color:#fff; font-size:12.5px; font-weight:600;
      padding:10px 20px; border-radius:50px;
      backdrop-filter:blur(10px);
      transition:transform .35s cubic-bezier(.34,1.56,.64,1), opacity .3s;
      opacity:0; z-index:9999;
      white-space:nowrap; pointer-events:none;
      max-width:calc(100vw - 40px); text-align:center;
    }
    .toast.error   { background:rgba(239,68,68,0.96);  box-shadow:0 4px 20px rgba(239,68,68,0.35); }
    .toast.success { background:rgba(34,197,94,0.96);   box-shadow:0 4px 20px rgba(34,197,94,0.35); }
    .toast.show    { transform:translateX(-50%) translateY(0); opacity:1; }

    /* ── FORGOT PASSWORD MODAL ── */
    .modal-backdrop {
      position:fixed; inset:0; z-index:100;
      background:rgba(8,12,25,0.72);
      backdrop-filter:blur(8px);
      -webkit-backdrop-filter:blur(8px);
      display:flex; align-items:center; justify-content:center;
      padding:20px;
      opacity:0; pointer-events:none;
      transition:opacity .28s ease;
    }
    .modal-backdrop.open { opacity:1; pointer-events:all; }

    .modal {
      width:100%; max-width:360px;
      background:rgba(20,28,52,0.95);
      border:1px solid rgba(255,255,255,0.16);
      border-radius:20px;
      padding:28px 26px 24px;
      box-shadow:0 20px 60px rgba(0,0,0,0.55);
      transform:translateY(18px) scale(0.97);
      transition:transform .32s cubic-bezier(.34,1.2,.64,1), opacity .28s ease;
      opacity:0;
    }
    .modal-backdrop.open .modal {
      transform:translateY(0) scale(1);
      opacity:1;
    }

    .modal-header {
      display:flex; align-items:flex-start; justify-content:space-between;
      margin-bottom:6px;
    }
    .modal-header h2 { font-size:20px; font-weight:800; letter-spacing:-0.3px; }
    .modal-close {
      background:none; border:none; cursor:pointer;
      color:rgba(255,255,255,0.45); padding:2px;
      display:flex; align-items:center; justify-content:center;
      transition:color .2s; flex-shrink:0; margin-top:2px;
    }
    .modal-close:hover { color:#fff; }
    .modal-close svg { width:18px; height:18px; fill:none; stroke:currentColor; stroke-width:2; stroke-linecap:round; }

    .modal p.modal-sub {
      font-size:12px; color:rgba(255,255,255,0.55);
      line-height:1.55; margin-bottom:16px;
    }

    .modal-sent { display:none; flex-direction:column; align-items:center; text-align:center; padding:8px 0 4px; }
    .modal-sent.show { display:flex; }
    .modal-form-area.hidden { display:none; }

    .sent-icon {
      width:52px; height:52px; border-radius:50%;
      background:rgba(37,99,235,0.20);
      border:2px solid rgba(96,165,250,0.40);
      display:flex; align-items:center; justify-content:center;
      margin-bottom:12px;
      animation:popIn .4s cubic-bezier(.34,1.56,.64,1) both;
    }
    .sent-icon svg { width:24px; height:24px; fill:none; stroke:#60a5fa; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
    .modal-sent h3  { font-size:15px; font-weight:700; margin-bottom:6px; }
    .modal-sent p   { font-size:12px; color:rgba(255,255,255,0.55); line-height:1.6; }
    .modal-sent .btn-back {
      margin-top:18px; background:none; border:1px solid rgba(255,255,255,0.18);
      border-radius:10px; color:rgba(255,255,255,0.70);
      font-size:12px; font-weight:600; font-family:'Montserrat',sans-serif;
      padding:9px 24px; cursor:pointer;
      transition:background .2s, color .2s, border-color .2s;
    }
    .modal-sent .btn-back:hover {
      background:rgba(255,255,255,0.08); color:#fff; border-color:rgba(255,255,255,0.30);
    }

    /* ── RESPONSIVE ── */
    @media (max-width: 900px) {
      .hero-overlay { background:linear-gradient(180deg, rgba(8,12,25,.55) 0%, rgba(8,12,25,.62) 100%); }
      .page { justify-content:center; padding:24px 20px; align-items:center; }
      .card-wrap { max-width:420px; }
    }
    @media (max-width: 480px) {
      .hero-bg img { object-position:55% center; }
      .hero-overlay { background:rgba(8,12,25,0.58); }
      .page { padding:16px; align-items:center; min-height:100dvh; }
      .card-wrap { max-width:100%; border-radius:18px; }
      .panel { padding:22px 18px 18px; }
      .panel h1 { font-size:23px; }
      .glass-input { font-size:14px; height:44px; }
      .btn-primary, .btn-google, .btn-guest { height:44px; font-size:13.5px; }
    }
    @supports (padding: env(safe-area-inset-bottom)) {
      .page { padding-bottom: calc(16px + env(safe-area-inset-bottom)); }
    }
  </style>
</head>
<body class="splash-active">

<!-- ═══ SPLASH / LOADING SCREEN ═══ -->
<div id="splashScreen">
  <video id="splashVideo" autoplay muted playsinline webkit-playsinline preload="auto">
    <source src="Animation2.mp4" type="video/mp4">
  </video>
  <div class="splash-loader"><div class="splash-loader-bar" id="splashLoaderBar"></div></div>
</div>

<div class="hero-bg">
  <img src="Modern.jpg" alt="Bacolod City">
  <div class="hero-overlay"></div>
</div>

<div class="page">
  <div class="card-wrap" id="cardWrap">
    <div class="card-inner">

      <div class="success-overlay" id="successOverlay">
        <div class="check-circle">✓</div>
        <p id="successMsg">Logged in!</p>
        <small id="successSub">Redirecting…</small>
      </div>

      <div class="slider" id="slider">

        <!-- ═══ LOGIN PANEL ═══ -->
        <div class="panel" id="loginPanel">
          <h1>Login</h1>
          <p class="subtitle">Welcome back! Please login to your account.</p>

          <div class="field">
            <div class="input-wrap">
              <span class="input-icon">
                <svg viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 7l10 7 10-7"/></svg>
              </span>
              <input id="loginEmail" class="glass-input" type="email" placeholder="Email" autocomplete="email">
            </div>
          </div>

          <div class="field">
            <div class="input-wrap">
              <span class="input-icon">
                <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              </span>
              <input id="loginPassword" class="glass-input" type="password" placeholder="Password" autocomplete="current-password">
              <button type="button" class="pw-toggle" onclick="togglePw('loginPassword')" aria-label="Toggle password">
                <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
          </div>

          <button class="btn-primary" id="loginBtn" onclick="handleLogin(event)">
            <span class="spinner"></span>
            <span class="btn-label">Login</span>
          </button>

          <button class="forgot" onclick="openForgotModal()">Forgot Password?</button>

          <div class="divider"><span>or continue with</span></div>

          <!-- Google Login Button -->
          <button class="btn-google" onclick="handleGoogle()">
            <!-- Official Google "G" logo SVG -->
            <svg viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
              <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
              <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
              <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
              <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
              <path fill="none" d="M0 0h48v48H0z"/>
            </svg>
            Continue with Google
          </button>

          <button class="btn-guest" onclick="handleGuest()">
            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Continue as Guest
          </button>

          <p class="switch-link">Don't have an account? <span onclick="switchTo('register')">Register</span></p>
        </div>

        <!-- ═══ REGISTER PANEL ═══ -->
        <div class="panel" id="registerPanel">
          <h1>Register</h1>
          <p class="subtitle">Create your account to get started.</p>

          <div class="field">
            <div class="input-wrap">
              <span class="input-icon">
                <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              </span>
              <input id="regName" class="glass-input" type="text" placeholder="Full Name" autocomplete="name">
            </div>
          </div>

          <div class="field">
            <div class="input-wrap">
              <span class="input-icon">
                <svg viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 7l10 7 10-7"/></svg>
              </span>
              <input id="regEmail" class="glass-input" type="email" placeholder="Email" autocomplete="email">
            </div>
          </div>

          <div class="field">
            <div class="input-wrap">
              <span class="input-icon">
                <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              </span>
              <input id="regPassword" class="glass-input" type="password" placeholder="Password" autocomplete="new-password">
              <button type="button" class="pw-toggle" onclick="togglePw('regPassword')" aria-label="Toggle password">
                <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
          </div>

          <div class="field">
            <div class="input-wrap">
              <span class="input-icon">
                <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              </span>
              <input id="regPassword2" class="glass-input" type="password" placeholder="Confirm Password" autocomplete="new-password">
              <button type="button" class="pw-toggle" onclick="togglePw('regPassword2')" aria-label="Toggle password">
                <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
          </div>

          <button class="btn-primary" id="registerBtn" onclick="handleRegister(event)">
            <span class="spinner"></span>
            <span class="btn-label">Create Account</span>
          </button>

          <p class="switch-link">Already have an account? <span onclick="switchTo('login')">Login</span></p>
        </div>

      </div><!-- /slider -->
    </div><!-- /card-inner -->
  </div><!-- /card-wrap -->
</div>

<!-- ═══ FORGOT PASSWORD MODAL ═══ -->
<div class="modal-backdrop" id="forgotBackdrop" onclick="handleBackdropClick(event)">
  <div class="modal" id="forgotModal" role="dialog" aria-modal="true" aria-labelledby="forgotTitle">

    <div class="modal-header">
      <h2 id="forgotTitle">Reset Password</h2>
      <button class="modal-close" onclick="closeForgotModal()" aria-label="Close">
        <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>

    <div class="modal-form-area" id="forgotFormArea">
      <p class="modal-sub">Enter the email address linked to your account and we'll send you a reset link.</p>

      <div class="field">
        <div class="input-wrap">
          <span class="input-icon">
            <svg viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 7l10 7 10-7"/></svg>
          </span>
          <input id="forgotEmail" class="glass-input" type="email" placeholder="Your email address" autocomplete="email">
        </div>
      </div>

      <button class="btn-primary" id="forgotBtn" onclick="handleForgot(event)" style="margin-top:12px;">
        <span class="spinner"></span>
        <span class="btn-label">Send Reset Link</span>
      </button>
    </div>

    <div class="modal-sent" id="forgotSentArea">
      <div class="sent-icon">
        <svg viewBox="0 0 24 24"><path d="M22 2L11 13"/><path d="M22 2L15 22l-4-9-9-4 20-7z"/></svg>
      </div>
      <h3>Check your inbox</h3>
      <p>We've sent a reset link to <strong id="sentEmailDisplay"></strong>. It expires in 1 hour.</p>
      <button class="btn-back" onclick="closeForgotModal()">Back to Login</button>
    </div>

  </div>
</div>

<div class="toast" id="toast"></div>

<script>
  // ── SPLASH SCREEN ─────────────────────────────────────────
  (function () {
    const splash     = document.getElementById('splashScreen');
    const video      = document.getElementById('splashVideo');
    const loaderBar  = document.getElementById('splashLoaderBar');
    const FALLBACK_MS = 6500; // safety timeout in case the video can't load/play
    let dismissed = false;

    function dismissSplash() {
      if (dismissed) return;
      dismissed = true;
      splash.classList.add('fade-out');
      document.body.classList.remove('splash-active');
      setTimeout(() => splash.classList.add('gone'), 650);
    }

    video.addEventListener('ended', dismissSplash);
    video.addEventListener('error', dismissSplash);

    // Drive the little progress bar off actual video playback time
    video.addEventListener('timeupdate', () => {
      if (video.duration) {
        loaderBar.style.width = Math.min(100, (video.currentTime / video.duration) * 100) + '%';
      }
    });

    // Safety net: some mobile browsers can block autoplay entirely
    setTimeout(dismissSplash, FALLBACK_MS);

    // If autoplay with sound gets blocked, retry muted (should already be muted, but just in case)
    video.play().catch(() => { video.muted = true; video.play().catch(dismissSplash); });
  })();

  const slider        = document.getElementById('slider');
  const cardWrap      = document.getElementById('cardWrap');
  const loginPanel    = document.getElementById('loginPanel');
  const registerPanel = document.getElementById('registerPanel');
  let current = 'login';

  function syncHeight(panel) { cardWrap.style.height = panel.scrollHeight + 'px'; }
  window.addEventListener('load',   () => syncHeight(loginPanel));
  window.addEventListener('resize', () => syncHeight(current === 'login' ? loginPanel : registerPanel));

  function switchTo(target) {
    if (target === current) return;
    current = target;
    if (target === 'register') { slider.style.transform = 'translateX(-50%)'; syncHeight(registerPanel); }
    else                       { slider.style.transform = 'translateX(0)';    syncHeight(loginPanel); }
  }

  function togglePw(id) {
    const el = document.getElementById(id);
    el.type = el.type === 'password' ? 'text' : 'password';
  }

  function doRipple(e, btn) {
    const r = document.createElement('span');
    r.className = 'ripple-el';
    const size = Math.max(btn.clientWidth, btn.clientHeight);
    const rect = btn.getBoundingClientRect();
    r.style.cssText = `width:${size}px;height:${size}px;left:${e.clientX-rect.left-size/2}px;top:${e.clientY-rect.top-size/2}px`;
    btn.appendChild(r);
    r.addEventListener('animationend', () => r.remove());
  }

  let toastTimer = null;
  function showToast(msg, type = 'error') {
    const t = document.getElementById('toast');
    t.textContent = msg; t.className = `toast ${type}`;
    void t.offsetWidth; t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), 3500);
  }

  function showSuccess(msg, sub, dest) {
    const ov = document.getElementById('successOverlay');
    document.getElementById('successMsg').textContent = msg;
    document.getElementById('successSub').textContent = sub || 'Redirecting…';
    ov.classList.add('show');
    setTimeout(() => { window.location.href = dest; }, 2000);
  }

  function setLoading(btn, state) { btn.classList.toggle('loading', state); btn.disabled = state; }

  function fieldError(id) {
    const el = document.getElementById(id);
    el.classList.add('error');
    el.addEventListener('input', () => el.classList.remove('error'), { once: true });
  }

  // ── LOGIN ─────────────────────────────────────────────────
  function handleLogin(e) {
    const email    = document.getElementById('loginEmail').value.trim();
    const password = document.getElementById('loginPassword').value;
    if (!email)    { showToast('Please enter your email.');    fieldError('loginEmail');    return; }
    if (!password) { showToast('Please enter your password.'); fieldError('loginPassword'); return; }
    const btn = document.getElementById('loginBtn');
    if (btn.disabled) return;
    doRipple(e, btn); setLoading(btn, true);
    fetch('index.php', { method:'POST', body: new URLSearchParams({ action:'login', email, password }) })
      .then(r => r.json())
      .then(data => {
        if (data.ok) showSuccess('Welcome back!', 'Taking you to your dashboard…', data.redirect);
        else         { showToast(data.message); setLoading(btn, false); }
      })
      .catch(() => { showToast('Connection error. Please try again.'); setLoading(btn, false); });
  }

  // ── REGISTER ─────────────────────────────────────────────
  function handleRegister(e) {
    const fullName  = document.getElementById('regName').value.trim();
    const email     = document.getElementById('regEmail').value.trim();
    const password  = document.getElementById('regPassword').value;
    const password2 = document.getElementById('regPassword2').value;
    if (!fullName)                     { showToast('Please enter your full name.');        fieldError('regName');      return; }
    if (!email || !email.includes('@')) { showToast('Please enter a valid email.');        fieldError('regEmail');     return; }
    if (password.length < 6)           { showToast('Password must be at least 6 chars.'); fieldError('regPassword');  return; }
    if (password !== password2)        { showToast('Passwords do not match.');            fieldError('regPassword2'); return; }
    const btn = document.getElementById('registerBtn');
    if (btn.disabled) return;
    doRipple(e, btn); setLoading(btn, true);
    fetch('index.php', { method:'POST', body: new URLSearchParams({ action:'register', full_name:fullName, email, password, password2 }) })
      .then(r => r.json())
      .then(data => {
        if (data.ok) showSuccess('Account created!', 'Welcome aboard! Redirecting…', data.redirect);
        else         { showToast(data.message); setLoading(btn, false); }
      })
      .catch(() => { showToast('Connection error. Please try again.'); setLoading(btn, false); });
  }

  // ── GOOGLE LOGIN ──────────────────────────────────────────
  function handleGoogle() {
    window.location.href = 'index.php?action=google_auth';
  }

  // ── GUEST ─────────────────────────────────────────────────
  function handleGuest() {
    window.location.href = 'commuter/commuter.php?guest=1';
}

  // ── FORGOT PASSWORD MODAL ─────────────────────────────────
  function openForgotModal() {
    document.getElementById('forgotFormArea').classList.remove('hidden');
    document.getElementById('forgotSentArea').classList.remove('show');
    document.getElementById('forgotEmail').value = '';
    document.getElementById('forgotEmail').classList.remove('error');
    setLoading(document.getElementById('forgotBtn'), false);
    document.getElementById('forgotBackdrop').classList.add('open');
    setTimeout(() => document.getElementById('forgotEmail').focus(), 320);
  }

  function closeForgotModal() {
    document.getElementById('forgotBackdrop').classList.remove('open');
  }

  function handleBackdropClick(e) {
    if (e.target === document.getElementById('forgotBackdrop')) closeForgotModal();
  }

  function handleForgot(e) {
    const email = document.getElementById('forgotEmail').value.trim();
    if (!email || !email.includes('@')) { showToast('Please enter a valid email address.'); fieldError('forgotEmail'); return; }
    const btn = document.getElementById('forgotBtn');
    if (btn.disabled) return;
    doRipple(e, btn); setLoading(btn, true);
    fetch('index.php', { method:'POST', body: new URLSearchParams({ action:'forgot_password', email }) })
      .then(r => r.json())
      .then(data => {
        if (data.ok) {
          document.getElementById('sentEmailDisplay').textContent = email;
          document.getElementById('forgotFormArea').classList.add('hidden');
          document.getElementById('forgotSentArea').classList.add('show');
        } else { showToast(data.message); setLoading(btn, false); }
      })
      .catch(() => { showToast('Connection error. Please try again.'); setLoading(btn, false); });
  }

  // ── KEYBOARD ──────────────────────────────────────────────
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeForgotModal(); return; }
    if (e.key !== 'Enter') return;
    if (document.getElementById('forgotBackdrop').classList.contains('open')) { handleForgot({ clientX:0, clientY:0 }); return; }
    if (current === 'login')    handleLogin({ clientX:0, clientY:0 });
    if (current === 'register') handleRegister({ clientX:0, clientY:0 });
  });
</script>
</body>
</html>