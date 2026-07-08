<?php
// ════════════════════════════════════════════════════════════
//  JEEPLIFY BCD — operator/dashboard.php  (FIXED)
// ════════════════════════════════════════════════════════════
session_start();

// ── AUTH GUARD ───────────────────────────────────────────────
if (empty($_SESSION['account_id']) || ($_SESSION['role'] ?? '') !== 'operator') {
    header('Location: ../index.php');
    exit;
}

require_once '../db.php';

$operatorId = (int) $_SESSION['account_id'];

// ── OPERATOR NAME ────────────────────────────────────────────
// operator_profiles has full_name (not first_name / last_name)
try {
    $opStmt = $pdo->prepare("
        SELECT full_name
        FROM   operator_profiles
        WHERE  account_id = ? LIMIT 1
    ");
    $opStmt->execute([$operatorId]);
    $opProfile = $opStmt->fetch() ?: [];
    $fullName  = $opProfile['full_name'] ?? 'Operator';
    // Split for avatar initials (first word = first name, rest = last)
    $nameParts = explode(' ', $fullName, 2);
    $_SESSION['first_name'] = $nameParts[0] ?? 'Operator';
    $_SESSION['last_name']  = $nameParts[1] ?? '';
} catch (Throwable $e) {
    error_log('op profile: ' . $e->getMessage());
    $_SESSION['first_name'] = 'Operator';
    $_SESSION['last_name']  = '';
}

// ── FLEET ────────────────────────────────────────────────────
try {
    $fleet = $pdo->query("
        SELECT
            j.id AS unit_id, j.unit_code, j.status,
            dp.full_name AS driver_name,
            r.name AS route_name,
            DATE_FORMAT(t.departure_time,'%h:%i %p') AS departed_at,
            NULL AS eta
        FROM   jeepneys j
        LEFT JOIN driver_jeepney  dj ON dj.jeepney_id = j.id
        LEFT JOIN driver_profiles dp ON dp.id = dj.driver_id
        LEFT JOIN routes          r  ON r.id  = j.route_id
        LEFT JOIN trips           t  ON t.jeepney_id = j.id
                                    AND t.status = 'active'
                                    AND DATE(t.departure_time) = CURDATE()
        ORDER BY j.unit_code
    ")->fetchAll();
} catch (Throwable $e) { error_log('fleet: '.$e->getMessage()); $fleet = []; }

// ── BOOKINGS ─────────────────────────────────────────────────
try {
    $bookings = $pdo->query("
        SELECT
            b.id, b.passenger_name, b.passenger_count,
            DATE_FORMAT(b.booking_date,'%b %d, %Y') AS booking_date,
            DATE_FORMAT(b.booking_time,'%h:%i %p')  AS booking_time,
            b.pickup_location, b.dropoff_location, b.status,
            j.unit_code,
            r.name AS route_name
        FROM   bookings b
        JOIN   jeepneys j ON j.id = b.jeepney_id
        LEFT JOIN routes r ON r.id = b.route_id
        ORDER BY FIELD(b.status,'pending','approved','declined','cancelled'), b.created_at DESC
        LIMIT  50
    ")->fetchAll();
} catch (Throwable $e) { error_log('bookings: '.$e->getMessage()); $bookings = []; }

// ── SCHEDULES ────────────────────────────────────────────────
try {
    $schedules = $pdo->query("
        SELECT
            s.id,
            dp.full_name AS driver_name,
            j.unit_code, r.name AS route_name,
            DATE_FORMAT(s.first_trip,'%h:%i %p') AS first_trip,
            DATE_FORMAT(s.last_trip, '%h:%i %p') AS last_trip,
            s.frequency_min
        FROM   schedules s
        JOIN   driver_profiles dp ON dp.id = s.driver_id
        JOIN   jeepneys        j  ON j.id  = s.jeepney_id
        LEFT JOIN routes       r  ON r.id  = j.route_id
        ORDER BY dp.full_name
    ")->fetchAll();
} catch (Throwable $e) { error_log('schedules: '.$e->getMessage()); $schedules = []; }

// ── DRIVERS ──────────────────────────────────────────────────
try {
    $drivers = $pdo->query("
        SELECT
            dp.id, dp.full_name,
            j.unit_code, r.name AS route_name
        FROM   driver_profiles dp
        LEFT JOIN driver_jeepney dj ON dj.driver_id  = dp.id
        LEFT JOIN jeepneys       j  ON j.id           = dj.jeepney_id
        LEFT JOIN routes         r  ON r.id           = j.route_id
        ORDER BY dp.full_name
    ")->fetchAll();
} catch (Throwable $e) { error_log('drivers: '.$e->getMessage()); $drivers = []; }

// ── AVAILABLE UNITS + ROUTES ──────────────────────────────────
try {
    $available_units = $pdo->query("
        SELECT j.id, j.unit_code
        FROM   jeepneys j
        LEFT JOIN driver_jeepney dj ON dj.jeepney_id = j.id
        WHERE  dj.id IS NULL
        ORDER BY j.unit_code
    ")->fetchAll();
    $routes = $pdo->query("SELECT id, name FROM routes ORDER BY name")->fetchAll();
} catch (Throwable $e) { error_log('units/routes: '.$e->getMessage()); $available_units = []; $routes = []; }

// Avatar initials helper
$avatarInitials = strtoupper(
    substr($_SESSION['first_name'] ?? 'O', 0, 1) .
    substr($_SESSION['last_name']  ?? 'P', 0, 1)
);
$displayName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? 'Operator'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Bacolod Jeepney Tracker - Operator</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
  <link rel="icon" type="image/png" href="fav.png"/>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --cyan: #22d3ee;
      --cyan-dim: rgba(6,182,212,0.15);
      --cyan-border: rgba(6,182,212,0.28);
      --surface: #0D111A;
      --surface2: #10151F;
      --surface3: #161C2A;
      --border: rgba(255,255,255,0.07);
      --text: #e2e8f0;
      --text-muted: #64748b;
      --text-dim: #94a3b8;
      --green: #22c55e;
      --yellow: #facc15;
      --red: #ef4444;
    }

    body {
      font-family: 'Plus Jakarta Sans', sans-serif;
      background: var(--surface);
      color: var(--text);
      height: 100dvh;
      overflow: hidden;
    }

    #map { position: absolute; inset: 0; z-index: 0; }
    .leaflet-control-attribution { display: none !important; }

    .header-pill {
      position: fixed; top: 16px; left: 50%;
      transform: translateX(-50%); z-index: 1000;
      display: flex; align-items: center; gap: 10px;
      background: rgba(9,12,20,0.80);
      backdrop-filter: blur(24px) saturate(180%);
      -webkit-backdrop-filter: blur(24px) saturate(180%);
      border: 1px solid var(--border); border-radius: 999px;
      padding: 9px 18px; box-shadow: 0 8px 32px rgba(0,0,0,0.5);
      white-space: nowrap;
    }
    .header-pill img { width: 22px; height: 22px; object-fit: contain; }
    .header-pill .title { font-size: 13px; font-weight: 700; color: var(--text); }
    .header-pill .badge {
      font-size: 10px; font-weight: 700;
      background: var(--cyan-dim); color: var(--cyan);
      border: 1px solid var(--cyan-border);
      border-radius: 999px; padding: 2px 9px; letter-spacing: 0.05em;
    }

    .sheet-backdrop {
      position: fixed; inset: 0; z-index: 1400;
      background: rgba(0,0,0,0.45);
      opacity: 0; pointer-events: none;
      transition: opacity 0.3s ease;
    }
    .sheet-backdrop.open { opacity: 1; pointer-events: auto; }

    .sheet {
      position: fixed; bottom: 0; left: 0; right: 0; z-index: 1500;
      background: var(--surface2); border-top: 1px solid var(--border);
      border-radius: 24px 24px 0 0; padding-bottom: 100px;
      max-height: 80dvh; overflow: hidden;
      transform: translateY(100%);
      transition: transform 0.35s cubic-bezier(0.32, 0.72, 0, 1);
      pointer-events: none;
      visibility: hidden;
    }
    .sheet.open {
      transform: translateY(0);
      pointer-events: auto;
      visibility: visible;
    }

    .sheet-handle {
      width: 36px; height: 4px; background: rgba(255,255,255,0.15);
      border-radius: 99px; margin: 12px auto 0;
    }
    .sheet-header {
      display: flex; align-items: center; justify-content: space-between;
      padding: 16px 20px 12px; border-bottom: 1px solid var(--border);
      position: sticky; top: 0; background: var(--surface2); z-index: 1;
    }
    .sheet-header h2 { font-size: 16px; font-weight: 800; color: var(--text); }
    .sheet-close {
      width: 30px; height: 30px; border-radius: 50%;
      background: var(--surface3); border: 1px solid var(--border);
      color: var(--text-dim); font-size: 16px;
      display: flex; align-items: center; justify-content: center;
      cursor: pointer; transition: all 0.15s;
    }
    .sheet-close:hover { background: rgba(255,255,255,0.08); color: var(--text); }
    .sheet-body {
      padding: 16px 20px;
      overflow-y: auto;
      max-height: calc(80dvh - 56px - 100px);
    }
    .sheet-body::-webkit-scrollbar { width: 4px; }
    .sheet-body::-webkit-scrollbar-track { background: transparent; }
    .sheet-body::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 99px; }

    /* Fleet slider */
    #sheet-fleet .sheet-body { padding: 0; overflow: hidden; max-height: none; }
    .fleet-slider-viewport { overflow: hidden; }
    .fleet-slider-track {
      display: flex; width: 200%;
      transition: transform 0.38s cubic-bezier(0.32, 0.72, 0, 1);
    }
    .fleet-slider-track.show-logs { transform: translateX(-50%); }
    .fleet-panel {
      width: 50%; overflow-y: auto;
      max-height: calc(80dvh - 56px - 100px);
      padding: 16px 20px;
    }
    .fleet-panel::-webkit-scrollbar { width: 4px; }
    .fleet-panel::-webkit-scrollbar-track { background: transparent; }
    .fleet-panel::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 99px; }

    .logs-panel-header { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; }
    .logs-back-btn {
      display: flex; align-items: center; gap: 5px;
      background: var(--surface3); border: 1px solid var(--border);
      border-radius: 999px; padding: 5px 12px;
      font-size: 12px; font-weight: 700; color: var(--text-dim);
      cursor: pointer; transition: all 0.15s; flex-shrink: 0;
    }
    .logs-back-btn:hover { color: var(--text); background: rgba(255,255,255,0.06); }
    .logs-back-arrow { font-size: 14px; line-height: 1; }
    .logs-unit-badge { font-size: 13px; font-weight: 800; color: var(--text); }
    .logs-route-tag {
      font-size: 10px; font-weight: 700;
      background: var(--cyan-dim); color: var(--cyan);
      border: 1px solid var(--cyan-border); border-radius: 999px; padding: 2px 9px;
    }

    .trip-log-item {
      position: relative; padding: 12px 14px 12px 44px;
      margin-bottom: 8px; background: var(--surface3);
      border: 1px solid var(--border); border-radius: 14px;
    }
    .trip-log-item::before {
      content: ''; position: absolute; left: 16px; top: 20px;
      width: 10px; height: 10px; border-radius: 50%;
      background: var(--cyan); border: 2px solid var(--surface3);
      box-shadow: 0 0 0 2px var(--cyan-border);
    }
    .trip-log-item:not(:last-child)::after {
      content: ''; position: absolute; left: 20px; top: 32px;
      width: 2px; bottom: -8px; background: var(--border);
    }
    .trip-log-item.status-completed::before  { background: var(--green); box-shadow: 0 0 0 2px rgba(34,197,94,0.3); }
    .trip-log-item.status-in-progress::before{ background: var(--cyan);  box-shadow: 0 0 0 2px rgba(6,182,212,0.3); }
    .trip-log-item.status-cancelled::before  { background: var(--red);   box-shadow: 0 0 0 2px rgba(239,68,68,0.3); }

    .trip-log-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
    .trip-log-times { font-size: 12px; font-weight: 700; color: var(--text); display: flex; align-items: center; gap: 6px; }
    .trip-log-arrow { font-size: 11px; color: var(--text-muted); }
    .trip-log-date  { font-size: 11px; color: var(--text-muted); }
    .trip-log-meta  { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .trip-log-route { font-size: 11px; color: var(--text-dim); font-weight: 600; }
    .trip-log-pax   { font-size: 11px; color: var(--text-muted); display: flex; align-items: center; gap: 3px; }

    .logs-summary { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; margin-bottom: 16px; }
    .logs-stat { background: var(--surface3); border: 1px solid var(--border); border-radius: 12px; padding: 10px 12px; text-align: center; }
    .logs-stat-value { font-size: 18px; font-weight: 800; color: var(--text); line-height: 1; }
    .logs-stat-label { font-size: 10px; font-weight: 600; color: var(--text-muted); letter-spacing: 0.05em; text-transform: uppercase; margin-top: 3px; }
    .logs-stat-value.green { color: var(--green); }
    .logs-stat-value.cyan  { color: var(--cyan); }

    .logs-loading { text-align: center; padding: 40px 0; color: var(--text-muted); font-size: 13px; }
    .logs-spinner {
      width: 28px; height: 28px; border: 2.5px solid var(--border);
      border-top-color: var(--cyan); border-radius: 50%;
      margin: 0 auto 12px; animation: spin 0.7s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    .data-table { width: 100%; border-collapse: collapse; font-size: 12px; }
    .data-table th {
      text-align: left; font-size: 10px; font-weight: 700; letter-spacing: 0.07em;
      color: var(--text-muted); text-transform: uppercase;
      padding: 0 10px 10px; border-bottom: 1px solid var(--border);
    }
    .data-table td { padding: 11px 10px; border-bottom: 1px solid rgba(255,255,255,0.04); vertical-align: middle; }
    .data-table tr:last-child td { border-bottom: none; }
    .data-table tr:hover td { background: rgba(255,255,255,0.02); }

    .status { display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 700; border-radius: 999px; padding: 3px 9px; }
    .status-dot { width: 6px; height: 6px; border-radius: 50%; }
    .status.active  { background: rgba(34,197,94,0.12);  color: #22c55e; }
    .status.idle    { background: rgba(250,204,21,0.12);  color: #facc15; }
    .status.offline { background: rgba(239,68,68,0.12);   color: #ef4444; }
    .status.active  .status-dot { background: #22c55e; box-shadow: 0 0 5px #22c55e88; }
    .status.idle    .status-dot { background: #facc15; box-shadow: 0 0 5px #facc1588; }
    .status.offline .status-dot { background: #ef4444; box-shadow: 0 0 5px #ef444488; }

    .btn { cursor: pointer; font-family: inherit; border-radius: 8px; font-size: 11px; font-weight: 700; padding: 5px 12px; border: none; transition: all 0.15s; }
    .btn-cyan   { background: var(--cyan-dim); color: var(--cyan); border: 1px solid var(--cyan-border); }
    .btn-cyan:hover { background: rgba(6,182,212,0.25); }
    .btn-green  { background: rgba(34,197,94,0.12); color: #22c55e; border: 1px solid rgba(34,197,94,0.28); }
    .btn-green:hover { background: rgba(34,197,94,0.22); }
    .btn-yellow { background: rgba(250,204,21,0.12); color: #facc15; border: 1px solid rgba(250,204,21,0.28); }
    .btn-yellow:hover { background: rgba(250,204,21,0.22); }
    .btn-red    { background: rgba(239,68,68,0.12); color: #ef4444; border: 1px solid rgba(239,68,68,0.28); }
    .btn-red:hover { background: rgba(239,68,68,0.22); }
    .btn-ghost  { background: var(--surface3); color: var(--text-dim); border: 1px solid var(--border); }
    .btn-ghost:hover { color: var(--text); }
    .btn-full   { width: 100%; padding: 11px; font-size: 13px; border-radius: 12px; }

    .form-group { margin-bottom: 14px; }
    .form-label { font-size: 11px; font-weight: 600; color: var(--text-dim); margin-bottom: 5px; display: block; letter-spacing: 0.04em; }
    .form-input, .form-select {
      width: 100%; background: var(--surface3);
      border: 1px solid var(--border); border-radius: 10px;
      color: var(--text); font-family: inherit; font-size: 13px;
      padding: 10px 12px; outline: none; transition: border-color 0.2s;
    }
    .form-input:focus, .form-select:focus { border-color: var(--cyan-border); }
    .form-select { display: none; }

    .custom-select { position: relative; width: 100%; }
    .custom-select-trigger {
      width: 100%; background: var(--surface3);
      border: 1px solid var(--border); border-radius: 10px;
      color: var(--text); font-family: inherit; font-size: 13px;
      padding: 10px 36px 10px 12px; cursor: pointer;
      display: flex; align-items: center; justify-content: space-between;
      transition: border-color 0.2s; user-select: none;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .custom-select-trigger.placeholder { color: var(--text-muted); }
    .custom-select-trigger:hover { border-color: rgba(255,255,255,0.18); }
    .custom-select-trigger.open { border-color: var(--cyan-border); box-shadow: 0 0 0 3px rgba(6,182,212,0.12); }
    .custom-select-arrow {
      position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
      width: 14px; height: 14px; pointer-events: none; color: var(--text-muted);
      transition: transform 0.2s;
    }
    .custom-select-trigger.open ~ .custom-select-arrow { transform: translateY(-50%) rotate(180deg); }
    .custom-select-dropdown {
      position: absolute; top: calc(100% + 4px); left: 0; right: 0;
      background: #111827; border: 1px solid rgba(255,255,255,0.12);
      border-radius: 12px; max-height: 220px; overflow-y: auto; z-index: 9999;
      opacity: 0; transform: translateY(-6px); pointer-events: none;
      transition: opacity 0.18s, transform 0.18s;
      box-shadow: 0 12px 40px rgba(0,0,0,0.6);
    }
    .custom-select-dropdown.open { opacity: 1; transform: translateY(0); pointer-events: auto; }
    .custom-select-dropdown::-webkit-scrollbar { width: 4px; }
    .custom-select-dropdown::-webkit-scrollbar-track { background: transparent; }
    .custom-select-dropdown::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 99px; }
    .custom-select-option {
      padding: 10px 14px; font-size: 13px; color: var(--text-dim);
      cursor: pointer; transition: background 0.12s, color 0.12s;
      border-radius: 8px; margin: 3px;
    }
    .custom-select-option:hover { background: rgba(255,255,255,0.06); color: var(--text); }
    .custom-select-option.selected { background: rgba(6,182,212,0.15); color: var(--cyan); font-weight: 700; }
    .custom-select-option.placeholder-opt { color: var(--text-muted); font-style: italic; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .form-section { background: var(--surface3); border: 1px solid var(--border); border-radius: 14px; padding: 14px; margin-bottom: 14px; }
    .form-section-title { font-size: 11px; font-weight: 700; color: var(--text-dim); letter-spacing: 0.07em; text-transform: uppercase; margin-bottom: 12px; }

    .booking-card { background: var(--surface3); border: 1px solid var(--border); border-radius: 14px; padding: 14px; margin-bottom: 10px; }
    .booking-card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
    .booking-passenger { font-size: 14px; font-weight: 700; color: var(--text); }
    .booking-date { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
    .booking-details { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 12px; }
    .booking-detail-item { font-size: 11px; }
    .booking-detail-label { color: var(--text-muted); margin-bottom: 2px; }
    .booking-detail-value { color: var(--text); font-weight: 600; }
    .booking-actions { display: flex; gap: 8px; }

    .sched-card { background: var(--surface3); border: 1px solid var(--border); border-radius: 14px; padding: 14px; margin-bottom: 10px; }
    .sched-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
    .sched-driver { font-size: 13px; font-weight: 700; color: var(--text); }
    .sched-route  { font-size: 11px; color: var(--text-muted); margin-top: 1px; }
    .sched-times  { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }
    .sched-time-block { text-align: center; }
    .sched-time-label { font-size: 10px; color: var(--text-muted); margin-bottom: 3px; }
    .sched-time-value { font-size: 13px; font-weight: 700; color: var(--cyan); }

    .driver-card { display: flex; align-items: center; gap: 12px; background: var(--surface3); border: 1px solid var(--border); border-radius: 14px; padding: 12px 14px; margin-bottom: 10px; }
    .driver-avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg,#06b6d4,#3b82f6); display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 800; color: white; flex-shrink: 0; }
    .driver-info  { flex: 1; }
    .driver-name  { font-size: 13px; font-weight: 700; color: var(--text); }
    .driver-meta  { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
    .driver-actions { display: flex; gap: 6px; }

    .profile-dropdown {
      position: absolute; bottom: calc(100% + 10px); left: 50%;
      transform: translateX(-50%) translateY(6px) scale(0.95);
      transform-origin: bottom center; width: 220px;
      background: #111827; border: 1px solid rgba(255,255,255,0.10);
      border-radius: 16px; overflow: hidden;
      box-shadow: 0 16px 48px rgba(0,0,0,0.65);
      opacity: 0; pointer-events: none;
      transition: opacity 0.2s ease, transform 0.22s cubic-bezier(0.34,1.4,0.64,1);
    }
    .profile-dropdown.open { opacity: 1; pointer-events: auto; transform: translateX(-50%) translateY(0) scale(1); }
    .profile-dropdown::after {
      content: ''; position: absolute; bottom: -6px; left: 50%;
      transform: translateX(-50%) rotate(45deg); width: 11px; height: 11px;
      background: #111827; border-right: 1px solid rgba(255,255,255,0.10);
      border-bottom: 1px solid rgba(255,255,255,0.10);
    }
    .pd-user { display: flex; align-items: center; gap: 10px; padding: 12px 14px; border-bottom: 1px solid rgba(255,255,255,0.06); }
    .pd-avatar { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg,#06b6d4,#3b82f6); display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 800; color: white; flex-shrink: 0; }
    .pd-name { font-size: 13px; font-weight: 700; color: #f1f5f9; line-height: 1.2; }
    .pd-role { display: inline-block; font-size: 10px; font-weight: 700; letter-spacing: 0.06em; background: var(--cyan-dim); color: var(--cyan); border: 1px solid var(--cyan-border); border-radius: 999px; padding: 1px 8px; margin-top: 3px; }
    .pd-menu { padding: 6px; }
    .pd-item { display: flex; align-items: center; gap: 9px; padding: 8px 10px; border-radius: 10px; font-size: 12px; font-weight: 600; color: #94a3b8; cursor: pointer; transition: background 0.12s, color 0.12s; }
    .pd-item:hover { background: rgba(255,255,255,0.05); color: #e2e8f0; }
    .pd-item svg { width: 15px; height: 15px; flex-shrink: 0; }
    .pd-item.logout { color: #f87171; }
    .pd-item.logout:hover { background: rgba(239,68,68,0.10); color: #fca5a5; }

    .nav-wrapper { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); z-index: 2000; display: flex; flex-direction: column; align-items: center; }
    .bottom-nav {
      display: flex; align-items: center; gap: 2px;
      background: rgba(12,16,28,0.72);
      backdrop-filter: blur(40px) saturate(200%);
      -webkit-backdrop-filter: blur(40px) saturate(200%);
      border: 1px solid rgba(255,255,255,0.12); border-radius: 999px;
      padding: 5px; box-shadow: 0 8px 40px rgba(0,0,0,0.6), 0 1px 0 rgba(255,255,255,0.06) inset;
    }
    .nav-btn {
      display: flex; flex-direction: column; align-items: center; gap: 3px;
      padding: 8px 14px; border-radius: 999px; border: none;
      background: transparent; color: rgba(148,163,184,0.65);
      font-family: inherit; font-size: 10px; font-weight: 600;
      cursor: pointer; transition: all 0.22s cubic-bezier(0.34,1.56,0.64,1);
      white-space: nowrap; min-width: 58px;
    }
    .nav-btn svg { width: 18px; height: 18px; transition: transform 0.22s cubic-bezier(0.34,1.56,0.64,1); }
    .nav-btn:hover { color: var(--text); background: rgba(255,255,255,0.05); }
    .nav-btn.active {
      background: var(--cyan-dim); color: var(--cyan);
      border: 1px solid var(--cyan-border);
      box-shadow: 0 0 18px rgba(6,182,212,0.18), 0 2px 8px rgba(0,0,0,0.3);
    }
    .nav-btn.active svg { transform: scale(1.12); }

    .divider { height: 1px; background: var(--border); margin: 14px 0; }
    .tag { display: inline-block; font-size: 10px; font-weight: 700; border-radius: 999px; padding: 2px 9px; }
    .tag-cyan   { background: var(--cyan-dim); color: var(--cyan); border: 1px solid var(--cyan-border); }
    .tag-yellow { background: rgba(250,204,21,0.12); color: #facc15; border: 1px solid rgba(250,204,21,0.28); }
    .tag-green  { background: rgba(34,197,94,0.12); color: #22c55e; border: 1px solid rgba(34,197,94,0.28); }
    .tag-red    { background: rgba(239,68,68,0.12); color: #ef4444; border: 1px solid rgba(239,68,68,0.28); }

    .empty-state { text-align: center; padding: 40px 0; color: var(--text-muted); font-size: 13px; }
    .empty-state .empty-icon  { font-size: 32px; margin-bottom: 10px; }
    .empty-state .empty-label { font-weight: 600; color: var(--text-dim); margin-bottom: 4px; }
    .empty-state .empty-sub   { font-size: 11px; }
    .section-label { font-size: 11px; font-weight: 700; color: var(--text-muted); letter-spacing: 0.07em; text-transform: uppercase; margin-bottom: 12px; }

    .sheet::-webkit-scrollbar { width: 4px; }
    .sheet::-webkit-scrollbar-track { background: transparent; }
    .sheet::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 99px; }

    .leaflet-popup-content-wrapper { background:#0D111A!important; border:1px solid rgba(255,255,255,0.08)!important; border-radius:12px!important; box-shadow:0 8px 32px rgba(0,0,0,0.5)!important; padding:0!important; }
    .leaflet-popup-content { margin:0!important; }
    .leaflet-popup-tip { background:#0D111A!important; }
    .leaflet-popup-close-button { color:#64748b!important; top:6px!important; right:8px!important; }

    /* ── DISPATCH MODAL ── */
    .dispatch-overlay {
      position: fixed; inset: 0; z-index: 3000;
      background: rgba(0,0,0,0.6); backdrop-filter: blur(10px);
      display: flex; align-items: center; justify-content: center;
      padding: 20px;
      opacity: 0; pointer-events: none; transition: opacity 0.25s;
    }
    .dispatch-overlay.active { opacity: 1; pointer-events: auto; }
    .dispatch-card {
      width: 100%; max-width: 360px;
      background: var(--surface2); border: 1px solid var(--border);
      border-radius: 20px; padding: 24px 22px;
      transform: translateY(18px) scale(0.97);
      transition: transform 0.3s cubic-bezier(0.34,1.2,0.64,1), opacity 0.25s;
      opacity: 0;
    }
    .dispatch-overlay.active .dispatch-card { transform: translateY(0) scale(1); opacity: 1; }
    .dispatch-card h3 { font-size: 17px; font-weight: 800; margin-bottom: 4px; }
    .dispatch-card .sub { font-size: 12px; color: var(--text-muted); margin-bottom: 18px; }
    .dispatch-msg { font-size: 12px; font-weight: 600; margin-top: 10px; min-height: 18px; }
    .dispatch-msg.error   { color: var(--red); }
    .dispatch-msg.success { color: var(--green); }
    .dispatch-footer { display: flex; gap: 8px; margin-top: 16px; }

    /* Toast notification */
    .toast {
      position: fixed; bottom: 110px; left: 50%; transform: translateX(-50%) translateY(20px);
      background: #1e293b; border: 1px solid rgba(255,255,255,0.12);
      border-radius: 12px; padding: 10px 18px; z-index: 9999;
      font-size: 13px; font-weight: 600; color: var(--text);
      opacity: 0; transition: opacity 0.25s, transform 0.25s; pointer-events: none;
      white-space: nowrap;
    }
    .toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
    .toast.success { border-color: rgba(34,197,94,0.4); color: #22c55e; }
    .toast.error   { border-color: rgba(239,68,68,0.4);  color: #ef4444; }

    /* ── FLEET CARDS (mobile-first) ── */
    .fleet-card {
      background: var(--surface3); border: 1px solid var(--border);
      border-radius: 14px; padding: 14px; margin-bottom: 10px;
    }
    .fleet-card-top {
      display: flex; align-items: center; justify-content: space-between;
      margin-bottom: 10px;
    }
    .fleet-card-unit { font-size: 15px; font-weight: 800; color: var(--text); }
    .fleet-card-row {
      display: flex; align-items: center; justify-content: space-between;
      margin-bottom: 6px; font-size: 12px;
    }
    .fleet-card-label { color: var(--text-muted); font-weight: 600; }
    .fleet-card-value { color: var(--text); font-weight: 600; text-align: right; max-width: 65%; }

    /* ── DARK TIME/NUMBER INPUTS ── */
    input[type="time"],
    input[type="number"] {
      color-scheme: dark;
    }
    input[type="time"]::-webkit-calendar-picker-indicator,
    input[type="number"]::-webkit-inner-spin-button {
      filter: invert(1);
    }
  </style>
</head>
<body>

<div id="map"></div>

<div class="header-pill">
  <img src="fav.png" alt="Logo">
  <span class="title">Bacolod Jeepney Tracker</span>
  <span class="badge">OPERATOR</span>
</div>

<div class="sheet-backdrop" id="backdrop" onclick="closeAll()"></div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<!-- ══ SHEET: FLEET ══ -->
<div class="sheet" id="sheet-fleet">
  <div class="sheet-handle"></div>
  <div class="sheet-header">
    <h2 id="fleet-sheet-title">🚍 Fleet Overview</h2>
    <div class="sheet-close" onclick="closeAll()">✕</div>
  </div>
  <div class="fleet-slider-viewport">
    <div class="fleet-slider-track" id="fleetSliderTrack">

      <!-- Panel A: Fleet Table -->
      <div class="fleet-panel" id="fleetPanelA">
        <?php if (!empty($fleet)): ?>
        <?php foreach ($fleet as $jeep): ?>
        <div class="fleet-card">
          <div class="fleet-card-top">
            <div class="fleet-card-unit"><?= htmlspecialchars($jeep['unit_code']) ?></div>
            <span class="status <?= htmlspecialchars($jeep['status']) ?>">
              <span class="status-dot"></span><?= ucfirst($jeep['status']) ?>
            </span>
          </div>
          <div class="fleet-card-row">
            <span class="fleet-card-label">Driver</span>
            <span class="fleet-card-value"><?= htmlspecialchars($jeep['driver_name'] ?? '—') ?></span>
          </div>
          <div class="fleet-card-row">
            <span class="fleet-card-label">Route</span>
            <span class="tag tag-cyan" style="font-size:10px;"><?= htmlspecialchars($jeep['route_name'] ?? '—') ?></span>
          </div>
          <?php if ($jeep['departed_at']): ?>
          <div class="fleet-card-row">
            <span class="fleet-card-label">Departed</span>
            <span class="fleet-card-value"><?= htmlspecialchars($jeep['departed_at']) ?></span>
          </div>
          <?php endif; ?>
          <div style="display:flex;gap:6px;margin-top:10px;">
            <button class="btn btn-ghost" style="flex:1;font-size:11px;"
              onclick="openRouteLogs(<?= (int)$jeep['unit_id'] ?>,'<?= htmlspecialchars($jeep['unit_code'],ENT_QUOTES) ?>','<?= htmlspecialchars($jeep['route_name'] ?? '',ENT_QUOTES) ?>')">
              📋 Logs
            </button>
            <button class="btn btn-cyan" style="flex:1;font-size:11px;"
              onclick="openDispatchTrip(<?= (int)$jeep['unit_id'] ?>,<?= htmlspecialchars(json_encode($jeep['unit_code'])) ?>)">
              🚌 Dispatch
            </button>
          </div>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
        <div class="empty-state">
          <div class="empty-icon">🚍</div>
          <div class="empty-label">No fleet data yet</div>
          <div class="empty-sub">Jeepneys will appear here once registered</div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Panel B: Route Logs -->
      <div class="fleet-panel" id="fleetPanelB">
        <div class="logs-panel-header">
          <button class="logs-back-btn" onclick="closeRouteLogs()">
            <span class="logs-back-arrow">←</span> Back
          </button>
          <span class="logs-unit-badge" id="logsUnitBadge">—</span>
          <span class="logs-route-tag" id="logsRouteTag">—</span>
        </div>
        <div class="logs-summary" id="logsSummary" style="display:none;">
          <div class="logs-stat"><div class="logs-stat-value cyan" id="logsTotalTrips">0</div><div class="logs-stat-label">Total Trips</div></div>
          <div class="logs-stat"><div class="logs-stat-value green" id="logsCompleted">0</div><div class="logs-stat-label">Completed</div></div>
          <div class="logs-stat"><div class="logs-stat-value" id="logsTotalPax">0</div><div class="logs-stat-label">Total Pax</div></div>
        </div>
        <div id="logsListContainer">
          <div class="logs-loading" id="logsLoading">
            <div class="logs-spinner"></div>Loading trip history…
          </div>
        </div>
      </div>

    </div>
  </div>
</div>


<!-- ══ SHEET: BOOKINGS ══ -->
<div class="sheet" id="sheet-booking">
  <div class="sheet-handle"></div>
  <div class="sheet-header">
    <h2>📋 Booking Requests</h2>
    <div class="sheet-close" onclick="closeAll()">✕</div>
  </div>
  <div class="sheet-body">
    <?php if (!empty($bookings)): ?>
    <?php foreach ($bookings as $b): ?>
    <div class="booking-card" data-id="<?= $b['id'] ?>">
      <div class="booking-card-header">
        <div>
          <div class="booking-passenger"><?= htmlspecialchars($b['passenger_name']) ?></div>
          <div class="booking-date"><?= htmlspecialchars($b['booking_date']) ?> &nbsp;·&nbsp; <?= htmlspecialchars($b['booking_time']) ?></div>
        </div>
        <?php $tagClass = match($b['status']) { 'approved'=>'tag-green','declined'=>'tag-red','cancelled'=>'tag-red', default=>'tag-yellow' }; ?>
        <span class="tag <?= $tagClass ?>"><?= ucfirst($b['status']) ?></span>
      </div>
      <div class="booking-details">
        <div class="booking-detail-item"><div class="booking-detail-label">Jeepney</div><div class="booking-detail-value"><?= htmlspecialchars($b['unit_code']) ?> — <?= htmlspecialchars($b['route_name'] ?? '—') ?></div></div>
        <div class="booking-detail-item"><div class="booking-detail-label">Passengers</div><div class="booking-detail-value"><?= (int)$b['passenger_count'] ?> pax</div></div>
        <div class="booking-detail-item"><div class="booking-detail-label">Pickup</div><div class="booking-detail-value"><?= htmlspecialchars($b['pickup_location']) ?></div></div>
        <div class="booking-detail-item"><div class="booking-detail-label">Drop-off</div><div class="booking-detail-value"><?= htmlspecialchars($b['dropoff_location']) ?></div></div>
      </div>
      <div class="booking-actions">
        <?php if ($b['status'] === 'pending'): ?>
        <button class="btn btn-green"  onclick="approveBooking(this,<?= $b['id'] ?>)">✓ Approve</button>
        <button class="btn btn-yellow" onclick="rescheduleBooking(this,<?= $b['id'] ?>)">⟳ Reschedule</button>
        <button class="btn btn-red"    onclick="declineBooking(this,<?= $b['id'] ?>)">✕ Decline</button>
        <?php elseif ($b['status'] === 'approved'): ?>
        <button class="btn btn-ghost" disabled style="opacity:0.4;cursor:default;">✓ Approved</button>
        <?php else: ?>
        <button class="btn btn-ghost" disabled style="opacity:0.4;cursor:default;">✕ <?= ucfirst($b['status']) ?></button>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div class="empty-state">
      <div class="empty-icon">📋</div>
      <div class="empty-label">No booking requests</div>
      <div class="empty-sub">New bookings from passengers will appear here</div>
    </div>
    <?php endif; ?>
  </div>
</div>


<!-- ══ SHEET: SCHEDULES ══ -->
<div class="sheet" id="sheet-schedule">
  <div class="sheet-handle"></div>
  <div class="sheet-header">
    <h2>🕐 Schedules</h2>
    <div class="sheet-close" onclick="closeAll()">✕</div>
  </div>
  <div class="sheet-body">

    <!-- Add Schedule Form -->
    <div class="form-section">
      <div class="form-section-title">➕ Assign Schedule to Driver</div>
      <div class="form-group">
        <label class="form-label">Driver</label>
        <select class="form-select" id="newSchedDriver">
          <option value="">— Select Driver —</option>
          <?php foreach ($drivers as $d): ?>
          <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['full_name']) ?> <?= $d['unit_code'] ? '(' . htmlspecialchars($d['unit_code']) . ')' : '' ?></option>
          <?php endforeach; ?>
        </select>
        <div class="custom-select" id="cs-newSchedDriver">
          <div class="custom-select-trigger placeholder" onclick="csToggle('newSchedDriver')">— Select Driver —</div>
          <svg class="custom-select-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
          <div class="custom-select-dropdown" id="csd-newSchedDriver">
            <div class="custom-select-option placeholder-opt" data-value="">— Select Driver —</div>
            <?php foreach ($drivers as $d): ?>
            <div class="custom-select-option" data-value="<?= $d['id'] ?>"><?= htmlspecialchars($d['full_name']) ?> <?= $d['unit_code'] ? '(' . htmlspecialchars($d['unit_code']) . ')' : '' ?></div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">First Trip</label><input type="time" class="form-input" id="newSchedFirst" value="05:00"></div>
        <div class="form-group"><label class="form-label">Last Trip</label><input type="time" class="form-input" id="newSchedLast" value="22:00"></div>
      </div>
      <div class="form-group">
        <label class="form-label">Frequency <span style="color:var(--text-muted);font-weight:400;">(how many minutes between each trip)</span></label>
        <input type="number" class="form-input" id="newSchedFreq" min="5" max="120" value="20" placeholder="e.g. 20 = departs every 20 mins">
      </div>
      <button class="btn btn-cyan btn-full" onclick="createSchedule()">Assign Schedule</button>
    </div>

    <div class="divider"></div>
    <div class="section-label">Existing Schedules</div>

    <div id="schedList">
    <?php if (!empty($schedules)): ?>
    <?php foreach ($schedules as $s): ?>
    <div class="sched-card" id="sched-<?= $s['id'] ?>">
      <div class="sched-header">
        <div>
          <div class="sched-driver">
            <?= htmlspecialchars($s['driver_name']) ?>
            &nbsp;<span class="tag tag-cyan"><?= htmlspecialchars($s['unit_code']) ?></span>
          </div>
          <div class="sched-route"><?= htmlspecialchars($s['route_name'] ?? '—') ?></div>
        </div>
        <button class="btn btn-cyan" onclick="editSchedule(<?= $s['id'] ?>)">Edit</button>
      </div>
      <div class="sched-times">
        <div class="sched-time-block"><div class="sched-time-label">First Trip</div><div class="sched-time-value"><?= htmlspecialchars($s['first_trip']) ?></div></div>
        <div class="sched-time-block"><div class="sched-time-label">Last Trip</div><div class="sched-time-value"><?= htmlspecialchars($s['last_trip']) ?></div></div>
        <div class="sched-time-block"><div class="sched-time-label">Frequency</div><div class="sched-time-value"><?= (int)$s['frequency_min'] ?> min</div></div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div class="empty-state" id="schedEmptyState">
      <div class="empty-icon">🕐</div>
      <div class="empty-label">No schedules yet</div>
      <div class="empty-sub">Assign a schedule to a driver above</div>
    </div>
    <?php endif; ?>
    </div>

    <div class="form-section" id="schedEditForm" style="display:none; margin-top:6px;">
      <div class="form-section-title">✏️ Edit Schedule — <span id="editSchedLabel"></span></div>
      <input type="hidden" id="editSchedId">
      <div class="form-row">
        <div class="form-group"><label class="form-label">First Trip</label><input type="time" class="form-input" id="editFirst"></div>
        <div class="form-group"><label class="form-label">Last Trip</label><input type="time" class="form-input" id="editLast"></div>
      </div>
      <div class="form-group">
        <label class="form-label">Frequency <span style="color:var(--text-muted);font-weight:400;">(mins between trips)</span></label>
        <input type="number" class="form-input" id="editFreq" min="5" max="120">
      </div>
      <div style="display:flex;gap:8px;">
        <button class="btn btn-cyan btn-full" onclick="saveSchedule()">Save Changes</button>
        <button class="btn btn-ghost btn-full" onclick="cancelEditSchedule()">Cancel</button>
      </div>
    </div>
  </div>
</div>


<!-- ══ SHEET: DRIVERS ══ -->
<div class="sheet" id="sheet-drivers">
  <div class="sheet-handle"></div>
  <div class="sheet-header">
    <h2>👤 Driver Accounts</h2>
    <div class="sheet-close" onclick="closeAll()">✕</div>
  </div>
  <div class="sheet-body">

    <div class="form-section">
      <div class="form-section-title">➕ Create New Driver Account</div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">First Name</label><input type="text" class="form-input" id="drvFirst" placeholder="First name"></div>
        <div class="form-group"><label class="form-label">Last Name</label><input type="text" class="form-input" id="drvLast" placeholder="Last name"></div>
      </div>
      <div class="form-group"><label class="form-label">License No.</label><input type="text" class="form-input" id="drvLicense" placeholder="e.g. N01-12-123456"></div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Assign Jeep</label>
          <select class="form-select" id="drvJeep">
            <option value="">— Select Unit —</option>
            <?php foreach ($available_units as $unit): ?>
            <option value="<?= $unit['id'] ?>"><?= htmlspecialchars($unit['unit_code']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="custom-select" id="cs-drvJeep">
            <div class="custom-select-trigger placeholder" onclick="csToggle('drvJeep')">— Select Unit —</div>
            <svg class="custom-select-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
            <div class="custom-select-dropdown" id="csd-drvJeep">
              <div class="custom-select-option placeholder-opt" data-value="">— Select Unit —</div>
              <?php foreach ($available_units as $unit): ?>
              <div class="custom-select-option" data-value="<?= $unit['id'] ?>"><?= htmlspecialchars($unit['unit_code']) ?></div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Assign Route</label>
          <select class="form-select" id="drvRoute">
            <option value="">— Select Route —</option>
            <?php foreach ($routes as $route): ?>
            <option value="<?= $route['id'] ?>"><?= htmlspecialchars($route['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="custom-select" id="cs-drvRoute">
            <div class="custom-select-trigger placeholder" onclick="csToggle('drvRoute')">— Select Route —</div>
            <svg class="custom-select-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
            <div class="custom-select-dropdown" id="csd-drvRoute">
              <div class="custom-select-option placeholder-opt" data-value="">— Select Route —</div>
              <?php foreach ($routes as $route): ?>
              <div class="custom-select-option" data-value="<?= $route['id'] ?>"><?= htmlspecialchars($route['name']) ?></div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Username</label><input type="text" class="form-input" id="drvUser" placeholder="driver_username"></div>
        <div class="form-group"><label class="form-label">Password</label><input type="password" class="form-input" id="drvPass" placeholder="••••••••"></div>
      </div>
      <button class="btn btn-cyan btn-full" onclick="createDriver()">Create Driver Account</button>
    </div>

    <div class="divider"></div>
    <div class="section-label">Existing Drivers</div>

    <div id="driverList">
      <?php if (!empty($drivers)): ?>
      <?php foreach ($drivers as $d): ?>
      <div class="driver-card" data-id="<?= $d['id'] ?>">
        <div class="driver-avatar"><?= strtoupper(substr($d['full_name'],0,1)) ?></div>
        <div class="driver-info">
          <div class="driver-name"><?= htmlspecialchars($d['full_name']) ?></div>
          <div class="driver-meta"><?= htmlspecialchars($d['unit_code'] ?? '—') ?> &nbsp;·&nbsp; <?= htmlspecialchars($d['route_name'] ?? '—') ?></div>
        </div>
        <div class="driver-actions">
          <button class="btn btn-ghost" onclick="editDriver(<?= $d['id'] ?>)">Edit</button>
          <button class="btn btn-red"   onclick="confirmRemoveDriver(<?= $d['id'] ?>, <?= htmlspecialchars(json_encode($d['full_name'])) ?>)">Remove</button>
        </div>
      </div>
      <?php endforeach; ?>
      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon">👤</div>
        <div class="empty-label">No drivers yet</div>
        <div class="empty-sub">Create a driver account using the form above</div>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── EDIT DRIVER FORM ── -->
    <div class="form-section" id="editDrvForm" style="display:none; margin-top:6px;">
      <div class="form-section-title">✏️ Edit Driver — <span id="editDrvFormLabel"></span></div>
      <input type="hidden" id="editDrvId">
      <div class="form-group">
        <label class="form-label">Full Name</label>
        <input type="text" class="form-input" id="editDrvName" placeholder="e.g. Dela Cruz, Juan">
      </div>
      <div class="form-group">
        <label class="form-label">License No. <span style="color:var(--text-muted);font-weight:400;">(leave blank to keep current)</span></label>
        <input type="text" class="form-input" id="editDrvLicense" placeholder="N01-12-123456">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Assign Jeep</label>
          <select class="form-select" id="editDrvJeep">
            <option value="">— Select Unit —</option>
            <?php foreach ($available_units as $unit): ?>
            <option value="<?= $unit['id'] ?>"><?= htmlspecialchars($unit['unit_code']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="custom-select" id="cs-editDrvJeep">
            <div class="custom-select-trigger placeholder" onclick="csToggle('editDrvJeep')">— Select Unit —</div>
            <svg class="custom-select-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
            <div class="custom-select-dropdown" id="csd-editDrvJeep">
              <div class="custom-select-option placeholder-opt" data-value="">— Select Unit —</div>
              <?php foreach ($available_units as $unit): ?>
              <div class="custom-select-option" data-value="<?= $unit['id'] ?>"><?= htmlspecialchars($unit['unit_code']) ?></div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Assign Route</label>
          <select class="form-select" id="editDrvRoute">
            <option value="">— Select Route —</option>
            <?php foreach ($routes as $route): ?>
            <option value="<?= $route['id'] ?>"><?= htmlspecialchars($route['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="custom-select" id="cs-editDrvRoute">
            <div class="custom-select-trigger placeholder" onclick="csToggle('editDrvRoute')">— Select Route —</div>
            <svg class="custom-select-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
            <div class="custom-select-dropdown" id="csd-editDrvRoute">
              <div class="custom-select-option placeholder-opt" data-value="">— Select Route —</div>
              <?php foreach ($routes as $route): ?>
              <div class="custom-select-option" data-value="<?= $route['id'] ?>"><?= htmlspecialchars($route['name']) ?></div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">New Password <span style="color:var(--text-muted);font-weight:400;">(leave blank to keep current)</span></label>
        <input type="password" class="form-input" id="editDrvPass" placeholder="••••••••">
      </div>
      <div style="display:flex;gap:8px;">
        <button class="btn btn-cyan btn-full" onclick="saveEditDriver()">Save Changes</button>
        <button class="btn btn-ghost btn-full" onclick="cancelEditDriver()">Cancel</button>
      </div>
    </div>

  </div>
</div>


<!-- ══ NAV ══ -->
<!-- ══ RESCHEDULE BOOKING MODAL ══ -->
<div class="dispatch-overlay" id="reschedOverlay" onclick="if(event.target===this)closeReschedule()">
  <div class="dispatch-card">
    <h3>⟳ Reschedule Booking</h3>
    <p class="sub">Set a new date and time. Booking will be marked Approved.</p>
    <div class="form-group">
      <label class="form-label">New Date</label>
      <input type="date" class="form-input" id="reschedDate">
    </div>
    <div class="form-group">
      <label class="form-label">New Time</label>
      <input type="time" class="form-input" id="reschedTime">
    </div>
    <p class="dispatch-msg" id="reschedMsg"></p>
    <div class="dispatch-footer">
      <button class="btn btn-cyan btn-full" id="reschedSubmitBtn" onclick="submitReschedule()">Save</button>
      <button class="btn btn-ghost btn-full" onclick="closeReschedule()">Cancel</button>
    </div>
  </div>
</div>

<!-- ══ DISPATCH TRIP MODAL ══ -->
<div class="dispatch-overlay" id="dispatchOverlay" onclick="if(event.target===this)closeDispatchTrip()">
  <div class="dispatch-card">
    <h3>🚌 Dispatch Trip</h3>
    <p class="sub">Unit: <strong id="dispatchUnitLabel">—</strong></p>
    <div class="form-group">
      <label class="form-label">Route</label>
      <select class="form-input form-select" id="dispatchRoute" style="height:44px;appearance:auto;-webkit-appearance:auto;">
        <option value="">— Select Route —</option>
        <?php foreach ($routes as $route): ?>
        <option value="<?= $route['id'] ?>"><?= htmlspecialchars($route['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Departure Time</label>
      <input type="time" class="form-input" id="dispatchTime">
    </div>
    <p class="dispatch-msg" id="dispatchMsg"></p>
    <div class="dispatch-footer">
      <button class="btn btn-cyan btn-full" id="dispatchSubmitBtn" onclick="submitDispatch()">Dispatch</button>
      <button class="btn btn-ghost btn-full" onclick="closeDispatchTrip()">Cancel</button>
    </div>
  </div>
</div>

<div class="nav-wrapper">
  <div class="profile-dropdown" id="profileDropdown">
    <div class="pd-user">
      <div class="pd-avatar"><?= $avatarInitials ?></div>
      <div>
        <div class="pd-name"><?= htmlspecialchars($displayName) ?></div>
        <div class="pd-role">OPERATOR</div>
      </div>
    </div>
    <div class="pd-menu">
      <div class="pd-item logout" onclick="window.location.href='../logout.php'">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Logout
      </div>
    </div>
  </div>

  <nav class="bottom-nav">
    <button class="nav-btn" id="btn-fleet" onclick="switchTab('fleet')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
      Fleet
    </button>
    <button class="nav-btn" id="btn-booking" onclick="switchTab('booking')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>
      Bookings
    </button>
    <button class="nav-btn" id="btn-schedule" onclick="switchTab('schedule')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      Schedules
    </button>
    <button class="nav-btn" id="btn-drivers" onclick="switchTab('drivers')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      Drivers
    </button>
    <button class="nav-btn" id="btn-profile" onclick="switchTab('profile')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      Profile
    </button>
  </nav>
</div>

<script>
// ─────────────────────────────────────────────────────────────
// TOAST
// ─────────────────────────────────────────────────────────────
function showToast(msg, type = 'success', duration = 3000) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = `toast ${type} show`;
  clearTimeout(t._timer);
  t._timer = setTimeout(() => { t.classList.remove('show'); }, duration);
}

// ─────────────────────────────────────────────────────────────
// MAP
// ─────────────────────────────────────────────────────────────
let _map = null;
function initMap() {
  _map = L.map('map', { zoomControl: false }).setView([10.6765, 122.9509], 13);
  L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { subdomains: 'abcd' }).addTo(_map);
}

// ─────────────────────────────────────────────────────────────
// TAB LOGIC
// ─────────────────────────────────────────────────────────────
const TABS = ['fleet','booking','schedule','drivers','profile'];
let activeTab = null;

function closeAll() {
  closeRouteLogs(true);
  TABS.forEach(t => {
    document.getElementById('btn-' + t)?.classList.remove('active');
    const sheet = document.getElementById('sheet-' + t);
    if (sheet) sheet.classList.remove('open');
  });
  document.getElementById('profileDropdown').classList.remove('open');
  document.getElementById('backdrop').classList.remove('open');
  ['editDrvForm','schedEditForm'].forEach(fid => {
    const el = document.getElementById(fid);
    if (el) el.style.display = 'none';
  });
  activeTab = null;
}

function switchTab(tab) {
  if (activeTab === tab) { closeAll(); return; }
  if (activeTab === 'fleet') closeRouteLogs(true);

  // Close everything cleanly
  TABS.forEach(t => {
    document.getElementById('btn-' + t)?.classList.remove('active');
    const sheet = document.getElementById('sheet-' + t);
    if (sheet) sheet.classList.remove('open');
  });
  document.getElementById('profileDropdown').classList.remove('open');
  document.getElementById('backdrop').classList.remove('open');

  // Reset all sub-forms and scroll positions
  ['editDrvForm','schedEditForm'].forEach(fid => {
    const el = document.getElementById(fid);
    if (el) el.style.display = 'none';
  });
  document.querySelectorAll('.sheet-body').forEach(b => b.scrollTop = 0);

  activeTab = tab;
  document.getElementById('btn-' + tab).classList.add('active');
  if (tab === 'profile') {
    document.getElementById('profileDropdown').classList.add('open');
  } else {
    const targetSheet = document.getElementById('sheet-' + tab);
    if (targetSheet) targetSheet.classList.add('open');
    document.getElementById('backdrop').classList.add('open');
  }
}

document.addEventListener('click', e => {
  if (!e.target.closest('.nav-wrapper') && activeTab === 'profile') {
    document.getElementById('profileDropdown').classList.remove('open');
    document.getElementById('btn-profile').classList.remove('active');
    activeTab = null;
  }
});

// ─────────────────────────────────────────────────────────────
// ROUTE LOGS
// ─────────────────────────────────────────────────────────────
let logsShowing = false;

function openRouteLogs(unitId, unitCode, routeName) {
  document.getElementById('logsUnitBadge').textContent = unitCode;
  document.getElementById('logsRouteTag').textContent  = routeName || '—';
  document.getElementById('fleet-sheet-title').textContent = '📋 Route Logs';
  document.getElementById('logsSummary').style.display = 'none';
  document.getElementById('logsListContainer').innerHTML = `
    <div class="logs-loading">
      <div class="logs-spinner"></div>Loading trip history…
    </div>`;
  document.getElementById('fleetSliderTrack').classList.add('show-logs');
  logsShowing = true;
  document.getElementById('fleetPanelB').scrollTop = 0;
  fetchRouteLogs(unitId);
}

function closeRouteLogs(silent) {
  if (!logsShowing) return;
  document.getElementById('fleetSliderTrack').classList.remove('show-logs');
  if (!silent) document.getElementById('fleet-sheet-title').textContent = '🚍 Fleet Overview';
  logsShowing = false;
}

function fetchRouteLogs(unitId) {
  fetch('actions/route_logs.php?unit_id=' + encodeURIComponent(unitId))
    .then(r => r.json())
    .then(res => {
      if (!res.success) {
        document.getElementById('logsListContainer').innerHTML = `
          <div class="empty-state"><div class="empty-icon">⚠️</div>
          <div class="empty-label">Could not load logs</div>
          <div class="empty-sub">${escHtml(res.message||'Unknown error')}</div></div>`;
        return;
      }
      const trips = res.trips || [];
      const total     = trips.length;
      const completed = trips.filter(t => t.status === 'completed').length;
      const totalPax  = trips.reduce((s,t) => s + (parseInt(t.passenger_count)||0), 0);
      document.getElementById('logsTotalTrips').textContent = total;
      document.getElementById('logsCompleted').textContent  = completed;
      document.getElementById('logsTotalPax').textContent   = totalPax;
      document.getElementById('logsSummary').style.display  = total > 0 ? 'grid' : 'none';
      if (total === 0) {
        document.getElementById('logsListContainer').innerHTML = `
          <div class="empty-state"><div class="empty-icon">🗂️</div>
          <div class="empty-label">No trips logged yet</div>
          <div class="empty-sub">Trip records will appear here once this unit starts operating</div></div>`;
        return;
      }
      document.getElementById('logsListContainer').innerHTML = trips.map(buildTripLogItem).join('');
    })
    .catch(() => {
      document.getElementById('logsListContainer').innerHTML = `
        <div class="empty-state"><div class="empty-icon">⚠️</div>
        <div class="empty-label">Network error</div>
        <div class="empty-sub">Could not reach the server. Please try again.</div></div>`;
    });
}

function buildTripLogItem(t) {
  const statusClass = { completed:'status-completed','in-progress':'status-in-progress',cancelled:'status-cancelled' }[t.status]||'status-completed';
  const statusLabel = { completed:'✓ Completed','in-progress':'⟳ In Progress',cancelled:'✕ Cancelled',scheduled:'⏱ Scheduled',active:'⟳ Active' }[t.status]||t.status;
  const statusColor = { completed:'var(--green)','in-progress':'var(--cyan)',cancelled:'var(--red)',scheduled:'var(--text-muted)',active:'var(--cyan)' }[t.status]||'var(--text-muted)';
  const dep = t.departure_time || '—';
  const pax = parseInt(t.passenger_count)||0;
  return `<div class="trip-log-item ${statusClass}">
    <div class="trip-log-row">
      <div class="trip-log-times">${escHtml(dep)}</div>
      <span style="font-size:10px;font-weight:700;color:${statusColor};">${statusLabel}</span>
    </div>
    <div class="trip-log-meta">
      ${t.trip_date  ? `<span class="trip-log-date">${escHtml(t.trip_date)}</span>` : ''}
      ${t.route_name ? `<span class="trip-log-route">${escHtml(t.route_name)}</span>` : ''}
      ${pax > 0      ? `<span class="trip-log-pax">👥 ${pax} pax</span>` : ''}
    </div>
  </div>`;
}

function escHtml(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ─────────────────────────────────────────────────────────────
// BOOKING ACTIONS
// ─────────────────────────────────────────────────────────────
function approveBooking(btn, id) {
  bookingAction(btn, id, 'approve');
}
function declineBooking(btn, id) {
  bookingAction(btn, id, 'decline');
}
function bookingAction(btn, id, action) {
  btn.disabled = true;
  fetch('actions/booking_action.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id, action })
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      const card = btn.closest('.booking-card');
      const tag  = card.querySelector('.tag');
      if (action === 'approve') {
        tag.className = 'tag tag-green'; tag.textContent = 'Approved';
        card.querySelector('.booking-actions').innerHTML = '<button class="btn btn-ghost" disabled style="opacity:0.4;cursor:default;">✓ Approved</button>';
        showToast('Booking approved', 'success');
      } else {
        tag.className = 'tag tag-red'; tag.textContent = 'Declined';
        card.querySelector('.booking-actions').innerHTML = '<button class="btn btn-ghost" disabled style="opacity:0.4;cursor:default;">✕ Declined</button>';
        showToast('Booking declined', 'error');
      }
    } else {
      showToast(res.message || 'Action failed', 'error');
      btn.disabled = false;
    }
  })
  .catch(() => { showToast('Network error', 'error'); btn.disabled = false; });
}
let _rescheduleId = null;
function rescheduleBooking(btn, id) {
  _rescheduleId = id;
  // Default to tomorrow
  const tomorrow = new Date(); tomorrow.setDate(tomorrow.getDate() + 1);
  document.getElementById('reschedDate').value =
    tomorrow.toISOString().slice(0, 10);
  document.getElementById('reschedTime').value = '08:00';
  document.getElementById('reschedMsg').textContent = '';
  document.getElementById('reschedMsg').className = '';
  document.getElementById('reschedOverlay').classList.add('active');
}

function closeReschedule() {
  document.getElementById('reschedOverlay').classList.remove('active');
  _rescheduleId = null;
}

function submitReschedule() {
  if (!_rescheduleId) return;
  const date = document.getElementById('reschedDate').value;
  const time = document.getElementById('reschedTime').value;
  const msgEl = document.getElementById('reschedMsg');
  if (!date || !time) { msgEl.textContent = 'Please choose a date and time.'; msgEl.className = 'dispatch-msg error'; return; }

  const btn = document.getElementById('reschedSubmitBtn');
  btn.disabled = true; btn.textContent = 'Saving…';

  fetch('actions/reschedule_booking.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id: _rescheduleId, booking_date: date, booking_time: time })
  })
  .then(r => r.json())
  .then(res => {
    btn.disabled = false; btn.textContent = 'Save';
    if (res.success) {
      // Update card in DOM
      const card = document.querySelector(`.booking-card[data-id="${_rescheduleId}"]`);
      if (card) {
        card.querySelector('.booking-date').textContent = res.booking_date + '  ·  ' + res.booking_time;
        card.querySelector('.tag').className = 'tag tag-green';
        card.querySelector('.tag').textContent = 'Approved';
        card.querySelector('.booking-actions').innerHTML = '<button class="btn btn-ghost" disabled style="opacity:0.4;cursor:default;">✓ Approved</button>';
      }
      showToast('Booking rescheduled & approved', 'success');
      setTimeout(closeReschedule, 900);
    } else {
      msgEl.textContent = res.message || 'Failed.'; msgEl.className = 'dispatch-msg error';
    }
  })
  .catch(() => { btn.disabled = false; btn.textContent = 'Save'; msgEl.textContent = 'Network error.'; msgEl.className = 'dispatch-msg error'; });
}

// ─────────────────────────────────────────────────────────────
// SCHEDULE EDIT
// ─────────────────────────────────────────────────────────────
function editSchedule(id) {
  const card  = document.getElementById('sched-' + id);
  const times = card.querySelectorAll('.sched-time-value');
  const label = card.querySelector('.sched-driver').childNodes[0].textContent.trim();
  document.getElementById('editSchedLabel').textContent = label;
  document.getElementById('editSchedId').value = id;
  const toTime = t => {
    const m = t.match(/(\d+):(\d+)\s*(AM|PM)/i);
    if (!m) return '00:00';
    let hh = parseInt(m[1]);
    const ap = m[3].toUpperCase();
    if (ap === 'PM' && hh !== 12) hh += 12;
    if (ap === 'AM' && hh === 12) hh = 0;
    return `${String(hh).padStart(2,'0')}:${m[2]}`;
  };
  document.getElementById('editFirst').value = toTime(times[0].textContent);
  document.getElementById('editLast').value  = toTime(times[1].textContent);
  document.getElementById('editFreq').value  = parseInt(times[2].textContent);
  document.getElementById('schedEditForm').style.display = 'block';
  document.getElementById('schedEditForm').scrollIntoView({ behavior: 'smooth' });
}

function saveSchedule() {
  const id    = document.getElementById('editSchedId').value;
  const first = document.getElementById('editFirst').value;
  const last  = document.getElementById('editLast').value;
  const freq  = document.getElementById('editFreq').value;
  if (!first || !last || !freq) { showToast('Fill in all fields','error'); return; }

  fetch('actions/schedule_action.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id, first_trip: first, last_trip: last, frequency_min: parseInt(freq) })
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      const card  = document.getElementById('sched-' + id);
      const times = card.querySelectorAll('.sched-time-value');
      const toDisplay = v => {
        const [h, m] = v.split(':');
        let hh = parseInt(h), ap = hh >= 12 ? 'PM' : 'AM';
        hh = hh % 12 || 12;
        return `${hh}:${m} ${ap}`;
      };
      times[0].textContent = toDisplay(first);
      times[1].textContent = toDisplay(last);
      times[2].textContent = freq + ' min';
      document.getElementById('schedEditForm').style.display = 'none';
      showToast('Schedule updated', 'success');
    } else {
      showToast(res.message || 'Save failed', 'error');
    }
  })
  .catch(() => showToast('Network error', 'error'));
}

function cancelEditSchedule() {
  document.getElementById('schedEditForm').style.display = 'none';
}

// ─────────────────────────────────────────────────────────────
// DRIVER / TRIP
// ─────────────────────────────────────────────────────────────
let tripDriverId = null;


function editDriver(id) {
  const card = document.querySelector(`#driverList .driver-card[data-id="${id}"]`);
  if (!card) return;
  const name    = card.querySelector('.driver-name').textContent.trim();
  const metaParts = card.querySelector('.driver-meta').textContent.split('·');
  const unitCode  = metaParts[0]?.trim() || '';
  const routeName = metaParts[1]?.trim() || '';

  document.getElementById('editDrvId').value          = id;
  document.getElementById('editDrvName').value         = name;
  document.getElementById('editDrvLicense').value      = '';
  document.getElementById('editDrvFormLabel').textContent = name;

  // Pre-select current unit & route by label matching
  const unitOpts  = document.querySelectorAll('#csd-editDrvJeep .custom-select-option:not(.placeholder-opt)');
  const routeOpts = document.querySelectorAll('#csd-editDrvRoute .custom-select-option:not(.placeholder-opt)');

  let matchedUnit = '', matchedUnitLabel = '— Select Unit —';
  unitOpts.forEach(o => { if (o.textContent.trim() === unitCode) { matchedUnit = o.dataset.value; matchedUnitLabel = o.textContent.trim(); } });
  csSelect('editDrvJeep', matchedUnit, matchedUnit ? matchedUnitLabel : '— Select Unit —');

  let matchedRoute = '', matchedRouteLabel = '— Select Route —';
  routeOpts.forEach(o => { if (o.textContent.trim() === routeName) { matchedRoute = o.dataset.value; matchedRouteLabel = o.textContent.trim(); } });
  csSelect('editDrvRoute', matchedRoute, matchedRoute ? matchedRouteLabel : '— Select Route —');

    const form = document.getElementById('editDrvForm');
  form.style.display = 'block';
  form.scrollIntoView({ behavior: 'smooth' });
}

function saveEditDriver() {
  const id      = document.getElementById('editDrvId').value;
  const name    = document.getElementById('editDrvName').value.trim();
  const license = document.getElementById('editDrvLicense').value.trim();
  const jeep    = document.getElementById('editDrvJeep').value;
  const route   = document.getElementById('editDrvRoute').value;
  const newPass = document.getElementById('editDrvPass').value;

  if (!name) { showToast('Full name is required', 'error'); return; }

  fetch('actions/edit_driver.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id, full_name: name, license_number: license, unit_id: jeep, route_id: route, password: newPass })
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      const card = document.querySelector(`#driverList .driver-card[data-id="${id}"]`);
      if (card) {
        card.querySelector('.driver-name').textContent = name;
        const unitLabel  = jeep  ? document.querySelector(`#csd-editDrvJeep  .custom-select-option[data-value="${jeep}"]`)?.textContent.trim()  || '—' : '—';
        const routeLabel = route ? document.querySelector(`#csd-editDrvRoute .custom-select-option[data-value="${route}"]`)?.textContent.trim() || '—' : '—';
        card.querySelector('.driver-meta').innerHTML = `${unitLabel} &nbsp;·&nbsp; ${routeLabel}`;
        card.querySelector('.driver-avatar').textContent = name.charAt(0).toUpperCase();
      }
      document.getElementById('editDrvForm').style.display = 'none';
      showToast('Driver updated', 'success');
    } else {
      showToast(res.message || 'Update failed', 'error');
    }
  })
  .catch(() => showToast('Network error', 'error'));
}

function cancelEditDriver() {
  document.getElementById('editDrvForm').style.display = 'none';
}

function createDriver() {
  const first   = document.getElementById('drvFirst').value.trim();
  const last    = document.getElementById('drvLast').value.trim();
  const license = document.getElementById('drvLicense').value.trim();
  const jeep    = document.getElementById('drvJeep').value;
  const route   = document.getElementById('drvRoute').value;
  const user    = document.getElementById('drvUser').value.trim();
  const pass    = document.getElementById('drvPass').value;

  if (!first || !last || !license || !jeep || !route || !user || !pass) {
    showToast('Please fill in all fields', 'error'); return;
  }

  fetch('actions/create_driver.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ first_name: first, last_name: last, license, unit_id: jeep, route_id: route, username: user, password: pass })
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      // full_name stored as "Last, First" in DB — display nicely
      const displayName = last + ', ' + first;
      const initials    = (first[0] + last[0]).toUpperCase();
      const card        = document.createElement('div');
      card.className    = 'driver-card';
      card.dataset.id   = res.driver_id;
      card.innerHTML = `
        <div class="driver-avatar">${initials}</div>
        <div class="driver-info">
          <div class="driver-name">${escHtml(displayName)}</div>
          <div class="driver-meta">${escHtml(res.unit_code)} &nbsp;·&nbsp; ${escHtml(res.route_name)}</div>
        </div>
        <div class="driver-actions">
          <button class="btn btn-ghost" onclick="editDriver(${res.driver_id})">Edit</button>
        </div>`;

      const empty = document.querySelector('#driverList .empty-state');
      if (empty) empty.remove();
      document.getElementById('driverList').appendChild(card);

      ['drvFirst','drvLast','drvLicense','drvUser','drvPass'].forEach(id => document.getElementById(id).value = '');
      csReset('drvJeep',  '— Select Unit —');
      csReset('drvRoute', '— Select Route —');
      showToast('Driver account created!', 'success');
    } else {
      showToast(res.message || 'Failed to create driver', 'error');
    }
  })
  .catch(() => showToast('Network error', 'error'));
}

// ─────────────────────────────────────────────────────────────
// LIVE MAP POLLING
// ─────────────────────────────────────────────────────────────
const _opMarkers = {}, _opData = {};

function _opJeepIcon(direction, stale) {
  const opacity = stale ? '0.45' : '1';
  const flip    = direction === 'reverse' ? 'scaleX(-1)' : 'scaleX(1)';
  return L.divIcon({
    className: '',
    html: `<img src="Modern.png" style="width:38px;height:38px;transform:${flip};opacity:${opacity};transform-origin:center;">`,
    iconSize:[38,38], iconAnchor:[19,19], popupAnchor:[0,-22]
  });
}

function _opPopup(d) {
  const eta = d.eta_minutes != null
    ? `<div style="margin-top:5px;font-size:11px;color:#22c55e">ETA ~${d.eta_minutes} min${d.eta_dist_km!=null?' · '+d.eta_dist_km+' km':''}</div>`
    : '';
  const staleTag = d.stale ? `<span style="font-size:10px;background:#1e293b;color:#64748b;padding:1px 6px;border-radius:999px;margin-left:4px">stale</span>` : '';
  return `<div style="font-family:inherit;min-width:170px;padding:10px 12px">
    <div style="font-weight:700;font-size:13px;color:#e2e8f0">${d.unit_code||'—'}${staleTag}</div>
    <div style="font-size:11px;color:#64748b;margin-top:2px">${d.plate_no||''}${d.route_name?' · '+d.route_name:''}</div>
    <div style="font-size:11px;color:#94a3b8;margin-top:2px">${d.driver_name||'Unknown driver'}</div>
    ${eta}
  </div>`;
}

async function _opPollJeepneys() {
  if (!_map) return;
  try {
    const res  = await fetch('../commuter/api.php?action=live_jeepneys', { cache: 'no-store' });
    if (!res.ok) return;
    const body = await res.json();
    if (!body.ok) return;
    const seen = new Set();
    body.jeepneys.forEach(d => {
      seen.add(d.account_id);
      _opData[d.account_id] = d;
      if (_opMarkers[d.account_id]) {
        _opMarkers[d.account_id].setLatLng([d.lat, d.lng]);
        _opMarkers[d.account_id].setIcon(_opJeepIcon(d.direction, d.stale));
        if (_opMarkers[d.account_id].isPopupOpen())
          _opMarkers[d.account_id].setPopupContent(_opPopup(d));
      } else {
        _opMarkers[d.account_id] = L.marker([d.lat,d.lng],{ icon:_opJeepIcon(d.direction,d.stale) })
          .bindPopup(_opPopup(d),{ maxWidth:240 }).addTo(_map);
      }
    });
    Object.keys(_opMarkers).forEach(id => {
      if (!seen.has(+id)) { _map.removeLayer(_opMarkers[id]); delete _opMarkers[id]; delete _opData[id]; }
    });
  } catch(e) { console.warn('Op map poll failed:', e); }
}

// ─────────────────────────────────────────────────────────────
// CUSTOM SELECT
// ─────────────────────────────────────────────────────────────
function csToggle(id) {
  const trigger  = document.querySelector(`#cs-${id} .custom-select-trigger`);
  const dropdown = document.getElementById(`csd-${id}`);
  const isOpen   = dropdown.classList.contains('open');
  document.querySelectorAll('.custom-select-dropdown.open').forEach(d => {
    d.classList.remove('open');
    d.previousElementSibling?.previousElementSibling?.classList.remove('open');
  });
  if (!isOpen) { dropdown.classList.add('open'); trigger.classList.add('open'); }
}

function csSelect(id, value, label) {
  const native = document.getElementById(id);
  if (native) native.value = value;
  const trigger = document.querySelector(`#cs-${id} .custom-select-trigger`);
  trigger.textContent = label;
  trigger.classList.toggle('placeholder', !value);
  document.querySelectorAll(`#csd-${id} .custom-select-option`).forEach(opt => {
    opt.classList.toggle('selected', opt.dataset.value === value);
  });
  document.getElementById(`csd-${id}`).classList.remove('open');
  trigger.classList.remove('open');
}

function csReset(id, placeholder) { csSelect(id, '', placeholder); }


// ─────────────────────────────────────────────────────────────
// CREATE SCHEDULE
// ─────────────────────────────────────────────────────────────
function createSchedule() {
  const driverId = document.getElementById('newSchedDriver').value;
  const first    = document.getElementById('newSchedFirst').value;
  const last     = document.getElementById('newSchedLast').value;
  const freq     = document.getElementById('newSchedFreq').value;

  if (!driverId) { showToast('Please select a driver', 'error'); return; }
  if (!first || !last || !freq) { showToast('Fill in all time fields', 'error'); return; }

  fetch('actions/create_schedule.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ driver_id: driverId, first_trip: first, last_trip: last, frequency_min: parseInt(freq) })
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      // Remove empty state if present
      const empty = document.getElementById('schedEmptyState');
      if (empty) empty.remove();

      // Build and insert card
      const toDisplay = v => {
        const [h, m] = v.split(':');
        let hh = parseInt(h), ap = hh >= 12 ? 'PM' : 'AM';
        hh = hh % 12 || 12;
        return `${hh}:${m} ${ap}`;
      };
      const card = document.createElement('div');
      card.className = 'sched-card';
      card.id = 'sched-' + res.sched_id;
      card.innerHTML = `
        <div class="sched-header">
          <div>
            <div class="sched-driver">${escHtml(res.driver_name)}&nbsp;<span class="tag tag-cyan">${escHtml(res.unit_code || '—')}</span></div>
            <div class="sched-route">${escHtml(res.route_name || '—')}</div>
          </div>
          <button class="btn btn-cyan" onclick="editSchedule(${res.sched_id})">Edit</button>
        </div>
        <div class="sched-times">
          <div class="sched-time-block"><div class="sched-time-label">First Trip</div><div class="sched-time-value">${toDisplay(first)}</div></div>
          <div class="sched-time-block"><div class="sched-time-label">Last Trip</div><div class="sched-time-value">${toDisplay(last)}</div></div>
          <div class="sched-time-block"><div class="sched-time-label">Frequency</div><div class="sched-time-value">${freq} min</div></div>
        </div>`;
      document.getElementById('schedList').appendChild(card);

      // Reset form
      csReset('newSchedDriver', '— Select Driver —');
      document.getElementById('newSchedFirst').value = '05:00';
      document.getElementById('newSchedLast').value  = '22:00';
      document.getElementById('newSchedFreq').value  = '20';
      showToast('Schedule assigned!', 'success');
    } else {
      showToast(res.message || 'Failed to assign schedule', 'error');
    }
  })
  .catch(() => showToast('Network error', 'error'));
}

// ─────────────────────────────────────────────────────────────
// DISPATCH TRIP
// ─────────────────────────────────────────────────────────────
let _dispatchUnitId = null;

function openDispatchTrip(unitId, unitCode) {
  _dispatchUnitId = unitId;
  document.getElementById('dispatchUnitLabel').textContent = unitCode;
  // Default departure = now + 5 minutes rounded to nearest 5
  const now = new Date();
  now.setMinutes(Math.ceil((now.getMinutes() + 5) / 5) * 5, 0, 0);
  document.getElementById('dispatchTime').value =
    String(now.getHours()).padStart(2,'0') + ':' + String(now.getMinutes()).padStart(2,'0');
  document.getElementById('dispatchMsg').textContent = '';
  document.getElementById('dispatchMsg').className = '';
  document.getElementById('dispatchOverlay').classList.add('active');
}

function closeDispatchTrip() {
  document.getElementById('dispatchOverlay').classList.remove('active');
  _dispatchUnitId = null;
}

function submitDispatch() {
  if (!_dispatchUnitId) return;
  const routeId   = document.getElementById('dispatchRoute').value;
  const departure = document.getElementById('dispatchTime').value;
  const msgEl     = document.getElementById('dispatchMsg');

  if (!routeId)   { msgEl.textContent = 'Please select a route.'; msgEl.className = 'dispatch-msg error'; return; }
  if (!departure) { msgEl.textContent = 'Please set a departure time.'; msgEl.className = 'dispatch-msg error'; return; }

  // trip_action.php needs driver_id (driver profile id) not unit id.
  // We resolve driver_id from unit_id server-side via a helper parameter.
  const btn = document.getElementById('dispatchSubmitBtn');
  btn.disabled = true; btn.textContent = 'Dispatching…';

  fetch('actions/trip_action.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ unit_id: _dispatchUnitId, route_id: parseInt(routeId), departure })
  })
  .then(r => r.json())
  .then(res => {
    btn.disabled = false; btn.textContent = 'Dispatch';
    if (res.success) {
      msgEl.textContent = 'Trip dispatched! (Trip #' + res.trip_id + ')';
      msgEl.className = 'dispatch-msg success';
      setTimeout(closeDispatchTrip, 1600);
      showToast('Trip dispatched', 'success');
    } else {
      msgEl.textContent = res.message || 'Failed to dispatch.';
      msgEl.className = 'dispatch-msg error';
    }
  })
  .catch(() => {
    btn.disabled = false; btn.textContent = 'Dispatch';
    msgEl.textContent = 'Network error.'; msgEl.className = 'dispatch-msg error';
  });
}

// ─────────────────────────────────────────────────────────────
// REMOVE DRIVER
// ─────────────────────────────────────────────────────────────
function confirmRemoveDriver(id, name) {
  if (!confirm(`Remove driver "${name}"?\n\nThis will deactivate their login and unassign their jeepney.`)) return;
  removeDriver(id);
}

function removeDriver(id) {
  fetch('actions/remove_driver.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ driver_id: id })
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      const card = document.querySelector(`#driverList .driver-card[data-id="${id}"]`);
      if (card) card.remove();
      if (!document.querySelector('#driverList .driver-card')) {
        document.getElementById('driverList').innerHTML = `
          <div class="empty-state" id="driverEmptyState">
            <div class="empty-icon">👤</div>
            <div class="empty-label">No drivers yet</div>
            <div class="empty-sub">Create a driver account using the form above</div>
          </div>`;
      }
      showToast('Driver removed', 'success');
    } else {
      showToast(res.message || 'Remove failed', 'error');
    }
  })
  .catch(() => showToast('Network error', 'error'));
}

// ─────────────────────────────────────────────────────────────
// INIT
// ─────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  // Wire custom select options
  document.querySelectorAll('.custom-select-option').forEach(opt => {
    opt.addEventListener('click', () => {
      const dropdown = opt.closest('.custom-select-dropdown');
      const id = dropdown.id.replace('csd-', '');
      csSelect(id, opt.dataset.value, opt.textContent.trim());
    });
  });

  // Close dropdowns on outside click
  document.addEventListener('click', e => {
    if (!e.target.closest('.custom-select')) {
      document.querySelectorAll('.custom-select-dropdown.open').forEach(d => {
        d.classList.remove('open');
        d.previousElementSibling?.previousElementSibling?.classList.remove('open');
      });
    }
  });
});

window.onload = () => {
  initMap();
  _opPollJeepneys();
  setInterval(_opPollJeepneys, 10000);
};
</script>
</body>
</html>