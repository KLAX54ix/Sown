<?php
declare(strict_types=1);

/**
 * 标签管理函数
 * 轻量级实现，适用于低配置服务器
 */

require_once __DIR__ . '/db.php';

/**
 * 获取或创建标签
 * @param string $name 标签名称
 * @return int 标签ID
 */
function get_or_create_tag(string $name): int {
  $name = trim($name);
  if ($name === '') {
    return 0;
  }
  
  // 限制标签长度
  if (mb_strlen($name) > 20) {
    $name = mb_substr($name, 0, 20);
  }
  
  // 生成 slug（用于URL）
  $slug = mb_strtolower($name);
  $slug = preg_replace('/[^\p{L}\p{N}\-]/u', '-', $slug);
  $slug = preg_replace('/-+/', '-', $slug);
  $slug = trim($slug, '-');
  
  $pdo = db();
  
  // 先查找是否存在
  $st = $pdo->prepare("SELECT id FROM post_tag WHERE slug = ? LIMIT 1");
  $st->execute([$slug]);
  $tag = $st->fetch();
  
  if ($tag) {
    return (int)$tag['id'];
  }
  
  // 不存在则创建
  $st = $pdo->prepare("INSERT INTO post_tag (name, slug, post_count, created_at) VALUES (?, ?, 0, NOW())");
  $st->execute([$name, $slug]);
  
  return (int)$pdo->lastInsertId();
}

/**
 * 获取帖子的所有标签
 * @param int $postId 帖子ID
 * @return array 标签数组
 */
function get_post_tags(int $postId): array {
  $pdo = db();
  
  try {
    $st = $pdo->prepare("
      SELECT t.id, t.name, t.slug
      FROM post_tag t
      JOIN post_tag_relation r ON r.tag_id = t.id
      WHERE r.post_id = ?
      ORDER BY t.name ASC
    ");
    $st->execute([$postId]);
    return $st->fetchAll();
  } catch (Throwable $e) {
    // 表不存在时返回空数组
    return [];
  }
}

/**
 * 设置帖子的标签
 * @param int $postId 帖子ID
 * @param array $tagNames 标签名称数组（最多5个）
 * @return bool 是否成功
 */
function set_post_tags(int $postId, array $tagNames): bool {
  $pdo = db();
  
  try {
    $pdo->beginTransaction();
    
    // 删除旧的关联
    $st = $pdo->prepare("DELETE FROM post_tag_relation WHERE post_id = ?");
    $st->execute([$postId]);
    
    // 限制最多5个标签
    $tagNames = array_slice($tagNames, 0, 5);
    
    // 添加新标签（去重）
    $tagIds = [];
    foreach ($tagNames as $tagName) {
      $tagId = get_or_create_tag($tagName);
      if ($tagId > 0 && !in_array($tagId, $tagIds)) {
        $tagIds[] = $tagId;
      }
    }
    foreach ($tagIds as $tagId) {
      $st = $pdo->prepare("INSERT INTO post_tag_relation (post_id, tag_id) VALUES (?, ?)");
      $st->execute([$postId, $tagId]);
    }
    
    // 更新标签的帖子计数
    $st = $pdo->prepare("
      UPDATE post_tag t
      SET post_count = (
        SELECT COUNT(*) FROM post_tag_relation r
        WHERE r.tag_id = t.id
      )
    ");
    $st->execute();
    
    $pdo->commit();
    return true;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    return false;
  }
}

/**
 * 获取热门标签
 * @param int $limit 数量限制
 * @return array 标签数组
 */
function get_popular_tags(int $limit = 20): array {
  $pdo = db();
  
  try {
    $st = $pdo->prepare("
      SELECT id, name, slug, post_count
      FROM post_tag
      WHERE post_count > 0
      ORDER BY post_count DESC, name ASC
      LIMIT ?
    ");
    $st->execute([$limit]);
    return $st->fetchAll();
  } catch (Throwable $e) {
    return [];
  }
}

/**
 * 获取所有标签（用于下拉选择）
 * @return array 标签数组
 */
function get_all_tags(): array {
  $pdo = db();
  
  try {
    $st = $pdo->prepare("
      SELECT id, name, slug
      FROM post_tag
      ORDER BY name ASC
    ");
    $st->execute();
    return $st->fetchAll();
  } catch (Throwable $e) {
    return [];
  }
}

