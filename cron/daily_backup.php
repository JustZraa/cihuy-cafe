<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup_service.php';

$pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
$backup = cozycafe_create_database_backup($pdo, 'auto');
fwrite(
    STDOUT,
    'Created database backup ' . $backup['file_name']
    . ' (' . $backup['file_size'] . " bytes).\n"
);
