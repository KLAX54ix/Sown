<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/tag.php';

$pdo = db();

// 标签筛选参数
$tagSlug = input_string('tag');
$tagId = 0;
$tagName = '';
if ($tagSlug !== '') {
  try {
    $st = $pdo->prepare("SELECT id, name FROM post_tag WHERE slug = ? LIMIT 1");
    $st->execute([$tagSlug]);
    $tag = $st->fetch();
    if ($tag) {
      $tagId = (int)$tag['id'];
      $tagName = $tag['name'];
    }
  } catch (Throwable $e) {
    // 表不存在时忽略
  }
}

// 排序参数（默认推荐/最新）。「动态」仅登录后生效；未登录仍在本页打开登录模态框，不跳转中转页。
$allowedSorts = ['latest', 'hot', 'dynamic'];
$sortInput = input_string('sort', 'latest');
if (!in_array($sortInput, $allowedSorts, true)) {
  $sortInput = 'latest';
}
$sortOptions = ['hot' => '热门', 'dynamic' => '动态'];
$canUseDynamic = is_logged_in() && current_user();
$isDynamic = ($sortInput === 'dynamic' && $canUseDynamic);
$sort = ($sortInput === 'dynamic' && !$canUseDynamic) ? 'latest' : $sortInput;
$tabSort = ($sortInput === 'dynamic' && !$canUseDynamic) ? 'latest' : $sortInput;
$openLoginForDynamic = ($sortInput === 'dynamic' && !$canUseDynamic);
$dynamicNextUrl = '/forum.php?sort=dynamic' . ($tagSlug !== '' ? '&tag=' . urlencode($tagSlug) : '');

$dynamicUserId = 0;
if ($isDynamic) {
  $dynamicUserId = (int)current_user()['id'];
}

// 分页参数（用于无限滚动）
$page = input_int('page', 1, 1, 2000);
$perPage = 12;
$offset = ($page - 1) * $perPage;
$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';

// 热门排序：只对最近30天的帖子排序
$dateLimit = '';
if ($sort === 'hot') {
  $dateLimit = "AND p.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
}

// 取总数
if ($isDynamic) {
  if ($tagId > 0) {
    $st = $pdo->prepare("
      SELECT COUNT(DISTINCT p.id) AS c
      FROM post p
      JOIN user_follow f ON f.following_id = p.user_id
      JOIN post_tag_relation r ON r.post_id = p.id AND r.tag_id = ?
      WHERE p.status = 1 AND (p.review_status IS NULL OR p.review_status = 0) AND f.follower_id = ?
    ");
    $st->execute([$tagId, $dynamicUserId]);
  } else {
    $st = $pdo->prepare("
      SELECT COUNT(DISTINCT p.id) AS c
      FROM post p
      JOIN user_follow f ON f.following_id = p.user_id
      WHERE p.status = 1 AND (p.review_status IS NULL OR p.review_status = 0) AND f.follower_id = ?
    ");
    $st->execute([$dynamicUserId]);
  }
  $total = (int)$st->fetch()['c'];
} else {
  if ($tagId > 0) {
    $st = $pdo->prepare("
      SELECT COUNT(DISTINCT p.id) AS c
      FROM post p
      JOIN post_tag_relation r ON r.post_id = p.id
      WHERE p.status = 1 AND (p.review_status IS NULL OR p.review_status = 0) AND r.tag_id = ? {$dateLimit}
    ");
    $st->execute([$tagId]);
    $total = (int)$st->fetch()['c'];
  } else {
    if ($dateLimit) {
      $st = $pdo->prepare("SELECT COUNT(*) AS c FROM post p WHERE p.status = 1 AND (p.review_status IS NULL OR p.review_status = 0) {$dateLimit}");
      $st->execute();
      $total = (int)$st->fetch()['c'];
    } else {
      $total = (int)$pdo->query("SELECT COUNT(*) AS c FROM post WHERE status = 1 AND (review_status IS NULL OR review_status = 0)")->fetch()['c'];
    }
  }
}
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

// 排序SQL
$orderBy = 'p.created_at DESC';
if ($sort === 'hot') {
  // 热门算法：(like_count * 2 + comment_count + favorite_count) / (天数 + 1)
  $orderBy = "(p.like_count * 2 + p.comment_count + p.favorite_count) / (DATEDIFF(NOW(), p.created_at) + 1) DESC, p.created_at DESC";
}

// 取当前页数据（包含标签信息）
if ($isDynamic) {
  if ($tagId > 0) {
    $stmt = $pdo->prepare("
      SELECT DISTINCT p.id, p.title, p.content, p.image, p.created_at, u.id AS user_id, u.username,
             p.like_count, p.comment_count, p.favorite_count,
             (SELECT GROUP_CONCAT(t.name ORDER BY t.name ASC SEPARATOR ',') FROM post_tag_relation r JOIN post_tag t ON t.id = r.tag_id WHERE r.post_id = p.id) AS tags,
             (SELECT GROUP_CONCAT(t.slug ORDER BY t.name ASC SEPARATOR ',') FROM post_tag_relation r2 JOIN post_tag t ON t.id = r2.tag_id WHERE r2.post_id = p.id) AS tag_slugs
      FROM post p
      JOIN user u ON u.id = p.user_id
      JOIN user_follow f ON f.following_id = p.user_id
      JOIN post_tag_relation r ON r.post_id = p.id AND r.tag_id = :tagId
      WHERE p.status = 1 AND (p.review_status IS NULL OR p.review_status = 0) AND f.follower_id = :uid
      ORDER BY p.created_at DESC
      LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':tagId', $tagId, PDO::PARAM_INT);
  } else {
    $stmt = $pdo->prepare("
      SELECT DISTINCT p.id, p.title, p.content, p.image, p.created_at, u.id AS user_id, u.username,
             p.like_count, p.comment_count, p.favorite_count,
             (SELECT GROUP_CONCAT(t.name ORDER BY t.name ASC SEPARATOR ',') FROM post_tag_relation r JOIN post_tag t ON t.id = r.tag_id WHERE r.post_id = p.id) AS tags,
             (SELECT GROUP_CONCAT(t.slug ORDER BY t.name ASC SEPARATOR ',') FROM post_tag_relation r2 JOIN post_tag t ON t.id = r2.tag_id WHERE r2.post_id = p.id) AS tag_slugs
      FROM post p
      JOIN user u ON u.id = p.user_id
      JOIN user_follow f ON f.following_id = p.user_id
      WHERE p.status = 1 AND (p.review_status IS NULL OR p.review_status = 0) AND f.follower_id = :uid
      ORDER BY p.created_at DESC
      LIMIT :limit OFFSET :offset
    ");
  }

  $stmt->bindValue(':uid', $dynamicUserId, PDO::PARAM_INT);
  $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
  $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
  $stmt->execute();
  $posts = $stmt->fetchAll();
} else {
  if ($tagId > 0) {
    $stmt = $pdo->prepare("
      SELECT DISTINCT p.id, p.title, p.content, p.image, p.created_at, u.id AS user_id, u.username,
             p.like_count, p.comment_count, p.favorite_count,
             (SELECT GROUP_CONCAT(t.name ORDER BY t.name ASC SEPARATOR ',') FROM post_tag_relation r JOIN post_tag t ON t.id = r.tag_id WHERE r.post_id = p.id) AS tags,
             (SELECT GROUP_CONCAT(t.slug ORDER BY t.name ASC SEPARATOR ',') FROM post_tag_relation r2 JOIN post_tag t ON t.id = r2.tag_id WHERE r2.post_id = p.id) AS tag_slugs
      FROM post p
      JOIN user u ON u.id = p.user_id
      JOIN post_tag_relation r ON r.post_id = p.id
      WHERE p.status = 1 AND (p.review_status IS NULL OR p.review_status = 0) AND r.tag_id = :tagId {$dateLimit}
      ORDER BY {$orderBy}
      LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':tagId', $tagId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $posts = $stmt->fetchAll();
  } else {
    $stmt = $pdo->prepare("
      SELECT p.id, p.title, p.content, p.image, p.created_at, u.id AS user_id, u.username,
             p.like_count, p.comment_count, p.favorite_count,
             (SELECT GROUP_CONCAT(t.name ORDER BY t.name ASC SEPARATOR ',') FROM post_tag_relation r JOIN post_tag t ON t.id = r.tag_id WHERE r.post_id = p.id) AS tags,
             (SELECT GROUP_CONCAT(t.slug ORDER BY t.name ASC SEPARATOR ',') FROM post_tag_relation r2 JOIN post_tag t ON t.id = r2.tag_id WHERE r2.post_id = p.id) AS tag_slugs
      FROM post p
      JOIN user u ON u.id = p.user_id
      WHERE p.status = 1 AND (p.review_status IS NULL OR p.review_status = 0) {$dateLimit}
      ORDER BY {$orderBy}
      LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $posts = $stmt->fetchAll();
  }
}

// 页码导航
$window = 3;
$start = max(1, $page - $window);
$end   = min($totalPages, $page + $window);

// AJAX请求只返回卡片（与动态统一 partial）
if ($isAjax) {
  foreach ($posts as $idx => $p) {
    require __DIR__ . '/partials/post_grid_card.php';
  }
  exit;
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php require __DIR__ . '/partials/head.php'; ?>
  <title>数问社区</title>
  <link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(__DIR__.'/assets/app.css') ?>">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="forum-page">
  <?php require __DIR__ . '/partials/header.php'; ?>
  <div class="container">

    <div class="section" style="position:relative;">
      <?php if (is_logged_in() && current_user()): ?>
        <!-- 发布按钮（加号） -->
        <div class="post-fab-top">
          <a href="/creator.php?action=create" class="post-fab-btn" title="发布内容">+</a>
        </div>
      <?php endif; ?>
      <div class="section-header">
        <h2 class="discover-title">
          <span class="discover-icon" aria-hidden="true">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="10" />
              <polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76" />
            </svg>
          </span>
          <span>发现<?= $tagName ? ' · ' . htmlspecialchars($tagName, ENT_QUOTES, 'UTF-8') : '' ?></span>
        </h2>
      </div>
      
      <!-- 排序选项 -->
      <div style="margin-bottom:16px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
        <a href="/forum.php<?= $tagSlug ? '?tag=' . urlencode($tagSlug) : '' ?>" 
           class="btn btn-small <?= $tabSort === 'latest' ? 'primary' : '' ?>">
          推荐
        </a>
        <?php foreach ($sortOptions as $key => $label): ?>
          <?php
            $sortParam = $tagSlug ? '&tag=' . urlencode($tagSlug) : '';
            $isActive = $tabSort === $key;
            $href = '/forum.php?sort=' . $key . $sortParam;
          ?>
          <?php if ($key === 'dynamic' && !$canUseDynamic): ?>
            <a href="#" class="btn btn-small forum-sort-guest-dynamic<?= $isActive ? ' primary' : '' ?>" data-next="<?= htmlspecialchars($dynamicNextUrl, ENT_QUOTES, 'UTF-8') ?>">
              <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
            </a>
          <?php else: ?>
            <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" 
               class="btn btn-small <?= $isActive ? 'primary' : '' ?>">
              <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
            </a>
          <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($tagSlug): ?>
          <a href="/forum.php?sort=<?= $sort ?>" class="btn btn-small">清除标签筛选</a>
        <?php endif; ?>
      </div>

      <?php if (input_string('msg') === 'deleted'): ?>
        <div class="alert success">内容已删除</div>
      <?php endif; ?>

      <div class="post-grid" id="postGrid">
        <?php if (!$posts): ?>
          <div class="post-card empty">
            <div class="post-content">
              <div class="post-title">还没有内容</div>
              <div class="post-meta">点击右上角「发布」创建第一条。</div>
            </div>
          </div>
        <?php else: ?>
          <?php foreach ($posts as $idx => $p) {
            require __DIR__ . '/partials/post_grid_card.php';
          } ?>
        <?php endif; ?>
      </div>

      <!-- 无限滚动加载提示 -->
      <div id="loadingIndicator" style="display:none; text-align:center; padding:20px; color:#999;">
        加载中...
      </div>
      <div id="noMoreIndicator" style="display:none; text-align:center; padding:20px; color:#999;">
        没有更多了
      </div>
      
    </div>

    <?php require __DIR__ . '/partials/footer.php'; ?>
  </div>

  <script src="/assets/app.js?v=<?= filemtime(__DIR__.'/assets/app.js') ?>"></script>
  <script>
  // 无限滚动
  (function() {
    var currentPage = <?= $page ?>;
    var totalPages = <?= $totalPages ?>;
    var isLoading = false;
    var hasMore = currentPage < totalPages;
    var postGrid = document.querySelector('.post-grid');
    var loadingIndicator = document.getElementById('loadingIndicator');
    var noMoreIndicator = document.getElementById('noMoreIndicator');
    
    if (!postGrid || !hasMore) {
      if (noMoreIndicator && !hasMore && currentPage > 1) {
        noMoreIndicator.style.display = 'block';
      }
      return;
    }
    
    var tagParam = '<?= $tagSlug ? "&tag=" . urlencode($tagSlug) : "" ?>';
    var sortParam = '<?= $sort !== "latest" ? "&sort=" . urlencode($sort) : "" ?>';
    
    function loadMore() {
      if (isLoading || !hasMore) return;
      
      isLoading = true;
      if (loadingIndicator) loadingIndicator.style.display = 'block';
      
      var nextPage = currentPage + 1;
      var url = '/forum.php?page=' + nextPage + tagParam + sortParam + '&ajax=1';
      
      fetch(url)
        .then(function(response) {
          return response.text();
        })
        .then(function(html) {
          // 解析HTML，提取post-card元素
          var tempDiv = document.createElement('div');
          tempDiv.innerHTML = html;
          var newCards = tempDiv.querySelectorAll('.post-card');
          
          if (newCards.length > 0) {
            newCards.forEach(function(card) {
              postGrid.appendChild(card);
            });
            currentPage = nextPage;
            hasMore = currentPage < totalPages;
          } else {
            hasMore = false;
          }
          
          if (!hasMore && noMoreIndicator) {
            noMoreIndicator.style.display = 'block';
          }
        })
        .catch(function(error) {
          console.error('Load more error:', error);
        })
        .finally(function() {
          isLoading = false;
          if (loadingIndicator) loadingIndicator.style.display = 'none';
        });
    }
    
    // 滚动监听
    var scrollTimeout;
    window.addEventListener('scroll', function() {
      clearTimeout(scrollTimeout);
      scrollTimeout = setTimeout(function() {
        var scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        var windowHeight = window.innerHeight;
        var documentHeight = document.documentElement.scrollHeight;
        
        // 距离底部200px时加载
        if (scrollTop + windowHeight >= documentHeight - 200) {
          loadMore();
        }
      }, 100);
    });
  })();

  (function() {
    function bindGuestDynamic() {
      document.querySelectorAll('a.forum-sort-guest-dynamic').forEach(function (el) {
        el.addEventListener('click', function (e) {
          e.preventDefault();
          var next = el.getAttribute('data-next') || '/forum.php?sort=dynamic';
          if (window.requireLogin) {
            window.requireLogin(next);
          }
        });
      });
    }
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', bindGuestDynamic);
    } else {
      bindGuestDynamic();
    }

    <?php if ($openLoginForDynamic): ?>
    window.addEventListener('DOMContentLoaded', function () {
      try {
        var u = new URL(window.location.href);
        if (u.searchParams.get('sort') === 'dynamic') {
          u.searchParams.delete('sort');
          var q = u.searchParams.toString();
          history.replaceState({}, '', u.pathname + (q ? '?' + q : '') + u.hash);
        }
      } catch (err) {}
      var next = <?= json_encode($dynamicNextUrl, JSON_UNESCAPED_UNICODE) ?>;
      if (window.requireLogin) {
        window.requireLogin(next);
      }
    });
    <?php endif; ?>
  })();
  </script>
</body>
</html>
