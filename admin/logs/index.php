<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$adminFilter = isset($_GET['admin_id']) && is_string($_GET['admin_id']) ? $_GET['admin_id'] : '';
$actionFilter = isset($_GET['action_type']) && is_string($_GET['action_type']) ? $_GET['action_type'] : '';
$dateFrom = isset($_GET['date_from']) && is_string($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) && is_string($_GET['date_to']) ? $_GET['date_to'] : '';
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$page = is_int($page) ? $page : 1;
$pageSize = 25;
$errors = [];
$datePattern = '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/';
$dateValid = static function (string $value) use ($datePattern): bool {
    if ($value === '') {
        return true;
    }
    if (preg_match($datePattern, $value) !== 1) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $dateErrors = DateTimeImmutable::getLastErrors();
    return $date !== false
        && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
};

if (
    ($adminFilter !== '' && preg_match('/\A[1-9][0-9]{0,9}\z/', $adminFilter) !== 1)
    || ($actionFilter !== '' && preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/', $actionFilter) !== 1)
    || !$dateValid($dateFrom)
    || !$dateValid($dateTo)
    || ($dateFrom !== '' && $dateTo !== '' && $dateTo < $dateFrom)
) {
    $errors[] = __('admin.error.audit_filters');
    $adminFilter = '';
    $actionFilter = '';
    $dateFrom = '';
    $dateTo = '';
}

$conditions = [];
$parameters = [];
if ($adminFilter !== '') {
    $conditions[] = 'l.admin_id = :admin_id';
    $parameters['admin_id'] = (int) $adminFilter;
}
if ($actionFilter !== '') {
    $conditions[] = 'l.action = :action_type';
    $parameters['action_type'] = $actionFilter;
}
if ($dateFrom !== '') {
    $conditions[] = 'l.created_at >= :date_from';
    $parameters['date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $conditions[] = 'l.created_at < DATE_ADD(:date_to, INTERVAL 1 DAY)';
    $parameters['date_to'] = $dateTo;
}
$where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
$countQuery = $pdo->prepare(
    'SELECT COUNT(*) FROM admin_logs l INNER JOIN admins a ON a.id = l.admin_id ' . $where
);
$countQuery->execute($parameters);
$totalRows = (int) $countQuery->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $pageSize));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $pageSize;
$logQuery = $pdo->prepare(
    'SELECT l.id, l.action, l.entity_type, l.entity_id, l.description,
            l.ip_address, l.created_at, a.name AS admin_name, a.email AS admin_email
     FROM admin_logs l
     INNER JOIN admins a ON a.id = l.admin_id
     ' . $where . '
     ORDER BY l.created_at DESC, l.id DESC
     LIMIT :page_size OFFSET :offset'
);
foreach ($parameters as $name => $value) {
    $logQuery->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$logQuery->bindValue(':page_size', $pageSize, PDO::PARAM_INT);
$logQuery->bindValue(':offset', $offset, PDO::PARAM_INT);
$logQuery->execute();
$logs = $logQuery->fetchAll();

$admins = $pdo->query('SELECT id, name, email FROM admins ORDER BY name')->fetchAll();
$actions = $pdo->query('SELECT DISTINCT action FROM admin_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$pageUrl = static function (int $number) use ($adminFilter, $actionFilter, $dateFrom, $dateTo): string {
    $parameters = ['page' => $number];
    if ($adminFilter !== '') {
        $parameters['admin_id'] = $adminFilter;
    }
    if ($actionFilter !== '') {
        $parameters['action_type'] = $actionFilter;
    }
    if ($dateFrom !== '') {
        $parameters['date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $parameters['date_to'] = $dateTo;
    }
    return admin_url('logs/index.php?' . http_build_query($parameters));
};

admin_layout_start($pdo, __('admin.page.logs'), 'logs');
foreach ($errors as $error):
    ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endforeach; ?>
<section class="admin-panel card-cozy mb-4">
    <form method="get" action="<?= e(admin_url('logs/index.php')) ?>" class="row g-3 align-items-end">
        <div class="col-12 col-md-3"><label class="form-label" for="log-admin"><?= e(__('admin.logs.admin_filter')) ?></label>
            <select class="form-select" id="log-admin" name="admin_id"><option value=""><?= e(__('admin.report.all_admins')) ?></option>
                <?php foreach ($admins as $adminOption): ?><option value="<?= e((int) $adminOption['id']) ?>" <?= $adminFilter === (string) $adminOption['id'] ? 'selected' : '' ?>><?= e($adminOption['name']) ?> (<?= e($adminOption['email']) ?>)</option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-3"><label class="form-label" for="log-action"><?= e(__('admin.logs.action_filter')) ?></label>
            <select class="form-select" id="log-action" name="action_type"><option value=""><?= e(__('admin.report.all_actions')) ?></option>
                <?php foreach ($actions as $action): ?><option value="<?= e($action) ?>" <?= $actionFilter === $action ? 'selected' : '' ?>><?= e($action) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2"><label class="form-label" for="log-from"><?= e(__('admin.logs.date_from')) ?></label><input class="form-control" id="log-from" type="date" name="date_from" value="<?= e($dateFrom) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label" for="log-to"><?= e(__('admin.logs.date_to')) ?></label><input class="form-control" id="log-to" type="date" name="date_to" value="<?= e($dateTo) ?>"></div>
        <div class="col-12 col-md-2"><button class="btn btn-cozy" type="submit"><?= e(__('admin.report.apply_filter')) ?></button></div>
    </form>
</section>
<section class="admin-panel card-cozy">
    <div class="admin-section-heading"><h2 class="h4 mb-0"><?= e(__('admin.logs.history')) ?></h2><span><?= e(__('admin.logs.entries', ['count' => $totalRows])) ?></span></div>
    <?php if ($logs === []): ?>
        <p class="mb-0 text-secondary"><?= e(__('common.no_data')) ?></p>
    <?php else: ?>
        <div class="table-responsive"><table class="table table-cozy align-middle">
            <thead><tr>
                <th><?= e(__('admin.table.created')) ?></th><th><?= e(__('admin.logs.admin')) ?></th>
                <th><?= e(__('admin.logs.action')) ?></th><th><?= e(__('admin.logs.entity')) ?></th>
                <th><?= e(__('admin.logs.description')) ?></th><th><?= e(__('admin.logs.ip_address')) ?></th>
            </tr></thead>
            <tbody><?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= e(format_wib((string) $log['created_at'])) ?></td>
                    <td><?= e($log['admin_name']) ?><small class="d-block text-secondary"><?= e($log['admin_email']) ?></small></td>
                    <td><code><?= e($log['action']) ?></code></td>
                    <td><?= e($log['entity_type']) ?><?= $log['entity_id'] === null ? '' : ' #' . e((int) $log['entity_id']) ?></td>
                    <td><?= e($log['description'] ?? '-') ?></td>
                    <td><?= e($log['ip_address'] ?? '-') ?></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <nav class="admin-pagination" aria-label="<?= e(__('admin.logs.pagination')) ?>">
            <span><?= e(__('admin.logs.page_of', ['page' => $page, 'pages' => $totalPages])) ?></span>
            <div class="d-flex gap-2">
                <?php if ($page > 1): ?><a class="btn btn-outline-cozy btn-sm" href="<?= e($pageUrl($page - 1)) ?>"><?= e(__('button.back')) ?></a><?php endif; ?>
                <?php if ($page < $totalPages): ?><a class="btn btn-outline-cozy btn-sm" href="<?= e($pageUrl($page + 1)) ?>"><?= e(__('button.continue')) ?></a><?php endif; ?>
            </div>
        </nav>
    <?php endif; ?>
</section>
<?php admin_layout_end(); ?>
