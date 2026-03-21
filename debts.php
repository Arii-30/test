<?php
require_once 'connection.php';
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$user      = htmlspecialchars($_SESSION['username'] ?? 'Admin');
$role      = htmlspecialchars(ucfirst($_SESSION['role'] ?? 'admin'));
$ava       = strtoupper(substr($user, 0, 1));
$uid       = (int)$_SESSION['user_id'];
$canEdit   = in_array($_SESSION['role'], ['admin','accountant']);
$canDelete = ($_SESSION['role'] === 'admin');

function esc($conn,$v){ return $conn->real_escape_string($v); }
function fmt($v){
    if($v>=1000000) return '$'.number_format($v/1000000,1).'M';
    if($v>=1000)    return '$'.number_format($v/1000,1).'k';
    return '$'.number_format($v,2);
}
function genNum($conn,$prefix,$table,$col){
    $today=date('Ymd');
    $r=$conn->query("SELECT MAX($col) AS mx FROM $table WHERE $col LIKE '{$prefix}{$today}%'");
    $mx=$r?($r->fetch_assoc()['mx']??null):null;
    $seq=$mx?((int)substr($mx,-4)+1):1;
    return $prefix.$today.str_pad($seq,4,'0',STR_PAD_LEFT);
}
$flash=$flashType='';

// ── AJAX: debt detail ──────────────────────────────────────────
if(isset($_GET['ajax']) && $_GET['ajax']==='detail'){
    header('Content-Type: application/json');
    $did=(int)($_GET['id']??0);
    $r=$conn->query("SELECT d.*,
        TRIM(CONCAT(IFNULL(c.first_name,''),' ',IFNULL(c.last_name,''))) AS customer_name,
        IFNULL(c.business_name,'') AS biz, c.phone AS cphone,
        s.sale_number, s.sale_date, s.total_amount AS sale_total,
        u.username AS creator_name
        FROM debts d
        LEFT JOIN customers c ON c.id=d.customer_id
        LEFT JOIN sales s ON s.id=d.sale_id
        LEFT JOIN users u ON u.id=d.created_by
        WHERE d.id=$did LIMIT 1");
    if(!$r||!$r->num_rows){echo json_encode(['ok'=>false]);exit;}
    $debt=$r->fetch_assoc();
    $ph=$conn->query("SELECT p.*, u.username AS recv_name
        FROM payments p LEFT JOIN users u ON u.id=p.received_by
        WHERE p.debt_id=$did ORDER BY p.payment_date DESC");
    $payments=[];
    if($ph) while($row=$ph->fetch_assoc()) $payments[]=$row;
    echo json_encode(['ok'=>true,'debt'=>$debt,'payments'=>$payments]);
    exit;
}

// ── POST: add debt ─────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='add' && $canEdit){
    $cust_id   = (int)($_POST['customer_id']??0)?:null;
    $sale_id   = (int)($_POST['sale_id']??0)?:null;
    $amount    = floatval($_POST['original_amount']??0);
    $due_date  = $_POST['due_date']??'';
    $currency  = esc($conn,$_POST['currency']??'USD');
    $notes     = esc($conn,trim($_POST['notes']??''));
    $status    = 'active';

    if($amount<=0){ $flash="Amount must be greater than zero."; $flashType='err'; }
    else{
        $dnum=genNum($conn,'DBT','debts','debt_number');
        $due = $due_date ? "'$due_date'" : 'NULL';
        $cid = $cust_id ?? 'NULL';
        $sid = $sale_id ?? 'NULL';
        if($conn->query("INSERT INTO debts(debt_number,customer_id,sale_id,original_amount,
            paid_amount,due_date,currency,status,notes,created_by)
            VALUES('$dnum',$cid,$sid,$amount,0,$due,'$currency','$status','$notes',$uid)")){
            $new_id=$conn->insert_id;
            $conn->query("INSERT INTO audit_logs(user_id,user_name,action,table_name,record_id,description)
                VALUES($uid,'$user','CREATE','debts',$new_id,'Debt $dnum created for \$$amount')");
            $flash="Debt <strong>$dnum</strong> created."; $flashType='ok';
        } else { $flash="Error: ".htmlspecialchars($conn->error); $flashType='err'; }
    }
    header("Location: debts.php?flash=".urlencode($flash)."&ft=$flashType"); exit;
}

// ── POST: edit debt ────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='edit' && $canEdit){
    $did      = (int)($_POST['debt_id']??0);
    $amount   = floatval($_POST['original_amount']??0);
    $due_date = $_POST['due_date']??'';
    $notes    = esc($conn,trim($_POST['notes']??''));
    $status   = esc($conn,$_POST['status']??'active');
    if($did && $amount>0){
        $due=$due_date?"'$due_date'":'NULL';
        $conn->query("UPDATE debts SET original_amount=$amount,due_date=$due,
            status='$status',notes='$notes' WHERE id=$did");
        $conn->query("INSERT INTO audit_logs(user_id,user_name,action,table_name,record_id,description)
            VALUES($uid,'$user','UPDATE','debts',$did,'Debt #$did updated')");
        $flash="Debt updated."; $flashType='ok';
    }
    header("Location: debts.php?flash=".urlencode($flash)."&ft=$flashType"); exit;
}

// ── POST: write off ────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='writeoff' && $canEdit){
    $did=(int)($_POST['debt_id']??0);
    if($did){
        $conn->query("UPDATE debts SET status='written_off' WHERE id=$did");
        $conn->query("INSERT INTO audit_logs(user_id,user_name,action,table_name,record_id,description)
            VALUES($uid,'$user','UPDATE','debts',$did,'Debt #$did written off')");
        $flash="Debt written off."; $flashType='warn';
    }
    header("Location: debts.php?flash=".urlencode($flash)."&ft=$flashType"); exit;
}

// ── POST: delete debt ──────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='delete' && $canDelete){
    $did=(int)($_POST['debt_id']??0);
    if($did){
        $conn->query("DELETE FROM payments WHERE debt_id=$did");
        $conn->query("DELETE FROM debts WHERE id=$did");
        $conn->query("INSERT INTO audit_logs(user_id,user_name,action,table_name,record_id,description)
            VALUES($uid,'$user','DELETE','debts',$did,'Debt #$did deleted')");
        $flash="Debt deleted."; $flashType='warn';
    }
    header("Location: debts.php?flash=".urlencode($flash)."&ft=$flashType"); exit;
}

// ── POST: mark overdue (bulk) ──────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='mark_overdue' && $canEdit){
    $conn->query("UPDATE debts SET status='overdue'
        WHERE due_date < CURDATE() AND status IN('active','partially_paid')");
    $n=(int)$conn->affected_rows;
    $flash="$n debt".($n!=1?'s':'')." marked as overdue."; $flashType='warn';
    header("Location: debts.php?flash=".urlencode($flash)."&ft=$flashType"); exit;
}

if(isset($_GET['flash'])){ $flash=urldecode($_GET['flash']); $flashType=$_GET['ft']??'ok'; }

// ── FILTERS ────────────────────────────────────────────────────
$search  = trim($_GET['q']??'');
$fStatus = $_GET['status']??'';
$fSort   = $_GET['sort']??'due_date';
$page    = max(1,(int)($_GET['page']??1));
$perPage = 20;
$offset  = ($page-1)*$perPage;

$allowedSorts = ['due_date','remaining_amount','original_amount','created_at'];
if(!in_array($fSort,$allowedSorts)) $fSort='due_date';

$w = "1=1";
if($search){
    $s=esc($conn,$search);
    $w.=" AND (d.debt_number LIKE '%$s%' OR c.first_name LIKE '%$s%'
            OR c.last_name LIKE '%$s%' OR c.business_name LIKE '%$s%' OR c.phone LIKE '%$s%')";
}
if($fStatus) $w.=" AND d.status='".esc($conn,$fStatus)."'";
else         $w.=" AND d.status NOT IN('paid','written_off')"; // default: open only

$allStatuses = ['','active','partially_paid','overdue','paid','written_off','disputed'];

// Total count
$tc=$conn->query("SELECT COUNT(*) AS c FROM debts d
    LEFT JOIN customers c ON c.id=d.customer_id WHERE $w");
$total=$tc?(int)$tc->fetch_assoc()['c']:0;
$totalPages=max(1,(int)ceil($total/$perPage));

$sortDir = in_array($fSort,['remaining_amount','original_amount'])?'DESC':'ASC';
$debts=$conn->query("SELECT d.*,
    TRIM(CONCAT(IFNULL(c.first_name,''),' ',IFNULL(c.last_name,''))) AS customer_name,
    IFNULL(c.business_name,'') AS biz, c.phone AS cphone,
    s.sale_number
    FROM debts d
    LEFT JOIN customers c ON c.id=d.customer_id
    LEFT JOIN sales s ON s.id=d.sale_id
    WHERE $w ORDER BY d.$fSort $sortDir, d.id DESC
    LIMIT $perPage OFFSET $offset");

// Stats
function sv($c,$q){$r=$c->query($q);return $r?($r->fetch_assoc()['v']??0):0;}
$stat_total_debt   =(float)sv($conn,"SELECT COALESCE(SUM(remaining_amount),0) AS v FROM debts WHERE status IN('active','partially_paid','overdue')");
$stat_overdue_amt  =(float)sv($conn,"SELECT COALESCE(SUM(remaining_amount),0) AS v FROM debts WHERE status='overdue' OR (due_date<CURDATE() AND status IN('active','partially_paid'))");
$stat_count_open   =(int)sv($conn,"SELECT COUNT(*) AS v FROM debts WHERE status IN('active','partially_paid','overdue')");
$stat_count_overdue=(int)sv($conn,"SELECT COUNT(*) AS v FROM debts WHERE status='overdue' OR (due_date<CURDATE() AND status IN('active','partially_paid'))");
$stat_collected    =(float)sv($conn,"SELECT COALESCE(SUM(amount),0) AS v FROM payments");
$stat_written_off  =(float)sv($conn,"SELECT COALESCE(SUM(original_amount),0) AS v FROM debts WHERE status='written_off'");

// Customers & sales for add modal
$customers_list=$conn->query("SELECT id,TRIM(CONCAT(first_name,' ',IFNULL(last_name,''))) AS full_name,
    IFNULL(business_name,'') AS biz,phone FROM customers WHERE status='active' ORDER BY first_name");

function buildQS($ov=[]){
    $p=array_merge($_GET,$ov); unset($p['page']);
    $qs=http_build_query(array_filter($p,fn($v)=>$v!==''));
    return $qs?"?$qs&":"?";
}
$qs=buildQS();
$current_page='debts';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SalesOS &mdash; Debts</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
[data-theme="dark"]{
  --bg:#0D0F14;--bg2:#13161D;--bg3:#191D27;--bg4:#1F2433;
  --br:rgba(255,255,255,.06);--br2:rgba(255,255,255,.11);
  --tx:#EDF0F7;--t2:#7B82A0;--t3:#3E4460;
  --ac:#4F8EF7;--ac2:#6BA3FF;--ag:rgba(79,142,247,.15);
  --go:#F5A623;--god:rgba(245,166,35,.12);--gob:rgba(245,166,35,.22);
  --gr:#34D399;--grd:rgba(52,211,153,.12);--grb:rgba(52,211,153,.22);
  --re:#F87171;--red:rgba(248,113,113,.12);--reb:rgba(248,113,113,.22);
  --pu:#A78BFA;--pud:rgba(167,139,250,.12);
  --te:#22D3EE;--ted:rgba(34,211,238,.12);
  --sh:0 2px 16px rgba(0,0,0,.35);--sh2:0 8px 32px rgba(0,0,0,.45);
}
[data-theme="light"]{
  --bg:#F0EEE9;--bg2:#FFFFFF;--bg3:#F7F5F1;--bg4:#EDE9E2;
  --br:rgba(0,0,0,.07);--br2:rgba(0,0,0,.13);
  --tx:#1A1C27;--t2:#6B7280;--t3:#C0C5D4;
  --ac:#3B7DD8;--ac2:#2563EB;--ag:rgba(59,125,216,.12);
  --go:#D97706;--god:rgba(217,119,6,.1);--gob:rgba(217,119,6,.18);
  --gr:#059669;--grd:rgba(5,150,105,.1);--grb:rgba(5,150,105,.18);
  --re:#DC2626;--red:rgba(220,38,38,.1);--reb:rgba(220,38,38,.18);
  --pu:#7C3AED;--pud:rgba(124,58,237,.1);
  --te:#0891B2;--ted:rgba(8,145,178,.1);
  --sh:0 2px 12px rgba(0,0,0,.07);--sh2:0 8px 28px rgba(0,0,0,.1);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--tx);font-family:'Plus Jakarta Sans',sans-serif;
  font-size:.875rem;min-height:100vh;display:flex;transition:background .4s,color .4s}
/* SIDEBAR */
.sidebar{width:248px;min-height:100vh;background:var(--bg2);border-right:1px solid var(--br);
  display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;transition:transform .3s}
.slogo{display:flex;align-items:center;gap:11px;padding:22px 20px;border-bottom:1px solid var(--br)}
.logo-icon{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,var(--ac),var(--ac2));
  display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px var(--ag);flex-shrink:0}
.logo-icon svg{width:18px;height:18px}
.logo-txt{font-size:1.1rem;font-weight:800;color:var(--tx);letter-spacing:-.02em}
.logo-txt span{color:var(--ac)}
.nav-sec{padding:14px 12px 4px}
.nav-lbl{font-size:.62rem;color:var(--t3);letter-spacing:.12em;text-transform:uppercase;padding:0 8px 8px;display:block;font-weight:600}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:9px;
  color:var(--t2);text-decoration:none;transition:all .15s;margin-bottom:1px;position:relative;font-size:.82rem;font-weight:500}
.nav-item:hover{background:rgba(255,255,255,.05);color:var(--tx)}
[data-theme="light"] .nav-item:hover{background:rgba(0,0,0,.04)}
.nav-item.active{background:var(--ag);color:var(--ac);font-weight:600}
.nav-item.active::before{content:'';position:absolute;left:0;top:20%;bottom:20%;width:3px;background:var(--ac);border-radius:0 3px 3px 0}
.nav-item svg{width:16px;height:16px;flex-shrink:0}
.nbadge{margin-left:auto;background:var(--re);color:#fff;font-size:.6rem;font-weight:700;padding:2px 7px;border-radius:100px;line-height:1.4}
.nbadge.g{background:var(--gr)}.nbadge.b{background:var(--ac)}
.sfooter{margin-top:auto;padding:14px 12px;border-top:1px solid var(--br)}
.ucard{display:flex;align-items:center;gap:10px;padding:10px;background:var(--bg3);
  border:1px solid var(--br);border-radius:11px;cursor:pointer;transition:background .15s}
.ucard:hover{background:var(--bg4)}
.ava{width:34px;height:34px;border-radius:9px;flex-shrink:0;background:linear-gradient(135deg,var(--ac),var(--ac2));
  display:flex;align-items:center;justify-content:center;font-size:.9rem;font-weight:800;color:#fff}
.uinfo{flex:1;min-width:0}
.uname{font-size:.8rem;font-weight:700;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.urole{font-size:.68rem;color:var(--ac);font-weight:500;margin-top:1px}
/* TOPNAV */
.topnav{height:58px;background:var(--bg2);border-bottom:1px solid var(--br);position:sticky;
  top:0;z-index:50;display:flex;align-items:center;padding:0 28px;gap:14px;transition:background .4s}
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
.ibtn{width:36px;height:36px;border-radius:9px;background:var(--bg3);border:1px solid var(--br);
  display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .2s;position:relative;color:var(--t2);text-decoration:none}
.ibtn:hover{background:var(--ag);border-color:rgba(79,142,247,.3);color:var(--ac)}
.ibtn svg{width:16px;height:16px}
.ibtn .dot{position:absolute;top:-4px;right:-4px;width:16px;height:16px;background:var(--re);
  border-radius:50%;font-size:.56rem;color:#fff;display:flex;align-items:center;justify-content:center;border:2px solid var(--bg2);font-weight:700}
.thm-btn{width:36px;height:36px;border-radius:9px;background:var(--bg3);border:1px solid var(--br);
  display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.95rem;transition:all .25s}
.thm-btn:hover{transform:rotate(15deg);border-color:var(--ac)}
.logout-btn{display:flex;align-items:center;gap:6px;padding:7px 13px;background:var(--red);
  border:1px solid rgba(248,113,113,.25);border-radius:9px;color:var(--re);font-size:.76rem;
  font-weight:600;text-decoration:none;transition:all .2s;font-family:inherit}
.logout-btn:hover{background:rgba(248,113,113,.2)}
.logout-btn svg{width:14px;height:14px}
/* LAYOUT */
.main{margin-left:248px;flex:1;display:flex;flex-direction:column;min-height:100vh}
.content{padding:26px 28px;display:flex;flex-direction:column;gap:20px}
/* STATS */
.stats-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:14px}
.stat-card{background:var(--bg2);border:1px solid var(--br);border-radius:13px;padding:18px 20px;
  transition:all .2s;animation:fadeUp .4s cubic-bezier(.16,1,.3,1) both}
.stat-card:hover{transform:translateY(-2px);box-shadow:var(--sh2)}
.stat-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.stat-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center}
.stat-icon svg{width:18px;height:18px}
.stat-badge{font-size:.63rem;font-weight:700;padding:3px 8px;border-radius:100px}
.stat-val{font-size:1.45rem;font-weight:800;color:var(--tx);letter-spacing:-.04em;line-height:1}
.stat-lbl{font-size:.71rem;color:var(--t2);margin-top:5px;font-weight:500}
@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
/* PAGE HEADER */
.page-hdr{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}
.ph-title{font-size:1.45rem;font-weight:800;color:var(--tx);letter-spacing:-.03em}
.ph-sub{font-size:.78rem;color:var(--t2);margin-top:4px}
/* TOOLBAR */
.toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.searchbox{display:flex;align-items:center;gap:8px;background:var(--bg2);border:1px solid var(--br);
  border-radius:10px;padding:8px 14px;flex:1;min-width:220px;max-width:340px;transition:border-color .2s}
.searchbox:focus-within{border-color:var(--ac)}
.searchbox svg{width:13px;height:13px;color:var(--t3);flex-shrink:0}
.searchbox input{background:none;border:none;outline:none;font-family:inherit;font-size:.81rem;color:var(--tx);width:100%}
.searchbox input::placeholder{color:var(--t3)}
.fsel{background:var(--bg2);border:1px solid var(--br);border-radius:10px;padding:8px 12px;
  font-family:inherit;font-size:.79rem;color:var(--tx);outline:none;cursor:pointer;transition:border-color .2s}
.fsel:focus{border-color:var(--ac)}
.tbr{margin-left:auto;display:flex;align-items:center;gap:8px}
.count-lbl{font-size:.74rem;color:var(--t2)}
/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:9px;
  font-family:inherit;font-size:.79rem;font-weight:700;cursor:pointer;border:none;transition:all .18s;text-decoration:none}
.btn-primary{background:var(--ac);color:#fff}
.btn-primary:hover{background:var(--ac2);transform:translateY(-1px)}
.btn-ghost{background:var(--bg2);border:1px solid var(--br);color:var(--t2)}
.btn-ghost:hover{color:var(--tx);border-color:var(--br2)}
.btn-warn{background:var(--god);border:1px solid rgba(245,166,35,.3);color:var(--go)}
.btn-warn:hover{background:var(--gob)}
.btn-danger{background:var(--red);border:1px solid rgba(248,113,113,.3);color:var(--re)}
.btn-danger:hover{background:var(--reb)}
.btn svg{width:13px;height:13px}
/* PANEL */
.panel{background:var(--bg2);border:1px solid var(--br);border-radius:14px;overflow:hidden}
/* TABLE */
.dtbl{width:100%;border-collapse:collapse}
.dtbl th{font-size:.62rem;color:var(--t3);text-transform:uppercase;letter-spacing:.1em;
  padding:10px 14px;text-align:left;border-bottom:1px solid var(--br);font-weight:700;white-space:nowrap;background:var(--bg2);position:sticky;top:0}
.dtbl th a{color:inherit;text-decoration:none;display:flex;align-items:center;gap:4px}
.dtbl th a:hover{color:var(--tx)}
.dtbl td{padding:13px 14px;font-size:.8rem;border-bottom:1px solid var(--br);vertical-align:middle}
.dtbl tr:last-child td{border-bottom:none}
.dtbl tbody tr{transition:background .1s;cursor:pointer}
.dtbl tbody tr:hover td{background:rgba(255,255,255,.025)}
[data-theme="light"] .dtbl tbody tr:hover td{background:rgba(0,0,0,.02)}
/* Overdue row highlight */
.dtbl tbody tr.is-overdue td{background:rgba(248,113,113,.04)}
.dtbl tbody tr.is-overdue:hover td{background:rgba(248,113,113,.08)}
/* PILLS */
.pill{display:inline-flex;align-items:center;padding:2px 9px;border-radius:100px;font-size:.64rem;font-weight:700;white-space:nowrap}
.pill.ok  {background:var(--grd);color:var(--gr)}
.pill.warn{background:var(--god);color:var(--go)}
.pill.err {background:var(--red);color:var(--re)}
.pill.info{background:var(--ag); color:var(--ac)}
.pill.pu  {background:var(--pud);color:var(--pu)}
.pill.nt  {background:var(--bg3);color:var(--t3);border:1px solid var(--br)}
/* PROGRESS BAR */
.pbar-wrap{height:5px;background:var(--br);border-radius:3px;overflow:hidden;min-width:80px;margin-top:4px}
.pbar-fill{height:100%;border-radius:3px;transition:width .6s cubic-bezier(.16,1,.3,1)}
/* FLASH */
.flash{display:flex;align-items:center;gap:10px;padding:11px 16px;border-radius:10px;
  font-size:.82rem;font-weight:500;animation:fadeUp .35s cubic-bezier(.16,1,.3,1)}
.flash.ok  {background:var(--grd);border:1px solid rgba(52,211,153,.2);color:var(--gr)}
.flash.warn{background:var(--god);border:1px solid rgba(245,166,35,.2);color:var(--go)}
.flash.err {background:var(--red);border:1px solid rgba(248,113,113,.2);color:var(--re)}
.flash svg{width:15px;height:15px;flex-shrink:0}
/* PAGINATION */
.pager{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-top:1px solid var(--br)}
.pager-info{font-size:.74rem;color:var(--t2)}
.pager-btns{display:flex;gap:4px}
.pg-btn{min-width:32px;height:32px;padding:0 8px;border-radius:8px;border:1px solid var(--br);
  background:var(--bg3);color:var(--t2);font-family:inherit;font-size:.77rem;font-weight:600;
  cursor:pointer;transition:all .15s;text-decoration:none;display:flex;align-items:center;justify-content:center}
.pg-btn:hover{border-color:var(--br2);color:var(--tx)}
.pg-btn.on{background:var(--ac);border-color:var(--ac);color:#fff}
/* MODALS */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:400;display:flex;
  align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;
  transition:opacity .25s;backdrop-filter:blur(6px)}
.overlay.open{opacity:1;pointer-events:all}
.modal{background:var(--bg2);border:1px solid var(--br2);border-radius:18px;width:100%;
  max-width:600px;max-height:92vh;overflow-y:auto;box-shadow:var(--sh2);
  transform:translateY(20px) scale(.97);transition:transform .3s cubic-bezier(.16,1,.3,1)}
.overlay.open .modal{transform:none}
.modal-lg{max-width:700px}
.mhead{display:flex;align-items:center;justify-content:space-between;padding:20px 24px;
  border-bottom:1px solid var(--br);position:sticky;top:0;background:var(--bg2);z-index:1}
.mtitle{font-size:.95rem;font-weight:800;color:var(--tx)}
.msub{font-size:.71rem;color:var(--t2);margin-top:2px}
.mclose{width:30px;height:30px;border-radius:7px;border:1px solid var(--br);background:transparent;
  color:var(--t2);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:1.1rem;transition:all .15s}
.mclose:hover{background:var(--red);color:var(--re)}
.mbody{padding:22px 24px;display:flex;flex-direction:column;gap:14px}
.mfoot{padding:14px 24px;border-top:1px solid var(--br);display:flex;justify-content:flex-end;
  gap:10px;position:sticky;bottom:0;background:var(--bg2)}
/* FORM */
.frow2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.frow3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
.fgrp{display:flex;flex-direction:column;gap:5px}
.flbl{font-size:.68rem;font-weight:700;color:var(--t2);text-transform:uppercase;letter-spacing:.04em}
.flbl .req{color:var(--re)}
.finput,.fselect,.ftextarea{width:100%;background:var(--bg3);border:1.5px solid var(--br);
  border-radius:9px;padding:9px 12px;font-family:inherit;font-size:.82rem;color:var(--tx);
  outline:none;transition:border-color .2s}
.finput:focus,.fselect:focus,.ftextarea:focus{border-color:var(--ac);background:var(--bg)}
.finput::placeholder,.ftextarea::placeholder{color:var(--t3)}
.ftextarea{resize:vertical;min-height:64px}
/* DETAIL SECTIONS */
.det-row{display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:1px solid var(--br);font-size:.8rem}
.det-row:last-child{border:none}
.det-row .dl{color:var(--t2)}.det-row .dv{font-weight:600;color:var(--tx)}
/* MISC */
.spinner{width:28px;height:28px;border:3px solid var(--br);border-top-color:var(--ac);
  border-radius:50%;animation:spin .7s linear infinite;margin:30px auto}
@keyframes spin{to{transform:rotate(360deg)}}
.empty-state{padding:56px 20px;text-align:center;color:var(--t3)}
.empty-state svg{width:38px;height:38px;margin:0 auto 12px;opacity:.28;display:block}
.empty-state p{font-size:.81rem}
/* RESPONSIVE */
@media(max-width:1300px){.stats-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:900px){
  .sidebar{transform:translateX(-100%)}.sidebar.open{transform:none}
  .main{margin-left:0}.mob-btn{display:block}.sbar{display:none}}
@media(max-width:640px){.stats-grid{grid-template-columns:repeat(2,1fr)}.content{padding:16px}.frow2,.frow3{grid-template-columns:1fr}}
</style>
</head>
<body>

<?php $current_page = 'debts'; include 'sidebar.php'; ?>

<div class="main">
<?php $breadcrumbs=[['label'=>'Sales'],['label'=>'Debts']]; include 'topnav.php'; ?>

<div class="content">

<?php if($flash): ?>
<div class="flash <?= $flashType ?>" id="flashMsg">
  <?php if($flashType==='ok'): ?>
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8" cy="8" r="7"/><path d="M5 8l2 2 4-4"/></svg>
  <?php else: ?>
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 1L1 13h14z"/><path d="M8 6v3M8 11v1"/></svg>
  <?php endif; ?>
  <span><?= $flash ?></span>
</div>
<?php endif; ?>

<!-- Header -->
<div class="page-hdr">
  <div>
    <div class="ph-title">Debts</div>
    <div class="ph-sub">Track and manage outstanding customer debts</div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if($canEdit): ?>
    <form method="POST" style="display:inline">
      <input type="hidden" name="action" value="mark_overdue">
      <button type="submit" class="btn btn-warn">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 2"/></svg>
        Mark Overdue
      </button>
    </form>
    <button class="btn btn-primary" onclick="openAdd()">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M8 1v14M1 8h14"/></svg>
      New Debt
    </button>
    <?php endif; ?>
    <a href="payments.php" class="btn btn-ghost">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="1" y="4" width="14" height="9" rx="1.5"/><path d="M1 8h14"/></svg>
      Record Payment
    </a>
  </div>
</div>

<!-- Stats -->
<div class="stats-grid">
  <div class="stat-card" style="--dl:.0s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--red)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--re)" stroke-width="1.8"><circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 2"/></svg></div>
      <span class="stat-badge" style="background:var(--red);color:var(--re)"><?= $stat_count_open ?> open</span>
    </div>
    <div class="stat-val"><?= fmt($stat_total_debt) ?></div>
    <div class="stat-lbl">Total Outstanding</div>
  </div>
  <div class="stat-card" style="--dl:.06s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--reb,var(--red))"><svg viewBox="0 0 16 16" fill="none" stroke="var(--re)" stroke-width="1.8"><path d="M8 1L1 13h14z"/><path d="M8 6v3M8 11v1"/></svg></div>
      <span class="stat-badge" style="background:var(--red);color:var(--re)"><?= $stat_count_overdue ?> debts</span>
    </div>
    <div class="stat-val"><?= fmt($stat_overdue_amt) ?></div>
    <div class="stat-lbl">Overdue Amount</div>
  </div>
  <div class="stat-card" style="--dl:.12s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--god)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--go)" stroke-width="1.8"><path d="M8 2v12M4 6l4-4 4 4"/></svg></div>
      <span class="stat-badge" style="background:var(--god);color:var(--go)">Open</span>
    </div>
    <div class="stat-val"><?= $stat_count_open ?></div>
    <div class="stat-lbl">Open Debts</div>
  </div>
  <div class="stat-card" style="--dl:.18s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--grd)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--gr)" stroke-width="1.8"><rect x="1" y="4" width="14" height="9" rx="1.5"/><path d="M1 8h14"/></svg></div>
      <span class="stat-badge" style="background:var(--grd);color:var(--gr)">All-time</span>
    </div>
    <div class="stat-val"><?= fmt($stat_collected) ?></div>
    <div class="stat-lbl">Total Collected</div>
  </div>
  <div class="stat-card" style="--dl:.24s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--pud)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--pu)" stroke-width="1.8"><path d="M2 2h12v12H2z"/><path d="M5 8h6M5 5h3"/></svg></div>
      <span class="stat-badge" style="background:var(--pud);color:var(--pu)">Written off</span>
    </div>
    <div class="stat-val"><?= fmt($stat_written_off) ?></div>
    <div class="stat-lbl">Written Off</div>
  </div>
  <div class="stat-card" style="--dl:.3s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--ag)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--ac)" stroke-width="1.8"><path d="M8 1v14M1 8h14"/></svg></div>
      <span class="stat-badge" style="background:var(--ag);color:var(--ac)">Showing</span>
    </div>
    <div class="stat-val"><?= $total ?></div>
    <div class="stat-lbl">Filtered Results</div>
  </div>
</div>

<!-- Toolbar -->
<form method="GET" action="debts.php" id="filterForm">
  <div class="toolbar">
    <div class="searchbox">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="7" cy="7" r="4.5"/><path d="M11 11l3 3"/></svg>
      <input type="text" name="q" placeholder="Search by customer, debt #, phone&hellip;" value="<?= htmlspecialchars($search) ?>" id="searchInp">
    </div>
    <select name="status" class="fsel" onchange="this.form.submit()">
      <option value="">Open Debts</option>
      <option value="active"         <?= $fStatus==='active'?'selected':'' ?>>Active</option>
      <option value="partially_paid" <?= $fStatus==='partially_paid'?'selected':'' ?>>Partially Paid</option>
      <option value="overdue"        <?= $fStatus==='overdue'?'selected':'' ?>>Overdue</option>
      <option value="paid"           <?= $fStatus==='paid'?'selected':'' ?>>Paid</option>
      <option value="written_off"    <?= $fStatus==='written_off'?'selected':'' ?>>Written Off</option>
      <option value="disputed"       <?= $fStatus==='disputed'?'selected':'' ?>>Disputed</option>
    </select>
    <select name="sort" class="fsel" onchange="this.form.submit()">
      <option value="due_date"        <?= $fSort==='due_date'?'selected':'' ?>>Sort: Due Date</option>
      <option value="remaining_amount"<?= $fSort==='remaining_amount'?'selected':'' ?>>Sort: Amount</option>
      <option value="created_at"      <?= $fSort==='created_at'?'selected':'' ?>>Sort: Newest</option>
    </select>
    <button type="submit" class="btn btn-primary" style="padding:8px 14px">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="7" cy="7" r="4.5"/><path d="M11 11l3 3"/></svg>
      Search
    </button>
    <?php if($search||$fStatus||$fSort!=='due_date'): ?>
    <a href="debts.php" class="btn btn-ghost" style="padding:8px 14px">Clear</a>
    <?php endif; ?>
    <div class="tbr">
      <span class="count-lbl"><?= number_format($total) ?> result<?= $total!=1?'s':'' ?></span>
    </div>
  </div>
</form>

<!-- Debts Table -->
<div class="panel">
  <?php if($debts && $debts->num_rows > 0): ?>
  <div style="overflow-x:auto">
    <table class="dtbl">
      <thead>
        <tr>
          <th><a href="<?= $qs ?>sort=due_date">Debt # <?= $fSort==='due_date'?'↑':'' ?></a></th>
          <th>Customer</th>
          <th>Sale</th>
          <th><a href="<?= $qs ?>sort=original_amount">Original <?= $fSort==='original_amount'?'↓':'' ?></a></th>
          <th>Paid</th>
          <th><a href="<?= $qs ?>sort=remaining_amount">Remaining <?= $fSort==='remaining_amount'?'↓':'' ?></a></th>
          <th>Progress</th>
          <th>Due Date</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $today = date('Y-m-d');
      while($d=$debts->fetch_assoc()):
        $cname   = trim($d['customer_name'])?:($d['biz']?:'Walk-in');
        $orig    = floatval($d['original_amount']);
        $paid    = floatval($d['paid_amount']);
        $rem     = floatval($d['remaining_amount']);
        $pct     = $orig>0 ? min(100,round($paid/$orig*100)) : 0;
        $isPast  = $d['due_date'] && $d['due_date']<$today && !in_array($d['status'],['paid','written_off']);
        $rowCls  = $isPast?'is-overdue':'';
        $barClr  = $pct>=100?'var(--gr)':($pct>=50?'var(--go)':'var(--re)');

        // Status pill
        $spill = match(true){
          $isPast                         => ['err','Overdue'],
          $d['status']==='paid'           => ['ok','Paid'],
          $d['status']==='partially_paid' => ['warn','Partial'],
          $d['status']==='overdue'        => ['err','Overdue'],
          $d['status']==='written_off'    => ['nt','Written Off'],
          $d['status']==='disputed'       => ['pu','Disputed'],
          default                         => ['info','Active'],
        };

        // Due date display
        $dueDisplay = '—';
        if($d['due_date']){
          $diff = (int)round((strtotime($d['due_date'])-strtotime($today))/86400);
          if($diff<0)      $dueDisplay='<span style="color:var(--re);font-weight:700">'.abs($diff).'d overdue</span>';
          elseif($diff===0)$dueDisplay='<span style="color:var(--go);font-weight:700">Today</span>';
          elseif($diff<=7) $dueDisplay='<span style="color:var(--go)">'.$diff.'d left</span>';
          else             $dueDisplay=date('M j, Y',strtotime($d['due_date']));
        }
      ?>
      <tr class="<?= $rowCls ?>" onclick="openDetail(<?= $d['id'] ?>)">
        <td>
          <span style="font-family:monospace;font-size:.78rem;font-weight:700;color:var(--ac)"><?= htmlspecialchars($d['debt_number']) ?></span>
        </td>
        <td>
          <div style="font-weight:700;color:var(--tx);font-size:.8rem"><?= htmlspecialchars($cname) ?></div>
          <?php if($d['biz'] && trim($d['customer_name'])): ?>
          <div style="font-size:.68rem;color:var(--t2)"><?= htmlspecialchars($d['biz']) ?></div>
          <?php endif; ?>
        </td>
        <td>
          <?php if($d['sale_number']): ?>
          <a href="sales.php" onclick="event.stopPropagation()" style="font-size:.74rem;color:var(--ac);font-family:monospace;text-decoration:none"><?= htmlspecialchars($d['sale_number']) ?></a>
          <?php else: ?>
          <span style="color:var(--t3)">—</span>
          <?php endif; ?>
        </td>
        <td style="font-weight:700;color:var(--tx)"><?= fmt($orig) ?></td>
        <td style="color:var(--gr);font-weight:600"><?= fmt($paid) ?></td>
        <td>
          <span style="font-weight:800;font-size:.88rem;color:<?= $rem>0?'var(--re)':'var(--gr)' ?>"><?= fmt($rem) ?></span>
        </td>
        <td style="min-width:100px">
          <div style="font-size:.67rem;color:var(--t2);margin-bottom:3px"><?= $pct ?>%</div>
          <div class="pbar-wrap">
            <div class="pbar-fill" style="width:<?= $pct ?>%;background:<?= $barClr ?>"></div>
          </div>
        </td>
        <td><?= $dueDisplay ?></td>
        <td><span class="pill <?= $spill[0] ?>"><?= $spill[1] ?></span></td>
        <td onclick="event.stopPropagation()">
          <div style="display:flex;gap:5px;flex-wrap:nowrap">
            <a href="payments.php" class="btn btn-ghost" style="padding:4px 8px;font-size:.7rem;white-space:nowrap">
              <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" style="width:11px;height:11px"><rect x="1" y="4" width="14" height="9" rx="1.5"/><path d="M1 8h14"/></svg>Pay
            </a>
            <?php if($canEdit && !in_array($d['status'],['paid','written_off'])): ?>
            <button class="btn btn-ghost" style="padding:4px 8px;font-size:.7rem" onclick="openEdit(<?= htmlspecialchars(json_encode($d)) ?>)">
              <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" style="width:11px;height:11px"><path d="M11 2l3 3-9 9H2v-3z"/></svg>Edit
            </button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Write off this debt? This cannot be undone.')">
              <input type="hidden" name="action" value="writeoff">
              <input type="hidden" name="debt_id" value="<?= $d['id'] ?>">
              <button type="submit" class="btn btn-warn" style="padding:4px 8px;font-size:.7rem">
                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" style="width:11px;height:11px"><path d="M2 2l12 12M14 2L2 14"/></svg>W/O
              </button>
            </form>
            <?php endif; ?>
            <?php if($canDelete): ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this debt and all its payments permanently?')">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="debt_id" value="<?= $d['id'] ?>">
              <button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:.7rem">
                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" style="width:11px;height:11px"><path d="M2 4h12M5 4V2h6v2M6 7v4M10 7v4M3 4l1 8h8l1-8"/></svg>
              </button>
            </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if($totalPages>1): ?>
  <div class="pager">
    <span class="pager-info">Showing <?= $offset+1 ?>&ndash;<?= min($offset+$perPage,$total) ?> of <?= $total ?></span>
    <div class="pager-btns">
      <?php if($page>1): ?><a class="pg-btn" href="<?= $qs ?>page=<?= $page-1 ?>">&lsaquo;</a><?php endif; ?>
      <?php for($p=max(1,$page-2);$p<=min($totalPages,$page+2);$p++): ?>
        <a class="pg-btn <?= $p===$page?'on':'' ?>" href="<?= $qs ?>page=<?= $p ?>"><?= $p ?></a>
      <?php endfor; ?>
      <?php if($page<$totalPages): ?><a class="pg-btn" href="<?= $qs ?>page=<?= $page+1 ?>">&rsaquo;</a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php else: ?>
  <div class="empty-state">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4"><circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 2"/></svg>
    <p><?= ($search||$fStatus)?'No debts match your filters.':'No open debts &mdash; all clear!' ?></p>
  </div>
  <?php endif; ?>
</div>

</div><!-- /content -->
</div><!-- /main -->

<!-- ═══ ADD DEBT MODAL ═══ -->
<div class="overlay" id="addOverlay" onclick="if(event.target===this)closeOv('addOverlay')">
  <div class="modal">
    <div class="mhead">
      <div><div class="mtitle">New Debt</div><div class="msub">Create a standalone debt record</div></div>
      <button class="mclose" onclick="closeOv('addOverlay')">&#x2715;</button>
    </div>
    <form method="POST" action="debts.php">
      <input type="hidden" name="action" value="add">
      <div class="mbody">
        <div class="frow2">
          <div class="fgrp">
            <div class="flbl">Customer</div>
            <select name="customer_id" class="fselect">
              <option value="">— Walk-in / No customer —</option>
              <?php
              if($customers_list) {
                $customers_list->data_seek(0);
                while($c=$customers_list->fetch_assoc()):
                  $dn=trim($c['full_name'])?:$c['biz'];
              ?>
              <option value="<?= $c['id'] ?>"><?= htmlspecialchars($dn) ?> &mdash; <?= htmlspecialchars($c['phone']) ?></option>
              <?php endwhile; } ?>
            </select>
          </div>
          <div class="fgrp">
            <div class="flbl">Currency</div>
            <select name="currency" class="fselect">
              <option value="USD">USD ($)</option>
              <option value="EUR">EUR (&euro;)</option>
              <option value="GBP">GBP (&pound;)</option>
              <option value="IQD">IQD (&#x62F;.&#x639;)</option>
            </select>
          </div>
        </div>
        <div class="frow2">
          <div class="fgrp">
            <div class="flbl">Amount <span class="req">*</span></div>
            <input type="number" name="original_amount" class="finput" min="0.01" step="0.01" placeholder="0.00" required>
          </div>
          <div class="fgrp">
            <div class="flbl">Due Date</div>
            <input type="date" name="due_date" class="finput" value="<?= date('Y-m-d',strtotime('+30 days')) ?>">
          </div>
        </div>
        <div class="fgrp">
          <div class="flbl">Notes</div>
          <textarea name="notes" class="ftextarea" placeholder="Reason for debt, reference, etc&hellip;"></textarea>
        </div>
      </div>
      <div class="mfoot">
        <button type="button" class="btn btn-ghost" onclick="closeOv('addOverlay')">Cancel</button>
        <button type="submit" class="btn btn-primary">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M8 1v14M1 8h14"/></svg>
          Create Debt
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ═══ EDIT DEBT MODAL ═══ -->
<div class="overlay" id="editOverlay" onclick="if(event.target===this)closeOv('editOverlay')">
  <div class="modal">
    <div class="mhead">
      <div><div class="mtitle">Edit Debt</div><div class="msub" id="editSub"></div></div>
      <button class="mclose" onclick="closeOv('editOverlay')">&#x2715;</button>
    </div>
    <form method="POST" action="debts.php">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="debt_id" id="editDebtId">
      <div class="mbody">
        <div class="frow2">
          <div class="fgrp">
            <div class="flbl">Amount <span class="req">*</span></div>
            <input type="number" name="original_amount" id="editAmount" class="finput" min="0.01" step="0.01" required>
          </div>
          <div class="fgrp">
            <div class="flbl">Due Date</div>
            <input type="date" name="due_date" id="editDueDate" class="finput">
          </div>
        </div>
        <div class="fgrp">
          <div class="flbl">Status</div>
          <select name="status" id="editStatus" class="fselect">
            <option value="active">Active</option>
            <option value="partially_paid">Partially Paid</option>
            <option value="overdue">Overdue</option>
            <option value="disputed">Disputed</option>
            <option value="paid">Paid</option>
            <option value="written_off">Written Off</option>
          </select>
        </div>
        <div class="fgrp">
          <div class="flbl">Notes</div>
          <textarea name="notes" id="editNotes" class="ftextarea"></textarea>
        </div>
      </div>
      <div class="mfoot">
        <button type="button" class="btn btn-ghost" onclick="closeOv('editOverlay')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══ DETAIL MODAL ═══ -->
<div class="overlay" id="detailOverlay" onclick="if(event.target===this)closeOv('detailOverlay')">
  <div class="modal modal-lg">
    <div class="mhead">
      <div><div class="mtitle" id="detTitle">Debt Details</div><div class="msub" id="detSub"></div></div>
      <button class="mclose" onclick="closeOv('detailOverlay')">&#x2715;</button>
    </div>
    <div style="padding:22px 24px" id="detBody"><div class="spinner"></div></div>
    <div class="mfoot">
      <button class="btn btn-ghost" onclick="closeOv('detailOverlay')">Close</button>
      <a href="payments.php" class="btn btn-primary">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="1" y="4" width="14" height="9" rx="1.5"/><path d="M1 8h14"/></svg>
        Record Payment
      </a>
    </div>
  </div>
</div>

<script>
// Theme
const html=document.documentElement,thi=document.getElementById('thi');
function applyTheme(t){html.dataset.theme=t;if(thi)thi.textContent=t==='dark'?'☀️':'🌙';}
const thBtn=document.getElementById('thm');
if(thBtn) thBtn.onclick=()=>{const nt=html.dataset.theme==='dark'?'light':'dark';applyTheme(nt);localStorage.setItem('pos_theme',nt);};
const sv=localStorage.getItem('pos_theme');if(sv)applyTheme(sv);
document.addEventListener('click',e=>{
  const sb=document.getElementById('sidebar');
  if(sb&&window.innerWidth<=900&&!sb.contains(e.target)&&!e.target.closest('.mob-btn'))sb.classList.remove('open');
});

function xss(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

// Overlays
function openOv(id){document.getElementById(id).classList.add('open');document.body.style.overflow='hidden';}
function closeOv(id){document.getElementById(id).classList.remove('open');document.body.style.overflow='';}

// ── Add modal ─────────────────────────────────────────────────
function openAdd(){openOv('addOverlay');}

// ── Edit modal ────────────────────────────────────────────────
function openEdit(d){
  document.getElementById('editDebtId').value  = d.id;
  document.getElementById('editSub').textContent = d.debt_number;
  document.getElementById('editAmount').value  = parseFloat(d.original_amount).toFixed(2);
  document.getElementById('editDueDate').value = d.due_date||'';
  document.getElementById('editStatus').value  = d.status||'active';
  document.getElementById('editNotes').value   = d.notes||'';
  openOv('editOverlay');
}

// ── Detail modal ──────────────────────────────────────────────
async function openDetail(id){
  document.getElementById('detBody').innerHTML='<div class="spinner"></div>';
  document.getElementById('detTitle').textContent='Debt Details';
  document.getElementById('detSub').textContent='Loading…';
  openOv('detailOverlay');
  try{
    const r=await fetch(`debts.php?ajax=detail&id=${id}`);
    const d=await r.json();
    if(!d.ok){document.getElementById('detBody').innerHTML='<p style="color:var(--re)">Failed to load.</p>';return;}
    buildDetail(d.debt, d.payments);
  }catch{document.getElementById('detBody').innerHTML='<p style="color:var(--re)">Error.</p>';}
}

function buildDetail(debt, payments){
  const cname = (debt.customer_name?.trim()||debt.biz||'Walk-in');
  document.getElementById('detTitle').textContent = cname;
  document.getElementById('detSub').textContent   = debt.debt_number+' · '+debt.status.replace(/_/g,' ');

  const orig = parseFloat(debt.original_amount)||0;
  const paid = parseFloat(debt.paid_amount)||0;
  const rem  = parseFloat(debt.remaining_amount)||0;
  const pct  = orig>0 ? Math.min(100,(paid/orig*100)) : 0;
  const today = new Date().toISOString().slice(0,10);
  const isPast = debt.due_date && debt.due_date < today && !['paid','written_off'].includes(debt.status);
  const barClr = pct>=100?'var(--gr)':pct>=50?'var(--go)':'var(--re)';

  // Status pill
  const sMap={active:['info','Active'],partially_paid:['warn','Partially Paid'],
    overdue:['err','Overdue'],paid:['ok','Paid'],written_off:['nt','Written Off'],disputed:['pu','Disputed']};
  const sp = isPast ? ['err','Overdue'] : (sMap[debt.status]||['nt',debt.status]);

  // Payment history
  let histHtml = payments.length
    ? payments.map(p=>`
      <div style="display:flex;justify-content:space-between;align-items:center;background:var(--bg3);border-radius:9px;padding:10px 13px;margin-bottom:6px">
        <div>
          <div style="font-size:.78rem;font-weight:700;color:var(--tx)">${xss(p.payment_number)}</div>
          <div style="font-size:.68rem;color:var(--t2);margin-top:2px">${p.payment_date}${p.recv_name?' · by '+xss(p.recv_name):''}${p.notes?' · '+xss(p.notes):''}</div>
        </div>
        <div style="font-size:.9rem;font-weight:800;color:var(--gr)">+$${parseFloat(p.amount).toFixed(2)}</div>
      </div>`).join('')
    : '<div style="padding:16px 0;text-align:center;color:var(--t3);font-size:.78rem">No payments recorded yet.</div>';

  document.getElementById('detBody').innerHTML=`
    <!-- Amount cards -->
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:16px">
      <div style="background:var(--bg3);border-radius:10px;padding:14px;text-align:center">
        <div style="font-size:.6rem;color:var(--t3);text-transform:uppercase;letter-spacing:.09em;font-weight:700;margin-bottom:6px">Original</div>
        <div style="font-size:1.15rem;font-weight:800;color:var(--tx)">$${orig.toFixed(2)}</div>
      </div>
      <div style="background:var(--grb,rgba(52,211,153,.12));border-radius:10px;padding:14px;text-align:center">
        <div style="font-size:.6rem;color:var(--t3);text-transform:uppercase;letter-spacing:.09em;font-weight:700;margin-bottom:6px">Paid</div>
        <div style="font-size:1.15rem;font-weight:800;color:var(--gr)">$${paid.toFixed(2)}</div>
      </div>
      <div style="background:${rem>0?'rgba(248,113,113,.1)':'rgba(52,211,153,.1)'};border-radius:10px;padding:14px;text-align:center">
        <div style="font-size:.6rem;color:var(--t3);text-transform:uppercase;letter-spacing:.09em;font-weight:700;margin-bottom:6px">Remaining</div>
        <div style="font-size:1.15rem;font-weight:800;color:${rem>0?'var(--re)':'var(--gr)'}">$${rem.toFixed(2)}</div>
      </div>
    </div>
    <!-- Progress -->
    <div style="margin-bottom:16px">
      <div style="display:flex;justify-content:space-between;font-size:.68rem;color:var(--t2);margin-bottom:5px">
        <span>Payment Progress</span><span>${pct.toFixed(1)}% paid</span>
      </div>
      <div style="height:9px;background:var(--br);border-radius:5px;overflow:hidden">
        <div style="height:100%;width:${pct}%;background:${barClr};border-radius:5px;transition:width .8s cubic-bezier(.16,1,.3,1)"></div>
      </div>
    </div>
    <!-- Info rows -->
    <div style="background:var(--bg3);border-radius:11px;padding:14px 16px;margin-bottom:16px">
      <div class="det-row"><span class="dl">Debt Number</span><span class="dv" style="font-family:monospace;color:var(--ac)">${xss(debt.debt_number)}</span></div>
      <div class="det-row"><span class="dl">Customer</span><span class="dv">${xss(cname)}${debt.cphone?'<span style="font-size:.72rem;color:var(--t2);margin-left:8px">'+xss(debt.cphone)+'</span>':''}</span></div>
      ${debt.sale_number?`<div class="det-row"><span class="dl">Linked Sale</span><span class="dv" style="font-family:monospace;color:var(--ac)">${xss(debt.sale_number)}</span></div>`:''}
      <div class="det-row"><span class="dl">Status</span><span class="dv"><span class="pill ${sp[0]}">${sp[1]}</span></span></div>
      ${debt.due_date?`<div class="det-row"><span class="dl">Due Date</span><span class="dv" style="color:${isPast?'var(--re)':'var(--tx)'}">${debt.due_date}${isPast?' <span style="color:var(--re);font-size:.72rem">OVERDUE</span>':''}</span></div>`:''}
      ${debt.currency&&debt.currency!=='USD'?`<div class="det-row"><span class="dl">Currency</span><span class="dv">${xss(debt.currency)}</span></div>`:''}
      ${debt.created_by?`<div class="det-row"><span class="dl">Created By</span><span class="dv">${xss(debt.creator_name||'')}</span></div>`:''}
      ${debt.notes?`<div class="det-row"><span class="dl">Notes</span><span class="dv" style="max-width:320px;text-align:right;word-break:break-word">${xss(debt.notes)}</span></div>`:''}
    </div>
    <!-- Payment history -->
    <div>
      <div style="font-size:.68rem;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.09em;margin-bottom:10px">
        Payment History (${payments.length})
      </div>
      ${histHtml}
    </div>`;
}

// Flash dismiss
setTimeout(()=>{
  const f=document.getElementById('flashMsg');
  if(f){f.style.transition='opacity .5s';f.style.opacity='0';setTimeout(()=>f?.remove(),520);}
},5000);

// Search on enter
document.getElementById('searchInp')?.addEventListener('keydown',function(e){
  if(e.key==='Enter')this.form.submit();
  if(e.key==='Escape'){this.value='';this.form.submit();}
});
</script>
</body>
</html>
