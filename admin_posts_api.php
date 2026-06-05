<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/admin.php';

admin_ensure_schema();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'msg' => 'Method Not Allowed']);
  exit;
}

$token = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
if (!csrf_check($token)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'msg' => 'CSRF Forbidden']);
  exit;
}

require_admin();

$action = isset($_POST['action']) ? (string)$_POST['action'] : '';
$postId = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
$postIdsRaw = isset($_POST['post_ids']) ? (string)$_POST['post_ids'] : '';

$pdo = db();

try {
  switch ($action) {
    case 'approve':
      if ($postId <= 0) {
        echo json_encode(['ok' => false, 'msg' => '参数错误']);
        exit;
      }
      $st = $pdo->prepare("UPDATE post SET review_status = 0 WHERE id = ? AND status = 1");
      $st->execute([$postId]);
      admin_log('post_approve', 'post', $postId, '批准帖子 #' . $postId);
      echo json_encode(['ok' => true, 'msg' => '已批准']);
      break;

    case 'reject':
      if ($postId <= 0) {
        echo json_encode(['ok' => false, 'msg' => '参数错误']);
        exit;
      }
      $st = $pdo->prepare("UPDATE post SET review_status = 2 WHERE id = ? AND status = 1");
      $st->execute([$postId]);
      admin_log('post_reject', 'post', $postId, '拒绝帖子 #' . $postId);
      echo json_encode(['ok' => true, 'msg' => '已拒绝']);
      break;

    case 'delete':
      if ($postId <= 0) {
        echo json_encode(['ok' => false, 'msg' => '参数错误']);
        exit;
      }
      $pdo->beginTransaction();
      $pdo->prepare("DELETE FROM comment WHERE post_id = ?")->execute([$postId]);
      $pdo->prepare("DELETE FROM post_tag_relation WHERE post_id = ?")->execute([$postId]);
      $pdo->prepare("DELETE FROM post_like WHERE post_id = ?")->execute([$postId]);
      $pdo->prepare("DELETE FROM post_favorite WHERE post_id = ?")->execute([$postId]);
      $pdo->prepare("DELETE FROM notification WHERE related_id = ? AND type IN ('comment','reply','like')")->execute([$postId]);
      $pdo->prepare("DELETE FROM post WHERE id = ?")->execute([$postId]);
      $pdo->commit();
      admin_log('post_delete', 'post', $postId, '永久删除帖子 #' . $postId);
      echo json_encode(['ok' => true, 'msg' => '已永久删除']);
      break;

    case 'batch_approve':
      if ($postIdsRaw === '') {
        echo json_encode(['ok' => false, 'msg' => '参数错误']);
        exit;
      }
      $ids = array_map('intval', explode(',', $postIdsRaw));
      $ids = array_filter($ids, fn($v) => $v > 0);
      if (empty($ids)) {
        echo json_encode(['ok' => false, 'msg' => '参数错误']);
        exit;
      }
      $placeholders = implode(',', array_fill(0, count($ids), '?'));
      $st = $pdo->prepare("UPDATE post SET review_status = 0 WHERE id IN ($placeholders) AND status = 1");
      $st->execute(array_values($ids));
      admin_log('post_batch_approve', 'post', 0, '批量批准 ' . count($ids) . ' 篇帖子');
      echo json_encode(['ok' => true, 'msg' => '已批量批准']);
      break;

    case 'batch_reject':
      if ($postIdsRaw === '') {
        echo json_encode(['ok' => false, 'msg' => '参数错误']);
        exit;
      }
      $ids = array_map('intval', explode(',', $postIdsRaw));
      $ids = array_filter($ids, fn($v) => $v > 0);
      if (empty($ids)) {
        echo json_encode(['ok' => false, 'msg' => '参数错误']);
        exit;
      }
      $placeholders = implode(',', array_fill(0, count($ids), '?'));
      $st = $pdo->prepare("UPDATE post SET review_status = 2 WHERE id IN ($placeholders) AND status = 1");
      $st->execute(array_values($ids));
      admin_log('post_batch_reject', 'post', 0, '批量拒绝 ' . count($ids) . ' 篇帖子');
      echo json_encode(['ok' => true, 'msg' => '已批量拒绝']);
      break;

    default:
      echo json_encode(['ok' => false, 'msg' => '未知操作']);
  }
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'msg' => '服务器错误']);
  error_log('admin_posts_api: ' . $e->getMessage());
}
