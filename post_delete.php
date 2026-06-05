<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  header('Location: /forum.php');
  exit;
}

// 需要登录
if (!is_logged_in()) {
  header('Location: ' . login_url('/forum.php'));
  exit;
}

$currentUser = current_user();
if (!$currentUser) {
  header('Location: /login.php');
  exit;
}

if (!csrf_check($_POST['csrf'] ?? '')) {
  header('Location: /forum.php?err=csrf');
  exit;
}

$postId = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
if ($postId <= 0) {
  header('Location: /forum.php?err=invalid');
  exit;
}

$pdo = db();

try {
  // 检查帖子是否存在且是当前用户的
  $st = $pdo->prepare("SELECT id, user_id FROM post WHERE id=? AND status=1 LIMIT 1");
  $st->execute([$postId]);
  $post = $st->fetch();

  if (!$post) {
    header('Location: /forum.php?err=notfound');
    exit;
  }

  // 只能删除自己的帖子
  if ((int)$post['user_id'] !== (int)$currentUser['id']) {
    header('Location: /forum.php?err=permission');
    exit;
  }

  // 软删除：设置 status=0
  $st = $pdo->prepare("UPDATE post SET status=0 WHERE id=?");
  $st->execute([$postId]);

  header('Location: /forum.php?msg=deleted');
  exit;
} catch (Throwable $e) {
  header('Location: /forum.php?err=server');
  exit;
}

