<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$reportTypes = ['daily', 'weekly', 'monthly', 'products', 'payments', 'bookings', 'customers'];
$reportType = isset($_GET['type']) && is_string($_GET['type']) ? $_GET['type'] : 'daily';
if (!in_array($reportType, $reportTypes, true)) {
    $reportType = 'daily';
}
$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Jakarta'));
$startDefault = $today->modify('-29 days')->format('Y-m-d');
$startDate = isset($_GET['start_date']) && is_string($_GET['start_date']) ? $_GET['start_date'] : $startDefault;
$endDate = isset($_GET['end_date']) && is_string($_GET['end_date']) ? $_GET['end_date'] : $today->format('Y-m-d');
$parseDate = static function (string $value): ?DateTimeImmutable {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Jakarta'));
    $errors = DateTimeImmutable::getLastErrors();
    if (
        $date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d') !== $value
    ) {
        return null;
    }
    return $date;
};
$start = $parseDate($startDate);
$end = $parseDate($endDate);
$errors = [];
if ($start === null || $end === null || $end < $start || $start->diff($end)->days > 365) {
    $errors[] = __('admin.error.report_date_range');
    $start = $parseDate($startDefault);
    $end = $today;
    $startDate = $startDefault;
    $endDate = $today->format('Y-m-d');
}

$salesQuery = $pdo->prepare(
    "SELECT DATE(verified_at) AS report_date, 'order' AS revenue_type,
            SUM(order_total) AS amount
     FROM (
         SELECT o.id, p.verified_at, (o.subtotal + o.tax_amount) AS order_total
         FROM orders o
         INNER JOIN (
             SELECT payable_id, MIN(verified_at) AS verified_at
             FROM payments
             WHERE payable_type = 'order' AND status = 'Verified' AND verified_at IS NOT NULL
             GROUP BY payable_id
         ) p ON p.payable_id = o.id
         WHERE o.status <> 'Cancelled'
     ) verified_orders
     WHERE DATE(verified_at) BETWEEN :order_start AND :order_end
     GROUP BY DATE(verified_at)
     UNION ALL
     SELECT DATE(p.verified_at) AS report_date, 'dp' AS revenue_type, SUM(p.amount) AS amount
     FROM (
         SELECT payable_id, MIN(id) AS payment_id
         FROM payments
         WHERE payable_type = 'reservation_dp'
           AND status = 'Verified'
           AND verified_at IS NOT NULL
         GROUP BY payable_id
     ) verified_dp
     INNER JOIN payments p ON p.id = verified_dp.payment_id
     WHERE DATE(p.verified_at) BETWEEN :dp_start AND :dp_end
     GROUP BY DATE(p.verified_at)
     ORDER BY report_date ASC"
);
$salesQuery->execute([
    'order_start' => $startDate,
    'order_end' => $endDate,
    'dp_start' => $startDate,
    'dp_end' => $endDate,
]);
$dailyTotals = [];
$verifiedDp = 0.0;
foreach ($salesQuery->fetchAll() as $row) {
    $date = (string) $row['report_date'];
    $amount = (float) $row['amount'];
    $dailyTotals[$date] = ($dailyTotals[$date] ?? 0.0) + $amount;
    if ($row['revenue_type'] === 'dp') {
        $verifiedDp += $amount;
    }
}
$orderTotalsQuery = $pdo->prepare(
    "SELECT COALESCE(SUM(o.subtotal), 0) AS subtotal,
            COALESCE(SUM(o.tax_amount), 0) AS tax
     FROM orders o
     INNER JOIN (
         SELECT payable_id, MIN(verified_at) AS verified_at
         FROM payments
         WHERE payable_type = 'order' AND status = 'Verified' AND verified_at IS NOT NULL
         GROUP BY payable_id
     ) verified ON verified.payable_id = o.id
     WHERE DATE(verified.verified_at) BETWEEN :start_date AND :end_date
       AND o.status <> 'Cancelled'"
);
$orderTotalsQuery->execute(['start_date' => $startDate, 'end_date' => $endDate]);
$orderTotals = $orderTotalsQuery->fetch();
$reportSubtotal = (float) $orderTotals['subtotal'];
$reportTax = (float) $orderTotals['tax'];
$totalRevenue = $reportSubtotal + $reportTax + $verifiedDp;

$rangeSalesQuery = $pdo->prepare(
    "SELECT DATE(p.verified_at) AS report_date,
            SUM(CASE WHEN verified.payable_type = 'order'
                     THEN o.subtotal + o.tax_amount
                     ELSE p.amount END) AS amount,
            COUNT(*) AS transactions
     FROM (
         SELECT payable_type, payable_id, MIN(id) AS payment_id
         FROM payments
         WHERE status = 'Verified' AND verified_at IS NOT NULL
         GROUP BY payable_type, payable_id
     ) verified
     INNER JOIN payments p ON p.id = verified.payment_id
     LEFT JOIN orders o ON verified.payable_type = 'order' AND o.id = verified.payable_id
     WHERE DATE(p.verified_at) BETWEEN :start_date AND :end_date
       AND ((verified.payable_type = 'order' AND o.status <> 'Cancelled')
            OR verified.payable_type = 'reservation_dp')
     GROUP BY DATE(p.verified_at)
     ORDER BY report_date"
);
$rangeSalesQuery->execute(['start_date' => $startDate, 'end_date' => $endDate]);
$salesRows = $rangeSalesQuery->fetchAll();

$series = [];
$seriesLabels = [];
$rangeStart = $start;
$rangeEnd = $end;
if (in_array($reportType, ['daily', 'weekly', 'monthly'], true)) {
    for ($cursor = $rangeStart; $cursor <= $rangeEnd; $cursor = $cursor->modify('+1 day')) {
        $dayKey = $cursor->format('Y-m-d');
        $bucket = match ($reportType) {
            'weekly' => $cursor->format('o-\WW'),
            'monthly' => $cursor->format('Y-m'),
            default => $dayKey,
        };
        $series[$bucket] = ($series[$bucket] ?? 0.0) + ($dailyTotals[$dayKey] ?? 0.0);
    }
    foreach ($series as $bucket => $amount) {
        $seriesLabels[] = $bucket;
    }
}

$reportRows = [];
if ($reportType === 'products') {
    $productQuery = $pdo->prepare(
        "SELECT oi.product_name_snapshot, SUM(oi.quantity) AS quantity_sold,
                SUM(oi.subtotal) AS gross_sales
         FROM order_items oi
         INNER JOIN orders o ON o.id = oi.order_id
         INNER JOIN (
             SELECT payable_id, MIN(verified_at) AS verified_at
             FROM payments
             WHERE payable_type = 'order' AND status = 'Verified' AND verified_at IS NOT NULL
             GROUP BY payable_id
         ) p ON p.payable_id = o.id
         WHERE DATE(p.verified_at) BETWEEN :start_date AND :end_date
           AND o.status <> 'Cancelled'
         GROUP BY oi.product_name_snapshot
         ORDER BY quantity_sold DESC, gross_sales DESC
         LIMIT 20"
    );
    $productQuery->execute(['start_date' => $startDate, 'end_date' => $endDate]);
    $reportRows = $productQuery->fetchAll();
} elseif ($reportType === 'payments') {
    $paymentQuery = $pdo->prepare(
        "SELECT p.payment_method, COUNT(*) AS transactions, SUM(p.amount) AS amount
         FROM (
             SELECT MIN(id) AS payment_id
             FROM payments
             WHERE status = 'Verified' AND verified_at IS NOT NULL
             GROUP BY payable_type, payable_id
         ) verified
         INNER JOIN payments p ON p.id = verified.payment_id
         WHERE DATE(p.verified_at) BETWEEN :start_date AND :end_date
         GROUP BY p.payment_method
         ORDER BY p.payment_method"
    );
    $paymentQuery->execute(['start_date' => $startDate, 'end_date' => $endDate]);
    $reportRows = $paymentQuery->fetchAll();
} elseif ($reportType === 'bookings') {
    $bookingQuery = $pdo->prepare(
        'SELECT status, COUNT(*) AS booking_count, SUM(dp_amount) AS dp_total
         FROM reservations
         WHERE reservation_date BETWEEN :start_date AND :end_date
         GROUP BY status ORDER BY booking_count DESC'
    );
    $bookingQuery->execute(['start_date' => $startDate, 'end_date' => $endDate]);
    $reportRows = $bookingQuery->fetchAll();
} elseif ($reportType === 'customers') {
    $customerQuery = $pdo->prepare(
        'SELECT u.id, u.full_name, u.email, u.created_at, u.is_active,
                COUNT(DISTINCT o.id) AS orders_count,
                COUNT(DISTINCT r.id) AS reservations_count
         FROM users u
         LEFT JOIN orders o ON o.user_id = u.id
             AND o.created_at >= :order_start AND o.created_at < DATE_ADD(:order_end, INTERVAL 1 DAY)
         LEFT JOIN reservations r ON r.user_id = u.id
             AND r.reservation_date BETWEEN :reservation_start AND :reservation_end
         WHERE u.created_at >= :customer_start AND u.created_at < DATE_ADD(:customer_end, INTERVAL 1 DAY)
            OR o.id IS NOT NULL OR r.id IS NOT NULL
         GROUP BY u.id, u.full_name, u.email, u.created_at, u.is_active
         ORDER BY (COUNT(DISTINCT o.id) + COUNT(DISTINCT r.id)) DESC, u.created_at DESC
         LIMIT 100'
    );
    $customerQuery->execute([
        'order_start' => $startDate,
        'order_end' => $endDate,
        'reservation_start' => $startDate,
        'reservation_end' => $endDate,
        'customer_start' => $startDate . ' 00:00:00',
        'customer_end' => $endDate . ' 00:00:00',
    ]);
    $reportRows = $customerQuery->fetchAll();
}

$reportTabs = [
    'daily' => 'admin.report.daily',
    'weekly' => 'admin.report.weekly',
    'monthly' => 'admin.report.monthly',
    'products' => 'admin.report.products',
    'payments' => 'admin.report.payments',
    'bookings' => 'admin.report.bookings',
    'customers' => 'admin.report.customers',
];
admin_layout_start($pdo, __('admin.page.reports'), 'reports');
foreach ($errors as $error):
    ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endforeach; ?>
<section class="admin-panel card-cozy mb-4">
    <form method="get" action="<?= e(admin_url('reports/index.php')) ?>" class="row g-3 align-items-end">
        <input type="hidden" name="type" value="<?= e($reportType) ?>">
        <div class="col-12 col-md-4"><label class="form-label" for="report-start"><?= e(__('admin.report.start_date')) ?></label><input class="form-control" id="report-start" type="date" name="start_date" value="<?= e($startDate) ?>" required></div>
        <div class="col-12 col-md-4"><label class="form-label" for="report-end"><?= e(__('admin.report.end_date')) ?></label><input class="form-control" id="report-end" type="date" name="end_date" value="<?= e($endDate) ?>" required></div>
        <div class="col-12 col-md-4"><button class="btn btn-cozy" type="submit"><?= e(__('admin.report.apply_filter')) ?></button></div>
    </form>
</section>
<nav class="admin-report-tabs mb-4" aria-label="<?= e(__('admin.report.types')) ?>">
    <?php foreach ($reportTabs as $type => $labelKey): ?>
        <a class="btn <?= $reportType === $type ? 'btn-cozy' : 'btn-outline-cozy' ?>"
           href="<?= e(admin_url('reports/index.php?type=' . rawurlencode($type) . '&start_date=' . rawurlencode($startDate) . '&end_date=' . rawurlencode($endDate))) ?>"
           <?= $reportType === $type ? 'aria-current="page"' : '' ?>><?= e(__($labelKey)) ?></a>
    <?php endforeach; ?>
</nav>
<section class="admin-kpi-grid mb-4">
    <article class="admin-kpi card-cozy"><span><?= e(__('admin.report.food_subtotal')) ?></span><strong><?= e(format_rupiah($reportSubtotal)) ?></strong></article>
    <article class="admin-kpi card-cozy"><span><?= e(__('admin.report.tax')) ?></span><strong><?= e(format_rupiah($reportTax)) ?></strong></article>
    <article class="admin-kpi card-cozy"><span><?= e(__('admin.report.verified_dp')) ?></span><strong><?= e(format_rupiah($verifiedDp)) ?></strong></article>
    <article class="admin-kpi card-cozy"><span><?= e(__('admin.report.total_revenue')) ?></span><strong><?= e(format_rupiah($totalRevenue)) ?></strong></article>
</section>

<?php if (in_array($reportType, ['daily', 'weekly', 'monthly'], true)): ?>
    <section class="admin-panel card-cozy">
        <div class="admin-section-heading"><h2 class="h4 mb-0"><?= e(__($reportTabs[$reportType])) ?></h2></div>
        <div class="admin-chart-wrap"><canvas id="report-chart" aria-label="<?= e(__('admin.report.chart_label')) ?>"></canvas></div>
        <?php if ($salesRows === []): ?><p class="mt-3 mb-0 text-secondary"><?= e(__('common.no_data')) ?></p><?php else: ?>
            <div class="table-responsive mt-4"><table class="table table-cozy align-middle">
                <thead><tr><th><?= e(__('admin.report.date_or_period')) ?></th><th><?= e(__('admin.report.verified_transactions')) ?></th><th><?= e(__('admin.report.revenue')) ?></th></tr></thead>
                <tbody><?php foreach ($series as $bucket => $amount): ?>
                    <?php
                    $count = 0;
                    foreach ($salesRows as $saleRow) {
                        $saleDate = new DateTimeImmutable((string) $saleRow['report_date']);
                        $saleBucket = match ($reportType) {
                            'weekly' => $saleDate->format('o-\WW'),
                            'monthly' => $saleDate->format('Y-m'),
                            default => $saleDate->format('Y-m-d'),
                        };
                        if ($saleBucket === $bucket) {
                            $count += (int) $saleRow['transactions'];
                        }
                    }
                    ?>
                    <tr><td><?= e($bucket) ?></td><td><?= e($count) ?></td><td><?= e(format_rupiah($amount)) ?></td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>
<?php else: ?>
    <section class="admin-panel card-cozy">
        <h2 class="h4 mb-3"><?= e(__($reportTabs[$reportType])) ?></h2>
        <?php if ($reportRows === []): ?><p class="mb-0 text-secondary"><?= e(__('common.no_data')) ?></p>
        <?php elseif ($reportType === 'products'): ?>
            <div class="table-responsive"><table class="table table-cozy"><thead><tr><th><?= e(__('admin.table.product')) ?></th><th><?= e(__('admin.report.quantity_sold')) ?></th><th><?= e(__('admin.report.food_subtotal')) ?></th></tr></thead><tbody><?php foreach ($reportRows as $row): ?><tr><td><?= e($row['product_name_snapshot']) ?></td><td><?= e((int) $row['quantity_sold']) ?></td><td><?= e(format_rupiah((string) $row['gross_sales'])) ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php elseif ($reportType === 'payments'): ?>
            <div class="table-responsive"><table class="table table-cozy"><thead><tr><th><?= e(__('admin.table.payment_method')) ?></th><th><?= e(__('admin.report.verified_transactions')) ?></th><th><?= e(__('admin.report.revenue')) ?></th></tr></thead><tbody><?php foreach ($reportRows as $row): ?><tr><td><?= e($row['payment_method'] === 'cash' ? __('payment.method.cash') : __('payment.method.qris')) ?></td><td><?= e((int) $row['transactions']) ?></td><td><?= e(format_rupiah((string) $row['amount'])) ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php elseif ($reportType === 'bookings'): ?>
            <div class="table-responsive"><table class="table table-cozy"><thead><tr><th><?= e(__('label.status')) ?></th><th><?= e(__('admin.report.booking_count')) ?></th><th><?= e(__('admin.report.dp_value')) ?></th></tr></thead><tbody><?php foreach ($reportRows as $row): ?><tr><td><?= e(admin_status_label((string) $row['status'], 'reservation')) ?></td><td><?= e((int) $row['booking_count']) ?></td><td><?= e(format_rupiah((string) $row['dp_total'])) ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php else: ?>
            <div class="table-responsive"><table class="table table-cozy"><thead><tr><th><?= e(__('admin.table.customer')) ?></th><th><?= e(__('admin.table.created')) ?></th><th><?= e(__('admin.report.customer_orders')) ?></th><th><?= e(__('admin.report.customer_bookings')) ?></th></tr></thead><tbody><?php foreach ($reportRows as $row): ?><tr><td><?= e($row['full_name']) ?><small class="d-block text-secondary"><?= e($row['email']) ?></small></td><td><?= e(format_wib((string) $row['created_at'])) ?></td><td><?= e((int) $row['orders_count']) ?></td><td><?= e((int) $row['reservations_count']) ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if (in_array($reportType, ['daily', 'weekly', 'monthly'], true)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
    (() => {
        const canvas = document.getElementById('report-chart');
        if (!canvas || typeof Chart === 'undefined') return;
        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: <?= json_encode($seriesLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                datasets: [{
                    label: <?= json_encode(__('admin.report.revenue'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                    data: <?= json_encode(array_values($series), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                    backgroundColor: 'rgba(92, 58, 33, 0.75)'
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
        });
    })();
</script>
<?php endif; ?>
<?php admin_layout_end(); ?>
