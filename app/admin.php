<?php
declare(strict_types=1);

/**
 * 管理员后台辅助函数
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/** @var bool */
$GLOBALS['admin_schema_ready'] = false;

function admin_is_duplicate_schema_error(Throwable $e): bool {
  $m = $e->getMessage();
  if (strpos($m, '1060') !== false || stripos($m, 'Duplicate column') !== false) {
    return true;
  }
  if (strpos($m, '1050') !== false || stripos($m, 'duplicate') !== false) {
    return true;
  }
  return false;
}

function admin_ensure_schema(): void {
  if (!empty($GLOBALS['admin_schema_ready'])) {
    return;
  }
  $pdo = db();

  // user.role
  try {
    $pdo->exec("ALTER TABLE user ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'user'");
  } catch (Throwable $e) {
    if (!admin_is_duplicate_schema_error($e)) {
      error_log('admin_ensure_schema user.role: ' . $e->getMessage());
    }
  }

  // user.login_fail_count / user.login_locked_until
  try {
    $pdo->exec("ALTER TABLE user ADD COLUMN login_fail_count INT NOT NULL DEFAULT 0");
  } catch (Throwable $e) {
    if (!admin_is_duplicate_schema_error($e)) {
      error_log('admin_ensure_schema user.login_fail_count: ' . $e->getMessage());
    }
  }
  try {
    $pdo->exec("ALTER TABLE user ADD COLUMN login_locked_until DATETIME DEFAULT NULL");
  } catch (Throwable $e) {
    if (!admin_is_duplicate_schema_error($e)) {
      error_log('admin_ensure_schema user.login_locked_until: ' . $e->getMessage());
    }
  }

  // post.review_status
  try {
    $pdo->exec("ALTER TABLE post ADD COLUMN review_status TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=approved, 1=pending, 2=rejected'");
  } catch (Throwable $e) {
    if (!admin_is_duplicate_schema_error($e)) {
      error_log('admin_ensure_schema post.review_status: ' . $e->getMessage());
    }
  }

  // shop_item 表
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS shop_item (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      title VARCHAR(200) NOT NULL DEFAULT '',
      description VARCHAR(500) NOT NULL DEFAULT '',
      cost INT NOT NULL DEFAULT 0,
      icon VARCHAR(50) NOT NULL DEFAULT '',
      image VARCHAR(500) NOT NULL DEFAULT '',
      sort_order INT NOT NULL DEFAULT 0,
      enabled TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  // shop_item.is_physical
  try {
    $pdo->exec("ALTER TABLE shop_item ADD COLUMN is_physical TINYINT(1) NOT NULL DEFAULT 0");
  } catch (Throwable $e) {
    if (!admin_is_duplicate_schema_error($e)) {
      error_log('admin_ensure_schema shop_item.is_physical: ' . $e->getMessage());
    }
  }

  // shop_order 表
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS shop_order (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      item_id INT NOT NULL,
      item_title VARCHAR(200) NOT NULL,
      cost_points INT NOT NULL,
      recipient_name VARCHAR(100) NOT NULL,
      recipient_phone VARCHAR(50) NOT NULL,
      recipient_address VARCHAR(500) NOT NULL,
      tracking_number VARCHAR(100) NOT NULL DEFAULT '',
      status TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=pending, 1=shipped, 2=delivered, 3=cancelled',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      shipped_at DATETIME DEFAULT NULL,
      KEY idx_user (user_id),
      KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  // shop_item.repeatable
  try {
    $pdo->exec("ALTER TABLE shop_item ADD COLUMN repeatable TINYINT(1) NOT NULL DEFAULT 0");
  } catch (Throwable $e) {
    if (!admin_is_duplicate_schema_error($e)) {
      error_log('admin_ensure_schema shop_item.repeatable: ' . $e->getMessage());
    }
  }

  // shop_item.is_title
  try {
    $pdo->exec("ALTER TABLE shop_item ADD COLUMN is_title TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否为称号类虚拟商品'");
  } catch (Throwable $e) {
    if (!admin_is_duplicate_schema_error($e)) {
      error_log('admin_ensure_schema shop_item.is_title: ' . $e->getMessage());
    }
  }

  // 迁移：已有商品 is_title 设置
  try {
    $pdo->exec("UPDATE shop_item SET is_title = 1 WHERE id = 1 AND is_title = 0");
  } catch (Throwable $e) {
    error_log('admin_ensure_schema migrate is_title: ' . $e->getMessage());
  }

  // shop_order.quantity
  try {
    $pdo->exec("ALTER TABLE shop_order ADD COLUMN quantity INT NOT NULL DEFAULT 1");
  } catch (Throwable $e) {
    if (!admin_is_duplicate_schema_error($e)) {
      error_log('admin_ensure_schema shop_order.quantity: ' . $e->getMessage());
    }
  }

  // admin_log 表
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS admin_log (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      admin_id INT NOT NULL,
      action VARCHAR(64) NOT NULL,
      target_type VARCHAR(32) NOT NULL DEFAULT '',
      target_id INT NOT NULL DEFAULT 0,
      detail VARCHAR(500) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_admin_time (admin_id, created_at),
      KEY idx_action (action, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  // 检查 shop_item 是否有数据，没有则插入默认数据
  $st = $pdo->query("SELECT COUNT(*) AS c FROM shop_item");
  $row = $st->fetch();
  if ($row && (int)$row['c'] === 0) {
    $pdo->exec("
      INSERT INTO shop_item (id, title, description, cost, icon, image, sort_order, enabled, is_physical, repeatable, is_title) VALUES
      (1, '菜鸡铁粉', '社区专属称号，兑换后可在个人主页展示，彰显你的求知精神。', 180, '📖', '/data/shop/1.svg', 1, 1, 0, 0, 1),
      (2, '菜鸡玩偶', '应老师同款菜鸡玩偶，软萌好捏，学习压力大时抱一抱～', 999, '', '/data/teacher/caiji.jpg', 2, 1, 1, 0, 0),
      (3, '菜鸡草稿本', '印有菜鸡Logo的限量草稿本，方格内页，书写顺滑，学习必备。', 150, '', '', 3, 1, 1, 1, 0)
    ");
  }

  // media_folder 表
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS media_folder (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      parent_id INT UNSIGNED DEFAULT NULL,
      name VARCHAR(200) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_parent (parent_id),
      CONSTRAINT fk_folder_parent FOREIGN KEY (parent_id) REFERENCES media_folder(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  // media_file 表
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS media_file (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      folder_id INT UNSIGNED DEFAULT NULL,
      filename VARCHAR(200) NOT NULL,
      original_name VARCHAR(255) NOT NULL,
      mime_type VARCHAR(100) NOT NULL DEFAULT '',
      size INT UNSIGNED NOT NULL DEFAULT 0,
      width INT UNSIGNED NOT NULL DEFAULT 0,
      height INT UNSIGNED NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_folder (folder_id),
      CONSTRAINT fk_file_folder FOREIGN KEY (folder_id) REFERENCES media_folder(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  // 迁移：当 media_file 表为空时，将 shop_item 中指向 /data/ 的旧图片迁移到 uploads/media/
  try {
    $st = $pdo->query("SELECT COUNT(*) AS c FROM media_file");
    $row = $st->fetch();
    $mediaCount = $row ? (int)$row['c'] : 0;
  } catch (Throwable $e) {
    $mediaCount = 999;
  }
  if ($mediaCount === 0) {
    $mediaDir = __DIR__ . '/../uploads/media';
    if (!is_dir($mediaDir)) {
      @mkdir($mediaDir, 0755, true);
    }

    $stItems = $pdo->query("SELECT id, image FROM shop_item WHERE image != ''");
    $items = $stItems->fetchAll();

    $stUpdate = $pdo->prepare("UPDATE shop_item SET image = ? WHERE id = ?");
    $stInsert = $pdo->prepare(
      "INSERT INTO media_file (folder_id, filename, original_name, mime_type, size) VALUES (NULL, ?, ?, ?, ?)"
    );

    foreach ($items as $item) {
      $oldPath = (string)$item['image'];
      // 只迁移指向 /data/ 的旧路径
      if (!str_starts_with($oldPath, '/data/')) {
        continue;
      }
      $diskPath = __DIR__ . '/../' . ltrim($oldPath, '/');
      if (!file_exists($diskPath)) {
        continue;
      }
      $ext = strtolower(pathinfo($diskPath, PATHINFO_EXTENSION));
      if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true)) {
        continue;
      }
      $ts = time();
      $rand = bin2hex(random_bytes(8));
      $newFilename = $ts . '_' . $rand . '.' . $ext;
      $dest = $mediaDir . '/' . $newFilename;
      if (@copy($diskPath, $dest)) {
        $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'];
        $mime = $mimeMap[$ext] ?? 'application/octet-stream';
        $size = filesize($dest);
        $stInsert->execute([$newFilename, basename($diskPath), $mime, $size]);
        $newPath = '/uploads/media/' . $newFilename;
        $stUpdate->execute([$newPath, (int)$item['id']]);
      }
    }
  }

  $GLOBALS['admin_schema_ready'] = true;
}

function is_admin(): bool {
  $user = current_user();
  return $user !== null && isset($user['role']) && $user['role'] === 'admin';
}

function require_admin(): void {
  if (!is_admin()) {
    http_response_code(403);
    if (is_ajax()) {
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok' => false, 'msg' => 'Forbidden']);
    } else {
      echo '<h1>403 Forbidden</h1><p>无权限访问管理后台</p>';
    }
    exit;
  }
}

function admin_log(string $action, string $targetType = '', int $targetId = 0, string $detail = ''): void {
  $user = current_user();
  $adminId = $user ? (int)$user['id'] : 0;
  if ($adminId <= 0) {
    return;
  }
  try {
    $st = db()->prepare(
      'INSERT INTO admin_log (admin_id, action, target_type, target_id, detail) VALUES (?, ?, ?, ?, ?)'
    );
    $st->execute([$adminId, $action, $targetType, $targetId, $detail]);
  } catch (Throwable $e) {
    error_log('admin_log: ' . $e->getMessage());
  }
}

/**
 * 物理清理 30 天前软删除的帖子及其关联数据
 * 可被 cron 或管理员手动触发
 * @return int 清理的帖子数量
 */
function admin_cleanup_trashed_posts(): int {
  $pdo = db();
  try {
    $st = $pdo->query("SELECT id FROM post WHERE status = 0 AND updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    if (empty($ids)) {
      return 0;
    }
    $idList = implode(',', array_map('intval', $ids));
    $pdo->beginTransaction();
    $pdo->exec("DELETE FROM comment WHERE post_id IN ($idList)");
    $pdo->exec("DELETE FROM post_like WHERE post_id IN ($idList)");
    $pdo->exec("DELETE FROM post_favorite WHERE post_id IN ($idList)");
    $pdo->exec("DELETE FROM post_tag_relation WHERE post_id IN ($idList)");
    $pdo->exec("DELETE FROM notification WHERE (type IN ('comment','like') AND related_id IN ($idList))");
    $pdo->exec("DELETE FROM post WHERE id IN ($idList)");
    $pdo->commit();
    $count = count($ids);
    error_log("admin_cleanup_trashed_posts: cleaned $count posts");
    return $count;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    error_log('admin_cleanup_trashed_posts: ' . $e->getMessage());
    return 0;
  }
}
