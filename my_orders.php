<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/points.php';

require_login_or_redirect('/my_orders.php');

$user = current_user();
$uid = (int)$user['id'];
$balance = points_get_balance($uid);

// 获取当前用户的订单
$pdo = db();
$statusFilter = input_int('status', -1, -1, 3, 'get');

$where = 'WHERE o.user_id = ?';
$params = [$uid];
if ($statusFilter >= 0) {
  $where .= ' AND o.status = ?';
  $params[] = $statusFilter;
}

$st = $pdo->prepare("SELECT COUNT(*) AS c FROM shop_order o $where");
$st->execute($params);
$total = (int)$st->fetch()['c'];

$page = max(1, input_int('page', 1, 1, 9999, 'get'));
$perPage = 20;
$pagi = pagination($total, $page, $perPage);

$sql = "SELECT o.* FROM shop_order o $where ORDER BY o.id DESC LIMIT {$pagi['perPage']} OFFSET {$pagi['offset']}";
$st = $pdo->prepare($sql);
$st->execute($params);
$orders = $st->fetchAll();

$statusLabels = ['待发货', '已发货', '已完成', '已取消'];
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php require __DIR__ . '/partials/head.php'; ?>
  <title>我的兑换 · Sown</title>
  <link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(__DIR__.'/assets/app.css') ?>">
  <link rel="stylesheet" href="/assets/admin.css?v=<?= filemtime(__DIR__.'/assets/admin.css') ?>">
  <style>
    .my-orders-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 24px;
      flex-wrap: wrap;
      gap: 12px;
    }
    .my-orders-header h2 {
      margin: 0;
      font-size: var(--fs-card-title);
      color: var(--near-black);
    }
    .my-orders-balance {
      font-size: var(--fs-body);
      color: var(--stone);
    }
    .my-orders-balance strong {
      color: var(--primary);
    }

    .order-card {
      background: var(--pure-white);
      border: 1px solid var(--light-gray);
      border-radius: var(--r-card);
      padding: var(--sp-5);
      margin-bottom: var(--sp-4);
    }
    .order-card-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: var(--sp-3);
    }
    .order-card-id {
      font-size: var(--fs-small);
      color: var(--stone);
    }
    .order-card-status {
      font-size: var(--fs-small);
      font-weight: var(--fw-medium);
      padding: 2px 10px;
      border-radius: 12px;
    }
    .order-card-status.pending { background: #fef3d5; color: #b8860b; }
    .order-card-status.shipped { background: #dbeafe; color: #1d4ed8; }
    .order-card-status.done { background: #e6f0da; color: #778B3E; }
    .order-card-status.cancelled { background: #f0f0f0; color: #999; }

    .order-card-body {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .order-card-title {
      font-size: var(--fs-body-lg);
      font-weight: var(--fw-medium);
      color: var(--near-black);
    }
    .order-card-cost {
      font-size: var(--fs-body);
      color: var(--stone);
    }
    .order-card-address {
      font-size: var(--fs-small);
      color: var(--stone);
      line-height: 1.5;
    }
    .order-card-tracking {
      margin-top: var(--sp-2);
      padding: var(--sp-2) var(--sp-3);
      background: var(--snow);
      border-radius: var(--r-block);
      font-size: var(--fs-small);
      color: var(--near-black);
    }
    .order-card-tracking strong {
      color: var(--primary);
    }
    .order-card-time {
      margin-top: var(--sp-2);
      font-size: var(--fs-small);
      color: var(--stone);
    }

    .my-orders-empty {
      text-align: center;
      padding: 48px 20px;
      color: var(--stone);
    }
    .my-orders-empty a {
      color: var(--primary);
    }
  </style>
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
  <?php require __DIR__ . '/partials/header.php'; ?>
  <div class="container container--narrow">
    <div class="my-orders-header">
      <h2>我的兑换</h2>
      <span class="my-orders-balance">当前积分：<strong><?= $balance ?></strong></span>
    </div>

    <!-- 状态筛选 -->
    <div class="admin-filter-bar" style="margin-bottom:20px;">
      <a href="/my_orders.php" class="admin-filter-link <?= $statusFilter < 0 ? 'active' : '' ?>">全部</a>
      <?php foreach ($statusLabels as $code => $label): ?>
        <a href="/my_orders.php?status=<?= $code ?>" class="admin-filter-link <?= $statusFilter === $code ? 'active' : '' ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
      <?php endforeach; ?>
    </div>

    <?php if (empty($orders)): ?>
      <div class="my-orders-empty">
        <p>暂无兑换记录</p>
        <p><a href="/shop.php">去商城逛逛</a></p>
      </div>
    <?php else: ?>
      <?php foreach ($orders as $o): ?>
        <div class="order-card">
          <div class="order-card-header">
            <span class="order-card-id">订单 #<?= (int)$o['id'] ?></span>
            <span class="order-card-status <?= ['pending','shipped','done','cancelled'][(int)$o['status']] ?>"><?= $statusLabels[(int)$o['status']] ?? '未知' ?></span>
          </div>
          <div class="order-card-body">
            <div class="order-card-title"><?= htmlspecialchars($o['item_title'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="order-card-cost">花费 <?= (int)$o['cost_points'] ?> 积分</div>
            <?php if ((int)$o['status'] === 0): ?>
              <div class="order-card-address">
                收件：<?= htmlspecialchars($o['recipient_name'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($o['recipient_phone'], ENT_QUOTES, 'UTF-8') ?>
                <br><?= htmlspecialchars($o['recipient_address'], ENT_QUOTES, 'UTF-8') ?>
              </div>
            <?php elseif (!empty($o['tracking_number'])): ?>
              <div class="order-card-tracking">
                物流单号：<strong><?= htmlspecialchars($o['tracking_number'], ENT_QUOTES, 'UTF-8') ?></strong>
              </div>
            <?php endif; ?>
            <div class="order-card-time"><?= htmlspecialchars($o['created_at'], ENT_QUOTES, 'UTF-8') ?></div>
          </div>
        </div>
      <?php endforeach; ?>

      <?php if ($pagi['totalPages'] > 1): ?>
      <div class="admin-pagination">
        <?php if ($pagi['hasPrev']): ?>
          <a href="?page=<?= $pagi['prevPage'] ?><?= $statusFilter >= 0 ? '&status=' . $statusFilter : '' ?>" class="btn btn-small">上一页</a>
        <?php endif; ?>
        <span class="admin-page-info">第 <?= $pagi['page'] ?> / <?= $pagi['totalPages'] ?> 页</span>
        <?php if ($pagi['hasNext']): ?>
          <a href="?page=<?= $pagi['nextPage'] ?><?= $statusFilter >= 0 ? '&status=' . $statusFilter : '' ?>" class="btn btn-small">下一页</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    <?php endif; ?>

    <?php require __DIR__ . '/partials/footer.php'; ?>
  </div>
  <script src="/assets/app.js?v=<?= filemtime(__DIR__.'/assets/app.js') ?>"></script>
</body>
</html>
