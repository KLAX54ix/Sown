<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/auth.php';

// 检查是否是 AJAX 请求
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
  } else {
    echo "Method Not Allowed";
  }
  exit;
}

// 需要登录
if (!is_logged_in()) {
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      'success' => false,
      'error' => '请先登录',
      'code' => 'LOGIN',
      'login' => login_url('/post_note.php')
    ]);
  } else {
    header('Location: ' . login_url('/post_note.php'));
  }
  exit;
}

$user = current_user();
if (!$user) {
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => '用户未找到']);
  } else {
    header('Location: /login.php');
  }
  exit;
}

// 检查 CSRF（对于 AJAX 请求，可以从 header 或 body 获取）
$csrfToken = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($csrfToken)) {
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'CSRF token 未提供']);
  } else {
    header('Location: /post_note.php?err=csrf');
  }
  exit;
}

if (!csrf_check($csrfToken)) {
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'CSRF验证失败']);
  } else {
    header('Location: /post_note.php?err=csrf');
  }
  exit;
}

// 检查文件上传
if (!isset($_FILES['image'])) {
  $errorMsg = '未检测到上传的文件';
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $errorMsg]);
  } else {
    header('Location: /post_note.php?err=image_upload_failed');
  }
  exit;
}

if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
  $uploadErrors = [
    UPLOAD_ERR_INI_SIZE => '图片大小超过服务器限制',
    UPLOAD_ERR_FORM_SIZE => '图片大小超过表单限制',
    UPLOAD_ERR_PARTIAL => '图片上传不完整',
    UPLOAD_ERR_NO_TMP_DIR => '临时目录不存在',
    UPLOAD_ERR_CANT_WRITE => '无法写入文件',
    UPLOAD_ERR_EXTENSION => '上传被扩展阻止',
    UPLOAD_ERR_NO_FILE => '未选择文件'
  ];
  $errorCode = $_FILES['image']['error'];
  $errorMsg = $uploadErrors[$errorCode] ?? '图片上传失败（错误代码：' . $errorCode . '）';
  
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $errorMsg]);
  } else {
    header('Location: /post_note.php?err=image_upload_failed');
  }
  exit;
}

$file = $_FILES['image'];
$maxSize = 5 * 1024 * 1024; // 5MB
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

// 验证文件大小
if ($file['size'] > $maxSize) {
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => '图片大小不能超过5MB']);
  } else {
    header('Location: /post_note.php?err=image_too_large');
  }
  exit;
}

// 验证文件类型
$mimeType = null;
if (function_exists('finfo_open')) {
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mimeType = finfo_file($finfo, $file['tmp_name']);
  finfo_close($finfo);
} elseif (function_exists('getimagesize')) {
  $imageInfo = @getimagesize($file['tmp_name']);
  if ($imageInfo !== false) {
    $mimeType = $imageInfo['mime'];
  }
} else {
  $mimeType = $file['type'];
}

if (!$mimeType || !in_array($mimeType, $allowedTypes)) {
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => '只支持 JPG、PNG、GIF、WEBP 格式的图片']);
  } else {
    header('Location: /post_note.php?err=image_invalid_type');
  }
  exit;
}

// 创建上传目录
$uploadDir = __DIR__ . '/uploads/posts/';
if (!is_dir($uploadDir)) {
  if (!mkdir($uploadDir, 0755, true)) {
    error_log('Failed to create upload directory: ' . $uploadDir);
    if ($isAjax) {
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['success' => false, 'error' => '上传目录创建失败']);
    } else {
      header('Location: /post_note.php?err=image_upload_failed');
    }
    exit;
  }
}

// 确保目录可写
if (!is_writable($uploadDir)) {
  error_log('Upload directory is not writable: ' . $uploadDir);
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => '上传目录不可写']);
  } else {
    header('Location: /post_note.php?err=image_upload_failed');
  }
  exit;
}

// 生成文件名（时间戳 + 随机字符串）
$extension = '';
switch ($mimeType) {
  case 'image/jpeg':
    $extension = '.jpg';
    break;
  case 'image/png':
    $extension = '.png';
    break;
  case 'image/gif':
    $extension = '.gif';
    break;
  case 'image/webp':
    $extension = '.webp';
    break;
}

$filename = time() . '_' . bin2hex(random_bytes(8)) . $extension;
$targetPath = $uploadDir . $filename;

// 移动文件
if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
  $errorDetails = [
    'tmp_name' => $file['tmp_name'],
    'target_path' => $targetPath,
    'tmp_exists' => file_exists($file['tmp_name']),
    'target_dir_exists' => is_dir($uploadDir),
    'target_dir_writable' => is_writable($uploadDir),
    'error' => error_get_last()
  ];
  error_log('Failed to move uploaded file: ' . json_encode($errorDetails));
  
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => '文件保存失败，请检查服务器配置']);
  } else {
    header('Location: /post_note.php?err=image_upload_failed');
  }
  exit;
}

// 确保文件权限正确
chmod($targetPath, 0644);

$imageUrl = '/uploads/posts/' . $filename;

// 返回 JSON 响应
if ($isAjax) {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'success' => true,
    'url' => $imageUrl
  ]);
} else {
  header('Location: /post_note.php?image=' . urlencode($imageUrl));
}
exit;

