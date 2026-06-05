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

$page = max(1, input_int('page', 1, 1, 9999, 'get'));
$perPage = 20;
$statusFilter = input_int('status', -1, -1, 3, 'get');

$where = '';
$params = [];
if ($statusFilter >= 0) {
  $where = 'WHERE o.status = ?';
  $params[] = $statusFilter;
}

// 总数
$st = $pdo->prepare("SELECT COUNT(*) AS c FROM shop_order o $where");
$st->execute($params);
$total = (int)$st->fetch()['c'];
$pagi = pagination($total, $page, $perPage);

// 查询订单（关联用户获取用户名）
$sql = "SELECT o.*, u.username
        FROM shop_order o
        LEFT JOIN user u ON u.id = o.user_id
        $where
        ORDER BY o.id DESC
        LIMIT {$pagi['perPage']} OFFSET {$pagi['offset']}";
$st = $pdo->prepare($sql);
$st->execute($params);
$orders = $st->fetchAll();

$statusLabels = ['待发货', '已发货', '已完成', '已取消'];
$statusColors = ['orange', 'blue', 'green', 'gray'];
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php require __DIR__ . '/partials/head.php'; ?>
  <title>订单管理 · 管理后台 · Sown</title>
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
        <h1 class="admin-title">订单管理</h1>
        <div class="admin-header-actions">
          <button type="button" class="btn" id="exportCsvBtn">导出 CSV</button>
          <button type="button" class="btn" id="importCsvBtn">批量上传单号</button>
        </div>
      </div>

      <!-- 状态筛选 -->
      <div class="admin-filter-bar">
        <a href="/admin_orders.php" class="admin-filter-link <?= $statusFilter < 0 ? 'active' : '' ?>">全部</a>
        <?php foreach ($statusLabels as $code => $label): ?>
          <a href="/admin_orders.php?status=<?= $code ?>" class="admin-filter-link <?= $statusFilter === $code ? 'active' : '' ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
      </div>

      <?php if (empty($orders)): ?>
        <div class="admin-empty">暂无订单</div>
      <?php else: ?>
      <table class="admin-table">
        <thead>
          <tr>
            <th width="60">ID</th>
            <th>商品</th>
            <th>用户</th>
            <th>积分</th>
            <th>收件人</th>
            <th>手机号</th>
            <th>地址</th>
            <th width="90">物流单号</th>
            <th width="70">状态</th>
            <th width="150">时间</th>
            <th width="180">操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orders as $o): ?>
          <tr data-order-id="<?= (int)$o['id'] ?>">
            <td><?= (int)$o['id'] ?></td>
            <td><?= htmlspecialchars($o['item_title'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($o['username'] ?? '用户#' . $o['user_id'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= (int)$o['cost_points'] ?></td>
            <td><?= htmlspecialchars($o['recipient_name'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($o['recipient_phone'], ENT_QUOTES, 'UTF-8') ?></td>
            <td class="admin-addr-cell" title="<?= htmlspecialchars($o['recipient_address'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(truncate($o['recipient_address'], 20), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= !empty($o['tracking_number']) ? htmlspecialchars($o['tracking_number'], ENT_QUOTES, 'UTF-8') : '-' ?></td>
            <td><span class="admin-badge admin-badge-<?= $statusColors[(int)$o['status']] ?? 'gray' ?>"><?= $statusLabels[(int)$o['status']] ?? '未知' ?></span></td>
            <td class="admin-time-cell"><?= htmlspecialchars($o['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
            <td class="admin-action-cell">
              <?php if ((int)$o['status'] === 0): ?>
                <button type="button" class="btn btn-small admin-order-ship" data-id="<?= (int)$o['id'] ?>">发货</button>
                <button type="button" class="btn btn-small admin-order-cancel" data-id="<?= (int)$o['id'] ?>">取消</button>
              <?php elseif ((int)$o['status'] === 1): ?>
                <button type="button" class="btn btn-small admin-order-done" data-id="<?= (int)$o['id'] ?>">完成</button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <!-- 分页 -->
      <?php if ($pagi['totalPages'] > 1): ?>
      <div class="admin-pagination">
        <?php if ($pagi['hasPrev']): ?>
          <a href="?page=<?= $pagi['prevPage'] ?><?= $statusFilter >= 0 ? '&status=' . $statusFilter : '' ?>" class="btn btn-small">上一页</a>
        <?php endif; ?>
        <span class="admin-page-info">第 <?= $pagi['page'] ?> / <?= $pagi['totalPages'] ?> 页（共 <?= $pagi['total'] ?> 条）</span>
        <?php if ($pagi['hasNext']): ?>
          <a href="?page=<?= $pagi['nextPage'] ?><?= $statusFilter >= 0 ? '&status=' . $statusFilter : '' ?>" class="btn btn-small">下一页</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </main>
  </div>

  <!-- 发货弹窗 -->
  <div class="admin-modal" id="shipTrackModal" hidden>
    <div class="admin-modal-backdrop" id="shipTrackBackdrop"></div>
    <div class="admin-modal-content" style="max-width:420px;">
      <div class="admin-modal-header">
        <h3 class="admin-modal-title">填写物流单号</h3>
        <button type="button" class="admin-modal-close" id="shipTrackClose">&times;</button>
      </div>
      <div class="admin-modal-body">
        <form id="shipTrackForm">
          <input type="hidden" id="shipTrackOrderId" value="0">
          <div class="admin-form-group">
            <label class="admin-form-label">物流单号</label>
            <input type="text" id="shipTrackNumber" class="admin-form-input" required maxlength="100" placeholder="请输入快递单号">
          </div>
          <div class="admin-form-actions">
            <button type="submit" class="btn primary">确认发货</button>
            <button type="button" class="btn" id="shipTrackCancel">取消</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- 批量上传单号弹窗 -->
  <div class="admin-modal" id="importCsvModal" hidden>
    <div class="admin-modal-backdrop" id="importCsvBackdrop"></div>
    <div class="admin-modal-content" style="max-width:480px;">
      <div class="admin-modal-header">
        <h3 class="admin-modal-title">批量上传物流单号</h3>
        <button type="button" class="admin-modal-close" id="importCsvClose">&times;</button>
      </div>
      <div class="admin-modal-body">
        <p style="margin:0 0 var(--sp-4);color:var(--stone);font-size:var(--fs-small);line-height:1.6;">
          请先导出 CSV，在文件中填写物流单号后上传。系统会自动识别"订单ID"和"物流单号"列，
          仅对"待发货"状态的订单进行发货操作。
        </p>
        <form id="importCsvForm">
          <div class="admin-form-group">
            <label class="admin-form-label">选择 CSV 文件</label>
            <input type="file" id="importCsvFile" class="admin-form-input" accept=".csv" required style="padding:var(--sp-2);">
          </div>
          <div class="admin-form-actions">
            <button type="submit" class="btn primary" id="importCsvSubmit">上传并发货</button>
            <button type="button" class="btn" id="importCsvCancel">取消</button>
          </div>
        </form>
        <div id="importCsvResult" style="display:none;margin-top:var(--sp-4);padding:var(--sp-3);border-radius:var(--r-container);font-size:var(--fs-small);line-height:1.6;"></div>
      </div>
    </div>
  </div>

  <script src="/assets/app.js?v=<?= filemtime(__DIR__.'/assets/app.js') ?>"></script>
  <script>
  (function() {
    var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    // 发货弹窗
    var trackModal = document.getElementById('shipTrackModal');
    var trackBackdrop = document.getElementById('shipTrackBackdrop');
    var trackClose = document.getElementById('shipTrackClose');
    var trackCancel = document.getElementById('shipTrackCancel');
    var trackForm = document.getElementById('shipTrackForm');
    var trackOrderId = document.getElementById('shipTrackOrderId');
    var trackNumber = document.getElementById('shipTrackNumber');

    function openTrackModal(orderId) {
      trackOrderId.value = String(orderId);
      trackNumber.value = '';
      trackModal.hidden = false;
      document.body.style.overflow = 'hidden';
      trackNumber.focus();
    }

    function closeTrackModal() {
      trackModal.hidden = true;
      document.body.style.overflow = '';
    }

    if (trackClose) trackClose.addEventListener('click', closeTrackModal);
    if (trackCancel) trackCancel.addEventListener('click', closeTrackModal);
    if (trackBackdrop) trackBackdrop.addEventListener('click', closeTrackModal);

    if (trackForm) {
      trackForm.addEventListener('submit', function(e) {
        e.preventDefault();
        var id = parseInt(trackOrderId.value, 10);
        var tn = trackNumber.value.trim();
        if (!id || !tn) return;

        var fd = new FormData();
        fd.append('csrf', csrf);
        fd.append('action', 'status_update');
        fd.append('order_id', String(id));
        fd.append('tracking_number', tn);
        fetch('/admin_orders_api.php', {
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

    // 发货按钮 → 弹出物流单号输入框
    document.querySelectorAll('.admin-order-ship').forEach(function(btn) {
      btn.addEventListener('click', function() {
        openTrackModal(this.getAttribute('data-id'));
      });
    });

    // 完成操作
    document.querySelectorAll('.admin-order-done').forEach(function(btn) {
      btn.addEventListener('click', function() {
        var id = this.getAttribute('data-id');
        var self = this;
        window.showAppConfirm('确定将该订单标记为已完成吗？', { title: '确认完成' }).then(function(ok) {
        if (!ok) return;
        var fd = new FormData();
        fd.append('csrf', csrf);
        fd.append('action', 'status_update');
        fd.append('order_id', String(id));
        fetch('/admin_orders_api.php', {
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
    });
    });

    // 取消操作
    document.querySelectorAll('.admin-order-cancel').forEach(function(btn) {
      btn.addEventListener('click', function() {
        var id = this.getAttribute('data-id');
        window.showAppConfirm('确定取消该订单吗？', { title: '取消订单', danger: true }).then(function(ok) {
        if (!ok) return;
        var fd = new FormData();
        fd.append('csrf', csrf);
        fd.append('action', 'status_cancel');
        fd.append('order_id', String(id));
        fetch('/admin_orders_api.php', {
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
    });
    });

    // 导出 CSV
    document.getElementById('exportCsvBtn')?.addEventListener('click', function() {
      var status = <?= $statusFilter >= 0 ? $statusFilter : '""' ?>;
      var url = '/admin_orders_api.php?action=export_csv';
      if (status !== '') url += '&status=' + status;
      window.location.href = url;
    });

    // 批量上传单号
    var importModal = document.getElementById('importCsvModal');
    var importBackdrop = document.getElementById('importCsvBackdrop');
    var importClose = document.getElementById('importCsvClose');
    var importCancel = document.getElementById('importCsvCancel');
    var importForm = document.getElementById('importCsvForm');
    var importFile = document.getElementById('importCsvFile');
    var importSubmit = document.getElementById('importCsvSubmit');
    var importResult = document.getElementById('importCsvResult');

    document.getElementById('importCsvBtn')?.addEventListener('click', function() {
      importFile.value = '';
      importResult.style.display = 'none';
      importModal.hidden = false;
      document.body.style.overflow = 'hidden';
    });

    function closeImportModal() {
      importModal.hidden = true;
      document.body.style.overflow = '';
    }
    if (importClose) importClose.addEventListener('click', closeImportModal);
    if (importCancel) importCancel.addEventListener('click', closeImportModal);
    if (importBackdrop) importBackdrop.addEventListener('click', closeImportModal);

    if (importForm) {
      importForm.addEventListener('submit', function(e) {
        e.preventDefault();
        try {
          var file = importFile.files[0];
          if (!file) { window.showAppAlert('请选择 CSV 文件'); return; }
          if (!file.name.toLowerCase().endsWith('.csv')) { window.showAppAlert('请上传 CSV 格式的文件'); return; }

          importResult.style.display = 'none';

          var fd = new FormData();
          fd.append('csrf', csrf);
          fd.append('action', 'import_tracking');
          fd.append('csv_file', file);
          importSubmit.disabled = true;
          importSubmit.textContent = '上传中…';

          fetch('/admin_orders_api.php', {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
          })
          .then(function(r) {
            return r.text().then(function(text) {
              // 尝试解析 JSON
              var ct = r.headers.get('content-type') || '';
              if (ct.indexOf('json') !== -1) {
                try {
                  var data = JSON.parse(text);
                  importResult.style.display = 'block';
                  if (data.ok) {
                    importResult.style.color = 'var(--green-dark, #5A6B2F)';
                    importResult.style.background = 'var(--green-bg, #F8FAF0)';
                    importResult.textContent = data.msg;
                    window.showAppAlert(data.msg);
                    setTimeout(function() { window.location.reload(); }, 3000);
                  } else {
                    importResult.style.color = '#b91c1c';
                    importResult.style.background = '#fef2f2';
                    importResult.textContent = data.msg || '导入失败';
                    window.showAppAlert(data.msg || '导入失败');
                  }
                  return;
                } catch(e) {}
              }
              // 非 JSON 响应，显示原始内容
              importResult.style.display = 'block';
              importResult.style.color = '#b91c1c';
              importResult.style.background = '#fef2f2';
              importResult.textContent = 'Status ' + r.status + ': ' + text;
              window.showAppAlert('Status ' + r.status + ': ' + text);
            });
          })
          .catch(function(err) {
            importResult.style.display = 'block';
            importResult.style.color = '#b91c1c';
            importResult.style.background = '#fef2f2';
            try { importResult.textContent = 'Fetch error: ' + (err.message || String(err)); } catch(e) { importResult.textContent = 'Fetch error'; }
            try { window.showAppAlert('Fetch error: ' + (err.message || String(err))); } catch(e) {}
          })
          .finally(function() {
            importSubmit.disabled = false;
            importSubmit.textContent = '上传并发货';
          });
        } catch (err) {
          window.showAppAlert('脚本错误: ' + (err.message || String(err)));
          importSubmit.disabled = false;
          importSubmit.textContent = '上传并发货';
        }
      });
    }
  })();
  </script>
</body>
</html>
