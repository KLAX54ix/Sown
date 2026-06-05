<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/avatar.php';
require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/tag.php';

// 需要登录才能发布
if (!is_logged_in()) {
  safe_redirect(login_url('/post_note.php'));
}

$user = current_user();
if (!$user) {
  safe_redirect('/login.php?next=' . urlencode('/post_note.php'));
}

// 检查是否编辑现有内容
$postId = input_int('id', 0, 0);
$isEditMode = $postId > 0;
$post = null;
$postTags = '';
// 优先使用POST数据中的标签（表单回显）
$postedTags = input_string('tags', '', 'post');
if ($postedTags !== '') {
    $postTags = $postedTags;
}
$tagArray = []; // 存储标签数组，用于JavaScript初始化

if ($isEditMode) {
  $pdo = db();
  // 加载帖子数据，确保属于当前用户
  $st = $pdo->prepare("SELECT * FROM post WHERE id = ? AND user_id = ?");
  $st->execute([$postId, $user['id']]);
  $post = $st->fetch();

  if (!$post) {
    http_response_code(404);
    echo "内容不存在或无权编辑";
    exit;
  }

  // 加载标签（仅当没有POST数据中的标签时）
  if (!isset($_POST['tags'])) {
    try {
      $st = $pdo->prepare("
        SELECT t.name
        FROM post_tag t
        JOIN post_tag_relation r ON r.tag_id = t.id
        WHERE r.post_id = ?
      ");
      $st->execute([$postId]);
      $tags = $st->fetchAll(PDO::FETCH_COLUMN);
      $tags = array_values(array_unique($tags));
      $postTags = implode(', ', $tags);
    } catch (Throwable $e) {
      // 表不存在或其他错误时返回空
      error_log("Failed to load tags for post {$postId}: " . $e->getMessage());
      $postTags = '';
    }
  }

}

$initialCover = '';
if ($isEditMode && $post) {
  $ic = post_grid_first_image($post['image'] ?? null, null);
  if ($ic !== null) {
    $initialCover = $ic;
  }
}
$previewAvatarHtml = avatar_html($user, 24);
$previewUsernameEsc = htmlspecialchars((string)($user['username'] ?? ''), ENT_QUOTES, 'UTF-8');

// 获取错误消息
$err = input_string('err');
$errMsg = render_error($err);
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>发布内容 · Sown</title>
  <?php require __DIR__ . '/partials/head.php'; ?>
  <link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(__DIR__.'/assets/app.css') ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css">
  <link rel="stylesheet" href="/assets/vendor/quill-1.3.7/quill.snow.css?v=<?= filemtime(__DIR__.'/assets/vendor/quill-1.3.7/quill.snow.css') ?>">
  <style>
    .post-edit-quill-wrap .ql-editor { min-height: 280px; font-size: 16px; line-height: 1.7; }
    /* 编辑器区域：Snow 整块外框（与主题分割线颜色一致） */
    .post-edit-quill-wrap .ql-toolbar.ql-snow {
      border: 2px solid var(--light-gray);
      border-bottom: none;
      border-radius: var(--r) var(--r) 0 0;
      overflow: visible;
      position: relative;
      z-index: 3;
    }
    .post-edit-quill-wrap .ql-container.ql-snow {
      border: 2px solid var(--light-gray);
      border-top: none;
      border-radius: 0 0 var(--r) var(--r);
    }
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-expanded .ql-picker-options { z-index: 50; }
    /* 行距与字号文案：用“行距/字体 + 数值”，并减少档位数量 */
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-lineHeight,
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-lineheight {
      width: 6.25rem;
      min-width: 6.25rem;
    }
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-lineHeight .ql-picker-label:not([data-value])::before,
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-lineHeight .ql-picker-item:not([data-value])::before,
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-lineHeight .ql-picker-label[data-value=""]::before,
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-lineHeight .ql-picker-item[data-value=""]::before,
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-lineheight .ql-picker-label:not([data-value])::before,
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-lineheight .ql-picker-item:not([data-value])::before,
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-lineheight .ql-picker-label[data-value=""]::before,
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-lineheight .ql-picker-item[data-value=""]::before {
      content: '行距 1.7';
    }
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-lineHeight .ql-picker-label[data-value="1.2"]::before,
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-lineHeight .ql-picker-item[data-value="1.2"]::before,
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-lineheight .ql-picker-label[data-value="1.2"]::before,
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-lineheight .ql-picker-item[data-value="1.2"]::before { content: '行距 1.2'; }
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-lineHeight .ql-picker-label[data-value="1.6"]::before,
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-lineHeight .ql-picker-item[data-value="1.6"]::before,
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-lineheight .ql-picker-label[data-value="1.6"]::before,
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-lineheight .ql-picker-item[data-value="1.6"]::before { content: '行距 1.6'; }
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-lineHeight .ql-picker-label[data-value="2"]::before,
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-lineHeight .ql-picker-item[data-value="2"]::before,
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-lineheight .ql-picker-label[data-value="2"]::before,
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-lineheight .ql-picker-item[data-value="2"]::before { content: '行距 2.0'; }
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-lineHeight .ql-picker-label[data-value="2.6"]::before,
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-lineHeight .ql-picker-item[data-value="2.6"]::before,
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-lineheight .ql-picker-label[data-value="2.6"]::before,
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-lineheight .ql-picker-item[data-value="2.6"]::before { content: '行距 2.6'; }

    .post-edit-quill-wrap .ql-snow .ql-picker.ql-size {
      width: 6.5rem;
      min-width: 6.5rem;
    }
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-size .ql-picker-label:not([data-value])::before,
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-size .ql-picker-item:not([data-value])::before,
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-size .ql-picker-label[data-value=""]::before,
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-size .ql-picker-item[data-value=""]::before {
      content: '字体 16px';
    }
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-size .ql-picker-label[data-value="14px"]::before,
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-size .ql-picker-item[data-value="14px"]::before { content: '字体 14px'; }
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-size .ql-picker-label[data-value="16px"]::before,
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-size .ql-picker-item[data-value="16px"]::before { content: '字体 16px'; }
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-size .ql-picker-label[data-value="20px"]::before,
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-size .ql-picker-item[data-value="20px"]::before { content: '字体 20px'; }
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-size .ql-picker-label[data-value="24px"]::before,
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-size .ql-picker-item[data-value="24px"]::before { content: '字体 24px'; }
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-size .ql-picker-label[data-value="30px"]::before,
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-size .ql-picker-item[data-value="30px"]::before { content: '字体 30px'; }
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-size .ql-picker-label[data-value="36px"]::before,
    .post-edit-quill-wrap .ql-snow .ql-picker.ql-size .ql-picker-item[data-value="36px"]::before { content: '字体 36px'; }

    /* 工具栏：按钮与下拉统一对齐 */
    .post-edit-quill-wrap .ql-toolbar.ql-snow {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 6px;
      padding: 8px 10px;
    }
    .post-edit-quill-wrap .ql-toolbar.ql-snow .ql-formats {
      display: inline-flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 4px;
      margin-right: 4px;
      margin-bottom: 0;
    }
    .post-edit-quill-wrap .ql-toolbar.ql-snow button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 28px;
      height: 28px;
      padding: 0;
      border-radius: 4px;
      font-weight: 400;
    }
    .post-edit-quill-wrap .ql-toolbar.ql-snow button.ql-math-symbols {
      width: 28px;
      font-weight: 500;
      font-size: 13px;
      line-height: 1;
      font-family: "Times New Roman", Georgia, serif;
    }
    /* 编辑器中 LaTeX 公式渲染样式 */
    .post-edit-quill-wrap .ql-editor .ql-formula {
      display: inline-block;
      padding: 0 2px;
      vertical-align: middle;
    }
    .post-edit-quill-wrap .ql-editor .ql-formula .katex {
      font-size: 1.1em;
    }
    /* 公式输入框样式：更宽更高，适合输入 LaTeX */
    .ql-snow .ql-tooltip[data-mode=formula] {
      padding: 8px 14px;
    }
    .ql-snow .ql-tooltip[data-mode=formula]::before {
      content: "Enter formula:" !important;
      font-size: 14px;
      line-height: 34px;
    }
    .ql-snow .ql-tooltip[data-mode=formula] input[type=text] {
      width: 320px;
      height: 34px;
      font-size: 15px;
      padding: 4px 10px;
      font-family: "Consolas", "Monaco", "Courier New", monospace;
      border: 1px solid var(--green-light, #9CAF6E);
      border-radius: 6px;
      letter-spacing: 0.3px;
      background: var(--green-bg, #F8FAF0);
      color: #3A4028;
      transition: border-color 0.2s, box-shadow 0.2s;
    }
    .ql-snow .ql-tooltip[data-mode=formula] input[type=text]:focus {
      border-color: var(--green-primary, #778B3E);
      box-shadow: 0 0 0 3px var(--ring-green, rgba(119, 139, 62, 0.25));
      outline: none;
    }
    .ql-snow .ql-tooltip[data-mode=formula] a {
      line-height: 34px;
    }
    .ql-snow .ql-tooltip[data-mode=formula] a.ql-action::after {
      font-size: 14px;
      color: var(--green-primary, #778B3E);
    }
    .post-edit-quill-wrap .ql-toolbar.ql-snow button svg {
      width: 16px;
      height: 16px;
    }
    .post-edit-quill-wrap .ql-toolbar.ql-snow .ql-picker-label {
      border-radius: 4px;
      height: 28px;
      display: inline-flex;
      align-items: center;
    }

    /* 数学符号面板样式 */
    #mathSymbolsPanel.editor-floating-panel {
      width: min(420px, calc(100vw - 16px));
      max-width: min(420px, calc(100vw - 16px));
      max-height: min(62vh, 460px);
      overflow: auto;
      padding: 10px;
      border: 1px solid #d9dce3;
      border-radius: 10px;
      background: #fff;
      box-shadow: 0 14px 36px rgba(0, 0, 0, 0.16);
    }
    #mathSymbolsPanel .symbol-panel-head {
      position: sticky;
      top: 0;
      z-index: 2;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
      margin: -2px -2px 10px;
      padding: 8px 2px 10px;
      background: #fff;
      border-bottom: 1px solid #eceff4;
    }
    #mathSymbolsPanel .symbol-panel-title {
      font-size: 13px;
      font-weight: 600;
      color: #252b36;
    }
    #mathSymbolsPanel .symbol-panel-close {
      border: 1px solid #d6dbe6;
      background: #f7f9fc;
      color: #3a4352;
      border-radius: 6px;
      padding: 4px 10px;
      font-size: 12px;
      line-height: 1.2;
      cursor: pointer;
    }
    #mathSymbolsPanel .symbol-group {
      margin-bottom: 10px;
    }
    #mathSymbolsPanel .symbol-group-title {
      font-size: 12px;
      color: #667085;
      margin: 0 0 6px;
      font-weight: 600;
    }
    #mathSymbolsPanel .symbols {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(42px, 1fr));
      gap: 6px;
    }
    #mathSymbolsPanel .symbol-btn {
      height: 34px;
      min-width: 0;
      border: 1px solid #d6dbe6;
      border-radius: 8px;
      background: #f8fafc;
      color: #1f2937;
      font-size: 18px;
      line-height: 1;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: all 0.14s ease;
    }
    #mathSymbolsPanel .symbol-btn:hover {
      border-color: #94a3b8;
      background: #eef3ff;
      transform: translateY(-1px);
    }
    #mathSymbolsPanel .symbol-btn:active {
      transform: translateY(0);
      background: #e7eefc;
    }
    #mathSymbolsPanel .symbol-btn--action {
      font-size: 12px;
      font-weight: 600;
      letter-spacing: 0;
      padding: 0 8px;
    }

    /* 上下标占位框样式：可点击、可直接输入替换 */
    .post-edit-quill-wrap .ql-editor .ql-slot-super,
    .post-edit-quill-wrap .ql-editor .ql-slot-sub {
      display: inline-block;
      min-width: 0.95em;
      padding: 0 0.18em;
      border: 1px dashed #94a3b8;
      border-radius: 4px;
      background: #f8fbff;
      color: #1f2937;
      cursor: text;
      text-align: center;
      line-height: 1;
    }
    .post-edit-quill-wrap .ql-editor .ql-slot-super {
      font-size: 0.72em;
      vertical-align: super;
    }
    .post-edit-quill-wrap .ql-editor .ql-slot-sub {
      font-size: 0.72em;
      vertical-align: sub;
    }
    .post-edit-quill-wrap .ql-editor .ql-slot-super::selection,
    .post-edit-quill-wrap .ql-editor .ql-slot-sub::selection {
      background: rgba(59, 130, 246, 0.25);
    }
  </style>
</head>
<body>
  <?php require __DIR__ . '/partials/header.php'; ?>
  <div class="container">

    <div class="section post-edit-section">
      <div class="post-edit-header">
        <h1 class="post-edit-title"><?= $isEditMode ? '编辑内容' : '发布内容' ?></h1>
        <?php if ($isEditMode): ?>
        <p class="post-edit-subtitle">修改你的内容，更新后重新发布</p>
        <?php endif; ?>
      </div>

      <?= $errMsg ?>

      <form method="post" action="/post_note_post.php" class="post-edit-form" id="postNoteForm">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="submit_intent" id="postSubmitIntent" value="">
        <input type="hidden" name="content" id="contentInput" value="">
        <input type="hidden" name="cover_image" id="coverImageInput" value="<?= htmlspecialchars($initialCover, ENT_QUOTES, 'UTF-8') ?>">
        <?php if ($isEditMode): ?>
          <input type="hidden" name="post_id" value="<?= (int)$postId ?>">
        <?php endif; ?>
        <?php if ($isEditMode): ?>
          <textarea id="editorInitialHtml" hidden><?= htmlspecialchars($post['content'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
        <?php endif; ?>
        
        <div class="form-group">
          <label class="form-label">
            <span>标题</span>
            <span class="form-hint">简洁明了地概括你的内容</span>
          </label>
          <input name="title" type="text" class="form-input" placeholder="输入标题" required maxlength="200"
                 value="<?= $isEditMode ? htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8') : htmlspecialchars(input_string('title', '', 'post'), ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div class="form-group">
          <label class="form-label">
            <span>标签</span>
            <span class="form-hint">添加标签帮助其他用户发现你的内容</span>
          </label>
          <div class="tag-input-container">
            <input type="hidden" name="tags" id="tagsHiddenField" value="<?= htmlspecialchars($postTags, ENT_QUOTES, 'UTF-8') ?>">
            <div id="tagList" class="tag-list">
            </div>
            <div class="tag-input-wrapper">
              <input type="text" id="tagInput" class="form-input" placeholder="输入标签名称，回车或点 + 添加" maxlength="20" value="" autocomplete="off">
              <button type="button" id="addTagBtn" class="tag-add-btn" title="添加标签">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="12" y1="5" x2="12" y2="19"></line>
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
              </button>
            </div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">
            <span>正文</span>
            <span class="form-hint" style="display:none;">在光标位置插入图片；工具栏可设置字号、行距、加粗、下划线、颜色等</span>
          </label>
          <!-- 工具栏插在 #editor 之前，须用外层包裹，否则字号等样式选不到 .ql-toolbar -->
          <div class="post-edit-quill-wrap">
            <div id="editor"></div>
          </div>
          <div class="form-counter">
            <span id="contentCounter">0</span> / 约 50000 字（纯文本计）
          </div>
        </div>

        <div class="form-group" id="coverImageSection">
          <label class="form-label">
            <span>列表封面图</span>
            <span class="form-hint">上传并裁剪（约 4:3），用于社区列表缩略图</span>
          </label>
          <div class="post-edit-cover-actions">
            <input type="file" id="coverCropFileInput" accept="image/jpeg,image/png,image/webp,image/gif" class="post-edit-crop-file" hidden>
            <button type="button" class="btn btn-secondary" id="coverUploadCropBtn">上传并裁剪封面</button>
          </div>
          <div id="coverImageList" class="cover-image-list" style="display: flex; flex-wrap: wrap; gap: 12px;"></div>
          <button type="button" class="btn btn-secondary" id="removeCoverBtn" style="display: none; margin-top: 8px;">取消封面选择</button>
        </div>

        <!-- 预览封面和卡片 -->
        <div class="form-group post-edit-community-preview" id="postCommunityPreview">
          <label class="form-label">
            <span>封面和卡片预览</span>
            <span class="form-hint">社区列表中的显示效果</span>
          </label>
          <div class="post-grid post-edit-preview-grid-narrow">
            <a class="post-card post-edit-preview-card" id="previewCardNarrow" href="#" tabindex="-1" aria-hidden="true">
              <div class="post-card-image placeholder" data-preview-img-wrap>
                <div class="placeholder-text" data-preview-ph>预览</div>
              </div>
              <div class="post-card-content">
                <div class="post-card-title" data-preview-title>标题预览</div>
                <p class="post-card-excerpt" data-preview-excerpt style="display:none"></p>
                <div class="post-card-tags" data-preview-tags style="display:none"></div>
                <div class="post-card-footer">
                  <div class="post-card-author">
                    <span class="post-card-avatar"><?= $previewAvatarHtml ?></span>
                    <span class="post-card-username"><?= $previewUsernameEsc ?></span>
                  </div>
                  <div class="post-card-likes">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
                    </svg>
                    <span>0</span>
                  </div>
                </div>
              </div>
            </a>
          </div>
        </div>

        <div class="form-actions">
          <a class="btn btn-secondary" href="<?= $isEditMode ? '/creator.php' : '/forum.php' ?>">取消</a>
          <?php if (!$isEditMode || ($isEditMode && $post['status'] == 2)): // 新建或编辑草稿时显示保存草稿按钮 ?>
            <button class="btn btn-secondary" type="submit" name="save_as_draft" value="1">
              <span>保存草稿</span>
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                <polyline points="17 21 17 13 7 13 7 21"></polyline>
                <polyline points="7 3 7 8 15 8"></polyline>
              </svg>
            </button>
          <?php endif; ?>
          <button class="btn btn-primary" type="submit" name="publish" value="1">
            <span><?= $isEditMode ? '更新内容' : '发布内容' ?></span>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <line x1="22" y1="2" x2="11" y2="13"></line>
              <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
            </svg>
          </button>
        </div>
      </form>

      <div id="coverCropModal" class="post-edit-crop-modal" hidden>
        <div class="post-edit-crop-backdrop" data-cover-crop-close></div>
        <div class="post-edit-crop-dialog" role="dialog" aria-modal="true" aria-labelledby="coverCropTitle">
          <div class="post-edit-crop-head" id="coverCropTitle">裁剪封面（约 4:3）</div>
          <div class="post-edit-crop-body">
            <img id="coverCropImg" alt="">
          </div>
          <div class="post-edit-crop-foot">
            <button type="button" class="btn btn-secondary" id="coverCropCancel">取消</button>
            <button type="button" class="btn btn-primary" id="coverCropApply">上传并设为封面</button>
          </div>
        </div>
      </div>

      <div id="mathSymbolsPanel" class="editor-floating-panel" style="display:none;position:fixed;z-index:10002;"></div>
    </div>

    <?php require __DIR__ . '/partials/footer.php'; ?>
  </div>

  <script src="/assets/app.js?v=<?= filemtime(__DIR__.'/assets/app.js') ?>"></script>
  <script src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js"></script>
  <script src="/assets/vendor/quill-1.3.7/quill.js?v=<?= filemtime(__DIR__.'/assets/vendor/quill-1.3.7/quill.js') ?>"></script>
  <script src="/assets/editor.js?v=<?= filemtime(__DIR__.'/assets/editor.js') ?>"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <script src="/assets/post_note_preview.js?v=<?= filemtime(__DIR__.'/assets/post_note_preview.js') ?>"></script>
  <script>
  // 标签管理
  (function() {
    var tagInput = document.getElementById('tagInput');
    var tagsHidden = document.getElementById('tagsHiddenField');
    var tagList = document.getElementById('tagList');
    var addTagBtn = document.getElementById('addTagBtn');
    var tags = [];

    // 初始化：从隐藏域加载标签（visible 的 #tagInput 仅用于输入，不参与提交）
    (function initTags() {
      if (tagsHidden && tagsHidden.value) {
        tags = tagsHidden.value.split(/\s*[,，]\s*/).map(function(t) { return t.trim(); }).filter(function(t) { return t; });
        renderTags();
      }
    })();

    function renderTags() {
      if (!tagList) return;
      tagList.innerHTML = tags.map(function(tag, index) {
        return '<span class="tag-item">' +
          '<span class="tag-text">' + escapeHtml(tag) + '</span>' +
          '<button type="button" class="tag-remove" data-index="' + index + '" title="删除标签">' +
          '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
          '<line x1="18" y1="6" x2="6" y2="18"></line>' +
          '<line x1="6" y1="6" x2="18" y2="18"></line>' +
          '</svg></button></span>';
      }).join('');

      if (tagsHidden) {
        tagsHidden.value = tags.join(', ');
      }

      if (typeof window.refreshPostCommunityPreview === 'function') {
        window.refreshPostCommunityPreview();
      }

      // 绑定删除按钮事件
      tagList.querySelectorAll('.tag-remove').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
          e.preventDefault();
          var index = parseInt(this.getAttribute('data-index'));
          tags.splice(index, 1);
          renderTags();
        });
      });
    }

    function escapeHtml(text) {
      var div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }

    function addTag(tagName) {
      tagName = tagName.trim();
      if (!tagName) return;
      if (tags.indexOf(tagName) === -1) {
        tags.push(tagName);
        renderTags();
      }
      if (tagInput) tagInput.value = '';
    }

    window.syncPostNoteTagsBeforeSubmit = function() {
      if (tagsHidden) tagsHidden.value = tags.join(', ');
    };

    if (addTagBtn) {
      addTagBtn.addEventListener('click', function(e) {
        e.preventDefault();
        if (tagInput) addTag(tagInput.value);
      });
    }

    if (tagInput) {
      tagInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ',') {
          e.preventDefault();
          addTag(this.value);
        }
      });
    }
  })();

  // 正文字数（纯文本近似）
  (function() {
    var contentCounter = document.getElementById('contentCounter');
    function updateCounter() {
      var q = window.sownQuillEditor;
      if (!q || !contentCounter) return;
      var t = q.getText() || '';
      var length = t.replace(/\s+$/g, '').length;
      contentCounter.textContent = length;
      if (length > 48000) {
        contentCounter.style.color = '#d32f2f';
      } else if (length > 40000) {
        contentCounter.style.color = '#f57c00';
      } else {
        contentCounter.style.color = 'var(--muted)';
      }
    }
    function bind() {
      if (!window.sownQuillEditor || !contentCounter) return false;
      window.sownQuillEditor.on('text-change', updateCounter);
      updateCounter();
      return true;
    }
    if (!bind()) {
      var n = 0;
      var id = setInterval(function() {
        n++;
        if (bind() || n > 100) clearInterval(id);
      }, 50);
    }
  })();

  // AJAX表单提交
  (function() {
    var form = document.getElementById('postNoteForm');
    if (!form) return;
    
    form.addEventListener('submit', async function(e) {
      e.preventDefault();

      // 获取用户点击的提交按钮（必须在任何 disabled 之前读取，否则不会进入 FormData）
      var submitBtn = e.submitter;
      if (!submitBtn) {
        submitBtn = form.querySelector('button[type="submit"]:focus') || form.querySelector('button[type="submit"]');
      }
      var textSpan = submitBtn ? submitBtn.querySelector('span') : null;

      if (typeof window.sownSyncQuillToContentInput === 'function') {
        var sync = window.sownSyncQuillToContentInput();
        if (!sync.ok) {
          window.showAppAlert(sync.msg || '请检查正文');
          return;
        }
      }
      
      try {
        if (typeof window.syncPostNoteTagsBeforeSubmit === 'function') {
          window.syncPostNoteTagsBeforeSubmit();
        }
        var intentEl = document.getElementById('postSubmitIntent');
        if (intentEl && submitBtn) {
          intentEl.value = submitBtn.getAttribute('name') === 'save_as_draft' ? 'draft' : 'publish';
        }
        // 在按钮仍为 enabled 时构造 FormData；disabled 的提交钮不会进入 POST，会导致草稿被当成发布
        var formData;
        try {
          formData = submitBtn ? new FormData(form, submitBtn) : new FormData(form);
        } catch (err) {
          formData = new FormData(form);
        }
        if (submitBtn && submitBtn.getAttribute('name')) {
          formData.set(submitBtn.getAttribute('name'), submitBtn.value || '1');
        }

        if (textSpan) {
          submitBtn.dataset.originalText = textSpan.textContent;
          textSpan.textContent = '提交中...';
        }
        form.querySelectorAll('button[type="submit"]').forEach(function(b) {
          b.disabled = true;
        });

        var response = await fetch('/post_note_post.php', {
          method: 'POST',
          body: formData,
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        });
        
        var result = await response.json();
        
        if (result.ok) {
          if (result.data && result.data.url) {
            window.location.href = result.data.url;
          } else {
            window.location.href = '/forum.php';
          }
        } else {
          if (result.code === 'LOGIN' && window.openLoginModal) {
            window.openLoginModal('/login.php');
            form.querySelectorAll('button[type="submit"]').forEach(function(b) {
              b.disabled = false;
            });
            if (textSpan) textSpan.textContent = submitBtn.dataset.originalText || '提交';
            return;
          }
          window.showAppAlert(result.msg || '发布失败，请重试');
          form.querySelectorAll('button[type="submit"]').forEach(function(b) {
            b.disabled = false;
          });
          if (textSpan) textSpan.textContent = submitBtn.dataset.originalText || '提交';
        }
      } catch (error) {
        console.error('Submit error:', error);
        window.showAppAlert('网络错误，请重试');
        form.querySelectorAll('button[type="submit"]').forEach(function(b) {
          b.disabled = false;
        });
        if (submitBtn && textSpan) textSpan.textContent = submitBtn.dataset.originalText || '提交';
      }
    });
  })();

  // ============================================
  // 未保存提醒
  // ============================================
  (function() {
    var form = document.getElementById('postNoteForm');
    if (!form) return;

    var titleInput = form.querySelector('input[name="title"]');
    var tagHidden = document.getElementById('tagsHiddenField');
    var coverInput = document.getElementById('coverImageInput');
    var quill = window.sownQuillEditor;

    // 记录初始状态
    var initialTitle = titleInput ? titleInput.value : '';
    var initialContent = quill ? quill.root.innerHTML : '';
    var initialTags = tagHidden ? tagHidden.value : '';
    var initialCover = coverInput ? coverInput.value : '';

    function hasChanges() {
      if (titleInput && titleInput.value !== initialTitle) return true;
      if (tagHidden && tagHidden.value !== initialTags) return true;
      if (coverInput && coverInput.value !== initialCover) return true;
      if (quill) {
        var currentHtml = quill.root.innerHTML;
        // 比较纯文本或 HTML，忽略 Quill 的零宽字符等差异
        if (currentHtml.replace(/\u200B/g, '') !== initialContent.replace(/\u200B/g, '')) return true;
      }
      return false;
    }

    // 提交时标记为已保存，避免 beforeunload 弹窗
    form.addEventListener('submit', function() {
      window._postNoteClean = true;
    });

    // 关闭/刷新页面时提醒
    window.addEventListener('beforeunload', function(e) {
      if (window._postNoteClean) return;
      if (!hasChanges()) return;
      e.preventDefault();
      e.returnValue = '';
    });

    // "取消"按钮 — 有修改时弹窗确认
    var cancelLink = form.querySelector('.form-actions a.btn-secondary');
    if (cancelLink) {
      cancelLink.addEventListener('click', function(e) {
        if (window._postNoteClean) return;
        if (!hasChanges()) return;
        e.preventDefault();
        var href = this.getAttribute('href');

        var draftBtn = form.querySelector('button[name="save_as_draft"]');
        var msg = draftBtn
          ? '你有未保存的修改，是否先保存草稿？'
          : '你有未保存的修改，确定要离开吗？';

        // 使用全站统一弹窗样式
        var dialog = document.getElementById('unsavedDialog');
        if (!dialog) {
          dialog = document.createElement('div');
          dialog.id = 'unsavedDialog';
          dialog.innerHTML =
            '<div class="unsaved-backdrop">' +
              '<div class="unsaved-dialog" role="dialog" aria-modal="true">' +
                '<div class="unsaved-header"><h3 class="unsaved-title"></h3></div>' +
                '<div class="unsaved-body"></div>' +
                '<div class="unsaved-footer">' +
                  '<button type="button" class="btn btn-secondary unsaved-cancel-btn"></button>' +
                  '<button type="button" class="btn primary unsaved-confirm-btn"></button>' +
                '</div>' +
              '</div>' +
            '</div>';
          document.body.appendChild(dialog);

          // 点击遮罩关闭（等同取消）
          dialog.querySelector('.unsaved-backdrop').addEventListener('click', function(e) {
            if (e.target === this) dialog.style.display = 'none';
          });
        }

        var titleEl = dialog.querySelector('.unsaved-title');
        var bodyEl = dialog.querySelector('.unsaved-body');
        var cancelBtn = dialog.querySelector('.unsaved-cancel-btn');
        var confirmBtn = dialog.querySelector('.unsaved-confirm-btn');

        titleEl.textContent = '尚未保存';
        bodyEl.textContent = msg;
        cancelBtn.textContent = '不保存';
        confirmBtn.textContent = draftBtn ? '保存草稿' : '离开';

        // 移除旧监听，绑定新
        var newCancel = cancelBtn.cloneNode(true);
        cancelBtn.parentNode.replaceChild(newCancel, cancelBtn);
        var newConfirm = confirmBtn.cloneNode(true);
        confirmBtn.parentNode.replaceChild(newConfirm, confirmBtn);

        newCancel.addEventListener('click', function() {
          dialog.style.display = 'none';
          // "不保存" → 直接离开
          window.location.href = href;
        });

        newConfirm.addEventListener('click', function() {
          dialog.style.display = 'none';
          if (draftBtn) {
            window._postNoteClean = true;
            draftBtn.click();
          } else {
            window.location.href = href;
          }
        });

        dialog.style.display = '';
      });
    }
  })();
  </script>

  <style>
  .unsaved-backdrop {
    position: fixed;
    inset: 0;
    z-index: 100080;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(0,0,0,0.45);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
  }
  .unsaved-dialog {
    width: 100%;
    max-width: 420px;
    background: var(--pure-white, #fff);
    border-radius: var(--r-container, 12px);
    border: 1px solid var(--light-gray, #e5e5e5);
    overflow: hidden;
  }
  .unsaved-header {
    padding: 24px 24px 0;
  }
  .unsaved-title {
    margin: 0;
    font-size: var(--fs-body-lg, 16px);
    font-weight: var(--fw-semibold, 600);
    color: var(--pure-black, #000);
  }
  .unsaved-body {
    padding: 12px 24px 24px;
    font-size: var(--fs-caption, 14px);
    color: var(--mid-gray, #525252);
    line-height: 1.5;
  }
  .unsaved-footer {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    padding: 0 24px 24px;
  }
  </style>
</body>
</html>

