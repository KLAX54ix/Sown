<?php
declare(strict_types=1);
/**
 * 草稿预览入口：与 post.php 相同版式，仅作者本人可访问，互动数据为空。
 * 实际渲染由 post.php + preview=1 完成。
 */
if (!isset($_GET['id']) || (int)$_GET['id'] <= 0) {
  http_response_code(404);
  echo 'Not Found';
  exit;
}
$_GET['preview'] = '1';
require __DIR__ . '/post.php';
