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
function genNum($conn){
    $today=date('Ymd');
    $r=$conn->query("SELECT MAX(expense_number) AS mx FROM expenses WHERE expense_number LIKE 'EXP{$today}%'");
    $mx=$r?($r->fetch_assoc()['mx']??null):null;
    $seq=$mx?((int)substr($mx,-4)+1):1;
    return 'EXP'.$today.str_pad($seq,4,'0',STR_PAD_LEFT);
}
$flash=$flashType='';

// ── POST: add ──────────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='add' && $canEdit){
    $title    = trim($_POST['title']??'');
    $desc     = esc($conn, trim($_POST['description']??''));
    $amount   = floatval($_POST['amount']??0);
    $currency = esc($conn, $_POST['currency']??'USD');
    $edate    = $_POST['expense_date']??date('Y-m-d');
    $notes    = esc($conn, trim($_POST['notes']??''));
    $category = esc($conn, trim($_POST['category']??''));

    if(!$title||$amount<=0){ $flash="Title and amount are required."; $flashType='err'; }
    else{
        $enum  = genNum($conn);
        $etitle= esc($conn,$title);
        $ecat  = $category ? "'$category'" : 'NULL';
        $approved_by = 'NULL';
        $approved_at = 'NULL';
        // admin auto-approves
        if($_SESSION['role']==='admin'){
            $approved_by = $uid;
            $approved_at = "'".date('Y-m-d H:i:s')."'";
        }
        if($conn->query("INSERT INTO expenses(expense_number,title,description,amount,currency,
            expense_date,approved_by,approved_at,notes,created_by)
            VALUES('$enum','$etitle','$desc',$amount,'$currency','$edate',
            $approved_by,$approved_at,'$notes',$uid)")){
            $new_id=$conn->insert_id;
            $conn->query("INSERT INTO audit_logs(user_id,user_name,action,table_name,record_id,description)
                VALUES($uid,'$user','CREATE','expenses',$new_id,'Expense $enum: $title (\$$amount) created')");
            $flash="Expense <strong>$enum</strong> added."; $flashType='ok';
        } else { $flash="Error: ".htmlspecialchars($conn->error); $flashType='err'; }
    }
    header("Location: expenses.php?flash=".urlencode($flash)."&ft=$flashType"); exit;
}

// ── POST: edit ─────────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='edit' && $canEdit){
    $eid   = (int)($_POST['expense_id']??0);
    $title = esc($conn,trim($_POST['title']??''));
    $desc  = esc($conn,trim($_POST['description']??''));
    $amount= floatval($_POST['amount']??0);
    $edate = $_POST['expense_date']??date('Y-m-d');
    $notes = esc($conn,trim($_POST['notes']??''));
    if($eid&&$title&&$amount>0){
        $conn->query("UPDATE expenses SET title='$title',description='$desc',
            amount=$amount,expense_date='$edate',notes='$notes' WHERE id=$eid");
        $conn->query("INSERT INTO audit_logs(user_id,user_name,action,table_name,record_id,description)
            VALUES($uid,'$user','UPDATE','expenses',$eid,'Expense #$eid updated')");
        $flash="Expense updated."; $flashType='ok';
    }
    header("Location: expenses.php?flash=".urlencode($flash)."&ft=$flashType"); exit;
}

// ── POST: approve ──────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='approve' && $_SESSION['role']==='admin'){
    $eid=(int)($_POST['expense_id']??0);
    if($eid){
        $now=date('Y-m-d H:i:s');
        $conn->query("UPDATE expenses SET approved_by=$uid,approved_at='$now' WHERE id=$eid AND approved_by IS NULL");
        $conn->query("INSERT INTO audit_logs(user_id,user_name,action,table_name,record_id,description)
            VALUES($uid,'$user','APPROVE','expenses',$eid,'Expense #$eid approved')");
        $flash="Expense approved."; $flashType='ok';
    }
    header("Location: expenses.php?flash=".urlencode($flash)."&ft=$flashType"); exit;
}

// ── POST: delete ───────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='delete' && $canDelete){
    $eid=(int)($_POST['expense_id']??0);
    if($eid){
        $conn->query("DELETE FROM expenses WHERE id=$eid");
        $conn->query("INSERT INTO audit_logs(user_id,user_name,action,table_name,record_id,description)
            VALUES($uid,'$user','DELETE','expenses',$eid,'Expense #$eid deleted')");
        $flash="Expense deleted."; $flashType='warn';
    }
    header("Location: expenses.php?flash=".urlencode($flash)."&ft=$flashType"); exit;
}

if(isset($_GET['flash'])){ $flash=urldecode($_GET['flash']); $flashType=$_GET['ft']??'ok'; }

// ── FILTERS ────────────────────────────────────────────────────
$search   = trim($_GET['q']??'');
$fMonth   = (int)($_GET['month']??date('n'));
$fYear    = (int)($_GET['year']??date('Y'));
$fApproved= $_GET['approved']??'';
$fSort    = $_GET['sort']??'expense_date';
$page     = max(1,(int)($_GET['page']??1));
$perPage  = 20;
$offset   = ($page-1)*$perPage;

$allowedSorts=['expense_date','amount','title','created_at'];
if(!in_array($fSort,$allowedSorts)) $fSort='expense_date';

$w="1=1";
if($search){ $s=esc($conn,$search); $w.=" AND (e.title LIKE '%$s%' OR e.expense_number LIKE '%$s%' OR e.description LIKE '%$s%' OR e.notes LIKE '%$s%')"; }
if($fMonth) $w.=" AND MONTH(e.expense_date)=$fMonth";
if($fYear)  $w.=" AND YEAR(e.expense_date)=$fYear";
if($fApproved==='1')  $w.=" AND e.approved_by IS NOT NULL";
if($fApproved==='0')  $w.=" AND e.approved_by IS NULL";

$sortDir = $fSort==='amount'?'DESC':'DESC';
$tc=$conn->query("SELECT COUNT(*) AS c FROM expenses e WHERE $w");
$total=$tc?(int)$tc->fetch_assoc()['c']:0;
$totalPages=max(1,(int)ceil($total/$perPage));

$expenses=$conn->query("SELECT e.*,
    ua.username AS approved_by_name,
    uc.username AS created_by_name
    FROM expenses e
    LEFT JOIN users ua ON ua.id=e.approved_by
    LEFT JOIN users uc ON uc.id=e.created_by
    WHERE $w ORDER BY e.$fSort $sortDir LIMIT $perPage OFFSET $offset");

// Stats
function sv($c,$q){$r=$c->query($q);return $r?($r->fetch_assoc()['v']??0):0;}
$cm=$fMonth; $cy=$fYear;
$stat_this_month  =(float)sv($conn,"SELECT COALESCE(SUM(amount),0) AS v FROM expenses WHERE MONTH(expense_date)=$cm AND YEAR(expense_date)=$cy");
$stat_this_year   =(float)sv($conn,"SELECT COALESCE(SUM(amount),0) AS v FROM expenses WHERE YEAR(expense_date)=$cy");
$stat_total       =(float)sv($conn,"SELECT COALESCE(SUM(amount),0) AS v FROM expenses");
$stat_pending     =(int)sv($conn,"SELECT COUNT(*) AS v FROM expenses WHERE approved_by IS NULL");
$stat_count_month =(int)sv($conn,"SELECT COUNT(*) AS v FROM expenses WHERE MONTH(expense_date)=$cm AND YEAR(expense_date)=$cy");
$stat_avg_month   = $stat_count_month>0 ? round($stat_this_month/$stat_count_month,2) : 0;

// Monthly chart data (last 12 months)
$chart_r=$conn->query("SELECT YEAR(expense_date) AS yr, MONTH(expense_date) AS mn,
    SUM(amount) AS total, COUNT(*) AS cnt
    FROM expenses WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY yr,mn ORDER BY yr,mn");
$chart_data=[];
if($chart_r) while($row=$chart_r->fetch_assoc()) $chart_data[]=$row;

// Month options
$months=['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

function buildQS($ov=[]){
    $p=array_merge($_GET,$ov); unset($p['page']);
    $qs=http_build_query(array_filter($p,fn($v)=>$v!==''));
    return $qs?"?$qs&":"?";
}
$qs=buildQS();
$current_page='expenses';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SalesOS &mdash; Expenses</title>
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
body{background:var(--bg);color:var(--tx);font-family:'Plus Jakarta Sans',sans-serif;font-size:.875rem;min-height:100vh;display:flex;transition:background .4s,color .4s}
/* SIDEBAR */
.sidebar{width:248px;min-height:100vh;background:var(--bg2);border-right:1px solid var(--br);display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;transition:transform .3s}
.slogo{display:flex;align-items:center;gap:11px;padding:22px 20px;border-bottom:1px solid var(--br)}
.logo-icon{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,var(--ac),var(--ac2));display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px var(--ag);flex-shrink:0}
.logo-icon svg{width:18px;height:18px}
.logo-txt{font-size:1.1rem;font-weight:800;color:var(--tx);letter-spacing:-.02em}
.logo-txt span{color:var(--ac)}
.nav-sec{padding:14px 12px 4px}
.nav-lbl{font-size:.62rem;color:var(--t3);letter-spacing:.12em;text-transform:uppercase;padding:0 8px 8px;display:block;font-weight:600}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:9px;color:var(--t2);text-decoration:none;transition:all .15s;margin-bottom:1px;position:relative;font-size:.82rem;font-weight:500}
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
/* TOPNAV */
.topnav{height:58px;background:var(--bg2);border-bottom:1px solid var(--br);position:sticky;top:0;z-index:50;display:flex;align-items:center;padding:0 28px;gap:14px;transition:background .4s}
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
/* LAYOUT */
.main{margin-left:248px;flex:1;display:flex;flex-direction:column;min-height:100vh}
.content{padding:26px 28px;display:flex;flex-direction:column;gap:20px}
/* STATS */
.stats-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:14px}
.stat-card{background:var(--bg2);border:1px solid var(--br);border-radius:13px;padding:18px 20px;transition:all .2s;animation:fadeUp .4s cubic-bezier(.16,1,.3,1) both}
.stat-card:hover{transform:translateY(-2px);box-shadow:var(--sh2)}
.stat-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.stat-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center}
.stat-icon svg{width:18px;height:18px}
.stat-badge{font-size:.63rem;font-weight:700;padding:3px 8px;border-radius:100px}
.stat-val{font-size:1.45rem;font-weight:800;color:var(--tx);letter-spacing:-.04em;line-height:1}
.stat-lbl{font-size:.71rem;color:var(--t2);margin-top:5px;font-weight:500}
@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
/* TOOLBAR */
.page-hdr{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}
.ph-title{font-size:1.45rem;font-weight:800;color:var(--tx);letter-spacing:-.03em}
.ph-sub{font-size:.78rem;color:var(--t2);margin-top:4px}
.toolbar{display:flex;align-items:center;gap:9px;flex-wrap:wrap}
.searchbox{display:flex;align-items:center;gap:8px;background:var(--bg2);border:1px solid var(--br);border-radius:10px;padding:8px 14px;flex:1;min-width:200px;max-width:300px;transition:border-color .2s}
.searchbox:focus-within{border-color:var(--ac)}
.searchbox svg{width:13px;height:13px;color:var(--t3);flex-shrink:0}
.searchbox input{background:none;border:none;outline:none;font-family:inherit;font-size:.8rem;color:var(--tx);width:100%}
.searchbox input::placeholder{color:var(--t3)}
.fsel{background:var(--bg2);border:1px solid var(--br);border-radius:10px;padding:8px 11px;font-family:inherit;font-size:.79rem;color:var(--tx);outline:none;cursor:pointer;transition:border-color .2s}
.fsel:focus{border-color:var(--ac)}
.tbr{margin-left:auto;display:flex;align-items:center;gap:8px}
.count-lbl{font-size:.74rem;color:var(--t2)}
/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:9px;font-family:inherit;font-size:.79rem;font-weight:700;cursor:pointer;border:none;transition:all .18s;text-decoration:none}
.btn-primary{background:var(--ac);color:#fff}.btn-primary:hover{background:var(--ac2);transform:translateY(-1px)}
.btn-ghost{background:var(--bg2);border:1px solid var(--br);color:var(--t2)}.btn-ghost:hover{color:var(--tx);border-color:var(--br2)}
.btn-success{background:var(--grd);border:1px solid rgba(52,211,153,.3);color:var(--gr)}.btn-success:hover{background:var(--grb)}
.btn-danger{background:var(--red);border:1px solid rgba(248,113,113,.3);color:var(--re)}.btn-danger:hover{background:var(--reb)}
.btn svg{width:13px;height:13px}
/* TWO-PANEL LAYOUT */
.main-grid{display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start}
/* CHART PANEL */
.panel{background:var(--bg2);border:1px solid var(--br);border-radius:14px;overflow:hidden}
.panel-head{padding:17px 22px;border-bottom:1px solid var(--br);display:flex;align-items:center;justify-content:space-between}
.panel-title{font-size:.9rem;font-weight:800;color:var(--tx);display:flex;align-items:center;gap:8px}
.panel-title svg{width:15px;height:15px;color:var(--ac)}
.panel-sub{font-size:.69rem;color:var(--t3);margin-top:2px}
.panel-body{padding:20px 22px}
/* SPARKLINE BARS */
.spark-wrap{display:flex;align-items:flex-end;gap:6px;height:72px}
.spark-bar{flex:1;border-radius:4px 4px 0 0;background:var(--ag);border:1px solid rgba(79,142,247,.15);transition:background .2s;cursor:default;position:relative;min-height:4px}
.spark-bar:hover{background:var(--ac)}
.spark-bar.current{background:rgba(79,142,247,.35);border-color:rgba(79,142,247,.5)}
.spark-bar::after{content:attr(title);position:absolute;bottom:calc(100% + 6px);left:50%;transform:translateX(-50%);background:var(--bg4);border:1px solid var(--br2);border-radius:7px;padding:4px 9px;font-size:.63rem;color:var(--tx);white-space:nowrap;opacity:0;pointer-events:none;transition:opacity .15s;font-family:inherit;z-index:10}
.spark-bar:hover::after{opacity:1}
.spark-lbls{display:flex;justify-content:space-between;font-size:.6rem;color:var(--t3);margin-top:6px;font-weight:600}
/* CATEGORY BREAKDOWN */
.cat-item{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--br)}
.cat-item:last-child{border:none}
.cat-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0}
.cat-info{flex:1;min-width:0}
.cat-name{font-size:.78rem;font-weight:600;color:var(--tx)}
.cat-bar-wrap{height:4px;background:var(--br);border-radius:2px;overflow:hidden;margin-top:4px}
.cat-bar-fill{height:100%;border-radius:2px}
.cat-amt{font-size:.8rem;font-weight:700;color:var(--tx);flex-shrink:0}
/* TABLE */
.dtbl{width:100%;border-collapse:collapse}
.dtbl th{font-size:.62rem;color:var(--t3);text-transform:uppercase;letter-spacing:.1em;padding:10px 14px;text-align:left;border-bottom:1px solid var(--br);font-weight:700;white-space:nowrap}
.dtbl td{padding:12px 14px;font-size:.8rem;border-bottom:1px solid var(--br);vertical-align:middle}
.dtbl tr:last-child td{border-bottom:none}
.dtbl tbody tr{transition:background .1s}
.dtbl tbody tr:hover td{background:rgba(255,255,255,.025)}
[data-theme="light"] .dtbl tbody tr:hover td{background:rgba(0,0,0,.02)}
/* PILLS */
.pill{display:inline-flex;align-items:center;padding:2px 9px;border-radius:100px;font-size:.64rem;font-weight:700;white-space:nowrap}
.pill.ok  {background:var(--grd);color:var(--gr)}
.pill.warn{background:var(--god);color:var(--go)}
.pill.err {background:var(--red);color:var(--re)}
.pill.info{background:var(--ag); color:var(--ac)}
.pill.pu  {background:var(--pud);color:var(--pu)}
.pill.nt  {background:var(--bg3);color:var(--t3);border:1px solid var(--br)}
/* FLASH */
.flash{display:flex;align-items:center;gap:10px;padding:11px 16px;border-radius:10px;font-size:.82rem;font-weight:500;animation:fadeUp .35s cubic-bezier(.16,1,.3,1)}
.flash.ok  {background:var(--grd);border:1px solid rgba(52,211,153,.2);color:var(--gr)}
.flash.warn{background:var(--god);border:1px solid rgba(245,166,35,.2);color:var(--go)}
.flash.err {background:var(--red);border:1px solid rgba(248,113,113,.2);color:var(--re)}
.flash svg{width:15px;height:15px;flex-shrink:0}
/* PAGINATION */
.pager{display:flex;align-items:center;justify-content:space-between;padding:13px 18px;border-top:1px solid var(--br)}
.pager-info{font-size:.74rem;color:var(--t2)}
.pager-btns{display:flex;gap:4px}
.pg-btn{min-width:32px;height:32px;padding:0 8px;border-radius:8px;border:1px solid var(--br);background:var(--bg3);color:var(--t2);font-family:inherit;font-size:.77rem;font-weight:600;cursor:pointer;transition:all .15s;text-decoration:none;display:flex;align-items:center;justify-content:center}
.pg-btn:hover{border-color:var(--br2);color:var(--tx)}
.pg-btn.on{background:var(--ac);border-color:var(--ac);color:#fff}
/* MODAL */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:400;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .25s;backdrop-filter:blur(6px)}
.overlay.open{opacity:1;pointer-events:all}
.modal{background:var(--bg2);border:1px solid var(--br2);border-radius:18px;width:100%;max-width:580px;max-height:92vh;overflow-y:auto;box-shadow:var(--sh2);transform:translateY(20px) scale(.97);transition:transform .3s cubic-bezier(.16,1,.3,1)}
.overlay.open .modal{transform:none}
.mhead{display:flex;align-items:center;justify-content:space-between;padding:20px 24px;border-bottom:1px solid var(--br);position:sticky;top:0;background:var(--bg2);z-index:1}
.mtitle{font-size:.95rem;font-weight:800;color:var(--tx)}
.msub{font-size:.71rem;color:var(--t2);margin-top:2px}
.mclose{width:30px;height:30px;border-radius:7px;border:1px solid var(--br);background:transparent;color:var(--t2);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:1.1rem;transition:all .15s}
.mclose:hover{background:var(--red);color:var(--re)}
.mbody{padding:22px 24px;display:flex;flex-direction:column;gap:13px}
.mfoot{padding:14px 24px;border-top:1px solid var(--br);display:flex;justify-content:flex-end;gap:10px;position:sticky;bottom:0;background:var(--bg2)}
/* FORM */
.frow2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.fgrp{display:flex;flex-direction:column;gap:5px}
.flbl{font-size:.68rem;font-weight:700;color:var(--t2);text-transform:uppercase;letter-spacing:.04em}
.flbl .req{color:var(--re)}
.finput,.fselect,.ftextarea{width:100%;background:var(--bg3);border:1.5px solid var(--br);border-radius:9px;padding:9px 12px;font-family:inherit;font-size:.82rem;color:var(--tx);outline:none;transition:border-color .2s}
.finput:focus,.fselect:focus,.ftextarea:focus{border-color:var(--ac);background:var(--bg)}
.finput::placeholder,.ftextarea::placeholder{color:var(--t3)}
.ftextarea{resize:vertical;min-height:64px}
/* AMOUNT DISPLAY */
.amt-display{background:var(--bg3);border-radius:11px;padding:14px 16px;text-align:center}
.amt-val{font-size:1.8rem;font-weight:800;letter-spacing:-.04em}
/* EMPTY */
.empty-state{padding:52px 20px;text-align:center;color:var(--t3)}
.empty-state svg{width:38px;height:38px;margin:0 auto 12px;opacity:.28;display:block}
.empty-state p{font-size:.81rem}
/* RESPONSIVE */
@media(max-width:1300px){.stats-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:1040px){.main-grid{grid-template-columns:1fr}}
@media(max-width:900px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:none}.main{margin-left:0}.mob-btn{display:block}.sbar{display:none}}
@media(max-width:640px){.stats-grid{grid-template-columns:repeat(2,1fr)}.content{padding:16px}.frow2{grid-template-columns:1fr}}
</style>
</head>
<body>

<?php $current_page = 'expenses'; include 'sidebar.php'; ?>

<div class="main">
<?php $breadcrumbs=[['label'=>'HR & Finance'],['label'=>'Expenses']]; include 'topnav.php'; ?>

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
    <div class="ph-title">Expenses</div>
    <div class="ph-sub">Track and manage company expenditures</div>
  </div>
  <?php if($canEdit): ?>
  <button class="btn btn-primary" onclick="openAdd()">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M8 1v14M1 8h14"/></svg>
    Add Expense
  </button>
  <?php endif; ?>
</div>

<!-- Stats -->
<div class="stats-grid">
  <div class="stat-card" style="--dl:.0s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--red)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--re)" stroke-width="1.8"><path d="M2 2h12v12H2z"/><path d="M5 8h6M5 5h6M5 11h3"/></svg></div>
      <span class="stat-badge" style="background:var(--god);color:var(--go)"><?= $months[$fMonth] ?> <?= $fYear ?></span>
    </div>
    <div class="stat-val"><?= fmt($stat_this_month) ?></div>
    <div class="stat-lbl">This Month (<?= $stat_count_month ?> expenses)</div>
  </div>
  <div class="stat-card" style="--dl:.06s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--god)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--go)" stroke-width="1.8"><path d="M8 1v14M4 5l4-4 4 4"/></svg></div>
      <span class="stat-badge" style="background:var(--god);color:var(--go)"><?= $fYear ?></span>
    </div>
    <div class="stat-val"><?= fmt($stat_this_year) ?></div>
    <div class="stat-lbl">This Year</div>
  </div>
  <div class="stat-card" style="--dl:.12s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--ag)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--ac)" stroke-width="1.8"><path d="M3 10l3 3 4-5 4 4"/></svg></div>
      <span class="stat-badge" style="background:var(--ag);color:var(--ac)">All-time</span>
    </div>
    <div class="stat-val"><?= fmt($stat_total) ?></div>
    <div class="stat-lbl">Total Spent</div>
  </div>
  <div class="stat-card" style="--dl:.18s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--god)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--go)" stroke-width="1.8"><path d="M8 1a7 7 0 100 14A7 7 0 008 1z"/><path d="M6 6v4M10 6v4"/></svg></div>
      <span class="stat-badge" style="background:var(--red);color:var(--re)"><?= $stat_pending ?> pending</span>
    </div>
    <div class="stat-val"><?= $stat_pending ?></div>
    <div class="stat-lbl">Awaiting Approval</div>
  </div>
  <div class="stat-card" style="--dl:.24s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--pud)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--pu)" stroke-width="1.8"><path d="M8 1v14M4 12l4 3 4-3"/></svg></div>
      <span class="stat-badge" style="background:var(--pud);color:var(--pu)">Avg</span>
    </div>
    <div class="stat-val"><?= fmt($stat_avg_month) ?></div>
    <div class="stat-lbl">Avg per Expense</div>
  </div>
  <div class="stat-card" style="--dl:.3s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--ag)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--ac)" stroke-width="1.8"><path d="M2 4h12M2 8h9M2 12h6"/></svg></div>
      <span class="stat-badge" style="background:var(--ag);color:var(--ac)">Filtered</span>
    </div>
    <div class="stat-val"><?= $total ?></div>
    <div class="stat-lbl">Results</div>
  </div>
</div>

<!-- Main grid -->
<div class="main-grid">

  <!-- LEFT: Table + filters -->
  <div style="display:flex;flex-direction:column;gap:16px">

    <!-- Toolbar -->
    <form method="GET" action="expenses.php" id="filterForm">
      <div class="toolbar">
        <div class="searchbox">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="7" cy="7" r="4.5"/><path d="M11 11l3 3"/></svg>
          <input type="text" name="q" placeholder="Search title, description&hellip;" value="<?= htmlspecialchars($search) ?>" id="searchInp">
        </div>
        <select name="month" class="fsel" onchange="this.form.submit()">
          <?php for($m=1;$m<=12;$m++): ?>
          <option value="<?= $m ?>" <?= $fMonth===$m?'selected':'' ?>><?= $months[$m] ?></option>
          <?php endfor; ?>
        </select>
        <select name="year" class="fsel" onchange="this.form.submit()">
          <?php for($y=date('Y');$y>=date('Y')-4;$y--): ?>
          <option value="<?= $y ?>" <?= $fYear===$y?'selected':'' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
        <select name="approved" class="fsel" onchange="this.form.submit()">
          <option value="">All</option>
          <option value="1" <?= $fApproved==='1'?'selected':'' ?>>Approved</option>
          <option value="0" <?= $fApproved==='0'?'selected':'' ?>>Pending</option>
        </select>
        <select name="sort" class="fsel" onchange="this.form.submit()">
          <option value="expense_date" <?= $fSort==='expense_date'?'selected':'' ?>>Date</option>
          <option value="amount"       <?= $fSort==='amount'?'selected':'' ?>>Amount</option>
          <option value="title"        <?= $fSort==='title'?'selected':'' ?>>Title</option>
          <option value="created_at"   <?= $fSort==='created_at'?'selected':'' ?>>Added</option>
        </select>
        <button type="submit" class="btn btn-primary" style="padding:8px 14px">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="7" cy="7" r="4.5"/><path d="M11 11l3 3"/></svg>Search
        </button>
        <?php if($search||$fApproved!==''||$fSort!=='expense_date'): ?>
        <a href="expenses.php?month=<?= $fMonth ?>&year=<?= $fYear ?>" class="btn btn-ghost" style="padding:8px 14px">Clear</a>
        <?php endif; ?>
        <div class="tbr"><span class="count-lbl"><?= number_format($total) ?> result<?= $total!=1?'s':'' ?></span></div>
      </div>
    </form>

    <!-- Table -->
    <div class="panel">
      <?php if($expenses && $expenses->num_rows>0): ?>
      <div style="overflow-x:auto">
        <table class="dtbl">
          <thead>
            <tr>
              <th>Expense #</th>
              <th>Title</th>
              <th>Date</th>
              <th>Amount</th>
              <th>Currency</th>
              <th>Approval</th>
              <th>Added By</th>
              <?php if($canEdit): ?><th>Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
          <?php while($e=$expenses->fetch_assoc()): ?>
          <tr onclick="openDetail(<?= htmlspecialchars(json_encode($e),ENT_QUOTES) ?>)" style="cursor:pointer">
            <td>
              <span style="font-family:monospace;font-size:.76rem;color:var(--ac);font-weight:700"><?= htmlspecialchars($e['expense_number']) ?></span>
            </td>
            <td>
              <div style="font-weight:700;color:var(--tx);font-size:.81rem"><?= htmlspecialchars($e['title']) ?></div>
              <?php if($e['description']): ?>
              <div style="font-size:.68rem;color:var(--t2);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px"><?= htmlspecialchars($e['description']) ?></div>
              <?php endif; ?>
            </td>
            <td style="color:var(--t2);white-space:nowrap"><?= date('M j, Y',strtotime($e['expense_date'])) ?></td>
            <td>
              <span style="font-size:.92rem;font-weight:800;color:var(--re)">-<?= fmt($e['amount']) ?></span>
            </td>
            <td style="color:var(--t2);font-size:.75rem"><?= htmlspecialchars($e['currency']) ?></td>
            <td>
              <?php if($e['approved_by']): ?>
                <span class="pill ok">&#10003; Approved</span>
                <div style="font-size:.66rem;color:var(--t2);margin-top:3px"><?= htmlspecialchars($e['approved_by_name']??'') ?></div>
              <?php else: ?>
                <span class="pill warn">Pending</span>
              <?php endif; ?>
            </td>
            <td style="color:var(--t2);font-size:.76rem"><?= htmlspecialchars($e['created_by_name']??'—') ?></td>
            <?php if($canEdit): ?>
            <td onclick="event.stopPropagation()">
              <div style="display:flex;gap:5px">
                <button class="btn btn-ghost" style="padding:4px 8px;font-size:.7rem" onclick="openEdit(<?= htmlspecialchars(json_encode($e),ENT_QUOTES) ?>)">
                  <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" style="width:11px;height:11px"><path d="M11 2l3 3-9 9H2v-3z"/></svg>Edit
                </button>
                <?php if(!$e['approved_by'] && $_SESSION['role']==='admin'): ?>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="action" value="approve">
                  <input type="hidden" name="expense_id" value="<?= $e['id'] ?>">
                  <button type="submit" class="btn btn-success" style="padding:4px 8px;font-size:.7rem">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" style="width:11px;height:11px"><circle cx="8" cy="8" r="7"/><path d="M5 8l2 2 4-4"/></svg>Approve
                  </button>
                </form>
                <?php endif; ?>
                <?php if($canDelete): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this expense permanently?')">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="expense_id" value="<?= $e['id'] ?>">
                  <button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:.7rem">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" style="width:11px;height:11px"><path d="M2 4h12M5 4V2h6v2M6 7v4M10 7v4M3 4l1 8h8l1-8"/></svg>
                  </button>
                </form>
                <?php endif; ?>
              </div>
            </td>
            <?php endif; ?>
          </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>

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
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M2 2h12v12H2z"/><path d="M5 8h6M5 5h6M5 11h3"/></svg>
        <p><?= $search?'No expenses match your search.':'No expenses recorded for this period.' ?></p>
      </div>
      <?php endif; ?>
    </div>
  </div><!-- /left -->

  <!-- RIGHT: Chart + breakdown -->
  <div style="display:flex;flex-direction:column;gap:16px;position:sticky;top:78px">

    <!-- Monthly sparkline -->
    <div class="panel">
      <div class="panel-head">
        <div>
          <div class="panel-title">
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12l4-4 3 3 4-5 3 2"/></svg>
            Monthly Spending
          </div>
          <div class="panel-sub">Last 12 months</div>
        </div>
      </div>
      <div class="panel-body">
        <?php
        $maxAmt = 1;
        foreach($chart_data as $cd) if(floatval($cd['total'])>$maxAmt) $maxAmt=floatval($cd['total']);
        $monthAbbr=['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        ?>
        <div class="spark-wrap">
          <?php if(count($chart_data)>0): foreach($chart_data as $cd):
            $pct=max(4,round(floatval($cd['total'])/$maxAmt*100));
            $isCur=((int)$cd['mn']===$fMonth && (int)$cd['yr']===$fYear);
            $lbl=($monthAbbr[(int)$cd['mn']]??'').' '.number_format(floatval($cd['total']),0);
          ?>
          <div class="spark-bar <?= $isCur?'current':'' ?>" style="height:<?= $pct ?>%" title="<?= htmlspecialchars($lbl) ?>"></div>
          <?php endforeach; else: for($i=0;$i<8;$i++): ?>
          <div class="spark-bar" style="height:5%"></div>
          <?php endfor; endif; ?>
        </div>
        <div class="spark-lbls">
          <?php foreach($chart_data as $cd): ?>
          <span><?= substr($monthAbbr[(int)$cd['mn']]??'',0,1) ?></span>
          <?php endforeach; ?>
        </div>
        <div style="margin-top:14px;display:flex;justify-content:space-between;align-items:center">
          <div>
            <div style="font-size:1.3rem;font-weight:800;color:var(--re);letter-spacing:-.04em"><?= fmt($stat_this_month) ?></div>
            <div style="font-size:.7rem;color:var(--t2);margin-top:2px"><?= $months[$fMonth].' '.$fYear ?></div>
          </div>
          <div style="text-align:right">
            <div style="font-size:.82rem;font-weight:700;color:var(--tx)"><?= $stat_count_month ?> expenses</div>
            <div style="font-size:.7rem;color:var(--t2);margin-top:2px">avg <?= fmt($stat_avg_month) ?></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Category breakdown (computed from current filter) -->
    <?php
    $cat_r=$conn->query("SELECT COALESCE(NULLIF(TRIM(description),''),'Uncategorized') AS cat,
        SUM(amount) AS total, COUNT(*) AS cnt
        FROM expenses WHERE MONTH(expense_date)=$fMonth AND YEAR(expense_date)=$fYear
        GROUP BY cat ORDER BY total DESC LIMIT 8");
    $cat_rows=[];
    if($cat_r) while($crow=$cat_r->fetch_assoc()) $cat_rows[]=$crow;
    $catMax=count($cat_rows)?floatval($cat_rows[0]['total']):1;
    $catColors=['var(--re)','var(--go)','var(--ac)','var(--pu)','var(--gr)','var(--te)','var(--re)','var(--go)'];
    ?>
    <?php if(count($cat_rows)): ?>
    <div class="panel">
      <div class="panel-head">
        <div>
          <div class="panel-title">
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8" cy="8" r="6"/><path d="M8 8l4-3"/><path d="M8 2v2M8 12v2M2 8h2M12 8h2"/></svg>
            By Description
          </div>
          <div class="panel-sub"><?= $months[$fMonth].' '.$fYear ?></div>
        </div>
      </div>
      <div style="padding:12px 18px">
        <?php foreach($cat_rows as $i=>$cr):
          $pct2=round(floatval($cr['total'])/$catMax*100);
          $clr=$catColors[$i%count($catColors)];
        ?>
        <div class="cat-item">
          <div class="cat-dot" style="background:<?= $clr ?>"></div>
          <div class="cat-info">
            <div class="cat-name"><?= htmlspecialchars(mb_strimwidth($cr['cat'],0,28,'…')) ?></div>
            <div class="cat-bar-wrap"><div class="cat-bar-fill" style="width:<?= $pct2 ?>%;background:<?= $clr ?>"></div></div>
          </div>
          <div class="cat-amt" style="color:<?= $clr ?>"><?= fmt($cr['total']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /right -->
</div><!-- /main-grid -->
</div><!-- /content -->
</div><!-- /main -->

<!-- ═══ ADD MODAL ═══ -->
<div class="overlay" id="addOverlay" onclick="if(event.target===this)closeOv('addOverlay')">
  <div class="modal">
    <div class="mhead">
      <div><div class="mtitle">Add Expense</div><div class="msub">Record a new company expenditure</div></div>
      <button class="mclose" onclick="closeOv('addOverlay')">&#x2715;</button>
    </div>
    <form method="POST" action="expenses.php">
      <input type="hidden" name="action" value="add">
      <div class="mbody">
        <div class="fgrp">
          <div class="flbl">Title <span class="req">*</span></div>
          <input type="text" name="title" class="finput" placeholder="e.g. Office Supplies, Rent, Utilities&hellip;" required>
        </div>
        <div class="frow2">
          <div class="fgrp">
            <div class="flbl">Amount <span class="req">*</span></div>
            <input type="number" name="amount" id="addAmount" class="finput" min="0.01" step="0.01" placeholder="0.00" required oninput="updateAmtPreview(this.value)">
          </div>
          <div class="fgrp">
            <div class="flbl">Currency</div>
            <select name="currency" class="fselect">
              <option value="USD">USD ($)</option>
              <option value="EUR">EUR (&euro;)</option>
              <option value="GBP">GBP (&pound;)</option>
              <option value="IQD">IQD</option>
            </select>
          </div>
        </div>
        <!-- Live amount preview -->
        <div class="amt-display" id="amtPreview" style="display:none">
          <div style="font-size:.62rem;color:var(--t3);text-transform:uppercase;letter-spacing:.09em;font-weight:700;margin-bottom:6px">Expense Amount</div>
          <div class="amt-val" style="color:var(--re)" id="amtPreviewVal">$0.00</div>
        </div>
        <div class="frow2">
          <div class="fgrp">
            <div class="flbl">Expense Date</div>
            <input type="date" name="expense_date" class="finput" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="fgrp">
            <div class="flbl">Description / Category</div>
            <input type="text" name="description" class="finput" placeholder="e.g. Rent, Travel, Utilities&hellip;" list="cat-suggestions">
            <datalist id="cat-suggestions">
              <option value="Rent">
              <option value="Utilities">
              <option value="Salaries">
              <option value="Office Supplies">
              <option value="Travel">
              <option value="Marketing">
              <option value="Maintenance">
              <option value="Insurance">
              <option value="Equipment">
              <option value="Food & Beverages">
              <option value="Communication">
              <option value="Software">
            </datalist>
          </div>
        </div>
        <div class="fgrp">
          <div class="flbl">Notes (optional)</div>
          <textarea name="notes" class="ftextarea" placeholder="Additional details, receipt reference, vendor name&hellip;"></textarea>
        </div>
      </div>
      <div class="mfoot">
        <button type="button" class="btn btn-ghost" onclick="closeOv('addOverlay')">Cancel</button>
        <button type="submit" class="btn btn-primary">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M8 1v14M1 8h14"/></svg>
          Save Expense
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ═══ EDIT MODAL ═══ -->
<div class="overlay" id="editOverlay" onclick="if(event.target===this)closeOv('editOverlay')">
  <div class="modal">
    <div class="mhead">
      <div><div class="mtitle">Edit Expense</div><div class="msub" id="editSub"></div></div>
      <button class="mclose" onclick="closeOv('editOverlay')">&#x2715;</button>
    </div>
    <form method="POST" action="expenses.php">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="expense_id" id="editId">
      <div class="mbody">
        <div class="fgrp">
          <div class="flbl">Title <span class="req">*</span></div>
          <input type="text" name="title" id="editTitle" class="finput" required>
        </div>
        <div class="frow2">
          <div class="fgrp">
            <div class="flbl">Amount <span class="req">*</span></div>
            <input type="number" name="amount" id="editAmount" class="finput" min="0.01" step="0.01" required>
          </div>
          <div class="fgrp">
            <div class="flbl">Expense Date</div>
            <input type="date" name="expense_date" id="editDate" class="finput">
          </div>
        </div>
        <div class="fgrp">
          <div class="flbl">Description</div>
          <input type="text" name="description" id="editDesc" class="finput">
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
  <div class="modal">
    <div class="mhead">
      <div><div class="mtitle" id="detTitle">Expense Detail</div><div class="msub" id="detSub"></div></div>
      <button class="mclose" onclick="closeOv('detailOverlay')">&#x2715;</button>
    </div>
    <div id="detBody" style="padding:22px 24px"></div>
    <div class="mfoot">
      <button class="btn btn-ghost" onclick="closeOv('detailOverlay')">Close</button>
      <?php if($_SESSION['role']==='admin'): ?>
      <button class="btn btn-success" id="detApproveBtn" style="display:none">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8" cy="8" r="7"/><path d="M5 8l2 2 4-4"/></svg>
        Approve
      </button>
      <?php endif; ?>
      <button class="btn btn-primary" id="detEditBtn">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M11 2l3 3-9 9H2v-3z"/></svg>
        Edit
      </button>
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
function openOv(id){document.getElementById(id).classList.add('open');document.body.style.overflow='hidden';}
function closeOv(id){document.getElementById(id).classList.remove('open');document.body.style.overflow='';}

// Add modal
function openAdd(){
  document.getElementById('addAmount').value='';
  document.getElementById('amtPreview').style.display='none';
  openOv('addOverlay');
  setTimeout(()=>document.querySelector('#addOverlay input[name="title"]')?.focus(),200);
}

// Amount preview
function updateAmtPreview(v){
  const n=parseFloat(v)||0;
  const prev=document.getElementById('amtPreview');
  if(n>0){
    prev.style.display='block';
    document.getElementById('amtPreviewVal').textContent=
      n>=1000000?'$'+n/1000000+'M': n>=1000?'$'+(n/1000).toFixed(1)+'k':'$'+n.toFixed(2);
  } else { prev.style.display='none'; }
}

// Edit modal
function openEdit(e){
  document.getElementById('editId').value     = e.id;
  document.getElementById('editSub').textContent = e.expense_number;
  document.getElementById('editTitle').value  = e.title||'';
  document.getElementById('editAmount').value = parseFloat(e.amount).toFixed(2);
  document.getElementById('editDate').value   = e.expense_date||'';
  document.getElementById('editDesc').value   = e.description||'';
  document.getElementById('editNotes').value  = e.notes||'';
  openOv('editOverlay');
}

// Detail modal
function openDetail(e){
  document.getElementById('detTitle').textContent = e.title;
  document.getElementById('detSub').textContent   = e.expense_number+' · '+e.expense_date;

  const approved = !!e.approved_by;
  const approveBtn = document.getElementById('detApproveBtn');
  const editBtn    = document.getElementById('detEditBtn');

  if(approveBtn) approveBtn.style.display = approved?'none':'inline-flex';
  if(approveBtn && !approved){
    approveBtn.onclick = ()=>{
      // Submit approve form
      const f=document.createElement('form');
      f.method='POST';f.action='expenses.php';
      f.innerHTML=`<input type="hidden" name="action" value="approve"><input type="hidden" name="expense_id" value="${e.id}">`;
      document.body.appendChild(f);f.submit();
    };
  }
  if(editBtn) editBtn.onclick=()=>{closeOv('detailOverlay');openEdit(e);};

  const amt=parseFloat(e.amount)||0;
  const amtColor = amt>=5000?'var(--re)':amt>=1000?'var(--go)':'var(--re)';

  document.getElementById('detBody').innerHTML=`
    <div style="text-align:center;background:var(--bg3);border-radius:11px;padding:18px;margin-bottom:16px">
      <div style="font-size:.62rem;color:var(--t3);text-transform:uppercase;letter-spacing:.09em;font-weight:700;margin-bottom:8px">Amount</div>
      <div style="font-size:2rem;font-weight:800;color:${amtColor};letter-spacing:-.04em">-$${amt.toFixed(2)}</div>
      <div style="font-size:.72rem;color:var(--t2);margin-top:4px">${xss(e.currency)}</div>
    </div>
    <div style="background:var(--bg3);border-radius:11px;padding:14px 16px;display:flex;flex-direction:column;gap:0">
      ${row('Expense #','<span style="font-family:monospace;color:var(--ac)">'+xss(e.expense_number)+'</span>')}
      ${row('Date',e.expense_date)}
      ${e.description?row('Description',xss(e.description)):''}
      ${row('Status',e.approved_by
        ?'<span class="pill ok">&#10003; Approved by '+xss(e.approved_by_name||'')+'</span>'
        :'<span class="pill warn">Pending Approval</span>')}
      ${e.approved_at?row('Approved At',e.approved_at):''}
      ${row('Added By',xss(e.created_by_name||'—'))}
      ${e.notes?row('Notes','<span style="color:var(--t2);font-style:italic">'+xss(e.notes)+'</span>'):''}
    </div>`;
  openOv('detailOverlay');
}

function row(label,val){
  return `<div style="display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:1px solid var(--br);font-size:.8rem">
    <span style="color:var(--t2)">${label}</span><span style="font-weight:600;color:var(--tx);text-align:right;max-width:65%">${val}</span>
  </div>`;
}

// Flash dismiss
setTimeout(()=>{
  const f=document.getElementById('flashMsg');
  if(f){f.style.transition='opacity .5s';f.style.opacity='0';setTimeout(()=>f?.remove(),520);}
},5000);

// Search
document.getElementById('searchInp')?.addEventListener('keydown',function(e){
  if(e.key==='Enter')this.form.submit();
  if(e.key==='Escape'){this.value='';this.form.submit();}
});
</script>
</body>
</html>
