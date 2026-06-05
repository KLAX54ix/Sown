<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/avatar.php';

$pdo = db();

// 获取搜索关键词
$q = input_string('q');
$q = trim($q);

// 限制搜索长度
if (mb_strlen($q) > 100) {
  $q = mb_substr($q, 0, 100);
}

$posts = [];
$users = [];
$postCount = 0;
$userCount = 0;

if ($q !== '') {
  try {
    // 转义LIKE特殊字符（% 和 _）
    $escapedQ = str_replace(['%', '_', '\\'], ['\%', '\_', '\\\\'], $q);
    $searchTerm = '%' . $escapedQ . '%';

    // 1. 搜索帖子标题
    $st = $pdo->prepare("
      SELECT p.id, p.title, p.image, p.created_at, u.id AS user_id, u.username,
             p.like_count, p.comment_count, p.favorite_count,
             (SELECT GROUP_CONCAT(t.name ORDER BY t.name ASC SEPARATOR ',') FROM post_tag_relation r JOIN post_tag t ON t.id = r.tag_id WHERE r.post_id = p.id) AS tags
      FROM post p
      JOIN user u ON u.id = p.user_id
      WHERE p.status = 1 AND (p.review_status IS NULL OR p.review_status = 0) AND p.title LIKE ?
      ORDER BY p.created_at DESC
      LIMIT 50
    ");
    $st->execute([$searchTerm]);
    $posts = $st->fetchAll();
    $postCount = count($posts);

    // 2. 搜索用户名
    $st = $pdo->prepare("
      SELECT u.id, u.username,
             (SELECT COUNT(*) FROM post WHERE user_id = u.id AND status = 1 AND (review_status IS NULL OR review_status = 0)) AS post_count
      FROM user u
      WHERE u.status = 1 AND u.username LIKE ?
      ORDER BY u.username
      LIMIT 50
    ");
    $st->execute([$searchTerm]);
    $users = $st->fetchAll();
    $userCount = count($users);
  } catch (Throwable $e) {
    error_log('Search error: ' . $e->getMessage() . ' | Query: ' . $q);
    $posts = [];
    $users = [];
    $postCount = 0;
    $userCount = 0;
  }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>搜索<?= $q ? ' · ' . htmlspecialchars($q, ENT_QUOTES, 'UTF-8') : '' ?> · Sown</title>
  <?php require __DIR__ . '/partials/head.php'; ?>
  <link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(__DIR__.'/assets/app.css') ?>">
</head>
<body>
  <?php require __DIR__ . '/partials/header.php'; ?>

  <div class="container">

    <div class="section">
      <div class="section-header">
        <h2>搜索结果</h2>
        <?php if ($q): ?>
          <p class="sub">关键词：<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?> · 内容 <?= $postCount ?> 条 · 用户 <?= $userCount ?> 条</p>
        <?php else: ?>
          <p class="sub">请在导航栏搜索框输入关键词，搜索内容标题和用户名</p>
        <?php endif; ?>
      </div>

      <?php if ($q === ''): ?>
        <div class="post-item empty">
          <div class="post-content">
            <div class="post-title">请输入搜索关键词</div>
            <div class="post-meta">在导航栏的搜索框中输入关键词，搜索内容标题和用户名。</div>
          </div>
        </div>
      <?php elseif (empty($posts) && empty($users)): ?>
        <div class="post-item empty">
          <div class="post-content">
            <div class="post-title">没有找到相关内容</div>
            <div class="post-meta">尝试使用其他关键词搜索。</div>
          </div>
        </div>
      <?php else: ?>
        <?php if (!empty($posts)): ?>
        <h3 class="search-section-title">内容</h3>
        <div class="post-grid" id="postGrid">
          <?php foreach ($posts as $p):
            // 解析图片（支持旧格式单图片和新格式JSON多图片）
            $postImage = null;
            if (!empty($p['image'])) {
              $imageStr = trim($p['image']);
              $imageData = json_decode($imageStr, true);
              if (json_last_error() === JSON_ERROR_NONE && is_array($imageData) && !empty($imageData)) {
                // 新格式：JSON数组，取第一张图片
                $postImage = '/' . ltrim($imageData[0], '/');
              } else {
                // 旧格式：单个图片路径
                if ($imageStr !== '' && $imageStr !== 'null' && strpos($imageStr, '/') !== false) {
                  $postImage = '/' . ltrim($imageStr, '/');
                }
              }
            }
            // 解析标签
            $cardTags = [];
            if (!empty($p['tags'])) {
              $cardTags = array_slice(explode(',', $p['tags']), 0, 5);
            }

            // 高亮搜索关键词（先转义再高亮，避免截断破坏 HTML）
            $titlePlain = htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8');
            $usernamePlain = htmlspecialchars($p['username'], ENT_QUOTES, 'UTF-8');
            $title = $q !== '' ? preg_replace('/(' . preg_quote(htmlspecialchars($q, ENT_QUOTES, 'UTF-8'), '/') . ')/iu', '<mark class="search-highlight">$1</mark>', $titlePlain) : $titlePlain;
            $username = $q !== '' ? preg_replace('/(' . preg_quote(htmlspecialchars($q, ENT_QUOTES, 'UTF-8'), '/') . ')/iu', '<mark class="search-highlight">$1</mark>', $usernamePlain) : $usernamePlain;
          ?>
            <a href="/post.php?id=<?= (int)$p['id'] ?>" class="post-card">
              <?php if (!empty($postImage)): ?>
                <div class="post-card-image">
                  <img src="<?= htmlspecialchars($postImage, ENT_QUOTES, 'UTF-8') ?>" alt="<?= $titlePlain ?>" loading="lazy">
                </div>
              <?php else: ?>
                <div class="post-card-image placeholder">
                  <div class="placeholder-text"><?= mb_strlen($p['title']) > 10 ? mb_substr($titlePlain, 0, 10) . '...' : $titlePlain ?></div>
                </div>
              <?php endif; ?>
              <div class="post-card-content">
                <div class="post-card-title"><?= $title ?></div>
                <div class="post-card-tags" role="group" aria-label="标签">
                    <?php if (!empty($cardTags)):
                      foreach (array_slice($cardTags, 0, 3) as $tg): ?>
                        <span class="post-card-tag"><?= htmlspecialchars($tg, ENT_QUOTES, 'UTF-8') ?></span>
                      <?php endforeach; endif; ?>
                  </div>
                <div class="post-card-footer">
                  <div class="post-card-author">
                    <span class="post-card-avatar"><?php
                    $authorUser = ['id' => $p['user_id'], 'username' => $p['username']];
                    echo avatar_html($authorUser, 24);
                    ?></span>
                    <span class="post-card-username"><?= $username ?></span>
                  </div>
                  <div class="post-card-likes">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
                    </svg>
                    <span><?= (int)$p['like_count'] ?></span>
                  </div>
                </div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($users)): ?>
        <h3 class="search-section-title">用户</h3>
        <div class="search-user-list">
          <?php foreach ($users as $u):
            $usernameDisplay = htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8');
            $postCountDisplay = (int)($u['post_count'] ?? 0);
          ?>
            <a href="/user.php?id=<?= (int)$u['id'] ?>" class="search-user-item">
              <span class="search-user-avatar"><?php
              $authorUser = ['id' => $u['id'], 'username' => $u['username']];
              echo avatar_html($authorUser, 40);
              ?></span>
              <div class="search-user-info">
                <span class="search-user-name"><?= $usernameDisplay ?></span>
                <span class="search-user-meta"><?= $postCountDisplay ?> 篇内容</span>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <?php require __DIR__ . '/partials/footer.php'; ?>
  </div>

  <script src="/assets/app.js?v=<?= filemtime(__DIR__.'/assets/app.js') ?>"></script>
</body>
</html>

