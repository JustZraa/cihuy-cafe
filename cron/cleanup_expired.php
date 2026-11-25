<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'env.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $providedSecret = $_SERVER['HTTP_X_CRON_SECRET'] ?? '';
    $expectedSecret = cozycafe_env('CRON_SECRET');
    if (
        ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
        || !is_string($providedSecret)
        || !is_string($expectedSecret)
        || strlen($expectedSecret) < 32
        || !hash_equals($expectedSecret, $providedSecret)
    ) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    set_exception_handler(static function (Throwable $exception): void {
        error_log('Cron cleanup request failed: ' . $exception->getMessage());
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo '{"error":"Cleanup failed."}';
    });
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'payment_service.php';

$pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
$expiredPaymentQuery = $pdo->query(
    "SELECT p.id
     FROM payments p
     WHERE p.payable_type IN ('order', 'reservation_dp')
       AND p.payment_method = 'qris'
       AND p.status IN ('Pending', 'Rejected')
       AND p.expires_at IS NOT NULL
       AND p.expires_at <= NOW()
       AND NOT EXISTS (
           SELECT 1 FROM payment_proofs pp WHERE pp.payment_id = p.id
       )
       AND (
           (p.payable_type = 'order' AND EXISTS (
               SELECT 1 FROM orders o
               WHERE o.id = p.payable_id
                 AND o.order_type = 'takeaway'
                 AND o.status = 'Waiting for Payment'
           ))
           OR
           (p.payable_type = 'reservation_dp' AND EXISTS (
               SELECT 1 FROM reservations r
               WHERE r.id = p.payable_id AND r.status = 'Waiting for DP'
           ))
       )
     ORDER BY p.id"
);

$expiredPayments = 0;
foreach ($expiredPaymentQuery->fetchAll(PDO::FETCH_COLUMN) as $paymentId) {
    if (cozycafe_expire_payment_if_unproven($pdo, (int) $paymentId)) {
        $expiredPayments++;
    }
}

$tableCandidates = $pdo->query(
    "SELECT id
     FROM `tables`
     WHERE status = 'Digunakan'
       AND occupied_until IS NOT NULL
       AND occupied_until <= NOW()
     ORDER BY id"
)->fetchAll(PDO::FETCH_COLUMN);
$freedTables = 0;
foreach ($tableCandidates as $tableId) {
    $pdo->beginTransaction();
    try {
        $tableQuery = $pdo->prepare(
            "SELECT id
             FROM `tables`
             WHERE id = :id
               AND status = 'Digunakan'
               AND occupied_until IS NOT NULL
               AND occupied_until <= NOW()
             FOR UPDATE"
        );
        $tableQuery->execute(['id' => (int) $tableId]);
        if ($tableQuery->fetchColumn() === false) {
            $pdo->commit();
            continue;
        }

        $reservationQuery = $pdo->prepare(
            "SELECT 1
             FROM reservations r
             WHERE r.table_id = :table_id
               AND r.reservation_date >= CURRENT_DATE()
               AND TIMESTAMP(r.reservation_date, r.end_time) > NOW()
               AND (
                   r.status = 'Confirmed'
                   OR (
                       r.status = 'Waiting for DP'
                       AND EXISTS (
                           SELECT 1
                           FROM payments p
                           WHERE p.payable_type = 'reservation_dp'
                             AND p.payable_id = r.id
                             AND (
                                 p.status = 'Waiting Verification'
                                 OR (p.status IN ('Pending', 'Rejected') AND p.expires_at > NOW())
                             )
                       )
                   )
               )
             LIMIT 1"
        );
        $reservationQuery->execute(['table_id' => (int) $tableId]);
        if ($reservationQuery->fetchColumn() !== false) {
            $pdo->commit();
            continue;
        }

        $updateTable = $pdo->prepare(
            "UPDATE `tables`
             SET status = 'Tersedia', occupied_until = NULL, current_reservation_id = NULL
             WHERE id = :id AND status = 'Digunakan' AND occupied_until <= NOW()"
        );
        $updateTable->execute(['id' => (int) $tableId]);
        $freedTables += $updateTable->rowCount();
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

$result = [
    'expired_payments' => $expiredPayments,
    'freed_tables' => $freedTables,
];
if ($isCli) {
    fwrite(
        STDOUT,
        'Expired ' . $expiredPayments . ' payment(s); freed ' . $freedTables . " table(s).\n"
    );
} else {
    echo json_encode($result, JSON_THROW_ON_ERROR);
}
