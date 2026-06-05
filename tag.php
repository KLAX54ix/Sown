<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/tag.php';

$pdo = db();

// 获取标签slug
$tagSlug = input_string('slug');
if ($tagSlug === '') {
  http_response_code(404);
  echo "标签不存在";
  exit;
}

// 获取标签信息
try {
  $st = $pdo->prepare("SELECT id, name, slug, post_count FROM post_tag WHERE slug = ? LIMIT 1");
  $st->execute([$tagSlug]);
  $tag = $st->fetch();
  
  if (!$tag) {
    http_response_code(404);
    echo "标签不存在";
    exit;
  }
} catch (Throwable $e) {
  http_response_code(404);
  echo "标签不存在";
  exit;
}

// 分页参数
$page = input_int('page', 1, 1, 2000);
$perPage = 12;
$offset = ($page - 1) * $perPage;

// 获取该标签下的帖子总数
$st = $pdo->prepare("
  SELECT COUNT(DISTINCT p.id) AS c
  FROM post p
  JOIN post_tag_relation r ON r.post_id = p.id
  WHERE p.status = 1 AND (p.review_status IS NULL OR p.review_status = 0) AND r.tag_id = ?
");
$st->execute([$tag['id']]);
$total = (int)$st->fetch()['c'];
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;

// 获取该标签下的帖子（包含标签信息）
$stmt = $pdo->prepare("
  SELECT DISTINCT p.id, p.title, p.created_at, u.id AS user_id, u.username,
         p.like_count, p.comment_count, p.favorite_count,
         (SELECT GROUP_CONCAT(t.name ORDER BY t.name ASC SEPARATOR ',') FROM post_tag_relation r2 JOIN post_tag t ON t.id = r2.tag_id WHERE r2.post_id = p.id) AS tags
  FROM post p
  JOIN user u ON u.id = p.user_id
  JOIN post_tag_relation r ON r.post_id = p.id
  WHERE p.status = 1 AND (p.review_status IS NULL OR p.review_status = 0) AND r.tag_id = :tag_id
  ORDER BY p.created_at DESC
  LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':tag_id', $tag['id'], PDO::PARAM_INT);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$posts = $stmt->fetchAll();

// 页码导航
$window = 3;
$start = max(1, $page - $window);
$end   = min($totalPages, $page + $window);
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($tag['name'], ENT_QUOTES, 'UTF-8') ?> · 标签 · Sown</title>
  <?php require __DIR__ . '/partials/head.php'; ?>
  <link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(__DIR__.'/assets/app.css') ?>">
</head>
<body>
  <?php require __DIR__ . '/partials/header.php'; ?>
  <div class="container">

    <div class="section">
      <div class="section-header">
        <h2>标签：<?= htmlspecialchars($tag['name'], ENT_QUOTES, 'UTF-8') ?></h2>
        <p class="sub">共 <?= $total ?> 帖 · 第 <?= $page ?> / <?= $totalPages ?> 页</p>
      </div>

      <div style="margin-bottom:16px;">
        <a href="/forum.php" class="btn btn-small">返回社区</a>
      </div>

      <div class="post-list">
        <?php if (!$posts): ?>
          <div class="post-item empty">
            <div class="post-content">
              <div class="post-title">该标签下还没有内容</div>
            </div>
          </div>
        <?php else: ?>
          <?php foreach ($posts as $p):
            // 解析标签
            $cardTags = [];
            if (!empty($p['tags'])) {
              $cardTags = array_slice(explode(',', $p['tags']), 0, 5);
            }
          ?>
            <div class="post-item">
              <div class="post-main">
                <div class="post-title"><a href="/post.php?id=<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?></a></div>
                <div class="post-meta">
                  <span class="post-author"><a href="/user.php?id=<?= (int)$p['user_id'] ?>"><?= htmlspecialchars($p['username'], ENT_QUOTES, 'UTF-8') ?></a></span>
                  <span class="post-time"><?= time_ago($p['created_at']) ?></span>
                </div>
              </div>
              <div class="post-stats">
                <div class="stat">
                  <span class="stat-icon">♥</span>
                  <span class="stat-value"><?= (int)$p['like_count'] ?></span>
                </div>
                <div class="stat">
                  <span class="stat-icon">💬</span>
                  <span class="stat-value"><?= (int)$p['comment_count'] ?></span>
                </div>
                <div class="stat">
                  <span class="stat-icon">★</span>
                  <span class="stat-value"><?= (int)$p['favorite_count'] ?></span>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <!-- 分页 -->
      <?php if ($totalPages > 1): ?>
        <div class="pagination">
          <?php
            $prev = max(1, $page - 1);
            $next = min($totalPages, $page + 1);
          ?>
          <a class="btn" href="/tag.php?slug=<?= urlencode($tagSlug) ?>&page=<?= $prev ?>">← 上一页</a>
          
          <div class="page-numbers">
            <?php if ($start > 1): ?>
              <a class="btn" href="/tag.php?slug=<?= urlencode($tagSlug) ?>&page=1">1</a>
              <?php if ($start > 2): ?>
                <span class="ellipsis">…</span>
              <?php endif; ?>
            <?php endif; ?>

            <?php for ($i = $start; $i <= $end; $i++): ?>
              <?php if ($i === $page): ?>
                <span class="btn primary current"><?= $i ?></span>
              <?php else: ?>
                <a class="btn" href="/tag.php?slug=<?= urlencode($tagSlug) ?>&page=<?= $i ?>"><?= $i ?></a>
              <?php endif; ?>
            <?php endfor; ?>

            <?php if ($end < $totalPages): ?>
              <?php if ($end < $totalPages - 1): ?>
                <span class="ellipsis">…</span>
              <?php endif; ?>
              <a class="btn" href="/tag.php?slug=<?= urlencode($tagSlug) ?>&page=<?= $totalPages ?>"><?= $totalPages ?></a>
            <?php endif; ?>
          </div>
          
          <a class="btn" href="/tag.php?slug=<?= urlencode($tagSlug) ?>&page=<?= $next ?>">下一页 →</a>
        </div>
      <?php endif; ?>
    </div>

    <?php require __DIR__ . '/partials/footer.php'; ?>
  </div>

  <script src="/assets/app.js?v=<?= filemtime(__DIR__.'/assets/app.js') ?>"></script>
</body>
</html>

