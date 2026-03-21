<?php
/**
 * sidebar.php – Reusable sidebar component
 *
 * Required globals:
 *   $conn         – MySQLi connection
 *   $current_page – e.g. 'dashboard', 'sales', 'employees' (matches page keys below)
 *
 * Optional (auto‑computed if missing):
 *   $user, $role, $ava, $uid
 *   $pending_sales_badge, $debt_badge, $low_stock_count, $unread_notifs
 */
if (!isset($conn)) die('sidebar.php: $conn required');

// Set defaults from session
$user  = $user  ?? htmlspecialchars($_SESSION['username'] ?? 'User');
$role  = $role  ?? htmlspecialchars(ucfirst($_SESSION['role'] ?? 'staff'));
$ava   = $ava   ?? strtoupper(substr($user, 0, 1));
$uid   = $uid   ?? (int)($_SESSION['user_id'] ?? 0);
$current_page = $current_page ?? '';

// Helper to fetch a badge count if not already set
function _ensure_badge(&$var, $conn, $sql) {
    if (!isset($var)) {
        $r = $conn->query($sql);
        $var = $r ? (int)($r->fetch_assoc()['v'] ?? 0) : 0;
    }
    return $var;
}

_ensure_badge($unread_notifs,        $conn, "SELECT COUNT(*) AS v FROM notifications WHERE recipient_id=$uid AND is_read=FALSE");
_ensure_badge($pending_sales_badge,  $conn, "SELECT COUNT(*) AS v FROM sales WHERE approval_status='pending'");
_ensure_badge($debt_badge,           $conn, "SELECT COUNT(*) AS v FROM debts WHERE status IN ('active','partially_paid','overdue')");
_ensure_badge($low_stock_count,      $conn, "SELECT COUNT(*) AS v FROM vw_low_stock");

// Helper to print a nav link with active state and badge
function nav_link($href, $label, $icon_svg, $page_key, $badge = null, $badge_class = '') {
    global $current_page;
    $active = ($page_key === $current_page) ? 'active' : '';
    echo '<a class="nav-item ' . $active . '" href="' . $href . '">';
    echo '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8">' . $icon_svg . '</svg>';
    echo $label;
    if ($badge !== null && $badge > 0) {
        echo '<span class="nbadge ' . $badge_class . '">' . $badge . '</span>';
    }
    echo '</a>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
 <style>
  /* ── SIDEBAR ── */
.sidebar {
  width: 248px; min-height: 100vh;
  background: var(--bg2); border-right: 1px solid var(--br);
  display: flex; flex-direction: column;
  position: fixed; top: 0; left: 0; bottom: 0; z-index: 100;
  transition: transform .3s ease;
}
.slogo {
  display: flex; align-items: center; gap: 11px;
  padding: 22px 20px; border-bottom: 1px solid var(--br);
}
.logo-icon {
  width: 34px; height: 34px; border-radius: 9px;
  background: linear-gradient(135deg, var(--ac), var(--ac2));
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 4px 14px var(--ag); flex-shrink: 0;
}
.logo-icon svg { width: 18px; height: 18px; }
.logo-txt { font-size: 1.1rem; font-weight: 800; color: var(--tx); letter-spacing: -.02em; }
.logo-txt span { color: var(--ac); }

.nav-sec { padding: 14px 12px 4px; }
.nav-lbl {
  font-size: .62rem; color: var(--t3); letter-spacing: .12em;
  text-transform: uppercase; padding: 0 8px 8px; display: block; font-weight: 600;
}
.nav-item {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 10px; border-radius: 9px;
  color: var(--t2); text-decoration: none; cursor: pointer;
  transition: all .15s; margin-bottom: 1px; position: relative;
  font-size: .82rem; font-weight: 500;
}
.nav-item:hover { background: rgba(255,255,255,.05); color: var(--tx); }
[data-theme="light"] .nav-item:hover { background: rgba(0,0,0,.04); }
.nav-item.active { background: var(--ag); color: var(--ac); font-weight: 600; }
.nav-item.active::before {
  content: ''; position: absolute; left: 0; top: 20%; bottom: 20%;
  width: 3px; background: var(--ac); border-radius: 0 3px 3px 0;
}
.nav-item svg { width: 16px; height: 16px; flex-shrink: 0; }
.nbadge {
  margin-left: auto; background: var(--re); color: #fff;
  font-size: .6rem; font-weight: 700; padding: 2px 7px;
  border-radius: 100px; line-height: 1.4;
}
.nbadge.g { background: var(--gr); color: #fff; }
.nbadge.b { background: var(--ac); }

.sfooter { margin-top: auto; padding: 14px 12px; border-top: 1px solid var(--br); }
.ucard {
  display: flex; align-items: center; gap: 10px; padding: 10px;
  background: var(--bg3); border: 1px solid var(--br); border-radius: 11px;
  cursor: pointer; transition: background .15s;
}
.ucard:hover { background: var(--bg4); }
.ava {
  width: 34px; height: 34px; border-radius: 9px; flex-shrink: 0;
  background: linear-gradient(135deg, var(--ac), var(--ac2));
  display: flex; align-items: center; justify-content: center;
  font-size: .9rem; font-weight: 800; color: #fff;
}
.uinfo { flex: 1; min-width: 0; }
.uname { font-size: .8rem; font-weight: 700; color: var(--tx); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.urole { font-size: .68rem; color: var(--ac); font-weight: 500; margin-top: 1px; }
 </style>
</head>
<body>
  <div class="sidebar" id="sidebar">
  <div class="slogo">
    <div class="logo-icon">
      <svg viewBox="0 0 18 18" fill="none" stroke="#fff" stroke-width="2">
        <path d="M3 9l3 3 4-5 4 4"/><rect x="1" y="1" width="16" height="16" rx="3"/>
      </svg>
    </div>
    <span class="logo-txt">Sales<span>OS</span></span>
  </div>

  <!-- Main -->
  <div class="nav-sec">
    <span class="nav-lbl">Main</span>
    <?php nav_link('index.php', 'Dashboard',
      '<rect x="1" y="1" width="6" height="6" rx="1.5"/><rect x="9" y="1" width="6" height="6" rx="1.5"/><rect x="1" y="9" width="6" height="6" rx="1.5"/><rect x="9" y="9" width="6" height="6" rx="1.5"/>',
      'dashboard'); ?>
  </div>

  <!-- Sales -->
  <div class="nav-sec">
    <span class="nav-lbl">Sales</span>
    <?php nav_link('sales.php', 'Sales Orders',
      '<path d="M2 12V6l6-4 6 4v6"/><path d="M6 12V9h4v3"/>',
      'sales', $pending_sales_badge); ?>
    <?php nav_link('customers.php', 'Customers',
      '<circle cx="6" cy="5" r="3"/><path d="M1 14c0-3 2.24-5 5-5h4c2.76 0 5 2 5 5"/>',
      'customers'); ?>
    <?php nav_link('payments.php', 'Payments',
      '<rect x="1" y="3" width="14" height="11" rx="1.5"/><path d="M1 7h14M5 3V1M11 3V1"/>',
      'payments'); ?>
    <?php nav_link('debts.php', 'Debts',
      '<path d="M8 1v14M1 8h14"/>',
      'debts', $debt_badge); ?>
  </div>

  <!-- Inventory -->
  <div class="nav-sec">
    <span class="nav-lbl">Inventory</span>
    <?php nav_link('products.php', 'Products',
      '<path d="M2 4l6-2 6 2v8l-6 2-6-2V4z"/><path d="M8 2v12M2 4l6 2 6-2"/>',
      'products'); ?>
    <?php nav_link('stock.php', 'Stock',
      '<rect x="2" y="6" width="12" height="8" rx="1"/><path d="M5 6V4a3 3 0 016 0v2"/>',
      'stock', $low_stock_count, 'b'); ?>
    <?php nav_link('purchases.php', 'Purchases',
      '<path d="M1 4h14v9H1z"/><path d="M1 7h14M5 4V2M11 4V2"/>',
      'purchases'); ?>
  </div>

  <!-- HR & Finance -->
  <div class="nav-sec">
    <span class="nav-lbl">HR &amp; Finance</span>
    <?php nav_link('employees.php', 'Employees',
      '<circle cx="5" cy="5" r="3"/><circle cx="11" cy="5" r="3"/><path d="M1 14c0-2.76 1.8-5 4-5M15 14c0-2.76-1.8-5-4-5M7 14c0-2.76.9-5 2-5s2 2.24 2 5"/>',
      'employees'); ?>
    <?php nav_link('salaries.php', 'Salaries',
      '<circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 2"/>',
      'salaries'); ?>
    <?php nav_link('expenses.php', 'Expenses',
      '<path d="M2 2h12v12H2z"/><path d="M5 8h6M5 5h6M5 11h3"/>',
      'expenses'); ?>
  </div>

  <!-- System -->
  <div class="nav-sec">
    <span class="nav-lbl">System</span>
    <?php nav_link('notifications.php', 'Notifications',
      '<path d="M8 1a5 5 0 015 5v2l1.5 2.5h-13L3 8V6a5 5 0 015-5z"/><path d="M6 13a2 2 0 004 0"/>',
      'notifications', $unread_notifs); ?>
    <?php nav_link('users.php', 'Users',
      '<circle cx="8" cy="8" r="3"/><path d="M8 1v2M8 13v2M1 8h2M13 8h2M3.1 3.1l1.4 1.4M11.5 11.5l1.4 1.4M3.1 12.9l1.4-1.4M11.5 4.5l1.4-1.4"/>',
      'users'); ?>
    <?php nav_link('audit.php', 'Audit Logs',
      '<path d="M2 3h12v2H2zM2 7h12v2H2zM2 11h7"/>',
      'audit', null, 'g'); ?>
  </div>

  <!-- User card -->
  <div class="sfooter">
    <div class="ucard">
      <div class="ava"><?= $ava ?></div>
      <div class="uinfo">
        <div class="uname"><?= $user ?></div>
        <div class="urole"><?= $role ?></div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
