<?php
session_start();
require_once '../db.php';
$accountId = $_SESSION['account_id'] ?? null;
$isGuest   = isset($_GET['guest']) && $_GET['guest'] === '1';

if (!$accountId && !$isGuest) {
    header('Location: ../index.php');
    exit;
}
$userName  = 'Guest';
$userRole  = 'guest';
$userEmail = '';
$avatarUrl = null;
$initials  = '?';
if ($accountId) {
    try {
        $stmt = $pdo->prepare(
            'SELECT a.email, r.name AS role, up.full_name,
                    COALESCE(up.avatar_url, a.avatar_url) AS avatar_url
             FROM accounts a
             JOIN roles r ON r.id = a.role_id
             LEFT JOIN user_profiles up ON up.account_id = a.id
             WHERE a.id = ? LIMIT 1'
        );
        $stmt->execute([$accountId]);
        $user = $stmt->fetch();
        if ($user) {
            $userName  = $user['full_name'] ?: explode('@', $user['email'])[0];
            $userRole  = ucfirst($user['role']);
            $userEmail = $user['email'];
            $avatarUrl = $user['avatar_url'];
            $initials  = strtoupper(substr($userName, 0, 1));
        }
    } catch (PDOException $e) {
        error_log('Commuter profile fetch: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"/>
  <title>Jeeplify — Commuter Dashboard</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="fav.png"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css"/>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
  <style>
    :root {
      color-scheme: dark;
      --bg:     #0d1321;
      --panel:  rgba(13,20,38,0.97);
      --accent: #3b82f6;
      --green:  #10b981;
      --text:   #e8edf5;
      --muted:  rgba(232,237,245,0.50);
      --border: rgba(255,255,255,0.09);
      --card:   rgba(255,255,255,0.06);
      --hover:  rgba(255,255,255,0.11);
      --safe-b: env(safe-area-inset-bottom, 0px);
    }
    *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
    html, body { width:100%; height:100vh; height:var(--app-height, 100vh); overflow:hidden; font-family:'Montserrat',sans-serif; background:var(--bg); color:var(--text); }
    .app { position:relative; width:100%; height:100vh; height:var(--app-height, 100vh); display:flex; flex-direction:column; overflow:hidden; }
    .map-area { position:relative; flex:1; min-height:0; overflow:hidden; }
    #map { position:absolute; inset:0; width:100%; height:100%; z-index:1; }
    .leaflet-container { background:#0d1321 !important; }
    /* ── TOP BAR ── */
    .top-bar {
      position:absolute;
      top:calc(16px + env(safe-area-inset-top, 0px));
      left:16px; right:16px;
      display:flex; align-items:center; gap:10px;
      z-index:20;
      animation: fadeDown .38s .1s cubic-bezier(.22,1,.36,1) both;
    }
    @keyframes fadeDown { from{transform:translateY(-12px);opacity:0} to{transform:translateY(0);opacity:1} }
    .top-search-wrap { flex:1; position:relative; min-width:0; }
    .top-search-icon { position:absolute; left:16px; top:50%; transform:translateY(-50%); width:15px; height:15px; color:var(--muted); pointer-events:none; }
    .top-search {
      width:100%; height:50px; padding:0 20px 0 44px;
      border-radius:999px;
      border:1px solid rgba(255,255,255,0.13);
      background:rgba(13,19,33,.88);
      backdrop-filter:blur(24px) saturate(180%);
      -webkit-backdrop-filter:blur(24px) saturate(180%);
      color:var(--text); font-size:13.5px; font-family:'Montserrat',sans-serif;
      outline:none;
      box-shadow:0 4px 24px rgba(0,0,0,.38), 0 1px 0 rgba(255,255,255,.06) inset;
      transition:border-color .22s, box-shadow .22s;
    }
    .top-search::placeholder { color:var(--muted); }
    .top-search:focus {
      border-color:rgba(59,130,246,.55);
      box-shadow:0 0 0 4px rgba(59,130,246,.13), 0 4px 24px rgba(0,0,0,.38);
    }
    .top-icon-btn {
      width:50px; height:50px; border-radius:999px;
      border:1px solid rgba(255,255,255,0.13);
      background:rgba(13,19,33,.88);
      backdrop-filter:blur(24px) saturate(180%);
      -webkit-backdrop-filter:blur(24px) saturate(180%);
      color:var(--text); font-size:18px; flex-shrink:0;
      cursor:pointer; display:flex; align-items:center; justify-content:center;
      box-shadow:0 4px 24px rgba(0,0,0,.38), 0 1px 0 rgba(255,255,255,.06) inset;
      transition:background .2s, transform .15s;
    }
    .top-icon-btn:active { transform:scale(.92); background:rgba(30,42,72,.95); }
    /* ── LIVE COUNTER PILL ── */
    .live-pill {
      position:absolute;
      top:calc(80px + env(safe-area-inset-top, 0px));
      left:50%; transform:translateX(-50%);
      z-index:20;
      display:flex; align-items:center; gap:7px;
      background:rgba(13,19,33,.88);
      backdrop-filter:blur(20px);
      border:1px solid rgba(255,255,255,.10);
      border-radius:999px;
      padding:6px 14px;
      font-size:11px; font-weight:700; color:var(--muted);
      pointer-events:none;
      opacity:0; transition:opacity .3s;
      white-space:nowrap;
    }
    .live-pill.visible { opacity:1; }
    .live-dot {
      width:7px; height:7px; border-radius:50%;
      background:var(--green);
      animation:blink 1.6s ease-in-out infinite;
      flex-shrink:0;
    }
    @keyframes blink { 0%,100%{opacity:1} 50%{opacity:.25} }
    /* ── BOTTOM NAV ── */
    .bottom-nav {
      position:absolute;
      bottom:calc(20px + var(--safe-b));
      left:50%; transform:translateX(-50%);
      z-index:30;
      display:flex; align-items:center;
      background:rgba(12,17,32,.82);
      backdrop-filter:blur(28px) saturate(200%);
      -webkit-backdrop-filter:blur(28px) saturate(200%);
      border:1px solid rgba(255,255,255,0.12);
      border-radius:999px;
      padding:8px;
      box-shadow: 0 8px 40px rgba(0,0,0,.55), 0 1px 0 rgba(255,255,255,.08) inset;
      animation:floatUp .45s .08s cubic-bezier(.22,1,.36,1) both;
    }
    @keyframes floatUp { from{transform:translateX(-50%) translateY(28px);opacity:0} to{transform:translateX(-50%) translateY(0);opacity:1} }
    .nav-tab {
      display:flex; flex-direction:column; align-items:center; justify-content:center;
      gap:4px; padding:10px 20px; cursor:pointer;
      color:var(--muted); transition:color .2s;
      border-radius:999px; min-width:72px;
    }
    .nav-tab.active { color:#fff; background:rgba(59,130,246,.22); }
    .nav-tab.book-tab {
      padding:10px 22px;
      background:linear-gradient(135deg, rgba(37,99,235,.85), rgba(16,185,129,.70));
      color:#fff;
      border:1px solid rgba(255,255,255,.18);
      box-shadow:0 2px 14px rgba(37,99,235,.35);
    }
    .nav-tab.book-tab:active { opacity:.82; transform:scale(.95); }
    .nav-tab:not(.book-tab):active { background:rgba(255,255,255,.08); }
    .nav-tab svg { width:20px; height:20px; flex-shrink:0; }
    .nav-tab span { font-size:9px; font-weight:700; letter-spacing:.04em; white-space:nowrap; }
    .nav-profile-avatar {
      width:26px; height:26px; border-radius:50%;
      border:2px solid rgba(59,130,246,.6);
      overflow:hidden; flex-shrink:0;
      background:linear-gradient(135deg,#2563eb,#10b981);
      display:flex; align-items:center; justify-content:center;
    }
    .nav-profile-avatar img { width:100%; height:100%; object-fit:cover; display:block; }
    .nav-profile-avatar .nav-initials { font-size:10px; font-weight:800; color:#fff; line-height:1; }
    /* ── PROFILE CARD ── */
    .profile-card-overlay {
      position:fixed; inset:0; z-index:50;
      background:rgba(5,8,18,.45);
      backdrop-filter:blur(3px); -webkit-backdrop-filter:blur(3px);
      opacity:0; pointer-events:none;
      transition:opacity .22s ease;
    }
    .profile-card-overlay.open { opacity:1; pointer-events:all; }
    .profile-card {
      position:fixed;
      bottom:calc(96px + var(--safe-b));
      left:50%;
      transform:translateX(-50%) translateY(18px);
      z-index:51;
      width:min(300px, calc(100vw - 40px));
      background:rgba(13,20,38,.97);
      border:1px solid rgba(255,255,255,.12);
      border-radius:22px;
      padding:20px;
      box-shadow:0 16px 48px rgba(0,0,0,.65), 0 1px 0 rgba(255,255,255,.07) inset;
      opacity:0; pointer-events:none;
      transition:opacity .22s ease, transform .25s cubic-bezier(.22,1,.36,1);
    }
    .profile-card.open {
      opacity:1; pointer-events:all;
      transform:translateX(-50%) translateY(0);
    }
    .profile-card::after {
      content:'';
      position:absolute;
      bottom:-7px; left:50%;
      width:14px; height:14px;
      background:rgba(13,20,38,.97);
      border-right:1px solid rgba(255,255,255,.12);
      border-bottom:1px solid rgba(255,255,255,.12);
      transform:translateX(-50%) rotate(45deg);
    }
    .pc-header {
      display:flex; align-items:center; gap:13px; margin-bottom:16px;
      padding-bottom:16px; border-bottom:1px solid var(--border);
    }
    .pc-avatar {
      width:52px; height:52px; border-radius:50%; flex-shrink:0;
      border:2.5px solid rgba(59,130,246,.55);
      box-shadow:0 0 0 4px rgba(59,130,246,.12);
      overflow:hidden;
      background:linear-gradient(135deg,#2563eb,#10b981);
      display:flex; align-items:center; justify-content:center;
    }
    .pc-avatar img { width:100%; height:100%; object-fit:cover; display:block; }
    .pc-avatar .pc-initials { font-size:20px; font-weight:800; color:#fff; }
    .pc-info { flex:1; min-width:0; }
    .pc-name { font-size:14px; font-weight:800; letter-spacing:-.2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .pc-email { font-size:10.5px; color:var(--muted); margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .pc-badge {
      display:inline-flex; align-items:center; margin-top:6px;
      font-size:9px; font-weight:700; letter-spacing:.06em; text-transform:uppercase;
      color:rgba(59,130,246,.95); background:rgba(59,130,246,.12);
      border:1px solid rgba(59,130,246,.22); border-radius:20px; padding:2px 9px;
    }
    .pc-logout {
      width:100%; height:40px;
      display:flex; align-items:center; justify-content:center; gap:7px;
      border:1px solid rgba(239,68,68,.35); border-radius:12px;
      background:rgba(239,68,68,.08); color:rgba(239,68,68,.85);
      font-size:12px; font-weight:700; font-family:'Montserrat',sans-serif;
      cursor:pointer; transition:background .2s;
    }
    .pc-logout:active { background:rgba(239,68,68,.22); }
    .pc-logout svg { width:13px; height:13px; }
    /* ── LEAFLET ── */
    .leaflet-control-zoom { display:none !important; }
    .leaflet-popup-content-wrapper {
      background:rgba(13,20,38,.96)!important;
      backdrop-filter:blur(16px);
      border:1px solid var(--border)!important;
      border-radius:16px!important;
      box-shadow:0 8px 28px rgba(0,0,0,.45)!important;
      color:var(--text)!important;
      font-family:'Montserrat',sans-serif!important;
    }
    .leaflet-popup-content { margin:0!important; }
    .leaflet-popup-tip-container { display:none; }
    .leaflet-popup-close-button { color:var(--muted)!important; font-size:18px!important; top:8px!important; right:10px!important; }
    .leaflet-control-attribution { background:rgba(10,14,26,.75)!important; color:rgba(255,255,255,.35)!important; font-size:9px!important; }
    /* ── SEARCH RESULTS DROPDOWN ── */
    .search-results {
      position:absolute; top:calc(100% + 8px); left:0; right:0;
      background:rgba(13,19,33,.97);
      backdrop-filter:blur(24px) saturate(180%);
      -webkit-backdrop-filter:blur(24px) saturate(180%);
      border:1px solid rgba(255,255,255,.13);
      border-radius:18px;
      box-shadow:0 12px 36px rgba(0,0,0,.5);
      max-height:min(320px, 50vh);
      overflow-y:auto;
      opacity:0; transform:translateY(-6px);
      pointer-events:none;
      transition:opacity .18s ease, transform .18s ease;
      z-index:25;
    }
    .search-results.open { opacity:1; transform:translateY(0); pointer-events:all; }
    .sr-item {
      display:flex; align-items:center; gap:11px;
      padding:11px 16px; cursor:pointer;
      border-bottom:1px solid rgba(255,255,255,.06);
      transition:background .15s;
    }
    .sr-item:last-child { border-bottom:none; }
    .sr-item:active, .sr-item:hover { background:rgba(255,255,255,.07); }
    .sr-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
    .sr-text { flex:1; min-width:0; }
    .sr-title { font-size:12.5px; font-weight:700; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .sr-sub { font-size:10.5px; color:var(--muted); margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .sr-empty { padding:18px 16px; text-align:center; font-size:11.5px; color:var(--muted); }
    /* ── SHEETS (Routes / Booking) ── */
    .sheet-overlay {
      position:fixed; inset:0; z-index:60;
      background:rgba(5,8,18,.55);
      backdrop-filter:blur(3px); -webkit-backdrop-filter:blur(3px);
      opacity:0; pointer-events:none;
      transition:opacity .25s ease;
    }
    .sheet-overlay.open { opacity:1; pointer-events:all; }
    .sheet {
      position:fixed; left:0; right:0; bottom:0; z-index:61;
      max-width:560px; margin:0 auto;
      max-height:min(82vh, 720px);
      display:flex; flex-direction:column;
      background:rgba(13,18,33,.98);
      backdrop-filter:blur(28px) saturate(180%);
      -webkit-backdrop-filter:blur(28px) saturate(180%);
      border:1px solid rgba(255,255,255,.12);
      border-bottom:none;
      border-radius:26px 26px 0 0;
      box-shadow:0 -12px 48px rgba(0,0,0,.55);
      transform:translateY(100%);
      transition:transform .32s cubic-bezier(.22,1,.36,1);
      padding-bottom:var(--safe-b);
    }
    .sheet.open { transform:translateY(0); }
    .sheet-grabber { width:38px; height:4px; border-radius:99px; background:rgba(255,255,255,.18); margin:10px auto 0; flex-shrink:0; }
    .sheet-head {
      display:flex; align-items:center; justify-content:space-between;
      padding:14px 20px 12px; flex-shrink:0;
      border-bottom:1px solid var(--border);
    }
    .sheet-title { font-size:16px; font-weight:800; letter-spacing:-.2px; }
    .sheet-close {
      width:32px; height:32px; border-radius:50%;
      border:1px solid rgba(255,255,255,.12); background:rgba(255,255,255,.05);
      color:var(--text); display:flex; align-items:center; justify-content:center;
      cursor:pointer; flex-shrink:0;
    }
    .sheet-close:active { background:rgba(255,255,255,.12); }
    .sheet-close svg { width:14px; height:14px; }
    .sheet-body { flex:1; overflow-y:auto; padding:16px 20px 26px; -webkit-overflow-scrolling:touch; }
    /* ── Route cards ── */
    .route-card {
      background:var(--card); border:1px solid var(--border); border-radius:18px;
      padding:16px; margin-bottom:12px;
    }
    .route-card-top { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
    .route-name { font-size:13.5px; font-weight:800; line-height:1.35; }
    .route-desc { font-size:11px; color:var(--muted); margin-top:4px; line-height:1.5; }
    .route-stats { display:flex; gap:8px; margin-top:12px; flex-wrap:wrap; }
    .route-stat {
      display:flex; align-items:center; gap:5px;
      font-size:10.5px; font-weight:700; color:var(--text);
      background:rgba(255,255,255,.05); border:1px solid var(--border);
      border-radius:999px; padding:5px 11px;
    }
    .route-stat .dot { width:6px; height:6px; border-radius:50%; flex-shrink:0; }
    .route-units { margin-top:12px; display:flex; flex-direction:column; gap:6px; }
    .live-unit-row {
      display:flex; align-items:center; gap:10px;
      padding:9px 11px; border-radius:12px;
      background:rgba(16,185,129,.07); border:1px solid rgba(16,185,129,.18);
      cursor:pointer; transition:background .15s;
    }
    .live-unit-row:active, .live-unit-row:hover { background:rgba(16,185,129,.14); }
    .live-unit-row .pulse-dot {
      width:8px; height:8px; border-radius:50%; flex-shrink:0;
      box-shadow:0 0 0 3px rgba(16,185,129,.18);
    }
    .live-unit-row .lu-text { flex:1; min-width:0; }
    .live-unit-row .lu-code { font-size:12px; font-weight:800; color:var(--text); }
    .live-unit-row .lu-plate { font-size:10px; color:var(--muted); margin-top:1px; }
    .live-unit-row .lu-go { font-size:10px; font-weight:700; color:#34d399; flex-shrink:0; }
    .route-units-empty { margin-top:12px; padding:14px; text-align:center; font-size:11px; color:var(--muted); background:rgba(255,255,255,.03); border:1px dashed var(--border); border-radius:12px; }
    .sheet-empty { text-align:center; padding:40px 10px; color:var(--muted); font-size:12.5px; }
    /* ── Booking form ── */
    .login-gate { text-align:center; padding:34px 12px 18px; }
    .login-gate-icon { font-size:34px; margin-bottom:10px; }
    .login-gate-title { font-size:14.5px; font-weight:800; margin-bottom:6px; }
    .login-gate-sub { font-size:11.5px; color:var(--muted); line-height:1.6; margin-bottom:20px; }
    .login-gate-btn {
      display:inline-flex; align-items:center; justify-content:center;
      height:42px; padding:0 26px; border-radius:12px; border:none;
      background:linear-gradient(135deg, rgba(37,99,235,.9), rgba(16,185,129,.75));
      color:#fff; font-size:12.5px; font-weight:700; font-family:'Montserrat',sans-serif;
      cursor:pointer; text-decoration:none;
    }
    .form-group { margin-bottom:14px; }
    .form-label {
      display:block; font-size:10.5px; font-weight:700; letter-spacing:.04em;
      text-transform:uppercase; color:var(--muted); margin-bottom:7px;
    }
    .form-row { display:flex; gap:10px; }
    .form-row .form-group { flex:1; min-width:0; }
    .form-input, .form-select, .form-textarea {
      width:100%; height:44px; border-radius:12px;
      border:1px solid rgba(255,255,255,.13);
      background:rgba(255,255,255,.04);
      color:var(--text); font-size:13px; font-family:'Montserrat',sans-serif;
      padding:0 14px; outline:none;
      transition:border-color .18s;
    }
    .form-select { appearance:none; -webkit-appearance:none; color-scheme:dark;
      background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23808a9c' stroke-width='2'><polyline points='6 9 12 15 18 9'/></svg>");
      background-repeat:no-repeat; background-position:right 14px center; background-size:14px;
      padding-right:36px;
    }
    .form-select option {
      background:#131a2c; color:var(--text);
    }
    .form-input[type="date"], .form-input[type="time"] { color-scheme:dark; }
    .form-textarea { height:auto; min-height:72px; padding:12px 14px; resize:vertical; font-family:'Montserrat',sans-serif; }
    .form-input:focus, .form-select:focus, .form-textarea:focus { border-color:rgba(59,130,246,.55); }
    .form-input::placeholder, .form-textarea::placeholder { color:var(--muted); }
    .form-input:disabled, .form-select:disabled { opacity:.45; cursor:not-allowed; }
    .stepper { display:flex; align-items:center; gap:0; height:44px; border:1px solid rgba(255,255,255,.13); border-radius:12px; overflow:hidden; background:rgba(255,255,255,.04); }
    .stepper-btn { width:42px; height:100%; flex-shrink:0; border:none; background:transparent; color:var(--text); font-size:17px; font-weight:700; cursor:pointer; }
    .stepper-btn:active { background:rgba(255,255,255,.08); }
    .stepper-val { flex:1; text-align:center; font-size:14px; font-weight:800; }
    .field-hint { font-size:10px; color:var(--muted); margin-top:6px; }
    .submit-btn {
      width:100%; height:46px; border-radius:13px; border:none; margin-top:6px;
      background:linear-gradient(135deg, rgba(37,99,235,.92), rgba(16,185,129,.78));
      color:#fff; font-size:13px; font-weight:800; font-family:'Montserrat',sans-serif;
      cursor:pointer; transition:opacity .15s;
      display:flex; align-items:center; justify-content:center; gap:8px;
    }
    .submit-btn:active { opacity:.82; }
    .submit-btn:disabled { opacity:.5; cursor:not-allowed; }
    .form-msg { font-size:11.5px; font-weight:600; margin-top:10px; padding:9px 12px; border-radius:10px; display:none; }
    .form-msg.show { display:block; }
    .form-msg.error   { background:rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.28); color:#f87171; }
    .form-msg.success { background:rgba(16,185,129,.1); border:1px solid rgba(16,185,129,.28); color:#34d399; }
    .booking-divider { display:flex; align-items:center; gap:10px; margin:24px 0 14px; }
    .booking-divider span { font-size:10.5px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:var(--muted); white-space:nowrap; }
    .booking-divider::before, .booking-divider::after { content:''; flex:1; height:1px; background:var(--border); }
    .booking-item {
      background:var(--card); border:1px solid var(--border); border-radius:16px;
      padding:13px 15px; margin-bottom:10px;
    }
    .booking-item-top { display:flex; align-items:center; justify-content:space-between; gap:8px; }
    .booking-route { font-size:12.5px; font-weight:700; }
    .booking-sub { font-size:10.5px; color:var(--muted); margin-top:5px; line-height:1.6; }
    .booking-cancel {
      margin-top:10px; width:100%; height:32px; border-radius:9px;
      border:1px solid rgba(239,68,68,.3); background:rgba(239,68,68,.08);
      color:#f87171; font-size:10.5px; font-weight:700; font-family:'Montserrat',sans-serif;
      cursor:pointer;
    }
    .booking-cancel:active { background:rgba(239,68,68,.2); }
    .booking-cancel:disabled { opacity:.4; cursor:not-allowed; }
    /* ── DESKTOP ── */
@media (min-width:768px) {
  .bottom-nav { bottom:28px; }
  .profile-card {
    left:auto; right:40px;
    transform:translateY(18px);
    bottom:calc(100px + var(--safe-b));
  }
  .profile-card.open { transform:translateY(0); }
  .profile-card::after { left:auto; right:30px; transform:rotate(45deg); }
  .top-bar { top:18px; left:18px; right:18px; }
  .sheet { display:none !important; }
  .sheet-overlay { display:none !important; }
}
  </style>
</head>
<body>
<!-- PROFILE CARD OVERLAY -->
<div class="profile-card-overlay" id="profileOverlay" onclick="closeProfileCard()"></div>
<!-- PROFILE CARD -->
<div class="profile-card" id="profileCard">
  <div class="pc-header">
    <div class="pc-avatar">
      <?php if ($avatarUrl): ?>
        <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="<?= htmlspecialchars($userName) ?>">
      <?php else: ?>
        <span class="pc-initials"><?= htmlspecialchars($initials) ?></span>
      <?php endif; ?>
    </div>
    <div class="pc-info">
      <div class="pc-name"><?= htmlspecialchars($userName) ?></div>
      <div class="pc-email"><?= htmlspecialchars($userEmail) ?></div>
      <div class="pc-badge"><?= htmlspecialchars($userRole) ?></div>
    </div>
  </div>
  <button class="pc-logout" onclick="doLogout()">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
      <polyline points="16 17 21 12 16 7"/>
      <line x1="21" y1="12" x2="9" y2="12"/>
    </svg>
    Logout
  </button>
</div>
<!-- SHARED SHEET OVERLAY -->
<div class="sheet-overlay" id="sheetOverlay" onclick="closeSheets()"></div>
<!-- ROUTES SHEET -->
<div class="sheet" id="routesSheet">
  <div class="sheet-grabber"></div>
  <div class="sheet-head">
    <div class="sheet-title">Available Routes</div>
    <button class="sheet-close" onclick="closeSheets()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  </div>
  <div class="sheet-body" id="routesBody">
    <div class="sheet-empty">Loading routes…</div>
  </div>
</div>
<!-- BOOKING SHEET -->
<div class="sheet" id="bookingSheet">
  <div class="sheet-grabber"></div>
  <div class="sheet-head">
    <div class="sheet-title">Rent a Jeepney</div>
    <button class="sheet-close" onclick="closeSheets()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  </div>
  <div class="sheet-body" id="bookingBody">
    <?php if (!$accountId): ?>
      <div class="login-gate">
        <div class="login-gate-icon">🔒</div>
        <div class="login-gate-title">Log in to book a jeepney</div>
        <div class="login-gate-sub">Create an account or sign in so we can confirm your booking and let you track its status.</div>
        <a class="login-gate-btn" href="../index.php">Log In / Sign Up</a>
      </div>
    <?php else: ?>
      <form id="bookingForm" autocomplete="off">
        <div class="form-group">
          <label class="form-label">Jeepney Unit</label>
          <select class="form-select" id="bf_jeepney" required disabled>
            <option value="">Loading available jeepneys…</option>
          </select>
          <div class="field-hint" id="bf_capacityHint"></div>
        </div>
        <div class="form-group">
          <label class="form-label">Passenger Name</label>
          <input class="form-input" type="text" id="bf_name" placeholder="Full name" value="<?= htmlspecialchars($accountId ? $userName : '') ?>" required>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Date</label>
            <input class="form-input" type="date" id="bf_date" required>
          </div>
          <div class="form-group">
            <label class="form-label">Time</label>
            <input class="form-input" type="time" id="bf_time" required>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Passengers</label>
          <div class="stepper">
            <button type="button" class="stepper-btn" onclick="adjustPassengers(-1)">−</button>
            <div class="stepper-val" id="bf_countVal">1</div>
            <button type="button" class="stepper-btn" onclick="adjustPassengers(1)">+</button>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Pickup Location</label>
          <input class="form-input" type="text" id="bf_pickup" placeholder="e.g. City Heights Central Market" required>
        </div>
        <div class="form-group">
          <label class="form-label">Drop-off Location</label>
          <input class="form-input" type="text" id="bf_dropoff" placeholder="e.g. Mansilingan Terminal" required>
        </div>
        <div class="form-group">
          <label class="form-label">Notes (optional)</label>
          <textarea class="form-textarea" id="bf_notes" placeholder="Anything the driver should know…"></textarea>
        </div>
        <button type="submit" class="submit-btn" id="bf_submit">Submit Booking Request</button>
        <div class="form-msg" id="bf_msg"></div>
      </form>
      <div class="booking-divider"><span>Your Bookings</span></div>
      <div id="myBookings"><div class="sheet-empty">Loading…</div></div>
    <?php endif; ?>
  </div>
</div>
<!-- MAIN APP -->
<div class="app" id="appShell">
  <div class="map-area">
    <div id="map"></div>
    <!-- TOP BAR -->
    <div class="top-bar">
      <div class="top-search-wrap">
        <svg class="top-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
        </svg>
        <input class="top-search" type="text" placeholder="Search routes, jeepney IDs, drivers…" id="topSearch" autocomplete="off">
        <div class="search-results" id="searchResults"></div>
      </div>
      <button class="top-icon-btn" id="themeBtn" title="Toggle map theme">
        <span id="themeBtnIcon">🌙</span>
      </button>
    </div>
    <!-- LIVE COUNTER PILL -->
    <div class="live-pill" id="livePill">
      <div class="live-dot"></div>
      <span id="liveCount">0 jeepneys live</span>
    </div>
    <!-- BOTTOM NAV -->
    <nav class="bottom-nav">
      <div class="nav-tab active" id="routesTab" onclick="setTab(this,'routes'); openSheet('routes');">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
          <line x1="3" y1="12" x2="21" y2="12"/>
          <polyline points="8 7 3 12 8 17"/>
          <polyline points="16 7 21 12 16 17"/>
        </svg>
        <span>Routes</span>
      </div>
      <div class="nav-tab book-tab" onclick="openSheet('booking');">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
          <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
          <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
        </svg>
        <span>Rent a Jeepney</span>
      </div>
      <div class="nav-tab" id="profileTab" onclick="toggleProfileCard()">
        <div class="nav-profile-avatar">
          <?php if ($avatarUrl): ?>
            <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="<?= htmlspecialchars($userName) ?>">
          <?php else: ?>
            <span class="nav-initials"><?= htmlspecialchars($initials) ?></span>
          <?php endif; ?>
        </div>
        <span>Profile</span>
      </div>
    </nav>
  </div>
</div>
<script>

function setAppHeight() {
  const h = window.innerHeight + 'px';
  document.documentElement.style.setProperty('--app-height', h);
  document.documentElement.style.height = h;
  document.body.style.height = h;
}
setAppHeight();
window.addEventListener('resize', setAppHeight);

const DEFAULT_LAT = 10.6765, DEFAULT_LNG = 122.9509;
const MAP_THEMES = {
  dawn:  { tile:'https://tile.openstreetmap.org/{z}/{x}/{y}.png',                 icon:'🌅', label:'dawn'  },
  day:   { tile:'https://tile.openstreetmap.org/{z}/{x}/{y}.png',                 icon:'☀️', label:'day'   },
  dusk:  { tile:'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',  icon:'🌇', label:'dusk'  },
  night: { tile:'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',  icon:'🌙', label:'night' }
};
const THEME_ORDER = ['night','dawn','day','dusk'];
function getThemeByHour(h) {
  if (h >= 5  && h < 7)  return MAP_THEMES.dawn;
  if (h >= 7  && h < 17) return MAP_THEMES.day;
  if (h >= 17 && h < 19) return MAP_THEMES.dusk;
  return MAP_THEMES.night;
}
const map = L.map('map', { center:[DEFAULT_LAT, DEFAULT_LNG], zoom:15, zoomControl:false });
function applyTheme(theme, oldLayer) {
  if (oldLayer) map.removeLayer(oldLayer);
  const opts = { attribution:'© OpenStreetMap © CARTO', maxZoom:19 };
  if (theme.tile.includes('cartocdn')) opts.subdomains = 'abcd';
  const layer = L.tileLayer(theme.tile, opts).addTo(map);
  document.getElementById('themeBtnIcon').textContent = theme.icon;
  return layer;
}
const initTheme = getThemeByHour(new Date().getHours());
let currentThemeIdx = THEME_ORDER.indexOf(initTheme.label);
if (currentThemeIdx < 0) currentThemeIdx = 0;
let tileLayer = applyTheme(initTheme, null);
setTimeout(() => map.invalidateSize(), 300);
document.getElementById('themeBtn').addEventListener('click', () => {
  currentThemeIdx = (currentThemeIdx + 1) % THEME_ORDER.length;
  tileLayer = applyTheme(MAP_THEMES[THEME_ORDER[currentThemeIdx]], tileLayer);
});
setInterval(() => {
  const auto = getThemeByHour(new Date().getHours());
  const idx  = THEME_ORDER.indexOf(auto.label);
  if (idx !== currentThemeIdx) { currentThemeIdx = idx; tileLayer = applyTheme(auto, tileLayer); }
}, 60000);
/* ── Profile card ── */
function toggleProfileCard() {
  const card    = document.getElementById('profileCard');
  const overlay = document.getElementById('profileOverlay');
  const isOpen  = card.classList.contains('open');
  card.classList.toggle('open', !isOpen);
  overlay.classList.toggle('open', !isOpen);
}
function closeProfileCard() {
  document.getElementById('profileCard').classList.remove('open');
  document.getElementById('profileOverlay').classList.remove('open');
}
/* ── Logout ── */
async function doLogout() {
  try {
    await fetch('api.php?action=logout', { method: 'POST', cache: 'no-store' });
  } catch (e) {
    console.warn('Logout request failed:', e);
  } finally {
    window.location.href = '../index.php';
  }
}
/* ── Bottom nav tabs ── */
function setTab(el, name) {
  document.querySelectorAll('.nav-tab:not(.book-tab)').forEach(t => t.classList.remove('active'));
  if (!el.classList.contains('book-tab')) el.classList.add('active');
}
/* ══════════════════════════════════════════════════════════
   SHEETS (Routes / Rent a Jeepney)
══════════════════════════════════════════════════════════ */
const IS_LOGGED_IN = <?= $accountId ? 'true' : 'false' ?>;
let routesCache = null;

function openSheet(name) {
  if (name === 'booking' && window.innerWidth >= 768) return;
  closeProfileCard();
  document.getElementById('sheetOverlay').classList.add('open');
  document.getElementById(name === 'routes' ? 'routesSheet' : 'bookingSheet').classList.add('open');
  if (name === 'routes') {
    document.getElementById('routesTab').classList.add('active');
    loadRoutes();
  } else if (name === 'booking' && IS_LOGGED_IN) {
    loadRoutes(); // ensures bf_jeepney select is populated with currently online units
    fetchMyBookings();
  }
}
function closeSheets() {
  document.getElementById('sheetOverlay').classList.remove('open');
  document.querySelectorAll('.sheet').forEach(s => s.classList.remove('open'));
}

/* ── Routes data (shared by Routes sheet + Booking form selects) ── */
async function loadRoutes(force) {
  if (routesCache && !force) {
    renderRoutesSheet(routesCache);
    populateJeepneySelect(routesCache);
    return;
  }
  try {
    const res  = await fetch('api.php?action=routes', { cache: 'no-store' });
    const body = await res.json();
    if (!body.ok) throw new Error(body.message || 'Failed to load routes');
    routesCache = body.routes;
    renderRoutesSheet(routesCache);
    populateJeepneySelect(routesCache);
  } catch (e) {
    console.warn('loadRoutes failed:', e);
    document.getElementById('routesBody').innerHTML =
      '<div class="sheet-empty">Couldn\'t load routes. Pull down to try again.</div>';
  }
}

const ROUTE_STATUS_DOT = { on_route:'#10b981', traffic:'#f59e0b', maintenance:'#f97316', complete:'#6b7280', idle:'#9ca3af' };

function renderRoutesSheet(routes) {
  const body = document.getElementById('routesBody');
  if (!routes || !routes.length) {
    body.innerHTML = '<div class="sheet-empty">No routes have been set up yet.</div>';
    return;
  }
  body.innerHTML = routes.map(r => {
    const units = (r.jeepneys || []).map(u => {
      const dot = ROUTE_STATUS_DOT[u.display_status] || ROUTE_STATUS_DOT.idle;
      const etaText = (u.eta_minutes != null)
        ? `~${u.eta_minutes} min${u.eta_dist_km != null ? ` · ${u.eta_dist_km} km` : ''}`
        : null;
      return `
        <div class="live-unit-row" onclick="locateRouteUnit(${u.account_id})">
          <span class="pulse-dot" style="background:${dot}"></span>
          <div class="lu-text">
            <div class="lu-code">${esc(u.unit_code)} <span style="font-weight:500;color:var(--muted);">· ${esc(u.plate_no)}</span></div>
            <div class="lu-plate">
              ${tripStatusBadge(u.display_status)}
              ${u.departure_time ? `<span style="margin-left:6px;">Departs ${esc(u.departure_time)}</span>` : ''}
            </div>
          </div>
          ${etaText ? `<span class="lu-go">${etaText}</span>` : `<span class="lu-go">Locate →</span>`}
        </div>`;
    }).join('');
    return `
      <div class="route-card">
        <div class="route-card-top">
          <div>
            <div class="route-name">${esc(r.name)}</div>
            ${r.description ? `<div class="route-desc">${esc(r.description)}</div>` : ''}
          </div>
        </div>
        <div class="route-stats">
          <div class="route-stat"><span class="dot" style="background:#10b981"></span>${r.live_count} available now</div>
        </div>
        ${units
          ? `<div class="route-units">${units}</div>`
          : `<div class="route-units-empty">No jeepneys online on this route right now</div>`}
      </div>`;
  }).join('');
}

function locateRouteUnit(accountId) {
  closeSheets();
  setTimeout(() => selectSearchResult(accountId), 280);
}

/* ── Booking form: jeepney unit select (flattened across all routes) ── */
function populateJeepneySelect(routes) {
  const sel = document.getElementById('bf_jeepney');
  if (!sel) return;
  const current = sel.value;
  const allUnits = [];
  (routes || []).forEach(r => (r.jeepneys || []).forEach(u => allUnits.push({ ...u, route_name: r.name })));

  if (!allUnits.length) {
    sel.innerHTML = '<option value="">No jeepneys online right now…</option>';
    sel.disabled = true;
    document.getElementById('bf_capacityHint').textContent = '';
    return;
  }
  sel.innerHTML = '<option value="">Select a jeepney…</option>' +
    allUnits.map(u => `<option value="${u.id}" data-capacity="${u.capacity}">${esc(u.unit_code)} — ${esc(u.plate_no)} · ${esc(u.route_name)}</option>`).join('');
  sel.disabled = false;
  if (current) sel.value = current;
}

document.addEventListener('change', (e) => {
  if (e.target && e.target.id === 'bf_jeepney') {
    const opt  = e.target.selectedOptions[0];
    const hint = document.getElementById('bf_capacityHint');
    hint.textContent = (opt && opt.dataset.capacity) ? `Seats up to ${opt.dataset.capacity} passengers` : '';
  }
});

let passengerCount = 1;
function adjustPassengers(delta) {
  passengerCount = Math.max(1, Math.min(99, passengerCount + delta));
  document.getElementById('bf_countVal').textContent = passengerCount;
}

function esc(str) {
  return String(str ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
}

const bookingForm = document.getElementById('bookingForm');
if (bookingForm) {
  // sensible default date/time
  const dateInput = document.getElementById('bf_date');
  const timeInput = document.getElementById('bf_time');
  const today = new Date();
  dateInput.min = today.toISOString().slice(0, 10);
  dateInput.value = today.toISOString().slice(0, 10);
  timeInput.value = today.toTimeString().slice(0, 5);

  bookingForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const msg = document.getElementById('bf_msg');
    const btn = document.getElementById('bf_submit');
    msg.className = 'form-msg';
    msg.textContent = '';

    const payload = {
      jeepney_id:       document.getElementById('bf_jeepney').value,
      passenger_name:   document.getElementById('bf_name').value.trim(),
      passenger_count:  passengerCount,
      booking_date:     document.getElementById('bf_date').value,
      booking_time:     document.getElementById('bf_time').value,
      pickup_location:  document.getElementById('bf_pickup').value.trim(),
      dropoff_location: document.getElementById('bf_dropoff').value.trim(),
      notes:            document.getElementById('bf_notes').value.trim(),
    };
    if (!payload.jeepney_id) {
      msg.textContent = 'Please select a jeepney unit.';
      msg.className = 'form-msg error show';
      return;
    }

    btn.disabled = true;
    btn.textContent = 'Submitting…';
    try {
      const res  = await fetch('api.php?action=create_booking', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const body = await res.json();
      if (!body.ok) {
        msg.textContent = body.message === 'login_required'
          ? 'Your session expired — please log in again.'
          : (body.message || 'Could not submit booking.');
        msg.className = 'form-msg error show';
      } else {
        msg.textContent = 'Booking request sent! Track its status below.';
        msg.className = 'form-msg success show';
        bookingForm.reset();
        passengerCount = 1;
        document.getElementById('bf_countVal').textContent = '1';
        populateJeepneySelect(routesCache);
        dateInput.value = today.toISOString().slice(0, 10);
        timeInput.value = today.toTimeString().slice(0, 5);
        fetchMyBookings();
      }
    } catch (err) {
      msg.textContent = 'Network error — please try again.';
      msg.className = 'form-msg error show';
    } finally {
      btn.disabled = false;
      btn.textContent = 'Submit Booking Request';
    }
  });
}

const BOOKING_STATUS_STYLES = {
  pending:   { label: 'Pending Review', color: '#f59e0b' },
  approved:  { label: 'Approved',       color: '#10b981' },
  declined:  { label: 'Declined',       color: '#ef4444' },
  cancelled: { label: 'Cancelled',      color: '#6b7280' },
};
async function fetchMyBookings() {
  const list = document.getElementById('myBookings');
  if (!list) return;
  try {
    const res  = await fetch('api.php?action=my_bookings', { cache: 'no-store' });
    const body = await res.json();
    if (!body.ok) throw new Error(body.message);
    if (!body.bookings.length) {
      list.innerHTML = '<div class="sheet-empty">No bookings yet — submit one above.</div>';
      return;
    }
    list.innerHTML = body.bookings.map(b => {
      const s = BOOKING_STATUS_STYLES[b.status] || { label: b.status, color: '#9ca3af' };
      return `
        <div class="booking-item">
          <div class="booking-item-top">
            <div class="booking-route">${esc(b.route_name || b.unit_code)}</div>
            <span style="font-size:9px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;
                         background:${s.color}22;color:${s.color};border:1px solid ${s.color}44;
                         border-radius:999px;padding:3px 9px;">${s.label}</span>
          </div>
          <div class="booking-sub">
            ${esc(b.unit_code)} · ${b.booking_date} at ${b.booking_time} · ${b.passenger_count} pax<br>
            ${esc(b.pickup_location)} → ${esc(b.dropoff_location)}
          </div>
          ${b.status === 'pending' ? `<button class="booking-cancel" onclick="cancelBooking(${b.id}, this)">Cancel Booking</button>` : ''}
        </div>`;
    }).join('');
  } catch (e) {
    list.innerHTML = '<div class="sheet-empty">Couldn\'t load your bookings.</div>';
  }
}
async function cancelBooking(id, btn) {
  btn.disabled = true;
  btn.textContent = 'Cancelling…';
  try {
    const res  = await fetch('api.php?action=cancel_booking', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id })
    });
    const body = await res.json();
    if (body.ok) fetchMyBookings();
    else { btn.disabled = false; btn.textContent = 'Cancel Booking'; alert(body.message || 'Could not cancel.'); }
  } catch (e) {
    btn.disabled = false; btn.textContent = 'Cancel Booking';
  }
}

/* ══════════════════════════════════════════════════════════
   LIVE JEEPNEY TRACKING
══════════════════════════════════════════════════════════ */
const jeepMarkers = {};
const jeepData    = {};

/* Preload Modern.png once so all markers share the same cached image */
const JEEP_IMG = new Image();
JEEP_IMG.src = '/commuter/Modern.png';

function makeJeepIcon(direction, stale, status) {
  const STATUS_COLORS = {
    on_route:    { glow: 'rgba(16,185,129,.8)',  pulse: '#6ee7b7' },
    traffic:     { glow: 'rgba(245,158,11,.8)',  pulse: '#fde68a' },
    maintenance: { glow: 'rgba(239,68,68,.8)',   pulse: '#fca5a5' },
    complete:    { glow: 'rgba(107,114,128,.6)', pulse: '#d1d5db' },
    idle:        { glow: 'rgba(147,197,253,.7)', pulse: '#bfdbfe' },
  };

  const sc       = STATUS_COLORS[status] || STATUS_COLORS.on_route;
  const dotColor = stale ? '#6b7280' : sc.pulse;
  const animName = `pulse_${status || 'on_route'}`;

  if (!document.getElementById('anim_' + animName)) {
    const style = document.createElement('style');
    style.id = 'anim_' + animName;
    style.textContent = `
      @keyframes ${animName} {
        0%   { transform: scale(1);   opacity: .65; }
        70%  { transform: scale(2.4); opacity: 0;   }
        100% { transform: scale(2.4); opacity: 0;   }
      }`;
    document.head.appendChild(style);
  }

  const flip    = direction === 'reverse' ? 'scaleX(-1)' : 'scaleX(1)';
  const opacity = stale ? '0.45' : '1';
  const glow    = stale ? '' : `filter:drop-shadow(0 0 6px ${sc.glow});`;
  const imgUrl  = window.location.origin + '/commuter/Modern.png';

  return L.divIcon({
    className: '',
    html: `
      <div style="position:relative;width:40px;height:40px;">
        ${!stale ? `<span style="
          position:absolute;top:50%;left:50%;
          width:28px;height:28px;
          margin-top:-14px;margin-left:-14px;
          border-radius:50%;
          background:${dotColor};
          opacity:.6;
          animation:${animName} 2s ease-out infinite;
          pointer-events:none;
          display:block;
        "></span>` : ''}
        <img src="${imgUrl}"
             width="40" height="40"
             style="
               position:absolute;top:0;left:0;
               width:40px;height:40px;
               object-fit:contain;
               transform:${flip};
               transform-origin:center;
               opacity:${opacity};
               ${glow}
               display:block;">
      </div>`,
    iconSize:   [40, 40],
    iconAnchor: [20, 20],
    popupAnchor:[0, -24]
  });
}


/* Trip status label + colour */
const TRIP_STATUS_STYLES = {
  on_route:    { label:'On Route',     color:'#10b981' },
  traffic:     { label:'In Traffic',   color:'#f59e0b' },
  maintenance: { label:'Maintenance',  color:'#f97316' },
  complete:    { label:'Completed',    color:'#6b7280' },
  active:      { label:'Active',       color:'#10b981' },
  scheduled:   { label:'Scheduled',   color:'#3b82f6' },
  idle:        { label:'Idle',         color:'#9ca3af' },
};
function tripStatusBadge(status) {
  const s = TRIP_STATUS_STYLES[status] || { label: status || '—', color:'#9ca3af' };
  return `<span style="display:inline-flex;align-items:center;gap:4px;
                        font-size:9px;font-weight:700;letter-spacing:.05em;
                        text-transform:uppercase;
                        background:${s.color}22;color:${s.color};
                        border:1px solid ${s.color}44;
                        border-radius:999px;padding:2px 8px;">
            <span style="width:5px;height:5px;border-radius:50%;background:${s.color};flex-shrink:0;display:inline-block;"></span>
            ${s.label}
          </span>`;
}
/* "2 min ago" helper */
function timeAgo(dtStr) {
  if (!dtStr) return '—';
  const diff = Math.floor((Date.now() - new Date(dtStr).getTime()) / 1000);
  if (diff < 60)   return diff + 's ago';
  if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
  return Math.floor(diff / 3600) + 'h ago';
}
/* Rich popup */
function buildPopup(d) {
  const staleBadge = d.stale
    ? `<span style="font-size:9px;background:#374151;color:#9ca3af;
                     padding:1px 7px;border-radius:999px;margin-left:5px;">
         last seen ${timeAgo(d.updated_at)}
       </span>`
    : '';
  const etaLine = (d.eta_minutes != null)
    ? `<div style="display:flex;align-items:center;justify-content:space-between;
                   margin-top:8px;padding-top:8px;border-top:1px solid rgba(255,255,255,.07);">
         <span style="font-size:10px;color:#9ca3af;">ETA</span>
         <span style="font-size:12px;font-weight:800;color:#10b981;">
           ~${d.eta_minutes} min
           ${d.eta_dist_km != null ? `<span style="font-weight:500;color:#6b7280;font-size:10px;"> · ${d.eta_dist_km} km</span>` : ''}
         </span>
       </div>`
    : '';
  const departureLine = d.departure_time
    ? `<div style="display:flex;align-items:center;justify-content:space-between;margin-top:5px;">
         <span style="font-size:10px;color:#9ca3af;">Departure</span>
         <span style="font-size:11px;font-weight:700;color:#e8edf5;">${d.departure_time}</span>
       </div>`
    : '';
  const routeLine = d.route_name
    ? `<div style="display:flex;align-items:center;justify-content:space-between;margin-top:5px;">
         <span style="font-size:10px;color:#9ca3af;">Route</span>
         <span style="font-size:11px;font-weight:700;color:#e8edf5;text-align:right;max-width:150px;">${d.route_name}</span>
       </div>`
    : '';
  return `
    <div style="font-family:'Montserrat',sans-serif;padding:12px 14px;min-width:200px;">
      <div style="display:flex;align-items:center;margin-bottom:8px;">
        <span style="font-size:15px;font-weight:800;color:#f9fafb;letter-spacing:-.3px;">
          🚌 ${d.unit_code || '—'}
        </span>
        ${staleBadge}
      </div>
      <div style="margin-bottom:10px;">
        ${tripStatusBadge(d.display_status)}
      </div>
      <div style="display:flex;flex-direction:column;gap:0;">
        ${routeLine}
        ${departureLine}
        <div style="display:flex;align-items:center;justify-content:space-between;margin-top:5px;">
          <span style="font-size:10px;color:#9ca3af;">Driver</span>
          <span style="font-size:11px;font-weight:600;color:#d1d5db;">${d.driver_name || '—'}</span>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-top:5px;">
          <span style="font-size:10px;color:#9ca3af;">Plate</span>
          <span style="font-size:11px;font-weight:600;color:#d1d5db;">${d.plate_no || '—'}</span>
        </div>
        ${etaLine}
      </div>
    </div>`;
}
/* Poll and update markers */
async function pollJeepneys() {
  try {
    const res  = await fetch('api.php?action=live_jeepneys', { cache:'no-store' });
    if (!res.ok) { console.warn('pollJeepneys: HTTP', res.status); return; }
    const body = await res.json();
    if (!body.ok) { console.warn('pollJeepneys: API error', body.message); return; }
    if (!body.jeepneys?.length) console.info('pollJeepneys: 0 live units returned from API');
    const seen = new Set();
    body.jeepneys.forEach(d => {
      const lat = parseFloat(d.lat);
      const lng = parseFloat(d.lng);
      if (!isFinite(lat) || !isFinite(lng)) return;
      seen.add(d.account_id);
      jeepData[d.account_id] = d;
      if (jeepMarkers[d.account_id]) {
        const m = jeepMarkers[d.account_id];
        m.setLatLng([lat, lng]);
        m.setIcon(makeJeepIcon(d.direction, d.stale, d.display_status));
        if (m.isPopupOpen()) m.setPopupContent(buildPopup(d));
      } else {
        const m = L.marker([lat, lng], { icon: makeJeepIcon(d.direction, d.stale, d.display_status) })
          .bindPopup(buildPopup(d), { maxWidth:260, closeButton:true })
          .addTo(map);
        jeepMarkers[d.account_id] = m;
      }
    });
    Object.keys(jeepMarkers).forEach(id => {
      if (!seen.has(+id)) {
        map.removeLayer(jeepMarkers[id]);
        delete jeepMarkers[id];
        delete jeepData[id];
      }
    });
    const count = seen.size;
    const pill  = document.getElementById('livePill');
    const label = document.getElementById('liveCount');
    label.textContent = count === 0
      ? 'No jeepneys online'
      : `${count} jeepney${count !== 1 ? 's' : ''} live`;
    pill.classList.add('visible');
  } catch (e) {
    console.warn('Jeepney poll failed:', e);
  }
}
pollJeepneys();
setInterval(pollJeepneys, 8000);
/* ── Search ── */
const searchInput   = document.getElementById('topSearch');
const searchResults = document.getElementById('searchResults');
const SEARCH_DOT = { on_route:'#10b981', traffic:'#f59e0b', maintenance:'#f97316', complete:'#6b7280', idle:'#9ca3af' };

function renderSearchResults(matches, q) {
  if (!q) { searchResults.classList.remove('open'); searchResults.innerHTML = ''; return; }
  if (!matches.length) {
    searchResults.innerHTML = '<div class="sr-empty">No jeepneys match your search</div>';
    searchResults.classList.add('open');
    return;
  }
  searchResults.innerHTML = matches.slice(0, 8).map(([id, d]) => {
    const dot = SEARCH_DOT[d.display_status] || '#9ca3af';
    return `
      <div class="sr-item" onclick="selectSearchResult('${id}')">
        <span class="sr-dot" style="background:${dot}"></span>
        <div class="sr-text">
          <div class="sr-title">${esc(d.unit_code || 'Unit')}</div>
          <div class="sr-sub">${esc(d.route_name || 'Unassigned route')} ${d.driver_name ? '· ' + esc(d.driver_name) : ''}</div>
        </div>
      </div>`;
  }).join('');
  searchResults.classList.add('open');
}

function selectSearchResult(id) {
  const marker = jeepMarkers[id];
  const d      = jeepData[id];
  if (!marker || !d) return;
  if (!map.hasLayer(marker)) marker.addTo(map);
  map.flyTo([parseFloat(d.lat), parseFloat(d.lng)], Math.max(map.getZoom(), 17), { duration: 0.6 });
  marker.openPopup();
  searchResults.classList.remove('open');
  searchInput.value = d.unit_code || '';
  searchInput.blur();
}

searchInput.addEventListener('input', function () {
  const q = this.value.trim().toLowerCase();
  const matches = [];
  Object.entries(jeepMarkers).forEach(([id, marker]) => {
    const d = jeepData[id];
    if (!d) return;
    const hay = [d.unit_code, d.plate_no, d.route_name, d.driver_name]
      .filter(Boolean).join(' ').toLowerCase();
    if (!q || hay.includes(q)) {
      if (!map.hasLayer(marker)) marker.addTo(map);
      if (q) matches.push([id, d]);
    } else {
      if (map.hasLayer(marker)) map.removeLayer(marker);
    }
  });
  renderSearchResults(matches, q);
});
searchInput.addEventListener('focus', function () {
  if (this.value.trim()) this.dispatchEvent(new Event('input'));
});
document.addEventListener('click', (e) => {
  if (!searchResults.contains(e.target) && e.target !== searchInput) {
    searchResults.classList.remove('open');
  }
});
window.addEventListener('resize', () => map.invalidateSize());
</script>
</body>
</html>