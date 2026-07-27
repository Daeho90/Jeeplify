<?php
// rate_limit.php
// Simple DB-backed rate limiter to slow down brute-force / spam attempts
// against login, register, and forgot-password. Requires the rate_limits
// table — see rate_limit_migration.sql.

/**
 * Best-effort client IP. Render/most hosts sit behind a proxy, so
 * REMOTE_ADDR alone may just be the proxy's IP — X-Forwarded-For (set by
 * the proxy, first entry) is more accurate when present.
 */
function clientIp(): string {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/**
 * Returns true if the request is allowed to proceed, false if the caller
 * has exceeded $maxAttempts within $windowMinutes for this action.
 * Does NOT record an attempt by itself — call recordAttempt() after
 * checking, once you know if it succeeded or failed.
 */
function isRateLimited(PDO $pdo, string $identifier, string $action, int $maxAttempts, int $windowMinutes): bool {
    $stmt = $pdo->prepare(
        'SELECT attempt_count, first_attempt_at FROM rate_limits WHERE identifier = ? AND action = ?'
    );
    $stmt->execute([$identifier, $action]);
    $row = $stmt->fetch();

    if (!$row) return false;

    $windowStart = strtotime($row['first_attempt_at']);
    $windowEnds  = $windowStart + ($windowMinutes * 60);

    if (time() > $windowEnds) {
        // Window expired — not limited (row will be reset on next recordAttempt call)
        return false;
    }

    return (int)$row['attempt_count'] >= $maxAttempts;
}

/**
 * Records one attempt for this identifier+action. Resets the counter if
 * the previous window has expired.
 */
function recordAttempt(PDO $pdo, string $identifier, string $action, int $windowMinutes): void {
    $stmt = $pdo->prepare(
        'SELECT attempt_count, first_attempt_at FROM rate_limits WHERE identifier = ? AND action = ?'
    );
    $stmt->execute([$identifier, $action]);
    $row = $stmt->fetch();

    if (!$row) {
        $pdo->prepare(
            'INSERT INTO rate_limits (identifier, action, attempt_count, first_attempt_at) VALUES (?, ?, 1, NOW())'
        )->execute([$identifier, $action]);
        return;
    }

    $windowStart = strtotime($row['first_attempt_at']);
    $windowEnds  = $windowStart + ($windowMinutes * 60);

    if (time() > $windowEnds) {
        // Window expired — start a fresh one
        $pdo->prepare(
            'UPDATE rate_limits SET attempt_count = 1, first_attempt_at = NOW() WHERE identifier = ? AND action = ?'
        )->execute([$identifier, $action]);
    } else {
        $pdo->prepare(
            'UPDATE rate_limits SET attempt_count = attempt_count + 1 WHERE identifier = ? AND action = ?'
        )->execute([$identifier, $action]);
    }
}

/**
 * Clears rate limit tracking for this identifier+action — call on a
 * successful login so a legitimate user's next mistake doesn't inherit
 * a near-exhausted counter.
 */
function clearRateLimit(PDO $pdo, string $identifier, string $action): void {
    $pdo->prepare('DELETE FROM rate_limits WHERE identifier = ? AND action = ?')
        ->execute([$identifier, $action]);
}