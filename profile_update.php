<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'msg' => 'Method Not Allowed']);
  exit;
}

// 需要登录
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

$pdo = db();
$userId = (int)$currentUser['id'];

// 获取要更新的字段
$username = isset($_POST['username']) ? trim((string)$_POST['username']) : null;
$bio = isset($_POST['bio']) ? trim((string)$_POST['bio']) : null;
$privacyShowFavorites = isset($_POST['privacy_show_favorites']) ? (string)$_POST['privacy_show_favorites'] : null;

// 验证用户名
if ($username !== null) {
  if ($username === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => '用户名不能为空']);
    exit;
  }
  
  if (mb_strlen($username) > 50) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => '用户名不能超过50个字符']);
    exit;
  }
  
  // 检查用户名是否已被其他用户使用
  $st = $pdo->prepare("SELECT id FROM user WHERE username = ? AND id != ? LIMIT 1");
  $st->execute([$username, $userId]);
  if ($st->fetch()) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => '该用户名已被使用']);
    exit;
  }
}

// 验证个性签名
if ($bio !== null) {
  if (mb_strlen($bio) > 200) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => '个性签名不能超过200个字符']);
    exit;
  }
}

// 验证隐私设置
if ($privacyShowFavorites !== null && $privacyShowFavorites !== '0' && $privacyShowFavorites !== '1') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'msg' => '参数错误']);
  exit;
}

try {
  $pdo->beginTransaction();
  
  // 更新用户名
  if ($username !== null) {
    $st = $pdo->prepare("UPDATE user SET username = ? WHERE id = ?");
    $st->execute([$username, $userId]);
  }
  
  // 更新个性签名
  if ($bio !== null) {
    $st = $pdo->prepare("UPDATE user SET bio = ? WHERE id = ?");
    $st->execute([$bio === '' ? null : $bio, $userId]);
  }

  // 更新隐私设置
  if ($privacyShowFavorites !== null) {
    $st = $pdo->prepare("UPDATE user SET privacy_show_favorites = ? WHERE id = ?");
    $st->execute([(int)$privacyShowFavorites, $userId]);
  }

  $pdo->commit();

  // 隐私设置单独请求返回简洁结果
  if ($privacyShowFavorites !== null && $username === null && $bio === null) {
    echo json_encode(['ok' => true, 'data' => ['privacy_show_favorites' => (int)$privacyShowFavorites]]);
    exit;
  }

  echo json_encode([
    'ok' => true,
    'data' => [
      'username' => $username !== null ? $username : $currentUser['username'],
      'bio' => $bio !== null ? $bio : ''
    ]
  ]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  http_response_code(500);
  echo json_encode(['ok' => false, 'msg' => '更新失败']);
}

