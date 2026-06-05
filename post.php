<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/avatar.php';
require_once __DIR__ . '/app/tag.php';
require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/moderation.php';

$pdo = db();

// 参数校验
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  http_response_code(404);
  echo "Not Found";
  exit;
}

$previewMode = isset($_GET['preview']) && $_GET['preview'] === '1';
$currentUserId = current_user_id();

if ($previewMode) {
  // 草稿预览：仅作者本人，样式同 post.php，互动数据一律为空
  if (!$currentUserId) {
    safe_redirect(login_url('/post_preview.php?id=' . $id));
  }
  $stmt = $pdo->prepare("
    SELECT p.id, p.title, p.content, p.image, p.created_at,
           p.like_count, p.comment_count, p.favorite_count,
           u.id AS user_id, u.username
    FROM post p
    JOIN user u ON u.id = p.user_id
    WHERE p.id = :id AND p.status = 2 AND p.user_id = :uid
    LIMIT 1
  ");
  $stmt->execute([':id' => $id, ':uid' => $currentUserId]);
  $post = $stmt->fetch();
  if (!$post) {
    http_response_code(404);
    echo "Not Found";
    exit;
  }
  $post['like_count'] = 0;
  $post['comment_count'] = 0;
  $post['favorite_count'] = 0;
  $isLiked = false;
  $isFav = false;
  $comments = [];
  $replies = [];
  $commentLikedIds = [];
} else {
  // 已发布帖子：作者可查看（不论审核状态），其他人不可见已驳回的
  $stmt = $pdo->prepare("
    SELECT p.id, p.title, p.content, p.image, p.created_at,
           p.like_count, p.comment_count, p.favorite_count,
           p.review_status,
           u.id AS user_id, u.username
    FROM post p
    JOIN user u ON u.id = p.user_id
    WHERE p.id = :id AND p.status = 1
      AND (p.user_id = :uid OR p.review_status IS NULL OR p.review_status != 2)
    LIMIT 1
  ");
  $stmt->execute([':id' => $id, ':uid' => $currentUserId ?? 0]);
  $post = $stmt->fetch();

  if (!$post) {
    http_response_code(404);
    echo "Not Found";
    exit;
  }

  // 检查当前用户是否已点赞/收藏
  $isLiked = false;
  $isFav = false;
  if ($currentUserId) {
    try {
      $st = $pdo->prepare("SELECT id FROM post_like WHERE post_id=? AND user_id=? LIMIT 1");
      $st->execute([$id, $currentUserId]);
      $isLiked = (bool)$st->fetch();

      $st = $pdo->prepare("SELECT id FROM post_favorite WHERE post_id=? AND user_id=? LIMIT 1");
      $st->execute([$id, $currentUserId]);
      $isFav = (bool)$st->fetch();
    } catch (Throwable $e) {
      // 表不存在时忽略
    }
  }

// 评论列表（支持回复）
$comments = [];
$replies = [];
try {
  // 获取主评论（parent_id 为 NULL 或 0）
  $cstmt = $pdo->prepare("
    SELECT c.id, c.content, c.created_at, c.user_id, c.parent_id, u.username,
           COALESCE(c.like_count, 0) AS like_count
    FROM comment c
    JOIN user u ON u.id = c.user_id
    WHERE c.post_id = :pid AND c.status = 1 AND (c.parent_id IS NULL OR c.parent_id = 0)
    ORDER BY c.created_at DESC
    LIMIT 200
  ");
  $cstmt->execute([':pid' => $id]);
  $comments = $cstmt->fetchAll();
  
  // 为评论添加头像信息
  foreach ($comments as &$comment) {
    $comment['avatar_url'] = get_avatar_url((int)$comment['user_id']);
    $comment['has_avatar'] = has_avatar((int)$comment['user_id']);
  }
  unset($comment);
  
  // 获取所有回复（包含被回复的用户信息）
  $rstmt = $pdo->prepare("
    SELECT c.id, c.content, c.created_at, c.user_id, c.parent_id, 
           u.username, pc.user_id AS parent_user_id, pu.username AS parent_username,
           COALESCE(c.like_count, 0) AS like_count
    FROM comment c
    JOIN user u ON u.id = c.user_id
    LEFT JOIN comment pc ON pc.id = c.parent_id
    LEFT JOIN user pu ON pu.id = pc.user_id
    WHERE c.post_id = :pid AND c.status = 1 AND c.parent_id IS NOT NULL AND c.parent_id > 0
    ORDER BY c.created_at DESC
    LIMIT 500
  ");
  $rstmt->execute([':pid' => $id]);
  $allReplies = $rstmt->fetchAll();
  
  // 为回复找到根父评论ID（扁平化结构），并添加头像信息
  foreach ($allReplies as &$reply) {
    // 添加头像信息
    $reply['avatar_url'] = get_avatar_url((int)$reply['user_id']);
    $reply['has_avatar'] = has_avatar((int)$reply['user_id']);
    
    // 递归查找根父评论ID（parent_id为NULL或0的评论）
    $rootParentId = (int)$reply['parent_id'];
    $visited = []; // 防止循环引用
    while ($rootParentId > 0 && !isset($visited[$rootParentId])) {
      $visited[$rootParentId] = true;
      $pstmt = $pdo->prepare("SELECT parent_id FROM comment WHERE id = ? AND status = 1 LIMIT 1");
      $pstmt->execute([$rootParentId]);
      $parent = $pstmt->fetch();
      if ($parent && $parent['parent_id'] !== null && (int)$parent['parent_id'] > 0) {
        $rootParentId = (int)$parent['parent_id'];
      } else {
        break;
      }
    }
    $reply['root_parent_id'] = $rootParentId;
  }
  unset($reply);
  
  // 按根父评论ID组织回复（扁平化结构）
  foreach ($allReplies as $reply) {
    $rootParentId = (int)$reply['root_parent_id'];
    if (!isset($replies[$rootParentId])) {
      $replies[$rootParentId] = [];
    }
    $replies[$rootParentId][] = $reply;
  }
  
  // 获取当前用户对哪些评论已点赞
  $commentLikedIds = [];
  if ($currentUserId) {
    $allCommentIds = array_map(fn($c) => (int)$c['id'], $comments);
    foreach ($replies as $list) {
      foreach ($list as $r) $allCommentIds[] = (int)$r['id'];
    }
    $allCommentIds = array_unique($allCommentIds);
    if (!empty($allCommentIds)) {
      try {
        $ph = implode(',', array_fill(0, count($allCommentIds), '?'));
        $st = $pdo->prepare("SELECT comment_id FROM comment_like WHERE user_id=? AND comment_id IN ($ph)");
        $st->execute(array_merge([$currentUserId], $allCommentIds));
        while ($row = $st->fetch()) $commentLikedIds[(int)$row['comment_id']] = true;
      } catch (Throwable $e) {}
    }
  }

  // 获取评论用户的称号
  $userIds = [];
  foreach ($comments as $c) {
    $userIds[] = (int)$c['user_id'];
  }
  foreach ($replies as $list) {
    foreach ($list as $r) {
      $userIds[] = (int)$r['user_id'];
    }
  }
  $userIds = array_values(array_unique($userIds));
  $userTitles = get_users_titles($userIds);
} catch (Throwable $e) {
  $comments = [];
  $replies = [];
  $commentLikedIds = [];
}
if (!isset($commentLikedIds)) {
  $commentLikedIds = [];
}
} // end else 已发布帖子（含评论加载）

// 获取帖子标签、作者头像（预览与正式共用）
$postTags = get_post_tags($id);
$authorUserId = (int)$post['user_id'];
$authorAvatarUrl = get_avatar_url($authorUserId);
$authorHasAvatar = has_avatar($authorUserId);
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php if ($previewMode): ?>
  <meta name="robots" content="noindex,nofollow">
  <?php endif; ?>
  <?php require __DIR__ . '/partials/head.php'; ?>
  <title><?= htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8') ?> · <?= $previewMode ? '草稿预览 · Sown' : 'Sown' ?></title>
  <link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(__DIR__.'/assets/app.css') ?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="post-page<?= $previewMode ? ' post-page--preview' : '' ?>">
  <?php require __DIR__ . '/partials/header.php'; ?>
  <div class="container post-page-container">
    <main class="post-reading">

    <article class="article article-reading">
      <?php if ($previewMode): ?>
      <div class="post-preview-banner" role="status">草稿预览 · 发布前仅自己可见，点赞/收藏/评论均为空</div>
      <?php elseif (isset($post['review_status']) && (int)$post['review_status'] === 1): ?>
      <div class="post-pending-banner" role="status">内容审核中</div>
      <?php endif; ?>
      <div class="post-header">
        <h1><?= htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8') ?></h1>
      </div>

      <div class="metaRow metaRow-reading">
        <a href="/user.php?id=<?= (int)$post['user_id'] ?>" class="meta-author-link"><?= htmlspecialchars($post['username'], ENT_QUOTES, 'UTF-8') ?></a>
        <span class="meta-sep">·</span>
        <time class="meta-date" datetime="<?= htmlspecialchars($post['created_at'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($post['created_at'])), ENT_QUOTES, 'UTF-8') ?></time>
      </div>

      <?php if (!empty($postTags)): ?>
      <div class="post-reading-tags">
        <?php foreach ($postTags as $t): ?>
          <span class="post-reading-tag"><?= htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') ?></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>


      <?php
      // 旧数据：图片仅在 post.image 字段、正文里还没有 <img> 时，在正文前纵向展示（非轮播）
      $images = [];
      if (!empty($post['image'])) {
        $imageStr = trim($post['image']);
        $imageData = json_decode($imageStr, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($imageData) && !empty($imageData)) {
          $images = array_values(array_filter(array_map(function($path) {
            if (empty($path) || !is_string($path)) {
              return null;
            }
            return '/' . ltrim($path, '/');
          }, $imageData)));
        } elseif ($imageStr !== '' && $imageStr !== 'null' && strpos($imageStr, '/') !== false) {
          $images = ['/' . ltrim($imageStr, '/')];
        }
      }
      $hasImgInContent = (stripos((string)$post['content'], '<img') !== false);
      if (!empty($images) && !$hasImgInContent):
      ?>
        <div class="post-reading-legacy-images" aria-label="帖子配图">
          <?php foreach ($images as $imagePath): ?>
            <?php if (!is_string($imagePath) || $imagePath === '') {
              continue;
            } ?>
            <figure class="post-reading-legacy-figure">
              <img src="<?= htmlspecialchars($imagePath, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8') ?>" class="post-reading-legacy-img js-zoomable" loading="lazy">
            </figure>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="content quill-content" id="post-content"><?= $post['content'] ?></div>
    </article>

    <!-- 互动条：固定在视口底部，随滚动保持可见 -->
    <div class="post-actions post-reading-actions" role="toolbar" aria-label="帖子操作">
      <div class="post-reading-actions-inner">
        <div class="post-reading-actions-left">
          <a href="/user.php?id=<?= $authorUserId ?>" class="post-reading-author">
            <?php if ($authorHasAvatar && $authorAvatarUrl): ?>
              <img src="<?= htmlspecialchars($authorAvatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" class="post-reading-author-avatar" width="40" height="40" loading="lazy">
            <?php else: ?>
              <span class="post-reading-author-avatar post-reading-author-avatar--fallback" aria-hidden="true">
                <svg viewBox="0 0 40 40" fill="#778B3E" style="width:60%;height:60%;display:block"><circle cx="20" cy="12" r="7"/><path d="M8 34 C8 25,14 21,20 21 C26 21,32 25,32 34 Z"/></svg>
              </span>
            <?php endif; ?>
            <span class="post-reading-author-name"><?= htmlspecialchars($post['username'], ENT_QUOTES, 'UTF-8') ?></span>
          </a>
        </div>
        <div class="post-reading-actions-right">
          <button type="button" class="action-btn action-btn-line like-btn <?= $isLiked ? 'liked' : '' ?>" id="likeBtn" data-post-id="<?= (int)$post['id'] ?>" data-count="<?= (int)$post['like_count'] ?>" aria-label="点赞"<?= $previewMode ? ' disabled aria-disabled="true"' : '' ?>>
            <svg class="action-icon action-icon-stroke" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>
            <span class="action-count" id="likeCount"><?= (int)$post['like_count'] ?></span>
          </button>
          <button type="button" class="action-btn action-btn-line fav-btn <?= $isFav ? 'favorited' : '' ?>" id="favBtn" data-post-id="<?= (int)$post['id'] ?>" data-count="<?= (int)$post['favorite_count'] ?>" aria-label="收藏"<?= $previewMode ? ' disabled aria-disabled="true"' : '' ?>>
            <svg class="action-icon action-icon-stroke" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            <span class="action-count" id="favCount"><?= (int)$post['favorite_count'] ?></span>
          </button>
          <button type="button" class="action-btn action-btn-line" id="commentBtn" aria-label="评论"<?= $previewMode ? ' disabled aria-disabled="true"' : '' ?>>
            <svg class="action-icon action-icon-stroke" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <span class="action-count"><?= (int)$post['comment_count'] ?></span>
          </button>
          <button type="button" class="action-btn action-btn-line" id="sharePostBtn" aria-label="复制链接"<?= $previewMode ? ' disabled aria-disabled="true"' : '' ?>>
            <svg class="action-icon action-icon-stroke" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
          </button>
          <a class="post-reading-back" href="<?= $previewMode ? '/creator.php' : '/forum.php' ?>"><?= $previewMode ? '返回创作者平台' : '返回社区' ?></a>
        </div>
      </div>
    </div>

    <!-- 评论区域 -->
    <div class="commentBox commentBox-reading" id="comments">
      <div class="commentHead">
        <b>留言</b>
        <span><?= count($comments) ?> 条</span>
      </div>

      <!-- 评论表单（预览模式不展示） -->
      <?php if (!$previewMode): ?>
      <div class="citem">
        <form method="post" action="/comment_create.php" id="commentForm" class="comment-form comment-form-post-bar">
          <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <div class="comment-input-bar comment-main-bar">
            <textarea name="content" rows="1" placeholder="写评论…" required maxlength="2000" class="comment-textarea comment-textarea-post"></textarea>
            <button type="button" class="comment-emoji-btn" title="插入表情" aria-label="插入表情">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
            </button>
            <button class="btn primary btn-small comment-submit-btn" type="submit">发送</button>
          </div>
        </form>

        <!-- 表情选择器 -->
        <div id="emojiPicker" class="emoji-picker" style="display:none;">
          <div class="emoji-picker-header">
            <span class="emoji-picker-title">选择表情</span>
            <button type="button" class="emoji-picker-close" id="emojiPickerClose">&times;</button>
          </div>
          <div class="emoji-picker-body" id="emojiPickerBody"></div>
        </div>

        <?php if (isset($_GET['err']) && $_GET['err'] === 'content'): ?>
          <div class="alert error" style="margin-top:10px;">
            内容不能为空，且不能超过 2000 字。
          </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- 评论列表 -->
      <?php if (!$comments): ?>
        <div class="citem">
          <div class="cmeta">暂无评论</div>
        </div>
      <?php else: ?>
          <?php 
          // 渲染评论的函数（小红书风格）
          $likeIconSvg = '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>';
          function render_comment($c, $currentUserId, $isReply = false, $parentUsername = null, $commentLikedIds = [], $replyCount = 0, $postId = 0) {
            global $userTitles;
            $commentId = (int)$c['id'];
            $userId = (int)$c['user_id'];
            $isOwner = $currentUserId && $userId === $currentUserId;
            $likeCount = isset($c['like_count']) ? (int)$c['like_count'] : 0;
            $isLiked = isset($commentLikedIds[$commentId]) && $commentLikedIds[$commentId];
            $avatarClass = $isReply ? 'comment-avatar comment-avatar-sm' : 'comment-avatar';
            
            $avatarUrl = isset($c['avatar_url']) ? $c['avatar_url'] : '';
            $hasAvatar = isset($c['has_avatar']) ? $c['has_avatar'] : false;
            $userLink = '/user.php?id=' . $userId;

            $avatarSvg = '<svg viewBox="0 0 40 40" fill="#778B3E" style="width:60%;height:60%;display:block"><circle cx="20" cy="12" r="7"/><path d="M8 34 C8 25,14 21,20 21 C26 21,32 25,32 34 Z"/></svg>';

            if ($hasAvatar && $avatarUrl) {
              $avatarHtml = '<a href="' . htmlspecialchars($userLink, ENT_QUOTES, 'UTF-8') . '" class="comment-avatar-wrap"><img src="' . htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8') . '" class="' . $avatarClass . '" onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'flex\';"><div class="' . $avatarClass . ' comment-avatar-fallback" style="display:none;">' . $avatarSvg . '</div></a>';
            } else {
              $avatarHtml = '<a href="' . htmlspecialchars($userLink, ENT_QUOTES, 'UTF-8') . '" class="comment-avatar-wrap"><div class="' . $avatarClass . '">' . $avatarSvg . '</div></a>';
            }
            ?>
            <div class="citem <?= $isReply ? 'comment-reply' : '' ?>" data-comment-id="<?= $commentId ?>">
              <div class="comment-row">
                <div class="comment-main">
                  <div class="comment-body">
                    <div class="cmeta <?= $isReply ? 'cmeta-sm' : '' ?>">
                      <?= $avatarHtml ?>
                      <a href="/user.php?id=<?= $userId ?>" class="comment-username"><?= htmlspecialchars($c['username'], ENT_QUOTES, 'UTF-8') ?></a>
                      <?php
                        $title = $userTitles[$userId] ?? null;
                        if ($title):
                      ?>
                        <span class="comment-user-title" title="<?= htmlspecialchars($title['title_name'], ENT_QUOTES, 'UTF-8') ?>">
                          <?php if (!empty($title['icon'])): ?><?= htmlspecialchars($title['icon'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
                          <?= htmlspecialchars($title['title_name'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                      <?php endif; ?>
                      <?php if ($isReply && $parentUsername): ?>
                        <span class="comment-reply-label">回复</span>
                        <a href="/user.php?id=<?= isset($c['parent_user_id']) ? (int)$c['parent_user_id'] : 0 ?>" class="comment-username">@<?= htmlspecialchars($parentUsername, ENT_QUOTES, 'UTF-8') ?></a>
                      <?php endif; ?>
                      <span class="comment-time">· <?= time_ago($c['created_at']) ?></span>
                    </div>
                    <div class="ctext <?= $isReply ? 'ctext-sm' : '' ?>"><?= moderation_render_comment_html((string)$c['content']) ?></div>
                    <div class="comment-reply-form comment-reply-form-post" data-parent-id="<?= $commentId ?>" style="display:none;">
                      <div class="comment-input-bar comment-reply-input-bar">
                        <textarea class="comment-textarea comment-reply-textarea comment-reply-textarea-post" rows="1" maxlength="2000" placeholder="回复 <?= htmlspecialchars($c['username'], ENT_QUOTES, 'UTF-8') ?>..."></textarea>
                        <button type="button" class="comment-emoji-btn comment-reply-emoji-btn" title="插入表情" aria-label="插入表情">
                          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
                        </button>
                        <button class="btn primary btn-small comment-reply-submit-btn" data-parent-id="<?= $commentId ?>">发送</button>
                      </div>
                      <button type="button" class="comment-reply-cancel-btn">取消</button>
                    </div>
                  </div>
                </div>
                <div class="comment-right">
                  <div class="comment-actions">
                    <button type="button" class="comment-like-btn <?= $isLiked ? 'liked' : '' ?>" data-comment-id="<?= $commentId ?>" data-post-id="<?= (int)$postId ?>" data-count="<?= $likeCount ?>">
                      <svg class="comment-like-icon" width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>
                      <span class="comment-like-count"><?= $likeCount ?: '' ?></span>
                    </button>
                    <?php if ($currentUserId): ?>
                      <?php
                        $replyIconSvg = '<svg class="comment-reply-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>';
                      ?>
                      <button type="button" class="comment-reply-link comment-reply-btn" data-comment-id="<?= $commentId ?>" data-username="<?= htmlspecialchars($c['username'], ENT_QUOTES, 'UTF-8') ?>" aria-label="回复"><?= $replyIconSvg ?><span class="comment-reply-count"><?= !$isReply && $replyCount > 0 ? (int)$replyCount : '' ?></span></button>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="comment-extra">
                  <?php if ($isOwner): ?>
                    <div class="comment-more-wrap">
                      <button type="button" class="comment-more-btn" data-comment-id="<?= $commentId ?>" aria-label="更多">⋯</button>
                      <div class="comment-more-dropdown" data-comment-id="<?= $commentId ?>" style="display:none;">
                        <button type="button" class="comment-delete-btn" data-comment-id="<?= $commentId ?>">删除评论</button>
                      </div>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <?php
          }
          
          foreach ($comments as $i => $c): 
            $replyCount = isset($replies[(int)$c['id']]) ? count($replies[(int)$c['id']]) : 0;
            render_comment($c, $currentUserId, false, null, $commentLikedIds, $replyCount, (int)$post['id']);
            
            // 显示该评论的回复（默认只显示 1 条，点击「展开xx条评论」可查看全部）
            $commentId = (int)$c['id'];
            if (isset($replies[$commentId]) && !empty($replies[$commentId])): 
              $replyList = $replies[$commentId];
              $replyCount = count($replyList);
              $showExpandBtn = $replyCount > 1;
              $hiddenCount = $replyCount - 1;
            ?>
              <div class="comment-replies-container" data-parent-comment-id="<?= $commentId ?>" style="margin-left:40px; margin-top:4px;">
                <?php 
                // 只显示第一条回复
                $reply = $replyList[0];
                $parentUsername = '';
                if (!empty($reply['parent_username'])) {
                  $parentUsername = $reply['parent_username'];
                } elseif (!empty($reply['parent_id'])) {
                  try {
                    $pstmt = $pdo->prepare("SELECT u.username FROM comment c JOIN user u ON u.id = c.user_id WHERE c.id = ? AND c.status = 1 LIMIT 1");
                    $pstmt->execute([$reply['parent_id']]);
                    $parentComment = $pstmt->fetch();
                    if ($parentComment) $parentUsername = $parentComment['username'];
                  } catch (Throwable $e) {}
                }
                if (empty($parentUsername)) $parentUsername = $c['username'];
                render_comment($reply, $currentUserId, true, $parentUsername, $commentLikedIds, 0, (int)$post['id']);
                ?>
                <?php if ($showExpandBtn): ?>
                  <div class="comment-replies-extra" data-parent-id="<?= $commentId ?>" style="display:none;">
                    <?php for ($ri = 1; $ri < $replyCount; $ri++): 
                      $reply = $replyList[$ri];
                      $parentUsername = '';
                      if (!empty($reply['parent_username'])) {
                        $parentUsername = $reply['parent_username'];
                      } elseif (!empty($reply['parent_id'])) {
                        try {
                          $pstmt = $pdo->prepare("SELECT u.username FROM comment c JOIN user u ON u.id = c.user_id WHERE c.id = ? AND c.status = 1 LIMIT 1");
                          $pstmt->execute([$reply['parent_id']]);
                          $parentComment = $pstmt->fetch();
                          if ($parentComment) $parentUsername = $parentComment['username'];
                        } catch (Throwable $e) {}
                      }
                      if (empty($parentUsername)) $parentUsername = $c['username'];
                      render_comment($reply, $currentUserId, true, $parentUsername, $commentLikedIds, 0, (int)$post['id']);
                    endfor; ?>
                  </div>
                  <button type="button" class="comment-expand-btn" data-parent-id="<?= $commentId ?>" data-hidden-count="<?= $hiddenCount ?>">展开 <?= $hiddenCount ?> 条回复</button>
                <?php endif; ?>
              </div>
            <?php endif;
          endforeach; 
          ?>
      <?php endif; ?>
    </div>

    </main>
    <?php require __DIR__ . '/partials/footer.php'; ?>
  </div>

  <script src="/assets/app.js?v=<?= filemtime(__DIR__.'/assets/app.js') ?>"></script>

  <!-- MathJax（先配置，再加载） -->
  <script>
  window.MathJax = {
    tex: {
      inlineMath: [['$', '$'], ['\\(', '\\)']],
      displayMath: [['$$', '$$'], ['\\[', '\\]']]
    }
  };
  </script>
  <script src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js" async></script>
  <script>
  document.addEventListener('DOMContentLoaded', function() {
    if (window.MathJax && window.MathJax.typesetPromise) {
      window.MathJax.typesetPromise().catch(function(err) {
        console.error('MathJax rendering error:', err);
      });
    }
  });
  </script>
  <?php if (!$previewMode): ?>
  <script>
  (function() {
    var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    async function toggle(endpoint, btn, labels) {
      var postId = btn.getAttribute('data-post-id');
      var countEl = btn.querySelector('.action-count');
      
      // 保存原始状态
      var originalDisabled = btn.disabled;
      var originalOpacity = btn.style.opacity;
      
      // 设置 loading 状态（不删除子元素）
      btn.disabled = true;
      btn.style.opacity = '0.6';
      btn.style.cursor = 'wait';
      
      try {
        var fd = new FormData();
        fd.append('post_id', postId);
        fd.append('csrf', csrf);

        var result = await safeFetchJSON(endpoint, { method:'POST', body: fd });
        
        if (!result.ok) {
          if (result.code === 'LOGIN') {
            return;
          }
          window.showAppAlert(result.msg || '操作失败');
          return;
        }

        // result.data 就是后端返回的原始数据
        var data = result.data || {};
        
        // 更新数量 - 确保从正确的位置获取
        if (countEl && typeof data.count !== 'undefined') {
          countEl.textContent = data.count;
        }
        
        // 更新按钮样式
        var on = (data.liked !== undefined) ? data.liked : (data.fav !== undefined ? data.fav : false);
        if (endpoint.includes('like')) {
          btn.classList.toggle('liked', !!on);
        } else if (endpoint.includes('favorite')) {
          btn.classList.toggle('favorited', !!on);
        }
        // 显示积分获得通知
        if (data.rewards && data.rewards.length && window.showRewardToasts) {
          window.showRewardToasts(data.rewards);
        }
      } catch (e) {
        console.error('Toggle error:', e);
        window.showAppAlert('网络或服务器错误');
      } finally {
        // 恢复状态
        btn.disabled = originalDisabled;
        btn.style.opacity = originalOpacity || '';
        btn.style.cursor = '';
      }
    }

    // 点赞
    var likeBtn = document.getElementById('likeBtn');
    if (likeBtn) {
      likeBtn.addEventListener('click', function() {
        toggle('/like_toggle.php', likeBtn, {});
      });
    }

    // 收藏
    var favBtn = document.getElementById('favBtn');
    if (favBtn) {
      favBtn.addEventListener('click', function() {
        toggle('/favorite_toggle.php', favBtn, {});
      });
    }

    // 复制链接（分享）
    var shareBtn = document.getElementById('sharePostBtn');
    if (shareBtn) {
      shareBtn.addEventListener('click', function() {
        var url = window.location.href;
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(url).then(function() {
            if (window.showAppAlert) window.showAppAlert('链接已复制');
          }).catch(function() {
            window.prompt('复制链接', url);
          });
        } else {
          window.prompt('复制链接', url);
        }
      });
    }

    // 评论按钮 → 滚动到评论区并聚焦输入框
    var commentBtn = document.getElementById('commentBtn');
    if (commentBtn) {
      commentBtn.addEventListener('click', function() {
        var comments = document.getElementById('comments');
        if (!comments) return;
        comments.scrollIntoView({ behavior: 'smooth', block: 'start' });
        setTimeout(function() {
          var ta = document.querySelector('#commentForm textarea');
          if (ta) ta.focus();
        }, 400);
      });
    }
    
    // 展开更多回复
    document.querySelectorAll('.comment-expand-btn').forEach(function(btn) {
      btn.addEventListener('click', function() {
        var parentId = this.getAttribute('data-parent-id');
        var extra = document.querySelector('.comment-replies-extra[data-parent-id="' + parentId + '"]');
        if (!extra) return;
        var hiddenCount = this.getAttribute('data-hidden-count');
        if (extra.style.display === 'none') {
          extra.style.display = 'block';
          this.textContent = '收起';
          this.classList.add('expanded');
        } else {
          extra.style.display = 'none';
          this.textContent = '展开 ' + hiddenCount + ' 条回复';
          this.classList.remove('expanded');
        }
      });
    });

    // 评论点赞、菜单、表情、回复、删除等 → 委托绑定，支持无刷新替换
    bindCommentEvents();

    function bindCommentEvents() {
      var box = document.querySelector('.commentBox');
      if (!box) return;

      box.addEventListener('click', async function(e) {
        // 点赞
        var likeBtn = e.target.closest('.comment-like-btn');
        if (likeBtn) {
          e.preventDefault();
          var commentId = likeBtn.getAttribute('data-comment-id');
          var postId = likeBtn.getAttribute('data-post-id');
          var countEl = likeBtn.querySelector('.comment-like-count');
          if (!commentId) return;
          var fd = new FormData();
          fd.append('comment_id', commentId);
          if (postId) fd.append('post_id', postId);
          fd.append('csrf', csrf);
          try {
            var result = await safeFetchJSON('/comment_like_toggle.php', { method:'POST', body: fd });
            if (!result.ok) {
              if (result.code === 'LOGIN') return;
              window.showAppAlert(result.msg || '操作失败');
              return;
            }
            var data = result.data || result;
            if (countEl) countEl.textContent = (data.count !== undefined ? data.count : result.count) || '';
            likeBtn.classList.toggle('liked', !!data.liked);
            var rewards = result.rewards || data.rewards;
            if (rewards && rewards.length && window.showRewardToasts) {
              window.showRewardToasts(rewards);
            }
          } catch (e) {
            window.showAppAlert('网络或服务器错误');
          }
          return;
        }

        // 三点菜单
        var moreBtn = e.target.closest('.comment-more-btn');
        if (moreBtn) {
          e.stopPropagation();
          var mCommentId = moreBtn.getAttribute('data-comment-id');
          var dropdown = document.querySelector('.comment-more-dropdown[data-comment-id="' + mCommentId + '"]');
          if (!dropdown) return;
          var isOpen = dropdown.style.display === 'block';
          document.querySelectorAll('.comment-more-dropdown').forEach(function(d) { d.style.display = 'none'; });
          dropdown.style.display = isOpen ? 'none' : 'block';
          return;
        }

        // 回复按钮
        var replyBtn = e.target.closest('.comment-reply-btn');
        if (replyBtn) {
          e.preventDefault();
          e.stopPropagation();
          const commentId = replyBtn.getAttribute('data-comment-id');
          const commentEl = replyBtn.closest('.citem');
          if (!commentEl) return;
          const replyForm = commentEl.querySelector('.comment-reply-form[data-parent-id="' + commentId + '"]');
          if (replyForm) {
            document.querySelectorAll('.comment-reply-form').forEach(function(f) {
              if (f !== replyForm) f.style.display = 'none';
            });
            const isVisible = replyForm.style.display === 'block';
            replyForm.style.display = isVisible ? 'none' : 'block';
            if (replyForm.style.display === 'block') {
              const textarea = replyForm.querySelector('.comment-reply-textarea');
              if (textarea) textarea.focus();
            }
          }
          return;
        }

        // 取消回复
        var cancelBtn = e.target.closest('.comment-reply-cancel-btn');
        if (cancelBtn) {
          const replyForm = cancelBtn.closest('.comment-reply-form');
          if (replyForm) {
            replyForm.style.display = 'none';
            const textarea = replyForm.querySelector('.comment-reply-textarea');
            if (textarea) textarea.value = '';
          }
          return;
        }

        // 回复框表情
        var replyEmojiBtn = e.target.closest('.comment-reply-emoji-btn');
        if (replyEmojiBtn) {
          e.preventDefault();
          e.stopPropagation();
          const replyForm = replyEmojiBtn.closest('.comment-reply-form');
          const ta = replyForm ? replyForm.querySelector('.comment-reply-textarea') : null;
          if (ta) window._emojiTarget = ta;
          toggleEmojiPicker(replyEmojiBtn);
          return;
        }

        // 回复提交（await 但不设 loading 状态）
        var submitBtn = e.target.closest('.comment-reply-submit-btn');
        if (submitBtn) {
          if (submitBtn._submitting) return;
          const parentId = submitBtn.getAttribute('data-parent-id');
          const replyForm = submitBtn.closest('.comment-reply-form');
          if (!replyForm) return;
          const textarea = replyForm.querySelector('.comment-reply-textarea');
          const content = textarea ? textarea.value.trim() : '';
          if (content === '') {
            window.showAppAlert('回复内容不能为空');
            return;
          }
          submitBtn._submitting = true;
          const fd = new FormData();
          fd.append('post_id', '<?= (int)$post['id'] ?>');
          fd.append('parent_id', parentId);
          fd.append('content', content);
          fd.append('csrf', csrf);
          try {
            await safeFetchJSON('/comment_create.php', {
              method:'POST', body: fd,
              headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
          } catch (e) {}
          submitBtn._submitting = false;
          reloadCommentSection();
          return;
        }

        // 删除评论
        var deleteBtn = e.target.closest('.comment-delete-btn');
        if (deleteBtn) {
          const commentId = deleteBtn.getAttribute('data-comment-id');
          var replyContainer = document.querySelector('.comment-replies-container[data-parent-comment-id="' + commentId + '"]');
          var hasReplies = replyContainer && replyContainer.querySelectorAll('.citem').length > 0;
          var confirmMsg = hasReplies
            ? '确定要删除这条评论吗？删除后，该评论下的所有回复也会被删除。'
            : '确定要删除这条评论吗？';
          if (!(await window.showAppConfirm(confirmMsg, { title: '删除评论', danger: true }))) return;
          setButtonLoading(deleteBtn, true);
          try {
            const fd = new FormData();
            fd.append('comment_id', commentId);
            fd.append('csrf', csrf);
            const result = await safeFetchJSON('/comment_delete.php', { method:'POST', body: fd });
            if (!result.ok) {
              window.showAppAlert(result.msg || '删除失败');
              return;
            }
            reloadCommentSection();
          } catch (e) {
            window.showAppAlert('网络或服务器错误');
          }
          return;
        }

        // 主评论表情
        var emojiBtn = e.target.closest('.comment-emoji-btn');
        if (emojiBtn && emojiBtn.closest('#commentForm')) {
          e.preventDefault();
          e.stopPropagation();
          var ta = document.querySelector('#commentForm textarea');
          if (ta) window._emojiTarget = ta;
          toggleEmojiPicker(emojiBtn);
          return;
        }
      });

      // 文本框自动增高
      document.querySelectorAll('.comment-textarea-post, .comment-reply-textarea-post').forEach(function(ta) {
        ta.addEventListener('input', function() { autoResizeTextarea(this); });
      });

      // 主评论提交（委托，评论区替换后继续有效）
      box.addEventListener('submit', async function(e) {
        var form = e.target.closest('#commentForm');
        if (!form) return;
        e.preventDefault();
        if (form._submitting) return;
        var ta = form.querySelector('textarea');
        if (!ta || !ta.value.trim()) {
          window.showAppAlert('评论内容不能为空');
          return;
        }
        var btn = form.querySelector('.comment-submit-btn');
        if (btn) setButtonLoading(btn, true);
        form._submitting = true;
        try {
          var fd = new FormData(form);
          var result = await safeFetchJSON('/comment_create.php', {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
          });
          if (!result.ok) {
            form._submitting = false;
            if (btn) setButtonLoading(btn, false);
            if (result.code === 'LOGIN') return;
            window.showAppAlert(result.msg || '评论失败');
            return;
          }
          form._submitting = false;
          if (btn) setButtonLoading(btn, false);
          if (ta) { ta.value = ''; ta.style.height = 'auto'; }
          reloadCommentSection();
        } catch (err) {
          form._submitting = false;
          if (btn) setButtonLoading(btn, false);
          window.showAppAlert('网络或服务器错误');
        }
      });
    }

    // ─── 表情选择器 ─────────────────────────
    var EMOJI_CATEGORIES = [
      {
        label: '笑脸',
        items: ['😊','😂','🤣','❤️','😍','🥰','😘','😋','😜','🤪','😎','🥳','🤩','😏','😌','😉','🙃','😢','😭','😤','🥺','😱','🤯','🥶','🤗','🤭']
      },
      {
        label: '手势',
        items: ['👍','👎','👌','✌️','🤞','🤟','🤘','👋','🤝','🙏','💪','🖕','✨']
      },
      {
        label: '爱心',
        items: ['❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💕','💗','💖','💘','💝']
      },
      {
        label: '符号',
        items: ['✅','❌','⭐','🔥','💯','🎉','🎊','🎈','💡','📌','💀','👀','🗣️','💤']
      },
      {
        label: '物品',
        items: ['🎁','🎂','🍰','🍕','🌮','🥤','☕','🍻','🎵','🎶','🔔','💎','🧸','🎮','📷','🌈']
      }
    ];

    function buildEmojiPicker() {
      var body = document.getElementById('emojiPickerBody');
      if (!body || body.children.length > 0) return;
      var html = '';
      for (var ci = 0; ci < EMOJI_CATEGORIES.length; ci++) {
        var cat = EMOJI_CATEGORIES[ci];
        html += '<div class="emoji-category">';
        html += '<div class="emoji-category-label">' + cat.label + '</div>';
        html += '<div class="emoji-grid">';
        for (var ei = 0; ei < cat.items.length; ei++) {
          html += '<button type="button" class="emoji-item" data-emoji="' + cat.items[ei] + '">' + cat.items[ei] + '</button>';
        }
        html += '</div></div>';
      }
      body.innerHTML = html;

      // 点击表情插入
      body.addEventListener('click', function(e) {
        var item = e.target.closest('.emoji-item');
        if (!item) return;
        var emoji = item.getAttribute('data-emoji');
        var ta = window._emojiTarget;
        if (ta) {
          var start = ta.selectionStart;
          var end = ta.selectionEnd;
          ta.value = ta.value.substring(0, start) + emoji + ta.value.substring(end);
          ta.selectionStart = ta.selectionEnd = start + emoji.length;
          ta.focus();
          ta.dispatchEvent(new Event('input', { bubbles: true }));
        }
        document.getElementById('emojiPicker').style.display = 'none';
      });
    }

    var _emojiPickerVisible = false;

    function toggleEmojiPicker(btn) {
      var picker = document.getElementById('emojiPicker');
      if (!picker) return;
      if (_emojiPickerVisible) {
        picker.style.display = 'none';
        _emojiPickerVisible = false;
        return;
      }
      buildEmojiPicker();
      var rect = btn.getBoundingClientRect();
      picker.style.display = 'flex';
      // 定位：在按钮上方弹出
      var top = rect.top - picker.offsetHeight - 8;
      if (top < 4) {
        // 空间不够则显示在下方
        top = rect.bottom + 4;
      }
      picker.style.left = Math.max(4, Math.min(rect.left, window.innerWidth - 324)) + 'px';
      picker.style.top = top + 'px';
      _emojiPickerVisible = true;
    }

    // 关闭表情选择器（点击外部）
    document.addEventListener('click', function(e) {
      var picker = document.getElementById('emojiPicker');
      if (!picker || picker.style.display === 'none') return;
      if (!picker.contains(e.target) && !e.target.closest('.comment-emoji-btn')) {
        picker.style.display = 'none';
        _emojiPickerVisible = false;
      }
    });

    // 关闭按钮
    document.getElementById('emojiPickerClose')?.addEventListener('click', function() {
      document.getElementById('emojiPicker').style.display = 'none';
      _emojiPickerVisible = false;
    });

    // 三点菜单全局关闭（不受评论区替换影响）
    document.addEventListener('click', function() {
      document.querySelectorAll('.comment-more-dropdown').forEach(function(d) { d.style.display = 'none'; });
    });

    // 评论成功后刷新页面展示新内容
    function reloadCommentSection() {
      window.location.reload();
    }
  })();
  </script>
  <?php endif; ?>

  <!-- 图片查看器 -->
  <div id="imageViewer" class="iv-overlay" style="display:none;" role="dialog" aria-label="图片查看">
    <div class="iv-backdrop"></div>
    <div class="iv-header">
      <button type="button" class="iv-btn iv-download" title="下载图片" aria-label="下载图片">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      </button>
      <button type="button" class="iv-btn iv-close" title="关闭" aria-label="关闭">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="iv-body">
      <img class="iv-image" src="" alt="查看大图">
    </div>
  </div>
  <script>
  (function() {
    var viewer = document.getElementById('imageViewer');
    if (!viewer) return;
    var imgEl = viewer.querySelector('.iv-image');
    var closeBtn = viewer.querySelector('.iv-close');
    var dlBtn = viewer.querySelector('.iv-download');
    var backdrop = viewer.querySelector('.iv-backdrop');

    function open(src) {
      imgEl.src = src;
      viewer.style.display = 'flex';
      document.body.style.overflow = 'hidden';
    }

    function close() {
      viewer.style.display = 'none';
      document.body.style.overflow = '';
      imgEl.src = '';
    }

    closeBtn.addEventListener('click', close);
    backdrop.addEventListener('click', close);
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') close();
    });

    dlBtn.addEventListener('click', function() {
      var src = imgEl.src;
      if (!src) return;
      var a = document.createElement('a');
      a.href = src;
      a.download = src.split('/').pop() || 'image';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
    });

    // 为所有可点击图片绑定点击事件
    document.addEventListener('click', function(e) {
      var target = e.target;
      // 检查是否是可放大的图片（js-zoomable 或在 quill-content 内的 img）
      if (target.tagName === 'IMG' && (target.classList.contains('js-zoomable') || target.closest('.quill-content'))) {
        // 排除头像等小图
        if (target.closest('.comment-avatar-wrap') || target.closest('.post-reading-author') || target.closest('.user-avatar')) return;
        e.preventDefault();
        e.stopPropagation();
        // 使用当前图片的实际渲染尺寸来判断是否是装饰性小图标
        if (target.naturalWidth < 50 && target.naturalHeight < 50) return;
        open(target.src);
      }
    });
  })();
  </script>

  <style>
  /* 表情选择器 */
  .emoji-picker {
    position: fixed;
    z-index: 10050;
    width: 320px;
    max-height: 280px;
    background: var(--pure-white, #fff);
    border: 1px solid var(--light-gray, #e5e5e5);
    border-radius: var(--r, 10px);
    box-shadow: 0 8px 30px rgba(0,0,0,0.15);
    overflow: hidden;
    display: flex;
    flex-direction: column;
  }
  .emoji-picker-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 12px 6px;
    border-bottom: 1px solid var(--light-gray, #e5e5e5);
  }
  .emoji-picker-title {
    font-size: 13px;
    font-weight: var(--fw-semibold, 600);
    color: var(--near-black, #333);
  }
  .emoji-picker-close {
    background: none;
    border: none;
    font-size: 20px;
    line-height: 1;
    cursor: pointer;
    color: var(--stone, #999);
    padding: 0 4px;
  }
  .emoji-picker-close:hover {
    color: var(--near-black, #333);
  }
  .emoji-picker-body {
    padding: 8px;
    overflow-y: auto;
    flex: 1;
  }
  .emoji-picker-body .emoji-category {
    margin-bottom: 6px;
  }
  .emoji-picker-body .emoji-category-label {
    font-size: 11px;
    color: var(--stone, #999);
    margin-bottom: 4px;
    padding: 0 2px;
  }
  .emoji-picker-body .emoji-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 2px;
  }
  .emoji-picker-body .emoji-item {
    width: 34px;
    height: 34px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    cursor: pointer;
    border-radius: 6px;
    border: none;
    background: none;
    padding: 0;
    transition: background 0.1s;
  }
  .emoji-picker-body .emoji-item:hover {
    background: var(--snow, #f5f5f5);
  }
  </style>
</body>
</html>
