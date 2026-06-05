<?php
declare(strict_types=1);

/**
 * 统一帖子卡片（发现 / 动态 等）
 * 依赖：bootstrap 已加载；需含 helpers.php、avatar.php
 *
 * @var array $p 须含 id, title, image, user_id, username, like_count；
 *                可选 content（HTML 正文，用于大卡摘要）, tags, tag_slugs
 */
if (!function_exists('post_grid_first_image')) {
  require_once __DIR__ . '/../app/helpers.php';
}
if (!function_exists('avatar_html')) {
  require_once __DIR__ . '/../app/avatar.php';
}

$postId = (int)($p['id'] ?? 0);
$rawTitle = (string)($p['title'] ?? '');
$titleEscFull = htmlspecialchars($rawTitle, ENT_QUOTES, 'UTF-8');
$cardExcerpt = post_grid_plain_excerpt(isset($p['content']) ? (string)$p['content'] : null, 180);

$postImage = post_grid_first_image($p['image'] ?? null, isset($p['content']) ? (string)$p['content'] : null);
$tagItems = post_grid_tag_pairs($p['tags'] ?? null, $p['tag_slugs'] ?? null, 5);
$authorUser = [
  'id'       => (int)($p['user_id'] ?? 0),
  'username' => (string)($p['username'] ?? ''),
];
$likeCount = (int)($p['like_count'] ?? 0);
?>
<a href="/post.php?id=<?= $postId ?>" class="post-card">
  <?php if (!empty($postImage)): ?>
    <div class="post-card-image">
      <img src="<?= htmlspecialchars($postImage, ENT_QUOTES, 'UTF-8') ?>" alt="<?= $titleEscFull ?>" loading="lazy">
    </div>
  <?php else: ?>
    <div class="post-card-image placeholder">
      <div class="placeholder-text"><?= mb_strlen($rawTitle) > 10 ? htmlspecialchars(mb_substr($rawTitle, 0, 10), ENT_QUOTES, 'UTF-8') . '…' : $titleEscFull ?></div>
    </div>
  <?php endif; ?>
  <div class="post-card-content">
    <div class="post-card-title"><?= $titleEscFull ?></div>
    <?php if ($cardExcerpt !== ''): ?>
      <p class="post-card-excerpt"><?= htmlspecialchars($cardExcerpt, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
      <div class="post-card-tags" role="group" aria-label="标签">
        <?php if (!empty($tagItems)):
          foreach ($tagItems as $tg):
            $nm = htmlspecialchars($tg['name'], ENT_QUOTES, 'UTF-8');
        ?>
          <span class="post-card-tag"><?= $nm ?></span>
        <?php endforeach; endif; ?>
      </div>
    <div class="post-card-footer">
      <div class="post-card-author">
        <span class="post-card-avatar"><?= avatar_html($authorUser, 24) ?></span>
        <span class="post-card-username"><?= htmlspecialchars($authorUser['username'], ENT_QUOTES, 'UTF-8') ?></span>
      </div>
      <div class="post-card-likes">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
        </svg>
        <span><?= $likeCount ?></span>
      </div>
    </div>
  </div>
</a>
