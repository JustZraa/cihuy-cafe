<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    if (headers_sent($file, $line)) {
        throw new RuntimeException('Session cannot be started after output at ' . $file . ':' . $line);
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');

    $https = (
        isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off'
    ) || (
        isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
        && strtolower(trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0])) === 'https'
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        throw new RuntimeException('Unable to start the application session.');
    }
}

function destroy_secure_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION = [];
    $cookie = session_get_cookie_params();
    if (ini_get('session.use_cookies')) {
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $cookie['path'],
            'domain' => $cookie['domain'],
            'secure' => $cookie['secure'],
            'httponly' => $cookie['httponly'],
            'samesite' => $cookie['samesite'] ?? 'Lax',
        ]);
    }

    if (!session_destroy()) {
        throw new RuntimeException('Unable to destroy the application session.');
    }
}

function normalize_indonesian_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', trim($phone));
    if (!is_string($digits) || $digits === '') {
        throw new InvalidArgumentException('Enter a valid Indonesian mobile number.');
    }

    if (str_starts_with($digits, '62')) {
        $digits = '0' . substr($digits, 2);
    } elseif (str_starts_with($digits, '8')) {
        $digits = '0' . $digits;
    }

    if (preg_match('/\A08[0-9]{8,11}\z/', $digits) !== 1) {
        throw new InvalidArgumentException('Enter a valid Indonesian mobile number.');
    }

    return $digits;
}

function cozycafe_app_path(string $relativePath = ''): string
{
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'env.php';

    $appUrl = cozycafe_env('APP_URL', 'http://localhost/cozycafe');
    $basePath = parse_url((string) $appUrl, PHP_URL_PATH);
    $basePath = is_string($basePath) ? rtrim($basePath, '/') : '';
    if (
        ($basePath !== '' && (!str_starts_with($basePath, '/') || str_starts_with($basePath, '//')))
        || preg_match('/[\x00-\x1F\x7F]/', $basePath) === 1
    ) {
        throw new RuntimeException('APP_URL must contain a safe application path.');
    }

    if (
        str_starts_with($relativePath, '/')
        || str_starts_with($relativePath, '\\')
        || str_contains($relativePath, '\\')
        || preg_match('/[\x00-\x20\x7F]/', $relativePath) === 1
    ) {
        throw new InvalidArgumentException('Application paths must be safe and relative.');
    }

    return ($basePath === '' ? '' : $basePath) . '/' . $relativePath;
}

function cozycafe_post_string(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? $value : '';
}

function generate_csrf_token(): string
{
    start_secure_session();

    if (!isset($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(generate_csrf_token()) . '">';
}

function verify_csrf_token(?string $token = null): bool
{
    start_secure_session();

    if ($token === null) {
        $postedToken = $_POST['csrf_token'] ?? null;
        if (!is_string($postedToken)) {
            return false;
        }
        $token = $postedToken;
    }

    $expectedToken = $_SESSION['_csrf_token'] ?? null;
    return is_string($expectedToken)
        && $expectedToken !== ''
        && $token !== ''
        && hash_equals($expectedToken, $token);
}

function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function format_rupiah(int|float|string $amount): string
{
    if (!is_numeric($amount)) {
        throw new InvalidArgumentException('The amount must be numeric.');
    }

    $numericAmount = (float) $amount;
    if (!is_finite($numericAmount)) {
        throw new InvalidArgumentException('The amount must be finite.');
    }

    return 'Rp ' . number_format($numericAmount, 0, ',', '.');
}

function format_wib(string|DateTimeInterface|null $datetime): string
{
    if ($datetime === null || (is_string($datetime) && trim($datetime) === '')) {
        return '-';
    }

    $timezone = new DateTimeZone('Asia/Jakarta');
    $date = $datetime instanceof DateTimeInterface
        ? DateTimeImmutable::createFromInterface($datetime)
        : new DateTimeImmutable($datetime, $timezone);

    return $date->setTimezone($timezone)->format('d-m-Y H:i') . ' WIB';
}

function secure_upload(array $file, string $targetDir, array $allowedMimes, int|float $maxSizeMB): string
{
    if (!is_finite((float) $maxSizeMB) || $maxSizeMB <= 0 || $allowedMimes === []) {
        throw new InvalidArgumentException('Upload size and allowed MIME types must be configured.');
    }
    foreach ($allowedMimes as $allowedMime) {
        if (!is_string($allowedMime) || $allowedMime === '') {
            throw new InvalidArgumentException('Allowed MIME types must be non-empty strings.');
        }
    }

    $uploadError = $file['error'] ?? null;
    if (!is_int($uploadError) || $uploadError !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the server upload limit.',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the form upload limit.',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially received.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server upload directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not save the uploaded file.',
            UPLOAD_ERR_EXTENSION => 'A server extension stopped the file upload.',
        ];
        throw new RuntimeException($messages[$uploadError] ?? 'The file upload failed.');
    }

    $temporaryPath = $file['tmp_name'] ?? null;
    if (!is_string($temporaryPath) || $temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        throw new RuntimeException('The uploaded file could not be verified.');
    }

    $size = filesize($temporaryPath);
    if ($size === false || $size < 1 || $size > $maxSizeMB * 1024 * 1024) {
        throw new RuntimeException('The uploaded file is empty or exceeds the allowed size.');
    }

    $originalName = (string) ($file['name'] ?? '');
    if (preg_match('/\.(?:php[0-9]?|phtml|phar|pht|cgi|pl|py|sh)(?:\.|$)/i', $originalName) === 1) {
        throw new RuntimeException('Executable file extensions are not allowed.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        throw new RuntimeException('The server could not initialize file type detection.');
    }

    try {
        $mimeType = finfo_file($finfo, $temporaryPath);
    } finally {
        finfo_close($finfo);
    }

    if (!is_string($mimeType) || !in_array($mimeType, $allowedMimes, true)) {
        throw new RuntimeException('The uploaded file type is not allowed.');
    }

    $extensionsByMime = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'application/pdf' => 'pdf',
    ];
    if (!isset($extensionsByMime[$mimeType])) {
        throw new RuntimeException('No safe file extension is configured for the detected file type.');
    }

    if (!is_dir($targetDir) && !mkdir($targetDir, 0750, true) && !is_dir($targetDir)) {
        throw new RuntimeException('The upload destination could not be created.');
    }

    $resolvedDirectory = realpath($targetDir);
    if ($resolvedDirectory === false || !is_writable($resolvedDirectory)) {
        throw new RuntimeException('The upload destination is not writable.');
    }

    $fileName = bin2hex(random_bytes(16)) . '.' . $extensionsByMime[$mimeType];
    $destination = $resolvedDirectory . DIRECTORY_SEPARATOR . $fileName;
    if (file_exists($destination) || !move_uploaded_file($temporaryPath, $destination)) {
        throw new RuntimeException('The uploaded file could not be saved safely.');
    }

    return $fileName;
}

function log_admin_action(
    PDO $pdo,
    int $adminId,
    string $action,
    string $entityType,
    ?int $entityId = null,
    ?string $description = null,
    ?string $ipAddress = null
): void {
    if ($adminId < 1 || trim($action) === '' || trim($entityType) === '') {
        throw new InvalidArgumentException('Admin ID, action, and entity type are required.');
    }

    $ipAddress = $ipAddress ?? ($_SERVER['REMOTE_ADDR'] ?? null);
    if ($ipAddress !== null && (strlen($ipAddress) > 45 || filter_var($ipAddress, FILTER_VALIDATE_IP) === false)) {
        throw new InvalidArgumentException('The IP address is invalid or exceeds the database column length.');
    }
    if ($entityId !== null && $entityId < 1) {
        throw new InvalidArgumentException('The entity ID must be positive.');
    }

    $statement = $pdo->prepare(
        'INSERT INTO admin_logs (admin_id, action, entity_type, entity_id, description, ip_address)
         VALUES (:admin_id, :action, :entity_type, :entity_id, :description, :ip_address)'
    );
    $statement->execute([
        'admin_id' => $adminId,
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'description' => $description,
        'ip_address' => $ipAddress,
    ]);
}

function log_order_status(
    PDO $pdo,
    int $orderId,
    ?string $oldStatus,
    string $newStatus,
    string $changedByType,
    ?int $changedById = null,
    ?string $notes = null
): void {
    log_status_change(
        $pdo,
        'order_status_logs',
        'order_id',
        $orderId,
        $oldStatus,
        $newStatus,
        $changedByType,
        $changedById,
        $notes
    );
}

function log_reservation_status(
    PDO $pdo,
    int $reservationId,
    ?string $oldStatus,
    string $newStatus,
    string $changedByType,
    ?int $changedById = null,
    ?string $notes = null
): void {
    log_status_change(
        $pdo,
        'reservation_status_logs',
        'reservation_id',
        $reservationId,
        $oldStatus,
        $newStatus,
        $changedByType,
        $changedById,
        $notes
    );
}

function log_status_change(
    PDO $pdo,
    string $table,
    string $referenceColumn,
    int $referenceId,
    ?string $oldStatus,
    string $newStatus,
    string $changedByType,
    ?int $changedById,
    ?string $notes
): void {
    $statusLogTables = [
        'order_status_logs' => 'order_id',
        'reservation_status_logs' => 'reservation_id',
    ];

    if (!isset($statusLogTables[$table]) || $statusLogTables[$table] !== $referenceColumn) {
        throw new InvalidArgumentException('Unsupported status log target.');
    }
    if (
        $referenceId < 1
        || trim($newStatus) === ''
        || !in_array($changedByType, ['customer', 'admin', 'system'], true)
        || ($changedById !== null && $changedById < 1)
    ) {
        throw new InvalidArgumentException('Invalid status change details.');
    }

    $statement = $pdo->prepare(
        "INSERT INTO {$table} ({$referenceColumn}, old_status, new_status, changed_by_type, changed_by_id, notes)
         VALUES (:reference_id, :old_status, :new_status, :changed_by_type, :changed_by_id, :notes)"
    );
    $statement->execute([
        'reference_id' => $referenceId,
        'old_status' => $oldStatus,
        'new_status' => $newStatus,
        'changed_by_type' => $changedByType,
        'changed_by_id' => $changedById,
        'notes' => $notes,
    ]);
}

function create_notification(
    PDO $pdo,
    string $recipientType,
    ?int $recipientId,
    string $title,
    string $message,
    ?string $linkUrl = null
): int {
    if (
        !in_array($recipientType, ['customer', 'admin'], true)
        || ($recipientId !== null && $recipientId < 1)
        || trim($title) === ''
        || trim($message) === ''
    ) {
        throw new InvalidArgumentException('Notification recipient, title, and message are required.');
    }

    $statement = $pdo->prepare(
        'INSERT INTO notifications (recipient_type, recipient_id, title, message, link_url)
         VALUES (:recipient_type, :recipient_id, :title, :message, :link_url)'
    );
    $statement->execute([
        'recipient_type' => $recipientType,
        'recipient_id' => $recipientId,
        'title' => $title,
        'message' => $message,
        'link_url' => $linkUrl,
    ]);

    return (int) $pdo->lastInsertId();
}

function check_rate_limit(string $identifier, int $maxAttempts = 5, int $decaySeconds = 900): bool
{
    if (trim($identifier) === '' || $maxAttempts < 1 || $decaySeconds < 1) {
        throw new InvalidArgumentException('A rate-limit identifier and positive limits are required.');
    }

    start_secure_session();

    if (!isset($_SESSION['_rate_limits']) || !is_array($_SESSION['_rate_limits'])) {
        $_SESSION['_rate_limits'] = [];
    }

    $key = hash('sha256', $identifier);
    $now = time();
    $attempt = $_SESSION['_rate_limits'][$key] ?? null;

    if (
        !is_array($attempt)
        || !isset($attempt['started_at'], $attempt['count'])
        || !is_int($attempt['started_at'])
        || !is_int($attempt['count'])
        || $now >= $attempt['started_at'] + $decaySeconds
    ) {
        $attempt = ['started_at' => $now, 'count' => 0];
    }

    if (isset($attempt['blocked_until']) && is_int($attempt['blocked_until']) && $now < $attempt['blocked_until']) {
        $_SESSION['_rate_limits'][$key] = $attempt;
        return false;
    }

    if ($attempt['count'] >= $maxAttempts) {
        $attempt['blocked_until'] = $attempt['started_at'] + $decaySeconds;
        $_SESSION['_rate_limits'][$key] = $attempt;
        return false;
    }

    $attempt['count']++;
    $_SESSION['_rate_limits'][$key] = $attempt;
    return true;
}

function clear_rate_limit(string $identifier): void
{
    if (trim($identifier) === '') {
        throw new InvalidArgumentException('A rate-limit identifier is required.');
    }

    start_secure_session();
    if (!isset($_SESSION['_rate_limits']) || !is_array($_SESSION['_rate_limits'])) {
        return;
    }
    unset($_SESSION['_rate_limits'][hash('sha256', $identifier)]);
}

function __(string $key, array $replacements = []): string
{
    start_secure_session();

    $language = $_SESSION['lang'] ?? 'id';
    if (!is_string($language) || !in_array($language, ['id', 'en'], true)) {
        $language = 'id';
    }

    static $catalogues = [];
    if (!isset($catalogues[$language])) {
        $cataloguePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . $language . '.php';
        $catalogue = require $cataloguePath;
        if (!is_array($catalogue)) {
            throw new RuntimeException('The language catalogue must return an array.');
        }
        $catalogues[$language] = $catalogue;
    }

    $text = $catalogues[$language][$key] ?? null;
    if (!is_string($text)) {
        if (!isset($catalogues['id'])) {
            $cataloguePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . 'id.php';
            $catalogue = require $cataloguePath;
            if (!is_array($catalogue)) {
                throw new RuntimeException('The Indonesian language catalogue must return an array.');
            }
            $catalogues['id'] = $catalogue;
        }
        $text = $catalogues['id'][$key] ?? $key;
    }

    $mappedReplacements = [];
    foreach ($replacements as $name => $value) {
        $mappedReplacements['{' . trim((string) $name, '{}') . '}'] = (string) $value;
    }

    return strtr($text, $mappedReplacements);
}
