/**
 * Sown / 数问 - 画板绘图引擎 v2
 * Canvas-based drawing board: shape tools, selection, multi-select, move, delete
 */
(function () {
  'use strict';

  var canvas = document.getElementById('drawCanvas');
  if (!canvas) return;
  var ctx = canvas.getContext('2d');

  // ============================================
  // 状态
  // ============================================

  var state = {
    tool: 'pen',
    color: '#262626',
    width: 4,
    gridSize: 20,
    showGrid: true,
    snapEnabled: false,
    drawing: false,
    hasDrawn: false,
    selectedIndices: [],       // 选中的图形索引
    dragShapes: false          // 是否正在拖拽选中图形
  };

  var paths = [];
  var undoStack = [];
  var redoStack = [];
  var MAX_UNDO = 50;

  // 当前绘制中的临时数据
  var currentPath = null;      // pen 工具用
  var startPos = null;         // 形状工具起始点
  var previewShape = null;     // 形状预览
  var dragStart = null;        // 拖拽移动起始
  var dragOrigins = null;      // 拖拽前各图形原始位置
  var lineStart = null;        // 直线工具第一点击点（点击-点击模式）
  var rectAnchor = null;       // 矩形工具第一个顶点（点击-点击模式）
  var circleCenter = null;     // 圆工具圆心（点击-点击模式）

  // 文本输入状态
  var textPending = null;

  // 函数图象状态
  var pendingAxes = null;      // { func: '...' } 待放置的坐标轴
  var axesOverlay = document.getElementById('axesOverlay');
  var axesInput = document.getElementById('axesInput');
  var axesConfirm = document.getElementById('axesConfirm');
  var axesCancel = document.getElementById('axesCancel');
  var axesScaleSection = document.getElementById('axesScaleSection');
  var scaleXVal = document.getElementById('scaleXVal');
  var scaleYVal = document.getElementById('scaleYVal');

  // ============================================
  // Retina 适配
  // ============================================

  var dpr = window.devicePixelRatio || 1;
  var wrap = canvas.parentElement;

  function resizeCanvas() {
    var rect = wrap.getBoundingClientRect();
    var w = rect.width;
    var h = rect.height;
    canvas.width = w * dpr;
    canvas.height = h * dpr;
    canvas.style.width = w + 'px';
    canvas.style.height = h + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    render();
  }

  window.addEventListener('resize', resizeCanvas);

  // ============================================
  // 坐标工具
  // ============================================

  function getPos(e) {
    var rect = canvas.getBoundingClientRect();
    var x = e.clientX - rect.left;
    var y = e.clientY - rect.top;
    if (state.snapEnabled) {
      // 几何吸附优先（更精确）
      var snapPt = snapToGeometry({x: x, y: y});
      if (snapPt) {
        return snapPt;
      }
      // 无几何点则吸附到网格
      var gs = state.gridSize;
      x = Math.round(x / gs) * gs;
      y = Math.round(y / gs) * gs;
    }
    return { x: x, y: y };
  }

  // ============================================
  // 几何吸附系统
  // ============================================

  var SNAP_DIST = 8;  // 吸附阈值（像素）
  var snapHighlight = null;  // 当前吸附点（用于视觉反馈）

  // 获取最近的可吸附几何点
  function snapToGeometry(pos) {
    var bestPt = null;
    var bestDist = SNAP_DIST;
    snapHighlight = null;

    // 收集所有图形的端点/特殊点
    for (var i = 0; i < paths.length; i++) {
      var pts = getShapeSnapPoints(paths[i]);
      for (var j = 0; j < pts.length; j++) {
        var dx = pos.x - pts[j].x;
        var dy = pos.y - pts[j].y;
        var dist = Math.sqrt(dx * dx + dy * dy);
        if (dist < bestDist) {
          bestDist = dist;
          bestPt = pts[j];
        }
      }
    }

    // 收集图形间的交点
    var interPts = getIntersectionSnapPoints();
    for (var i = 0; i < interPts.length; i++) {
      var dx = pos.x - interPts[i].x;
      var dy = pos.y - interPts[i].y;
      var dist = Math.sqrt(dx * dx + dy * dy);
      if (dist < bestDist) {
        bestDist = dist;
        bestPt = interPts[i];
      }
    }

    snapHighlight = bestPt;
    return bestPt;
  }

  // 获取单个图形的吸附点
  function getShapeSnapPoints(p) {
    var pts = [];
    switch (p.type) {
      case 'line':
        // 两个端点 + 中点
        pts.push({x: p.x, y: p.y});
        pts.push({x: p.x + p.w, y: p.y + p.h});
        if (p.w !== 0 || p.h !== 0) {
          pts.push({x: p.x + p.w / 2, y: p.y + p.h / 2});
        }
        break;
      case 'rect':
        var rx = p.w >= 0 ? p.x : p.x + p.w;
        var ry = p.h >= 0 ? p.y : p.y + p.h;
        var rw = Math.abs(p.w), rh = Math.abs(p.h);
        // 四个角
        pts.push({x: rx, y: ry});
        pts.push({x: rx + rw, y: ry});
        pts.push({x: rx + rw, y: ry + rh});
        pts.push({x: rx, y: ry + rh});
        // 四条边中点
        pts.push({x: rx + rw / 2, y: ry});
        pts.push({x: rx + rw, y: ry + rh / 2});
        pts.push({x: rx + rw / 2, y: ry + rh});
        pts.push({x: rx, y: ry + rh / 2});
        break;
      case 'circle':
        var rx = Math.abs(p.w) / 2, ry = Math.abs(p.h) / 2;
        var cx = p.w >= 0 ? p.x + rx : p.x - rx;
        var cy = p.h >= 0 ? p.y + ry : p.y - ry;
        // 圆心
        pts.push({x: cx, y: cy});
        // 四个方位点
        pts.push({x: cx - rx, y: cy});  // 左
        pts.push({x: cx + rx, y: cy});  // 右
        pts.push({x: cx, y: cy - ry});  // 上
        pts.push({x: cx, y: cy + ry});  // 下
        break;
      case 'path':
        var step = Math.max(1, Math.floor(p.points.length / 30));
        for (var i = 0; i < p.points.length; i += step) {
          pts.push({x: p.points[i].x, y: p.points[i].y});
        }
        if (p.points.length > 0) {
          var last = p.points[p.points.length - 1];
          pts.push({x: last.x, y: last.y});
        }
        break;
    }
    return pts;
  }

  // 计算所有图形对之间的交点
  function getIntersectionSnapPoints() {
    var pts = [];
    for (var i = 0; i < paths.length; i++) {
      for (var j = i + 1; j < paths.length; j++) {
        var inter = computeShapeIntersection(paths[i], paths[j]);
        if (inter) {
          for (var k = 0; k < inter.length; k++) {
            // 去重（交点可能重合）
            var dup = false;
            for (var m = 0; m < pts.length; m++) {
              if (Math.abs(pts[m].x - inter[k].x) < 0.5 && Math.abs(pts[m].y - inter[k].y) < 0.5) {
                dup = true;
                break;
              }
            }
            if (!dup) pts.push(inter[k]);
          }
        }
      }
    }
    return pts;
  }

  // 计算两个图形的交点
  function computeShapeIntersection(a, b) {
    if (a.type === 'line' && b.type === 'line') {
      var pt = lineSegIntersection(
        {x: a.x, y: a.y}, {x: a.x + a.w, y: a.y + a.h},
        {x: b.x, y: b.y}, {x: b.x + b.w, y: b.y + b.h}
      );
      return pt ? [pt] : null;
    }
    if (a.type === 'line' && b.type === 'rect') return lineRectIntersection(a, b);
    if (a.type === 'rect' && b.type === 'line') return lineRectIntersection(b, a);
    if (a.type === 'line' && b.type === 'circle') return lineCircleIntersection(a, b);
    if (a.type === 'circle' && b.type === 'line') return lineCircleIntersection(b, a);
    if (a.type === 'rect' && b.type === 'circle') return rectCircleIntersection(a, b);
    if (a.type === 'circle' && b.type === 'rect') return rectCircleIntersection(b, a);
    return null;
  }

  // 线段-线段交点
  function lineSegIntersection(p1, p2, p3, p4) {
    var d1x = p2.x - p1.x, d1y = p2.y - p1.y;
    var d2x = p4.x - p3.x, d2y = p4.y - p3.y;
    var denom = d1x * d2y - d1y * d2x;
    if (Math.abs(denom) < 0.0001) return null;
    var t = ((p3.x - p1.x) * d2y - (p3.y - p1.y) * d2x) / denom;
    var u = ((p3.x - p1.x) * d1y - (p3.y - p1.y) * d1x) / denom;
    if (t >= 0 && t <= 1 && u >= 0 && u <= 1) {
      return {x: p1.x + t * d1x, y: p1.y + t * d1y};
    }
    return null;
  }

  // 线段-矩形交点
  function lineRectIntersection(line, rect) {
    var rx = rect.w >= 0 ? rect.x : rect.x + rect.w;
    var ry = rect.h >= 0 ? rect.y : rect.y + rect.h;
    var rw = Math.abs(rect.w), rh = Math.abs(rect.h);
    var edges = [
      {x1: rx, y1: ry, x2: rx + rw, y2: ry},
      {x1: rx + rw, y1: ry, x2: rx + rw, y2: ry + rh},
      {x1: rx + rw, y1: ry + rh, x2: rx, y2: ry + rh},
      {x1: rx, y1: ry + rh, x2: rx, y2: ry}
    ];
    var pts = [];
    var l1 = {x: line.x, y: line.y}, l2 = {x: line.x + line.w, y: line.y + line.h};
    for (var i = 0; i < edges.length; i++) {
      var e = edges[i];
      var pt = lineSegIntersection(l1, l2, {x: e.x1, y: e.y1}, {x: e.x2, y: e.y2});
      if (pt) pts.push(pt);
    }
    return pts.length > 0 ? pts : null;
  }

  // 线段-圆交点（圆始终为完美正圆）
  function lineCircleIntersection(line, circle) {
    var r = Math.abs(circle.w) / 2;
    var cx = circle.w >= 0 ? circle.x + r : circle.x - r;
    var cy = circle.h >= 0 ? circle.y + r : circle.y - r;

    var dx = line.w, dy = line.h;
    var len = Math.sqrt(dx * dx + dy * dy);
    if (len < 0.0001) return null;
    var ux = dx / len, uy = dy / len;

    var ex = line.x - cx, ey = line.y - cy;
    var proj = -(ex * ux + ey * uy);
    var nearX = line.x + proj * ux;
    var nearY = line.y + proj * uy;
    var distSq = (nearX - cx) * (nearX - cx) + (nearY - cy) * (nearY - cy);
    var rSq = r * r;

    if (distSq > rSq) return null;
    if (Math.abs(distSq - rSq) < 0.0001) {
      // 相切
      var t = proj / len;
      if (t >= 0 && t <= 1) return [{x: nearX, y: nearY}];
      return null;
    }

    var half = Math.sqrt(rSq - distSq);
    var t1 = (proj - half) / len;
    var t2 = (proj + half) / len;
    var pts = [];
    if (t1 >= 0 && t1 <= 1) pts.push({x: line.x + t1 * dx, y: line.y + t1 * dy});
    if (t2 >= 0 && t2 <= 1) pts.push({x: line.x + t2 * dx, y: line.y + t2 * dy});
    return pts.length > 0 ? pts : null;
  }

  // 矩形-圆交点
  function rectCircleIntersection(rect, circle) {
    var rx = rect.w >= 0 ? rect.x : rect.x + rect.w;
    var ry = rect.h >= 0 ? rect.y : rect.y + rect.h;
    var rw = Math.abs(rect.w), rh = Math.abs(rect.h);
    var edges = [
      {x1: rx, y1: ry, x2: rx + rw, y2: ry},
      {x1: rx + rw, y1: ry, x2: rx + rw, y2: ry + rh},
      {x1: rx + rw, y1: ry + rh, x2: rx, y2: ry + rh},
      {x1: rx, y1: ry + rh, x2: rx, y2: ry}
    ];
    var pts = [];
    for (var i = 0; i < edges.length; i++) {
      var e = edges[i];
      var line = {x: e.x1, y: e.y1, w: e.x2 - e.x1, h: e.y2 - e.y1};
      var inter = lineCircleIntersection(line, circle);
      if (inter) {
        for (var j = 0; j < inter.length; j++) pts.push(inter[j]);
      }
    }
    return pts.length > 0 ? pts : null;
  }

  // ============================================
  // 网格绘制
  // ============================================

  function drawGrid() {
    if (!state.showGrid) return;
    var w = canvas.width / dpr;
    var h = canvas.height / dpr;
    var gs = state.gridSize;

    ctx.save();
    ctx.strokeStyle = '#e5e5e5';
    ctx.lineWidth = 0.5;

    ctx.beginPath();
    for (var x = 0; x <= w; x += gs) {
      ctx.moveTo(x, 0);
      ctx.lineTo(x, h);
    }
    for (var y = 0; y <= h; y += gs) {
      ctx.moveTo(0, y);
      ctx.lineTo(w, y);
    }
    ctx.stroke();
    ctx.restore();
  }

  // ============================================
  // 渲染引擎
  // ============================================

  function render() {
    var w = canvas.width / dpr;
    var h = canvas.height / dpr;

    ctx.clearRect(0, 0, w, h);

    // 背景
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, w, h);

    drawGrid();

    // 绘制所有已提交的路径
    for (var i = 0; i < paths.length; i++) {
      drawPath(paths[i], i);
    }

    // 绘制预览形状
    if (previewShape) {
      drawPath(previewShape, -1);
    }

    // 绘制实时手绘路径（钢笔工具拖拽时可见）
    if (currentPath) {
      drawPath(currentPath, -1);
    }

    // 绘制起点/锚点标记（比当前笔触略大以便观察）
    function drawAnchor(p) {
      if (!p) return;
      var r = Math.max(3, state.width + 1);
      ctx.save();
      ctx.fillStyle = state.color;
      ctx.beginPath();
      ctx.arc(p.x, p.y, r, 0, Math.PI * 2);
      ctx.fill();
      ctx.strokeStyle = '#ffffff';
      ctx.lineWidth = 1.5;
      ctx.stroke();
      ctx.restore();
    }
    drawAnchor(lineStart);
    drawAnchor(rectAnchor);
    drawAnchor(circleCenter);

    // 绘制吸附指示器
    if (snapHighlight && state.snapEnabled) {
      ctx.save();
      ctx.strokeStyle = '#bbbbbb';
      ctx.lineWidth = 0.8;
      var sh = 5;
      ctx.beginPath();
      ctx.moveTo(snapHighlight.x - sh, snapHighlight.y - sh);
      ctx.lineTo(snapHighlight.x + sh, snapHighlight.y + sh);
      ctx.moveTo(snapHighlight.x - sh, snapHighlight.y + sh);
      ctx.lineTo(snapHighlight.x + sh, snapHighlight.y - sh);
      ctx.stroke();
      ctx.restore();
    }

    // 绘制选中状态
    if (state.selectedIndices.length > 0) {
      drawSelectionHighlights();
    }
  }

  function drawPath(p, index) {
    ctx.save();

    switch (p.type) {
      case 'path':
        drawFreePath(p);
        break;
      case 'line':
        drawLine(p);
        break;
      case 'rect':
        drawRect(p);
        break;
      case 'circle':
        drawCircle(p);
        break;
      case 'text':
        drawTextItem(p);
        break;
      case 'axes':
        drawAxes(p);
        break;
      case 'func':
        drawFunc(p);
        break;
    }

    ctx.restore();
  }

  // -- Freehand path with quadratic smoothing --
  function drawFreePath(p) {
    var pts = p.points;
    if (pts.length < 2) return;
    ctx.strokeStyle = p.color;
    ctx.lineWidth = p.width;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.beginPath();
    ctx.moveTo(pts[0].x, pts[0].y);
    if (pts.length === 2) {
      ctx.lineTo(pts[1].x, pts[1].y);
    } else {
      for (var i = 1; i < pts.length - 1; i++) {
        var mx = (pts[i].x + pts[i + 1].x) / 2;
        var my = (pts[i].y + pts[i + 1].y) / 2;
        ctx.quadraticCurveTo(pts[i].x, pts[i].y, mx, my);
      }
      ctx.lineTo(pts[pts.length - 1].x, pts[pts.length - 1].y);
    }
    ctx.stroke();
  }

  function drawLine(p) {
    ctx.strokeStyle = p.color;
    ctx.lineWidth = p.width;
    ctx.lineCap = 'round';
    ctx.beginPath();
    ctx.moveTo(p.x, p.y);
    ctx.lineTo(p.x + p.w, p.y + p.h);
    ctx.stroke();
  }

  function drawRect(p) {
    ctx.strokeStyle = p.color;
    ctx.lineWidth = p.width;
    ctx.strokeRect(p.x, p.y, p.w, p.h);
  }

  function drawCircle(p) {
    ctx.strokeStyle = p.color;
    ctx.lineWidth = p.width;
    ctx.beginPath();
    var rx = Math.abs(p.w) / 2;
    var ry = Math.abs(p.h) / 2;
    var cx = p.w >= 0 ? p.x + rx : p.x - rx;
    var cy = p.h >= 0 ? p.y + ry : p.y - ry;
    ctx.ellipse(cx, cy, rx, ry, 0, 0, Math.PI * 2);
    ctx.stroke();
  }

  function drawTextItem(p) {
    ctx.fillStyle = p.color;
    ctx.font = (p.fontSize || 20) + 'px system-ui, sans-serif';
    ctx.textBaseline = 'top';
    var lines = p.text.split('\n');
    for (var i = 0; i < lines.length; i++) {
      ctx.fillText(lines[i], p.x, p.y + i * (p.fontSize || 20) * 1.3);
    }
  }

  // ============================================
  // 函数图象绘制
  // ============================================

  function evalFunction(expr, x) {
    try {
      // 将数学中的 ^（乘方）替换为 JS 的 **（指数运算符）
      expr = expr.replace(/\^/g, '**');
      var fn = new Function('x', 'with(Math){return (' + expr + ')}');
      var v = fn(x);
      return (typeof v === 'number' && isFinite(v)) ? v : NaN;
    } catch(e) {
      return NaN;
    }
  }

  function drawAxes(p) {
    var ox = p.ox, oy = p.oy;
    var sx = p.scaleX || 30, sy = p.scaleY || 30;
    var range = 5;
    var xEndPx = ox + range * sx;
    var xStartPx = ox - range * sx;
    var yEndPx = oy + range * sy;
    var yStartPx = oy - range * sy;

    ctx.save();

    // 坐标轴（限制 ±range 单位）
    ctx.strokeStyle = '#666';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(xStartPx, oy);
    ctx.lineTo(xEndPx, oy);
    ctx.moveTo(ox, yStartPx);
    ctx.lineTo(ox, yEndPx);
    ctx.stroke();

    // 箭头
    ctx.fillStyle = '#666';
    // X轴箭头（右端）
    ctx.beginPath();
    ctx.moveTo(xEndPx, oy);
    ctx.lineTo(xEndPx - 8, oy - 5);
    ctx.lineTo(xEndPx - 8, oy + 5);
    ctx.fill();
    // Y轴箭头（上端）
    ctx.beginPath();
    ctx.moveTo(ox, yStartPx);
    ctx.lineTo(ox - 5, yStartPx + 8);
    ctx.lineTo(ox + 5, yStartPx + 8);
    ctx.fill();

    // 刻度线和标签
    ctx.fillStyle = '#999';
    ctx.font = '11px system-ui, sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'top';

    // X轴刻度（限制在 ±range 内）
    var xStep = getAxisStep(sx);
    var xStart = Math.ceil(-range / xStep) * xStep;
    var xEnd = Math.floor(range / xStep) * xStep;
    ctx.strokeStyle = '#ccc';
    ctx.lineWidth = 0.5;
    for (var xv = xStart; xv <= xEnd; xv += xStep) {
      if (Math.abs(xv) < xStep * 0.01) continue;
      var px = ox + xv * sx;
      ctx.beginPath();
      ctx.moveTo(px, oy - 4);
      ctx.lineTo(px, oy + 4);
      ctx.stroke();
      ctx.fillStyle = '#999';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'top';
      ctx.fillText(formatNum(xv), px, oy + 6);
    }

    // Y轴刻度（限制在 ±range 内）
    var yStep = getAxisStep(sy);
    var yStart = Math.ceil(-range / yStep) * yStep;
    var yEnd = Math.floor(range / yStep) * yStep;
    ctx.strokeStyle = '#ccc';
    ctx.lineWidth = 0.5;
    for (var yv = yStart; yv <= yEnd; yv += yStep) {
      if (Math.abs(yv) < yStep * 0.01) continue;
      var py = oy - yv * sy;
      ctx.beginPath();
      ctx.moveTo(ox - 4, py);
      ctx.lineTo(ox + 4, py);
      ctx.stroke();
      ctx.fillStyle = '#999';
      ctx.textAlign = 'right';
      ctx.textBaseline = 'middle';
      ctx.fillText(formatNum(yv), ox - 7, py);
    }

    // 原点 O
    ctx.fillStyle = '#999';
    ctx.textAlign = 'right';
    ctx.textBaseline = 'top';
    ctx.font = '12px system-ui, sans-serif';
    ctx.fillText('O', ox - 6, oy + 6);

    ctx.restore();
  }

  function drawFunc(p) {
    var ox = p.ox, oy = p.oy;
    var sx = p.scaleX || 30, sy = p.scaleY || 30;
    var cw = canvas.width / dpr, ch = canvas.height / dpr;
    var range = 5;
    var minPx = ox - range * sx;
    var maxPx = ox + range * sx;

    if (!p.func || !p.func.trim()) return;

    ctx.save();
    ctx.strokeStyle = p.color;
    ctx.lineWidth = p.width;
    ctx.beginPath();
    var first = true;
    for (var px = Math.max(0, minPx); px <= Math.min(cw, maxPx); px++) {
      var xv = (px - ox) / sx;
      var yv = evalFunction(p.func, xv);
      if (isNaN(yv)) { first = true; continue; }
      var py = oy - yv * sy;
      if (py < -1000 || py > ch + 1000) { first = true; continue; }
      if (first) {
        ctx.moveTo(px, py);
        first = false;
      } else {
        ctx.lineTo(px, py);
      }
    }
    ctx.stroke();
    ctx.restore();
  }

  function getAxisStep(scale) {
    var raw = scale / 3;
    var mag = Math.pow(10, Math.floor(Math.log10(raw)));
    var norm = raw / mag;
    if (norm < 1.5) return mag;
    if (norm < 3.5) return 2 * mag;
    if (norm < 7.5) return 5 * mag;
    return 10 * mag;
  }

  function formatNum(v) {
    if (Number.isInteger(v)) return v.toString();
    var s = v.toFixed(2);
    s = s.replace(/\.?0+$/, '');
    return s;
  }

  // ============================================
  // 选中高亮绘制
  // ============================================

  function drawSelectionHighlights() {
    ctx.save();
    for (var si = 0; si < state.selectedIndices.length; si++) {
      var idx = state.selectedIndices[si];
      if (idx < 0 || idx >= paths.length) continue;
      var p = paths[idx];
      var bounds = getBounds(p);
      if (!bounds) continue;

      // 选中虚线框
      ctx.strokeStyle = '#778B3E';
      ctx.lineWidth = 1.5;
      ctx.setLineDash([4, 3]);
      ctx.strokeRect(bounds.x - 4, bounds.y - 4, bounds.w + 8, bounds.h + 8);
      ctx.setLineDash([]);

      // 四角手柄
      var handles = [
        { x: bounds.x - 4, y: bounds.y - 4 },
        { x: bounds.x + bounds.w + 4, y: bounds.y - 4 },
        { x: bounds.x - 4, y: bounds.y + bounds.h + 4 },
        { x: bounds.x + bounds.w + 4, y: bounds.y + bounds.h + 4 }
      ];
      ctx.fillStyle = '#778B3E';
      for (var hi = 0; hi < handles.length; hi++) {
        ctx.fillRect(handles[hi].x - 3, handles[hi].y - 3, 6, 6);
      }
    }
    ctx.restore();
  }

  // ============================================
  // 图形包围盒
  // ============================================

  function getBounds(p) {
    if (!p) return null;
    switch (p.type) {
      case 'path':
        return getPathBounds(p);
      case 'line': {
        var lx = p.w >= 0 ? p.x : p.x + p.w;
        var ly = p.h >= 0 ? p.y : p.y + p.h;
        return { x: lx, y: ly, w: Math.abs(p.w), h: Math.abs(p.h) };
      }
      case 'rect':
        return { x: p.x, y: p.y, w: Math.abs(p.w), h: Math.abs(p.h) };
      case 'circle': {
        var rx = Math.abs(p.w) / 2;
        var ry = Math.abs(p.h) / 2;
        var cx = p.w >= 0 ? p.x + rx : p.x - rx;
        var cy = p.h >= 0 ? p.y + ry : p.y - ry;
        return { x: cx - rx, y: cy - ry, w: rx * 2, h: ry * 2 };
      }
      case 'axes':
        return { x: 0, y: 0, w: canvas.width / dpr, h: canvas.height / dpr };
      case 'func': {
        var range = 5;
        var sx = p.scaleX || 30, sy = p.scaleY || 30;
        return {
          x: p.ox - range * sx,
          y: p.oy - range * sy,
          w: 2 * range * sx,
          h: 2 * range * sy
        };
      }
      case 'text': {
        var tw = (p.text || '').length * (p.fontSize || 20) * 0.6;
        var th = ((p.text || '').split('\n').length) * (p.fontSize || 20) * 1.3;
        return { x: p.x, y: p.y, w: tw, h: th };
      }
    }
    return null;
  }

  function getPathBounds(p) {
    if (!p.points || p.points.length === 0) return null;
    var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    for (var i = 0; i < p.points.length; i++) {
      if (p.points[i].x < minX) minX = p.points[i].x;
      if (p.points[i].y < minY) minY = p.points[i].y;
      if (p.points[i].x > maxX) maxX = p.points[i].x;
      if (p.points[i].y > maxY) maxY = p.points[i].y;
    }
    return { x: minX, y: minY, w: maxX - minX, h: maxY - minY };
  }

  // ============================================
  // 碰撞检测
  // ============================================

  var HIT_PADDING = 6;

  function hitTest(pos) {
    // 从后往前遍历（上层图形优先）
    for (var i = paths.length - 1; i >= 0; i--) {
      if (hitTestPath(pos, paths[i])) return i;
    }
    return -1;
  }

  function hitTestPath(pos, p) {
    switch (p.type) {
      case 'path': return hitTestFreePath(pos, p);
      case 'line': return hitTestLine(pos, p);
      case 'rect': return hitTestRect(pos, p);
      case 'circle': return hitTestCircle(pos, p);
      case 'text': return hitTestText(pos, p);
      case 'axes': return true;
      case 'func': {
        var range = 5;
        var sx = p.scaleX || 30, sy = p.scaleY || 30;
        var rx = p.ox - range * sx, ry = p.oy - range * sy;
        var rw = 2 * range * sx, rh = 2 * range * sy;
        return pos.x >= rx - HIT_PADDING && pos.x <= rx + rw + HIT_PADDING &&
               pos.y >= ry - HIT_PADDING && pos.y <= ry + rh + HIT_PADDING;
      }
    }
    return false;
  }

  function hitTestFreePath(pos, p) {
    var pts = p.points;
    if (!pts || pts.length < 2) return false;
    var threshold = Math.max(p.width / 2 + 2, HIT_PADDING);
    for (var i = 0; i < pts.length - 1; i++) {
      var dist = distToSegment(pos, pts[i], pts[i + 1]);
      if (dist < threshold) return true;
    }
    return false;
  }

  function hitTestLine(pos, p) {
    var ax = p.x, ay = p.y;
    var bx = p.x + p.w, by = p.y + p.h;
    var threshold = Math.max(p.width / 2 + 2, HIT_PADDING);
    return distToSegment(pos, { x: ax, y: ay }, { x: bx, y: by }) < threshold;
  }

  function hitTestRect(pos, p) {
    var rx = p.w >= 0 ? p.x : p.x + p.w;
    var ry = p.h >= 0 ? p.y : p.y + p.h;
    var rw = Math.abs(p.w), rh = Math.abs(p.h);
    // 点击内部或边缘
    return pos.x >= rx - HIT_PADDING && pos.x <= rx + rw + HIT_PADDING &&
           pos.y >= ry - HIT_PADDING && pos.y <= ry + rh + HIT_PADDING;
  }

  function hitTestCircle(pos, p) {
    var rx = Math.abs(p.w) / 2;
    var ry = Math.abs(p.h) / 2;
    var cx = p.w >= 0 ? p.x + rx : p.x - rx;
    var cy = p.h >= 0 ? p.y + ry : p.y - ry;
    // 椭圆近似碰撞：缩放坐标到单位圆
    var sx = (pos.x - cx) / (rx + HIT_PADDING);
    var sy = (pos.y - cy) / (ry + HIT_PADDING);
    return (sx * sx + sy * sy) <= 1;
  }

  function hitTestText(pos, p) {
    var fontSize = p.fontSize || 20;
    var lines = (p.text || '').split('\n');
    var maxW = 0;
    for (var i = 0; i < lines.length; i++) {
      var lw = lines[i].length * fontSize * 0.6;
      if (lw > maxW) maxW = lw;
    }
    var th = lines.length * fontSize * 1.3;
    return pos.x >= p.x - HIT_PADDING && pos.x <= p.x + maxW + HIT_PADDING &&
           pos.y >= p.y - HIT_PADDING && pos.y <= p.y + th + HIT_PADDING;
  }

  // 点到线段距离
  function distToSegment(p, a, b) {
    var dx = b.x - a.x;
    var dy = b.y - a.y;
    var lenSq = dx * dx + dy * dy;
    if (lenSq === 0) return Math.sqrt((p.x - a.x) * (p.x - a.x) + (p.y - a.y) * (p.y - a.y));
    var t = ((p.x - a.x) * dx + (p.y - a.y) * dy) / lenSq;
    t = Math.max(0, Math.min(1, t));
    var nearX = a.x + t * dx;
    var nearY = a.y + t * dy;
    return Math.sqrt((p.x - nearX) * (p.x - nearX) + (p.y - nearY) * (p.y - nearY));
  }

  // ============================================
  // 撤销 / 重做
  // ============================================

  function saveUndo() {
    undoStack.push(JSON.stringify(paths));
    if (undoStack.length > MAX_UNDO) {
      undoStack.shift();
    }
    redoStack = [];
    updateButtons();
  }

  function undo() {
    if (undoStack.length === 0) return;
    redoStack.push(JSON.stringify(paths));
    paths = JSON.parse(undoStack.pop());
    state.hasDrawn = paths.length > 0 || undoStack.length > 0;
    state.selectedIndices = [];
    state.dragShapes = false;
    render();
    updateButtons();
  }

  function redo() {
    if (redoStack.length === 0) return;
    undoStack.push(JSON.stringify(paths));
    paths = JSON.parse(redoStack.pop());
    state.hasDrawn = true;
    state.selectedIndices = [];
    state.dragShapes = false;
    render();
    updateButtons();
  }

  function updateButtons() {
    var undoBtn = document.getElementById('btnUndo');
    var redoBtn = document.getElementById('btnRedo');
    if (undoBtn) undoBtn.disabled = undoStack.length === 0;
    if (redoBtn) redoBtn.disabled = redoStack.length === 0;
  }

  // ============================================
  // 删除选中图形
  // ============================================

  function deleteSelected() {
    if (state.selectedIndices.length === 0) return;
    saveUndo();
    // 从大到小排序，从后往前删
    var sorted = state.selectedIndices.slice().sort(function (a, b) { return b - a; });
    for (var i = 0; i < sorted.length; i++) {
      paths.splice(sorted[i], 1);
    }
    state.selectedIndices = [];
    state.hasDrawn = paths.length > 0;
    render();
    updateButtons();
  }

  // ============================================
  // 指针事件处理
  // ============================================

  function onPointerDown(e) {
    e.preventDefault();
    canvas.setPointerCapture(e.pointerId);

    var pos = getPos(e);
    var ctrl = e.ctrlKey || e.metaKey;

    if (state.tool === 'text') {
      showTextOverlay(pos.x, pos.y);
      return;
    }

    // 函数图象工具：点击放置坐标轴和函数曲线（两个独立对象）
    if (state.tool === 'axes') {
      if (pendingAxes) {
        saveUndo();
        // 坐标轴线框
        paths.push({
          type: 'axes',
          ox: pos.x,
          oy: pos.y,
          scaleX: 30,
          scaleY: 30
        });
        // 函数曲线（如果有表达式）
        if (pendingAxes.func && pendingAxes.func.trim()) {
          paths.push({
            type: 'func',
            ox: pos.x,
            oy: pos.y,
            scaleX: 30,
            scaleY: 30,
            func: pendingAxes.func,
            color: state.color,
            width: state.width
          });
        }
        state.hasDrawn = true;
        pendingAxes = null;
        render();
        updateButtons();
        setTool('select');
      }
      return;
    }

    if (state.tool === 'select') {
      handleSelectPointerDown(pos, ctrl);
      return;
    }

    // 直线工具：点击-点击模式
    if (state.tool === 'line') {
      if (!lineStart) {
        // 第一次点击：记录起点，显示锚点
        lineStart = { x: pos.x, y: pos.y };
        previewShape = null;
        render();
      } else {
        // 第二次点击：完成直线
        var dx = pos.x - lineStart.x;
        var dy = pos.y - lineStart.y;
        if (Math.abs(dx) > 1 || Math.abs(dy) > 1) {
          saveUndo();
          paths.push({
            type: 'line',
            x: lineStart.x,
            y: lineStart.y,
            w: dx,
            h: dy,
            color: state.color,
            width: state.width
          });
          state.hasDrawn = true;
        }
        lineStart = null;
        previewShape = null;
        render();
        updateButtons();
      }
      return;
    }

    // 矩形工具：点击-点击模式（按住 Ctrl 强制正方形）
    if (state.tool === 'rect') {
      if (!rectAnchor) {
        rectAnchor = { x: pos.x, y: pos.y };
        previewShape = null;
        render();
      } else {
        var dx = pos.x - rectAnchor.x;
        var dy = pos.y - rectAnchor.y;
        if (e.ctrlKey || e.metaKey) {
          var side = Math.max(Math.abs(dx), Math.abs(dy));
          dx = side * (dx >= 0 ? 1 : -1);
          dy = side * (dy >= 0 ? 1 : -1);
        }
        if (Math.abs(dx) > 1 || Math.abs(dy) > 1) {
          saveUndo();
          paths.push({
            type: 'rect',
            x: dx >= 0 ? rectAnchor.x : rectAnchor.x + dx,
            y: dy >= 0 ? rectAnchor.y : rectAnchor.y + dy,
            w: Math.abs(dx),
            h: Math.abs(dy),
            color: state.color,
            width: state.width
          });
          state.hasDrawn = true;
        }
        rectAnchor = null;
        previewShape = null;
        render();
        updateButtons();
      }
      return;
    }

    // 圆形工具：点击-点击模式（先点圆心，再点半径）
    if (state.tool === 'circle') {
      if (!circleCenter) {
        circleCenter = { x: pos.x, y: pos.y };
        previewShape = null;
        render();
      } else {
        var dx = pos.x - circleCenter.x;
        var dy = pos.y - circleCenter.y;
        var r = Math.max(Math.abs(dx), Math.abs(dy));
        dx = r * (dx >= 0 ? 1 : -1);
        dy = r * (dy >= 0 ? 1 : -1);
        if (r > 1) {
          saveUndo();
          paths.push({
            type: 'circle',
            x: dx >= 0 ? circleCenter.x - r : circleCenter.x + r,
            y: dy >= 0 ? circleCenter.y - r : circleCenter.y + r,
            w: 2 * dx,
            h: 2 * dy,
            color: state.color,
            width: state.width
          });
          state.hasDrawn = true;
        }
        circleCenter = null;
        previewShape = null;
        render();
        updateButtons();
      }
      return;
    }

    // 绘制工具：开始画形状
    state.drawing = true;

    if (state.tool === 'pen') {
      currentPath = {
        type: 'path',
        points: [{ x: pos.x, y: pos.y }],
        color: state.color,
        width: state.width
      };
    } else {
      // rect / circle（兜底，实际已被上面各自的点击模式拦截）
      startPos = { x: pos.x, y: pos.y };
      previewShape = {
        type: state.tool,
        x: pos.x,
        y: pos.y,
        w: 0,
        h: 0,
        color: state.color,
        width: state.width
      };
    }
  }

  // -- 选择工具按下 --
  function handleSelectPointerDown(pos, ctrl) {
    var hitIdx = hitTest(pos);

    // 点击了空白区域
    if (hitIdx === -1) {
      state.selectedIndices = [];
      state.dragShapes = false;
      render();
      return;
    }

    // 点击了某个图形
    var alreadySelected = false;
    for (var i = 0; i < state.selectedIndices.length; i++) {
      if (state.selectedIndices[i] === hitIdx) {
        alreadySelected = true;
        break;
      }
    }

    if (ctrl) {
      // Ctrl+click: 切换选中状态
      if (alreadySelected) {
        removeFromSelection(hitIdx);
        state.dragShapes = false;
      } else {
        state.selectedIndices.push(hitIdx);
        state.dragShapes = true;
        initDrag(pos);
      }
    } else {
      if (alreadySelected && state.selectedIndices.length > 0) {
        // 点击已选中的图形：开始拖拽
        state.dragShapes = true;
        initDrag(pos);
      } else {
        // 点击未选中图形：单选
        state.selectedIndices = [hitIdx];
        state.dragShapes = true;
        initDrag(pos);
      }
    }
    render();
  }

  function removeFromSelection(idx) {
    var newSel = [];
    for (var i = 0; i < state.selectedIndices.length; i++) {
      if (state.selectedIndices[i] !== idx) {
        newSel.push(state.selectedIndices[i]);
      }
    }
    state.selectedIndices = newSel;
  }

  function initDrag(pos) {
    dragStart = { x: pos.x, y: pos.y };
    dragOrigins = [];
    for (var i = 0; i < state.selectedIndices.length; i++) {
      var idx = state.selectedIndices[i];
      if (idx >= 0 && idx < paths.length) {
        var p = paths[idx];
        dragOrigins.push({
          x: p.x || p.ox || 0,
          y: p.y || p.oy || 0,
          points: p.points ? JSON.parse(JSON.stringify(p.points)) : null
        });
      } else {
        dragOrigins.push(null);
      }
    }
  }

  function onPointerMove(e) {
    e.preventDefault();
    var pos = getPos(e);

    // 选择工具+拖拽移动
    if (state.tool === 'select' && state.dragShapes && dragStart) {
      moveSelectedShapes(pos);
      return;
    }

    // 直线工具：从起点到光标实时预览
    if (state.tool === 'line' && lineStart) {
      previewShape = {
        type: 'line',
        x: lineStart.x,
        y: lineStart.y,
        w: pos.x - lineStart.x,
        h: pos.y - lineStart.y,
        color: state.color,
        width: state.width
      };
      render();
      updateCursor(pos);
      return;
    }

    // 矩形工具：从第一个顶点到光标实时预览
    if (state.tool === 'rect' && rectAnchor) {
      var dx = pos.x - rectAnchor.x;
      var dy = pos.y - rectAnchor.y;
      if (e.ctrlKey || e.metaKey) {
        var side = Math.max(Math.abs(dx), Math.abs(dy));
        dx = side * (dx >= 0 ? 1 : -1);
        dy = side * (dy >= 0 ? 1 : -1);
      }
      previewShape = {
        type: 'rect',
        x: dx >= 0 ? rectAnchor.x : rectAnchor.x + dx,
        y: dy >= 0 ? rectAnchor.y : rectAnchor.y + dy,
        w: Math.abs(dx),
        h: Math.abs(dy),
        color: state.color,
        width: state.width
      };
      render();
      updateCursor(pos);
      return;
    }

    // 圆形工具：从圆心到光标实时预览（始终为正圆）
    if (state.tool === 'circle' && circleCenter) {
      var cdx = pos.x - circleCenter.x;
      var cdy = pos.y - circleCenter.y;
      var cr = Math.max(Math.abs(cdx), Math.abs(cdy));
      cdx = cr * (cdx >= 0 ? 1 : -1);
      cdy = cr * (cdy >= 0 ? 1 : -1);
      previewShape = {
        type: 'circle',
        x: cdx >= 0 ? circleCenter.x - cr : circleCenter.x + cr,
        y: cdy >= 0 ? circleCenter.y - cr : circleCenter.y + cr,
        w: 2 * cdx,
        h: 2 * cdy,
        color: state.color,
        width: state.width
      };
      render();
      updateCursor(pos);
      return;
    }

    if (!state.drawing) {
      updateCursor(pos);
      // 鼠标悬浮时也要渲染吸附指示器（getPos 已通过 snapToGeometry 设置 snapHighlight）
      render();
      return;
    }

    // 绘制中
    if (state.tool === 'pen') {
      if (!currentPath) return;
      currentPath.points.push({ x: pos.x, y: pos.y });
      render();
    } else if (startPos && previewShape) {
      var dx = pos.x - startPos.x;
      var dy = pos.y - startPos.y;
      previewShape.x = dx >= 0 ? startPos.x : pos.x;
      previewShape.y = dy >= 0 ? startPos.y : pos.y;
      previewShape.w = dx;
      previewShape.h = dy;
      render();
    }
  }

  function moveSelectedShapes(pos) {
    if (!dragStart || !dragOrigins) return;
    var offsetX = pos.x - dragStart.x;
    var offsetY = pos.y - dragStart.y;

    for (var i = 0; i < state.selectedIndices.length; i++) {
      var idx = state.selectedIndices[i];
      if (idx < 0 || idx >= paths.length) continue;
      var p = paths[idx];
      var origin = dragOrigins[i];
      if (!origin) continue;

      if (p.type === 'path' && p.points && origin.points) {
        for (var j = 0; j < p.points.length; j++) {
          p.points[j].x = origin.points[j].x + offsetX;
          p.points[j].y = origin.points[j].y + offsetY;
        }
      } else if (p.type === 'axes' || p.type === 'func') {
        p.ox = origin.x + offsetX;
        p.oy = origin.y + offsetY;
      } else {
        if (p.x !== undefined) p.x = origin.x + offsetX;
        if (p.y !== undefined) p.y = origin.y + offsetY;
      }
    }
    render();
  }

  function onPointerUp(e) {
    e.preventDefault();

    // 选择工具完成拖拽
    if (state.tool === 'select' && state.dragShapes) {
      state.dragShapes = false;
      dragStart = null;
      dragOrigins = null;
      // 如果确实移动了，存一下 undo
      return;
    }

    if (!state.drawing) return;
    state.drawing = false;

    if (state.tool === 'pen') {
      if (currentPath && currentPath.points.length > 1) {
        saveUndo();
        paths.push(currentPath);
        state.hasDrawn = true;
      }
      currentPath = null;
      render();
    } else if (startPos && previewShape) {
      if (Math.abs(previewShape.w) > 1 || Math.abs(previewShape.h) > 1) {
        saveUndo();
        paths.push({
          type: previewShape.type,
          x: previewShape.x,
          y: previewShape.y,
          w: previewShape.w,
          h: previewShape.h,
          color: previewShape.color,
          width: previewShape.width
        });
        state.hasDrawn = true;
      }
      previewShape = null;
      startPos = null;
      render();
    }

    updateButtons();
  }

  // ============================================
  // 光标样式
  // ============================================

  var dotCursor = "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='8' height='8'%3E%3Ccircle cx='4' cy='4' r='1.5' fill='%23555'/%3E%3C/svg%3E\") 4 4, crosshair";

  function updateCursor(pos) {
    var cursor = 'crosshair';
    switch (state.tool) {
      case 'pen':
      case 'line':
      case 'rect':
      case 'circle':
        cursor = dotCursor;
        break;
      case 'text':
        cursor = 'text';
        break;
      case 'axes':
        if (pendingAxes) {
          cursor = dotCursor;
        }
        break;
      case 'select':
        if (pos && hitTest(pos) !== -1) {
          cursor = 'pointer';
        } else {
          cursor = 'default';
        }
        break;
    }
    canvas.style.cursor = cursor;
  }

  // ============================================
  // 文本输入叠加层
  // ============================================

  var textOverlay = document.getElementById('textOverlay');
  var textInput = document.getElementById('textInput');
  var textConfirm = document.getElementById('textConfirm');
  var textCancel = document.getElementById('textCancel');

  function showTextOverlay(x, y) {
    textPending = { x: x, y: y };
    var wrapRect = wrap.getBoundingClientRect();
    var canvasRect = canvas.getBoundingClientRect();
    var left = canvasRect.left - wrapRect.left + x;
    var top = canvasRect.top - wrapRect.top + y;
    if (left + 200 > wrapRect.width) left = wrapRect.width - 210;
    if (top + 80 > wrapRect.height) top = wrapRect.height - 90;
    if (left < 0) left = 10;
    if (top < 0) top = 10;

    textOverlay.style.left = left + 'px';
    textOverlay.style.top = top + 'px';
    textOverlay.style.display = 'block';
    textInput.value = '';
    textInput.style.color = state.color;
    textInput.focus();
  }

  function hideTextOverlay() {
    textOverlay.style.display = 'none';
    textInput.value = '';
    textPending = null;
  }

  function confirmText() {
    if (!textPending) return;
    var txt = textInput.value.trim();
    if (txt) {
      saveUndo();
      paths.push({
        type: 'text',
        x: textPending.x,
        y: textPending.y,
        text: txt,
        color: state.color,
        fontSize: 20
      });
      state.hasDrawn = true;
      render();
      updateButtons();
    }
    hideTextOverlay();
  }

  if (textConfirm) textConfirm.addEventListener('click', confirmText);
  if (textCancel) textCancel.addEventListener('click', hideTextOverlay);
  if (textInput) {
    textInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); confirmText(); }
      if (e.key === 'Escape') { hideTextOverlay(); }
    });
  }

  // ============================================
  // 函数图象输入叠加层
  // ============================================

  function showAxesOverlay() {
    axesOverlay.style.display = 'flex';
    axesInput.value = '';
    axesInput.focus();
    // 切换工具为 axes 但等待确认后才能放置
    state.tool = 'axes';
    pendingAxes = null;
    var btns = document.querySelectorAll('.drawboard-tool');
    for (var i = 0; i < btns.length; i++) {
      btns[i].classList.toggle('active', btns[i].dataset.tool === 'axes');
    }
  }

  function hideAxesOverlay() {
    axesOverlay.style.display = 'none';
    axesInput.value = '';
    pendingAxes = null;
  }

  function confirmAxes() {
    var expr = axesInput.value.trim();
    if (!expr) return;
    pendingAxes = { func: expr };
    axesOverlay.style.display = 'none';
    canvas.style.cursor = 'crosshair';
  }

  if (axesConfirm) axesConfirm.addEventListener('click', confirmAxes);
  if (axesCancel) axesCancel.addEventListener('click', function () {
    hideAxesOverlay();
    setTool('pen');
  });
  if (axesInput) {
    axesInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); confirmAxes(); }
      if (e.key === 'Escape') { e.preventDefault(); hideAxesOverlay(); setTool('pen'); }
    });
  }

  // 点击 "函数" 工具按钮时弹出输入框
  // 由 toolbar 事件统一处理，通过 setTool 调用
  // 这里覆写 setTool 的 axes 分支行为
  var _settingTool = false;
  var origSetTool = setTool;
  setTool = function(tool) {
    if (_settingTool) return;
    _settingTool = true;
    if (tool === 'axes') {
      showAxesOverlay();
      _settingTool = false;
      return;
    }
    hideAxesOverlay();
    origSetTool(tool);
    _settingTool = false;
  };

  // ============================================
  // 坐标轴缩放控制
  // ============================================

  function getSelectedAxes() {
    for (var i = 0; i < state.selectedIndices.length; i++) {
      var idx = state.selectedIndices[i];
      if (idx >= 0 && idx < paths.length && (paths[idx].type === 'axes' || paths[idx].type === 'func')) {
        return paths[idx];
      }
    }
    return null;
  }

  function updateAxesScaleSection() {
    if (!axesScaleSection) return;
    // 仅在选择工具且选中坐标轴时显示缩放面板
    if (state.tool === 'select' && getSelectedAxes()) {
      var axesObj = getSelectedAxes();
      axesScaleSection.style.display = 'block';
      if (scaleXVal) scaleXVal.textContent = axesObj.scaleX || 30;
      if (scaleYVal) scaleYVal.textContent = axesObj.scaleY || 30;
    } else {
      axesScaleSection.style.display = 'none';
    }
  }

  var scaleBtns = document.querySelectorAll('.drawboard-scale-btn');
  for (var si = 0; si < scaleBtns.length; si++) {
    (function(btn) {
      btn.addEventListener('click', function () {
        var axesObj = getSelectedAxes();
        if (!axesObj) return;
        var axis = btn.dataset.axis;
        var dir = parseInt(btn.dataset.dir, 10);
        var key = axis === 'x' ? 'scaleX' : 'scaleY';
        var newVal = Math.max(5, (axesObj[key] || 30) + dir * 5);
        // 同步更新所有 axes 和 func 对象的缩放
        for (var pi = 0; pi < paths.length; pi++) {
          if (paths[pi].type === 'axes' || paths[pi].type === 'func') {
            paths[pi][key] = newVal;
          }
        }
        if (scaleXVal) scaleXVal.textContent = axesObj.scaleX;
        if (scaleYVal) scaleYVal.textContent = axesObj.scaleY;
        render();
      });
    })(scaleBtns[si]);
  }

  // 覆写 render 以在渲染后更新缩放面板
  var origRender = render;
  render = function() {
    origRender();
    updateAxesScaleSection();
  };

  // ============================================
  // 工具切换
  // ============================================

  function setTool(tool) {
    state.tool = tool;
    lineStart = null;
    rectAnchor = null;
    circleCenter = null;
    previewShape = null;
    if (textOverlay.style.display === 'block') hideTextOverlay();
    var btns = document.querySelectorAll('.drawboard-tool');
    for (var i = 0; i < btns.length; i++) {
      btns[i].classList.toggle('active', btns[i].dataset.tool === tool);
    }
    canvas.style.cursor = 'crosshair';
  }

  // ============================================
  // 事件绑定
  // ============================================

  canvas.addEventListener('pointerdown', onPointerDown);
  canvas.addEventListener('pointermove', onPointerMove);
  canvas.addEventListener('pointerup', onPointerUp);
  canvas.addEventListener('pointercancel', onPointerUp);
  canvas.addEventListener('pointerleave', function () { /* do nothing */ });
  canvas.addEventListener('touchstart', function (e) { e.preventDefault(); }, { passive: false });
  canvas.addEventListener('touchmove', function (e) { e.preventDefault(); }, { passive: false });

  // ============================================
  // 工具栏事件
  // ============================================

  var toolBtns = document.querySelectorAll('.drawboard-tool');
  for (var i = 0; i < toolBtns.length; i++) {
    (function (btn) {
      btn.addEventListener('click', function () {
        setTool(btn.dataset.tool);
      });
    })(toolBtns[i]);
  }

  // ============================================
  // 颜色
  // ============================================

  var colorPicker = document.getElementById('colorPicker');
  if (colorPicker) {
    colorPicker.addEventListener('input', function () {
      state.color = colorPicker.value;
      // 同步高亮预设按钮
      var presets = document.querySelectorAll('.drawboard-color-preset');
      for (var pi = 0; pi < presets.length; pi++) {
        presets[pi].classList.toggle('active', presets[pi].dataset.color === colorPicker.value);
      }
    });
  }

  // 颜色预设
  var colorPresets = document.querySelectorAll('.drawboard-color-preset');
  for (var pi = 0; pi < colorPresets.length; pi++) {
    (function (btn) {
      btn.addEventListener('click', function () {
        var c = btn.dataset.color;
        state.color = c;
        if (colorPicker) colorPicker.value = c;
        for (var pj = 0; pj < colorPresets.length; pj++) {
          colorPresets[pj].classList.toggle('active', colorPresets[pj] === btn);
        }
        // 重新绘制锚点以匹配新颜色
        render();
      });
    })(colorPresets[pi]);
  }

  // ============================================
  // 笔触宽度
  // ============================================

  var strokeBtns = document.querySelectorAll('.drawboard-stroke-btn');
  for (var i = 0; i < strokeBtns.length; i++) {
    (function (btn) {
      btn.addEventListener('click', function () {
        for (var j = 0; j < strokeBtns.length; j++) {
          strokeBtns[j].classList.remove('active');
        }
        btn.classList.add('active');
        state.width = parseInt(btn.dataset.width, 10);
      });
    })(strokeBtns[i]);
  }

  // ============================================
  // 网格
  // ============================================

  var gridToggle = document.getElementById('gridToggle');
  if (gridToggle) {
    gridToggle.addEventListener('change', function () {
      state.showGrid = gridToggle.checked;
      render();
    });
  }

  var snapToggle = document.getElementById('snapToggle');
  if (snapToggle) {
    snapToggle.addEventListener('change', function () {
      state.snapEnabled = snapToggle.checked;
    });
  }

  var gridBtns = document.querySelectorAll('.drawboard-grid-btn');
  for (var i = 0; i < gridBtns.length; i++) {
    (function (btn) {
      btn.addEventListener('click', function () {
        for (var j = 0; j < gridBtns.length; j++) {
          gridBtns[j].classList.remove('active');
        }
        btn.classList.add('active');
        state.gridSize = parseInt(btn.dataset.size, 10);
        if (state.showGrid) render();
      });
    })(gridBtns[i]);
  }

  // ============================================
  // 操作按钮
  // ============================================

  document.getElementById('btnUndo').addEventListener('click', undo);
  document.getElementById('btnRedo').addEventListener('click', redo);

  document.getElementById('btnClear').addEventListener('click', function () {
    if (!state.hasDrawn) return;
    if (!confirm('确定要清空画布吗？')) return;
    saveUndo();
    paths = [];
    state.hasDrawn = false;
    state.selectedIndices = [];
    render();
    updateButtons();
  });

  document.getElementById('btnExport').addEventListener('click', function () {
    // 保存当前状态
    var savedSel = state.selectedIndices;
    var savedGrid = state.showGrid;
    var savedPreview = previewShape;
    var savedLineStart = lineStart;
    var savedRectAnchor = rectAnchor;
    var savedCircleCenter = circleCenter;
    var savedCurrentPath = currentPath;

    // 导出时不包含网格、选中状态、临时图形
    state.showGrid = false;
    state.selectedIndices = [];
    previewShape = null;
    lineStart = null;
    rectAnchor = null;
    circleCenter = null;
    currentPath = null;
    render();

    canvas.toBlob(function (blob) {
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url;
      a.download = 'drawboard-' + Date.now() + '.png';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);

      // 恢复状态
      state.showGrid = savedGrid;
      state.selectedIndices = savedSel;
      previewShape = savedPreview;
      lineStart = savedLineStart;
      rectAnchor = savedRectAnchor;
      circleCenter = savedCircleCenter;
      currentPath = savedCurrentPath;
      render();
    }, 'image/png');
  });

  // ============================================
  // 快捷键
  // ============================================

  document.addEventListener('keydown', function (e) {
    if (textOverlay.style.display === 'block') return;

    var ctrl = e.ctrlKey || e.metaKey;

    // Ctrl+Z 撤销
    if (ctrl && !e.shiftKey && e.key === 'z') {
      e.preventDefault();
      undo();
      return;
    }
    // Ctrl+Shift+Z / Ctrl+Y 重做
    if ((ctrl && e.shiftKey && e.key === 'z') || (ctrl && e.key === 'y')) {
      e.preventDefault();
      redo();
      return;
    }

    // Ctrl+A 全选
    if (ctrl && (e.key === 'a' || e.key === 'A')) {
      if (paths.length > 0) {
        e.preventDefault();
        state.selectedIndices = [];
        for (var ai = 0; ai < paths.length; ai++) {
          state.selectedIndices.push(ai);
        }
        render();
      }
      return;
    }

    // Delete / Backspace 删除选中
    if (e.key === 'Delete' || e.key === 'Backspace') {
      deleteSelected();
      return;
    }

    // 工具快捷键
    var toolMap = {
      'p': 'pen',
      'l': 'line',
      'r': 'rect',
      'c': 'circle',
      't': 'text',
      'f': 'axes',
      'v': 'select'
    };

    var tool = toolMap[e.key.toLowerCase()];
    if (tool && !ctrl && !e.altKey) {
      e.preventDefault();
      setTool(tool);
      return;
    }

    // Esc 取消
    if (e.key === 'Escape') {
      if (lineStart || rectAnchor || circleCenter) {
        lineStart = null;
        rectAnchor = null;
        circleCenter = null;
        previewShape = null;
        render();
      }
      if (previewShape) {
        previewShape = null;
        startPos = null;
        state.drawing = false;
        render();
      }
      // 取消选中
      if (state.selectedIndices.length > 0) {
        state.selectedIndices = [];
        render();
      }
    }
  });

  // ============================================
  // 初始化
  // ============================================

  updateButtons();
  resizeCanvas();

  var defaultStroke = document.querySelector('.drawboard-stroke-btn[data-width="1"]');
  if (defaultStroke) defaultStroke.classList.add('active');
  state.width = 1;

  var defaultGrid = document.querySelector('.drawboard-grid-btn[data-size="20"]');
  if (defaultGrid) defaultGrid.classList.add('active');

  var resizeTimer;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(resizeCanvas, 150);
  });

})();
