<?php
declare(strict_types=1);

// 防止重复包含
if (defined('BOOTSTRAP_LOADED') || function_exists('current_user_id')) {
  return;
}
define('BOOTSTRAP_LOADED', true);

// PHP 7.x 兼容函数：str_starts_with (PHP 8.0+)
if (!function_exists('str_starts_with')) {
  function str_starts_with(string $haystack, string $needle): bool {
    return $needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
  }
}

// PHP 7.x 兼容函数：str_ends_with (PHP 8.0+)
if (!function_exists('str_ends_with')) {
  function str_ends_with(string $haystack, string $needle): bool {
    return $needle !== '' && substr($haystack, -strlen($needle)) === $needle;
  }
}

// 全站统一 session
if (session_status() !== PHP_SESSION_ACTIVE) {
  // 记住我：根据 cookie 或本次登录请求的表单参数，设置持久化会话 cookie
  $rememberMe = false;
  if (isset($_COOKIE['sown_remember']) && $_COOKIE['sown_remember'] === '1') {
    $rememberMe = true;
  }
  if (isset($_POST['remember_me']) && ($_POST['remember_me'] === '1' || $_POST['remember_me'] === 'on')) {
    $rememberMe = true;
  }

  $lifetime = $rememberMe ? (60 * 60 * 24 * 30) : 0; // 30天；0=会话期 cookie（浏览器关闭即失效）

  // 安全策略：尽量启用 HTTPS 下的 secure cookie；如无 HTTPS，则 secure=false
  $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

  // 让服务端 gc 生命周期覆盖“记住我”的会话周期，避免客户端 cookie 仍在但会话已被清理
  if ($lifetime > 0) {
    ini_set('session.gc_maxlifetime', (string)$lifetime);
  }

  session_set_cookie_params([
    'lifetime' => $lifetime,
    'path' => '/',
    'domain' => '',
    'secure' => $isSecure,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);

  session_start();
}

// 登录用户 helper
if (!function_exists('current_user_id')) {
  function current_user_id(): ?int {
    return $_SESSION['user_id'] ?? null;
  }
}

if (!function_exists('is_logged_in')) {
  function is_logged_in(): bool {
    return isset($_SESSION['user_id']);
  }
}

if (!function_exists('next_url')) {
  function next_url(string $fallback = '/'): string {
    $next = $_GET['next'] ?? '';
    if (!is_string($next) || $next === '') return $fallback;
    
    // 只允许站内跳转，防止 open redirect
    if (str_starts_with($next, '/')) return $next;
    return $fallback;
  }
}

if (!function_exists('login_url')) {
  function login_url(string $next): string {
    return '/login.php?next=' . urlencode($next);
  }
}

if (!function_exists('require_login_or_redirect')) {
  function require_login_or_redirect(string $next): void {
    if (!is_logged_in()) {
      header('Location: ' . login_url($next));
      exit;
    }
  }
}

if (!function_exists('is_ajax')) {
  function is_ajax(): bool {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
      strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
  }
}

// Content-Security-Policy: XSS 纵深防御
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; img-src 'self' data:; font-src 'self' https://fonts.gstatic.com; connect-src 'self'; form-action 'self'; frame-ancestors 'none';");
  