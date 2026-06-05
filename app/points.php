<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** @var bool */
$GLOBALS['points_schema_ready'] = false;

function points_is_duplicate_schema_error(Throwable $e): bool {
  $m = $e->getMessage();
  if (strpos($m, '1060') !== false || stripos($m, 'Duplicate column') !== false) {
    return true;
  }
  if (strpos($m, '1050') !== false || stripos($m, 'duplicate') !== false) {
    return true;
  }
  return false;
}

function points_ensure_schema(): void {
  if (!empty($GLOBALS['points_schema_ready'])) {
    return;
  }
  $pdo = db();

  try {
    $pdo->exec('ALTER TABLE user ADD COLUMN points INT NOT NULL DEFAULT 0');
  } catch (Throwable $e) {
    if (!points_is_duplicate_schema_error($e)) {
      error_log('points_ensure_schema user.points: ' . $e->getMessage());
    }
  }

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS point_ledger (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      delta INT NOT NULL,
      reason VARCHAR(64) NOT NULL,
      reason_key VARCHAR(190) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_user_reason_key (user_id, reason_key),
      KEY idx_user_time (user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS user_checkin (
      user_id INT NOT NULL,
      checkin_date DATE NOT NULL,
      PRIMARY KEY (user_id, checkin_date),
      KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS shop_purchase (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      item_id INT NOT NULL,
      item_title VARCHAR(200) NOT NULL,
      cost_points INT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  // 用户称号表
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS user_title (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      title_key VARCHAR(50) NOT NULL COMMENT '称号标识，如 seeker',
      title_name VARCHAR(100) NOT NULL COMMENT '称号名称',
      icon VARCHAR(20) DEFAULT '' COMMENT '称号图标',
      is_active TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否正在使用',
      obtained_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_user_title (user_id, title_key),
      KEY idx_user_active (user_id, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  // post.review_status（确保被关联查询使用时不报错）
  try {
    $pdo->exec("ALTER TABLE post ADD COLUMN review_status TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=approved, 1=pending, 2=rejected'");
  } catch (Throwable $e) {
    if (!points_is_duplicate_schema_error($e)) {
      error_log('points_ensure_schema post.review_status: ' . $e->getMessage());
    }
  }

  $GLOBALS['points_schema_ready'] = true;
}

/**
 * @return int 当前积分（失败时 0）
 */
function points_get_balance(int $userId): int {
  points_ensure_schema();
  if ($userId <= 0) {
    return 0;
  }
  $st = db()->prepare('SELECT COALESCE(points, 0) AS p FROM user WHERE id = ? LIMIT 1');
  $st->execute([$userId]);
  $row = $st->fetch();
  return $row ? (int)$row['p'] : 0;
}

function points_has_ledger_key(int $userId, string $reasonKey): bool {
  points_ensure_schema();
  $st = db()->prepare('SELECT 1 FROM point_ledger WHERE user_id = ? AND reason_key = ? LIMIT 1');
  $st->execute([$userId, $reasonKey]);
  return (bool)$st->fetch();
}

/**
 * 发放积分（reason_key 全局唯一则同一用户只能领一次）
 */
function points_grant(int $userId, int $amount, string $reason, string $reasonKey): bool {
  points_ensure_schema();
  if ($userId <= 0 || $amount <= 0) {
    return false;
  }
  $pdo = db();
  try {
    $pdo->beginTransaction();
    $st = $pdo->prepare(
      'INSERT INTO point_ledger (user_id, delta, reason, reason_key) VALUES (?, ?, ?, ?)'
    );
    $st->execute([$userId, $amount, $reason, $reasonKey]);
    $st = $pdo->prepare('UPDATE user SET points = COALESCE(points, 0) + ? WHERE id = ?');
    $st->execute([$amount, $userId]);
    $pdo->commit();
    if (session_status() === PHP_SESSION_ACTIVE) {
      _points_record_reward($userId, $amount, $reason, $reasonKey);
    }
    return true;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    if ($e instanceof PDOException && (int)$e->errorInfo[1] === 1062) {
      return false;
    }
    error_log('points_grant: ' . $e->getMessage());
    return false;
  }
}

/**
 * 获取任务/里程碑的显示名称
 */
function _points_reward_label(string $reasonKey, string $reason, int $amount): ?string {
  $detailMap = [
    'milestone_streak_7'            => '连续签到 7 天',
    'milestone_streak_30'           => '连续签到 30 天',
    'milestone_posts_1'             => '首次发帖',
    'milestone_posts_5'             => '累计发帖 5 篇',
    'milestone_posts_10'            => '累计发帖 10 篇',
    'milestone_post_likes_100'      => '单篇帖子获赞 100',
    'milestone_post_favs_100'       => '单篇帖子被收藏 100',
    'milestone_comment_likes_100'   => '单条评论获赞 100',
  ];
  if (isset($detailMap[$reasonKey])) {
    return $detailMap[$reasonKey] . '，获得 ' . $amount . ' 积分';
  }
  if ($reason === 'checkin_daily') {
    return '每日签到，获得 ' . $amount . ' 积分';
  }
  if ($reason === 'checkin_streak') {
    return '连续签到奖励，获得 ' . $amount . ' 积分';
  }
  return null;
}

/**
 * 记录积分获得通知到会话（用于前端弹窗）
 */
function _points_record_reward(int $userId, int $amount, string $reason, string $reasonKey): void {
  $label = _points_reward_label($reasonKey, $reason, $amount);
  if ($label === null) return;
  if (!isset($_SESSION['_point_rewards'])) {
    $_SESSION['_point_rewards'] = [];
  }
  $_SESSION['_point_rewards'][] = [
    'amount' => $amount,
    'label' => $label,
  ];
}

/**
 * 取出并清除所有待显示的积分获得通知
 * @return list<array{amount:int, label:string}>
 */
function points_drain_rewards(): array {
  if (session_status() !== PHP_SESSION_ACTIVE) return [];
  $rewards = isset($_SESSION['_point_rewards']) ? (array)$_SESSION['_point_rewards'] : [];
  unset($_SESSION['_point_rewards']);
  return $rewards;
}

/**
 * 扣减积分（reasonKey 需单次唯一，例如带 purchase id）
 */
function points_spend(int $userId, int $amount, string $reason, string $reasonKey): bool {
  points_ensure_schema();
  if ($userId <= 0 || $amount <= 0) {
    return false;
  }
  $pdo = db();
  try {
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT COALESCE(points, 0) AS p FROM user WHERE id = ? FOR UPDATE');
    $st->execute([$userId]);
    $row = $st->fetch();
    if (!$row || (int)$row['p'] < $amount) {
      $pdo->rollBack();
      return false;
    }
    $st = $pdo->prepare(
      'INSERT INTO point_ledger (user_id, delta, reason, reason_key) VALUES (?, ?, ?, ?)'
    );
    $st->execute([$userId, -$amount, $reason, $reasonKey]);
    $st = $pdo->prepare('UPDATE user SET points = points - ? WHERE id = ? AND points >= ?');
    $st->execute([$amount, $userId, $amount]);
    if ($st->rowCount() === 0) {
      $pdo->rollBack();
      return false;
    }
    $pdo->commit();
    return true;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    error_log('points_spend: ' . $e->getMessage());
    return false;
  }
}

function points_streak_from_anchor(array $dateSet, string $anchorYmd): int {
  $d = DateTimeImmutable::createFromFormat('Y-m-d', $anchorYmd);
  if (!$d) {
    return 0;
  }
  $streak = 0;
  for ($i = 0; $i < 400; $i++) {
    $key = $d->format('Y-m-d');
    if (empty($dateSet[$key])) {
      break;
    }
    $streak++;
    $d = $d->modify('-1 day');
  }
  return $streak;
}

/**
 * 展示用：当日已签则从今天起算连续天数；否则从昨天起算（不断签則仍顯示昨日連續）
 */
function points_current_streak(int $userId): int {
  points_ensure_schema();
  if ($userId <= 0) {
    return 0;
  }
  $st = db()->prepare(
    'SELECT checkin_date FROM user_checkin WHERE user_id = ? ORDER BY checkin_date DESC'
  );
  $st->execute([$userId]);
  $dates = $st->fetchAll(PDO::FETCH_COLUMN);
  if (!$dates) {
    return 0;
  }
  $set = [];
  foreach ($dates as $d) {
    $set[(string)$d] = true;
  }
  $today = (new DateTimeImmutable('today'))->format('Y-m-d');
  $yesterday = (new DateTimeImmutable('yesterday'))->format('Y-m-d');
  if (isset($set[$today])) {
    return points_streak_from_anchor($set, $today);
  }
  if (isset($set[$yesterday])) {
    return points_streak_from_anchor($set, $yesterday);
  }
  return 0;
}

function points_checked_in_today(int $userId): bool {
  points_ensure_schema();
  if ($userId <= 0) {
    return false;
  }
  $today = (new DateTimeImmutable('today'))->format('Y-m-d');
  $st = db()->prepare(
    'SELECT 1 FROM user_checkin WHERE user_id = ? AND checkin_date = ? LIMIT 1'
  );
  $st->execute([$userId, $today]);
  return (bool)$st->fetch();
}

/**
 * 签到：每日一次 + 连续 7 / 30 天一次性奖励
 * @return array{ok:bool, msg:string, streak?:int, gained?:int}
 */
function points_do_checkin(int $userId): array {
  points_ensure_schema();
  if ($userId <= 0) {
    return ['ok' => false, 'msg' => '请先登录'];
  }
  $pdo = db();
  $today = (new DateTimeImmutable('today'))->format('Y-m-d');

  try {
    $pdo->beginTransaction();
    $st = $pdo->prepare(
      'INSERT INTO user_checkin (user_id, checkin_date) VALUES (?, ?)'
    );
    $st->execute([$userId, $today]);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    if ($e instanceof PDOException && (int)$e->errorInfo[1] === 1062) {
      return ['ok' => false, 'msg' => '今日已签到'];
    }
    error_log('points_do_checkin: ' . $e->getMessage());
    return ['ok' => false, 'msg' => '签到失败，请稍后重试'];
  }

  $dailyKey = 'daily_checkin_' . $today;
  $dailyPts = 5;
  points_grant($userId, $dailyPts, 'checkin_daily', $dailyKey);

  $st = $pdo->prepare(
    'SELECT checkin_date FROM user_checkin WHERE user_id = ? ORDER BY checkin_date DESC'
  );
  $st->execute([$userId]);
  $list = $st->fetchAll(PDO::FETCH_COLUMN);
  $set = [];
  foreach ($list as $d) {
    $set[(string)$d] = true;
  }
  $streak = points_streak_from_anchor($set, $today);
  $extra = 0;
  if ($streak >= 7 && points_grant($userId, 30, 'checkin_streak', 'milestone_streak_7')) {
    $extra += 30;
  }
  if ($streak >= 30 && points_grant($userId, 120, 'checkin_streak', 'milestone_streak_30')) {
    $extra += 120;
  }

  return [
    'ok' => true,
    'msg' => '签到成功',
    'streak' => $streak,
    'gained' => $dailyPts + $extra,
  ];
}

function points_count_published_posts(int $userId): int {
  if ($userId <= 0) {
    return 0;
  }
  $st = db()->prepare('SELECT COUNT(*) AS c FROM post WHERE user_id = ? AND status = 1 AND (review_status IS NULL OR review_status != 2)');
  $st->execute([$userId]);
  $row = $st->fetch();
  return $row ? (int)$row['c'] : 0;
}

function points_author_post_stats(int $userId): array {
  if ($userId <= 0) {
    return ['max_likes' => 0, 'max_favs' => 0];
  }
  $st = db()->prepare(
    'SELECT COALESCE(MAX(like_count), 0) AS ml, COALESCE(MAX(favorite_count), 0) AS mf
     FROM post WHERE user_id = ? AND status = 1 AND (review_status IS NULL OR review_status != 2)'
  );
  $st->execute([$userId]);
  $r = $st->fetch();
  return [
    'max_likes' => $r ? (int)$r['ml'] : 0,
    'max_favs' => $r ? (int)$r['mf'] : 0,
  ];
}

function points_author_max_comment_likes(int $userId): int {
  if ($userId <= 0) {
    return 0;
  }
  try {
    $st = db()->prepare(
      'SELECT COALESCE(MAX(like_count), 0) AS m FROM comment WHERE user_id = ? AND status = 1'
    );
    $st->execute([$userId]);
    $r = $st->fetch();
    return $r ? (int)$r['m'] : 0;
  } catch (Throwable $e) {
    return 0;
  }
}

/** 发帖并公开后：按累计发帖数里程碑发奖 */
function points_after_post_published(int $userId): void {
  points_ensure_schema();
  if ($userId <= 0) {
    return;
  }
  $n = points_count_published_posts($userId);
  if ($n >= 1) {
    points_grant($userId, 10, 'task_posts', 'milestone_posts_1');
  }
  if ($n >= 5) {
    points_grant($userId, 40, 'task_posts', 'milestone_posts_5');
  }
  if ($n >= 10) {
    points_grant($userId, 90, 'task_posts', 'milestone_posts_10');
  }
  points_refresh_author_engagement_milestones($userId);
}

/** 帖子被赞/被收藏变化后，检查作者的单帖峰值里程碑 */
function points_refresh_author_engagement_milestones(int $authorUserId): void {
  points_ensure_schema();
  if ($authorUserId <= 0) {
    return;
  }
  $s = points_author_post_stats($authorUserId);
  if ($s['max_likes'] >= 100) {
    points_grant($authorUserId, 100, 'task_post_likes', 'milestone_post_likes_100');
  }
  if ($s['max_favs'] >= 100) {
    points_grant($authorUserId, 100, 'task_post_favorites', 'milestone_post_favs_100');
  }
}

/** 评论被点赞后，检查评论作者 */
function points_refresh_comment_engagement_milestones(int $commentAuthorId): void {
  points_ensure_schema();
  if ($commentAuthorId <= 0) {
    return;
  }
  $m = points_author_max_comment_likes($commentAuthorId);
  if ($m >= 100) {
    points_grant($commentAuthorId, 100, 'task_comment_likes', 'milestone_comment_likes_100');
  }
}

/**
 * 商城商品（虚拟），id 需稳定
 * 优先从数据库读取，回退到硬编码数组
 * @return list<array{id:int,title:string,description:string,cost:int,icon:string,image:string,repeatable:bool}>
 */
function shop_catalog(): array {
  $fallback = [
    [
      'id' => 1,
      'title' => '菜鸡铁粉',
      'description' => '社区专属称号，兑换后可在个人主页展示，彰显你的求知精神。',
      'cost' => 180,
      'icon' => '📖',
      'image' => '/data/shop/1.svg',
      'is_physical' => false,
      'repeatable' => false,
      'is_title' => true,
    ],
    [
      'id' => 2,
      'title' => '菜鸡玩偶',
      'description' => '应老师同款菜鸡玩偶，软萌好捏，学习压力大时抱一抱～',
      'cost' => 999,
      'icon' => '',
      'image' => '/data/teacher/caiji.jpg',
      'is_physical' => false,
      'repeatable' => false,
    ],
  ];

  try {
    $pdo = db();
    $st = $pdo->query("SELECT id, title, description, cost, icon, image, sort_order, is_physical, repeatable, is_title FROM shop_item WHERE enabled = 1 ORDER BY sort_order ASC, id ASC");
    $rows = $st->fetchAll();
    if (!empty($rows)) {
      $items = [];
      foreach ($rows as $row) {
        $items[] = [
          'id' => (int)$row['id'],
          'title' => (string)$row['title'],
          'description' => (string)$row['description'],
          'cost' => (int)$row['cost'],
          'icon' => (string)$row['icon'],
          'image' => (string)$row['image'],
          'is_physical' => !empty($row['is_physical']),
          'repeatable' => !empty($row['repeatable']),
          'is_title' => !empty($row['is_title']),
        ];
      }
      return $items;
    }
  } catch (Throwable $e) {
    // 表不存在时使用回退数据
  }

  return $fallback;
}

function shop_find_item(int $id): ?array {
  foreach (shop_catalog() as $item) {
    if ((int)$item['id'] === $id) {
      return $item;
    }
  }
  return null;
}

/**
 * @return array{ok:bool, msg:string}
 */
function shop_purchase_item(int $userId, int $itemId): array {
  points_ensure_schema();
  $item = shop_find_item($itemId);
  if (!$item) {
    return ['ok' => false, 'msg' => '商品不存在'];
  }
  if (!empty($item['is_physical'])) {
    return ['ok' => false, 'msg' => '实物商品请通过收货信息表单兑换'];
  }
  if (empty($item['repeatable']) && shop_has_purchased($userId, $itemId)) {
    return ['ok' => false, 'msg' => '您已拥有该商品'];
  }
  $cost = (int)$item['cost'];
  $pdo = db();
  try {
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT COALESCE(points, 0) AS p FROM user WHERE id = ? FOR UPDATE');
    $st->execute([$userId]);
    $row = $st->fetch();
    if (!$row || (int)$row['p'] < $cost) {
      $pdo->rollBack();
      return ['ok' => false, 'msg' => '积分不足'];
    }
    $title = (string)$item['title'];
    $icon = (string)($item['icon'] ?? '');
    $isTitle = !empty($item['is_title']);
    $titleKey = 'item_' . $itemId;
    $st = $pdo->prepare(
      'INSERT INTO shop_purchase (user_id, item_id, item_title, cost_points) VALUES (?, ?, ?, ?)'
    );
    $st->execute([$userId, $itemId, $title, $cost]);
    $pid = (int)$pdo->lastInsertId();
    if ($isTitle) {
      // 称号类商品写入用户称号表
      $st = $pdo->prepare("
        INSERT INTO user_title (user_id, title_key, title_name, icon, is_active)
        VALUES (?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE title_name = VALUES(title_name), icon = VALUES(icon)
      ");
      $st->execute([$userId, $titleKey, $title, $icon]);
    }
    $reasonKey = 'shop_purchase_' . $pid;
    $st = $pdo->prepare(
      'INSERT INTO point_ledger (user_id, delta, reason, reason_key) VALUES (?, ?, ?, ?)'
    );
    $st->execute([$userId, -$cost, 'shop', $reasonKey]);
    $st = $pdo->prepare('UPDATE user SET points = points - ? WHERE id = ? AND points >= ?');
    $st->execute([$cost, $userId, $cost]);
    if ($st->rowCount() === 0) {
      $pdo->rollBack();
      return ['ok' => false, 'msg' => '积分不足'];
    }
    $pdo->commit();
    return ['ok' => true, 'msg' => '兑换成功，请在「我的兑换」查看详情'];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    error_log('shop_purchase_item: ' . $e->getMessage());
    return ['ok' => false, 'msg' => '兑换失败，请稍后重试'];
  }
}

/**
 * 检查用户是否已购买指定商品
 * @return bool
 */
function shop_has_purchased(int $userId, int $itemId): bool {
  points_ensure_schema();
  if ($userId <= 0) {
    return false;
  }
  $st = db()->prepare('SELECT 1 FROM shop_purchase WHERE user_id = ? AND item_id = ? LIMIT 1');
  $st->execute([$userId, $itemId]);
  return (bool)$st->fetch();
}

/**
 * 创建实物商品订单并扣减积分
 * @return array{ok:bool, msg:string, order_id?:int}
 */
function shop_order_create(int $userId, int $itemId, string $name, string $phone, string $address, int $quantity = 1): array {
  points_ensure_schema();
  $item = shop_find_item($itemId);
  if (!$item) {
    return ['ok' => false, 'msg' => '商品不存在'];
  }
  if (empty($item['is_physical'])) {
    return ['ok' => false, 'msg' => '该商品不是实物商品'];
  }
  if (empty($item['repeatable']) && shop_has_purchased($userId, $itemId)) {
    return ['ok' => false, 'msg' => '您已兑换过该商品'];
  }
  $name = trim($name);
  $phone = trim($phone);
  $address = trim($address);
  if ($name === '' || $phone === '' || $address === '') {
    return ['ok' => false, 'msg' => '请填写完整的收货信息'];
  }
  if ($quantity < 1) {
    $quantity = 1;
  }
  $cost = (int)$item['cost'];
  $totalCost = $cost * $quantity;
  $title = (string)$item['title'];
  $pdo = db();
  try {
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT COALESCE(points, 0) AS p FROM user WHERE id = ? FOR UPDATE');
    $st->execute([$userId]);
    $row = $st->fetch();
    if (!$row || (int)$row['p'] < $totalCost) {
      $pdo->rollBack();
      return ['ok' => false, 'msg' => '积分不足'];
    }
    // 写入购买记录
    $st = $pdo->prepare(
      'INSERT INTO shop_purchase (user_id, item_id, item_title, cost_points) VALUES (?, ?, ?, ?)'
    );
    $st->execute([$userId, $itemId, $title, $totalCost]);
    $pid = (int)$pdo->lastInsertId();
    // 写入订单
    $st = $pdo->prepare(
      'INSERT INTO shop_order (user_id, item_id, item_title, cost_points, recipient_name, recipient_phone, recipient_address, quantity)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([$userId, $itemId, $title, $totalCost, $name, $phone, $address, $quantity]);
    $orderId = (int)$pdo->lastInsertId();
    // 扣减积分
    $reasonKey = 'shop_order_' . $orderId;
    $st = $pdo->prepare(
      'INSERT INTO point_ledger (user_id, delta, reason, reason_key) VALUES (?, ?, ?, ?)'
    );
    $st->execute([$userId, -$totalCost, 'shop', $reasonKey]);
    $st = $pdo->prepare('UPDATE user SET points = points - ? WHERE id = ? AND points >= ?');
    $st->execute([$totalCost, $userId, $totalCost]);
    if ($st->rowCount() === 0) {
      $pdo->rollBack();
      return ['ok' => false, 'msg' => '积分不足'];
    }
    $pdo->commit();
    return ['ok' => true, 'msg' => '兑换成功，请在「我的兑换」查看订单详情', 'order_id' => $orderId];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    error_log('shop_order_create: ' . $e->getMessage());
    return ['ok' => false, 'msg' => '兑换失败，请稍后重试'];
  }
}

/**
 * 任务展示数据（用于商城页）
 * @return array<string, mixed>
 */
function points_task_summary(int $userId): array {
  points_ensure_schema();
  if ($userId <= 0) {
    return [
      'streak' => 0,
      'checked_today' => false,
      'posts' => 0,
      'max_post_likes' => 0,
      'max_post_favs' => 0,
      'max_comment_likes' => 0,
      'ledger' => [],
    ];
  }
  $ps = points_author_post_stats($userId);
  $st = db()->prepare(
    'SELECT reason_key FROM point_ledger WHERE user_id = ? AND delta > 0 ORDER BY id ASC LIMIT 200'
  );
  $st->execute([$userId]);
  $keys = $st->fetchAll(PDO::FETCH_COLUMN);

  return [
    'streak' => points_current_streak($userId),
    'checked_today' => points_checked_in_today($userId),
    'posts' => points_count_published_posts($userId),
    'max_post_likes' => $ps['max_likes'],
    'max_post_favs' => $ps['max_favs'],
    'max_comment_likes' => points_author_max_comment_likes($userId),
    'ledger' => $keys,
  ];
}
