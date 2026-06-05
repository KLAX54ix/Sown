// Quill Editor Configuration and Custom Handlers

(function() {
  'use strict';

  // 等待 DOM 加载完成
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initEditor);
  } else {
    initEditor();
  }

  // 全局变量保存 Quill 实例
  var quillInstance = null;
  var mathPanelOutsideClickHandler = null;

  /** 与全站 app-dialog 一致；未加载 app.js 时回退原生 alert */
  function sownNotify(msg) {
    if (typeof window.showAppAlert === 'function') {
      window.showAppAlert(msg);
    } else {
      window.alert(msg);
    }
  }

  function initEditor() {
    var editorContainer = document.getElementById('editor');
    if (!editorContainer) return;

    if (typeof Quill === 'undefined') {
      console.error('Quill 未加载');
      return;
    }

    // 行高（块级样式）
    var Parchment = Quill.import('parchment');
    var LineHeightStyle = new Parchment.Attributor.Style('lineHeight', 'line-height', {
      scope: Parchment.Scope.BLOCK,
      whitelist: ['1.2', '1.6', '2', '2.6']
    });
    Quill.register(LineHeightStyle, true);
    var SlotClass = new Parchment.Attributor.Class('slot', 'ql-slot', {
      scope: Parchment.Scope.INLINE,
      whitelist: ['super', 'sub']
    });
    Quill.register(SlotClass, true);

    // 字号：仅用一项下拉（像素），多档位；不再使用 header/第二套 small·large·huge
    var SizeStyle = Quill.import('attributors/style/size');
    SizeStyle.whitelist = [
      false,
      '14px', '16px', '20px', '24px', '30px', '36px'
    ];
    Quill.register(SizeStyle, true);

    // 先添加自定义工具栏按钮的 HTML
    addCustomToolbarButtons();

    // 定义自定义工具栏（单一字号控制 + 行距等）
    // 行距首项 false = 恢复默认（与 Quill addSelect 一致）；不要自定义 handler，交给默认 format + USER
    var toolbarOptions = [
      [{ 'size': SizeStyle.whitelist }],
      [{ 'lineHeight': [false, '1.2', '1.6', '2', '2.6'] }],
      ['bold', 'italic', 'underline', 'strike'],
      ['image', 'formula'],
      [{ 'color': [] }, { 'background': [] }],
      [{ 'align': [] }],
      ['blockquote', 'code-block'],
      ['clean'],
      ['math-symbols']
    ];

    // 创建 Quill 实例
    quillInstance = new Quill('#editor', {
      theme: 'snow',
      modules: {
        formula: true,
        toolbar: {
          container: toolbarOptions,
          handlers: {
            'math-symbols': function() {
              showMathSymbolsPanel(quillInstance);
            },
            'image': function() {
              var quill = quillInstance || window.sownQuillEditor;
              if (!quill) return;
              var input = document.createElement('input');
              input.setAttribute('type', 'file');
              input.setAttribute('accept', 'image/jpeg,image/png,image/webp,image/gif');
              input.onchange = function() {
                var file = input.files[0];
                if (!file) return;
                if (file.size > 5 * 1024 * 1024) {
                  sownNotify('图片大小不能超过5MB');
                  return;
                }
                var formData = new FormData();
                formData.append('image', file);
                var csrfInput = document.querySelector('input[name="csrf"]');
                if (csrfInput) formData.append('csrf', csrfInput.value);

                fetch('/image_upload.php', {
                  method: 'POST',
                  body: formData,
                  headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                  }
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                  if (data.success && data.url) {
                    var range = quill.getSelection(true);
                    var index = range ? range.index : quill.getLength();
                    quill.insertEmbed(index, 'image', data.url, 'user');
                    quill.setSelection(index + 1);
                  } else {
                    sownNotify(data.error || '上传失败');
                  }
                })
                .catch(function() {
                  sownNotify('上传失败，请检查网络');
                });
              };
              input.click();
            }
          }
        }
      },
      placeholder: '写下你的内容…'
    });

    window.sownQuillEditor = quillInstance;
    // 点击上下标占位框时，自动选中占位内容，便于直接输入替换
    quillInstance.root.addEventListener('click', function(e) {
      var slotEl = e.target && e.target.closest
        ? e.target.closest('.ql-slot-super, .ql-slot-sub')
        : null;
      if (!slotEl) return;
      var blot = Quill.find(slotEl);
      if (!blot) return;
      var idx = quillInstance.getIndex(blot);
      var len = Math.max(1, (slotEl.textContent || '').length);
      quillInstance.setSelection(idx, len, 'user');
    });

    window.sownSyncQuillToContentInput = function() {
      if (!quillInstance) {
        return { ok: false, msg: '编辑器未初始化' };
      }
      var contentInput = document.getElementById('contentInput');
      if (!contentInput) {
        return { ok: false, msg: '表单错误' };
      }
      var html = quillInstance.root.innerHTML;
      var text = quillInstance.getText().trim();
      var tempDiv = document.createElement('div');
      tempDiv.innerHTML = html;
      var plainText = (tempDiv.textContent || tempDiv.innerText || '').trim();
      if (!text && !plainText) {
        return { ok: false, msg: '请输入内容' };
      }
      if (html.length > 500000) {
        return { ok: false, msg: '内容过长，请精简后重试' };
      }
      contentInput.value = html;
      return { ok: true };
    };

    var initTa = document.getElementById('editorInitialHtml');
    if (initTa && initTa.value) {
      var initialHtml = initTa.value;
      quillInstance.clipboard.dangerouslyPasteHTML(0, initialHtml);
      initTa.parentNode.removeChild(initTa);
    }

    // 初始化数学符号面板
    initMathSymbolsPanel();

    // 发布页使用 AJAX 提交（postNoteForm），由页面调用 sownSyncQuillToContentInput

    // 处理 Markdown 语法（在输入时自动转换）
    quillInstance.on('text-change', function() {
      // 占位框被真实内容替换后，去掉“slot”框样式，仅保留上下标格式
      cleanupFilledSlots();

      // Markdown 支持可以通过后处理实现
      // 这里可以添加实时 Markdown 预览等功能
      
      // 更新封面图片选择
      updateCoverImageSelection();
    });
    
    // 初始化封面图片选择
    updateCoverImageSelection();

    window.sownRefreshCoverImageSelection = function() {
      updateCoverImageSelection();
    };
  }
  
  // 更新封面图片选择（仅显示已上传的封面，不从正文提取）
  function updateCoverImageSelection() {
    var coverSection = document.getElementById('coverImageSection');
    var coverImageList = document.getElementById('coverImageList');
    var coverImageInput = document.getElementById('coverImageInput');
    var removeCoverBtn = document.getElementById('removeCoverBtn');

    if (!coverSection || !coverImageList || !coverImageInput) return;

    var coverVal = (coverImageInput.value || '').trim();

    coverSection.style.display = 'block';
    coverImageList.innerHTML = '';

    if (coverVal && coverVal.indexOf('/uploads/') === 0) {
      var imgDiv = document.createElement('div');
      imgDiv.className = 'cover-image-item';
      imgDiv.style.cssText = 'position:relative; width:120px; height:120px; border:2px solid #007bff; border-radius:8px; overflow:hidden; cursor:pointer; transition:all 0.2s;';

      var img = document.createElement('img');
      img.src = coverVal;
      img.style.cssText = 'width:100%; height:100%; object-fit:cover;';
      imgDiv.appendChild(img);

      var checkmark = document.createElement('div');
      checkmark.className = 'cover-checkmark';
      checkmark.innerHTML = '✓';
      checkmark.style.cssText = 'position:absolute; top:4px; right:4px; width:24px; height:24px; background:#007bff; color:#fff; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:14px; font-weight:bold;';
      imgDiv.appendChild(checkmark);

      coverImageList.appendChild(imgDiv);
    }

    if (removeCoverBtn) {
      removeCoverBtn.style.display = coverVal ? 'inline-block' : 'none';
    }

    if (typeof window.refreshPostCommunityPreview === 'function') {
      window.refreshPostCommunityPreview();
    }
  }
  
  // 取消封面按钮
  document.addEventListener('DOMContentLoaded', function() {
    var removeCoverBtn = document.getElementById('removeCoverBtn');
    if (removeCoverBtn) {
      removeCoverBtn.addEventListener('click', function() {
        var coverImageInput = document.getElementById('coverImageInput');
        var coverImageList = document.getElementById('coverImageList');
        
        if (coverImageInput) coverImageInput.value = '';
        if (coverImageList) {
          coverImageList.querySelectorAll('.cover-image-item').forEach(function(item) {
            item.style.borderColor = '#ddd';
            var cm = item.querySelector('.cover-checkmark');
            if (cm) cm.style.display = 'none';
          });
        }
        removeCoverBtn.style.display = 'none';
        if (typeof window.sownRefreshCoverImageSelection === 'function') {
          window.sownRefreshCoverImageSelection();
        }
      });
    }
  });

  // 添加自定义工具栏按钮
  function addCustomToolbarButtons() {
    // Quill 会根据 toolbarOptions 生成按钮，这里仅修饰数学按钮外观，不重复创建按钮
    setTimeout(function() {
      var toolbar = document.querySelector('.ql-toolbar');
      if (!toolbar) return;
      var mathSymbolsBtn = toolbar.querySelector('button.ql-math-symbols');
      if (!mathSymbolsBtn) return;
      mathSymbolsBtn.type = 'button';
      mathSymbolsBtn.innerHTML = 'Ω';
      mathSymbolsBtn.title = '插入数学符号';
      mathSymbolsBtn.classList.add('ql-math-symbols--custom');
    }, 100);
  }

  // 初始化数学符号面板
  function initMathSymbolsPanel() {
    var panel = document.getElementById('mathSymbolsPanel');
    if (!panel) return;

    var symbols = {
      '上下标模板': [
        { label: '上标□', action: 'super-placeholder' },
        { label: '下标□', action: 'sub-placeholder' }
      ],
      '运算符': [
        { symbol: '±', name: 'plus-minus' }, { symbol: '×', name: 'times' }, { symbol: '÷', name: 'divide' }, { symbol: '·', name: 'dot' },
        { symbol: '√', name: 'sqrt' }, { symbol: '∛', name: 'cuberoot' }, { symbol: '∜', name: 'fourth-root' }, { symbol: '∞', name: 'infinity' },
        { symbol: '∑', name: 'sum' }, { symbol: '∏', name: 'product' }, { symbol: '∫', name: 'integral' }, { symbol: '∬', name: 'double-integral' },
        { symbol: '∭', name: 'triple-integral' }, { symbol: '∮', name: 'contour-integral' }, { symbol: '∂', name: 'partial' }, { symbol: '∇', name: 'nabla' },
        { symbol: '∝', name: 'proportional' }, { symbol: '⊕', name: 'oplus' }, { symbol: '⊗', name: 'otimes' }, { symbol: '⊙', name: 'odot' }
      ],
      '关系符': [
        { symbol: '=', name: 'equal' }, { symbol: '≠', name: 'not-equal' }, { symbol: '<', name: 'less' }, { symbol: '>', name: 'greater' },
        { symbol: '≤', name: 'less-equal' }, { symbol: '≥', name: 'greater-equal' }, { symbol: '≈', name: 'approx' }, { symbol: '≡', name: 'equiv' },
        { symbol: '≅', name: 'congruent' }, { symbol: '∼', name: 'similar' }, { symbol: '∈', name: 'in' }, { symbol: '∉', name: 'not-in' },
        { symbol: '⊂', name: 'subset' }, { symbol: '⊆', name: 'subset-eq' }, { symbol: '⊃', name: 'superset' }, { symbol: '⊇', name: 'superset-eq' },
        { symbol: '∪', name: 'union' }, { symbol: '∩', name: 'intersection' }, { symbol: '∅', name: 'empty' }, { symbol: '∀', name: 'for-all' },
        { symbol: '∃', name: 'exists' }, { symbol: '∄', name: 'not-exists' }
      ],
      '角度与弧度': [
        { symbol: '∠', name: 'angle' }, { symbol: '°', name: 'degree' }, { symbol: 'rad', name: 'radian' }, { symbol: 'π', name: 'pi' },
        { symbol: 'θ', name: 'theta' }, { symbol: 'sin', name: 'sin' }, { symbol: 'cos', name: 'cos' }, { symbol: 'tan', name: 'tan' },
        { symbol: 'cot', name: 'cot' }, { symbol: 'sec', name: 'sec' }, { symbol: 'csc', name: 'csc' }, { symbol: '⊥', name: 'perpendicular' },
        { symbol: '∥', name: 'parallel' }, { symbol: '′', name: 'prime' }, { symbol: '″', name: 'double-prime' }
      ],
      '箭头与逻辑': [
        { symbol: '→', name: 'right-arrow' }, { symbol: '←', name: 'left-arrow' }, { symbol: '↔', name: 'both-arrow' },
        { symbol: '⇒', name: 'implies' }, { symbol: '⇐', name: 'implied-by' }, { symbol: '⇔', name: 'iff' },
        { symbol: '∴', name: 'therefore' }, { symbol: '∵', name: 'because' }, { symbol: '¬', name: 'not' },
        { symbol: '∧', name: 'and' }, { symbol: '∨', name: 'or' }
      ],
      '希腊字母': [
        { symbol: 'α', name: 'alpha' }, { symbol: 'β', name: 'beta' }, { symbol: 'γ', name: 'gamma' }, { symbol: 'δ', name: 'delta' },
        { symbol: 'ε', name: 'epsilon' }, { symbol: 'ζ', name: 'zeta' }, { symbol: 'η', name: 'eta' }, { symbol: 'θ', name: 'theta' },
        { symbol: 'ι', name: 'iota' }, { symbol: 'κ', name: 'kappa' }, { symbol: 'λ', name: 'lambda' }, { symbol: 'μ', name: 'mu' },
        { symbol: 'ν', name: 'nu' }, { symbol: 'ξ', name: 'xi' }, { symbol: 'ο', name: 'omicron' }, { symbol: 'π', name: 'pi' },
        { symbol: 'ρ', name: 'rho' }, { symbol: 'σ', name: 'sigma' }, { symbol: 'τ', name: 'tau' }, { symbol: 'υ', name: 'upsilon' },
        { symbol: 'φ', name: 'phi' }, { symbol: 'χ', name: 'chi' }, { symbol: 'ψ', name: 'psi' }, { symbol: 'ω', name: 'omega' },
        { symbol: 'Γ', name: 'Gamma' }, { symbol: 'Δ', name: 'Delta' }, { symbol: 'Θ', name: 'Theta' }, { symbol: 'Λ', name: 'Lambda' },
        { symbol: 'Ξ', name: 'Xi' }, { symbol: 'Π', name: 'Pi' }, { symbol: 'Σ', name: 'Sigma' }, { symbol: 'Φ', name: 'Phi' },
        { symbol: 'Ψ', name: 'Psi' }, { symbol: 'Ω', name: 'Omega' }
      ]
    };

    var html = '';
    html += '<div class="symbol-panel-head">';
    html += '<div class="symbol-panel-title">常用数学符号</div>';
    html += '<button type="button" class="symbol-panel-close" title="关闭">完成</button>';
    html += '</div>';
    for (var groupName in symbols) {
      html += '<div class="symbol-group">';
      html += '<div class="symbol-group-title">' + groupName + '</div>';
      html += '<div class="symbols">';
      symbols[groupName].forEach(function(item) {
        if (item.action) {
          html += '<button type="button" class="symbol-btn symbol-btn--action" data-action="' + item.action + '">' + item.label + '</button>';
        } else {
          html += '<button type="button" class="symbol-btn" data-symbol="' + item.symbol + '">' + item.symbol + '</button>';
        }
      });
      html += '</div>';
      html += '</div>';
    }
    panel.innerHTML = html;

    panel.addEventListener('click', function(e) {
      e.stopPropagation();
    });

    // 绑定符号按钮点击事件（面板打开时不自动关闭，可连续输入）
    panel.addEventListener('click', function(e) {
      var closeBtn = e.target.closest('.symbol-panel-close');
      if (closeBtn) {
        hideMathSymbolsPanel();
        return;
      }
      var btn = e.target.closest('.symbol-btn');
      if (!btn) return;
      var action = btn.getAttribute('data-action');
      if (action === 'super-placeholder') {
        insertScriptPlaceholder('super');
        return;
      }
      if (action === 'sub-placeholder') {
        insertScriptPlaceholder('sub');
        return;
      }
      var symbol = btn.getAttribute('data-symbol');
      if (symbol) {
        insertSymbol(symbol);
      }
    });
  }

  // 显示数学符号面板
  function showMathSymbolsPanel(quill) {
    var panel = document.getElementById('mathSymbolsPanel');
    if (!panel) return;

    var toolbar = document.querySelector('.ql-toolbar');
    if (!toolbar) return;
    var mathBtn = toolbar.querySelector('.ql-math-symbols');
    if (!mathBtn) return;

    if (panel.style.display === 'block') {
      hideMathSymbolsPanel();
      return;
    }
    
    var rect = mathBtn.getBoundingClientRect();
    var left = rect.left;
    var top = rect.bottom + 8;
    var maxLeft = Math.max(8, window.innerWidth - 420);
    panel.style.left = Math.min(left, maxLeft) + 'px';
    panel.style.top = Math.min(top, window.innerHeight - 320) + 'px';
    
    panel.style.display = 'block';
    
    // 点击外部关闭面板（先清理旧监听，避免多次绑定导致异常）
    if (mathPanelOutsideClickHandler) {
      document.removeEventListener('click', mathPanelOutsideClickHandler);
    }
    mathPanelOutsideClickHandler = function(e) {
      if (!panel.contains(e.target) && !mathBtn.contains(e.target)) {
        hideMathSymbolsPanel();
      }
    };
    setTimeout(function() {
      document.addEventListener('click', mathPanelOutsideClickHandler);
    }, 0);
  }

  function hideMathSymbolsPanel() {
    var panel = document.getElementById('mathSymbolsPanel');
    if (panel) {
      panel.style.display = 'none';
    }
    if (mathPanelOutsideClickHandler) {
      document.removeEventListener('click', mathPanelOutsideClickHandler);
      mathPanelOutsideClickHandler = null;
    }
  }

  // 插入符号
  function insertSymbol(symbol) {
    var quill = quillInstance;
    if (!quill) {
      var editor = document.getElementById('editor');
      if (editor) {
        quill = Quill.find(editor);
      }
    }
    
    if (quill) {
      var range = quill.getSelection(true);
      if (!range) {
        range = { index: quill.getLength(), length: 0 };
      }
      quill.insertText(range.index, symbol, 'user');
      quill.setSelection(range.index + 1);
    }
  }

  // 插入上/下标占位框（插入可继续输入的 script 占位符）
  function insertScriptPlaceholder(type) {
    var quill = quillInstance;
    if (!quill) {
      var editor = document.getElementById('editor');
      if (editor) {
        quill = Quill.find(editor);
      }
    }
    if (!quill) return;

    var range = quill.getSelection(true);
    if (!range) {
      range = { index: quill.getLength(), length: 0 };
    }

    var placeholder = '□';
    // 在占位框后插入一个普通文本间隔位，便于方向键自然“走出”上下标区域
    var spacer = '\u2009';
    quill.insertText(range.index, placeholder, { script: type, slot: type }, 'user');
    quill.insertText(range.index + 1, spacer, { script: false, slot: false }, 'user');
    quill.setSelection(range.index, placeholder.length, 'user');
  }

  // 当占位框有真实输入后，自动移除 slot 样式（虚线框）
  function cleanupFilledSlots() {
    if (!quillInstance) return;
    var slotEls = quillInstance.root.querySelectorAll('.ql-slot-super, .ql-slot-sub');
    slotEls.forEach(function(el) {
      var text = (el.textContent || '').replace(/\u2009/g, '').trim();
      if (!text || text === '□') return;
      var blot = Quill.find(el);
      if (!blot) return;
      var index = quillInstance.getIndex(blot);
      var length = typeof blot.length === 'function' ? blot.length() : (el.textContent || '').length;
      if (length > 0) {
        quillInstance.formatText(index, length, 'slot', false, 'api');
      }
    });
  }

})();

