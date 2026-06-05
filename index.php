<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/helpers.php';

// 获取真实数据
$pdo = db();

// 用户数
$userCount = (int)$pdo->query("SELECT COUNT(*) AS c FROM user")->fetch()['c'];

// 帖子数
$postCount = (int)$pdo->query("SELECT COUNT(*) AS c FROM post WHERE status = 1 AND (review_status IS NULL OR review_status != 2)")->fetch()['c'];

// 标签数
$tagCount = (int)$pdo->query("SELECT COUNT(*) AS c FROM post_tag")->fetch()['c'];

// 总评论数
$commentCount = (int)$pdo->query("SELECT SUM(comment_count) AS c FROM post WHERE status = 1 AND (review_status IS NULL OR review_status != 2)")->fetch()['c'] ?? 0;

// 格式化数字
function formatNumber(int $num): string {
  if ($num >= 1000000) {
    return number_format($num / 1000000, 1) . 'M';
  }
  if ($num >= 10000) {
    return number_format($num / 10000, 1) . '万';
  }
  if ($num >= 1000) {
    return number_format($num / 1000, 1) . 'k';
  }
  return (string)$num;
}

// 开发者团队（首页展示）
$teamMembers = [
  ['name' => '王旭', 'role' => '产品 · 架构', 'bio' => '专注数学社区体验与产品方向，相信好的工具能让思考更自由。', 'photo' => '/assets/team/developer1.png'],
  ['name' => '王旭', 'role' => '前端 · 交互', 'bio' => '负责界面与编辑器体验，让公式与图文在屏幕上自然流动。', 'photo' => '/assets/team/developer2.png'],
  ['name' => '王旭', 'role' => '后端 · 工程', 'bio' => '保障数据与安全，在低配置环境下也能稳定服务每一位用户。', 'photo' => '/assets/team/developer3.png'],
  ['name' => '王旭', 'role' => '设计 · 品牌', 'bio' => '用视觉与动效传递「数问」的克制与温度。', 'photo' => '/assets/team/developer4.png'],
  ['name' => '王旭', 'role' => '算法 · 数学', 'bio' => '关注排版、搜索与内容理解，让好问题更容易被看见。', 'photo' => '/assets/team/developer5.png'],
  ['name' => '王旭', 'role' => '运营 · 社区', 'bio' => '连接创作者与读者，维护讨论氛围与活动节奏。', 'photo' => '/assets/team/developer6.png'],
];

$teamPhotoSrc = '/assets/team/member.png?v=' . (int) @filemtime(__DIR__ . '/assets/team/member.png');

$heroSlogan = '种下热爱，等数学开花';
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php require __DIR__ . '/partials/head.php'; ?>
  <title>Sown · 数问</title>
  <link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(__DIR__.'/assets/app.css') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Noto+Serif+SC:wght@400;500;600;700&display=swap" rel="stylesheet">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
  <!-- GSAP -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js"></script>
</head>
<body class="home-page">
  <!-- 导航栏（首页顶部透明，滚动后 .mainNav--scrolled 显形） -->
  <?php require __DIR__ . '/partials/header.php'; ?>

  <!-- 主视觉区：文案 + 操作 -->
  <section class="heroSection heroSection--textOnly" aria-label="站点主视觉">
  <div class="heroContainer">
    <div class="heroContent heroRevealPending">
      <h1
        class="heroTitle heroTitle--productSlogan"
        data-text="<?= htmlspecialchars($heroSlogan, ENT_QUOTES, 'UTF-8') ?>"
        aria-label="<?= htmlspecialchars($heroSlogan, ENT_QUOTES, 'UTF-8') ?>"
      ></h1>

      <p class="heroSubtitle heroSubtitle--productLead">在推演与追问之间，遇见更清澈的自己</p>

      <div class="heroActions">
        <?php if (is_logged_in()): ?>
          <a href="/post_note.php" class="ctaBtn ctaBtn--heroSecondary">
            <span>发布内容</span>
          </a>
        <?php else: ?>
          <?php
          $next = isset($_GET['next']) ? $_GET['next'] : $_SERVER['REQUEST_URI'];
          $registerUrl = '/register.php?next=' . urlencode($next);
          ?>
          <a href="<?= htmlspecialchars($registerUrl, ENT_QUOTES, 'UTF-8') ?>" class="ctaBtn ctaBtn--heroSecondary" data-register-modal="1">
            <span>注册并参与</span>
          </a>
        <?php endif; ?>
        <a href="/forum.php" class="heroTextLink heroTextLink--community" aria-label="进入社区">→</a>
      </div>
    </div>
  </div>
</section>

  <!-- 特色展示 -->
  <section class="featuresSection" id="features">
    <div class="featuresContainer">
      <header class="sectionHeader">
        <p class="sectionHeaderEyebrow">Why Sown</p>
        <h2 class="sectionHeaderTitle">为认真思考的人<br>提供一片净土</h2>
      </header>
      <div class="featuresGrid">
        <div class="featureCard">
          <div class="featureIcon">
            <svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M10 20 h28 a3 3 0 0 1 3 3 v14 a3 3 0 0 1 -3 3 h-18 l-6 7 v-7 h-4 a3 3 0 0 1 -3 -3 v-14 a3 3 0 0 1 3 -3z"/>
              <line x1="18" y1="28" x2="34" y2="28"/>
              <line x1="18" y1="33" x2="30" y2="33"/>
              <path d="M30 14 h16 a3 3 0 0 1 3 3 v10" opacity="0.3"/>
            </svg>
          </div>
          <h3 class="featureTitle">专业内容</h3>
          <p class="featureDesc">从数学分析到代数几何，深入探讨各个数学分支的核心概念</p>
        </div>
        <div class="featureCard">
          <div class="featureIcon">
            <svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="32" cy="16" r="5"/>
              <circle cx="14" cy="40" r="5"/>
              <circle cx="50" cy="40" r="5"/>
              <circle cx="32" cy="52" r="5"/>
              <line x1="32" y1="21" x2="32" y2="47"/>
              <line x1="18" y1="36" x2="28" y2="20"/>
              <line x1="46" y1="36" x2="36" y2="20"/>
              <line x1="19" y1="43" x2="45" y2="43"/>
            </svg>
          </div>
          <h3 class="featureTitle">活跃社区</h3>
          <p class="featureDesc">汇聚全球数学爱好者，分享见解，共同成长</p>
        </div>
        <div class="featureCard">
          <div class="featureIcon">
            <svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
              <rect x="10" y="36" width="44" height="10" rx="1"/>
              <rect x="10" y="22" width="44" height="10" rx="1"/>
              <rect x="10" y="8" width="44" height="10" rx="1"/>
              <line x1="18" y1="13" x2="38" y2="13"/>
              <line x1="18" y1="27" x2="34" y2="27"/>
              <line x1="18" y1="41" x2="30" y2="41"/>
            </svg>
          </div>
          <h3 class="featureTitle">知识库</h3>
          <p class="featureDesc">积累优质内容，构建完整的数学知识体系</p>
        </div>
        <div class="featureCard">
          <div class="featureIcon">
            <svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M22 18 C34 10 50 16 46 28 C42 40 24 34 22 46 C20 58 44 52 46 40"/>
              <path d="M22 18 C26 26 40 30 46 28" opacity="0.35"/>
              <path d="M22 46 C26 38 40 34 46 40" opacity="0.35"/>
            </svg>
          </div>
          <h3 class="featureTitle">创新思维</h3>
          <p class="featureDesc">鼓励创造性思考，推动数学研究的边界</p>
        </div>
      </div>
    </div>
  </section>

  <!-- 开发者团队（首页模块） -->
  <section class="team-page" aria-label="开发者团队">
    <div class="container team-page-inner">
      <header class="team-page-header">
        <h2 class="team-page-title">开发者团队</h2>
        <p class="team-page-lead">一群热爱数学与工程的人，一起维护 Sown。</p>
      </header>

      <section class="team-marquee-wrap" aria-label="团队成员横向滚动展示">
        <div class="team-marquee team-marquee--fade" aria-hidden="true">
          <div class="team-marquee-track">
            <?php foreach ([0, 1] as $dup): ?>
              <?php foreach ($teamMembers as $m): ?>
                <article class="team-card">
                  <div class="team-card-photo">
                    <img src="<?= htmlspecialchars($m['photo'] ?? $teamPhotoSrc, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($m['name'], ENT_QUOTES, 'UTF-8') ?>" width="560" height="560" loading="lazy" decoding="async">
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
  </section>

  <!-- 数据展示 -->
  <section class="statsSection statsSection--highlight">
    <div class="statsContainer">
      <header class="sectionHeader">
        <p class="sectionHeaderEyebrow">Numbers</p>
        <h2 class="sectionHeaderTitle">社区在生长</h2>
      </header>

      <div class="statsGrid">
        <div class="statCard">
          <div class="statIcon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>
          </div>
          <div class="statNumber" data-target="<?= $userCount ?>"><?= formatNumber($userCount) ?></div>
          <div class="statLabel">活跃用户</div>
        </div>
        <div class="statCard">
          <div class="statIcon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
          </div>
          <div class="statNumber" data-target="<?= $postCount ?>"><?= formatNumber($postCount) ?></div>
          <div class="statLabel">内容主题</div>
        </div>
        <div class="statCard">
          <div class="statIcon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
          </div>
          <div class="statNumber" data-target="<?= $tagCount ?>"><?= formatNumber($tagCount) ?></div>
          <div class="statLabel">知识标签</div>
        </div>
        <div class="statCard">
          <div class="statIcon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
          </div>
          <div class="statNumber" data-target="<?= $commentCount ?>"><?= formatNumber($commentCount) ?></div>
          <div class="statLabel">深度回复</div>
        </div>
      </div>
    </div>
  </section>

  <!-- 页脚 -->
  <footer class="mainFooter">
    <div class="footerContainer">
      <div class="footerContent">
        <p>© 2024 Sown / 数问. 专注于数学的在线社区</p>
        <div class="footerLinks">
          <a href="javascript:void(0)" onclick="openInfoModal('about')">关于我们</a>
          <a href="javascript:void(0)" onclick="openInfoModal('privacy')">隐私政策</a>
          <a href="javascript:void(0)" onclick="openInfoModal('contact')">联系我们</a>
        </div>
      </div>
    </div>
  </footer>

  <!-- 关于我们 / 隐私政策 / 联系我们 信息模态框 -->
  <div id="infoModal" class="info-modal-backdrop" style="display:none;" onclick="closeInfoModal(event)">
    <div class="info-modal" role="dialog" aria-modal="true">
      <button type="button" class="info-modal-close" aria-label="关闭" onclick="closeInfoModal()">×</button>
      <div class="info-modal-body">
        <!-- 关于我们 -->
        <div id="infoContent-about" class="info-content" style="display:none;">
          <h2 class="info-title">关于我们</h2>
          <div class="info-text">
            <p><strong>Sown（数问）</strong> 是一个以数学为核心的在线社区平台。我们致力于为数学爱好者、学生和专业人士提供一个纯粹、专注的交流空间。</p>
            <p>在这里，你可以：</p>
            <ul>
              <li>发布数学相关的问题和内容</li>
              <li>浏览和收藏优质的数学内容</li>
              <li>与其他数学爱好者深入交流</li>
              <li>获取最新的数学学习资源</li>
            </ul>
            <p>Sown 的名称寓意着"播种"——每一次提问和回答，都是在知识的土壤中播下一颗种子。我们相信，在数学的世界里，理解会自然生长。</p>
            <p class="info-motto">用数学播种，让理解自然生长。</p>
          </div>
        </div>
        <!-- 隐私政策 -->
        <div id="infoContent-privacy" class="info-content" style="display:none;">
          <h2 class="info-title">隐私政策</h2>
          <div class="info-text">
            <p>我们重视你的隐私。本隐私政策说明我们如何收集、使用和保护你的个人信息。</p>
            <h3>1. 信息收集</h3>
            <p>我们收集你在注册时提供的个人信息，包括用户名、邮箱地址和手机号。你发布的帖子、评论和收藏内容也会被存储。</p>
            <h3>2. 信息使用</h3>
            <p>收集的信息用于提供和改善社区服务，包括内容推荐、通知推送和账号安全保护。我们不会将你的个人信息出售给第三方。</p>
            <h3>3. 数据安全</h3>
            <p>我们采用合理的加密和安全措施保护你的数据。但请注意，互联网上的数据传输无法保证百分之百的安全。</p>
            <h3>4. Cookie 使用</h3>
            <p>我们使用必要的 Cookie 来维持登录状态和基本的网站功能。你可以在浏览器设置中管理 Cookie 偏好。</p>
            <h3>5. 用户权利</h3>
            <p>你可以随时查看、修改或删除你的个人信息。如有疑问，可通过"联系我们"页面与我们沟通。</p>
            <p class="info-updated">最后更新：2026 年 5 月</p>
          </div>
        </div>
        <!-- 联系我们 -->
        <div id="infoContent-contact" class="info-content" style="display:none;">
          <h2 class="info-title">联系我们</h2>
          <div class="info-text">
            <p>如果你有任何问题、建议或反馈，欢迎通过以下方式联系我们：</p>
            <div class="contact-item">
              <span class="contact-label">邮箱</span>
              <span class="contact-value">douding7004@gmail.com</span>
            </div>
            <div class="contact-item">
              <span class="contact-label">反馈</span>
              <span class="contact-value">在社区中发布帖子并标记 #反馈 标签</span>
            </div>
            <p>我们通常会在 48 小时内回复你的来信。感谢你对 Sown 的支持！</p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php require __DIR__ . '/partials/footer.php'; ?>

  <script src="/assets/app.js?v=<?= filemtime(__DIR__.'/assets/app.js') ?>"></script>
  <script>
    try { gsap.registerPlugin(ScrollTrigger); } catch (_) {}

    document.addEventListener('DOMContentLoaded', () => {
      (function homeNavScroll() {
        const nav = document.querySelector('.mainNav');
        if (!nav || !document.body.classList.contains('home-page')) return;
        const threshold = 24;
        function sync() {
          if (window.scrollY > threshold) nav.classList.add('mainNav--scrolled');
          else nav.classList.remove('mainNav--scrolled');
        }
        window.addEventListener('scroll', sync, { passive: true });
        sync();
      })();

      /* 入场动画结束后清掉 GSAP 留在行内的 transform，否则它会压住 .mainNav--hidden 的 translateY */
      try {
        gsap.from('.mainNav', {
          y: -100,
          opacity: 0,
          duration: 0.8,
          ease: 'power3.out',
          onComplete: function() {
            var el = document.querySelector('.mainNav');
            if (el) try { gsap.set(el, { clearProps: 'transform' }); } catch (_) {}
          }
        });
      } catch (_) {}

      // 打字机效果 + 后续动画（不依赖 GSAP）
      (function typewriter() {
        const h1 = document.querySelector('.heroTitle--productSlogan');
        if (!h1) return;
        const text = h1.getAttribute('data-text') || '';
        let i = 0;
        const speed = 260;

        function type() {
          if (i < text.length) {
            h1.textContent = text.substring(0, i + 1);
            i++;
            setTimeout(type, speed);
          } else {
            // 打字完成后，副标题和按钮入场
            const heroContent = document.querySelector('.heroContent');
            if (heroContent) heroContent.classList.remove('heroRevealPending');

            try {
              gsap.fromTo('.heroSection .heroSubtitle',
                { y: 24, opacity: 0 },
                { y: 0, opacity: 1, duration: 0.75, ease: 'power3.out' }
              );
              gsap.fromTo('.heroSection .heroActions',
                { y: 24, opacity: 0 },
                { y: 0, opacity: 1, duration: 0.75, ease: 'power3.out', delay: 0.08 }
              );
            } catch (_) {}
          }
        }

        // 稍等片刻再开始打字（让页面先稳住）
        setTimeout(type, 300);
      })();

      try {
        gsap.fromTo('.featureCard',
          { opacity: 0, y: 50 },
          {
            scrollTrigger: { trigger: '.featuresSection', start: 'top 80%', once: true },
            opacity: 1,
            y: 0,
            duration: 0.8,
            stagger: 0.1,
            ease: 'power3.out'
          }
        );
      } catch (_) {}

      try {
        const statNumbers = document.querySelectorAll('.statNumber');
        statNumbers.forEach(num => {
          gsap.from(num, {
            scrollTrigger: { trigger: '.statsSection', start: 'top 80%' },
            innerText: 0,
            duration: 2,
            ease: 'power2.out',
            snap: { innerText: 1 }
          });
        });
      } catch (_) {}

    });
  </script>
</body>
</html>