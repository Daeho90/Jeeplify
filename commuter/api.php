<?php
// ════════════════════════════════════════════════════════════
//  JEEPLIFY BCD — commuter/api.php
// ════════════════════════════════════════════════════════════
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once '../db.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'live_jeepneys':   handle_live_jeepneys($pdo); break;
    case 'routes':          handle_routes($pdo); break;
    case 'my_bookings':     handle_my_bookings($pdo); break;
    case 'create_booking':  handle_create_booking($pdo); break;
    case 'cancel_booking':  handle_cancel_booking($pdo); break;
    case 'update_location': handle_update_location($pdo); break;
    case 'logout':          handle_logout(); break;
    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
}

// ════════════════════════════════════════════════════════════
//  POST ?action=update_location
// ════════════════════════════════════════════════════════════
function handle_update_location(PDO $pdo): void {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $accountId = null;

    // 1) Session
    if (!empty($_SESSION['account_id'])) {
        $accountId = (int) $_SESSION['account_id'];
    }

    // 2) Token header fallback
    if (!$accountId) {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_DRIVER_TOKEN'] ?? '';
        $token  = preg_replace('/^Bearer\s+/i', '', trim($header));
        if ($token) {
            $s = $pdo->prepare("
                SELECT account_id FROM driver_tokens
                WHERE token = ? AND expires_at > NOW()
                LIMIT 1
            ");
            try {
                $s->execute([$token]);
                $row = $s->fetch();
                if ($row) $accountId = (int) $row['account_id'];
            } catch (PDOException $e) {
                // driver_tokens table may not exist — fall through
            }
        }
    }

    // NOTE: body account_id fallback removed — security risk

    if (!$accountId) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => 'Not authenticated.']);
        return;
    }

    $lat       = isset($body['lat'])         ? (float)  $body['lat']         : null;
    $lng       = isset($body['lng'])         ? (float)  $body['lng']         : null;
    $direction = isset($body['direction'])   ? (string) $body['direction']   : null;
    $status    = isset($body['status'])      ? (string) $body['status']      : null;
    $etaMin    = isset($body['eta_minutes']) ? (int)    $body['eta_minutes'] : null;
    $etaDist   = isset($body['eta_dist_km']) ? (float)  $body['eta_dist_km'] : null;

    if ($lat === null || $lng === null || !is_finite($lat) || !is_finite($lng)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'lat and lng are required.']);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO driver_locations
                (account_id, lat, lng, direction, status, eta_minutes, eta_dist_km, updated_at)
            VALUES
                (:account_id, :lat, :lng, :direction, :status, :eta_minutes, :eta_dist_km, NOW())
            ON DUPLICATE KEY UPDATE
                lat         = VALUES(lat),
                lng         = VALUES(lng),
                direction   = VALUES(direction),
                status      = VALUES(status),
                eta_minutes = VALUES(eta_minutes),
                eta_dist_km = VALUES(eta_dist_km),
                updated_at  = NOW()
        ");

        $stmt->execute([
            ':account_id'  => $accountId,
            ':lat'         => $lat,
            ':lng'         => $lng,
            ':direction'   => $direction,
            ':status'      => $status,
            ':eta_minutes' => $etaMin,
            ':eta_dist_km' => $etaDist,
        ]);

        echo json_encode(['ok' => true]);

    } catch (PDOException $e) {
        error_log('api/update_location: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    }
}

// ════════════════════════════════════════════════════════════
//  POST ?action=logout
// ════════════════════════════════════════════════════════════
function handle_logout(): void {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }

    session_destroy();
    echo json_encode(['ok' => true]);
}

// ════════════════════════════════════════════════════════════
//  GET ?action=live_jeepneys  (public)
// ════════════════════════════════════════════════════════════
function handle_live_jeepneys(PDO $pdo): void {
    try {
        $stmt = $pdo->query("
            SELECT
                dl.account_id,
                dl.lat,
                dl.lng,
                dl.direction,
                dl.status           AS driver_status,
                dl.eta_minutes,
                dl.eta_dist_km,
                dl.updated_at,
                (dl.updated_at < NOW() - INTERVAL 2 MINUTE) AS stale,
                j.unit_code,
                j.plate_no,
                j.status            AS jeepney_status,
                r.name              AS route_name,
                dp.full_name        AS driver_name,
                t.status            AS trip_status,
                DATE_FORMAT(t.departure_time, '%h:%i %p') AS departure_time
            FROM driver_locations dl
            JOIN driver_profiles  dp ON dp.account_id = dl.account_id
            JOIN driver_jeepney   dj ON dj.driver_id  = dp.id
            JOIN jeepneys          j ON j.id           = dj.jeepney_id
            LEFT JOIN routes       r ON r.id            = j.route_id
            LEFT JOIN trips        t ON t.id = (
                SELECT id FROM trips
                WHERE jeepney_id = j.id
                  AND status IN ('active','scheduled')
                  AND DATE(departure_time) = CURDATE()
                ORDER BY departure_time ASC
                LIMIT 1
            )
            WHERE dl.updated_at >= NOW() - INTERVAL 10 MINUTE
              AND j.status != 'offline'
            ORDER BY dl.updated_at DESC
        ");

        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['lat']         = (float) $row['lat'];
            $row['lng']         = (float) $row['lng'];
            $row['eta_minutes'] = $row['eta_minutes'] !== null ? (int)   $row['eta_minutes'] : null;
            $row['eta_dist_km'] = $row['eta_dist_km'] !== null ? (float) $row['eta_dist_km'] : null;
            $row['stale']       = (bool)  $row['stale'];
            $row['display_status'] = $row['driver_status']
                ?: ($row['trip_status'] === 'active'    ? 'on_route' : null)
                ?: ($row['trip_status'] === 'scheduled' ? 'idle'     : null)
                ?: 'idle';
        }
        unset($row);

        echo json_encode(['ok' => true, 'jeepneys' => $rows]);

    } catch (PDOException $e) {
        error_log('api/live_jeepneys: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Query failed']);
    }
}

// ════════════════════════════════════════════════════════════
//  GET ?action=routes  (public)
// ════════════════════════════════════════════════════════════
function handle_routes(PDO $pdo): void {
    try {
        $stmt = $pdo->query("
            SELECT
                r.id AS route_id, r.name, r.description,
                j.id AS jeepney_id, j.unit_code, j.plate_no, j.capacity,
                j.status AS jeepney_status,
                dl.account_id, dl.lat, dl.lng, dl.direction,
                dl.status AS driver_status, dl.eta_minutes, dl.eta_dist_km,
                (dl.updated_at < NOW() - INTERVAL 2 MINUTE) AS stale,
                t.status AS trip_status,
                DATE_FORMAT(t.departure_time, '%h:%i %p') AS departure_time
            FROM routes r
            LEFT JOIN jeepneys j          ON j.route_id     = r.id
                                          AND j.status      != 'offline'
            LEFT JOIN driver_jeepney dj   ON dj.jeepney_id  = j.id
            LEFT JOIN driver_profiles dp  ON dp.id          = dj.driver_id
            LEFT JOIN driver_locations dl ON dl.account_id  = dp.account_id
                                          AND dl.updated_at >= NOW() - INTERVAL 10 MINUTE
            LEFT JOIN trips t ON t.id = (
                SELECT id FROM trips
                WHERE jeepney_id = j.id
                  AND status IN ('active','scheduled')
                  AND DATE(departure_time) = CURDATE()
                ORDER BY departure_time ASC
                LIMIT 1
            )
            ORDER BY r.name, j.unit_code
        ");

        $rows = $stmt->fetchAll();

        $routes = [];
        foreach ($rows as $row) {
            $rid = (int) $row['route_id'];
            if (!isset($routes[$rid])) {
                $routes[$rid] = [
                    'id'          => $rid,
                    'name'        => $row['name'],
                    'description' => $row['description'],
                    'live_count'  => 0,
                    'jeepneys'    => [],
                ];
            }
            if ($row['jeepney_id'] !== null && $row['account_id'] !== null) {
                $routes[$rid]['jeepneys'][] = [
                    'id'             => (int) $row['jeepney_id'],
                    'account_id'     => (int) $row['account_id'],
                    'unit_code'      => $row['unit_code'],
                    'plate_no'       => $row['plate_no'],
                    'capacity'       => $row['capacity'] !== null ? (int) $row['capacity'] : 16,
                    'lat'            => (float) $row['lat'],
                    'lng'            => (float) $row['lng'],
                    'direction'      => $row['direction'],
                    'display_status' => $row['driver_status']
                                            ?: ($row['trip_status'] === 'active'    ? 'on_route' : null)
                                            ?: ($row['trip_status'] === 'scheduled' ? 'idle'     : null)
                                            ?: 'idle',
                    'eta_minutes'    => $row['eta_minutes'] !== null ? (int)   $row['eta_minutes'] : null,
                    'eta_dist_km'    => $row['eta_dist_km'] !== null ? (float) $row['eta_dist_km'] : null,
                    'departure_time' => $row['departure_time'],
                    'stale'          => (bool) $row['stale'],
                ];
                $routes[$rid]['live_count']++;
            }
        }

        echo json_encode(['ok' => true, 'routes' => array_values($routes)]);

    } catch (PDOException $e) {
        error_log('api/routes: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Query failed']);
    }
}

// ════════════════════════════════════════════════════════════
//  GET ?action=my_bookings  (auth required)
// ════════════════════════════════════════════════════════════
function handle_my_bookings(PDO $pdo): void {
    $accountId = require_login();

    try {
        $stmt = $pdo->prepare("
            SELECT
                b.id, b.passenger_name, b.passenger_count,
                DATE_FORMAT(b.booking_date,'%b %d, %Y') AS booking_date,
                DATE_FORMAT(b.booking_time,'%h:%i %p')  AS booking_time,
                b.pickup_location, b.dropoff_location, b.status, b.notes,
                j.unit_code, r.name AS route_name
            FROM bookings b
            JOIN jeepneys j      ON j.id = b.jeepney_id
            LEFT JOIN routes r   ON r.id = b.route_id
            WHERE b.commuter_id = ?
            ORDER BY FIELD(b.status,'pending','approved','declined','cancelled'), b.created_at DESC
            LIMIT 25
        ");
        $stmt->execute([$accountId]);
        $bookings = $stmt->fetchAll();

        foreach ($bookings as &$b) {
            $b['id']              = (int) $b['id'];
            $b['passenger_count'] = (int) $b['passenger_count'];
        }
        unset($b);

        echo json_encode(['ok' => true, 'bookings' => $bookings]);

    } catch (PDOException $e) {
        error_log('api/my_bookings: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Query failed']);
    }
}

// ════════════════════════════════════════════════════════════
//  POST ?action=create_booking  (auth required, JSON body)
// ════════════════════════════════════════════════════════════
function handle_create_booking(PDO $pdo): void {
    $accountId = require_login();

    $d = json_decode(file_get_contents('php://input'), true) ?: [];

    $routeId         = isset($d['route_id']) && $d['route_id'] !== '' ? (int) $d['route_id'] : null;
    $jeepneyId       = (int) ($d['jeepney_id'] ?? 0);
    $passengerName   = trim((string) ($d['passenger_name'] ?? ''));
    $passengerCount  = (int) ($d['passenger_count'] ?? 1);
    $bookingDate     = trim((string) ($d['booking_date'] ?? ''));
    $bookingTime     = trim((string) ($d['booking_time'] ?? ''));
    $pickupLocation  = trim((string) ($d['pickup_location'] ?? ''));
    $dropoffLocation = trim((string) ($d['dropoff_location'] ?? ''));
    $notes           = trim((string) ($d['notes'] ?? ''));

    $errors = [];
    if (!$jeepneyId)                                  $errors[] = 'Please choose a jeepney unit.';
    if ($passengerName === '')                        $errors[] = 'Passenger name is required.';
    if ($passengerCount < 1 || $passengerCount > 99) $errors[] = 'Passenger count must be between 1 and 99.';
    if ($pickupLocation === '')                       $errors[] = 'Pickup location is required.';
    if ($dropoffLocation === '')                      $errors[] = 'Drop-off location is required.';

    $dateObj = DateTime::createFromFormat('Y-m-d', $bookingDate);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $bookingDate) {
        $errors[] = 'Please choose a valid booking date.';
    } else {
        $today = new DateTime('today');
        if ($dateObj < $today) $errors[] = 'Booking date cannot be in the past.';
    }

    $timeObj = DateTime::createFromFormat('H:i', $bookingTime)
            ?: DateTime::createFromFormat('H:i:s', $bookingTime);
    if (!$timeObj) $errors[] = 'Please choose a valid booking time.';

    if ($errors) {
        echo json_encode(['ok' => false, 'message' => implode(' ', $errors)]);
        return;
    }

    try {
        $check = $pdo->prepare("SELECT id, route_id, capacity, status FROM jeepneys WHERE id = ? LIMIT 1");
        $check->execute([$jeepneyId]);
        $jeepney = $check->fetch();

        if (!$jeepney) {
            echo json_encode(['ok' => false, 'message' => 'Selected jeepney could not be found.']);
            return;
        }
        if ($jeepney['status'] === 'offline') {
            echo json_encode(['ok' => false, 'message' => 'That unit is currently offline. Please pick another.']);
            return;
        }
        // Capacity check
        if ($jeepney['capacity'] && $passengerCount > (int) $jeepney['capacity']) {
            echo json_encode(['ok' => false, 'message' => 'Passenger count exceeds jeepney capacity of ' . $jeepney['capacity'] . '.']);
            return;
        }
        if ($routeId && (int) $jeepney['route_id'] !== $routeId) {
            echo json_encode(['ok' => false, 'message' => 'Selected unit does not belong to the chosen route.']);
            return;
        }
        if (!$routeId) $routeId = $jeepney['route_id'] ? (int) $jeepney['route_id'] : null;

        $stmt = $pdo->prepare("
            INSERT INTO bookings
                (commuter_id, jeepney_id, route_id, passenger_name, passenger_count,
                 booking_date, booking_time, pickup_location, dropoff_location, notes, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([
            $accountId, $jeepneyId, $routeId, $passengerName, $passengerCount,
            $bookingDate, $bookingTime, $pickupLocation, $dropoffLocation,
            $notes !== '' ? $notes : null,
        ]);

        echo json_encode(['ok' => true, 'booking_id' => (int) $pdo->lastInsertId()]);

    } catch (PDOException $e) {
        error_log('api/create_booking: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Could not save booking. Please try again.']);
    }
}

// ════════════════════════════════════════════════════════════
//  POST ?action=cancel_booking  (auth required, JSON body: {id})
// ════════════════════════════════════════════════════════════
function handle_cancel_booking(PDO $pdo): void {
    $accountId = require_login();

    $d  = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int) ($d['id'] ?? 0);

    if (!$id) {
        echo json_encode(['ok' => false, 'message' => 'Invalid request.']);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE bookings
            SET status = 'cancelled'
            WHERE id = ? AND commuter_id = ? AND status = 'pending'
        ");
        $stmt->execute([$id, $accountId]);

        if ($stmt->rowCount() === 0) {
            echo json_encode(['ok' => false, 'message' => 'Booking could not be cancelled (it may already be processed).']);
            return;
        }

        echo json_encode(['ok' => true]);

    } catch (PDOException $e) {
        error_log('api/cancel_booking: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Server error.']);
    }
}

// ════════════════════════════════════════════════════════════
//  Helper — require an active commuter session or stop
// ════════════════════════════════════════════════════════════
function require_login(): int {
    $accountId = $_SESSION['account_id'] ?? null;
    if (!$accountId) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => 'login_required']);
        exit;
    }
    return (int) $accountId;
}