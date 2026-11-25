<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'cart_service.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'order_service.php';

final class CozyCafeCheckoutException extends RuntimeException
{
    public function __construct(
        public readonly string $translationKey,
        public readonly array $replacements = []
    ) {
        parent::__construct($translationKey);
    }
}

function cozycafe_checkout_cart(PDO $pdo, ?int $customerId): array
{
    if ($customerId !== null) {
        $cartQuery = $pdo->prepare(
            'SELECT id, order_notes
             FROM carts
             WHERE user_id = :user_id
             FOR UPDATE'
        );
        $cartQuery->execute(['user_id' => $customerId]);
        $cart = $cartQuery->fetch();
        if (!is_array($cart)) {
            throw new CozyCafeCheckoutException('error.cart_empty');
        }

        $itemsQuery = $pdo->prepare(
            'SELECT product_id, quantity, item_notes
             FROM cart_items
             WHERE cart_id = :cart_id
             ORDER BY product_id ASC
             FOR UPDATE'
        );
        $itemsQuery->execute(['cart_id' => (int) $cart['id']]);
        $items = $itemsQuery->fetchAll();

        return [
            'cart_id' => (int) $cart['id'],
            'items' => $items,
            'order_notes' => is_string($cart['order_notes']) ? $cart['order_notes'] : '',
        ];
    }

    $guestCart = $_SESSION['guest_cart'] ?? [];
    if (!is_array($guestCart)) {
        throw new CozyCafeCheckoutException('error.cart_invalid_item');
    }

    return [
        'cart_id' => null,
        'items' => array_values(cozycafe_cart_session_items($guestCart)),
        'order_notes' => cozycafe_cart_order_notes($guestCart),
    ];
}

function cozycafe_checkout_find_reservation(PDO $pdo, int $customerId, int $reservationId): array
{
    $statement = $pdo->prepare(
        "SELECT id, table_id, booking_code, reservation_date, start_time
         FROM reservations
         WHERE id = :id
           AND user_id = :user_id
           AND status = 'Confirmed'
           AND reservation_date = CURRENT_DATE()
           AND start_time <= TIME(DATE_ADD(NOW(), INTERVAL 15 MINUTE))
           AND end_time > CURRENT_TIME()
         FOR UPDATE"
    );
    $statement->execute(['id' => $reservationId, 'user_id' => $customerId]);
    $reservation = $statement->fetch();
    if (!is_array($reservation)) {
        throw new CozyCafeCheckoutException('error.checkout_reservation_unavailable');
    }

    return $reservation;
}

function cozycafe_checkout_find_open_order(
    PDO $pdo,
    int $customerId,
    int $orderId,
    ?int $reservationId = null
): array {
    $sql = "SELECT id, order_code, status, subtotal, tax_amount, total_amount, order_notes, scheduled_prep_at
            FROM orders
            WHERE user_id = :user_id
              AND order_type = 'dine_in'
              AND status IN ('Pending', 'Processing')
              AND created_at >= CURRENT_DATE()";
    $parameters = ['user_id' => $customerId];
    if ($reservationId !== null) {
        $sql .= ' AND reservation_id = :reservation_id';
        $parameters['reservation_id'] = $reservationId;
    } else {
        $sql .= ' AND id = :id AND reservation_id IS NULL';
        $parameters['id'] = $orderId;
    }
    $sql .= ' LIMIT 1 FOR UPDATE';

    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    $order = $statement->fetch();
    if (!is_array($order)) {
        throw new CozyCafeCheckoutException('error.checkout_order_unavailable');
    }

    $paymentsQuery = $pdo->prepare(
        "SELECT id
         FROM payments
         WHERE payable_type = 'order'
           AND payable_id = :order_id
           AND status IN ('Waiting Verification', 'Verified')
         FOR UPDATE"
    );
    $paymentsQuery->execute(['order_id' => (int) $order['id']]);
    if ($paymentsQuery->fetchColumn() !== false) {
        throw new CozyCafeCheckoutException('error.checkout_order_unavailable');
    }

    return $order;
}

function cozycafe_checkout_get_locked_products(PDO $pdo, array $cartItems): array
{
    $quantities = [];
    foreach ($cartItems as $item) {
        $productId = (int) ($item['product_id'] ?? 0);
        $quantity = (int) ($item['quantity'] ?? 0);
        if ($productId < 1 || $quantity < 1) {
            throw new CozyCafeCheckoutException('error.cart_invalid_item');
        }
        $quantities[$productId] = ($quantities[$productId] ?? 0) + $quantity;
        if ($quantities[$productId] > 4294967295) {
            throw new CozyCafeCheckoutException('error.cart_invalid_quantity');
        }
    }

    if ($quantities === []) {
        throw new CozyCafeCheckoutException('error.cart_empty');
    }

    $productIds = array_keys($quantities);
    sort($productIds, SORT_NUMERIC);
    $placeholders = [];
    $parameters = [];
    foreach ($productIds as $index => $productId) {
        $placeholder = ':product_' . $index;
        $placeholders[] = $placeholder;
        $parameters[$placeholder] = $productId;
    }

    $statement = $pdo->prepare(
        'SELECT id, name, price, stock, is_available
         FROM products
         WHERE id IN (' . implode(', ', $placeholders) . ')
         ORDER BY id ASC
         FOR UPDATE'
    );
    $statement->execute($parameters);
    $products = [];
    foreach ($statement->fetchAll() as $product) {
        $products[(int) $product['id']] = $product;
    }

    foreach ($quantities as $productId => $quantity) {
        if (!isset($products[$productId])) {
            throw new CozyCafeCheckoutException('error.cart_product_missing');
        }
        if ((int) $products[$productId]['is_available'] !== 1) {
            throw new CozyCafeCheckoutException('error.cart_product_unavailable');
        }
        if ($quantity > (int) $products[$productId]['stock']) {
            throw new CozyCafeCheckoutException('validation.stock_unavailable');
        }
    }

    return [$products, $quantities];
}

function cozycafe_checkout_amounts(array $products, array $quantities, array $existingOrder = []): array
{
    $subtotalCents = 0;
    if ($existingOrder !== []) {
        $subtotalCents = (int) round((float) $existingOrder['subtotal'] * 100, 0, PHP_ROUND_HALF_UP);
    }

    foreach ($quantities as $productId => $quantity) {
        $priceCents = (int) round((float) $products[$productId]['price'] * 100, 0, PHP_ROUND_HALF_UP);
        if ($priceCents < 0 || $priceCents > intdiv(999999999999, $quantity)) {
            throw new CozyCafeCheckoutException('error.cart_total_too_large');
        }
        $lineTotal = $priceCents * $quantity;
        if ($subtotalCents > 999999999999 - $lineTotal) {
            throw new CozyCafeCheckoutException('error.cart_total_too_large');
        }
        $subtotalCents += $lineTotal;
    }

    $taxCents = (int) round($subtotalCents * 0.10, 0, PHP_ROUND_HALF_UP);
    $totalCents = $subtotalCents + $taxCents;
    if ($totalCents > 999999999999) {
        throw new CozyCafeCheckoutException('error.cart_total_too_large');
    }

    return [
        'subtotal' => number_format($subtotalCents / 100, 2, '.', ''),
        'tax_amount' => number_format($taxCents / 100, 2, '.', ''),
        'total_amount' => number_format($totalCents / 100, 2, '.', ''),
    ];
}

function cozycafe_checkout_order_code(): string
{
    return 'CC-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
}

function cozycafe_checkout_payment_code(): string
{
    return 'PAY-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
}

function cozycafe_checkout_store_payment(
    PDO $pdo,
    int $orderId,
    string $paymentMethod,
    string $amount,
    ?string $expiresAt
): void {
    $paymentQuery = $pdo->prepare(
        "SELECT id
         FROM payments
         WHERE payable_type = 'order'
           AND payable_id = :order_id
         ORDER BY id DESC
         LIMIT 1
         FOR UPDATE"
    );
    $paymentQuery->execute(['order_id' => $orderId]);
    $paymentId = $paymentQuery->fetchColumn();
    if ($paymentId !== false) {
        $update = $pdo->prepare(
            "UPDATE payments
             SET payment_method = :payment_method,
                 amount = :amount,
                 status = 'Pending',
                 rejection_reason = NULL,
                 verified_by = NULL,
                 verified_at = NULL,
                 expires_at = " . ($expiresAt === null ? 'NULL' : ':expires_at') . "
             WHERE id = :id"
        );
        $parameters = [
            'payment_method' => $paymentMethod,
            'amount' => $amount,
            'id' => (int) $paymentId,
        ];
        if ($expiresAt !== null) {
            $parameters['expires_at'] = $expiresAt;
        }
        $update->execute($parameters);
        return;
    }

    $insert = $pdo->prepare(
        "INSERT INTO payments
            (payment_code, payable_type, payable_id, payment_method, amount, status, expires_at)
         VALUES
            (:payment_code, 'order', :payable_id, :payment_method, :amount, 'Pending',
             " . ($expiresAt === null ? 'NULL' : ':expires_at') . ')'
    );
    $parameters = [
        'payment_code' => cozycafe_checkout_payment_code(),
        'payable_id' => $orderId,
        'payment_method' => $paymentMethod,
        'amount' => $amount,
    ];
    if ($expiresAt !== null) {
        $parameters['expires_at'] = $expiresAt;
    }
    $insert->execute($parameters);
}

function cozycafe_place_order(
    PDO $pdo,
    ?int $customerId,
    string $guestName,
    string $guestPhone,
    string $orderType,
    string $paymentMethod,
    string $dineInTarget
): array {
    if (!in_array($orderType, ['dine_in', 'takeaway'], true)) {
        throw new CozyCafeCheckoutException('error.checkout_invalid_order_type');
    }
    if (!in_array($paymentMethod, ['qris', 'cash'], true)) {
        throw new CozyCafeCheckoutException('error.checkout_invalid_payment_method');
    }
    if ($orderType === 'takeaway') {
        $paymentMethod = 'qris';
        $dineInTarget = 'new';
    } elseif (!preg_match('/\A(?:new|reservation:[1-9][0-9]{0,9}|order:[1-9][0-9]{0,9})\z/', $dineInTarget)) {
        throw new CozyCafeCheckoutException('error.checkout_invalid_target');
    }

    $order = cozycafe_with_transaction($pdo, static function () use (
        $pdo,
        $customerId,
        $guestName,
        $guestPhone,
        $orderType,
        $paymentMethod,
        $dineInTarget
    ): array {
        $cart = cozycafe_checkout_cart($pdo, $customerId);
        $cartItems = $cart['items'];
        if ($cartItems === []) {
            throw new CozyCafeCheckoutException('error.cart_empty');
        }

        $existingOrder = null;
        $reservation = null;
        if ($orderType === 'dine_in' && $dineInTarget !== 'new') {
            if ($customerId === null) {
                throw new CozyCafeCheckoutException('error.checkout_login_required_for_target');
            }
            [$targetType, $targetIdString] = explode(':', $dineInTarget, 2);
            $targetId = (int) $targetIdString;
            if ($targetType === 'reservation') {
                $reservation = cozycafe_checkout_find_reservation($pdo, $customerId, $targetId);
                $candidateQuery = $pdo->prepare(
                    "SELECT id, order_code, status, subtotal, tax_amount, total_amount, order_notes, scheduled_prep_at
                     FROM orders
                     WHERE user_id = :user_id
                       AND reservation_id = :reservation_id
                       AND order_type = 'dine_in'
                       AND status IN ('Pending', 'Processing')
                     ORDER BY id ASC
                     LIMIT 1
                     FOR UPDATE"
                );
                $candidateQuery->execute([
                    'user_id' => $customerId,
                    'reservation_id' => (int) $reservation['id'],
                ]);
                $candidate = $candidateQuery->fetch();
                if (is_array($candidate)) {
                    $existingOrder = cozycafe_checkout_find_open_order(
                        $pdo,
                        $customerId,
                        (int) $candidate['id'],
                        (int) $reservation['id']
                    );
                }
            } else {
                $existingOrder = cozycafe_checkout_find_open_order($pdo, $customerId, $targetId);
            }
        }

        [$products, $quantities] = cozycafe_checkout_get_locked_products($pdo, $cartItems);
        $amounts = cozycafe_checkout_amounts($products, $quantities, is_array($existingOrder) ? $existingOrder : []);
        $orderNotes = cozycafe_cart_validate_notes((string) $cart['order_notes'], true);
        if (is_array($existingOrder)) {
            $oldNotes = is_string($existingOrder['order_notes']) ? trim($existingOrder['order_notes']) : '';
            if ($orderNotes !== '' && $oldNotes !== '') {
                $orderNotes = $oldNotes . "\n" . $orderNotes;
                $length = preg_match_all('/./us', $orderNotes);
                if ($length === false || $length > 5000) {
                    throw new CozyCafeCheckoutException('error.cart_order_notes_too_long', ['count' => 5000]);
                }
            } elseif ($oldNotes !== '') {
                $orderNotes = $oldNotes;
            }
        }

        $hasDeadline = $orderType === 'takeaway';
        $status = $hasDeadline ? 'Waiting for Payment' : 'Pending';
        $paymentDeadline = null;
        $scheduledPrepAt = null;
        if (is_array($reservation)) {
            $reservationStart = new DateTimeImmutable(
                (string) $reservation['reservation_date'] . ' ' . (string) $reservation['start_time'],
                new DateTimeZone('Asia/Jakarta')
            );
            $scheduledPrepAt = $reservationStart->modify('-15 minutes')->format('Y-m-d H:i:s');
        }
        if ($hasDeadline) {
            $deadlineQuery = $pdo->query('SELECT DATE_ADD(NOW(), INTERVAL 15 MINUTE)');
            $deadline = $deadlineQuery->fetchColumn();
            if (!is_string($deadline)) {
                throw new RuntimeException('Unable to calculate the payment deadline.');
            }
            $paymentDeadline = $deadline;
        }
        if (is_array($existingOrder)) {
            $orderId = (int) $existingOrder['id'];
            $orderCode = (string) $existingOrder['order_code'];
            $updateOrder = $pdo->prepare(
                'UPDATE orders
                 SET guest_name = :guest_name,
                     guest_phone = :guest_phone,
                     subtotal = :subtotal,
                     tax_amount = :tax_amount,
                     total_amount = :total_amount,
                     payment_method = :payment_method,
                     order_notes = :order_notes,
                     payment_deadline = :payment_deadline,
                     scheduled_prep_at = COALESCE(scheduled_prep_at, :scheduled_prep_at)
                 WHERE id = :id'
            );
            $updateOrder->execute([
                'guest_name' => $guestName,
                'guest_phone' => $guestPhone,
                'subtotal' => $amounts['subtotal'],
                'tax_amount' => $amounts['tax_amount'],
                'total_amount' => $amounts['total_amount'],
                'payment_method' => $paymentMethod,
                'order_notes' => $orderNotes !== '' ? $orderNotes : null,
                'payment_deadline' => $paymentDeadline,
                'scheduled_prep_at' => $scheduledPrepAt,
                'id' => $orderId,
            ]);
        } else {
            $orderCode = cozycafe_checkout_order_code();
            $insertOrder = $pdo->prepare(
                'INSERT INTO orders
                    (order_code, user_id, guest_name, guest_phone, order_type, reservation_id, table_id,
                     subtotal, tax_amount, total_amount, payment_method, status, order_notes, scheduled_prep_at,
                     payment_deadline)
                 VALUES
                    (:order_code, :user_id, :guest_name, :guest_phone, :order_type, :reservation_id, :table_id,
                     :subtotal, :tax_amount, :total_amount, :payment_method, :status, :order_notes,
                     :scheduled_prep_at, :payment_deadline)'
            );
            $insertOrder->execute([
                'order_code' => $orderCode,
                'user_id' => $customerId,
                'guest_name' => $guestName,
                'guest_phone' => $guestPhone,
                'order_type' => $orderType,
                'reservation_id' => is_array($reservation) ? (int) $reservation['id'] : null,
                'table_id' => is_array($reservation) ? (int) $reservation['table_id'] : null,
                'subtotal' => $amounts['subtotal'],
                'tax_amount' => $amounts['tax_amount'],
                'total_amount' => $amounts['total_amount'],
                'payment_method' => $paymentMethod,
                'status' => $status,
                'order_notes' => $orderNotes !== '' ? $orderNotes : null,
                'scheduled_prep_at' => $scheduledPrepAt,
                'payment_deadline' => $paymentDeadline,
            ]);
            $orderId = (int) $pdo->lastInsertId();

            $statusLog = $pdo->prepare(
                'INSERT INTO order_status_logs
                    (order_id, old_status, new_status, changed_by_type, changed_by_id, notes)
                 VALUES
                    (:order_id, NULL, :new_status, :changed_by_type, :changed_by_id, :notes)'
            );
            $statusLog->execute([
                'order_id' => $orderId,
                'new_status' => $status,
                'changed_by_type' => 'customer',
                'changed_by_id' => $customerId,
                'notes' => __('order.created_by_customer'),
            ]);
        }

        $insertItem = $pdo->prepare(
            'INSERT INTO order_items
                (order_id, product_id, product_name_snapshot, price_snapshot, quantity, subtotal, item_notes)
             VALUES
                (:order_id, :product_id, :product_name_snapshot, :price_snapshot, :quantity, :subtotal, :item_notes)'
        );
        foreach ($cartItems as $item) {
            $productId = (int) $item['product_id'];
            $quantity = (int) $item['quantity'];
            $product = $products[$productId];
            $price = (string) $product['price'];
            $lineCents = (int) round((float) $price * 100, 0, PHP_ROUND_HALF_UP) * $quantity;

            $insertItem->execute([
                'order_id' => $orderId,
                'product_id' => $productId,
                'product_name_snapshot' => (string) $product['name'],
                'price_snapshot' => $price,
                'quantity' => $quantity,
                'subtotal' => number_format($lineCents / 100, 2, '.', ''),
                'item_notes' => isset($item['item_notes']) && is_string($item['item_notes']) && $item['item_notes'] !== ''
                    ? $item['item_notes']
                    : null,
            ]);

        }
        deduct_order_stock($pdo, $orderId);

        cozycafe_checkout_store_payment(
            $pdo,
            $orderId,
            $paymentMethod,
            $amounts['total_amount'],
            $paymentDeadline
        );

        if (!is_array($existingOrder)) {
            $orderLink = cozycafe_app_path('admin/orders/detail.php?id=' . $orderId);
            create_notification(
                $pdo,
                'admin',
                null,
                __('notification.admin.new_order_title'),
                __('notification.admin.new_order'),
                $orderLink
            );
            if ($customerId !== null) {
                create_notification(
                    $pdo,
                    'customer',
                    $customerId,
                    __('notification.customer.order_created_title'),
                    __('notification.customer.order_created'),
                    cozycafe_app_path('customer/orders/detail.php?id=' . $orderId)
                );
            }
        }

        if ($customerId !== null && $cart['cart_id'] !== null) {
            $clearItems = $pdo->prepare('DELETE FROM cart_items WHERE cart_id = :cart_id');
            $clearItems->execute(['cart_id' => (int) $cart['cart_id']]);
            $clearNotes = $pdo->prepare('UPDATE carts SET order_notes = NULL WHERE id = :id');
            $clearNotes->execute(['id' => (int) $cart['cart_id']]);
        }

        return [
            'id' => $orderId,
            'order_code' => $orderCode,
            'order_type' => $orderType,
            'status' => is_array($existingOrder) ? (string) $existingOrder['status'] : $status,
            'payment_method' => $paymentMethod,
            'payment_code' => null,
            'payment_deadline' => null,
            'subtotal' => $amounts['subtotal'],
            'tax_amount' => $amounts['tax_amount'],
            'total_amount' => $amounts['total_amount'],
            'merged' => is_array($existingOrder),
        ];
    });

    if ($customerId === null) {
        unset($_SESSION['guest_cart']);
    }

    $paymentQuery = $pdo->prepare(
        "SELECT payment_code, expires_at
         FROM payments
         WHERE payable_type = 'order' AND payable_id = :order_id
         ORDER BY id DESC
         LIMIT 1"
    );
    $paymentQuery->execute(['order_id' => (int) $order['id']]);
    $payment = $paymentQuery->fetch();
    if (is_array($payment)) {
        $order['payment_code'] = (string) $payment['payment_code'];
        $order['payment_deadline'] = is_string($payment['expires_at']) ? $payment['expires_at'] : null;
    }

    return $order;
}
