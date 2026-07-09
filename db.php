<?php
// ════════════════════════════════════════════════════════════
//  JEEPLIFY BCD — db.php
//  Reusable database connection — include this in any page
//  Usage: require_once 'db.php';  then use $pdo
// ════════════════════════════════════════════════════════════

define('DB_HOST', 'sql113.infinityfree.com');
define('DB_NAME', 'if0_41976004_jeeplify_bcd');
define('DB_USER', 'if0_41976004');
define('DB_PASS', 'ps42BuY87T');         // ← change this

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    // Don't expose DB details to the browser
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'message' => 'Database connection failed. Please try again later.']));
}