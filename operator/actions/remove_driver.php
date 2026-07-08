<?php
// ════════════════════════════════════════════════════════════
//  JEEPLIFY BCD — operator/actions/remove_driver.php
//  POST  { driver_id: int }  → { success, message }
// ════════════════════════════════════════════════════════════
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['account_id']) || ($_SESSION['role'] ?? '') !== 'operator') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../../db.php';

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$driverId = (int) ($body['driver_id'] ?? 0);

if (!$driverId) {
    echo json_encode(['success' => false, 'message' => 'Invalid driver ID']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Get account_id so we can deactivate the login
    $row = $pdo->prepare("SELECT account_id FROM driver_profiles WHERE id = ?");
    $row->execute([$driverId]);
    $accountId = (int) ($row->fetchColumn() ?: 0);

    // 2. Remove jeepney assignment
    $pdo->prepare("DELETE FROM driver_jeepney WHERE driver_id = ?")->execute([$driverId]);

    // 3. Deactivate account (soft delete — keeps history intact)
    if ($accountId) {
        $pdo->prepare("UPDATE accounts SET is_active = 0 WHERE id = ?")->execute([$accountId]);
    }

    // 4. Remove driver profile
    $pdo->prepare("DELETE FROM driver_profiles WHERE id = ?")->execute([$driverId]);

    $pdo->commit();
    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('remove_driver: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}