<?php
$activeAdminNav = isset($activeAdminNav) && is_string($activeAdminNav) ? $activeAdminNav : '';
$adminNavLinks = [
  ['key' => 'permissions', 'href' => 'admin.php', 'label' => 'Permissions'],
  ['key' => 'chats', 'href' => 'chats.php', 'label' => 'Chat Overview'],
  ['key' => 'dashboard', 'href' => 'index.php', 'label' => 'Return to the dashboard'],
];
?>
<nav class="adminNavBar" aria-label="Admin navigation">
  <?php foreach ($adminNavLinks as $link): ?>
    <a class="adminNavLink<?= $activeAdminNav === $link['key'] ? ' active' : '' ?><?= $link['key'] === 'dashboard' ? ' adminNavLinkDashboard' : '' ?>" href="<?= htmlspecialchars($link['href'], ENT_QUOTES) ?>"><?= htmlspecialchars($link['label'], ENT_QUOTES) ?></a>
  <?php endforeach; ?>
</nav>
