<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/csrf.php';

// 页面标题
$pageTitle = '向应老师提问';
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $pageTitle ?> · Sown</title>
  <?php require __DIR__ . '/partials/head.php'; ?>
  <link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(__DIR__.'/assets/app.css') ?>">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
  <!-- KaTeX 数学公式渲染 -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css" crossorigin="anonymous">
  <style>
    /* ========== 应老师问答页面 ========== */
    .ai-teacher-page {
      max-width: 800px;
      margin: 0 auto;
      padding: 20px 16px 40px;
      display: flex;
      flex-direction: column;
      height: calc(100vh - 60px); /* 减去header高度 */
      min-height: 500px;
    }

    .ai-teacher-header {
      text-align: center;
      padding: 16px 0 20px;
      border-bottom: 1px solid var(--light-gray);
      flex-shrink: 0;
    }

    .ai-teacher-header h1 {
      font-size: 22px;
      font-weight: 600;
      margin: 0 0 6px;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
    }

    .ai-teacher-avatar-lg {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      flex-shrink: 0;
    }
    .ai-teacher-avatar-lg img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      border-radius: 50%;
    }

    .ai-teacher-header p {
      color: var(--muted);
      font-size: 14px;
      margin: 0;
    }

    /* 消息列表区域 */
    .ai-messages {
      flex: 1;
      overflow-y: auto;
      padding: 20px 4px;
      display: flex;
      flex-direction: column;
      gap: 16px;
      scroll-behavior: smooth;
    }

    .ai-message {
      display: flex;
      gap: 10px;
      max-width: 85%;
      animation: aiMsgIn 0.3s ease;
    }

    @keyframes aiMsgIn {
      from { opacity: 0; transform: translateY(8px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    .ai-message-user {
      align-self: flex-end;
      flex-direction: row-reverse;
    }

    .ai-message-bot {
      align-self: flex-start;
    }

    .ai-message-avatar {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      flex-shrink: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 13px;
      font-weight: 600;
    }

    .ai-message-user .ai-message-avatar {
      background: var(--green-bg);
      color: var(--green-primary);
    }

    .ai-message-bot .ai-message-avatar {
      overflow: hidden;
      flex-shrink: 0;
    }
    .ai-message-bot .ai-message-avatar img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      border-radius: 50%;
    }

    .ai-message-bubble {
      padding: 10px 14px;
      border-radius: 14px;
      font-size: 15px;
      line-height: 1.65;
      word-break: break-word;
    }

    .ai-message-user .ai-message-bubble {
      background: var(--green-bg);
      color: var(--green-primary);
      border-bottom-right-radius: 4px;
    }

    .ai-message-bot .ai-message-bubble {
      background: #f5f5f5;
      color: var(--pure-black);
      border-bottom-left-radius: 4px;
    }

    .ai-message-bubble p { margin: 0 0 8px; }
    .ai-message-bubble p:last-child { margin-bottom: 0; }
    .ai-message-bubble pre {
      background: #1e1e1e;
      color: #d4d4d4;
      padding: 12px;
      border-radius: 8px;
      overflow-x: auto;
      font-size: 13px;
      line-height: 1.5;
      margin: 8px 0;
    }
    .ai-message-bubble code {
      background: rgba(0,0,0,0.06);
      padding: 2px 5px;
      border-radius: 4px;
      font-size: 13px;
      font-family: monospace;
    }
    .ai-message-bubble pre code { background: none; padding: 0; }
    .ai-message-bubble ul, .ai-message-bubble ol { margin: 8px 0; padding-left: 20px; }
    .ai-message-bubble li { margin: 3px 0; }
    .ai-message-bubble blockquote {
      border-left: 3px solid var(--green-primary);
      margin: 8px 0;
      padding-left: 12px;
      color: var(--stone);
    }

    /* 打字机光标 */
    .ai-cursor {
      display: inline-block;
      width: 2px;
      height: 1.1em;
      background: var(--green-primary);
      vertical-align: text-bottom;
      margin-left: 2px;
      animation: aiCursorBlink 1s step-end infinite;
    }

    @keyframes aiCursorBlink {
      0%, 100% { opacity: 1; }
      50% { opacity: 0; }
    }

    /* 加载状态 */
    .ai-thinking {
      display: flex;
      align-items: center;
      gap: 8px;
      color: var(--muted);
      font-size: 13px;
      padding: 4px 0;
    }

    .ai-thinking-dots {
      display: flex;
      gap: 4px;
    }

    .ai-thinking-dots span {
      width: 6px;
      height: 6px;
      background: var(--muted);
      border-radius: 50%;
      animation: aiDot 1.4s ease-in-out infinite both;
    }

    .ai-thinking-dots span:nth-child(1) { animation-delay: -0.32s; }
    .ai-thinking-dots span:nth-child(2) { animation-delay: -0.16s; }

    @keyframes aiDot {
      0%, 80%, 100% { transform: scale(0); }
      40% { transform: scale(1); }
    }

    /* 输入区域 */
    .ai-input-area {
      flex-shrink: 0;
      border-top: 1px solid var(--light-gray);
      padding-top: 12px;
    }

    .ai-input-wrap {
      display: flex;
      gap: 8px;
      align-items: flex-end;
      background: #f8f9fa;
      border-radius: 20px;
      padding: 8px 8px 8px 16px;
      border: 1px solid var(--light-gray);
      transition: border-color 0.2s;
    }

    .ai-input-wrap:focus-within {
      border-color: var(--green-primary);
      background: #fff;
    }

    .ai-input-wrap textarea {
      flex: 1;
      border: none;
      background: transparent;
      resize: none;
      outline: none;
      font-size: 15px;
      line-height: 1.5;
      max-height: 120px;
      min-height: 24px;
      padding: 4px 0;
      font-family: inherit;
    }

    .ai-send-btn {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      border: none;
      background: var(--green-primary);
      color: white;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      transition: opacity 0.2s, transform 0.15s;
    }

    .ai-send-btn:hover:not(:disabled) {
      opacity: 0.85;
      transform: scale(1.05);
    }

    .ai-send-btn:disabled {
      opacity: 0.4;
      cursor: not-allowed;
    }

    .ai-hint {
      text-align: center;
      font-size: 12px;
      color: var(--muted);
      margin-top: 8px;
    }

    /* 快捷问题 */
    .ai-suggestions {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-bottom: 12px;
      justify-content: center;
    }

    .ai-suggestion {
      padding: 6px 14px;
      background: #f0f4ff;
      color: #4a6cf7;
      border-radius: 16px;
      font-size: 13px;
      cursor: pointer;
      border: none;
      transition: background 0.2s;
    }

    .ai-suggestion:hover {
      background: #e0e8ff;
    }

    @media (max-width: 600px) {
      .ai-teacher-page { padding: 12px 12px 20px; height: calc(100vh - 52px); }
      .ai-message { max-width: 92%; }
    }
  </style>
</head>
<body>
  <?php require __DIR__ . '/partials/header.php'; ?>

  <div class="ai-teacher-page">
    <div class="ai-teacher-header">
      <h1>
        <span class="ai-teacher-avatar-lg"><img src="/data/teacher/%E5%86%99%E7%9C%9F.jpg" alt="应老师"></span>
        向应老师提问
      </h1>
      <p>数学问题、学习方法、解题思路……都可以问应老师</p>
    </div>

    <div class="ai-messages" id="aiMessages">
      <!-- 欢迎消息 -->
      <div class="ai-message ai-message-bot">
        <div class="ai-message-avatar"><img src="/data/teacher/%E5%86%99%E7%9C%9F.jpg" alt="应老师"></div>
        <div class="ai-message-bubble">
          同学你好，我是应老师 👋<br>
          有什么数学问题随时问我，别怕错，错着错着就对了 😊
        </div>
      </div>
    </div>

    <div class="ai-input-area">
      <div class="ai-suggestions" id="aiSuggestions">
        <button class="ai-suggestion" data-q="如何理解函数的概念？">如何理解函数的概念？</button>
        <button class="ai-suggestion" data-q="高一数学怎么打基础？">高一数学怎么打基础？</button>
        <button class="ai-suggestion" data-q="证明题没有思路怎么办？">证明题没有思路怎么办？</button>
      </div>
      <div class="ai-input-wrap">
        <textarea id="aiInput" placeholder="输入你的数学问题，Enter 发送，Shift+Enter 换行" rows="1"></textarea>
        <button class="ai-send-btn" id="aiSendBtn" title="发送">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <line x1="22" y1="2" x2="11" y2="13"></line>
            <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
          </svg>
        </button>
      </div>
      <div class="ai-hint">应老师由 AI 驱动，回答仅供参考，请以教材和老师课堂讲解为准</div>
    </div>
  </div>

  <?php require __DIR__ . '/partials/footer.php'; ?>

  <script src="/assets/app.js?v=<?= filemtime(__DIR__.'/assets/app.js') ?>"></script>
  <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js" crossorigin="anonymous"></script>
  <script src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js" crossorigin="anonymous"></script>
  <script src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/contrib/auto-render.min.js" crossorigin="anonymous"></script>

  <script>
  (function() {
    'use strict';

    var messagesEl = document.getElementById('aiMessages');
    var inputEl = document.getElementById('aiInput');
    var sendBtn = document.getElementById('aiSendBtn');
    var suggestionsEl = document.getElementById('aiSuggestions');

    var isTyping = false;
    var history = []; // 多轮对话历史
    var csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).getAttribute('content') || '';

    // 自动增高 textarea
    function autoResize(el) {
      el.style.height = 'auto';
      el.style.height = Math.min(el.scrollHeight, 120) + 'px';
    }

    inputEl.addEventListener('input', function() {
      autoResize(this);
    });

    // Enter 发送，Shift+Enter 换行
    inputEl.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
      }
    });

    sendBtn.addEventListener('click', sendMessage);

    // 快捷问题
    suggestionsEl.addEventListener('click', function(e) {
      var btn = e.target.closest('.ai-suggestion');
      if (!btn) return;
      inputEl.value = btn.getAttribute('data-q');
      autoResize(inputEl);
      sendMessage();
    });

    function sendMessage() {
      var text = inputEl.value.trim();
      if (!text || isTyping) return;

      // 添加用户消息
      appendUserMessage(text);
      inputEl.value = '';
      inputEl.style.height = 'auto';

      // 隐藏快捷问题（首次发送后）
      if (suggestionsEl.style.display !== 'none') {
        suggestionsEl.style.display = 'none';
      }

      // 添加"正在思考"
      var thinkingId = appendThinking();
      isTyping = true;
      sendBtn.disabled = true;

      // 调用后端接口
      fetch('/ask_teacher_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'question=' + encodeURIComponent(text) + '&history=' + encodeURIComponent(JSON.stringify(history)) + '&csrf=' + encodeURIComponent(csrfToken)
      })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        removeThinking(thinkingId);
        if (data.ok) {
          // 打字机效果显示回复
          typewriterReply(data.answer, function() {
            // 完成后渲染 Markdown + 数学公式
            renderLastMessage();
          });
          // 记录历史
          history.push({ role: 'user', content: text });
          history.push({ role: 'assistant', content: data.answer });
          // 限制历史长度（保留最近10轮）
          if (history.length > 20) history = history.slice(-20);
        } else {
          appendBotMessage('抱歉，应老师暂时无法回答。' + (data.msg || ''));
        }
      })
      .catch(function(err) {
        removeThinking(thinkingId);
        appendBotMessage('网络出了点问题，请稍后再试。');
        console.error(err);
      })
      .finally(function() {
        isTyping = false;
        sendBtn.disabled = false;
      });
    }

    function appendUserMessage(text) {
      var div = document.createElement('div');
      div.className = 'ai-message ai-message-user';
      div.innerHTML = '<div class="ai-message-avatar">我</div>' +
        '<div class="ai-message-bubble">' + escapeHtml(text) + '</div>';
      messagesEl.appendChild(div);
      scrollToBottom();
    }

    function appendBotMessage(html) {
      var div = document.createElement('div');
      div.className = 'ai-message ai-message-bot';
      div.innerHTML = '<div class="ai-message-avatar"><img src="/data/teacher/%E5%86%99%E7%9C%9F.jpg" alt="应老师"></div>' +
        '<div class="ai-message-bubble">' + html + '</div>';
      messagesEl.appendChild(div);
      scrollToBottom();
      return div;
    }

    function appendThinking() {
      var id = 'thinking-' + Date.now();
      var div = document.createElement('div');
      div.className = 'ai-message ai-message-bot';
      div.id = id;
      div.innerHTML = '<div class="ai-message-avatar"><img src="/data/teacher/%E5%86%99%E7%9C%9F.jpg" alt="应老师"></div>' +
        '<div class="ai-message-bubble">' +
          '<div class="ai-thinking">' +
            '<span>应老师正在思考</span>' +
            '<div class="ai-thinking-dots"><span></span><span></span><span></span></div>' +
          '</div>' +
        '</div>';
      messagesEl.appendChild(div);
      scrollToBottom();
      return id;
    }

    function removeThinking(id) {
      var el = document.getElementById(id);
      if (el) el.remove();
    }

    // 打字机效果
    function typewriterReply(text, onDone) {
      var div = document.createElement('div');
      div.className = 'ai-message ai-message-bot';
      var bubble = document.createElement('div');
      bubble.className = 'ai-message-bubble';
      var contentSpan = document.createElement('span');
      var cursor = document.createElement('span');
      cursor.className = 'ai-cursor';
      bubble.appendChild(contentSpan);
      bubble.appendChild(cursor);
      div.appendChild(document.createElement('div'));
      div.firstChild.className = 'ai-message-avatar';
      div.firstChild.innerHTML = '<img src="/data/teacher/%E5%86%99%E7%9C%9F.jpg" alt="应老师">';
      div.appendChild(bubble);
      messagesEl.appendChild(div);
      scrollToBottom();

      var chars = Array.from(text);
      var i = 0;
      var speed = 25; // 毫秒/字

      function type() {
        if (i < chars.length) {
          contentSpan.textContent += chars[i];
          i++;
          scrollToBottom();
          setTimeout(type, speed);
        } else {
          cursor.remove();
          if (onDone) onDone();
        }
      }
      type();
    }

    // 渲染最后一条消息的 Markdown + KaTeX
    function renderLastMessage() {
      var bots = messagesEl.querySelectorAll('.ai-message-bot');
      if (!bots.length) return;
      var last = bots[bots.length - 1];
      var bubble = last.querySelector('.ai-message-bubble');
      if (!bubble) return;

      var text = bubble.textContent;
      // 用 marked 转 Markdown
      bubble.innerHTML = marked.parse(text);
      // 用 KaTeX 渲染数学公式
      if (typeof renderMathInElement === 'function') {
        renderMathInElement(bubble, {
          delimiters: [
            { left: '$$', right: '$$', display: true },
            { left: '$', right: '$', display: false },
            { left: '\\[', right: '\\]', display: true },
            { left: '\\(', right: '\\)', display: false }
          ],
          throwOnError: false
        });
      }
      scrollToBottom();
    }

    function scrollToBottom() {
      messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function escapeHtml(text) {
      var div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }
  })();
  </script>
</body>
</html>
