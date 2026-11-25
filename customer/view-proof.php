<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth_middleware.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'payment_service.php';

start_secure_session();
$proofId = $_GET['id'] ?? null;
if (!is_string($proofId) || preg_match('/\A[1-9][0-9]{0,9}\z/', $proofId) !== 1) {
    http_response_code(404);
    exit(__('error.payment_proof_not_found'));
}

$pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
cozycafe_payment_stream_proof($pdo, (int) $proofId);
