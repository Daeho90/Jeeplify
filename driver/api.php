<?php
// ════════════════════════════════════════════════════════════
//  JEEPLIFY BCD — driver/api.php
//  Consolidated driver-side endpoint.
//  Replaces: get_driver_data.php, update_location.php, update_trip_status.php
// ════════════════════════════════════════════════════════════
require_once '../session_init.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once '../db.php';

// ── AUTH GUARD (applies to every action in this file) ────────
if (empty($_SESSION['account_id']) || ($_SESSION['role'] ?? '') !== 'driver') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'get_driver_data':   handle_get_driver_data($pdo);   break;
    case 'update_location':   handle_update_location($pdo);   break;
    case 'update_trip_status':handle_update_trip_status($pdo);break;
    case 'driver_bookings':   handle_driver_bookings($pdo);   break;
    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
}

// ════════════════════════════════════════════════════════════
//  GET ?action=get_driver_data
// ════════════════════════════════════════════════════════════
function handle_get_driver_data(PDO $pdo): void {
    $accountId = (int) $_SESSION['account_id'];

    try {
        // ── 1. Driver profile + assigned jeepney ────────────
        //  driver_jeepney.driver_id → driver_profiles.id  (NOT account_id)
        $stmt = $pdo->prepare("
            SELECT
                dp.id            AS driver_id,
                dp.full_name,
                dp.license_number,
                dp.phone,
                dp.is_available,
                j.id             AS jeepney_id,
                j.plate_no,
                j.model,
                j.capacity,
                j.status         AS jeepney_status
            FROM   driver_profiles dp
            LEFT JOIN driver_jeepney dj ON dj.driver_id  = dp.id
            LEFT JOIN jeepneys       j  ON j.id           = dj.jeepney_id
            WHERE  dp.account_id = ?
            LIMIT  1
        ");
        $stmt->execute([$accountId]);
        $driver = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$driver) {
            echo json_encode(['ok' => false, 'message' => 'Driver profile not found.']);
            return;
        }

        // ── 2. Next / active trip ───────────────────────────
        $trip = null;

        if ($driver['jeepney_id']) {
            $tStmt = $pdo->prepare("
                SELECT
                    t.id,
                    t.route_name,
                    DATE_FORMAT(t.departure_time, '%h:%i %p') AS departure,
                    t.status,
                    j.plate_no AS vehicle
                FROM   trips    t
                JOIN   jeepneys j ON j.id = t.jeepney_id
                WHERE  t.jeepney_id = ?
                  AND  t.status IN ('scheduled', 'active')
                ORDER BY
                    FIELD(t.status, 'active', 'scheduled'),
                    t.departure_time ASC
                LIMIT  1
            ");
            $tStmt->execute([$driver['jeepney_id']]);
            $trip = $tStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        // ── 3. Last known location ───────────────────────────
        $lStmt = $pdo->prepare("
            SELECT lat, lng, updated_at
            FROM   driver_locations
            WHERE  account_id = ?
            LIMIT  1
        ");
        $lStmt->execute([$accountId]);
        $loc = $lStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        // ── Response ─────────────────────────────────────────
        echo json_encode([
            'ok'     => true,
            'driver' => [
                'name'          => $driver['full_name'],
                'license_no'    => $driver['license_number'] ?? null,
                'phone'         => $driver['phone']          ?? null,
                'is_available'  => (bool) $driver['is_available'],
                'jeep_id'       => $driver['plate_no']       ?? null,
                'model'         => $driver['model']          ?? null,
                'capacity'      => $driver['capacity']       ?? null,
                'jeepney_status'=> $driver['jeepney_status'] ?? null,
            ],
            'trip' => $trip ? [
                'id'        => $trip['id'],
                'route'     => $trip['route_name'],
                'departure' => $trip['departure'],
                'vehicle'   => $trip['vehicle'],
                'status'    => $trip['status'],
            ] : null,
            'last_location' => $loc,
        ]);

    } catch (Throwable $e) {
        error_log('api/get_driver_data: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Server error. Please try again.']);
    }
}

// ════════════════════════════════════════════════════════════
//  POST ?action=update_location
//  Body (form-encoded): lat, lng, eta_minutes, eta_dist_km, direction, status
//  Offline beacon: _offline=1 (sent via navigator.sendBeacon on page unload)
// ════════════════════════════════════════════════════════════
function handle_update_location(PDO $pdo): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
        return;
    }

    $accountId = (int) $_SESSION['account_id'];

    // ── OFFLINE BEACON ────────────────────────────────────────
    if (!empty($_POST['_offline'])) {
        $pdo->prepare("
            UPDATE jeepneys j
            JOIN driver_jeepney  dj ON dj.jeepney_id = j.id
            JOIN driver_profiles dp ON dp.id          = dj.driver_id
            SET j.status = 'offline'
            WHERE dp.account_id = ?
        ")->execute([$accountId]);
        echo json_encode(['ok' => true]);
        return;
    }

    // ── COORDINATES ──────────────────────────────────────────
    $lat = filter_input(INPUT_POST, 'lat', FILTER_VALIDATE_FLOAT);
    $lng = filter_input(INPUT_POST, 'lng', FILTER_VALIDATE_FLOAT);

    if ($lat === false || $lng === false || $lat === null || $lng === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Invalid coordinates']);
        return;
    }

    // Basic bounds check (Philippines bounding box)
    if ($lat < 4.5 || $lat > 21.5 || $lng < 116.0 || $lng > 127.0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Coordinates out of expected range']);
        return;
    }

    // ── ETA FIELDS (optional — null if not provided) ─────────
    $etaMinutesRaw = $_POST['eta_minutes'] ?? '';
    $etaDistRaw    = $_POST['eta_dist_km'] ?? '';
    $direction     = $_POST['direction']   ?? 'forward';
    $rawStatus     = $_POST['status']      ?? '';

    $etaMinutes = ($etaMinutesRaw !== '' && is_numeric($etaMinutesRaw))
        ? (int) $etaMinutesRaw
        : null;

    $etaDistKm = ($etaDistRaw !== '' && is_numeric($etaDistRaw))
        ? (float) $etaDistRaw
        : null;

    $direction = in_array($direction, ['forward', 'reverse']) ? $direction : 'forward';

    $ALLOWED_STATUSES = ['on_route', 'traffic', 'maintenance', 'complete', 'idle'];
    $status = in_array($rawStatus, $ALLOWED_STATUSES) ? $rawStatus : 'on_route';

    try {
        // UPSERT — insert or update in one query (MySQL 8+ ON DUPLICATE KEY)
        // Assumes driver_locations has a UNIQUE KEY on account_id
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
        // `j.status != 'offline'` filter.
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
        error_log('api/update_location: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Server error']);
    }
}

// ════════════════════════════════════════════════════════════
//  POST ?action=update_trip_status
//  Body (form-encoded): trip_id, status
//  Valid statuses: on_route, traffic, maintenance, complete
// ════════════════════════════════════════════════════════════
function handle_update_trip_status(PDO $pdo): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
        return;
    }

    $tripId    = isset($_POST['trip_id']) ? (int)$_POST['trip_id'] : 0;
    $rawStatus = isset($_POST['status'])  ? trim((string)$_POST['status']) : '';

    // Map driver UI statuses → DB trip statuses
    // on_route / traffic / maintenance → 'active'  (trip is ongoing)
    // complete                          → 'completed'
    $ALLOWED = ['on_route', 'traffic', 'maintenance', 'complete'];
    if (!$tripId || !in_array($rawStatus, $ALLOWED)) {
        echo json_encode(['ok' => false, 'message' => 'Invalid parameters']);
        return;
    }

    $dbStatus = ($rawStatus === 'complete') ? 'completed' : 'active';
    $accountId = (int) $_SESSION['account_id'];

    try {
        // Verify the trip belongs to this driver's jeepney
        $checkStmt = $pdo->prepare("
            SELECT t.id
            FROM   trips          t
            JOIN   driver_jeepney dj ON dj.jeepney_id = t.jeepney_id
            JOIN   driver_profiles dp ON dp.id = dj.driver_id
            WHERE  t.id        = ?
              AND  dp.account_id = ?
            LIMIT  1
        ");
        $checkStmt->execute([$tripId, $accountId]);
        if (!$checkStmt->fetch()) {
            echo json_encode(['ok' => false, 'message' => 'Trip not found or access denied']);
            return;
        }

        // Update status
        $stmt = $pdo->prepare("
            UPDATE trips
            SET    status = ?
            WHERE  id     = ?
              AND  status NOT IN ('cancelled')
        ");
        $stmt->execute([$dbStatus, $tripId]);

        // Mirror the driver-facing status (on_route/traffic/maintenance/complete)
        // directly into driver_locations so the commuter map always shows the right colour
        // even when there's no trip row or the trip hasn't started yet.
        $pdo->prepare("
            UPDATE driver_locations
            SET    status = ?
            WHERE  account_id = ?
        ")->execute([$rawStatus, $accountId]);

        // If completing, also mark driver as available
        if ($dbStatus === 'completed') {
            $pdo->prepare("
                UPDATE driver_profiles dp
                JOIN   accounts a ON a.id = dp.account_id
                SET    dp.is_available = 1
                WHERE  a.id = ?
            ")->execute([$accountId]);
        }

        echo json_encode(['ok' => true, 'db_status' => $dbStatus]);

    } catch (Throwable $e) {
        error_log('api/update_trip_status: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Server error']);
    }
}

// ════════════════════════════════════════════════════════════
//  GET ?action=driver_bookings
//  Returns pending + approved bookings for this driver's assigned unit
// ════════════════════════════════════════════════════════════
function handle_driver_bookings(PDO $pdo): void {
    $accountId = (int) $_SESSION['account_id'];

    try {
        $stmt = $pdo->prepare("
            SELECT
                b.id, b.passenger_name, b.passenger_count,
                DATE_FORMAT(b.booking_date,'%b %d, %Y') AS booking_date,
                DATE_FORMAT(b.booking_time,'%h:%i %p')  AS booking_time,
                b.pickup_location, b.dropoff_location, b.status, b.notes,
                j.unit_code, r.name AS route_name
            FROM bookings b
            JOIN jeepneys j          ON j.id = b.jeepney_id
            JOIN driver_jeepney dj   ON dj.jeepney_id = j.id
            JOIN driver_profiles dp  ON dp.id = dj.driver_id
            LEFT JOIN routes r       ON r.id = b.route_id
            WHERE dp.account_id = ?
              AND b.status IN ('pending', 'approved')
            ORDER BY b.booking_date ASC, b.booking_time ASC
        ");
        $stmt->execute([$accountId]);
        $bookings = $stmt->fetchAll();

        foreach ($bookings as &$b) {
            $b['id']              = (int) $b['id'];
            $b['passenger_count'] = (int) $b['passenger_count'];
        }
        unset($b);

        echo json_encode(['ok' => true, 'bookings' => $bookings]);

    } catch (PDOException $e) {
        error_log('api/driver_bookings: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Query failed']);
    }
}