/**
 * Sown / 数问 - 通用 JavaScript 逻辑
 * 轻量级，无依赖
 */

(function() {
  'use strict';

  // ============================================
  // Fetch JSON 容错处理
  // ============================================

  /**
   * 安全的 fetch JSON 请求
   * @param {string} url - 请求URL
   * @param {RequestInit} options - fetch选项
   * @returns {Promise<{ok: boolean, data?: any, msg?: string, code?: string}>}
   */
  window.safeFetchJSON = async function(url, options) {
    options = options || {};
    
    try {
      // 如果是 FormData，让浏览器自动设置 Content-Type（包含 boundary）
      // 不要手动设置 application/x-www-form-urlencoded
      var headers = options.headers || {};
      if (options.body instanceof FormData) {
        // 删除手动设置的 Content-Type，让浏览器自动处理
        delete headers['Content-Type'];
      }
      
      var response = await fetch(url, {
        method: options.method || 'GET',
        body: options.body,
        credentials: 'same-origin',
        headers: headers
      });

      // 尝试解析 JSON（即使状态码不是200，也可能有JSON响应）
      var data;
      try {
        data = await response.json();
      } catch (e) {
        // 如果无法解析JSON，检查响应状态
        if (!response.ok) {
          var statusText = response.statusText || 'Bad Request';
          return {
            ok: false,
            msg: '请求失败：' + statusText,
            code: 'HTTP_ERROR'
          };
        }
        return {
          ok: false,
          msg: '响应格式错误',
          code: 'PARSE_ERROR'
        };
      }

      // 检查登录状态（优先处理LOGIN代码，即使状态码不是200）
      if (data.code === 'LOGIN' && data.login) {
        // 优先弹出登录弹窗，其次再整页跳转
        if (window.openLoginModal) {
          window.openLoginModal(data.login);
        } else {
          window.location.href = data.login;
        }
        return {
          ok: false,
          msg: '请先登录',
          code: 'LOGIN'
        };
      }

      // 检查响应状态（在检查LOGIN之后）
      if (!response.ok) {
        var statusText = response.statusText || 'Bad Request';
        return {
          ok: false,
          msg: data.msg || ('请求失败：' + statusText),
          code: data.code || 'HTTP_ERROR'
        };
      }

      return {
        ok: data.ok !== false,
        data: data.data !== undefined ? data.data : data,
        msg: data.msg || '',
        code: data.code || ''
      };
    } catch (error) {
      console.error('Fetch error:', error);
      return {
        ok: false,
        msg: '网络错误，请稍后重试',
        code: 'NETWORK_ERROR'
      };
    }
  };

  // ============================================
  // 全局提示 / 确认弹窗（与全站黑白灰、圆角卡片风格一致）
  // ============================================

  var _appDialogState = { mode: 'alert', resolve: null };

  function closeAppDialog(result) {
    var root = document.getElementById('appDialogRoot');
    if (!root) return;
    var mode = _appDialogState.mode;
    var fn = _appDialogState.resolve;
    _appDialogState.resolve = null;
    root.setAttribute('hidden', '');
    root.classList.remove('app-dialog-backdrop--visible');
    var loginOpen =
      document.getElementById('loginModal') && document.getElementById('loginModal').style.display === 'flex';
    var regOpen =
      document.getElementById('registerModal') && document.getElementById('registerModal').style.display === 'flex';
    if (!loginOpen && !regOpen) {
      document.body.style.overflow = '';
    }
    if (!fn) return;
    if (mode === 'confirm') {
      fn(!!result);
    } else {
      fn();
    }
  }

  function ensureAppDialog() {
    var root = document.getElementById('appDialogRoot');
    if (root) return root;
    root = document.createElement('div');
    root.id = 'appDialogRoot';
    root.className = 'app-dialog-backdrop';
    root.setAttribute('hidden', '');
    root.innerHTML =
      '<div class="app-dialog" role="dialog" aria-modal="true" aria-labelledby="appDialogTitle">' +
      '<div class="app-dialog__header">' +
      '<h2 class="app-dialog__title" id="appDialogTitle"></h2>' +
      '</div>' +
      '<div class="app-dialog__body" id="appDialogMessage"></div>' +
      '<div class="app-dialog__footer">' +
      '<button type="button" class="btn btn-secondary app-dialog__btn-cancel">取消</button>' +
      '<button type="button" class="btn primary app-dialog__btn-ok">确定</button>' +
      '</div>' +
      '</div>';
    document.body.appendChild(root);

    root.addEventListener('click', function(e) {
      if (e.target === root) {
        closeAppDialog(_appDialogState.mode === 'confirm' ? false : true);
      }
    });
    root.querySelector('.app-dialog').addEventListener('click', function(e) {
      e.stopPropagation();
    });
    root.querySelector('.app-dialog__btn-cancel').addEventListener('click', function() {
      closeAppDialog(false);
    });
    root.querySelector('.app-dialog__btn-ok').addEventListener('click', function() {
      closeAppDialog(true);
    });

    if (!window._appDialogEscBound) {
      window._appDialogEscBound = true;
      document.addEventListener('keydown', function(e) {
        if (e.key !== 'Escape') return;
        var el = document.getElementById('appDialogRoot');
        if (!el || el.hasAttribute('hidden')) return;
        e.preventDefault();
        closeAppDialog(_appDialogState.mode === 'confirm' ? false : true);
      });
    }
    return root;
  }

  window.showAppAlert = function(message, opts) {
    opts = opts || {};
    return new Promise(function(resolve) {
      _appDialogState.mode = 'alert';
      _appDialogState.resolve = resolve;
      var root = ensureAppDialog();
      root.querySelector('#appDialogTitle').textContent = opts.title || '提示';
      root.querySelector('#appDialogMessage').textContent = message || '';
      var cancelBtn = root.querySelector('.app-dialog__btn-cancel');
      cancelBtn.style.display = 'none';
      var okBtn = root.querySelector('.app-dialog__btn-ok');
      okBtn.textContent = opts.confirmText || '知道了';
      okBtn.className = 'btn primary app-dialog__btn-ok';
      root.removeAttribute('hidden');
      root.classList.add('app-dialog-backdrop--visible');
      document.body.style.overflow = 'hidden';
      setTimeout(function() {
        okBtn.focus();
      }, 0);
    });
  };

  window.showAppConfirm = function(message, opts) {
    opts = opts || {};
    return new Promise(function(resolve) {
      _appDialogState.mode = 'confirm';
      _appDialogState.resolve = resolve;
      var root = ensureAppDialog();
      root.querySelector('#appDialogTitle').textContent = opts.title || '确认操作';
      root.querySelector('#appDialogMessage').textContent = message || '';
      var cancelBtn = root.querySelector('.app-dialog__btn-cancel');
      cancelBtn.style.display = '';
      cancelBtn.textContent = opts.cancelText || '取消';
      var okBtn = root.querySelector('.app-dialog__btn-ok');
      okBtn.textContent = opts.confirmText || '确定';
      okBtn.className = opts.danger
        ? 'btn btn-danger app-dialog__btn-ok'
        : 'btn primary app-dialog__btn-ok';
      root.removeAttribute('hidden');
      root.classList.add('app-dialog-backdrop--visible');
      document.body.style.overflow = 'hidden';
      setTimeout(function() {
        okBtn.focus();
      }, 0);
    });
  };

  document.addEventListener(
    'submit',
    function(e) {
      var form = e.target;
      if (!form || form.nodeName !== 'FORM' || !form.hasAttribute('data-confirm')) return;
      e.preventDefault();
      e.stopPropagation();
      var msg = form.getAttribute('data-confirm');
      if (!msg) return;
      var title = form.getAttribute('data-confirm-title') || '确认操作';
      var danger = form.getAttribute('data-confirm-danger') !== '0';
      window.showAppConfirm(msg, { title: title, danger: danger }).then(function(ok) {
        if (ok) form.submit();
      });
    },
    true
  );

  // ============================================
  // 登录跳转处理
  // ============================================

  /**
   * 检查是否需要登录，如果需要则弹窗或跳转
   * @param {string} next - 登录后跳转的URL
   */
  window.requireLogin = function(next) {
    var loginUrl = '/login.php?next=' + encodeURIComponent(next || window.location.href);
    if (window.openLoginModal) {
      window.openLoginModal(loginUrl);
    } else {
      window.location.href = loginUrl;
    }
  };

  // ============================================
  // 登录弹窗逻辑
  // ============================================

  function parseNextFromLoginUrl(loginUrl) {
    try {
      var url = new URL(loginUrl, window.location.origin);
      return url.searchParams.get('next') || window.location.href;
    } catch (e) {
      return window.location.href;
    }
  }

  window.openLoginModal = function(loginUrl) {
    // 先关闭可能已打开的注册/登录弹窗，再打开登录弹窗
    if (window.closeLoginModal) {
      window.closeLoginModal();
    }

    var modal = document.getElementById('loginModal');
    if (!modal) {
      window.location.href = loginUrl || '/login.php';
      return false;
    }

    var next = parseNextFromLoginUrl(loginUrl || '/login.php?next=' + encodeURIComponent(window.location.href));

    // 设置两个表单的 next 值
    var nextInputs = modal.querySelectorAll('input[name="next"]');
    nextInputs.forEach(function(el) { el.value = next; });

    var errEl = document.getElementById('loginModalErr');
    if (errEl) {
      errEl.style.display = 'none';
      errEl.textContent = '';
    }

    var registerLink = document.getElementById('loginModalRegisterLink');
    if (registerLink) {
      registerLink.href = '/register.php?next=' + encodeURIComponent(next);
    }

    // 切换到密码登录 tab
    switchLoginModalTab('password');

    modal.style.display = 'flex';

    var emailInput = modal.querySelector('#loginModalForm input[name="email"]');
    if (emailInput) {
      setTimeout(function() {
        emailInput.focus();
      }, 0);
    }

    document.body.style.overflow = 'hidden';
    return false;
  };

  window.closeLoginModal = function() {
    // 关闭所有登录/注册弹窗
    ['loginModal', 'registerModal'].forEach(function(id) {
      var m = document.getElementById(id);
      if (m) m.style.display = 'none';
    });
    document.body.style.overflow = '';
  };

  // 登录弹窗表单：AJAX 提交，失败时在弹窗内显示错误，不跳转到旧登录页
  var loginModalForm = document.getElementById('loginModalForm');
  if (loginModalForm) {
    loginModalForm.addEventListener('submit', function(e) {
      e.preventDefault();
      var form = this;
      var errEl = document.getElementById('loginModalErr');
      var submitBtn = form.querySelector('button[type="submit"]');

      if (errEl) {
        errEl.style.display = 'none';
        errEl.textContent = '';
      }
      if (submitBtn && window.setButtonLoading) {
        window.setButtonLoading(submitBtn, true);
      }

      var formData = new FormData(form);
      fetch('/login_post.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function(res) { return res.json().then(function(data) { return { ok: res.ok, data: data }; }); })
        .then(function(result) {
          if (result.data.ok) {
            window.closeLoginModal && window.closeLoginModal();
            window.location.href = result.data.redirect || '/forum.php';
            return;
          }
          if (errEl) {
            errEl.textContent = result.data.msg || '登录失败，请重试';
            errEl.style.display = 'block';
          } else {
            window.showAppAlert(result.data.msg || '登录失败，请重试');
          }
        })
        .catch(function() {
          if (errEl) {
            errEl.textContent = '网络错误，请稍后重试';
            errEl.style.display = 'block';
          } else {
            window.showAppAlert('网络错误，请稍后重试');
          }
        })
        .finally(function() {
          if (submitBtn && window.setButtonLoading) {
            window.setButtonLoading(submitBtn, false);
          }
        });
    });
  }

  // 登录弹窗 tab 切换
  window.switchLoginModalTab = function(tab) {
    var tabs = document.querySelectorAll('.login-modal-tab');
    var contents = document.querySelectorAll('.login-modal-tab-content');
    tabs.forEach(function(t) { t.classList.toggle('active', t.getAttribute('data-modal-tab') === tab); });
    contents.forEach(function(c) { c.style.display = c.getAttribute('data-modal-tab-content') === tab ? '' : 'none'; });
  };

  // 登录弹窗 tab 点击
  document.querySelectorAll('.login-modal-tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
      var target = this.getAttribute('data-modal-tab');
      window.switchLoginModalTab(target);
      // 清除错误消息
      var errs = document.querySelectorAll('#loginModal .alert.error');
      errs.forEach(function(el) { el.style.display = 'none'; el.textContent = ''; });
    });
  });

  // 注册弹窗表单：AJAX 提交，失败时在弹窗内显示错误，不跳转到旧注册页
  var registerModalForm = document.getElementById('registerModalForm');
  if (registerModalForm) {
    registerModalForm.addEventListener('submit', function(e) {
      e.preventDefault();
      var form = this;
      var errEl = document.getElementById('registerModalErr');
      var submitBtn = form.querySelector('button[type="submit"]');

      if (errEl) {
        errEl.style.display = 'none';
        errEl.textContent = '';
      }

      // 手机号客户端验证（必填）
      var phoneVal = (form.querySelector('input[name="phone"]') || {}).value || '';
      if (!/^1\d{10}$/.test(phoneVal.trim())) {
        if (errEl) {
          errEl.textContent = '请输入有效的11位手机号';
          errEl.style.display = 'block';
        }
        return;
      }

      if (submitBtn && window.setButtonLoading) {
        window.setButtonLoading(submitBtn, true);
      }

      var formData = new FormData(form);
      fetch('/register_post.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function(res) { return res.json().then(function(data) { return { ok: res.ok, data: data }; }); })
        .then(function(result) {
          if (result.data.ok) {
            window.closeLoginModal && window.closeLoginModal();
            // 解析 redirect URL 获取 next 参数，打开登录弹窗
            var redirectUrl = result.data.redirect || '/login.php?next=/forum.php';
            var next = parseNextFromLoginUrl(redirectUrl);
            var loginUrl = '/login.php?next=' + encodeURIComponent(next);
            window.openLoginModal(loginUrl);

            // 在登录弹窗中显示成功消息并自动填充邮箱
            setTimeout(function() {
              var loginErrEl = document.getElementById('loginModalErr');
              if (loginErrEl) {
                loginErrEl.textContent = '注册成功！请登录您的账户。';
                loginErrEl.className = 'alert success';
                loginErrEl.style.display = 'block';
              }
              // 自动填充注册时使用的邮箱
              var emailInput = document.querySelector('#loginModal input[name="email"]');
              if (emailInput) {
                var registeredEmail = form.querySelector('input[name="email"]').value;
                emailInput.value = registeredEmail;
                // 将焦点设置到密码输入框
                var passwordInput = document.querySelector('#loginModal input[name="password"]');
                if (passwordInput) {
                  setTimeout(function() { passwordInput.focus(); }, 20);
                }
              }
            }, 10);
            return;
          }
          if (errEl) {
            errEl.textContent = result.data.msg || '注册失败，请重试';
            errEl.style.display = 'block';
          } else {
            window.showAppAlert(result.data.msg || '注册失败，请重试');
          }
        })
        .catch(function() {
          if (errEl) {
            errEl.textContent = '网络错误，请稍后重试';
            errEl.style.display = 'block';
          } else {
            window.showAppAlert('网络错误，请稍后重试');
          }
        })
        .finally(function() {
          if (submitBtn && window.setButtonLoading) {
            window.setButtonLoading(submitBtn, false);
          }
        });
    });
  }

  /**
   * 打开注册弹窗
   * @param {string} registerUrl - 带 next 的注册URL
   */
  window.openRegisterModal = function(registerUrl) {
    var modal = document.getElementById('registerModal');
    if (!modal) {
      window.location.href = registerUrl || '/register.php';
      return false;
    }

    var next = parseNextFromLoginUrl(registerUrl || '/register.php?next=' + encodeURIComponent(window.location.href));

    var nextInput = document.getElementById('registerModalNext');
    if (nextInput) {
      nextInput.value = next;
    }

    var loginLink = document.getElementById('registerModalLoginLink');
    if (loginLink) {
      loginLink.href = '/login.php?next=' + encodeURIComponent(next);
    }

    // 清除之前的错误消息
    var errEl = document.getElementById('registerModalErr');
    if (errEl) {
      errEl.style.display = 'none';
      errEl.textContent = '';
    }

    modal.style.display = 'flex';

    var usernameInput = modal.querySelector('input[name="username"]') || modal.querySelector('input[name="email"]');
    if (usernameInput) {
      setTimeout(function() {
        usernameInput.focus();
      }, 0);
    }

    document.body.style.overflow = 'hidden';
    return false;
  };

  // ============================================
  // 按钮 Loading 状态
  // ============================================

  /**
   * 设置按钮为 loading 状态
   * @param {HTMLElement} button - 按钮元素
   * @param {boolean} loading - 是否loading
   */
  window.setButtonLoading = function(button, loading) {
    if (!button) return;
    
    if (loading) {
      button.disabled = true;
      button.classList.add('loading');

      // 对于搜索按钮，不清空内容（保留放大镜图标）
      if (button.classList.contains('navSearchBtn')) {
        return;
      }

      // 保存原始文字（优先使用textContent，如果没有则使用innerText）
      var originalText = button.textContent || button.innerText || '';
      if (originalText.trim()) {
        button.dataset.originalText = originalText.trim();
      }
      button.textContent = '';
      button.innerHTML = '';
    } else {
      button.disabled = false;
      button.classList.remove('loading');

      // 对于搜索按钮，不需要恢复文字（内容未被清空）
      if (button.classList.contains('navSearchBtn')) {
        return;
      }

      // 恢复原始文字，如果没有保存则使用默认值
      var restoreText = button.dataset.originalText || '登录';
      button.textContent = restoreText;
      if (button.dataset.originalText) {
        delete button.dataset.originalText;
      }
    }
  };

  // ============================================
  // 用户下拉菜单
  // ============================================

  function initUserMenu() {
    var userMenus = document.querySelectorAll('.user-menu');

    userMenus.forEach(function(userMenu) {
      var userAvatar = userMenu.querySelector('.user-avatar');
      var userDropdown = userMenu.querySelector('.user-dropdown');

      if (userAvatar && userDropdown) {
        userAvatar.addEventListener('click', function(e) {
          e.preventDefault();
          e.stopPropagation();

          // 关闭其他菜单
          document.querySelectorAll('.user-menu').forEach(function(um) {
            if (um !== userMenu) {
              um.classList.remove('active');
            }
          });

          // 切换当前菜单
          userMenu.classList.toggle('active');
          userDropdown.classList.toggle('active');
        });
      }
    });

    // 点击外部关闭菜单
    document.addEventListener('click', function(e) {
      if (!e.target.closest('.user-menu')) {
        document.querySelectorAll('.user-menu').forEach(function(um) {
          um.classList.remove('active');
        });
        document.querySelectorAll('.user-dropdown').forEach(function(dd) {
          dd.classList.remove('active');
        });
      }
    });
  }

  // ============================================
  // 表单提交处理
  // ============================================

  /**
   * 为表单添加 loading 状态
   */
  function initFormLoading() {
    document.querySelectorAll('form').forEach(function(form) {
      // 跳过搜索表单，避免放大镜图标消失
      if (form.classList.contains('navSearchForm')) return;
      // 跳过评论表单，由 post.php 的 AJAX 处理
      if (form.id === 'commentForm') return;

      form.addEventListener('submit', function() {
        var submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) {
          setButtonLoading(submitBtn, true);
        }
      });
    });
  }

  /**
   * 重置搜索按钮状态（防止浏览器后退时按钮处于 loading 状态）
   */
  function resetSearchButtons() {
    document.querySelectorAll('.navSearchBtn').forEach(function(btn) {
      btn.disabled = false;
      btn.classList.remove('loading');
      if (btn.dataset.originalText) {
        delete btn.dataset.originalText;
      }
    });
  }

  // ============================================
  // 初始化
  // ============================================

  // DOM 加载完成后初始化
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
      resetSearchButtons();
      initUserMenu();
      initFormLoading();
      initLoginLinks();
      initRegisterLinks();
      initLoginModalMisc();
      checkPendingRewards();
    });
  } else {
    resetSearchButtons();
    initUserMenu();
    initFormLoading();
    initLoginLinks();
    initRegisterLinks();
    initLoginModalMisc();
    checkPendingRewards();
  }

  // 绑定导航栏「登录」/「注册」按钮、弹窗关闭等
  function initLoginLinks() {
    var loginLinks = document.querySelectorAll('a[data-login-modal]');
    loginLinks.forEach(function(a) {
      a.addEventListener('click', function(e) {
        e.preventDefault();
        var href = a.getAttribute('href') || '/login.php';
        window.openLoginModal(href);
      });
    });
  }

  function initLoginModalMisc() {
    ['loginModal', 'registerModal'].forEach(function(id) {
      var modal = document.getElementById(id);
      if (!modal) return;

      // 点击遮罩关闭
      modal.addEventListener('click', function(e) {
        if (e.target === modal) {
          window.closeLoginModal();
        }
      });
    });

    // Esc 关闭（全局监听一次）
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        window.closeLoginModal();
      }
    });
  }

  function initRegisterLinks() {
    var registerLinks = document.querySelectorAll('a[data-register-modal]');
    registerLinks.forEach(function(a) {
      a.addEventListener('click', function(e) {
        e.preventDefault();
        var href = a.getAttribute('href') || '/register.php';
        window.openRegisterModal(href);
      });
    });
  }

  // 全局初始化密码显隐小眼睛
  document.addEventListener('DOMContentLoaded', function() {
    document.body.addEventListener('click', function(e) {
      var btn = e.target.closest('.password-toggle');
      if (!btn) return;

      var targetId = btn.getAttribute('data-target');
      if (!targetId) return;

      var input = document.getElementById(targetId);
      if (!input) return;

      if (input.type === 'password') {
        input.type = 'text';
        btn.textContent = '🙈';
      } else {
        input.type = 'password';
        btn.textContent = '👁';
      }
    });
  });

  // ============================================
  // 剪贴板 + 轻提示（分享链接等）
  // ============================================

  /** 复制文本到剪贴板（优先 Clipboard API，否则 execCommand） */
  function copyTextToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function(resolve, reject) {
      try {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        ta.style.top = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        ta.setSelectionRange(0, text.length);
        var ok = document.execCommand('copy');
        document.body.removeChild(ta);
        if (ok) resolve();
        else reject(new Error('copy failed'));
      } catch (e) {
        reject(e);
      }
    });
  }

  var _appToastTimer = null;
  /** 底部短暂文字提示 */
  function showAppToast(message, duration) {
    duration = duration === undefined ? 2600 : duration;
    var el = document.getElementById('appToast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'appToast';
      el.className = 'app-toast';
      el.setAttribute('role', 'status');
      el.setAttribute('aria-live', 'polite');
      document.body.appendChild(el);
    }
    el.textContent = message;
    el.classList.add('app-toast--visible');
    clearTimeout(_appToastTimer);
    _appToastTimer = setTimeout(function() {
      el.classList.remove('app-toast--visible');
    }, duration);
  }

  window.copyTextToClipboard = copyTextToClipboard;
  window.showAppToast = showAppToast;

  // 图片查看器 - 放大查看和下载图片
  function openImageViewer(imageUrls, currentIndex) {
    if (!imageUrls || imageUrls.length === 0) return;

    // 创建图片查看器modal
    var imageViewer = document.createElement('div');
    imageViewer.id = 'imageViewerModal';
    imageViewer.className = 'image-viewer-modal';
    imageViewer.innerHTML = `
      <div class="image-viewer-backdrop"></div>
      <div class="image-viewer-container">
        <div class="image-viewer-header">
          <button class="image-viewer-close" aria-label="关闭">×</button>
          <div class="image-viewer-title">
            <span class="image-viewer-counter">${currentIndex + 1} / ${imageUrls.length}</span>
          </div>
          <div class="image-viewer-toolbar">
            <button class="image-viewer-toolbar-btn" title="下载" id="imageViewerDownload">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                <polyline points="7 10 12 15 17 10"></polyline>
                <line x1="12" y1="15" x2="12" y2="3"></line>
              </svg>
            </button>
            <button class="image-viewer-toolbar-btn" title="放大" id="imageViewerZoomIn">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                <line x1="11" y1="8" x2="11" y2="14"></line>
                <line x1="8" y1="11" x2="14" y2="11"></line>
              </svg>
            </button>
            <button class="image-viewer-toolbar-btn" title="缩小" id="imageViewerZoomOut">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                <line x1="8" y1="11" x2="14" y2="11"></line>
              </svg>
            </button>
            <button class="image-viewer-toolbar-btn" title="实际大小" id="imageViewerZoomReset">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M8 3v3a2 2 0 0 1-2 2H3m18 0h-3a2 2 0 0 1-2-2V3m0 18v-3a2 2 0 0 1 2-2h3M3 16h3a2 2 0 0 1 2 2v3"></path>
              </svg>
            </button>
          </div>
        </div>
        <div class="image-viewer-body">
          <div class="image-viewer-navigation">
            <button class="image-viewer-nav-btn image-viewer-prev" aria-label="上一张">
              <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="15 18 9 12 15 6"></polyline>
              </svg>
            </button>
            <div class="image-viewer-content">
              <img src="${escapeHtml(imageUrls[currentIndex])}" alt="图片 ${currentIndex + 1}" class="image-viewer-img" id="imageViewerImg">
            </div>
            <button class="image-viewer-nav-btn image-viewer-next" aria-label="下一张">
              <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="9 18 15 12 9 6"></polyline>
              </svg>
            </button>
          </div>
        </div>
        <div class="image-viewer-footer">
          <div class="image-viewer-thumbnails">
            ${imageUrls.map(function(url, index) {
              return `<button class="image-viewer-thumbnail ${index === currentIndex ? 'active' : ''}" data-index="${index}">
                <img src="${escapeHtml(url)}" alt="缩略图 ${index + 1}">
              </button>`;
            }).join('')}
          </div>
        </div>
      </div>
    `;

    document.body.appendChild(imageViewer);
    document.body.style.overflow = 'hidden';

    var currentIdx = currentIndex;
    var totalImages = imageUrls.length;
    var zoomLevel = 1;
    var maxZoom = 5;
    var minZoom = 0.5;

    // 获取DOM元素
    var imgElement = document.getElementById('imageViewerImg');
    var counterElement = imageViewer.querySelector('.image-viewer-counter');
    var prevBtn = imageViewer.querySelector('.image-viewer-prev');
    var nextBtn = imageViewer.querySelector('.image-viewer-next');
    var closeBtn = imageViewer.querySelector('.image-viewer-close');
    var backdrop = imageViewer.querySelector('.image-viewer-backdrop');
    var downloadBtn = document.getElementById('imageViewerDownload');
    var zoomInBtn = document.getElementById('imageViewerZoomIn');
    var zoomOutBtn = document.getElementById('imageViewerZoomOut');
    var zoomResetBtn = document.getElementById('imageViewerZoomReset');
    var thumbnails = imageViewer.querySelectorAll('.image-viewer-thumbnail');

    // 更新图片显示
    function updateImage() {
      imgElement.src = imageUrls[currentIdx];
      counterElement.textContent = (currentIdx + 1) + ' / ' + totalImages;

      // 更新缩略图激活状态
      thumbnails.forEach(function(thumb, index) {
        thumb.classList.toggle('active', index === currentIdx);
      });

      // 重置缩放
      zoomLevel = 1;
      imgElement.style.transform = 'scale(1)';
    }

    // 切换到上一张图片
    function prevImage() {
      if (totalImages <= 1) return;
      currentIdx = (currentIdx - 1 + totalImages) % totalImages;
      updateImage();
    }

    // 切换到下一张图片
    function nextImage() {
      if (totalImages <= 1) return;
      currentIdx = (currentIdx + 1) % totalImages;
      updateImage();
    }

    // 应用缩放
    function applyZoom() {
      imgElement.style.transform = 'scale(' + zoomLevel + ')';
    }

    // 放大
    function zoomIn() {
      if (zoomLevel < maxZoom) {
        zoomLevel = Math.min(maxZoom, zoomLevel + 0.25);
        applyZoom();
      }
    }

    // 缩小
    function zoomOut() {
      if (zoomLevel > minZoom) {
        zoomLevel = Math.max(minZoom, zoomLevel - 0.25);
        applyZoom();
      }
    }

    // 重置缩放
    function zoomReset() {
      zoomLevel = 1;
      applyZoom();
    }

    // 下载当前图片
    function downloadImage() {
      var url = imageUrls[currentIdx];
      var filename = url.split('/').pop() || 'image.jpg';
      var a = document.createElement('a');
      a.href = url;
      a.download = filename;
      a.target = '_blank';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
    }

    // 事件监听
    prevBtn.addEventListener('click', prevImage);
    nextBtn.addEventListener('click', nextImage);

    closeBtn.addEventListener('click', closeImageViewer);
    backdrop.addEventListener('click', closeImageViewer);

    downloadBtn.addEventListener('click', downloadImage);
    zoomInBtn.addEventListener('click', zoomIn);
    zoomOutBtn.addEventListener('click', zoomOut);
    zoomResetBtn.addEventListener('click', zoomReset);

    // 缩略图点击
    thumbnails.forEach(function(thumb) {
      thumb.addEventListener('click', function() {
        var index = parseInt(this.getAttribute('data-index'));
        if (index !== currentIdx) {
          currentIdx = index;
          updateImage();
        }
      });
    });

    // 键盘导航
    function handleKeydown(e) {
      switch(e.key) {
        case 'Escape':
          closeImageViewer();
          break;
        case 'ArrowLeft':
          prevImage();
          break;
        case 'ArrowRight':
          nextImage();
          break;
        case '+':
        case '=':
          zoomIn();
          break;
        case '-':
          zoomOut();
          break;
        case '0':
          zoomReset();
          break;
      }
    }

    // 图片滚轮缩放
    imgElement.addEventListener('wheel', function(e) {
      e.preventDefault();
      if (e.deltaY < 0) {
        zoomIn();
      } else {
        zoomOut();
      }
    }, { passive: false });

    document.addEventListener('keydown', handleKeydown);

    // 关闭图片查看器
    function closeImageViewer() {
      document.removeEventListener('keydown', handleKeydown);
      if (imageViewer && imageViewer.parentNode) {
        imageViewer.parentNode.removeChild(imageViewer);
        document.body.style.overflow = '';
      }
    }

    // 暴露关闭函数
    window.closeImageViewer = closeImageViewer;
  }

  // HTML 转义函数
  function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  // ============================================
  // 积分奖励弹窗提示
  // ============================================

  /**
   * 显示积分获得通知弹窗
   * @param {number} amount
   * @param {string} label
   */
  window.showRewardToast = function(amount, label) {
    var body = document.body;
    var toast = document.createElement('div');
    toast.className = 'reward-toast';
    toast.innerHTML = '<div class="reward-toast-inner">' +
      '<span class="reward-toast-icon">+</span>' +
      '<div class="reward-toast-msg">' +
        '<div class="reward-toast-title">🎉 获得 ' + amount + ' 积分</div>' +
        '<div class="reward-toast-desc">' + escapeHtml(label) + '</div>' +
      '</div>' +
    '</div>';
    body.appendChild(toast);

    // 触发动画
    requestAnimationFrame(function() {
      toast.classList.add('reward-toast--show');
    });

    // 3.6s 后移除
    setTimeout(function() {
      toast.classList.remove('reward-toast--show');
      setTimeout(function() { toast.remove(); }, 300);
    }, 3600);
  };

  /**
   * 批量显示积分通知（支持数组或单条）
   */
  window.showRewardToasts = function(rewards) {
    if (!rewards || !rewards.length) return;
    rewards.forEach(function(r) {
      if (r.amount && r.label) {
        setTimeout(function() {
          window.showRewardToast(r.amount, r.label);
        }, 200 * rewards.indexOf(r));
      }
    });
  };

  // 页面加载时检查是否有待显示的积分通知
  function checkPendingRewards() {
    if (window._pendingRewards && window._pendingRewards.length) {
      window.showRewardToasts(window._pendingRewards);
      window._pendingRewards = null;
    }
  }

})();

  // 帖子卡片：标签筛选仍拦截；正文链接改为直接跳转 post.php（不再开弹窗）
  document.addEventListener('DOMContentLoaded', function() {
    document.addEventListener('click', function(e) {
      var tagFilter = e.target.closest('.post-card-tag--link');
      if (tagFilter && tagFilter.getAttribute('data-tag-url')) {
        e.preventDefault();
        e.stopPropagation();
        window.location.href = tagFilter.getAttribute('data-tag-url');
        return;
      }
    });
  });

  /**
   * 全站顶栏：向下滚动超过一定距离后收起，向上滚动再次显示
   */
  document.addEventListener('DOMContentLoaded', function() {
    var nav = document.querySelector('.mainNav');
    if (!nav) return;

    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      return;
    }

    var lastY = window.scrollY || document.documentElement.scrollTop || 0;
    var engageAfter = 56;
    var deltaNeed = 8;

    function applyNavHeightVars() {
      var h = nav.offsetHeight || 64;
      var hPx = h + 'px';
      document.documentElement.style.setProperty('--main-nav-height', hPx);
      if (document.body.classList.contains('home-page')) {
        document.documentElement.style.setProperty('--home-nav-height', hPx);
      }
    }

    applyNavHeightVars();
    window.addEventListener('resize', applyNavHeightVars, { passive: true });

    function onScroll() {
      var y = window.scrollY || document.documentElement.scrollTop || 0;
      if (y < engageAfter) {
        nav.classList.remove('mainNav--hidden');
      } else {
        var dy = y - lastY;
        if (dy > deltaNeed) {
          nav.classList.add('mainNav--hidden');
        } else if (dy < -deltaNeed) {
          nav.classList.remove('mainNav--hidden');
        }
      }
      lastY = y;
    }

    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  });

  // ============================================
  // 信息模态框（关于我们 / 隐私政策 / 联系我们）
  // ============================================

  window.openInfoModal = function(type) {
    var modal = document.getElementById('infoModal');
    if (!modal) return;

    // 隐藏所有内容面板
    var contents = modal.querySelectorAll('.info-content');
    contents.forEach(function(c) { c.style.display = 'none'; });

    // 显示目标面板
    var target = document.getElementById('infoContent-' + type);
    if (target) target.style.display = 'block';

    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  };

  window.closeInfoModal = function(e) {
    // 如果有点击事件且目标不是遮罩本身，不关闭
    if (e && e.target !== undefined && !e.target.classList.contains('info-modal-backdrop')) return;
    var modal = document.getElementById('infoModal');
    if (!modal) return;
    modal.style.display = 'none';
    document.body.style.overflow = '';
  };

  // Esc 关闭信息模态框
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      var modal = document.getElementById('infoModal');
      if (modal && modal.style.display === 'flex') {
        modal.style.display = 'none';
        document.body.style.overflow = '';
      }
    }
  });
