<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/moderation.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'msg' => 'Method Not Allowed']);
  exit;
}

$commentId = isset($_POST['comment_id']) ? (int)$_POST['comment_id'] : 0;
$content = isset($_POST['content']) ? trim((string)$_POST['content']) : '';
$token = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';

if ($commentId <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'msg' => 'Bad Request']);
  exit;
}

if (!csrf_check($token)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'msg' => 'CSRF Forbidden']);
  exit;
}

if ($content === '' || mb_strlen($content) > 2000) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'msg' => '内容不能为空，且不能超过 2000 字']);
  exit;
}

// 违规检测：命中则写入统一屏蔽文案
$content = moderation_filter_comment_text($content)['text'];

$userId = current_user_id();
if (!$userId) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'code' => 'LOGIN', 'msg' => '请先登录']);
  exit;
}

$pdo = db();

try {
  // 检查评论是否存在且是当前用户的
  $st = $pdo->prepare("SELECT id, user_id, post_id FROM comment WHERE id=? AND status=1 LIMIT 1");
  $st->execute([$commentId]);
  $comment = $st->fetch();

  if (!$comment) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'msg' => '评论不存在']);
    exit;
  }

  // 只能编辑自己的评论
  if ((int)$comment['user_id'] !== $userId) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => '无权编辑此评论']);
    exit;
  }

  // 更新评论
  $st = $pdo->prepare("UPDATE comment SET content=?, updated_at=NOW() WHERE id=?");
  $st->execute([$content, $commentId]);

  echo json_encode(['ok' => true, 'content' => htmlspecialchars($content, ENT_QUOTES, 'UTF-8')]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'msg' => 'Server Error']);
}

