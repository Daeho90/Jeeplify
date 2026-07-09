<?php
// operator/actions/create_schedule.php
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['account_id']) || ($_SESSION['role'] ?? '') !== 'operator') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../../db.php';

$raw       = file_get_contents('php://input');
$d         = json_decode($raw, true);
$driver_id = (int)($d['driver_id']     ?? 0);
$first     = trim($d['first_trip']     ?? '');
$last      = trim($d['last_trip']      ?? '');
$freq      = (int)($d['frequency_min'] ?? 20);

if (!$driver_id || !$first || !$last) {
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit;
}

try {
    // Get driver's jeepney
    $djStmt = $pdo->prepare("
        SELECT dj.jeepney_id, dp.full_name, j.unit_code, r.name AS route_name
        FROM   driver_profiles dp
        LEFT JOIN driver_jeepney dj ON dj.driver_id = dp.id
        LEFT JOIN jeepneys       j  ON j.id = dj.jeepney_id
        LEFT JOIN routes         r  ON r.id = j.route_id
        WHERE  dp.id = ?
        LIMIT 1
    ");
    $djStmt->execute([$driver_id]);
    $info = $djStmt->fetch();

    if (!$info) {
        echo json_encode(['success' => false, 'message' => 'Driver not found.']);
        exit;
    }

    $jeepney_id = (int)($info['jeepney_id'] ?? 0);
    if (!$jeepney_id) {
        echo json_encode(['success' => false, 'message' => 'This driver has no jeepney assigned. Assign a jeepney first.']);
        exit;
    }

    // Check if schedule already exists for this driver — update if so
    $existing = $pdo->prepare("SELECT id FROM schedules WHERE driver_id = ? LIMIT 1");
    $existing->execute([$driver_id]);
    $row = $existing->fetch();

    if ($row) {
        $pdo->prepare("
            UPDATE schedules SET first_trip=?, last_trip=?, frequency_min=?
            WHERE driver_id=?
        ")->execute([$first, $last, $freq, $driver_id]);
        $schedId = (int)$row['id'];
    } else {
        $pdo->prepare("
            INSERT INTO schedules (driver_id, jeepney_id, first_trip, last_trip, frequency_min)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$driver_id, $jeepney_id ?: null, $first, $last, $freq]);
        $schedId = (int)$pdo->lastInsertId();
    }

    echo json_encode([
        'success'     => true,
        'sched_id'    => $schedId,
        'driver_name' => $info['full_name']  ?? '',
        'unit_code'   => $info['unit_code']  ?? '',
        'route_name'  => $info['route_name'] ?? '',
    ]);

} catch (Throwable $e) {
    error_log('create_schedule: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}