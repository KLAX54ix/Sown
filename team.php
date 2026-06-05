<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/helpers.php';

$teamMembers = [
  ['name' => '王旭', 'role' => '产品 · 架构', 'bio' => '专注数学社区体验与产品方向，相信好的工具能让思考更自由。'],
  ['name' => '王旭', 'role' => '前端 · 交互', 'bio' => '负责界面与编辑器体验，让公式与图文在屏幕上自然流动。'],
  ['name' => '王旭', 'role' => '后端 · 工程', 'bio' => '保障数据与安全，在低配置环境下也能稳定服务每一位用户。'],
  ['name' => '王旭', 'role' => '设计 · 品牌', 'bio' => '用视觉与动效传递「数问」的克制与温度。'],
  ['name' => '王旭', 'role' => '算法 · 数学', 'bio' => '关注排版、搜索与内容理解，让好问题更容易被看见。'],
  ['name' => '王旭', 'role' => '运营 · 社区', 'bio' => '连接创作者与读者，维护讨论氛围与活动节奏。'],
];

$teamPhotoSrc = '/assets/team/member.png?v=' . (int) @filemtime(__DIR__ . '/assets/team/member.png');

?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>开发者团队 · Sown</title>
  <?php require __DIR__ . '/partials/head.php'; ?>
  <link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(__DIR__.'/assets/app.css') ?>">
</head>
<body class="team-page">
  <?php require __DIR__ . '/partials/header.php'; ?>

  <div class="container team-page-inner">
    <header class="team-page-header">
      <h1 class="team-page-title">开发者团队</h1>
      <p class="team-page-lead">一群热爱数学与工程的人，一起维护 Sown。</p>
    </header>

    <section class="team-marquee-wrap" aria-label="团队成员横向滚动展示">
      <div class="team-marquee team-marquee--fade" aria-hidden="true">
        <div class="team-marquee-track">
          <?php foreach ([0, 1] as $dup): ?>
            <?php foreach ($teamMembers as $m): ?>
              <article class="team-card">
                <div class="team-card-photo">
                  <img src="<?= htmlspecialchars($teamPhotoSrc, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($m['name'], ENT_QUOTES, 'UTF-8') ?>" width="560" height="336" loading="lazy" decoding="async">
                </div>
                <div class="team-card-body">
                  <h2 class="team-card-name"><?= htmlspecialchars($m['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                  <p class="team-card-role"><?= htmlspecialchars($m['role'], ENT_QUOTES, 'UTF-8') ?></p>
                  <p class="team-card-bio"><?= htmlspecialchars($m['bio'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
              </article>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
  </div>

  <?php require __DIR__ . '/partials/footer.php'; ?>
  <script src="/assets/app.js?v=<?= filemtime(__DIR__.'/assets/app.js') ?>"></script>
</body>
</html>
