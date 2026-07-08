<?php
// operator/actions/create_driver.php
session_start();
header('Content-Type: application/json');

// Auth guard
if (empty($_SESSION['account_id']) || ($_SESSION['role'] ?? '') !== 'operator') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../../db.php';

$raw = file_get_contents('php://input');
$d   = json_decode($raw, true);

$first    = trim($d['first_name'] ?? '');
$last     = trim($d['last_name']  ?? '');
$license  = trim($d['license']    ?? '');
$unit_id  = (int)($d['unit_id']   ?? 0);
$route_id = (int)($d['route_id']  ?? 0);
$username = trim($d['username']   ?? '');
$password = $d['password'] ?? '';

// Validate
if (!$first || !$last || !$license || !$unit_id || !$route_id || !$username || !$password) {
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Check username uniqueness (email column stores username here)
    $check = $pdo->prepare("SELECT id FROM accounts WHERE email = ? LIMIT 1");
    $check->execute([$username]);
    if ($check->fetch()) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Username already taken.']);
        exit;
    }

    // 2. Get driver role_id
    $roleStmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'driver' LIMIT 1");
    $roleStmt->execute();
    $role = $roleStmt->fetch();
    if (!$role) {
        // Fallback: look for role_id = 2 (common driver role)
        $roleId = 2;
    } else {
        $roleId = (int)$role['id'];
    }

    // 3. Create account
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $accStmt = $pdo->prepare("
        INSERT INTO accounts (email, password_hash, role_id, is_active)
        VALUES (?, ?, ?, 1)
    ");
    $accStmt->execute([$username, $hash, $roleId]);
    $accountId = (int)$pdo->lastInsertId();

    // 4. Create driver_profile (full_name, license_number — no route_id column here)
    $profStmt = $pdo->prepare("
        INSERT INTO driver_profiles (account_id, full_name, license_number)
        VALUES (?, ?, ?)
    ");
    $profStmt->execute([$accountId, "$last, $first", $license]);
    $driverProfileId = (int)$pdo->lastInsertId();

    // 5. Assign jeepney via driver_jeepney
    $djStmt = $pdo->prepare("
        INSERT INTO driver_jeepney (driver_id, jeepney_id)
        VALUES (?, ?)
    ");
    $djStmt->execute([$driverProfileId, $unit_id]);

    // 6. Update jeepney's route (route belongs on jeepneys table)
    $jStmt = $pdo->prepare("UPDATE jeepneys SET route_id = ? WHERE id = ?");
    $jStmt->execute([$route_id, $unit_id]);

    // 7. Fetch unit_code and route_name for response
    $infoStmt = $pdo->prepare("
        SELECT j.unit_code, r.name AS route_name
        FROM   jeepneys j
        LEFT JOIN routes r ON r.id = j.route_id
        WHERE  j.id = ?
    ");
    $infoStmt->execute([$unit_id]);
    $info = $infoStmt->fetch();

    $pdo->commit();

    echo json_encode([
        'success'    => true,
        'driver_id'  => $driverProfileId,
        'unit_code'  => $info['unit_code']  ?? '',
        'route_name' => $info['route_name'] ?? '',
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('create_driver: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}