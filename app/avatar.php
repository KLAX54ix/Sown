<?php
declare(strict_types=1);

/**
 * 获取用户头像URL
 * @param int $userId 用户ID
 * @return string 头像URL
 */
function get_avatar_url(int $userId): string {
  $avatarPath = '/uploads/avatars/' . $userId . '.jpg';
  $filePath = __DIR__ . '/../uploads/avatars/' . $userId . '.jpg';
  
  if (file_exists($filePath)) {
    return $avatarPath . '?v=' . filemtime($filePath);
  }
  
  return '';
}

/**
 * 检查用户是否有头像
 * @param int $userId 用户ID
 * @return bool
 */
function has_avatar(int $userId): bool {
  $filePath = __DIR__ . '/../uploads/avatars/' . $userId . '.jpg';
  return file_exists($filePath);
}

/**
 * 生成头像HTML（带fallback）
 * @param array $user 用户信息数组（包含id和username）
 * @param int $size 头像大小（像素）
 * @param string|null $className 额外的CSS类名
 * @return string HTML字符串
 */
function avatar_html(array $user, int $size = 28, ?string $className = null): string {
  $userId = (int)$user['id'];

  $avatarUrl = get_avatar_url($userId);
  $hasAvatar = has_avatar($userId);

  $classAttr = $className ? ' class="' . htmlspecialchars($className, ENT_QUOTES, 'UTF-8') . '"' : '';

  $svg = '<svg viewBox="0 0 40 40" fill="#778B3E" style="width:60%;height:60%;display:block"><circle cx="20" cy="12" r="7"/><path d="M8 34 C8 25,14 21,20 21 C26 21,32 25,32 34 Z"/></svg>';

  if ($hasAvatar && $avatarUrl) {
    return sprintf(
      '<img src="%s"%s style="width:%dpx; height:%dpx; border-radius:50%%; object-fit:cover;" onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'flex\';"><div style="display:none; width:%dpx; height:%dpx; border-radius:50%%; background:#fff; align-items:center; justify-content:center;">%s</div>',
      htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8'),
      $classAttr,
      $size, $size, $size, $size,
      $svg
    );
  }

  return sprintf(
    '<div%s style="width:%dpx; height:%dpx; border-radius:50%%; background:#fff; display:flex; align-items:center; justify-content:center;">%s</div>',
    $classAttr,
    $size, $size,
    $svg
  );
}

