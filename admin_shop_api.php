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
    case 'get':
      $itemId = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
      if ($itemId <= 0) {
        echo json_encode(['ok' => false, 'msg' => '参数错误']);
        exit;
      }
      $st = $pdo->prepare("SELECT * FROM shop_item WHERE id = ? LIMIT 1");
      $st->execute([$itemId]);
      $item = $st->fetch();
      if (!$item) {
        echo json_encode(['ok' => false, 'msg' => '商品不存在']);
        exit;
      }
      echo json_encode(['ok' => true, 'data' => $item]);
      break;

    case 'create':
      $title = isset($_POST['title']) ? trim((string)$_POST['title']) : '';
      $description = isset($_POST['description']) ? trim((string)$_POST['description']) : '';
      $cost = isset($_POST['cost']) ? (int)$_POST['cost'] : 0;
      $icon = isset($_POST['icon']) ? trim((string)$_POST['icon']) : '';
      $image = isset($_POST['image']) ? trim((string)$_POST['image']) : '';
      $sortOrder = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;
      $enabled = isset($_POST['enabled']) ? (int)$_POST['enabled'] : 1;
      $isPhysical = isset($_POST['is_physical']) ? (int)$_POST['is_physical'] : 0;
      $repeatable = isset($_POST['repeatable']) ? (int)$_POST['repeatable'] : 0;
      $isTitle = isset($_POST['is_title']) ? (int)$_POST['is_title'] : 0;

      if ($title === '') {
        echo json_encode(['ok' => false, 'msg' => '商品名称不能为空']);
        exit;
      }

      $st = $pdo->prepare("INSERT INTO shop_item (title, description, cost, icon, image, sort_order, enabled, is_physical, repeatable, is_title) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
      $st->execute([$title, $description, $cost, $icon, $image, $sortOrder, $enabled, $isPhysical, $repeatable, $isTitle]);
      $newId = (int)$pdo->lastInsertId();
      admin_log('shop_create', 'shop_item', $newId, '创建商品: ' . $title);
      echo json_encode(['ok' => true, 'msg' => '创建成功']);
      break;

    case 'update':
      $itemId = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
      $title = isset($_POST['title']) ? trim((string)$_POST['title']) : '';
      $description = isset($_POST['description']) ? trim((string)$_POST['description']) : '';
      $cost = isset($_POST['cost']) ? (int)$_POST['cost'] : 0;
      $icon = isset($_POST['icon']) ? trim((string)$_POST['icon']) : '';
      $image = isset($_POST['image']) ? trim((string)$_POST['image']) : '';
      $sortOrder = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;
      $enabled = isset($_POST['enabled']) ? (int)$_POST['enabled'] : 1;
      $isPhysical = isset($_POST['is_physical']) ? (int)$_POST['is_physical'] : 0;
      $repeatable = isset($_POST['repeatable']) ? (int)$_POST['repeatable'] : 0;
      $isTitle = isset($_POST['is_title']) ? (int)$_POST['is_title'] : 0;

      if ($itemId <= 0 || $title === '') {
        echo json_encode(['ok' => false, 'msg' => '参数错误']);
        exit;
      }

      $st = $pdo->prepare("UPDATE shop_item SET title = ?, description = ?, cost = ?, icon = ?, image = ?, sort_order = ?, enabled = ?, is_physical = ?, repeatable = ?, is_title = ? WHERE id = ?");
      $st->execute([$title, $description, $cost, $icon, $image, $sortOrder, $enabled, $isPhysical, $repeatable, $isTitle, $itemId]);
      admin_log('shop_update', 'shop_item', $itemId, '编辑商品: ' . $title);
      echo json_encode(['ok' => true, 'msg' => '更新成功']);
      break;

    case 'delete':
      $itemId = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
      if ($itemId <= 0) {
        echo json_encode(['ok' => false, 'msg' => '参数错误']);
        exit;
      }
      $st = $pdo->prepare("DELETE FROM shop_item WHERE id = ?");
      $st->execute([$itemId]);
      admin_log('shop_delete', 'shop_item', $itemId, '删除商品 #' . $itemId);
      echo json_encode(['ok' => true, 'msg' => '已删除']);
      break;

    default:
      echo json_encode(['ok' => false, 'msg' => '未知操作']);
  }
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'msg' => '服务器错误']);
  error_log('admin_shop_api: ' . $e->getMessage());
}
