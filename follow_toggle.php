<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/notification.php';
require_once __DIR__ . '/app/ratelimit.php';

header('Content-Type: application/json; charset=utf-8');

check_rate_limit('follow', 30);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'msg'=>'Method Not Allowed']);
  exit;
}

$targetUserId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
$token = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';

// 1) 先校验参数
if ($targetUserId <= 0) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'msg'=>'Bad Request']);
  exit;
}

// 2) 再校验 CSRF
if (!csrf_check($token)) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'msg'=>'CSRF Forbidden']);
  exit;
}

// 3) 再判断登录
if (!is_logged_in()) {
  http_response_code(401);
  echo json_encode([
    'ok' => false,
    'code' => 'LOGIN',
    'login' => login_url('/user.php?id=' . $targetUserId)
  ]);
  exit;
}

$currentUser = current_user();
if (!$currentUser) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'msg'=>'请先登录']);
  exit;
}

$followerId = $currentUser['id'];

// 不能关注自己
if ($followerId === $targetUserId) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'msg'=>'不能关注自己']);
  exit;
}

$pdo = db();

try {
  $pdo->beginTransaction();

  // 检查用户是否存在
  $st = $pdo->prepare("SELECT id FROM user WHERE id=? AND status=1 LIMIT 1");
  $st->execute([$targetUserId]);
  if (!$st->fetch()) {
    $pdo->rollBack();
    http_response_code(404);
    echo json_encode(['ok'=>false,'msg'=>'User Not Found']);
    exit;
  }

  // 是否已关注
  $st = $pdo->prepare("SELECT id FROM user_follow WHERE follower_id=? AND following_id=? LIMIT 1");
  $st->execute([$followerId, $targetUserId]);
  $following = (bool)$st->fetch();

  if ($following) {
    // 取消关注
    $st = $pdo->prepare("DELETE FROM user_follow WHERE follower_id=? AND following_id=?");
    $st->execute([$followerId, $targetUserId]);
    $newFollowing = false;
  } else {
    // 关注
    $st = $pdo->prepare("INSERT INTO user_follow (follower_id, following_id) VALUES (?,?)");
    $st->execute([$followerId, $targetUserId]);
    $newFollowing = true;

    // 发送通知给被关注用户
    create_notification($targetUserId, 'follow', $targetUserId, $followerId);
  }

  $pdo->commit();

  echo json_encode(['ok'=>true,'following'=>$newFollowing]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['ok'=>false,'msg'=>'Server Error']);
}

