<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_require_post();
    $productId = filter_var(cozycafe_post_string('product_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $quantityChange = filter_var(
        cozycafe_post_string('quantity_change'),
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => -2147483648, 'max_range' => 2147483647]]
    );
    $notes = trim(cozycafe_post_string('notes'));
    if (
        !is_int($productId) || $productId < 1
        || !is_int($quantityChange) || $quantityChange === 0
        || strlen($notes) > 2000
    ) {
        $errors[] = __('admin.error.invalid_stock');
    } else {
        try {
            admin_transaction($pdo, static function () use ($pdo, $adminId, $productId, $quantityChange, $notes): void {
                $productQuery = $pdo->prepare('SELECT id, name, stock FROM products WHERE id = :id FOR UPDATE');
                $productQuery->execute(['id' => $productId]);
                $product = $productQuery->fetch();
                if (!is_array($product)) {
                    throw new InvalidArgumentException(__('admin.error.not_found'));
                }
                $stockBefore = (int) $product['stock'];
                $stockAfter = $stockBefore + $quantityChange;
                if ($stockAfter < 0 || $stockAfter > 4294967295) {
                    throw new DomainException(__('admin.error.stock_negative'));
                }
                $update = $pdo->prepare('UPDATE products SET stock = :stock WHERE id = :id');
                $update->execute(['stock' => $stockAfter, 'id' => $productId]);
                $log = $pdo->prepare(
                    'INSERT INTO product_stock_logs
                        (product_id, admin_id, change_type, qty_change, stock_before, stock_after, notes)
                     VALUES (:product_id, :admin_id, :change_type, :qty_change, :stock_before, :stock_after, :notes)'
                );
                $log->execute([
                    'product_id' => $productId,
                    'admin_id' => $adminId,
                    'change_type' => $quantityChange > 0 ? 'restock' : 'adjustment',
                    'qty_change' => $quantityChange,
                    'stock_before' => $stockBefore,
                    'stock_after' => $stockAfter,
                    'notes' => $notes !== '' ? $notes : null,
                ]);
                log_admin_action(
                    $pdo,
                    $adminId,
                    'stock_adjustment',
                    'product',
                    $productId,
                    'Stock for ' . $product['name'] . ' changed by ' . $quantityChange . '.'
                );
            });
            admin_redirect('inventory/index.php', 'admin.message.stock_updated');
        } catch (InvalidArgumentException | DomainException $exception) {
            $errors[] = $exception->getMessage();
        }
    }
}

$products = $pdo->query(
    'SELECT id, name, stock FROM products ORDER BY name ASC'
)->fetchAll();
$history = $pdo->query(
    'SELECT l.id, l.change_type, l.qty_change, l.stock_before, l.stock_after, l.notes, l.created_at,
            p.name AS product_name, a.name AS admin_name
     FROM product_stock_logs l
     INNER JOIN products p ON p.id = l.product_id
     LEFT JOIN admins a ON a.id = l.admin_id
     ORDER BY l.created_at DESC, l.id DESC
     LIMIT 100'
)->fetchAll();
admin_layout_start($pdo, __('admin.page.inventory'), 'inventory');
foreach ($errors as $error):
    ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endforeach; ?>
<section class="admin-panel card-cozy mb-4">
    <h2 class="h4"><?= e(__('admin.action.adjust_stock')) ?></h2>
    <?php if ($products === []): ?>
        <p class="mb-0 text-secondary"><?= e(__('admin.empty.products')) ?></p>
    <?php else: ?>
        <form method="post" action="<?= e(admin_url('inventory/index.php')) ?>" class="row g-3">
            <?= csrf_field() ?>
            <div class="col-12 col-md-5"><label class="form-label" for="inventory-product"><?= e(__('admin.table.product')) ?></label><select class="form-select" id="inventory-product" name="product_id" required><option value=""><?= e(__('admin.field.product_name')) ?></option><?php foreach ($products as $product): ?><option value="<?= e((int) $product['id']) ?>"><?= e($product['name']) ?> (<?= e((int) $product['stock']) ?>)</option><?php endforeach; ?></select></div>
            <div class="col-12 col-md-3"><label class="form-label" for="inventory-change"><?= e(__('admin.field.stock_change')) ?></label><input class="form-control" id="inventory-change" name="quantity_change" type="number" step="1" required></div>
            <div class="col-12 col-md-4"><label class="form-label" for="inventory-notes"><?= e(__('admin.field.notes')) ?></label><input class="form-control" id="inventory-notes" name="notes" maxlength="2000"></div>
            <div class="col-12"><button class="btn btn-cozy" type="submit"><?= e(__('admin.action.adjust_stock')) ?></button></div>
        </form>
    <?php endif; ?>
</section>
<section class="admin-panel card-cozy">
    <h2 class="h4 mb-3"><?= e(__('admin.inventory_history')) ?></h2>
    <?php if ($history === []): ?>
        <p class="mb-0 text-secondary"><?= e(__('common.no_data')) ?></p>
    <?php else: ?>
        <div class="table-responsive"><table class="table table-cozy align-middle">
            <thead><tr><th><?= e(__('admin.table.created')) ?></th><th><?= e(__('admin.table.product')) ?></th><th><?= e(__('admin.inventory.change_type')) ?></th><th><?= e(__('admin.field.current_stock')) ?></th><th><?= e(__('admin.table.quantity')) ?></th><th><?= e(__('admin.field.notes')) ?></th><th><?= e(__('admin.table.customer')) ?></th></tr></thead>
            <tbody><?php foreach ($history as $row): ?>
                <tr>
                    <td><?= e(format_wib((string) $row['created_at'])) ?></td><td><?= e($row['product_name']) ?></td>
                    <td><?= e(__('admin.inventory.type.' . $row['change_type'])) ?></td>
                    <td><?= e((int) $row['stock_before']) ?> → <?= e((int) $row['stock_after']) ?></td>
                    <td><?= e((int) $row['qty_change'] > 0 ? '+' . (int) $row['qty_change'] : (int) $row['qty_change']) ?></td>
                    <td><?= e($row['notes'] ?? '-') ?></td><td><?= e($row['admin_name'] ?? '-') ?></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php admin_layout_end(); ?>
