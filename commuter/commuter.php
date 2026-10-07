<?php
require_once '../session_init.php';
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
  <link rel='stylesheet' type='text/css' href='https://api.tomtom.com/maps-sdk-for-web/cdn/6.x/6.25.0/maps/maps.css'/>
  <script src="https://api.tomtom.com/maps-sdk-for-web/cdn/6.x/6.25.0/maps/maps-web.min.js"></script>
  <script src="https://api.tomtom.com/maps-sdk-for-web/cdn/6.x/6.25.0/services/services-web.min.js"></script>
  <style>
    /* ══════════════════════════════════════════════
       DESIGN TOKENS
       Palette: deep navy base, amber jeepney accent,
       emerald live-status, soft slate text.
       Signature: amber glow ring on the chat button
       that pulses like a jeepney horn indicator.
    ══════════════════════════════════════════════ */
    :root {
      color-scheme: dark;
      /* Base */
      --bg:       #080e1c;
      --bg2:      #0d1526;
      --panel:    rgba(8,14,28,0.97);
      /* Accents */
      --amber:    #f59e0b;
      --amber-d:  #d97706;
      --amber-glow: rgba(245,158,11,0.22);
      --blue:     #3b82f6;
      --blue-d:   #2563eb;
      --green:    #10b981;
      --red:      #ef4444;
      /* Text */
      --text:     #e8edf5;
      --muted:    rgba(232,237,245,0.45);
      --muted2:   rgba(232,237,245,0.22);
      /* Surface */
      --border:   rgba(255,255,255,0.08);
      --border2:  rgba(255,255,255,0.13);
      --card:     rgba(255,255,255,0.05);
      --hover:    rgba(255,255,255,0.09);
      /* Safe area */
      --safe-b:   env(safe-area-inset-bottom, 0px);
      --safe-t:   env(safe-area-inset-top, 0px);
    }

    *, *::before, *::after {
      margin: 0; padding: 0; box-sizing: border-box;
      -webkit-tap-highlight-color: transparent;
    }
    html, body {
      width: 100%; height: 100vh;
      height: var(--app-height, 100vh);
      overflow: hidden;
      font-family: 'Montserrat', sans-serif;
      background: var(--bg); color: var(--text);
    }

    /* ── APP SHELL ── */
    .app { position: relative; width: 100%; height: 100vh; height: var(--app-height, 100vh); display: flex; flex-direction: column; overflow: hidden; }
    .map-area { position: relative; flex: 1; min-height: 0; overflow: hidden; }
    #map { position: absolute; inset: 0; width: 100%; height: 100%; z-index: 1; }
    #map, #map canvas { background: var(--bg) !important; }
    /* TomTom's night filter — the default SDK style is light-only, so
       "night"/"dusk" themes are simulated with a CSS filter on the canvas
       rather than swapping tile sets like Leaflet did. */
    #map.jl-filter-invert canvas { filter: invert(1) hue-rotate(180deg) brightness(0.92) contrast(0.9); }
    #map.jl-filter-dim canvas    { filter: brightness(0.78) saturate(0.85); }

    /* ── TOP BAR ── */
    .top-bar {
      position: absolute;
      top: calc(16px + var(--safe-t));
      left: 16px; right: 16px;
      display: flex; align-items: center; gap: 10px;
      z-index: 20;
      animation: fadeDown .38s .1s cubic-bezier(.22,1,.36,1) both;
    }
    @keyframes fadeDown {
      from { transform: translateY(-14px); opacity: 0; }
      to   { transform: translateY(0);     opacity: 1; }
    }
    .top-search-wrap { flex: 1; position: relative; min-width: 0; }
    .top-search-icon {
      position: absolute; left: 16px; top: 50%;
      transform: translateY(-50%);
      width: 15px; height: 15px;
      color: var(--muted); pointer-events: none;
    }
    .top-search {
      width: 100%; height: 50px;
      padding: 0 20px 0 44px;
      border-radius: 999px;
      border: 1px solid var(--border2);
      background: rgba(8,14,28,.90);
      backdrop-filter: blur(28px) saturate(180%);
      -webkit-backdrop-filter: blur(28px) saturate(180%);
      color: var(--text);
      font-size: 13.5px; font-family: 'Montserrat', sans-serif;
      outline: none;
      box-shadow: 0 4px 28px rgba(0,0,0,.45), 0 1px 0 rgba(255,255,255,.05) inset;
      transition: border-color .22s, box-shadow .22s;
    }
    .top-search::placeholder { color: var(--muted); }
    .top-search:focus {
      border-color: rgba(59,130,246,.5);
      box-shadow: 0 0 0 4px rgba(59,130,246,.12), 0 4px 28px rgba(0,0,0,.45);
    }
    .top-icon-btn {
      width: 50px; height: 50px; border-radius: 999px;
      border: 1px solid var(--border2);
      background: rgba(8,14,28,.90);
      backdrop-filter: blur(28px) saturate(180%);
      -webkit-backdrop-filter: blur(28px) saturate(180%);
      color: var(--text); font-size: 18px; flex-shrink: 0;
      cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      box-shadow: 0 4px 28px rgba(0,0,0,.45), 0 1px 0 rgba(255,255,255,.05) inset;
      transition: background .2s, transform .15s;
    }
    .top-icon-btn:active { transform: scale(.92); background: rgba(20,30,56,.95); }

    /* ── LIVE COUNTER PILL ── */
    .live-pill {
      position: absolute;
      top: calc(140px + var(--safe-t));
      left: 50%; transform: translateX(-50%);
      z-index: 20;
      display: flex; align-items: center; gap: 7px;
      background: rgba(8,14,28,.88);
      backdrop-filter: blur(20px);
      border: 1px solid var(--border2);
      border-radius: 999px;
      padding: 6px 14px;
      font-size: 11px; font-weight: 700; color: var(--muted);
      pointer-events: none;
      opacity: 0; transition: opacity .3s;
      white-space: nowrap;
    }
    .live-pill.visible { opacity: 1; }
    .live-dot {
      width: 7px; height: 7px; border-radius: 50%;
      background: var(--green);
      animation: blink 1.6s ease-in-out infinite;
      flex-shrink: 0;
    }
    @keyframes blink { 0%,100%{opacity:1} 50%{opacity:.25} }


        /* ── ROUTE TO JEEPNEY CARD ── */
    .route-info-card {
      position: absolute;
      bottom: calc(100px + var(--safe-b));
      left: 16px; right: 16px;
      max-width: 380px; margin: 0 auto;
      z-index: 25;
      background: rgba(8,14,28,.97);
      backdrop-filter: blur(28px) saturate(180%);
      -webkit-backdrop-filter: blur(28px) saturate(180%);
      border: 1px solid var(--border2);
      border-radius: 18px;
      padding: 14px 16px;
      box-shadow: 0 12px 40px rgba(0,0,0,.5);
      opacity: 0; transform: translateY(14px);
      pointer-events: none;
      transition: opacity .25s ease, transform .25s ease;
    }
    .route-info-card.visible { opacity: 1; transform: translateY(0); pointer-events: all; }
    .ric-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .ric-title { font-size: 12.5px; font-weight: 700; color: var(--text); }
    .ric-close {
      background: none; border: none; color: var(--muted);
      font-size: 14px; cursor: pointer; padding: 2px 6px; flex-shrink: 0;
    }
    .ric-close:hover { color: var(--text); }
    .ric-modes { display: flex; gap: 6px; margin-top: 10px; }
    .ric-mode {
      flex: 1; height: 34px; border-radius: 10px;
      border: 1px solid var(--border2); background: rgba(255,255,255,.04);
      color: var(--muted); font-size: 11.5px; font-weight: 700;
      cursor: pointer; font-family: 'Montserrat', sans-serif;
      transition: background .15s, color .15s, border-color .15s;
    }
    .ric-mode.active {
      background: rgba(59,130,246,.18); border-color: rgba(59,130,246,.4); color: #fff;
    }
    .ric-stats { display: flex; gap: 10px; margin-top: 10px; }
    .ric-stat {
      flex: 1; text-align: center; padding: 8px; border-radius: 10px;
      background: var(--card); border: 1px solid var(--border);
    }
    .ric-stat span { display: block; font-size: 15px; font-weight: 800; color: var(--text); }
    .ric-stat small { font-size: 9.5px; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }
    .ric-hint { font-size: 10.5px; color: var(--muted); margin-top: 9px; line-height: 1.5; }

    /* ── BOTTOM NAV (pill) ── */
    .bottom-nav {
      position: absolute;
      bottom: calc(20px + var(--safe-b));
      left: 50%; transform: translateX(-50%);
      z-index: 30;
      display: flex; align-items: center;
      background: rgba(8,12,26,.85);
      backdrop-filter: blur(32px) saturate(200%);
      -webkit-backdrop-filter: blur(32px) saturate(200%);
      border: 1px solid var(--border2);
      border-radius: 999px;
      padding: 8px;
      box-shadow: 0 8px 44px rgba(0,0,0,.6), 0 1px 0 rgba(255,255,255,.07) inset;
      animation: floatUp .45s .08s cubic-bezier(.22,1,.36,1) both;
    }
    @keyframes floatUp {
      from { transform: translateX(-50%) translateY(32px); opacity: 0; }
      to   { transform: translateX(-50%) translateY(0);    opacity: 1; }
    }
    .nav-tab {
      display: flex; flex-direction: column; align-items: center; justify-content: center;
      gap: 4px; padding: 10px 20px; cursor: pointer;
      color: var(--muted); transition: color .2s, background .2s;
      border-radius: 999px; min-width: 72px;
    }
    .nav-tab.active { color: #fff; background: rgba(59,130,246,.20); }
    .nav-tab.book-tab {
      padding: 10px 22px;
      background: linear-gradient(135deg, rgba(37,99,235,.88), rgba(16,185,129,.68));
      color: #fff;
      border: 1px solid rgba(255,255,255,.16);
      box-shadow: 0 2px 16px rgba(37,99,235,.30);
    }
    .nav-tab.book-tab:active  { opacity: .82; transform: scale(.95); }
    .nav-tab:not(.book-tab):active { background: rgba(255,255,255,.07); }
    .nav-tab svg { width: 20px; height: 20px; flex-shrink: 0; }
    .nav-tab span { font-size: 9px; font-weight: 700; letter-spacing: .04em; white-space: nowrap; }
    .nav-profile-avatar {
      width: 26px; height: 26px; border-radius: 50%;
      border: 2px solid rgba(59,130,246,.55);
      overflow: hidden; flex-shrink: 0;
      background: linear-gradient(135deg,#2563eb,#10b981);
      display: flex; align-items: center; justify-content: center;
    }
    .nav-profile-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .nav-profile-avatar .nav-initials { font-size: 10px; font-weight: 800; color: #fff; line-height: 1; }

    /* ── PROFILE CARD ── */
    .profile-card-overlay {
      position: fixed; inset: 0; z-index: 50;
      background: rgba(3,6,16,.5);
      backdrop-filter: blur(3px); -webkit-backdrop-filter: blur(3px);
      opacity: 0; pointer-events: none;
      transition: opacity .22s ease;
    }
    .profile-card-overlay.open { opacity: 1; pointer-events: all; }
    .profile-card {
      position: fixed;
      bottom: calc(96px + var(--safe-b));
      left: 50%;
      transform: translateX(-50%) translateY(18px);
      z-index: 51;
      width: min(300px, calc(100vw - 40px));
      background: rgba(8,14,28,.98);
      border: 1px solid var(--border2);
      border-radius: 22px;
      padding: 20px;
      box-shadow: 0 20px 56px rgba(0,0,0,.7), 0 1px 0 rgba(255,255,255,.06) inset;
      opacity: 0; pointer-events: none;
      transition: opacity .22s ease, transform .25s cubic-bezier(.22,1,.36,1);
    }
    .profile-card.open {
      opacity: 1; pointer-events: all;
      transform: translateX(-50%) translateY(0);
    }
    .profile-card::after {
      content: '';
      position: absolute;
      bottom: -7px; left: 50%;
      width: 14px; height: 14px;
      background: rgba(8,14,28,.98);
      border-right: 1px solid var(--border2);
      border-bottom: 1px solid var(--border2);
      transform: translateX(-50%) rotate(45deg);
    }
    .pc-header {
      display: flex; align-items: center; gap: 13px;
      margin-bottom: 16px; padding-bottom: 16px;
      border-bottom: 1px solid var(--border);
    }
    .pc-avatar {
      width: 52px; height: 52px; border-radius: 50%; flex-shrink: 0;
      border: 2.5px solid rgba(59,130,246,.50);
      box-shadow: 0 0 0 4px rgba(59,130,246,.10);
      overflow: hidden;
      background: linear-gradient(135deg,#2563eb,#10b981);
      display: flex; align-items: center; justify-content: center;
    }
    .pc-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .pc-avatar .pc-initials { font-size: 20px; font-weight: 800; color: #fff; }
    .pc-info { flex: 1; min-width: 0; }
    .pc-name  { font-size: 14px; font-weight: 800; letter-spacing: -.2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .pc-email { font-size: 10.5px; color: var(--muted); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .pc-badge {
      display: inline-flex; align-items: center; margin-top: 6px;
      font-size: 9px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
      color: rgba(59,130,246,.95); background: rgba(59,130,246,.10);
      border: 1px solid rgba(59,130,246,.20); border-radius: 20px; padding: 2px 9px;
    }
    .pc-logout {
      width: 100%; height: 40px;
      display: flex; align-items: center; justify-content: center; gap: 7px;
      border: 1px solid rgba(239,68,68,.32); border-radius: 12px;
      background: rgba(239,68,68,.07); color: rgba(239,68,68,.85);
      font-size: 12px; font-weight: 700; font-family: 'Montserrat', sans-serif;
      cursor: pointer; transition: background .2s;
    }
    .pc-logout:active { background: rgba(239,68,68,.20); }
    .pc-logout svg { width: 13px; height: 13px; }

    /* ── TOMTOM SDK OVERRIDES ──
       No NavigationControl is added below, so there's no built-in zoom
       control to hide (unlike Leaflet, which shows one by default).
       TomTom's Popup is built on MapLibre GL JS, so its DOM uses the
       .maplibregl-* class names; we target those (plus the legacy
       .mapboxgl-* names as a fallback, since some SDK builds alias them). */
    .maplibregl-popup-content,
    .mapboxgl-popup-content {
      background: rgba(8,14,28,.97) !important;
      backdrop-filter: blur(20px);
      border: 1px solid var(--border2) !important;
      border-radius: 18px !important;
      box-shadow: 0 10px 36px rgba(0,0,0,.55) !important;
      color: var(--text) !important;
      font-family: 'Montserrat', sans-serif !important;
      padding: 0 !important;
    }
    .maplibregl-popup-tip, .mapboxgl-popup-tip { display: none !important; }
    .maplibregl-popup-close-button,
    .mapboxgl-popup-close-button {
      color: var(--muted) !important; font-size: 18px !important;
      top: 8px !important; right: 10px !important;
    }
    .maplibregl-ctrl-attrib, .mapboxgl-ctrl-attrib {
      background: rgba(6,10,22,.8) !important; color: rgba(255,255,255,.3) !important; font-size: 9px !important;
    }
    .maplibregl-ctrl-logo, .mapboxgl-ctrl-logo { opacity: .55; }

    /* ── SEARCH DROPDOWN ── */
    .search-results {
      position: absolute; top: calc(100% + 8px); left: 0; right: 0;
      background: rgba(8,14,28,.98);
      backdrop-filter: blur(28px) saturate(180%);
      -webkit-backdrop-filter: blur(28px) saturate(180%);
      border: 1px solid var(--border2);
      border-radius: 18px;
      box-shadow: 0 14px 40px rgba(0,0,0,.55);
      max-height: min(320px, 50vh);
      overflow-y: auto;
      opacity: 0; transform: translateY(-6px);
      pointer-events: none;
      transition: opacity .18s ease, transform .18s ease;
      z-index: 25;
    }
    .search-results.open { opacity: 1; transform: translateY(0); pointer-events: all; }
    .sr-item {
      display: flex; align-items: center; gap: 11px;
      padding: 11px 16px; cursor: pointer;
      border-bottom: 1px solid rgba(255,255,255,.05);
      transition: background .15s;
    }
    .sr-item:last-child { border-bottom: none; }
    .sr-item:active, .sr-item:hover { background: var(--hover); }
    .sr-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
    .sr-text { flex: 1; min-width: 0; }
    .sr-title { font-size: 12.5px; font-weight: 700; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .sr-sub   { font-size: 10.5px; color: var(--muted); margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .sr-empty { padding: 18px 16px; text-align: center; font-size: 11.5px; color: var(--muted); }

    /* ── SHARED SHEET STYLES ── */
    .sheet-overlay {
      position: fixed; inset: 0; z-index: 60;
      background: rgba(3,6,16,.58);
      backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
      opacity: 0; pointer-events: none;
      transition: opacity .25s ease;
    }
    .sheet-overlay.open { opacity: 1; pointer-events: all; }
    .sheet {
      position: fixed; left: 0; right: 0; bottom: 0; z-index: 61;
      max-width: 560px; margin: 0 auto;
      max-height: min(82vh, 720px);
      display: flex; flex-direction: column;
      background: rgba(8,14,28,.99);
      backdrop-filter: blur(32px) saturate(180%);
      -webkit-backdrop-filter: blur(32px) saturate(180%);
      border: 1px solid var(--border2);
      border-bottom: none;
      border-radius: 26px 26px 0 0;
      box-shadow: 0 -14px 56px rgba(0,0,0,.65);
      transform: translateY(100%);
      transition: transform .32s cubic-bezier(.22,1,.36,1);
      padding-bottom: var(--safe-b);
    }
    .sheet.open { transform: translateY(0); }
    .sheet-grabber {
      width: 38px; height: 4px; border-radius: 99px;
      background: rgba(255,255,255,.16);
      margin: 10px auto 0; flex-shrink: 0;
    }
    .sheet-head {
      display: flex; align-items: center; justify-content: space-between;
      padding: 14px 20px 12px; flex-shrink: 0;
      border-bottom: 1px solid var(--border);
    }
    .sheet-title { font-size: 16px; font-weight: 800; letter-spacing: -.2px; }
    .sheet-close {
      width: 32px; height: 32px; border-radius: 50%;
      border: 1px solid var(--border2); background: var(--card);
      color: var(--text);
      display: flex; align-items: center; justify-content: center;
      cursor: pointer; flex-shrink: 0;
    }
    .sheet-close:active { background: var(--hover); }
    .sheet-close svg { width: 14px; height: 14px; }
    .sheet-body { flex: 1; overflow-y: auto; padding: 16px 20px 26px; -webkit-overflow-scrolling: touch; }

    /* ── ROUTE CARDS ── */
    .route-card {
      background: var(--card); border: 1px solid var(--border);
      border-radius: 18px; padding: 16px; margin-bottom: 12px;
    }
    .route-card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
    .route-name  { font-size: 13.5px; font-weight: 800; line-height: 1.35; }
    .route-desc  { font-size: 11px; color: var(--muted); margin-top: 4px; line-height: 1.5; }
    .route-stats { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; }
    .route-stat  {
      display: flex; align-items: center; gap: 5px;
      font-size: 10.5px; font-weight: 700; color: var(--text);
      background: rgba(255,255,255,.04); border: 1px solid var(--border);
      border-radius: 999px; padding: 5px 11px;
    }
    .route-stat .dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
    .route-units { margin-top: 12px; display: flex; flex-direction: column; gap: 6px; }
    .live-unit-row {
      display: flex; align-items: center; gap: 10px;
      padding: 9px 11px; border-radius: 12px;
      background: rgba(16,185,129,.06); border: 1px solid rgba(16,185,129,.15);
      cursor: pointer; transition: background .15s;
    }
    .live-unit-row:active, .live-unit-row:hover { background: rgba(16,185,129,.13); }
    .live-unit-row .pulse-dot {
      width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
      box-shadow: 0 0 0 3px rgba(16,185,129,.16);
    }
    .live-unit-row .lu-text { flex: 1; min-width: 0; }
    .live-unit-row .lu-code  { font-size: 12px; font-weight: 800; color: var(--text); }
    .live-unit-row .lu-plate { font-size: 10px; color: var(--muted); margin-top: 1px; }
    .live-unit-row .lu-go    { font-size: 10px; font-weight: 700; color: #34d399; flex-shrink: 0; }
    .route-units-empty {
      margin-top: 12px; padding: 14px; text-align: center;
      font-size: 11px; color: var(--muted);
      background: rgba(255,255,255,.02); border: 1px dashed var(--border); border-radius: 12px;
    }
    .sheet-empty { text-align: center; padding: 40px 10px; color: var(--muted); font-size: 12.5px; }

    /* ── BOOKING FORM ── */
    .login-gate { text-align: center; padding: 34px 12px 18px; }
    .login-gate-icon  { font-size: 34px; margin-bottom: 10px; }
    .login-gate-title { font-size: 14.5px; font-weight: 800; margin-bottom: 6px; }
    .login-gate-sub   { font-size: 11.5px; color: var(--muted); line-height: 1.6; margin-bottom: 20px; }
    .login-gate-btn {
      display: inline-flex; align-items: center; justify-content: center;
      height: 42px; padding: 0 26px; border-radius: 12px; border: none;
      background: linear-gradient(135deg, rgba(37,99,235,.9), rgba(16,185,129,.75));
      color: #fff; font-size: 12.5px; font-weight: 700;
      font-family: 'Montserrat', sans-serif;
      cursor: pointer; text-decoration: none;
    }
    .form-group   { margin-bottom: 14px; }
    .form-label   {
      display: block; font-size: 10.5px; font-weight: 700;
      letter-spacing: .04em; text-transform: uppercase;
      color: var(--muted); margin-bottom: 7px;
    }
    .form-row     { display: flex; gap: 10px; }
    .form-row .form-group { flex: 1; min-width: 0; }
    .form-input, .form-select, .form-textarea {
      width: 100%; height: 44px; border-radius: 12px;
      border: 1px solid var(--border2);
      background: rgba(255,255,255,.04);
      color: var(--text); font-size: 13px; font-family: 'Montserrat', sans-serif;
      padding: 0 14px; outline: none;
      transition: border-color .18s;
    }
    .form-select {
      appearance: none; -webkit-appearance: none; color-scheme: dark;
      background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23808a9c' stroke-width='2'><polyline points='6 9 12 15 18 9'/></svg>");
      background-repeat: no-repeat; background-position: right 14px center; background-size: 14px;
      padding-right: 36px;
    }
    .form-select option { background: #0d1526; color: var(--text); }
    .form-input[type="date"], .form-input[type="time"] { color-scheme: dark; }
    .form-textarea { height: auto; min-height: 72px; padding: 12px 14px; resize: vertical; font-family: 'Montserrat', sans-serif; }
    .form-input:focus, .form-select:focus, .form-textarea:focus { border-color: rgba(59,130,246,.50); }
    .form-input::placeholder, .form-textarea::placeholder { color: var(--muted); }
    .form-input:disabled, .form-select:disabled { opacity: .45; cursor: not-allowed; }
    .stepper {
      display: flex; align-items: center; height: 44px;
      border: 1px solid var(--border2); border-radius: 12px;
      overflow: hidden; background: rgba(255,255,255,.04);
    }
    .stepper-btn {
      width: 42px; height: 100%; flex-shrink: 0;
      border: none; background: transparent;
      color: var(--text); font-size: 17px; font-weight: 700; cursor: pointer;
    }
    .stepper-btn:active { background: var(--hover); }
    .stepper-val { flex: 1; text-align: center; font-size: 14px; font-weight: 800; }
    .field-hint  { font-size: 10px; color: var(--muted); margin-top: 6px; }
    .submit-btn {
      width: 100%; height: 46px; border-radius: 13px; border: none; margin-top: 6px;
      background: linear-gradient(135deg, rgba(37,99,235,.92), rgba(16,185,129,.78));
      color: #fff; font-size: 13px; font-weight: 800;
      font-family: 'Montserrat', sans-serif;
      cursor: pointer; transition: opacity .15s;
      display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    .submit-btn:active   { opacity: .82; }
    .submit-btn:disabled { opacity: .5; cursor: not-allowed; }
    .form-msg { font-size: 11.5px; font-weight: 600; margin-top: 10px; padding: 9px 12px; border-radius: 10px; display: none; }
    .form-msg.show    { display: block; }
    .form-msg.error   { background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.25); color: #f87171; }
    .form-msg.success { background: rgba(16,185,129,.1); border: 1px solid rgba(16,185,129,.25); color: #34d399; }
    .booking-divider {
      display: flex; align-items: center; gap: 10px;
      margin: 24px 0 14px;
    }
    .booking-divider span {
      font-size: 10.5px; font-weight: 700; letter-spacing: .05em;
      text-transform: uppercase; color: var(--muted); white-space: nowrap;
    }
    .booking-divider::before, .booking-divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }
    .booking-item {
      background: var(--card); border: 1px solid var(--border);
      border-radius: 16px; padding: 13px 15px; margin-bottom: 10px;
    }
    .booking-item-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .booking-route { font-size: 12.5px; font-weight: 700; }
    .booking-sub   { font-size: 10.5px; color: var(--muted); margin-top: 5px; line-height: 1.6; }
    .booking-cancel {
      margin-top: 10px; width: 100%; height: 32px; border-radius: 9px;
      border: 1px solid rgba(239,68,68,.28); background: rgba(239,68,68,.07);
      color: #f87171; font-size: 10.5px; font-weight: 700;
      font-family: 'Montserrat', sans-serif; cursor: pointer;
    }
    .booking-cancel:active   { background: rgba(239,68,68,.18); }
    .booking-cancel:disabled { opacity: .4; cursor: not-allowed; }

    /* ── DESKTOP ── */
    @media (min-width: 768px) {
      .bottom-nav { bottom: 28px; }
      .profile-card {
        left: auto; right: 40px;
        transform: translateY(18px);
        bottom: calc(100px + var(--safe-b));
      }
      .profile-card.open { transform: translateY(0); }
      .profile-card::after { left: auto; right: 30px; transform: rotate(45deg); }
      .top-bar { top: 18px; left: 18px; right: 18px; }
      .sheet { display: none !important; }
      .sheet-overlay { display: none !important; }
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
      <polyline points="16 17 21 12 16 17"/>
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
        <input class="top-search" type="text" placeholder="Search routes, jeepneys, or a location…" id="topSearch" autocomplete="off">
        <div class="search-results" id="searchResults"></div>
      </div>
      <button class="top-icon-btn" id="themeBtn" title="Toggle map theme">
        <span id="themeBtnIcon">🌙</span>
      </button>
      <button class="top-icon-btn" id="trafficBtn" title="Toggle traffic">
        <span id="trafficBtnIcon">🚦</span>
      </button>
      <button class="top-icon-btn" id="locateBtn" title="Find nearest jeepney">
        <span id="locateBtnIcon">📍</span>
      </button>
    </div>

    <!-- LIVE COUNTER PILL -->
    <div class="live-pill" id="livePill">
      <div class="live-dot"></div>
      <span id="liveCount">0 jeepneys live</span>
    </div>

    <!-- ROUTE TO JEEPNEY CARD -->
    <div class="route-info-card" id="routeInfoCard">
      <div class="ric-top">
        <div class="ric-title" id="ricTitle">Routing to nearest jeepney…</div>
        <button class="ric-close" onclick="clearRouteToJeepney()">✕</button>
      </div>

      <div class="ric-stats">
        <div class="ric-stat"><span id="ricDistance">—</span><small>distance</small></div>
        <div class="ric-stat"><span id="ricDuration">—</span><small>ETA</small></div>
      </div>
      <div class="ric-hint" id="ricHint">Tap the map to set your starting point, or use GPS 📍</div>
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
/* ── APP HEIGHT ── */
function setAppHeight() {
  const h = window.innerHeight + 'px';
  document.documentElement.style.setProperty('--app-height', h);
  document.documentElement.style.height = h;
  document.body.style.height = h;
}
setAppHeight();
window.addEventListener('resize', setAppHeight);

/* ── MAP (TomTom Maps SDK for Web) ── */
const TOMTOM_KEY = '2YJdW1w9sE4xkaSAkFUCf655UVpMAEvO'; // domain-restricted in TomTom dashboard
const DEFAULT_LAT = 10.6765, DEFAULT_LNG = 122.9509;

// NOTE: TomTom's default vector style is light-only. There's no built-in
// "night style" preset name to swap to the way Leaflet swapped raster tile
// URLs, so day/dawn/dusk/night is simulated with a CSS filter on the map
// canvas (see #map.jl-filter-* rules above). If you create a custom dark
// style in TomTom Map Styler, swap this out for map.setStyle('your-style-url').
const MAP_THEMES = {
  dawn:  { filterClass: null,               icon:'🌅', label:'dawn'  },
  day:   { filterClass: null,               icon:'☀️', label:'day'   },
  dusk:  { filterClass: 'jl-filter-dim',    icon:'🌇', label:'dusk'  },
  night: { filterClass: 'jl-filter-invert', icon:'🌙', label:'night' }
};
const THEME_ORDER = ['night','dawn','day','dusk'];
function getThemeByHour(h) {
  if (h >= 5  && h < 7)  return MAP_THEMES.dawn;
  if (h >= 7  && h < 17) return MAP_THEMES.day;
  if (h >= 17 && h < 19) return MAP_THEMES.dusk;
  return MAP_THEMES.night;
}

// TomTom coordinates are [lng, lat] (opposite of Leaflet's [lat, lng]).
const map = tt.map({
  key: TOMTOM_KEY,
  container: 'map',
  center: [DEFAULT_LNG, DEFAULT_LAT],
  zoom: 15,
});
// No tt.NavigationControl added on purpose — mirrors the old zoomControl:false setup.

function applyTheme(theme) {
  const mapEl = document.getElementById('map');
  mapEl.classList.remove('jl-filter-invert', 'jl-filter-dim');
  if (theme.filterClass) mapEl.classList.add(theme.filterClass);
  document.getElementById('themeBtnIcon').textContent = theme.icon;
  _jeepApplyTheme();
}
const initTheme = getThemeByHour(new Date().getHours());
let currentThemeIdx = THEME_ORDER.indexOf(initTheme.label);
if (currentThemeIdx < 0) currentThemeIdx = 0;
map.on('load', () => applyTheme(initTheme));
setTimeout(() => map.resize(), 300);
document.getElementById('themeBtn').addEventListener('click', () => {
  currentThemeIdx = (currentThemeIdx + 1) % THEME_ORDER.length;
  applyTheme(MAP_THEMES[THEME_ORDER[currentThemeIdx]]);
});
setInterval(() => {
  const auto = getThemeByHour(new Date().getHours());
  const idx  = THEME_ORDER.indexOf(auto.label);
  if (idx !== currentThemeIdx) { currentThemeIdx = idx; applyTheme(auto); }
}, 60000);

/* ── TRAFFIC LAYER (TomTom SDK native traffic, not a manual tile URL) ── */
let trafficOn = false;

function setTraffic(on) {
  if (on) {
    map.showTrafficFlow();
    map.showTrafficIncidents();
  } else {
    map.hideTrafficFlow();
    map.hideTrafficIncidents();
  }
}

document.getElementById('trafficBtn').addEventListener('click', () => {
  trafficOn = !trafficOn;
  const btn = document.getElementById('trafficBtn');
  setTraffic(trafficOn);
  if (trafficOn) {
    btn.style.background = 'rgba(239,68,68,.25)';
    btn.style.borderColor = 'rgba(239,68,68,.4)';
  } else {
    btn.style.background = '';
    btn.style.borderColor = '';
  }
});

/* ── PROFILE CARD ── */
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

/* ── LOGOUT ── */
async function doLogout() {
  try {
    await fetch('api.php?action=logout', { method:'POST', cache:'no-store' });
  } catch(e) { console.warn('Logout request failed:', e); }
  finally    { window.location.href = '../index.php'; }
}

/* ── TABS ── */
function setTab(el, name) {
  document.querySelectorAll('.nav-tab:not(.book-tab)').forEach(t => t.classList.remove('active'));
  if (!el.classList.contains('book-tab')) el.classList.add('active');
}

/* ══════════════════════════════════════════════
   SHEETS
══════════════════════════════════════════════ */
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
    loadRoutes();
    fetchMyBookings();
  }
}
function closeSheets() {
  document.getElementById('sheetOverlay').classList.remove('open');
  document.querySelectorAll('.sheet').forEach(s => s.classList.remove('open'));
}

async function loadRoutes(force) {
  if (routesCache && !force) { renderRoutesSheet(routesCache); populateJeepneySelect(routesCache); return; }
  try {
    const res  = await fetch('api.php?action=routes', { cache:'no-store' });
    const body = await res.json();
    if (!body.ok) throw new Error(body.message || 'Failed');
    routesCache = body.routes;
    renderRoutesSheet(routesCache);
    populateJeepneySelect(routesCache);
  } catch(e) {
    document.getElementById('routesBody').innerHTML = '<div class="sheet-empty">Couldn\'t load routes. Try again.</div>';
  }
}

const ROUTE_STATUS_DOT = { on_route:'#10b981', traffic:'#f59e0b', maintenance:'#f97316', complete:'#6b7280', idle:'#9ca3af' };

function renderRoutesSheet(routes) {
  const body = document.getElementById('routesBody');
  if (!routes?.length) { body.innerHTML = '<div class="sheet-empty">No routes set up yet.</div>'; return; }
  body.innerHTML = routes.map(r => {
    const units = (r.jeepneys || []).map(u => {
      const dot = ROUTE_STATUS_DOT[u.display_status] || ROUTE_STATUS_DOT.idle;
      const etaText = u.eta_minutes != null
        ? `~${u.eta_minutes} min${u.eta_dist_km != null ? ` · ${u.eta_dist_km} km` : ''}`
        : null;
      return `
        <div class="live-unit-row" onclick="locateRouteUnit(${u.account_id})">
          <span class="pulse-dot" style="background:${dot}"></span>
          <div class="lu-text">
            <div class="lu-code">${esc(u.unit_code)} <span style="font-weight:500;color:var(--muted)">· ${esc(u.plate_no)}</span></div>
            <div class="lu-plate">
              ${tripStatusBadge(u.display_status)}
              ${u.departure_time ? `<span style="margin-left:6px">Departs ${esc(u.departure_time)}</span>` : ''}
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

function populateJeepneySelect(routes) {
  const sel = document.getElementById('bf_jeepney');
  if (!sel) return;
  const current  = sel.value;
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
  if (e.target?.id === 'bf_jeepney') {
    const opt  = e.target.selectedOptions[0];
    const hint = document.getElementById('bf_capacityHint');
    hint.textContent = opt?.dataset.capacity ? `Seats up to ${opt.dataset.capacity} passengers` : '';
  }
});

let passengerCount = 1;
function adjustPassengers(delta) {
  passengerCount = Math.max(1, Math.min(99, passengerCount + delta));
  document.getElementById('bf_countVal').textContent = passengerCount;
}

function esc(str) {
  return String(str ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

const bookingForm = document.getElementById('bookingForm');
if (bookingForm) {
  const dateInput = document.getElementById('bf_date');
  const timeInput = document.getElementById('bf_time');
  const today     = new Date();
  dateInput.min   = today.toISOString().slice(0,10);
  dateInput.value = today.toISOString().slice(0,10);
  timeInput.value = today.toTimeString().slice(0,5);

  bookingForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const msg = document.getElementById('bf_msg');
    const btn = document.getElementById('bf_submit');
    msg.className = 'form-msg'; msg.textContent = '';
    const payload = {
      jeepney_id:       document.getElementById('bf_jeepney').value,
      passenger_name:   document.getElementById('bf_name').value.trim(),
      passenger_count:  passengerCount,
      booking_date:     dateInput.value,
      booking_time:     timeInput.value,
      pickup_location:  document.getElementById('bf_pickup').value.trim(),
      dropoff_location: document.getElementById('bf_dropoff').value.trim(),
      notes:            document.getElementById('bf_notes').value.trim(),
    };
    if (!payload.jeepney_id) {
      msg.textContent = 'Please select a jeepney unit.';
      msg.className = 'form-msg error show'; return;
    }
    btn.disabled = true; btn.textContent = 'Submitting…';
    try {
      const res  = await fetch('api.php?action=create_booking', {
        method:'POST', headers:{'Content-Type':'application/json'},
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
        dateInput.value = today.toISOString().slice(0,10);
        timeInput.value = today.toTimeString().slice(0,5);
        fetchMyBookings();
      }
    } catch(err) {
      msg.textContent = 'Network error — please try again.';
      msg.className = 'form-msg error show';
    } finally {
      btn.disabled = false; btn.textContent = 'Submit Booking Request';
    }
  });
}

const BOOKING_STATUS_STYLES = {
  pending:   { label:'Pending Review', color:'#f59e0b' },
  approved:  { label:'Approved',       color:'#10b981' },
  declined:  { label:'Declined',       color:'#ef4444' },
  cancelled: { label:'Cancelled',      color:'#6b7280' },
};
async function fetchMyBookings() {
  const list = document.getElementById('myBookings');
  if (!list) return;
  try {
    const res  = await fetch('api.php?action=my_bookings', { cache:'no-store' });
    const body = await res.json();
    if (!body.ok) throw new Error(body.message);
    if (!body.bookings.length) {
      list.innerHTML = '<div class="sheet-empty">No bookings yet — submit one above.</div>'; return;
    }
    list.innerHTML = body.bookings.map(b => {
      const s = BOOKING_STATUS_STYLES[b.status] || { label:b.status, color:'#9ca3af' };
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
  } catch(e) {
    list.innerHTML = '<div class="sheet-empty">Couldn\'t load your bookings.</div>';
  }
}
async function cancelBooking(id, btn) {
  btn.disabled = true; btn.textContent = 'Cancelling…';
  try {
    const res  = await fetch('api.php?action=cancel_booking', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ id })
    });
    const body = await res.json();
    if (body.ok) fetchMyBookings();
    else { btn.disabled = false; btn.textContent = 'Cancel Booking'; alert(body.message || 'Could not cancel.'); }
  } catch(e) { btn.disabled = false; btn.textContent = 'Cancel Booking'; }
}

/* ══════════════════════════════════════════════
   LIVE JEEPNEY TRACKING (unchanged logic)
══════════════════════════════════════════════ */


const TRIP_STATUS_STYLES = {
  on_route:    { label:'On Route',    color:'#10b981' },
  traffic:     { label:'In Traffic',  color:'#f59e0b' },
  maintenance: { label:'Maintenance', color:'#f97316' },
  complete:    { label:'Completed',   color:'#6b7280' },
  active:      { label:'Active',      color:'#10b981' },
  scheduled:   { label:'Scheduled',  color:'#3b82f6' },
  idle:        { label:'Idle',        color:'#9ca3af' },
};
function tripStatusBadge(status) {
  const s = TRIP_STATUS_STYLES[status] || { label: status || '—', color:'#9ca3af' };
  return `<span style="display:inline-flex;align-items:center;gap:4px;font-size:9px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;background:${s.color}22;color:${s.color};border:1px solid ${s.color}44;border-radius:999px;padding:2px 8px;"><span style="width:5px;height:5px;border-radius:50%;background:${s.color};flex-shrink:0;display:inline-block;"></span>${s.label}</span>`;
}
function timeAgo(dtStr) {
  if (!dtStr) return '—';
  const diff = Math.floor((Date.now() - new Date(dtStr).getTime()) / 1000);
  if (diff < 60)   return diff + 's ago';
  if (diff < 3600) return Math.floor(diff/60) + 'm ago';
  return Math.floor(diff/3600) + 'h ago';
}
function buildPopup(d) {
  const staleBadge = d.stale
    ? `<span style="font-size:9px;background:#374151;color:#9ca3af;padding:1px 7px;border-radius:999px;margin-left:5px;">last seen ${timeAgo(d.updated_at)}</span>` : '';
  const etaLine = d.eta_minutes != null
    ? `<div style="display:flex;align-items:center;justify-content:space-between;margin-top:8px;padding-top:8px;border-top:1px solid rgba(255,255,255,.07);">
         <span style="font-size:10px;color:#9ca3af;">ETA</span>
         <span style="font-size:12px;font-weight:800;color:#10b981;">~${d.eta_minutes} min${d.eta_dist_km != null ? `<span style="font-weight:500;color:#6b7280;font-size:10px;"> · ${d.eta_dist_km} km</span>` : ''}</span>
       </div>` : '';
  return `
    <div style="font-family:'Montserrat',sans-serif;padding:12px 14px;min-width:200px;">
      <div style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">
  <img src="/commuter/bus_se.png" alt="" style="width:12px;height:12px;object-fit:contain;flex-shrink:0;">
  <span style="font-size:15px;font-weight:800;color:#f9fafb;letter-spacing:-.3px;">${d.unit_code || '—'}</span>
  ${staleBadge}
</div>
      <div style="margin-bottom:10px;">${tripStatusBadge(d.display_status)}</div>
      <div style="display:flex;flex-direction:column;gap:0;">
        ${d.route_name ? `<div style="display:flex;align-items:center;justify-content:space-between;margin-top:5px;"><span style="font-size:10px;color:#9ca3af;">Route</span><span style="font-size:11px;font-weight:700;color:#e8edf5;text-align:right;max-width:150px;">${d.route_name}</span></div>` : ''}
        ${d.departure_time ? `<div style="display:flex;align-items:center;justify-content:space-between;margin-top:5px;"><span style="font-size:10px;color:#9ca3af;">Departure</span><span style="font-size:11px;font-weight:700;color:#e8edf5;">${d.departure_time}</span></div>` : ''}
        <div style="display:flex;align-items:center;justify-content:space-between;margin-top:5px;"><span style="font-size:10px;color:#9ca3af;">Driver</span><span style="font-size:11px;font-weight:600;color:#d1d5db;">${d.driver_name || '—'}</span></div>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-top:5px;"><span style="font-size:10px;color:#9ca3af;">Plate</span><span style="font-size:11px;font-weight:600;color:#d1d5db;">${d.plate_no || '—'}</span></div>
        ${etaLine}
      </div>
    </div>`;
}




/* ══════════════════════════════════════════════
   LIVE FLEET — one map layer, fast polling, smooth gliding, 3D bus sprites
══════════════════════════════════════════════ */
const POLL_MS  = 3000;   // ask the server every 3 s
const TWEEN_MS = 3200;   // glide a bit longer than the gap so motion never stops

const jeepData  = {};    // account_id -> latest server row
const jeepFleet = {};    // account_id -> animation state
let _fleetRaf = null, _lastDraw = 0, _jeepReady = false, _polling = false;
let _openPopup = null, _openPopupId = null;
let _jeepNight = false;

const JEEP_GLOW = ['match', ['get', 'state'],
  'traffic', '#f59e0b', 'maintenance', '#f97316', 'complete', '#6b7280',
  'idle', '#93c5fd', 'stale', '#6b7280', '#10b981'];

function _bearing(lat1, lng1, lat2, lng2) {
  const rad = x => x * Math.PI / 180, deg = x => x * 180 / Math.PI;
  const dLng = rad(lng2 - lng1);
  const y = Math.sin(dLng) * Math.cos(rad(lat2));
  const x = Math.cos(rad(lat1)) * Math.sin(rad(lat2)) -
            Math.sin(rad(lat1)) * Math.cos(rad(lat2)) * Math.cos(dLng);
  return (deg(Math.atan2(y, x)) + 360) % 360;
}
function _distM(lat1, lng1, lat2, lng2) {
  const dy = (lat2 - lat1) * 111320;
  const dx = (lng2 - lng1) * 111320 * Math.cos(lat1 * Math.PI / 180);
  return Math.sqrt(dx * dx + dy * dy);
}
function _unwrap(prev, next) {            // shortest way around the circle
  return prev + ((((next - prev) % 360) + 540) % 360 - 180);
}

// Night theme inverts the map canvas with a CSS filter, which would also invert
// the buses. Pre-apply the same colour maths so they come out normal after it.
function nightify(imgData) {
  const d = imgData.data, cl = v => v < 0 ? 0 : v > 1 ? 1 : v;
  for (let i = 0; i < d.length; i += 4) {
    if (!d[i + 3]) continue;
    const r = 1 - d[i] / 255, g = 1 - d[i + 1] / 255, b = 1 - d[i + 2] / 255;
    d[i]     = cl(-0.574 * r + 1.430 * g + 0.144 * b) * 255;
    d[i + 1] = cl( 0.426 * r + 0.430 * g + 0.144 * b) * 255;
    d[i + 2] = cl( 0.426 * r + 1.430 * g - 0.856 * b) * 255;
  }
  return imgData;
}

// which of the 4 bus pictures to show, based on the heading as seen on screen
function _jeepSprite(heading) {
  const h = (((heading - map.getBearing()) % 360) + 360) % 360;
  const dir = h < 90 ? 'ne' : h < 180 ? 'se' : h < 270 ? 'sw' : 'nw';
  return 'bus-' + dir + (_jeepNight ? '-night' : '');
}

// ── glide each bus from where it is now to its newest position ──
function _fleetCurrent(f, now) {
  const k = Math.min((now - f.t0) / TWEEN_MS, 1);
  const e = k < .5 ? 2 * k * k : -1 + (4 - 2 * k) * k;
  return {
    lng: f.from.lng + (f.to.lng - f.from.lng) * e,
    lat: f.from.lat + (f.to.lat - f.from.lat) * e,
    heading: f.from.heading + (f.to.heading - f.from.heading) * e,
    done: k >= 1
  };
}
function _fleetRender(now) {
  const src = map.getSource('jeep-source');
  if (!src) { _fleetRaf = null; return; }
  if (now - _lastDraw < 33) { _fleetRaf = requestAnimationFrame(_fleetRender); return; }  // ~30 fps
  _lastDraw = now;
  let animating = false;
  const features = [];
  for (const id in jeepFleet) {
    const f = jeepFleet[id], c = _fleetCurrent(f, now);
    if (!c.done) animating = true;
    features.push({
      type: 'Feature',
      properties: { id: +id, state: f.state, img: _jeepSprite(c.heading) },
      geometry: { type: 'Point', coordinates: [c.lng, c.lat] }
    });
    if (_openPopup && String(_openPopupId) === id) _openPopup.setLngLat([c.lng, c.lat]);
  }
  src.setData({ type: 'FeatureCollection', features });
  _fleetRaf = animating ? requestAnimationFrame(_fleetRender) : null;
}
function _fleetKick() { if (!_fleetRaf) _fleetRaf = requestAnimationFrame(_fleetRender); }

function _fleetUpdate(d, lat, lng, now) {
  const id = d.account_id, prev = jeepFleet[id];
  const state = d.stale ? 'stale' : (d.display_status || 'on_route');
  let target = null;
  if (d.heading != null && d.heading !== '' && !isNaN(d.heading)) target = +d.heading;
  else if (prev && _distM(prev.to.lat, prev.to.lng, lat, lng) > 4) target = _bearing(prev.to.lat, prev.to.lng, lat, lng);

  if (!prev) {
    const h = target ?? 0;
    jeepFleet[id] = { from: { lng, lat, heading: h }, to: { lng, lat, heading: h }, t0: now, state };
    return;
  }
  const cur = _fleetCurrent(prev, now);
  prev.from  = { lng: cur.lng, lat: cur.lat, heading: cur.heading };
  prev.to    = { lng, lat, heading: target === null ? cur.heading : _unwrap(cur.heading, target) };
  prev.t0    = now;
  prev.state = state;
}

// ── layers (created once the map has loaded) ──
function _jeepInitLayers() {
  const dirs = ['ne', 'se', 'sw', 'nw'];
  Promise.all(dirs.map(k => new Promise(res => {
    const img = new Image();
    img.onload = () => {
      const c = document.createElement('canvas');
      c.width = img.width; c.height = img.height;
      const ctx = c.getContext('2d');
      ctx.drawImage(img, 0, 0);
      map.addImage('bus-' + k, ctx.getImageData(0, 0, c.width, c.height));
      map.addImage('bus-' + k + '-night', nightify(ctx.getImageData(0, 0, c.width, c.height)));
      res();
    };
    img.onerror = () => { console.warn('Missing bus sprite: bus_' + k + '.png'); res(); };
    img.src = 'bus_' + k + '.png';
  }))).then(() => {
    map.addSource('jeep-source', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });

    map.addLayer({
      id: 'jeep-glow', type: 'circle', source: 'jeep-source',
      paint: {
        'circle-radius': ['interpolate', ['linear'], ['zoom'], 10, 3, 13, 6, 15, 11, 17, 20, 19, 40],
        'circle-color': JEEP_GLOW,
        'circle-opacity': 0.35,
        'circle-blur': 0.5,
        'circle-translate': [0, 6],
        'circle-pitch-alignment': 'map'
      }
    });
    map.addLayer({
      id: 'jeep-icons', type: 'symbol', source: 'jeep-source',
      layout: {
        'icon-image': ['get', 'img'],
        'icon-size': ['interpolate', ['linear'], ['zoom'], 10, 0.05, 13, 0.09, 15, 0.15, 17, 0.22, 19, 0.4],
        'icon-rotation-alignment': 'viewport',
        'icon-pitch-alignment': 'viewport',
        'icon-allow-overlap': true,
        'icon-ignore-placement': true
      },
      paint: { 'icon-opacity': ['case', ['==', ['get', 'state'], 'stale'], 0.45, 1] }
    });

    map.on('click', 'jeep-icons', e => openJeepPopup(e.features[0].properties.id));
    map.on('mouseenter', 'jeep-icons', () => { map.getCanvas().style.cursor = 'pointer'; });
    map.on('mouseleave', 'jeep-icons', () => { map.getCanvas().style.cursor = ''; });
    map.on('rotate', _fleetKick);   // re-pick the sprite if the user rotates the map

    _jeepReady = true;
    _jeepApplyTheme();
    _fleetKick();
  });
}
map.on('load', _jeepInitLayers);

function _jeepApplyTheme() {
  _jeepNight = document.getElementById('map').classList.contains('jl-filter-invert');
  if (_jeepReady) _fleetKick();
}
function _jeepSetFilter(ids) {            // ids = array to show only those, null = show all
  if (!_jeepReady) return;
  const f = ids === null ? null : ['in', ['get', 'id'], ['literal', ids]];
  ['jeep-glow', 'jeep-icons'].forEach(l => map.setFilter(l, f));
}

function openJeepPopup(id) {
  const d = jeepData[id], f = jeepFleet[id];
  if (!d || !f) return;
  if (_openPopup) _openPopup.remove();
  const c = _fleetCurrent(f, performance.now());
  const p = new tt.Popup({ offset: 18, maxWidth: '260px' })
    .setLngLat([c.lng, c.lat]).setHTML(buildPopup(d)).addTo(map);
  _openPopup = p; _openPopupId = id;
  p.on('close', () => { if (_openPopup === p) { _openPopup = null; _openPopupId = null; } });
}

// ── fast polling ──
async function pollJeepneys() {
  if (_polling || document.hidden) return;
  _polling = true;
  try {
    const res = await fetch('api.php?action=live_jeepneys', { cache: 'no-store' });
    if (!res.ok) return;
    const body = await res.json();
    if (!body.ok) return;

    const now = performance.now();
    const seen = new Set();
    body.jeepneys.forEach(d => {
      const lat = parseFloat(d.lat), lng = parseFloat(d.lng);
      if (!isFinite(lat) || !isFinite(lng)) return;
      seen.add(String(d.account_id));
      jeepData[d.account_id] = d;
      _fleetUpdate(d, lat, lng, now);
    });
    Object.keys(jeepFleet).forEach(id => {
      if (!seen.has(id)) {
        delete jeepFleet[id]; delete jeepData[id];
        if (_openPopup && String(_openPopupId) === id) _openPopup.remove();
      }
    });
    if (_openPopup && jeepData[_openPopupId]) _openPopup.setHTML(buildPopup(jeepData[_openPopupId]));

    const count = seen.size;

        document.getElementById('liveCount').textContent =
      count + (count === 1 ? ' jeepney live' : ' jeepneys live');
    document.getElementById('livePill').classList.toggle('visible', count > 0);

    if (_jeepReady) _fleetKick();
  } catch (e) {
    console.warn('Poll failed:', e);
  } finally {
    _polling = false;
  }
}
setInterval(pollJeepneys, POLL_MS);
pollJeepneys();

/* ══════════════════════════════════════════════
   ROUTE TO NEAREST JEEPNEY
══════════════════════════════════════════════ */
let userOrigin      = null;   // {lat, lng}
let originMarker     = null;
let routeLayerId     = 'route-to-jeep-layer';
let routeSourceId    = 'route-to-jeep-source';
const currentRouteMode = 'pedestrian';
let targetJeepId     = null;
let mapClickArmed    = false;

function haversineKm(lat1, lon1, lat2, lon2) {
  const R = 6371, dLat = (lat2-lat1)*Math.PI/180, dLon = (lon2-lon1)*Math.PI/180;
  const a = Math.sin(dLat/2)**2 + Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*Math.sin(dLon/2)**2;
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
}

function makeUserEl(mode) {
  if (!document.getElementById('anim_userPulse')) {
    const style = document.createElement('style');
    style.id = 'anim_userPulse';
    style.textContent = `@keyframes userPulse { 0%{transform:scale(1);opacity:.55} 70%{transform:scale(2.2);opacity:0} 100%{transform:scale(2.2);opacity:0} }`;
    document.head.appendChild(style);
  }
  const isCar   = mode === 'car';
  const bg      = isCar ? '#f59e0b' : '#3b82f6';
  const glow    = isCar ? 'rgba(245,158,11,.55)' : 'rgba(59,130,246,.55)';
  const iconSrc = isCar
    ? window.location.origin + '/commuter/car.png'
    : window.location.origin + '/commuter/people.png';

  const el = document.createElement('div');
  el.style.cssText = 'position:relative;width:36px;height:36px;';
  el.innerHTML = `
    <span style="position:absolute;top:50%;left:50%;width:34px;height:34px;margin-top:-17px;margin-left:-17px;
                 border-radius:50%;background:${glow};opacity:.55;animation:userPulse 2s ease-out infinite;pointer-events:none;"></span>
    <div style="position:relative;width:34px;height:34px;border-radius:50%;background:${bg};
                border:3px solid #fff;box-shadow:0 3px 10px rgba(0,0,0,.4);
                display:flex;align-items:center;justify-content:center;overflow:hidden;">
      <img src="${iconSrc}" width="20" height="20" style="object-fit:contain;display:block;">
    </div>`;
  return el;
}

function findNearestJeepney(lat, lng) {
  let best = null, bestDist = Infinity;
  Object.entries(jeepData).forEach(([id, d]) => {
    if (d.stale) return; // skip offline units
    const dLat = parseFloat(d.lat), dLng = parseFloat(d.lng);
    if (!isFinite(dLat) || !isFinite(dLng)) return;
    const dist = haversineKm(lat, lng, dLat, dLng);
    if (dist < bestDist) { bestDist = dist; best = { id, d, dist }; }
  });
  return best;
}

// GPS button
document.getElementById('locateBtn').addEventListener('click', () => {
  if (!navigator.geolocation) { alert('GPS not available on this device.'); return; }
  document.getElementById('locateBtnIcon').textContent = '⏳';
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      document.getElementById('locateBtnIcon').textContent = '📍';
      setOriginAndRoute(pos.coords.latitude, pos.coords.longitude);
    },
    () => {
      document.getElementById('locateBtnIcon').textContent = '📍';
      alert('Could not get your location. Try tapping the map instead.');
      armMapClick();
    },
    { enableHighAccuracy: true, timeout: 8000 }
  );
});

// Manual pin: arm map-click mode, then listen once
function armMapClick() {
  mapClickArmed = true;
  document.getElementById('ricHint').textContent = 'Tap anywhere on the map to set your starting point…';
  document.getElementById('routeInfoCard').classList.add('visible');
}
map.on('click', (e) => {
  if (!mapClickArmed) return;
  mapClickArmed = false;
  setOriginAndRoute(e.lngLat.lat, e.lngLat.lng);
});
// Long-press-ish: also allow tapping map anytime origin card is open with no origin set yet
document.getElementById('routeInfoCard').addEventListener('click', (e) => {
  if (e.target.id === 'ricHint' && !userOrigin) armMapClick();
});

function setOriginAndRoute(lat, lng) {
  userOrigin = { lat, lng };
  if (originMarker) originMarker.remove();
  originMarker = new tt.Marker({ element: makeUserEl(currentRouteMode) }).setLngLat([lng, lat]).addTo(map);

  const nearest = findNearestJeepney(lat, lng);
  if (!nearest) {
    document.getElementById('routeInfoCard').classList.add('visible');
    document.getElementById('ricTitle').textContent = 'No jeepneys online right now';
    document.getElementById('ricHint').textContent = 'Check back in a bit, or browse routes below.';
    document.getElementById('ricDistance').textContent = '—';
    document.getElementById('ricDuration').textContent = '—';
    return;
  }
  targetJeepId = nearest.id;
  document.getElementById('routeInfoCard').classList.add('visible');
  document.getElementById('ricTitle').textContent = `Routing to ${nearest.d.unit_code || 'nearest jeepney'}`;
  drawRoute();
}



async function drawRoute() {
  if (!userOrigin || !targetJeepId) return;
  const d = jeepData[targetJeepId];
  if (!d) return;
  const destLat = parseFloat(d.lat), destLng = parseFloat(d.lng);

  document.getElementById('ricHint').textContent = 'Calculating route…';
  try {
    // traffic=true + sectionType=traffic gives per-segment congestion data.
    // Traffic data only applies meaningfully to 'car' mode; TomTom still
    // accepts the params for pedestrian but sections will just come back empty.
    const url = `https://api.tomtom.com/routing/1/calculateRoute/` +
      `${userOrigin.lat},${userOrigin.lng}:${destLat},${destLng}/json` +
      `?key=${TOMTOM_KEY}&travelMode=${currentRouteMode}&routeType=fastest` +
      `&traffic=true&sectionType=traffic`;
    const res  = await fetch(url);
    const data = await res.json();
    const route = data.routes?.[0];
    if (!route) throw new Error('No route found');

    const coords = [];
    route.legs.forEach(leg => leg.points.forEach(p => coords.push([p.longitude, p.latitude])));

    // Traffic color scale, same idea as Google Maps' green/yellow/red bands
    const TRAFFIC_COLORS = {
      0: '#10b981', // free flow
      1: '#f59e0b', // minor delay
      2: '#f97316', // moderate delay
      3: '#ef4444', // heavy delay
      4: '#7f1d1d', // severe / road closed
    };

    const trafficSections = (route.sections || []).filter(s => s.sectionType === 'TRAFFIC');
    const features = [];

    if (currentRouteMode === 'car' && trafficSections.length) {
      // Walk the route and color each stretch by its traffic magnitude.
      // Any point range not covered by a TRAFFIC section is free-flow (green).
      let cursor = 0;
      const sorted = [...trafficSections].sort((a,b) => a.startPointIndex - b.startPointIndex);
      sorted.forEach(sec => {
        if (sec.startPointIndex > cursor) {
          features.push({
            type: 'Feature',
            properties: { color: TRAFFIC_COLORS[0] },
            geometry: { type: 'LineString', coordinates: coords.slice(cursor, sec.startPointIndex + 1) },
          });
        }
        const mag = sec.magnitudeOfDelay ?? 0;
        features.push({
          type: 'Feature',
          properties: { color: TRAFFIC_COLORS[mag] || TRAFFIC_COLORS[0] },
          geometry: { type: 'LineString', coordinates: coords.slice(sec.startPointIndex, sec.endPointIndex + 1) },
        });
        cursor = sec.endPointIndex;
      });
      if (cursor < coords.length - 1) {
        features.push({
          type: 'Feature',
          properties: { color: TRAFFIC_COLORS[0] },
          geometry: { type: 'LineString', coordinates: coords.slice(cursor) },
        });
      }
    } else {
      // Walking mode (or no traffic data): single solid color, as before
      features.push({
        type: 'Feature',
        properties: { color: currentRouteMode === 'pedestrian' ? '#3b82f6' : '#10b981' },
        geometry: { type: 'LineString', coordinates: coords },
      });
    }

    const geojson = { type: 'FeatureCollection', features };

    if (map.getLayer(routeLayerId)) map.removeLayer(routeLayerId);
    if (map.getLayer(routeLayerId + '-casing')) map.removeLayer(routeLayerId + '-casing');
    if (map.getSource(routeSourceId)) map.removeSource(routeSourceId);
    map.addSource(routeSourceId, { type: 'geojson', data: geojson });

    // Dark casing underneath for a bold, "popped" look
    map.addLayer({
      id: routeLayerId + '-casing',
      type: 'line',
      source: routeSourceId,
      layout: { 'line-join': 'round', 'line-cap': 'round' },
      paint: { 'line-color': '#0a1020', 'line-width': 11, 'line-opacity': 0.6 },
    });
    // Colored traffic-aware line on top, using each feature's own color
    map.addLayer({
      id: routeLayerId,
      type: 'line',
      source: routeSourceId,
      layout: { 'line-join': 'round', 'line-cap': 'round' },
      paint: {
        'line-color': ['get', 'color'],
        'line-width': 7,
        'line-opacity': 0.95,
      },
    });

    const distKm = (route.summary.lengthInMeters / 1000).toFixed(1);
    const mins   = Math.round(route.summary.travelTimeInSeconds / 60);
    document.getElementById('ricDistance').textContent = `${distKm} km`;
    document.getElementById('ricDuration').textContent  = `${mins} min`;
    document.getElementById('ricHint').textContent = currentRouteMode === 'car'
      ? 'Route colored by live traffic — green is clear, red is heavy.'
      : 'Route updates automatically as the jeepney moves.';

    const bounds = new tt.LngLatBounds();
    coords.forEach(c => bounds.extend(c));
    map.fitBounds(bounds, { padding: 80, duration: 500 });
  } catch (err) {
    console.warn('Routing failed:', err);
    document.getElementById('ricHint').textContent = 'Could not calculate route — try again.';
  }
}

function clearRouteToJeepney() {
  userOrigin = null; targetJeepId = null; mapClickArmed = false;
  if (originMarker) { originMarker.remove(); originMarker = null; }
  if (map.getLayer(routeLayerId)) map.removeLayer(routeLayerId);
  if (map.getLayer(routeLayerId + '-casing')) map.removeLayer(routeLayerId + '-casing');
  if (map.getSource(routeSourceId)) map.removeSource(routeSourceId);
  document.getElementById('routeInfoCard').classList.remove('visible');
}
// Keep the route live: redraw every time the jeepney position updates
const _originalPollJeepneys = pollJeepneys;
setInterval(() => {
  if (userOrigin && targetJeepId && jeepData[targetJeepId]) drawRoute();
}, 8000);


/* ── SEARCH (jeepneys + locations via Nominatim) ── */
const searchInput   = document.getElementById('topSearch');
const searchResults = document.getElementById('searchResults');
const SEARCH_DOT = { on_route:'#10b981', traffic:'#f59e0b', maintenance:'#f97316', complete:'#6b7280', idle:'#9ca3af' };

let placeMarker   = null;
let placeMatches  = [];
let geocodeTimer  = null;
let geocodeAbort  = null;

// Rough Bacolod City bounding box (lon,lat) — biases results toward the area
const BACOLOD_VIEWBOX = '122.86,10.75,123.05,10.62';

async function searchLocations(q) {
  if (geocodeAbort) geocodeAbort.abort();
  geocodeAbort = new AbortController();
  try {
    const center = map.getCenter(); // biases results to what's currently on screen
    const url = `https://api.tomtom.com/search/2/search/${encodeURIComponent(q)}.json` +
      `?key=${TOMTOM_KEY}` +
      `&lat=${center.lat}&lon=${center.lng}` +
      `&radius=20000&limit=5&typeahead=true&countrySet=PH`;
    const res = await fetch(url, { signal: geocodeAbort.signal });
    if (!res.ok) return [];
    const data = await res.json();
    // Normalize to the same shape the old Nominatim code expected,
    // so renderSearchResults / selectPlaceResult need no changes.
    return (data.results || []).map(r => ({
      lat: r.position.lat,
      lon: r.position.lon,
      display_name: r.poi?.name
        ? `${r.poi.name}, ${r.address?.freeformAddress || ''}`
        : (r.address?.freeformAddress || 'Unknown place'),
    }));
  } catch (e) {
    if (e.name !== 'AbortError') console.warn('TomTom search failed:', e);
    return [];
  }
}

function renderSearchResults(jeepMatches, locMatches, q) {
  if (!q) { searchResults.classList.remove('open'); searchResults.innerHTML = ''; return; }
  const jeepHtml = jeepMatches.slice(0,5).map(([id,d]) => {
    const dot = SEARCH_DOT[d.display_status] || '#9ca3af';
    return `<div class="sr-item" onclick="selectSearchResult('${id}')">
      <span class="sr-dot" style="background:${dot}"></span>
      <div class="sr-text">
        <div class="sr-title">${esc(d.unit_code || 'Unit')}</div>
        <div class="sr-sub">${esc(d.route_name || 'Unassigned route')} ${d.driver_name ? '· ' + esc(d.driver_name) : ''}</div>
      </div>
    </div>`;
  }).join('');
  const locHtml = locMatches.slice(0,5).map((loc, idx) => `
    <div class="sr-item" onclick="selectPlaceResult(${idx})">
      <span class="sr-dot" style="background:#3b82f6"></span>
      <div class="sr-text">
        <div class="sr-title">${esc(loc.display_name.split(',')[0])}</div>
        <div class="sr-sub">${esc(loc.display_name)}</div>
      </div>
    </div>`).join('');
  searchResults.innerHTML = (jeepHtml + locHtml) || '<div class="sr-empty">No matches found</div>';
  searchResults.classList.add('open');
}

function selectSearchResult(id) {
  const d = jeepData[id], f = jeepFleet[id];
  if (!d || !f) return;
  const c = _fleetCurrent(f, performance.now());
  map.flyTo({ center: [c.lng, c.lat], zoom: Math.max(map.getZoom(), 17), duration: 600 });
  openJeepPopup(id);
  searchResults.classList.remove('open');
  searchInput.value = d.unit_code || '';
  searchInput.blur();
}

function selectPlaceResult(idx) {
  const loc = placeMatches[idx];
  if (!loc) return;
  const lat = parseFloat(loc.lat), lng = parseFloat(loc.lon);
  if (placeMarker) placeMarker.remove();
  const popup = new tt.Popup({ offset: 30 }).setHTML(`<div style="font-family:'Montserrat',sans-serif;padding:10px 12px;max-width:220px;">
      <div style="font-size:12.5px;font-weight:700;color:#f9fafb;">${esc(loc.display_name.split(',')[0])}</div>
      <div style="font-size:10.5px;color:#9ca3af;margin-top:3px;">${esc(loc.display_name)}</div>
    </div>`);
  placeMarker = new tt.Marker()
    .setLngLat([lng, lat])
    .setPopup(popup)
    .addTo(map);
  placeMarker.togglePopup();
  map.flyTo({ center: [lng, lat], zoom: 16, duration: 600 });
  searchResults.classList.remove('open');
  searchInput.value = loc.display_name.split(',')[0];
  searchInput.blur();
}

searchInput.addEventListener('input', function() {
  const q = this.value.trim();
  const qLower = q.toLowerCase();
  const jeepMatches = [];
  Object.entries(jeepData).forEach(([id, d]) => {
    const hay = [d.unit_code, d.plate_no, d.route_name, d.driver_name].filter(Boolean).join(' ').toLowerCase();
    if (qLower && hay.includes(qLower)) jeepMatches.push([id, d]);
  });
  _jeepSetFilter(qLower ? jeepMatches.map(([id]) => +id) : null);

  renderSearchResults(jeepMatches, placeMatches, q);

  clearTimeout(geocodeTimer);
  if (q.length < 2) { placeMatches = []; renderSearchResults(jeepMatches, placeMatches, q); return; }
  geocodeTimer = setTimeout(async () => {
    placeMatches = await searchLocations(q);
    renderSearchResults(jeepMatches, placeMatches, searchInput.value.trim());
  }, 450);
});
searchInput.addEventListener('focus', function() { if (this.value.trim()) this.dispatchEvent(new Event('input')); });
document.addEventListener('click', (e) => {
  if (!searchResults.contains(e.target) && e.target !== searchInput) searchResults.classList.remove('open');
});
window.addEventListener('resize', () => map.resize());

</script>
</body>
</html>