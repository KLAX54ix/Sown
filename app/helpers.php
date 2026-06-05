<?php
declare(strict_types=1);

/**
 * 通用工具函数库
 * 适用于低配置服务器（2GB内存/双核）
 */

// 防止重复包含
if (defined('HELPERS_LOADED')) {
  return;
}
define('HELPERS_LOADED', true);

/**
 * 获取并清理 GET/POST 参数
 * @param string $key 参数名
 * @param mixed $default 默认值
 * @param string $type 'get' | 'post' | 'request'
 * @return mixed
 */
function input(string $key, $default = null, string $type = 'request') {
  // 兼容 PHP 7.x，不使用 match 表达式
  switch ($type) {
    case 'get':
      $source = $_GET;
      break;
    case 'post':
      $source = $_POST;
      break;
    default:
      $source = $_REQUEST;
  }
  
  if (!isset($source[$key])) {
    return $default;
  }
  
  $value = $source[$key];
  
  // 清理字符串
  if (is_string($value)) {
    $value = trim($value);
    return $value === '' ? $default : $value;
  }
  
  return $value;
}

/**
 * 获取整数参数
 */
function input_int(string $key, int $default = 0, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX, string $type = 'request'): int {
  $value = input($key, $default, $type);
  if (!is_numeric($value)) {
    return $default;
  }
  $int = (int)$value;
  return max($min, min($max, $int));
}

/**
 * 获取字符串参数（自动转义）
 */
function input_string(string $key, string $default = '', string $type = 'request'): string {
  $value = input($key, $default, $type);
  return is_string($value) ? $value : $default;
}

/**
 * 获取错误消息
 * 统一所有页面的错误处理，减少重复代码
 */
function get_error_message(string $err, array $customMessages = []): string {
  $messages = array_merge([
    // 通用错误
    'csrf' => 'CSRF验证失败，请重试',
    'server' => '服务器错误，请稍后重试',
    'method' => '请求方法错误',
    'unauthorized' => '请先登录',
    'forbidden' => '无权访问',
    'not_found' => '内容不存在',
    
    // 表单错误
    'required' => '请填写所有必填项',
    'invalid' => '输入内容无效',
    'email' => '请输入有效的邮箱地址',
    'password' => '密码不能为空',
    'password_short' => '密码长度至少6位',
    'username' => '用户名格式不正确',
    
    // 文件上传错误
    'no_file' => '请选择文件',
    'invalid_type' => '文件格式不支持',
    'too_large' => '文件大小超过限制',
    'upload_failed' => '上传失败，请重试',
    'image_too_large' => '图片大小不能超过5MB',
    'image_invalid_type' => '只支持 JPG、PNG、GIF 格式的图片',
    'image_upload_failed' => '图片上传失败，请重试',
    
    // 业务逻辑错误
    'exists' => '已存在',
    'duplicate' => '重复提交',
    'deleted' => '已被删除',
  ], $customMessages);
  
  return $messages[$err] ?? '';
}

/**
 * 渲染错误提示 HTML
 */
function render_error(string $err, array $customMessages = []): string {
  $msg = get_error_message($err, $customMessages);
  if ($msg === '') {
    return '';
  }
  return '<div class="alert error">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</div>';
}

/**
 * 渲染成功提示 HTML
 */
function render_success(string $msg): string {
  if ($msg === '') {
    return '';
  }
  return '<div class="alert success">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</div>';
}

/**
 * 获取搜索页 URL（支持子路径部署）
 */
function search_url(string $q): string {
  $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
  $path = ($base === '' || $base === '.') ? '/search.php' : $base . '/search.php';
  return $path . '?q=' . urlencode($q);
}

/**
 * 社区/动态等列表卡片：解析首张封面图路径
 * @param string|null $contentHtml 可选：正文 HTML，当 image 列为空时尝试取首图
 */
function post_grid_first_image(?string $imageRaw, ?string $contentHtml = null): ?string {
  if ($imageRaw !== null && $imageRaw !== '') {
    $imageStr = trim($imageRaw);
    $imageData = json_decode($imageStr, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($imageData) && !empty($imageData)) {
      $first = $imageData[0];
      if (is_string($first) && $first !== '') {
        return '/' . ltrim($first, '/');
      }
    }
    if ($imageStr !== '' && $imageStr !== 'null' && strpos($imageStr, '/') !== false) {
      return '/' . ltrim($imageStr, '/');
    }
  }
  if ($contentHtml !== null && $contentHtml !== '') {
    if (preg_match('#<img[^>]+src=["\'](/uploads/posts/[^"\']+)["\']#i', $contentHtml, $m)) {
      return '/' . ltrim($m[1], '/');
    }
  }
  return null;
}

/**
 * GROUP_CONCAT 标签名与同序 slug 配对（用于 `?tag=` 筛选）
 * @return array<int, array{name: string, slug: string}>
 */
function post_grid_tag_pairs(?string $namesCsv, ?string $slugsCsv, int $max = 3): array {
  $names = [];
  if ($namesCsv !== null && $namesCsv !== '') {
    foreach (explode(',', $namesCsv) as $part) {
      // GROUP_CONCAT 用逗号分隔；若标签名含逗号可能错位。业务上限定标签名不含逗号
      $t = trim((string)$part);
      if ($t !== '') {
        $names[] = $t;
      }
    }
  }
  $slugs = [];
  if ($slugsCsv !== null && $slugsCsv !== '') {
    foreach (explode(',', $slugsCsv) as $part) {
      $slugs[] = trim((string)$part);
    }
  }
  $out = [];
  $n = min($max, count($names));
  for ($i = 0; $i < $n; $i++) {
    $out[] = [
      'name' => $names[$i],
      'slug' => $slugs[$i] ?? '',
    ];
  }
  return $out;
}

/**
 * 列表卡片摘要：从帖子 HTML 正文得到纯文本并截断（用于大卡展示）
 */
function post_grid_plain_excerpt(?string $html, int $maxChars = 160): string {
  if ($html === null || $html === '') {
    return '';
  }
  $text = strip_tags($html);
  $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $text = preg_replace('/\s+/u', ' ', $text);
  $text = trim($text);
  if ($text === '') {
    return '';
  }
  return truncate($text, $maxChars, '…');
}

/**
 * 安全的重定向（防止 open redirect）
 */
function safe_redirect(string $url, int $code = 302): void {
  // 只允许站内跳转
  if (!str_starts_with($url, '/') && !str_starts_with($url, 'http')) {
    $url = '/' . $url;
  }
  
  header("Location: {$url}", true, $code);
  exit;
}

/**
 * 分页计算（内存友好）
 */
function pagination(int $total, int $page, int $perPage, int $window = 3): array {
  $totalPages = max(1, (int)ceil($total / $perPage));
  $page = max(1, min($page, $totalPages));
  
  $start = max(1, $page - $window);
  $end = min($totalPages, $page + $window);
  
  return [
    'page' => $page,
    'perPage' => $perPage,
    'total' => $total,
    'totalPages' => $totalPages,
    'start' => $start,
    'end' => $end,
    'offset' => ($page - 1) * $perPage,
    'hasPrev' => $page > 1,
    'hasNext' => $page < $totalPages,
    'prevPage' => max(1, $page - 1),
    'nextPage' => min($totalPages, $page + 1),
  ];
}

/**
 * 格式化时间（低内存）
 */
function time_ago(string $datetime): string {
  $time = strtotime($datetime);
  $now = time();
  $diff = $now - $time;
  
  if ($diff < 60) {
    return '刚刚';
  }
  if ($diff < 3600) {
    return (int)($diff / 60) . '分钟前';
  }
  if ($diff < 86400) {
    return (int)($diff / 3600) . '小时前';
  }
  if ($diff < 604800) {
    return (int)($diff / 86400) . '天前';
  }
  
  return date('Y-m-d', $time);
}

/**
 * 截断文本（无 mbstring 依赖）
 */
function truncate(string $text, int $length, string $suffix = '...'): string {
  // 优先使用 mb_substr（如果有），否则用 substr
  if (function_exists('mb_substr') && function_exists('mb_strlen')) {
    if (mb_strlen($text, 'UTF-8') <= $length) {
      return $text;
    }
    return mb_substr($text, 0, $length, 'UTF-8') . $suffix;
  }
  
  // 纯字节截断（可能截断 UTF-8 字符，仅作为 fallback）
  if (strlen($text) <= $length) {
    return $text;
  }
  return substr($text, 0, $length) . $suffix;
}

/**
 * 获取用户名首字母（支持中文）
 */
function user_initials(string $username): string {
  if (function_exists('mb_substr')) {
    return mb_substr($username, 0, 1, 'UTF-8');
  }
  return substr($username, 0, 1);
}

/**
 * 生成随机字符串（用于 token）
 */
function random_string(int $length = 32): string {
  return bin2hex(random_bytes($length / 2));
}

/**
 * 清理输出缓存（低内存优化）
 */
function clean_output(): void {
  while (ob_get_level()) {
    ob_end_clean();
  }
}

/**
 * 从正文 HTML 中提取本站上传的图片路径（用于列表封面等，最多 9 张）
 * @return list<string>
 */
function post_extract_uploaded_image_paths(string $html): array {
  if ($html === '') {
    return [];
  }
  if (!preg_match_all('#<img[^>]+src=["\'](/uploads/posts/[^"\']+)["\']#i', $html, $m)) {
    return [];
  }
  $paths = array_values(array_unique($m[1]));
  return array_slice($paths, 0, 9);
}

/**
 * 净化 Quill 输出的 CSS style（仅允许安全属性）
 */
function sanitize_post_css_style(string $style): string {
  $style = trim($style);
  if ($style === '') {
    return '';
  }
  $allowedProps = [
    'color' => true,
    'background-color' => true,
    'font-size' => true,
    'line-height' => true,
    'text-align' => true,
    'text-decoration' => true,
    'font-weight' => true,
    'font-family' => true,
  ];
  $out = [];
  foreach (explode(';', $style) as $rule) {
    $rule = trim($rule);
    if ($rule === '') {
      continue;
    }
    $parts = explode(':', $rule, 2);
    if (count($parts) !== 2) {
      continue;
    }
    $prop = strtolower(trim($parts[0]));
    $val = trim($parts[1]);
    if (!isset($allowedProps[$prop])) {
      continue;
    }
    if (preg_match('/\b(url|expression|javascript|import|@import)\b/i', $val)) {
      continue;
    }
    $out[] = $prop . ':' . $val;
  }
  return implode(';', $out);
}

/**
 * 净化 class：仅保留 ql-*（Quill）等安全前缀
 */
function sanitize_post_class_attr(string $class): string {
  $class = trim($class);
  if ($class === '') {
    return '';
  }
  $parts = preg_split('/\s+/', $class);
  $keep = [];
  foreach ($parts as $p) {
    if ($p === '') {
      continue;
    }
    if (preg_match('/^ql-[a-zA-Z0-9_-]+$/', $p) || preg_match('/^latex-formula$/', $p)) {
      $keep[] = $p;
    }
  }
  return implode(' ', $keep);
}

/**
 * DOM 递归净化（帖子正文）
 */
function sanitize_post_dom_element(DOMNode $node, DOMDocument $dom): void {
  if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
    return;
  }
  if ($node->nodeType !== XML_ELEMENT_NODE) {
    return;
  }
  /** @var DOMElement $el */
  $el = $node;
  $name = strtolower($el->tagName);

  $danger = ['script', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'style', 'link', 'meta', 'base'];
  if (in_array($name, $danger, true)) {
    if ($el->parentNode) {
      $el->parentNode->removeChild($el);
    }
    return;
  }

  $allowed = [
    'p' => ['class', 'style'],
    'div' => ['class', 'style'],
    'span' => ['class', 'style'],
    'br' => [],
    'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [], 'strike' => [],
    'sub' => [], 'sup' => [],
    'h1' => ['class', 'style'], 'h2' => ['class', 'style'], 'h3' => ['class', 'style'],
    'h4' => ['class', 'style'], 'h5' => ['class', 'style'], 'h6' => ['class', 'style'],
    'ul' => ['class', 'style'], 'ol' => ['class', 'style', 'data-list'], 'li' => ['class', 'style', 'data-list'],
    'blockquote' => ['class', 'style'],
    'pre' => ['class', 'style'], 'code' => ['class', 'style'],
    'a' => ['href', 'class', 'target', 'rel'],
    'img' => ['src', 'alt', 'class', 'width', 'height'],
  ];

  if (!isset($allowed[$name])) {
    $fragment = $dom->createDocumentFragment();
    while ($el->firstChild) {
      $fragment->appendChild($el->firstChild);
    }
    if ($el->parentNode) {
      $el->parentNode->replaceChild($fragment, $el);
    }
    $toSanitize = [];
    foreach ($fragment->childNodes as $ch) {
      $toSanitize[] = $ch;
    }
    foreach ($toSanitize as $ch) {
      sanitize_post_dom_element($ch, $dom);
    }
    return;
  }

  $attrs = [];
  if ($el->attributes) {
    foreach ($el->attributes as $a) {
      $attrs[] = $a;
    }
  }
  foreach ($attrs as $attr) {
    $an = strtolower($attr->name);
    if (strpos($an, 'on') === 0) {
      $el->removeAttribute($attr->name);
      continue;
    }
    if (!in_array($an, $allowed[$name], true)) {
      $el->removeAttribute($attr->name);
    }
  }

  if ($el->hasAttribute('class')) {
    $c = sanitize_post_class_attr($el->getAttribute('class'));
    if ($c === '') {
      $el->removeAttribute('class');
    } else {
      $el->setAttribute('class', $c);
    }
  }

  if ($el->hasAttribute('style')) {
    $st = sanitize_post_css_style($el->getAttribute('style'));
    if ($st === '') {
      $el->removeAttribute('style');
    } else {
      $el->setAttribute('style', $st);
    }
  }

  if ($name === 'img') {
    $src = $el->getAttribute('src');
    if ($src === '' || !preg_match('#^/uploads/posts/[^?\s]+$#', $src)) {
      if ($el->parentNode) {
        $el->parentNode->removeChild($el);
      }
      return;
    }
  }

  if ($name === 'a') {
    $href = $el->getAttribute('href');
    if ($href !== '' && !preg_match('#^(https?:)?//#i', $href)) {
      $el->removeAttribute('href');
    }
    if ($el->getAttribute('target') === '_blank') {
      $el->setAttribute('rel', 'noopener noreferrer');
    }
  }

  $children = [];
  foreach ($el->childNodes as $ch) {
    $children[] = $ch;
  }
  foreach ($children as $ch) {
    sanitize_post_dom_element($ch, $dom);
  }
}

/**
 * 净化帖子正文 HTML（Quill 富文本）
 */
function sanitize_post_content_html(string $html): string {
  if (trim($html) === '') {
    return '';
  }
  $html = str_replace("\0", '', $html);
  libxml_use_internal_errors(true);
  $dom = new DOMDocument('1.0', 'UTF-8');
  $wrapped = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"/></head><body><div id="post-sanitize-root">' . $html . '</div></body></html>';
  if (function_exists('mb_convert_encoding')) {
    @$dom->loadHTML(mb_convert_encoding($wrapped, 'HTML-ENTITIES', 'UTF-8'));
  } else {
    @$dom->loadHTML($wrapped);
  }
  $root = $dom->getElementById('post-sanitize-root');
  if (!$root) {
    libxml_clear_errors();
    return '';
  }
  $children = [];
  foreach ($root->childNodes as $ch) {
    $children[] = $ch;
  }
  foreach ($children as $ch) {
    sanitize_post_dom_element($ch, $dom);
  }
  $out = '';
  foreach ($root->childNodes as $ch) {
    $out .= $dom->saveHTML($ch);
  }
  libxml_clear_errors();
  return $out;
}

/**
 * 将封面图路径置于 image JSON 数组首位（用于列表缩略图）
 * @param list<string> $paths
 * @return list<string>
 */
function post_reorder_images_for_cover(array $paths, ?string $coverPath): array {
  if ($coverPath === null || $coverPath === '') {
    return $paths;
  }
  $coverPath = '/' . ltrim($coverPath, '/');
  $idx = array_search($coverPath, $paths, true);
  if ($idx === false) {
    return $paths;
  }
  $item = $paths[$idx];
  unset($paths[$idx]);
  return array_values(array_merge([$item], $paths));
}

/**
 * 获取用户当前激活的称号
 * @param int $userId 用户ID
 * @return array|null 返回称号信息数组，包含 title_key, title_name, icon，如果没有激活的称号则返回 null
 */
function get_user_title(int $userId): ?array {
  if ($userId <= 0) {
    return null;
  }
  try {
    $pdo = db();
    // 检查表是否存在，如果不存在则返回 null（避免因表未创建而报错）
    $stmt = $pdo->prepare("
      SELECT title_key, title_name, icon
      FROM user_title
      WHERE user_id = ? AND is_active = 1
      LIMIT 1
    ");
    $stmt->execute([$userId]);
    $title = $stmt->fetch(PDO::FETCH_ASSOC);
    return $title ?: null;
  } catch (Throwable $e) {
    // 表可能不存在，忽略错误
    return null;
  }
}

/**
 * 获取用户拥有的所有称号
 * @param int $userId 用户ID
 * @return list<array{title_key:string,title_name:string,icon:string,is_active:int}> 称号列表
 */
function get_user_titles(int $userId): array {
  if ($userId <= 0) {
    return [];
  }
  try {
    $pdo = db();
    $stmt = $pdo->prepare("
      SELECT title_key, title_name, icon, is_active
      FROM user_title
      WHERE user_id = ?
      ORDER BY obtained_at ASC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    return [];
  }
}

/**
 * 设置用户当前激活的称号
 * @param int $userId 用户ID
 * @param string $titleKey 称号标识，空字符串表示取消称号
 * @return bool 是否成功
 */
function set_user_active_title(int $userId, string $titleKey): bool {
  if ($userId <= 0) {
    return false;
  }
  try {
    $pdo = db();
    $pdo->beginTransaction();
    // 先取消所有激活
    $stmt = $pdo->prepare('UPDATE user_title SET is_active = 0 WHERE user_id = ?');
    $stmt->execute([$userId]);
    if ($titleKey !== '') {
      // 激活指定称号
      $stmt = $pdo->prepare('UPDATE user_title SET is_active = 1 WHERE user_id = ? AND title_key = ?');
      $stmt->execute([$userId, $titleKey]);
      if ($stmt->rowCount() === 0) {
        $pdo->rollBack();
        return false;
      }
    }
    $pdo->commit();
    return true;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    error_log('set_user_active_title: ' . $e->getMessage());
    return false;
  }
}

/**
 * 批量获取用户称号（用于评论列表等场景）
 * @param int[] $userIds 用户ID数组
 * @return array<int, array> 用户ID => 称号信息
 */
function get_users_titles(array $userIds): array {
  if (empty($userIds)) {
    return [];
  }
  $ids = array_map('intval', $userIds);
  $ids = array_values(array_unique($ids));
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  try {
    $pdo = db();
    $stmt = $pdo->prepare("
      SELECT user_id, title_key, title_name, icon
      FROM user_title
      WHERE user_id IN ($placeholders) AND is_active = 1
    ");
    $stmt->execute($ids);
    $titles = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $titles[(int)$row['user_id']] = $row;
    }
    return $titles;
  } catch (Throwable $e) {
    return [];
  }
}
