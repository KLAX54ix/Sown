<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/auth.php';

$isAjax = ($_POST['_ajax'] ?? '') === '1';

function ajaxOrRedirect($ok, $msg, $redirectUrl) {
  global $isAjax;
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $ok, 'msg' => $msg]);
    exit;
  }
  header('Location: ' . $redirectUrl);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  ajaxOrRedirect(false, '请求方法错误', '/settings.php?err=method');
}

// 需要登录
if (!is_logged_in()) {
  ajaxOrRedirect(false, '请先登录', login_url('/settings.php'));
}

$currentUser = current_user();
if (!$currentUser) {
  ajaxOrRedirect(false, '请先登录', '/login.php');
}

if (!csrf_check($_POST['csrf'] ?? '')) {
  ajaxOrRedirect(false, 'CSRF验证失败', '/settings.php?err=csrf');
}

// 检查文件上传
if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
  ajaxOrRedirect(false, '请选择文件', '/settings.php?err=no_file');
}

$file = $_FILES['avatar'];
$maxSize = 2 * 1024 * 1024; // 2MB
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];

// 验证文件大小
if ($file['size'] > $maxSize) {
  ajaxOrRedirect(false, '文件大小不能超过2MB', '/settings.php?err=too_large');
}

// 验证文件类型
$mimeType = null;

// 优先使用 finfo（如果可用）
if (function_exists('finfo_open')) {
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mimeType = finfo_file($finfo, $file['tmp_name']);
  finfo_close($finfo);
}
// 回退到 getimagesize
elseif (function_exists('getimagesize')) {
  $imageInfo = @getimagesize($file['tmp_name']);
  if ($imageInfo !== false) {
    $mimeType = $imageInfo['mime'];
  }
}
// 最后回退到 $_FILES 的 type
else {
  $mimeType = $file['type'];
}

if (!$mimeType || !in_array($mimeType, $allowedTypes)) {
  ajaxOrRedirect(false, '只支持 JPG、PNG、GIF 格式', '/settings.php?err=invalid_type');
}

// 创建上传目录
$uploadDir = __DIR__ . '/uploads/avatars/';
if (!is_dir($uploadDir)) {
  mkdir($uploadDir, 0755, true);
}

// 生成文件名（用户ID.jpg）
$filename = (int)$currentUser['id'] . '.jpg';
$targetPath = $uploadDir . $filename;

// 处理图片（统一转换为JPG）
try {
  $image = null;
  switch ($mimeType) {
    case 'image/jpeg':
      $image = imagecreatefromjpeg($file['tmp_name']);
      break;
    case 'image/png':
      $image = imagecreatefrompng($file['tmp_name']);
      break;
    case 'image/gif':
      $image = imagecreatefromgif($file['tmp_name']);
      break;
  }

  if (!$image) {
    ajaxOrRedirect(false, '上传失败，请重试', '/settings.php?err=upload_failed');
  }

  // 创建正方形缩略图（200x200）
  $size = min(imagesx($image), imagesy($image));
  $thumb = imagecreatetruecolor(200, 200);

  // 保持透明（PNG/GIF）
  imagealphablending($thumb, false);
  imagesavealpha($thumb, true);
  $transparent = imagecolorallocatealpha($thumb, 255, 255, 255, 127);
  imagefill($thumb, 0, 0, $transparent);

  // 居中裁剪
  $srcX = (imagesx($image) - $size) / 2;
  $srcY = (imagesy($image) - $size) / 2;
  imagecopyresampled($thumb, $image, 0, 0, $srcX, $srcY, 200, 200, $size, $size);

  // 保存为JPG
  imagejpeg($thumb, $targetPath, 90);
  imagedestroy($image);
  imagedestroy($thumb);

  if ($isAjax) {
    $avatarUrl = '/uploads/avatars/' . $filename . '?v=' . time();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => ['avatar_url' => $avatarUrl]]);
    exit;
  }

  // 跳转回个人主页
  header('Location: /user.php?id=' . (int)$currentUser['id']);
  exit;
} catch (Throwable $e) {
  ajaxOrRedirect(false, '服务器错误，请稍后重试', '/settings.php?err=server');
}

