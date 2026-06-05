<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/avatar.php';
require_once __DIR__ . '/app/helpers.php';

// 如果已登录，跳转
if (is_logged_in()) {
  safe_redirect('/forum.php');
}

// 获取错误消息（注册专用文案）
$err = input_string('err');
$errMsg = render_error($err, [
  'method'          => '请求方式不正确，请重试',
  'csrf'            => '安全验证失败，请刷新页面后重试',
  'username'        => '请输入用户名',
  'username_len'    => '用户名长度不能超过 50 个字符',
  'username_exists' => '该用户名已被占用，请换一个',
  'email'           => '请输入有效的邮箱地址',
  'email_exists'    => '该邮箱已注册，请直接登录',
  'password'        => '请输入密码（至少 6 位）',
  'password_short'  => '密码长度至少 6 位',
  'phone'           => '请输入11位手机号',
  'phone_exists'    => '该手机号已注册',
  'server'          => '服务器错误，请稍后重试',
]);

// 获取 next 参数
$next = input_string('next');
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>注册 · Sown</title>
  <?php require __DIR__ . '/partials/head.php'; ?>
  <link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(__DIR__.'/assets/app.css') ?>">
</head>
<body>
  <?php require __DIR__ . '/partials/header.php'; ?>
  <div class="container">

    <div class="section narrow-section">
      <h2>注册</h2>

      <?= $errMsg ?>

      <form method="post" action="/register_post.php" class="card">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <?php if ($next): ?>
          <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES, 'UTF-8') ?>">
        <?php endif; ?>
        <div class="input-icon-wrap">
          <svg class="input-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M20 21a8 8 0 0 0-16 0"></path>
            <circle cx="12" cy="7" r="4"></circle>
          </svg>
          <input class="input-icon-input" name="username" type="text" placeholder="用户名" required maxlength="50"
                 value="<?= htmlspecialchars(input_string('username', '', 'post'), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="input-icon-wrap">
          <svg class="input-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 4h16v16H4z"></path>
            <path d="M4 4l8 7 8-7"></path>
          </svg>
          <input class="input-icon-input" name="email" type="email" placeholder="邮箱" required
                 value="<?= htmlspecialchars(input_string('email', '', 'post'), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="input-icon-wrap">
          <svg class="input-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
          </svg>
          <input class="input-icon-input" name="phone" type="tel" placeholder="手机号" required
                 value="<?= htmlspecialchars(input_string('phone', '', 'post'), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="input-icon-wrap">
          <svg class="input-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
            <path d="M5 11h14v10H5z"></path>
          </svg>
          <input class="input-icon-input" name="password" type="password" placeholder="密码（至少6位）" required minlength="6">
        </div>
        <button class="btn primary" type="submit">注册</button>
      </form>

      <p class="form-footer-link">
        <a href="/login.php<?= $next ? '?next=' . urlencode($next) : '' ?>">已有账号？登录</a>
      </p>
    </div>

    <?php require __DIR__ . '/partials/footer.php'; ?>
  </div>

  <script src="/assets/app.js?v=<?= filemtime(__DIR__.'/assets/app.js') ?>"></script>
</body>
</html>
