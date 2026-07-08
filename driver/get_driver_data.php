<?php
// ════════════════════════════════════════════════════════════
//  JEEPLIFY BCD — driver/get_driver_data.php
// ════════════════════════════════════════════════════════════

session_start();
header('Content-Type: application/json');

// ── AUTH GUARD ───────────────────────────────────────────────
if (empty($_SESSION['account_id']) || ($_SESSION['role'] ?? '') !== 'driver') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../db.php';   // provides $pdo

$accountId = (int) $_SESSION['account_id'];

try {
    // ── 1. Driver profile + assigned jeepney ────────────────
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
        exit;
    }

    // ── 2. Next / active trip ───────────────────────────────
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

    // ── 3. Last known location ──────────────────────────────
    $lStmt = $pdo->prepare("
        SELECT lat, lng, updated_at
        FROM   driver_locations
        WHERE  account_id = ?
        LIMIT  1
    ");
    $lStmt->execute([$accountId]);
    $loc = $lStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // ── Response ─────────────────────────────────────────────
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
    error_log('get_driver_data error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'message' => 'Server error. Please try again.'
    ]);
}