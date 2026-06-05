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

// 获取所有商品
$st = $pdo->query("SELECT * FROM shop_item ORDER BY sort_order ASC, id ASC");
$items = $st->fetchAll();
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php require __DIR__ . '/partials/head.php'; ?>
  <title>商城管理 · 管理后台 · Sown</title>
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
        <h1 class="admin-title">商城管理</h1>
        <button type="button" class="btn primary" id="addShopBtn">添加商品</button>
      </div>

      <?php if (empty($items)): ?>
        <div class="admin-empty">暂无商品</div>
      <?php else: ?>
      <table class="admin-table">
        <thead>
          <tr>
            <th width="60">ID</th>
            <th>商品名称</th>
            <th width="80">积分</th>
            <th width="100">图片</th>
            <th width="60">上架</th>
            <th width="80">重复兑换</th>
            <th width="160">操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $item): ?>
          <tr data-item-id="<?= (int)$item['id'] ?>">
            <td><?= (int)$item['id'] ?></td>
            <td><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= (int)$item['cost'] ?></td>
            <td>
              <?php if (!empty($item['image'])): ?>
                <img src="<?= htmlspecialchars($item['image'], ENT_QUOTES, 'UTF-8') ?>" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:4px;">
              <?php else: ?>
                <span class="admin-badge admin-badge-gray">无</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ((int)$item['enabled']): ?>
                <span class="admin-badge admin-badge-green">上架</span>
              <?php else: ?>
                <span class="admin-badge admin-badge-gray">下架</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!empty($item['repeatable'])): ?>
                <span class="admin-badge admin-badge-blue">可重复</span>
              <?php else: ?>
                <span class="admin-badge admin-badge-gray">单次</span>
              <?php endif; ?>
            </td>
            <td class="admin-action-cell">
              <button type="button" class="btn btn-small admin-btn-edit" data-id="<?= (int)$item['id'] ?>">编辑</button>
              <button type="button" class="btn btn-small admin-btn-del" data-id="<?= (int)$item['id'] ?>">删除</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </main>
  </div>

  <!-- 商品表单模态框 -->
  <div class="admin-modal" id="shopModal" hidden>
    <div class="admin-modal-backdrop" id="shopModalBackdrop"></div>
    <div class="admin-modal-content">
      <div class="admin-modal-header">
        <h3 class="admin-modal-title" id="shopModalTitle">添加商品</h3>
        <button type="button" class="admin-modal-close" id="shopModalClose">&times;</button>
      </div>
      <div class="admin-modal-body">
        <form id="shopForm">
          <input type="hidden" name="item_id" id="shopItemId" value="0">
          <div class="admin-form-group">
            <label class="admin-form-label">商品名称</label>
            <input type="text" name="title" id="shopTitle" class="admin-form-input" required maxlength="200">
          </div>
          <div class="admin-form-group">
            <label class="admin-form-label">描述</label>
            <textarea name="description" id="shopDesc" class="admin-form-input" rows="3" maxlength="500"></textarea>
          </div>
          <div class="admin-form-group">
            <label class="admin-form-label">所需积分</label>
            <input type="number" name="cost" id="shopCost" class="admin-form-input" required min="0">
          </div>
          <div class="admin-form-group">
            <label class="admin-form-label">图片</label>
            <div class="media-picker-wrapper" style="gap:12px;">
              <input type="hidden" name="image" id="shopImage" value="">
              <div class="media-picker-preview" id="shopImagePreview" style="cursor:pointer;width:80px;height:80px;border:2px dashed var(--border-color,#d0c8c0);border-radius:8px;display:flex;align-items:center;justify-content:center;color:var(--text-secondary,#aaa);font-size:12px;overflow:hidden;flex-shrink:0;">
                <img src="" alt="" id="shopImagePreviewImg" style="display:none;width:100%;height:100%;object-fit:cover;">
                <span id="shopImagePlaceholder">无图片</span>
              </div>
              <button type="button" class="btn media-picker-btn" id="mediaPickerBtn">从素材库选择</button>
              <button type="button" class="btn media-picker-btn" id="mediaPickerClearBtn" style="display:none;">清除</button>
            </div>
          </div>
          <div class="admin-form-group">
            <label class="admin-form-label">上架</label>
            <select name="enabled" id="shopEnabled" class="admin-form-input">
              <option value="1">上架</option>
              <option value="0">下架</option>
            </select>
          </div>
          <div class="admin-form-group">
            <label class="admin-form-checkbox">
              <input type="hidden" name="is_physical" value="0">
              <input type="checkbox" name="is_physical" id="shopIsPhysical" value="1">
              <span>实物商品（需要收货信息）</span>
            </label>
          </div>
          <div class="admin-form-group">
            <label class="admin-form-checkbox">
              <input type="hidden" name="repeatable" value="0">
              <input type="checkbox" name="repeatable" id="shopRepeatable" value="1">
              <span>可重复兑换</span>
            </label>
          </div>
          <div class="admin-form-group">
            <label class="admin-form-checkbox">
              <input type="hidden" name="is_title" value="0">
              <input type="checkbox" name="is_title" id="shopIsTitle" value="1">
              <span>设为称号（用户兑换后获得称号，可在个人主页展示）</span>
            </label>
          </div>
          <div class="admin-form-actions">
            <button type="submit" class="btn primary" id="shopSubmitBtn">保存</button>
            <button type="button" class="btn" id="shopCancelBtn">取消</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- 素材选择器弹窗 -->
  <div class="admin-modal media-picker-modal" id="mediaPickerModal" hidden>
    <div class="admin-modal-backdrop" id="mediaPickerBackdrop"></div>
    <div class="admin-modal-content">
      <div class="admin-modal-header">
        <h3 class="admin-modal-title">选择素材图片</h3>
        <button type="button" class="admin-modal-close" id="mediaPickerClose">&times;</button>
      </div>
      <div class="media-picker-body">
        <div class="admin-form-group media-picker-search">
          <input type="text" class="admin-form-input" id="mediaPickerSearch" placeholder="搜索文件名..." style="font-size:13px;">
        </div>
        <div class="media-picker-grid" id="mediaPickerGrid"></div>
        <div class="media-picker-empty" id="mediaPickerEmpty" hidden>暂无内容</div>
        <div class="media-picker-empty" id="mediaPickerLoading" hidden>加载中...</div>
      </div>
      <div class="media-picker-actions">
        <span id="mediaPickerSelectedInfo" style="font-size:12px;color:var(--text-secondary,#999);margin-right:auto;"></span>
        <button type="button" class="btn" id="mediaPickerCancel">取消</button>
        <button type="button" class="btn primary" id="mediaPickerConfirm" disabled>选择</button>
      </div>
    </div>
  </div>

  <script src="/assets/app.js?v=<?= filemtime(__DIR__.'/assets/app.js') ?>"></script>
  <script>
  (function() {
    var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    var modal = document.getElementById('shopModal');
    var backdrop = document.getElementById('shopModalBackdrop');
    var modalTitle = document.getElementById('shopModalTitle');
    var modalClose = document.getElementById('shopModalClose');
    var cancelBtn = document.getElementById('shopCancelBtn');
    var form = document.getElementById('shopForm');
    var itemIdInput = document.getElementById('shopItemId');
    var submitBtn = document.getElementById('shopSubmitBtn');
    var shopImagePreview = document.getElementById('shopImagePreview');
    var shopImagePreviewImg = document.getElementById('shopImagePreviewImg');
    var shopImagePlaceholder = document.getElementById('shopImagePlaceholder');
    var shopImageClearBtn = document.getElementById('mediaPickerClearBtn');

    function updateImagePreview(url) {
      if (url && url.trim()) {
        shopImagePreviewImg.src = url.trim();
        shopImagePreviewImg.style.display = '';
        shopImagePlaceholder.style.display = 'none';
        shopImageClearBtn.style.display = '';
        shopImagePreview.style.borderStyle = 'solid';
      } else {
        shopImagePreviewImg.style.display = 'none';
        shopImagePlaceholder.style.display = '';
        shopImageClearBtn.style.display = 'none';
        shopImagePreview.style.borderStyle = 'dashed';
      }
    }

    function openModal(title, data) {
      modalTitle.textContent = title;
      if (data) {
        itemIdInput.value = data.id || 0;
        document.getElementById('shopTitle').value = data.title || '';
        document.getElementById('shopDesc').value = data.description || '';
        document.getElementById('shopCost').value = data.cost || 0;
        document.getElementById('shopImage').value = data.image || '';
        updateImagePreview(data.image || '');
        document.getElementById('shopEnabled').value = data.enabled || '1';
        document.getElementById('shopIsPhysical').checked = data.is_physical == 1 || data.is_physical === '1';
        document.getElementById('shopRepeatable').checked = data.repeatable == 1 || data.repeatable === '1';
        document.getElementById('shopIsTitle').checked = data.is_title == 1 || data.is_title === '1';
      } else {
        form.reset();
        itemIdInput.value = 0;
        document.getElementById('shopEnabled').value = '1';
        document.getElementById('shopIsPhysical').checked = false;
        document.getElementById('shopRepeatable').checked = false;
        document.getElementById('shopIsTitle').checked = false;
        updateImagePreview('');
      }
      modal.hidden = false;
      document.body.style.overflow = 'hidden';
    }

    function closeModal() {
      modal.hidden = true;
      document.body.style.overflow = '';
    }

    // 添加按钮
    document.getElementById('addShopBtn').addEventListener('click', function() {
      openModal('添加商品', null);
    });

    // 编辑按钮
    document.querySelectorAll('.admin-btn-edit').forEach(function(btn) {
      btn.addEventListener('click', function() {
        var id = this.getAttribute('data-id');
        var row = this.closest('tr');
        var data = {
          id: id,
          title: row.querySelector('td:nth-child(2)').textContent.trim(),
          cost: row.querySelector('td:nth-child(3)').textContent.trim(),
          enabled: row.querySelector('td:nth-child(5) .admin-badge').textContent.trim() === '上架' ? '1' : '0',
        };
        // 获取详细数据（对于长描述，从 API 获取）
        var fd = new FormData();
        fd.append('csrf', csrf);
        fd.append('action', 'get');
        fd.append('item_id', id);
        fetch('/admin_shop_api.php', {
          method: 'POST',
          body: fd,
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          if (res.ok && res.data) {
            openModal('编辑商品', res.data);
          } else {
            window.showAppAlert(res.msg || '获取商品信息失败');
          }
        })
        .catch(function() { window.showAppAlert('网络错误'); });
      });
    });

    // 删除按钮
    document.querySelectorAll('.admin-btn-del').forEach(function(btn) {
      btn.addEventListener('click', function() {
        var id = this.getAttribute('data-id');
        window.showAppConfirm('确定要删除该商品吗？', { title: '删除确认', danger: true }).then(function(ok) {
        if (!ok) return;
        var fd = new FormData();
        fd.append('csrf', csrf);
        fd.append('action', 'delete');
        fd.append('item_id', id);
        fetch('/admin_shop_api.php', {
          method: 'POST',
          body: fd,
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data.ok) {
            window.location.reload();
          } else {
            window.showAppAlert(data.msg || '删除失败');
          }
        })
        .catch(function() { window.showAppAlert('网络错误'); });
      }); });
    });

    // 表单提交
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      var id = parseInt(itemIdInput.value, 10);
      var action = id > 0 ? 'update' : 'create';
      var fd = new FormData();
      fd.append('csrf', csrf);
      fd.append('action', action);
      fd.append('item_id', id);
      fd.append('title', document.getElementById('shopTitle').value);
      fd.append('description', document.getElementById('shopDesc').value);
      fd.append('cost', document.getElementById('shopCost').value);
      fd.append('image', document.getElementById('shopImage').value);
      fd.append('enabled', document.getElementById('shopEnabled').value);
      fd.append('is_physical', document.getElementById('shopIsPhysical').checked ? '1' : '0');
      fd.append('repeatable', document.getElementById('shopRepeatable').checked ? '1' : '0');
      fd.append('is_title', document.getElementById('shopIsTitle').checked ? '1' : '0');

      submitBtn.disabled = true;
      fetch('/admin_shop_api.php', {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.ok) {
          window.location.reload();
        } else {
          window.showAppAlert(data.msg || '保存失败');
        }
      })
      .catch(function() { window.showAppAlert('网络错误'); })
      .finally(function() { submitBtn.disabled = false; });
    });

    // 关闭模态框
    modalClose.addEventListener('click', closeModal);
    cancelBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('click', closeModal);
  })();

  // ─── 素材选择器（简化版，无文件夹） ─────────
  (function() {
    var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    var pickerModal = document.getElementById('mediaPickerModal');
    var pickerGrid = document.getElementById('mediaPickerGrid');
    var pickerEmpty = document.getElementById('mediaPickerEmpty');
    var pickerLoading = document.getElementById('mediaPickerLoading');
    var pickerSearch = document.getElementById('mediaPickerSearch');
    var pickerConfirm = document.getElementById('mediaPickerConfirm');
    var pickerCancel = document.getElementById('mediaPickerCancel');
    var pickerSelectedInfo = document.getElementById('mediaPickerSelectedInfo');
    var pickerCloseBtn = document.getElementById('mediaPickerClose');
    var pickerBackdrop = document.getElementById('mediaPickerBackdrop');

    var selectedFileUrl = null;
    var resolveCallback = null;
    var searchTimer = null;

    function openMediaPicker() {
      return new Promise(function(resolve) {
        resolveCallback = resolve;
        selectedFileUrl = null;
        pickerSearch.value = '';
        pickerConfirm.disabled = true;
        pickerSelectedInfo.textContent = '';
        pickerModal.hidden = false;
        document.body.style.overflow = 'hidden';
        loadPickerFiles();
      });
    }

    function closeMediaPicker() {
      pickerModal.hidden = true;
      document.body.style.overflow = '';
      if (resolveCallback) {
        resolveCallback(null);
        resolveCallback = null;
      }
    }

    function loadPickerFiles(query) {
      pickerLoading.hidden = false;
      pickerGrid.innerHTML = '';
      pickerEmpty.hidden = true;

      var fd = new FormData();
      fd.append('csrf', csrf);
      if (query) {
        fd.append('action', 'file_search');
        fd.append('q', query);
      } else {
        fd.append('action', 'file_list');
        fd.append('page', '1');
      }

      fetch('/admin_media_api.php', {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        pickerLoading.hidden = true;
        if (!res.ok) return;
        renderPickerFiles(res.files || [], query);
      })
      .catch(function() { pickerLoading.hidden = true; });
    }

    function renderPickerFiles(files, query) {
      if (!files.length) {
        pickerGrid.innerHTML = '';
        pickerEmpty.hidden = false;
        pickerEmpty.textContent = query ? '未找到匹配的图片' : '素材库暂无图片';
        return;
      }
      pickerEmpty.hidden = true;
      var html = '';
      files.forEach(function(f) {
        var url = '/uploads/media/' + f.filename;
        var sel = selectedFileUrl === url ? ' selected' : '';
        html += '<div class="media-picker-item' + sel + '" data-url="' + url + '" title="' + esc(f.original_name) + '">';
        html += '  <img src="' + url + '" alt="' + esc(f.original_name) + '" loading="lazy">';
        html += '</div>';
      });
      pickerGrid.innerHTML = html;

      pickerGrid.querySelectorAll('.media-picker-item').forEach(function(el) {
        el.addEventListener('click', function() {
          pickerGrid.querySelectorAll('.media-picker-item').forEach(function(i) { i.classList.remove('selected'); });
          this.classList.add('selected');
          selectedFileUrl = this.getAttribute('data-url');
          pickerConfirm.disabled = false;
          pickerSelectedInfo.textContent = '已选择: ' + (this.querySelector('img')?.alt || '');
        });
      });
    }

    // 搜索防抖
    pickerSearch.addEventListener('input', function() {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function() {
        var q = pickerSearch.value.trim();
        loadPickerFiles(q || null);
      }, 300);
    });

    // 确认选择
    pickerConfirm.addEventListener('click', function() {
      if (selectedFileUrl && resolveCallback) {
        var cb = resolveCallback;
        resolveCallback = null;
        closeMediaPicker();
        cb(selectedFileUrl);
      }
    });

    // 关闭
    pickerCancel.addEventListener('click', closeMediaPicker);
    pickerCloseBtn.addEventListener('click', closeMediaPicker);
    pickerBackdrop.addEventListener('click', closeMediaPicker);

    // 打开选择器按钮
    document.getElementById('mediaPickerBtn').addEventListener('click', function() {
      openMediaPicker().then(function(url) {
        if (url) {
          document.getElementById('shopImage').value = url;
          var previewImg = document.getElementById('shopImagePreviewImg');
          var placeholder = document.getElementById('shopImagePlaceholder');
          var clearBtn = document.getElementById('mediaPickerClearBtn');
          var preview = document.getElementById('shopImagePreview');
          previewImg.src = url;
          previewImg.style.display = '';
          placeholder.style.display = 'none';
          clearBtn.style.display = '';
          preview.style.borderStyle = 'solid';
        }
      });
    });

    // 清除按钮
    document.getElementById('mediaPickerClearBtn').addEventListener('click', function() {
      document.getElementById('shopImage').value = '';
      var previewImg = document.getElementById('shopImagePreviewImg');
      var placeholder = document.getElementById('shopImagePlaceholder');
      var clearBtn = document.getElementById('mediaPickerClearBtn');
      var preview = document.getElementById('shopImagePreview');
      previewImg.style.display = 'none';
      previewImg.src = '';
      placeholder.style.display = '';
      clearBtn.style.display = 'none';
      preview.style.borderStyle = 'dashed';
    });

    function esc(s) {
      if (s === null || s === undefined) return '';
      return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
  })();
</script>
</body>
</html>
