<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/helpers.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'msg' => 'Method Not Allowed']);
  exit;
}

if (!csrf_check($_POST['csrf'] ?? '')) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'msg' => '安全验证失败']);
  exit;
}

$uid = current_user_id();
if (!$uid) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'msg' => '请先登录']);
  exit;
}

$titleKey = isset($_POST['title_key']) ? trim((string)$_POST['title_key']) : '';

if (set_user_active_title((int)$uid, $titleKey)) {
  echo json_encode(['ok' => true, 'msg' => '称号设置成功']);
} else {
  echo json_encode(['ok' => false, 'msg' => '称号设置失败']);
}
