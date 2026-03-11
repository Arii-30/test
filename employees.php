<?php
require_once 'connection.php';
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$user  = htmlspecialchars($_SESSION['username'] ?? 'Admin');
$role  = htmlspecialchars(ucfirst($_SESSION['role'] ?? 'admin'));
$ava   = strtoupper(substr($user, 0, 1));
$uid   = (int)$_SESSION['user_id'];

// ── ACCESS CONTROL ────────────────────────────────────────────
$canEdit   = in_array($_SESSION['role'], ['admin', 'accountant']);
$canDelete = ($_SESSION['role'] === 'admin');

// ── FLASH MESSAGE ─────────────────────────────────────────────
$flash = $flashType = '';

// ── HANDLE POST ACTIONS ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $action = $_POST['action'] ?? '';

    // ── ADD EMPLOYEE ──────────────────────────────────────────
    if ($action === 'add') {
        $fname      = trim($_POST['first_name'] ?? '');
        $lname      = trim($_POST['last_name'] ?? '');
        $email      = trim($_POST['email'] ?? '') ?: null;
        $phone      = trim($_POST['phone'] ?? '') ?: null;
        $address    = trim($_POST['address'] ?? '') ?: null;
        $city       = trim($_POST['city'] ?? '') ?: null;
        $dob        = $_POST['date_of_birth'] ?? '' ?: null;
        $gender     = $_POST['gender'] ?? 'other';
        $dept       = $_POST['department'] ?? 'sales';
        $hire_date  = $_POST['hire_date'] ?? date('Y-m-d');
        $is_rep     = isset($_POST['is_representative']) ? 1 : 0;
        $commission = floatval($_POST['commission_rate'] ?? 0);
        $status     = $_POST['status'] ?? 'active';

        if ($fname) {
            $stmt = $conn->prepare("INSERT INTO employees
                (first_name,last_name,email,phone,address,city,date_of_birth,gender,
                 department,hire_date,is_representative,commission_rate,status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('ssssssssssdss',
                $fname,$lname,$email,$phone,$address,$city,$dob,$gender,
                $dept,$hire_date,$is_rep,$commission,$status);

            if ($stmt->execute()) {
                $new_id = $conn->insert_id;
                // Audit log
                $conn->query("INSERT INTO audit_logs (user_id,user_name,action,table_name,record_id,description)
                    VALUES ($uid,'$user','CREATE','employees',$new_id,'Employee $fname $lname added')");
                $flash = "Employee <strong>$fname $lname</strong> added successfully.";
                $flashType = 'ok';
            } else {
                $flash = "Error: " . htmlspecialchars($conn->error);
                $flashType = 'err';
            }
            $stmt->close();
        } else {
            $flash = "First name is required."; $flashType = 'err';
        }
    }

    // ── EDIT EMPLOYEE ─────────────────────────────────────────
    elseif ($action === 'edit') {
        $id         = (int)($_POST['id'] ?? 0);
        $fname      = trim($_POST['first_name'] ?? '');
        $lname      = trim($_POST['last_name'] ?? '');
        $email      = trim($_POST['email'] ?? '') ?: null;
        $phone      = trim($_POST['phone'] ?? '') ?: null;
        $address    = trim($_POST['address'] ?? '') ?: null;
        $city       = trim($_POST['city'] ?? '') ?: null;
        $dob        = $_POST['date_of_birth'] ?? '' ?: null;
        $gender     = $_POST['gender'] ?? 'other';
        $dept       = $_POST['department'] ?? 'sales';
        $hire_date  = $_POST['hire_date'] ?? date('Y-m-d');
        $is_rep     = isset($_POST['is_representative']) ? 1 : 0;
        $commission = floatval($_POST['commission_rate'] ?? 0);
        $status     = $_POST['status'] ?? 'active';

        if ($id && $fname) {
            $stmt = $conn->prepare("UPDATE employees SET
                first_name=?,last_name=?,email=?,phone=?,address=?,city=?,
                date_of_birth=?,gender=?,department=?,hire_date=?,
                is_representative=?,commission_rate=?,status=?
                WHERE id=?");
            $stmt->bind_param('sssssssssssdsi',
                $fname,$lname,$email,$phone,$address,$city,$dob,$gender,
                $dept,$hire_date,$is_rep,$commission,$status,$id);

            if ($stmt->execute()) {
                $conn->query("INSERT INTO audit_logs (user_id,user_name,action,table_name,record_id,description)
                    VALUES ($uid,'$user','UPDATE','employees',$id,'Employee #$id updated')");
                $flash = "Employee <strong>$fname $lname</strong> updated successfully.";
                $flashType = 'ok';
            } else {
                $flash = "Error: " . htmlspecialchars($conn->error);
                $flashType = 'err';
            }
            $stmt->close();
        }
    }

    // ── STATUS CHANGE ─────────────────────────────────────────
    elseif ($action === 'status') {
        $id        = (int)($_POST['id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? 'inactive';
        if ($id && in_array($newStatus, ['active','inactive','terminated'])) {
            $conn->query("UPDATE employees SET status='$newStatus' WHERE id=$id");
            $conn->query("INSERT INTO audit_logs (user_id,user_name,action,table_name,record_id,description)
                VALUES ($uid,'$user','STATUS_CHANGE','employees',$id,'Status changed to $newStatus')");
            $flash = "Employee status updated to <strong>$newStatus</strong>.";
            $flashType = 'ok';
        }
    }

    // ── DELETE ────────────────────────────────────────────────
    elseif ($action === 'delete' && $canDelete) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            // Soft-delete: set terminated
            $conn->query("UPDATE employees SET status='terminated' WHERE id=$id");
            $conn->query("INSERT INTO audit_logs (user_id,user_name,action,table_name,record_id,description)
                VALUES ($uid,'$user','DELETE','employees',$id,'Employee #$id terminated')");
            $flash = "Employee terminated successfully.";
            $flashType = 'warn';
        }
    }

    header("Location: employees.php" . ($flash ? "?flash=" . urlencode($flash) . "&ft=$flashType" : ''));
    exit;
}

// Restore flash from redirect
if (isset($_GET['flash'])) {
    $flash     = urldecode($_GET['flash']);
    $flashType = $_GET['ft'] ?? 'ok';
}

// ── FILTERS ───────────────────────────────────────────────────
$search   = trim($_GET['q'] ?? '');
$fDept    = $_GET['dept'] ?? '';
$fStatus  = $_GET['status'] ?? '';
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 12;
$offset   = ($page - 1) * $perPage;

$where = "WHERE 1=1";
$params = [];

if ($search) {
    $s = $conn->real_escape_string($search);
    $where .= " AND (first_name LIKE '%$s%' OR last_name LIKE '%$s%'
                     OR email LIKE '%$s%' OR phone LIKE '%$s%' OR city LIKE '%$s%')";
}
if ($fDept)   $where .= " AND department='" . $conn->real_escape_string($fDept) . "'";
if ($fStatus) $where .= " AND status='" . $conn->real_escape_string($fStatus) . "'";

// Total count
$totalR = $conn->query("SELECT COUNT(*) AS c FROM employees $where");
$total  = $totalR ? (int)$totalR->fetch_assoc()['c'] : 0;
$totalPages = ceil($total / $perPage);

// Fetch employees
$employees = $conn->query("SELECT * FROM employees $where
    ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");

// ── STATS ─────────────────────────────────────────────────────
function empStat($conn, $where) {
    $r = $conn->query("SELECT COUNT(*) AS v FROM employees $where");
    return $r ? (int)$r->fetch_assoc()['v'] : 0;
}
$statsTotal      = empStat($conn, '');
$statsActive     = empStat($conn, "WHERE status='active'");
$statsReps       = empStat($conn, "WHERE is_representative=1 AND status='active'");
$statsTerminated = empStat($conn, "WHERE status='terminated'");

// Department breakdown
$deptStats = $conn->query("SELECT department, COUNT(*) AS cnt
    FROM employees WHERE status='active' GROUP BY department ORDER BY cnt DESC");

// ── DEPT / GENDER / STATUS OPTIONS ───────────────────────────
$depts = ['management','sales','warehouse','accounting','delivery','hr'];
$deptColors = [
    'management' => ['var(--ac)','var(--ag)'],
    'sales'      => ['var(--gr)','var(--grd)'],
    'warehouse'  => ['var(--go)','var(--god)'],
    'accounting' => ['var(--pu)','var(--pud)'],
    'delivery'   => ['var(--te)','var(--ted)'],
    'hr'         => ['var(--re)','var(--red)'],
];

function deptPill($d) {
    global $deptColors;
    $c = $deptColors[$d] ?? ['var(--t2)','var(--bg3)'];
    return '<span class="pill dept" style="color:'.$c[0].';background:'.$c[1].'">'.ucfirst($d).'</span>';
}
function statusPill($s) {
    if ($s === 'active')     return '<span class="pill ok">Active</span>';
    if ($s === 'inactive')   return '<span class="pill warn">Inactive</span>';
    if ($s === 'terminated') return '<span class="pill err">Terminated</span>';
    return '<span class="pill nt">'.htmlspecialchars($s).'</span>';
}

// Sidebar badges
$unread_notifs       = (int)($conn->query("SELECT COUNT(*) AS v FROM notifications WHERE recipient_id=$uid AND is_read=FALSE")->fetch_assoc()['v'] ?? 0);
$pending_sales_badge = (int)($conn->query("SELECT COUNT(*) AS v FROM sales WHERE approval_status='pending'")->fetch_assoc()['v'] ?? 0);
$debt_badge          = (int)($conn->query("SELECT COUNT(*) AS v FROM debts WHERE status IN ('active','partially_paid','overdue')")->fetch_assoc()['v'] ?? 0);
$low_stock_count     = (int)($conn->query("SELECT COUNT(*) AS v FROM vw_low_stock")->fetch_assoc()['v'] ?? 0);

// Build query string for pagination
function buildQS($overrides = []) {
    $p = array_merge($_GET, $overrides);
    unset($p['page']);
    $qs = http_build_query(array_filter($p, fn($v) => $v !== ''));
    return $qs ? "?$qs&" : "?";
}
$qs = buildQS();
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SalesOS — Employees</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
[data-theme="dark"] {
  --bg:#0D0F14; --bg2:#13161D; --bg3:#191D27; --bg4:#1F2433;
  --br:rgba(255,255,255,.06); --br2:rgba(255,255,255,.11);
  --tx:#EDF0F7; --t2:#7B82A0; --t3:#3E4460;
  --ac:#4F8EF7; --ac2:#6BA3FF; --ag:rgba(79,142,247,.15);
  --go:#F5A623; --god:rgba(245,166,35,.12); --gob:rgba(245,166,35,.2);
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
  --go:#D97706; --god:rgba(217,119,6,.1); --gob:rgba(217,119,6,.18);
  --gr:#059669; --grd:rgba(5,150,105,.1);
  --re:#DC2626; --red:rgba(220,38,38,.1);
  --pu:#7C3AED; --pud:rgba(124,58,237,.1);
  --te:#0891B2; --ted:rgba(8,145,178,.1);
  --sh:0 2px 12px rgba(0,0,0,.07); --sh2:0 8px 28px rgba(0,0,0,.1);
}

*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{background:var(--bg);color:var(--tx);font-family:'Plus Jakarta Sans',sans-serif;font-size:.875rem;min-height:100vh;display:flex;transition:background .4s,color .4s}

/* ── SIDEBAR ── */
.sidebar{width:248px;min-height:100vh;background:var(--bg2);border-right:1px solid var(--br);display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;transition:transform .3s ease}
.slogo{display:flex;align-items:center;gap:11px;padding:22px 20px;border-bottom:1px solid var(--br)}
.logo-icon{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,var(--ac),var(--ac2));display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px var(--ag);flex-shrink:0}
.logo-icon svg{width:18px;height:18px}
.logo-txt{font-size:1.1rem;font-weight:800;color:var(--tx);letter-spacing:-.02em}
.logo-txt span{color:var(--ac)}
.nav-sec{padding:14px 12px 4px}
.nav-lbl{font-size:.62rem;color:var(--t3);letter-spacing:.12em;text-transform:uppercase;padding:0 8px 8px;display:block;font-weight:600}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:9px;color:var(--t2);text-decoration:none;cursor:pointer;transition:all .15s;margin-bottom:1px;position:relative;font-size:.82rem;font-weight:500}
.nav-item:hover{background:rgba(255,255,255,.05);color:var(--tx)}
[data-theme="light"] .nav-item:hover{background:rgba(0,0,0,.04)}
.nav-item.active{background:var(--ag);color:var(--ac);font-weight:600}
.nav-item.active::before{content:'';position:absolute;left:0;top:20%;bottom:20%;width:3px;background:var(--ac);border-radius:0 3px 3px 0}
.nav-item svg{width:16px;height:16px;flex-shrink:0}
.nbadge{margin-left:auto;background:var(--re);color:#fff;font-size:.6rem;font-weight:700;padding:2px 7px;border-radius:100px;line-height:1.4}
.nbadge.g{background:var(--gr)}
.nbadge.b{background:var(--ac)}
.sfooter{margin-top:auto;padding:14px 12px;border-top:1px solid var(--br)}
.ucard{display:flex;align-items:center;gap:10px;padding:10px;background:var(--bg3);border:1px solid var(--br);border-radius:11px;cursor:pointer;transition:background .15s}
.ucard:hover{background:var(--bg4)}
.ava{width:34px;height:34px;border-radius:9px;flex-shrink:0;background:linear-gradient(135deg,var(--ac),var(--ac2));display:flex;align-items:center;justify-content:center;font-size:.9rem;font-weight:800;color:#fff}
.uinfo{flex:1;min-width:0}
.uname{font-size:.8rem;font-weight:700;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.urole{font-size:.68rem;color:var(--ac);font-weight:500;margin-top:1px}

/* ── MAIN ── */
.main{margin-left:248px;flex:1;display:flex;flex-direction:column;min-height:100vh}
.topnav{height:58px;background:var(--bg2);border-bottom:1px solid var(--br);position:sticky;top:0;z-index:50;display:flex;align-items:center;padding:0 28px;gap:14px;transition:background .4s}
.mob-btn{display:none;background:none;border:none;cursor:pointer;color:var(--t2);padding:4px}
.mob-btn svg{width:20px;height:20px}
.bc{display:flex;align-items:center;gap:7px;font-size:.75rem;color:var(--t3)}
.bc .sep{opacity:.4}
.bc .cur{color:var(--tx);font-weight:600}
.bc a{color:var(--t2);text-decoration:none}
.bc a:hover{color:var(--tx)}
.tnr{margin-left:auto;display:flex;align-items:center;gap:8px}
.ibtn{width:36px;height:36px;border-radius:9px;background:var(--bg3);border:1px solid var(--br);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .2s;position:relative;color:var(--t2)}
.ibtn:hover{background:var(--ag);border-color:rgba(79,142,247,.3);color:var(--ac)}
.ibtn svg{width:16px;height:16px}
.ibtn .dot{position:absolute;top:-4px;right:-4px;width:16px;height:16px;background:var(--re);border-radius:50%;font-size:.56rem;color:#fff;display:flex;align-items:center;justify-content:center;border:2px solid var(--bg2);font-weight:700}
.thm-btn{width:36px;height:36px;border-radius:9px;background:var(--bg3);border:1px solid var(--br);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.95rem;transition:all .25s}
.thm-btn:hover{transform:rotate(15deg);border-color:var(--ac)}
.logout-btn{display:flex;align-items:center;gap:6px;padding:7px 13px;background:var(--red);border:1px solid rgba(248,113,113,.25);border-radius:9px;color:var(--re);font-size:.76rem;font-weight:600;text-decoration:none;transition:all .2s;font-family:inherit}
.logout-btn:hover{background:rgba(248,113,113,.2)}
.logout-btn svg{width:14px;height:14px}

/* ── CONTENT ── */
.content{padding:28px;display:flex;flex-direction:column;gap:22px}

/* Page header */
.page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px}
.ph-left .ph-title{font-size:1.5rem;font-weight:800;color:var(--tx);letter-spacing:-.03em}
.ph-left .ph-sub{font-size:.78rem;color:var(--t2);margin-top:4px}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:10px;font-family:inherit;font-size:.8rem;font-weight:700;cursor:pointer;border:none;transition:all .2s}
.btn-primary{background:var(--ac);color:#fff;box-shadow:0 4px 14px var(--ag)}
.btn-primary:hover{background:var(--ac2);transform:translateY(-1px);box-shadow:0 6px 20px var(--ag)}
.btn svg{width:15px;height:15px}

/* Flash */
.flash{display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:11px;font-size:.82rem;font-weight:500;animation:fadeUp .4s cubic-bezier(.16,1,.3,1)}
.flash.ok {background:var(--grd);border:1px solid rgba(52,211,153,.2);color:var(--gr)}
.flash.warn{background:var(--god);border:1px solid rgba(245,166,35,.2);color:var(--go)}
.flash.err {background:var(--red);border:1px solid rgba(248,113,113,.2);color:var(--re)}
.flash svg{width:15px;height:15px;flex-shrink:0}
@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}

/* Stats */
.stats4{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
.scard{background:var(--bg2);border:1px solid var(--br);border-radius:14px;padding:18px 20px;animation:fadeUp .4s cubic-bezier(.16,1,.3,1) both}
.scard:hover{transform:translateY(-2px);box-shadow:var(--sh2)}
.sc-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.sc-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center}
.sc-icon svg{width:18px;height:18px}
.sc-badge{font-size:.65rem;font-weight:700;padding:3px 8px;border-radius:100px}
.sc-val{font-size:1.7rem;font-weight:800;color:var(--tx);letter-spacing:-.04em;line-height:1}
.sc-lbl{font-size:.72rem;color:var(--t2);margin-top:5px;font-weight:500}

/* Dept breakdown */
.dept-strip{display:flex;gap:10px;flex-wrap:wrap}
.dept-chip{display:flex;align-items:center;gap:7px;padding:6px 12px;background:var(--bg2);border:1px solid var(--br);border-radius:100px;font-size:.73rem;font-weight:600;cursor:pointer;transition:all .15s;text-decoration:none;color:var(--t2)}
.dept-chip:hover,.dept-chip.on{background:var(--ag);border-color:rgba(79,142,247,.3);color:var(--ac)}
.dept-chip .dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}

/* Toolbar */
.toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.searchbox{display:flex;align-items:center;gap:8px;background:var(--bg2);border:1px solid var(--br);border-radius:10px;padding:8px 14px;flex:1;min-width:200px;max-width:320px;transition:border-color .2s}
.searchbox:focus-within{border-color:var(--ac)}
.searchbox svg{width:14px;height:14px;color:var(--t3);flex-shrink:0}
.searchbox input{background:none;border:none;outline:none;font-family:inherit;font-size:.82rem;color:var(--tx);width:100%}
.searchbox input::placeholder{color:var(--t3)}
.sel{background:var(--bg2);border:1px solid var(--br);border-radius:10px;padding:8px 12px;font-family:inherit;font-size:.8rem;color:var(--tx);cursor:pointer;outline:none;transition:border-color .2s}
.sel:focus{border-color:var(--ac)}
.toolbar-right{margin-left:auto;display:flex;align-items:center;gap:8px}
.count-info{font-size:.76rem;color:var(--t2)}

/* Table panel */
.panel{background:var(--bg2);border:1px solid var(--br);border-radius:14px;overflow:hidden;animation:fadeUp .4s .1s cubic-bezier(.16,1,.3,1) both}
.dtbl{width:100%;border-collapse:collapse}
.dtbl th{font-size:.65rem;color:var(--t3);text-transform:uppercase;letter-spacing:.1em;padding:11px 16px;text-align:left;border-bottom:1px solid var(--br);font-weight:700;white-space:nowrap}
.dtbl td{padding:13px 16px;font-size:.81rem;border-bottom:1px solid var(--br);vertical-align:middle}
.dtbl tr:last-child td{border-bottom:none}
.dtbl tbody tr{transition:background .12s}
.dtbl tbody tr:hover td{background:rgba(255,255,255,.025)}
[data-theme="light"] .dtbl tbody tr:hover td{background:rgba(0,0,0,.02)}
.emp-cell{display:flex;align-items:center;gap:11px}
.emp-ava{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.85rem;color:#fff;flex-shrink:0}
.emp-name{font-size:.84rem;font-weight:700;color:var(--tx)}
.emp-email{font-size:.7rem;color:var(--t2);margin-top:2px}
.dim{color:var(--t2)}
.rep-badge{display:inline-flex;align-items:center;gap:4px;background:var(--ag);color:var(--ac);font-size:.62rem;font-weight:700;padding:2px 7px;border-radius:100px;border:1px solid rgba(79,142,247,.2)}

/* Pills */
.pill{display:inline-flex;align-items:center;padding:3px 10px;border-radius:100px;font-size:.68rem;font-weight:700;white-space:nowrap}
.pill.ok  {background:var(--grd);color:var(--gr)}
.pill.warn{background:var(--god);color:var(--go)}
.pill.err {background:var(--red);color:var(--re)}
.pill.info{background:var(--ag); color:var(--ac)}
.pill.pu  {background:var(--pud);color:var(--pu)}
.pill.nt  {background:var(--bg3);color:var(--t3)}
.pill.dept{font-size:.67rem}

/* Action buttons */
.actions{display:flex;align-items:center;gap:5px}
.act-btn{width:30px;height:30px;border-radius:7px;border:1px solid var(--br);background:transparent;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .15s;color:var(--t2)}
.act-btn:hover{border-color:var(--br2);color:var(--tx)}
.act-btn.edit:hover{background:var(--ag);border-color:rgba(79,142,247,.3);color:var(--ac)}
.act-btn.del:hover{background:var(--red);border-color:rgba(248,113,113,.3);color:var(--re)}
.act-btn svg{width:13px;height:13px}

/* Pagination */
.pager{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-top:1px solid var(--br)}
.pager-info{font-size:.75rem;color:var(--t2)}
.pager-btns{display:flex;gap:4px}
.pg-btn{min-width:32px;height:32px;padding:0 8px;border-radius:8px;border:1px solid var(--br);background:var(--bg3);color:var(--t2);font-family:inherit;font-size:.78rem;font-weight:600;cursor:pointer;transition:all .15s;text-decoration:none;display:flex;align-items:center;justify-content:center}
.pg-btn:hover{border-color:var(--br2);color:var(--tx)}
.pg-btn.on{background:var(--ac);border-color:var(--ac);color:#fff}
.pg-btn:disabled{opacity:.3;cursor:not-allowed}

/* Empty state */
.empty-state{padding:56px 20px;text-align:center}
.empty-icon{width:52px;height:52px;border-radius:14px;background:var(--bg3);border:1px solid var(--br);margin:0 auto 14px;display:flex;align-items:center;justify-content:center}
.empty-icon svg{width:22px;height:22px;color:var(--t3)}
.empty-title{font-size:.95rem;font-weight:700;color:var(--tx);margin-bottom:5px}
.empty-sub{font-size:.78rem;color:var(--t2)}

/* ── MODAL ── */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:200;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .25s;backdrop-filter:blur(4px)}
.overlay.open{opacity:1;pointer-events:all}
.modal{background:var(--bg2);border:1px solid var(--br2);border-radius:18px;width:100%;max-width:620px;max-height:90vh;overflow-y:auto;box-shadow:var(--sh2);transform:translateY(24px) scale(.97);transition:transform .3s cubic-bezier(.16,1,.3,1)}
.overlay.open .modal{transform:none}
.modal-head{display:flex;align-items:center;justify-content:space-between;padding:22px 24px;border-bottom:1px solid var(--br);position:sticky;top:0;background:var(--bg2);z-index:1}
.modal-title{font-size:1rem;font-weight:800;color:var(--tx)}
.modal-sub{font-size:.73rem;color:var(--t2);margin-top:2px}
.close-btn{width:32px;height:32px;border-radius:8px;border:1px solid var(--br);background:transparent;color:var(--t2);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s;font-size:1.1rem}
.close-btn:hover{background:var(--red);border-color:rgba(248,113,113,.3);color:var(--re)}
.modal-body{padding:24px}

/* Form */
.frow{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.frow.full{grid-template-columns:1fr}
.fgroup{display:flex;flex-direction:column;gap:6px;margin-bottom:4px}
.flabel{font-size:.73rem;font-weight:600;color:var(--t2);letter-spacing:.02em}
.flabel .req{color:var(--re)}
.finput,.fselect,.ftextarea{width:100%;background:var(--bg3);border:1px solid var(--br);border-radius:9px;padding:9px 13px;font-family:inherit;font-size:.82rem;color:var(--tx);outline:none;transition:border-color .2s}
.finput::placeholder,.ftextarea::placeholder{color:var(--t3)}
.finput:focus,.fselect:focus,.ftextarea:focus{border-color:var(--ac)}
.ftextarea{resize:vertical;min-height:70px}
.fcheck{display:flex;align-items:center;gap:9px;cursor:pointer;font-size:.82rem;color:var(--tx)}
.fcheck input[type=checkbox]{width:16px;height:16px;accent-color:var(--ac);cursor:pointer}
.fsection{font-size:.7rem;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.1em;padding:14px 0 6px;border-bottom:1px solid var(--br);margin-bottom:14px}

.modal-foot{padding:16px 24px;border-top:1px solid var(--br);display:flex;align-items:center;justify-content:flex-end;gap:10px;position:sticky;bottom:0;background:var(--bg2)}
.btn-ghost{background:var(--bg3);border:1px solid var(--br);color:var(--t2)}
.btn-ghost:hover{border-color:var(--br2);color:var(--tx)}
.btn-danger{background:var(--red);border:1px solid rgba(248,113,113,.3);color:var(--re)}
.btn-danger:hover{background:rgba(248,113,113,.2)}

/* Confirm modal */
.confirm-modal{max-width:400px}
.confirm-icon{width:52px;height:52px;border-radius:14px;background:var(--red);display:flex;align-items:center;justify-content:center;margin:0 auto 14px}
.confirm-icon svg{width:24px;height:24px;color:var(--re)}
.confirm-title{font-size:1rem;font-weight:800;text-align:center;margin-bottom:6px}
.confirm-sub{font-size:.8rem;color:var(--t2);text-align:center;line-height:1.5}

/* Responsive */
@media(max-width:1100px){.stats4{grid-template-columns:repeat(2,1fr)}}
@media(max-width:900px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:none}.main{margin-left:0}.mob-btn{display:block}.frow{grid-template-columns:1fr}}
@media(max-width:640px){.content{padding:16px}.stats4{grid-template-columns:1fr}.page-header{flex-direction:column}.toolbar-right{margin-left:0}}
</style>
</head>
<body>

<!-- ══ SIDEBAR ══════════════════════════════════════════════ -->
<div class="sidebar" id="sidebar">
  <div class="slogo">
    <div class="logo-icon">
      <svg viewBox="0 0 18 18" fill="none" stroke="#fff" stroke-width="2"><path d="M3 9l3 3 4-5 4 4"/><rect x="1" y="1" width="16" height="16" rx="3"/></svg>
    </div>
    <span class="logo-txt">Sales<span>OS</span></span>
  </div>

  <div class="nav-sec">
    <span class="nav-lbl">Main</span>
    <a class="nav-item" href="index.php">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="1" y="1" width="6" height="6" rx="1.5"/><rect x="9" y="1" width="6" height="6" rx="1.5"/><rect x="1" y="9" width="6" height="6" rx="1.5"/><rect x="9" y="9" width="6" height="6" rx="1.5"/></svg>
      Dashboard
    </a>
  </div>
  <div class="nav-sec">
    <span class="nav-lbl">Sales</span>
    <a class="nav-item" href="#">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 12V6l6-4 6 4v6"/><path d="M6 12V9h4v3"/></svg>
      Sales Orders
      <?php if ($pending_sales_badge > 0): ?><span class="nbadge"><?= $pending_sales_badge ?></span><?php endif; ?>
    </a>
    <a class="nav-item" href="#"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="6" cy="5" r="3"/><path d="M1 14c0-3 2.24-5 5-5h4c2.76 0 5 2 5 5"/></svg>Customers</a>
    <a class="nav-item" href="#"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="1" y="3" width="14" height="11" rx="1.5"/><path d="M1 7h14M5 3V1M11 3V1"/></svg>Payments</a>
    <a class="nav-item" href="#">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 1v14M1 8h14"/></svg>
      Debts
      <?php if ($debt_badge > 0): ?><span class="nbadge"><?= $debt_badge ?></span><?php endif; ?>
    </a>
  </div>
  <div class="nav-sec">
    <span class="nav-lbl">Inventory</span>
    <a class="nav-item" href="#"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 4l6-2 6 2v8l-6 2-6-2V4z"/><path d="M8 2v12M2 4l6 2 6-2"/></svg>Products</a>
    <a class="nav-item" href="#">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="6" width="12" height="8" rx="1"/><path d="M5 6V4a3 3 0 016 0v2"/></svg>
      Stock
      <?php if ($low_stock_count > 0): ?><span class="nbadge b"><?= $low_stock_count ?></span><?php endif; ?>
    </a>
    <a class="nav-item" href="#"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 4h14v9H1z"/><path d="M1 7h14M5 4V2M11 4V2"/></svg>Purchases</a>
  </div>
  <div class="nav-sec">
    <span class="nav-lbl">HR &amp; Finance</span>
    <a class="nav-item active" href="employees.php">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="5" cy="5" r="3"/><circle cx="11" cy="5" r="3"/><path d="M1 14c0-2.76 1.8-5 4-5M15 14c0-2.76-1.8-5-4-5M7 14c0-2.76.9-5 2-5s2 2.24 2 5"/></svg>
      Employees
    </a>
    <a class="nav-item" href="#"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 2"/></svg>Salaries</a>
    <a class="nav-item" href="#"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 2h12v12H2z"/><path d="M5 8h6M5 5h6M5 11h3"/></svg>Expenses</a>
  </div>
  <div class="nav-sec">
    <span class="nav-lbl">System</span>
    <a class="nav-item" href="#">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 1a5 5 0 015 5v2l1.5 2.5h-13L3 8V6a5 5 0 015-5z"/><path d="M6 13a2 2 0 004 0"/></svg>
      Notifications
      <?php if ($unread_notifs > 0): ?><span class="nbadge"><?= $unread_notifs ?></span><?php endif; ?>
    </a>
    <a class="nav-item" href="#"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8" cy="8" r="3"/><path d="M8 1v2M8 13v2M1 8h2M13 8h2M3.1 3.1l1.4 1.4M11.5 11.5l1.4 1.4M3.1 12.9l1.4-1.4M11.5 4.5l1.4-1.4"/></svg>Users</a>
    <a class="nav-item" href="#"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 3h12v2H2zM2 7h12v2H2zM2 11h7"/></svg>Audit Logs<span class="nbadge g">New</span></a>
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

<!-- ══ MAIN ═════════════════════════════════════════════════ -->
<div class="main">
  <div class="topnav">
    <button class="mob-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">
      <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h14M3 10h14M3 14h14"/></svg>
    </button>
    <div class="bc">
      <a href="index.php">SalesOS</a><span class="sep">/</span>
      <span>HR &amp; Finance</span><span class="sep">/</span>
      <span class="cur">Employees</span>
    </div>
    <div class="tnr">
      <div class="ibtn">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 1a5 5 0 015 5v2l1.5 2.5h-13L3 8V6a5 5 0 015-5z"/><path d="M6 13a2 2 0 004 0"/></svg>
        <?php if ($unread_notifs > 0): ?><span class="dot"><?= min($unread_notifs,99) ?></span><?php endif; ?>
      </div>
      <button class="thm-btn" id="thm"><span id="thi">☀️</span></button>
      <a href="logout.php" class="logout-btn">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 14H3a1 1 0 01-1-1V3a1 1 0 011-1h3M11 11l3-3-3-3M14 8H6"/></svg>
        Logout
      </a>
    </div>
  </div>

  <div class="content">

    <!-- Page Header -->
    <div class="page-header">
      <div class="ph-left">
        <div class="ph-title">Employees</div>
        <div class="ph-sub">Manage your workforce — <?= $statsActive ?> active out of <?= $statsTotal ?> total employees</div>
      </div>
      <?php if ($canEdit): ?>
      <button class="btn btn-primary" onclick="openAdd()">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M8 1v14M1 8h14"/></svg>
        Add Employee
      </button>
      <?php endif; ?>
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

    <!-- Stats -->
    <div class="stats4">
      <div class="scard" style="--dl:.0s">
        <div class="sc-top">
          <div class="sc-icon" style="background:var(--ag)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--ac)" stroke-width="1.8"><circle cx="5" cy="5" r="3"/><circle cx="11" cy="5" r="3"/><path d="M1 14c0-2.76 1.8-5 4-5M15 14c0-2.76-1.8-5-4-5M7 14c0-2.76.9-5 2-5s2 2.24 2 5"/></svg></div>
          <span class="sc-badge" style="background:var(--ag);color:var(--ac)">Total</span>
        </div>
        <div class="sc-val"><?= $statsTotal ?></div>
        <div class="sc-lbl">All Employees</div>
      </div>
      <div class="scard" style="--dl:.08s">
        <div class="sc-top">
          <div class="sc-icon" style="background:var(--grd)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--gr)" stroke-width="1.8"><circle cx="8" cy="8" r="7"/><path d="M5 8l2 2 4-4"/></svg></div>
          <span class="sc-badge" style="background:var(--grd);color:var(--gr)">Active</span>
        </div>
        <div class="sc-val"><?= $statsActive ?></div>
        <div class="sc-lbl">Active Staff</div>
      </div>
      <div class="scard" style="--dl:.16s">
        <div class="sc-top">
          <div class="sc-icon" style="background:var(--pud)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--pu)" stroke-width="1.8"><path d="M3 10l3 3 4-5 4 4"/><circle cx="8" cy="5" r="3"/></svg></div>
          <span class="sc-badge" style="background:var(--pud);color:var(--pu)">Reps</span>
        </div>
        <div class="sc-val"><?= $statsReps ?></div>
        <div class="sc-lbl">Sales Representatives</div>
      </div>
      <div class="scard" style="--dl:.24s">
        <div class="sc-top">
          <div class="sc-icon" style="background:var(--red)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--re)" stroke-width="1.8"><path d="M2 2l12 12M14 2L2 14"/></svg></div>
          <span class="sc-badge" style="background:var(--red);color:var(--re)">Off</span>
        </div>
        <div class="sc-val"><?= $statsTerminated ?></div>
        <div class="sc-lbl">Terminated</div>
      </div>
    </div>

    <!-- Department filter chips -->
    <?php if ($deptStats && $deptStats->num_rows > 0): ?>
    <div class="dept-strip">
      <a class="dept-chip <?= !$fDept ? 'on' : '' ?>" href="employees.php<?= $search || $fStatus ? '?' . http_build_query(array_filter(['q'=>$search,'status'=>$fStatus])) : '' ?>">
        <span class="dot" style="background:var(--ac)"></span>All departments
      </a>
      <?php
      $dc = ['management'=>'var(--ac)','sales'=>'var(--gr)','warehouse'=>'var(--go)',
             'accounting'=>'var(--pu)','delivery'=>'var(--te)','hr'=>'var(--re)'];
      $deptStats->data_seek(0);
      while ($ds = $deptStats->fetch_assoc()):
        $d = $ds['department'];
        $dclr = $dc[$d] ?? 'var(--t2)';
        $active = ($fDept === $d) ? 'on' : '';
        $qs2 = http_build_query(array_filter(['q'=>$search,'dept'=>$d,'status'=>$fStatus]));
      ?>
      <a class="dept-chip <?= $active ?>" href="employees.php<?= $qs2 ? '?'.$qs2 : '' ?>">
        <span class="dot" style="background:<?= $dclr ?>"></span>
        <?= ucfirst($d) ?> <span style="color:var(--t3);font-size:.68rem">(<?= $ds['cnt'] ?>)</span>
      </a>
      <?php endwhile; ?>
    </div>
    <?php endif; ?>

    <!-- Toolbar -->
    <form method="GET" action="employees.php" id="filterForm">
      <div class="toolbar">
        <div class="searchbox">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="7" cy="7" r="4.5"/><path d="M11 11l3 3"/></svg>
          <input type="text" name="q" placeholder="Search by name, email, phone, city…" value="<?= htmlspecialchars($search) ?>" id="searchInp">
        </div>
        <?php if ($fDept): ?><input type="hidden" name="dept" value="<?= htmlspecialchars($fDept) ?>"><?php endif; ?>
        <select name="status" class="sel" onchange="this.form.submit()">
          <option value="">All Status</option>
          <option value="active"     <?= $fStatus==='active'?'selected':'' ?>>Active</option>
          <option value="inactive"   <?= $fStatus==='inactive'?'selected':'' ?>>Inactive</option>
          <option value="terminated" <?= $fStatus==='terminated'?'selected':'' ?>>Terminated</option>
        </select>
        <button type="submit" class="btn btn-primary" style="padding:8px 14px">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="7" cy="7" r="4.5"/><path d="M11 11l3 3"/></svg>
          Search
        </button>
        <?php if ($search || $fDept || $fStatus): ?>
        <a href="employees.php" class="btn btn-ghost" style="padding:8px 14px">Clear</a>
        <?php endif; ?>
        <div class="toolbar-right">
          <span class="count-info"><?= number_format($total) ?> result<?= $total !== 1 ? 's' : '' ?></span>
        </div>
      </div>
    </form>

    <!-- Employee Table -->
    <div class="panel">
      <?php if ($employees && $employees->num_rows > 0): ?>
      <div style="overflow-x:auto">
        <table class="dtbl">
          <thead>
            <tr>
              <th>Employee</th>
              <th>Department</th>
              <th>Phone</th>
              <th>City</th>
              <th>Hire Date</th>
              <th>Role</th>
              <th>Status</th>
              <?php if ($canEdit): ?><th>Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
          <?php
          $avatarGrads = [
            'management' => 'linear-gradient(135deg,#4F8EF7,#6BA3FF)',
            'sales'      => 'linear-gradient(135deg,#34D399,#059669)',
            'warehouse'  => 'linear-gradient(135deg,#F5A623,#D4880A)',
            'accounting' => 'linear-gradient(135deg,#A78BFA,#7C3AED)',
            'delivery'   => 'linear-gradient(135deg,#22D3EE,#0891B2)',
            'hr'         => 'linear-gradient(135deg,#F87171,#DC2626)',
          ];
          while ($emp = $employees->fetch_assoc()):
            $initials = strtoupper(substr($emp['first_name'],0,1) . substr($emp['last_name'],0,1));
            $grad = $avatarGrads[$emp['department']] ?? 'linear-gradient(135deg,#4F8EF7,#6BA3FF)';
            $hireDate = $emp['hire_date'] ? date('M j, Y', strtotime($emp['hire_date'])) : '—';
          ?>
          <tr>
            <td>
              <div class="emp-cell">
                <div class="emp-ava" style="background:<?= $grad ?>"><?= $initials ?></div>
                <div>
                  <div class="emp-name"><?= htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']) ?></div>
                  <div class="emp-email"><?= htmlspecialchars($emp['email'] ?? '—') ?></div>
                </div>
              </div>
            </td>
            <td><?= deptPill($emp['department']) ?></td>
            <td class="dim"><?= htmlspecialchars($emp['phone'] ?? '—') ?></td>
            <td class="dim"><?= htmlspecialchars($emp['city'] ?? '—') ?></td>
            <td class="dim"><?= $hireDate ?></td>
            <td>
              <?php if ($emp['is_representative']): ?>
                <span class="rep-badge">
                  <svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8" width="10" height="10"><path d="M2 8l2 2 3-4 3 3"/></svg>
                  Rep <?php if ($emp['commission_rate'] > 0): ?><span style="opacity:.7"><?= $emp['commission_rate'] ?>%</span><?php endif; ?>
                </span>
              <?php else: ?>
                <span style="color:var(--t3);font-size:.75rem"><?= ucfirst($emp['gender'] ?? '') ?></span>
              <?php endif; ?>
            </td>
            <td><?= statusPill($emp['status']) ?></td>
            <?php if ($canEdit): ?>
            <td>
              <div class="actions">
                <button class="act-btn edit" title="Edit"
                  onclick='openEdit(<?= json_encode($emp) ?>)'>
                  <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M11 2l3 3-9 9H2v-3z"/></svg>
                </button>
                <!-- Status toggle -->
                <form method="POST" style="display:inline">
                  <input type="hidden" name="action" value="status">
                  <input type="hidden" name="id" value="<?= $emp['id'] ?>">
                  <?php if ($emp['status'] === 'active'): ?>
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
                <button class="act-btn del" title="Terminate"
                  onclick="openConfirm(<?= $emp['id'] ?>, '<?= htmlspecialchars($emp['first_name'].' '.$emp['last_name'], ENT_QUOTES) ?>')">
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

      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
      <div class="pager">
        <span class="pager-info">
          Showing <?= $offset+1 ?>–<?= min($offset+$perPage,$total) ?> of <?= $total ?> employees
        </span>
        <div class="pager-btns">
          <?php if ($page > 1): ?>
            <a class="pg-btn" href="<?= $qs ?>page=<?= $page-1 ?>">‹</a>
          <?php endif; ?>
          <?php
          $start = max(1, $page - 2);
          $end   = min($totalPages, $page + 2);
          for ($p = $start; $p <= $end; $p++):
          ?>
            <a class="pg-btn <?= $p === $page ? 'on' : '' ?>" href="<?= $qs ?>page=<?= $p ?>"><?= $p ?></a>
          <?php endfor; ?>
          <?php if ($page < $totalPages): ?>
            <a class="pg-btn" href="<?= $qs ?>page=<?= $page+1 ?>">›</a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="5" cy="5" r="3"/><circle cx="11" cy="5" r="3"/><path d="M1 14c0-2.76 1.8-5 4-5M15 14c0-2.76-1.8-5-4-5M7 14c0-2.76.9-5 2-5s2 2.24 2 5"/></svg>
        </div>
        <div class="empty-title">No employees found</div>
        <div class="empty-sub">
          <?= ($search || $fDept || $fStatus) ? 'Try adjusting your filters or search term.' : 'Get started by adding your first employee.' ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- /content -->
</div><!-- /main -->


<?php if ($canEdit): ?>
<!-- ══ ADD / EDIT MODAL ══════════════════════════════════════ -->
<div class="overlay" id="empOverlay" onclick="closeModal(event,this)">
  <div class="modal" id="empModal">
    <div class="modal-head">
      <div>
        <div class="modal-title" id="modalTitle">Add Employee</div>
        <div class="modal-sub" id="modalSub">Fill in the details below</div>
      </div>
      <button class="close-btn" onclick="closeAll()">✕</button>
    </div>
    <form method="POST" action="employees.php" id="empForm">
      <input type="hidden" name="action" id="formAction" value="add">
      <input type="hidden" name="id" id="formId" value="">
      <div class="modal-body">

        <div class="fsection">Personal Information</div>
        <div class="frow">
          <div class="fgroup">
            <label class="flabel">First Name <span class="req">*</span></label>
            <input type="text" name="first_name" id="f_first_name" class="finput" placeholder="John" required>
          </div>
          <div class="fgroup">
            <label class="flabel">Last Name</label>
            <input type="text" name="last_name" id="f_last_name" class="finput" placeholder="Doe">
          </div>
        </div>
        <div class="frow">
          <div class="fgroup">
            <label class="flabel">Email</label>
            <input type="email" name="email" id="f_email" class="finput" placeholder="john@example.com">
          </div>
          <div class="fgroup">
            <label class="flabel">Phone</label>
            <input type="text" name="phone" id="f_phone" class="finput" placeholder="+1 555 000 0000">
          </div>
        </div>
        <div class="frow">
          <div class="fgroup">
            <label class="flabel">Date of Birth</label>
            <input type="date" name="date_of_birth" id="f_dob" class="finput">
          </div>
          <div class="fgroup">
            <label class="flabel">Gender</label>
            <select name="gender" id="f_gender" class="fselect">
              <option value="male">Male</option>
              <option value="female">Female</option>
              <option value="other">Other</option>
            </select>
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

        <div class="fsection">Employment Details</div>
        <div class="frow">
          <div class="fgroup">
            <label class="flabel">Department <span class="req">*</span></label>
            <select name="department" id="f_department" class="fselect">
              <?php foreach ($depts as $d): ?>
              <option value="<?= $d ?>"><?= ucfirst($d) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fgroup">
            <label class="flabel">Status</label>
            <select name="status" id="f_status" class="fselect">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="terminated">Terminated</option>
            </select>
          </div>
        </div>
        <div class="frow">
          <div class="fgroup">
            <label class="flabel">Hire Date <span class="req">*</span></label>
            <input type="date" name="hire_date" id="f_hire_date" class="finput" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="fgroup">
            <label class="flabel">Commission Rate (%)</label>
            <input type="number" name="commission_rate" id="f_commission" class="finput" min="0" max="100" step="0.01" placeholder="0.00">
          </div>
        </div>
        <div class="frow full">
          <div class="fgroup">
            <label class="fcheck">
              <input type="checkbox" name="is_representative" id="f_is_rep">
              Mark as Sales Representative
            </label>
          </div>
        </div>

      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="closeAll()">Cancel</button>
        <button type="submit" class="btn btn-primary" id="empSubmitBtn">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M8 1v14M1 8h14"/></svg>
          <span id="empSubmitTxt">Add Employee</span>
        </button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($canDelete): ?>
<!-- ══ CONFIRM MODAL ════════════════════════════════════════ -->
<div class="overlay" id="confirmOverlay" onclick="closeModal(event,this)">
  <div class="modal confirm-modal">
    <div class="modal-body" style="padding:32px 28px 20px">
      <div class="confirm-icon">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 4h12M5 4V2h6v2M6 7v5M10 7v5M3 4l1 10h8l1-10"/></svg>
      </div>
      <div class="confirm-title">Terminate Employee?</div>
      <div class="confirm-sub" id="confirmMsg">This will mark the employee as terminated. This action can be undone by editing the employee.</div>
    </div>
    <form method="POST" action="employees.php">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" id="confirmId" value="">
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="closeAll()">Cancel</button>
        <button type="submit" class="btn btn-danger">Terminate</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
// Theme
const html=document.documentElement,thi=document.getElementById('thi');
function applyTheme(t){html.dataset.theme=t;thi.textContent=t==='dark'?'☀️':'🌙';}
document.getElementById('thm').onclick=()=>{
  const nt=html.dataset.theme==='dark'?'light':'dark';
  applyTheme(nt);localStorage.setItem('pos_theme',nt);
};
const sv=localStorage.getItem('pos_theme');if(sv)applyTheme(sv);

// Sidebar mobile
document.addEventListener('click',e=>{
  const sb=document.getElementById('sidebar');
  if(window.innerWidth<=900&&!sb.contains(e.target)&&!e.target.closest('.mob-btn'))
    sb.classList.remove('open');
});

// Modal helpers
function closeModal(e,el){if(e.target===el)closeAll();}
function closeAll(){
  document.querySelectorAll('.overlay').forEach(o=>o.classList.remove('open'));
  document.body.style.overflow='';
}
function openOverlay(id){
  document.getElementById(id).classList.add('open');
  document.body.style.overflow='hidden';
}

<?php if ($canEdit): ?>
// Open ADD modal
function openAdd(){
  document.getElementById('modalTitle').textContent='Add Employee';
  document.getElementById('modalSub').textContent='Fill in the details below';
  document.getElementById('formAction').value='add';
  document.getElementById('formId').value='';
  document.getElementById('empForm').reset();
  document.getElementById('f_hire_date').value='<?= date('Y-m-d') ?>';
  document.getElementById('empSubmitTxt').textContent='Add Employee';
  openOverlay('empOverlay');
}

// Open EDIT modal
function openEdit(emp){
  document.getElementById('modalTitle').textContent='Edit Employee';
  document.getElementById('modalSub').textContent='#' + emp.id + ' — Update employee details';
  document.getElementById('formAction').value='edit';
  document.getElementById('formId').value=emp.id;
  document.getElementById('f_first_name').value=emp.first_name||'';
  document.getElementById('f_last_name').value=emp.last_name||'';
  document.getElementById('f_email').value=emp.email||'';
  document.getElementById('f_phone').value=emp.phone||'';
  document.getElementById('f_dob').value=emp.date_of_birth||'';
  document.getElementById('f_gender').value=emp.gender||'other';
  document.getElementById('f_city').value=emp.city||'';
  document.getElementById('f_address').value=emp.address||'';
  document.getElementById('f_department').value=emp.department||'sales';
  document.getElementById('f_status').value=emp.status||'active';
  document.getElementById('f_hire_date').value=emp.hire_date||'';
  document.getElementById('f_commission').value=emp.commission_rate||'0';
  document.getElementById('f_is_rep').checked=emp.is_representative==1||emp.is_representative===true;
  document.getElementById('empSubmitTxt').textContent='Save Changes';
  openOverlay('empOverlay');
}
<?php endif; ?>

<?php if ($canDelete): ?>
function openConfirm(id,name){
  document.getElementById('confirmId').value=id;
  document.getElementById('confirmMsg').textContent=
    'This will mark "'+name+'" as terminated. You can reactivate them later.';
  openOverlay('confirmOverlay');
}
<?php endif; ?>

// Auto-submit search on clear
document.getElementById('searchInp').addEventListener('keydown',function(e){
  if(e.key==='Escape'){this.value='';this.form.submit();}
});

// Auto-dismiss flash
setTimeout(()=>{
  const f=document.querySelector('.flash');
  if(f){f.style.transition='opacity .5s';f.style.opacity='0';setTimeout(()=>f.remove(),500);}
},4000);
</script>
</body>
</html>
