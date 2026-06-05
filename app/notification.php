<?php
declare(strict_types=1);

/**
 * 通知管理函数
 * 轻量级实现，适用于低配置服务器
 */

require_once __DIR__ . '/db.php';

/**
 * 创建通知
 * @param int $userId 接收通知的用户ID
 * @param string $type 通知类型：'comment', 'reply', 'like', 'follow'
 * @param int $relatedId 关联的帖子/评论ID
 * @param int|null $relatedUserId 触发通知的用户ID
 * @param string $content 通知内容
 * @return bool 是否成功
 */
function create_notification(int $userId, string $type, int $relatedId, ?int $relatedUserId = null, string $content = ''): bool {
  $pdo = db();
  
  try {
    $st = $pdo->prepare("
      INSERT INTO notification (user_id, type, related_id, related_user_id, content, is_read, created_at)
      VALUES (?, ?, ?, ?, ?, 0, NOW())
    ");
    $st->execute([$userId, $type, $relatedId, $relatedUserId, $content]);
    return true;
  } catch (Throwable $e) {
    // 表不存在时忽略
    return false;
  }
}

/**
 * 获取用户未读通知数量
 * @param int $userId 用户ID
 * @return int 未读通知数量
 */
function get_unread_notification_count(int $userId): int {
  $pdo = db();
  
  try {
    $st = $pdo->prepare("SELECT COUNT(*) AS c FROM notification WHERE user_id = ? AND is_read = 0");
    $st->execute([$userId]);
    $result = $st->fetch();
    return $result ? (int)$result['c'] : 0;
  } catch (Throwable $e) {
    return 0;
  }
}

/**
 * 获取用户通知列表
 * @param int $userId 用户ID
 * @param int $limit 限制数量（最多50条）
 * @param bool $unreadOnly 是否只获取未读
 * @return array 通知数组
 */
function get_notifications(int $userId, int $limit = 50, bool $unreadOnly = false): array {
  $pdo = db();
  
  // 限制最多50条
  $limit = min($limit, 50);
  
  try {
    $sql = "
      SELECT id, type, related_id, related_user_id, content, is_read, created_at
      FROM notification
      WHERE user_id = ?";
    
    if ($unreadOnly) {
      $sql .= " AND is_read = 0";
    }
    
    $sql .= " ORDER BY created_at DESC LIMIT ?";
    
    $st = $pdo->prepare($sql);
    $st->execute([$userId, $limit]);
    return $st->fetchAll();
  } catch (Throwable $e) {
    return [];
  }
}

/**
 * 标记通知为已读
 * @param int $notificationId 通知ID
 * @param int $userId 用户ID（验证权限）
 * @return bool 是否成功
 */
function mark_notification_read(int $notificationId, int $userId): bool {
  $pdo = db();
  
  try {
    $st = $pdo->prepare("UPDATE notification SET is_read = 1 WHERE id = ? AND user_id = ?");
    $st->execute([$notificationId, $userId]);
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

/**
 * 标记所有通知为已读
 * @param int $userId 用户ID
 * @return bool 是否成功
 */
function mark_all_notifications_read(int $userId): bool {
  $pdo = db();
  
  try {
    $st = $pdo->prepare("UPDATE notification SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $st->execute([$userId]);
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

/**
 * 清理旧通知（保留30天）
 * @return int 清理的通知数量
 */
function clean_old_notifications(): int {
  $pdo = db();
  
  try {
    $st = $pdo->prepare("DELETE FROM notification WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $st->execute();
    return $st->rowCount();
  } catch (Throwable $e) {
    return 0;
  }
}

