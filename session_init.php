<?php
// session_init.php
// Shared session bootstrap. Include this instead of calling session_start()
// directly. Sessions are stored in MySQL (not local disk) so they survive
// Render's container restarts/redeploys — file-based sessions get wiped
// whenever the container recycles, causing random 401s mid-session.

require_once __DIR__ . '/db.php'; // must provide $pdo (PDO connection)

class DbSessionHandler implements SessionHandlerInterface {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function open($savePath, $sessionName): bool { return true; }
    public function close(): bool { return true; }

    public function read($id): string|false {
        $stmt = $this->pdo->prepare("SELECT data FROM php_sessions WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['data'] : '';
    }

    public function write($id, $data): bool {
        $stmt = $this->pdo->prepare("
            INSERT INTO php_sessions (id, data, last_access)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE data = VALUES(data), last_access = VALUES(last_access)
        ");
        return $stmt->execute([$id, $data, time()]);
    }

    public function destroy($id): bool {
        $stmt = $this->pdo->prepare("DELETE FROM php_sessions WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function gc($max_lifetime): int|false {
        $stmt = $this->pdo->prepare("DELETE FROM php_sessions WHERE last_access < ?");
        $stmt->execute([time() - $max_lifetime]);
        return $stmt->rowCount();
    }
}

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_set_save_handler(new DbSessionHandler($pdo), true);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}