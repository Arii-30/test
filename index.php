<?php
require_once 'connection.php';
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$user  = htmlspecialchars($_SESSION['username'] ?? 'Admin');
$role  = htmlspecialchars(ucfirst($_SESSION['role'] ?? 'admin'));
$email = htmlspecialchars($_SESSION['email'] ?? '');
$ava   = strtoupper(substr($user, 0, 1));
$uid   = (int)$_SESSION['user_id'];
$h     = (int)date('H');
$greet = $h < 12 ? 'Good morning' : ($h < 18 ? 'Good afternoon' : 'Good evening');

// ── HELPERS ──────────────────────────────────────────────────
function fmt($v) {
    if ($v >= 1000000) return '$' . number_format($v / 1000000, 1) . 'M';
    if ($v >= 1000)    return '$' . number_format($v / 1000, 1) . 'k';
    return '$' . number_format($v, 2);
}
function timeAgo($dt) {
    if (!$dt) return 'Never';
    $diff = time() - strtotime($dt);
    if ($diff < 60)   return $diff . 's ago';
    if ($diff < 3600) return floor($diff/60) . ' min ago';
    if ($diff < 86400)return floor($diff/3600) . ' hr ago';
    if ($diff < 172800) return 'Yesterday';
    return date('M j', strtotime($dt));
}
function safeQ($conn, $sql) {
    $r = $conn->query($sql);
    if (!$r) return null;
    return $r;
}
function fetchVal($conn, $sql, $col = 'v') {
    $r = $conn->query($sql);
    if (!$r) return 0;
    $row = $r->fetch_assoc();
    return $row[$col] ?? 0;
}

// ── TODAY STATS ──────────────────────────────────────────────
$today = date('Y-m-d');
$r = $conn->query("SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS rev
                   FROM sales WHERE sale_date='$today'
                   AND order_status NOT IN ('cancelled','returned')
                   AND approval_status='approved'");
$today_row     = $r ? $r->fetch_assoc() : ['cnt'=>0,'rev'=>0];
$today_orders  = (int)$today_row['cnt'];
$today_revenue = (float)$today_row['rev'];

$pending_approvals = (int)fetchVal($conn, "SELECT COUNT(*) AS v FROM vw_pending_rep_orders");

// ── STAT CARDS ────────────────────────────────────────────────
$total_revenue    = (float)fetchVal($conn,
    "SELECT COALESCE(SUM(total_amount),0) AS v FROM sales
     WHERE approval_status='approved' AND order_status NOT IN ('cancelled','returned')");

$active_customers = (int)fetchVal($conn,
    "SELECT COUNT(*) AS v FROM customers WHERE status='active'");

$total_stock      = (int)fetchVal($conn,
    "SELECT COALESCE(SUM(quantity_in_stock),0) AS v FROM inventory");

$low_stock_count  = (int)fetchVal($conn,
    "SELECT COUNT(*) AS v FROM vw_low_stock");

$outstanding_debts = (float)fetchVal($conn,
    "SELECT COALESCE(SUM(remaining_amount),0) AS v FROM debts
     WHERE status IN ('active','partially_paid','overdue')");

$employee_count   = (int)fetchVal($conn,
    "SELECT COUNT(*) AS v FROM employees WHERE status='active'");

$payments_month   = (float)fetchVal($conn,
    "SELECT COALESCE(SUM(amount),0) AS v FROM payments
     WHERE MONTH(payment_date)=MONTH(NOW()) AND YEAR(payment_date)=YEAR(NOW())");

$orders_month     = (int)fetchVal($conn,
    "SELECT COUNT(*) AS v FROM sales
     WHERE MONTH(sale_date)=MONTH(NOW()) AND YEAR(sale_date)=YEAR(NOW())
     AND order_status NOT IN ('cancelled','returned')");

$unread_notifs    = (int)fetchVal($conn,
    "SELECT COUNT(*) AS v FROM notifications WHERE recipient_id=$uid AND is_read=FALSE");

// ── SIDEBAR BADGES ────────────────────────────────────────────
$pending_sales_badge = (int)fetchVal($conn,
    "SELECT COUNT(*) AS v FROM sales WHERE approval_status='pending'");

$debt_badge = (int)fetchVal($conn,
    "SELECT COUNT(*) AS v FROM debts WHERE status IN ('active','partially_paid','overdue')");

// ── RECENT SALES (last 5) ─────────────────────────────────────
$recent_sales = $conn->query("
    SELECT s.sale_number, s.total_amount, s.order_status, s.payment_status, s.sale_date,
           TRIM(CONCAT(IFNULL(c.first_name,''), ' ', IFNULL(c.last_name,''))) AS customer_name
    FROM sales s
    LEFT JOIN customers c ON c.id = s.customer_id
    ORDER BY s.created_at DESC LIMIT 5");

// ── ACTIVITY FEED (last 5 audit logs) ────────────────────────
$activity = $conn->query("
    SELECT action, table_name, description, created_at, IFNULL(user_name,'System') AS user_name
    FROM audit_logs ORDER BY created_at DESC LIMIT 5");

// ── MONTHLY REVENUE SPARKLINE (last 12 months) ───────────────
$spark_res = $conn->query("
    SELECT yr, mn, IFNULL(total_revenue,0) AS total_revenue
    FROM vw_monthly_financials
    ORDER BY yr DESC, mn DESC LIMIT 12");
$spark_rows = [];
if ($spark_res) {
    while ($row = $spark_res->fetch_assoc()) { $spark_rows[] = $row; }
}
$spark_rows = array_reverse($spark_rows);
$spark_vals = array_column($spark_rows, 'total_revenue');
$spark_max  = count($spark_vals) ? max(array_map('floatval', $spark_vals)) : 1;
if ($spark_max == 0) $spark_max = 1;
$month_abbr = ['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

// ── YEAR-TO-DATE REVENUE ──────────────────────────────────────
$ytd_revenue = (float)fetchVal($conn,
    "SELECT COALESCE(SUM(total_revenue),0) AS v FROM vw_monthly_financials
     WHERE yr=YEAR(NOW())");

// ── LOW STOCK ITEMS (top 5) ───────────────────────────────────
$low_stock_items = $conn->query("
    SELECT product_name, quantity_available, min_stock_level, reorder_level
    FROM vw_low_stock LIMIT 5");

// ── SYSTEM USERS (most recently active) ──────────────────────
$sys_users = $conn->query("
    SELECT id, username, role, is_active, last_login
    FROM users
    ORDER BY is_active DESC, last_login IS NULL ASC, last_login DESC
    LIMIT 5");

// ── PILL HELPER ───────────────────────────────────────────────
function statusPill($s) {
    $map = [
        'delivered'  => ['ok',  'Delivered'],
        'paid'       => ['ok',  'Paid'],
        'active'     => ['ok',  'Active'],
        'approved'   => ['ok',  'Approved'],
        'confirmed'  => ['ok',  'Confirmed'],
        'pending'    => ['warn','Pending'],
        'partial'    => ['warn','Partial'],
        'processing' => ['info','Processing'],
        'packed'     => ['info','Packed'],
        'shipped'    => ['info','Shipped'],
        'draft'      => ['pu',  'Draft'],
        'unpaid'     => ['pu',  'Unpaid'],
        'cancelled'  => ['err', 'Cancelled'],
        'returned'   => ['err', 'Returned'],
        'rejected'   => ['err', 'Rejected'],
    ];
    $key = strtolower(trim($s));
    $p = $map[$key] ?? ['nt', ucfirst($s)];
    return '<span class="pill ' . $p[0] . '">' . $p[1] . '</span>';
}

// ── ACTIVITY ICON HELPER ──────────────────────────────────────
function actIcon($action, $table) {
    $action = strtoupper($action);
    if ($action === 'APPROVE') return ['var(--grd)', 'var(--gr)', '<circle cx="8" cy="8" r="7"/><path d="M5 8l2 2 4-4"/>'];
    if ($action === 'REJECT')  return ['var(--red)', 'var(--re)', '<path d="M8 1L1 13h14z"/><path d="M8 6v3M8 11v1"/>'];
    if ($action === 'LOGIN' || $action === 'LOGOUT') return ['var(--pud)', 'var(--pu)', '<circle cx="8" cy="5.5" r="3"/><path d="M1.5 14c0-3 2.24-5.5 6.5-5.5s6.5 2.5 6.5 5.5"/>'];
    if ($action === 'CREATE' && $table === 'sales') return ['var(--grd)', 'var(--gr)', '<circle cx="8" cy="8" r="7"/><path d="M5 8l2 2 4-4"/>'];
    if ($table === 'inventory') return ['var(--red)', 'var(--re)', '<path d="M8 1L1 13h14z"/><path d="M8 6v3M8 11v1"/>'];
    if ($action === 'CREATE')  return ['var(--ag)',  'var(--ac)', '<rect x="1" y="4" width="14" height="9" rx="1.5"/><path d="M1 8h14"/>'];
    if ($action === 'UPDATE')  return ['var(--god)', 'var(--go)', '<circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 2"/>'];
    if ($action === 'DELETE')  return ['var(--red)', 'var(--re)', '<path d="M2 4h12M5 4V2h6v2M6 7v5M10 7v5M3 4l1 10h8l1-10"/>'];
    return ['var(--ted)', 'var(--te)', '<circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 2"/>'];
}

// ── USER AVATAR GRADIENT ──────────────────────────────────────
function roleGradient($role) {
    if ($role === 'admin')          return 'linear-gradient(135deg,var(--ac),var(--ac2))';
    if ($role === 'accountant')     return 'linear-gradient(135deg,#F5A623,#D4880A)';
    if ($role === 'representative') return 'linear-gradient(135deg,#A78BFA,#7C3AED)';
    return 'linear-gradient(135deg,#34D399,#059669)';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SalesOS — Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
[data-theme="dark"] {
  --bg:    #0D0F14;
  --bg2:   #13161D;
  --bg3:   #191D27;
  --bg4:   #1F2433;
  --br:    rgba(255,255,255,.06);
  --br2:   rgba(255,255,255,.11);
  --tx:    #EDF0F7;
  --t2:    #7B82A0;
  --t3:    #3E4460;
  --ac:    #4F8EF7;
  --ac2:   #6BA3FF;
  --ag:    rgba(79,142,247,.15);
  --go:    #F5A623;
  --god:   rgba(245,166,35,.12);
  --gob:   rgba(245,166,35,.2);
  --gr:    #34D399;
  --grd:   rgba(52,211,153,.12);
  --re:    #F87171;
  --red:   rgba(248,113,113,.12);
  --pu:    #A78BFA;
  --pud:   rgba(167,139,250,.12);
  --te:    #22D3EE;
  --ted:   rgba(34,211,238,.12);
  --sh:    0 2px 16px rgba(0,0,0,.35);
  --sh2:   0 8px 32px rgba(0,0,0,.4);
}
[data-theme="light"] {
  --bg:    #F0EEE9;
  --bg2:   #FFFFFF;
  --bg3:   #F7F5F1;
  --bg4:   #EDE9E2;
  --br:    rgba(0,0,0,.07);
  --br2:   rgba(0,0,0,.13);
  --tx:    #1A1C27;
  --t2:    #6B7280;
  --t3:    #C0C5D4;
  --ac:    #3B7DD8;
  --ac2:   #2563EB;
  --ag:    rgba(59,125,216,.12);
  --go:    #D97706;
  --god:   rgba(217,119,6,.1);
  --gob:   rgba(217,119,6,.18);
  --gr:    #059669;
  --grd:   rgba(5,150,105,.1);
  --re:    #DC2626;
  --red:   rgba(220,38,38,.1);
  --pu:    #7C3AED;
  --pud:   rgba(124,58,237,.1);
  --te:    #0891B2;
  --ted:   rgba(8,145,178,.1);
  --sh:    0 2px 12px rgba(0,0,0,.07);
  --sh2:   0 8px 28px rgba(0,0,0,.1);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body {
  background: var(--bg); color: var(--tx);
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: .875rem; min-height: 100vh;
  display: flex; transition: background .4s, color .4s;
}

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

/* ── MAIN ── */
.main { margin-left: 248px; flex: 1; display: flex; flex-direction: column; min-height: 100vh; }

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

/* ── CONTENT ── */
.content { padding: 28px; display: flex; flex-direction: column; gap: 24px; }

/* Welcome bar */
.welcome-bar {
  background: linear-gradient(135deg, var(--ac), var(--ac2));
  border-radius: 16px; padding: 24px 28px;
  display: flex; align-items: center; justify-content: space-between;
  box-shadow: 0 8px 32px var(--ag);
  animation: fadeUp .5s cubic-bezier(.16,1,.3,1) both;
}
@keyframes fadeUp { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:none} }
.wb-title { font-size: 1.4rem; font-weight: 800; color: #fff; letter-spacing: -.03em; margin-bottom: 4px; }
.wb-sub { font-size: .78rem; color: rgba(255,255,255,.75); }
.wb-right { display: flex; gap: 20px; }
.wb-stat { text-align: center; }
.wb-val { font-size: 1.3rem; font-weight: 800; color: #fff; }
.wb-lbl { font-size: .68rem; color: rgba(255,255,255,.7); margin-top: 2px; }
.wb-div { width: 1px; background: rgba(255,255,255,.2); }

/* Stats grid */
.stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
.stat {
  background: var(--bg2); border: 1px solid var(--br);
  border-radius: 14px; padding: 20px;
  transition: all .2s; cursor: default;
  animation: fadeUp .5s var(--dl,0s) cubic-bezier(.16,1,.3,1) both;
}
.stat:hover { transform: translateY(-3px); box-shadow: var(--sh2); border-color: var(--br2); }
.stat-top { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 16px; }
.stat-icon { width: 40px; height: 40px; border-radius: 11px; display: flex; align-items: center; justify-content: center; }
.stat-icon svg { width: 20px; height: 20px; }
.trend { font-size: .68rem; padding: 3px 9px; border-radius: 100px; font-weight: 700; }
.trend.up { background: var(--grd); color: var(--gr); }
.trend.dn { background: var(--red); color: var(--re); }
.trend.nt { background: var(--bg3); color: var(--t3); }
.stat-val { font-size: 1.6rem; font-weight: 800; color: var(--tx); letter-spacing: -.04em; line-height: 1; }
.stat-lbl { font-size: .72rem; color: var(--t2); margin-top: 6px; font-weight: 500; }
.stat-bar { height: 3px; background: var(--br); border-radius: 2px; overflow: hidden; margin-top: 14px; }
.stat-bar-fill { height: 100%; border-radius: 2px; }

/* Grid layouts */
.g2 { display: grid; grid-template-columns: 1.4fr 1fr; gap: 18px; }
.g3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }

/* Panel */
.panel {
  background: var(--bg2); border: 1px solid var(--br);
  border-radius: 14px; overflow: hidden;
  animation: fadeUp .5s .15s cubic-bezier(.16,1,.3,1) both;
}
.phead {
  display: flex; align-items: center; justify-content: space-between;
  padding: 18px 20px; border-bottom: 1px solid var(--br);
}
.ptitle { font-size: .9rem; font-weight: 700; color: var(--tx); }
.psub { font-size: .68rem; color: var(--t3); margin-top: 3px; font-weight: 500; }
.plink { font-size: .72rem; color: var(--ac); text-decoration: none; font-weight: 600; }
.plink:hover { opacity: .75; }
.pbody { padding: 16px 20px; }

/* Table */
.dtbl { width: 100%; border-collapse: collapse; }
.dtbl th {
  font-size: .65rem; color: var(--t3); text-transform: uppercase;
  letter-spacing: .1em; padding: 8px 14px; text-align: left;
  border-bottom: 1px solid var(--br); font-weight: 700;
}
.dtbl td { padding: 12px 14px; font-size: .8rem; border-bottom: 1px solid var(--br); }
.dtbl tr:last-child td { border-bottom: none; }
.dtbl tr:hover td { background: rgba(255,255,255,.02); }
[data-theme="light"] .dtbl tr:hover td { background: rgba(0,0,0,.02); }
.dtbl td.nm { color: var(--tx); font-weight: 600; }
.dtbl td.dim { color: var(--t2); }

/* Pills */
.pill { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 100px; font-size: .68rem; font-weight: 700; }
.pill.ok   { background: var(--grd); color: var(--gr); }
.pill.warn { background: var(--god); color: var(--go); }
.pill.err  { background: var(--red); color: var(--re); }
.pill.info { background: var(--ag);  color: var(--ac); }
.pill.pu   { background: var(--pud); color: var(--pu); }
.pill.nt   { background: var(--bg3); color: var(--t3); }

/* Activity */
.act-item { display: flex; gap: 12px; padding: 11px 0; border-bottom: 1px solid var(--br); align-items: flex-start; }
.act-item:last-child { border-bottom: none; }
.act-icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.act-icon svg { width: 15px; height: 15px; }
.act-body { flex: 1; }
.act-txt { font-size: .78rem; color: var(--tx); font-weight: 500; line-height: 1.4; }
.act-time { font-size: .66rem; color: var(--t3); margin-top: 3px; }

/* Sparkline */
.sparkline { display: flex; align-items: flex-end; gap: 5px; height: 64px; }
.spk {
  flex: 1; border-radius: 4px 4px 0 0;
  background: var(--ag); border: 1px solid rgba(79,142,247,.2);
  transition: background .2s; cursor: default; position: relative;
}
.spk:hover { background: var(--ac); }
.spk::after {
  content: attr(title); position: absolute; bottom: calc(100% + 7px); left: 50%;
  transform: translateX(-50%); background: var(--bg4); border: 1px solid var(--br2);
  border-radius: 7px; padding: 4px 9px; font-size: .63rem; color: var(--tx);
  white-space: nowrap; opacity: 0; pointer-events: none; transition: opacity .15s;
  font-family: inherit;
}
.spk:hover::after { opacity: 1; }

/* Progress bars */
.pb-item { padding: 10px 0; border-bottom: 1px solid var(--br); }
.pb-item:last-child { border-bottom: none; }
.pb-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 7px; }
.pb-name { font-size: .78rem; color: var(--tx); font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 160px; }
.pb-val { font-size: .73rem; font-weight: 600; flex-shrink: 0; }
.pb-track { height: 5px; background: var(--br); border-radius: 3px; overflow: hidden; }
.pb-fill { height: 100%; border-radius: 3px; transition: width 1s cubic-bezier(.16,1,.3,1); }

/* User row */
.usr-row { display: flex; align-items: center; gap: 11px; padding: 10px 0; border-bottom: 1px solid var(--br); }
.usr-row:last-child { border-bottom: none; }
.usr-ava { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: .82rem; color: #fff; flex-shrink: 0; }
.usr-info { flex: 1; min-width: 0; }
.usr-name { font-size: .8rem; font-weight: 600; color: var(--tx); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.usr-role { font-size: .67rem; color: var(--t2); margin-top: 1px; }
.usr-status { font-size: .67rem; font-weight: 600; flex-shrink: 0; }

.empty-state { padding: 24px; text-align: center; color: var(--t3); font-size: .78rem; }

/* Responsive */
@media(max-width: 1200px) { .stats { grid-template-columns: repeat(2,1fr); } }
@media(max-width: 960px)  { .g2, .g3 { grid-template-columns: 1fr; } }
@media(max-width: 900px) {
  .sidebar { transform: translateX(-100%); }
  .sidebar.open { transform: none; }
  .main { margin-left: 0; }
  .mob-btn { display: block; }
  .sbar { display: none; }
}
@media(max-width: 640px) { .stats { grid-template-columns: 1fr; } .content { padding: 16px; } .wb-right { display: none; } }
</style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar" id="sidebar">
  <div class="slogo">
    <div class="logo-icon">
      <svg viewBox="0 0 18 18" fill="none" stroke="#fff" stroke-width="2"><path d="M3 9l3 3 4-5 4 4"/><rect x="1" y="1" width="16" height="16" rx="3"/></svg>
    </div>
    <span class="logo-txt">Sales<span>OS</span></span>
  </div>

  <div class="nav-sec">
    <span class="nav-lbl">Main</span>
    <a class="nav-item active" href="#">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="1" y="1" width="6" height="6" rx="1.5"/><rect x="9" y="1" width="6" height="6" rx="1.5"/><rect x="1" y="9" width="6" height="6" rx="1.5"/><rect x="9" y="9" width="6" height="6" rx="1.5"/></svg>
      Dashboard
    </a>
  </div>
  <div class="nav-sec">
    <span class="nav-lbl">Sales</span>
    <a class="nav-item" href="#">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 12V6l6-4 6 4v6"/><path d="M6 12V9h4v3"/></svg>
      Sales Orders
      <?php if ($pending_sales_badge > 0): ?>
        <span class="nbadge"><?= $pending_sales_badge ?></span>
      <?php endif; ?>
    </a>
    <a class="nav-item" href="#">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="6" cy="5" r="3"/><path d="M1 14c0-3 2.24-5 5-5h4c2.76 0 5 2 5 5"/></svg>
      Customers
    </a>
    <a class="nav-item" href="#">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="1" y="3" width="14" height="11" rx="1.5"/><path d="M1 7h14M5 3V1M11 3V1"/></svg>
      Payments
    </a>
    <a class="nav-item" href="#">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 1v14M1 8h14"/></svg>
      Debts
      <?php if ($debt_badge > 0): ?>
        <span class="nbadge"><?= $debt_badge ?></span>
      <?php endif; ?>
    </a>
  </div>
  <div class="nav-sec">
    <span class="nav-lbl">Inventory</span>
    <a class="nav-item" href="products.php">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 4l6-2 6 2v8l-6 2-6-2V4z"/><path d="M8 2v12M2 4l6 2 6-2"/></svg>
      Products
    </a>
    <a class="nav-item" href="#">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="6" width="12" height="8" rx="1"/><path d="M5 6V4a3 3 0 016 0v2"/></svg>
      Stock
      <?php if ($low_stock_count > 0): ?>
        <span class="nbadge b"><?= $low_stock_count ?></span>
      <?php endif; ?>
    </a>
    <a class="nav-item" href="#">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 4h14v9H1z"/><path d="M1 7h14M5 4V2M11 4V2"/></svg>
      Purchases
    </a>
  </div>
  <div class="nav-sec">
    <span class="nav-lbl">HR &amp; Finance</span>
    <a class="nav-item" href="employees.php">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="5" cy="5" r="3"/><circle cx="11" cy="5" r="3"/><path d="M1 14c0-2.76 1.8-5 4-5M15 14c0-2.76-1.8-5-4-5M7 14c0-2.76.9-5 2-5s2 2.24 2 5"/></svg>
      Employees
    </a>
    <a class="nav-item" href="#">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 2"/></svg>
      Salaries
    </a>
    <a class="nav-item" href="#">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 2h12v12H2z"/><path d="M5 8h6M5 5h6M5 11h3"/></svg>
      Expenses
    </a>
  </div>
  <div class="nav-sec">
    <span class="nav-lbl">System</span>
    <a class="nav-item" href="#">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 1a5 5 0 015 5v2l1.5 2.5h-13L3 8V6a5 5 0 015-5z"/><path d="M6 13a2 2 0 004 0"/></svg>
      Notifications
      <?php if ($unread_notifs > 0): ?>
        <span class="nbadge"><?= $unread_notifs ?></span>
      <?php endif; ?>
    </a>
    <a class="nav-item" href="#">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8" cy="8" r="3"/><path d="M8 1v2M8 13v2M1 8h2M13 8h2M3.1 3.1l1.4 1.4M11.5 11.5l1.4 1.4M3.1 12.9l1.4-1.4M11.5 4.5l1.4-1.4"/></svg>
      Users
    </a>
    <a class="nav-item" href="#">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 3h12v2H2zM2 7h12v2H2zM2 11h7"/></svg>
      Audit Logs
      <span class="nbadge g">New</span>
    </a>
  </div>

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

<!-- MAIN -->
<div class="main">
  <div class="topnav">
    <button class="mob-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">
      <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h14M3 10h14M3 14h14"/></svg>
    </button>
    <div class="bc">
      <span>SalesOS</span><span class="sep">/</span><span class="cur">Dashboard</span>
    </div>
    <div class="tnr">
      <div class="sbar">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="7" cy="7" r="4.5"/><path d="M11 11l3 3"/></svg>
        <input type="text" placeholder="Search anything...">
      </div>
      <div class="ibtn">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 1a5 5 0 015 5v2l1.5 2.5h-13L3 8V6a5 5 0 015-5z"/><path d="M6 13a2 2 0 004 0"/></svg>
        <?php if ($unread_notifs > 0): ?>
          <span class="dot"><?= min($unread_notifs, 99) ?></span>
        <?php endif; ?>
      </div>
      <button class="thm-btn" id="thm"><span id="thi">☀️</span></button>
      <a href="logout.php" class="logout-btn">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 14H3a1 1 0 01-1-1V3a1 1 0 011-1h3M11 11l3-3-3-3M14 8H6"/></svg>
        Logout
      </a>
    </div>
  </div>

  <div class="content">

    <!-- Welcome banner -->
    <div class="welcome-bar">
      <div class="wb-left">
        <div class="wb-title"><?= $greet ?>, <?= $user ?> 👋</div>
        <div class="wb-sub"><?= date('l, F j Y') ?> &nbsp;·&nbsp; Logged in as <strong><?= $role ?></strong></div>
      </div>
      <div class="wb-right">
        <div class="wb-stat">
          <div class="wb-val"><?= $today_orders ?></div>
          <div class="wb-lbl">Orders Today</div>
        </div>
        <div class="wb-div"></div>
        <div class="wb-stat">
          <div class="wb-val"><?= fmt($today_revenue) ?></div>
          <div class="wb-lbl">Revenue Today</div>
        </div>
        <div class="wb-div"></div>
        <div class="wb-stat">
          <div class="wb-val"><?= $pending_approvals ?></div>
          <div class="wb-lbl">Pending Approvals</div>
        </div>
      </div>
    </div>

    <!-- Stat cards -->
    <div class="stats">

      <!-- Total Revenue -->
      <div class="stat" style="--dl:.05s">
        <div class="stat-top">
          <div class="stat-icon" style="background:var(--grd)">
            <svg viewBox="0 0 16 16" fill="none" stroke="var(--gr)" stroke-width="1.8"><path d="M3 10l3 3 4-5 4 4"/></svg>
          </div>
          <span class="trend up">All-time</span>
        </div>
        <div class="stat-val"><?= fmt($total_revenue) ?></div>
        <div class="stat-lbl">Total Revenue</div>
        <div class="stat-bar"><div class="stat-bar-fill" style="width:72%;background:var(--gr)"></div></div>
      </div>

      <!-- Active Customers -->
      <div class="stat" style="--dl:.1s">
        <div class="stat-top">
          <div class="stat-icon" style="background:var(--ag)">
            <svg viewBox="0 0 16 16" fill="none" stroke="var(--ac)" stroke-width="1.8"><path d="M1 4h14v9H1z"/><path d="M1 7h14M5 4V2M11 4V2"/></svg>
          </div>
          <span class="trend up">Active</span>
        </div>
        <div class="stat-val"><?= number_format($active_customers) ?></div>
        <div class="stat-lbl">Active Customers</div>
        <div class="stat-bar"><div class="stat-bar-fill" style="width:60%;background:var(--ac)"></div></div>
      </div>

      <!-- Products in Stock -->
      <div class="stat" style="--dl:.15s">
        <div class="stat-top">
          <div class="stat-icon" style="background:var(--pud)">
            <svg viewBox="0 0 16 16" fill="none" stroke="var(--pu)" stroke-width="1.8"><path d="M2 4l6-2 6 2v8l-6 2-6-2z"/></svg>
          </div>
          <span class="trend nt">Units</span>
        </div>
        <div class="stat-val"><?= number_format($total_stock) ?></div>
        <div class="stat-lbl">Products in Stock</div>
        <div class="stat-bar"><div class="stat-bar-fill" style="width:80%;background:var(--pu)"></div></div>
      </div>

      <!-- Low Stock Alerts -->
      <div class="stat" style="--dl:.2s">
        <div class="stat-top">
          <div class="stat-icon" style="background:var(--red)">
            <svg viewBox="0 0 16 16" fill="none" stroke="var(--re)" stroke-width="1.8"><path d="M8 2v4M8 10v4M3 7l-2 1 2 1M13 7l2 1-2 1"/></svg>
          </div>
          <span class="trend dn">▼ Alert</span>
        </div>
        <div class="stat-val"><?= $low_stock_count ?></div>
        <div class="stat-lbl">Low Stock Alerts</div>
        <div class="stat-bar"><div class="stat-bar-fill" style="width:<?= min($low_stock_count * 10, 100) ?>%;background:var(--re)"></div></div>
      </div>

      <!-- Outstanding Debts -->
      <div class="stat" style="--dl:.25s">
        <div class="stat-top">
          <div class="stat-icon" style="background:var(--god)">
            <svg viewBox="0 0 16 16" fill="none" stroke="var(--go)" stroke-width="1.8"><circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 2"/></svg>
          </div>
          <span class="trend dn"><?= $debt_badge ?> open</span>
        </div>
        <div class="stat-val"><?= fmt($outstanding_debts) ?></div>
        <div class="stat-lbl">Outstanding Debts</div>
        <div class="stat-bar"><div class="stat-bar-fill" style="width:35%;background:var(--go)"></div></div>
      </div>

      <!-- Employees -->
      <div class="stat" style="--dl:.3s">
        <div class="stat-top">
          <div class="stat-icon" style="background:var(--ted)">
            <svg viewBox="0 0 16 16" fill="none" stroke="var(--te)" stroke-width="1.8"><circle cx="5" cy="5" r="3"/><circle cx="11" cy="5" r="3"/><path d="M1 14c0-2.76 2.24-5 4-5h6c2.76 0 4 2.24 4 5"/></svg>
          </div>
          <span class="trend up">Active</span>
        </div>
        <div class="stat-val"><?= $employee_count ?></div>
        <div class="stat-lbl">Employees</div>
        <div class="stat-bar"><div class="stat-bar-fill" style="width:55%;background:var(--te)"></div></div>
      </div>

      <!-- Payments This Month -->
      <div class="stat" style="--dl:.35s">
        <div class="stat-top">
          <div class="stat-icon" style="background:var(--grd)">
            <svg viewBox="0 0 16 16" fill="none" stroke="var(--gr)" stroke-width="1.8"><rect x="1" y="4" width="14" height="9" rx="1.5"/><path d="M1 8h14M5 4V2M11 4V2"/></svg>
          </div>
          <span class="trend up">This month</span>
        </div>
        <div class="stat-val"><?= fmt($payments_month) ?></div>
        <div class="stat-lbl">Payments Received</div>
        <div class="stat-bar"><div class="stat-bar-fill" style="width:60%;background:var(--gr)"></div></div>
      </div>

      <!-- Orders This Month -->
      <div class="stat" style="--dl:.4s">
        <div class="stat-top">
          <div class="stat-icon" style="background:var(--ag)">
            <svg viewBox="0 0 16 16" fill="none" stroke="var(--ac)" stroke-width="1.8"><path d="M1 8h14M3 4l5-3 5 3M3 12l5 3 5-3"/></svg>
          </div>
          <span class="trend up">This month</span>
        </div>
        <div class="stat-val"><?= $orders_month ?></div>
        <div class="stat-lbl">Orders This Month</div>
        <div class="stat-bar"><div class="stat-bar-fill" style="width:50%;background:var(--ac)"></div></div>
      </div>

    </div>

    <!-- Recent orders + Activity -->
    <div class="g2">
      <div class="panel">
        <div class="phead">
          <div><div class="ptitle">Recent Sales Orders</div><div class="psub">Last 5 transactions</div></div>
          <a href="#" class="plink">View all →</a>
        </div>
        <table class="dtbl">
          <thead><tr><th>Order</th><th>Customer</th><th>Amount</th><th>Status</th></tr></thead>
          <tbody>
          <?php if ($recent_sales && $recent_sales->num_rows > 0): ?>
            <?php while ($s = $recent_sales->fetch_assoc()):
              $cname = trim($s['customer_name']) ?: 'Walk-in';
            ?>
            <tr>
              <td class="nm"><?= htmlspecialchars($s['sale_number']) ?></td>
              <td class="dim"><?= htmlspecialchars($cname) ?></td>
              <td><?= fmt((float)$s['total_amount']) ?></td>
              <td><?= statusPill($s['order_status']) ?></td>
            </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr><td colspan="4" class="empty-state">No sales recorded yet.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="panel">
        <div class="phead"><div><div class="ptitle">Activity Feed</div><div class="psub">Recent audit events</div></div></div>
        <div class="pbody">
        <?php if ($activity && $activity->num_rows > 0):
          while ($a = $activity->fetch_assoc()):
            [$bg, $clr, $svgPath] = actIcon($a['action'], $a['table_name']);
            $desc = $a['description'] ?: ucfirst(strtolower($a['action'])) . ' on ' . $a['table_name'];
            if (strlen($desc) > 52) $desc = substr($desc, 0, 52) . '…';
        ?>
          <div class="act-item">
            <div class="act-icon" style="background:<?= $bg ?>">
              <svg viewBox="0 0 16 16" fill="none" stroke="<?= $clr ?>" stroke-width="1.8"><?= $svgPath ?></svg>
            </div>
            <div class="act-body">
              <div class="act-txt"><?= htmlspecialchars($desc) ?></div>
              <div class="act-time"><?= htmlspecialchars($a['user_name']) ?> · <?= timeAgo($a['created_at']) ?></div>
            </div>
          </div>
        <?php endwhile; else: ?>
          <div class="empty-state">No recent activity.</div>
        <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Revenue sparkline + Low stock + Users -->
    <div class="g3">

      <div class="panel">
        <div class="phead">
          <div><div class="ptitle">Monthly Revenue</div><div class="psub">Last 12 months</div></div>
        </div>
        <div class="pbody">
          <div style="margin-bottom:12px">
            <div style="font-size:1.6rem;font-weight:800;color:var(--tx);letter-spacing:-.04em"><?= fmt($ytd_revenue) ?></div>
            <div style="font-size:.72rem;color:var(--t2);margin-top:3px">Year to date</div>
          </div>
          <div class="sparkline" id="spk">
          <?php if (count($spark_rows) > 0): ?>
            <?php foreach ($spark_rows as $sr):
              $pct = $spark_max > 0 ? round(floatval($sr['total_revenue']) / $spark_max * 100) : 5;
              $pct = max($pct, 4);
              $lbl = ($month_abbr[(int)$sr['mn']] ?? 'M' . $sr['mn']) . ' ' . fmt((float)$sr['total_revenue']);
            ?>
            <div class="spk" style="height:<?= $pct ?>%" title="<?= htmlspecialchars($lbl) ?>"></div>
            <?php endforeach; ?>
          <?php else: ?>
            <?php for ($i = 0; $i < 12; $i++): ?>
            <div class="spk" style="height:8%" title="No data"></div>
            <?php endfor; ?>
          <?php endif; ?>
          </div>
          <?php
            // Build month labels aligned to spark_rows, or just show current year months
            if (count($spark_rows) >= 2):
              $labels = array_map(fn($r) => $month_abbr[(int)$r['mn']] ?? '?', $spark_rows);
            else:
              $labels = ['J','F','M','A','M','J','J','A','S','O','N','D'];
            endif;
          ?>
          <div style="display:flex;justify-content:space-between;font-size:.6rem;color:var(--t3);margin-top:6px;font-weight:600">
            <?php foreach ($labels as $lbl): ?>
              <span><?= htmlspecialchars(substr($lbl,0,1)) ?></span>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="phead">
          <div>
            <div class="ptitle">Low Stock</div>
            <div class="psub"><?= $low_stock_count ?> need restocking</div>
          </div>
          <a href="#" class="plink">View →</a>
        </div>
        <div class="pbody">
        <?php if ($low_stock_items && $low_stock_items->num_rows > 0):
          while ($ls = $low_stock_items->fetch_assoc()):
            $qty   = (int)$ls['quantity_available'];
            $min   = (int)$ls['min_stock_level'];
            $max   = max($min, 1);
            $pct   = min(round($qty / $max * 100), 100);
            $color = $qty <= 5 ? 'var(--re)' : ($qty <= $max * 0.5 ? 'var(--go)' : 'var(--gr)');
        ?>
          <div class="pb-item">
            <div class="pb-top">
              <span class="pb-name"><?= htmlspecialchars($ls['product_name']) ?></span>
              <span class="pb-val" style="color:<?= $color ?>"><?= $qty ?> left</span>
            </div>
            <div class="pb-track"><div class="pb-fill" style="width:<?= max($pct,3) ?>%;background:<?= $color ?>"></div></div>
          </div>
        <?php endwhile; else: ?>
          <div class="empty-state">All stock levels are healthy ✓</div>
        <?php endif; ?>
        </div>
      </div>

      <div class="panel">
        <div class="phead">
          <div><div class="ptitle">System Users</div><div class="psub">Recent logins</div></div>
          <a href="#" class="plink">Manage →</a>
        </div>
        <div class="pbody">
        <?php if ($sys_users && $sys_users->num_rows > 0):
          while ($u = $sys_users->fetch_assoc()):
            $uAva  = strtoupper(substr($u['username'], 0, 1));
            $uGrad = roleGradient($u['role']);
            $uRole = ucfirst($u['role']);
            $isMe  = ($u['id'] == $uid);
            $online = $isMe || ($u['last_login'] && (time() - strtotime($u['last_login'])) < 900);
        ?>
          <div class="usr-row">
            <div class="usr-ava" style="background:<?= $uGrad ?>"><?= $uAva ?></div>
            <div class="usr-info">
              <div class="usr-name"><?= htmlspecialchars($u['username']) ?><?= $isMe ? ' (you)' : '' ?></div>
              <div class="usr-role"><?= htmlspecialchars($uRole) ?></div>
            </div>
            <?php if ($u['is_active']): ?>
              <span class="usr-status" style="color:<?= $online ? 'var(--gr)' : 'var(--t3)' ?>">
                <?= $online ? '● Online' : '○ Offline' ?>
              </span>
            <?php else: ?>
              <span class="usr-status" style="color:var(--re)">✕ Disabled</span>
            <?php endif; ?>
          </div>
        <?php endwhile; else: ?>
          <div class="empty-state">No users found.</div>
        <?php endif; ?>
        </div>
      </div>

    </div>

  </div>
</div>

<script>
// Theme
const html = document.documentElement, thi = document.getElementById('thi');
function applyTheme(t) { html.dataset.theme = t; thi.textContent = t==='dark'?'☀️':'🌙'; }
document.getElementById('thm').onclick = () => {
  const nt = html.dataset.theme==='dark' ? 'light' : 'dark';
  applyTheme(nt); localStorage.setItem('pos_theme', nt);
};
const sv = localStorage.getItem('pos_theme'); if (sv) applyTheme(sv);

// Sidebar nav
document.querySelectorAll('.nav-item').forEach(x => x.addEventListener('click', function() {
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  this.classList.add('active');
}));

// Close sidebar on outside click (mobile)
document.addEventListener('click', e => {
  const sb = document.getElementById('sidebar');
  if (window.innerWidth <= 900 && !sb.contains(e.target) && !e.target.closest('.mob-btn'))
    sb.classList.remove('open');
});

// Highlight last sparkline bar
const bars = document.querySelectorAll('.spk');
if (bars.length) {
  const last = bars[bars.length-1];
  last.style.background = 'var(--ag)';
  last.style.borderColor = 'rgba(79,142,247,.4)';
}
</script>
</body>
</html>
