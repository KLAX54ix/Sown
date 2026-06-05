<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/ratelimit.php';

header('Content-Type: application/json; charset=utf-8');

check_rate_limit('like', 30);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'msg'=>'Method Not Allowed']);
  exit;
}

$commentId = isset($_POST['comment_id']) ? (int)$_POST['comment_id'] : 0;
$postId = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
$token = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';

if ($commentId <= 0) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'msg'=>'Bad Request']);
  exit;
}

if (!csrf_check($token)) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'msg'=>'CSRF Forbidden']);
  exit;
}

if (!is_logged_in()) {
  $next = $postId > 0 ? '/post.php?id=' . $postId : '/post.php';
  http_response_code(401);
  echo json_encode([
    'ok' => false,
    'code' => 'LOGIN',
    'login' => login_url($next)
  ]);
  exit;
}

$userId = current_user_id();
$pdo = db();

// 确保 comment_like 表和 comment.like_count 列存在
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS comment_like (comment_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (comment_id, user_id), KEY idx_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'msg'=>'数据库初始化失败，请联系管理员']);
  exit;
}
try {
  $pdo->exec("ALTER TABLE comment ADD COLUMN like_count INT NOT NULL DEFAULT 0");
} catch (Throwable $e) {
  $msg = $e->getMessage();
  $isDuplicate = strpos($msg, 'Duplicate') !== false || stripos($msg, 'duplicate') !== false || strpos($msg, '1060') !== false;
  if (!$isDuplicate) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'msg'=>'请执行: ALTER TABLE comment ADD COLUMN like_count INT NOT NULL DEFAULT 0']);
    exit;
  }
}

// 若 comment 表尚无 like_count（例如 ALTER 因权限未生效），先检测再提示
try {
  $pdo->query("SELECT like_count FROM comment LIMIT 1");
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'msg'=>'评论点赞功能未就绪，请在数据库中执行: ALTER TABLE comment ADD COLUMN like_count INT NOT NULL DEFAULT 0']);
  exit;
}

try {
  $pdo->beginTransaction();

  $st = $pdo->prepare("SELECT id, user_id, post_id FROM comment WHERE id=:id AND status=1 LIMIT 1");
  $st->execute([':id'=>$commentId]);
  $comment = $st->fetch();
  if (!$comment) {
    $pdo->rollBack();
    http_response_code(404);
    echo json_encode(['ok'=>false,'msg'=>'Comment Not Found']);
    exit;
  }

  $commentOwnerId = (int)$comment['user_id'];

  // comment_like 表只有 comment_id 和 user_id，查询存在性即可
  $st = $pdo->prepare("SELECT 1 FROM comment_like WHERE comment_id=:cid AND user_id=:uid LIMIT 1");
  $st->execute([':cid'=>$commentId, ':uid'=>$userId]);
  $liked = (bool)$st->fetch();

  if ($liked) {
    $st = $pdo->prepare("DELETE FROM comment_like WHERE comment_id=:cid AND user_id=:uid");
    $st->execute([':cid'=>$commentId, ':uid'=>$userId]);
    $st = $pdo->prepare("UPDATE comment SET like_count = GREATEST(COALESCE(like_count,0)-1,0) WHERE id=:id");
    $st->execute([':id'=>$commentId]);
    $newLiked = false;
  } else {
    $st = $pdo->prepare("INSERT INTO comment_like (comment_id, user_id) VALUES (:cid,:uid)");
    $st->execute([':cid'=>$commentId, ':uid'=>$userId]);
    $st = $pdo->prepare("UPDATE comment SET like_count = COALESCE(like_count,0)+1 WHERE id=:id");
    $st->execute([':id'=>$commentId]);
    $newLiked = true;
  }

  $st = $pdo->prepare("SELECT COALESCE(like_count,0) AS cnt FROM comment WHERE id=:id");
  $st->execute([':id'=>$commentId]);
  $row = $st->fetch();
  $count = $row ? (int)$row['cnt'] : 0;

  $pdo->commit();

  if ($newLiked) {
    require_once __DIR__ . '/app/points.php';
    points_refresh_comment_engagement_milestones($commentOwnerId);
  }

  $rewards = function_exists('points_drain_rewards') ? points_drain_rewards() : [];
  $res = ['ok'=>true,'liked'=>$newLiked,'count'=>$count,'data'=>['liked'=>$newLiked,'count'=>$count]];
  if (!empty($rewards)) $res['rewards'] = $rewards;
  echo json_encode($res);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  $err = $e->getMessage();
  $msg = '点赞失败，请稍后重试';
  if (strpos($err, 'like_count') !== false || strpos($err, 'comment_like') !== false || strpos($err, "doesn't exist") !== false || stripos($err, 'Unknown column') !== false) {
    $msg = '评论点赞功能未就绪，请在数据库中执行: ALTER TABLE comment ADD COLUMN like_count INT NOT NULL DEFAULT 0';
  }
  error_log('comment_like_toggle: ' . $err . ' in ' . $e->getFile() . ':' . $e->getLine());
  echo json_encode(['ok'=>false,'msg'=>$msg]);
}
