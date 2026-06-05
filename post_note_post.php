<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/tag.php';
require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/moderation.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => '请求方法错误']);
  } else {
    header('Location: /post_note.php?err=method');
  }
  exit;
}

// 检查是否是AJAX请求
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// 需要登录
if (!is_logged_in()) {
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => '请先登录', 'code' => 'LOGIN']);
  } else {
    require_login_or_redirect('/post_note.php');
  }
  exit;
}

$user = current_user();
if (!$user) {
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => '请先登录', 'code' => 'LOGIN']);
  } else {
    header('Location: /login.php?next=' . urlencode('/post_note.php'));
  }
  exit;
}

// 检查是否编辑现有内容
$postId = (int)($_POST['post_id'] ?? 0);
$isEditMode = $postId > 0;
$existingPost = null;

if ($isEditMode) {
  $pdo = db();
  // 验证帖子是否存在且属于当前用户
  $st = $pdo->prepare("SELECT * FROM post WHERE id = ? AND user_id = ?");
  $st->execute([$postId, $user['id']]);
  $existingPost = $st->fetch();

  if (!$existingPost) {
    if ($isAjax) {
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok' => false, 'msg' => '内容不存在或无权编辑']);
    } else {
      header('Location: /post_note.php?err=not_found');
    }
    exit;
  }
}

if (!csrf_check($_POST['csrf'] ?? '')) {
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => 'CSRF验证失败，请刷新页面后重试']);
  } else {
    header('Location: /post_note.php?err=csrf');
  }
  exit;
}

$title = trim($_POST['title'] ?? '');
$rawContent = $_POST['content'] ?? '';

// 参数验证
if ($title === '') {
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => '请输入标题']);
  } else {
    header('Location: /post_note.php?err=title');
  }
  exit;
}

if (mb_strlen($title) > 200) {
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => '标题长度不能超过200个字符']);
  } else {
    header('Location: /post_note.php?err=title_len');
  }
  exit;
}

if (!is_string($rawContent) || trim($rawContent) === '') {
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => '请输入内容']);
  } else {
    header('Location: /post_note.php?err=content');
  }
  exit;
}

$maxHtmlLen = 500000;
if (mb_strlen($rawContent) > $maxHtmlLen) {
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => '正文 HTML 过长，请精简后重试']);
  } else {
    header('Location: /post_note.php?err=content_len');
  }
  exit;
}

$contentHtml = sanitize_post_content_html($rawContent);
$plainForLimit = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($contentHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
if ($plainForLimit === '') {
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => '请输入有效正文内容']);
  } else {
    header('Location: /post_note.php?err=content');
  }
  exit;
}

$maxPlainLen = 50000;
if (mb_strlen($plainForLimit) > $maxPlainLen) {
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => '正文纯文本长度不能超过 ' . $maxPlainLen . ' 个字符']);
  } else {
    header('Location: /post_note.php?err=content_len');
  }
  exit;
}

// 违规检测：命中后将该条内容替换为统一屏蔽文案
$moderation = moderation_filter_post($title, $contentHtml);
$title = $moderation['title'];
$contentHtml = $moderation['content_html'];

// 从正文提取图片路径（列表封面用）；可选封面优先
$extractedPaths = post_extract_uploaded_image_paths($contentHtml);
$coverRaw = trim((string)($_POST['cover_image'] ?? ''));
if ($coverRaw !== '') {
  $coverNorm = '/' . ltrim($coverRaw, '/');
  if (!in_array($coverNorm, $extractedPaths, true)) {
    $extractedPaths = array_values(array_unique(array_merge([$coverNorm], $extractedPaths)));
  }
}
$finalImagePaths = post_reorder_images_for_cover($extractedPaths, $coverRaw !== '' ? $coverRaw : null);
$finalImageJson = json_encode($finalImagePaths, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// 确定状态：保存为草稿还是发布（save_as_draft 按钮 + submit_intent 双保险，避免 AJAX 未带上按钮名时误发布）
$intent = trim((string)($_POST['submit_intent'] ?? ''));
$isDraft = isset($_POST['save_as_draft']) || $intent === 'draft';
$status = $isDraft ? 2 : 1; // 2=草稿, 1=发布

// 如果是编辑已发布内容并保存为草稿，状态变为草稿
// 如果是编辑草稿并发布，状态变为发布
if ($isEditMode && $existingPost) {
  if ($isDraft) {
    $status = 2; // 保存为草稿
  } elseif (isset($_POST['publish']) || $intent === 'publish') {
    $status = 1; // 发布
  } else {
    // 默认保持原状态（编辑但未指定）
    $status = (int)$existingPost['status'];
  }
}

// 审核状态：非管理员用户发布的内容需要审核
$isAdmin = isset($user['role']) && $user['role'] === 'admin';
$reviewStatus = ($status === 1 && !$isAdmin) ? 1 : 0; // 1=pending, 0=approved

$pdo = db();

try {
  if ($isEditMode) {
    // 更新现有内容
    $st = $pdo->prepare(
      "UPDATE post SET title = ?, content = ?, image = ?, status = ?, review_status = ?, updated_at = NOW()
       WHERE id = ? AND user_id = ?"
    );
    $st->execute([$title, $contentHtml, $finalImageJson, $status, $reviewStatus, $postId, $user['id']]);

    // 清理旧标签
    $st = $pdo->prepare("DELETE FROM post_tag_relation WHERE post_id = ?");
    $st->execute([$postId]);
  } else {
    // 插入新内容
    $st = $pdo->prepare(
      "INSERT INTO post (user_id, title, content, image, status, review_status, like_count, comment_count, favorite_count, created_at, updated_at)
       VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, NOW(), NOW())"
    );
    $st->execute([$user['id'], $title, $contentHtml, $finalImageJson, $status, $reviewStatus]);

    $postId = (int)$pdo->lastInsertId();
  }

  // 处理标签：空表示清空；须始终 set_post_tags 以写入关联并刷新 post_tag.post_count
  $tagsInput = trim($_POST['tags'] ?? '');
  $tagNames = $tagsInput !== ''
    ? array_values(array_unique(array_filter(array_map(
        'trim',
        preg_split('/[,，]/', $tagsInput, -1, PREG_SPLIT_NO_EMPTY)
      ))))
    : [];
  set_post_tags($postId, $tagNames);

  if ($status === 1) {
    require_once __DIR__ . '/app/points.php';
    points_after_post_published((int)$user['id']);
  }

  // 确定重定向URL和成功消息
  $isDraft = $status == 2;
  $message = $isDraft ? '草稿已保存' : ($isEditMode ? '内容已更新' : '发布成功');
  // 始终跳转到创作者平台
  $redirectUrl = "/creator.php?msg=" . ($isEditMode ? 'saved' : ($isDraft ? 'draft_saved' : 'published'));

  if ($isAjax) {
    // AJAX请求：返回JSON用于弹窗显示
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      'ok' => true,
      'msg' => $message,
      'data' => [
        'post_id' => $postId,
        'url' => $redirectUrl
      ]
    ]);
    exit;
  }

  // 非AJAX请求：跳转
  header("Location: " . $redirectUrl);
  exit;
} catch (Throwable $e) {
  http_response_code(500);
  error_log('Post creation error: ' . $e->getMessage());
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => '服务器错误，请稍后重试']);
  } else {
    header('Location: /post_note.php?err=server');
  }
  exit;
}

