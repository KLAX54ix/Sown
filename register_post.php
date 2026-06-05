<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/ratelimit.php';

check_rate_limit('register', 5, 300);  // 5次/5分钟

// 注册弹窗使用 AJAX 提交，返回 JSON 而非跳转
$isAjax = is_ajax();

$errMsgs = [
  'method'          => '请求方式不正确，请重试',
  'csrf'            => '安全验证失败，请刷新页面后重试',
  'username'        => '请输入用户名',
  'username_len'    => '用户名长度不能超过 50 个字符',
  'username_exists' => '该用户名已被占用，请换一个',
  'email'           => '请输入有效的邮箱地址',
  'email_exists'    => '该邮箱已注册，请直接登录',
  'phone'           => '手机号格式不正确，请输11位手机号',
  'phone_exists'    => '该手机号已注册',
  'password'        => '请输入密码（至少 6 位）',
  'password_confirm'=> '两次输入的密码不一致',
  'server'          => '服务器错误，请稍后重试',
];

$respond_err = function (string $errCode, int $httpCode = 400) use ($isAjax, $errMsgs) {
  $msg = $errMsgs[$errCode] ?? '请求失败';
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($httpCode);
    echo json_encode(['ok' => false, 'msg' => $msg]);
  } else {
    header('Location: /register.php?err=' . $errCode);
  }
  exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  $respond_err('method', 405);
}

if (!csrf_check($_POST['csrf'] ?? '')) {
  $respond_err('csrf', 403);
}

$username = trim($_POST['username'] ?? '');
$email    = trim($_POST['email'] ?? '');
$phone    = trim($_POST['phone'] ?? '');
$password = $_POST['password'] ?? '';
$passwordConfirm = $_POST['password_confirm'] ?? '';

// 参数验证
if ($username === '') {
  $respond_err('username');
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  $respond_err('email');
}

if (strlen($password) < 6) {
  $respond_err('password');
}

// 二次确认密码不一致
if ($passwordConfirm === '' || $password !== $passwordConfirm) {
  $respond_err('password_confirm');
}

// 用户名长度限制
if (mb_strlen($username) > 50) {
  $respond_err('username_len');
}

// 手机号验证
if ($phone === '' || !preg_match('/^1\d{10}$/', $phone)) {
  $respond_err('phone');
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$pdo = db();

try {
  // 检查邮箱是否已注册
  $st = $pdo->prepare("SELECT id FROM user WHERE email=? LIMIT 1");
  $st->execute([$email]);
  if ($st->fetch()) {
    $respond_err('email_exists');
  }

  // 检查用户名是否已存在
  $st = $pdo->prepare("SELECT id FROM user WHERE username=? LIMIT 1");
  $st->execute([$username]);
  if ($st->fetch()) {
    $respond_err('username_exists');
  }

  // 检查手机号是否已注册
  $st = $pdo->prepare("SELECT id FROM user WHERE phone=? LIMIT 1");
  $st->execute([$phone]);
  if ($st->fetch()) {
    $respond_err('phone_exists');
  }

  // 插入新用户（先插入以获取ID）
  $st = $pdo->prepare(
    "INSERT INTO user (username,email,phone,password,status)
     VALUES (?,?,?,?,1)"
  );
  $st->execute([$username, $email, $phone, $hash]);
  
  // 获取新用户的ID并生成账号
  $newUserId = (int)$pdo->lastInsertId();
  require_once __DIR__ . '/app/account.php';
  $account = generate_account($newUserId);
  
  // 更新账号字段
  $st = $pdo->prepare("UPDATE user SET account = ? WHERE id = ?");
  $st->execute([$account, $newUserId]);

  // 支持next跳转，跳转到登录页面并显示成功消息
  $next = next_url('/forum.php');
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'redirect' => '/login.php?success=registered&next=' . urlencode($next)]);
  } else {
    header('Location: /login.php?success=registered&next=' . urlencode($next));
  }
} catch (Throwable $e) {
  $respond_err('server', 500);
}
