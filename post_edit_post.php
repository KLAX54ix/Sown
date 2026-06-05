<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/tag.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  header('Location: /forum.php?err=method');
  exit;
}

// 需要登录
require_login_or_redirect('/forum.php');

$user = current_user();
if (!$user) {
  header('Location: /login.php?next=' . urlencode('/forum.php'));
  exit;
}

if (!csrf_check($_POST['csrf'] ?? '')) {
  header('Location: /forum.php?err=csrf');
  exit;
}

$postId = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
$title = trim($_POST['title'] ?? '');
$content = trim($_POST['content'] ?? '');

// 参数验证
if ($postId <= 0) {
  header('Location: /forum.php?err=invalid');
  exit;
}

if ($title === '') {
  header("Location: /post_edit.php?id={$postId}&err=title");
  exit;
}

if (mb_strlen($title) > 200) {
  header("Location: /post_edit.php?id={$postId}&err=title_len");
  exit;
}

if ($content === '') {
  header("Location: /post_edit.php?id={$postId}&err=content");
  exit;
}

if (mb_strlen($content) > 10000) {
  header("Location: /post_edit.php?id={$postId}&err=content_len");
  exit;
}

$pdo = db();

try {
  // 统一规则：帖子发布后不可编辑，这里直接拒绝请求
  http_response_code(403);
  header("Location: /post.php?id={$postId}&err=immutable");
  exit;
} catch (Throwable $e) {
  http_response_code(500);
  header("Location: /post.php?id={$postId}&err=server");
  exit;
}

