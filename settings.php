<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/avatar.php';
require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/account.php';
require_once __DIR__ . '/app/address.php';

// 必须登录
require_login_or_redirect('/settings.php');

$currentUser = current_user();
if (!$currentUser) {
  require_login_or_redirect('/settings.php');
}

$userId = (int)$currentUser['id'];

try {
  $pdo = db();

  // 确保 privacy_show_favorites 列存在
  try {
    $pdo->exec("ALTER TABLE user ADD COLUMN privacy_show_favorites TINYINT(1) NOT NULL DEFAULT 1");
  } catch (Throwable $e) {
    // 列已存在则忽略
  }

  // 获取完整用户信息
  $st = $pdo->prepare("SELECT id, account, username, bio, email, created_at, privacy_show_favorites FROM user WHERE id = ? AND status = 1 LIMIT 1");
  $st->execute([$userId]);
  $targetUser = $st->fetch(PDO::FETCH_ASSOC);

  if (!$targetUser['account']) {
    $targetUser['account'] = ensure_user_account($userId);
  }

  if (!$targetUser || empty($targetUser)) {
    http_response_code(404);
    echo "用户不存在";
    exit;
  }

  if (!isset($targetUser['created_at'])) {
    $targetUser['created_at'] = '';
  }

  // 获取用户称号
  $userTitle = get_user_title($userId);
  $userTitles = get_user_titles($userId);

  // 获取收货地址列表
  $addresses = address_get_list($userId);
  $privacyShowFavorites = (int)($targetUser['privacy_show_favorites'] ?? 1);
} catch (Throwable $e) {
  http_response_code(500);
  echo "服务器错误";
  exit;
}

$pageTitle = '设置';
?><!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>设置 · Sown</title>
  <?php require __DIR__ . '/partials/head.php'; ?>
  <link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(__DIR__.'/assets/app.css') ?>">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
  <style>
    .settings-form-btn { display: inline-block !important; vertical-align: middle !important; outline: none !important; }
    .settings-form-hidden { display: none !important; }
  </style>
</head>
<body>
<?php require __DIR__ . '/partials/header.php'; ?>
<div class="container container--narrow">

  <h2 style="margin: 0 0 24px;">设置</h2>

  <!-- 个人资料（头像 + 用户名 + 签名） -->
  <div class="section settings-section">
    <div class="user-profile-card" style="padding: 24px;">
      <h3 style="margin: 0 0 20px; font-size: var(--fs-body-lg, 18px); color: var(--near-black);">个人资料</h3>
      <div style="display: flex; gap: 24px; align-items: flex-start;">
        <!-- 头像 -->
        <div style="flex-shrink: 0; text-align: center;">
          <div id="settingsAvatarWrap" style="cursor:pointer; margin-bottom: 8px;">
            <?= avatar_html($targetUser, 80, 'avatar-img') ?>
          </div>
          <button type="button" class="btn btn-small" id="changeAvatarBtn" style="font-size:12px;">更换头像</button>
          <input type="file" id="avatarFileInput" accept="image/jpeg,image/png,image/gif" style="display:none;">
          <p style="margin:4px 0 0; font-size: 11px; color: var(--stone); line-height:1.3;">JPG/PNG/GIF<br>≤2MB</p>
        </div>
        <!-- 基本信息 -->
        <div style="flex: 1; min-width: 0;">
          <!-- 用户名 -->
          <div style="margin-bottom: 16px;">
            <label style="display: block; font-size: 13px; color: var(--stone); margin-bottom: 4px;">用户名</label>
            <div style="display: flex; align-items: center; gap: 8px;">
              <span id="usernameDisplay"><?= htmlspecialchars($targetUser['username'], ENT_QUOTES, 'UTF-8') ?></span>
              <button id="editUsernameBtn" class="btn btn-small" style="font-size:12px;">编辑</button>
            </div>
            <div id="usernameEditForm" class="settings-form-hidden" style="margin-top:8px;">
              <form id="usernameForm" style="display:flex; gap:8px; align-items:center;">
                <input type="text" id="usernameInput" name="username" value="<?= htmlspecialchars($targetUser['username'], ENT_QUOTES, 'UTF-8') ?>"
                       maxlength="50" required style="flex:1; padding:6px 10px; border:1px solid var(--light-gray,#e5e5e5); border-radius:4px;">
                <button type="submit" class="btn primary settings-form-btn" style="flex-shrink:0;">保存</button>
                <button type="button" id="cancelUsernameBtn" class="btn settings-form-btn" style="flex-shrink:0;">取消</button>
              </form>
            </div>
          </div>
          <!-- 个性签名 -->
          <div>
            <label style="display: block; font-size: 13px; color: var(--stone); margin-bottom: 4px;">个性签名</label>
            <div class="user-bio <?= empty($targetUser['bio']) ? 'is-empty' : '' ?>" id="bioDisplay">
              <?= !empty($targetUser['bio']) ? nl2br(htmlspecialchars($targetUser['bio'], ENT_QUOTES, 'UTF-8')) : '' ?>
            </div>
            <div style="margin-top:8px;">
              <button id="modifyBioBtn" class="btn btn-small settings-form-btn" style="font-size:12px;">修改</button>
              <div id="bioFormContainer" class="settings-form-hidden" style="margin-top:8px;">
                <form id="bioForm" style="display:flex; flex-direction:column; gap:8px;">
                  <textarea id="bioInput" name="bio" rows="2" maxlength="200"
                            placeholder="介绍一下自己..."
                            style="width:100%; padding:8px; border:1px solid var(--light-gray,#e5e5e5); border-radius:4px; font-size:14px; resize:vertical;"><?= htmlspecialchars($targetUser['bio'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                  <div style="display:flex; gap:8px;">
                    <button type="submit" class="btn primary settings-form-btn">保存</button>
                    <button type="button" id="cancelBioBtn" class="btn settings-form-btn">取消</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- 称号管理 -->
  <?php if (!empty($userTitles)): ?>
  <div class="section settings-section">
    <div class="user-profile-card" style="padding: 24px;">
      <div class="user-title-panel" style="margin-top:0;">
        <div class="user-title-panel-header">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
          </svg>
          我的称号（点击切换展示）
        </div>
        <div class="title-list">
          <?php foreach ($userTitles as $t): ?>
            <button type="button" class="title-chip <?= $t['is_active'] ? 'active' : '' ?>" data-title-key="<?= htmlspecialchars($t['title_key'], ENT_QUOTES, 'UTF-8') ?>">
              <?php if (!empty($t['icon'])): ?><span class="title-chip-icon"><?= htmlspecialchars($t['icon'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
              <span><?= htmlspecialchars($t['title_name'], ENT_QUOTES, 'UTF-8') ?></span>
            </button>
          <?php endforeach; ?>
          <button type="button" class="title-chip title-chip-none<?php if (!$userTitle): ?> active<?php endif; ?>" data-title-key="">
            不展示称号
          </button>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- 隐私设置 -->
  <div class="section settings-section">
    <div class="user-profile-card" style="padding: 24px;">
      <h3 style="margin: 0 0 20px; font-size: var(--fs-body-lg, 18px); color: var(--near-black);">隐私设置</h3>
      <div style="display:flex; align-items:center; justify-content:space-between; padding:12px 0; border-bottom:1px solid var(--snow,#f5f5f5);">
        <div>
          <div style="font-weight:500; color:var(--near-black);">允许他人查看我的收藏</div>
          <div style="font-size:13px; color:var(--stone); margin-top:4px;">开启后，其他用户访问你的个人主页时可以查看你收藏的内容</div>
        </div>
        <label class="toggle-switch">
          <input type="checkbox" id="privacyShowFavorites" <?= $privacyShowFavorites ? 'checked' : '' ?>>
          <span class="toggle-slider"></span>
        </label>
      </div>
    </div>
  </div>

  <!-- 收件地址设置 -->
  <div class="section settings-section">
    <div class="user-profile-card" style="padding: 24px;">
      <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px;">
        <h3 style="margin:0; font-size: var(--fs-body-lg, 18px); color: var(--near-black);">收件地址</h3>
        <button type="button" class="btn primary btn-small" id="addAddressBtn">添加地址</button>
      </div>

      <div id="addressList">
        <?php if (empty($addresses)): ?>
          <div class="empty-state" style="padding:20px 0;" id="noAddressTip">还没有添加收件地址</div>
        <?php else: ?>
          <?php foreach ($addresses as $addr): ?>
            <div class="address-card" data-address-id="<?= (int)$addr['id'] ?>">
              <div class="address-card-info">
                <div class="address-card-name">
                  <span class="address-recipient"><?= htmlspecialchars($addr['recipient'], ENT_QUOTES, 'UTF-8') ?></span>
                  <span class="address-phone"><?= htmlspecialchars($addr['phone'], ENT_QUOTES, 'UTF-8') ?></span>
                  <?php if ((int)$addr['is_default']): ?>
                    <span class="address-default-badge">默认</span>
                  <?php endif; ?>
                </div>
                <div class="address-detail">
                  <?php if (!empty($addr['region'])): ?>
                    <span><?= htmlspecialchars($addr['region'], ENT_QUOTES, 'UTF-8') ?></span>
                  <?php endif; ?>
                  <span><?= htmlspecialchars($addr['detail'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
              </div>
              <div class="address-card-actions">
                <?php if (!(int)$addr['is_default']): ?>
                  <button type="button" class="btn btn-small address-set-default-btn" data-address-id="<?= (int)$addr['id'] ?>">设为默认</button>
                <?php endif; ?>
                <button type="button" class="btn btn-small address-edit-btn" data-address-id="<?= (int)$addr['id'] ?>"
                  data-recipient="<?= htmlspecialchars($addr['recipient'], ENT_QUOTES, 'UTF-8') ?>"
                  data-phone="<?= htmlspecialchars($addr['phone'], ENT_QUOTES, 'UTF-8') ?>"
                  data-region="<?= htmlspecialchars($addr['region'], ENT_QUOTES, 'UTF-8') ?>"
                  data-detail="<?= htmlspecialchars($addr['detail'], ENT_QUOTES, 'UTF-8') ?>">编辑</button>
                <button type="button" class="btn btn-small btn-danger address-delete-btn" data-address-id="<?= (int)$addr['id'] ?>">删除</button>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- 头像预览弹窗 -->
  <div id="avatarPreviewModal" class="login-modal-backdrop" style="display:none;">
    <div class="login-modal" style="max-width:420px;">
      <button type="button" class="login-modal-close" aria-label="关闭头像预览" id="closeAvatarPreviewBtn">&times;</button>
      <div class="login-modal-inner" style="grid-template-columns:1fr;">
        <div class="login-modal-right" style="align-items:center; text-align:center;">
          <div style="margin-bottom:16px; font-weight:600;"><?= htmlspecialchars($targetUser['username'], ENT_QUOTES, 'UTF-8') ?></div>
          <div class="avatar-preview" style="margin-bottom:16px;" id="avatarPreviewContainer">
            <?= avatar_html($targetUser, 160, 'avatar-img') ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- 收货地址编辑弹窗 -->
  <div id="addressModal" class="login-modal-backdrop" style="display:none;">
    <div class="login-modal" style="max-width:480px;">
      <button type="button" class="login-modal-close" aria-label="关闭" id="closeAddressModalBtn">&times;</button>
      <div class="login-modal-inner" style="grid-template-columns:1fr; padding:32px;">
        <h3 style="margin:0 0 20px; font-size:var(--fs-body-lg,18px); color:var(--near-black);" id="addressModalTitle">添加地址</h3>
        <form id="addressForm" style="display:flex; flex-direction:column; gap:12px;">
          <input type="hidden" id="addressFormId" value="">
          <div>
            <label style="display:block; font-size:13px; color:var(--stone); margin-bottom:4px;">收件人</label>
            <input type="text" id="addressFormRecipient" required maxlength="100"
                   style="width:100%; padding:8px 10px; border:1px solid var(--light-gray,#e5e5e5); border-radius:4px; font-size:14px; box-sizing:border-box;">
          </div>
          <div>
            <label style="display:block; font-size:13px; color:var(--stone); margin-bottom:4px;">联系电话</label>
            <input type="tel" id="addressFormPhone" required maxlength="20"
                   style="width:100%; padding:8px 10px; border:1px solid var(--light-gray,#e5e5e5); border-radius:4px; font-size:14px; box-sizing:border-box;">
          </div>
          <div>
            <label style="display:block; font-size:13px; color:var(--stone); margin-bottom:4px;">所在地区</label>
            <input type="text" id="addressFormRegion" maxlength="200" placeholder="省/市/区"
                   style="width:100%; padding:8px 10px; border:1px solid var(--light-gray,#e5e5e5); border-radius:4px; font-size:14px; box-sizing:border-box;">
          </div>
          <div>
            <label style="display:block; font-size:13px; color:var(--stone); margin-bottom:4px;">详细地址</label>
            <textarea id="addressFormDetail" maxlength="500" placeholder="街道、门牌号等"
                      style="width:100%; padding:8px 10px; border:1px solid var(--light-gray,#e5e5e5); border-radius:4px; font-size:14px; box-sizing:border-box; resize:vertical; min-height:60px;"></textarea>
          </div>
          <div style="display:flex; gap:8px; margin-top:4px;">
            <button type="submit" class="btn primary" id="addressFormSubmit">保存</button>
            <button type="button" class="btn" id="cancelAddressFormBtn">取消</button>
          </div>
          <div id="addressFormError" style="color:var(--red); font-size:13px; display:none;"></div>
        </form>
      </div>
    </div>
  </div>

  <?php require __DIR__ . '/partials/footer.php'; ?>

</div>

<script src="/assets/app.js?v=<?= filemtime(__DIR__.'/assets/app.js') ?>"></script>
<script>
// 编辑用户名功能
(function() {
  var editUsernameBtn = document.getElementById('editUsernameBtn');
  var cancelUsernameBtn = document.getElementById('cancelUsernameBtn');
  var usernameForm = document.getElementById('usernameForm');
  var usernameDisplay = document.getElementById('usernameDisplay');
  var usernameEditForm = document.getElementById('usernameEditForm');
  var usernameInput = document.getElementById('usernameInput');

  if (!editUsernameBtn) return;

  editUsernameBtn.addEventListener('click', function() {
    usernameEditForm.classList.remove('settings-form-hidden');
    editUsernameBtn.classList.add('settings-form-hidden');
    usernameInput.focus();
  });

  if (cancelUsernameBtn) {
    cancelUsernameBtn.addEventListener('click', function() {
      usernameEditForm.classList.add('settings-form-hidden');
      editUsernameBtn.classList.remove('settings-form-hidden');
      usernameInput.value = usernameDisplay.textContent.trim();
    });
  }

  if (usernameForm) {
    usernameForm.addEventListener('submit', async function(e) {
      e.preventDefault();

      var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      var fd = new FormData();
      fd.append('username', usernameInput.value.trim());
      fd.append('csrf', csrf);

      try {
        var result = await safeFetchJSON('/profile_update.php', { method:'POST', body: fd });

        if (!result.ok) {
          window.showAppAlert(result.msg || '更新失败');
          return;
        }

        usernameDisplay.textContent = result.data.username;
        usernameEditForm.classList.add('settings-form-hidden');
        editUsernameBtn.classList.remove('settings-form-hidden');

        // 同步更新导航栏中的用户名和头像预览弹窗中的用户名
        var navUsername = document.querySelector('.user-menu .user-avatar span');
        if (navUsername) navUsername.textContent = result.data.username;
        var previewUsername = document.querySelector('#avatarPreviewModal .login-modal-right > div:first-child');
        if (previewUsername) previewUsername.textContent = result.data.username;

        document.title = '设置 · Sown';
      } catch (e) {
        window.showAppAlert('网络或服务器错误');
      }
    });
  }
})();

// 编辑个性签名功能
(function() {
  var modifyBioBtn = document.getElementById('modifyBioBtn');
  var cancelBioBtn = document.getElementById('cancelBioBtn');
  var bioForm = document.getElementById('bioForm');
  var bioFormContainer = document.getElementById('bioFormContainer');
  var bioDisplay = document.getElementById('bioDisplay');
  var bioInput = document.getElementById('bioInput');

  if (modifyBioBtn) {
    modifyBioBtn.addEventListener('click', function() {
      if (bioFormContainer) bioFormContainer.classList.remove('settings-form-hidden');
      modifyBioBtn.classList.add('settings-form-hidden');
      if (bioInput) bioInput.focus();
    });
  }

  function hideBioForm() {
    if (bioFormContainer) bioFormContainer.classList.add('settings-form-hidden');
    if (modifyBioBtn) modifyBioBtn.classList.remove('settings-form-hidden');
  }

  if (cancelBioBtn) {
    cancelBioBtn.addEventListener('click', function() {
      hideBioForm();
      if (bioInput && bioDisplay) {
        bioInput.value = bioDisplay.textContent.trim();
      }
    });
  }

  if (bioForm) {
    bioForm.addEventListener('submit', async function(e) {
      e.preventDefault();

      var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      var fd = new FormData();
      fd.append('bio', bioInput.value.trim());
      fd.append('csrf', csrf);

      try {
        var result = await safeFetchJSON('/profile_update.php', { method:'POST', body: fd });

        if (!result.ok) {
          window.showAppAlert(result.msg || '更新失败');
          return;
        }

        // 直接更新显示，不刷新页面
        var newBio = result.data.bio || '';
        if (newBio) {
          bioDisplay.innerHTML = newBio.replace(/\n/g, '<br>');
          bioDisplay.classList.remove('is-empty');
        } else {
          bioDisplay.innerHTML = '';
          bioDisplay.classList.add('is-empty');
        }
        hideBioForm();
      } catch (e) {
        window.showAppAlert('网络或服务器错误');
      }
    });
  }
})();

// 称号切换功能
(function() {
  var titleChips = document.querySelectorAll('.title-chip[data-title-key]');
  if (!titleChips.length) return;

  var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

  titleChips.forEach(function(btn) {
    btn.addEventListener('click', async function() {
      var titleKey = btn.getAttribute('data-title-key');
      titleChips.forEach(function(b) { b.disabled = true; });

      try {
        var fd = new FormData();
        fd.append('title_key', titleKey);
        fd.append('csrf', csrf);

        var result = await safeFetchJSON('/title_toggle.php', { method: 'POST', body: fd });

        if (!result.ok) {
          window.showAppAlert(result.msg || '设置失败');
          titleChips.forEach(function(b) { b.disabled = false; });
          return;
        }

        titleChips.forEach(function(b) {
          b.classList.remove('active');
          b.disabled = false;
        });
        if (titleKey !== '') {
          btn.classList.add('active');
        }
      } catch (e) {
        window.showAppAlert('网络或服务器错误');
        titleChips.forEach(function(b) { b.disabled = false; });
      }
    });
  });
})();

// 头像上传功能
(function() {
  var changeBtn = document.getElementById('changeAvatarBtn');
  var fileInput = document.getElementById('avatarFileInput');
  if (!changeBtn || !fileInput) return;

  var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

  changeBtn.addEventListener('click', function() {
    fileInput.click();
  });

  fileInput.addEventListener('change', async function() {
    var file = fileInput.files[0];
    if (!file) return;

    // 验证文件类型
    var allowed = ['image/jpeg', 'image/png', 'image/gif'];
    if (allowed.indexOf(file.type) === -1) {
      window.showAppAlert('只支持 JPG、PNG、GIF 格式');
      fileInput.value = '';
      return;
    }

    // 验证文件大小（2MB）
    if (file.size > 2 * 1024 * 1024) {
      window.showAppAlert('文件大小不能超过 2MB');
      fileInput.value = '';
      return;
    }

    changeBtn.disabled = true;
    changeBtn.textContent = '上传中...';

    try {
      var fd = new FormData();
      fd.append('avatar', file);
      fd.append('csrf', csrf);
      fd.append('_ajax', '1');

      var result = await safeFetchJSON('/avatar_upload_post.php', { method: 'POST', body: fd });

      if (!result.ok) {
        window.showAppAlert(result.msg || '上传失败');
        return;
      }

      // 更新头像显示 - 刷新页面以获取最新头像
      location.reload();
    } catch (e) {
      window.showAppAlert('网络或服务器错误');
    } finally {
      changeBtn.disabled = false;
      changeBtn.textContent = '更换头像';
      fileInput.value = '';
    }
  });
})();

// 头像预览功能
(function() {
  var avatarWrap = document.getElementById('settingsAvatarWrap');
  var viewBtn = document.getElementById('viewAvatarBtn');
  var modal = document.getElementById('avatarPreviewModal');
  var closeBtn = document.getElementById('closeAvatarPreviewBtn');
  if (!modal) return;

  function openModal() {
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }

  function closeModal() {
    modal.style.display = 'none';
    document.body.style.overflow = '';
  }

  if (avatarWrap) {
    avatarWrap.addEventListener('click', openModal);
  }
  if (viewBtn) {
    viewBtn.addEventListener('click', openModal);
  }
  if (closeBtn) {
    closeBtn.addEventListener('click', closeModal);
  }
  modal.addEventListener('click', function(e) {
    if (e.target === modal) {
      closeModal();
    }
  });
})();

// 隐私设置 - 收藏可见性切换
(function() {
  var toggle = document.getElementById('privacyShowFavorites');
  if (!toggle) return;

  var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

  toggle.addEventListener('change', async function() {
    toggle.disabled = true;
    try {
      var fd = new FormData();
      fd.append('privacy_show_favorites', toggle.checked ? '1' : '0');
      fd.append('csrf', csrf);

      var result = await safeFetchJSON('/profile_update.php', { method: 'POST', body: fd });
      if (!result.ok) {
        toggle.checked = !toggle.checked;
        window.showAppAlert(result.msg || '设置失败');
      }
    } catch (e) {
      toggle.checked = !toggle.checked;
      window.showAppAlert('网络或服务器错误');
    } finally {
      toggle.disabled = false;
    }
  });
})();

// 收件地址管理
(function() {
  var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  var modal = document.getElementById('addressModal');
  var closeBtn = document.getElementById('closeAddressModalBtn');
  var cancelBtn = document.getElementById('cancelAddressFormBtn');
  var addBtn = document.getElementById('addAddressBtn');
  var form = document.getElementById('addressForm');
  var formId = document.getElementById('addressFormId');
  var formRecipient = document.getElementById('addressFormRecipient');
  var formPhone = document.getElementById('addressFormPhone');
  var formRegion = document.getElementById('addressFormRegion');
  var formDetail = document.getElementById('addressFormDetail');
  var formSubmit = document.getElementById('addressFormSubmit');
  var formError = document.getElementById('addressFormError');
  var modalTitle = document.getElementById('addressModalTitle');
  var addressList = document.getElementById('addressList');

  if (!modal || !form) return;

  function openModal_() {
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }

  function closeModal_() {
    modal.style.display = 'none';
    document.body.style.overflow = '';
    formError.style.display = 'none';
  }

  function resetForm() {
    formId.value = '';
    formRecipient.value = '';
    formPhone.value = '';
    formRegion.value = '';
    formDetail.value = '';
    formError.style.display = 'none';
  }

  if (addBtn) {
    addBtn.addEventListener('click', function() {
      resetForm();
      modalTitle.textContent = '添加地址';
      formSubmit.textContent = '保存';
      openModal_();
      formRecipient.focus();
    });
  }

  if (closeBtn) closeBtn.addEventListener('click', closeModal_);
  if (cancelBtn) cancelBtn.addEventListener('click', closeModal_);
  modal.addEventListener('click', function(e) {
    if (e.target === modal) closeModal_();
  });

  // 提交表单
  form.addEventListener('submit', async function(e) {
    e.preventDefault();
    var recipient = formRecipient.value.trim();
    var phone = formPhone.value.trim();
    var region = formRegion.value.trim();
    var detail = formDetail.value.trim();

    if (!recipient) {
      formError.textContent = '请填写收件人';
      formError.style.display = 'block';
      return;
    }
    if (!phone) {
      formError.textContent = '请填写联系电话';
      formError.style.display = 'block';
      return;
    }

    var isEdit = formId.value !== '';
    var action = isEdit ? 'update' : 'add';
    var fd = new FormData();
    fd.append('action', action);
    fd.append('csrf', csrf);
    fd.append('recipient', recipient);
    fd.append('phone', phone);
    fd.append('region', region);
    fd.append('detail', detail);
    if (isEdit) {
      fd.append('address_id', formId.value);
    }

    formSubmit.disabled = true;
    formError.style.display = 'none';

    try {
      var result = await safeFetchJSON('/shipping_address_api.php', { method: 'POST', body: fd });
      if (!result.ok) {
        formError.textContent = result.msg || '操作失败';
        formError.style.display = 'block';
        return;
      }
      closeModal_();
      location.reload();
    } catch (e) {
      formError.textContent = '网络或服务器错误';
      formError.style.display = 'block';
    } finally {
      formSubmit.disabled = false;
    }
  });

  // 编辑地址
  addressList.addEventListener('click', function(e) {
    var editBtn = e.target.closest('.address-edit-btn');
    if (!editBtn) return;

    formId.value = editBtn.getAttribute('data-address-id');
    formRecipient.value = editBtn.getAttribute('data-recipient');
    formPhone.value = editBtn.getAttribute('data-phone');
    formRegion.value = editBtn.getAttribute('data-region');
    formDetail.value = editBtn.getAttribute('data-detail');
    modalTitle.textContent = '编辑地址';
    formSubmit.textContent = '更新';
    openModal_();
    formRecipient.focus();
  });

  // 删除地址
  addressList.addEventListener('click', function(e) {
    var delBtn = e.target.closest('.address-delete-btn');
    if (!delBtn) return;

    var addrId = delBtn.getAttribute('data-address-id');
    if (!addrId) return;

    window.showAppConfirm('确定要删除这个收件地址吗？', { title: '删除确认', danger: true }).then(async function(ok) {
      if (!ok) return;

      var fd = new FormData();
      fd.append('action', 'delete');
      fd.append('csrf', csrf);
      fd.append('address_id', addrId);

      try {
        var result = await safeFetchJSON('/shipping_address_api.php', { method: 'POST', body: fd });
        if (!result.ok) {
          window.showAppAlert(result.msg || '删除失败');
          return;
        }
        location.reload();
      } catch (e) {
        window.showAppAlert('网络或服务器错误');
      }
    });
  });

  // 设为默认
  addressList.addEventListener('click', function(e) {
    var defBtn = e.target.closest('.address-set-default-btn');
    if (!defBtn) return;

    var addrId = defBtn.getAttribute('data-address-id');
    if (!addrId) return;

    var fd = new FormData();
    fd.append('action', 'set_default');
    fd.append('csrf', csrf);
    fd.append('address_id', addrId);

    defBtn.disabled = true;
    safeFetchJSON('/shipping_address_api.php', { method: 'POST', body: fd }).then(function(result) {
      if (!result.ok) {
        window.showAppAlert(result.msg || '设置失败');
        return;
      }
      location.reload();
    }).catch(function() {
      window.showAppAlert('网络或服务器错误');
    }).finally(function() {
      defBtn.disabled = false;
    });
  });
})();
</script>

</body>
</html>
