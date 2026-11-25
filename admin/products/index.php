<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$errors = [];
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$editProduct = null;
$uploadedImagePath = null;
$oldImageToDelete = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_require_post();
    $action = cozycafe_post_string('action');
    $productId = filter_var(cozycafe_post_string('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    try {
        if (in_array($action, ['create', 'update'], true)) {
            $name = trim(cozycafe_post_string('name'));
            $slugInput = trim(cozycafe_post_string('slug'));
            $description = trim(cozycafe_post_string('description'));
            $categoryId = filter_var(cozycafe_post_string('category_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $price = trim(cozycafe_post_string('price'));
            $initialStock = $action === 'create'
                ? filter_var(cozycafe_post_string('stock'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483647]])
                : 0;
            $nameLength = preg_match_all('/./us', $name);
            if (
                $nameLength === false || $nameLength < 2 || $nameLength > 150
                || !is_int($categoryId) || $categoryId < 1
                || preg_match('/\A[0-9]{1,10}(?:\.[0-9]{1,2})?\z/', $price) !== 1
                || ($action === 'create' && $initialStock === false)
                || ($action === 'update' && (!is_int($productId) || $productId < 1))
            ) {
                throw new InvalidArgumentException(__('error.invalid_request'));
            }
            $slug = substr(admin_slug($slugInput !== '' ? $slugInput : $name), 0, 170);
            $isAvailable = isset($_POST['is_available']) ? 1 : 0;
            $isFeatured = isset($_POST['is_featured']) ? 1 : 0;
            $categoriesQuery = $pdo->prepare('SELECT id FROM categories WHERE id = :id AND is_active = 1');
            $categoriesQuery->execute(['id' => $categoryId]);
            if ($categoriesQuery->fetchColumn() === false) {
                throw new InvalidArgumentException(__('admin.error.category_unavailable'));
            }

            if (isset($_FILES['image']) && is_array($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $fileName = secure_upload(
                    $_FILES['image'],
                    dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products',
                    ['image/jpeg', 'image/png', 'image/webp'],
                    5
                );
                $uploadedImagePath = admin_image_path('products', $fileName);
            }

            admin_transaction($pdo, static function () use (
                $pdo,
                $adminId,
                $action,
                $productId,
                $name,
                $slug,
                $description,
                $categoryId,
                $price,
                $initialStock,
                $isAvailable,
                $isFeatured,
                $uploadedImagePath,
                &$oldImageToDelete
            ): void {
                if ($action === 'create') {
                    $insert = $pdo->prepare(
                        'INSERT INTO products
                            (category_id, name, slug, image, description, price, stock, is_available, is_featured)
                         VALUES
                            (:category_id, :name, :slug, :image, :description, :price, :stock, :is_available, :is_featured)'
                    );
                    $insert->execute([
                        'category_id' => $categoryId,
                        'name' => $name,
                        'slug' => $slug,
                        'image' => $uploadedImagePath,
                        'description' => $description !== '' ? $description : null,
                        'price' => $price,
                        'stock' => $initialStock,
                        'is_available' => $isAvailable,
                        'is_featured' => $isFeatured,
                    ]);
                    $id = (int) $pdo->lastInsertId();
                    if ($initialStock > 0) {
                        $stockLog = $pdo->prepare(
                            "INSERT INTO product_stock_logs
                                (product_id, admin_id, change_type, qty_change, stock_before, stock_after, notes)
                             VALUES (:product_id, :admin_id, 'initial', :quantity, 0, :stock_after, :notes)"
                        );
                        $stockLog->execute([
                            'product_id' => $id,
                            'admin_id' => $adminId,
                            'quantity' => $initialStock,
                            'stock_after' => $initialStock,
                            'notes' => 'Initial stock set during product creation.',
                        ]);
                    }
                } else {
                    $lock = $pdo->prepare('SELECT price, image FROM products WHERE id = :id FOR UPDATE');
                    $lock->execute(['id' => $productId]);
                    $previous = $lock->fetch();
                    if (!is_array($previous)) {
                        throw new InvalidArgumentException(__('admin.error.not_found'));
                    }
                    $image = $uploadedImagePath ?? $previous['image'];
                    $update = $pdo->prepare(
                        'UPDATE products
                         SET category_id = :category_id, name = :name, slug = :slug, image = :image,
                             description = :description, price = :price, is_available = :is_available,
                             is_featured = :is_featured
                         WHERE id = :id'
                    );
                    $update->execute([
                        'category_id' => $categoryId,
                        'name' => $name,
                        'slug' => $slug,
                        'image' => $image,
                        'description' => $description !== '' ? $description : null,
                        'price' => $price,
                        'is_available' => $isAvailable,
                        'is_featured' => $isFeatured,
                        'id' => $productId,
                    ]);
                    if ((float) $previous['price'] !== (float) $price) {
                        $priceLog = $pdo->prepare(
                            'INSERT INTO product_price_logs (product_id, admin_id, old_price, new_price)
                             VALUES (:product_id, :admin_id, :old_price, :new_price)'
                        );
                        $priceLog->execute([
                            'product_id' => $productId,
                            'admin_id' => $adminId,
                            'old_price' => $previous['price'],
                            'new_price' => $price,
                        ]);
                    }
                    if (
                        is_string($uploadedImagePath)
                        && is_string($previous['image'])
                        && $previous['image'] !== $uploadedImagePath
                    ) {
                        $oldImageToDelete = $previous['image'];
                    }
                    $id = $productId;
                }
                log_admin_action(
                    $pdo,
                    $adminId,
                    $action === 'create' ? 'create' : 'update',
                    'product',
                    $id,
                    'Product ' . $name . ' was ' . ($action === 'create' ? 'created.' : 'updated.')
                );
            });
            if (is_string($oldImageToDelete)) {
                admin_delete_uploaded_image($oldImageToDelete);
            }
            admin_redirect('products/index.php', 'admin.message.saved');
        }

        if ($action === 'delete' && is_int($productId) && $productId > 0) {
            $oldImageToDelete = null;
            admin_transaction($pdo, static function () use ($pdo, $adminId, $productId, &$oldImageToDelete): void {
                $lock = $pdo->prepare('SELECT name, image, stock FROM products WHERE id = :id FOR UPDATE');
                $lock->execute(['id' => $productId]);
                $product = $lock->fetch();
                if (!is_array($product)) {
                    throw new InvalidArgumentException(__('admin.error.not_found'));
                }
                $history = $pdo->prepare(
                    'SELECT
                        (SELECT COUNT(*) FROM cart_items WHERE product_id = :cart_product_id)
                      + (SELECT COUNT(*) FROM order_items WHERE product_id = :order_product_id)
                      + (SELECT COUNT(*) FROM product_stock_logs WHERE product_id = :stock_product_id)
                      + (SELECT COUNT(*) FROM product_price_logs WHERE product_id = :price_product_id)'
                );
                $history->execute([
                    'cart_product_id' => $productId,
                    'order_product_id' => $productId,
                    'stock_product_id' => $productId,
                    'price_product_id' => $productId,
                ]);
                if ((int) $history->fetchColumn() > 0 || (int) $product['stock'] > 0) {
                    throw new DomainException(__('admin.error.used_product'));
                }
                $pdo->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $productId]);
                $oldImageToDelete = is_string($product['image']) ? $product['image'] : null;
                log_admin_action($pdo, $adminId, 'delete', 'product', $productId, 'Product ' . $product['name'] . ' was deleted.');
            });
            admin_delete_uploaded_image($oldImageToDelete);
            admin_redirect('products/index.php', 'admin.message.deleted');
        }
        throw new InvalidArgumentException(__('admin.error.invalid_action'));
    } catch (InvalidArgumentException | DomainException $exception) {
        $errors[] = $exception->getMessage();
        if (is_string($uploadedImagePath)) {
            admin_delete_uploaded_image($uploadedImagePath);
            $uploadedImagePath = null;
        }
    } catch (PDOException $exception) {
        if (is_string($uploadedImagePath)) {
            admin_delete_uploaded_image($uploadedImagePath);
            $uploadedImagePath = null;
        }
        if (($exception->errorInfo[1] ?? null) === 1062) {
            $errors[] = __('admin.error.duplicate_product');
        } else {
            throw $exception;
        }
    } catch (RuntimeException $exception) {
        if (is_string($uploadedImagePath)) {
            admin_delete_uploaded_image($uploadedImagePath);
            $uploadedImagePath = null;
        }
        $errors[] = $exception->getMessage();
    }
}

if (is_int($editId) && $editId > 0) {
    $editQuery = $pdo->prepare(
        'SELECT id, category_id, name, slug, description, price, stock, image, is_available, is_featured
         FROM products WHERE id = :id LIMIT 1'
    );
    $editQuery->execute(['id' => $editId]);
    $editProduct = $editQuery->fetch();
    if (!is_array($editProduct)) {
        $editId = null;
    }
}
$categories = $pdo->query('SELECT id, name FROM categories ORDER BY sort_order, name')->fetchAll();
$products = $pdo->query(
    'SELECT p.id, p.name, p.slug, p.price, p.stock, p.image, p.is_available, p.is_featured, c.name AS category_name
     FROM products p INNER JOIN categories c ON c.id = p.category_id
     ORDER BY p.created_at DESC, p.id DESC'
)->fetchAll();

admin_layout_start($pdo, __('admin.page.products'), 'products');
foreach ($errors as $error):
    ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endforeach; ?>
<section class="admin-panel card-cozy mb-4">
    <div class="admin-section-heading">
        <h2 class="h4 mb-0"><?= e($editProduct ? __('admin.label.editing') : __('admin.action.create')) ?></h2>
        <?php if ($editProduct): ?><a class="btn btn-outline-cozy btn-sm" href="<?= e(admin_url('products/index.php')) ?>"><?= e(__('button.cancel')) ?></a><?php endif; ?>
    </div>
    <form method="post" enctype="multipart/form-data" action="<?= e(admin_url('products/index.php')) ?>" class="row g-3">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $editProduct ? 'update' : 'create' ?>">
        <?php if ($editProduct): ?><input type="hidden" name="id" value="<?= e((int) $editProduct['id']) ?>"><?php endif; ?>
        <div class="col-12 col-md-6"><label class="form-label" for="product-name"><?= e(__('admin.field.product_name')) ?></label><input class="form-control" id="product-name" name="name" maxlength="150" value="<?= e($editProduct['name'] ?? '') ?>" required></div>
        <div class="col-12 col-md-6"><label class="form-label" for="product-slug"><?= e(__('admin.field.slug')) ?></label><input class="form-control" id="product-slug" name="slug" maxlength="170" value="<?= e($editProduct['slug'] ?? '') ?>" placeholder="<?= e(__('admin.label.slug_hint')) ?>"></div>
        <div class="col-12 col-md-4"><label class="form-label" for="product-category"><?= e(__('admin.field.category')) ?></label><select class="form-select" id="product-category" name="category_id" required><option value=""><?= e(__('admin.field.category')) ?></option><?php foreach ($categories as $category): ?><option value="<?= e((int) $category['id']) ?>" <?= (int) ($editProduct['category_id'] ?? 0) === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-4"><label class="form-label" for="product-price"><?= e(__('admin.field.price')) ?></label><input class="form-control" id="product-price" name="price" type="number" min="0.01" max="9999999999.99" step="0.01" value="<?= e($editProduct['price'] ?? '') ?>" required></div>
        <?php if (!$editProduct): ?>
            <div class="col-6 col-md-4"><label class="form-label" for="product-stock"><?= e(__('admin.field.stock')) ?></label><input class="form-control" id="product-stock" name="stock" type="number" min="0" max="2147483647" step="1" value="0" required></div>
        <?php else: ?>
            <div class="col-6 col-md-4"><label class="form-label"><?= e(__('admin.field.current_stock')) ?></label><input class="form-control" value="<?= e((int) $editProduct['stock']) ?>" readonly></div>
        <?php endif; ?>
        <div class="col-12"><label class="form-label" for="product-description"><?= e(__('admin.field.description')) ?></label><textarea class="form-control" id="product-description" name="description" rows="3"><?= e($editProduct['description'] ?? '') ?></textarea></div>
        <div class="col-12 col-md-6"><label class="form-label" for="product-image"><?= e(__('admin.field.image')) ?></label><input class="form-control" id="product-image" name="image" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"><small class="form-text"><?= e($editProduct ? __('admin.label.upload_optional') : __('admin.label.image_limit')) ?></small></div>
        <div class="col-12 d-flex flex-wrap gap-4">
            <label class="form-check"><input class="form-check-input" type="checkbox" name="is_available" <?= !$editProduct || (int) $editProduct['is_available'] === 1 ? 'checked' : '' ?>> <?= e(__('admin.field.available')) ?></label>
            <label class="form-check"><input class="form-check-input" type="checkbox" name="is_featured" <?= $editProduct && (int) $editProduct['is_featured'] === 1 ? 'checked' : '' ?>> <?= e(__('admin.field.featured')) ?></label>
        </div>
        <div class="col-12"><button class="btn btn-cozy" type="submit"><?= e(__('button.save')) ?></button></div>
    </form>
</section>
<section class="admin-panel card-cozy">
    <h2 class="h4 mb-3"><?= e(__('admin.nav.products')) ?></h2>
    <?php if ($products === []): ?>
        <p class="mb-0 text-secondary"><?= e(__('admin.empty.products')) ?></p>
    <?php else: ?>
        <div class="table-responsive"><table class="table table-cozy align-middle">
            <thead><tr><th><?= e(__('admin.table.product')) ?></th><th><?= e(__('admin.field.category')) ?></th><th><?= e(__('admin.field.price')) ?></th><th><?= e(__('admin.table.stock')) ?></th><th><?= e(__('label.status')) ?></th><th><?= e(__('admin.table.actions')) ?></th></tr></thead>
            <tbody><?php foreach ($products as $product): ?>
                <tr>
                    <td><strong><?= e($product['name']) ?></strong><small class="d-block text-secondary"><?= e($product['slug']) ?><?= (int) $product['is_featured'] === 1 ? ' · ' . e(__('admin.field.featured')) : '' ?></small></td>
                    <td><?= e($product['category_name']) ?></td>
                    <td><?= e(format_rupiah((string) $product['price'])) ?></td>
                    <td><?= e((int) $product['stock']) ?></td>
                    <td><?= (int) $product['is_available'] === 1 ? e(__('admin.field.available')) : e(__('admin.label.status_inactive')) ?></td>
                    <td class="text-nowrap">
                        <a class="btn btn-outline-cozy btn-sm" href="<?= e(admin_url('products/index.php?edit=' . (int) $product['id'])) ?>"><?= e(__('button.edit')) ?></a>
                        <form class="d-inline" method="post" action="<?= e(admin_url('products/index.php')) ?>" onsubmit="return confirm(<?= e(json_encode(__('button.delete'))) ?>)">
                            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e((int) $product['id']) ?>">
                            <button class="btn btn-outline-danger btn-sm" type="submit"><?= e(__('button.delete')) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php admin_layout_end(); ?>
