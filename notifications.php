<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/notification.php';

// 需要登录
if (!is_logged_in()) {
  safe_redirect(login_url('/notifications.php'));
}

$user = current_user();
if (!$user) {
  safe_redirect('/login.php?next=' . urlencode('/notifications.php'));
}

$pdo = db();
$userId = $user['id'];

// 标记所有为已读（如果请求）
if (isset($_GET['mark_all_read']) && $_GET['mark_all_read'] === '1') {
  mark_all_notifications_read($userId);
  // 清除未读数缓存
  unset($_SESSION['unread_notification_count_ts'][$userId]);
  unset($_SESSION['unread_notification_count_val'][$userId]);
  header('Location: /notifications.php');
  exit;
}

// 获取通知列表（最多50条）
$notifications = get_notifications($userId, 50, false);

// 获取用户名映射
$userIds = [];
foreach ($notifications as $n) {
  $rid = $n['related_user_id'];
  if ($rid !== null && $rid !== '') {
    $userIds[(int)$rid] = true;
  }
}
$userIds = array_keys($userIds);
$userMap = [];
if (!empty($userIds)) {
  $placeholders = implode(',', array_fill(0, count($userIds), '?'));
  $st = $pdo->prepare("SELECT id, username FROM user WHERE id IN ({$placeholders})");
  $st->execute($userIds);
  foreach ($st->fetchAll() as $u) {
    $userMap[(int)$u['id']] = $u['username'];
  }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>通知中心 · Sown</title>
  <?php require __DIR__ . '/partials/head.php'; ?>
  <link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(__DIR__.'/assets/app.css') ?>">
</head>
<body>
  <?php require __DIR__ . '/partials/header.php'; ?>
  <div class="container">

    <div class="section">
      <div class="section-header" style="display:flex; justify-content:space-between; align-items:center;">
        <div>
          <h2>通知中心</h2>
          <p class="sub">共 <?= count($notifications) ?> 条通知</p>
        </div>
        <?php if (!empty($notifications)): ?>
          <a href="/notifications.php?mark_all_read=1" class="btn btn-small">全部标记为已读</a>
        <?php endif; ?>
      </div>

      <div class="post-list">
        <?php if (empty($notifications)): ?>
          <div class="post-item empty">
            <div class="post-content">
              <div class="post-title">还没有通知</div>
              <div class="post-meta">当有人评论、点赞你的内容或关注你时，会收到通知。</div>
            </div>
          </div>
        <?php else: ?>
          <?php foreach ($notifications as $n): ?>
            <div class="post-item" style="<?= $n['is_read'] ? '' : 'background:#f9f9f9;' ?>">
              <div class="post-main" style="flex:1;">
                <?php
                  $typeLabel = '';
                  $link = '';
                  $username = $userMap[(int)$n['related_user_id']] ?? '某用户';
                  $hideUser = false; // 系统通知不显示用户名

                  $postId = 0;
                  switch ($n['type']) {
                    case 'comment':
                      $typeLabel = '评论了你的内容';
                      $link = '/post.php?id=' . (int)$n['related_id'];
                      $postId = (int)$n['related_id'];
                      break;
                    case 'reply':
                      $typeLabel = '回复了你的评论';
                      $link = '/post.php?id=' . (int)$n['related_id'];
                      $postId = (int)$n['related_id'];
                      break;
                    case 'like':
                      $typeLabel = '点赞了你的内容';
                      $link = '/post.php?id=' . (int)$n['related_id'];
                      $postId = (int)$n['related_id'];
                      break;
                    case 'follow':
                      $typeLabel = '关注了你';
                      // 用户已注销时不生成链接
                      if (isset($userMap[$n['related_user_id']])) {
                        $link = '/user.php?id=' . (int)$n['related_user_id'];
                      }
                      break;
                    case 'shipped':
                      $typeLabel = '订单已发货';
                      $link = '/my_orders.php';
                      $hideUser = true;
                      break;
                  }
                ?>
                <div class="post-title">
                  <?php if ($link): ?>
                    <a href="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>"
                       class="notification-link"
                       data-notification-id="<?= (int)$n['id'] ?>"
                       data-is-read="<?= $n['is_read'] ? '1' : '0' ?>"
                       <?= $postId ? 'data-post-id="' . $postId . '"' : '' ?>
                       style="text-decoration:none; color:inherit;">
                      <?php if ($hideUser): ?>
                        <?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?>
                      <?php else: ?>
                        <strong><?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?></strong> <?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?>
                      <?php endif; ?>
                    </a>
                  <?php else: ?>
                    <?php if ($hideUser): ?>
                      <?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?>
                    <?php else: ?>
                      <strong><?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?></strong> <?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
                <?php if ($n['content']): ?>
                  <div class="post-meta" style="margin-top:4px; color:#666;">
                    <?= htmlspecialchars($n['content'], ENT_QUOTES, 'UTF-8') ?>
                  </div>
                <?php endif; ?>
                <div class="post-meta">
                  <span class="post-time"><?= time_ago($n['created_at']) ?></span>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <?php require __DIR__ . '/partials/footer.php'; ?>
  </div>

  <script src="/assets/app.js?v=<?= filemtime(__DIR__.'/assets/app.js') ?>"></script>
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
  <script>
  (function() {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    
    function updateUnreadBadges() {
      const unreadBadge = document.querySelector('.navNotifyBadge');
      if (unreadBadge) {
        const currentCount = parseInt(unreadBadge.textContent.replace('+', ''), 10) || 0;
        if (currentCount > 1) {
          unreadBadge.textContent = currentCount - 1 > 99 ? '99+' : String(currentCount - 1);
        } else {
          unreadBadge.remove();
        }
      }
      const menuNotificationLink = document.querySelector('.user-dropdown a[href="/notifications.php"]');
      if (menuNotificationLink) {
        const text = menuNotificationLink.textContent.replace(/\(\d+\)/, '');
        const currentCount = parseInt(menuNotificationLink.textContent.match(/\((\d+)\)/)?.[1] || '0') || 0;
        if (currentCount > 1) {
          menuNotificationLink.textContent = text + ' (' + (currentCount - 1) + ')';
        } else {
          menuNotificationLink.textContent = text;
        }
      }
    }
    
    document.querySelectorAll('.notification-link').forEach(link => {
      link.addEventListener('click', async function(e) {
        const notificationId = this.getAttribute('data-notification-id');
        const isRead = this.getAttribute('data-is-read') === '1';
        const href = this.getAttribute('href');
        const postId = parseInt(this.getAttribute('data-post-id'), 10) || 0;
        
        // 帖子类通知：跳转 post.php 查看详情
        if (postId) {
          e.preventDefault();
          if (!isRead) {
            try {
              const fd = new FormData();
              fd.append('notification_id', notificationId);
              fd.append('csrf', csrf);
              const result = await safeFetchJSON('/notification_read.php', { method:'POST', body: fd });
              if (result.ok) {
                this.setAttribute('data-is-read', '1');
                this.closest('.post-item').style.background = '';
                updateUnreadBadges();
              }
            } catch (err) { /* 忽略 */ }
          }
          window.location.href = href;
          return;
        }
        
        // 用户类通知（关注等）：原逻辑
        if (isRead) return;
        e.preventDefault();
        try {
          const fd = new FormData();
          fd.append('notification_id', notificationId);
          fd.append('csrf', csrf);
          const result = await safeFetchJSON('/notification_read.php', { method:'POST', body: fd });
          if (result.ok) {
            this.setAttribute('data-is-read', '1');
            this.closest('.post-item').style.background = '';
            updateUnreadBadges();
          }
          window.location.href = href;
        } catch (err) {
          window.location.href = href;
        }
      });
    });
  })();
  </script>
</body>
</html>

