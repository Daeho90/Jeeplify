<?php
// operator/actions/edit_driver.php
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['account_id']) || ($_SESSION['role'] ?? '') !== 'operator') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../../db.php';

$raw       = file_get_contents('php://input');
$d         = json_decode($raw, true);
$id        = (int)($d['id']             ?? 0);
$full_name = trim($d['full_name']       ?? '');
$license   = trim($d['license_number']  ?? '');
$unit_id   = (int)($d['unit_id']        ?? 0);
$route_id  = (int)($d['route_id']       ?? 0);
$password  = $d['password'] ?? '';

if (!$id || !$full_name) {
    echo json_encode(['success' => false, 'message' => 'Driver ID and name are required.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Update driver_profiles name (and license if provided)
    if ($license) {
        $pdo->prepare("UPDATE driver_profiles SET full_name = ?, license_number = ? WHERE id = ?")
            ->execute([$full_name, $license, $id]);
    } else {
        $pdo->prepare("UPDATE driver_profiles SET full_name = ? WHERE id = ?")
            ->execute([$full_name, $id]);
    }

    // 2. Update jeepney assignment if a unit was selected
    if ($unit_id) {
        // Remove any existing assignment for this driver
        $pdo->prepare("DELETE FROM driver_jeepney WHERE driver_id = ?")->execute([$id]);
        // Also remove any other driver from this unit
        $pdo->prepare("DELETE FROM driver_jeepney WHERE jeepney_id = ?")->execute([$unit_id]);
        // Insert new assignment
        $pdo->prepare("INSERT INTO driver_jeepney (driver_id, jeepney_id) VALUES (?, ?)")
            ->execute([$id, $unit_id]);
    }

    // 3. Update route on the assigned jeepney if route was selected
    if ($route_id && $unit_id) {
        $pdo->prepare("UPDATE jeepneys SET route_id = ? WHERE id = ?")
            ->execute([$route_id, $unit_id]);
    }

    // 4. Update password on the accounts table if provided
    if ($password) {
        // Get account_id from driver_profiles
        $accRow = $pdo->prepare("SELECT account_id FROM driver_profiles WHERE id = ?");
        $accRow->execute([$id]);
        $acc = $accRow->fetch();
        if ($acc) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE accounts SET password_hash = ? WHERE id = ?")
                ->execute([$hash, $acc['account_id']]);
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('edit_driver: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}