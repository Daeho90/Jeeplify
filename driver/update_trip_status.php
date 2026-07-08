<?php
// ════════════════════════════════════════════════════════════
//  JEEPLIFY BCD — driver/update_trip_status.php
//  POST (form-encoded): trip_id, status
//  Valid statuses: on_route, traffic, maintenance, complete
// ════════════════════════════════════════════════════════════
session_start();
header('Content-Type: application/json');

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

$tripId    = isset($_POST['trip_id']) ? (int)$_POST['trip_id'] : 0;
$rawStatus = isset($_POST['status'])  ? trim((string)$_POST['status']) : '';

// Map driver UI statuses → DB trip statuses
// on_route / traffic / maintenance → 'active'  (trip is ongoing)
// complete                          → 'completed'
$ALLOWED = ['on_route', 'traffic', 'maintenance', 'complete'];
if (!$tripId || !in_array($rawStatus, $ALLOWED)) {
    echo json_encode(['ok' => false, 'message' => 'Invalid parameters']);
    exit;
}

$dbStatus = ($rawStatus === 'complete') ? 'completed' : 'active';

require_once '../db.php';
$accountId = (int)$_SESSION['account_id'];

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
        exit;
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
    error_log('update_trip_status error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Server error']);
}