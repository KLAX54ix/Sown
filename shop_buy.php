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

$itemId = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
if ($itemId <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'msg' => '参数错误']);
  exit;
}

// 检查是否为实物商品
$item = shop_find_item($itemId);
if (!$item) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'msg' => '商品不存在']);
  exit;
}

if (!empty($item['is_physical'])) {
  // 实物商品：需要收货信息
  $name = isset($_POST['shipping_name']) ? trim((string)$_POST['shipping_name']) : '';
  $phone = isset($_POST['shipping_phone']) ? trim((string)$_POST['shipping_phone']) : '';
  $address = isset($_POST['shipping_address']) ? trim((string)$_POST['shipping_address']) : '';
  if ($name === '' || $phone === '' || $address === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => '请填写完整的收货信息']);
    exit;
  }
  $quantity = isset($_POST['quantity']) ? max(1, (int)$_POST['quantity']) : 1;
  $r = shop_order_create((int)$uid, $itemId, $name, $phone, $address, $quantity);
} else {
  // 虚拟商品：原有流程
  $r = shop_purchase_item((int)$uid, $itemId);
}

if ($r['ok']) {
  echo json_encode([
    'ok' => true,
    'msg' => $r['msg'],
    'balance' => points_get_balance((int)$uid),
  ]);
  exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'msg' => $r['msg']]);
