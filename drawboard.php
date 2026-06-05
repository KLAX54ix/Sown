<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/helpers.php';

// 需要登录
if (!is_logged_in()) {
  safe_redirect(login_url('/drawboard.php'));
}

$user = current_user();
if (!$user) {
  safe_redirect('/login.php?next=' . urlencode('/drawboard.php'));
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>数问画板</title>
  <?php require __DIR__ . '/partials/head.php'; ?>
  <link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(__DIR__.'/assets/app.css') ?>">
</head>
<body>
  <?php require __DIR__ . '/partials/header.php'; ?>

  <div class="drawboardContainer">
    <!-- 顶部操作栏 -->
    <div class="drawboard-header">
<div class="drawboard-actions">
        <button type="button" id="btnUndo" class="drawboard-action-btn" title="撤销 (Ctrl+Z)">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="1 4 1 10 7 10"></polyline>
            <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>
          </svg>
        </button>
        <button type="button" id="btnRedo" class="drawboard-action-btn" title="重做 (Ctrl+Shift+Z / Ctrl+Y)">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="23 4 23 10 17 10"></polyline>
            <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
          </svg>
        </button>
        <button type="button" id="btnClear" class="drawboard-action-btn" title="清空画布">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="3 6 5 6 21 6"></polyline>
            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
          </svg>
        </button>
        <button type="button" id="btnExport" class="drawboard-action-btn" title="导出 PNG">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
            <polyline points="7 10 12 15 17 10"></polyline>
            <line x1="12" y1="15" x2="12" y2="3"></line>
          </svg>
        </button>
      </div>
    </div>

    <div class="drawboard-body">
      <!-- 左侧工具栏 -->
      <div class="drawboard-toolbar">
        <button type="button" class="drawboard-tool active" data-tool="pen" title="画笔 (P)">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 19l7-7 3 3-7 7-3-3z"></path>
            <path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"></path>
            <path d="M2 2l7.586 7.586"></path>
            <circle cx="11" cy="11" r="2"></circle>
          </svg>
        </button>
        <button type="button" class="drawboard-tool" data-tool="line" title="直线 (L)">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="4" y1="20" x2="20" y2="4"></line>
          </svg>
        </button>
        <button type="button" class="drawboard-tool" data-tool="rect" title="矩形 (R)">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
          </svg>
        </button>
        <button type="button" class="drawboard-tool" data-tool="circle" title="圆 (C)">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"></circle>
          </svg>
        </button>
        <button type="button" class="drawboard-tool" data-tool="text" title="文本 (T)">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="4 7 4 4 20 4 20 7"></polyline>
            <line x1="9" y1="20" x2="15" y2="20"></line>
            <line x1="12" y1="4" x2="12" y2="20"></line>
          </svg>
        </button>
        <button type="button" class="drawboard-tool" data-tool="axes" title="函数图象 (F)">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="3" y1="20" x2="21" y2="20"></line>
            <line x1="3" y1="4" x2="3" y2="20"></line>
            <polyline points="3 12 7 10 11 14 15 6 19 9 21 8"></polyline>
          </svg>
        </button>
        <!-- eraser removed per user request -->
        <div class="drawboard-tool-divider"></div>
        <button type="button" class="drawboard-tool" data-tool="select" title="选择 (V)">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 3l7.07 16.97 2.51-7.39 7.39-2.51L3 3z"></path>
            <path d="M13 13l6 6"></path>
          </svg>
        </button>
      </div>

      <!-- 中间 Canvas 区域 -->
      <div class="drawboard-canvas-wrap">
        <canvas id="drawCanvas"></canvas>
        <div id="textOverlay" class="drawboard-text-overlay" style="display:none;">
          <textarea id="textInput" class="drawboard-text-input" placeholder="输入文字..." maxlength="200"></textarea>
          <div class="drawboard-text-actions">
            <button type="button" id="textConfirm" class="drawboard-text-btn">确认</button>
            <button type="button" id="textCancel" class="drawboard-text-btn cancel">取消</button>
          </div>
        </div>
        <div id="axesOverlay" class="drawboard-axes-overlay" style="display:none;">
          <div class="drawboard-axes-box">
            <label class="drawboard-axes-label">f(x) =</label>
            <input type="text" id="axesInput" class="drawboard-axes-input" placeholder="例如: x^2, sin(x), 2*x+1" maxlength="100" spellcheck="false">
            <div class="drawboard-axes-actions">
              <button type="button" id="axesConfirm" class="drawboard-text-btn">绘图</button>
              <button type="button" id="axesCancel" class="drawboard-text-btn cancel">取消</button>
            </div>
          </div>
        </div>
      </div>

      <!-- 右侧控制面板 -->
      <div class="drawboard-controls">
        <div class="drawboard-controls-section">
          <label class="drawboard-controls-label">颜色</label>
          <div class="drawboard-color-row">
            <input type="color" id="colorPicker" class="drawboard-color-picker" value="#262626">
          </div>
          <div class="drawboard-color-presets">
            <button type="button" class="drawboard-color-preset active" data-color="#262626" style="background:#262626;" title="黑色"></button>
            <button type="button" class="drawboard-color-preset" data-color="#555555" style="background:#555555;" title="深灰"></button>
            <button type="button" class="drawboard-color-preset" data-color="#999999" style="background:#999999;" title="灰色"></button>
            <button type="button" class="drawboard-color-preset" data-color="#c0392b" style="background:#c0392b;" title="红色"></button>
            <button type="button" class="drawboard-color-preset" data-color="#e67e22" style="background:#e67e22;" title="橙色"></button>
            <button type="button" class="drawboard-color-preset" data-color="#f1c40f" style="background:#f1c40f;" title="黄色"></button>
            <button type="button" class="drawboard-color-preset" data-color="#27ae60" style="background:#27ae60;" title="绿色"></button>
            <button type="button" class="drawboard-color-preset" data-color="#1abc9c" style="background:#1abc9c;" title="青色"></button>
            <button type="button" class="drawboard-color-preset" data-color="#2980b9" style="background:#2980b9;" title="蓝色"></button>
            <button type="button" class="drawboard-color-preset" data-color="#8e44ad" style="background:#8e44ad;" title="紫色"></button>
            <button type="button" class="drawboard-color-preset" data-color="#e84393" style="background:#e84393;" title="粉色"></button>
            <button type="button" class="drawboard-color-preset" data-color="#8B6914" style="background:#8B6914;" title="棕色"></button>
          </div>
        </div>
        <div class="drawboard-controls-section">
          <label class="drawboard-controls-label">笔触</label>
          <div class="drawboard-stroke-row">
            <button type="button" class="drawboard-stroke-btn active" data-width="1" style="width:22px;height:22px;"><span style="width:1px;height:1px;"></span></button>
            <button type="button" class="drawboard-stroke-btn" data-width="2" style="width:26px;height:26px;"><span style="width:2px;height:2px;"></span></button>
            <button type="button" class="drawboard-stroke-btn" data-width="3" style="width:30px;height:30px;"><span style="width:3px;height:3px;"></span></button>
            <button type="button" class="drawboard-stroke-btn" data-width="5" style="width:34px;height:34px;"><span style="width:5px;height:5px;"></span></button>
            <button type="button" class="drawboard-stroke-btn" data-width="8" style="width:38px;height:38px;"><span style="width:8px;height:8px;"></span></button>
            <button type="button" class="drawboard-stroke-btn" data-width="12" style="width:44px;height:44px;"><span style="width:12px;height:12px;"></span></button>
          </div>
        </div>
        <div class="drawboard-controls-section">
          <label class="drawboard-controls-label">选项</label>
          <div class="drawboard-switch-row">
            <span>显示网格</span>
            <label class="drawboard-switch">
              <input type="checkbox" id="gridToggle" checked>
              <span class="drawboard-switch-slider"></span>
            </label>
          </div>
          <div class="drawboard-switch-row">
            <span>吸附网格</span>
            <label class="drawboard-switch">
              <input type="checkbox" id="snapToggle">
              <span class="drawboard-switch-slider"></span>
            </label>
          </div>
        </div>
        <div class="drawboard-controls-section">
          <label class="drawboard-controls-label">网格大小</label>
          <div class="drawboard-grid-size-row">
            <button type="button" class="drawboard-grid-btn" data-size="5">5</button>
            <button type="button" class="drawboard-grid-btn" data-size="10">10</button>
            <button type="button" class="drawboard-grid-btn" data-size="15">15</button>
            <button type="button" class="drawboard-grid-btn active" data-size="20">20</button>
            <button type="button" class="drawboard-grid-btn" data-size="30">30</button>
            <button type="button" class="drawboard-grid-btn" data-size="50">50</button>
          </div>
        </div>
        <div class="drawboard-controls-section" id="axesScaleSection" style="display:none;">
          <label class="drawboard-controls-label">坐标轴缩放</label>
          <div class="drawboard-scale-row">
            <span class="drawboard-scale-label">X 轴</span>
            <button type="button" class="drawboard-scale-btn" data-axis="x" data-dir="-1">-</button>
            <span class="drawboard-scale-val" id="scaleXVal">30</span>
            <button type="button" class="drawboard-scale-btn" data-axis="x" data-dir="1">+</button>
          </div>
          <div class="drawboard-scale-row">
            <span class="drawboard-scale-label">Y 轴</span>
            <button type="button" class="drawboard-scale-btn" data-axis="y" data-dir="-1">-</button>
            <span class="drawboard-scale-val" id="scaleYVal">30</span>
            <button type="button" class="drawboard-scale-btn" data-axis="y" data-dir="1">+</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php require __DIR__ . '/partials/footer.php'; ?>

  <script src="/assets/app.js?v=<?= filemtime(__DIR__.'/assets/app.js') ?>"></script>
  <script src="/assets/drawboard.js?v=<?= filemtime(__DIR__.'/assets/drawboard.js') ?>"></script>
</body>
</html>
