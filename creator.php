<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/avatar.php';
require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/tag.php';

// 获取操作参数
$action = input_string('action'); // 'create', 'edit', 'delete'
$postId = input_int('id', 0, 0);

// 需要登录
if (!is_logged_in()) {
  safe_redirect(login_url('/creator.php'));
}

$user = current_user();
if (!$user) {
  safe_redirect('/login.php?next=' . urlencode('/creator.php'));
}

// 如果是创建新内容，跳转到编辑页面
if ($action === 'create') {
  error_log("creator.php: Redirecting to /post_note.php, action={$action}");
  header('Location: /post_note.php');
  exit;
}

// 如果是编辑现有内容，跳转到编辑页面
if ($action === 'edit' && $postId > 0) {
  header('Location: /post_note.php?id=' . $postId);
  exit;
}

// 处理删除请求
if ($action === 'delete' && $postId > 0) {
  // CSRF检查
  if (!csrf_check(input_string('csrf'))) {
    http_response_code(403);
    echo "CSRF验证失败";
    exit;
  }

  $pdo = db();
  try {
    // 检查帖子是否属于当前用户
    $st = $pdo->prepare("SELECT id FROM post WHERE id = ? AND user_id = ?");
    $st->execute([$postId, $user['id']]);
    $post = $st->fetch();

    if (!$post) {
      http_response_code(404);
      echo "内容不存在或无权操作";
      exit;
    }

    // 删除帖子（软删除：将status设为0）
    $st = $pdo->prepare("UPDATE post SET status = 0 WHERE id = ?");
    $st->execute([$postId]);

    header('Location: /creator.php');
    exit;
  } catch (Throwable $e) {
    http_response_code(500);
    echo "服务器错误";
    exit;
  }
}

// 处理发布草稿
if ($action === 'publish' && $postId > 0) {
  // CSRF检查
  if (!csrf_check(input_string('csrf'))) {
    http_response_code(403);
    echo "CSRF验证失败";
    exit;
  }

  $pdo = db();
  try {
    // 检查帖子是否属于当前用户且是草稿（status=2）
    $st = $pdo->prepare("SELECT id, status FROM post WHERE id = ? AND user_id = ?");
    $st->execute([$postId, $user['id']]);
    $post = $st->fetch();

    if (!$post) {
      http_response_code(404);
      echo "内容不存在或无权操作";
      exit;
    }

    if ($post['status'] != 2) {
      http_response_code(400);
      echo "只有草稿可以发布";
      exit;
    }

    // 发布草稿：将status设为1（已发布）；非管理员需重置审核状态为待审核
    $isAdmin = isset($user['role']) && $user['role'] === 'admin';
    $newReviewStatus = $isAdmin ? 0 : 1; // 管理员无需审核，其他用户待审核
    $st = $pdo->prepare("UPDATE post SET status = 1, review_status = ?, updated_at = NOW() WHERE id = ?");
    $st->execute([$newReviewStatus, $postId]);

    // 重定向回创作者平台
    header('Location: /creator.php?msg=published');
    exit;
  } catch (Throwable $e) {
    http_response_code(500);
    echo "服务器错误";
    exit;
  }
}

// 获取用户的所有内容（包括草稿）
$pdo = db();
$st = $pdo->prepare("
  SELECT id, title, image, created_at, status, review_status,
         like_count, comment_count, favorite_count,
         (status = 1) as is_published,
         (SELECT GROUP_CONCAT(t.name ORDER BY t.name ASC SEPARATOR ',') FROM post_tag_relation r JOIN post_tag t ON t.id = r.tag_id WHERE r.post_id = post.id) AS tags
  FROM post
  WHERE user_id = ? AND status IN (0, 1, 2)
  ORDER BY
    CASE
      WHEN status = 2 THEN 0  -- 草稿优先
      WHEN status = 1 THEN 1  -- 已发布
      WHEN status = 0 THEN 2  -- 已删除（软删除）
    END,
    created_at DESC
");
$st->execute([$user['id']]);
$posts = $st->fetchAll();

// 获取消息提示
$msg = input_string('msg');
$msgText = '';
if ($msg === 'published') {
  $msgText = '<div class="alert success">内容已发布</div>';
} elseif ($msg === 'saved' || $msg === 'draft_saved') {
  $msgText = '<div class="alert success">草稿已保存</div>';
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>创作平台 · Sown</title>
  <?php require __DIR__ . '/partials/head.php'; ?>
  <link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(__DIR__.'/assets/app.css') ?>">
  <style>
    .creator-container {
      padding: var(--sp-6) 0;
    }
    .creator-header {
      margin-bottom: var(--sp-8);
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: var(--sp-4);
      flex-wrap: wrap;
    }
    .creator-title {
      font-size: var(--fs-card-title);
      font-weight: var(--fw-semibold);
      color: var(--near-black);
      margin: 0;
    }
    .creator-subtitle {
      color: var(--stone);
      margin-top: var(--sp-2);
      font-size: var(--fs-body);
    }
    .action-btn {
      display: inline-flex;
      align-items: center;
      padding: var(--sp-2) var(--sp-4);
      border-radius: var(--r-block);
      font-size: var(--fs-small);
      font-weight: var(--fw-medium);
      text-decoration: none;
      border: 1px solid var(--light-gray);
      background: var(--pure-white);
      color: var(--near-black);
      cursor: pointer;
      transition: all 0.15s ease;
      line-height: 1.4;
    }
    .action-btn:hover {
      background: var(--snow);
      border-color: var(--stone);
    }
    .action-view {
      color: var(--primary);
      border-color: var(--primary);
    }
    .action-view:hover {
      background: var(--primary);
      color: var(--pure-white);
    }
    .action-edit {
      color: var(--near-black);
    }
    .action-publish {
      background: var(--primary);
      color: var(--pure-white);
      border-color: var(--primary);
    }
    .action-publish:hover {
      opacity: 0.9;
    }
    .action-delete {
      color: var(--red, #c0392b);
      border-color: var(--red, #c0392b);
    }
    .action-delete:hover {
      background: var(--red, #c0392b);
      color: var(--pure-white);
    }
  </style>
</head>
<body>
  <?php require __DIR__ . '/partials/header.php'; ?>
  <div class="container">

    <div class="creator-container">
      <div class="creator-header">
        <div>
          <h1 class="creator-title">创作平台</h1>
          <p class="creator-subtitle">管理您发布的内容和草稿</p>
        </div>
        <a href="/creator.php?action=create" class="btn primary" style="display:inline-flex;align-items:center;gap:8px;white-space:nowrap;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <line x1="12" y1="5" x2="12" y2="19"></line>
            <line x1="5" y1="12" x2="19" y2="12"></line>
          </svg>
          创作新内容
        </a>
      </div>

      <?= $msgText ?>

      <?php if (empty($posts)): ?>
        <div class="section" style="text-align:center;padding:60px 20px;">
          <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:16px;opacity:0.4;color:var(--stone);">
            <path d="M12 2L2 7l10 5 10-5-10-5z"></path>
            <path d="M2 17l10 5 10-5"></path>
            <path d="M2 12l10 5 10-5"></path>
          </svg>
          <h3 style="font-size:var(--fs-body-lg);color:var(--near-black);margin:0 0 8px;">还没有内容</h3>
          <p style="color:var(--stone);margin:0 0 24px;">创建您的第一个内容或草稿，与社区分享数学见解</p>
          <a href="/creator.php?action=create" class="btn primary">开始创作</a>
        </div>
      <?php else: ?>
        <div class="post-grid">
          <?php foreach ($posts as $post):
            // 解析图片
            $postImage = null;
            if (!empty($post['image'])) {
              $imageStr = trim($post['image']);
              $imageData = json_decode($imageStr, true);
              if (json_last_error() === JSON_ERROR_NONE && is_array($imageData) && !empty($imageData)) {
                $postImage = '/' . ltrim($imageData[0], '/');
              } elseif ($imageStr !== '' && $imageStr !== 'null' && strpos($imageStr, '/') !== false) {
                $postImage = '/' . ltrim($imageStr, '/');
              }
            }

            // 解析标签
            $cardTags = [];
            if (!empty($post['tags'])) {
              $cardTags = array_slice(explode(',', $post['tags']), 0, 5);
            }

            // 确定状态
            $statusClass = '';
            $statusText = '';
            $rs = isset($post['review_status']) ? (int)$post['review_status'] : 0;
            if ($post['status'] == 2) { // 草稿
              $statusClass = 'status-draft';
              $statusText = '草稿';
            } elseif ($post['status'] == 1 && $rs == 2) { // 已发布但被拒绝
              $statusClass = 'status-rejected';
              $statusText = '已拒绝';
            } elseif ($post['status'] == 1 && $rs == 1) { // 待审核
              $statusClass = 'status-pending';
              $statusText = '待审核';
            } elseif ($post['status'] == 1) { // 已发布且已通过
              $statusClass = 'status-published';
              $statusText = '已发布';
            } elseif ($post['status'] == 0) { // 已删除
              $statusClass = 'status-deleted';
              $statusText = '已删除';
            }
          ?>
            <div class="post-card">
              <?php if (!empty($postImage)): ?>
                <div class="post-card-image">
                  <img src="<?= htmlspecialchars($postImage, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
              <?php else: ?>
                <div class="post-card-image placeholder">
                  <div class="placeholder-text"><?= mb_strlen($post['title']) > 10 ? htmlspecialchars(mb_substr($post['title'], 0, 10), ENT_QUOTES, 'UTF-8') . '…' : htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
              <?php endif; ?>

              <div class="post-card-content">
                <div class="post-card-title"><?= htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="post-card-tags" role="group" aria-label="标签">
                    <?php if (!empty($cardTags)):
                      foreach ($cardTags as $tg): ?>
                        <span class="post-card-tag"><?= htmlspecialchars($tg, ENT_QUOTES, 'UTF-8') ?></span>
                      <?php endforeach; endif; ?>
                  </div>

                <div class="post-card-footer-block">
                  <div class="post-card-meta">
                  <span><?= date('Y-m-d', strtotime($post['created_at'])) ?></span>
                  <span class="post-card-status <?= $statusClass ?>"><?= $statusText ?></span>
                </div>

                <div class="post-card-actions">
                  <?php if ($post['status'] == 2): // 草稿 ?>
                    <a href="/post_preview.php?id=<?= (int)$post['id'] ?>" class="action-btn action-view">预览</a>
                    <a href="/creator.php?action=edit&id=<?= (int)$post['id'] ?>" class="action-btn action-edit">编辑</a>
                    <form method="post" action="/creator.php" style="display: inline;" data-confirm="确定发布此草稿吗？" data-confirm-title="发布草稿" data-confirm-danger="0">
                      <input type="hidden" name="action" value="publish">
                      <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
                      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                      <button type="submit" class="action-btn action-publish">发布</button>
                    </form>
                    <form method="post" action="/creator.php" style="display: inline;" data-confirm="确定删除此草稿吗？" data-confirm-title="删除草稿">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
                      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                      <button type="submit" class="action-btn action-delete">删除</button>
                    </form>
                  <?php elseif ($post['status'] == 1 && $rs == 2): // 已拒绝 ?>
                    <a href="/post.php?id=<?= (int)$post['id'] ?>" class="action-btn action-view">查看</a>
                    <span class="action-btn" style="opacity:0.5;cursor:default;color:var(--red,#c0392b);border-color:var(--red,#c0392b);">审核未通过</span>
                    <form method="post" action="/creator.php" style="display: inline;" data-confirm="确定删除此内容吗？删除后不可恢复。" data-confirm-title="删除内容">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
                      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                      <button type="submit" class="action-btn action-delete">删除</button>
                    </form>
                  <?php elseif ($post['status'] == 1 && $rs == 1): // 待审核 ?>
                    <a href="/post.php?id=<?= (int)$post['id'] ?>" class="action-btn action-view">查看</a>
                    <span class="action-btn" style="opacity:0.5;cursor:default;color:var(--stone);border-color:var(--stone);">审核中</span>
                    <form method="post" action="/creator.php" style="display: inline;" data-confirm="确定删除此内容吗？删除后不可恢复。" data-confirm-title="删除内容">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
                      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                      <button type="submit" class="action-btn action-delete">删除</button>
                    </form>
                  <?php elseif ($post['status'] == 1): // 已发布且已通过 ?>
                    <a href="/post.php?id=<?= (int)$post['id'] ?>" class="action-btn action-view">查看</a>
                    <a href="/creator.php?action=edit&id=<?= (int)$post['id'] ?>" class="action-btn action-edit">编辑</a>
                    <form method="post" action="/creator.php" style="display: inline;" data-confirm="确定删除此内容吗？删除后不可恢复。" data-confirm-title="删除内容">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
                      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                      <button type="submit" class="action-btn action-delete">删除</button>
                    </form>
                  <?php elseif ($post['status'] == 0): // 已删除 ?>
                    <span class="action-btn" style="opacity:0.5;cursor:default;">已删除</span>
                  <?php endif; ?>
                </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php require __DIR__ . '/partials/footer.php'; ?>
  </div>

  <script src="/assets/app.js?v=<?= filemtime(__DIR__.'/assets/app.js') ?>"></script>
</body>
</html>