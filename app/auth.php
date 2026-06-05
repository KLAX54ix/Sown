<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';

/**
 * 获取当前登录用户信息
 * @return array|null
 */
if (!function_exists('current_user')) {
  function current_user(): ?array {
    $uid = current_user_id(); // 来自 bootstrap.php
    if (!$uid) return null;

    static $cache = null; // 同一请求内只查一次
    if ($cache !== null) return $cache;

    $st = db()->prepare(
      "SELECT id, username, email, phone, role
       FROM user
       WHERE id = ? AND status = 1
       LIMIT 1"
    );
    $st->execute([$uid]);
    $user = $st->fetch();

    $cache = $user ?: null;
    return $cache;
  }
}
