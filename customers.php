<?php
require_once 'connection.php';
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$user = htmlspecialchars($_SESSION['username'] ?? 'Admin');
$role = htmlspecialchars(ucfirst($_SESSION['role'] ?? 'admin'));
$ava  = strtoupper(substr($user, 0, 1));
$uid  = (int)$_SESSION['user_id'];

$canEdit   = in_array($_SESSION['role'], ['admin', 'accountant']);
$canDelete = ($_SESSION['role'] === 'admin');

// ─── Sidebar / topnav vars ──────────────────────────────────
$current_page = 'customers';
$page_title   = 'Customers';

// Sidebar badge counts
$unread_notifs       = (int)($conn->query("SELECT COUNT(*) AS v FROM notifications WHERE recipient_id=$uid AND is_read=FALSE")->fetch_assoc()['v'] ?? 0);
$pending_sales_badge = (int)($conn->query("SELECT COUNT(*) AS v FROM sales WHERE approval_status='pending'")->fetch_assoc()['v'] ?? 0);
$debt_badge          = (int)($conn->query("SELECT COUNT(*) AS v FROM debts WHERE status IN ('active','partially_paid','overdue')")->fetch_assoc()['v'] ?? 0);
$low_stock_count     = (int)($conn->query("SELECT COUNT(*) AS v FROM vw_low_stock")->fetch_assoc()['v'] ?? 0);

// ─── Helpers ──────────────────────────────────────────────────
function fmt($v) {
    if ($v >= 1000000) return '$' . number_format($v/1000000, 2) . 'M';
    if ($v >= 1000)    return '$' . number_format($v/1000, 1)    . 'k';
    return '$' . number_format($v, 2);
}
function fval($conn, $sql, $col = 'v') {
    $r = $conn->query($sql); if (!$r) return 0;
    $row = $r->fetch_assoc(); return $row[$col] ?? 0;
}

// ─── POST Handlers ────────────────────────────────────────────
$flash = $flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $action = $_POST['action'] ?? '';

    // ── ADD ──────────────────────────────────────────────────
    if ($action === 'add') {
        $fname   = trim($_POST['first_name']   ?? '');
        $lname   = trim($_POST['last_name']    ?? '') ?: null;
        $biz     = trim($_POST['business_name']?? '') ?: null;
        $email   = trim($_POST['email']        ?? '') ?: null;
        $phone   = trim($_POST['phone']        ?? '');
        $address = trim($_POST['address']      ?? '') ?: null;
        $city    = trim($_POST['city']         ?? '') ?: null;
        $climit  = floatval($_POST['credit_limit'] ?? 0);
        $status  = $_POST['status'] ?? 'active';
        $notes   = trim($_POST['notes']        ?? '') ?: null;

        if ($fname && $phone) {
            $stmt = $conn->prepare("INSERT INTO customers
                (first_name,last_name,business_name,email,phone,address,city,credit_limit,status,notes)
                VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('sssssssdss',
                $fname,$lname,$biz,$email,$phone,$address,$city,$climit,$status,$notes);

            if ($stmt->execute()) {
                $new_id = $conn->insert_id;
                $escaped = $conn->real_escape_string("$fname " . ($lname ?? ''));
                $conn->query("INSERT INTO audit_logs (user_id,user_name,action,table_name,record_id,description)
                    VALUES ($uid,'$user','CREATE','customers',$new_id,'Customer $escaped added')");
                $flash = "Customer <strong>$fname " . htmlspecialchars($lname ?? '') . "</strong> added.";
                $flashType = 'ok';
            } else {
                $flash = "Error: " . htmlspecialchars($conn->error); $flashType = 'err';
            }
            $stmt->close();
        } else { $flash = "First name and phone are required."; $flashType = 'err'; }
    }

    // ── EDIT ─────────────────────────────────────────────────
    elseif ($action === 'edit') {
        $id      = (int)($_POST['id']          ?? 0);
        $fname   = trim($_POST['first_name']   ?? '');
        $lname   = trim($_POST['last_name']    ?? '') ?: null;
        $biz     = trim($_POST['business_name']?? '') ?: null;
        $email   = trim($_POST['email']        ?? '') ?: null;
        $phone   = trim($_POST['phone']        ?? '');
        $address = trim($_POST['address']      ?? '') ?: null;
        $city    = trim($_POST['city']         ?? '') ?: null;
        $climit  = floatval($_POST['credit_limit'] ?? 0);
        $status  = $_POST['status'] ?? 'active';
        $notes   = trim($_POST['notes']        ?? '') ?: null;

        if ($id && $fname && $phone) {
            $stmt = $conn->prepare("UPDATE customers SET
                first_name=?,last_name=?,business_name=?,email=?,phone=?,
                address=?,city=?,credit_limit=?,status=?,notes=? WHERE id=?");
            $stmt->bind_param('sssssssdssi',
                $fname,$lname,$biz,$email,$phone,$address,$city,$climit,$status,$notes,$id);

            if ($stmt->execute()) {
                $conn->query("INSERT INTO audit_logs (user_id,user_name,action,table_name,record_id,description)
                    VALUES ($uid,'$user','UPDATE','customers',$id,'Customer #$id updated')");
                $flash = "Customer updated successfully."; $flashType = 'ok';
            } else { $flash = "Error: " . htmlspecialchars($conn->error); $flashType = 'err'; }
            $stmt->close();
        }
    }

    // ── ADJUST BALANCE ────────────────────────────────────────
    elseif ($action === 'adjust_balance') {
        $id     = (int)($_POST['id']    ?? 0);
        $amount = floatval($_POST['amount'] ?? 0);
        $type   = $_POST['adj_type'] ?? 'add'; // add | subtract | set
        if ($id) {
            if ($type === 'set') {
                $conn->query("UPDATE customers SET current_balance=$amount WHERE id=$id");
            } elseif ($type === 'add') {
                $conn->query("UPDATE customers SET current_balance=current_balance+$amount WHERE id=$id");
            } else {
                $conn->query("UPDATE customers SET current_balance=GREATEST(0,current_balance-$amount) WHERE id=$id");
            }
            $conn->query("INSERT INTO audit_logs (user_id,user_name,action,table_name,record_id,description)
                VALUES ($uid,'$user','UPDATE','customers',$id,'Balance adjusted for customer #$id')");
            $flash = "Customer balance updated."; $flashType = 'ok';
        }
    }

    // ── STATUS CHANGE ─────────────────────────────────────────
    elseif ($action === 'status') {
        $id  = (int)($_POST['id'] ?? 0);
        $ns  = $_POST['new_status'] ?? 'inactive';
        if ($id && in_array($ns, ['active','inactive','blacklisted'])) {
            $conn->query("UPDATE customers SET status='$ns' WHERE id=$id");
            $conn->query("INSERT INTO audit_logs (user_id,user_name,action,table_name,record_id,description)
                VALUES ($uid,'$user','STATUS_CHANGE','customers',$id,'Customer #$id status → $ns')");
            $flash = "Status changed to <strong>$ns</strong>."; $flashType = 'ok';
        }
    }

    // ── DELETE ────────────────────────────────────────────────
    elseif ($action === 'delete' && $canDelete) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $conn->query("UPDATE customers SET status='inactive' WHERE id=$id");
            $conn->query("INSERT INTO audit_logs (user_id,user_name,action,table_name,record_id,description)
                VALUES ($uid,'$user','DELETE','customers',$id,'Customer #$id deactivated')");
            $flash = "Customer deactivated."; $flashType = 'warn';
        }
    }

    header("Location: customers.php" . ($flash ? "?flash=".urlencode($flash)."&ft=$flashType" : ''));
    exit;
}

if (isset($_GET['flash'])) { $flash = urldecode($_GET['flash']); $flashType = $_GET['ft'] ?? 'ok'; }

// ─── Filters ──────────────────────────────────────────────────
$search  = trim($_GET['q']      ?? '');
$fStatus = $_GET['status']      ?? '';
$fCity   = trim($_GET['city']   ?? '');
$fType   = $_GET['type']        ?? ''; // 'business' | 'individual'
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

$where = "WHERE 1=1";
if ($search) {
    $s = $conn->real_escape_string($search);
    $where .= " AND (first_name LIKE '%$s%' OR last_name LIKE '%$s%'
                OR business_name LIKE '%$s%' OR email LIKE '%$s%'
                OR phone LIKE '%$s%' OR city LIKE '%$s%')";
}
if ($fStatus) $where .= " AND status='" . $conn->real_escape_string($fStatus) . "'";
if ($fCity)   $where .= " AND city='"   . $conn->real_escape_string($fCity)   . "'";
if ($fType === 'business')   $where .= " AND business_name IS NOT NULL AND business_name != ''";
if ($fType === 'individual') $where .= " AND (business_name IS NULL OR business_name = '')";

$totalR = $conn->query("SELECT COUNT(*) AS c FROM customers $where");
$total  = $totalR ? (int)$totalR->fetch_assoc()['c'] : 0;
$totalPages = max(1, ceil($total / $perPage));

$customers = $conn->query("SELECT * FROM customers $where
    ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");

// ─── Stats ────────────────────────────────────────────────────
$statTotal      = (int)fval($conn, "SELECT COUNT(*) AS v FROM customers");
$statActive     = (int)fval($conn, "SELECT COUNT(*) AS v FROM customers WHERE status='active'");
$statBusiness   = (int)fval($conn, "SELECT COUNT(*) AS v FROM customers WHERE business_name IS NOT NULL AND business_name!=''");
$statBlacklisted= (int)fval($conn, "SELECT COUNT(*) AS v FROM customers WHERE status='blacklisted'");
$totalCredit    = (float)fval($conn, "SELECT COALESCE(SUM(credit_limit),0) AS v FROM customers WHERE status='active'");
$totalBalance   = (float)fval($conn, "SELECT COALESCE(SUM(current_balance),0) AS v FROM customers WHERE status='active'");

// Top cities
$citiesRes = $conn->query("SELECT city, COUNT(*) AS cnt FROM customers
    WHERE city IS NOT NULL AND city != '' AND status='active'
    GROUP BY city ORDER BY cnt DESC LIMIT 6");
$cities = [];
if ($citiesRes) while ($cr = $citiesRes->fetch_assoc()) $cities[] = $cr;

// Top customers by balance
$topRes = $conn->query("SELECT id, first_name, last_name, business_name,
    current_balance, credit_limit, status
    FROM customers WHERE status='active' AND current_balance > 0
    ORDER BY current_balance DESC LIMIT 5");

// ─── Unique cities for filter dropdown ────────────────────────
$cityOptRes = $conn->query("SELECT DISTINCT city FROM customers
    WHERE city IS NOT NULL AND city != '' ORDER BY city LIMIT 50");
$cityOpts = [];
if ($cityOptRes) while ($co = $cityOptRes->fetch_assoc()) $cityOpts[] = $co['city'];

// ─── Helpers ─────────────────────────────────────────────────
function statusPill($s) {
    if ($s === 'active')      return '<span class="pill ok">Active</span>';
    if ($s === 'inactive')    return '<span class="pill nt">Inactive</span>';
    if ($s === 'blacklisted') return '<span class="pill err">Blacklisted</span>';
    return '<span class="pill nt">' . htmlspecialchars($s) . '</span>';
}
function custName($c) {
    $name = trim($c['first_name'] . ' ' . ($c['last_name'] ?? ''));
    if (!empty($c['business_name'])) return $c['business_name'];
    return $name;
}
function custInitial($c) {
    if (!empty($c['business_name'])) return strtoupper(substr($c['business_name'], 0, 1));
    return strtoupper(substr($c['first_name'], 0, 1) . substr($c['last_name'] ?? '', 0, 1));
}
function avatarColor($id) {
    $colors = [
        'linear-gradient(135deg,#4F8EF7,#6BA3FF)',
        'linear-gradient(135deg,#34D399,#059669)',
        'linear-gradient(135deg,#F5A623,#D4880A)',
        'linear-gradient(135deg,#A78BFA,#7C3AED)',
        'linear-gradient(135deg,#22D3EE,#0891B2)',
        'linear-gradient(135deg,#F87171,#DC2626)',
        'linear-gradient(135deg,#FB923C,#EA580C)',
        'linear-gradient(135deg,#4ADE80,#16A34A)',
    ];
    return $colors[$id % count($colors)];
}
function buildQS($overrides = []) {
    $p = array_merge($_GET, $overrides); unset($p['page']);
    $qs = http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== false));
    return $qs ? "?$qs&" : "?";
}
$qs = buildQS();
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SalesOS — <?= $page_title ?></title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ── CSS VARIABLES ── */
[data-theme="dark"] {
  --bg:#0D0F14; --bg2:#13161D; --bg3:#191D27; --bg4:#1F2433;
  --br:rgba(255,255,255,.06); --br2:rgba(255,255,255,.11);
  --tx:#EDF0F7; --t2:#7B82A0; --t3:#3E4460;
  --ac:#4F8EF7; --ac2:#6BA3FF; --ag:rgba(79,142,247,.15);
  --go:#F5A623; --god:rgba(245,166,35,.12);
  --gr:#34D399; --grd:rgba(52,211,153,.12);
  --re:#F87171; --red:rgba(248,113,113,.12);
  --pu:#A78BFA; --pud:rgba(167,139,250,.12);
  --te:#22D3EE; --ted:rgba(34,211,238,.12);
  --sh:0 2px 16px rgba(0,0,0,.35); --sh2:0 8px 32px rgba(0,0,0,.4);
}
[data-theme="light"] {
  --bg:#F0EEE9; --bg2:#FFFFFF; --bg3:#F7F5F1; --bg4:#EDE9E2;
  --br:rgba(0,0,0,.07); --br2:rgba(0,0,0,.13);
  --tx:#1A1C27; --t2:#6B7280; --t3:#C0C5D4;
  --ac:#3B7DD8; --ac2:#2563EB; --ag:rgba(59,125,216,.12);
  --go:#D97706; --god:rgba(217,119,6,.1);
  --gr:#059669; --grd:rgba(5,150,105,.1);
  --re:#DC2626; --red:rgba(220,38,38,.1);
  --pu:#7C3AED; --pud:rgba(124,58,237,.1);
  --te:#0891B2; --ted:rgba(8,145,178,.1);
  --sh:0 2px 12px rgba(0,0,0,.07); --sh2:0 8px 28px rgba(0,0,0,.1);
}

/* ── RESET & BASE ── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{background:var(--bg);color:var(--tx);font-family:'Plus Jakarta Sans',sans-serif;font-size:.875rem;min-height:100vh;display:flex;transition:background .4s,color .4s}

/* ════════════════════════════════════════
   SIDEBAR  (shared styles)
════════════════════════════════════════ */
.sidebar{width:248px;min-height:100vh;background:var(--bg2);border-right:1px solid var(--br);display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;transition:transform .3s ease;overflow-y:auto}
.slogo{display:flex;align-items:center;gap:11px;padding:22px 20px;border-bottom:1px solid var(--br);flex-shrink:0}
.logo-icon{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,var(--ac),var(--ac2));display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px var(--ag);flex-shrink:0}
.logo-icon svg{width:18px;height:18px}
.logo-txt{font-size:1.1rem;font-weight:800;color:var(--tx);letter-spacing:-.02em}
.logo-txt span{color:var(--ac)}
.nav-sec{padding:14px 12px 4px;flex-shrink:0}
.nav-lbl{font-size:.62rem;color:var(--t3);letter-spacing:.12em;text-transform:uppercase;padding:0 8px 8px;display:block;font-weight:600}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:9px;color:var(--t2);text-decoration:none;transition:all .15s;margin-bottom:1px;position:relative;font-size:.82rem;font-weight:500}
.nav-item:hover{background:rgba(255,255,255,.05);color:var(--tx)}
[data-theme="light"] .nav-item:hover{background:rgba(0,0,0,.04)}
.nav-item.active{background:var(--ag);color:var(--ac);font-weight:600}
.nav-item.active::before{content:'';position:absolute;left:0;top:20%;bottom:20%;width:3px;background:var(--ac);border-radius:0 3px 3px 0}
.nav-item svg{width:16px;height:16px;flex-shrink:0}
.nbadge{margin-left:auto;background:var(--re);color:#fff;font-size:.6rem;font-weight:700;padding:2px 7px;border-radius:100px;line-height:1.4}
.nbadge.g{background:var(--gr)}.nbadge.b{background:var(--ac)}
.sfooter{margin-top:auto;padding:14px 12px;border-top:1px solid var(--br);flex-shrink:0}
.ucard{display:flex;align-items:center;gap:10px;padding:10px;background:var(--bg3);border:1px solid var(--br);border-radius:11px;cursor:pointer;transition:background .15s}
.ucard:hover{background:var(--bg4)}
.ava{width:34px;height:34px;border-radius:9px;flex-shrink:0;background:linear-gradient(135deg,var(--ac),var(--ac2));display:flex;align-items:center;justify-content:center;font-size:.9rem;font-weight:800;color:#fff}
.uinfo{flex:1;min-width:0}
.uname{font-size:.8rem;font-weight:700;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.urole{font-size:.68rem;color:var(--ac);font-weight:500;margin-top:1px}

/* ════════════════════════════════════════
   TOPNAV  (shared styles)
════════════════════════════════════════ */
.topnav{height:58px;background:var(--bg2);border-bottom:1px solid var(--br);position:sticky;top:0;z-index:50;display:flex;align-items:center;padding:0 28px;gap:14px;transition:background .4s;flex-shrink:0}
.mob-btn{display:none;background:none;border:none;cursor:pointer;color:var(--t2);padding:4px}
.mob-btn svg{width:20px;height:20px}
.bc{display:flex;align-items:center;gap:7px;font-size:.75rem;color:var(--t3)}
.bc .sep{opacity:.4}.bc .cur{color:var(--tx);font-weight:600}
.bc a{color:var(--t2);text-decoration:none}.bc a:hover{color:var(--tx)}
.tnr{margin-left:auto;display:flex;align-items:center;gap:8px}
.sbar{display:flex;align-items:center;gap:8px;background:var(--bg3);border:1px solid var(--br);border-radius:9px;padding:7px 14px;transition:border-color .2s}
.sbar:focus-within{border-color:var(--ac)}
.sbar svg{width:14px;height:14px;color:var(--t3);flex-shrink:0}
.sbar input{background:none;border:none;outline:none;font-family:inherit;font-size:.78rem;color:var(--tx);width:180px}
.sbar input::placeholder{color:var(--t3)}
.ibtn{width:36px;height:36px;border-radius:9px;background:var(--bg3);border:1px solid var(--br);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .2s;position:relative;color:var(--t2);text-decoration:none}
.ibtn:hover{background:var(--ag);border-color:rgba(79,142,247,.3);color:var(--ac)}
.ibtn svg{width:16px;height:16px}
.ibtn .dot{position:absolute;top:-4px;right:-4px;width:16px;height:16px;background:var(--re);border-radius:50%;font-size:.56rem;color:#fff;display:flex;align-items:center;justify-content:center;border:2px solid var(--bg2);font-weight:700}
.thm-btn{width:36px;height:36px;border-radius:9px;background:var(--bg3);border:1px solid var(--br);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.95rem;transition:all .25s}
.thm-btn:hover{transform:rotate(15deg);border-color:var(--ac)}
.logout-btn{display:flex;align-items:center;gap:6px;padding:7px 13px;background:var(--red);border:1px solid rgba(248,113,113,.25);border-radius:9px;color:var(--re);font-size:.76rem;font-weight:600;text-decoration:none;transition:all .2s;font-family:inherit}
.logout-btn:hover{background:rgba(248,113,113,.2)}
.logout-btn svg{width:14px;height:14px}

/* ════════════════════════════════════════
   MAIN LAYOUT
════════════════════════════════════════ */
.main{margin-left:248px;flex:1;display:flex;flex-direction:column;min-height:100vh}
.content{padding:28px;display:flex;flex-direction:column;gap:22px}

/* ════════════════════════════════════════
   PAGE-SPECIFIC STYLES
════════════════════════════════════════ */
@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}

/* Page header */
.page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}
.ph-title{font-size:1.5rem;font-weight:800;color:var(--tx);letter-spacing:-.03em}
.ph-sub{font-size:.78rem;color:var(--t2);margin-top:4px}
.ph-actions{display:flex;gap:8px;align-items:center}

/* Buttons */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:10px;font-family:inherit;font-size:.8rem;font-weight:700;cursor:pointer;border:none;transition:all .2s;text-decoration:none}
.btn-primary{background:var(--ac);color:#fff;box-shadow:0 4px 14px var(--ag)}
.btn-primary:hover{background:var(--ac2);transform:translateY(-1px)}
.btn-ghost{background:var(--bg3);border:1px solid var(--br);color:var(--t2)}
.btn-ghost:hover{border-color:var(--br2);color:var(--tx)}
.btn-danger{background:var(--red);border:1px solid rgba(248,113,113,.3);color:var(--re)}
.btn-danger:hover{background:rgba(248,113,113,.2)}
.btn svg{width:15px;height:15px}

/* Flash */
.flash{display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:11px;font-size:.82rem;font-weight:500;animation:fadeUp .4s cubic-bezier(.16,1,.3,1)}
.flash.ok  {background:var(--grd);border:1px solid rgba(52,211,153,.2);color:var(--gr)}
.flash.warn{background:var(--god);border:1px solid rgba(245,166,35,.2);color:var(--go)}
.flash.err {background:var(--red);border:1px solid rgba(248,113,113,.2);color:var(--re)}
.flash svg{width:15px;height:15px;flex-shrink:0}

/* Stats */
.stats-row{display:grid;grid-template-columns:repeat(6,1fr);gap:12px}
.scard{background:var(--bg2);border:1px solid var(--br);border-radius:13px;padding:16px 18px;animation:fadeUp .4s cubic-bezier(.16,1,.3,1) both;transition:transform .2s,box-shadow .2s;cursor:default}
.scard:hover{transform:translateY(-2px);box-shadow:var(--sh2)}
.sc-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.sc-icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center}
.sc-icon svg{width:16px;height:16px}
.sc-val{font-size:1.3rem;font-weight:800;color:var(--tx);letter-spacing:-.04em;line-height:1}
.sc-lbl{font-size:.68rem;color:var(--t2);margin-top:4px;font-weight:500}

/* 2-col layout */
.g2{display:grid;grid-template-columns:1.8fr 1fr;gap:18px}

/* Panels */
.panel{background:var(--bg2);border:1px solid var(--br);border-radius:14px;overflow:hidden;animation:fadeUp .4s .08s cubic-bezier(.16,1,.3,1) both}
.phead{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--br)}
.ptitle{font-size:.9rem;font-weight:700;color:var(--tx)}
.psub{font-size:.68rem;color:var(--t3);margin-top:3px;font-weight:500}
.plink{font-size:.72rem;color:var(--ac);text-decoration:none;font-weight:600}
.plink:hover{opacity:.75}
.pbody{padding:16px 20px}

/* Toolbar */
.toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.searchbox{display:flex;align-items:center;gap:8px;background:var(--bg2);border:1px solid var(--br);border-radius:10px;padding:8px 14px;flex:1;min-width:180px;max-width:300px;transition:border-color .2s}
.searchbox:focus-within{border-color:var(--ac)}
.searchbox svg{width:14px;height:14px;color:var(--t3);flex-shrink:0}
.searchbox input{background:none;border:none;outline:none;font-family:inherit;font-size:.82rem;color:var(--tx);width:100%}
.searchbox input::placeholder{color:var(--t3)}
.sel{background:var(--bg2);border:1px solid var(--br);border-radius:10px;padding:8px 12px;font-family:inherit;font-size:.8rem;color:var(--tx);cursor:pointer;outline:none;transition:border-color .2s}
.sel:focus{border-color:var(--ac)}
.toolbar-right{margin-left:auto;display:flex;align-items:center;gap:8px}
.count-lbl{font-size:.75rem;color:var(--t2)}

/* View toggle */
.view-toggle{display:flex;background:var(--bg3);border:1px solid var(--br);border-radius:9px;overflow:hidden}
.vt-btn{width:34px;height:34px;display:flex;align-items:center;justify-content:center;cursor:pointer;border:none;background:transparent;color:var(--t2);transition:all .15s}
.vt-btn.on{background:var(--ac);color:#fff}
.vt-btn svg{width:15px;height:15px}

/* Table */
.dtbl{width:100%;border-collapse:collapse}
.dtbl th{font-size:.64rem;color:var(--t3);text-transform:uppercase;letter-spacing:.1em;padding:11px 16px;text-align:left;border-bottom:1px solid var(--br);font-weight:700;white-space:nowrap}
.dtbl td{padding:13px 16px;font-size:.81rem;border-bottom:1px solid var(--br);vertical-align:middle}
.dtbl tr:last-child td{border-bottom:none}
.dtbl tbody tr{transition:background .1s}
.dtbl tbody tr:hover td{background:rgba(255,255,255,.022)}
[data-theme="light"] .dtbl tbody tr:hover td{background:rgba(0,0,0,.02)}
.cust-cell{display:flex;align-items:center;gap:11px}
.cust-ava{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.82rem;color:#fff;flex-shrink:0}
.cust-name{font-size:.84rem;font-weight:700;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px}
.cust-sub{font-size:.69rem;color:var(--t2);margin-top:1px}
.dim{color:var(--t2)}
.num{font-variant-numeric:tabular-nums;font-weight:700}
.num.gr{color:var(--gr)}
.num.go{color:var(--go)}
.num.re{color:var(--re)}

/* Pills */
.pill{display:inline-flex;align-items:center;padding:3px 10px;border-radius:100px;font-size:.67rem;font-weight:700;white-space:nowrap}
.pill.ok  {background:var(--grd);color:var(--gr)}
.pill.warn{background:var(--god);color:var(--go)}
.pill.err {background:var(--red);color:var(--re)}
.pill.info{background:var(--ag); color:var(--ac)}
.pill.pu  {background:var(--pud);color:var(--pu)}
.pill.nt  {background:var(--bg3);color:var(--t3)}

/* Credit bar */
.credit-bar{height:4px;background:var(--br);border-radius:2px;overflow:hidden;margin-top:4px;width:80px}
.credit-fill{height:100%;border-radius:2px;transition:width .6s cubic-bezier(.16,1,.3,1)}

/* Actions */
.actions{display:flex;align-items:center;gap:4px}
.act-btn{width:29px;height:29px;border-radius:7px;border:1px solid var(--br);background:transparent;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .15s;color:var(--t2)}
.act-btn:hover{border-color:var(--br2);color:var(--tx)}
.act-btn.edit:hover{background:var(--ag);border-color:rgba(79,142,247,.3);color:var(--ac)}
.act-btn.bal:hover{background:var(--grd);border-color:rgba(52,211,153,.3);color:var(--gr)}
.act-btn.del:hover{background:var(--red);border-color:rgba(248,113,113,.3);color:var(--re)}
.act-btn svg{width:13px;height:13px}

/* Cards view */
.cards-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;padding:18px}
.cust-card{background:var(--bg3);border:1px solid var(--br);border-radius:13px;padding:18px;transition:all .18s;animation:fadeUp .3s cubic-bezier(.16,1,.3,1) both}
.cust-card:hover{transform:translateY(-3px);box-shadow:var(--sh2);border-color:var(--br2)}
.cc-head{display:flex;align-items:flex-start;gap:12px;margin-bottom:14px}
.cc-ava{width:42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.95rem;color:#fff;flex-shrink:0}
.cc-name{font-size:.88rem;font-weight:700;color:var(--tx);line-height:1.3}
.cc-sub{font-size:.7rem;color:var(--t2);margin-top:2px}
.cc-meta{display:flex;align-items:center;gap:6px;font-size:.72rem;color:var(--t2);margin-top:2px}
.cc-stat-row{display:flex;gap:10px;margin-bottom:12px}
.cc-stat{flex:1;background:var(--bg2);border:1px solid var(--br);border-radius:8px;padding:9px 11px}
.cc-stat-lbl{font-size:.65rem;color:var(--t3);font-weight:600;text-transform:uppercase;letter-spacing:.06em;margin-bottom:3px}
.cc-stat-val{font-size:.9rem;font-weight:800;color:var(--tx)}
.cc-footer{display:flex;align-items:center;justify-content:space-between}

/* City chips */
.city-chips{display:flex;flex-wrap:wrap;gap:8px}
.city-chip{display:flex;align-items:center;gap:7px;padding:7px 12px;background:var(--bg3);border:1px solid var(--br);border-radius:100px;font-size:.73rem;font-weight:600;text-decoration:none;color:var(--t2);transition:all .15s;cursor:pointer}
.city-chip:hover,.city-chip.on{background:var(--ag);border-color:rgba(79,142,247,.3);color:var(--ac)}
.city-chip .cnt{font-size:.65rem;color:var(--t3)}

/* Top debtors */
.debtor-row{display:flex;align-items:center;gap:11px;padding:10px 0;border-bottom:1px solid var(--br)}
.debtor-row:last-child{border-bottom:none}
.debtor-num{width:20px;font-size:.7rem;font-weight:700;color:var(--t3);flex-shrink:0;text-align:right}
.debtor-ava{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.75rem;color:#fff;flex-shrink:0}
.debtor-info{flex:1;min-width:0}
.debtor-name{font-size:.8rem;font-weight:700;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.debtor-sub{font-size:.67rem;color:var(--t2);margin-top:1px}
.debtor-amt{font-size:.82rem;font-weight:800;color:var(--go);flex-shrink:0}

/* Pagination */
.pager{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-top:1px solid var(--br)}
.pager-info{font-size:.75rem;color:var(--t2)}
.pager-btns{display:flex;gap:4px}
.pg-btn{min-width:32px;height:32px;padding:0 8px;border-radius:8px;border:1px solid var(--br);background:var(--bg3);color:var(--t2);font-family:inherit;font-size:.78rem;font-weight:600;cursor:pointer;transition:all .15s;text-decoration:none;display:flex;align-items:center;justify-content:center}
.pg-btn:hover{border-color:var(--br2);color:var(--tx)}
.pg-btn.on{background:var(--ac);border-color:var(--ac);color:#fff}

/* Empty */
.empty-state{padding:56px 20px;text-align:center}
.empty-icon{width:52px;height:52px;border-radius:14px;background:var(--bg3);border:1px solid var(--br);margin:0 auto 14px;display:flex;align-items:center;justify-content:center}
.empty-icon svg{width:24px;height:24px;color:var(--t3)}
.empty-title{font-size:.95rem;font-weight:700;color:var(--tx);margin-bottom:4px}
.empty-sub{font-size:.78rem;color:var(--t2)}

/* ── MODAL ── */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:200;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .25s;backdrop-filter:blur(4px)}
.overlay.open{opacity:1;pointer-events:all}
.modal{background:var(--bg2);border:1px solid var(--br2);border-radius:18px;width:100%;max-width:600px;max-height:90vh;overflow-y:auto;box-shadow:var(--sh2);transform:translateY(20px) scale(.97);transition:transform .28s cubic-bezier(.16,1,.3,1)}
.overlay.open .modal{transform:none}
.modal.sm{max-width:400px}
.modal-head{display:flex;align-items:center;justify-content:space-between;padding:20px 24px;border-bottom:1px solid var(--br);position:sticky;top:0;background:var(--bg2);z-index:1}
.modal-title{font-size:1rem;font-weight:800;color:var(--tx)}
.modal-sub{font-size:.72rem;color:var(--t2);margin-top:2px}
.close-btn{width:30px;height:30px;border-radius:8px;border:1px solid var(--br);background:transparent;color:var(--t2);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s;font-size:1rem;line-height:1}
.close-btn:hover{background:var(--red);border-color:rgba(248,113,113,.3);color:var(--re)}
.modal-body{padding:22px 24px}
.modal-foot{padding:14px 24px;border-top:1px solid var(--br);display:flex;align-items:center;justify-content:flex-end;gap:10px;position:sticky;bottom:0;background:var(--bg2)}

/* Form */
.frow{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.frow.full{grid-template-columns:1fr}
.fgroup{display:flex;flex-direction:column;gap:5px;margin-bottom:2px}
.flabel{font-size:.72rem;font-weight:600;color:var(--t2)}
.flabel .req{color:var(--re)}
.finput,.fselect,.ftextarea{width:100%;background:var(--bg3);border:1px solid var(--br);border-radius:9px;padding:9px 13px;font-family:inherit;font-size:.82rem;color:var(--tx);outline:none;transition:border-color .2s}
.finput:focus,.fselect:focus,.ftextarea:focus{border-color:var(--ac)}
.finput::placeholder,.ftextarea::placeholder{color:var(--t3)}
.ftextarea{resize:vertical;min-height:64px}
.fsection{font-size:.68rem;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.1em;padding:12px 0 8px;border-bottom:1px solid var(--br);margin-bottom:12px}

/* Balance adjust modal */
.bal-display{background:var(--bg3);border:1px solid var(--br);border-radius:12px;padding:16px 20px;text-align:center;margin-bottom:18px}
.bal-label{font-size:.7rem;color:var(--t2);font-weight:600;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px}
.bal-amount{font-size:2rem;font-weight:800;letter-spacing:-.04em}
.bal-credit{font-size:.74rem;color:var(--t2);margin-top:3px}
.adj-types{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:16px}
.adj-type{padding:10px;border-radius:10px;border:1.5px solid var(--br);background:var(--bg3);cursor:pointer;text-align:center;transition:all .15s}
.adj-type:hover{border-color:var(--br2)}
.adj-type.on{border-color:var(--ac);background:var(--ag)}
.adj-type input[type=radio]{display:none}
.adj-type-icon{font-size:1.1rem;margin-bottom:3px}
.adj-type-lbl{font-size:.72rem;font-weight:700;color:var(--t2)}
.adj-type.on .adj-type-lbl{color:var(--ac)}

/* Confirm */
.cicon{width:50px;height:50px;border-radius:13px;display:flex;align-items:center;justify-content:center;margin:0 auto 14px}
.cicon svg{width:22px;height:22px}
.ctitle{font-size:1rem;font-weight:800;text-align:center;margin-bottom:6px}
.csub{font-size:.79rem;color:var(--t2);text-align:center;line-height:1.55}

/* ── RESPONSIVE ── */
@media(max-width:1280px){.stats-row{grid-template-columns:repeat(3,1fr)}}
@media(max-width:1100px){.g2{grid-template-columns:1fr}}
@media(max-width:900px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:none}.main{margin-left:0}.mob-btn{display:block}.sbar{display:none}.frow{grid-template-columns:1fr}}
@media(max-width:640px){.content{padding:16px}.stats-row{grid-template-columns:repeat(2,1fr)}.page-header{flex-direction:column}.adj-types{grid-template-columns:1fr}}
</style>
</head>
<body>

<?php include 'sidebar.php'; ?>

<div class="main">

  <?php
  $breadcrumbs = [['label' => 'Sales'], ['label' => 'Customers']];
  include 'topnav.php';
  ?>

  <div class="content">

    <!-- Page Header -->
    <div class="page-header">
      <div>
        <div class="ph-title">Customers</div>
        <div class="ph-sub">
          <?= number_format($statActive) ?> active customers &nbsp;·&nbsp;
          Total credit extended: <strong style="color:var(--ac)"><?= fmt($totalCredit) ?></strong> &nbsp;·&nbsp;
          Outstanding balance: <strong style="color:var(--go)"><?= fmt($totalBalance) ?></strong>
        </div>
      </div>
      <div class="ph-actions">
        <?php if ($canEdit): ?>
        <button class="btn btn-primary" onclick="openAdd()">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M8 1v14M1 8h14"/></svg>
          Add Customer
        </button>
        <?php endif; ?>
      </div>
    </div>

    <!-- Flash -->
    <?php if ($flash): ?>
    <div class="flash <?= $flashType ?>">
      <?php if ($flashType === 'ok'): ?>
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8" cy="8" r="7"/><path d="M5 8l2 2 4-4"/></svg>
      <?php else: ?>
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 1L1 13h14z"/><path d="M8 6v3M8 11v1"/></svg>
      <?php endif; ?>
      <span><?= $flash ?></span>
    </div>
    <?php endif; ?>

    <!-- Stat Cards -->
    <div class="stats-row">
      <div class="scard" style="--dl:.00s">
        <div class="sc-top">
          <div class="sc-icon" style="background:var(--ag)">
            <svg viewBox="0 0 16 16" fill="none" stroke="var(--ac)" stroke-width="1.8"><circle cx="6" cy="5" r="3"/><path d="M1 14c0-3 2.24-5 5-5h4c2.76 0 5 2 5 5"/></svg>
          </div>
        </div>
        <div class="sc-val"><?= number_format($statTotal) ?></div>
        <div class="sc-lbl">Total Customers</div>
      </div>
      <div class="scard" style="--dl:.05s">
        <div class="sc-top">
          <div class="sc-icon" style="background:var(--grd)">
            <svg viewBox="0 0 16 16" fill="none" stroke="var(--gr)" stroke-width="1.8"><circle cx="8" cy="8" r="7"/><path d="M5 8l2 2 4-4"/></svg>
          </div>
        </div>
        <div class="sc-val" style="color:var(--gr)"><?= number_format($statActive) ?></div>
        <div class="sc-lbl">Active</div>
      </div>
      <div class="scard" style="--dl:.10s">
        <div class="sc-top">
          <div class="sc-icon" style="background:var(--pud)">
            <svg viewBox="0 0 16 16" fill="none" stroke="var(--pu)" stroke-width="1.8"><path d="M2 2h12v3H2zM2 7h12v3H2zM2 12h6"/></svg>
          </div>
        </div>
        <div class="sc-val"><?= number_format($statBusiness) ?></div>
        <div class="sc-lbl">Businesses</div>
      </div>
      <div class="scard" style="--dl:.15s">
        <div class="sc-top">
          <div class="sc-icon" style="background:var(--red)">
            <svg viewBox="0 0 16 16" fill="none" stroke="var(--re)" stroke-width="1.8"><path d="M8 1L1 13h14z"/><path d="M8 6v3M8 11v1"/></svg>
          </div>
        </div>
        <div class="sc-val" style="color:var(--re)"><?= number_format($statBlacklisted) ?></div>
        <div class="sc-lbl">Blacklisted</div>
      </div>
      <div class="scard" style="--dl:.20s">
        <div class="sc-top">
          <div class="sc-icon" style="background:var(--ag)">
            <svg viewBox="0 0 16 16" fill="none" stroke="var(--ac)" stroke-width="1.8"><rect x="1" y="4" width="14" height="9" rx="1.5"/><path d="M1 8h14M5 4V2M11 4V2"/></svg>
          </div>
        </div>
        <div class="sc-val"><?= fmt($totalCredit) ?></div>
        <div class="sc-lbl">Credit Limit (Total)</div>
      </div>
      <div class="scard" style="--dl:.25s">
        <div class="sc-top">
          <div class="sc-icon" style="background:var(--god)">
            <svg viewBox="0 0 16 16" fill="none" stroke="var(--go)" stroke-width="1.8"><circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 2"/></svg>
          </div>
        </div>
        <div class="sc-val" style="color:var(--go)"><?= fmt($totalBalance) ?></div>
        <div class="sc-lbl">Outstanding Balance</div>
      </div>
    </div>

    <!-- City chips + Top debtors -->
    <div class="g2">
      <!-- Cities panel -->
      <div class="panel">
        <div class="phead">
          <div><div class="ptitle">Browse by City</div><div class="psub">Filter customers by location</div></div>
          <?php if ($fCity): ?>
            <a href="<?= buildQS(['city'=>'']) ?>" class="plink" style="font-size:.72rem">Clear ✕</a>
          <?php endif; ?>
        </div>
        <div class="pbody">
          <div class="city-chips">
            <a class="city-chip <?= !$fCity ? 'on' : '' ?>" href="customers.php<?= $search||$fStatus||$fType ? '?'.http_build_query(array_filter(['q'=>$search,'status'=>$fStatus,'type'=>$fType])) : '' ?>">
              <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" width="13" height="13"><path d="M8 1a5 5 0 015 7.5L8 15l-5-6.5A5 5 0 018 1z"/><circle cx="8" cy="6" r="1.5"/></svg>
              All Cities
            </a>
            <?php foreach ($cities as $c):
              $cqs = http_build_query(array_filter(['q'=>$search,'city'=>$c['city'],'status'=>$fStatus,'type'=>$fType]));
            ?>
            <a class="city-chip <?= $fCity===$c['city']?'on':'' ?>" href="customers.php?<?= $cqs ?>">
              <?= htmlspecialchars($c['city']) ?>
              <span class="cnt">(<?= $c['cnt'] ?>)</span>
            </a>
            <?php endforeach; ?>
            <?php if (!count($cities)): ?>
              <span style="font-size:.78rem;color:var(--t3)">No city data available.</span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Top balances panel -->
      <div class="panel">
        <div class="phead">
          <div><div class="ptitle">Highest Balances</div><div class="psub">Customers with outstanding debt</div></div>
        </div>
        <div class="pbody">
          <?php if ($topRes && $topRes->num_rows > 0):
            $rank = 1;
            while ($tr = $topRes->fetch_assoc()):
              $tname = custName($tr);
          ?>
          <div class="debtor-row">
            <div class="debtor-num"><?= $rank ?></div>
            <div class="debtor-ava" style="background:<?= avatarColor($tr['id']) ?>"><?= strtoupper(substr($tname,0,1)) ?></div>
            <div class="debtor-info">
              <div class="debtor-name"><?= htmlspecialchars($tname) ?></div>
              <div class="debtor-sub">Limit: <?= fmt((float)$tr['credit_limit']) ?></div>
            </div>
            <div class="debtor-amt"><?= fmt((float)$tr['current_balance']) ?></div>
          </div>
          <?php $rank++; endwhile;
          else: ?>
          <div style="text-align:center;color:var(--t3);font-size:.8rem;padding:24px 0">No outstanding balances.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Toolbar -->
    <form method="GET" action="customers.php" id="filterForm">
      <div class="toolbar">
        <?php if ($fCity): ?><input type="hidden" name="city" value="<?= htmlspecialchars($fCity) ?>"><?php endif; ?>
        <div class="searchbox">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="7" cy="7" r="4.5"/><path d="M11 11l3 3"/></svg>
          <input type="text" name="q" placeholder="Name, phone, email, city…" value="<?= htmlspecialchars($search) ?>" id="searchInp">
        </div>
        <select name="status" class="sel" onchange="this.form.submit()">
          <option value="">All Status</option>
          <option value="active"      <?= $fStatus==='active'?'selected':'' ?>>Active</option>
          <option value="inactive"    <?= $fStatus==='inactive'?'selected':'' ?>>Inactive</option>
          <option value="blacklisted" <?= $fStatus==='blacklisted'?'selected':'' ?>>Blacklisted</option>
        </select>
        <select name="type" class="sel" onchange="this.form.submit()">
          <option value="">All Types</option>
          <option value="business"   <?= $fType==='business'?'selected':'' ?>>Business</option>
          <option value="individual" <?= $fType==='individual'?'selected':'' ?>>Individual</option>
        </select>
        <button type="submit" class="btn btn-primary" style="padding:8px 14px">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="7" cy="7" r="4.5"/><path d="M11 11l3 3"/></svg>
          Search
        </button>
        <?php if ($search||$fStatus||$fCity||$fType): ?>
          <a href="customers.php" class="btn btn-ghost" style="padding:8px 14px">Clear</a>
        <?php endif; ?>
        <div class="toolbar-right">
          <span class="count-lbl"><?= number_format($total) ?> result<?= $total!==1?'s':'' ?></span>
          <div class="view-toggle">
            <button type="button" class="vt-btn on" id="viewTbl" title="Table view" onclick="setView('table')">
              <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 4h14M1 8h14M1 12h14M4 2v12"/></svg>
            </button>
            <button type="button" class="vt-btn" id="viewCards" title="Card view" onclick="setView('cards')">
              <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="1" y="1" width="6" height="6" rx="1.5"/><rect x="9" y="1" width="6" height="6" rx="1.5"/><rect x="1" y="9" width="6" height="6" rx="1.5"/><rect x="9" y="9" width="6" height="6" rx="1.5"/></svg>
            </button>
          </div>
        </div>
      </div>
    </form>

    <!-- Data panel -->
    <div class="panel">
      <?php if ($customers && $customers->num_rows > 0): ?>

      <!-- TABLE VIEW -->
      <div id="tableView" style="overflow-x:auto">
        <table class="dtbl">
          <thead>
            <tr>
              <th>Customer</th>
              <th>Phone</th>
              <th>City</th>
              <th>Credit Limit</th>
              <th>Balance</th>
              <th>Type</th>
              <th>Status</th>
              <?php if ($canEdit): ?><th>Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
          <?php
          $customers->data_seek(0);
          while ($c = $customers->fetch_assoc()):
            $cname    = custName($c);
            $initials = custInitial($c);
            $isBiz    = !empty($c['business_name']);
            $bal      = (float)$c['current_balance'];
            $lim      = (float)$c['credit_limit'];
            $usePct   = $lim > 0 ? min(round($bal/$lim*100),100) : 0;
            $balColor = $usePct > 80 ? 'var(--re)' : ($usePct > 50 ? 'var(--go)' : 'var(--gr)');
          ?>
          <tr>
            <td>
              <div class="cust-cell">
                <div class="cust-ava" style="background:<?= avatarColor($c['id']) ?>"><?= htmlspecialchars($initials) ?></div>
                <div>
                  <div class="cust-name"><?= htmlspecialchars($cname) ?></div>
                  <div class="cust-sub"><?= htmlspecialchars($c['email'] ?? ($isBiz ? trim($c['first_name'].' '.($c['last_name']??'')) : '—')) ?></div>
                </div>
              </div>
            </td>
            <td class="dim"><?= htmlspecialchars($c['phone']) ?></td>
            <td class="dim"><?= htmlspecialchars($c['city'] ?? '—') ?></td>
            <td>
              <span class="num"><?= $lim > 0 ? fmt($lim) : '—' ?></span>
            </td>
            <td>
              <?php if ($bal > 0): ?>
                <span class="num" style="color:<?= $balColor ?>"><?= fmt($bal) ?></span>
                <?php if ($lim > 0): ?>
                <div class="credit-bar">
                  <div class="credit-fill" style="width:<?= $usePct ?>%;background:<?= $balColor ?>"></div>
                </div>
                <?php endif; ?>
              <?php else: ?>
                <span class="dim">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($isBiz): ?>
                <span class="pill info">Business</span>
              <?php else: ?>
                <span class="pill nt">Individual</span>
              <?php endif; ?>
            </td>
            <td><?= statusPill($c['status']) ?></td>
            <?php if ($canEdit): ?>
            <td>
              <div class="actions">
                <!-- Edit -->
                <button class="act-btn edit" title="Edit" onclick='openEdit(<?= json_encode($c) ?>)'>
                  <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M11 2l3 3-9 9H2v-3z"/></svg>
                </button>
                <!-- Adjust balance -->
                <button class="act-btn bal" title="Adjust balance" onclick='openBalance(<?= json_encode($c) ?>)'>
                  <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 1v14M1 8h14"/></svg>
                </button>
                <!-- Status toggle -->
                <form method="POST" style="display:inline">
                  <input type="hidden" name="action" value="status">
                  <input type="hidden" name="id" value="<?= $c['id'] ?>">
                  <?php if ($c['status'] === 'active'): ?>
                    <input type="hidden" name="new_status" value="inactive">
                    <button type="submit" class="act-btn" title="Deactivate" style="color:var(--go)">
                      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 1a7 7 0 100 14A7 7 0 008 1z"/><path d="M6 6v4M10 6v4"/></svg>
                    </button>
                  <?php else: ?>
                    <input type="hidden" name="new_status" value="active">
                    <button type="submit" class="act-btn" title="Reactivate" style="color:var(--gr)">
                      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8" cy="8" r="7"/><path d="M5 8l2 2 4-4"/></svg>
                    </button>
                  <?php endif; ?>
                </form>
                <?php if ($canDelete): ?>
                <button class="act-btn del" title="Deactivate/remove" onclick="openDel(<?= $c['id'] ?>, '<?= htmlspecialchars($cname, ENT_QUOTES) ?>')">
                  <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 4h12M5 4V2h6v2M6 7v5M10 7v5M3 4l1 10h8l1-10"/></svg>
                </button>
                <?php endif; ?>
              </div>
            </td>
            <?php endif; ?>
          </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>

      <!-- CARDS VIEW (hidden by default) -->
      <div id="cardsView" style="display:none">
        <div class="cards-grid">
        <?php
        $customers->data_seek(0);
        $ci = 0;
        while ($c = $customers->fetch_assoc()):
          $cname    = custName($c);
          $initials = custInitial($c);
          $isBiz    = !empty($c['business_name']);
          $bal      = (float)$c['current_balance'];
          $lim      = (float)$c['credit_limit'];
          $usePct   = $lim > 0 ? min(round($bal/$lim*100),100) : 0;
          $balColor = $usePct > 80 ? 'var(--re)' : ($usePct > 50 ? 'var(--go)' : 'var(--gr)');
        ?>
        <div class="cust-card" style="--dl:<?= $ci*0.04 ?>s">
          <div class="cc-head">
            <div class="cc-ava" style="background:<?= avatarColor($c['id']) ?>"><?= htmlspecialchars($initials) ?></div>
            <div style="flex:1;min-width:0">
              <div class="cc-name"><?= htmlspecialchars($cname) ?></div>
              <?php if ($isBiz && ($c['first_name']||$c['last_name'])): ?>
                <div class="cc-sub"><?= htmlspecialchars(trim($c['first_name'].' '.($c['last_name']??''))) ?></div>
              <?php elseif ($c['email']): ?>
                <div class="cc-sub"><?= htmlspecialchars($c['email']) ?></div>
              <?php endif; ?>
              <div class="cc-meta">
                <?php if ($c['city']): ?>
                  <svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" width="10" height="10"><path d="M6 1a3.5 3.5 0 013.5 5.25L6 11 2.5 6.25A3.5 3.5 0 016 1z"/><circle cx="6" cy="4.5" r="1"/></svg>
                  <?= htmlspecialchars($c['city']) ?>
                <?php endif; ?>
                <?php if ($c['phone']): ?>
                  &nbsp;·&nbsp; <?= htmlspecialchars($c['phone']) ?>
                <?php endif; ?>
              </div>
            </div>
            <?= statusPill($c['status']) ?>
          </div>
          <div class="cc-stat-row">
            <div class="cc-stat">
              <div class="cc-stat-lbl">Credit Limit</div>
              <div class="cc-stat-val"><?= $lim > 0 ? fmt($lim) : '—' ?></div>
            </div>
            <div class="cc-stat">
              <div class="cc-stat-lbl">Balance</div>
              <div class="cc-stat-val" style="color:<?= $bal > 0 ? $balColor : 'var(--t3)' ?>"><?= $bal > 0 ? fmt($bal) : '—' ?></div>
            </div>
          </div>
          <?php if ($lim > 0): ?>
          <div class="credit-bar" style="width:100%;margin-bottom:12px">
            <div class="credit-fill" style="width:<?= $usePct ?>%;background:<?= $balColor ?>"></div>
          </div>
          <?php endif; ?>
          <div class="cc-footer">
            <span class="pill <?= $isBiz?'info':'nt' ?>"><?= $isBiz?'Business':'Individual' ?></span>
            <?php if ($canEdit): ?>
            <div class="actions">
              <button class="act-btn edit" title="Edit" onclick='openEdit(<?= json_encode($c) ?>)'>
                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M11 2l3 3-9 9H2v-3z"/></svg>
              </button>
              <button class="act-btn bal" title="Adjust balance" onclick='openBalance(<?= json_encode($c) ?>)'>
                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 1v14M1 8h14"/></svg>
              </button>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php $ci++; endwhile; ?>
        </div>
      </div>

      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
      <div class="pager">
        <span class="pager-info">Showing <?= $offset+1 ?>–<?= min($offset+$perPage,$total) ?> of <?= $total ?></span>
        <div class="pager-btns">
          <?php if ($page > 1): ?><a class="pg-btn" href="<?= $qs ?>page=<?= $page-1 ?>">‹</a><?php endif; ?>
          <?php for ($p=max(1,$page-2); $p<=min($totalPages,$page+2); $p++): ?>
            <a class="pg-btn <?= $p===$page?'on':'' ?>" href="<?= $qs ?>page=<?= $p ?>"><?= $p ?></a>
          <?php endfor; ?>
          <?php if ($page < $totalPages): ?><a class="pg-btn" href="<?= $qs ?>page=<?= $page+1 ?>">›</a><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="6" cy="5" r="3"/><path d="M1 14c0-3 2.24-5 5-5h4c2.76 0 5 2 5 5"/></svg></div>
        <div class="empty-title">No customers found</div>
        <div class="empty-sub"><?= ($search||$fStatus||$fCity||$fType) ? 'Try adjusting your search or filters.' : 'Add your first customer to get started.' ?></div>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- /content -->
</div><!-- /main -->


<?php if ($canEdit): ?>
<!-- ══ ADD / EDIT MODAL ══════════════════════════════════════ -->
<div class="overlay" id="custOverlay" onclick="closeModal(event,this)">
  <div class="modal">
    <div class="modal-head">
      <div>
        <div class="modal-title" id="mTitle">Add Customer</div>
        <div class="modal-sub"  id="mSub">Fill in the customer details</div>
      </div>
      <button class="close-btn" onclick="closeAll()">✕</button>
    </div>
    <form method="POST" action="customers.php" id="custForm">
      <input type="hidden" name="action" id="fAction" value="add">
      <input type="hidden" name="id"     id="fId"     value="">
      <div class="modal-body">

        <div class="fsection">Contact Information</div>
        <div class="frow">
          <div class="fgroup">
            <label class="flabel">First Name <span class="req">*</span></label>
            <input type="text" name="first_name" id="f_fname" class="finput" placeholder="John" required>
          </div>
          <div class="fgroup">
            <label class="flabel">Last Name</label>
            <input type="text" name="last_name" id="f_lname" class="finput" placeholder="Doe">
          </div>
        </div>
        <div class="frow full">
          <div class="fgroup">
            <label class="flabel">Business Name <span style="color:var(--t3)">(leave blank for individual)</span></label>
            <input type="text" name="business_name" id="f_biz" class="finput" placeholder="Acme Corp">
          </div>
        </div>
        <div class="frow">
          <div class="fgroup">
            <label class="flabel">Phone <span class="req">*</span></label>
            <input type="text" name="phone" id="f_phone" class="finput" placeholder="+1 555 000 0000" required>
          </div>
          <div class="fgroup">
            <label class="flabel">Email</label>
            <input type="email" name="email" id="f_email" class="finput" placeholder="john@example.com">
          </div>
        </div>
        <div class="frow">
          <div class="fgroup">
            <label class="flabel">City</label>
            <input type="text" name="city" id="f_city" class="finput" placeholder="New York">
          </div>
          <div class="fgroup">
            <label class="flabel">Address</label>
            <input type="text" name="address" id="f_address" class="finput" placeholder="123 Main St">
          </div>
        </div>

        <div class="fsection">Financial &amp; Status</div>
        <div class="frow">
          <div class="fgroup">
            <label class="flabel">Credit Limit ($)</label>
            <input type="number" name="credit_limit" id="f_climit" class="finput" min="0" step="0.01" placeholder="0.00" value="0">
          </div>
          <div class="fgroup">
            <label class="flabel">Status</label>
            <select name="status" id="f_status" class="fselect">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="blacklisted">Blacklisted</option>
            </select>
          </div>
        </div>
        <div class="frow full">
          <div class="fgroup">
            <label class="flabel">Notes</label>
            <textarea name="notes" id="f_notes" class="ftextarea" placeholder="Internal notes about this customer…"></textarea>
          </div>
        </div>

      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="closeAll()">Cancel</button>
        <button type="submit" class="btn btn-primary">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M2 8l4 4 8-8"/></svg>
          <span id="fSubmitTxt">Add Customer</span>
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ══ BALANCE MODAL ════════════════════════════════════════ -->
<div class="overlay" id="balOverlay" onclick="closeModal(event,this)">
  <div class="modal sm">
    <div class="modal-head">
      <div>
        <div class="modal-title">Adjust Balance</div>
        <div class="modal-sub" id="balName">—</div>
      </div>
      <button class="close-btn" onclick="closeAll()">✕</button>
    </div>
    <form method="POST" action="customers.php">
      <input type="hidden" name="action" value="adjust_balance">
      <input type="hidden" name="id" id="balId" value="">
      <div class="modal-body">
        <div class="bal-display">
          <div class="bal-label">Current Balance</div>
          <div class="bal-amount" id="balCurr" style="color:var(--go)">$0.00</div>
          <div class="bal-credit">Credit limit: <span id="balLim">—</span></div>
        </div>
        <div class="adj-types" id="adjTypes">
          <label class="adj-type on" onclick="selectAdj(this,'add')">
            <input type="radio" name="adj_type" value="add" checked>
            <div class="adj-type-icon">➕</div>
            <div class="adj-type-lbl">Add</div>
          </label>
          <label class="adj-type" onclick="selectAdj(this,'subtract')">
            <input type="radio" name="adj_type" value="subtract">
            <div class="adj-type-icon">➖</div>
            <div class="adj-type-lbl">Subtract</div>
          </label>
          <label class="adj-type" onclick="selectAdj(this,'set')">
            <input type="radio" name="adj_type" value="set">
            <div class="adj-type-icon">🎯</div>
            <div class="adj-type-lbl">Set to</div>
          </label>
        </div>
        <div class="fgroup">
          <label class="flabel">Amount ($) <span class="req">*</span></label>
          <input type="number" name="amount" class="finput" min="0" step="0.01" placeholder="0.00" required>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="closeAll()">Cancel</button>
        <button type="submit" class="btn btn-primary">Update Balance</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($canDelete): ?>
<!-- ══ CONFIRM MODAL ════════════════════════════════════════ -->
<div class="overlay" id="delOverlay" onclick="closeModal(event,this)">
  <div class="modal sm">
    <div class="modal-body" style="padding:30px 26px 18px">
      <div class="cicon" style="background:var(--red)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--re)" stroke-width="1.8"><path d="M2 4h12M5 4V2h6v2M6 7v5M10 7v5M3 4l1 10h8l1-10"/></svg></div>
      <div class="ctitle">Deactivate Customer?</div>
      <div class="csub" id="delMsg">This will mark the customer as inactive.</div>
    </div>
    <form method="POST" action="customers.php">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" id="delId" value="">
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="closeAll()">Cancel</button>
        <button type="submit" class="btn btn-danger">Deactivate</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
// ── Theme ────────────────────────────────────────────────────
const html=document.documentElement,thi=document.getElementById('thi');
function applyTheme(t){html.dataset.theme=t;thi.textContent=t==='dark'?'☀️':'🌙';}
document.getElementById('thm').addEventListener('click',()=>{
  const nt=html.dataset.theme==='dark'?'light':'dark';
  applyTheme(nt);localStorage.setItem('pos_theme',nt);
});
const sv=localStorage.getItem('pos_theme');if(sv)applyTheme(sv);

// ── Sidebar mobile ───────────────────────────────────────────
document.addEventListener('click',e=>{
  const sb=document.getElementById('sidebar');
  if(window.innerWidth<=900&&sb&&!sb.contains(e.target)&&!e.target.closest('.mob-btn'))
    sb.classList.remove('open');
});

// ── Modal helpers ────────────────────────────────────────────
function closeModal(e,el){if(e.target===el)closeAll();}
function closeAll(){document.querySelectorAll('.overlay').forEach(o=>o.classList.remove('open'));document.body.style.overflow='';}
function openOverlay(id){document.getElementById(id).classList.add('open');document.body.style.overflow='hidden';}

// ── View toggle ──────────────────────────────────────────────
function setView(v){
  const isTable=(v==='table');
  document.getElementById('tableView').style.display=isTable?'':'none';
  document.getElementById('cardsView').style.display=isTable?'none':'';
  document.getElementById('viewTbl').classList.toggle('on',isTable);
  document.getElementById('viewCards').classList.toggle('on',!isTable);
  localStorage.setItem('cust_view',v);
}
const savedView=localStorage.getItem('cust_view');
if(savedView)setView(savedView);

// ── Add modal ────────────────────────────────────────────────
function openAdd(){
  document.getElementById('mTitle').textContent='Add Customer';
  document.getElementById('mSub').textContent='Fill in the customer details';
  document.getElementById('fAction').value='add';
  document.getElementById('fId').value='';
  document.getElementById('custForm').reset();
  document.getElementById('fSubmitTxt').textContent='Add Customer';
  openOverlay('custOverlay');
}

// ── Edit modal ───────────────────────────────────────────────
function openEdit(c){
  document.getElementById('mTitle').textContent='Edit Customer';
  document.getElementById('mSub').textContent='#'+c.id+' — Update details';
  document.getElementById('fAction').value='edit';
  document.getElementById('fId').value=c.id;
  document.getElementById('f_fname').value=c.first_name||'';
  document.getElementById('f_lname').value=c.last_name||'';
  document.getElementById('f_biz').value=c.business_name||'';
  document.getElementById('f_phone').value=c.phone||'';
  document.getElementById('f_email').value=c.email||'';
  document.getElementById('f_city').value=c.city||'';
  document.getElementById('f_address').value=c.address||'';
  document.getElementById('f_climit').value=parseFloat(c.credit_limit||0).toFixed(2);
  document.getElementById('f_status').value=c.status||'active';
  document.getElementById('f_notes').value=c.notes||'';
  document.getElementById('fSubmitTxt').textContent='Save Changes';
  openOverlay('custOverlay');
}

// ── Balance modal ─────────────────────────────────────────────
function fmtAmt(v){return'$'+parseFloat(v||0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');}
function openBalance(c){
  const name=c.business_name||(c.first_name+' '+(c.last_name||'')).trim();
  document.getElementById('balName').textContent=name;
  document.getElementById('balId').value=c.id;
  document.getElementById('balCurr').textContent=fmtAmt(c.current_balance);
  document.getElementById('balCurr').style.color=parseFloat(c.current_balance)>0?'var(--go)':'var(--gr)';
  document.getElementById('balLim').textContent=parseFloat(c.credit_limit)>0?fmtAmt(c.credit_limit):'No limit';
  // reset adj type
  document.querySelectorAll('.adj-type').forEach(el=>el.classList.remove('on'));
  document.querySelector('.adj-type[onclick*="add"]').classList.add('on');
  document.querySelector('input[name=adj_type][value=add]').checked=true;
  openOverlay('balOverlay');
}
function selectAdj(el,type){
  document.querySelectorAll('.adj-type').forEach(e=>e.classList.remove('on'));
  el.classList.add('on');
}

// ── Delete confirm ────────────────────────────────────────────
function openDel(id,name){
  document.getElementById('delId').value=id;
  document.getElementById('delMsg').textContent='Deactivate "'+name+'"? You can reactivate them later.';
  openOverlay('delOverlay');
}

// ── Flash auto-dismiss ────────────────────────────────────────
setTimeout(()=>{
  const f=document.querySelector('.flash');
  if(f){f.style.transition='opacity .5s';f.style.opacity='0';setTimeout(()=>f.remove(),500);}
},4500);

// ── ESC close ────────────────────────────────────────────────
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeAll();});
</script>
</body>
</html>