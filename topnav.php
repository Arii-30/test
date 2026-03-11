<?php
/**
 * topnav.php  — Reusable top navigation bar
 *
 * Required vars (set BEFORE including):
 *   $page_title   string   e.g. 'Dashboard'
 *   $breadcrumbs  array    e.g. [['label'=>'HR & Finance'], ['label'=>'Employees', 'url'=>'employees.php']]
 *                          Last item is always the "current" crumb (no link).
 *
 * Optional:
 *   $uid          int      logged-in user id  (for notification count)
 */

if (!isset($uid))  $uid = (int)($_SESSION['user_id'] ?? 0);
if (!isset($page_title))  $page_title  = 'SalesOS';
if (!isset($breadcrumbs)) $breadcrumbs = [];

// Unread count (may already be set by sidebar.php)
if (!isset($_sb_notifs)) {
    $r = $conn->query("SELECT COUNT(*) AS v FROM notifications WHERE recipient_id=$uid AND is_read=FALSE");
    $_sb_notifs = $r ? (int)$r->fetch_assoc()['v'] : 0;
}
?>
<div class="topnav">

  <!-- Mobile menu toggle -->
  <button class="mob-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">
    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2">
      <path d="M3 6h14M3 10h14M3 14h14"/>
    </svg>
  </button>

  <!-- Breadcrumb -->
  <div class="bc">
    <a href="index.php">SalesOS</a>
    <?php foreach ($breadcrumbs as $i => $crumb):
      $isLast = ($i === count($breadcrumbs) - 1);
    ?>
      <span class="sep">/</span>
      <?php if (!$isLast && isset($crumb['url'])): ?>
        <a href="<?= htmlspecialchars($crumb['url']) ?>"><?= htmlspecialchars($crumb['label']) ?></a>
      <?php else: ?>
        <span class="cur"><?= htmlspecialchars($crumb['label']) ?></span>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>

  <!-- Right controls -->
  <div class="tnr">

    <!-- Search -->
    <div class="sbar">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8">
        <circle cx="7" cy="7" r="4.5"/><path d="M11 11l3 3"/>
      </svg>
      <input type="text" placeholder="Search anything…" id="globalSearch">
    </div>

    <!-- Notifications bell -->
    <a href="notifications.php" class="ibtn" title="Notifications">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8">
        <path d="M8 1a5 5 0 015 5v2l1.5 2.5h-13L3 8V6a5 5 0 015-5z"/>
        <path d="M6 13a2 2 0 004 0"/>
      </svg>
      <?php if ($_sb_notifs > 0): ?>
        <span class="dot"><?= min($_sb_notifs, 99) ?></span>
      <?php endif; ?>
    </a>

    <!-- Theme toggle -->
    <button class="thm-btn" id="thm" title="Toggle theme"><span id="thi">☀️</span></button>

    <!-- Logout -->
    <a href="logout.php" class="logout-btn">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8">
        <path d="M6 14H3a1 1 0 01-1-1V3a1 1 0 011-1h3M11 11l3-3-3-3M14 8H6"/>
      </svg>
      Logout
    </a>

  </div>
</div>
