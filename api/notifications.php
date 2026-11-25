<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth_middleware.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'cart_service.php';

start_secure_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($requestMethod, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    echo json_encode(['error' => __('error.method_not_allowed')]);
    exit;
}

$pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
$recipientType = null;
$recipientId = null;
if (cozycafe_session_has_id('admin_id')) {
    $adminId = (int) $_SESSION['admin_id'];
    $adminQuery = $pdo->prepare('SELECT id FROM admins WHERE id = :id LIMIT 1');
    $adminQuery->execute(['id' => $adminId]);
    if ($adminQuery->fetchColumn() === false) {
        http_response_code(401);
        echo json_encode(['error' => __('error.unauthorized')]);
        exit;
    }
    $recipientType = 'admin';
} else {
    $recipientId = cozycafe_customer_session_id_if_active();
    if ($recipientId === null) {
        http_response_code(401);
        echo json_encode(['error' => __('error.unauthorized')]);
        exit;
    }
    $recipientType = 'customer';
}

if ($requestMethod === 'POST') {
    if (!verify_csrf_token()) {
        http_response_code(403);
        echo json_encode(['error' => __('validation.invalid_csrf')]);
        exit;
    }
    $action = cozycafe_post_string('action');
    if ($action !== 'mark_read') {
        http_response_code(400);
        echo json_encode(['error' => __('error.invalid_request')]);
        exit;
    }

    $notificationId = filter_var(
        cozycafe_post_string('notification_id'),
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $notificationIds = $_POST['notification_ids'] ?? null;
    $scope = "recipient_type = :recipient_type AND recipient_id " . ($recipientType === 'admin' ? 'IS NULL' : '= :recipient_id');
    $parameters = ['recipient_type' => $recipientType];
    if ($recipientType === 'customer') {
        $parameters['recipient_id'] = $recipientId;
    }

    if ($notificationIds !== null) {
        if (!is_array($notificationIds) || count($notificationIds) > 5) {
            http_response_code(400);
            echo json_encode(['error' => __('error.invalid_request')]);
            exit;
        }
        $ids = [];
        foreach ($notificationIds as $value) {
            if (!is_string($value) || preg_match('/\A[1-9][0-9]{0,9}\z/', $value) !== 1) {
                http_response_code(400);
                echo json_encode(['error' => __('error.invalid_request')]);
                exit;
            }
            $ids[] = (int) $value;
        }
        if ($ids !== []) {
            $placeholders = [];
            foreach ($ids as $index => $id) {
                $placeholder = ':notification_' . $index;
                $placeholders[] = $placeholder;
                $parameters['notification_' . $index] = $id;
            }
            $statement = $pdo->prepare(
                'UPDATE notifications SET is_read = 1
                 WHERE ' . $scope . ' AND is_read = 0 AND id IN (' . implode(', ', $placeholders) . ')'
            );
            $statement->execute($parameters);
        }
    } elseif (is_int($notificationId) && $notificationId > 0) {
        $statement = $pdo->prepare(
            'UPDATE notifications SET is_read = 1
             WHERE ' . $scope . ' AND id = :notification_id AND is_read = 0'
        );
        $statement->execute($parameters + ['notification_id' => $notificationId]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => __('error.invalid_request')]);
        exit;
    }

    echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
    exit;
}

$countQuery = $pdo->prepare(
    'SELECT COUNT(*) FROM notifications
     WHERE recipient_type = :recipient_type
       AND recipient_id ' . ($recipientType === 'admin' ? 'IS NULL' : '= :recipient_id') . '
       AND is_read = 0'
);
$countParameters = ['recipient_type' => $recipientType];
if ($recipientType === 'customer') {
    $countParameters['recipient_id'] = $recipientId;
}
$countQuery->execute($countParameters);

$latestQuery = $pdo->prepare(
    'SELECT id, title, message, link_url, is_read, created_at
     FROM notifications
     WHERE recipient_type = :recipient_type
       AND recipient_id ' . ($recipientType === 'admin' ? 'IS NULL' : '= :recipient_id') . '
     ORDER BY created_at DESC, id DESC
     LIMIT 5'
);
$latestQuery->execute($countParameters);
$notifications = [];
foreach ($latestQuery->fetchAll() as $notification) {
    $notifications[] = [
        'id' => (int) $notification['id'],
        'title' => (string) $notification['title'],
        'message' => (string) $notification['message'],
        'link_url' => $notification['link_url'],
        'is_read' => (bool) $notification['is_read'],
        'created_at' => format_wib((string) $notification['created_at']),
    ];
}

echo json_encode([
    'unread_count' => (int) $countQuery->fetchColumn(),
    'notifications' => $notifications,
], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
