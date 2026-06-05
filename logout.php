<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

// 清除 session 数据
$_SESSION = [];

// 删除 session cookie
if (ini_get("session.use_cookies")) {
  $params = session_get_cookie_params();
  setcookie(session_name(), '', time() - 42000,
    $params["path"], $params["domain"],
    $params["secure"], $params["httponly"]
  );
}

// 销毁 session
session_destroy();

// 重定向到首页
$params = session_get_cookie_params();
if (isset($_COOKIE['sown_remember'])) {
  // 删除“记住我”标记 cookie
  setcookie('sown_remember', '', time() - 42000, '/', $params['domain'] ?? '', $params['secure'] ?? false, true);
}

header('Location: /');
exit;
