<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/ratelimit.php';

check_rate_limit('login', 10, 60);  // 10次/分钟

// 登录弹窗使用 AJAX 提交，返回 JSON 而非跳转
$isAjax = is_ajax();

$errMsgs = [
  'method'   => '请求方式不正确，请重试',
  'csrf'     => '安全验证失败，请刷新页面后重试',
  'email'    => '请输入有效的邮箱地址',
  'password' => '请输入密码',
  'invalid'  => '账号或密码错误',
  'locked'   => '账号已被锁定，请15分钟后再试',
  'server'   => '服务器错误，请稍后重试',
];

$respond_err = function (string $errCode, int $httpCode = 400) use ($isAjax, $errMsgs) {
  $msg = $errMsgs[$errCode] ?? '请求失败';
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($httpCode);
    echo json_encode(['ok' => false, 'msg' => $msg]);
  } else {
    header('Location: /login.php?err=' . $errCode);
  }
  exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  $respond_err('method', 405);
}

if (!csrf_check($_POST['csrf'] ?? '')) {
  $respond_err('csrf', 403);
}

// === 密码登录 ===
$email = trim($_POST['email'] ?? '');
$pass  = $_POST['password'] ?? '';

// 参数验证
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  $respond_err('email');
}

if ($pass === '') {
  $respond_err('password');
}

$pdo = db();

try {
  // 先确保表有锁定字段
  try {
    $pdo->exec("ALTER TABLE user ADD COLUMN login_fail_count INT NOT NULL DEFAULT 0");
  } catch (Throwable $e) {}
  try {
    $pdo->exec("ALTER TABLE user ADD COLUMN login_locked_until DATETIME DEFAULT NULL");
  } catch (Throwable $e) {}

  $st = $pdo->prepare("SELECT id,password,login_fail_count,login_locked_until FROM user WHERE email=? AND status=1 LIMIT 1");
  $st->execute([$email]);
  $user = $st->fetch();

  if (!$user) {
    $respond_err('invalid');
  }

  // 检查账号是否被锁定
  $lockedUntil = $user['login_locked_until'] ?? null;
  if ($lockedUntil !== null) {
    $lockTime = strtotime($lockedUntil);
    if ($lockTime > time()) {
      $remain = ceil(($lockTime - time()) / 60);
      $respond_err('locked');
    }
  }

  if (!password_verify($pass, $user['password'])) {
    // 密码错误：增加失败次数
    $newCount = (int)($user['login_fail_count'] ?? 0) + 1;
    if ($newCount >= 5) {
      $pdo->prepare("UPDATE user SET login_fail_count = ?, login_locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?")
        ->execute([$newCount, (int)$user['id']]);
    } else {
      $pdo->prepare("UPDATE user SET login_fail_count = ? WHERE id = ?")
        ->execute([$newCount, (int)$user['id']]);
    }
    $respond_err('invalid');
  }

  // 登录成功：重置失败计数和锁定状态
  $pdo->prepare("UPDATE user SET login_fail_count = 0, login_locked_until = NULL WHERE id = ?")
    ->execute([(int)$user['id']]);

  $_SESSION['user_id'] = (int)$user['id'];

  // 记住我：写入一个长期标记 cookie，供后续请求决定 session cookie 的 lifetime
  $rememberMe = isset($_POST['remember_me']) && ($_POST['remember_me'] === '1' || $_POST['remember_me'] === 'on');
  if ($rememberMe) {
    $expires = time() + (60 * 60 * 24 * 30);
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    // 关键修复：重新生成 session ID，触发 PHP 发送新的 Set-Cookie 头
    session_regenerate_id(true);

    // 显式设置 session cookie 为 30 天有效期（覆盖之前 GET 请求设置的 lifetime=0）
    $sessParams = session_get_cookie_params();
    setcookie(
      session_name(),
      session_id(),
      $expires,
      $sessParams['path'],
      $sessParams['domain'],
      $sessParams['secure'],
      $sessParams['httponly']
    );

    // 写入标记 cookie，供后续请求识别"记住我"状态
    setcookie(
      'sown_remember',
      '1',
      $expires,
      '/',
      '',
      $isSecure,
      true
    );
  }

  // 支持next跳转
  $next = next_url('/forum.php');

  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'redirect' => $next]);
  } else {
    header('Location: ' . $next);
  }
  exit;
} catch (Throwable $e) {
  $respond_err('server', 500);
}
