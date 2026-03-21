<?php
require_once 'connection.php';
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

// Only admin can access this page
if ($_SESSION['role'] !== 'admin') { header('Location: index.php'); exit; }

$user = htmlspecialchars($_SESSION['username'] ?? 'Admin');
$role = htmlspecialchars(ucfirst($_SESSION['role'] ?? 'admin'));
$ava  = strtoupper(substr($user, 0, 1));
$uid  = (int)$_SESSION['user_id'];

function esc($c,$v){ return $c->real_escape_string($v); }
$flash = $flashType = '';

// ── POST: add user ─────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='add'){
    $uname  = trim($_POST['username']??'');
    $email  = trim($_POST['email']??'');
    $pass   = $_POST['password']??'';
    $urole  = $_POST['role']??'representative';
    $active = isset($_POST['is_active'])?1:0;

    if(!$uname||!$email||!$pass){ $flash="Username, email and password are required."; $flashType='err'; }
    else{
        $un = esc($conn,$uname); $em = esc($conn,$email);
        // Check unique
        $chk=$conn->query("SELECT id FROM users WHERE username='$un' OR email='$em' LIMIT 1");
        if($chk && $chk->num_rows){
            $flash="Username or email already exists."; $flashType='err';
        } else {
            $hash = hash('sha256',$pass);
            $ur   = esc($conn,$urole);
            if($conn->query("INSERT INTO users(username,email,password_hash,role,is_active)
                VALUES('$un','$em','$hash','$ur',$active)")){
                $nid=$conn->insert_id;
                $conn->query("INSERT INTO audit_logs(user_id,user_name,action,table_name,record_id,description)
                    VALUES($uid,'$user','CREATE','users',$nid,'User $uname created with role $urole')");
                $flash="User <strong>$uname</strong> created."; $flashType='ok';
            } else { $flash="Error: ".htmlspecialchars($conn->error); $flashType='err'; }
        }
    }
    header("Location: users.php?flash=".urlencode($flash)."&ft=$flashType"); exit;
}

// ── POST: edit user ────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='edit'){
    $eid   = (int)($_POST['user_id']??0);
    $email = esc($conn,trim($_POST['email']??''));
    $urole = esc($conn,$_POST['role']??'representative');
    $active= isset($_POST['is_active'])?1:0;

    if($eid && $eid!==$uid){ // prevent self-demotion
        $conn->query("UPDATE users SET email='$email',role='$urole',is_active=$active WHERE id=$eid");
        $conn->query("INSERT INTO audit_logs(user_id,user_name,action,table_name,record_id,description)
            VALUES($uid,'$user','UPDATE','users',$eid,'User #$eid updated: role=$urole active=$active')");
        $flash="User updated."; $flashType='ok';
    } elseif($eid===$uid){
        // Allow self email update only
        $conn->query("UPDATE users SET email='$email' WHERE id=$uid");
        $flash="Your email updated."; $flashType='ok';
    }
    header("Location: users.php?flash=".urlencode($flash)."&ft=$flashType"); exit;
}

// ── POST: reset password ───────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='reset_password'){
    $eid  = (int)($_POST['user_id']??0);
    $pass = $_POST['new_password']??'';
    if($eid && strlen($pass)>=6){
        $hash=hash('sha256',$pass);
        $conn->query("UPDATE users SET password_hash='$hash' WHERE id=$eid");
        $conn->query("INSERT INTO audit_logs(user_id,user_name,action,table_name,record_id,description)
            VALUES($uid,'$user','PASSWORD_CHANGE','users',$eid,'Password reset for user #$eid')");
        $flash="Password reset successfully."; $flashType='ok';
    } else { $flash="Password must be at least 6 characters."; $flashType='err'; }
    header("Location: users.php?flash=".urlencode($flash)."&ft=$flashType"); exit;
}

// ── POST: toggle active ────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='toggle'){
    $eid = (int)($_POST['user_id']??0);
    $val = (int)($_POST['is_active']??0);
    if($eid && $eid!==$uid){
        $conn->query("UPDATE users SET is_active=$val WHERE id=$eid");
        $conn->query("INSERT INTO audit_logs(user_id,user_name,action,table_name,record_id,description)
            VALUES($uid,'$user','STATUS_CHANGE','users',$eid,'User #$eid ".($val?'activated':'deactivated')."')");
        $flash="User ".($val?'activated':'deactivated')."."; $flashType=$val?'ok':'warn';
    }
    header("Location: users.php?flash=".urlencode($flash)."&ft=$flashType"); exit;
}

// ── POST: delete user ──────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='delete'){
    $eid=(int)($_POST['user_id']??0);
    if($eid && $eid!==$uid){
        $conn->query("UPDATE users SET is_active=0 WHERE id=$eid"); // soft delete
        $conn->query("INSERT INTO audit_logs(user_id,user_name,action,table_name,record_id,description)
            VALUES($uid,'$user','DELETE','users',$eid,'User #$eid deactivated/deleted')");
        $flash="User deactivated."; $flashType='warn';
    }
    header("Location: users.php?flash=".urlencode($flash)."&ft=$flashType"); exit;
}

if(isset($_GET['flash'])){ $flash=urldecode($_GET['flash']); $flashType=$_GET['ft']??'ok'; }

// ── FILTERS ────────────────────────────────────────────────────
$search  = trim($_GET['q']??'');
$fRole   = $_GET['role']??'';
$fActive = $_GET['active']??'';
$page    = max(1,(int)($_GET['page']??1));
$perPage = 15;
$offset  = ($page-1)*$perPage;

$w="1=1";
if($search){ $s=esc($conn,$search); $w.=" AND (username LIKE '%$s%' OR email LIKE '%$s%')"; }
if($fRole)  $w.=" AND role='".esc($conn,$fRole)."'";
if($fActive==='1') $w.=" AND is_active=1";
if($fActive==='0') $w.=" AND is_active=0";

$tc=$conn->query("SELECT COUNT(*) AS c FROM users WHERE $w");
$total=$tc?(int)$tc->fetch_assoc()['c']:0;
$totalPages=max(1,(int)ceil($total/$perPage));

$users=$conn->query("SELECT u.*,
    (SELECT COUNT(*) FROM sales WHERE created_by=u.id) AS sale_count,
    (SELECT COUNT(*) FROM audit_logs WHERE user_id=u.id) AS log_count
    FROM users u WHERE $w ORDER BY u.is_active DESC, u.role ASC, u.username ASC
    LIMIT $perPage OFFSET $offset");

// Stats
function sv($c,$q){$r=$c->query($q);return $r?(int)($r->fetch_assoc()['v']??0):0;}
$stat_total   = sv($conn,"SELECT COUNT(*) AS v FROM users");
$stat_active  = sv($conn,"SELECT COUNT(*) AS v FROM users WHERE is_active=1");
$stat_admins  = sv($conn,"SELECT COUNT(*) AS v FROM users WHERE role='admin' AND is_active=1");
$stat_acct    = sv($conn,"SELECT COUNT(*) AS v FROM users WHERE role='accountant' AND is_active=1");
$stat_reps    = sv($conn,"SELECT COUNT(*) AS v FROM users WHERE role='representative' AND is_active=1");
$stat_inactive= sv($conn,"SELECT COUNT(*) AS v FROM users WHERE is_active=0");

// Online users (last_login within 15 min)
$online_r=$conn->query("SELECT id FROM users WHERE last_login >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND is_active=1");
$online_ids=[];
if($online_r) while($row=$online_r->fetch_assoc()) $online_ids[]=(int)$row['id'];

function buildQS($ov=[]){
    $p=array_merge($_GET,$ov); unset($p['page']);
    $qs=http_build_query(array_filter($p,fn($v)=>$v!==''));
    return $qs?"?$qs&":"?";
}
$qs=buildQS();
$current_page='users';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SalesOS &mdash; Users</title>
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
  --pu:#A78BFA;--pud:rgba(167,139,250,.12);--pub:rgba(167,139,250,.22);
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
  --pu:#7C3AED;--pud:rgba(124,58,237,.1);--pub:rgba(124,58,237,.18);
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
.stat-val{font-size:1.6rem;font-weight:800;color:var(--tx);letter-spacing:-.04em;line-height:1}
.stat-lbl{font-size:.71rem;color:var(--t2);margin-top:5px;font-weight:500}
@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
/* PAGE HEADER */
.page-hdr{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}
.ph-title{font-size:1.45rem;font-weight:800;color:var(--tx);letter-spacing:-.03em}
.ph-sub{font-size:.78rem;color:var(--t2);margin-top:4px}
/* TOOLBAR */
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
.btn-warn{background:var(--god);border:1px solid rgba(245,166,35,.3);color:var(--go)}.btn-warn:hover{background:var(--gob)}
.btn-danger{background:var(--red);border:1px solid rgba(248,113,113,.3);color:var(--re)}.btn-danger:hover{background:var(--reb)}
.btn-purple{background:var(--pud);border:1px solid rgba(167,139,250,.3);color:var(--pu)}.btn-purple:hover{background:var(--pub)}
.btn svg{width:13px;height:13px}
/* USER CARDS GRID */
.user-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px}
/* USER CARD */
.user-card{background:var(--bg2);border:1px solid var(--br);border-radius:14px;overflow:hidden;transition:all .2s;animation:fadeUp .4s cubic-bezier(.16,1,.3,1) both}
.user-card:hover{border-color:var(--br2);box-shadow:var(--sh)}
.user-card.inactive{opacity:.6}
.uc-top{padding:20px 20px 16px;display:flex;align-items:flex-start;gap:14px}
.uc-avatar{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;font-weight:800;color:#fff;flex-shrink:0;position:relative}
.uc-online{position:absolute;bottom:-2px;right:-2px;width:12px;height:12px;border-radius:50%;border:2px solid var(--bg2)}
.uc-info{flex:1;min-width:0}
.uc-name{font-size:.92rem;font-weight:800;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.uc-email{font-size:.71rem;color:var(--t2);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.uc-badges{display:flex;align-items:center;gap:6px;margin-top:8px;flex-wrap:wrap}
/* STATS ROW */
.uc-stats{display:grid;grid-template-columns:1fr 1fr 1fr;border-top:1px solid var(--br)}
.uc-stat{padding:12px 0;text-align:center;border-right:1px solid var(--br)}
.uc-stat:last-child{border-right:none}
.uc-stat-val{font-size:.9rem;font-weight:800;color:var(--tx)}
.uc-stat-lbl{font-size:.62rem;color:var(--t3);margin-top:2px;text-transform:uppercase;letter-spacing:.06em;font-weight:600}
/* ACTIONS ROW */
.uc-actions{display:flex;gap:6px;padding:12px 16px;border-top:1px solid var(--br);flex-wrap:wrap}
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
.pager{display:flex;align-items:center;justify-content:space-between;padding:14px 0}
.pager-info{font-size:.74rem;color:var(--t2)}
.pager-btns{display:flex;gap:4px}
.pg-btn{min-width:32px;height:32px;padding:0 8px;border-radius:8px;border:1px solid var(--br);background:var(--bg2);color:var(--t2);font-family:inherit;font-size:.77rem;font-weight:600;cursor:pointer;transition:all .15s;text-decoration:none;display:flex;align-items:center;justify-content:center}
.pg-btn:hover{border-color:var(--br2);color:var(--tx)}
.pg-btn.on{background:var(--ac);border-color:var(--ac);color:#fff}
/* MODAL */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:400;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .25s;backdrop-filter:blur(6px)}
.overlay.open{opacity:1;pointer-events:all}
.modal{background:var(--bg2);border:1px solid var(--br2);border-radius:18px;width:100%;max-width:520px;max-height:92vh;overflow-y:auto;box-shadow:var(--sh2);transform:translateY(20px) scale(.97);transition:transform .3s cubic-bezier(.16,1,.3,1)}
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
.finput,.fsel-m{width:100%;background:var(--bg3);border:1.5px solid var(--br);border-radius:9px;padding:9px 12px;font-family:inherit;font-size:.82rem;color:var(--tx);outline:none;transition:border-color .2s}
.finput:focus,.fsel-m:focus{border-color:var(--ac);background:var(--bg)}
.finput::placeholder{color:var(--t3)}
.pw-wrap{position:relative}
.pw-wrap .finput{padding-right:40px}
.pw-toggle{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--t2);cursor:pointer;font-size:.82rem;padding:4px}
.pw-toggle:hover{color:var(--tx)}
/* STRENGTH METER */
.pw-strength{height:4px;border-radius:2px;margin-top:4px;transition:all .3s;background:var(--br)}
.pw-strength-bar{height:100%;border-radius:2px;transition:width .3s,background .3s}
.pw-hint{font-size:.65rem;color:var(--t3);margin-top:2px}
/* ROLE CARDS */
.role-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.role-card{border:2px solid var(--br);border-radius:10px;padding:11px 10px;cursor:pointer;transition:all .15s;text-align:center}
.role-card:hover{border-color:var(--br2)}
.role-card.sel{border-color:var(--ac);background:var(--ag)}
.role-card input{display:none}
.role-card-icon{font-size:1.4rem;margin-bottom:4px}
.role-card-name{font-size:.74rem;font-weight:700;color:var(--tx)}
.role-card-desc{font-size:.62rem;color:var(--t2);margin-top:2px;line-height:1.3}
/* CHECKBOX */
.fcheck{display:flex;align-items:center;gap:9px;cursor:pointer;font-size:.82rem;color:var(--tx)}
.fcheck input{width:15px;height:15px;accent-color:var(--ac);cursor:pointer}
/* DIVIDER */
.fdiv{font-size:.67rem;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.1em;padding:8px 0 4px;border-bottom:1px solid var(--br);margin-bottom:4px}
/* EMPTY */
.empty-state{padding:60px 20px;text-align:center;color:var(--t3)}
.empty-state svg{width:40px;height:40px;margin:0 auto 12px;opacity:.28;display:block}
.empty-state p{font-size:.82rem}
/* RESPONSIVE */
@media(max-width:1400px){.stats-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:900px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:none}.main{margin-left:0}.mob-btn{display:block}.sbar{display:none}}
@media(max-width:640px){.stats-grid{grid-template-columns:repeat(2,1fr)}.content{padding:14px}.frow2,.role-cards{grid-template-columns:1fr}}
</style>
</head>
<body>

<?php $current_page = 'users'; include 'sidebar.php'; ?>

<div class="main">
<?php $breadcrumbs=[['label'=>'System'],['label'=>'Users']]; include 'topnav.php'; ?>

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
    <div class="ph-title">User Management</div>
    <div class="ph-sub">Manage system accounts, roles and permissions</div>
  </div>
  <button class="btn btn-primary" onclick="openAdd()">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M8 1v14M1 8h14"/></svg>
    Add User
  </button>
</div>

<!-- Stats -->
<div class="stats-grid">
  <div class="stat-card" style="--dl:.0s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--ag)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--ac)" stroke-width="1.8"><circle cx="5" cy="5" r="3"/><circle cx="11" cy="5" r="3"/><path d="M1 14c0-2.76 1.8-5 4-5M15 14c0-2.76-1.8-5-4-5M7 14c0-2.76.9-5 2-5s2 2.24 2 5"/></svg></div>
      <span class="stat-badge" style="background:var(--ag);color:var(--ac)">Total</span>
    </div>
    <div class="stat-val"><?= $stat_total ?></div>
    <div class="stat-lbl">All Users</div>
  </div>
  <div class="stat-card" style="--dl:.06s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--grd)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--gr)" stroke-width="1.8"><circle cx="8" cy="8" r="7"/><path d="M5 8l2 2 4-4"/></svg></div>
      <span class="stat-badge" style="background:var(--grd);color:var(--gr)">Active</span>
    </div>
    <div class="stat-val"><?= $stat_active ?></div>
    <div class="stat-lbl">Active</div>
  </div>
  <div class="stat-card" style="--dl:.12s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--red)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--re)" stroke-width="1.8"><circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 2"/></svg></div>
      <span class="stat-badge" style="background:var(--red);color:var(--re)">Admin</span>
    </div>
    <div class="stat-val"><?= $stat_admins ?></div>
    <div class="stat-lbl">Admins</div>
  </div>
  <div class="stat-card" style="--dl:.18s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--god)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--go)" stroke-width="1.8"><path d="M2 2h12v12H2z"/><path d="M5 8h6M5 5h6M5 11h3"/></svg></div>
      <span class="stat-badge" style="background:var(--god);color:var(--go)">Finance</span>
    </div>
    <div class="stat-val"><?= $stat_acct ?></div>
    <div class="stat-lbl">Accountants</div>
  </div>
  <div class="stat-card" style="--dl:.24s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--pud)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--pu)" stroke-width="1.8"><path d="M3 10l3 3 4-5 4 4"/><circle cx="8" cy="4" r="3"/></svg></div>
      <span class="stat-badge" style="background:var(--pud);color:var(--pu)">Sales</span>
    </div>
    <div class="stat-val"><?= $stat_reps ?></div>
    <div class="stat-lbl">Representatives</div>
  </div>
  <div class="stat-card" style="--dl:.3s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--bg3)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--t2)" stroke-width="1.8"><path d="M2 2l12 12M14 2L2 14"/></svg></div>
      <span class="stat-badge" style="background:var(--bg3);color:var(--t3)">Off</span>
    </div>
    <div class="stat-val"><?= $stat_inactive ?></div>
    <div class="stat-lbl">Inactive</div>
  </div>
</div>

<!-- Toolbar -->
<form method="GET" action="users.php" id="filterForm">
  <div class="toolbar">
    <div class="searchbox">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="7" cy="7" r="4.5"/><path d="M11 11l3 3"/></svg>
      <input type="text" name="q" placeholder="Search username or email&hellip;" value="<?= htmlspecialchars($search) ?>" id="searchInp">
    </div>
    <select name="role" class="fsel" onchange="this.form.submit()">
      <option value="">All Roles</option>
      <option value="admin"          <?= $fRole==='admin'?'selected':'' ?>>Admin</option>
      <option value="accountant"     <?= $fRole==='accountant'?'selected':'' ?>>Accountant</option>
      <option value="representative" <?= $fRole==='representative'?'selected':'' ?>>Representative</option>
    </select>
    <select name="active" class="fsel" onchange="this.form.submit()">
      <option value="">All Status</option>
      <option value="1" <?= $fActive==='1'?'selected':'' ?>>Active</option>
      <option value="0" <?= $fActive==='0'?'selected':'' ?>>Inactive</option>
    </select>
    <button type="submit" class="btn btn-primary" style="padding:8px 14px">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="7" cy="7" r="4.5"/><path d="M11 11l3 3"/></svg>Search
    </button>
    <?php if($search||$fRole||$fActive!==''): ?>
    <a href="users.php" class="btn btn-ghost" style="padding:8px 14px">Clear</a>
    <?php endif; ?>
    <div class="tbr"><span class="count-lbl"><?= $total ?> user<?= $total!=1?'s':'' ?></span></div>
  </div>
</form>

<!-- User Cards -->
<?php if($users && $users->num_rows > 0): ?>
<div class="user-grid">
<?php
$roleGrad = [
  'admin'          => ['linear-gradient(135deg,#4F8EF7,#6BA3FF)','var(--red)','var(--re)','Admin'],
  'accountant'     => ['linear-gradient(135deg,#F5A623,#D4880A)','var(--god)','var(--go)','Accountant'],
  'representative' => ['linear-gradient(135deg,#A78BFA,#7C3AED)','var(--pud)','var(--pu)','Rep'],
];
while($u=$users->fetch_assoc()):
  $rg  = $roleGrad[$u['role']] ?? ['linear-gradient(135deg,#4F8EF7,#6BA3FF)','var(--ag)','var(--ac)','User'];
  $isSelf  = ($u['id']==$uid);
  $isOnline= in_array((int)$u['id'],$online_ids) || $isSelf;
  $initials= strtoupper(substr($u['username'],0,2));

  // Last login time ago
  $lastLogin = '—';
  if($u['last_login']){
    $diff=time()-strtotime($u['last_login']);
    if($diff<60)        $lastLogin='Just now';
    elseif($diff<3600)  $lastLogin=floor($diff/60).'m ago';
    elseif($diff<86400) $lastLogin=floor($diff/3600).'h ago';
    elseif($diff<604800)$lastLogin=floor($diff/86400).'d ago';
    else                $lastLogin=date('M j',strtotime($u['last_login']));
  }
?>
<div class="user-card <?= !$u['is_active']?'inactive':'' ?>">
  <div class="uc-top">
    <!-- Avatar -->
    <div class="uc-avatar" style="background:<?= $rg[0] ?>">
      <?= $initials ?>
      <div class="uc-online" style="background:<?= $isOnline?'var(--gr)':'var(--bg3)' ?>;border-color:var(--bg2)"></div>
    </div>
    <!-- Info -->
    <div class="uc-info">
      <div class="uc-name">
        <?= htmlspecialchars($u['username']) ?>
        <?php if($isSelf): ?><span style="font-size:.62rem;color:var(--ac);font-weight:500;margin-left:5px">(you)</span><?php endif; ?>
      </div>
      <div class="uc-email"><?= htmlspecialchars($u['email']) ?></div>
      <div class="uc-badges">
        <span class="pill" style="background:<?= $rg[1] ?>;color:<?= $rg[2] ?>"><?= $rg[3] ?></span>
        <?php if($u['is_active']): ?>
          <span class="pill ok">Active</span>
        <?php else: ?>
          <span class="pill nt">Inactive</span>
        <?php endif; ?>
        <?php if($isOnline): ?>
          <span style="font-size:.63rem;color:var(--gr);font-weight:600;display:flex;align-items:center;gap:3px">
            <span style="width:6px;height:6px;border-radius:50%;background:var(--gr);display:inline-block"></span>Online
          </span>
        <?php endif; ?>
      </div>
    </div>
    <!-- Last login -->
    <div style="text-align:right;flex-shrink:0">
      <div style="font-size:.62rem;color:var(--t3);margin-bottom:2px">Last login</div>
      <div style="font-size:.72rem;color:var(--t2);font-weight:500"><?= $lastLogin ?></div>
    </div>
  </div>

  <!-- Stats -->
  <div class="uc-stats">
    <div class="uc-stat">
      <div class="uc-stat-val"><?= number_format($u['sale_count']) ?></div>
      <div class="uc-stat-lbl">Sales</div>
    </div>
    <div class="uc-stat">
      <div class="uc-stat-val"><?= number_format($u['log_count']) ?></div>
      <div class="uc-stat-lbl">Actions</div>
    </div>
    <div class="uc-stat">
      <div class="uc-stat-val"><?= date('M Y',strtotime($u['created_at'])) ?></div>
      <div class="uc-stat-lbl">Joined</div>
    </div>
  </div>

  <!-- Actions -->
  <div class="uc-actions">
    <button class="btn btn-ghost" style="flex:1;padding:6px 10px;font-size:.74rem;justify-content:center"
      onclick="openEdit(<?= htmlspecialchars(json_encode($u),ENT_QUOTES) ?>)">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M11 2l3 3-9 9H2v-3z"/></svg>Edit
    </button>
    <button class="btn btn-purple" style="flex:1;padding:6px 10px;font-size:.74rem;justify-content:center"
      onclick="openResetPw(<?= $u['id'] ?>,'<?= htmlspecialchars(addslashes($u['username'])) ?>')">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 8a6 6 0 0112 0"/><path d="M14 8v3a1 1 0 01-1 1H3a1 1 0 01-1-1V8"/><path d="M8 12v3M5 15h6"/></svg>Reset PW
    </button>
    <?php if(!$isSelf): ?>
    <form method="POST" style="flex:1;display:flex">
      <input type="hidden" name="action" value="toggle">
      <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
      <input type="hidden" name="is_active" value="<?= $u['is_active']?0:1 ?>">
      <button type="submit" class="btn <?= $u['is_active']?'btn-warn':'btn-ghost' ?>"
        style="flex:1;padding:6px 10px;font-size:.74rem;justify-content:center"
        onclick="return confirm('<?= $u['is_active']?'Deactivate':'Activate' ?> this user?')">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8">
          <?php if($u['is_active']): ?>
            <path d="M8 1a7 7 0 100 14A7 7 0 008 1z"/><path d="M6 6v4M10 6v4"/>
          <?php else: ?>
            <circle cx="8" cy="8" r="7"/><path d="M5 8l2 2 4-4"/>
          <?php endif; ?>
        </svg>
        <?= $u['is_active']?'Disable':'Enable' ?>
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php endwhile; ?>
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
<div style="background:var(--bg2);border:1px solid var(--br);border-radius:14px">
  <div class="empty-state">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4"><circle cx="5" cy="5" r="3"/><circle cx="11" cy="5" r="3"/><path d="M1 14c0-2.76 1.8-5 4-5M15 14c0-2.76-1.8-5-4-5M7 14c0-2.76.9-5 2-5s2 2.24 2 5"/></svg>
    <p>No users found<?= ($search||$fRole||$fActive!=='')?'. Try adjusting your filters.':'.'; ?></p>
  </div>
</div>
<?php endif; ?>

</div><!-- /content -->
</div><!-- /main -->

<!-- ═══ ADD USER MODAL ═══ -->
<div class="overlay" id="addOverlay" onclick="if(event.target===this)closeOv('addOverlay')">
  <div class="modal">
    <div class="mhead">
      <div><div class="mtitle">Add User</div><div class="msub">Create a new system account</div></div>
      <button class="mclose" onclick="closeOv('addOverlay')">&#x2715;</button>
    </div>
    <form method="POST" action="users.php" id="addForm">
      <input type="hidden" name="action" value="add">
      <div class="mbody">
        <div class="frow2">
          <div class="fgrp">
            <div class="flbl">Username <span class="req">*</span></div>
            <input type="text" name="username" class="finput" placeholder="e.g. john_doe" required autocomplete="off">
          </div>
          <div class="fgrp">
            <div class="flbl">Email <span class="req">*</span></div>
            <input type="email" name="email" class="finput" placeholder="john@company.com" required>
          </div>
        </div>
        <div class="fgrp">
          <div class="flbl">Password <span class="req">*</span></div>
          <div class="pw-wrap">
            <input type="password" name="password" id="addPw" class="finput" placeholder="Min 6 characters" required minlength="6" oninput="checkStrength(this,'addStr')">
            <button type="button" class="pw-toggle" onclick="togglePw('addPw',this)">👁</button>
          </div>
          <div class="pw-strength"><div class="pw-strength-bar" id="addStr" style="width:0"></div></div>
          <div class="pw-hint" id="addHint"></div>
        </div>
        <div class="fgrp">
          <div class="flbl" style="margin-bottom:8px">Role <span class="req">*</span></div>
          <div class="role-cards">
            <label class="role-card" id="rc-admin" onclick="selectRole('admin')">
              <input type="radio" name="role" value="admin">
              <div class="role-card-icon">🔑</div>
              <div class="role-card-name">Admin</div>
              <div class="role-card-desc">Full access to all features</div>
            </label>
            <label class="role-card" id="rc-accountant" onclick="selectRole('accountant')">
              <input type="radio" name="role" value="accountant">
              <div class="role-card-icon">📊</div>
              <div class="role-card-name">Accountant</div>
              <div class="role-card-desc">Finance, payments & approvals</div>
            </label>
            <label class="role-card sel" id="rc-representative" onclick="selectRole('representative')">
              <input type="radio" name="role" value="representative" checked>
              <div class="role-card-icon">🤝</div>
              <div class="role-card-name">Rep</div>
              <div class="role-card-desc">Sales orders & customers</div>
            </label>
          </div>
        </div>
        <label class="fcheck">
          <input type="checkbox" name="is_active" checked>
          Account active (user can log in immediately)
        </label>
      </div>
      <div class="mfoot">
        <button type="button" class="btn btn-ghost" onclick="closeOv('addOverlay')">Cancel</button>
        <button type="submit" class="btn btn-primary">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M8 1v14M1 8h14"/></svg>
          Create User
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ═══ EDIT USER MODAL ═══ -->
<div class="overlay" id="editOverlay" onclick="if(event.target===this)closeOv('editOverlay')">
  <div class="modal">
    <div class="mhead">
      <div><div class="mtitle">Edit User</div><div class="msub" id="editSub"></div></div>
      <button class="mclose" onclick="closeOv('editOverlay')">&#x2715;</button>
    </div>
    <form method="POST" action="users.php">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="user_id" id="editId">
      <div class="mbody">
        <div class="fgrp">
          <div class="flbl">Username</div>
          <input type="text" id="editUsername" class="finput" disabled style="opacity:.5;cursor:not-allowed">
        </div>
        <div class="fgrp">
          <div class="flbl">Email <span class="req">*</span></div>
          <input type="email" name="email" id="editEmail" class="finput" required>
        </div>
        <div class="fgrp" id="editRoleWrap">
          <div class="flbl" style="margin-bottom:8px">Role</div>
          <div class="role-cards" id="editRoleCards">
            <label class="role-card" id="erc-admin" onclick="selectEditRole('admin')">
              <input type="radio" name="role" value="admin">
              <div class="role-card-icon">🔑</div>
              <div class="role-card-name">Admin</div>
              <div class="role-card-desc">Full access</div>
            </label>
            <label class="role-card" id="erc-accountant" onclick="selectEditRole('accountant')">
              <input type="radio" name="role" value="accountant">
              <div class="role-card-icon">📊</div>
              <div class="role-card-name">Accountant</div>
              <div class="role-card-desc">Finance & approvals</div>
            </label>
            <label class="role-card" id="erc-representative" onclick="selectEditRole('representative')">
              <input type="radio" name="role" value="representative">
              <div class="role-card-icon">🤝</div>
              <div class="role-card-name">Rep</div>
              <div class="role-card-desc">Sales & customers</div>
            </label>
          </div>
        </div>
        <label class="fcheck" id="editActiveWrap">
          <input type="checkbox" name="is_active" id="editActive">
          Account active
        </label>
      </div>
      <div class="mfoot">
        <button type="button" class="btn btn-ghost" onclick="closeOv('editOverlay')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══ RESET PASSWORD MODAL ═══ -->
<div class="overlay" id="pwOverlay" onclick="if(event.target===this)closeOv('pwOverlay')">
  <div class="modal">
    <div class="mhead">
      <div><div class="mtitle">Reset Password</div><div class="msub" id="pwSub"></div></div>
      <button class="mclose" onclick="closeOv('pwOverlay')">&#x2715;</button>
    </div>
    <form method="POST" action="users.php">
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="user_id" id="pwUserId">
      <div class="mbody">
        <div style="background:var(--god);border:1px solid rgba(245,166,35,.3);border-radius:10px;padding:12px 14px;font-size:.79rem;color:var(--go);display:flex;align-items:center;gap:8px">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" style="width:15px;height:15px;flex-shrink:0"><path d="M8 1L1 13h14z"/><path d="M8 6v3M8 11v1"/></svg>
          This will immediately change the user's password. They will need to log in again.
        </div>
        <div class="fgrp">
          <div class="flbl">New Password <span class="req">*</span></div>
          <div class="pw-wrap">
            <input type="password" name="new_password" id="pwInput" class="finput" placeholder="Min 6 characters" required minlength="6" oninput="checkStrength(this,'pwStr')">
            <button type="button" class="pw-toggle" onclick="togglePw('pwInput',this)">👁</button>
          </div>
          <div class="pw-strength"><div class="pw-strength-bar" id="pwStr" style="width:0"></div></div>
          <div class="pw-hint" id="pwHint"></div>
        </div>
        <div class="fgrp">
          <div class="flbl">Confirm Password <span class="req">*</span></div>
          <input type="password" id="pwConfirm" class="finput" placeholder="Re-enter password" oninput="checkConfirm()">
          <div class="pw-hint" id="pwConfirmHint"></div>
        </div>
      </div>
      <div class="mfoot">
        <button type="button" class="btn btn-ghost" onclick="closeOv('pwOverlay')">Cancel</button>
        <button type="submit" class="btn btn-purple" id="pwSubmit">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 8a6 6 0 0112 0"/><path d="M14 8v3a1 1 0 01-1 1H3a1 1 0 01-1-1V8"/></svg>
          Reset Password
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

function openOv(id){document.getElementById(id).classList.add('open');document.body.style.overflow='hidden';}
function closeOv(id){document.getElementById(id).classList.remove('open');document.body.style.overflow='';}

// Role card selection (Add)
let selectedRole='representative';
function selectRole(r){
  selectedRole=r;
  ['admin','accountant','representative'].forEach(x=>{
    const el=document.getElementById('rc-'+x);
    if(el){el.classList.toggle('sel',x===r);}
    const inp=el?.querySelector('input');
    if(inp) inp.checked=(x===r);
  });
}

// Role card selection (Edit)
function selectEditRole(r){
  ['admin','accountant','representative'].forEach(x=>{
    const el=document.getElementById('erc-'+x);
    if(el){el.classList.toggle('sel',x===r);}
    const inp=el?.querySelector('input');
    if(inp) inp.checked=(x===r);
  });
}

// Open add
function openAdd(){
  document.getElementById('addForm').reset();
  selectRole('representative');
  document.getElementById('addStr').style.width='0';
  document.getElementById('addHint').textContent='';
  openOv('addOverlay');
  setTimeout(()=>document.querySelector('#addForm input[name="username"]')?.focus(),200);
}

// Open edit
const selfUid = <?= $uid ?>;
function openEdit(u){
  document.getElementById('editId').value      = u.id;
  document.getElementById('editSub').textContent = u.username + ' — ' + u.email;
  document.getElementById('editUsername').value= u.username;
  document.getElementById('editEmail').value   = u.email;
  document.getElementById('editActive').checked= u.is_active==1;
  selectEditRole(u.role);

  // Can't change own role or own active status
  const isSelf = (parseInt(u.id)===selfUid);
  document.getElementById('editRoleWrap').style.opacity   = isSelf?'.4':'1';
  document.getElementById('editRoleWrap').style.pointerEvents = isSelf?'none':'';
  document.getElementById('editActiveWrap').style.opacity = isSelf?'.4':'1';
  document.getElementById('editActiveWrap').style.pointerEvents = isSelf?'none':'';
  openOv('editOverlay');
}

// Open reset password
function openResetPw(id,name){
  document.getElementById('pwUserId').value     = id;
  document.getElementById('pwSub').textContent  = 'Resetting password for: '+name;
  document.getElementById('pwInput').value      = '';
  document.getElementById('pwConfirm').value    = '';
  document.getElementById('pwStr').style.width  = '0';
  document.getElementById('pwHint').textContent = '';
  document.getElementById('pwConfirmHint').textContent='';
  openOv('pwOverlay');
  setTimeout(()=>document.getElementById('pwInput')?.focus(),200);
}

// Password strength
function checkStrength(inp,barId){
  const v=inp.value;
  const bar=document.getElementById(barId);
  const hint=document.getElementById(barId.replace('Str','Hint'));
  let score=0,hints=[];
  if(v.length>=6)  score+=20;
  if(v.length>=10) score+=20;
  if(/[A-Z]/.test(v)){score+=20;}else hints.push('uppercase');
  if(/[0-9]/.test(v)){score+=20;}else hints.push('number');
  if(/[^A-Za-z0-9]/.test(v)){score+=20;}else hints.push('symbol');
  const colors=['#F87171','#F5A623','#F5A623','#34D399','#34D399'];
  bar.style.width=score+'%';
  bar.style.background=colors[Math.floor(score/20)-1]||'#F87171';
  if(hint) hint.textContent=score<100&&hints.length?'Add: '+hints.join(', '):(score===100?'Strong password ✓':'');
}

// Password visibility toggle
function togglePw(id,btn){
  const inp=document.getElementById(id);
  if(inp.type==='password'){inp.type='text';btn.textContent='🙈';}
  else{inp.type='password';btn.textContent='👁';}
}

// Confirm password match
function checkConfirm(){
  const pw=document.getElementById('pwInput').value;
  const cf=document.getElementById('pwConfirm').value;
  const hint=document.getElementById('pwConfirmHint');
  const btn=document.getElementById('pwSubmit');
  if(!cf){hint.textContent='';return;}
  if(pw===cf){hint.textContent='✓ Passwords match';hint.style.color='var(--gr)';btn.disabled=false;}
  else{hint.textContent='Passwords do not match';hint.style.color='var(--re)';btn.disabled=true;}
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
