<?php
// operator/actions/booking_action.php
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['account_id']) || ($_SESSION['role'] ?? '') !== 'operator') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../../db.php';

$raw    = file_get_contents('php://input');
$d      = json_decode($raw, true);
$id     = (int)($d['id']     ?? 0);
$action = trim($d['action']  ?? '');

if (!$id || !in_array($action, ['approve', 'decline', 'cancel'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$statusMap = [
    'approve' => 'approved',
    'decline' => 'declined',
    'cancel'  => 'cancelled',
];
$newStatus = $statusMap[$action];

try {
    $stmt = $pdo->prepare("UPDATE bookings SET status = ? WHERE id = ?");
    $stmt->execute([$newStatus, $id]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        exit;
    }

    echo json_encode(['success' => true, 'status' => $newStatus]);

} catch (Throwable $e) {
    error_log('booking_action: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}