<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$todayRevenueQuery = $pdo->prepare(
    "SELECT COALESCE(SUM(amount), 0)
     FROM payments
     WHERE status = 'Verified' AND DATE(verified_at) = CURRENT_DATE()"
);
$todayRevenueQuery->execute();
$todayRevenue = (string) $todayRevenueQuery->fetchColumn();
$todayTransactions = (int) $pdo->query(
    "SELECT COUNT(*) FROM payments WHERE status = 'Verified' AND DATE(verified_at) = CURRENT_DATE()"
)->fetchColumn();
$todayReservations = (int) $pdo->query(
    'SELECT COUNT(*) FROM reservations WHERE reservation_date = CURRENT_DATE()'
)->fetchColumn();
$customerCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

$salesRows = $pdo->query(
    "SELECT DATE(verified_at) AS sale_date, SUM(amount) AS total
     FROM payments
     WHERE status = 'Verified'
       AND verified_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 6 DAY)
       AND verified_at < DATE_ADD(CURRENT_DATE(), INTERVAL 1 DAY)
     GROUP BY DATE(verified_at)
     ORDER BY sale_date ASC"
)->fetchAll();
$salesByDate = [];
foreach ($salesRows as $row) {
    $salesByDate[(string) $row['sale_date']] = (float) $row['total'];
}
$chartLabels = [];
$chartValues = [];
$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Jakarta'));
for ($day = 6; $day >= 0; $day--) {
    $date = $today->modify('-' . $day . ' days');
    $key = $date->format('Y-m-d');
    $chartLabels[] = $date->format('d/m');
    $chartValues[] = $salesByDate[$key] ?? 0;
}

$activeOrders = $pdo->query(
    "SELECT o.id, o.order_code, o.order_type, o.status, o.total_amount, o.created_at,
            COALESCE(u.full_name, o.guest_name) AS customer_name
     FROM orders o
     LEFT JOIN users u ON u.id = o.user_id
     WHERE o.status IN ('Pending','Waiting for Payment','Paid','Processing','Ready')
     ORDER BY o.created_at DESC
     LIMIT 6"
)->fetchAll();
$upcomingReservations = $pdo->query(
    "SELECT r.id, r.booking_code, r.reservation_date, r.start_time, r.end_time,
            r.guest_count, r.status, u.full_name, t.table_number,
            MIN(o.scheduled_prep_at) AS scheduled_prep_at
     FROM reservations r
     INNER JOIN users u ON u.id = r.user_id
     INNER JOIN `tables` t ON t.id = r.table_id
     LEFT JOIN orders o ON o.reservation_id = r.id AND o.status NOT IN ('Cancelled','Completed')
     WHERE r.reservation_date >= CURRENT_DATE()
       AND r.status IN ('Waiting for DP','Confirmed')
     GROUP BY r.id, r.booking_code, r.reservation_date, r.start_time, r.end_time,
              r.guest_count, r.status, u.full_name, t.table_number
     ORDER BY r.reservation_date ASC, r.start_time ASC
     LIMIT 6"
)->fetchAll();
$pendingProofs = $pdo->query(
    "SELECT p.id, p.payment_code, p.payable_type, p.payable_id, p.amount, p.created_at,
            COALESCE(o.order_code, r.booking_code) AS reference_code
     FROM payments p
     LEFT JOIN orders o ON p.payable_type = 'order' AND o.id = p.payable_id
     LEFT JOIN reservations r ON p.payable_type = 'reservation_dp' AND r.id = p.payable_id
     WHERE p.status = 'Waiting Verification'
     ORDER BY p.created_at ASC
     LIMIT 5"
)->fetchAll();
$bestSellers = $pdo->query(
    "SELECT oi.product_name_snapshot, SUM(oi.quantity) AS quantity_sold
     FROM order_items oi
     INNER JOIN orders o ON o.id = oi.order_id
     WHERE o.status IN ('Paid','Processing','Ready','Completed')
     GROUP BY oi.product_name_snapshot
     ORDER BY quantity_sold DESC, oi.product_name_snapshot ASC
     LIMIT 5"
)->fetchAll();

admin_layout_start($pdo, __('admin.page.dashboard'), 'dashboard');
?>
<section class="admin-kpi-grid" aria-label="<?= e(__('admin.overview')) ?>">
    <article class="admin-kpi card-cozy">
        <span><?= e(__('admin.kpi.revenue')) ?></span>
        <strong><?= e(format_rupiah($todayRevenue)) ?></strong>
    </article>
    <article class="admin-kpi card-cozy">
        <span><?= e(__('admin.kpi.transactions')) ?></span>
        <strong><?= e($todayTransactions) ?></strong>
    </article>
    <article class="admin-kpi card-cozy">
        <span><?= e(__('admin.kpi.bookings')) ?></span>
        <strong><?= e($todayReservations) ?></strong>
    </article>
    <article class="admin-kpi card-cozy">
        <span><?= e(__('admin.kpi.customers')) ?></span>
        <strong><?= e($customerCount) ?></strong>
    </article>
</section>

<section class="admin-panel card-cozy mt-4">
    <div class="admin-section-heading">
        <h2 class="h4 mb-0"><?= e(__('admin.sales_chart')) ?></h2>
    </div>
    <div class="admin-chart-wrap"><canvas id="sales-chart" aria-label="<?= e(__('admin.sales_chart')) ?>"></canvas></div>
</section>

<div class="row g-4 mt-1">
    <section class="col-12 col-xxl-7">
        <div class="admin-panel card-cozy h-100">
            <div class="admin-section-heading">
                <h2 class="h4 mb-0"><?= e(__('admin.active_orders')) ?></h2>
                <a class="btn btn-outline-cozy btn-sm" href="<?= e(admin_url('orders/index.php')) ?>"><?= e(__('button.view_details')) ?></a>
            </div>
            <?php if ($activeOrders === []): ?>
                <p class="text-secondary mb-0"><?= e(__('common.no_data')) ?></p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-cozy align-middle">
                        <thead><tr>
                            <th><?= e(__('label.order_code')) ?></th><th><?= e(__('label.full_name')) ?></th>
                            <th><?= e(__('label.status')) ?></th><th><?= e(__('label.total')) ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($activeOrders as $order): ?>
                            <tr>
                                <td><a href="<?= e(admin_url('orders/detail.php?id=' . (int) $order['id'])) ?>"><?= e($order['order_code']) ?></a></td>
                                <td><?= e($order['customer_name'] ?: __('admin.guest')) ?></td>
                                <td><?= admin_status_badge((string) $order['status']) ?></td>
                                <td><?= e(format_rupiah((string) $order['total_amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <section class="col-12 col-xxl-5">
        <div class="admin-panel card-cozy h-100">
            <div class="admin-section-heading"><h2 class="h4 mb-0"><?= e(__('admin.pending_payments')) ?></h2>
                <a class="btn btn-outline-cozy btn-sm" href="<?= e(admin_url('payments/index.php')) ?>"><?= e(__('button.view_details')) ?></a>
            </div>
            <?php if ($pendingProofs === []): ?>
                <p class="text-secondary mb-0"><?= e(__('common.no_data')) ?></p>
            <?php else: ?>
                <ul class="admin-compact-list">
                <?php foreach ($pendingProofs as $payment): ?>
                    <li>
                        <div><strong><?= e($payment['reference_code'] ?? $payment['payment_code']) ?></strong>
                            <small><?= e($payment['payment_code']) ?> · <?= e(format_wib((string) $payment['created_at'])) ?></small></div>
                        <span><?= e(format_rupiah((string) $payment['amount'])) ?></span>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>
    <section class="col-12 col-xl-7">
        <div class="admin-panel card-cozy h-100">
            <div class="admin-section-heading">
                <h2 class="h4 mb-0"><?= e(__('admin.upcoming_reservations')) ?></h2>
                <a class="btn btn-outline-cozy btn-sm" href="<?= e(admin_url('reservations/index.php')) ?>"><?= e(__('button.view_details')) ?></a>
            </div>
            <?php if ($upcomingReservations === []): ?>
                <p class="text-secondary mb-0"><?= e(__('common.no_data')) ?></p>
            <?php else: ?>
                <ul class="admin-compact-list">
                <?php foreach ($upcomingReservations as $reservation): ?>
                    <li>
                        <div>
                            <strong><?= e($reservation['booking_code']) ?> · <?= e($reservation['table_number']) ?></strong>
                            <small><?= e($reservation['full_name']) ?> · <?= e(format_wib((string) $reservation['reservation_date'] . ' ' . (string) $reservation['start_time'])) ?></small>
                            <?php if (is_string($reservation['scheduled_prep_at'])): ?>
                                <small class="admin-prep-note"><?= e(__('admin.prep_at')) ?> <?= e(format_wib($reservation['scheduled_prep_at'])) ?></small>
                            <?php endif; ?>
                        </div>
                        <?= admin_status_badge((string) $reservation['status'], 'reservation') ?>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>
    <section class="col-12 col-xl-5">
        <div class="admin-panel card-cozy h-100">
            <div class="admin-section-heading"><h2 class="h4 mb-0"><?= e(__('admin.best_sellers')) ?></h2></div>
            <?php if ($bestSellers === []): ?>
                <p class="text-secondary mb-0"><?= e(__('common.no_data')) ?></p>
            <?php else: ?>
                <ol class="admin-best-sellers">
                    <?php foreach ($bestSellers as $product): ?>
                        <li><span><?= e($product['product_name_snapshot']) ?></span><strong><?= e((int) $product['quantity_sold']) ?></strong></li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>
    </section>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
    (() => {
        const canvas = document.getElementById('sales-chart');
        if (!canvas || typeof Chart === 'undefined') return;
        new Chart(canvas, {
            type: 'line',
            data: {
                labels: <?= json_encode($chartLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                datasets: [{
                    label: <?= json_encode(__('admin.chart_dataset'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                    data: <?= json_encode($chartValues, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                    borderColor: '#5C3A21',
                    backgroundColor: 'rgba(217, 197, 178, 0.45)',
                    fill: true,
                    tension: 0.35
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { beginAtZero: true } }
            }
        });
    })();
</script>
<?php admin_layout_end(); ?>
