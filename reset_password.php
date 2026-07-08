<?php
// ════════════════════════════════════════════════════════════
//  JEEPLIFY BCD — reset_password.php
//  Handles the token link sent by the forgot-password flow.
//  GET  ?token=xxx  → show the form
//  POST             → validate & update password
// ════════════════════════════════════════════════════════════

session_start();
require_once 'db.php';

function jsonOut(bool $ok, string $msg, array $extra = []): void {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok' => $ok, 'message' => $msg], $extra));
    exit;
}

$token      = trim($_GET['token'] ?? '');
$tokenValid = false;
$tokenError = '';
$accountId  = null;

// ── Validate token (GET & POST both need this) ───────────────
if ($token) {
    try {
        $stmt = $pdo->prepare(
            'SELECT account_id, expires_at FROM password_resets WHERE token = ? LIMIT 1'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        if ($row) {
            if (new DateTime('now') < new DateTime($row['expires_at'])) {
                $tokenValid = true;
                $accountId  = (int) $row['account_id'];
            } else {
                $tokenError = 'This reset link has expired. Please request a new one.';
            }
        } else {
            $tokenError = 'Invalid or already used reset link. Please request a new one.';
        }
    } catch (PDOException $e) {
        error_log('reset_password lookup: ' . $e->getMessage());
        $tokenError = 'A server error occurred. Please try again.';
    }
} else {
    $tokenError = 'No reset token provided.';
}

// ── Handle POST (JSON response for AJAX) ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$tokenValid) jsonOut(false, $tokenError ?: 'Invalid reset link.');

    $password  = $_POST['password']  ?? '';
    $password2 = $_POST['password2'] ?? '';

    if (strlen($password) < 6)      jsonOut(false, 'Password must be at least 6 characters.');
    if ($password !== $password2)   jsonOut(false, 'Passwords do not match.');

    try {
        // Re-check token hasn't been used/expired between page load and submit
        $stmt = $pdo->prepare(
            'SELECT account_id, expires_at FROM password_resets WHERE token = ? LIMIT 1'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        if (!$row || new DateTime('now') >= new DateTime($row['expires_at'])) {
            jsonOut(false, 'Reset link has expired. Please request a new one.');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);

        $pdo->prepare('UPDATE accounts SET password_hash = ? WHERE id = ?')
            ->execute([$hash, $row['account_id']]);

        // Invalidate the token
        $pdo->prepare('DELETE FROM password_resets WHERE account_id = ?')
            ->execute([$row['account_id']]);

        jsonOut(true, 'Password reset successfully!', ['redirect' => 'index.php']);

    } catch (PDOException $e) {
        error_log('reset_password update: ' . $e->getMessage());
        jsonOut(false, 'Server error. Please try again.');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"/>
  <title>Jeeplify — Reset Password</title>
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

    .hero-bg { position:fixed; inset:0; z-index:0; }
    .hero-bg img { width:100%; height:100%; object-fit:cover; object-position:center; }
    .hero-overlay {
      position:absolute; inset:0;
      background:linear-gradient(90deg,rgba(8,12,25,.90) 0%,rgba(8,12,25,.60) 40%,rgba(8,12,25,.20) 70%,rgba(8,12,25,.10) 100%);
    }

    .page {
      position:relative; z-index:2;
      width:100%; min-height:100vh;
      display:flex; justify-content:flex-start; align-items:center;
      padding:40px clamp(32px,6vw,100px);
    }

    .card-wrap {
      width:100%; max-width:380px;
      border-radius:20px;
      background:rgba(255,255,255,0.10);
      border:1px solid rgba(255,255,255,0.16);
      backdrop-filter:blur(22px);
      -webkit-backdrop-filter:blur(22px);
      box-shadow:0 16px 56px rgba(0,0,0,0.40);
      overflow:hidden;
    }
    .panel { padding:28px 26px 26px; }

    .panel h1 { font-size:26px; font-weight:800; margin-bottom:4px; letter-spacing:-0.5px; }
    .subtitle  { color:rgba(255,255,255,0.60); font-size:11.5px; margin-bottom:20px; line-height:1.5; }

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
      color:#fff; font-size:13px; font-family:'Montserrat',sans-serif;
      outline:none;
      transition:border-color .2s, box-shadow .2s, background .2s;
    }
    .glass-input::placeholder { color:rgba(255,255,255,0.35); }
    .glass-input:focus {
      border-color:#60a5fa;
      box-shadow:0 0 0 3px rgba(96,165,250,0.15);
      background:rgba(255,255,255,0.09);
    }
    .glass-input.error { border-color:#f87171; box-shadow:0 0 0 3px rgba(248,113,113,0.13); }

    .pw-toggle {
      position:absolute; right:10px; top:50%; transform:translateY(-50%);
      background:none; border:none; cursor:pointer;
      color:rgba(255,255,255,0.45); padding:4px;
      display:flex; align-items:center; justify-content:center; transition:color .2s;
    }
    .pw-toggle:hover { color:rgba(255,255,255,0.85); }
    .pw-toggle svg { width:15px; height:15px; fill:none; stroke:currentColor; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; }

    .btn-primary {
      width:100%; height:42px; margin-top:10px;
      border:none; border-radius:10px;
      background:#2563eb; color:#fff;
      font-size:13px; font-weight:700; font-family:'Montserrat',sans-serif;
      cursor:pointer;
      box-shadow:0 5px 18px rgba(37,99,235,0.38);
      display:flex; align-items:center; justify-content:center; gap:8px;
      transition:background .2s, box-shadow .2s, transform .1s;
    }
    .btn-primary:hover:not(:disabled) { background:#1d4ed8; transform:translateY(-1px); }
    .btn-primary:active:not(:disabled) { transform:translateY(0); }
    .btn-primary:disabled { opacity:.60; cursor:not-allowed; transform:none; }

    .spinner {
      width:15px; height:15px;
      border:2px solid rgba(255,255,255,0.3); border-top-color:#fff;
      border-radius:50%; animation:spin .7s linear infinite; display:none;
    }
    @keyframes spin { to { transform:rotate(360deg); } }
    .btn-primary.loading .spinner  { display:block; }
    .btn-primary.loading .btn-label { opacity:.7; }

    .error-box {
      background:rgba(239,68,68,0.13); border:1px solid rgba(239,68,68,0.30);
      border-radius:12px; padding:14px 16px; margin-bottom:18px;
      font-size:12.5px; color:#fca5a5; line-height:1.5;
    }

    .success-overlay {
      position:absolute; inset:0;
      background:rgba(8,12,25,0.65); backdrop-filter:blur(10px);
      display:flex; flex-direction:column; align-items:center; justify-content:center;
      border-radius:20px; opacity:0; pointer-events:none;
      transition:opacity .35s; z-index:10;
    }
    .success-overlay.show { opacity:1; pointer-events:all; }
    .check-circle {
      width:58px; height:58px; border-radius:50%; background:#22c55e;
      display:flex; align-items:center; justify-content:center; font-size:26px;
      animation:popIn .4s cubic-bezier(.34,1.56,.64,1) both;
    }
    @keyframes popIn { from{transform:scale(0);opacity:0} to{transform:scale(1);opacity:1} }
    .success-overlay p    { margin-top:13px; font-size:14px; font-weight:700; color:#fff; }
    .success-overlay small{ margin-top:5px;  font-size:11px; color:rgba(255,255,255,0.55); }

    .back-link { display:block; margin-top:12px; text-align:center; font-size:11.5px; color:rgba(255,255,255,0.45); }
    .back-link a { color:#60a5fa; font-weight:600; text-decoration:none; }
    .back-link a:hover { color:#93c5fd; }

    .toast {
      position:fixed; bottom:24px; left:50%; transform:translateX(-50%) translateY(16px);
      color:#fff; font-size:12.5px; font-weight:600;
      padding:10px 20px; border-radius:50px; backdrop-filter:blur(10px);
      transition:transform .35s cubic-bezier(.34,1.56,.64,1), opacity .3s;
      opacity:0; z-index:9999; white-space:nowrap; pointer-events:none;
      max-width:calc(100vw - 40px); text-align:center;
    }
    .toast.error   { background:rgba(239,68,68,0.96);  box-shadow:0 4px 20px rgba(239,68,68,0.35); }
    .toast.success { background:rgba(34,197,94,0.96);  box-shadow:0 4px 20px rgba(34,197,94,0.35); }
    .toast.show    { transform:translateX(-50%) translateY(0); opacity:1; }

    @media (max-width:900px) { .page { justify-content:center; padding:24px 20px; } }
    @media (max-width:480px) { .page { padding:16px; min-height:100dvh; } .card-wrap { max-width:100%; } }
  </style>
</head>
<body>

<div class="hero-bg">
  <img src="Modern.jpg" alt="Bacolod City">
  <div class="hero-overlay"></div>
</div>

<div class="page">
  <div class="card-wrap" style="position:relative;">

    <div class="success-overlay" id="successOverlay">
      <div class="check-circle">✓</div>
      <p>Password reset!</p>
      <small>Redirecting to login…</small>
    </div>

    <div class="panel">
      <h1>Reset Password</h1>

      <?php if (!$tokenValid): ?>
        <p class="subtitle">There was a problem with your reset link.</p>
        <div class="error-box">
          <?= htmlspecialchars($tokenError) ?>
        </div>
        <p class="back-link"><a href="index.php">← Back to Login</a></p>

      <?php else: ?>
        <p class="subtitle">Choose a new password for your Jeeplify account.</p>

        <div class="field">
          <div class="input-wrap">
            <span class="input-icon">
              <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </span>
            <input id="newPassword" class="glass-input" type="password" placeholder="New password" autocomplete="new-password">
            <button type="button" class="pw-toggle" onclick="togglePw('newPassword')" aria-label="Show/hide password">
              <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>

        <div class="field">
          <div class="input-wrap">
            <span class="input-icon">
              <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </span>
            <input id="confirmPassword" class="glass-input" type="password" placeholder="Confirm new password" autocomplete="new-password">
            <button type="button" class="pw-toggle" onclick="togglePw('confirmPassword')" aria-label="Show/hide password">
              <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>

        <button class="btn-primary" id="resetBtn" onclick="handleReset(event)">
          <span class="spinner"></span>
          <span class="btn-label">Set New Password</span>
        </button>

        <p class="back-link"><a href="index.php">← Back to Login</a></p>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<?php if ($tokenValid): ?>
<script>
  function togglePw(id) {
    const el = document.getElementById(id);
    el.type = el.type === 'password' ? 'text' : 'password';
  }

  let toastTimer;
  function showToast(msg, type = 'error') {
    const t = document.getElementById('toast');
    t.textContent = msg; t.className = `toast ${type}`;
    void t.offsetWidth; t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), 3500);
  }

  function fieldError(id) {
    const el = document.getElementById(id);
    el.classList.add('error');
    el.addEventListener('input', () => el.classList.remove('error'), { once: true });
  }

  function setLoading(btn, state) { btn.classList.toggle('loading', state); btn.disabled = state; }

  function handleReset(e) {
    const pw1 = document.getElementById('newPassword').value;
    const pw2 = document.getElementById('confirmPassword').value;

    if (pw1.length < 6) { showToast('Password must be at least 6 characters.'); fieldError('newPassword'); return; }
    if (pw1 !== pw2)    { showToast('Passwords do not match.'); fieldError('confirmPassword'); return; }

    const btn = document.getElementById('resetBtn');
    if (btn.disabled) return;
    setLoading(btn, true);

    fetch(window.location.href, {
      method: 'POST',
      body: new URLSearchParams({ password: pw1, password2: pw2 })
    })
    .then(r => r.json())
    .then(data => {
      if (data.ok) {
        document.getElementById('successOverlay').classList.add('show');
        setTimeout(() => { window.location.href = data.redirect || 'index.php'; }, 2200);
      } else {
        showToast(data.message);
        setLoading(btn, false);
      }
    })
    .catch(() => { showToast('Connection error. Please try again.'); setLoading(btn, false); });
  }

  document.addEventListener('keydown', e => {
    if (e.key === 'Enter') handleReset({ clientX:0, clientY:0 });
  });
</script>
<?php endif; ?>

</body>
</html>
