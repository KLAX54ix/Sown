<?php
declare(strict_types=1);

/**
 * 统一页脚组件
 * 使用方式：require __DIR__ . '/partials/footer.php';
 */
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// 确保可以使用 csrf_token（用于登录弹窗表单）
if (!function_exists('csrf_token')) {
  require_once __DIR__ . '/../app/csrf.php';
}

// 检查是否有待显示的积分获得通知
$_pendingRewards = [];
if (!function_exists('points_drain_rewards')) {
  require_once __DIR__ . '/../app/points.php';
}
if (function_exists('points_drain_rewards')) {
  $_pendingRewards = points_drain_rewards();
}
?>
<!-- 应老师 AI 问答悬浮入口 -->
<?php if (is_logged_in()): ?>
<a href="/ask_teacher.php" class="ai-teacher-float" title="向应老师提问" aria-label="向应老师提问">
  <span class="ai-teacher-float-img">
    <img src="/data/teacher/%E5%86%99%E7%9C%9F.jpg" alt="应老师">
  </span>
  <span class="ai-teacher-float-label">问应老师</span>
</a>
<?php else: ?>
<a href="/login.php?next=<?= urlencode('/ask_teacher.php') ?>" class="ai-teacher-float" title="向应老师提问" aria-label="向应老师提问" data-login-modal="1">
  <span class="ai-teacher-float-img">
    <img src="/data/teacher/%E5%86%99%E7%9C%9F.jpg" alt="应老师">
  </span>
  <span class="ai-teacher-float-label">问应老师</span>
</a>
<?php endif; ?>

<style>
.ai-teacher-float {
  position: fixed;
  right: 24px;
  bottom: 80px;
  z-index: 100;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  text-decoration: none;
  transition: transform 0.25s;
}
.ai-teacher-float:hover {
  transform: scale(1.08);
}
.ai-teacher-float-img {
  width: 76px;
  height: 76px;
  border-radius: 50%;
  overflow: hidden;
  border: 3px solid #fff;
  box-shadow: 0 4px 18px rgba(0,0,0,0.16);
  display: block;
  flex-shrink: 0;
  transition: box-shadow 0.25s;
}
.ai-teacher-float:hover .ai-teacher-float-img {
  box-shadow: 0 8px 30px rgba(0,0,0,0.25);
}
.ai-teacher-float-img img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.ai-teacher-float-label {
  font-size: 12px;
  color: #555;
  font-weight: 500;
  white-space: nowrap;
  background: rgba(255,255,255,0.85);
  backdrop-filter: blur(4px);
  padding: 2px 10px;
  border-radius: 10px;
  box-shadow: 0 1px 4px rgba(0,0,0,0.08);
}
@media (max-width: 600px) {
  .ai-teacher-float { right: 16px; bottom: 72px; }
  .ai-teacher-float-img { width: 64px; height: 64px; border-width: 2.5px; }
}
</style>

<!-- 全局登录弹窗（未登录点赞 / 收藏 / 评论时使用） -->
<div id="loginModal" class="login-modal-backdrop" style="display:none;">
  <div class="login-modal">
    <button type="button" class="login-modal-close" aria-label="关闭登录" onclick="window.closeLoginModal && window.closeLoginModal();">×</button>
    <div class="login-modal-inner">
      <div class="login-modal-left">
        <div class="login-modal-brand">
          <img src="/assets/New%20Sown.svg" alt="Sown / 数问" style="height:48px;width:auto;display:block;">
        </div>
        <div class="login-modal-slogan">登录后推荐更懂你的内容</div>
        <div class="login-modal-illustration">
          <div class="login-modal-circle login-modal-circle-1"></div>
          <div class="login-modal-circle login-modal-circle-2"></div>
          <div class="login-modal-circle login-modal-circle-3"></div>
        </div>
        <div class="login-modal-tip">用数学播种，让理解自然生长。</div>
      </div>
      <div class="login-modal-right">
        <div class="login-modal-title">邮箱登录</div>
        <div class="login-modal-tabs">
        </div>
        <form method="post" action="/login_post.php" id="loginModalForm" class="login-modal-form login-modal-tab-content" data-modal-tab-content="password">
          <div id="loginModalErr" class="alert error" style="display:none;"></div>
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="next" id="loginModalNext" value="">
            <div class="input-icon-wrap">
              <svg class="input-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M4 4h16v16H4z"></path>
                <path d="M4 4l8 7 8-7"></path>
              </svg>
              <input class="input-icon-input" name="email" type="email" placeholder="邮箱" required autocomplete="email">
            </div>
          <div class="password-field">
              <svg class="input-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                <path d="M5 11h14v10H5z"></path>
              </svg>
            <input id="loginPassword" name="password" type="password" placeholder="密码" required autocomplete="current-password">
            <button type="button" class="password-toggle" data-target="loginPassword" aria-label="显示或隐藏密码">👁</button>
          </div>
          <label style="display:flex;align-items:center;gap:8px;justify-content:flex-start;cursor:pointer;">
            <input type="checkbox" name="remember_me" value="1" checked>
            记住我
          </label>
          <button class="btn primary" type="submit">登录</button>
        </form>
        <div class="login-modal-footer-text">
          没有账号？
          <a id="loginModalRegisterLink" href="/register.php" data-register-modal="1">去注册</a>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- 全局注册弹窗（样式与登录一致） -->
<div id="registerModal" class="login-modal-backdrop" style="display:none;">
  <div class="login-modal">
    <button type="button" class="login-modal-close" aria-label="关闭注册" onclick="window.closeLoginModal && window.closeLoginModal();">×</button>
    <div class="login-modal-inner">
      <div class="login-modal-left">
        <div class="login-modal-brand">
          <img src="/assets/New%20Sown.svg" alt="Sown / 数问" style="height:48px;width:auto;display:block;">
        </div>
        <div class="login-modal-slogan">注册后就可以发布、收藏和关注内容</div>
        <div class="login-modal-illustration">
          <div class="login-modal-circle login-modal-circle-1"></div>
          <div class="login-modal-circle login-modal-circle-2"></div>
          <div class="login-modal-circle login-modal-circle-3"></div>
        </div>
        <div class="login-modal-tip">用数学播种，让理解自然生长。</div>
      </div>
      <div class="login-modal-right">
        <div class="login-modal-title">注册</div>
        <form method="post" action="/register_post.php" id="registerModalForm" class="login-modal-form">
          <div id="registerModalErr" class="alert error" style="display:none;"></div>
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="next" id="registerModalNext" value="">
          <div class="input-icon-wrap">
            <svg class="input-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M20 21a8 8 0 0 0-16 0"></path>
              <circle cx="12" cy="7" r="4"></circle>
            </svg>
            <input class="input-icon-input" name="username" type="text" placeholder="用户名" required maxlength="50" autocomplete="username">
          </div>
          <div class="input-icon-wrap">
            <svg class="input-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M4 4h16v16H4z"></path>
              <path d="M4 4l8 7 8-7"></path>
            </svg>
            <input class="input-icon-input" name="email" type="email" placeholder="邮箱" required autocomplete="email">
          </div>
          <div class="input-icon-wrap">
            <svg class="input-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
            </svg>
            <input class="input-icon-input" name="phone" type="tel" placeholder="手机号" required autocomplete="tel">
          </div>
          <div class="password-field">
            <svg class="input-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
              <path d="M5 11h14v10H5z"></path>
            </svg>
            <input id="registerPassword" name="password" type="password" placeholder="密码（至少6位）" required minlength="6" autocomplete="new-password">
            <button type="button" class="password-toggle" data-target="registerPassword" aria-label="显示或隐藏密码">👁</button>
          </div>
          <div class="password-field">
            <svg class="input-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
              <path d="M5 11h14v10H5z"></path>
            </svg>
            <input id="registerPasswordConfirm" name="password_confirm" type="password" placeholder="确认密码" required minlength="6" autocomplete="new-password">
            <button type="button" class="password-toggle" data-target="registerPasswordConfirm" aria-label="显示或隐藏密码">👁</button>
          </div>
          <button class="btn primary" type="submit">注册</button>
        </form>
        <div class="login-modal-footer-text">
          已有账号？
          <a id="registerModalLoginLink" href="/login.php" data-login-modal="1">去登录</a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if (!empty($_pendingRewards)): ?>
<script>window._pendingRewards = <?= json_encode($_pendingRewards) ?>;</script>
<?php endif; ?>
