<?php
declare(strict_types=1);

/**
 * 统一导航栏组件
 * 使用方式：require_once __DIR__ . '/partials/header.php';
 * 
 * 注意：调用此文件的页面应该已经加载了 bootstrap.php 和 auth.php
 * 这里只做防御性检查，避免重复加载
 */
if (!defined('BOOTSTRAP_LOADED')) {
  require_once __DIR__ . '/../app/bootstrap.php';
}
if (!function_exists('current_user')) {
  require_once __DIR__ . '/../app/auth.php';
}
if (!defined('HELPERS_LOADED')) {
  require_once __DIR__ . '/../app/helpers.php';
}
if (!function_exists('avatar_html')) {
  require_once __DIR__ . '/../app/avatar.php';
}
if (!function_exists('get_unread_notification_count')) {
  require_once __DIR__ . '/../app/notification.php';
}

$user = current_user();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
// 优先复用 head.php 中计算好的 filemtime（减少每次请求的磁盘 stat）
if (!isset($logoSvgTime)) {
  $logoSvgTime = file_exists(__DIR__ . '/../assets/New Sown.svg')
    ? filemtime(__DIR__ . '/../assets/New Sown.svg')
    : time();
}

// 获取未读通知数量
$unreadCount = 0;
if ($user) {
  $uid = (int)$user['id'];
  // 短时缓存：避免每次页面请求都对 notification 做 COUNT(*)
  $cacheTtlSeconds = 30;
  $cachedTs = $_SESSION['unread_notification_count_ts'][$uid] ?? 0;
  $cachedVal = $_SESSION['unread_notification_count_val'][$uid] ?? null;

  if ($cachedVal !== null && $cachedTs > 0 && (time() - (int)$cachedTs) < $cacheTtlSeconds) {
    $unreadCount = (int)$cachedVal;
  } else {
    $unreadCount = get_unread_notification_count($uid);
    $_SESSION['unread_notification_count_ts'][$uid] = time();
    $_SESSION['unread_notification_count_val'][$uid] = $unreadCount;
  }
}
?>
<nav class="mainNav">
  <div class="navContainer">
    <div class="navLeft">
      <a href="/" class="navLogo">
        <img src="/assets/New%20Sown.svg?v=<?= $logoSvgTime ?>" alt="Sown / 数问" class="navLogoImg">
      </a>
      <div class="navMenu">
        <a href="/" class="navLink">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
            <polyline points="9 22 9 12 15 12 15 22"></polyline>
          </svg>
          首页
        </a>
        <a href="/forum.php" class="navLink">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="2" y1="12" x2="22" y2="12"></line>
            <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
          </svg>
          社区
        </a>
        <?php if ($user): ?>
        <a href="/shop.php" class="navLink">
        <?php else: ?>
        <a href="/login.php?next=<?= urlencode('/shop.php') ?>" class="navLink" data-login-modal="1">
        <?php endif; ?>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;">
            <circle cx="9" cy="21" r="1"></circle>
            <circle cx="20" cy="21" r="1"></circle>
            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
          </svg>
          商城
        </a>
        <?php if ($user): ?>
        <a href="/drawboard.php" class="navLink">
        <?php else: ?>
        <a href="/login.php?next=<?= urlencode('/drawboard.php') ?>" class="navLink" data-login-modal="1">
        <?php endif; ?>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;">
            <path d="M12 19l7-7 3 3-7 7-3-3z"></path>
            <path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"></path>
            <path d="M2 2l7.586 7.586"></path>
            <circle cx="11" cy="11" r="2"></circle>
          </svg>
          画板
        </a>
      </div>
    </div>
    <div class="navSearch">
      <?php $searchAction = search_url(''); $searchAction = preg_replace('/\?q=$/', '', $searchAction); ?>
      <form method="get" action="<?= htmlspecialchars($searchAction, ENT_QUOTES, 'UTF-8') ?>" class="navSearchForm">
        <input type="text" name="q" placeholder=""
               value="<?= htmlspecialchars(input_string('q', '', 'get'), ENT_QUOTES, 'UTF-8') ?>"
               class="navSearchInput"
               maxlength="100">
        <button type="submit" class="navSearchBtn" title="搜索">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8"></circle>
            <path d="m21 21-4.35-4.35"></path>
          </svg>
        </button>
      </form>
      <script>
      (function(){
        var wrap = document.querySelector('.navSearch');
        var btn = wrap && wrap.querySelector('.navSearchBtn');
        var inp = wrap && wrap.querySelector('.navSearchInput');
        if (!wrap || !btn || !inp) return;
        function isMobile() { return window.innerWidth <= 768; }
        btn.addEventListener('click', function(e) {
          if (!isMobile()) return;
          if (!wrap.classList.contains('-open')) {
            e.preventDefault();
            wrap.classList.add('-open');
            setTimeout(function(){ inp.focus(); }, 0);
          }
        });
        inp.addEventListener('blur', function() {
          if (isMobile() && !inp.value) {
            wrap.classList.remove('-open');
          }
        });
        document.addEventListener('click', function(e) {
          if (isMobile() && wrap.classList.contains('-open') && !wrap.contains(e.target)) {
            wrap.classList.remove('-open');
          }
        });
      })();
      </script>
    </div>
    <div class="navRight">
      <?php if (is_logged_in() && $user): ?>
        <a href="/notifications.php" class="navNotify" title="通知中心" aria-label="通知中心">
          <svg class="navNotifyIcon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
          </svg>
          <?php if ($unreadCount > 0): ?>
            <span class="navNotifyBadge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
          <?php endif; ?>
        </a>
        <div class="user-menu">
          <a href="/user.php?id=<?= (int)$user['id'] ?>" class="user-avatar">
            <?= avatar_html($user, 28) ?>
            <span style="font-size:14px;"><?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></span>
            <svg class="chevron-down" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <polyline points="6 9 12 15 18 9"></polyline>
            </svg>
          </a>
          <div class="user-dropdown">
            <a href="/user.php?id=<?= (int)$user['id'] ?>">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                <circle cx="12" cy="7" r="4"></circle>
              </svg>
              个人主页
            </a>
            <a href="/creator.php">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 5v14"></path>
                <path d="M5 12h14"></path>
              </svg>
              创作平台
            </a>
            <a href="/my_orders.php">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="9" cy="21" r="1"></circle>
                <circle cx="20" cy="21" r="1"></circle>
                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
              </svg>
              我的兑换
            </a>
            <?php if (isset($user['role']) && $user['role'] === 'admin'): ?>
            <a href="/admin.php">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="3" width="7" height="7"></rect>
                <rect x="14" y="3" width="7" height="7"></rect>
                <rect x="14" y="14" width="7" height="7"></rect>
                <rect x="3" y="14" width="7" height="7"></rect>
              </svg>
              管理后台
            </a>
            <?php endif; ?>
            <a href="/settings.php">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="3"></circle>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
              </svg>
              设置
            </a>
            <div class="dropdown-divider"></div>
            <a href="/logout.php">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                <polyline points="16 17 21 12 16 7"></polyline>
                <line x1="21" y1="12" x2="9" y2="12"></line>
              </svg>
              退出登录
            </a>
          </div>
        </div>
      <?php else: ?>
        <?php
        $next = isset($_GET['next']) ? $_GET['next'] : $_SERVER['REQUEST_URI'];
        $loginUrl = '/login.php?next=' . urlencode($next);
        $registerUrl = '/register.php?next=' . urlencode($next);
        ?>
        <a href="<?= htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') ?>" class="navBtn" data-login-modal="1">登录</a>
        <a href="<?= htmlspecialchars($registerUrl, ENT_QUOTES, 'UTF-8') ?>" class="navBtn primary" data-register-modal="1">注册</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

