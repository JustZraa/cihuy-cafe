<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$search = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$search = substr($search, 0, 100);
$customersQuery = $pdo->prepare(
    'SELECT u.id, u.full_name, u.email, u.phone, u.is_active, u.created_at,
            (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id) AS order_count,
            (SELECT COUNT(*) FROM reservations r WHERE r.user_id = u.id) AS reservation_count
     FROM users u
     WHERE (:search = \'\'
        OR u.full_name LIKE :name_search
        OR u.email LIKE :email_search
        OR u.phone LIKE :phone_search)
     ORDER BY u.created_at DESC, u.id DESC
     LIMIT 200'
);
$customersQuery->execute([
    'search' => $search,
    'name_search' => '%' . $search . '%',
    'email_search' => '%' . $search . '%',
    'phone_search' => '%' . $search . '%',
]);
$customers = $customersQuery->fetchAll();

admin_layout_start($pdo, __('admin.page.customers'), 'customers');
?>
<section class="admin-panel card-cozy">
    <form method="get" action="<?= e(admin_url('customers/index.php')) ?>" class="row g-3 align-items-end mb-4">
        <div class="col-12 col-md-8"><label class="form-label" for="customer-search"><?= e(__('admin.customer_search')) ?></label><input class="form-control" id="customer-search" name="q" value="<?= e($search) ?>" maxlength="100"></div>
        <div class="col-12 col-md-4"><button class="btn btn-cozy" type="submit"><?= e(__('admin.customer_search')) ?></button></div>
    </form>
    <?php if ($customers === []): ?><p class="mb-0 text-secondary"><?= e(__('admin.empty.customers')) ?></p><?php else: ?>
        <div class="table-responsive"><table class="table table-cozy align-middle">
            <thead><tr><th><?= e(__('label.full_name')) ?></th><th><?= e(__('label.email')) ?></th><th><?= e(__('label.phone')) ?></th><th><?= e(__('label.status')) ?></th><th><?= e(__('admin.activity_summary')) ?></th><th><?= e(__('admin.table.created')) ?></th></tr></thead>
            <tbody><?php foreach ($customers as $customer): ?>
                <tr>
                    <td><a href="<?= e(admin_url('customers/detail.php?id=' . (int) $customer['id'])) ?>"><strong><?= e($customer['full_name']) ?></strong></a></td>
                    <td><?= e($customer['email']) ?></td><td><?= e($customer['phone']) ?></td>
                    <td><?= (int) $customer['is_active'] === 1 ? e(__('admin.label.status_active')) : e(__('admin.label.status_inactive')) ?></td>
                    <td><?= e((int) $customer['order_count']) ?> <?= e(__('admin.customer_orders')) ?> · <?= e((int) $customer['reservation_count']) ?> <?= e(__('admin.customer_reservations')) ?></td>
                    <td><?= e(format_wib((string) $customer['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php admin_layout_end(); ?>
