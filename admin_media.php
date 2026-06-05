<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/admin.php';

admin_ensure_schema();
require_admin();
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php require __DIR__ . '/partials/head.php'; ?>
  <title>素材库 · 管理后台 · Sown</title>
  <link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(__DIR__.'/assets/app.css') ?>">
  <link rel="stylesheet" href="/assets/admin.css?v=<?= filemtime(__DIR__.'/assets/admin.css') ?>">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
  <?php require __DIR__ . '/partials/header.php'; ?>
  <div class="admin-layout">
    <?php $currentPage = 'admin_media'; ?>
    <?php require __DIR__ . '/partials/admin_sidebar.php'; ?>
    <main class="admin-main">
      <div class="admin-header">
        <h1 class="admin-title">素材库</h1>
        <div class="admin-header-actions">
          <button type="button" class="btn primary" id="uploadBtn">上传图片</button>
        </div>
      </div>

      <!-- 文件网格 -->
      <div id="mediaFileGrid" class="media-grid"></div>

      <!-- 空状态 -->
      <div id="mediaEmpty" class="admin-empty" hidden>暂无素材图片，点击"上传图片"添加</div>

      <!-- 加载中 -->
      <div id="mediaLoading" class="admin-empty" hidden>加载中...</div>

      <!-- 分页 -->
      <div id="mediaPagination" class="admin-pagination" hidden>
        <button type="button" class="btn btn-small" id="prevPageBtn">上一页</button>
        <span class="admin-page-info" id="pageInfo"></span>
        <button type="button" class="btn btn-small" id="nextPageBtn">下一页</button>
      </div>
    </main>
  </div>

  <!-- 上传图片模态框 -->
  <div class="admin-modal" id="uploadModal" hidden>
    <div class="admin-modal-backdrop" id="uploadModalBackdrop"></div>
    <div class="admin-modal-content" style="max-width:500px;">
      <div class="admin-modal-header">
        <h3 class="admin-modal-title">上传图片</h3>
        <button type="button" class="admin-modal-close" id="uploadModalClose">&times;</button>
      </div>
      <div class="admin-modal-body">
        <div class="media-upload-zone" id="uploadDropZone">
          <p>拖拽图片到此处，或点击选择文件</p>
          <p class="media-upload-hint">支持 JPEG/PNG/GIF/WebP/SVG，最大 10MB</p>
          <input type="file" id="uploadFileInput" accept="image/jpeg,image/png,image/gif,image/webp,image/svg+xml" multiple hidden>
          <button type="button" class="btn" id="uploadSelectBtn">选择文件</button>
        </div>
        <div id="uploadProgress" class="media-upload-progress" hidden>
          <div class="media-upload-progress-text" id="uploadProgressText">上传中...</div>
          <div class="media-upload-progress-bar"><div class="media-upload-progress-fill" id="uploadProgressFill"></div></div>
        </div>
        <div class="admin-form-actions">
          <button type="button" class="btn" id="uploadDoneBtn">完成</button>
        </div>
      </div>
    </div>
  </div>

  <script src="/assets/app.js?v=<?= filemtime(__DIR__.'/assets/app.js') ?>"></script>
  <script>
  (function() {
    var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    var currentPage = 1;
    var totalFiles = 0;
    var perPage = 48;
    var isLoading = false;

    var fileGridEl = document.getElementById('mediaFileGrid');
    var emptyEl = document.getElementById('mediaEmpty');
    var loadingEl = document.getElementById('mediaLoading');
    var paginationEl = document.getElementById('mediaPagination');
    var pageInfoEl = document.getElementById('pageInfo');
    var prevBtn = document.getElementById('prevPageBtn');
    var nextBtn = document.getElementById('nextPageBtn');

    // ─── 加载文件列表 ─────────────────────────
    function loadFiles() {
      if (isLoading) return;
      isLoading = true;
      fileGridEl.innerHTML = '';
      paginationEl.hidden = true;
      loadingEl.hidden = false;
      emptyEl.hidden = true;

      var fd = new FormData();
      fd.append('csrf', csrf);
      fd.append('action', 'file_list');
      fd.append('page', String(currentPage));

      fetch('/admin_media_api.php', {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        isLoading = false;
        loadingEl.hidden = true;
        if (!res.ok) { showError(res.msg); return; }
        totalFiles = res.total || 0;
        renderFiles(res.files || []);
        if (!fileGridEl.children.length && totalFiles === 0) {
          emptyEl.hidden = false;
        } else {
          emptyEl.hidden = true;
        }
        renderPagination();
      })
      .catch(function() {
        isLoading = false;
        loadingEl.hidden = true;
        showError('网络错误');
      });
    }

    // ─── 文件渲染 ─────────────────────────
    function renderFiles(files) {
      if (!files.length) {
        fileGridEl.innerHTML = '';
        return;
      }
      var html = '';
      files.forEach(function(f) {
        var url = '/uploads/media/' + f.filename;
        var dims = '';
        if (parseInt(f.width, 10) > 0 && parseInt(f.height, 10) > 0) {
          dims = f.width + '×' + f.height;
        }
        html += '<div class="media-item" data-id="' + f.id + '">';
        html += '  <div class="media-item-thumb">';
        html += '    <img src="' + url + '" alt="' + esc(f.original_name) + '" loading="lazy">';
        html += '  </div>';
        html += '  <div class="media-item-info">';
        html += '    <div class="media-item-name" title="' + esc(f.original_name) + '">' + esc(f.original_name) + '</div>';
        if (dims) html += '    <div class="media-item-dims">' + dims + '</div>';
        html += '  </div>';
        html += '  <button type="button" class="media-item-del" title="删除" data-id="' + f.id + '">&times;</button>';
        html += '</div>';
      });
      fileGridEl.innerHTML = html;

      // 点击复制 URL
      fileGridEl.querySelectorAll('.media-item').forEach(function(item) {
        item.addEventListener('click', function(e) {
          if (e.target.closest('.media-item-del')) return;
          var img = this.querySelector('.media-item-thumb img');
          if (img) {
            var url = img.getAttribute('src');
            if (navigator.clipboard) {
              navigator.clipboard.writeText(url).then(function() {
                window.showAppToast && window.showAppToast('已复制链接: ' + url);
              }).catch(function() {});
            }
          }
        });
      });

      // 删除文件
      fileGridEl.querySelectorAll('.media-item-del').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
          e.stopPropagation();
          var id = parseInt(this.getAttribute('data-id'), 10);
          window.showAppConfirm('确定删除此图片？此操作不可恢复。', { title: '删除确认', danger: true }).then(function(ok) {
            if (!ok) return;
            var fd = new FormData();
            fd.append('csrf', csrf);
            fd.append('action', 'file_delete');
            fd.append('file_id', String(id));
            fetch('/admin_media_api.php', {
              method: 'POST',
              body: fd,
              headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
              if (res.ok) loadFiles();
              else window.showAppAlert(res.msg || '删除失败');
            })
            .catch(function() { window.showAppAlert('网络错误'); });
          });
        });
      });
    }

    // ─── 分页 ─────────────────────────
    function renderPagination() {
      var totalPages = Math.ceil(totalFiles / perPage);
      if (totalPages <= 1) {
        paginationEl.hidden = true;
        return;
      }
      paginationEl.hidden = false;
      pageInfoEl.textContent = '第 ' + currentPage + '/' + totalPages + ' 页（共 ' + totalFiles + ' 张）';
      prevBtn.disabled = currentPage <= 1;
      nextBtn.disabled = currentPage >= totalPages;
    }

    prevBtn.addEventListener('click', function() {
      if (currentPage > 1) {
        currentPage--;
        isLoading = false;
        loadFiles();
        window.scrollTo({ top: 0, behavior: 'smooth' });
      }
    });

    nextBtn.addEventListener('click', function() {
      currentPage++;
      isLoading = false;
      loadFiles();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    // ─── 上传图片 ─────────────────────────
    var uploadModal = document.getElementById('uploadModal');
    var uploadFileInput = document.getElementById('uploadFileInput');
    var uploadSelectBtn = document.getElementById('uploadSelectBtn');
    var uploadDropZone = document.getElementById('uploadDropZone');
    var uploadProgress = document.getElementById('uploadProgress');
    var uploadProgressText = document.getElementById('uploadProgressText');
    var uploadProgressFill = document.getElementById('uploadProgressFill');
    var uploadDoneBtn = document.getElementById('uploadDoneBtn');
    var modalCloseUpload = document.getElementById('uploadModalClose');
    var modalBackdropUpload = document.getElementById('uploadModalBackdrop');
    var uploadingFiles = [];

    document.getElementById('uploadBtn').addEventListener('click', function() {
      uploadingFiles = [];
      uploadProgress.hidden = true;
      uploadDropZone.hidden = false;
      uploadDoneBtn.textContent = '完成';
      uploadDoneBtn.disabled = false;
      uploadModal.hidden = false;
    });

    function closeUploadModal() {
      uploadModal.hidden = true;
    }
    modalCloseUpload.addEventListener('click', closeUploadModal);
    modalBackdropUpload.addEventListener('click', closeUploadModal);
    uploadDoneBtn.addEventListener('click', closeUploadModal);

    uploadSelectBtn.addEventListener('click', function() {
      uploadFileInput.click();
    });

    uploadFileInput.addEventListener('change', function() {
      var files = this.files;
      if (!files.length) return;
      startUpload(Array.from(files));
      this.value = '';
    });

    // 拖拽上传
    uploadDropZone.addEventListener('dragover', function(e) {
      e.preventDefault();
      e.stopPropagation();
      uploadDropZone.classList.add('media-upload-zone--active');
    });
    uploadDropZone.addEventListener('dragleave', function(e) {
      e.preventDefault();
      e.stopPropagation();
      uploadDropZone.classList.remove('media-upload-zone--active');
    });
    uploadDropZone.addEventListener('drop', function(e) {
      e.preventDefault();
      e.stopPropagation();
      uploadDropZone.classList.remove('media-upload-zone--active');
      var files = e.dataTransfer.files;
      if (files.length) startUpload(Array.from(files));
    });

    function startUpload(files) {
      uploadDropZone.hidden = true;
      uploadProgress.hidden = false;
      uploadDoneBtn.textContent = '上传中...';
      uploadDoneBtn.disabled = true;
      uploadingFiles = files.slice();
      uploadNext();
    }

    function uploadNext() {
      if (!uploadingFiles.length) {
        uploadProgressText.textContent = '上传完成';
        uploadProgressFill.style.width = '100%';
        uploadDoneBtn.textContent = '完成';
        uploadDoneBtn.disabled = false;
        loadFiles();
        return;
      }
      var file = uploadingFiles.shift();
      uploadProgressText.textContent = '正在上传: ' + file.name;

      var fd = new FormData();
      fd.append('csrf', csrf);
      fd.append('action', 'file_upload');
      fd.append('file', file);

      var xhr = new XMLHttpRequest();
      xhr.open('POST', '/admin_media_api.php', true);
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

      xhr.upload.addEventListener('progress', function(e) {
        if (e.lengthComputable) {
          uploadProgressFill.style.width = Math.round((e.loaded / e.total) * 100) + '%';
        }
      });

      xhr.addEventListener('load', function() {
        if (xhr.status === 200) {
          try {
            var res = JSON.parse(xhr.responseText);
            if (!res.ok) {
              window.showAppAlert('上传失败: ' + (res.msg || file.name));
            }
          } catch(e) {}
        } else {
          window.showAppAlert('上传失败: ' + file.name);
        }
        uploadNext();
      });

      xhr.addEventListener('error', function() {
        window.showAppAlert('上传失败: ' + file.name);
        uploadNext();
      });

      xhr.send(fd);
    }

    // ─── 工具函数 ─────────────────────────
    function esc(s) {
      if (s === null || s === undefined) return '';
      return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function showError(msg) {
      emptyEl.hidden = false;
      emptyEl.textContent = msg || '加载失败';
      isLoading = false;
      loadingEl.hidden = true;
    }

    // ─── 初始化加载 ─────────────────────────
    loadFiles();
  })();
  </script>
</body>
</html>
