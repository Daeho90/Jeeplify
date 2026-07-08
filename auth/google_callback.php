<?php

session_start();

require_once '../db.php';

define('COMMUTER_REDIRECT', '../commuter/commuter.php');

// ── GOOGLE OAuth CONFIG ─────────────────────────────────────
define('GOOGLE_CLIENT_ID',     '304618062248-50e9c4n1ann090v0h9jefscjhni5t0h9.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', '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');
define('GOOGLE_REDIRECT_URI',  'https://bcd-jeepney.kesug.com/auth/google_callback.php');

// ── HELPERS ─────────────────────────────────────────────────
function bail(string $msg): void {
    $_SESSION['oauth_error'] = $msg;
    header('Location: ../index.php');
    exit;
}

// ── STEP 1: Validate state (CSRF protection) ─────────────────
$state = $_GET['state'] ?? '';
if (!$state || !isset($_SESSION['oauth_state']) || !hash_equals($_SESSION['oauth_state'], $state)) {
    bail('Invalid OAuth state. Please try again.');
}
unset($_SESSION['oauth_state']);

// ── STEP 2: Check for errors from Google ─────────────────────
if (isset($_GET['error'])) {
    bail('Google login was cancelled or denied.');
}

// ── STEP 3: Exchange code for access token ───────────────────
$code = $_GET['code'] ?? '';
if (!$code) bail('No authorization code received from Google.');

$tokenResponse = file_get_contents('https://oauth2.googleapis.com/token', false, stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query([
            'code'          => $code,
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'redirect_uri'  => GOOGLE_REDIRECT_URI,
            'grant_type'    => 'authorization_code',
        ]),
        'ignore_errors' => true,
    ],
]));

if (!$tokenResponse) bail('Failed to contact Google. Please try again.');

$tokenData = json_decode($tokenResponse, true);

if (empty($tokenData['access_token'])) {
    error_log('Google token error: ' . $tokenResponse);
    bail('Could not retrieve access token from Google.');
}

$accessToken = $tokenData['access_token'];

// ── STEP 4: Fetch user info from Google ──────────────────────
$userResponse = file_get_contents('https://www.googleapis.com/oauth2/v2/userinfo', false, stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Authorization: Bearer $accessToken\r\n",
        'ignore_errors' => true,
    ],
]));

if (!$userResponse) bail('Failed to fetch user info from Google.');

$googleUser = json_decode($userResponse, true);

if (empty($googleUser['email'])) {
    error_log('Google userinfo error: ' . $userResponse);
    bail('Could not retrieve your email from Google.');
}

$googleId   = $googleUser['id']             ?? '';
$email      = $googleUser['email']          ?? '';
$fullName   = $googleUser['name']           ?? 'Google User';
$avatar     = $googleUser['picture']        ?? '';
$verified   = $googleUser['verified_email'] ?? false;

if (!$verified) bail('Your Google email is not verified. Please verify it and try again.');

// ── STEP 5: Find or create account ──────────────────────────
try {
    $db = $pdo;

    // Check if account already exists by email
    $stmt = $db->prepare(
        'SELECT a.id, a.email, a.is_active, r.name AS role
         FROM accounts a
         JOIN roles r ON r.id = a.role_id
         WHERE a.email = ? LIMIT 1'
    );
    $stmt->execute([$email]);
    $acc = $stmt->fetch();

    if ($acc) {
        // ── Existing account: only allow commuter (user) role ──
        if ($acc['role'] !== 'user')
            bail('This Google account is linked to an operator or driver account. Please log in manually.');

        if (!$acc['is_active'])
            bail('Your account has been deactivated. Please contact support.');

        // Update last login
        $db->prepare('UPDATE accounts SET last_login_at = NOW() WHERE id = ?')
           ->execute([$acc['id']]);

        // Store google_id if not yet saved
        $db->prepare('UPDATE accounts SET google_id = ? WHERE id = ? AND (google_id IS NULL OR google_id = "")')
           ->execute([$googleId, $acc['id']]);

        session_regenerate_id(true);
        $_SESSION['account_id'] = $acc['id'];
        $_SESSION['email']      = $acc['email'];
        $_SESSION['role']       = 'user';

        header('Location: ' . COMMUTER_REDIRECT);
        exit;

    } else {
        // ── New account: register automatically ────────────
        $roleRow = $db->query("SELECT id FROM roles WHERE name='user' LIMIT 1")->fetch();
        if (!$roleRow) bail('Registration is currently unavailable. Please try again later.');

        $db->beginTransaction();

        $db->prepare(
            'INSERT INTO accounts (email, google_id, role_id, is_active, last_login_at)
             VALUES (?, ?, ?, 1, NOW())'
        )->execute([$email, $googleId, $roleRow['id']]);

        $newId = $db->lastInsertId();

        $db->prepare(
            'INSERT INTO user_profiles (account_id, full_name, avatar_url)
             VALUES (?, ?, ?)'
        )->execute([$newId, $fullName, $avatar]);

        $db->commit();

        session_regenerate_id(true);
        $_SESSION['account_id'] = $newId;
        $_SESSION['email']      = $email;
        $_SESSION['role']       = 'user';

        header('Location: ' . COMMUTER_REDIRECT);
        exit;
    }

} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('Google callback DB error: ' . $e->getMessage());
    bail('A server error occurred. Please try again.');
}