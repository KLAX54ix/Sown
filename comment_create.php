<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/notification.php';
require_once __DIR__ . '/app/moderation.php';

// 检查是否是 AJAX 请求
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => 'Method Not Allowed']);
  } else {
  echo "Method Not Allowed";
  }
  exit;
}

$postId = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
$content = isset($_POST['content']) ? trim((string)$_POST['content']) : '';
$parentId = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : 0;
$token = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';

if ($postId <= 0) {
  http_response_code(400);
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => 'Bad Request']);
  } else {
  echo "Bad Request";
  }
  exit;
}
if (!csrf_check($token)) {
  http_response_code(403);
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => 'CSRF Forbidden']);
  } else {
  echo "CSRF Forbidden";
  }
  exit;
}
if ($content === '' || mb_strlen($content) > 2000) {
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => '内容不能为空，且不能超过 2000 字']);
  } else {
  header("Location: /post.php?id={$postId}&err=content");
  }
  exit;
}

// 违规检测：命中则写入统一屏蔽文案
$content = moderation_filter_comment_text($content)['text'];

$userId = current_user_id();
if (!$userId) {
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      'ok' => false,
      'code' => 'LOGIN',
      'login' => login_url('/post.php?id=' . $postId),
      'msg' => '请先登录'
    ]);
  } else {
  header('Location: ' . login_url('/post.php?id=' . $postId));
  }
  exit;
}


$pdo = db();

try {
  $pdo->beginTransaction();

  // 确认帖子存在且可评论，并获取帖子作者ID
  $stmt = $pdo->prepare("SELECT id, user_id FROM post WHERE id=:id AND status=1 AND (review_status IS NULL OR review_status = 0) LIMIT 1");
  $stmt->execute([':id' => $postId]);
  $post = $stmt->fetch();
  if (!$post) {
    $pdo->rollBack();
    http_response_code(404);
    $msg = '内容未通过审核';
    if ($isAjax) {
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok' => false, 'msg' => $msg]);
    } else {
    echo $msg;
    }
    exit;
  }

  $postOwnerId = (int)$post['user_id'];

  // 如果 parent_id 存在，验证父评论是否存在且属于同一帖子
  $parentCommentUserId = null;
  if ($parentId > 0) {
    $pstmt = $pdo->prepare("SELECT id, user_id, post_id FROM comment WHERE id=:id AND status=1 LIMIT 1");
    $pstmt->execute([':id' => $parentId]);
    $parentComment = $pstmt->fetch();
    if (!$parentComment || (int)$parentComment['post_id'] !== $postId) {
      $pdo->rollBack();
      http_response_code(400);
      if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'msg' => 'Invalid parent comment']);
      } else {
        echo "Invalid parent comment";
      }
      exit;
    }
    $parentCommentUserId = (int)$parentComment['user_id'];
  }

  // 插入评论
  $stmt = $pdo->prepare("
    INSERT INTO comment (post_id, user_id, content, parent_id, status)
    VALUES (:post_id, :user_id, :content, :parent_id, 1)
  ");
  $stmt->execute([
    ':post_id' => $postId,
    ':user_id' => $userId,
    ':content' => $content,
    ':parent_id' => $parentId > 0 ? $parentId : null,
  ]);

  // 帖子评论数 +1（性能友好，避免每次 COUNT）
  $stmt = $pdo->prepare("UPDATE post SET comment_count = comment_count + 1 WHERE id = :id");
  $stmt->execute([':id' => $postId]);

  // 发送通知
    $contentPreview = mb_strlen($content) > 50 ? mb_substr($content, 0, 50) . '...' : $content;
  
  // 如果是回复，通知被回复的用户
  if ($parentId > 0 && $parentCommentUserId && $parentCommentUserId !== $userId) {
    create_notification($parentCommentUserId, 'reply', $postId, $userId, $contentPreview);
  }
  // 如果是主评论，通知帖子作者（如果不是自己评论自己）
  elseif ($parentId == 0 && $postOwnerId !== $userId) {
    create_notification($postOwnerId, 'comment', $postId, $userId, $contentPreview);
  }

  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => 'Server Error']);
  } else {
  echo "Server Error";
  }
  exit;
}

// 检查是否是 AJAX 请求
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isAjax) {
  // AJAX 请求返回 JSON
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => true, 'msg' => '评论成功']);
} else {
  // 普通请求使用 PRG 模式：避免刷新重复提交
header("Location: /post.php?id={$postId}#comments");
}
exit;
