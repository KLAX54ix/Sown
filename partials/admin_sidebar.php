<?php
/**
 * 管理后台侧边栏导航
 * 要求全局变量 $currentPage（当前页面 basename）
 */
if (!isset($currentPage)) {
  $currentPage = basename($_SERVER['PHP_SELF'], '.php');
}
$adminPages = [
  'admin' => ['label' => '仪表盘', 'url' => '/admin.php'],
  'admin_posts' => ['label' => '帖子审核', 'url' => '/admin_posts.php'],
  'admin_shop' => ['label' => '商城管理', 'url' => '/admin_shop.php'],
  'admin_orders' => ['label' => '订单管理', 'url' => '/admin_orders.php'],
  'admin_media' => ['label' => '素材库', 'url' => '/admin_media.php'],
];
?>
<aside class="admin-sidebar">
  <nav class="admin-sidebar-nav">
    <ul>
      <?php foreach ($adminPages as $key => $item): ?>
        <li>
          <a href="<?= htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') ?>"
             class="admin-sidebar-link <?= $currentPage === $key ? 'active' : '' ?>">
            <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </nav>
</aside>
