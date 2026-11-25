<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup_service.php';

$downloadId = filter_input(INPUT_GET, 'download', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (array_key_exists('download', $_GET) && (!is_int($downloadId) || $downloadId < 1)) {
    http_response_code(400);
    exit(e(__('error.invalid_request')));
}
if (is_int($downloadId) && $downloadId > 0) {
    $query = $pdo->prepare('SELECT file_name, file_path, file_size FROM backups WHERE id = :id LIMIT 1');
    $query->execute(['id' => $downloadId]);
    $backup = $query->fetch();
    if (!is_array($backup)) {
        http_response_code(404);
        exit(e(__('admin.error.backup_not_found')));
    }
    $fileName = (string) $backup['file_name'];
    if (
        basename($fileName) !== $fileName
        || $fileName !== basename((string) $backup['file_path'])
        || preg_match('/\Acozycafe-[0-9]{8}-[0-9]{6}-[a-f0-9]{12}\.sql\z/', $fileName) !== 1
    ) {
        http_response_code(404);
        exit(e(__('admin.error.backup_not_found')));
    }
    try {
        $path = cozycafe_backup_file_path((string) $backup['file_path']);
    } catch (RuntimeException) {
        http_response_code(404);
        exit(e(__('admin.error.backup_not_found')));
    }
    $size = filesize($path);
    if ($size === false || $size !== (int) $backup['file_size']) {
        throw new RuntimeException('The stored backup size does not match the file on disk.');
    }
    header('Content-Type: application/sql');
    header('Content-Length: ' . (string) $size);
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    if (!readfile($path)) {
        throw new RuntimeException('The database backup could not be streamed.');
    }
    exit;
}

$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_require_post();
    if (cozycafe_post_string('action') !== 'create') {
        $errors[] = __('admin.error.invalid_action');
    } else {
        try {
            $backup = cozycafe_create_database_backup($pdo, 'manual', $adminId);
            try {
                log_admin_action(
                    $pdo,
                    $adminId,
                    'backup_created',
                    'backup',
                    (int) $backup['id'],
                    'Manual database backup ' . $backup['file_name'] . ' created.'
                );
            } catch (Throwable $exception) {
                $pdo->prepare('DELETE FROM backups WHERE id = :id')->execute(['id' => (int) $backup['id']]);
                $path = cozycafe_backup_file_path((string) $backup['file_path']);
                if (!unlink($path)) {
                    throw new RuntimeException('The unlogged database backup could not be removed.', 0, $exception);
                }
                throw $exception;
            }
            admin_redirect('backup/index.php', 'admin.message.backup_created');
        } catch (RuntimeException $exception) {
            $errors[] = $exception->getMessage();
        }
    }
}

$backups = $pdo->query(
    'SELECT b.id, b.file_name, b.file_path, b.file_size, b.backup_type, b.created_at,
            a.name AS admin_name
     FROM backups b
     LEFT JOIN admins a ON a.id = b.created_by
     ORDER BY b.created_at DESC, b.id DESC
     LIMIT 100'
)->fetchAll();

admin_layout_start($pdo, __('admin.page.backup'), 'backup');
foreach ($errors as $error):
    ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endforeach; ?>
<section class="admin-panel card-cozy mb-4">
    <h2 class="h4"><?= e(__('admin.backup.create_title')) ?></h2>
    <p class="text-secondary"><?= e(__('admin.backup.description')) ?></p>
    <form method="post" action="<?= e(admin_url('backup/index.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <button class="btn btn-cozy" type="submit"><?= e(__('admin.backup.create_manual')) ?></button>
    </form>
</section>
<section class="admin-panel card-cozy">
    <h2 class="h4 mb-3"><?= e(__('admin.backup.history')) ?></h2>
    <?php if ($backups === []): ?>
        <p class="mb-0 text-secondary"><?= e(__('admin.empty.backups')) ?></p>
    <?php else: ?>
        <div class="table-responsive"><table class="table table-cozy align-middle">
            <thead><tr>
                <th><?= e(__('admin.backup.file')) ?></th><th><?= e(__('admin.backup.type')) ?></th>
                <th><?= e(__('admin.table.created')) ?></th><th><?= e(__('admin.table.customer')) ?></th>
                <th><?= e(__('admin.backup.size')) ?></th><th></th>
            </tr></thead>
            <tbody><?php foreach ($backups as $backup): ?>
                <tr>
                    <td><?= e($backup['file_name']) ?></td>
                    <td><?= e($backup['backup_type'] === 'auto' ? __('admin.backup.automatic') : __('admin.backup.manual')) ?></td>
                    <td><?= e(format_wib((string) $backup['created_at'])) ?></td>
                    <td><?= e($backup['admin_name'] ?? __('admin.backup.system')) ?></td>
                    <td><?= e(number_format((int) $backup['file_size'])) ?> <?= e(__('admin.backup.bytes')) ?></td>
                    <td><a class="btn btn-outline-cozy btn-sm" href="<?= e(admin_url('backup/index.php?download=' . (int) $backup['id'])) ?>"><?= e(__('admin.backup.download')) ?></a></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php admin_layout_end(); ?>
