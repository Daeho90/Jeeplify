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
 * Returns active drivers with their latest GPS position.
 * Adjust table/column names to match your schema.
 */
function getActiveDrivers(PDO $pdo): array {
    $stmt = $pdo->query("
        SELECT
            d.id,
            d.name          AS driver_name,
            v.plate_number,
            r.name          AS route_name,
            dl.latitude,
            dl.longitude,
            dl.status,
            dl.recorded_at
        FROM drivers d
        JOIN vehicles        v  ON v.driver_id  = d.id
        JOIN routes          r  ON r.id         = v.route_id
        JOIN driver_locations dl ON dl.driver_id = d.id
        WHERE dl.status IN ('on_duty', 'available')
          AND dl.recorded_at >= NOW() - INTERVAL 10 MINUTE
        ORDER BY dl.recorded_at DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Returns all route definitions.
 */
function getRoutes(PDO $pdo): array {
    $stmt = $pdo->query("
        SELECT id, name, description, start_point, end_point, fare
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
               b.scheduled_time, b.status, r.name AS route_name
        FROM bookings b
        JOIN routes r ON r.id = b.route_id
        WHERE b.status IN ('pending', 'confirmed')
          AND b.scheduled_time >= NOW()
        ORDER BY b.scheduled_time ASC
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
    echo json_encode(['reply' => $reply]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}