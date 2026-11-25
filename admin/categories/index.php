<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_require_post();
    $action = cozycafe_post_string('action');
    $categoryId = filter_var(cozycafe_post_string('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    try {
        if (in_array($action, ['create', 'update'], true)) {
            $name = trim(cozycafe_post_string('name'));
            $slugInput = trim(cozycafe_post_string('slug'));
            $sortOrder = filter_var(
                cozycafe_post_string('sort_order'),
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => -2147483648, 'max_range' => 2147483647]]
            );
            $nameLength = preg_match_all('/./us', $name);
            if ($nameLength === false || $nameLength < 2 || $nameLength > 100 || $sortOrder === false) {
                throw new InvalidArgumentException(__('error.invalid_request'));
            }
            if ($action === 'update' && (!is_int($categoryId) || $categoryId < 1)) {
                throw new InvalidArgumentException(__('admin.error.not_found'));
            }
            $slug = substr(admin_slug($slugInput !== '' ? $slugInput : $name), 0, 120);
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            admin_transaction($pdo, static function () use (
                $pdo,
                $adminId,
                $action,
                $categoryId,
                $name,
                $slug,
                $sortOrder,
                $isActive
            ): void {
                if ($action === 'create') {
                    $statement = $pdo->prepare(
                        'INSERT INTO categories (name, slug, sort_order, is_active)
                         VALUES (:name, :slug, :sort_order, :is_active)'
                    );
                    $statement->execute([
                        'name' => $name,
                        'slug' => $slug,
                        'sort_order' => $sortOrder,
                        'is_active' => $isActive,
                    ]);
                    $id = (int) $pdo->lastInsertId();
                } else {
                    $statement = $pdo->prepare(
                        'UPDATE categories
                         SET name = :name, slug = :slug, sort_order = :sort_order, is_active = :is_active
                         WHERE id = :id'
                    );
                    $statement->execute([
                        'name' => $name,
                        'slug' => $slug,
                        'sort_order' => $sortOrder,
                        'is_active' => $isActive,
                        'id' => $categoryId,
                    ]);
                    if ($statement->rowCount() === 0) {
                        $exists = $pdo->prepare('SELECT 1 FROM categories WHERE id = :id');
                        $exists->execute(['id' => $categoryId]);
                        if ($exists->fetchColumn() === false) {
                            throw new InvalidArgumentException(__('admin.error.not_found'));
                        }
                    }
                    $id = $categoryId;
                }

                log_admin_action(
                    $pdo,
                    $adminId,
                    $action === 'create' ? 'create' : 'update',
                    'category',
                    $id,
                    'Category ' . $name . ' was ' . ($action === 'create' ? 'created.' : 'updated.')
                );
            });
            admin_redirect('categories/index.php', 'admin.message.saved');
        }

        if ($action === 'delete' && is_int($categoryId) && $categoryId > 0) {
            admin_transaction($pdo, static function () use ($pdo, $adminId, $categoryId): void {
                $lock = $pdo->prepare('SELECT name FROM categories WHERE id = :id FOR UPDATE');
                $lock->execute(['id' => $categoryId]);
                $category = $lock->fetch();
                if (!is_array($category)) {
                    throw new InvalidArgumentException(__('admin.error.not_found'));
                }
                $products = $pdo->prepare('SELECT COUNT(*) FROM products WHERE category_id = :id');
                $products->execute(['id' => $categoryId]);
                if ((int) $products->fetchColumn() > 0) {
                    throw new DomainException(__('admin.error.used_category'));
                }
                $pdo->prepare('DELETE FROM categories WHERE id = :id')->execute(['id' => $categoryId]);
                log_admin_action($pdo, $adminId, 'delete', 'category', $categoryId, 'Category ' . $category['name'] . ' was deleted.');
            });
            admin_redirect('categories/index.php', 'admin.message.deleted');
        }

        throw new InvalidArgumentException(__('admin.error.invalid_action'));
    } catch (InvalidArgumentException | DomainException $exception) {
        $errors[] = $exception->getMessage();
    } catch (PDOException $exception) {
        if (($exception->errorInfo[1] ?? null) === 1062) {
            $errors[] = __('admin.error.duplicate_category');
        } else {
            throw $exception;
        }
    }
}

$categories = $pdo->query('SELECT id, name, slug, sort_order, is_active, created_at FROM categories ORDER BY sort_order ASC, name ASC')->fetchAll();
admin_layout_start($pdo, __('admin.page.categories'), 'categories');
foreach ($errors as $error):
    ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endforeach; ?>
<section class="admin-panel card-cozy mb-4">
    <h2 class="h4"><?= e(__('admin.action.create')) ?></h2>
    <form method="post" action="<?= e(admin_url('categories/index.php')) ?>" class="row g-3">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="col-12 col-md-4">
            <label class="form-label" for="category-name"><?= e(__('admin.field.title')) ?></label>
            <input class="form-control" id="category-name" name="name" maxlength="100" required>
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label" for="category-slug"><?= e(__('admin.field.slug')) ?></label>
            <input class="form-control" id="category-slug" name="slug" maxlength="120">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label" for="category-sort"><?= e(__('admin.field.sort_order')) ?></label>
            <input class="form-control" id="category-sort" name="sort_order" type="number" value="0" required>
        </div>
        <div class="col-6 col-md-2 d-flex align-items-end">
            <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_active" checked> <?= e(__('admin.field.active')) ?></label>
        </div>
        <div class="col-12"><button class="btn btn-cozy" type="submit"><?= e(__('admin.action.create')) ?></button></div>
    </form>
</section>
<section class="admin-panel card-cozy">
    <h2 class="h4 mb-3"><?= e(__('admin.nav.categories')) ?></h2>
    <?php if ($categories === []): ?>
        <p class="mb-0 text-secondary"><?= e(__('admin.empty.categories')) ?></p>
    <?php else: ?>
        <div class="table-responsive"><table class="table table-cozy align-middle">
            <thead><tr><th><?= e(__('admin.field.title')) ?></th><th><?= e(__('admin.field.slug')) ?></th><th><?= e(__('admin.field.sort_order')) ?></th><th><?= e(__('label.status')) ?></th><th><?= e(__('admin.table.actions')) ?></th></tr></thead>
            <tbody><?php foreach ($categories as $category): ?>
                <tr>
                    <td colspan="5">
                        <form method="post" action="<?= e(admin_url('categories/index.php')) ?>" class="row g-2 align-items-center">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?= e((int) $category['id']) ?>">
                            <div class="col-12 col-lg-3"><label class="visually-hidden" for="cat-name-<?= e((int) $category['id']) ?>"><?= e(__('admin.field.title')) ?></label><input class="form-control" id="cat-name-<?= e((int) $category['id']) ?>" name="name" maxlength="100" value="<?= e($category['name']) ?>" required></div>
                            <div class="col-12 col-lg-3"><label class="visually-hidden" for="cat-slug-<?= e((int) $category['id']) ?>"><?= e(__('admin.field.slug')) ?></label><input class="form-control" id="cat-slug-<?= e((int) $category['id']) ?>" name="slug" maxlength="120" value="<?= e($category['slug']) ?>" required></div>
                            <div class="col-6 col-lg-1"><label class="visually-hidden" for="cat-sort-<?= e((int) $category['id']) ?>"><?= e(__('admin.field.sort_order')) ?></label><input class="form-control" id="cat-sort-<?= e((int) $category['id']) ?>" name="sort_order" type="number" value="<?= e((int) $category['sort_order']) ?>" required></div>
                            <div class="col-6 col-lg-2"><label class="form-check"><input class="form-check-input" type="checkbox" name="is_active" <?= (int) $category['is_active'] === 1 ? 'checked' : '' ?>> <?= e(__('admin.field.active')) ?></label></div>
                            <div class="col-6 col-lg-1"><button class="btn btn-outline-cozy btn-sm w-100" type="submit"><?= e(__('button.save')) ?></button></div>
                            <div class="col-6 col-lg-2"><button class="btn btn-outline-danger btn-sm w-100" type="submit" form="delete-category-<?= e((int) $category['id']) ?>"><?= e(__('button.delete')) ?></button></div>
                        </form>
                        <form id="delete-category-<?= e((int) $category['id']) ?>" method="post" action="<?= e(admin_url('categories/index.php')) ?>" hidden>
                            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e((int) $category['id']) ?>">
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php admin_layout_end(); ?>
