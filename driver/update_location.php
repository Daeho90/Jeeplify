<?php
// ════════════════════════════════════════════════════════════
//  JEEPLIFY BCD — driver/update_location.php
//  POST endpoint: receives {lat, lng, eta_minutes, eta_dist_km, direction}
//  and upserts driver_locations
//  Called every N seconds from the dashboard JS
// ════════════════════════════════════════════════════════════
session_start();
header('Content-Type: application/json');

// ── AUTH GUARD ───────────────────────────────────────────────
if (empty($_SESSION['account_id']) || ($_SESSION['role'] ?? '') !== 'driver') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

// ── OFFLINE BEACON ────────────────────────────────────────────
// Sent by navigator.sendBeacon on page unload — mark jeepney offline
if (!empty($_POST['_offline'])) {
    require_once '../db.php';
    $accountId = (int) $_SESSION['account_id'];
    $pdo->prepare("
        UPDATE jeepneys j
        JOIN driver_jeepney  dj ON dj.jeepney_id = j.id
        JOIN driver_profiles dp ON dp.id          = dj.driver_id
        SET j.status = 'offline'
        WHERE dp.account_id = ?
    ")->execute([$accountId]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── COORDINATES ──────────────────────────────────────────────
$lat = filter_input(INPUT_POST, 'lat', FILTER_VALIDATE_FLOAT);
$lng = filter_input(INPUT_POST, 'lng', FILTER_VALIDATE_FLOAT);

if ($lat === false || $lng === false || $lat === null || $lng === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid coordinates']);
    exit;
}

// Basic bounds check (Philippines bounding box)
if ($lat < 4.5 || $lat > 21.5 || $lng < 116.0 || $lng > 127.0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Coordinates out of expected range']);
    exit;
}

// ── ETA FIELDS (optional — null if not provided) ─────────────
$etaMinutesRaw = $_POST['eta_minutes'] ?? '';
$etaDistRaw    = $_POST['eta_dist_km'] ?? '';
$direction     = $_POST['direction']   ?? 'forward';
$rawStatus     = $_POST['status']      ?? '';

// eta_minutes: integer or NULL
$etaMinutes = ($etaMinutesRaw !== '' && is_numeric($etaMinutesRaw))
    ? (int) $etaMinutesRaw
    : null;

// eta_dist_km: float or NULL
$etaDistKm = ($etaDistRaw !== '' && is_numeric($etaDistRaw))
    ? (float) $etaDistRaw
    : null;

// direction: only allow known values
$direction = in_array($direction, ['forward', 'reverse']) ? $direction : 'forward';

// status: driver's current trip status — stored directly in driver_locations
$ALLOWED_STATUSES = ['on_route', 'traffic', 'maintenance', 'complete', 'idle'];
$status = in_array($rawStatus, $ALLOWED_STATUSES) ? $rawStatus : 'on_route';

require_once '../db.php';
$accountId = (int) $_SESSION['account_id'];

try {
    // UPSERT — insert or update in one query (MySQL 8+ ON DUPLICATE KEY)
    // Assumes driver_locations has a UNIQUE KEY on account_id
    //
    // Required DB migration (run once):
    // ALTER TABLE driver_locations
    //   ADD COLUMN eta_minutes  INT          NULL DEFAULT NULL,
    //   ADD COLUMN eta_dist_km  DECIMAL(6,2) NULL DEFAULT NULL,
    //   ADD COLUMN direction    VARCHAR(10)  NOT NULL DEFAULT 'forward',
    //   ADD COLUMN status       VARCHAR(20)  NOT NULL DEFAULT 'on_route';
    $stmt = $pdo->prepare("
        INSERT INTO driver_locations
            (account_id, lat, lng, eta_minutes, eta_dist_km, direction, status, updated_at)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            lat         = VALUES(lat),
            lng         = VALUES(lng),
            eta_minutes = VALUES(eta_minutes),
            eta_dist_km = VALUES(eta_dist_km),
            direction   = VALUES(direction),
            status      = VALUES(status),
            updated_at  = NOW()
    ");
    $stmt->execute([$accountId, $lat, $lng, $etaMinutes, $etaDistKm, $direction, $status]);

    // Mark the assigned jeepney as 'active' so it passes the commuter map's
    // `j.status != 'offline'` filter. We do this via the driver_profiles →
    // driver_jeepney → jeepneys join chain.
    $pdo->prepare("
        UPDATE jeepneys j
        JOIN driver_jeepney  dj ON dj.jeepney_id = j.id
        JOIN driver_profiles dp ON dp.id          = dj.driver_id
        SET j.status = 'active'
        WHERE dp.account_id = ?
          AND j.status = 'offline'
    ")->execute([$accountId]);

    echo json_encode(['ok' => true]);

} catch (PDOException $e) {
    error_log('update_location error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]); // temp debug
    exit;
}