<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/avatar.php';
require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/tag.php';

// 需要登录
if (!is_logged_in()) {
  safe_redirect(login_url('/forum.php'));
}

$user = current_user();
if (!$user) {
  safe_redirect('/login.php?next=' . urlencode('/forum.php'));
}

// 获取帖子ID
$postId = input_int('id', 0, 0);
if ($postId <= 0) {
  http_response_code(404);
  echo "内容不存在";
  exit;
}

$pdo = db();

// 检查帖子是否属于当前用户
$st = $pdo->prepare("SELECT id, status FROM post WHERE id = ? AND user_id = ?");
$st->execute([$postId, $user['id']]);
$post = $st->fetch();

if ($post) {
  // 重定向到创作者平台的编辑页面
  header('Location: /creator.php?action=edit&id=' . $postId);
  exit;
} else {
  http_response_code(404);
  echo "内容不存在或无权编辑";
  exit;
}

