<?php
// operator/actions/api.php
// Consolidated operator actions endpoint — replaces the separate
// booking_action.php, trip_action.php, reschedule_booking.php,
// schedule_action.php, edit_driver.php, route_logs.php,
// create_schedule.php, and create_driver.php files.
//
// Called as: actions/api.php?do=<name>
//   - POST endpoints send their usual JSON body unchanged.
//   - route_logs is a GET endpoint: actions/api.php?do=route_logs&unit_id=123

require_once '../../session_init.php';
header('Content-Type: application/json');

if (empty($_SESSION['account_id']) || ($_SESSION['role'] ?? '') !== 'operator') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../../db.php';

$do = $_GET['do'] ?? '';

$handlers = [
    'booking_action'      => 'handle_booking_action',
    'trip_action'         => 'handle_trip_action',
    'reschedule_booking'  => 'handle_reschedule_booking',
    'schedule_action'     => 'handle_schedule_action',
    'edit_driver'         => 'handle_edit_driver',
    'route_logs'          => 'handle_route_logs',
    'create_schedule'     => 'handle_create_schedule',
    'create_driver'       => 'handle_create_driver',
    'remove_driver'       => 'handle_remove_driver',
];

if (!isset($handlers[$do])) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

$handlers[$do]($pdo);

// ════════════════════════════════════════════════════════════
// booking_action — approve / decline / cancel a booking
// ════════════════════════════════════════════════════════════
function handle_booking_action(PDO $pdo): void {
    $raw    = file_get_contents('php://input');
    $d      = json_decode($raw, true);
    $id     = (int)($d['id']     ?? 0);
    $action = trim($d['action']  ?? '');

    if (!$id || !in_array($action, ['approve', 'decline', 'cancel'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        return;
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
            return;
        }

        echo json_encode(['success' => true, 'status' => $newStatus]);

    } catch (Throwable $e) {
        error_log('api/booking_action: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error. Please try again later.']);
    }
}

// ════════════════════════════════════════════════════════════
// trip_action — dispatch a new trip
// ════════════════════════════════════════════════════════════
function handle_trip_action(PDO $pdo): void {
    $raw = file_get_contents('php://input');
    $d   = json_decode($raw, true);

    $driver_id = (int)($d['driver_id'] ?? 0);
    $unit_id   = (int)($d['unit_id']   ?? 0);   // alternate: resolve via jeepney id
    $departure  = trim($d['departure'] ?? '');
    $route_id   = (int)($d['route_id'] ?? 0);

    if (!$departure || !$route_id || (!$driver_id && !$unit_id)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        return;
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
                return;
            }
            $jeepney_id = (int) $dj['jeepney_id'];
        }

        if (!$jeepney_id) {
            echo json_encode(['success' => false, 'message' => 'Could not resolve jeepney.']);
            return;
        }

        // Get route name
        $routeStmt = $pdo->prepare("SELECT name FROM routes WHERE id = ? LIMIT 1");
        $routeStmt->execute([$route_id]);
        $route = $routeStmt->fetch();
        if (!$route) {
            echo json_encode(['success' => false, 'message' => 'Route not found.']);
            return;
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
        error_log('api/trip_action: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error. Please try again later.']);
    }
}

// ════════════════════════════════════════════════════════════
// reschedule_booking — change date/time of an existing booking
// ════════════════════════════════════════════════════════════
function handle_reschedule_booking(PDO $pdo): void {
    $raw  = file_get_contents('php://input');
    $d    = json_decode($raw, true);
    $id   = (int)($d['id']           ?? 0);
    $date = trim($d['booking_date']  ?? '');
    $time = trim($d['booking_time']  ?? '');

    if (!$id || !$date || !$time) {
        echo json_encode(['success' => false, 'message' => 'Booking ID, date and time are required.']);
        return;
    }

    // Validate date/time formats
    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    $timeObj = DateTime::createFromFormat('H:i',   $time);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
        echo json_encode(['success' => false, 'message' => 'Invalid date format.']);
        return;
    }
    if (!$timeObj) {
        echo json_encode(['success' => false, 'message' => 'Invalid time format.']);
        return;
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
            return;
        }

        echo json_encode([
            'success'      => true,
            'booking_date' => $dateObj->format('M d, Y'),
            'booking_time' => $timeObj->format('h:i A'),
        ]);

    } catch (Throwable $e) {
        error_log('api/reschedule_booking: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error. Please try again later.']);
    }
}

// ════════════════════════════════════════════════════════════
// schedule_action — edit an existing schedule's timing
// ════════════════════════════════════════════════════════════
function handle_schedule_action(PDO $pdo): void {
    $raw   = file_get_contents('php://input');
    $d     = json_decode($raw, true);
    $id    = (int)($d['id']            ?? 0);
    $first = trim($d['first_trip']     ?? '');
    $last  = trim($d['last_trip']      ?? '');
    $freq  = (int)($d['frequency_min'] ?? 0);

    if (!$id || !$first || !$last || $freq <= 0) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        return;
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
            return;
        }

        echo json_encode(['success' => true]);

    } catch (Throwable $e) {
        error_log('api/schedule_action: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error. Please try again later.']);
    }
}

// ════════════════════════════════════════════════════════════
// edit_driver — update an existing driver's profile/assignment/password
// ════════════════════════════════════════════════════════════
function handle_edit_driver(PDO $pdo): void {
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
        return;
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
        error_log('api/edit_driver: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error. Please try again later.']);
    }
}

// ════════════════════════════════════════════════════════════
// route_logs — fetch recent trip logs for a unit (GET)
// ════════════════════════════════════════════════════════════
function handle_route_logs(PDO $pdo): void {
    $unit_id = (int)($_GET['unit_id'] ?? 0);
    if (!$unit_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid unit.']);
        return;
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
        error_log('api/route_logs: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error. Please try again later.']);
    }
}

// ════════════════════════════════════════════════════════════
// create_schedule — create/update a schedule for a driver
// ════════════════════════════════════════════════════════════
function handle_create_schedule(PDO $pdo): void {
    $raw       = file_get_contents('php://input');
    $d         = json_decode($raw, true);
    $driver_id = (int)($d['driver_id']     ?? 0);
    $first     = trim($d['first_trip']     ?? '');
    $last      = trim($d['last_trip']      ?? '');
    $freq      = (int)($d['frequency_min'] ?? 20);

    if (!$driver_id || !$first || !$last) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        return;
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
            return;
        }

        $jeepney_id = (int)($info['jeepney_id'] ?? 0);
        if (!$jeepney_id) {
            echo json_encode(['success' => false, 'message' => 'This driver has no jeepney assigned. Assign a jeepney first.']);
            return;
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
        error_log('api/create_schedule: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error. Please try again later.']);
    }
}

// ════════════════════════════════════════════════════════════
// create_driver — create a new driver account + profile
// ════════════════════════════════════════════════════════════
function handle_create_driver(PDO $pdo): void {
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
        return;
    }

    try {
        $pdo->beginTransaction();

        // 1. Check username uniqueness (email column stores username here)
        $check = $pdo->prepare("SELECT id FROM accounts WHERE email = ? LIMIT 1");
        $check->execute([$username]);
        if ($check->fetch()) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Username already taken.']);
            return;
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
        error_log('api/create_driver: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error. Please try again later.']);
    }
}

// ════════════════════════════════════════════════════════════
// remove_driver — deactivate account + remove driver profile/assignment
// ════════════════════════════════════════════════════════════
function handle_remove_driver(PDO $pdo): void {
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $driverId = (int) ($body['driver_id'] ?? 0);

    if (!$driverId) {
        echo json_encode(['success' => false, 'message' => 'Invalid driver ID']);
        return;
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
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('api/remove_driver: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error. Please try again later.']);
    }
}