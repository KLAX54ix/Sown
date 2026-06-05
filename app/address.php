<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** @var bool */
$GLOBALS['address_schema_ready'] = false;

function address_ensure_schema(): void {
  if (!empty($GLOBALS['address_schema_ready'])) {
    return;
  }
  $pdo = db();
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS shipping_address (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      recipient VARCHAR(100) NOT NULL COMMENT '收件人',
      phone VARCHAR(20) NOT NULL COMMENT '联系电话',
      region VARCHAR(200) NOT NULL DEFAULT '' COMMENT '所在地区（省/市/区）',
      detail VARCHAR(500) NOT NULL DEFAULT '' COMMENT '详细地址',
      is_default TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否默认',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_user (user_id),
      KEY idx_user_default (user_id, is_default)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
  $GLOBALS['address_schema_ready'] = true;
}

/**
 * 获取用户的收货地址列表
 * @return list<array>
 */
function address_get_list(int $userId): array {
  address_ensure_schema();
  if ($userId <= 0) {
    return [];
  }
  $st = db()->prepare(
    "SELECT id, user_id, recipient, phone, region, detail, is_default, created_at
     FROM shipping_address
     WHERE user_id = ?
     ORDER BY is_default DESC, id DESC"
  );
  $st->execute([$userId]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 添加收货地址
 * @return array{ok:bool, msg:string, id?:int}
 */
function address_add(int $userId, string $recipient, string $phone, string $region, string $detail): array {
  address_ensure_schema();
  if ($userId <= 0) {
    return ['ok' => false, 'msg' => '请先登录'];
  }
  $recipient = trim($recipient);
  $phone = trim($phone);
  $region = trim($region);
  $detail = trim($detail);

  if ($recipient === '') {
    return ['ok' => false, 'msg' => '请填写收件人'];
  }
  if ($phone === '') {
    return ['ok' => false, 'msg' => '请填写联系电话'];
  }
  if (mb_strlen($recipient) > 100) {
    return ['ok' => false, 'msg' => '收件人不能超过100个字符'];
  }
  if (mb_strlen($phone) > 20) {
    return ['ok' => false, 'msg' => '联系电话不能超过20个字符'];
  }
  if (mb_strlen($region) > 200) {
    return ['ok' => false, 'msg' => '所在地区不能超过200个字符'];
  }
  if (mb_strlen($detail) > 500) {
    return ['ok' => false, 'msg' => '详细地址不能超过500个字符'];
  }

  $pdo = db();
  try {
    // 检查是否已有地址，如果没有则设为默认
    $st = $pdo->prepare("SELECT COUNT(*) AS c FROM shipping_address WHERE user_id = ?");
    $st->execute([$userId]);
    $count = (int)$st->fetch()['c'];
    $isDefault = ($count === 0) ? 1 : 0;

    $st = $pdo->prepare(
      "INSERT INTO shipping_address (user_id, recipient, phone, region, detail, is_default)
       VALUES (?, ?, ?, ?, ?, ?)"
    );
    $st->execute([$userId, $recipient, $phone, $region, $detail, $isDefault]);
    return ['ok' => true, 'msg' => '添加成功', 'id' => (int)$pdo->lastInsertId()];
  } catch (Throwable $e) {
    error_log('address_add: ' . $e->getMessage());
    return ['ok' => false, 'msg' => '添加失败，请稍后重试'];
  }
}

/**
 * 更新收货地址
 * @return array{ok:bool, msg:string}
 */
function address_update(int $addressId, int $userId, string $recipient, string $phone, string $region, string $detail): array {
  address_ensure_schema();
  if ($userId <= 0) {
    return ['ok' => false, 'msg' => '请先登录'];
  }
  $recipient = trim($recipient);
  $phone = trim($phone);
  $region = trim($region);
  $detail = trim($detail);

  if ($recipient === '') {
    return ['ok' => false, 'msg' => '请填写收件人'];
  }
  if ($phone === '') {
    return ['ok' => false, 'msg' => '请填写联系电话'];
  }
  if (mb_strlen($recipient) > 100) {
    return ['ok' => false, 'msg' => '收件人不能超过100个字符'];
  }
  if (mb_strlen($phone) > 20) {
    return ['ok' => false, 'msg' => '联系电话不能超过20个字符'];
  }
  if (mb_strlen($region) > 200) {
    return ['ok' => false, 'msg' => '所在地区不能超过200个字符'];
  }
  if (mb_strlen($detail) > 500) {
    return ['ok' => false, 'msg' => '详细地址不能超过500个字符'];
  }

  $pdo = db();
  try {
    $st = $pdo->prepare(
      "UPDATE shipping_address SET recipient = ?, phone = ?, region = ?, detail = ?
       WHERE id = ? AND user_id = ?"
    );
    $st->execute([$recipient, $phone, $region, $detail, $addressId, $userId]);
    if ($st->rowCount() === 0) {
      return ['ok' => false, 'msg' => '地址不存在或无权操作'];
    }
    return ['ok' => true, 'msg' => '更新成功'];
  } catch (Throwable $e) {
    error_log('address_update: ' . $e->getMessage());
    return ['ok' => false, 'msg' => '更新失败，请稍后重试'];
  }
}

/**
 * 删除收货地址
 * @return array{ok:bool, msg:string}
 */
function address_delete(int $addressId, int $userId): array {
  address_ensure_schema();
  if ($userId <= 0) {
    return ['ok' => false, 'msg' => '请先登录'];
  }
  $pdo = db();
  try {
    $pdo->beginTransaction();

    // 获取要删除的地址
    $st = $pdo->prepare("SELECT is_default FROM shipping_address WHERE id = ? AND user_id = ? LIMIT 1");
    $st->execute([$addressId, $userId]);
    $addr = $st->fetch();
    if (!$addr) {
      $pdo->rollBack();
      return ['ok' => false, 'msg' => '地址不存在或无权操作'];
    }

    $wasDefault = (int)$addr['is_default'] === 1;

    // 删除地址
    $st = $pdo->prepare("DELETE FROM shipping_address WHERE id = ? AND user_id = ?");
    $st->execute([$addressId, $userId]);

    // 如果删除的是默认地址，将最新的地址设为默认
    if ($wasDefault) {
      $st = $pdo->prepare(
        "SELECT id FROM shipping_address WHERE user_id = ? ORDER BY id DESC LIMIT 1"
      );
      $st->execute([$userId]);
      $newDefault = $st->fetch();
      if ($newDefault) {
        $st = $pdo->prepare("UPDATE shipping_address SET is_default = 1 WHERE id = ?");
        $st->execute([(int)$newDefault['id']]);
      }
    }

    $pdo->commit();
    return ['ok' => true, 'msg' => '删除成功'];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    error_log('address_delete: ' . $e->getMessage());
    return ['ok' => false, 'msg' => '删除失败，请稍后重试'];
  }
}

/**
 * 设置默认地址
 * @return array{ok:bool, msg:string}
 */
function address_set_default(int $addressId, int $userId): array {
  address_ensure_schema();
  if ($userId <= 0) {
    return ['ok' => false, 'msg' => '请先登录'];
  }
  $pdo = db();
  try {
    $pdo->beginTransaction();
    // 先取消所有默认
    $st = $pdo->prepare("UPDATE shipping_address SET is_default = 0 WHERE user_id = ?");
    $st->execute([$userId]);
    // 设置新的默认
    $st = $pdo->prepare("UPDATE shipping_address SET is_default = 1 WHERE id = ? AND user_id = ?");
    $st->execute([$addressId, $userId]);
    if ($st->rowCount() === 0) {
      $pdo->rollBack();
      return ['ok' => false, 'msg' => '地址不存在或无权操作'];
    }
    $pdo->commit();
    return ['ok' => true, 'msg' => '已设为默认'];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    error_log('address_set_default: ' . $e->getMessage());
    return ['ok' => false, 'msg' => '设置失败，请稍后重试'];
  }
}
