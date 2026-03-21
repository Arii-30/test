<?php
/**
 * topnav.php – Reusable top navigation bar
 *
 * Required globals:
 *   $conn          – MySQLi connection
 *   $breadcrumbs   – array, e.g. [['label'=>'Dashboard']] or [['label'=>'Sales','url'=>'sales.php'], ['label'=>'Orders']]
 *   $uid           – logged‑in user ID (optional, taken from session if missing)
 *   $unread_notifs – optional (computed if not provided)
 */
if (!isset($conn)) die('topnav.php: $conn required');
if (!isset($uid))  $uid = (int)($_SESSION['user_id'] ?? 0);
$breadcrumbs = $breadcrumbs ?? [];
$page_title  = $page_title ?? 'SalesOS';

// Unread count fallback
if (!isset($unread_notifs)) {
    $r = $conn->query("SELECT COUNT(*) AS v FROM notifications WHERE recipient_id=$uid AND is_read=FALSE");
    $unread_notifs = $r ? (int)$r->fetch_assoc()['v'] : 0;
}
?>
<head>
  <style>
    /* Topnav */
.topnav {
  height: 58px; background: var(--bg2); border-bottom: 1px solid var(--br);
  position: sticky; top: 0; z-index: 50;
  display: flex; align-items: center; padding: 0 28px; gap: 14px;
  transition: background .4s;
}
.mob-btn { display: none; background: none; border: none; cursor: pointer; color: var(--t2); padding: 4px; }
.mob-btn svg { width: 20px; height: 20px; }
.bc { display: flex; align-items: center; gap: 7px; font-size: .75rem; color: var(--t3); }
.bc .sep { opacity: .4; }
.bc .cur { color: var(--tx); font-weight: 600; }

.tnr { margin-left: auto; display: flex; align-items: center; gap: 8px; }

.sbar {
  display: flex; align-items: center; gap: 8px;
  background: var(--bg3); border: 1px solid var(--br);
  border-radius: 9px; padding: 7px 14px; transition: border-color .2s;
}
.sbar:focus-within { border-color: var(--ac); }
.sbar svg { width: 14px; height: 14px; color: var(--t3); flex-shrink: 0; }
.sbar input { background: none; border: none; outline: none; font-family: inherit; font-size: .78rem; color: var(--tx); width: 180px; }
.sbar input::placeholder { color: var(--t3); }

.ibtn {
  width: 36px; height: 36px; border-radius: 9px;
  background: var(--bg3); border: 1px solid var(--br);
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; transition: all .2s; position: relative; color: var(--t2);
}
.ibtn:hover { background: var(--ag); border-color: rgba(79,142,247,.3); color: var(--ac); }
.ibtn svg { width: 16px; height: 16px; }
.ibtn .dot {
  position: absolute; top: -4px; right: -4px; width: 16px; height: 16px;
  background: var(--re); border-radius: 50%; font-size: .56rem; color: #fff;
  display: flex; align-items: center; justify-content: center;
  border: 2px solid var(--bg2); font-weight: 700;
}

.thm-btn {
  width: 36px; height: 36px; border-radius: 9px;
  background: var(--bg3); border: 1px solid var(--br);
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; font-size: .95rem; transition: all .25s;
}
.thm-btn:hover { transform: rotate(15deg); border-color: var(--ac); }

.logout-btn {
  display: flex; align-items: center; gap: 6px;
  padding: 7px 13px; background: var(--red);
  border: 1px solid rgba(248,113,113,.25);
  border-radius: 9px; color: var(--re);
  font-size: .76rem; font-weight: 600;
  text-decoration: none; transition: all .2s; font-family: inherit;
}
.logout-btn:hover { background: rgba(248,113,113,.2); }
.logout-btn svg { width: 14px; height: 14px; }
  </style>
</head>
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
    <!-- Search (static for now) -->
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
      <?php if ($unread_notifs > 0): ?>
        <span class="dot"><?= min($unread_notifs, 99) ?></span>
      <?php endif; ?>
    </a>

    <!-- Theme toggle (JavaScript remains in main page) -->
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