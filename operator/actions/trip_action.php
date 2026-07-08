<?php
// operator/actions/trip_action.php
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['account_id']) || ($_SESSION['role'] ?? '') !== 'operator') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../../db.php';

$raw = file_get_contents('php://input');
$d   = json_decode($raw, true);

$driver_id = (int)($d['driver_id'] ?? 0);
$unit_id   = (int)($d['unit_id']   ?? 0);   // alternate: resolve via jeepney id
$departure  = trim($d['departure'] ?? '');
$route_id   = (int)($d['route_id'] ?? 0);

if (!$departure || !$route_id || (!$driver_id && !$unit_id)) {
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit;
}

try {
    // Resolve jeepney_id
    $jeepney_id = 0;

    if ($unit_id) {
        // Direct unit_id provided by dispatch form
        $jeepney_id = $unit_id;
    } else {
        // driver_id provided — look up their assigned jeepney
        $djStmt = $pdo->prepare("
            SELECT dj.jeepney_id
            FROM   driver_jeepney dj
            WHERE  dj.driver_id = ?
            LIMIT 1
        ");
        $djStmt->execute([$driver_id]);
        $dj = $djStmt->fetch();
        if (!$dj) {
            echo json_encode(['success' => false, 'message' => 'Driver has no assigned jeepney.']);
            exit;
        }
        $jeepney_id = (int) $dj['jeepney_id'];
    }

    if (!$jeepney_id) {
        echo json_encode(['success' => false, 'message' => 'Could not resolve jeepney.']);
        exit;
    }

    // Get route name
    $routeStmt = $pdo->prepare("SELECT name FROM routes WHERE id = ? LIMIT 1");
    $routeStmt->execute([$route_id]);
    $route = $routeStmt->fetch();
    if (!$route) {
        echo json_encode(['success' => false, 'message' => 'Route not found.']);
        exit;
    }

    // Build departure datetime (today + time from input)
    $today             = date('Y-m-d');
    $departureDatetime = $today . ' ' . $departure . ':00';

    // trips: jeepney_id, route_name (varchar), departure_time (datetime), status
    $stmt = $pdo->prepare("
        INSERT INTO trips (jeepney_id, route_name, departure_time, status)
        VALUES (?, ?, ?, 'scheduled')
    ");
    $stmt->execute([$jeepney_id, $route['name'], $departureDatetime]);

    // Mark jeepney active
    $pdo->prepare("UPDATE jeepneys SET status = 'active' WHERE id = ?")
        ->execute([$jeepney_id]);

    echo json_encode(['success' => true, 'trip_id' => (int) $pdo->lastInsertId()]);

} catch (Throwable $e) {
    error_log('trip_action: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
