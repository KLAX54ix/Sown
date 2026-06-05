<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/points.php';
require_once __DIR__ . '/app/address.php';

points_ensure_schema();

$user = current_user();
$uid = $user ? (int)$user['id'] : 0;
$balance = $uid ? points_get_balance($uid) : 0;
$summary = points_task_summary($uid);
$catalog = shop_catalog();
$msg = input_string('msg', '', 'get');
$alerts = [
  'purchased' => '兑换成功，感谢支持！',
];
// 获取用户收货地址列表
$defaultAddress = null;
$allAddresses = [];
if ($uid) {
  $addrs = address_get_list($uid);
  $allAddresses = $addrs ?: [];
  $defaultAddress = !empty($addrs) ? $addrs[0] : null;
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php require __DIR__ . '/partials/head.php'; ?>
  <title>数问商城</title>
  <link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="shop-page">
  <?php require __DIR__ . '/partials/header.php'; ?>
  <div class="container">
    <div class="section shop-section">
      <!-- 顶部：标题 -->
      <?php if ($msg !== '' && isset($alerts[$msg])): ?>
        <div class="alert success shop-flash"><?= htmlspecialchars($alerts[$msg], ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <div id="shopCheckinToast" class="shop-toast" hidden></div>

      <?php if ($user): ?>
      <!-- 我的积分面板：积分、签到、两个查看按钮 -->
        <div class="shop-points-panel">
          <div class="shop-points-main">
            <div class="shop-points-balance">
              <span class="shop-points-label">我的积分</span>
              <span class="shop-points-value" id="shopBalance"><?= (int)$balance ?></span>
            </div>
            <div class="shop-points-actions">
              <button type="button" class="btn shop-info-btn" data-modal="progress">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                任务进度
              </button>
              <button type="button" class="btn shop-info-btn" data-modal="rules">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                如何获得积分
              </button>
              <button type="button" class="btn primary shop-checkin-btn" id="shopCheckinBtn" <?= $summary['checked_today'] ? 'disabled' : '' ?>>
                <?= $summary['checked_today'] ? '今日已签到' : '每日签到' ?>
              </button>
            </div>
          </div>
        </div>

        <!-- 任务进度模态框 -->
        <div class="shop-modal" id="progressModal" hidden>
          <div class="shop-modal-backdrop" data-modal-close="progress"></div>
          <div class="shop-modal-content">
            <div class="shop-modal-header">
              <h3 class="shop-modal-title"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:6px;"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>任务进度</h3>
              <button type="button" class="shop-modal-close" data-modal-close="progress">×</button>
            </div>
            <div class="shop-modal-body">
              <div class="shop-progress-grid-modal">
                <div class="shop-progress-card-modal">
                  <div class="shop-progress-icon"><svg width="28" height="28" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
                  <div class="shop-progress-label">连续签到</div>
                  <div class="shop-progress-value"><?= (int)$summary['streak'] ?> 天</div>
                </div>
                <div class="shop-progress-card-modal">
                  <div class="shop-progress-icon"><svg width="28" height="28" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg></div>
                  <div class="shop-progress-label">已发帖子</div>
                  <div class="shop-progress-value"><?= (int)$summary['posts'] ?> 篇</div>
                </div>
                <div class="shop-progress-card-modal">
                  <div class="shop-progress-icon"><svg width="28" height="28" viewBox="0 0 24 24"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3H14zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/></svg></div>
                  <div class="shop-progress-label">帖子最高获赞</div>
                  <div class="shop-progress-value"><?= (int)$summary['max_post_likes'] ?></div>
                </div>
                <div class="shop-progress-card-modal">
                  <div class="shop-progress-icon"><svg width="28" height="28" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></div>
                  <div class="shop-progress-label">帖子最高收藏</div>
                  <div class="shop-progress-value"><?= (int)$summary['max_post_favs'] ?></div>
                </div>
                <div class="shop-progress-card-modal">
                  <div class="shop-progress-icon"><svg width="28" height="28" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>
                  <div class="shop-progress-label">评论最高获赞</div>
                  <div class="shop-progress-value"><?= (int)$summary['max_comment_likes'] ?></div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- 积分规则模态框 -->
        <div class="shop-modal" id="rulesModal" hidden>
          <div class="shop-modal-backdrop" data-modal-close="rules"></div>
          <div class="shop-modal-content">
            <div class="shop-modal-header">
              <h3 class="shop-modal-title"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:6px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>如何获得积分</h3>
              <button type="button" class="shop-modal-close" data-modal-close="rules">×</button>
            </div>
            <div class="shop-modal-body">
              <ul class="shop-rules-list">
                <li class="shop-rule-item-modal">
                  <span class="shop-rule-icon"><svg width="22" height="22" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>
                  <div class="shop-rule-content">
                    <strong>每日签到</strong>
                    <span>每天签到可获得 <b>+5</b> 积分</span>
                  </div>
                </li>
                <li class="shop-rule-item-modal">
                  <span class="shop-rule-icon"><svg width="22" height="22" viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10"/></svg></span>
                  <div class="shop-rule-content">
                    <strong>连续签到奖励</strong>
                    <span>连续7天额外 <b>+30</b>，连续30天额外 <b>+120</b> 积分</span>
                  </div>
                </li>
                <li class="shop-rule-item-modal">
                  <span class="shop-rule-icon"><svg width="22" height="22" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg></span>
                  <div class="shop-rule-content">
                    <strong>首次发帖</strong>
                    <span>发布第1篇公开帖子获得 <b>+10</b> 积分</span>
                  </div>
                </li>
                <li class="shop-rule-item-modal">
                  <span class="shop-rule-icon"><svg width="22" height="22" viewBox="0 0 24 24"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg></span>
                  <div class="shop-rule-content">
                    <strong>发帖达人</strong>
                    <span>累计发布5篇帖子 <b>+40</b> 积分，10篇 <b>+90</b> 积分</span>
                  </div>
                </li>
                <li class="shop-rule-item-modal">
                  <span class="shop-rule-icon"><svg width="22" height="22" viewBox="0 0 24 24"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3H14zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/></svg></span>
                  <div class="shop-rule-content">
                    <strong>热门帖子</strong>
                    <span>单篇帖子获赞达到100获得 <b>+100</b> 积分</span>
                  </div>
                </li>
                <li class="shop-rule-item-modal">
                  <span class="shop-rule-icon"><svg width="22" height="22" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></span>
                  <div class="shop-rule-content">
                    <strong>热门收藏</strong>
                    <span>单篇帖子被收藏达到100获得 <b>+100</b> 积分</span>
                  </div>
                </li>
                <li class="shop-rule-item-modal">
                  <span class="shop-rule-icon"><svg width="22" height="22" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></span>
                  <div class="shop-rule-content">
                    <strong>神评论</strong>
                    <span>单条评论获赞达到100获得 <b>+100</b> 积分</span>
                  </div>
                </li>
              </ul>
            </div>
          </div>
        </div>

      <!-- 商品区域 -->
      <h2 class="shop-block-title">兑换商品</h2>
      <div class="shop-grid">
        <?php foreach ($catalog as $item): ?>
          <?php $owned = $user ? shop_has_purchased($uid, (int)$item['id']) : false; ?>
          <article class="shop-card <?= ($owned && empty($item['repeatable'])) ? 'shop-card--owned' : '' ?> <?= !empty($item['image']) ? 'shop-card--has-image' : '' ?>">
            <?php if (!empty($item['image'])): ?>
              <div class="shop-card-image">
                <img src="<?= htmlspecialchars($item['image'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
              </div>
            <?php endif; ?>
            <div class="shop-card-body">
              <div class="shop-card-header">
                <?php if (!empty($item['icon'])): ?>
                  <span class="shop-card-icon"><?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
                <h3 class="shop-card-title"><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></h3>
              </div>
              <p class="shop-card-desc"><?= htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="shop-card-footer">
              <span class="shop-card-price"><?= (int)$item['cost'] ?> 积分</span>
              <?php if ($owned && empty($item['repeatable'])): ?>
                <span class="shop-owned-badge">已拥有</span>
              <?php else: ?>
                <button type="button" class="btn primary btn-small shop-buy-btn" data-item-id="<?= (int)$item['id'] ?>" data-cost="<?= (int)$item['cost'] ?>" data-physical="<?= !empty($item['is_physical']) ? '1' : '0' ?>" data-repeatable="<?= !empty($item['repeatable']) ? '1' : '0' ?>">
                  <?= !empty($item['repeatable']) ? '兑换' : '兑换' ?>
                </button>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

      <!-- 实物商品收货信息模态框 -->
      <div class="shop-modal" id="shippingModal" hidden>
        <div class="shop-modal-backdrop" data-modal-close="shipping"></div>
        <div class="shop-modal-content">
          <div class="shop-modal-header">
            <h3 class="shop-modal-title">收货信息</h3>
            <button type="button" class="shop-modal-close" id="shipModalClose">×</button>
          </div>
          <div class="shop-modal-body">
            <form id="shippingForm" data-cost="0">
              <input type="hidden" id="shipItemId" value="0">
              <div class="shop-form-group" id="shipQuantityGroup" style="display:none;">
                <label class="shop-form-label" for="shipQuantity">兑换数量</label>
                <input type="number" id="shipQuantity" class="shop-form-input" value="1" min="1" max="99">
                <div class="shop-price-summary" id="shipPriceSummary">
                  单价 <span id="shipUnitCost">0</span> 积分 × <span id="shipQtyDisplay">1</span> = <strong id="shipTotalCost">0</strong> 积分
                </div>
              </div>
              <div class="shop-form-group" id="savedAddrGroup" style="display:none;">
                <label class="shop-form-label">选择已保存地址</label>
                <select id="shipAddrSelect" class="shop-form-input">
                  <option value="">-- 填写新地址 --</option>
                </select>
              </div>
              <div class="shop-form-group">
                <label class="shop-form-label" for="shipName">收件人姓名</label>
                <input type="text" id="shipName" class="shop-form-input" required maxlength="100" placeholder="请输入收件人姓名">
              </div>
              <div class="shop-form-group">
                <label class="shop-form-label" for="shipPhone">手机号码</label>
                <input type="tel" id="shipPhone" class="shop-form-input" required maxlength="50" placeholder="请输入手机号码">
              </div>
              <div class="shop-form-group">
                <label class="shop-form-label" for="shipAddress">收货地址</label>
                <textarea id="shipAddress" class="shop-form-input" required rows="3" maxlength="500" placeholder="请输入详细收货地址"></textarea>
              </div>
              <div class="shop-form-group" id="shipSaveAddrGroup">
                <label class="shop-form-checkbox">
                  <input type="checkbox" id="shipSaveAddr" checked>
                  <span>保存此地址到我的地址簿</span>
                </label>
              </div>
              <div class="shop-form-actions">
                <button type="button" class="btn primary" id="shipSubmitBtn">确认兑换</button>
                <button type="button" class="btn" id="shipCancelBtn">取消</button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <?php else: ?>
        <div class="shop-points-panel shop-points-panel--guest">
          <p>请先登录后查看商城内容。</p>
          <button type="button" class="btn primary" data-login-modal="1" id="shopLoginBtn">登录</button>
        </div>
      <?php endif; ?>
    </div>
    <?php require __DIR__ . '/partials/footer.php'; ?>
  </div>
  <script src="/assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>"></script>
  <?php if ($user): ?>
  <script>
  (function () {
    var csre = document.querySelector('meta[name="csrf-token"]');
    var csrf = csre ? csre.getAttribute('content') : '';
    var balEl = document.getElementById('shopBalance');
    var toastEl = document.getElementById('shopCheckinToast');

    // 用户已保存的地址列表
    var savedAddresses = <?= json_encode(array_map(function($a) {
      return [
        'id' => (int)$a['id'],
        'recipient' => $a['recipient'],
        'phone' => $a['phone'],
        'region' => $a['region'],
        'detail' => $a['detail'],
      ];
    }, $allAddresses)) ?>;

    function showToast(msg, isErr) {
      if (!toastEl) return;
      toastEl.textContent = msg;
      toastEl.hidden = false;
      toastEl.className = 'shop-toast' + (isErr ? ' shop-toast--err' : '');
      clearTimeout(showToast._t);
      showToast._t = setTimeout(function () { toastEl.hidden = true; }, 4200);
    }

    // 签到功能
    var checkinBtn = document.getElementById('shopCheckinBtn');
    if (checkinBtn && !checkinBtn.disabled) {
      checkinBtn.addEventListener('click', function () {
        var fd = new FormData();
        fd.append('csrf', csrf);
        checkinBtn.disabled = true;
        fetch('/shop_checkin.php', {
          method: 'POST',
          body: fd,
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data.ok) {
              showToast('签到成功，+' + (data.gained || 0) + ' 积分');
              if (typeof data.balance === 'number' && balEl) balEl.textContent = String(data.balance);
              checkinBtn.textContent = '今日已签到';
              if (data.rewards && data.rewards.length && window.showRewardToasts) {
                window.showRewardToasts(data.rewards);
              }
            } else {
              showToast(data.msg || '签到失败', true);
              checkinBtn.disabled = false;
            }
          })
          .catch(function () {
            showToast('网络错误', true);
            checkinBtn.disabled = false;
          });
      });
    }

    // 模态框功能
    document.querySelectorAll('.shop-info-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var modalId = btn.getAttribute('data-modal') + 'Modal';
        var modal = document.getElementById(modalId);
        if (modal) {
          modal.hidden = false;
          document.body.style.overflow = 'hidden';
        }
      });
    });

    document.querySelectorAll('[data-modal-close]').forEach(function (el) {
      el.addEventListener('click', function () {
        var modalName = el.getAttribute('data-modal-close');
        var modal = document.getElementById(modalName + 'Modal');
        if (modal) {
          modal.hidden = true;
          document.body.style.overflow = '';
        }
      });
    });

    // 点击模态框背景关闭
    document.querySelectorAll('.shop-modal').forEach(function (modal) {
      modal.addEventListener('click', function (e) {
        if (e.target === modal) {
          modal.hidden = true;
          document.body.style.overflow = '';
        }
      });
    });

    // 收货信息模态框
    var shipModal = document.getElementById('shippingModal');
    var shipForm = document.getElementById('shippingForm');
    var shipItemId = document.getElementById('shipItemId');
    var shipModalClose = document.getElementById('shipModalClose');
    var shipCancel = document.getElementById('shipCancelBtn');
    var shipSubmit = document.getElementById('shipSubmitBtn');

    if (shipModal) {
      function updatePriceSummary() {
        var cost = parseInt(shipForm.getAttribute('data-cost'), 10) || 0;
        var qty = parseInt(document.getElementById('shipQuantity').value, 10) || 1;
        if (qty < 1) qty = 1;
        document.getElementById('shipUnitCost').textContent = String(cost);
        document.getElementById('shipQtyDisplay').textContent = String(qty);
        document.getElementById('shipTotalCost').textContent = String(cost * qty);
      }
      function openShipModal(id, cost, repeatable) {
        shipItemId.value = String(id);
        shipForm.setAttribute('data-cost', String(cost));
        shipSubmit.disabled = false;
        // 数量选择器
        var qtyGroup = document.getElementById('shipQuantityGroup');
        var qtyInput = document.getElementById('shipQuantity');
        if (repeatable) {
          qtyGroup.style.display = '';
          qtyInput.value = 1;
          updatePriceSummary();
        } else {
          qtyGroup.style.display = 'none';
          qtyInput.value = 1;
        }
        // 填充地址选择器
        var select = document.getElementById('shipAddrSelect');
        var selectGroup = document.getElementById('savedAddrGroup');
        if (select) {
          select.innerHTML = '<option value="">-- 填写新地址 --</option>';
          savedAddresses.forEach(function(addr) {
            var opt = document.createElement('option');
            opt.value = String(addr.id);
            var label = addr.recipient + ' ' + addr.phone;
            if (addr.region) label += ' — ' + addr.region;
            opt.textContent = label;
            select.appendChild(opt);
          });
          if (savedAddresses.length > 0) {
            select.value = String(savedAddresses[0].id);
            fillAddressFromSelect(select.value);
          } else {
            document.getElementById('shipName').value = '';
            document.getElementById('shipPhone').value = '';
            document.getElementById('shipAddress').value = '';
          }
          if (selectGroup) selectGroup.style.display = savedAddresses.length > 0 ? '' : 'none';
        }
        shipModal.hidden = false;
        document.body.style.overflow = 'hidden';
      }
      function fillAddressFromSelect(id) {
        if (!id) {
          document.getElementById('shipName').value = '';
          document.getElementById('shipPhone').value = '';
          document.getElementById('shipAddress').value = '';
          document.getElementById('shipSaveAddr').checked = true;
          return;
        }
        var addr = savedAddresses.find(function(a) { return a.id == id; });
        if (addr) {
          document.getElementById('shipName').value = addr.recipient || '';
          document.getElementById('shipPhone').value = addr.phone || '';
          var full = '';
          if (addr.region) full += addr.region + ' ';
          if (addr.detail) full += addr.detail;
          document.getElementById('shipAddress').value = full.trim();
          document.getElementById('shipSaveAddr').checked = false;
        }
      }
      function closeShipModal() {
        shipModal.hidden = true;
        document.body.style.overflow = '';
      }
      if (shipModalClose) shipModalClose.addEventListener('click', closeShipModal);
      if (shipCancel) shipCancel.addEventListener('click', closeShipModal);
      shipModal.addEventListener('click', function (e) {
        if (e.target === shipModal) closeShipModal();
      });
      // 数量变更时更新价格
      var shipQtyInput = document.getElementById('shipQuantity');
      if (shipQtyInput) {
        shipQtyInput.addEventListener('input', updatePriceSummary);
        shipQtyInput.addEventListener('change', updatePriceSummary);
      }
      // 地址选择切换
      var shipAddrSelect = document.getElementById('shipAddrSelect');
      if (shipAddrSelect) {
        shipAddrSelect.addEventListener('change', function() {
          fillAddressFromSelect(this.value);
        });
      }
      if (shipSubmit) {
        var shippingLock = false;
        shipSubmit.addEventListener('click', async function () {
          if (shippingLock) return;
          var id = parseInt(shipItemId.value, 10);
          if (!id) return;
          var name = document.getElementById('shipName').value.trim();
          var phone = document.getElementById('shipPhone').value.trim();
          var address = document.getElementById('shipAddress').value.trim();
          if (!name || !phone || !address) {
            showToast('请填写完整的收货信息', true);
            return;
          }
          var fd = new FormData();
          fd.append('csrf', csrf);
          fd.append('item_id', String(id));
          fd.append('shipping_name', name);
          fd.append('shipping_phone', phone);
          fd.append('shipping_address', address);
          var quantity = parseInt(document.getElementById('shipQuantity').value, 10) || 1;
          if (quantity < 1) quantity = 1;
          fd.append('quantity', String(quantity));
          shippingLock = true;
          try {
            var r = await fetch('/shop_buy.php', {
              method: 'POST',
              body: fd,
              headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            var data = await r.json();
            if (data.ok) {
              var saveCheck = document.getElementById('shipSaveAddr');
              if (saveCheck && saveCheck.checked) {
                var addrFd = new FormData();
                addrFd.append('action', 'add');
                addrFd.append('csrf', csrf);
                addrFd.append('recipient', name);
                addrFd.append('phone', phone);
                addrFd.append('region', '');
                addrFd.append('detail', address);
                fetch('/shipping_address_api.php', { method: 'POST', body: addrFd }).catch(function(){});
              }
              closeShipModal();
              showToast(data.msg || '兑换成功');
              if (typeof data.balance === 'number' && balEl) balEl.textContent = String(data.balance);
              var btn = document.querySelector('.shop-buy-btn[data-item-id="' + id + '"]');
              if (btn && btn.getAttribute('data-repeatable') !== '1') {
                var card = btn.closest('.shop-card');
                if (card) card.classList.add('shop-card--owned');
                var badge = document.createElement('span');
                badge.className = 'shop-owned-badge';
                badge.textContent = '已拥有';
                btn.parentNode.replaceChild(badge, btn);
              }
            } else {
              showToast(data.msg || '兑换失败', true);
            }
          } catch (err) {
            showToast('网络错误', true);
          } finally {
            shippingLock = false;
          }
        });
      }
    }

    // 兑换功能
    document.querySelectorAll('.shop-buy-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = parseInt(btn.getAttribute('data-item-id'), 10);
        var cost = parseInt(btn.getAttribute('data-cost'), 10);
        var isPhysical = btn.getAttribute('data-physical') === '1';
        if (!id) return;
        if (isPhysical) {
          // 实物商品弹出收货信息模态框（已保存地址自动填入）
          if (shipModal) {
            var repeatable = btn.getAttribute('data-repeatable') === '1';
            openShipModal(id, cost, repeatable);
          }
          return;
        }
        window.showAppConfirm('确认花费 ' + cost + ' 积分兑换？', { title: '确认兑换' }).then(function(ok) {
        if (!ok) return;
        var fd = new FormData();
        fd.append('csrf', csrf);
        fd.append('item_id', String(id));
        btn.disabled = true;
        fetch('/shop_buy.php', {
          method: 'POST',
          body: fd,
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data.ok) {
              showToast(data.msg || '兑换成功');
              if (typeof data.balance === 'number' && balEl) balEl.textContent = String(data.balance);
              // 将按钮替换为"已拥有"徽标（可重复兑换商品不替换）
              if (btn.getAttribute('data-repeatable') !== '1') {
                var card = btn.closest('.shop-card');
                if (card) card.classList.add('shop-card--owned');
                var badge = document.createElement('span');
                badge.className = 'shop-owned-badge';
                badge.textContent = '已拥有';
                btn.parentNode.replaceChild(badge, btn);
              }
            } else {
              showToast(data.msg || '兑换失败', true);
            }
          })
          .catch(function () {
            showToast('网络错误', true);
          })
          .finally(function () {
            btn.disabled = false;
          });
      }); });
    });
  })();
  </script>
  <?php endif; ?>
  <?php if (!$user): ?>
  <script>
  (function () {
    var loginBtn = document.getElementById('shopLoginBtn');
    if (loginBtn) {
      // 等待 app.js 中的事件绑定完成后自动触发一次
      setTimeout(function () {
        if (window.openLoginModal) {
          window.openLoginModal('/login.php?next=' + encodeURIComponent('/shop.php'));
        } else {
          // 若 openLoginModal 尚未加载，直接模拟点击
          loginBtn.click();
        }
      }, 0);
    }
  })();
  </script>
  <?php endif; ?>
</body>
</html>
