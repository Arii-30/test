<?php
/**
 * sidebar.php  — Reusable sidebar
 *
 * Required vars (set BEFORE including this file):
 *   $current_page  string  e.g. 'dashboard', 'employees', 'salaries', 'customers' …
 *
 * These are auto-resolved from session if not already set:
 *   $user, $role, $ava, $uid
 */

if (!isset($user))  $user = htmlspecialchars($_SESSION['username'] ?? 'User');
if (!isset($role))  $role = htmlspecialchars(ucfirst($_SESSION['role'] ?? 'staff'));
if (!isset($ava))   $ava  = strtoupper(substr($user, 0, 1));
if (!isset($uid))   $uid  = (int)($_SESSION['user_id'] ?? 0);
if (!isset($current_page)) $current_page = '';

// Live badge counts
function _sb_val($conn, $sql) {
    $r = $conn->query($sql);
    if (!$r) return 0;
    $row = $r->fetch_assoc();
    return (int)($row['v'] ?? 0);
}
$_sb_notifs  = _sb_val($conn, "SELECT COUNT(*) AS v FROM notifications WHERE recipient_id=$uid AND is_read=FALSE");
$_sb_pending = _sb_val($conn, "SELECT COUNT(*) AS v FROM sales WHERE approval_status='pending'");
$_sb_debts   = _sb_val($conn, "SELECT COUNT(*) AS v FROM debts WHERE status IN ('active','partially_paid','overdue')");
$_sb_stock   = _sb_val($conn, "SELECT COUNT(*) AS v FROM vw_low_stock");

// Helper: nav link
function _nav($href, $label, $icon_path, $page_key, $current, $badge = null, $badge_cls = '') {
    $active = ($page_key === $current) ? 'active' : '';
    echo '<a class="nav-item ' . $active . '" href="' . $href . '">';
    echo '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8">' . $icon_path . '</svg>';
    echo htmlspecialchars($label);
    if ($badge !== null && $badge > 0) {
        echo '<span class="nbadge ' . $badge_cls . '">' . $badge . '</span>';
    }
    echo '</a>';
}
?>
<div class="sidebar" id="sidebar">

  <!-- Logo -->
  <div class="slogo">
    <div class="logo-icon">
      <svg viewBox="0 0 18 18" fill="none" stroke="#fff" stroke-width="2">
        <path d="M3 9l3 3 4-5 4 4"/>
        <rect x="1" y="1" width="16" height="16" rx="3"/>
      </svg>
    </div>
    <span class="logo-txt">Sales<span>OS</span></span>
  </div>

  <!-- Main -->
  <div class="nav-sec">
    <span class="nav-lbl">Main</span>
    <?php _nav('index.php', 'Dashboard',
      '<rect x="1" y="1" width="6" height="6" rx="1.5"/><rect x="9" y="1" width="6" height="6" rx="1.5"/><rect x="1" y="9" width="6" height="6" rx="1.5"/><rect x="9" y="9" width="6" height="6" rx="1.5"/>',
      'dashboard', $current_page); ?>
  </div>

  <!-- Sales -->
  <div class="nav-sec">
    <span class="nav-lbl">Sales</span>
    <?php _nav('sales.php', 'Sales Orders',
      '<path d="M2 12V6l6-4 6 4v6"/><path d="M6 12V9h4v3"/>',
      'sales', $current_page, $_sb_pending); ?>
    <?php _nav('customers.php', 'Customers',
      '<circle cx="6" cy="5" r="3"/><path d="M1 14c0-3 2.24-5 5-5h4c2.76 0 5 2 5 5"/>',
      'customers', $current_page); ?>
    <?php _nav('payments.php', 'Payments',
      '<rect x="1" y="3" width="14" height="11" rx="1.5"/><path d="M1 7h14M5 3V1M11 3V1"/>',
      'payments', $current_page); ?>
    <?php _nav('debts.php', 'Debts',
      '<path d="M8 1v14M1 8h14"/>',
      'debts', $current_page, $_sb_debts); ?>
  </div>

  <!-- Inventory -->
  <div class="nav-sec">
    <span class="nav-lbl">Inventory</span>
    <?php _nav('products.php', 'Products',
      '<path d="M2 4l6-2 6 2v8l-6 2-6-2V4z"/><path d="M8 2v12M2 4l6 2 6-2"/>',
      'products', $current_page); ?>
    <?php _nav('stock.php', 'Stock',
      '<rect x="2" y="6" width="12" height="8" rx="1"/><path d="M5 6V4a3 3 0 016 0v2"/>',
      'stock', $current_page, $_sb_stock, 'b'); ?>
    <?php _nav('purchases.php', 'Purchases',
      '<path d="M1 4h14v9H1z"/><path d="M1 7h14M5 4V2M11 4V2"/>',
      'purchases', $current_page); ?>
  </div>

  <!-- HR & Finance -->
  <div class="nav-sec">
    <span class="nav-lbl">HR &amp; Finance</span>
    <?php _nav('employees.php', 'Employees',
      '<circle cx="5" cy="5" r="3"/><circle cx="11" cy="5" r="3"/><path d="M1 14c0-2.76 1.8-5 4-5M15 14c0-2.76-1.8-5-4-5M7 14c0-2.76.9-5 2-5s2 2.24 2 5"/>',
      'employees', $current_page); ?>
    <?php _nav('salaries.php', 'Salaries',
      '<circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 2"/>',
      'salaries', $current_page); ?>
    <?php _nav('expenses.php', 'Expenses',
      '<path d="M2 2h12v12H2z"/><path d="M5 8h6M5 5h6M5 11h3"/>',
      'expenses', $current_page); ?>
  </div>

  <!-- System -->
  <div class="nav-sec">
    <span class="nav-lbl">System</span>
    <?php _nav('notifications.php', 'Notifications',
      '<path d="M8 1a5 5 0 015 5v2l1.5 2.5h-13L3 8V6a5 5 0 015-5z"/><path d="M6 13a2 2 0 004 0"/>',
      'notifications', $current_page, $_sb_notifs); ?>
    <?php _nav('users.php', 'Users',
      '<circle cx="8" cy="8" r="3"/><path d="M8 1v2M8 13v2M1 8h2M13 8h2M3.1 3.1l1.4 1.4M11.5 11.5l1.4 1.4M3.1 12.9l1.4-1.4M11.5 4.5l1.4-1.4"/>',
      'users', $current_page); ?>
    <?php _nav('audit.php', 'Audit Logs',
      '<path d="M2 3h12v2H2zM2 7h12v2H2zM2 11h7"/>',
      'audit', $current_page, null, 'g'); ?>
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
