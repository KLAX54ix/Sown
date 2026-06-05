<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'msg'=>'Method Not Allowed']);
  exit;
}

header('Content-Type: application/json; charset=utf-8');

// 需要登录
if (!is_logged_in()) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'msg'=>'请先登录']);
  exit;
}

$currentUser = current_user();
if (!$currentUser) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'msg'=>'请先登录']);
  exit;
}

if (!csrf_check($_POST['csrf'] ?? '')) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'msg'=>'CSRF Forbidden']);
  exit;
}

$commentId = isset($_POST['comment_id']) ? (int)$_POST['comment_id'] : 0;
if ($commentId <= 0) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'msg'=>'Bad Request']);
  exit;
}

$pdo = db();

try {
  $pdo->beginTransaction();

  // 检查评论是否存在且是当前用户的
  $st = $pdo->prepare("SELECT id, post_id, user_id FROM comment WHERE id=? AND status=1 LIMIT 1");
  $st->execute([$commentId]);
  $comment = $st->fetch();

  if (!$comment) {
    $pdo->rollBack();
    http_response_code(404);
    echo json_encode(['ok'=>false,'msg'=>'Comment Not Found']);
    exit;
  }

  // 只能删除自己的评论
  if ((int)$comment['user_id'] !== (int)$currentUser['id']) {
    $pdo->rollBack();
    http_response_code(403);
    echo json_encode(['ok'=>false,'msg'=>'Permission Denied']);
    exit;
  }

  $postId = (int)$comment['post_id'];

  // 查找所有以该评论为父评论的回复（包括嵌套回复）
  // 使用递归查询或循环查找所有子评论
  $allCommentIds = [$commentId]; // 包含要删除的主评论
  $toDelete = [$commentId];
  
  // 循环查找所有子回复（包括嵌套的回复）
  while (!empty($toDelete)) {
    $placeholders = implode(',', array_fill(0, count($toDelete), '?'));
    $st = $pdo->prepare("SELECT id FROM comment WHERE parent_id IN ({$placeholders}) AND status=1");
    $st->execute($toDelete);
    $children = $st->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($children)) {
      break;
    }
    
    $allCommentIds = array_merge($allCommentIds, $children);
    $toDelete = $children;
  }
  
  $deleteCount = count($allCommentIds);

  // 软删除：设置所有评论和回复的 status=0
  $placeholders = implode(',', array_fill(0, $deleteCount, '?'));
  $st = $pdo->prepare("UPDATE comment SET status=0 WHERE id IN ({$placeholders})");
  $st->execute($allCommentIds);

  // 帖子评论数减去删除的数量
  $st = $pdo->prepare("UPDATE post SET comment_count = GREATEST(comment_count-?,0) WHERE id=?");
  $st->execute([$deleteCount, $postId]);

  // 获取更新后的评论数
  $st = $pdo->prepare("SELECT comment_count FROM post WHERE id=? LIMIT 1");
  $st->execute([$postId]);
  $post = $st->fetch();
  $newCommentCount = $post ? (int)$post['comment_count'] : 0;

  $pdo->commit();

  echo json_encode([
    'ok' => true,
    'post_id' => $postId,
    'deleted_count' => $deleteCount,
    'comment_count' => $newCommentCount
  ]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['ok'=>false,'msg'=>'Server Error']);
}

