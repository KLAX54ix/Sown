<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/admin.php';

admin_ensure_schema();
require_admin();

$pdo = db();

// 统计数据
$stats = [];

// 总用户数
$st = $pdo->query("SELECT COUNT(*) AS c FROM user");
$stats['users'] = (int)$st->fetch()['c'];

// 总帖子数（仅已发布）
$st = $pdo->query("SELECT COUNT(*) AS c FROM post WHERE status = 1");
$stats['total_posts'] = (int)$st->fetch()['c'];

// 待审核帖子数
$st = $pdo->query("SELECT COUNT(*) AS c FROM post WHERE status = 1 AND review_status = 1");
$stats['pending_posts'] = (int)$st->fetch()['c'];

// 商品数
$st = $pdo->query("SELECT COUNT(*) AS c FROM shop_item");
$stats['shop_items'] = (int)$st->fetch()['c'];

// 已删除帖子数（30天内）
$st = $pdo->query("SELECT COUNT(*) AS c FROM post WHERE status = 0 AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$stats['trashed_posts'] = (int)$st->fetch()['c'];

// 清理触发
$cleanupMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cleanup_trash') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $cleanupMsg = '<div class="alert error">安全验证失败</div>';
  } else {
    $n = admin_cleanup_trashed_posts();
    if ($n > 0) {
      $cleanupMsg = '<div class="alert success">已清理 ' . $n . ' 条已删除超过 30 天的帖子及关联数据</div>';
      admin_log('cleanup_trash', 'post', 0, "清理了 $n 条过期删除帖");
    } else {
      $cleanupMsg = '<div class="alert success">没有需要清理的帖子</div>';
    }
  }
}

// 最近操作日志
$st = $pdo->query("
  SELECT al.*, u.username
  FROM admin_log al
  LEFT JOIN user u ON u.id = al.admin_id
  ORDER BY al.created_at DESC
  LIMIT 20
");
$logs = $st->fetchAll();
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php require __DIR__ . '/partials/head.php'; ?>
  <title>仪表盘 · 管理后台 · Sown</title>
  <link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(__DIR__.'/assets/app.css') ?>">
  <link rel="stylesheet" href="/assets/admin.css?v=<?= filemtime(__DIR__.'/assets/admin.css') ?>">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
  <?php require __DIR__ . '/partials/header.php'; ?>
  <div class="admin-layout">
    <?php require __DIR__ . '/partials/admin_sidebar.php'; ?>
    <main class="admin-main">
      <div class="admin-header">
        <h1 class="admin-title">仪表盘</h1>
      </div>

      <!-- 统计卡片 -->
      <div class="admin-stats">
        <div class="admin-stat-card">
          <div class="admin-stat-value"><?= (int)$stats['users'] ?></div>
          <div class="admin-stat-label">总用户数</div>
        </div>
        <div class="admin-stat-card">
          <div class="admin-stat-value"><?= (int)$stats['total_posts'] ?></div>
          <div class="admin-stat-label">总帖子数</div>
        </div>
        <div class="admin-stat-card">
          <div class="admin-stat-value admin-stat-warning"><?= (int)$stats['pending_posts'] ?></div>
          <div class="admin-stat-label">待审核</div>
        </div>
        <div class="admin-stat-card">
          <div class="admin-stat-value"><?= (int)$stats['shop_items'] ?></div>
          <div class="admin-stat-label">商品数</div>
        </div>
        <div class="admin-stat-card">
          <div class="admin-stat-value"><?= (int)$stats['trashed_posts'] ?></div>
          <div class="admin-stat-label">30天内删除帖</div>
        </div>
      </div>

      <?php if ($stats['trashed_posts'] > 0): ?>
      <div style="text-align:right;margin-bottom:16px;">
        <a href="javascript:;" class="btn btn-small" id="cleanupBtn">永久清理 30 天前删除的帖子</a>
      </div>
      <script>
      document.getElementById('cleanupBtn')?.addEventListener('click', function() {
        var msg = '确定永久清理 <?= $stats['trashed_posts'] ?> 条已删除超过 30 天的帖子？此操作不可恢复。';
        window.showAppConfirm(msg, { title: '确认清理', danger: true }).then(function(ok) {
        if (!ok) return;
        var fd = new FormData();
        fd.append('action', 'cleanup_trash');
        fd.append('csrf', '<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>');
        var btn = this;
        btn.textContent = '清理中...';
        btn.style.pointerEvents = 'none';
        fetch('/admin.php', {
          method: 'POST',
          body: fd,
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function() {
          window.location.reload();
        }).catch(function() {
          window.location.reload();
        });
      }); });
      </script>
      <?php endif; ?>

      <?= $cleanupMsg ?>

      <!-- 最近操作日志 -->
      <div class="admin-section">
        <h2 class="admin-section-title">最近操作</h2>
        <?php if (empty($logs)): ?>
          <div class="admin-empty">暂无操作记录</div>
        <?php else: ?>
          <table class="admin-table">
            <thead>
              <tr>
                <th width="160">时间</th>
                <th width="120">管理员</th>
                <th width="100">操作</th>
                <th>详情</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($logs as $log): ?>
              <tr>
                <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($log['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($log['username'] ?? '未知', ENT_QUOTES, 'UTF-8') ?></td>
                <td><span class="admin-badge"><?= htmlspecialchars($log['action'], ENT_QUOTES, 'UTF-8') ?></span></td>
                <td><?= htmlspecialchars($log['detail'], ENT_QUOTES, 'UTF-8') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </main>
  </div>
  <script src="/assets/app.js?v=<?= filemtime(__DIR__.'/assets/app.js') ?>"></script>
</body>
</html>
