<?php
declare(strict_types=1);

/**
 * 根据用户ID生成唯一账号（至少9位，包含数字、大小写字母）
 * 格式：S + 9位混合编码
 *    - 三段 base36(userId) 变换保证唯一性
 *    - 强制包含数字、大写字母、小写字母
 *
 * @param int $userId 用户ID
 * @return string 账号（如 S255tS100A, S255vSj00B）
 */
function generate_account(int $userId): string {
  // 生成三段 base36 以提供足够字符池
  $part1 = strtoupper(base_convert((string)($userId + 100000), 10, 36));
  $part2 = strtoupper(base_convert((string)($userId * 9 + 1000), 10, 36));
  $part3 = strtoupper(base_convert((string)($userId * 99 + 9999), 10, 36));
  $raw = str_pad(substr($part1 . $part2 . $part3, 0, 9), 9, '0', STR_PAD_RIGHT);

  // 字母位按出现顺序交替大小写，确保大小写字母都存在
  $result = '';
  $alphaIdx = 0;
  for ($i = 0; $i < 9; $i++) {
    $ch = $raw[$i];
    if (ctype_digit($ch)) {
      $result .= $ch;
    } else {
      $result .= ($alphaIdx++ % 2 === 0) ? strtolower($ch) : $ch;
    }
  }

  // 兜底：确保包含数字
  if (!preg_match('/\d/', $result)) {
    // 替换最后一个字母位为数字
    for ($i = 8; $i >= 0; $i--) {
      if (ctype_alpha($result[$i])) {
        $result[$i] = (string)($userId % 10 ?: 7);
        break;
      }
    }
  }

  // 兜底：确保包含小写字母
  if (!preg_match('/[a-z]/', $result)) {
    for ($i = 0; $i < 9; $i++) {
      if (ctype_upper($result[$i])) {
        $result[$i] = strtolower($result[$i]);
        break;
      }
    }
  }

  // 兜底：确保包含大写字母（由上面交替逻辑应已保证，保底检查）
  if (!preg_match('/[A-Z]/', $result)) {
    for ($i = 0; $i < 9; $i++) {
      if (ctype_lower($result[$i])) {
        $result[$i] = strtoupper($result[$i]);
        break;
      }
    }
  }

  return 'S' . $result;
}

/**
 * 为现有用户生成并更新账号
 * @param int $userId 用户ID
 * @return string 生成的账号
 */
function ensure_user_account(int $userId): string {
  require_once __DIR__ . '/db.php';
  $pdo = db();
  
  // 检查是否已有账号
  $st = $pdo->prepare("SELECT account FROM user WHERE id = ? LIMIT 1");
  $st->execute([$userId]);
  $user = $st->fetch();
  
  if ($user && !empty($user['account'])) {
    return $user['account'];
  }
  
  // 生成新账号
  $account = generate_account($userId);
  
  // 更新数据库
  $st = $pdo->prepare("UPDATE user SET account = ? WHERE id = ?");
  $st->execute([$account, $userId]);
  
  return $account;
}

