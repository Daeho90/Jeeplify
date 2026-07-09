<?php
// operator/actions/route_logs.php
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['account_id']) || ($_SESSION['role'] ?? '') !== 'operator') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../../db.php';

$unit_id = (int)($_GET['unit_id'] ?? 0);
if (!$unit_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid unit.']);
    exit;
}

try {
    // trips table: jeepney_id, route_name (varchar), departure_time (datetime), status
    // No arrival_time or passenger_count columns in this schema
    $stmt = $pdo->prepare("
        SELECT
            t.id,
            t.status,
            DATE_FORMAT(t.departure_time, '%h:%i %p')  AS departure_time,
            DATE_FORMAT(t.departure_time, '%b %d, %Y') AS trip_date,
            t.route_name,
            NULL AS arrival_time,
            0    AS passenger_count,
            NULL AS notes
        FROM   trips t
        WHERE  t.jeepney_id = ?
        ORDER BY t.departure_time DESC
        LIMIT 50
    ");
    $stmt->execute([$unit_id]);
    $trips = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'trips' => $trips]);

} catch (Throwable $e) {
    error_log('route_logs: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}