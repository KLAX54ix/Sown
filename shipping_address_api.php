<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/address.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'msg' => 'Method Not Allowed']);
  exit;
}

if (!is_logged_in()) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'code' => 'LOGIN', 'msg' => '请先登录']);
  exit;
}

$currentUser = current_user();
if (!$currentUser) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'code' => 'LOGIN', 'msg' => '请先登录']);
  exit;
}

if (!csrf_check($_POST['csrf'] ?? '')) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'msg' => 'CSRF验证失败']);
  exit;
}

$userId = (int)$currentUser['id'];
$action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';

switch ($action) {
  case 'list':
    $addresses = address_get_list($userId);
    echo json_encode(['ok' => true, 'data' => $addresses]);
    exit;

  case 'add':
    $recipient = (string)($_POST['recipient'] ?? '');
    $phone = (string)($_POST['phone'] ?? '');
    $region = (string)($_POST['region'] ?? '');
    $detail = (string)($_POST['detail'] ?? '');
    $result = address_add($userId, $recipient, $phone, $region, $detail);
    if (!$result['ok']) {
      http_response_code(400);
    }
    echo json_encode($result);
    exit;

  case 'update':
    $addressId = isset($_POST['address_id']) ? (int)$_POST['address_id'] : 0;
    if ($addressId <= 0) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'msg' => '参数错误']);
      exit;
    }
    $recipient = (string)($_POST['recipient'] ?? '');
    $phone = (string)($_POST['phone'] ?? '');
    $region = (string)($_POST['region'] ?? '');
    $detail = (string)($_POST['detail'] ?? '');
    $result = address_update($addressId, $userId, $recipient, $phone, $region, $detail);
    if (!$result['ok']) {
      http_response_code(400);
    }
    echo json_encode($result);
    exit;

  case 'delete':
    $addressId = isset($_POST['address_id']) ? (int)$_POST['address_id'] : 0;
    if ($addressId <= 0) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'msg' => '参数错误']);
      exit;
    }
    $result = address_delete($addressId, $userId);
    if (!$result['ok']) {
      http_response_code(400);
    }
    echo json_encode($result);
    exit;

  case 'set_default':
    $addressId = isset($_POST['address_id']) ? (int)$_POST['address_id'] : 0;
    if ($addressId <= 0) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'msg' => '参数错误']);
      exit;
    }
    $result = address_set_default($addressId, $userId);
    if (!$result['ok']) {
      http_response_code(400);
    }
    echo json_encode($result);
    exit;

  default:
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => '未知操作']);
    exit;
}
