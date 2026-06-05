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

// 筛选
$filter = input_string('filter', 'all'); // all | published | draft | pending | rejected
$page = input_int('page', 1, 1, 2000);
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = '1=1';
$params = [];

switch ($filter) {
  case 'published':
    $where = 'p.status = 1 AND (p.review_status IS NULL OR p.review_status = 0)';
    break;
  case 'draft':
    $where = 'p.status = 2';
    break;
  case 'pending':
    $where = 'p.status = 1 AND p.review_status = 1';
    break;
  case 'rejected':
    $where = 'p.status = 1 AND p.review_status = 2';
    break;
  case 'all':
  default:
    $where = '1=1';
    break;
}

// 总数
$st = $pdo->prepare("SELECT COUNT(*) AS c FROM post p WHERE {$where}");
$st->execute($params);
$total = (int)$st->fetch()['c'];
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

// 取数据
$st = $pdo->prepare("
  SELECT p.id, p.title, p.status, p.review_status, p.created_at,
         u.id AS user_id, u.username
  FROM post p
  JOIN user u ON u.id = p.user_id
  WHERE {$where}
  ORDER BY p.created_at DESC
  LIMIT :limit OFFSET :offset
");
$st->bindValue(':limit', $perPage, PDO::PARAM_INT);
$st->bindValue(':offset', $offset, PDO::PARAM_INT);
$st->execute();
$posts = $st->fetchAll();

$pag = pagination($total, $page, $perPage);
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php require __DIR__ . '/partials/head.php'; ?>
  <title>帖子审核 · 管理后台 · Sown</title>
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
        <h1 class="admin-title">帖子审核</h1>
      </div>

      <!-- 筛选标签 -->
      <div class="admin-tabs">
        <a href="?filter=all" class="admin-tab <?= $filter === 'all' ? 'active' : '' ?>">全部</a>
        <a href="?filter=published" class="admin-tab <?= $filter === 'published' ? 'active' : '' ?>">已发布</a>
        <a href="?filter=draft" class="admin-tab <?= $filter === 'draft' ? 'active' : '' ?>">草稿</a>
        <a href="?filter=pending" class="admin-tab <?= $filter === 'pending' ? 'active' : '' ?>">待审核</a>
        <a href="?filter=rejected" class="admin-tab <?= $filter === 'rejected' ? 'active' : '' ?>">已拒绝</a>
      </div>

      <!-- 批量操作 -->
      <div class="admin-batch-bar" id="batchBar" style="display:none;">
        <span class="admin-batch-count" id="batchCount">已选择 0 篇</span>
        <button type="button" class="btn primary btn-small" id="batchApproveBtn">批量批准</button>
        <button type="button" class="btn btn-small" id="batchRejectBtn">批量拒绝</button>
        <button type="button" class="btn btn-small" id="batchClearBtn">清除选择</button>
      </div>

      <?php if (empty($posts)): ?>
        <div class="admin-empty">暂无数据</div>
      <?php else: ?>
      <table class="admin-table">
        <thead>
          <tr>
            <th width="40"><input type="checkbox" id="selectAll"></th>
            <th width="60">ID</th>
            <th>标题</th>
            <th width="120">作者</th>
            <th width="80">状态</th>
            <th width="80">审核</th>
            <th width="160">创建时间</th>
            <th width="200">操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($posts as $p):
            $statusLabel = '';
            if ((int)$p['status'] === 0) $statusLabel = '<span class="admin-badge admin-badge-gray">已删除</span>';
            elseif ((int)$p['status'] === 2) $statusLabel = '<span class="admin-badge admin-badge-gray">草稿</span>';
            else $statusLabel = '<span class="admin-badge admin-badge-green">已发布</span>';

            $rs = (int)$p['review_status'];
            if ((int)$p['status'] !== 1) {
              $reviewLabel = '<span class="admin-badge admin-badge-gray">-</span>';
            } elseif ($rs === 0) {
              $reviewLabel = '<span class="admin-badge admin-badge-green">已通过</span>';
            } elseif ($rs === 1) {
              $reviewLabel = '<span class="admin-badge admin-badge-yellow">待审核</span>';
            } elseif ($rs === 2) {
              $reviewLabel = '<span class="admin-badge admin-badge-red">已拒绝</span>';
            } else {
              $reviewLabel = '<span class="admin-badge admin-badge-gray">-</span>';
            }
          ?>
          <tr data-post-id="<?= (int)$p['id'] ?>">
            <td><input type="checkbox" class="post-checkbox" value="<?= (int)$p['id'] ?>"></td>
            <td><?= (int)$p['id'] ?></td>
            <td class="admin-title-cell">
              <a href="/post.php?id=<?= (int)$p['id'] ?>" target="_blank"><?= htmlspecialchars(mb_strlen($p['title']) > 50 ? mb_substr($p['title'], 0, 50) . '…' : $p['title'], ENT_QUOTES, 'UTF-8') ?></a>
            </td>
            <td><a href="/user.php?id=<?= (int)$p['user_id'] ?>" target="_blank"><?= htmlspecialchars($p['username'], ENT_QUOTES, 'UTF-8') ?></a></td>
            <td><?= $statusLabel ?></td>
            <td data-review-status="<?= $rs ?>"><?= $reviewLabel ?></td>
            <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($p['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
            <td class="admin-action-cell">
              <?php if ((int)$p['status'] === 1): ?>
                <?php if ($rs !== 0): ?>
                  <button type="button" class="btn btn-small admin-btn-approve" data-id="<?= (int)$p['id'] ?>">批准</button>
                <?php endif; ?>
                <?php if ($rs !== 2): ?>
                  <button type="button" class="btn btn-small admin-btn-reject" data-id="<?= (int)$p['id'] ?>">拒绝</button>
                <?php endif; ?>
              <?php endif; ?>
              <?php if ((int)$p['status'] !== 0): ?>
                <button type="button" class="btn btn-small admin-btn-delete" data-id="<?= (int)$p['id'] ?>">删除</button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <!-- 分页 -->
      <?php if ($pag['totalPages'] > 1): ?>
      <div class="admin-pagination">
        <?php if ($pag['hasPrev']): ?>
          <a href="?filter=<?= urlencode($filter) ?>&page=<?= $pag['prevPage'] ?>" class="btn btn-small">上一页</a>
        <?php endif; ?>
        <span class="admin-page-info">第 <?= $pag['page'] ?> / <?= $pag['totalPages'] ?> 页</span>
        <?php if ($pag['hasNext']): ?>
          <a href="?filter=<?= urlencode($filter) ?>&page=<?= $pag['nextPage'] ?>" class="btn btn-small">下一页</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </main>
  </div>
  <script src="/assets/app.js?v=<?= filemtime(__DIR__.'/assets/app.js') ?>"></script>
  <script>
  (function() {
    var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    // 单个操作
    document.querySelectorAll('.admin-btn-approve, .admin-btn-reject, .admin-btn-delete').forEach(function(btn) {
      btn.addEventListener('click', function() {
        var id = this.getAttribute('data-id');
        var action = '';
        if (this.classList.contains('admin-btn-approve')) action = 'approve';
        else if (this.classList.contains('admin-btn-reject')) action = 'reject';
        else if (this.classList.contains('admin-btn-delete')) action = 'delete';

        var confirmMsg = action === 'delete' ? '确定要永久删除这篇帖子吗？此操作不可撤销。' :
                         action === 'reject' ? '确定要拒绝这篇帖子吗？' :
                         '确定要批准这篇帖子吗？';

        var self = this;
        window.showAppConfirm(confirmMsg, { title: '确认操作', danger: true }).then(function(ok) {
        if (!ok) return;
        var fd = new FormData();
        fd.append('csrf', csrf);
        fd.append('action', action);
        fd.append('post_id', id);

        fetch('/admin_posts_api.php', {
          method: 'POST',
          body: fd,
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data.ok) {
            window.location.reload();
          } else {
            window.showAppAlert(data.msg || '操作失败');
          }
        })
        .catch(function() { window.showAppAlert('网络错误'); });
      }); });
    });

    // 全选
    var selectAll = document.getElementById('selectAll');
    var batchBar = document.getElementById('batchBar');
    var batchCount = document.getElementById('batchCount');

    if (selectAll) {
      selectAll.addEventListener('change', function() {
        document.querySelectorAll('.post-checkbox').forEach(function(cb) {
          cb.checked = selectAll.checked;
        });
        updateBatchBar();
      });
    }

    document.querySelectorAll('.post-checkbox').forEach(function(cb) {
      cb.addEventListener('change', updateBatchBar);
    });

    function updateBatchBar() {
      var checked = document.querySelectorAll('.post-checkbox:checked');
      if (checked.length > 0) {
        batchBar.style.display = 'flex';
        batchCount.textContent = '已选择 ' + checked.length + ' 篇';
      } else {
        batchBar.style.display = 'none';
      }
    }

    // 批量操作
    function doBatch(action) {
      var checked = document.querySelectorAll('.post-checkbox:checked');
      if (checked.length === 0) return;
      var ids = [];
      checked.forEach(function(cb) { ids.push(cb.value); });
      var confirmMsg = action === 'batch_reject' ? '确定批量拒绝 ' + ids.length + ' 篇帖子？' :
                       '确定批量批准 ' + ids.length + ' 篇帖子？';
      window.showAppConfirm(confirmMsg, { title: '确认操作', danger: true }).then(function(ok) {
      if (!ok) return;

      var fd = new FormData();
      fd.append('csrf', csrf);
      fd.append('action', action);
      fd.append('post_ids', ids.join(','));

      fetch('/admin_posts_api.php', {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.ok) {
          window.location.reload();
        } else {
          window.showAppAlert(data.msg || '操作失败');
        }
      })
      .catch(function() { window.showAppAlert('网络错误'); });
      });
    }

    var batchApproveBtn = document.getElementById('batchApproveBtn');
    var batchRejectBtn = document.getElementById('batchRejectBtn');
    var batchClearBtn = document.getElementById('batchClearBtn');

    if (batchApproveBtn) batchApproveBtn.addEventListener('click', function() { doBatch('batch_approve'); });
    if (batchRejectBtn) batchRejectBtn.addEventListener('click', function() { doBatch('batch_reject'); });
    if (batchClearBtn) batchClearBtn.addEventListener('click', function() {
      document.querySelectorAll('.post-checkbox').forEach(function(cb) { cb.checked = false; });
      updateBatchBar();
    });
  })();
  </script>
</body>
</html>
