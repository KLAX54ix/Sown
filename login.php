<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/avatar.php';
require_once __DIR__ . '/app/helpers.php';

// 如果已登录，跳转
if (is_logged_in()) {
  safe_redirect(next_url('/forum.php'));
}

// 获取错误消息（登录专用文案）
$err = input_string('err');
$errMsg = render_error($err, [
  'method'   => '请求方式不正确，请重试',
  'csrf'     => '安全验证失败，请刷新页面后重试',
  'email'    => '请输入有效的邮箱地址',
  'password' => '请输入密码',
  'invalid'  => '账号或密码错误',
  'locked'   => '账号已被锁定，请15分钟后再试',
  'server'   => '服务器错误，请稍后重试',
]);

// 获取成功消息
$success = input_string('success');
$successMsg = '';
if ($success === 'registered') {
  $successMsg = render_success('注册成功！请登录。');
}

// 获取 next 参数
$next = input_string('next');
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>登录 · Sown</title>
  <?php require __DIR__ . '/partials/head.php'; ?>
  <link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(__DIR__.'/assets/app.css') ?>">
</head>
<body>
  <?php require __DIR__ . '/partials/header.php'; ?>
  <div class="container">

    <div class="section narrow-section">
      <h2>登录</h2>

      <?= $successMsg ?>
      <?= $errMsg ?>

      <div class="card">
        <div class="login-tabs">
          <button type="button" class="login-tab active" data-tab="password">密码登录</button>
        </div>

        <!-- 密码登录 -->
        <form method="post" action="/login_post.php" class="login-tab-content" id="loginTabPassword">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <?php if ($next): ?>
            <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES, 'UTF-8') ?>">
          <?php endif; ?>
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
              <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
              <path d="M5 11h14v10H5z"></path>
            </svg>
            <input class="input-icon-input" name="password" type="password" placeholder="密码" required>
          </div>
          <label style="display:flex;align-items:center;gap:8px;justify-content:flex-start;cursor:pointer;">
            <input type="checkbox" name="remember_me" value="1" checked>
            记住我
          </label>
          <button class="btn primary" type="submit">登录</button>
        </form>
      </div>

      <p class="form-footer-link">
        <a href="/register.php<?= $next ? '?next=' . urlencode($next) : '' ?>">没有账号？注册</a>
      </p>
    </div>

    <?php require __DIR__ . '/partials/footer.php'; ?>
  </div>

  <script src="/assets/app.js?v=<?= filemtime(__DIR__.'/assets/app.js') ?>"></script>
</body>
</html>
