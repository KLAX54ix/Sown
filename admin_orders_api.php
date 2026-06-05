<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/admin.php';
require_once __DIR__ . '/app/notification.php';

admin_ensure_schema();

$pdo = db();

// CSV 导出走 GET，无需 CSRF
$action = isset($_GET['action']) ? (string)$_GET['action'] : '';
if ($action === 'export_csv') {
  require_admin();

  $statusFilter = isset($_GET['status']) ? (int)$_GET['status'] : -1;

  $where = '';
  $params = [];
  if ($statusFilter >= 0 && $statusFilter <= 3) {
    $where = 'WHERE o.status = ?';
    $params[] = $statusFilter;
  }

  $sql = "SELECT o.*, u.username
          FROM shop_order o
          LEFT JOIN user u ON u.id = o.user_id
          $where
          ORDER BY o.id DESC";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $orders = $st->fetchAll();

  $statusLabels = ['待发货', '已发货', '已完成', '已取消'];

  // UTF-8 BOM 头
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="orders_' . date('Ymd') . '.csv"');
  $out = fopen('php://output', 'w');
  fprintf($out, "\xEF\xBB\xBF");
  fputcsv($out, ['订单ID', '商品名称', '用户名', '收件人', '手机号', '地址', '积分', '状态', '物流单号', '下单时间']);

  foreach ($orders as $o) {
    fputcsv($out, [
      $o['id'],
      $o['item_title'],
      $o['username'] ?? '用户#' . $o['user_id'],
      $o['recipient_name'],
      $o['recipient_phone'],
      $o['recipient_address'],
      $o['cost_points'],
      $statusLabels[(int)$o['status']] ?? '未知',
      $o['tracking_number'] ?? '',
      $o['created_at'],
    ]);
  }
  fclose($out);
  exit;
}

// POST 接口需要 CSRF
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
$orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;

// import_tracking 没有 order_id 参数，跳过检查
if ($action !== 'import_tracking' && $orderId <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'msg' => '参数错误']);
  exit;
}

try {
  switch ($action) {
    case 'status_update':
      // 查询当前状态
      $st = $pdo->prepare("SELECT status, item_title, user_id FROM shop_order WHERE id = ? LIMIT 1");
      $st->execute([$orderId]);
      $order = $st->fetch();
      if (!$order) {
        echo json_encode(['ok' => false, 'msg' => '订单不存在']);
        exit;
      }
      $cur = (int)$order['status'];
      $newStatus = -1;
      if ($cur === 0) {
        $newStatus = 1; // 待发货 → 已发货
        $trackingNumber = isset($_POST['tracking_number']) ? trim((string)$_POST['tracking_number']) : '';
        if ($trackingNumber === '') {
          echo json_encode(['ok' => false, 'msg' => '请填写物流单号']);
          exit;
        }
        $st = $pdo->prepare("UPDATE shop_order SET status = ?, tracking_number = ?, shipped_at = NOW() WHERE id = ?");
        $st->execute([$newStatus, $trackingNumber, $orderId]);
        // 发送通知给买家
        create_notification(
          (int)$order['user_id'],
          'shipped',
          $orderId,
          null,
          '您的商品「' . $order['item_title'] . '」已发货，物流单号：' . $trackingNumber
        );
      } elseif ($cur === 1) {
        $newStatus = 2; // 已发货 → 已完成
        $st = $pdo->prepare("UPDATE shop_order SET status = ? WHERE id = ?");
        $st->execute([$newStatus, $orderId]);
      }
      if ($newStatus < 0) {
        echo json_encode(['ok' => false, 'msg' => '当前状态不允许此操作']);
        exit;
      }
      admin_log('order_status', 'shop_order', $orderId, '订单状态更新: ' . $order['item_title']);
      echo json_encode(['ok' => true, 'msg' => '操作成功']);
      break;

    case 'status_cancel':
      $st = $pdo->prepare("SELECT status, item_title FROM shop_order WHERE id = ? LIMIT 1");
      $st->execute([$orderId]);
      $order = $st->fetch();
      if (!$order) {
        echo json_encode(['ok' => false, 'msg' => '订单不存在']);
        exit;
      }
      if ((int)$order['status'] !== 0) {
        echo json_encode(['ok' => false, 'msg' => '只能取消待发货订单']);
        exit;
      }
      $st = $pdo->prepare("UPDATE shop_order SET status = 3 WHERE id = ?");
      $st->execute([$orderId]);
      admin_log('order_cancel', 'shop_order', $orderId, '取消订单: ' . $order['item_title']);
      echo json_encode(['ok' => true, 'msg' => '已取消']);
      break;

    case 'import_tracking':
      if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'msg' => '请上传文件']);
        exit;
      }
      $tmpPath = $_FILES['csv_file']['tmp_name'];
      $origName = $_FILES['csv_file']['name'];
      $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
      if ($ext !== 'csv') {
        echo json_encode(['ok' => false, 'msg' => '请上传 CSV 格式的文件']);
        exit;
      }

      // 读取整个文件内容，支持 GBK/UTF-8 编码
      $content = file_get_contents($tmpPath);
      if ($content === false) {
        echo json_encode(['ok' => false, 'msg' => '无法读取文件']);
        exit;
      }
      // 移除 UTF-8 BOM
      $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
      // 检测并转换编码（Excel 保存的 CSV 常为 GBK）
      $enc = mb_detect_encoding($content, 'UTF-8, GBK, GB2312, GB18030, ISO-8859-1', true);
      if ($enc && $enc !== 'UTF-8') {
        $content = mb_convert_encoding($content, 'UTF-8', $enc);
      }

      // 按行解析 CSV
      $lines = explode("\n", $content);
      if (empty($lines)) {
        echo json_encode(['ok' => false, 'msg' => 'CSV 文件为空']);
        exit;
      }

      // 解析表头
      $headerLine = trim(array_shift($lines));
      $header = str_getcsv($headerLine);
      if (!$header || count($header) < 2) {
        echo json_encode(['ok' => false, 'msg' => 'CSV 格式错误：无法读取表头']);
        exit;
      }

      // 查找列索引
      $idIdx = null;
      $trackIdx = null;
      foreach ($header as $i => $col) {
        $col = trim($col);
        if ($col === '订单ID') $idIdx = $i;
        if ($col === '物流单号') $trackIdx = $i;
      }
      if ($idIdx === null) {
        $cols = implode(' / ', $header);
        echo json_encode(['ok' => false, 'msg' => "CSV 缺少\"订单ID\"列，当前表头: {$cols}"]);
        exit;
      }
      if ($trackIdx === null) {
        $cols = implode(' / ', $header);
        echo json_encode(['ok' => false, 'msg' => "CSV 缺少\"物流单号\"列，当前表头: {$cols}"]);
        exit;
      }

      // 逐行处理
      $success = 0;
      $errors = [];
      $stCheck = $pdo->prepare("SELECT id, status, item_title FROM shop_order WHERE id = ? LIMIT 1");
      $stUpdate = $pdo->prepare("UPDATE shop_order SET tracking_number = ?, status = 1, shipped_at = NOW() WHERE id = ? AND status = 0");
      foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $row = str_getcsv($line);
        if (count($row) <= max($idIdx, $trackIdx)) {
          $errors[] = '跳过无效行';
          continue;
        }
        $oid = (int)trim($row[$idIdx]);
        $track = trim($row[$trackIdx]);
        if ($oid <= 0 || $track === '') continue;
        $stCheck->execute([$oid]);
        $ord = $stCheck->fetch();
        if (!$ord) {
          $errors[] = "订单 #{$oid} 不存在";
          continue;
        }
        if ((int)$ord['status'] !== 0) {
          $errors[] = "订单 #{$oid} 状态不是待发货（当前: {$ord['status']}）";
          continue;
        }
        $stUpdate->execute([$track, $oid]);
        if ($stUpdate->rowCount() > 0) {
          $success++;
          admin_log('order_ship', 'shop_order', $oid, '批量导入发货: ' . $ord['item_title'] . ' 单号:' . $track);
        }
      }

      $msg = "成功导入 {$success} 条";
      if (!empty($errors)) {
        $msg .= '，' . count($errors) . ' 条跳过：' . implode('；', array_slice($errors, 0, 5));
        if (count($errors) > 5) $msg .= '…';
      }
      echo json_encode(['ok' => true, 'msg' => $msg]);
      break;

    default:
      echo json_encode(['ok' => false, 'msg' => '未知操作']);
  }
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'msg' => '服务器错误']);
  error_log('admin_orders_api: ' . $e->getMessage());
}
