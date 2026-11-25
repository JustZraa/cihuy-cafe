<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'storefront.php';

$errors = [];
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$editBanner = null;
$uploadedImagePath = null;
$oldImageToDelete = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_require_post();
    $action = cozycafe_post_string('action');
    $bannerId = filter_var(cozycafe_post_string('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    try {
        if (in_array($action, ['create', 'update'], true)) {
            $title = trim(cozycafe_post_string('title'));
            $description = trim(cozycafe_post_string('description'));
            $buttonText = trim(cozycafe_post_string('button_text'));
            $buttonUrl = trim(cozycafe_post_string('button_url'));
            $startDate = cozycafe_post_string('start_date');
            $endDate = cozycafe_post_string('end_date');
            $sortOrder = filter_var(
                cozycafe_post_string('sort_order'),
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => -2147483648, 'max_range' => 2147483647]]
            );
            $titleLength = preg_match_all('/./us', $title);
            $dateStart = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
            $dateEnd = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
            if (
                $titleLength === false || $titleLength < 2 || $titleLength > 150
                || strlen($buttonText) > 80 || strlen($buttonUrl) > 255
                || strlen($description) > 5000
                || $sortOrder === false
                || $dateStart === false || $dateStart->format('Y-m-d') !== $startDate
                || $dateEnd === false || $dateEnd->format('Y-m-d') !== $endDate
                || $dateEnd < $dateStart
                || ($action === 'update' && (!is_int($bannerId) || $bannerId < 1))
            ) {
                throw new InvalidArgumentException(__('admin.error.invalid_banner_dates'));
            }
            $storedButtonUrl = null;
            if ($buttonUrl !== '') {
                if (cozycafe_storefront_url($buttonUrl) === null) {
                    throw new InvalidArgumentException(__('admin.error.invalid_local_url'));
                }
                $buttonPath = parse_url($buttonUrl, PHP_URL_PATH);
                if (!is_string($buttonPath) || !cozycafe_storefront_route_available($buttonPath)) {
                    throw new InvalidArgumentException(__('admin.error.invalid_local_url'));
                }
                $storedButtonUrl = $buttonUrl;
            }
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            if (isset($_FILES['image']) && is_array($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $fileName = secure_upload(
                    $_FILES['image'],
                    dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'banners',
                    ['image/jpeg', 'image/png', 'image/webp'],
                    5
                );
                $uploadedImagePath = admin_image_path('banners', $fileName);
            } elseif ($action === 'create') {
                throw new InvalidArgumentException(__('admin.error.banner_image_required'));
            }

            admin_transaction($pdo, static function () use (
                $pdo,
                $adminId,
                $action,
                $bannerId,
                $title,
                $description,
                $buttonText,
                $storedButtonUrl,
                $startDate,
                $endDate,
                $sortOrder,
                $isActive,
                $uploadedImagePath,
                &$oldImageToDelete
            ): void {
                if ($action === 'create') {
                    $insert = $pdo->prepare(
                        'INSERT INTO banners
                            (title, description, image, button_text, button_url, start_date, end_date, sort_order, is_active)
                         VALUES (:title, :description, :image, :button_text, :button_url, :start_date, :end_date, :sort_order, :is_active)'
                    );
                    $insert->execute([
                        'title' => $title,
                        'description' => $description !== '' ? $description : null,
                        'image' => $uploadedImagePath,
                        'button_text' => $buttonText !== '' ? $buttonText : null,
                        'button_url' => $storedButtonUrl,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'sort_order' => $sortOrder,
                        'is_active' => $isActive,
                    ]);
                    $id = (int) $pdo->lastInsertId();
                } else {
                    $lock = $pdo->prepare('SELECT image FROM banners WHERE id = :id FOR UPDATE');
                    $lock->execute(['id' => $bannerId]);
                    $previous = $lock->fetch();
                    if (!is_array($previous)) {
                        throw new InvalidArgumentException(__('admin.error.not_found'));
                    }
                    $image = $uploadedImagePath ?? $previous['image'];
                    $update = $pdo->prepare(
                        'UPDATE banners
                         SET title = :title, description = :description, image = :image,
                             button_text = :button_text, button_url = :button_url,
                             start_date = :start_date, end_date = :end_date,
                             sort_order = :sort_order, is_active = :is_active
                         WHERE id = :id'
                    );
                    $update->execute([
                        'title' => $title,
                        'description' => $description !== '' ? $description : null,
                        'image' => $image,
                        'button_text' => $buttonText !== '' ? $buttonText : null,
                        'button_url' => $storedButtonUrl,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'sort_order' => $sortOrder,
                        'is_active' => $isActive,
                        'id' => $bannerId,
                    ]);
                    if (is_string($uploadedImagePath) && $previous['image'] !== $uploadedImagePath) {
                        $oldImageToDelete = (string) $previous['image'];
                    }
                    $id = $bannerId;
                }
                log_admin_action($pdo, $adminId, $action, 'banner', $id, 'Banner ' . $title . ' was ' . ($action === 'create' ? 'created.' : 'updated.'));
            });
            if (is_string($oldImageToDelete)) {
                admin_delete_uploaded_image($oldImageToDelete);
            }
            admin_redirect('banners/index.php', 'admin.message.saved');
        }
        if ($action === 'delete' && is_int($bannerId) && $bannerId > 0) {
            admin_transaction($pdo, static function () use ($pdo, $adminId, $bannerId, &$oldImageToDelete): void {
                $query = $pdo->prepare('SELECT title, image FROM banners WHERE id = :id FOR UPDATE');
                $query->execute(['id' => $bannerId]);
                $banner = $query->fetch();
                if (!is_array($banner)) {
                    throw new InvalidArgumentException(__('admin.error.not_found'));
                }
                $pdo->prepare('DELETE FROM banners WHERE id = :id')->execute(['id' => $bannerId]);
                $oldImageToDelete = (string) $banner['image'];
                log_admin_action($pdo, $adminId, 'delete', 'banner', $bannerId, 'Banner ' . $banner['title'] . ' was deleted.');
            });
            admin_delete_uploaded_image($oldImageToDelete);
            admin_redirect('banners/index.php', 'admin.message.deleted');
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
            $errors[] = __('admin.error.duplicate_banner');
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
    $query = $pdo->prepare('SELECT * FROM banners WHERE id = :id');
    $query->execute(['id' => $editId]);
    $editBanner = $query->fetch();
    if (!is_array($editBanner)) {
        $editId = null;
    }
}
$banners = $pdo->query('SELECT id, title, image, start_date, end_date, sort_order, is_active FROM banners ORDER BY sort_order, id DESC')->fetchAll();

admin_layout_start($pdo, __('admin.page.banners'), 'banners');
foreach ($errors as $error):
    ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endforeach; ?>
<section class="admin-panel card-cozy mb-4">
    <div class="admin-section-heading"><h2 class="h4 mb-0"><?= e($editBanner ? __('admin.label.editing') : __('admin.action.create')) ?></h2><?php if ($editBanner): ?><a class="btn btn-outline-cozy btn-sm" href="<?= e(admin_url('banners/index.php')) ?>"><?= e(__('button.cancel')) ?></a><?php endif; ?></div>
    <form method="post" enctype="multipart/form-data" action="<?= e(admin_url('banners/index.php')) ?>" class="row g-3">
        <?= csrf_field() ?><input type="hidden" name="action" value="<?= $editBanner ? 'update' : 'create' ?>"><?php if ($editBanner): ?><input type="hidden" name="id" value="<?= e((int) $editBanner['id']) ?>"><?php endif; ?>
        <div class="col-12 col-md-6"><label class="form-label" for="banner-title"><?= e(__('admin.field.title')) ?></label><input class="form-control" id="banner-title" name="title" maxlength="150" value="<?= e($editBanner['title'] ?? '') ?>" required></div>
        <div class="col-12 col-md-6"><label class="form-label" for="banner-image"><?= e(__('admin.field.image')) ?></label><input class="form-control" id="banner-image" name="image" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" <?= $editBanner ? '' : 'required' ?>></div>
        <div class="col-12"><label class="form-label" for="banner-description"><?= e(__('admin.field.description')) ?></label><textarea class="form-control" id="banner-description" name="description" rows="2" maxlength="5000"><?= e($editBanner['description'] ?? '') ?></textarea></div>
        <div class="col-6 col-md-3"><label class="form-label" for="banner-button-text"><?= e(__('admin.field.button_text')) ?></label><input class="form-control" id="banner-button-text" name="button_text" maxlength="80" value="<?= e($editBanner['button_text'] ?? '') ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label" for="banner-button-url"><?= e(__('admin.field.button_url')) ?></label><input class="form-control" id="banner-button-url" name="button_url" maxlength="255" value="<?= e($editBanner['button_url'] ?? '') ?>" placeholder="customer/menu.php"></div>
        <div class="col-6 col-md-2"><label class="form-label" for="banner-start"><?= e(__('admin.field.start_date')) ?></label><input class="form-control" id="banner-start" name="start_date" type="date" value="<?= e($editBanner['start_date'] ?? '') ?>" required></div>
        <div class="col-6 col-md-2"><label class="form-label" for="banner-end"><?= e(__('admin.field.end_date')) ?></label><input class="form-control" id="banner-end" name="end_date" type="date" value="<?= e($editBanner['end_date'] ?? '') ?>" required></div>
        <div class="col-6 col-md-1"><label class="form-label" for="banner-sort"><?= e(__('admin.field.sort_order')) ?></label><input class="form-control" id="banner-sort" name="sort_order" type="number" value="<?= e((int) ($editBanner['sort_order'] ?? 0)) ?>" required></div>
        <div class="col-6 col-md-1 d-flex align-items-end"><label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_active" <?= !$editBanner || (int) $editBanner['is_active'] === 1 ? 'checked' : '' ?>> <?= e(__('admin.field.active')) ?></label></div>
        <div class="col-12"><button class="btn btn-cozy" type="submit"><?= e(__('button.save')) ?></button></div>
    </form>
</section>
<section class="admin-panel card-cozy">
    <h2 class="h4 mb-3"><?= e(__('admin.nav.banners')) ?></h2>
    <?php if ($banners === []): ?><p class="mb-0 text-secondary"><?= e(__('admin.empty.banners')) ?></p><?php else: ?>
        <div class="table-responsive"><table class="table table-cozy align-middle"><thead><tr><th><?= e(__('admin.field.title')) ?></th><th><?= e(__('admin.field.start_date')) ?></th><th><?= e(__('admin.field.end_date')) ?></th><th><?= e(__('admin.field.sort_order')) ?></th><th><?= e(__('admin.field.active')) ?></th><th></th></tr></thead><tbody>
        <?php foreach ($banners as $banner): ?><tr>
            <td><?= e($banner['title']) ?></td><td><?= e($banner['start_date']) ?></td><td><?= e($banner['end_date']) ?></td><td><?= e((int) $banner['sort_order']) ?></td><td><?= (int) $banner['is_active'] === 1 ? e(__('admin.label.status_active')) : e(__('admin.label.status_inactive')) ?></td>
            <td class="text-nowrap"><a class="btn btn-outline-cozy btn-sm" href="<?= e(admin_url('banners/index.php?edit=' . (int) $banner['id'])) ?>"><?= e(__('button.edit')) ?></a>
                <form class="d-inline" method="post" action="<?= e(admin_url('banners/index.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e((int) $banner['id']) ?>"><button class="btn btn-outline-danger btn-sm" type="submit"><?= e(__('button.delete')) ?></button></form>
            </td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
</section>
<?php admin_layout_end(); ?>
