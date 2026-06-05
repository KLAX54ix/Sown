<?php
/**
 * 统一的页面头部（包含favicon和meta标签）
 * 使用方式：在<head>标签内 require_once __DIR__ . '/partials/head.php';
 */
$logoPngTime = file_exists(__DIR__ . '/../assets/New Sown.svg') ? filemtime(__DIR__ . '/../assets/New Sown.svg') : time();
$logoSvgTime = file_exists(__DIR__ . '/../assets/New Sown.svg') ? filemtime(__DIR__ . '/../assets/New Sown.svg') : time();
$faviconTime = file_exists(__DIR__ . '/../assets/favicon.svg') ? filemtime(__DIR__ . '/../assets/favicon.svg') : time();
?>
<link rel="icon" type="image/svg+xml" sizes="any" href="/assets/favicon.svg?v=<?= $faviconTime ?>">
<link rel="shortcut icon" type="image/svg+xml" href="/assets/favicon.svg?v=<?= $faviconTime ?>">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/favicon.svg?v=<?= $faviconTime ?>">

