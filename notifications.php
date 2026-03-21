<?php
require_once 'connection.php';
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$user = htmlspecialchars($_SESSION['username'] ?? 'Admin');
$role = htmlspecialchars(ucfirst($_SESSION['role'] ?? 'admin'));
$ava  = strtoupper(substr($user, 0, 1));
$uid  = (int)$_SESSION['user_id'];

function esc($c,$v){ return $c->real_escape_string($v); }

$flash = $flashType = '';

// ── POST: mark single read ─────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='mark_read'){
    $nid=(int)($_POST['nid']??0);
    if($nid) $conn->query("UPDATE notifications SET is_read=TRUE,read_at=NOW() WHERE id=$nid AND recipient_id=$uid");
    header("Location: notifications.php"); exit;
}

// ── POST: mark all read ────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='mark_all_read'){
    $conn->query("UPDATE notifications SET is_read=TRUE,read_at=NOW() WHERE recipient_id=$uid AND is_read=FALSE");
    $flash="All notifications marked as read."; $flashType='ok';
    header("Location: notifications.php?flash=".urlencode($flash)."&ft=$flashType"); exit;
}

// ── POST: delete single ────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='delete'){
    $nid=(int)($_POST['nid']??0);
    if($nid) $conn->query("DELETE FROM notifications WHERE id=$nid AND recipient_id=$uid");
    header("Location: notifications.php"); exit;
}

// ── POST: delete all read ──────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='delete_read'){
    $conn->query("DELETE FROM notifications WHERE recipient_id=$uid AND is_read=TRUE");
    $flash="Read notifications cleared."; $flashType='warn';
    header("Location: notifications.php?flash=".urlencode($flash)."&ft=$flashType"); exit;
}

if(isset($_GET['flash'])){ $flash=urldecode($_GET['flash']); $flashType=$_GET['ft']??'ok'; }

// ── FILTERS ────────────────────────────────────────────────────
$fType   = $_GET['type']??'';
$fRead   = $_GET['read']??'';   // '' = all, '0' = unread, '1' = read
$page    = max(1,(int)($_GET['page']??1));
$perPage = 20;
$offset  = ($page-1)*$perPage;

$w = "recipient_id=$uid";
if($fType)       $w .= " AND type='".esc($conn,$fType)."'";
if($fRead==='0') $w .= " AND is_read=FALSE";
if($fRead==='1') $w .= " AND is_read=TRUE";

$tc = $conn->query("SELECT COUNT(*) AS c FROM notifications WHERE $w");
$total = $tc ? (int)$tc->fetch_assoc()['c'] : 0;
$totalPages = max(1,(int)ceil($total/$perPage));

$notifs = $conn->query("SELECT n.*,
    u.username AS sender_name
    FROM notifications n
    LEFT JOIN users u ON u.id=n.sender_id
    WHERE $w ORDER BY n.created_at DESC
    LIMIT $perPage OFFSET $offset");

// Stats
function sv($c,$q){$r=$c->query($q);return $r?(int)($r->fetch_assoc()['v']??0):0;}
$stat_unread  = sv($conn,"SELECT COUNT(*) AS v FROM notifications WHERE recipient_id=$uid AND is_read=FALSE");
$stat_total   = sv($conn,"SELECT COUNT(*) AS v FROM notifications WHERE recipient_id=$uid");
$stat_today   = sv($conn,"SELECT COUNT(*) AS v FROM notifications WHERE recipient_id=$uid AND DATE(created_at)=CURDATE()");
$stat_read    = $stat_total - $stat_unread;

// Type counts for filter chips
$type_counts_r = $conn->query("SELECT type, COUNT(*) AS cnt FROM notifications WHERE recipient_id=$uid GROUP BY type ORDER BY cnt DESC");
$type_counts = [];
if($type_counts_r) while($row=$type_counts_r->fetch_assoc()) $type_counts[$row['type']] = (int)$row['cnt'];

function buildQS($ov=[]){
    $p=array_merge($_GET,$ov); unset($p['page']);
    $qs=http_build_query(array_filter($p,fn($v)=>$v!==''));
    return $qs?"?$qs&":"?";
}
$qs=buildQS();
$current_page='notifications';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SalesOS &mdash; Notifications</title>
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
/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:9px;font-family:inherit;font-size:.79rem;font-weight:700;cursor:pointer;border:none;transition:all .18s;text-decoration:none}
.btn-primary{background:var(--ac);color:#fff}.btn-primary:hover{background:var(--ac2)}
.btn-ghost{background:var(--bg2);border:1px solid var(--br);color:var(--t2)}.btn-ghost:hover{color:var(--tx);border-color:var(--br2)}
.btn-danger{background:var(--red);border:1px solid rgba(248,113,113,.3);color:var(--re)}.btn-danger:hover{background:var(--reb)}
.btn svg{width:13px;height:13px}
/* TYPE FILTER CHIPS */
.filter-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.chip{display:inline-flex;align-items:center;gap:6px;padding:6px 13px;border-radius:100px;font-size:.73rem;font-weight:600;cursor:pointer;transition:all .15s;text-decoration:none;border:1px solid var(--br);color:var(--t2);background:var(--bg2)}
.chip:hover{border-color:var(--br2);color:var(--tx)}
.chip.on{background:var(--ag);border-color:rgba(79,142,247,.3);color:var(--ac)}
.chip .cnt{font-size:.63rem;background:var(--bg3);padding:1px 6px;border-radius:100px;margin-left:2px}
.chip.on .cnt{background:rgba(79,142,247,.2)}
/* READ FILTER TABS */
.read-tabs{display:flex;gap:3px;background:var(--bg3);border-radius:10px;padding:3px;align-self:flex-start}
.rtab{padding:6px 14px;border-radius:7px;font-size:.75rem;font-weight:600;cursor:pointer;border:none;font-family:inherit;color:var(--t2);background:transparent;transition:all .15s;text-decoration:none}
.rtab:hover{color:var(--tx)}
.rtab.on{background:var(--bg2);color:var(--tx);box-shadow:var(--sh)}
/* NOTIFICATION LIST */
.notif-list{display:flex;flex-direction:column;background:var(--bg2);border:1px solid var(--br);border-radius:14px;overflow:hidden}
.notif-item{display:flex;align-items:flex-start;gap:14px;padding:16px 20px;border-bottom:1px solid var(--br);transition:background .12s;position:relative;cursor:pointer}
.notif-item:last-child{border-bottom:none}
.notif-item:hover{background:var(--bg3)}
.notif-item.unread{background:rgba(79,142,247,.04)}
.notif-item.unread:hover{background:rgba(79,142,247,.08)}
.notif-item.unread::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--ac);border-radius:0 2px 2px 0}
/* TYPE ICON */
.ntype-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.1rem}
/* Content */
.notif-body{flex:1;min-width:0}
.notif-title{font-size:.82rem;font-weight:700;color:var(--tx);line-height:1.35;margin-bottom:4px}
.notif-item.unread .notif-title{color:var(--tx)}
.notif-msg{font-size:.76rem;color:var(--t2);line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.notif-meta{display:flex;align-items:center;gap:8px;margin-top:6px;flex-wrap:wrap}
.notif-time{font-size:.67rem;color:var(--t3)}
.notif-from{font-size:.67rem;color:var(--t2)}
/* Actions */
.notif-actions{display:flex;align-items:center;gap:5px;flex-shrink:0;opacity:0;transition:opacity .15s}
.notif-item:hover .notif-actions{opacity:1}
.nact-btn{width:28px;height:28px;border-radius:7px;border:1px solid var(--br);background:transparent;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .15s;color:var(--t3)}
.nact-btn:hover{border-color:var(--br2);color:var(--tx)}
.nact-btn.read:hover{background:var(--ag);border-color:rgba(79,142,247,.3);color:var(--ac)}
.nact-btn.del:hover{background:var(--red);border-color:var(--reb);color:var(--re)}
.nact-btn svg{width:13px;height:13px}
/* Unread dot */
.unread-dot{width:8px;height:8px;border-radius:50%;background:var(--ac);flex-shrink:0;margin-top:6px}
/* PAGINATION */
.pager{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-top:1px solid var(--br)}
.pager-info{font-size:.74rem;color:var(--t2)}
.pager-btns{display:flex;gap:4px}
.pg-btn{min-width:32px;height:32px;padding:0 8px;border-radius:8px;border:1px solid var(--br);background:var(--bg3);color:var(--t2);font-family:inherit;font-size:.77rem;font-weight:600;cursor:pointer;transition:all .15s;text-decoration:none;display:flex;align-items:center;justify-content:center}
.pg-btn:hover{border-color:var(--br2);color:var(--tx)}
.pg-btn.on{background:var(--ac);border-color:var(--ac);color:#fff}
/* FLASH */
.flash{display:flex;align-items:center;gap:10px;padding:11px 16px;border-radius:10px;font-size:.82rem;font-weight:500;animation:fadeUp .35s cubic-bezier(.16,1,.3,1)}
.flash.ok  {background:var(--grd);border:1px solid rgba(52,211,153,.2);color:var(--gr)}
.flash.warn{background:var(--god);border:1px solid rgba(245,166,35,.2);color:var(--go)}
.flash.err {background:var(--red);border:1px solid rgba(248,113,113,.2);color:var(--re)}
.flash svg{width:15px;height:15px;flex-shrink:0}
/* PILLS */
.pill{display:inline-flex;align-items:center;padding:2px 8px;border-radius:100px;font-size:.63rem;font-weight:700;white-space:nowrap}
.pill.ok  {background:var(--grd);color:var(--gr)}
.pill.warn{background:var(--god);color:var(--go)}
.pill.err {background:var(--red);color:var(--re)}
.pill.info{background:var(--ag); color:var(--ac)}
.pill.pu  {background:var(--pud);color:var(--pu)}
.pill.nt  {background:var(--bg3);color:var(--t3);border:1px solid var(--br)}
/* EMPTY STATE */
.empty-state{padding:72px 20px;text-align:center;color:var(--t3);display:flex;flex-direction:column;align-items:center;gap:14px}
.empty-icon{width:64px;height:64px;border-radius:16px;background:var(--bg3);border:1px solid var(--br);display:flex;align-items:center;justify-content:center}
.empty-icon svg{width:28px;height:28px;opacity:.4}
.empty-title{font-size:.95rem;font-weight:700;color:var(--tx)}
.empty-sub{font-size:.78rem;color:var(--t2)}
/* RESPONSIVE */
@media(max-width:900px){.stats-grid{grid-template-columns:repeat(2,1fr)}.sidebar{transform:translateX(-100%)}.sidebar.open{transform:none}.main{margin-left:0}.mob-btn{display:block}.sbar{display:none}}
@media(max-width:640px){.stats-grid{grid-template-columns:repeat(2,1fr)}.content{padding:14px}.filter-row{gap:6px}}
</style>
</head>
<body>

<?php $current_page = 'notifications'; include 'sidebar.php'; ?>

<div class="main">
<?php $breadcrumbs=[['label'=>'Notifications']]; include 'topnav.php'; ?>

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
    <div class="ph-title">
      Notifications
      <?php if($stat_unread>0): ?>
      <span style="display:inline-flex;align-items:center;justify-content:center;background:var(--re);color:#fff;font-size:.7rem;font-weight:800;padding:3px 10px;border-radius:100px;margin-left:8px;vertical-align:middle"><?= $stat_unread ?></span>
      <?php endif; ?>
    </div>
    <div class="ph-sub">Stay updated on orders, payments, stock alerts and more</div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if($stat_unread>0): ?>
    <form method="POST" style="display:inline">
      <input type="hidden" name="action" value="mark_all_read">
      <button type="submit" class="btn btn-primary">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8" cy="8" r="7"/><path d="M5 8l2 2 4-4"/></svg>
        Mark All Read
      </button>
    </form>
    <?php endif; ?>
    <?php if($stat_read>0): ?>
    <form method="POST" style="display:inline" onsubmit="return confirm('Delete all read notifications?')">
      <input type="hidden" name="action" value="delete_read">
      <button type="submit" class="btn btn-danger">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 4h12M5 4V2h6v2M6 7v4M10 7v4M3 4l1 8h8l1-8"/></svg>
        Clear Read
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<!-- Stats -->
<div class="stats-grid">
  <div class="stat-card" style="--dl:.0s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--red)">
        <svg viewBox="0 0 16 16" fill="none" stroke="var(--re)" stroke-width="1.8"><path d="M8 1a5 5 0 015 5v2l1.5 2.5h-13L3 8V6a5 5 0 015-5z"/><path d="M6 13a2 2 0 004 0"/></svg>
      </div>
      <span class="stat-badge" style="background:var(--red);color:var(--re)">New</span>
    </div>
    <div class="stat-val"><?= $stat_unread ?></div>
    <div class="stat-lbl">Unread</div>
  </div>
  <div class="stat-card" style="--dl:.08s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--grd)">
        <svg viewBox="0 0 16 16" fill="none" stroke="var(--gr)" stroke-width="1.8"><circle cx="8" cy="8" r="7"/><path d="M5 8l2 2 4-4"/></svg>
      </div>
      <span class="stat-badge" style="background:var(--grd);color:var(--gr)">Done</span>
    </div>
    <div class="stat-val"><?= $stat_read ?></div>
    <div class="stat-lbl">Read</div>
  </div>
  <div class="stat-card" style="--dl:.16s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--ag)">
        <svg viewBox="0 0 16 16" fill="none" stroke="var(--ac)" stroke-width="1.8"><rect x="1" y="3" width="14" height="11" rx="1.5"/><path d="M1 7h14M5 3V1M11 3V1"/></svg>
      </div>
      <span class="stat-badge" style="background:var(--ag);color:var(--ac)">Today</span>
    </div>
    <div class="stat-val"><?= $stat_today ?></div>
    <div class="stat-lbl">Received Today</div>
  </div>
  <div class="stat-card" style="--dl:.24s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--pud)">
        <svg viewBox="0 0 16 16" fill="none" stroke="var(--pu)" stroke-width="1.8"><path d="M2 4h12M2 8h9M2 12h6"/></svg>
      </div>
      <span class="stat-badge" style="background:var(--pud);color:var(--pu)">All</span>
    </div>
    <div class="stat-val"><?= $stat_total ?></div>
    <div class="stat-lbl">Total</div>
  </div>
</div>

<!-- Filter row -->
<?php
$typeConfig = [
  'order_approval' => ['🔔', 'var(--go)', 'Order Approval'],
  'order_approved' => ['✅', 'var(--gr)', 'Approved'],
  'order_rejected' => ['❌', 'var(--re)', 'Rejected'],
  'low_stock'      => ['📦', 'var(--go)', 'Low Stock'],
  'payment_received'=> ['💰', 'var(--gr)', 'Payment'],
  'debt_overdue'   => ['⏰', 'var(--re)', 'Overdue'],
  'expiry_alert'   => ['⚠️',  'var(--go)', 'Expiry'],
  'general'        => ['📋', 'var(--ac)', 'General'],
];
?>
<div style="display:flex;flex-direction:column;gap:10px">
  <!-- Read filter tabs -->
  <div class="read-tabs">
    <a class="rtab <?= $fRead===''?'on':'' ?>" href="<?= buildQS(['read'=>'']) ?>page=1">All</a>
    <a class="rtab <?= $fRead==='0'?'on':'' ?>" href="<?= buildQS(['read'=>'0']) ?>page=1">
      Unread <?php if($stat_unread>0): ?><span style="font-size:.62rem;background:var(--re);color:#fff;padding:1px 6px;border-radius:100px;margin-left:3px"><?= $stat_unread ?></span><?php endif; ?>
    </a>
    <a class="rtab <?= $fRead==='1'?'on':'' ?>" href="<?= buildQS(['read'=>'1']) ?>page=1">Read</a>
  </div>

  <!-- Type chips -->
  <?php if(!empty($type_counts)): ?>
  <div class="filter-row">
    <a class="chip <?= $fType===''?'on':'' ?>" href="<?= buildQS(['type'=>'']) ?>page=1">
      All <span class="cnt"><?= $stat_total ?></span>
    </a>
    <?php foreach($type_counts as $t=>$cnt):
      $cfg=$typeConfig[$t]??['📋','var(--ac)',ucfirst(str_replace('_',' ',$t))];
    ?>
    <a class="chip <?= $fType===$t?'on':'' ?>" href="<?= buildQS(['type'=>$t]) ?>page=1">
      <?= $cfg[0] ?> <?= $cfg[2] ?> <span class="cnt"><?= $cnt ?></span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Notification list -->
<?php if($notifs && $notifs->num_rows > 0): ?>
<div class="notif-list">
<?php while($n=$notifs->fetch_assoc()):
  $cfg = $typeConfig[$n['type']] ?? ['📋','var(--ac)','General'];
  [$icon, $clr, $typeLbl] = $cfg;
  $isUnread = !$n['is_read'];

  // Time ago
  $diff = time()-strtotime($n['created_at']);
  if($diff < 60)       $timeAgo = $diff.'s ago';
  elseif($diff < 3600) $timeAgo = floor($diff/60).'m ago';
  elseif($diff < 86400)$timeAgo = floor($diff/3600).'h ago';
  elseif($diff < 604800)$timeAgo= floor($diff/86400).'d ago';
  else                 $timeAgo = date('M j, Y',strtotime($n['created_at']));

  // Reference link
  $refLink = '';
  if($n['reference_table']==='sales' && $n['reference_id']) $refLink = 'sales.php';
  elseif($n['reference_table']==='products' && $n['reference_id']) $refLink = 'stock.php';
?>
<div class="notif-item <?= $isUnread?'unread':'' ?>"
     onclick="markRead(<?= $n['id'] ?>,<?= $isUnread?'true':'false' ?>,'<?= htmlspecialchars(addslashes($refLink)) ?>')">

  <!-- Icon -->
  <div class="ntype-icon" style="background:<?= $clr ?>22;border:1px solid <?= $clr ?>33">
    <?= $icon ?>
  </div>

  <!-- Body -->
  <div class="notif-body">
    <div class="notif-title"><?= htmlspecialchars($n['title']) ?></div>
    <div class="notif-msg"><?= htmlspecialchars($n['message']) ?></div>
    <div class="notif-meta">
      <span class="notif-time"><?= $timeAgo ?></span>
      <?php if($n['sender_name']): ?>
      <span style="color:var(--t3)">·</span>
      <span class="notif-from">from <?= htmlspecialchars($n['sender_name']) ?></span>
      <?php endif; ?>
      <span style="color:var(--t3)">·</span>
      <span class="pill <?= $isUnread?'info':'nt' ?>" style="font-size:.6rem;padding:1px 7px">
        <?= $typeLbl ?>
      </span>
      <?php if($n['read_at'] && !$isUnread): ?>
      <span style="color:var(--t3)">·</span>
      <span style="font-size:.65rem;color:var(--t3)">Read <?= date('M j',strtotime($n['read_at'])) ?></span>
      <?php endif; ?>
    </div>
  </div>

  <!-- Unread dot -->
  <?php if($isUnread): ?>
  <div class="unread-dot"></div>
  <?php endif; ?>

  <!-- Action buttons -->
  <div class="notif-actions" onclick="event.stopPropagation()">
    <?php if($isUnread): ?>
    <form method="POST" style="display:inline">
      <input type="hidden" name="action" value="mark_read">
      <input type="hidden" name="nid" value="<?= $n['id'] ?>">
      <button type="submit" class="nact-btn read" title="Mark as read">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8" cy="8" r="7"/><path d="M5 8l2 2 4-4"/></svg>
      </button>
    </form>
    <?php endif; ?>
    <form method="POST" style="display:inline">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="nid" value="<?= $n['id'] ?>">
      <button type="submit" class="nact-btn del" title="Delete">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 4h12M5 4V2h6v2M6 7v4M10 7v4M3 4l1 8h8l1-8"/></svg>
      </button>
    </form>
  </div>

</div>
<?php endwhile; ?>

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

</div><!-- /notif-list -->

<?php else: ?>
<div class="notif-list">
  <div class="empty-state">
    <div class="empty-icon">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M8 1a5 5 0 015 5v2l1.5 2.5h-13L3 8V6a5 5 0 015-5z"/><path d="M6 13a2 2 0 004 0"/></svg>
    </div>
    <div class="empty-title">
      <?php if($fRead==='0'): ?>All caught up!
      <?php elseif($fType): ?>No <?= htmlspecialchars($typeConfig[$fType][2]??$fType) ?> notifications
      <?php else: ?>No notifications yet<?php endif; ?>
    </div>
    <div class="empty-sub">
      <?php if($fRead==='0'): ?>You have no unread notifications.
      <?php elseif($fType||$fRead): ?><a href="notifications.php" style="color:var(--ac)">View all notifications</a>
      <?php else: ?>Notifications about orders, payments and stock will appear here.<?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

</div><!-- /content -->
</div><!-- /main -->

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

// Mark read + navigate
function markRead(id, isUnread, refLink){
  if(isUnread){
    const f=document.createElement('form');
    f.method='POST'; f.action='notifications.php';
    f.innerHTML=`<input type="hidden" name="action" value="mark_read"><input type="hidden" name="nid" value="${id}">`;
    document.body.appendChild(f);
    if(refLink){
      // Submit silently then navigate
      fetch('notifications.php',{method:'POST',body:new FormData(f)}).finally(()=>{
        if(refLink) window.location.href=refLink;
      });
    } else { f.submit(); }
  } else if(refLink){
    window.location.href=refLink;
  }
}

// Flash dismiss
setTimeout(()=>{
  const f=document.getElementById('flashMsg');
  if(f){f.style.transition='opacity .5s';f.style.opacity='0';setTimeout(()=>f?.remove(),520);}
},4000);
</script>
</body>
</html>
