<?php
require_once 'connection.php';
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
if ($_SESSION['role'] !== 'admin') { header('Location: index.php'); exit; }

$user = htmlspecialchars($_SESSION['username'] ?? 'Admin');
$role = htmlspecialchars(ucfirst($_SESSION['role'] ?? 'admin'));
$ava  = strtoupper(substr($user, 0, 1));
$uid  = (int)$_SESSION['user_id'];

function esc($c,$v){ return $c->real_escape_string($v); }

// ── POST: clear old logs ───────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='clear_old'){
    $days = max(7,(int)($_POST['days']??30));
    $conn->query("DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL $days DAY)");
    $n = $conn->affected_rows;
    $flash="Deleted $n log".($n!=1?'s':'')." older than $days days."; $flashType='warn';
    header("Location: audit.php?flash=".urlencode($flash)."&ft=$flashType"); exit;
}

$flash=$flashType='';
if(isset($_GET['flash'])){ $flash=urldecode($_GET['flash']); $flashType=$_GET['ft']??'ok'; }

// ── FILTERS ────────────────────────────────────────────────────
$search  = trim($_GET['q']??'');
$fAction = $_GET['action_filter']??'';
$fTable  = $_GET['table']??'';
$fUser   = $_GET['user_filter']??'';
$fDate   = $_GET['date']??'';
$page    = max(1,(int)($_GET['page']??1));
$perPage = 25;
$offset  = ($page-1)*$perPage;

$w = "1=1";
if($search)  { $s=esc($conn,$search); $w.=" AND (al.description LIKE '%$s%' OR al.user_name LIKE '%$s%' OR al.table_name LIKE '%$s%')"; }
if($fAction) { $w.=" AND al.action='".esc($conn,$fAction)."'"; }
if($fTable)  { $w.=" AND al.table_name='".esc($conn,$fTable)."'"; }
if($fUser)   { $w.=" AND al.user_id=".((int)$fUser); }
if($fDate)   { $w.=" AND DATE(al.created_at)='".esc($conn,$fDate)."'"; }

$tc = $conn->query("SELECT COUNT(*) AS c FROM audit_logs al WHERE $w");
$total = $tc ? (int)$tc->fetch_assoc()['c'] : 0;
$totalPages = max(1,(int)ceil($total/$perPage));

$logs = $conn->query("SELECT al.*, u.email AS user_email
    FROM audit_logs al
    LEFT JOIN users u ON u.id=al.user_id
    WHERE $w ORDER BY al.created_at DESC
    LIMIT $perPage OFFSET $offset");

// Stats
function sv($c,$q){$r=$c->query($q);return $r?(int)($r->fetch_assoc()['v']??0):0;}
$stat_total   = sv($conn,"SELECT COUNT(*) AS v FROM audit_logs");
$stat_today   = sv($conn,"SELECT COUNT(*) AS v FROM audit_logs WHERE DATE(created_at)=CURDATE()");
$stat_week    = sv($conn,"SELECT COUNT(*) AS v FROM audit_logs WHERE created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)");
$stat_errors  = sv($conn,"SELECT COUNT(*) AS v FROM audit_logs WHERE action IN('LOGIN_FAILED','DELETE')");

// Action distribution for the chart
$act_dist = $conn->query("SELECT action, COUNT(*) AS cnt FROM audit_logs GROUP BY action ORDER BY cnt DESC LIMIT 10");
$act_data = [];
if($act_dist) while($r=$act_dist->fetch_assoc()) $act_data[$r['action']] = (int)$r['cnt'];

// Top active users
$top_users = $conn->query("SELECT user_name, COUNT(*) AS cnt FROM audit_logs
    WHERE user_name IS NOT NULL GROUP BY user_name ORDER BY cnt DESC LIMIT 5");

// Distinct filter options
$action_opts = $conn->query("SELECT DISTINCT action FROM audit_logs ORDER BY action");
$table_opts  = $conn->query("SELECT DISTINCT table_name FROM audit_logs ORDER BY table_name");
$user_opts   = $conn->query("SELECT DISTINCT user_id, user_name FROM audit_logs WHERE user_name IS NOT NULL ORDER BY user_name");

// Recent actions per day (last 14 days for mini chart)
$daily_r = $conn->query("SELECT DATE(created_at) AS d, COUNT(*) AS cnt
    FROM audit_logs WHERE created_at >= DATE_SUB(NOW(),INTERVAL 14 DAY)
    GROUP BY d ORDER BY d ASC");
$daily_data = [];
if($daily_r) while($r=$daily_r->fetch_assoc()) $daily_data[$r['d']] = (int)$r['cnt'];

function buildQS($ov=[]){
    $p=array_merge($_GET,$ov); unset($p['page']);
    $qs=http_build_query(array_filter($p,fn($v)=>$v!==''));
    return $qs?"?$qs&":"?";
}
$qs=buildQS();
$current_page='audit';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SalesOS &mdash; Audit Logs</title>
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
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
.stat-card{background:var(--bg2);border:1px solid var(--br);border-radius:13px;padding:18px 20px;transition:all .2s;animation:fadeUp .4s cubic-bezier(.16,1,.3,1) both}
.stat-card:hover{transform:translateY(-2px);box-shadow:var(--sh2)}
.stat-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.stat-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center}
.stat-icon svg{width:18px;height:18px}
.stat-badge{font-size:.63rem;font-weight:700;padding:3px 8px;border-radius:100px}
.stat-val{font-size:1.6rem;font-weight:800;color:var(--tx);letter-spacing:-.04em;line-height:1}
.stat-lbl{font-size:.71rem;color:var(--t2);margin-top:5px;font-weight:500}
@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
/* PAGE HEADER */
.page-hdr{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}
.ph-title{font-size:1.45rem;font-weight:800;color:var(--tx);letter-spacing:-.03em}
.ph-sub{font-size:.78rem;color:var(--t2);margin-top:4px}
/* DASHBOARD GRID */
.dash-grid{display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start}
/* PANELS */
.panel{background:var(--bg2);border:1px solid var(--br);border-radius:14px;overflow:hidden}
.panel-head{padding:16px 20px;border-bottom:1px solid var(--br);display:flex;align-items:center;justify-content:space-between}
.panel-title{font-size:.88rem;font-weight:800;color:var(--tx);display:flex;align-items:center;gap:8px}
.panel-title svg{width:15px;height:15px;color:var(--ac)}
.panel-sub{font-size:.68rem;color:var(--t3);margin-top:2px}
/* ACTIVITY CHART (14 day bars) */
.act-chart{display:flex;align-items:flex-end;gap:5px;height:60px;padding:12px 20px 4px}
.act-bar{flex:1;border-radius:3px 3px 0 0;background:var(--ag);border:1px solid rgba(79,142,247,.15);min-height:3px;transition:background .15s;cursor:default;position:relative}
.act-bar:hover{background:var(--ac)}
.act-bar::after{content:attr(title);position:absolute;bottom:calc(100%+5px);left:50%;transform:translateX(-50%);background:var(--bg4);border:1px solid var(--br2);border-radius:7px;padding:3px 8px;font-size:.6rem;color:var(--tx);white-space:nowrap;opacity:0;pointer-events:none;transition:opacity .15s;font-family:inherit;z-index:10}
.act-bar:hover::after{opacity:1}
.act-lbls{display:flex;justify-content:space-between;padding:0 20px 10px;font-size:.58rem;color:var(--t3);font-weight:600}
/* ACTION DISTRIBUTION */
.act-dist-item{display:flex;align-items:center;gap:10px;padding:9px 20px;border-bottom:1px solid var(--br)}
.act-dist-item:last-child{border:none}
.act-dist-name{font-size:.76rem;font-weight:700;flex:0 0 130px}
.act-dist-bar{flex:1;height:5px;background:var(--br);border-radius:3px;overflow:hidden}
.act-dist-fill{height:100%;border-radius:3px;transition:width .6s cubic-bezier(.16,1,.3,1)}
.act-dist-cnt{font-size:.72rem;font-weight:700;color:var(--t2);min-width:40px;text-align:right}
/* TOP USERS */
.top-user-item{display:flex;align-items:center;gap:10px;padding:10px 20px;border-bottom:1px solid var(--br)}
.top-user-item:last-child{border:none}
.tu-ava{width:30px;height:30px;border-radius:8px;background:linear-gradient(135deg,var(--ac),var(--ac2));display:flex;align-items:center;justify-content:center;font-size:.78rem;font-weight:800;color:#fff;flex-shrink:0}
.tu-name{font-size:.78rem;font-weight:600;color:var(--tx);flex:1}
.tu-cnt{font-size:.76rem;font-weight:700;color:var(--ac)}
/* TOOLBAR */
.toolbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.searchbox{display:flex;align-items:center;gap:8px;background:var(--bg2);border:1px solid var(--br);border-radius:10px;padding:8px 13px;flex:1;min-width:200px;max-width:280px;transition:border-color .2s}
.searchbox:focus-within{border-color:var(--ac)}
.searchbox svg{width:13px;height:13px;color:var(--t3);flex-shrink:0}
.searchbox input{background:none;border:none;outline:none;font-family:inherit;font-size:.8rem;color:var(--tx);width:100%}
.searchbox input::placeholder{color:var(--t3)}
.fsel{background:var(--bg2);border:1px solid var(--br);border-radius:10px;padding:8px 10px;font-family:inherit;font-size:.77rem;color:var(--tx);outline:none;cursor:pointer;transition:border-color .2s}
.fsel:focus{border-color:var(--ac)}
.tbr{margin-left:auto;display:flex;align-items:center;gap:8px}
/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 15px;border-radius:9px;font-family:inherit;font-size:.78rem;font-weight:700;cursor:pointer;border:none;transition:all .18s;text-decoration:none}
.btn-primary{background:var(--ac);color:#fff}.btn-primary:hover{background:var(--ac2)}
.btn-ghost{background:var(--bg2);border:1px solid var(--br);color:var(--t2)}.btn-ghost:hover{color:var(--tx);border-color:var(--br2)}
.btn-danger{background:var(--red);border:1px solid rgba(248,113,113,.3);color:var(--re)}.btn-danger:hover{background:var(--reb)}
.btn svg{width:13px;height:13px}
/* TABLE */
.dtbl{width:100%;border-collapse:collapse}
.dtbl th{font-size:.61rem;color:var(--t3);text-transform:uppercase;letter-spacing:.1em;padding:10px 14px;text-align:left;border-bottom:1px solid var(--br);font-weight:700;white-space:nowrap;background:var(--bg2)}
.dtbl td{padding:11px 14px;font-size:.79rem;border-bottom:1px solid var(--br);vertical-align:middle}
.dtbl tr:last-child td{border-bottom:none}
.dtbl tbody tr{transition:background .1s;cursor:pointer}
.dtbl tbody tr:hover td{background:rgba(255,255,255,.025)}
[data-theme="light"] .dtbl tbody tr:hover td{background:rgba(0,0,0,.02)}
/* ACTION BADGES */
.act-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:7px;font-size:.65rem;font-weight:700;white-space:nowrap}
/* PILLS */
.pill{display:inline-flex;align-items:center;padding:2px 8px;border-radius:100px;font-size:.63rem;font-weight:700;white-space:nowrap}
.pill.ok  {background:var(--grd);color:var(--gr)}
.pill.warn{background:var(--god);color:var(--go)}
.pill.err {background:var(--red);color:var(--re)}
.pill.info{background:var(--ag); color:var(--ac)}
.pill.pu  {background:var(--pud);color:var(--pu)}
.pill.te  {background:var(--ted);color:var(--te)}
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
/* DETAIL MODAL */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:400;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .25s;backdrop-filter:blur(6px)}
.overlay.open{opacity:1;pointer-events:all}
.modal{background:var(--bg2);border:1px solid var(--br2);border-radius:18px;width:100%;max-width:600px;max-height:92vh;overflow-y:auto;box-shadow:var(--sh2);transform:translateY(20px) scale(.97);transition:transform .3s cubic-bezier(.16,1,.3,1)}
.overlay.open .modal{transform:none}
.mhead{display:flex;align-items:center;justify-content:space-between;padding:20px 24px;border-bottom:1px solid var(--br);position:sticky;top:0;background:var(--bg2);z-index:1}
.mtitle{font-size:.95rem;font-weight:800;color:var(--tx)}
.msub{font-size:.71rem;color:var(--t2);margin-top:2px}
.mclose{width:30px;height:30px;border-radius:7px;border:1px solid var(--br);background:transparent;color:var(--t2);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:1.1rem;transition:all .15s}
.mclose:hover{background:var(--red);color:var(--re)}
.mbody{padding:22px 24px;display:flex;flex-direction:column;gap:10px}
.mfoot{padding:14px 24px;border-top:1px solid var(--br);display:flex;justify-content:flex-end;gap:10px;position:sticky;bottom:0;background:var(--bg2)}
.det-row{display:flex;justify-content:space-between;align-items:flex-start;padding:9px 0;border-bottom:1px solid var(--br);font-size:.8rem;gap:16px}
.det-row:last-child{border:none}
.det-row .dl{color:var(--t2);flex-shrink:0}
.det-row .dv{font-weight:600;color:var(--tx);text-align:right;word-break:break-all}
/* JSON viewer */
.json-block{background:var(--bg3);border:1px solid var(--br);border-radius:9px;padding:12px 14px;font-family:monospace;font-size:.72rem;color:var(--te);overflow-x:auto;white-space:pre-wrap;line-height:1.6;max-height:200px;overflow-y:auto}
/* MODAL FORM */
.fgrp{display:flex;flex-direction:column;gap:5px}
.flbl{font-size:.68rem;font-weight:700;color:var(--t2);text-transform:uppercase;letter-spacing:.04em}
.finput,.fselect{width:100%;background:var(--bg3);border:1.5px solid var(--br);border-radius:9px;padding:9px 12px;font-family:inherit;font-size:.82rem;color:var(--tx);outline:none;transition:border-color .2s}
.finput:focus,.fselect:focus{border-color:var(--ac)}
/* EMPTY */
.empty-state{padding:56px 20px;text-align:center;color:var(--t3)}
.empty-state svg{width:38px;height:38px;margin:0 auto 12px;opacity:.28;display:block}
.empty-state p{font-size:.81rem}
/* RESPONSIVE */
@media(max-width:1200px){.dash-grid{grid-template-columns:1fr}}
@media(max-width:900px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:none}.main{margin-left:0}.mob-btn{display:block}.sbar{display:none}}
@media(max-width:720px){.stats-grid{grid-template-columns:repeat(2,1fr)}.content{padding:14px}}
</style>
</head>
<body>

<?php $current_page = 'audit'; include 'sidebar.php'; ?>
<div class="main">
<?php $breadcrumbs=[['label'=>'System'],['label'=>'Audit Logs']]; include 'topnav.php'; ?>
<div class="content">

<?php if($flash): ?>
<div class="flash <?= $flashType ?>" id="flashMsg">
  <?php if($flashType==='ok'): ?><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8" cy="8" r="7"/><path d="M5 8l2 2 4-4"/></svg>
  <?php else: ?><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 1L1 13h14z"/><path d="M8 6v3M8 11v1"/></svg><?php endif; ?>
  <span><?= $flash ?></span>
</div>
<?php endif; ?>

<!-- Header -->
<div class="page-hdr">
  <div>
    <div class="ph-title">Audit Logs</div>
    <div class="ph-sub">Complete record of all system actions &mdash; <?= number_format($stat_total) ?> total entries</div>
  </div>
  <button class="btn btn-danger" onclick="openOv('clearOverlay')">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 4h12M5 4V2h6v2M6 7v4M10 7v4M3 4l1 8h8l1-8"/></svg>
    Clear Old Logs
  </button>
</div>

<!-- Stats -->
<div class="stats-grid">
  <div class="stat-card" style="--dl:.0s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--ag)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--ac)" stroke-width="1.8"><path d="M2 4h12M2 8h9M2 12h6"/></svg></div>
      <span class="stat-badge" style="background:var(--ag);color:var(--ac)">All</span>
    </div>
    <div class="stat-val"><?= number_format($stat_total) ?></div>
    <div class="stat-lbl">Total Log Entries</div>
  </div>
  <div class="stat-card" style="--dl:.08s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--grd)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--gr)" stroke-width="1.8"><rect x="1" y="3" width="14" height="11" rx="1.5"/><path d="M1 7h14M5 3V1M11 3V1"/></svg></div>
      <span class="stat-badge" style="background:var(--grd);color:var(--gr)">Today</span>
    </div>
    <div class="stat-val"><?= number_format($stat_today) ?></div>
    <div class="stat-lbl">Actions Today</div>
  </div>
  <div class="stat-card" style="--dl:.16s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--pud)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--pu)" stroke-width="1.8"><path d="M8 1v14M4 5l4-4 4 4"/></svg></div>
      <span class="stat-badge" style="background:var(--pud);color:var(--pu)">7d</span>
    </div>
    <div class="stat-val"><?= number_format($stat_week) ?></div>
    <div class="stat-lbl">This Week</div>
  </div>
  <div class="stat-card" style="--dl:.24s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--red)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--re)" stroke-width="1.8"><path d="M8 1L1 13h14z"/><path d="M8 6v3M8 11v1"/></svg></div>
      <span class="stat-badge" style="background:var(--red);color:var(--re)">Sensitive</span>
    </div>
    <div class="stat-val"><?= number_format($stat_errors) ?></div>
    <div class="stat-lbl">Deletes &amp; Failures</div>
  </div>
</div>

<!-- Dashboard: Activity chart + Distribution + Top users -->
<div class="dash-grid">
  <div style="display:flex;flex-direction:column;gap:16px">

    <!-- 14-day activity sparkline -->
    <div class="panel">
      <div class="panel-head">
        <div>
          <div class="panel-title"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12l4-4 3 3 4-5 3 2"/></svg>Activity (Last 14 Days)</div>
          <div class="panel-sub">Total actions per day</div>
        </div>
        <div style="font-size:.78rem;font-weight:700;color:var(--ac)"><?= $stat_today ?> today</div>
      </div>
      <?php
      $maxDaily = $daily_data ? max($daily_data) : 1;
      if(!$maxDaily) $maxDaily=1;
      // Fill last 14 days
      $days14=[];
      for($i=13;$i>=0;$i--){
        $d=date('Y-m-d',strtotime("-$i days"));
        $days14[$d]=$daily_data[$d]??0;
      }
      ?>
      <div class="act-chart">
        <?php foreach($days14 as $d=>$cnt):
          $pct=max(4,round($cnt/$maxDaily*100));
          $isToday=($d===date('Y-m-d'));
          $lbl=date('M j',$d?strtotime($d):time()).': '.$cnt.' action'.($cnt!=1?'s':'');
        ?>
        <div class="act-bar <?= $isToday?'current':'' ?>"
             style="height:<?= $pct ?>%;<?= $isToday?'background:rgba(79,142,247,.45);border-color:rgba(79,142,247,.6)':'' ?>"
             title="<?= htmlspecialchars($lbl) ?>"></div>
        <?php endforeach; ?>
      </div>
      <div class="act-lbls">
        <?php
        $keys=array_keys($days14);
        foreach([0,4,8,13] as $i) {
          echo '<span>'.date('M j',strtotime($keys[$i])).'</span>';
        }
        ?>
      </div>
    </div>

    <!-- Action distribution -->
    <div class="panel">
      <div class="panel-head">
        <div>
          <div class="panel-title"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8" cy="8" r="6"/><path d="M8 8l4-3"/></svg>Action Breakdown</div>
          <div class="panel-sub">All-time by action type</div>
        </div>
      </div>
      <?php
      $maxAct = $act_data ? max($act_data) : 1;
      $actColors=[
        'CREATE'=>['var(--grd)','var(--gr)'],
        'UPDATE'=>['var(--god)','var(--go)'],
        'DELETE'=>['var(--red)','var(--re)'],
        'LOGIN' =>['var(--ag)','var(--ac)'],
        'LOGOUT'=>['var(--pud)','var(--pu)'],
        'LOGIN_FAILED'=>['var(--reb)','var(--re)'],
        'APPROVE'=>['var(--grb)','var(--gr)'],
        'REJECT'=>['var(--reb)','var(--re)'],
        'STATUS_CHANGE'=>['var(--ted)','var(--te)'],
        'PASSWORD_CHANGE'=>['var(--pud)','var(--pu)'],
      ];
      foreach($act_data as $act=>$cnt):
        $clr=$actColors[$act]??['var(--ag)','var(--ac)'];
        $pct=round($cnt/$maxAct*100);
      ?>
      <div class="act-dist-item">
        <div class="act-dist-name">
          <span class="act-badge" style="background:<?= $clr[0] ?>;color:<?= $clr[1] ?>"><?= htmlspecialchars($act) ?></span>
        </div>
        <div class="act-dist-bar"><div class="act-dist-fill" style="width:<?= $pct ?>%;background:<?= $clr[1] ?>"></div></div>
        <div class="act-dist-cnt"><?= number_format($cnt) ?></div>
      </div>
      <?php endforeach; ?>
    </div>

  </div><!-- /left -->

  <!-- Right: Top users -->
  <div class="panel" style="position:sticky;top:78px">
    <div class="panel-head">
      <div>
        <div class="panel-title"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="5" cy="5" r="3"/><circle cx="11" cy="5" r="3"/><path d="M1 14c0-2.76 1.8-5 4-5M15 14c0-2.76-1.8-5-4-5M7 14c0-2.76.9-5 2-5s2 2.24 2 5"/></svg>Most Active Users</div>
        <div class="panel-sub">By total log entries</div>
      </div>
    </div>
    <?php
    $roleGrads=['admin'=>'linear-gradient(135deg,#4F8EF7,#6BA3FF)',
      'accountant'=>'linear-gradient(135deg,#F5A623,#D4880A)',
      'representative'=>'linear-gradient(135deg,#A78BFA,#7C3AED)'];
    $rank=1;
    if($top_users) while($tu=$top_users->fetch_assoc()):
      $uname=$tu['user_name']??'System';
      $gr=$roleGrads['representative'];
    ?>
    <div class="top-user-item">
      <div style="font-size:.68rem;font-weight:800;color:var(--t3);min-width:18px">#<?= $rank ?></div>
      <div class="tu-ava" style="background:<?= $gr ?>"><?= strtoupper(substr($uname,0,1)) ?></div>
      <div class="tu-name"><?= htmlspecialchars($uname) ?></div>
      <div class="tu-cnt"><?= number_format($tu['cnt']) ?></div>
    </div>
    <?php $rank++; endwhile; ?>

    <!-- System health quick info -->
    <div style="padding:14px 20px;border-top:1px solid var(--br)">
      <div style="font-size:.63rem;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.1em;margin-bottom:10px">Quick Stats</div>
      <div style="display:flex;flex-direction:column;gap:6px">
        <?php
        $logins    = sv($conn,"SELECT COUNT(*) AS v FROM audit_logs WHERE action='LOGIN'");
        $failures  = sv($conn,"SELECT COUNT(*) AS v FROM audit_logs WHERE action='LOGIN_FAILED'");
        $creates   = sv($conn,"SELECT COUNT(*) AS v FROM audit_logs WHERE action='CREATE'");
        $deletes   = sv($conn,"SELECT COUNT(*) AS v FROM audit_logs WHERE action='DELETE'");
        $row_data  = [
          ['Logins',$logins,'var(--gr)'],
          ['Failed Logins',$failures,'var(--re)'],
          ['Records Created',$creates,'var(--ac)'],
          ['Records Deleted',$deletes,'var(--go)'],
        ];
        foreach($row_data as [$lbl,$v,$clr]):
        ?>
        <div style="display:flex;justify-content:space-between;font-size:.74rem">
          <span style="color:var(--t2)"><?= $lbl ?></span>
          <span style="font-weight:700;color:<?= $clr ?>"><?= number_format($v) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div><!-- /dash-grid -->

<!-- Filters + Log Table -->
<form method="GET" action="audit.php" id="filterForm">
  <div class="toolbar">
    <div class="searchbox">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="7" cy="7" r="4.5"/><path d="M11 11l3 3"/></svg>
      <input type="text" name="q" placeholder="Search description, user, table&hellip;" value="<?= htmlspecialchars($search) ?>" id="searchInp">
    </div>
    <select name="action_filter" class="fsel" onchange="this.form.submit()">
      <option value="">All Actions</option>
      <?php if($action_opts) while($ao=$action_opts->fetch_assoc()): ?>
      <option value="<?= $ao['action'] ?>" <?= $fAction===$ao['action']?'selected':'' ?>><?= htmlspecialchars($ao['action']) ?></option>
      <?php endwhile; ?>
    </select>
    <select name="table" class="fsel" onchange="this.form.submit()">
      <option value="">All Tables</option>
      <?php if($table_opts) while($to=$table_opts->fetch_assoc()): ?>
      <option value="<?= $to['table_name'] ?>" <?= $fTable===$to['table_name']?'selected':'' ?>><?= htmlspecialchars($to['table_name']) ?></option>
      <?php endwhile; ?>
    </select>
    <select name="user_filter" class="fsel" onchange="this.form.submit()">
      <option value="">All Users</option>
      <?php if($user_opts) while($uo=$user_opts->fetch_assoc()): ?>
      <option value="<?= $uo['user_id'] ?>" <?= $fUser==(string)$uo['user_id']?'selected':'' ?>><?= htmlspecialchars($uo['user_name']) ?></option>
      <?php endwhile; ?>
    </select>
    <input type="date" name="date" class="fsel" value="<?= htmlspecialchars($fDate) ?>" onchange="this.form.submit()" title="Filter by date">
    <button type="submit" class="btn btn-primary" style="padding:8px 13px">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="7" cy="7" r="4.5"/><path d="M11 11l3 3"/></svg>Search
    </button>
    <?php if($search||$fAction||$fTable||$fUser||$fDate): ?>
    <a href="audit.php" class="btn btn-ghost" style="padding:8px 13px">Clear</a>
    <?php endif; ?>
    <div class="tbr">
      <span style="font-size:.74rem;color:var(--t2)"><?= number_format($total) ?> entr<?= $total!=1?'ies':'y' ?></span>
    </div>
  </div>
</form>

<!-- Log Table -->
<div class="panel">
  <?php
  // Action badge config
  $actCfg=[
    'CREATE'         =>['var(--grd)','var(--gr)','➕'],
    'UPDATE'         =>['var(--god)','var(--go)','✏️'],
    'DELETE'         =>['var(--red)','var(--re)','🗑'],
    'LOGIN'          =>['var(--ag)', 'var(--ac)','🔐'],
    'LOGOUT'         =>['var(--pud)','var(--pu)','🚪'],
    'LOGIN_FAILED'   =>['var(--reb)','var(--re)','🚫'],
    'APPROVE'        =>['var(--grb)','var(--gr)','✅'],
    'REJECT'         =>['var(--reb)','var(--re)','❌'],
    'STATUS_CHANGE'  =>['var(--ted)','var(--te)','🔄'],
    'PASSWORD_CHANGE'=>['var(--pud)','var(--pu)','🔑'],
    'EXPORT'         =>['var(--ag)', 'var(--ac)','📤'],
  ];
  ?>
  <?php if($logs && $logs->num_rows > 0): ?>
  <div style="overflow-x:auto">
    <table class="dtbl">
      <thead>
        <tr>
          <th>#</th>
          <th>Action</th>
          <th>User</th>
          <th>Table</th>
          <th>Record</th>
          <th>Description</th>
          <th>Time</th>
        </tr>
      </thead>
      <tbody>
      <?php while($log=$logs->fetch_assoc()):
        $cfg=$actCfg[$log['action']]??['var(--bg3)','var(--t2)','📋'];
        [$bg,$clr,$icon]=$cfg;

        // Time ago
        $diff=time()-strtotime($log['created_at']);
        if($diff<60)        $ago=$diff.'s ago';
        elseif($diff<3600)  $ago=floor($diff/60).'m ago';
        elseif($diff<86400) $ago=floor($diff/3600).'h ago';
        elseif($diff<604800)$ago=floor($diff/86400).'d ago';
        else                $ago=date('M j, Y',strtotime($log['created_at']));

        $desc=$log['description']??'';
        $descShort=mb_strlen($desc)>60?mb_substr($desc,0,60).'…':$desc;
        $hasJson=$log['old_values']||$log['new_values']||$log['changed_fields'];
      ?>
      <tr onclick="openDetail(<?= htmlspecialchars(json_encode($log),ENT_QUOTES) ?>)">
        <td style="color:var(--t3);font-size:.72rem;font-family:monospace"><?= $log['id'] ?></td>
        <td>
          <span class="act-badge" style="background:<?= $bg ?>;color:<?= $clr ?>">
            <?= $icon ?> <?= htmlspecialchars($log['action']) ?>
          </span>
        </td>
        <td>
          <?php if($log['user_name']): ?>
          <div style="font-weight:600;color:var(--tx);font-size:.78rem"><?= htmlspecialchars($log['user_name']) ?></div>
          <?php if($log['user_email']): ?><div style="font-size:.67rem;color:var(--t2)"><?= htmlspecialchars($log['user_email']) ?></div><?php endif; ?>
          <?php else: ?><span style="color:var(--t3);font-size:.75rem">System</span><?php endif; ?>
        </td>
        <td>
          <span class="pill nt" style="font-family:monospace;font-size:.63rem"><?= htmlspecialchars($log['table_name']) ?></span>
        </td>
        <td style="color:var(--t2);font-family:monospace;font-size:.74rem">
          <?= $log['record_id'] ? '#'.$log['record_id'] : '—' ?>
        </td>
        <td style="max-width:260px">
          <div style="font-size:.78rem;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:260px" title="<?= htmlspecialchars($desc) ?>">
            <?= htmlspecialchars($descShort) ?>
          </div>
          <?php if($hasJson): ?>
          <div style="font-size:.63rem;color:var(--ac);margin-top:2px">has data changes ›</div>
          <?php endif; ?>
        </td>
        <td style="white-space:nowrap">
          <div style="font-size:.75rem;color:var(--t2)"><?= $ago ?></div>
          <div style="font-size:.65rem;color:var(--t3);margin-top:1px"><?= date('H:i:s',strtotime($log['created_at'])) ?></div>
        </td>
      </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>

  <?php if($totalPages>1): ?>
  <div class="pager">
    <span class="pager-info">Showing <?= $offset+1 ?>&ndash;<?= min($offset+$perPage,$total) ?> of <?= number_format($total) ?></span>
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
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M2 4h12M2 8h9M2 12h6"/></svg>
    <p><?= ($search||$fAction||$fTable||$fUser||$fDate)?'No logs match your filters.':'No audit logs found.' ?></p>
  </div>
  <?php endif; ?>
</div>

</div><!-- /content -->
</div><!-- /main -->

<!-- DETAIL MODAL -->
<div class="overlay" id="detailOverlay" onclick="if(event.target===this)closeOv('detailOverlay')">
  <div class="modal">
    <div class="mhead">
      <div><div class="mtitle" id="detTitle">Log Entry</div><div class="msub" id="detSub"></div></div>
      <button class="mclose" onclick="closeOv('detailOverlay')">&#x2715;</button>
    </div>
    <div class="mbody" id="detBody"></div>
    <div class="mfoot">
      <button class="btn btn-ghost" onclick="closeOv('detailOverlay')">Close</button>
    </div>
  </div>
</div>

<!-- CLEAR OLD LOGS MODAL -->
<div class="overlay" id="clearOverlay" onclick="if(event.target===this)closeOv('clearOverlay')">
  <div class="modal" style="max-width:420px">
    <div class="mhead">
      <div><div class="mtitle">Clear Old Logs</div><div class="msub">Remove logs older than a set number of days</div></div>
      <button class="mclose" onclick="closeOv('clearOverlay')">&#x2715;</button>
    </div>
    <form method="POST" action="audit.php" onsubmit="return confirm('Permanently delete old audit logs? This cannot be undone.')">
      <input type="hidden" name="action" value="clear_old">
      <div class="mbody">
        <div style="background:var(--red);border:1px solid rgba(248,113,113,.3);border-radius:10px;padding:12px 14px;font-size:.79rem;color:var(--re);display:flex;align-items:center;gap:8px">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" style="width:15px;height:15px;flex-shrink:0"><path d="M8 1L1 13h14z"/><path d="M8 6v3M8 11v1"/></svg>
          This action is permanent and cannot be undone.
        </div>
        <div class="fgrp">
          <div class="flbl">Delete logs older than</div>
          <select name="days" class="fselect" style="width:100%;background:var(--bg3);border:1.5px solid var(--br);border-radius:9px;padding:9px 12px;font-family:inherit;font-size:.82rem;color:var(--tx);outline:none">
            <option value="7">7 days</option>
            <option value="30" selected>30 days</option>
            <option value="60">60 days</option>
            <option value="90">90 days</option>
            <option value="180">180 days</option>
            <option value="365">1 year</option>
          </select>
        </div>
        <div style="font-size:.74rem;color:var(--t2)">Current total: <strong style="color:var(--tx)"><?= number_format($stat_total) ?></strong> entries</div>
      </div>
      <div class="mfoot">
        <button type="button" class="btn btn-ghost" onclick="closeOv('clearOverlay')">Cancel</button>
        <button type="submit" class="btn btn-danger">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 4h12M5 4V2h6v2M6 7v4M10 7v4M3 4l1 8h8l1-8"/></svg>
          Delete Old Logs
        </button>
      </div>
    </form>
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

// Action badge config (JS mirror)
const actCfg={
  'CREATE':        ['rgba(52,211,153,.15)','#34D399','➕'],
  'UPDATE':        ['rgba(245,166,35,.15)','#F5A623','✏️'],
  'DELETE':        ['rgba(248,113,113,.2)','#F87171','🗑'],
  'LOGIN':         ['rgba(79,142,247,.15)','#4F8EF7','🔐'],
  'LOGOUT':        ['rgba(167,139,250,.15)','#A78BFA','🚪'],
  'LOGIN_FAILED':  ['rgba(248,113,113,.25)','#F87171','🚫'],
  'APPROVE':       ['rgba(52,211,153,.2)', '#34D399','✅'],
  'REJECT':        ['rgba(248,113,113,.2)','#F87171','❌'],
  'STATUS_CHANGE': ['rgba(34,211,238,.15)','#22D3EE','🔄'],
  'PASSWORD_CHANGE':['rgba(167,139,250,.15)','#A78BFA','🔑'],
  'EXPORT':        ['rgba(79,142,247,.15)','#4F8EF7','📤'],
};

function openDetail(log){
  const cfg=actCfg[log.action]||['rgba(79,142,247,.1)','#4F8EF7','📋'];
  const [bg,clr,icon]=cfg;

  document.getElementById('detTitle').innerHTML=
    `<span style="display:inline-flex;align-items:center;gap:7px">
      <span style="padding:3px 10px;border-radius:7px;background:${bg};color:${clr};font-size:.75rem;font-weight:700">${icon} ${xss(log.action)}</span>
    </span>`;
  document.getElementById('detSub').textContent='Log #'+log.id+' · '+log.created_at;

  let rows=[
    ['Log ID','#'+log.id],
    ['Action',`<span style="background:${bg};color:${clr};padding:2px 8px;border-radius:6px;font-size:.72rem;font-weight:700">${icon} ${xss(log.action)}</span>`],
    ['User', log.user_name?`<strong>${xss(log.user_name)}</strong>${log.user_email?' <span style="color:var(--t2);font-size:.72rem">'+xss(log.user_email)+'</span>':'`:'<em style="color:var(--t3)">System</em>'],
    ['Table', `<span style="font-family:monospace;background:var(--bg3);padding:2px 8px;border-radius:6px;font-size:.74rem">${xss(log.table_name)}</span>`],
    ['Record ID', log.record_id?`<span style="font-family:monospace;color:var(--ac)">#${log.record_id}</span>`:'—'],
    ['Timestamp', log.created_at],
    ['Description', xss(log.description||'—')],
  ];

  let html='';
  rows.forEach(([l,v])=>{
    html+=`<div class="det-row"><span class="dl">${l}</span><span class="dv">${v}</span></div>`;
  });

  if(log.changed_fields){
    try{
      const cf=JSON.parse(log.changed_fields);
      html+=`<div style="margin-top:4px"><div style="font-size:.65rem;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.09em;margin-bottom:6px">Changed Fields</div>
        <div class="json-block">${xss(JSON.stringify(cf,null,2))}</div></div>`;
    }catch{}
  }
  if(log.old_values){
    try{
      const ov=JSON.parse(log.old_values);
      html+=`<div style="margin-top:4px"><div style="font-size:.65rem;font-weight:700;color:var(--re);text-transform:uppercase;letter-spacing:.09em;margin-bottom:6px">Before</div>
        <div class="json-block" style="color:var(--re)">${xss(JSON.stringify(ov,null,2))}</div></div>`;
    }catch{}
  }
  if(log.new_values){
    try{
      const nv=JSON.parse(log.new_values);
      html+=`<div style="margin-top:4px"><div style="font-size:.65rem;font-weight:700;color:var(--gr);text-transform:uppercase;letter-spacing:.09em;margin-bottom:6px">After</div>
        <div class="json-block" style="color:var(--gr)">${xss(JSON.stringify(nv,null,2))}</div></div>`;
    }catch{}
  }

  document.getElementById('detBody').innerHTML=html;
  openOv('detailOverlay');
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
