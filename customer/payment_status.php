<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'payment_service.php';

start_secure_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cozycafe_payment_status_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    cozycafe_payment_status_response(405, ['success' => false, 'message' => __('error.method_not_allowed')]);
}
if (!verify_csrf_token()) {
    cozycafe_payment_status_response(403, ['success' => false, 'message' => __('validation.invalid_csrf')]);
}

$paymentIdInput = $_POST['payment_id'] ?? null;
if (!is_string($paymentIdInput) || preg_match('/\A[1-9][0-9]{0,9}\z/', $paymentIdInput) !== 1) {
    cozycafe_payment_status_response(422, ['success' => false, 'message' => __('error.payment_not_found')]);
}

$pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
$payment = cozycafe_payment_find_by_id($pdo, (int) $paymentIdInput);
if (!is_array($payment) || !cozycafe_payment_authorized($pdo, $payment)) {
    cozycafe_payment_status_response(404, ['success' => false, 'message' => __('error.payment_not_found')]);
}

cozycafe_expire_payment_if_unproven($pdo, (int) $payment['id']);
$payment = cozycafe_payment_find_by_id($pdo, (int) $paymentIdInput);
if (!is_array($payment)) {
    cozycafe_payment_status_response(404, ['success' => false, 'message' => __('error.payment_not_found')]);
}
$proofs = cozycafe_payment_proofs($pdo, (int) $payment['id']);
cozycafe_payment_status_response(200, [
    'success' => true,
    'status' => (string) $payment['status'],
    'has_proof' => $proofs !== [],
]);
