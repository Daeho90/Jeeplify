<?php
/**
 * Jeeplify BCD – AI Agent Endpoint (Gemini version)
 * File: /api/agent.php
 *
 * Receives a commuter message, fetches live DB context,
 * calls Google Gemini, and returns a JSON reply.
 *
 * Requirements:
 *   - PHP 7.4+
 *   - Your existing DB connection file
 *   - GEMINI_API_KEY set as an environment variable on Render
 */

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // tighten in production

// ── 1. Load your existing DB connection ──────────────────────────────────────
require_once __DIR__ . '/../db.php';
// Expects a PDO instance in $pdo

// ── 2. Your Gemini API key ────────────────────────────────────────────────────
// Set GEMINI_API_KEY as an environment variable in the Render dashboard
// (Service → Environment tab). Never hardcode real keys in source files.
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: '');
define('GEMINI_MODEL',   'gemini-2.0-flash');
define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent');

if (GEMINI_API_KEY === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Server misconfigured: GEMINI_API_KEY is not set.']);
    exit;
}

// ── 2b. Daily question limit ──────────────────────────────────────────────────
// Logged-in commuters are tracked by account_id; guests by their session id.
define('DAILY_QUESTION_LIMIT', 5);

$accountId = $_SESSION['account_id'] ?? null;
$guestKey  = $accountId ? null : session_id();
$today     = date('Y-m-d');

function getTodayUsage(PDO $pdo, ?int $accountId, ?string $guestKey, string $today): int {
    if ($accountId) {
        $stmt = $pdo->prepare('SELECT question_count FROM agent_usage WHERE account_id = ? AND usage_date = ? LIMIT 1');
        $stmt->execute([$accountId, $today]);
    } else {
        $stmt = $pdo->prepare('SELECT question_count FROM agent_usage WHERE guest_key = ? AND usage_date = ? LIMIT 1');
        $stmt->execute([$guestKey, $today]);
    }
    $row = $stmt->fetch();
    return $row ? (int) $row['question_count'] : 0;
}

function incrementTodayUsage(PDO $pdo, ?int $accountId, ?string $guestKey, string $today): void {
    if ($accountId) {
        $stmt = $pdo->prepare('
            INSERT INTO agent_usage (account_id, usage_date, question_count)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE question_count = question_count + 1
        ');
        $stmt->execute([$accountId, $today]);
    } else {
        $stmt = $pdo->prepare('
            INSERT INTO agent_usage (guest_key, usage_date, question_count)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE question_count = question_count + 1
        ');
        $stmt->execute([$guestKey, $today]);
    }
}

try {
    $usedToday = getTodayUsage($pdo, $accountId, $guestKey, $today);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    exit;
}

if ($usedToday >= DAILY_QUESTION_LIMIT) {
    http_response_code(429);
    echo json_encode([
        'limit_reached' => true,
        'message' => "Naabot na nimo ang " . DAILY_QUESTION_LIMIT . " free questions para sa subong nga adlaw. Balik ka lang buas para makapangutana pa 🙂",
    ]);
    exit;
}

// ── 3. Validate input ─────────────────────────────────────────────────────────
$input   = json_decode(file_get_contents('php://input'), true);
$message = trim($input['message'] ?? '');
$history = $input['history'] ?? []; // array of {role, content} from the frontend

if ($message === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Empty message.']);
    exit;
}

// ── 4. Fetch live data from your DB ──────────────────────────────────────────

/**
 * Returns active jeepneys with their latest GPS position.
 * Matches the real Jeeplify schema:
 *   jeepneys, driver_jeepney, driver_locations, driver_profiles, routes
 */
function getActiveDrivers(PDO $pdo): array {
    $stmt = $pdo->query("
        SELECT
            j.unit_code,
            j.plate_no,
            r.name           AS route_name,
            dp.full_name     AS driver_name,
            dl.lat,
            dl.lng,
            dl.status,
            dl.eta_minutes,
            dl.eta_dist_km,
            dl.updated_at
        FROM driver_jeepney dj
        JOIN jeepneys j          ON j.id = dj.jeepney_id
        JOIN driver_locations dl ON dl.account_id = dj.driver_id
        LEFT JOIN driver_profiles dp ON dp.account_id = dj.driver_id
        LEFT JOIN routes r           ON r.id = j.route_id
        WHERE dl.updated_at >= NOW() - INTERVAL 10 MINUTE
        ORDER BY dl.updated_at DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Returns all route definitions.
 */
function getRoutes(PDO $pdo): array {
    $stmt = $pdo->query("
        SELECT id, name, description
        FROM routes
        ORDER BY name
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Returns upcoming bookings for context (optional, useful for booking assistant).
 */
function getActiveBookings(PDO $pdo): array {
    $stmt = $pdo->query("
        SELECT b.id, b.pickup_location, b.dropoff_location,
               b.booking_date, b.booking_time, b.status, r.name AS route_name
        FROM bookings b
        LEFT JOIN routes r ON r.id = b.route_id
        WHERE b.status IN ('pending', 'approved')
          AND b.booking_date >= CURDATE()
        ORDER BY b.booking_date ASC, b.booking_time ASC
        LIMIT 20
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Run the queries
try {
    $activeDrivers  = getActiveDrivers($pdo);
    $routes         = getRoutes($pdo);
    $activeBookings = getActiveBookings($pdo);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    exit;
}

// ── 5. Build the system prompt ────────────────────────────────────────────────
$now = date('Y-m-d H:i:s');

// Encode JSON BEFORE building the heredoc, so the values actually interpolate.
$activeDriversJson = json_encode($activeDrivers,  JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
$routesJson        = json_encode($routes,         JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
$bookingsJson      = json_encode($activeBookings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

$systemPrompt = <<<PROMPT
You are Jeep 🚌, a friendly AI assistant built into Jeeplify BCD — a real-time jeepney tracking app for Bacolod City, Philippines.

Your job is to help commuters:
- Find the right jeepney route to reach their destination
- Check which jeepneys are currently active and where they are
- Estimate waiting or travel time
- Complete a booking via chat
- Answer any questions about Bacolod City jeepney routes

Current date and time: {$now}

--- ACTIVE JEEPNEYS RIGHT NOW ---
```
{$activeDriversJson}
```

--- ALL AVAILABLE ROUTES ---
```
{$routesJson}
```

--- UPCOMING BOOKINGS ---
```
{$bookingsJson}
```

Guidelines:
- Be concise, warm, and conversational. You can mix English and Filipino (Ilonggo/Tagalog is fine).
- If a commuter asks where to go, match their destination to the most relevant route.
- If no jeepneys are active on a route, say so honestly and suggest alternatives.
- For ETAs, use driver GPS position as reference — estimate travel time roughly (average jeepney speed in Bacolod is ~25 kph in traffic).
- Never make up driver names, plate numbers, or positions that aren't in the data above.
- If you don't know something, say so clearly and suggest they check the map.
- Keep replies short — commuters are usually on their phones on the go.
PROMPT;

// ── 6. Build the Gemini "contents" array (supports multi-turn history) ────────
// Gemini uses roles "user" and "model" (not "assistant"), and has no separate
// "system" field in this API version — so we prepend the system prompt as the
// first user turn, followed by a model acknowledgement.
$contents = [
    ['role' => 'user',  'parts' => [['text' => $systemPrompt]]],
    ['role' => 'model', 'parts' => [['text' => 'Understood! Ready to help commuters.']]],
];

// Append past turns from frontend (each: {role: 'user'|'assistant', content: '...'})
foreach ($history as $turn) {
    $role = $turn['role'] ?? '';
    if (in_array($role, ['user', 'assistant']) && !empty($turn['content'])) {
        $contents[] = [
            'role'  => $role === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => (string) $turn['content']]],
        ];
    }
}

// Append the new user message
$contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

// ── 7. Call Gemini ─────────────────────────────────────────────────────────────
function callGemini(array $contents): string {
    $payload = json_encode([
        'contents'         => $contents,
        'generationConfig' => [
            'maxOutputTokens' => 512,
        ],
    ]);

    $url = GEMINI_API_URL . '?key=' . urlencode(GEMINI_API_KEY);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException("cURL error calling Gemini API: {$curlErr}");
    }

    if ($httpCode !== 200) {
        throw new RuntimeException("Gemini API error (HTTP {$httpCode}): {$response}");
    }

    $data = json_decode($response, true);
    return $data['candidates'][0]['content']['parts'][0]['text']
        ?? 'Sorry, wala ko nasabat ang reply. Try again.';
}

try {
    $reply = callGemini($contents);
    incrementTodayUsage($pdo, $accountId, $guestKey, $today);
    $remaining = max(0, DAILY_QUESTION_LIMIT - ($usedToday + 1));
    echo json_encode(['reply' => $reply, 'remaining_today' => $remaining]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}