<?php
// operator/actions/schedule_action.php
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['account_id']) || ($_SESSION['role'] ?? '') !== 'operator') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../../db.php';

$raw   = file_get_contents('php://input');
$d     = json_decode($raw, true);
$id    = (int)($d['id']            ?? 0);
$first = trim($d['first_trip']     ?? '');
$last  = trim($d['last_trip']      ?? '');
$freq  = (int)($d['frequency_min'] ?? 0);

if (!$id || !$first || !$last || $freq <= 0) {
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        UPDATE schedules
        SET first_trip = ?, last_trip = ?, frequency_min = ?
        WHERE id = ?
    ");
    $stmt->execute([$first, $last, $freq, $id]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Schedule not found or no change.']);
        exit;
    }

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    error_log('schedule_action: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}