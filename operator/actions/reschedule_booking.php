<?php
// operator/actions/reschedule_booking.php
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['account_id']) || ($_SESSION['role'] ?? '') !== 'operator') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../../db.php';

$raw  = file_get_contents('php://input');
$d    = json_decode($raw, true);
$id   = (int)($d['id']           ?? 0);
$date = trim($d['booking_date']  ?? '');
$time = trim($d['booking_time']  ?? '');

if (!$id || !$date || !$time) {
    echo json_encode(['success' => false, 'message' => 'Booking ID, date and time are required.']);
    exit;
}

// Validate date/time formats
$dateObj = DateTime::createFromFormat('Y-m-d', $date);
$timeObj = DateTime::createFromFormat('H:i',   $time);
if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format.']);
    exit;
}
if (!$timeObj) {
    echo json_encode(['success' => false, 'message' => 'Invalid time format.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        UPDATE bookings
        SET booking_date = ?, booking_time = ?, status = 'approved'
        WHERE id = ?
    ");
    $stmt->execute([$date, $time . ':00', $id]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        exit;
    }

    echo json_encode([
        'success'      => true,
        'booking_date' => $dateObj->format('M d, Y'),
        'booking_time' => $timeObj->format('h:i A'),
    ]);

} catch (Throwable $e) {
    error_log('reschedule_booking: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
