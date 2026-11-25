<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'cart_service.php';

start_secure_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cozycafe_cart_json_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cozycafe_cart_request_integer(string $key): int
{
    $value = $_POST[$key] ?? null;
    if (
        (!is_string($value) && !is_int($value))
        || preg_match('/\A[1-9][0-9]{0,9}\z/', (string) $value) !== 1
    ) {
        throw new CozyCafeCartException('error.cart_invalid_quantity');
    }

    return (int) $value;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    cozycafe_cart_json_response(405, ['success' => false, 'message' => __('error.method_not_allowed')]);
}

if (!verify_csrf_token()) {
    cozycafe_cart_json_response(403, ['success' => false, 'message' => __('validation.invalid_csrf')]);
}

$pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
$customerId = cozycafe_current_customer_id();
$action = cozycafe_post_string('action');

try {
    $cart = match ($action) {
        'add' => cozycafe_cart_add(
            $pdo,
            $customerId,
            cozycafe_cart_request_integer('product_id'),
            cozycafe_cart_request_integer('quantity'),
            cozycafe_post_string('item_notes')
        ),
        'update' => cozycafe_cart_update(
            $pdo,
            $customerId,
            cozycafe_cart_request_integer('product_id'),
            cozycafe_cart_request_integer('quantity'),
            cozycafe_post_string('item_notes')
        ),
        'remove' => cozycafe_cart_remove(
            $pdo,
            $customerId,
            cozycafe_cart_request_integer('product_id')
        ),
        'update_order_notes' => cozycafe_cart_update_order_notes(
            $pdo,
            $customerId,
            cozycafe_post_string('order_notes')
        ),
        default => throw new CozyCafeCartException('error.cart_invalid_action'),
    };

    $totals = cozycafe_cart_totals($cart['items']);
    cozycafe_cart_json_response(200, [
        'success' => true,
        'message' => __('message.cart_updated'),
        'cart_label' => __('nav.cart'),
        'cart_count' => $totals['item_count'],
        'subtotal' => $totals['subtotal'],
        'tax_amount' => $totals['tax_amount'],
        'total_amount' => $totals['total_amount'],
        'invalid_item_count' => (int) $cart['invalid_item_count'],
    ]);
} catch (CozyCafeCartException $exception) {
    cozycafe_cart_json_response(422, [
        'success' => false,
        'message' => __($exception->translationKey, $exception->replacements),
    ]);
}
