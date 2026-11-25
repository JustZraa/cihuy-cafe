<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'env.php';

date_default_timezone_set('Asia/Jakarta');

$host = cozycafe_env('DB_HOST', '127.0.0.1');
$port = cozycafe_env('DB_PORT', '3306');
$database = cozycafe_env('DB_NAME', 'cozycafe');
$username = cozycafe_env('DB_USER', 'root');
$password = cozycafe_env('DB_PASS', '');

if ($host === null || $host === '' || !preg_match('/\A[A-Za-z0-9_.:-]+\z/', $host)) {
    throw new InvalidArgumentException('DB_HOST must be a valid hostname or IP address.');
}

if ($port === null || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
    throw new InvalidArgumentException('DB_PORT must be a valid TCP port.');
}

if ($database === null || !preg_match('/\A[A-Za-z0-9_$-]+\z/', $database)) {
    throw new InvalidArgumentException('DB_NAME contains unsupported characters.');
}

if ($username === null || $password === null) {
    throw new RuntimeException('Database credentials could not be loaded.');
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    $host,
    (int) $port,
    $database
);

$pdo = new PDO($dsn, $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$pdo->exec("SET time_zone = '+07:00'");

return $pdo;
