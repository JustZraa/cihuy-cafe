<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'cart_service.php';

final class CozyCafeOrderException extends RuntimeException
{
    public function __construct(public readonly string $translationKey)
    {
        parent::__construct($translationKey);
    }
}

function cozycafe_order_stock_movements(PDO $pdo, int $orderId): array
{
    $statement = $pdo->prepare(
        "SELECT product_id,
                SUM(CASE WHEN change_type = 'order_deduct' THEN -qty_change ELSE 0 END) AS deducted,
                SUM(CASE WHEN change_type = 'order_restore' THEN qty_change ELSE 0 END) AS restored
         FROM product_stock_logs
         WHERE order_id = :order_id
           AND change_type IN ('order_deduct', 'order_restore')
         GROUP BY product_id
         ORDER BY product_id ASC"
    );
    $statement->execute(['order_id' => $orderId]);
    $movements = [];
    foreach ($statement->fetchAll() as $row) {
        $deducted = (int) $row['deducted'];
        $restored = (int) $row['restored'];
        if ($deducted < 0 || $restored < 0 || $restored > $deducted) {
            throw new RuntimeException('Order stock movement history is inconsistent.');
        }
        $movements[(int) $row['product_id']] = [
            'deducted' => $deducted,
            'restored' => $restored,
            'outstanding' => $deducted - $restored,
        ];
    }

    return $movements;
}

function cozycafe_order_stock_products(PDO $pdo, array $productIds): array
{
    if ($productIds === []) {
        return [];
    }
    sort($productIds, SORT_NUMERIC);
    $placeholders = [];
    $parameters = [];
    foreach (array_values($productIds) as $index => $productId) {
        $placeholder = ':product_' . $index;
        $placeholders[] = $placeholder;
        $parameters[$placeholder] = $productId;
    }

    $statement = $pdo->prepare(
        'SELECT id, stock
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

    return $products;
}

function deduct_order_stock(PDO $pdo, int $orderId, ?int $adminId = null): void
{
    if ($orderId < 1 || ($adminId !== null && $adminId < 1)) {
        throw new InvalidArgumentException('A valid order ID and optional admin ID are required.');
    }

    cozycafe_with_transaction($pdo, static function () use ($pdo, $orderId, $adminId): void {
        $orderQuery = $pdo->prepare('SELECT id, status FROM orders WHERE id = :id FOR UPDATE');
        $orderQuery->execute(['id' => $orderId]);
        $order = $orderQuery->fetch();
        if (!is_array($order) || in_array($order['status'], ['Cancelled', 'Completed'], true)) {
            throw new RuntimeException('Stock cannot be deducted for this order.');
        }

        $itemsQuery = $pdo->prepare(
            'SELECT product_id, SUM(quantity) AS quantity
             FROM order_items
             WHERE order_id = :order_id AND product_id IS NOT NULL
             GROUP BY product_id
             ORDER BY product_id ASC'
        );
        $itemsQuery->execute(['order_id' => $orderId]);
        $desired = [];
        foreach ($itemsQuery->fetchAll() as $item) {
            $desired[(int) $item['product_id']] = (int) $item['quantity'];
        }
        if ($desired === []) {
            return;
        }

        $movements = cozycafe_order_stock_movements($pdo, $orderId);
        $deltas = [];
        foreach ($desired as $productId => $quantity) {
            $outstanding = $movements[$productId]['outstanding'] ?? 0;
            if ($quantity < $outstanding) {
                throw new RuntimeException('Order item quantities are lower than already deducted stock.');
            }
            $delta = $quantity - $outstanding;
            if ($delta > 0) {
                if ($delta > 2147483647) {
                    throw new RuntimeException('A stock change exceeds the supported log quantity.');
                }
                $deltas[$productId] = $delta;
            }
        }
        if ($deltas === []) {
            return;
        }

        $products = cozycafe_order_stock_products($pdo, array_keys($deltas));
        $updateStock = $pdo->prepare(
            'UPDATE products
             SET stock = stock - :quantity
             WHERE id = :product_id AND stock >= :minimum_stock'
        );
        $insertLog = $pdo->prepare(
            "INSERT INTO product_stock_logs
                (product_id, admin_id, order_id, change_type, qty_change, stock_before, stock_after, notes)
             VALUES
                (:product_id, :admin_id, :order_id, 'order_deduct', :qty_change, :stock_before, :stock_after, :notes)"
        );
        foreach ($deltas as $productId => $quantity) {
            if (!isset($products[$productId])) {
                throw new RuntimeException('An order item references a missing product.');
            }
            $stockBefore = (int) $products[$productId]['stock'];
            if ($quantity > $stockBefore) {
                throw new CozyCafeOrderException('validation.stock_unavailable');
            }
            $stockAfter = $stockBefore - $quantity;
            $updateStock->execute([
                'quantity' => $quantity,
                'product_id' => $productId,
                'minimum_stock' => $quantity,
            ]);
            if ($updateStock->rowCount() !== 1) {
                throw new CozyCafeOrderException('validation.stock_unavailable');
            }
            $insertLog->execute([
                'product_id' => $productId,
                'admin_id' => $adminId,
                'order_id' => $orderId,
                'qty_change' => -$quantity,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'notes' => 'Stock deducted for order.',
            ]);
        }
    });
}

function restore_order_stock(PDO $pdo, int $orderId, ?int $adminId = null): void
{
    if ($orderId < 1 || ($adminId !== null && $adminId < 1)) {
        throw new InvalidArgumentException('A valid order ID and optional admin ID are required.');
    }

    cozycafe_with_transaction($pdo, static function () use ($pdo, $orderId, $adminId): void {
        $orderQuery = $pdo->prepare('SELECT id, status FROM orders WHERE id = :id FOR UPDATE');
        $orderQuery->execute(['id' => $orderId]);
        $order = $orderQuery->fetch();
        if (!is_array($order) || $order['status'] !== 'Cancelled') {
            throw new RuntimeException('Only a cancelled order can have its stock restored.');
        }

        $movements = cozycafe_order_stock_movements($pdo, $orderId);
        $deltas = [];
        foreach ($movements as $productId => $movement) {
            if ($movement['outstanding'] > 0) {
                if ($movement['outstanding'] > 2147483647) {
                    throw new RuntimeException('A stock change exceeds the supported log quantity.');
                }
                $deltas[$productId] = $movement['outstanding'];
            }
        }
        if ($deltas === []) {
            return;
        }

        $products = cozycafe_order_stock_products($pdo, array_keys($deltas));
        $updateStock = $pdo->prepare(
            'UPDATE products
             SET stock = stock + :quantity
             WHERE id = :product_id AND stock <= :maximum_stock'
        );
        $insertLog = $pdo->prepare(
            "INSERT INTO product_stock_logs
                (product_id, admin_id, order_id, change_type, qty_change, stock_before, stock_after, notes)
             VALUES
                (:product_id, :admin_id, :order_id, 'order_restore', :qty_change, :stock_before, :stock_after, :notes)"
        );
        foreach ($deltas as $productId => $quantity) {
            if (!isset($products[$productId])) {
                throw new RuntimeException('A stock log references a missing product.');
            }
            $stockBefore = (int) $products[$productId]['stock'];
            if ($quantity > 4294967295 - $stockBefore) {
                throw new RuntimeException('Restoring this order would exceed the product stock limit.');
            }
            $stockAfter = $stockBefore + $quantity;
            $updateStock->execute([
                'quantity' => $quantity,
                'product_id' => $productId,
                'maximum_stock' => 4294967295 - $quantity,
            ]);
            if ($updateStock->rowCount() !== 1) {
                throw new RuntimeException('The order stock could not be restored.');
            }
            $insertLog->execute([
                'product_id' => $productId,
                'admin_id' => $adminId,
                'order_id' => $orderId,
                'qty_change' => $quantity,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'notes' => 'Stock restored after order cancellation.',
            ]);
        }
    });
}

function cozycafe_cancel_customer_order(PDO $pdo, int $orderId, int $customerId): void
{
    if ($orderId < 1 || $customerId < 1) {
        throw new CozyCafeOrderException('error.order_not_found');
    }

    cozycafe_with_transaction($pdo, static function () use ($pdo, $orderId, $customerId): void {
        $ownedOrderQuery = $pdo->prepare(
            'SELECT id, order_type FROM orders WHERE id = :id AND user_id = :user_id LIMIT 1'
        );
        $ownedOrderQuery->execute(['id' => $orderId, 'user_id' => $customerId]);
        $ownedOrder = $ownedOrderQuery->fetch();
        if (!is_array($ownedOrder)) {
            throw new CozyCafeOrderException('error.order_not_found');
        }

        $lockPayment = static function () use ($pdo, $orderId): array|false {
            $paymentIdQuery = $pdo->prepare(
                "SELECT id
                 FROM payments
                 WHERE payable_type = 'order' AND payable_id = :order_id
                 ORDER BY id DESC
                 LIMIT 1"
            );
            $paymentIdQuery->execute(['order_id' => $orderId]);
            $paymentId = $paymentIdQuery->fetchColumn();
            if ($paymentId === false) {
                return false;
            }
            $paymentQuery = $pdo->prepare('SELECT id, status FROM payments WHERE id = :id FOR UPDATE');
            $paymentQuery->execute(['id' => (int) $paymentId]);
            $payment = $paymentQuery->fetch();
            return is_array($payment) ? $payment : false;
        };
        $lockOrder = static function () use ($pdo, $orderId, $customerId): array|false {
            $orderQuery = $pdo->prepare(
                'SELECT id, status
                 FROM orders
                 WHERE id = :id AND user_id = :user_id
                 FOR UPDATE'
            );
            $orderQuery->execute(['id' => $orderId, 'user_id' => $customerId]);
            $order = $orderQuery->fetch();
            return is_array($order) ? $order : false;
        };
        if ($ownedOrder['order_type'] === 'takeaway') {
            $payment = $lockPayment();
            $order = $lockOrder();
        } else {
            $order = $lockOrder();
            $payment = $lockPayment();
        }
        if (!is_array($order)) {
            throw new CozyCafeOrderException('error.order_not_found');
        }
        if (!in_array($order['status'], ['Pending', 'Waiting for Payment'], true)) {
            throw new CozyCafeOrderException('error.order_not_cancellable');
        }
        if (is_array($payment) && in_array($payment['status'], ['Waiting Verification', 'Verified'], true)) {
            throw new CozyCafeOrderException('error.order_payment_locked');
        }

        $reason = __('order.customer_cancel_reason');
        $update = $pdo->prepare(
            "UPDATE orders
             SET status = 'Cancelled', cancel_reason = :reason
             WHERE id = :id AND status IN ('Pending', 'Waiting for Payment')"
        );
        $update->execute(['reason' => $reason, 'id' => $orderId]);
        if ($update->rowCount() !== 1) {
            throw new CozyCafeOrderException('error.order_not_cancellable');
        }
        log_order_status(
            $pdo,
            $orderId,
            (string) $order['status'],
            'Cancelled',
            'customer',
            $customerId,
            $reason
        );
        if (is_array($payment) && in_array($payment['status'], ['Pending', 'Rejected'], true)) {
            $expirePayment = $pdo->prepare(
                "UPDATE payments SET status = 'Expired' WHERE id = :id AND status IN ('Pending', 'Rejected')"
            );
            $expirePayment->execute(['id' => (int) $payment['id']]);
        }
        restore_order_stock($pdo, $orderId);
    });
}
