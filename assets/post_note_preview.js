/**
 * 发布/编辑页：社区卡片预览 + 封面上传裁剪（Cropper.js）
 */
(function() {
  'use strict';

  function notify(msg) {
    if (typeof window.showAppAlert === 'function') {
      window.showAppAlert(msg);
    } else {
      window.alert(msg);
    }
  }

  function getCsrf() {
    var el = document.querySelector('#postNoteForm input[name="csrf"]');
    return el ? el.value : '';
  }

  function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  function excerptFromHtml(html) {
    var d = document.createElement('div');
    d.innerHTML = html || '';
    var t = (d.textContent || d.innerText || '').replace(/\s+/g, ' ').trim();
    var chars = Array.from(t);
    if (chars.length <= 180) {
      return t;
    }
    return chars.slice(0, 180).join('') + '…';
  }

  function collectImageUrls(html) {
    var urls = [];
    var re = /<img[^>]+src=["']([^"']+)["']/gi;
    var m;
    while ((m = re.exec(html || '')) !== null) {
      if (m[1].indexOf('/uploads/') === 0 || m[1].indexOf('data:image/') === 0) {
        urls.push(m[1]);
      }
    }
    return urls;
  }

  function setCardImage(wrap, url, title) {
    if (!wrap) {
      return;
    }
    var t = title || '';
    wrap.textContent = '';
    if (url) {
      wrap.classList.remove('placeholder');
      var im = document.createElement('img');
      im.src = url;
      im.alt = t;
      im.loading = 'lazy';
      im.style.width = '100%';
      im.style.height = '100%';
      im.style.objectFit = 'cover';
      wrap.appendChild(im);
    } else {
      wrap.classList.add('placeholder');
      var ph = t.length > 10 ? Array.from(t).slice(0, 10).join('') + '…' : (t || '预览');
      var phDiv = document.createElement('div');
      phDiv.className = 'placeholder-text';
      phDiv.textContent = ph;
      wrap.appendChild(phDiv);
    }
  }

  function refreshPostCommunityPreview() {
    var narrow = document.getElementById('previewCardNarrow');
    if (!narrow) {
      return;
    }

    var titleIn = document.querySelector('#postNoteForm input[name="title"]');
    var title = (titleIn && titleIn.value) ? titleIn.value.trim() : '';
    var displayTitle = title || '标题预览';

    var coverIn = document.getElementById('coverImageInput');
    var coverVal = coverIn ? coverIn.value.trim() : '';

    var q = window.sownQuillEditor;
    var html = q ? q.root.innerHTML : '';
    var imgs = collectImageUrls(html);
    var imgUrl = coverVal || imgs[0] || '';

    var excerpt = q ? excerptFromHtml(html) : '';

    var tagsHidden = document.getElementById('tagsHiddenField');
    var tagStr = tagsHidden ? tagsHidden.value.trim() : '';
    var tagNames = tagStr
      ? tagStr.split(/\s*[,，]\s*/).map(function(s) { return s.trim(); }).filter(Boolean).slice(0, 3)
      : [];

    var titleEl = narrow.querySelector('[data-preview-title]');
    var exEl = narrow.querySelector('[data-preview-excerpt]');
    var tagsEl = narrow.querySelector('[data-preview-tags]');
    var wrap = narrow.querySelector('[data-preview-img-wrap]');

    if (titleEl) {
      titleEl.textContent = displayTitle;
    }
    setCardImage(wrap, imgUrl, displayTitle);

    if (exEl) {
      exEl.textContent = '';
      exEl.style.display = 'none';
    }

    if (tagsEl) {
      if (tagNames.length) {
        tagsEl.style.display = '';
        tagsEl.innerHTML = tagNames.map(function(nm) {
          return '<span class="post-card-tag">' + escapeHtml(nm) + '</span>';
        }).join('');
      } else {
        tagsEl.style.display = 'none';
        tagsEl.innerHTML = '';
      }
    }
  }

  window.refreshPostCommunityPreview = refreshPostCommunityPreview;

  var cropperInstance = null;
  var cropObjectUrl = null;

  function closeCropModal() {
    var modal = document.getElementById('coverCropModal');
    var img = document.getElementById('coverCropImg');
    if (cropperInstance && typeof cropperInstance.destroy === 'function') {
      cropperInstance.destroy();
      cropperInstance = null;
    }
    if (cropObjectUrl) {
      URL.revokeObjectURL(cropObjectUrl);
      cropObjectUrl = null;
    }
    if (img) {
      img.src = '';
      img.removeAttribute('src');
    }
    if (modal) {
      modal.hidden = true;
    }
    var fin = document.getElementById('coverCropFileInput');
    if (fin) {
      fin.value = '';
    }
  }

  function openCropWithFile(file) {
    if (!file || !file.type || file.type.indexOf('image/') !== 0) {
      notify('请选择图片文件');
      return;
    }
    if (typeof Cropper === 'undefined') {
      notify('裁剪组件未加载，请刷新页面重试');
      return;
    }
    var modal = document.getElementById('coverCropModal');
    var img = document.getElementById('coverCropImg');
    if (!modal || !img) {
      return;
    }
    closeCropModal();
    cropObjectUrl = URL.createObjectURL(file);
    img.src = cropObjectUrl;
    modal.hidden = false;
    cropperInstance = new Cropper(img, {
      aspectRatio: 4 / 3,
      viewMode: 1,
      autoCropArea: 1,
      responsive: true
    });
  }

  function bindCropUi() {
    var uploadBtn = document.getElementById('coverUploadCropBtn');
    var fileInput = document.getElementById('coverCropFileInput');
    var modal = document.getElementById('coverCropModal');
    var cancelBtn = document.getElementById('coverCropCancel');
    var applyBtn = document.getElementById('coverCropApply');

    if (uploadBtn && fileInput) {
      uploadBtn.addEventListener('click', function() {
        fileInput.click();
      });
      fileInput.addEventListener('change', function() {
        var f = fileInput.files && fileInput.files[0];
        if (f) {
          openCropWithFile(f);
        }
      });
    }

    if (cancelBtn) {
      cancelBtn.addEventListener('click', closeCropModal);
    }
    if (modal) {
      modal.querySelectorAll('[data-cover-crop-close]').forEach(function(el) {
        el.addEventListener('click', closeCropModal);
      });
    }

    if (applyBtn) {
      applyBtn.addEventListener('click', function() {
        if (!cropperInstance || typeof cropperInstance.getCroppedCanvas !== 'function') {
          notify('请先选择并裁剪图片');
          return;
        }
        var canvas = cropperInstance.getCroppedCanvas({
          maxWidth: 1600,
          maxHeight: 1200,
          imageSmoothingEnabled: true,
          imageSmoothingQuality: 'high'
        });
        if (!canvas || !canvas.toBlob) {
          notify('无法生成图片，请重试');
          return;
        }
        applyBtn.disabled = true;
        canvas.toBlob(function(blob) {
          if (!blob) {
            applyBtn.disabled = false;
            notify('导出图片失败');
            return;
          }
          var fd = new FormData();
          fd.append('csrf', getCsrf());
          fd.append('image', blob, 'cover.jpg');
          fetch('/image_upload.php', {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
          })
            .then(function(r) { return r.json(); })
            .then(function(data) {
              applyBtn.disabled = false;
              if (data && data.success && data.url) {
                var coverIn = document.getElementById('coverImageInput');
                if (coverIn) {
                  coverIn.value = data.url;
                }
                closeCropModal();
                if (typeof window.sownRefreshCoverImageSelection === 'function') {
                  window.sownRefreshCoverImageSelection();
                } else {
                  refreshPostCommunityPreview();
                }
              } else {
                notify((data && data.error) ? data.error : '上传失败');
              }
            })
            .catch(function() {
              applyBtn.disabled = false;
              notify('网络错误，请重试');
            });
        }, 'image/jpeg', 0.88);
      });
    }
  }

  function bindTitleAndPollQuill() {
    var titleIn = document.querySelector('#postNoteForm input[name="title"]');
    if (titleIn) {
      titleIn.addEventListener('input', refreshPostCommunityPreview);
    }

    var n = 0;
    var id = setInterval(function() {
      n++;
      if (window.sownQuillEditor) {
        window.sownQuillEditor.on('text-change', refreshPostCommunityPreview);
        refreshPostCommunityPreview();
        clearInterval(id);
      } else if (n > 120) {
        clearInterval(id);
        refreshPostCommunityPreview();
      }
    }, 50);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
      bindCropUi();
      bindTitleAndPollQuill();
    });
  } else {
    bindCropUi();
    bindTitleAndPollQuill();
  }
})();
