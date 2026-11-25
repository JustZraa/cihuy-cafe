<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'functions.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth_middleware.php';

final class CozyCafeCartException extends RuntimeException
{
    public function __construct(
        public readonly string $translationKey,
        public readonly array $replacements = []
    ) {
        parent::__construct($translationKey);
    }
}

function cozycafe_current_customer_id(): ?int
{
    start_secure_session();
    return cozycafe_customer_session_id_if_active();
}

function cozycafe_with_transaction(PDO $pdo, callable $operation): mixed
{
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $result = $operation();
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function cozycafe_cart_session_items(array $guestCart): array
{
    $items = [];
    foreach ($guestCart as $productId => $item) {
        if ($productId === '_order_notes') {
            continue;
        }

        $productId = (string) $productId;
        if (preg_match('/\A[1-9][0-9]{0,9}\z/', $productId) !== 1) {
            throw new CozyCafeCartException('error.cart_invalid_item');
        }

        if (is_array($item)) {
            $quantity = $item['quantity'] ?? null;
            $notes = $item['item_notes'] ?? '';
        } else {
            $quantity = $item;
            $notes = '';
        }

        if (
            (!is_int($quantity) && !(is_string($quantity) && ctype_digit($quantity)))
            || (int) $quantity < 1
            || (int) $quantity > 4294967295
            || !is_string($notes)
        ) {
            throw new CozyCafeCartException('error.cart_invalid_item');
        }

        $items[(int) $productId] = [
            'product_id' => (int) $productId,
            'quantity' => (int) $quantity,
            'item_notes' => trim($notes),
        ];
    }

    return $items;
}

function cozycafe_cart_order_notes(array $guestCart): string
{
    $notes = $guestCart['_order_notes'] ?? '';
    return is_string($notes) ? trim($notes) : '';
}

function cozycafe_cart_validate_notes(string $notes, bool $orderNote = false): string
{
    $notes = trim($notes);
    $length = preg_match_all('/./us', $notes);
    $maximumLength = $orderNote ? 5000 : 255;
    if (
        $length === false
        || $length > $maximumLength
        || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $notes) === 1
    ) {
        throw new CozyCafeCartException(
            $orderNote ? 'error.cart_order_notes_too_long' : 'error.cart_item_notes_too_long',
            ['count' => $maximumLength]
        );
    }

    return $notes;
}

function cozycafe_cart_get_or_create_id(PDO $pdo, int $customerId): int
{
    if ($customerId < 1) {
        throw new InvalidArgumentException('A positive customer ID is required.');
    }

    $statement = $pdo->prepare(
        'INSERT INTO carts (user_id) VALUES (:user_id)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
    );
    $statement->execute(['user_id' => $customerId]);
    $cartId = (int) $pdo->lastInsertId();
    if ($cartId < 1) {
        throw new RuntimeException('Unable to resolve the customer cart.');
    }

    return $cartId;
}

function cozycafe_cart_get(PDO $pdo, ?int $customerId): array
{
    if ($customerId !== null) {
        $cartQuery = $pdo->prepare('SELECT id, order_notes FROM carts WHERE user_id = :user_id LIMIT 1');
        $cartQuery->execute(['user_id' => $customerId]);
        $cart = $cartQuery->fetch();
        if (!is_array($cart)) {
            return ['items' => [], 'order_notes' => '', 'invalid_item_count' => 0];
        }

        $itemsQuery = $pdo->prepare(
            'SELECT ci.product_id, ci.quantity, ci.item_notes,
                    p.name, p.slug, p.image, p.price, p.stock, p.is_available
             FROM cart_items ci
             INNER JOIN products p ON p.id = ci.product_id
             WHERE ci.cart_id = :cart_id
             ORDER BY ci.created_at ASC, ci.id ASC'
        );
        $itemsQuery->execute(['cart_id' => (int) $cart['id']]);
        $items = $itemsQuery->fetchAll();

        return [
            'items' => $items,
            'order_notes' => is_string($cart['order_notes']) ? $cart['order_notes'] : '',
            'invalid_item_count' => 0,
        ];
    }

    $guestCart = $_SESSION['guest_cart'] ?? [];
    if (!is_array($guestCart)) {
        throw new CozyCafeCartException('error.cart_invalid_item');
    }
    $storedItems = cozycafe_cart_session_items($guestCart);
    if ($storedItems === []) {
        return [
            'items' => [],
            'order_notes' => cozycafe_cart_order_notes($guestCart),
            'invalid_item_count' => 0,
        ];
    }

    $productIds = array_keys($storedItems);
    $placeholders = implode(', ', array_fill(0, count($productIds), '?'));
    $productsQuery = $pdo->prepare(
        'SELECT id AS product_id, name, slug, image, price, stock, is_available
         FROM products
         WHERE id IN (' . $placeholders . ')
         ORDER BY id ASC'
    );
    $productsQuery->execute($productIds);
    $products = [];
    foreach ($productsQuery->fetchAll() as $product) {
        $products[(int) $product['product_id']] = $product;
    }

    $items = [];
    foreach ($storedItems as $productId => $storedItem) {
        if (!isset($products[$productId])) {
            continue;
        }
        $items[] = array_merge($products[$productId], $storedItem);
    }

    return [
        'items' => $items,
        'order_notes' => cozycafe_cart_order_notes($guestCart),
        'invalid_item_count' => count($storedItems) - count($items),
    ];
}

function cozycafe_cart_totals(array $items): array
{
    $subtotalCents = 0;
    foreach ($items as $item) {
        $price = $item['price'] ?? null;
        $quantity = $item['quantity'] ?? null;
        if (!is_numeric($price) || !is_numeric($quantity) || (int) $quantity < 1) {
            throw new CozyCafeCartException('error.cart_invalid_item');
        }

        $priceCents = (int) round((float) $price * 100, 0, PHP_ROUND_HALF_UP);
        $quantity = (int) $quantity;
        if ($priceCents < 0 || $priceCents > intdiv(999999999999, $quantity)) {
            throw new CozyCafeCartException('error.cart_total_too_large');
        }
        $lineTotal = $priceCents * $quantity;
        if ($subtotalCents > 999999999999 - $lineTotal) {
            throw new CozyCafeCartException('error.cart_total_too_large');
        }
        $subtotalCents += $lineTotal;
    }

    $taxCents = (int) round($subtotalCents * 0.10, 0, PHP_ROUND_HALF_UP);
    $totalCents = $subtotalCents + $taxCents;

    return [
        'subtotal_cents' => $subtotalCents,
        'tax_cents' => $taxCents,
        'total_cents' => $totalCents,
        'subtotal' => number_format($subtotalCents / 100, 2, '.', ''),
        'tax_amount' => number_format($taxCents / 100, 2, '.', ''),
        'total_amount' => number_format($totalCents / 100, 2, '.', ''),
        'item_count' => array_sum(array_map(static fn (array $item): int => (int) $item['quantity'], $items)),
    ];
}

function cozycafe_cart_validate_product(PDO $pdo, int $productId, int $quantity): array
{
    if ($productId < 1 || $quantity < 1) {
        throw new CozyCafeCartException('error.cart_invalid_quantity');
    }

    $statement = $pdo->prepare(
        'SELECT id, stock, is_available
         FROM products
         WHERE id = :id
         FOR UPDATE'
    );
    $statement->execute(['id' => $productId]);
    $product = $statement->fetch();
    if (!is_array($product)) {
        throw new CozyCafeCartException('error.cart_product_missing');
    }
    if ((int) $product['is_available'] !== 1 || (int) $product['stock'] < 1) {
        throw new CozyCafeCartException('error.cart_product_unavailable');
    }
    if ($quantity > (int) $product['stock']) {
        throw new CozyCafeCartException('validation.stock_unavailable');
    }

    return $product;
}

function cozycafe_cart_add(
    PDO $pdo,
    ?int $customerId,
    int $productId,
    int $quantity,
    string $itemNotes = ''
): array {
    $itemNotes = cozycafe_cart_validate_notes($itemNotes);
    if ($productId < 1 || $quantity < 1) {
        throw new CozyCafeCartException('error.cart_invalid_quantity');
    }

    if ($customerId === null) {
        $updatedItem = cozycafe_with_transaction($pdo, static function () use (
            $pdo,
            $productId,
            $quantity,
            $itemNotes
        ): array {
            $product = cozycafe_cart_validate_product($pdo, $productId, $quantity);
            $guestCart = $_SESSION['guest_cart'] ?? [];
            if (!is_array($guestCart)) {
                throw new CozyCafeCartException('error.cart_invalid_item');
            }
            $items = cozycafe_cart_session_items($guestCart);
            $existing = $items[$productId] ?? ['quantity' => 0, 'item_notes' => ''];
            $newQuantity = (int) $existing['quantity'] + $quantity;
            if ($newQuantity > (int) $product['stock']) {
                throw new CozyCafeCartException('validation.stock_unavailable');
            }

            return [
                'quantity' => $newQuantity,
                'item_notes' => $itemNotes !== '' ? $itemNotes : (string) $existing['item_notes'],
            ];
        });

        $guestCart = $_SESSION['guest_cart'] ?? [];
        if (!is_array($guestCart)) {
            $guestCart = [];
        }
        $guestCart[$productId] = $updatedItem;
        $_SESSION['guest_cart'] = $guestCart;
    } else {
        cozycafe_with_transaction($pdo, static function () use (
            $pdo,
            $customerId,
            $productId,
            $quantity,
            $itemNotes
        ): void {
            $cartId = cozycafe_cart_get_or_create_id($pdo, $customerId);
            $product = cozycafe_cart_validate_product($pdo, $productId, $quantity);
            $existingQuery = $pdo->prepare(
                'SELECT quantity, item_notes
                 FROM cart_items
                 WHERE cart_id = :cart_id AND product_id = :product_id
                 FOR UPDATE'
            );
            $existingQuery->execute(['cart_id' => $cartId, 'product_id' => $productId]);
            $existing = $existingQuery->fetch();
            $newQuantity = $quantity + (is_array($existing) ? (int) $existing['quantity'] : 0);
            if ($newQuantity > (int) $product['stock']) {
                throw new CozyCafeCartException('validation.stock_unavailable');
            }
            $notes = $itemNotes !== ''
                ? $itemNotes
                : (is_array($existing) ? (string) ($existing['item_notes'] ?? '') : '');

            if (is_array($existing)) {
                $save = $pdo->prepare(
                    'UPDATE cart_items
                     SET quantity = :quantity, item_notes = :item_notes
                     WHERE cart_id = :cart_id AND product_id = :product_id'
                );
            } else {
                $save = $pdo->prepare(
                    'INSERT INTO cart_items (cart_id, product_id, quantity, item_notes)
                     VALUES (:cart_id, :product_id, :quantity, :item_notes)'
                );
            }
            $save->execute([
                'cart_id' => $cartId,
                'product_id' => $productId,
                'quantity' => $newQuantity,
                'item_notes' => $notes,
            ]);
        });
    }

    return cozycafe_cart_get($pdo, $customerId);
}

function cozycafe_cart_update(
    PDO $pdo,
    ?int $customerId,
    int $productId,
    int $quantity,
    string $itemNotes = ''
): array {
    $itemNotes = cozycafe_cart_validate_notes($itemNotes);
    if ($productId < 1 || $quantity < 1) {
        throw new CozyCafeCartException('error.cart_invalid_quantity');
    }

    if ($customerId === null) {
        $guestCart = $_SESSION['guest_cart'] ?? [];
        if (!is_array($guestCart)) {
            throw new CozyCafeCartException('error.cart_invalid_item');
        }
        $items = cozycafe_cart_session_items($guestCart);
        if (!isset($items[$productId])) {
            throw new CozyCafeCartException('error.cart_item_missing');
        }
        $product = cozycafe_with_transaction(
            $pdo,
            static fn (): array => cozycafe_cart_validate_product($pdo, $productId, $quantity)
        );
        if ($quantity > (int) $product['stock']) {
            throw new CozyCafeCartException('validation.stock_unavailable');
        }
        $guestCart[$productId] = [
            'quantity' => $quantity,
            'item_notes' => $itemNotes,
        ];
        $_SESSION['guest_cart'] = $guestCart;
    } else {
        cozycafe_with_transaction($pdo, static function () use (
            $pdo,
            $customerId,
            $productId,
            $quantity,
            $itemNotes
        ): void {
            $cartId = cozycafe_cart_get_or_create_id($pdo, $customerId);
            $product = cozycafe_cart_validate_product($pdo, $productId, $quantity);
            $update = $pdo->prepare(
                'UPDATE cart_items
                 SET quantity = :quantity, item_notes = :item_notes
                 WHERE cart_id = :cart_id AND product_id = :product_id'
            );
            $update->execute([
                'quantity' => $quantity,
                'item_notes' => $itemNotes,
                'cart_id' => $cartId,
                'product_id' => $productId,
            ]);
            if ($update->rowCount() === 0) {
                $check = $pdo->prepare(
                    'SELECT 1 FROM cart_items WHERE cart_id = :cart_id AND product_id = :product_id'
                );
                $check->execute(['cart_id' => $cartId, 'product_id' => $productId]);
                if ($check->fetchColumn() === false) {
                    throw new CozyCafeCartException('error.cart_item_missing');
                }
            }
        });
    }

    return cozycafe_cart_get($pdo, $customerId);
}

function cozycafe_cart_remove(PDO $pdo, ?int $customerId, int $productId): array
{
    if ($productId < 1) {
        throw new CozyCafeCartException('error.cart_invalid_item');
    }

    if ($customerId === null) {
        $guestCart = $_SESSION['guest_cart'] ?? [];
        if (is_array($guestCart)) {
            unset($guestCart[$productId]);
            $_SESSION['guest_cart'] = $guestCart;
        }
    } else {
        cozycafe_with_transaction($pdo, static function () use ($pdo, $customerId, $productId): void {
            $cartQuery = $pdo->prepare('SELECT id FROM carts WHERE user_id = :user_id FOR UPDATE');
            $cartQuery->execute(['user_id' => $customerId]);
            $cartId = $cartQuery->fetchColumn();
            if ($cartId === false) {
                return;
            }
            $delete = $pdo->prepare(
                'DELETE FROM cart_items WHERE cart_id = :cart_id AND product_id = :product_id'
            );
            $delete->execute(['cart_id' => (int) $cartId, 'product_id' => $productId]);
        });
    }

    return cozycafe_cart_get($pdo, $customerId);
}

function cozycafe_cart_update_order_notes(PDO $pdo, ?int $customerId, string $notes): array
{
    $notes = cozycafe_cart_validate_notes($notes, true);
    if ($customerId === null) {
        $guestCart = $_SESSION['guest_cart'] ?? [];
        if (!is_array($guestCart)) {
            $guestCart = [];
        }
        $guestCart['_order_notes'] = $notes;
        $_SESSION['guest_cart'] = $guestCart;
    } else {
        cozycafe_with_transaction($pdo, static function () use ($pdo, $customerId, $notes): void {
            $cartId = cozycafe_cart_get_or_create_id($pdo, $customerId);
            $update = $pdo->prepare('UPDATE carts SET order_notes = :notes WHERE id = :id');
            $update->execute(['notes' => $notes, 'id' => $cartId]);
        });
    }

    return cozycafe_cart_get($pdo, $customerId);
}

function cozycafe_cart_merge_guest(PDO $pdo, int $customerId, array $guestCart): bool
{
    $items = cozycafe_cart_session_items($guestCart);
    $notes = cozycafe_cart_validate_notes(cozycafe_cart_order_notes($guestCart), true);
    if ($items === [] && $notes === '') {
        return false;
    }

    return cozycafe_with_transaction($pdo, static function () use ($pdo, $customerId, $items, $notes): bool {
        $cartId = cozycafe_cart_get_or_create_id($pdo, $customerId);
        $products = [];
        if ($items !== []) {
            $productIds = array_keys($items);
            $placeholders = [];
            $parameters = [];
            foreach ($productIds as $index => $productId) {
                $placeholder = ':product_' . $index;
                $placeholders[] = $placeholder;
                $parameters[$placeholder] = $productId;
            }
            $productQuery = $pdo->prepare(
                'SELECT id, stock, is_available
                 FROM products
                 WHERE id IN (' . implode(', ', $placeholders) . ')
                 ORDER BY id ASC
                 FOR UPDATE'
            );
            $productQuery->execute($parameters);
            foreach ($productQuery->fetchAll() as $product) {
                $products[(int) $product['id']] = $product;
            }
        }

        $existingItems = [];
        if ($items !== []) {
            $itemPlaceholders = [];
            $itemParameters = ['cart_id' => $cartId];
            foreach (array_keys($items) as $index => $productId) {
                $placeholder = ':item_product_' . $index;
                $itemPlaceholders[] = $placeholder;
                $itemParameters[$placeholder] = $productId;
            }
            $existingQuery = $pdo->prepare(
                'SELECT product_id, quantity, item_notes
                 FROM cart_items
                 WHERE cart_id = :cart_id
                   AND product_id IN (' . implode(', ', $itemPlaceholders) . ')
                 FOR UPDATE'
            );
            $existingQuery->execute($itemParameters);
            foreach ($existingQuery->fetchAll() as $existing) {
                $existingItems[(int) $existing['product_id']] = $existing;
            }
        }

        $adjusted = false;
        foreach ($items as $productId => $guestItem) {
            $product = $products[$productId] ?? null;
            $existing = $existingItems[$productId] ?? null;
            if (
                !is_array($product)
                || (int) $product['is_available'] !== 1
                || (int) $product['stock'] < 1
            ) {
                $adjusted = true;
                continue;
            }

            $existingQuantity = is_array($existing) ? (int) $existing['quantity'] : 0;
            $newQuantity = min(
                (int) $product['stock'],
                $existingQuantity + (int) $guestItem['quantity']
            );
            if ($newQuantity < $existingQuantity + (int) $guestItem['quantity']) {
                $adjusted = true;
            }

            $existingNotes = is_array($existing) && is_string($existing['item_notes'])
                ? $existing['item_notes']
                : '';
            $itemNotes = $existingNotes !== '' ? $existingNotes : $guestItem['item_notes'];
            if (is_array($existing)) {
                $save = $pdo->prepare(
                    'UPDATE cart_items
                     SET quantity = :quantity, item_notes = :item_notes
                     WHERE cart_id = :cart_id AND product_id = :product_id'
                );
            } else {
                $save = $pdo->prepare(
                    'INSERT INTO cart_items (cart_id, product_id, quantity, item_notes)
                     VALUES (:cart_id, :product_id, :quantity, :item_notes)'
                );
            }
            $save->execute([
                'quantity' => $newQuantity,
                'item_notes' => $itemNotes,
                'cart_id' => $cartId,
                'product_id' => $productId,
            ]);
        }

        if ($notes !== '') {
            $saveNotes = $pdo->prepare(
                "UPDATE carts
                 SET order_notes = CASE
                     WHEN order_notes IS NULL OR order_notes = '' THEN :notes
                     ELSE order_notes
                 END
                 WHERE id = :id"
            );
            $saveNotes->execute(['notes' => $notes, 'id' => $cartId]);
        }

        return $adjusted;
    });
}
