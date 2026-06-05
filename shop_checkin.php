<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/points.php';

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

if (!is_logged_in()) {
  http_response_code(401);
  echo json_encode([
    'ok' => false,
    'code' => 'LOGIN',
    'msg' => '请先登录',
    'login' => login_url('/shop.php'),
  ]);
  exit;
}

$uid = current_user_id();
if (!$uid) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'msg' => '请先登录']);
  exit;
}

$r = points_do_checkin((int)$uid);
if ($r['ok']) {
  $r['balance'] = points_get_balance((int)$uid);
  $r['rewards'] = points_drain_rewards();
  echo json_encode($r);
  exit;
}

echo json_encode(['ok' => false, 'msg' => $r['msg']]);
