<?php
require_once 'connection.php';
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$user  = htmlspecialchars($_SESSION['username'] ?? 'Admin');
$role  = htmlspecialchars(ucfirst($_SESSION['role'] ?? 'admin'));
$ava   = strtoupper(substr($user, 0, 1));
$uid   = (int)$_SESSION['user_id'];

$canEdit   = in_array($_SESSION['role'], ['admin', 'accountant']);
$canDelete = ($_SESSION['role'] === 'admin');
$canApprove= in_array($_SESSION['role'], ['admin', 'accountant']);

// ── HELPERS ───────────────────────────────────────────────────
function fmt($v) {
    if ($v >= 1000000) return '$' . number_format($v/1000000,2) . 'M';
    if ($v >= 1000)    return '$' . number_format($v/1000,1) . 'k';
    return '$' . number_format($v,2);
}
function fmtFull($v) { return '$' . number_format($v,2); }
function monthName($m) {
    return date('F', mktime(0,0,0,(int)$m,1));
}

// ── HANDLE POST ───────────────────────────────────────────────
$flash = $flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $action = $_POST['action'] ?? '';

    // ADD SALARY
    if ($action === 'add') {
        $emp_id     = (int)($_POST['employee_id'] ?? 0);
        $base       = floatval($_POST['base_salary'] ?? 0);
        $bonus      = floatval($_POST['bonus'] ?? 0);
        $net        = floatval($_POST['net_salary'] ?? 0);
        $pay_month  = (int)($_POST['pay_month'] ?? date('n'));
        $pay_year   = (int)($_POST['pay_year']  ?? date('Y'));
        $pay_date   = trim($_POST['payment_date'] ?? '') ?: null;
        $approved_by= $canApprove ? $uid : null;
        $notes      = trim($_POST['notes'] ?? '') ?: null;

        if ($emp_id && $base > 0) {
            $stmt = $conn->prepare("INSERT INTO salaries
                (employee_id,base_salary,bonus,net_salary,pay_month,pay_year,payment_date,approved_by,notes)
                VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('idddiiisi',
                $emp_id,$base,$bonus,$net,$pay_month,$pay_year,$pay_date,$approved_by,$notes);
            if ($stmt->execute()) {
                $new_id = $conn->insert_id;
                $conn->query("INSERT INTO audit_logs (user_id,user_name,action,table_name,record_id,description)
                    VALUES ($uid,'$user','CREATE','salaries',$new_id,
                    'Salary record created for employee #$emp_id — {$pay_month}/{$pay_year}')");
                $flash = "Salary record added successfully."; $flashType = 'ok';
            } else {
                $esc = $conn->real_escape_string($conn->error);
                if (strpos($conn->error,'uq_salary_period') !== false)
                    $flash = "A salary record already exists for this employee in that month/year.";
                else
                    $flash = "Error: " . htmlspecialchars($conn->error);
                $flashType = 'err';
            }
            $stmt->close();
        } else { $flash = "Employee and base salary are required."; $flashType = 'err'; }
    }

    // EDIT SALARY
    elseif ($action === 'edit') {
        $id        = (int)($_POST['id'] ?? 0);
        $base      = floatval($_POST['base_salary'] ?? 0);
        $bonus     = floatval($_POST['bonus'] ?? 0);
        $net       = floatval($_POST['net_salary'] ?? 0);
        $pay_date  = trim($_POST['payment_date'] ?? '') ?: null;
        $notes     = trim($_POST['notes'] ?? '') ?: null;

        if ($id && $base > 0) {
            $stmt = $conn->prepare("UPDATE salaries SET
                base_salary=?,bonus=?,net_salary=?,payment_date=?,notes=? WHERE id=?");
            $stmt->bind_param('dddisi',$base,$bonus,$net,$pay_date,$notes,$id);
            if ($stmt->execute()) {
                $conn->query("INSERT INTO audit_logs (user_id,user_name,action,table_name,record_id,description)
                    VALUES ($uid,'$user','UPDATE','salaries',$id,'Salary #$id updated')");
                $flash = "Salary record updated successfully."; $flashType = 'ok';
            } else { $flash = "Error: " . htmlspecialchars($conn->error); $flashType = 'err'; }
            $stmt->close();
        }
    }

    // MARK PAID
    elseif ($action === 'mark_paid') {
        $id       = (int)($_POST['id'] ?? 0);
        $pay_date = date('Y-m-d');
        if ($id) {
            $conn->query("UPDATE salaries SET payment_date='$pay_date', approved_by=$uid WHERE id=$id");
            $conn->query("INSERT INTO audit_logs (user_id,user_name,action,table_name,record_id,description)
                VALUES ($uid,'$user','UPDATE','salaries',$id,'Salary #$id marked as paid')");
            $flash = "Salary marked as paid."; $flashType = 'ok';
        }
    }

    // DELETE
    elseif ($action === 'delete' && $canDelete) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $conn->query("DELETE FROM salaries WHERE id=$id");
            $conn->query("INSERT INTO audit_logs (user_id,user_name,action,table_name,record_id,description)
                VALUES ($uid,'$user','DELETE','salaries',$id,'Salary record #$id deleted')");
            $flash = "Salary record deleted."; $flashType = 'warn';
        }
    }

    header("Location: salaries.php" . ($flash ? "?flash=".urlencode($flash)."&ft=$flashType" : ''));
    exit;
}

if (isset($_GET['flash'])) { $flash = urldecode($_GET['flash']); $flashType = $_GET['ft'] ?? 'ok'; }

// ── FILTERS ───────────────────────────────────────────────────
$search    = trim($_GET['q'] ?? '');
$fMonth    = (int)($_GET['month'] ?? 0);
$fYear     = (int)($_GET['year']  ?? 0);
$fDept     = $_GET['dept'] ?? '';
$fPaid     = $_GET['paid'] ?? '';
$page      = max(1,(int)($_GET['page'] ?? 1));
$perPage   = 15;
$offset    = ($page-1)*$perPage;

$where = "WHERE 1=1";
if ($search) {
    $s = $conn->real_escape_string($search);
    $where .= " AND (e.first_name LIKE '%$s%' OR e.last_name LIKE '%$s%'
                     OR e.email LIKE '%$s%' OR e.department LIKE '%$s%')";
}
if ($fMonth) $where .= " AND s.pay_month=$fMonth";
if ($fYear)  $where .= " AND s.pay_year=$fYear";
if ($fDept)  $where .= " AND e.department='" . $conn->real_escape_string($fDept) . "'";
if ($fPaid === '1')  $where .= " AND s.payment_date IS NOT NULL";
if ($fPaid === '0')  $where .= " AND s.payment_date IS NULL";

$baseQuery = "FROM salaries s
    JOIN employees e ON e.id = s.employee_id
    LEFT JOIN users u ON u.id = s.approved_by
    $where";

$totalR  = $conn->query("SELECT COUNT(*) AS c $baseQuery");
$total   = $totalR ? (int)$totalR->fetch_assoc()['c'] : 0;
$totalPages = ceil($total/$perPage);

$salaries = $conn->query("SELECT s.*, 
    e.first_name, e.last_name, e.email AS emp_email, e.department,
    e.is_representative, e.commission_rate,
    u.username AS approver_name
    $baseQuery
    ORDER BY s.pay_year DESC, s.pay_month DESC, s.created_at DESC
    LIMIT $perPage OFFSET $offset");

// ── SUMMARY STATS ─────────────────────────────────────────────
$curMonth = (int)date('n');
$curYear  = (int)date('Y');

function fetchVal($conn,$sql,$col='v'){
    $r=$conn->query($sql); if(!$r) return 0;
    $row=$r->fetch_assoc(); return $row[$col]??0;
}

$totalPayroll = (float)fetchVal($conn,
    "SELECT COALESCE(SUM(net_salary),0) AS v FROM salaries
     WHERE pay_month=$curMonth AND pay_year=$curYear");
$totalPaid = (float)fetchVal($conn,
    "SELECT COALESCE(SUM(net_salary),0) AS v FROM salaries
     WHERE pay_month=$curMonth AND pay_year=$curYear AND payment_date IS NOT NULL");
$totalUnpaid = $totalPayroll - $totalPaid;
$paidCount   = (int)fetchVal($conn,
    "SELECT COUNT(*) AS v FROM salaries
     WHERE pay_month=$curMonth AND pay_year=$curYear AND payment_date IS NOT NULL");
$unpaidCount = (int)fetchVal($conn,
    "SELECT COUNT(*) AS v FROM salaries
     WHERE pay_month=$curMonth AND pay_year=$curYear AND payment_date IS NULL");
$avgSalary = (float)fetchVal($conn,
    "SELECT COALESCE(AVG(net_salary),0) AS v FROM salaries
     WHERE pay_month=$curMonth AND pay_year=$curYear");
$totalBonus = (float)fetchVal($conn,
    "SELECT COALESCE(SUM(bonus),0) AS v FROM salaries
     WHERE pay_month=$curMonth AND pay_year=$curYear");

// Year-to-date
$ytdPayroll = (float)fetchVal($conn,
    "SELECT COALESCE(SUM(net_salary),0) AS v FROM salaries WHERE pay_year=$curYear");

// Department payroll breakdown this month
$deptPayroll = $conn->query("SELECT e.department,
    COUNT(*) AS cnt, COALESCE(SUM(s.net_salary),0) AS total
    FROM salaries s JOIN employees e ON e.id=s.employee_id
    WHERE s.pay_month=$curMonth AND s.pay_year=$curYear
    GROUP BY e.department ORDER BY total DESC");
$deptRows = [];
$deptMax  = 0;
if ($deptPayroll) {
    while ($dr = $deptPayroll->fetch_assoc()) {
        $deptRows[] = $dr;
        if ((float)$dr['total'] > $deptMax) $deptMax = (float)$dr['total'];
    }
}

// Monthly payroll trend (last 8 months)
$trendRows = [];
$trendRes  = $conn->query("SELECT pay_year, pay_month,
    COALESCE(SUM(net_salary),0) AS total,
    COALESCE(SUM(bonus),0) AS bonuses,
    COUNT(*) AS records
    FROM salaries
    GROUP BY pay_year, pay_month
    ORDER BY pay_year DESC, pay_month DESC LIMIT 8");
if ($trendRes) {
    while ($tr = $trendRes->fetch_assoc()) $trendRows[] = $tr;
    $trendRows = array_reverse($trendRows);
}
$trendMax = count($trendRows) ? max(array_column($trendRows,'total')) : 1;
if ($trendMax == 0) $trendMax = 1;

// Active employees (for modal dropdown)
$activeEmployees = $conn->query("SELECT id, first_name, last_name, department, 
    commission_rate, is_representative
    FROM employees WHERE status='active' ORDER BY first_name, last_name");

// Year options
$yearRes  = $conn->query("SELECT DISTINCT pay_year FROM salaries ORDER BY pay_year DESC");
$yearOpts = []; if ($yearRes) while ($yr = $yearRes->fetch_assoc()) $yearOpts[] = $yr['pay_year'];
if (!in_array($curYear,$yearOpts)) array_unshift($yearOpts,$curYear);

// Dept colors
$deptColors = [
    'management' => ['var(--ac)','var(--ag)'],
    'sales'      => ['var(--gr)','var(--grd)'],
    'warehouse'  => ['var(--go)','var(--god)'],
    'accounting' => ['var(--pu)','var(--pud)'],
    'delivery'   => ['var(--te)','var(--ted)'],
    'hr'         => ['var(--re)','var(--red)'],
];
function deptColor($d) { global $deptColors; return $deptColors[$d] ?? ['var(--t2)','var(--bg3)']; }
function deptPill($d) {
    [$c,$bg] = deptColor($d);
    return '<span class="pill dept" style="color:'.$c.';background:'.$bg.'">'.ucfirst($d).'</span>';
}
function avatarGrad($d) {
    $map = ['management'=>'linear-gradient(135deg,#4F8EF7,#6BA3FF)',
            'sales'=>'linear-gradient(135deg,#34D399,#059669)',
            'warehouse'=>'linear-gradient(135deg,#F5A623,#D4880A)',
            'accounting'=>'linear-gradient(135deg,#A78BFA,#7C3AED)',
            'delivery'=>'linear-gradient(135deg,#22D3EE,#0891B2)',
            'hr'=>'linear-gradient(135deg,#F87171,#DC2626)'];
    return $map[$d] ?? 'linear-gradient(135deg,#4F8EF7,#6BA3FF)';
}

// Sidebar badges
$unread_notifs       = (int)($conn->query("SELECT COUNT(*) AS v FROM notifications WHERE recipient_id=$uid AND is_read=FALSE")->fetch_assoc()['v'] ?? 0);
$pending_sales_badge = (int)($conn->query("SELECT COUNT(*) AS v FROM sales WHERE approval_status='pending'")->fetch_assoc()['v'] ?? 0);
$debt_badge          = (int)($conn->query("SELECT COUNT(*) AS v FROM debts WHERE status IN ('active','partially_paid','overdue')")->fetch_assoc()['v'] ?? 0);
$low_stock_count     = (int)($conn->query("SELECT COUNT(*) AS v FROM vw_low_stock")->fetch_assoc()['v'] ?? 0);

function buildQS($overrides=[]) {
    $p = array_merge($_GET,$overrides); unset($p['page']);
    $qs = http_build_query(array_filter($p,fn($v)=>$v!==''&&$v!==0&&$v!=='0'));
    return $qs ? "?$qs&" : "?";
}
$qs = buildQS();
$months = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

// ── VARIABLES FOR SIDEBAR & TOPNAV ─────────────────────────────
$current_page = 'salaries';        // matches the page key in sidebar
$page_title   = 'Salaries';        // for <title>
$breadcrumbs  = [
    ['label' => 'HR & Finance'],    // no URL -> just text
    ['label' => 'Salaries']         // last item = current page
];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SalesOS — <?= $page_title ?></title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ── All CSS from your original salaries.php (unchanged) ── */
[data-theme="dark"]{
  --bg:#0D0F14;--bg2:#13161D;--bg3:#191D27;--bg4:#1F2433;
  --br:rgba(255,255,255,.06);--br2:rgba(255,255,255,.11);
  --tx:#EDF0F7;--t2:#7B82A0;--t3:#3E4460;
  --ac:#4F8EF7;--ac2:#6BA3FF;--ag:rgba(79,142,247,.15);
  --go:#F5A623;--god:rgba(245,166,35,.12);--gob:rgba(245,166,35,.2);
  --gr:#34D399;--grd:rgba(52,211,153,.12);
  --re:#F87171;--red:rgba(248,113,113,.12);
  --pu:#A78BFA;--pud:rgba(167,139,250,.12);
  --te:#22D3EE;--ted:rgba(34,211,238,.12);
  --sh:0 2px 16px rgba(0,0,0,.35);--sh2:0 8px 32px rgba(0,0,0,.4);
}
[data-theme="light"]{
  --bg:#F0EEE9;--bg2:#FFFFFF;--bg3:#F7F5F1;--bg4:#EDE9E2;
  --br:rgba(0,0,0,.07);--br2:rgba(0,0,0,.13);
  --tx:#1A1C27;--t2:#6B7280;--t3:#C0C5D4;
  --ac:#3B7DD8;--ac2:#2563EB;--ag:rgba(59,125,216,.12);
  --go:#D97706;--god:rgba(217,119,6,.1);--gob:rgba(217,119,6,.18);
  --gr:#059669;--grd:rgba(5,150,105,.1);
  --re:#DC2626;--red:rgba(220,38,38,.1);
  --pu:#7C3AED;--pud:rgba(124,58,237,.1);
  --te:#0891B2;--ted:rgba(8,145,178,.1);
  --sh:0 2px 12px rgba(0,0,0,.07);--sh2:0 8px 28px rgba(0,0,0,.1);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{background:var(--bg);color:var(--tx);font-family:'Plus Jakarta Sans',sans-serif;font-size:.875rem;min-height:100vh;display:flex;transition:background .4s,color .4s}

/* SIDEBAR (styles now in sidebar.php) – but keep any missing ones */
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
.nbadge.g{background:var(--gr)}.nbadge.b{background:var(--ac)}
.sfooter{margin-top:auto;padding:14px 12px;border-top:1px solid var(--br)}
.ucard{display:flex;align-items:center;gap:10px;padding:10px;background:var(--bg3);border:1px solid var(--br);border-radius:11px;cursor:pointer;transition:background .15s}
.ucard:hover{background:var(--bg4)}
.ava{width:34px;height:34px;border-radius:9px;flex-shrink:0;background:linear-gradient(135deg,var(--ac),var(--ac2));display:flex;align-items:center;justify-content:center;font-size:.9rem;font-weight:800;color:#fff}
.uinfo{flex:1;min-width:0}
.uname{font-size:.8rem;font-weight:700;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.urole{font-size:.68rem;color:var(--ac);font-weight:500;margin-top:1px}

/* MAIN */
.main{margin-left:248px;flex:1;display:flex;flex-direction:column;min-height:100vh}
.topnav{height:58px;background:var(--bg2);border-bottom:1px solid var(--br);position:sticky;top:0;z-index:50;display:flex;align-items:center;padding:0 28px;gap:14px}
.mob-btn{display:none;background:none;border:none;cursor:pointer;color:var(--t2);padding:4px}
.mob-btn svg{width:20px;height:20px}
.bc{display:flex;align-items:center;gap:7px;font-size:.75rem;color:var(--t3)}
.bc .sep{opacity:.4}.bc .cur{color:var(--tx);font-weight:600}
.bc a{color:var(--t2);text-decoration:none}.bc a:hover{color:var(--tx)}
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

/* CONTENT */
.content{padding:28px;display:flex;flex-direction:column;gap:22px}

/* Page header */
.page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}
.ph-title{font-size:1.5rem;font-weight:800;color:var(--tx);letter-spacing:-.03em}
.ph-sub{font-size:.78rem;color:var(--t2);margin-top:4px}
.ph-actions{display:flex;gap:8px;align-items:center}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:10px;font-family:inherit;font-size:.8rem;font-weight:700;cursor:pointer;border:none;transition:all .2s;text-decoration:none}
.btn-primary{background:var(--ac);color:#fff;box-shadow:0 4px 14px var(--ag)}
.btn-primary:hover{background:var(--ac2);transform:translateY(-1px)}
.btn-ghost{background:var(--bg3);border:1px solid var(--br);color:var(--t2)}
.btn-ghost:hover{border-color:var(--br2);color:var(--tx)}
.btn svg{width:15px;height:15px}

/* Flash */
.flash{display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:11px;font-size:.82rem;font-weight:500;animation:fadeUp .4s cubic-bezier(.16,1,.3,1)}
.flash.ok{background:var(--grd);border:1px solid rgba(52,211,153,.2);color:var(--gr)}
.flash.warn{background:var(--god);border:1px solid rgba(245,166,35,.2);color:var(--go)}
.flash.err{background:var(--red);border:1px solid rgba(248,113,113,.2);color:var(--re)}
.flash svg{width:15px;height:15px;flex-shrink:0}
@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}

/* Month selector banner */
.month-banner{
  background:linear-gradient(135deg,var(--ac),var(--ac2));
  border-radius:16px;padding:22px 28px;
  display:flex;align-items:center;justify-content:space-between;gap:16px;
  box-shadow:0 8px 32px var(--ag);
  animation:fadeUp .4s cubic-bezier(.16,1,.3,1) both;
}
.mb-left .mb-label{font-size:.75rem;color:rgba(255,255,255,.7);font-weight:600;letter-spacing:.06em;text-transform:uppercase;margin-bottom:4px}
.mb-left .mb-period{font-size:1.6rem;font-weight:800;color:#fff;letter-spacing:-.03em}
.mb-left .mb-sub{font-size:.75rem;color:rgba(255,255,255,.7);margin-top:3px}
.mb-right{display:flex;gap:10px;align-items:center}
.mb-nav{width:36px;height:36px;border-radius:9px;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.25);color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;text-decoration:none;transition:background .15s;font-size:1rem;font-weight:700}
.mb-nav:hover{background:rgba(255,255,255,.28)}
.mb-stats{display:flex;gap:20px}
.mb-stat{text-align:center}
.mb-val{font-size:1.2rem;font-weight:800;color:#fff}
.mb-lbl{font-size:.67rem;color:rgba(255,255,255,.7);margin-top:2px}
.mb-div{width:1px;background:rgba(255,255,255,.2);align-self:stretch}

/* Stats grid */
.stats6{display:grid;grid-template-columns:repeat(6,1fr);gap:12px}
.scard{background:var(--bg2);border:1px solid var(--br);border-radius:13px;padding:16px 18px;animation:fadeUp .4s cubic-bezier(.16,1,.3,1) both;transition:transform .2s,box-shadow .2s}
.scard:hover{transform:translateY(-2px);box-shadow:var(--sh2)}
.sc-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.sc-icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center}
.sc-icon svg{width:16px;height:16px}
.sc-val{font-size:1.3rem;font-weight:800;color:var(--tx);letter-spacing:-.04em;line-height:1}
.sc-lbl{font-size:.68rem;color:var(--t2);margin-top:4px;font-weight:500}

/* 2-col layout */
.g2{display:grid;grid-template-columns:2fr 1fr;gap:18px}

/* Panel */
.panel{background:var(--bg2);border:1px solid var(--br);border-radius:14px;overflow:hidden;animation:fadeUp .4s .1s cubic-bezier(.16,1,.3,1) both}
.phead{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--br)}
.ptitle{font-size:.9rem;font-weight:700;color:var(--tx)}
.psub{font-size:.68rem;color:var(--t3);margin-top:3px;font-weight:500}
.pbody{padding:16px 20px}

/* Trend sparkline */
.sparkline{display:flex;align-items:flex-end;gap:5px;height:56px}
.spk{flex:1;border-radius:4px 4px 0 0;background:var(--ag);border:1px solid rgba(79,142,247,.2);transition:background .2s;cursor:default;position:relative;min-height:4px}
.spk:hover{background:var(--ac)}
.spk::after{content:attr(title);position:absolute;bottom:calc(100% + 6px);left:50%;transform:translateX(-50%);background:var(--bg4);border:1px solid var(--br2);border-radius:7px;padding:4px 8px;font-size:.62rem;color:var(--tx);white-space:nowrap;opacity:0;pointer-events:none;transition:opacity .15s;font-family:inherit}
.spk:hover::after{opacity:1}
.spk.curr{background:linear-gradient(180deg,var(--ac),var(--ac2));border-color:rgba(79,142,247,.5)}

/* Dept breakdown */
.dept-row{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--br)}
.dept-row:last-child{border-bottom:none}
.dept-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.dept-name{font-size:.78rem;font-weight:600;color:var(--tx);flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dept-bar-wrap{width:80px;flex-shrink:0}
.dept-track{height:5px;background:var(--br);border-radius:3px;overflow:hidden}
.dept-fill{height:100%;border-radius:3px;transition:width .8s cubic-bezier(.16,1,.3,1)}
.dept-amt{font-size:.73rem;font-weight:700;flex-shrink:0;min-width:60px;text-align:right}
.dept-cnt{font-size:.67rem;color:var(--t3);flex-shrink:0}

/* Toolbar */
.toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.searchbox{display:flex;align-items:center;gap:8px;background:var(--bg2);border:1px solid var(--br);border-radius:10px;padding:8px 14px;flex:1;min-width:180px;max-width:280px;transition:border-color .2s}
.searchbox:focus-within{border-color:var(--ac)}
.searchbox svg{width:14px;height:14px;color:var(--t3);flex-shrink:0}
.searchbox input{background:none;border:none;outline:none;font-family:inherit;font-size:.82rem;color:var(--tx);width:100%}
.searchbox input::placeholder{color:var(--t3)}
.sel{background:var(--bg2);border:1px solid var(--br);border-radius:10px;padding:8px 12px;font-family:inherit;font-size:.8rem;color:var(--tx);cursor:pointer;outline:none;transition:border-color .2s}
.sel:focus{border-color:var(--ac)}
.toolbar-right{margin-left:auto;display:flex;align-items:center;gap:8px}
.count-lbl{font-size:.75rem;color:var(--t2)}

/* Table */
.dtbl{width:100%;border-collapse:collapse}
.dtbl th{font-size:.64rem;color:var(--t3);text-transform:uppercase;letter-spacing:.1em;padding:11px 16px;text-align:left;border-bottom:1px solid var(--br);font-weight:700;white-space:nowrap}
.dtbl td{padding:12px 16px;font-size:.81rem;border-bottom:1px solid var(--br);vertical-align:middle}
.dtbl tr:last-child td{border-bottom:none}
.dtbl tbody tr{transition:background .12s}
.dtbl tbody tr:hover td{background:rgba(255,255,255,.022)}
[data-theme="light"] .dtbl tbody tr:hover td{background:rgba(0,0,0,.02)}
.emp-cell{display:flex;align-items:center;gap:10px}
.emp-ava{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.78rem;color:#fff;flex-shrink:0}
.emp-name{font-size:.83rem;font-weight:700;color:var(--tx)}
.emp-dept{font-size:.68rem;color:var(--t2);margin-top:1px}
.num{font-size:.85rem;font-weight:700;color:var(--tx);font-variant-numeric:tabular-nums}
.num.muted{color:var(--t2);font-weight:500}
.num.accent{color:var(--ac)}
.num.green{color:var(--gr)}

/* Pills */
.pill{display:inline-flex;align-items:center;padding:3px 10px;border-radius:100px;font-size:.67rem;font-weight:700;white-space:nowrap}
.pill.ok  {background:var(--grd);color:var(--gr)}
.pill.warn{background:var(--god);color:var(--go)}
.pill.err {background:var(--red);color:var(--re)}
.pill.info{background:var(--ag); color:var(--ac)}
.pill.pu  {background:var(--pud);color:var(--pu)}
.pill.nt  {background:var(--bg3);color:var(--t3)}
.pill.dept{font-size:.67rem}

/* Actions */
.actions{display:flex;align-items:center;gap:4px}
.act-btn{width:29px;height:29px;border-radius:7px;border:1px solid var(--br);background:transparent;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .15s;color:var(--t2)}
.act-btn:hover{border-color:var(--br2);color:var(--tx)}
.act-btn.edit:hover{background:var(--ag);border-color:rgba(79,142,247,.3);color:var(--ac)}
.act-btn.pay:hover{background:var(--grd);border-color:rgba(52,211,153,.3);color:var(--gr)}
.act-btn.del:hover{background:var(--red);border-color:rgba(248,113,113,.3);color:var(--re)}
.act-btn svg{width:13px;height:13px}

/* Pagination */
.pager{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-top:1px solid var(--br)}
.pager-info{font-size:.75rem;color:var(--t2)}
.pager-btns{display:flex;gap:4px}
.pg-btn{min-width:32px;height:32px;padding:0 8px;border-radius:8px;border:1px solid var(--br);background:var(--bg3);color:var(--t2);font-family:inherit;font-size:.78rem;font-weight:600;cursor:pointer;transition:all .15s;text-decoration:none;display:flex;align-items:center;justify-content:center}
.pg-btn:hover{border-color:var(--br2);color:var(--tx)}
.pg-btn.on{background:var(--ac);border-color:var(--ac);color:#fff}

/* Empty */
.empty-state{padding:52px 20px;text-align:center}
.empty-icon{width:50px;height:50px;border-radius:13px;background:var(--bg3);border:1px solid var(--br);margin:0 auto 12px;display:flex;align-items:center;justify-content:center}
.empty-icon svg{width:22px;height:22px;color:var(--t3)}
.empty-title{font-size:.92rem;font-weight:700;color:var(--tx);margin-bottom:4px}
.empty-sub{font-size:.77rem;color:var(--t2)}

/* MODAL */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:200;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .25s;backdrop-filter:blur(4px)}
.overlay.open{opacity:1;pointer-events:all}
.modal{background:var(--bg2);border:1px solid var(--br2);border-radius:18px;width:100%;max-width:580px;max-height:90vh;overflow-y:auto;box-shadow:var(--sh2);transform:translateY(24px) scale(.97);transition:transform .3s cubic-bezier(.16,1,.3,1)}
.overlay.open .modal{transform:none}
.modal-head{display:flex;align-items:center;justify-content:space-between;padding:20px 24px;border-bottom:1px solid var(--br);position:sticky;top:0;background:var(--bg2);z-index:1}
.modal-title{font-size:1rem;font-weight:800;color:var(--tx)}
.modal-sub{font-size:.72rem;color:var(--t2);margin-top:2px}
.close-btn{width:30px;height:30px;border-radius:8px;border:1px solid var(--br);background:transparent;color:var(--t2);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s;font-size:1rem}
.close-btn:hover{background:var(--red);border-color:rgba(248,113,113,.3);color:var(--re)}
.modal-body{padding:22px 24px}
.frow{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.frow.full{grid-template-columns:1fr}
.frow.three{grid-template-columns:1fr 1fr 1fr}
.fgroup{display:flex;flex-direction:column;gap:5px;margin-bottom:2px}
.flabel{font-size:.72rem;font-weight:600;color:var(--t2)}
.flabel .req{color:var(--re)}
.finput,.fselect,.ftextarea{width:100%;background:var(--bg3);border:1px solid var(--br);border-radius:9px;padding:9px 13px;font-family:inherit;font-size:.82rem;color:var(--tx);outline:none;transition:border-color .2s}
.finput:focus,.fselect:focus,.ftextarea:focus{border-color:var(--ac)}
.finput::placeholder,.ftextarea::placeholder{color:var(--t3)}
.ftextarea{resize:vertical;min-height:64px}
.fsection{font-size:.68rem;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.1em;padding:12px 0 8px;border-bottom:1px solid var(--br);margin-bottom:12px}
.calc-hint{font-size:.7rem;color:var(--t2);margin-top:4px}
.calc-hint strong{color:var(--gr)}
.modal-foot{padding:14px 24px;border-top:1px solid var(--br);display:flex;align-items:center;justify-content:flex-end;gap:10px;position:sticky;bottom:0;background:var(--bg2)}
.btn-danger{background:var(--red);border:1px solid rgba(248,113,113,.3);color:var(--re)}
.btn-danger:hover{background:rgba(248,113,113,.2)}

/* Confirm modal */
.confirm-modal{max-width:390px}
.cicon{width:50px;height:50px;border-radius:13px;display:flex;align-items:center;justify-content:center;margin:0 auto 14px}
.cicon svg{width:22px;height:22px}
.ctitle{font-size:1rem;font-weight:800;text-align:center;margin-bottom:6px}
.csub{font-size:.79rem;color:var(--t2);text-align:center;line-height:1.55}

/* Slip modal */
.slip-modal{max-width:480px}
.slip-header{background:linear-gradient(135deg,var(--ac),var(--ac2));border-radius:12px;padding:20px 22px;margin-bottom:18px;display:flex;justify-content:space-between;align-items:flex-start}
.slip-company{font-size:.68rem;color:rgba(255,255,255,.75);font-weight:600;text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px}
.slip-title{font-size:1.1rem;font-weight:800;color:#fff}
.slip-period{font-size:.72rem;color:rgba(255,255,255,.75);margin-top:3px}
.slip-emp{text-align:right}
.slip-emp-name{font-size:.88rem;font-weight:700;color:#fff}
.slip-emp-dept{font-size:.7rem;color:rgba(255,255,255,.7);margin-top:2px}
.slip-row{display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:1px solid var(--br);font-size:.82rem}
.slip-row:last-child{border-bottom:none}
.slip-row .lbl{color:var(--t2)}
.slip-row .val{font-weight:700;color:var(--tx)}
.slip-total{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;background:var(--bg3);border-radius:10px;margin-top:12px}
.slip-total .lbl{font-size:.82rem;font-weight:700;color:var(--t2)}
.slip-total .val{font-size:1.2rem;font-weight:800;color:var(--gr)}
.slip-status{display:inline-flex;align-items:center;gap:5px;font-size:.72rem;padding:3px 10px;border-radius:100px;font-weight:700}
.slip-status.paid{background:var(--grd);color:var(--gr)}
.slip-status.pending{background:var(--god);color:var(--go)}

@media(max-width:1280px){.stats6{grid-template-columns:repeat(3,1fr)}}
@media(max-width:1100px){.g2{grid-template-columns:1fr}}
@media(max-width:900px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:none}.main{margin-left:0}.mob-btn{display:block}.frow{grid-template-columns:1fr}.frow.three{grid-template-columns:1fr 1fr}}
@media(max-width:640px){.content{padding:16px}.stats6{grid-template-columns:repeat(2,1fr)}.mb-stats{display:none}.page-header{flex-direction:column}}
</style>
</head>
<body>

<!-- SIDEBAR (reusable) -->
<?php include 'sidebar.php'; ?>

<!-- MAIN CONTENT -->
<div class="main">
  <!-- TOP NAV (reusable) -->
  <?php include 'topnav.php'; ?>

  <div class="content">

    <!-- Page Header -->
    <div class="page-header">
      <div>
        <div class="ph-title">Salaries</div>
        <div class="ph-sub">Payroll management — YTD total: <strong style="color:var(--gr)"><?=fmt($ytdPayroll)?></strong></div>
      </div>
      <div class="ph-actions">
        <?php if($canEdit):?>
        <button class="btn btn-primary" onclick="openAdd()">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M8 1v14M1 8h14"/></svg>
          Add Salary Record
        </button>
        <?php endif;?>
      </div>
    </div>

    <!-- Flash -->
    <?php if($flash):?>
    <div class="flash <?=$flashType?>">
      <?php if($flashType==='ok'):?>
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8" cy="8" r="7"/><path d="M5 8l2 2 4-4"/></svg>
      <?php else:?>
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 1L1 13h14z"/><path d="M8 6v3M8 11v1"/></svg>
      <?php endif;?>
      <span><?=$flash?></span>
    </div>
    <?php endif;?>

    <!-- Month Banner -->
    <?php
    $viewMonth = $fMonth ?: $curMonth;
    $viewYear  = $fYear  ?: $curYear;
    $prevM = $viewMonth - 1; $prevY = $viewYear;
    if ($prevM < 1) { $prevM = 12; $prevY--; }
    $nextM = $viewMonth + 1; $nextY = $viewYear;
    if ($nextM > 12) { $nextM = 1; $nextY++; }
    $prevQS = http_build_query(array_filter(['q'=>$search,'month'=>$prevM,'year'=>$prevY,'dept'=>$fDept,'paid'=>$fPaid]));
    $nextQS = http_build_query(array_filter(['q'=>$search,'month'=>$nextM,'year'=>$nextY,'dept'=>$fDept,'paid'=>$fPaid]));
    ?>
    <div class="month-banner">
      <div class="mb-left">
        <div class="mb-label">Payroll Period</div>
        <div class="mb-period"><?=$months[$viewMonth]?> <?=$viewYear?></div>
        <div class="mb-sub">
          <?=$paidCount?> paid &nbsp;·&nbsp; <?=$unpaidCount?> pending
          <?php if($viewMonth==$curMonth&&$viewYear==$curYear):?>&nbsp;·&nbsp; <strong>Current month</strong><?php endif;?>
        </div>
      </div>
      <div class="mb-right">
        <a class="mb-nav" href="salaries.php?<?=$prevQS?>">‹</a>
        <div class="mb-stats">
          <div class="mb-stat"><div class="mb-val"><?=fmt($totalPayroll)?></div><div class="mb-lbl">Total Payroll</div></div>
          <div class="mb-div"></div>
          <div class="mb-stat"><div class="mb-val"><?=fmt($totalPaid)?></div><div class="mb-lbl">Disbursed</div></div>
          <div class="mb-div"></div>
          <div class="mb-stat"><div class="mb-val"><?=fmt($totalUnpaid)?></div><div class="mb-lbl">Pending</div></div>
          <div class="mb-div"></div>
          <div class="mb-stat"><div class="mb-val"><?=fmt($totalBonus)?></div><div class="mb-lbl">Bonuses</div></div>
        </div>
        <a class="mb-nav" href="salaries.php?<?=$nextQS?>">›</a>
      </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats6">
      <div class="scard" style="--dl:.0s">
        <div class="sc-top">
          <div class="sc-icon" style="background:var(--ag)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--ac)" stroke-width="1.8"><rect x="1" y="4" width="14" height="9" rx="1.5"/><path d="M1 8h14M5 4V2M11 4V2"/></svg></div>
        </div>
        <div class="sc-val"><?=fmt($totalPayroll)?></div>
        <div class="sc-lbl">Total Payroll</div>
      </div>
      <div class="scard" style="--dl:.05s">
        <div class="sc-top">
          <div class="sc-icon" style="background:var(--grd)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--gr)" stroke-width="1.8"><circle cx="8" cy="8" r="7"/><path d="M5 8l2 2 4-4"/></svg></div>
        </div>
        <div class="sc-val" style="color:var(--gr)"><?=fmt($totalPaid)?></div>
        <div class="sc-lbl">Paid Out</div>
      </div>
      <div class="scard" style="--dl:.1s">
        <div class="sc-top">
          <div class="sc-icon" style="background:var(--god)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--go)" stroke-width="1.8"><circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 2"/></svg></div>
        </div>
        <div class="sc-val" style="color:var(--go)"><?=fmt($totalUnpaid)?></div>
        <div class="sc-lbl">Pending</div>
      </div>
      <div class="scard" style="--dl:.15s">
        <div class="sc-top">
          <div class="sc-icon" style="background:var(--pud)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--pu)" stroke-width="1.8"><path d="M8 2v4M8 10v4M4 6l-2 2 2 2M12 6l2 2-2 2"/></svg></div>
        </div>
        <div class="sc-val"><?=fmt($totalBonus)?></div>
        <div class="sc-lbl">Total Bonuses</div>
      </div>
      <div class="scard" style="--dl:.2s">
        <div class="sc-top">
          <div class="sc-icon" style="background:var(--ted)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--te)" stroke-width="1.8"><path d="M3 10l3 3 4-5 4 4"/></svg></div>
        </div>
        <div class="sc-val"><?=fmt($avgSalary)?></div>
        <div class="sc-lbl">Avg Net Salary</div>
      </div>
      <div class="scard" style="--dl:.25s">
        <div class="sc-top">
          <div class="sc-icon" style="background:var(--grd)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--gr)" stroke-width="1.8"><path d="M2 2h12v12H2z"/><path d="M5 8h6M8 5v6"/></svg></div>
        </div>
        <div class="sc-val"><?=fmt($ytdPayroll)?></div>
        <div class="sc-lbl">YTD Payroll</div>
      </div>
    </div>

    <!-- Trend + Dept Breakdown -->
    <div class="g2">

      <!-- Payroll Trend -->
      <div class="panel">
        <div class="phead">
          <div><div class="ptitle">Payroll Trend</div><div class="psub">Monthly net salary totals</div></div>
        </div>
        <div class="pbody">
          <div class="sparkline">
            <?php foreach($trendRows as $i=>$tr):
              $pct = $trendMax > 0 ? round(floatval($tr['total'])/$trendMax*100) : 5;
              $pct = max($pct,4);
              $isCurr = ($tr['pay_year']==$curYear && $tr['pay_month']==$curMonth);
              $tip = $months[(int)$tr['pay_month']].' '.$tr['pay_year'].' — '.fmt((float)$tr['total']).' ('.$tr['records'].' records)';
            ?>
            <div class="spk <?=$isCurr?'curr':''?>" style="height:<?=$pct?>%" title="<?=htmlspecialchars($tip)?>"></div>
            <?php endforeach;?>
            <?php if(!count($trendRows)):?>
              <?php for($i=0;$i<8;$i++):?><div class="spk" style="height:8%" title="No data"></div><?php endfor;?>
            <?php endif;?>
          </div>
          <div style="display:flex;justify-content:space-between;font-size:.6rem;color:var(--t3);margin-top:6px;font-weight:600">
            <?php foreach($trendRows as $tr):?>
              <span><?=substr($months[(int)$tr['pay_month']],0,1)?></span>
            <?php endforeach;?>
          </div>
        </div>
      </div>

      <!-- Dept Breakdown -->
      <div class="panel">
        <div class="phead">
          <div><div class="ptitle">By Department</div><div class="psub"><?=$months[$viewMonth]?> <?=$viewYear?></div></div>
        </div>
        <div class="pbody">
          <?php if(count($deptRows)):
            foreach($deptRows as $dr):
              [$clr,$bgclr] = deptColor($dr['department']);
              $pct = $deptMax > 0 ? round(floatval($dr['total'])/$deptMax*100) : 0;
          ?>
          <div class="dept-row">
            <div class="dept-dot" style="background:<?=$clr?>"></div>
            <div class="dept-name"><?=ucfirst($dr['department'])?></div>
            <div class="dept-cnt"><?=$dr['cnt']?></div>
            <div class="dept-bar-wrap">
              <div class="dept-track"><div class="dept-fill" style="width:<?=$pct?>%;background:<?=$clr?>"></div></div>
            </div>
            <div class="dept-amt" style="color:<?=$clr?>"><?=fmt((float)$dr['total'])?></div>
          </div>
          <?php endforeach;
          else:?>
          <div style="text-align:center;color:var(--t3);font-size:.8rem;padding:24px 0">No payroll data for this period.</div>
          <?php endif;?>
        </div>
      </div>

    </div>

    <!-- Toolbar / Filter -->
    <form method="GET" action="salaries.php">
      <div class="toolbar">
        <div class="searchbox">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="7" cy="7" r="4.5"/><path d="M11 11l3 3"/></svg>
          <input type="text" name="q" placeholder="Search employee name, dept…" value="<?=htmlspecialchars($search)?>" id="searchInp">
        </div>
        <select name="month" class="sel" onchange="this.form.submit()">
          <option value="">All Months</option>
          <?php for($m=1;$m<=12;$m++):?>
          <option value="<?=$m?>" <?=$fMonth==$m?'selected':''?>><?=$months[$m]?></option>
          <?php endfor;?>
        </select>
        <select name="year" class="sel" onchange="this.form.submit()">
          <option value="">All Years</option>
          <?php foreach($yearOpts as $yr):?>
          <option value="<?=$yr?>" <?=$fYear==$yr?'selected':''?>><?=$yr?></option>
          <?php endforeach;?>
        </select>
        <select name="dept" class="sel" onchange="this.form.submit()">
          <option value="">All Depts</option>
          <?php foreach(['management','sales','warehouse','accounting','delivery','hr'] as $d):?>
          <option value="<?=$d?>" <?=$fDept==$d?'selected':''?>><?=ucfirst($d)?></option>
          <?php endforeach;?>
        </select>
        <select name="paid" class="sel" onchange="this.form.submit()">
          <option value="">All Status</option>
          <option value="1" <?=$fPaid==='1'?'selected':''?>>Paid</option>
          <option value="0" <?=$fPaid==='0'?'selected':''?>>Pending</option>
        </select>
        <button type="submit" class="btn btn-primary" style="padding:8px 14px">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="7" cy="7" r="4.5"/><path d="M11 11l3 3"/></svg>
          Filter
        </button>
        <?php if($search||$fMonth||$fYear||$fDept||$fPaid!==''):?>
        <a href="salaries.php" class="btn btn-ghost" style="padding:8px 14px">Clear</a>
        <?php endif;?>
        <div class="toolbar-right">
          <span class="count-lbl"><?=number_format($total)?> record<?=$total!==1?'s':''?></span>
        </div>
      </div>
    </form>

    <!-- Table -->
    <div class="panel">
      <?php if($salaries && $salaries->num_rows > 0):?>
      <div style="overflow-x:auto">
        <table class="dtbl">
          <thead>
            <tr>
              <th>Employee</th>
              <th>Period</th>
              <th>Base Salary</th>
              <th>Bonus</th>
              <th>Net Salary</th>
              <th>Payment Date</th>
              <th>Approved By</th>
              <th>Status</th>
              <?php if($canEdit):?><th>Actions</th><?php endif;?>
            </tr>
          </thead>
          <tbody>
          <?php while($sal = $salaries->fetch_assoc()):
            $initials = strtoupper(substr($sal['first_name'],0,1).substr($sal['last_name'],0,1));
            $isPaid   = !empty($sal['payment_date']);
          ?>
          <tr>
            <td>
              <div class="emp-cell">
                <div class="emp-ava" style="background:<?=avatarGrad($sal['department'])?>"><?=$initials?></div>
                <div>
                  <div class="emp-name"><?=htmlspecialchars($sal['first_name'].' '.$sal['last_name'])?></div>
                  <div class="emp-dept"><?=ucfirst($sal['department'])?><?php if($sal['is_representative']):?> · <span style="color:var(--ac)">Rep</span><?php endif;?></div>
                </div>
              </div>
            </td>
            <td>
              <div style="font-size:.82rem;font-weight:600;color:var(--tx)"><?=$months[(int)$sal['pay_month']]?></div>
              <div style="font-size:.7rem;color:var(--t2)"><?=$sal['pay_year']?></div>
            </td>
            <td><span class="num"><?=fmtFull((float)$sal['base_salary'])?></span></td>
            <?php $bonusClass = (float)$sal['bonus'] > 0 ? 'accent' : 'muted'; ?>
            <td><span class="num <?=$bonusClass?>"><?=(float)$sal['bonus']>0 ? fmtFull((float)$sal['bonus']) : '—'?></span></td>
            <td><span class="num green"><?=fmtFull((float)$sal['net_salary'])?></span></td>
            <td>
              <?php if($isPaid):?>
                <span style="font-size:.79rem;color:var(--tx);font-weight:600"><?=date('M j, Y',strtotime($sal['payment_date']))?></span>
              <?php else:?>
                <span style="color:var(--t3);font-size:.78rem">—</span>
              <?php endif;?>
            </td>
            <td class="dim" style="font-size:.78rem"><?=htmlspecialchars($sal['approver_name']??'—')?></td>
            <td>
              <?php if($isPaid):?>
                <span class="pill ok">Paid</span>
              <?php else:?>
                <span class="pill warn">Pending</span>
              <?php endif;?>
            </td>
            <?php if($canEdit):?>
            <td>
              <div class="actions">
                <!-- View Slip -->
                <button class="act-btn" title="View payslip" onclick='openSlip(<?=json_encode($sal)?>)'>
                  <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 2h8l4 4v9H2z"/><path d="M10 2v4h4M5 8h6M5 11h4"/></svg>
                </button>
                <!-- Edit -->
                <button class="act-btn edit" title="Edit" onclick='openEdit(<?=json_encode($sal)?>)'>
                  <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M11 2l3 3-9 9H2v-3z"/></svg>
                </button>
                <!-- Mark Paid -->
                <?php if(!$isPaid && $canApprove):?>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="action" value="mark_paid">
                  <input type="hidden" name="id" value="<?=$sal['id']?>">
                  <button type="submit" class="act-btn pay" title="Mark as paid">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8" cy="8" r="7"/><path d="M5 8l2 2 4-4"/></svg>
                  </button>
                </form>
                <?php endif;?>
                <!-- Delete -->
                <?php if($canDelete):?>
                <button class="act-btn del" title="Delete" onclick="openDel(<?=$sal['id']?>,<?=(int)$sal['pay_month']?>,<?=(int)$sal['pay_year']?>,'<?=htmlspecialchars($sal['first_name'].' '.$sal['last_name'],ENT_QUOTES)?>')">
                  <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 4h12M5 4V2h6v2M6 7v5M10 7v5M3 4l1 10h8l1-10"/></svg>
                </button>
                <?php endif;?>
              </div>
            </td>
            <?php endif;?>
          </tr>
          <?php endwhile;?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php if($totalPages > 1):?>
      <div class="pager">
        <span class="pager-info">Showing <?=$offset+1?>–<?=min($offset+$perPage,$total)?> of <?=$total?> records</span>
        <div class="pager-btns">
          <?php if($page>1):?><a class="pg-btn" href="<?=$qs?>page=<?=$page-1?>">‹</a><?php endif;?>
          <?php for($p=max(1,$page-2);$p<=min($totalPages,$page+2);$p++):?>
            <a class="pg-btn <?=$p===$page?'on':''?>" href="<?=$qs?>page=<?=$p?>"><?=$p?></a>
          <?php endfor;?>
          <?php if($page<$totalPages):?><a class="pg-btn" href="<?=$qs?>page=<?=$page+1?>">›</a><?php endif;?>
        </div>
      </div>
      <?php endif;?>

      <?php else:?>
      <div class="empty-state">
        <div class="empty-icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 2"/></svg></div>
        <div class="empty-title">No salary records found</div>
        <div class="empty-sub"><?=($search||$fMonth||$fYear||$fDept||$fPaid!=='')?'Try adjusting your filters.':'Start by adding a salary record for an employee.'?></div>
      </div>
      <?php endif;?>
    </div>

  </div><!-- /content -->
</div><!-- /main -->


<?php if($canEdit):?>
<!-- ══ ADD / EDIT MODAL ══════════════════════════════════════ -->
<div class="overlay" id="salOverlay" onclick="closeModal(event,this)">
  <div class="modal">
    <div class="modal-head">
      <div>
        <div class="modal-title" id="modalTitle">Add Salary Record</div>
        <div class="modal-sub" id="modalSub">Enter payroll details for the selected employee</div>
      </div>
      <button class="close-btn" onclick="closeAll()">✕</button>
    </div>
    <form method="POST" action="salaries.php" id="salForm">
      <input type="hidden" name="action" id="fAction" value="add">
      <input type="hidden" name="id" id="fId" value="">
      <div class="modal-body">

        <div class="fsection">Employee &amp; Period</div>
        <div class="frow">
          <div class="fgroup" style="grid-column:span 2">
            <label class="flabel">Employee <span class="req">*</span></label>
            <select name="employee_id" id="fEmp" class="fselect" onchange="autoFillSalary(this)" required>
              <option value="">— Select Employee —</option>
              <?php if($activeEmployees): $activeEmployees->data_seek(0); while($e=$activeEmployees->fetch_assoc()):?>
              <option value="<?=$e['id']?>"
                data-dept="<?=$e['department']?>"
                data-rep="<?=$e['is_representative']?>"
                data-comm="<?=$e['commission_rate']?>"
              ><?=htmlspecialchars($e['first_name'].' '.$e['last_name'])?> — <?=ucfirst($e['department'])?></option>
              <?php endwhile; endif;?>
            </select>
          </div>
        </div>
        <div class="frow three">
          <div class="fgroup">
            <label class="flabel">Month <span class="req">*</span></label>
            <select name="pay_month" id="fMonth" class="fselect" required>
              <?php for($m=1;$m<=12;$m++):?>
              <option value="<?=$m?>" <?=$m==$curMonth?'selected':''?>><?=$months[$m]?></option>
              <?php endfor;?>
            </select>
          </div>
          <div class="fgroup">
            <label class="flabel">Year <span class="req">*</span></label>
            <select name="pay_year" id="fYear" class="fselect" required>
              <?php for($y=$curYear;$y>=$curYear-4;$y--):?>
              <option value="<?=$y?>" <?=$y==$curYear?'selected':''?>><?=$y?></option>
              <?php endfor;?>
            </select>
          </div>
          <div class="fgroup">
            <label class="flabel">Payment Date</label>
            <input type="date" name="payment_date" id="fPayDate" class="finput">
          </div>
        </div>

        <div class="fsection">Salary Breakdown</div>
        <div class="frow">
          <div class="fgroup">
            <label class="flabel">Base Salary ($) <span class="req">*</span></label>
            <input type="number" name="base_salary" id="fBase" class="finput" min="0" step="0.01" placeholder="0.00" oninput="calcNet()" required>
          </div>
          <div class="fgroup">
            <label class="flabel">Bonus ($)</label>
            <input type="number" name="bonus" id="fBonus" class="finput" min="0" step="0.01" placeholder="0.00" value="0" oninput="calcNet()">
          </div>
        </div>
        <div class="frow full">
          <div class="fgroup">
            <label class="flabel">Net Salary ($) <span class="req">*</span></label>
            <input type="number" name="net_salary" id="fNet" class="finput" min="0" step="0.01" placeholder="0.00" required>
            <div class="calc-hint" id="calcHint">Net = Base + Bonus. Adjust manually if deductions apply. Current: <strong id="calcVal">$0.00</strong></div>
          </div>
        </div>
        <div class="frow full">
          <div class="fgroup">
            <label class="flabel">Notes</label>
            <textarea name="notes" id="fNotes" class="ftextarea" placeholder="Optional notes about this salary record…"></textarea>
          </div>
        </div>

      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="closeAll()">Cancel</button>
        <button type="submit" class="btn btn-primary">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M2 8l4 4 8-8"/></svg>
          <span id="fSubmitTxt">Save Record</span>
        </button>
      </div>
    </form>
  </div>
</div>
<?php endif;?>

<!-- ══ PAYSLIP MODAL ═════════════════════════════════════════ -->
<div class="overlay" id="slipOverlay" onclick="closeModal(event,this)">
  <div class="modal slip-modal">
    <div class="modal-head">
      <div><div class="modal-title">Pay Slip</div><div class="modal-sub" id="slipSub"></div></div>
      <button class="close-btn" onclick="closeAll()">✕</button>
    </div>
    <div class="modal-body" id="slipBody">
      <!-- populated by JS -->
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" onclick="closeAll()">Close</button>
      <button class="btn btn-primary" onclick="window.print()">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" width="14" height="14"><rect x="3" y="1" width="10" height="4" rx="1"/><path d="M3 5H1a1 1 0 00-1 1v5a1 1 0 001 1h2v3h10v-3h2a1 1 0 001-1V6a1 1 0 00-1-1h-2"/><rect x="3" y="10" width="10" height="5" rx="1"/></svg>
        Print
      </button>
    </div>
  </div>
</div>

<?php if($canDelete):?>
<!-- ══ CONFIRM DELETE MODAL ══════════════════════════════════ -->
<div class="overlay" id="delOverlay" onclick="closeModal(event,this)">
  <div class="modal confirm-modal">
    <div class="modal-body" style="padding:30px 26px 18px">
      <div class="cicon" style="background:var(--red)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--re)" stroke-width="1.8"><path d="M2 4h12M5 4V2h6v2M6 7v5M10 7v5M3 4l1 10h8l1-10"/></svg></div>
      <div class="ctitle">Delete Salary Record?</div>
      <div class="csub" id="delMsg">This will permanently remove the salary record. This cannot be undone.</div>
    </div>
    <form method="POST" action="salaries.php">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" id="delId" value="">
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="closeAll()">Cancel</button>
        <button type="submit" class="btn btn-danger">Delete Permanently</button>
      </div>
    </form>
  </div>
</div>
<?php endif;?>

<script>
// Theme
const html=document.documentElement,thi=document.getElementById('thi');
function applyTheme(t){html.dataset.theme=t;thi.textContent=t==='dark'?'☀️':'🌙';}
document.getElementById('thm').onclick=()=>{const nt=html.dataset.theme==='dark'?'light':'dark';applyTheme(nt);localStorage.setItem('pos_theme',nt);};
const sv=localStorage.getItem('pos_theme');if(sv)applyTheme(sv);

document.addEventListener('click',e=>{
  const sb=document.getElementById('sidebar');
  if(window.innerWidth<=900&&!sb.contains(e.target)&&!e.target.closest('.mob-btn'))sb.classList.remove('open');
});

function closeModal(e,el){if(e.target===el)closeAll();}
function closeAll(){document.querySelectorAll('.overlay').forEach(o=>o.classList.remove('open'));document.body.style.overflow='';}
function openOverlay(id){document.getElementById(id).classList.add('open');document.body.style.overflow='hidden';}

const months = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

// Net auto-calc
function calcNet(){
  const b=parseFloat(document.getElementById('fBase').value)||0;
  const bn=parseFloat(document.getElementById('fBonus').value)||0;
  const net=b+bn;
  document.getElementById('fNet').value=net.toFixed(2);
  document.getElementById('calcVal').textContent='$'+net.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');
}

function autoFillSalary(sel){
  // Could pre-fill from known data; for now just trigger recalc
  calcNet();
}

<?php if($canEdit):?>
function openAdd(){
  document.getElementById('modalTitle').textContent='Add Salary Record';
  document.getElementById('modalSub').textContent='Enter payroll details';
  document.getElementById('fAction').value='add';
  document.getElementById('fId').value='';
  document.getElementById('salForm').reset();
  document.getElementById('fSubmitTxt').textContent='Save Record';
  // Reset month/year to current
  document.getElementById('fMonth').value='<?=$curMonth?>';
  document.getElementById('fYear').value='<?=$curYear?>';
  calcNet();
  openOverlay('salOverlay');
}

function openEdit(s){
  document.getElementById('modalTitle').textContent='Edit Salary Record';
  document.getElementById('modalSub').textContent=s.first_name+' '+s.last_name+' — '+months[parseInt(s.pay_month)]+' '+s.pay_year;
  document.getElementById('fAction').value='edit';
  document.getElementById('fId').value=s.id;
  // Disable employee/period (can't change)
  const empSel=document.getElementById('fEmp');
  empSel.value=s.employee_id; empSel.disabled=true;
  document.getElementById('fMonth').value=s.pay_month; document.getElementById('fMonth').disabled=true;
  document.getElementById('fYear').value=s.pay_year;   document.getElementById('fYear').disabled=true;
  document.getElementById('fBase').value=parseFloat(s.base_salary).toFixed(2);
  document.getElementById('fBonus').value=parseFloat(s.bonus||0).toFixed(2);
  document.getElementById('fNet').value=parseFloat(s.net_salary).toFixed(2);
  document.getElementById('fPayDate').value=s.payment_date||'';
  document.getElementById('fNotes').value=s.notes||'';
  document.getElementById('fSubmitTxt').textContent='Save Changes';
  calcNet();
  openOverlay('salOverlay');
}
<?php endif;?>

function fmt(v){
  v=parseFloat(v)||0;
  if(v>=1000000)return'$'+(v/1000000).toFixed(2)+'M';
  if(v>=1000)return'$'+(v/1000).toFixed(1)+'k';
  return'$'+v.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');
}
function fmtFull(v){return'$'+parseFloat(v||0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');}

function openSlip(s){
  const isPaid = s.payment_date && s.payment_date!=='';
  const period = months[parseInt(s.pay_month)]+' '+s.pay_year;
  document.getElementById('slipSub').textContent=s.first_name+' '+s.last_name+' — '+period;
  document.getElementById('slipBody').innerHTML=`
    <div class="slip-header">
      <div>
        <div class="slip-company">SalesOS Payroll</div>
        <div class="slip-title">Pay Slip</div>
        <div class="slip-period">${period}</div>
      </div>
      <div class="slip-emp">
        <div class="slip-emp-name">${s.first_name} ${s.last_name}</div>
        <div class="slip-emp-dept">${s.department ? s.department.charAt(0).toUpperCase()+s.department.slice(1) : ''}</div>
        <div style="margin-top:6px">
          <span class="slip-status ${isPaid?'paid':'pending'}">${isPaid?'✓ Paid':'⏳ Pending'}</span>
        </div>
      </div>
    </div>
    <div class="slip-row"><span class="lbl">Base Salary</span><span class="val">${fmtFull(s.base_salary)}</span></div>
    <div class="slip-row"><span class="lbl">Bonus</span><span class="val" style="color:var(--ac)">${fmtFull(s.bonus)}</span></div>
    <div class="slip-row"><span class="lbl">Deductions</span><span class="val" style="color:var(--re)">—</span></div>
    <div class="slip-row"><span class="lbl">Payment Date</span><span class="val">${isPaid?s.payment_date:'Not paid yet'}</span></div>
    <div class="slip-row"><span class="lbl">Approved By</span><span class="val">${s.approver_name||'—'}</span></div>
    ${s.notes?`<div class="slip-row"><span class="lbl">Notes</span><span class="val" style="font-size:.77rem">${s.notes}</span></div>`:''}
    <div class="slip-total">
      <span class="lbl">Net Salary</span>
      <span class="val">${fmtFull(s.net_salary)}</span>
    </div>
  `;
  openOverlay('slipOverlay');
}

<?php if($canDelete):?>
function openDel(id,month,year,name){
  document.getElementById('delId').value=id;
  document.getElementById('delMsg').textContent=
    'Delete salary record for "'+name+'" ('+months[month]+' '+year+')? This cannot be undone.';
  openOverlay('delOverlay');
}
<?php endif;?>

// Auto-dismiss flash
setTimeout(()=>{const f=document.querySelector('.flash');if(f){f.style.transition='opacity .5s';f.style.opacity='0';setTimeout(()=>f.remove(),500);}},4500);

// ESC close
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeAll();});
</script>
</body>
</html>