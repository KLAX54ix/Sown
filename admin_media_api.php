<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/admin.php';

admin_ensure_schema();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'msg' => 'Method Not Allowed']);
  exit;
}

$token = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
if (!csrf_check($token)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'msg' => 'CSRF Forbidden']);
  exit;
}

require_admin();

$action = isset($_POST['action']) ? (string)$_POST['action'] : '';
$pdo = db();

try {
  switch ($action) {
    // ─── 文件列表（分页） ──────────────────────
    case 'file_list':
      $page = isset($_POST['page']) ? max(1, (int)$_POST['page']) : 1;
      $perPage = 48;
      $offset = ($page - 1) * $perPage;

      $st = $pdo->query("SELECT COUNT(*) AS c FROM media_file");
      $total = (int)$st->fetchColumn();

      $st = $pdo->prepare(
        "SELECT * FROM media_file ORDER BY created_at DESC LIMIT ? OFFSET ?"
      );
      $st->execute([$perPage, $offset]);
      $files = $st->fetchAll();

      echo json_encode([
        'ok' => true,
        'files' => $files,
        'total' => $total,
        'page' => $page,
        'perPage' => $perPage,
        'hasMore' => ($offset + $perPage) < $total,
      ]);
      break;

    // ─── 文件上传 ─────────────────────────────
    case 'file_upload':
      if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $code = isset($_FILES['file']) ? $_FILES['file']['error'] : -1;
        echo json_encode(['ok' => false, 'msg' => '上传失败 (code: ' . $code . ')']);
        exit;
      }

      $uploaded = $_FILES['file'];
      $origName = basename((string)$uploaded['name']);
      $tmpPath = (string)$uploaded['tmp_name'];
      $size = (int)$uploaded['size'];

      $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
      $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
      $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
      if (!in_array($ext, $allowedExts, true)) {
        echo json_encode(['ok' => false, 'msg' => '不支持的文件格式，仅支持 JPEG/PNG/GIF/WebP/SVG']);
        exit;
      }

      $maxSize = 10 * 1024 * 1024;
      if ($size > $maxSize) {
        echo json_encode(['ok' => false, 'msg' => '文件大小超过10MB限制']);
        exit;
      }

      // MIME 验证（使用 getimagesize，兼容无 fileinfo 扩展的环境）
      $detectedMime = '';
      $width = 0;
      $height = 0;
      if ($ext === 'svg') {
        $detectedMime = 'image/svg+xml';
      } else {
        $imgInfo = @getimagesize($tmpPath);
        if (!$imgInfo) {
          echo json_encode(['ok' => false, 'msg' => '文件类型无效或图片已损坏']);
          exit;
        }
        $detectedMime = (string)($imgInfo['mime'] ?? '');
        $width = (int)$imgInfo[0];
        $height = (int)$imgInfo[1];
      }
      $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
      if (!in_array($detectedMime, $allowedTypes, true)) {
        echo json_encode(['ok' => false, 'msg' => '文件类型无效']);
        exit;
      }

      $mediaDir = __DIR__ . '/uploads/media';
      if (!is_dir($mediaDir)) {
        @mkdir($mediaDir, 0755, true);
      }

      $ts = time();
      $rand = bin2hex(random_bytes(8));
      $newFilename = $ts . '_' . $rand . '.' . $ext;
      $dest = $mediaDir . '/' . $newFilename;

      if (!@move_uploaded_file($tmpPath, $dest)) {
        // move_uploaded_file 可能因跨文件系统或权限问题失败，尝试 copy + unlink
        if (!@copy($tmpPath, $dest)) {
          echo json_encode(['ok' => false, 'msg' => '文件保存失败']);
          exit;
        }
        @unlink($tmpPath);
      }

      $st = $pdo->prepare(
        "INSERT INTO media_file (folder_id, filename, original_name, mime_type, size, width, height) VALUES (NULL, ?, ?, ?, ?, ?, ?)"
      );
      $st->execute([$newFilename, $origName, $detectedMime, $size, $width, $height]);
      $fileId = (int)$pdo->lastInsertId();

      admin_log('media_upload', 'media_file', $fileId, '上传文件: ' . $origName);

      echo json_encode([
        'ok' => true,
        'msg' => '上传成功',
        'file' => [
          'id' => $fileId,
          'filename' => $newFilename,
          'original_name' => $origName,
          'url' => '/uploads/media/' . $newFilename,
        ],
      ]);
      break;

    // ─── 删除文件 ─────────────────────────────
    case 'file_delete':
      $fileId = isset($_POST['file_id']) ? (int)$_POST['file_id'] : 0;
      if ($fileId <= 0) {
        echo json_encode(['ok' => false, 'msg' => '参数错误']);
        exit;
      }
      $st = $pdo->prepare("SELECT * FROM media_file WHERE id = ? LIMIT 1");
      $st->execute([$fileId]);
      $file = $st->fetch();
      if (!$file) {
        echo json_encode(['ok' => false, 'msg' => '文件不存在']);
        exit;
      }
      $diskPath = __DIR__ . '/uploads/media/' . $file['filename'];
      if (file_exists($diskPath)) {
        @unlink($diskPath);
      }
      $st = $pdo->prepare("DELETE FROM media_file WHERE id = ?");
      $st->execute([$fileId]);
      admin_log('media_delete', 'media_file', $fileId, '删除文件: ' . $file['original_name']);
      echo json_encode(['ok' => true, 'msg' => '已删除']);
      break;

    // ─── 文件搜索 ─────────────────────────────
    case 'file_search':
      $q = isset($_POST['q']) ? trim((string)$_POST['q']) : '';
      if ($q === '') {
        echo json_encode(['ok' => true, 'files' => []]);
        exit;
      }
      $keyword = '%' . $q . '%';
      $st = $pdo->prepare(
        "SELECT * FROM media_file WHERE original_name LIKE ? ORDER BY created_at DESC LIMIT 50"
      );
      $st->execute([$keyword]);
      $files = $st->fetchAll();
      echo json_encode(['ok' => true, 'files' => $files]);
      break;

    default:
      echo json_encode(['ok' => false, 'msg' => '未知操作']);
  }
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'msg' => '服务器错误']);
  error_log('admin_media_api: ' . $e->getMessage());
}
