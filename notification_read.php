<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/notification.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'msg' => 'Method Not Allowed']);
  exit;
}

$notificationId = isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : 0;
$token = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';

if ($notificationId <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'msg' => 'Bad Request']);
  exit;
}

if (!csrf_check($token)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'msg' => 'CSRF Forbidden']);
  exit;
}

$userId = current_user_id();
if (!$userId) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'code' => 'LOGIN', 'msg' => '请先登录']);
  exit;
}

// 标记通知为已读
$success = mark_notification_read($notificationId, $userId);

if ($success) {
  echo json_encode(['ok' => true]);
} else {
  http_response_code(500);
  echo json_encode(['ok' => false, 'msg' => 'Server Error']);
}

