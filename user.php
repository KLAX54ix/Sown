<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/avatar.php';
require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/account.php';

// 获取用户ID
$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($userId <= 0) {
  http_response_code(404);
  echo "用户不存在";
  exit;
}

try {
  $pdo = db();
  
  // 先获取目标用户信息（在 current_user() 之前，避免变量覆盖）
  $st = $pdo->prepare("SELECT id, account, username, bio, email, created_at, privacy_show_favorites FROM user WHERE id = ? AND status = 1 LIMIT 1");
  $st->execute([$userId]);
  $targetUser = $st->fetch(PDO::FETCH_ASSOC);
  
  if (!$targetUser || empty($targetUser)) {
    http_response_code(404);
    echo "用户不存在";
    exit;
  }

  // 确保账号存在
  if (empty($targetUser['account'])) {
    $targetUser['account'] = ensure_user_account($userId);
  }
  
  // 确保 created_at 字段存在
  if (!isset($targetUser['created_at'])) {
    $targetUser['created_at'] = '';
  }
  
  // 获取当前登录用户信息（用于权限检查）
  $currentUser = current_user();
  $currentUserId = $currentUser ? $currentUser['id'] : null;

  // 获取用户称号
  $userTitle = get_user_title($userId);
} catch (Throwable $e) {
  http_response_code(500);
  echo "服务器错误";
  exit;
}

try {
  // 获取粉丝数（关注该用户的人数）
  $st = $pdo->prepare("SELECT COUNT(*) AS count FROM user_follow WHERE following_id = ?");
  $st->execute([$userId]);
  $followerCount = (int)$st->fetch()['count'];

  // 获取关注数（该用户关注的人数）
  $st = $pdo->prepare("SELECT COUNT(*) AS count FROM user_follow WHERE follower_id = ?");
  $st->execute([$userId]);
  $followingCount = (int)$st->fetch()['count'];

  // 检查当前用户是否已关注该用户
  $isFollowing = false;
  if ($currentUserId && $currentUserId !== $userId) {
    $st = $pdo->prepare("SELECT id FROM user_follow WHERE follower_id = ? AND following_id = ? LIMIT 1");
    $st->execute([$currentUserId, $userId]);
    $isFollowing = (bool)$st->fetch();
  }

  // 获取该用户的所有帖子（带封面图，按时间倒序）
  $st = $pdo->prepare("
    SELECT p.id, p.title, p.image, p.content, p.created_at, p.user_id, p.like_count, p.comment_count, p.favorite_count,
           (SELECT GROUP_CONCAT(t.name ORDER BY t.name ASC SEPARATOR ',') FROM post_tag_relation r JOIN post_tag t ON t.id = r.tag_id WHERE r.post_id = p.id) AS tags,
           u.username
    FROM post p
    JOIN user u ON u.id = p.user_id
    WHERE p.user_id = ? AND p.status = 1 AND (p.review_status IS NULL OR p.review_status = 0)
    ORDER BY p.created_at DESC
    LIMIT 100
  ");
  $st->execute([$userId]);
  $posts = $st->fetchAll();
} catch (Throwable $e) {
  $followerCount = 0;
  $followingCount = 0;
  $isFollowing = false;
  $posts = [];
}

// 获取收藏的帖子（自己查看 或 对方允许查看时）
$favoritePosts = [];
$canViewFavorites = $currentUserId && ($currentUserId === $userId || !empty($targetUser['privacy_show_favorites']));
if ($canViewFavorites) {
  try {
    $st = $pdo->prepare("
      SELECT p.id, p.title, p.image, p.content, p.created_at, u.id AS user_id, u.username, p.like_count, p.comment_count, p.favorite_count,
             (SELECT GROUP_CONCAT(t.name ORDER BY t.name ASC SEPARATOR ',') FROM post_tag_relation r JOIN post_tag t ON t.id = r.tag_id WHERE r.post_id = p.id) AS tags
      FROM post_favorite f
      JOIN post p ON p.id = f.post_id
      JOIN user u ON u.id = p.user_id
      WHERE f.user_id = ? AND p.status = 1
      ORDER BY f.id DESC
      LIMIT 20
    ");
    $st->execute([$userId]);
    $favoritePosts = $st->fetchAll();
  } catch (Throwable $e) {
    $favoritePosts = [];
  }
}

$pageTitle = htmlspecialchars($targetUser['username'] ?? '用户', ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $pageTitle ?> · Sown</title>
  <?php require __DIR__ . '/partials/head.php'; ?>
  <link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(__DIR__.'/assets/app.css') ?>">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
<?php require __DIR__ . '/partials/header.php'; ?>
<div class="container">

  <div class="section" style="margin-top:28px;">
    <!-- 用户信息卡片 -->
    <div class="user-profile-card">
      <div class="user-profile-header">
        <div class="user-avatar-large">
          <button type="button" id="avatarPreviewBtn">
            <?= avatar_html($targetUser, 72, 'avatar-img') ?>
          </button>
        </div>
        <div class="user-info">
          <div class="user-info-primary">
            <h2>
              <?= htmlspecialchars($targetUser['username'], ENT_QUOTES, 'UTF-8') ?>
            </h2>
            <?php if ($userTitle): ?>
            <span class="user-title-badge" title="<?= htmlspecialchars($userTitle['title_name'], ENT_QUOTES, 'UTF-8') ?>">
              <?php if (!empty($userTitle['icon'])): ?><?= htmlspecialchars($userTitle['icon'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
              <?= htmlspecialchars($userTitle['title_name'], ENT_QUOTES, 'UTF-8') ?>
            </span>
            <?php endif; ?>
            <span class="user-account"><?= htmlspecialchars($targetUser['account'], ENT_QUOTES, 'UTF-8') ?></span>
          </div>
          <div class="user-bio <?= empty($targetUser['bio']) ? 'is-empty' : '' ?>">
            <?= !empty($targetUser['bio']) ? nl2br(htmlspecialchars($targetUser['bio'], ENT_QUOTES, 'UTF-8')) : '' ?>
          </div>
          <div class="user-meta">
            <?php 
            // 获取 created_at 字段并格式化显示
            $createdAt = '';
            if (isset($targetUser['created_at'])) {
              $createdAt = is_string($targetUser['created_at']) ? trim($targetUser['created_at']) : '';
            }
            
            // 检查并格式化日期
            $displayDate = '注册时间未知';
            if ($createdAt && 
                $createdAt !== '' && 
                $createdAt !== '0000-00-00 00:00:00' && 
                $createdAt !== '0000-00-00') {
              $timestamp = strtotime($createdAt);
              if ($timestamp !== false && $timestamp > 0) {
                $displayDate = '注册于 ' . date('Y年m月d日', $timestamp);
              }
            }
            echo $displayDate;
            ?>
          </div>
        </div>
        <div class="user-stats">
          <div class="stat-item">
            <div class="stat-value"><?= $followerCount ?></div>
            <div class="stat-label">粉丝</div>
          </div>
          <div class="stat-item">
            <div class="stat-value"><?= $followingCount ?></div>
            <div class="stat-label">关注</div>
          </div>
          <?php if ($currentUserId && $currentUserId !== $userId): ?>
            <button class="btn <?= $isFollowing ? '' : 'primary' ?>" id="followBtn" data-user-id="<?= (int)$userId ?>" style="margin-left:8px;">
              <?= $isFollowing ? '已关注' : '关注' ?>
            </button>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- 标签切换 -->
    <div class="profile-tabs">
      <button type="button" class="profile-tab active" data-tab="posts">发布的内容</button>
      <?php if ($canViewFavorites): ?>
        <button type="button" class="profile-tab" data-tab="favorites">
          <?= $currentUserId === $userId ? '我的收藏' : 'TA的收藏' ?>
        </button>
      <?php endif; ?>
    </div>

    <!-- 发布的内容 -->
    <div id="profileTabPosts" class="profile-tab-content">
      <?php if (!$posts): ?>
        <div class="empty-state">
          还没有发布任何内容
        </div>
      <?php else: ?>
        <div class="post-grid">
          <?php foreach ($posts as $p):
            require __DIR__ . '/partials/post_grid_card.php';
          endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- 收藏内容 -->
    <?php if ($canViewFavorites): ?>
    <div id="profileTabFavorites" class="profile-tab-content" style="display:none;">
      <?php if (empty($favoritePosts)): ?>
        <div class="empty-state">
          <?= $currentUserId === $userId ? '还没有收藏任何内容' : 'TA还没有收藏任何内容' ?>
        </div>
      <?php else: ?>
        <div class="post-grid post-grid--compact">
          <?php foreach ($favoritePosts as $p):
            require __DIR__ . '/partials/post_grid_card.php';
          endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- 头像预览弹窗（仅个人主页使用） -->
  <div id="avatarModal" class="login-modal-backdrop" style="display:none;">
    <div class="login-modal" style="max-width:420px;">
      <button type="button" class="login-modal-close" aria-label="关闭头像预览" onclick="closeAvatarModal()">×</button>
      <div class="login-modal-inner" style="grid-template-columns:1fr;">
        <div class="login-modal-right" style="align-items:center; text-align:center;">
          <div style="margin-bottom:16px; font-weight:600;"><?= htmlspecialchars($targetUser['username'], ENT_QUOTES, 'UTF-8') ?></div>
          <div class="avatar-preview" style="margin-bottom:16px;">
            <?= avatar_html($targetUser, 160, 'avatar-img') ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php require __DIR__ . '/partials/footer.php'; ?>

</div>

<script src="/assets/app.js?v=<?= filemtime(__DIR__.'/assets/app.js') ?>"></script>
<script>
(function() {
  var btn = document.getElementById('avatarPreviewBtn');
  var modal = document.getElementById('avatarModal');
  if (!btn || !modal) return;

  window.openAvatarModal = function() {
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  };

  window.closeAvatarModal = function() {
    modal.style.display = 'none';
    document.body.style.overflow = '';
  };

  btn.addEventListener('click', function() {
    openAvatarModal();
  });

  modal.addEventListener('click', function(e) {
    if (e.target === modal) {
      closeAvatarModal();
    }
  });
})();
</script>
<script>
// 关注功能
(function() {
  const followBtn = document.getElementById('followBtn');
  if (!followBtn) return;
  
  const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  const userId = followBtn.getAttribute('data-user-id');
  
  followBtn.addEventListener('click', async function() {
    followBtn.disabled = true;
    try {
      const fd = new FormData();
      fd.append('user_id', userId);
      fd.append('csrf', csrf);

      const result = await safeFetchJSON('/follow_toggle.php', { method:'POST', body: fd });
      
      if (!result.ok) {
        if (result.code === 'LOGIN') return;
        window.showAppAlert(result.msg || '操作失败');
        return;
      }

      // 更新按钮状态
      const data = result.data || {};
      const isFollowing = data.following || false;
      followBtn.textContent = isFollowing ? '已关注' : '关注';
      followBtn.classList.toggle('primary', !isFollowing);
      
      // 刷新页面以更新粉丝数
      location.reload();
    } catch (e) {
      window.showAppAlert('网络或服务器错误');
    } finally {
      followBtn.disabled = false;
    }
  });
})();

// 个人主页标签切换
(function() {
  var tabs = document.querySelectorAll('.profile-tab');
  if (!tabs.length) return;

  tabs.forEach(function(tab) {
    tab.addEventListener('click', function() {
      var target = tab.getAttribute('data-tab');

      tabs.forEach(function(t) { t.classList.remove('active'); });
      tab.classList.add('active');

      var contents = document.querySelectorAll('.profile-tab-content');
      contents.forEach(function(c) { c.style.display = 'none'; });

      var targetContent = document.getElementById('profileTab' + target.charAt(0).toUpperCase() + target.slice(1));
      if (targetContent) {
        targetContent.style.display = '';
      }
    });
  });
})();
</script>

</body>
</html>
