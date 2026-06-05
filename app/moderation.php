<?php
declare(strict_types=1);

/**
 * 简单违规词检测（可按需扩展）。
 * - 命中任一关键词即视为违规
 * - 用于帖子标题/正文、评论内容
 */
function moderation_keywords(): array {
  return [
    // 辱骂/人身攻击
    '傻逼', '煞笔', '沙比', '脑残', '智障', '废物', '垃圾人', '去死', '滚开',
    '操你', '草你', '你妈', '妈的', '妈逼', '狗东西', '贱人', '畜生',
    // 暴力/极端
    '炸弹', '爆炸物', '恐袭', '恐怖袭击', '极端主义', '仇恨言论',
    // 高风险敏感
    '种族灭绝', '屠杀', '自制炸药'
  ];
}

function moderation_block_message(): string {
  return '内容违规，已屏蔽';
}

function moderation_is_violation_text(string $text): bool {
  $normalized = trim(mb_strtolower($text, 'UTF-8'));
  if ($normalized === '') {
    return false;
  }
  foreach (moderation_keywords() as $kw) {
    if (mb_strpos($normalized, mb_strtolower($kw, 'UTF-8'), 0, 'UTF-8') !== false) {
      return true;
    }
  }
  return false;
}

function moderation_is_block_message(string $text): bool {
  return trim($text) === moderation_block_message();
}

function moderation_filter_comment_text(string $text): array {
  $blocked = moderation_is_violation_text($text);
  return [
    'blocked' => $blocked,
    'text' => $blocked ? moderation_block_message() : $text,
  ];
}

function moderation_filter_post(string $title, string $contentHtml): array {
  $plain = trim((string)preg_replace(
    '/\s+/u',
    ' ',
    strip_tags(html_entity_decode($contentHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8'))
  ));
  $blocked = moderation_is_violation_text($title) || moderation_is_violation_text($plain);
  if (!$blocked) {
    return [
      'blocked' => false,
      'title' => $title,
      'content_html' => $contentHtml,
    ];
  }

  $msg = moderation_block_message();
  $blockedHtml = '<p class="blocked-content-text blocked-content-text--post">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>';
  return [
    'blocked' => true,
    'title' => $msg,
    'content_html' => $blockedHtml,
  ];
}

function moderation_render_comment_html(string $content): string {
  if (moderation_is_block_message($content)) {
    return '<span class="blocked-content-text blocked-content-text--comment">' . htmlspecialchars(moderation_block_message(), ENT_QUOTES, 'UTF-8') . '</span>';
  }
  return nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8'));
}

