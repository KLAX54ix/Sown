<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/notification.php';
require_once __DIR__ . '/app/ratelimit.php';

header('Content-Type: application/json; charset=utf-8');

check_rate_limit('like', 30);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'msg'=>'Method Not Allowed']);
  exit;
}

$postId = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
$token  = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';

// 1) 先校验参数
if ($postId <= 0) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'msg'=>'Bad Request']);
  exit;
}

// 2) 再校验 CSRF（未登录也要校验，避免被乱打接口）
if (!csrf_check($token)) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'msg'=>'CSRF Forbidden']);
  exit;
}

// 3) 再判断登录（AJAX：返回 JSON，让前端跳转）
if (!is_logged_in()) {
  http_response_code(401);
  echo json_encode([
    'ok' => false,
    'code' => 'LOGIN',
    'login' => login_url('/post.php?id=' . $postId)
  ]);
  exit;
}

$userId = current_user_id(); // 已登录必有
$pdo = db();

try {
  $pdo->beginTransaction();

  // 帖子存在性，并获取帖子作者ID
  $st = $pdo->prepare("SELECT id, user_id FROM post WHERE id=:id AND status=1 AND (review_status IS NULL OR review_status = 0) LIMIT 1");
  $st->execute([':id'=>$postId]);
  $post = $st->fetch();
  if (!$post) {
    $pdo->rollBack();
    http_response_code(404);
    echo json_encode(['ok'=>false,'msg'=>'内容未通过审核']);
    exit;
  }

  $postOwnerId = (int)$post['user_id'];

  // 是否已点赞
  $st = $pdo->prepare("SELECT id FROM post_like WHERE post_id=:pid AND user_id=:uid LIMIT 1");
  $st->execute([':pid'=>$postId, ':uid'=>$userId]);
  $liked = (bool)$st->fetch();

  if ($liked) {
    // 取消点赞
    $st = $pdo->prepare("DELETE FROM post_like WHERE post_id=:pid AND user_id=:uid");
    $st->execute([':pid'=>$postId, ':uid'=>$userId]);

    $st = $pdo->prepare("UPDATE post SET like_count = GREATEST(like_count-1,0) WHERE id=:id");
    $st->execute([':id'=>$postId]);

    $newLiked = false;
  } else {
    // 点赞
    $st = $pdo->prepare("INSERT INTO post_like (post_id, user_id) VALUES (:pid,:uid)");
    $st->execute([':pid'=>$postId, ':uid'=>$userId]);

    $st = $pdo->prepare("UPDATE post SET like_count = like_count+1 WHERE id=:id");
    $st->execute([':id'=>$postId]);

    $newLiked = true;

    // 发送通知给帖子作者（如果不是自己点赞自己）
    if ($postOwnerId !== $userId) {
      create_notification($postOwnerId, 'like', $postId, $userId);
    }
  }

  // 返回最新 like_count
  $st = $pdo->prepare("SELECT like_count FROM post WHERE id=:id");
  $st->execute([':id'=>$postId]);
  $row = $st->fetch();
  $count = $row ? (int)$row['like_count'] : 0;

  $pdo->commit();

  if ($newLiked) {
    require_once __DIR__ . '/app/points.php';
    points_refresh_author_engagement_milestones($postOwnerId);
  }

  $rewards = function_exists('points_drain_rewards') ? points_drain_rewards() : [];
  $res = ['ok'=>true,'liked'=>$newLiked,'count'=>$count];
  if (!empty($rewards)) $res['rewards'] = $rewards;
  echo json_encode($res);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['ok'=>false,'msg'=>'Server Error']);
}
