<?php
require_once 'connection.php';
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$user  = htmlspecialchars($_SESSION['username'] ?? 'Admin');
$role  = htmlspecialchars(ucfirst($_SESSION['role'] ?? 'admin'));
$ava   = strtoupper(substr($user, 0, 1));
$uid   = (int)$_SESSION['user_id'];
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

// ── AJAX: search debts ─────────────────────────────────────────
if(isset($_GET['ajax']) && $_GET['ajax']==='debts'){
    header('Content-Type: application/json');
    $q = esc($conn, trim($_GET['q'] ?? ''));
    $w = "d.status IN ('active','partially_paid','overdue')";
    if($q) $w .= " AND (c.first_name LIKE '%$q%' OR c.last_name LIKE '%$q%'
                    OR c.business_name LIKE '%$q%' OR d.debt_number LIKE '%$q%'
                    OR c.phone LIKE '%$q%')";
    $r = $conn->query("SELECT d.id, d.debt_number, d.original_amount, d.paid_amount,
        d.remaining_amount, d.due_date, d.status, d.currency,
        TRIM(CONCAT(IFNULL(c.first_name,''),' ',IFNULL(c.last_name,''))) AS customer_name,
        IFNULL(c.business_name,'') AS biz, c.phone,
        s.sale_number
        FROM debts d
        LEFT JOIN customers c ON c.id=d.customer_id
        LEFT JOIN sales s ON s.id=d.sale_id
        WHERE $w ORDER BY d.due_date ASC, d.status ASC LIMIT 30");
    $rows=[];
    if($r) while($row=$r->fetch_assoc()) $rows[]=$row;
    echo json_encode(['ok'=>true,'debts'=>$rows]);
    exit;
}

// ── AJAX: get debt detail ──────────────────────────────────────
if(isset($_GET['ajax']) && $_GET['ajax']==='debt_detail'){
    header('Content-Type: application/json');
    $did = (int)($_GET['id'] ?? 0);
    $r = $conn->query("SELECT d.*,
        TRIM(CONCAT(IFNULL(c.first_name,''),' ',IFNULL(c.last_name,''))) AS customer_name,
        IFNULL(c.business_name,'') AS biz, c.phone AS customer_phone,
        s.sale_number, s.sale_date
        FROM debts d
        LEFT JOIN customers c ON c.id=d.customer_id
        LEFT JOIN sales s ON s.id=d.sale_id
        WHERE d.id=$did LIMIT 1");
    if(!$r||!$r->num_rows){echo json_encode(['ok'=>false]);exit;}
    $debt = $r->fetch_assoc();
    // Payment history for this debt
    $ph = $conn->query("SELECT p.*, u.username AS received_by_name
        FROM payments p LEFT JOIN users u ON u.id=p.received_by
        WHERE p.debt_id=$did ORDER BY p.payment_date DESC LIMIT 20");
    $history=[];
    if($ph) while($row=$ph->fetch_assoc()) $history[]=$row;
    echo json_encode(['ok'=>true,'debt'=>$debt,'history'=>$history]);
    exit;
}

// ── POST: record payment ────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='record_payment' && $canEdit){
    $debt_id  = (int)($_POST['debt_id'] ?? 0);
    $amount   = floatval($_POST['amount'] ?? 0);
    $pay_date = $_POST['payment_date'] ?? date('Y-m-d');
    $notes    = esc($conn, trim($_POST['notes'] ?? ''));
    $recv_by  = (int)($_POST['received_by'] ?? $uid);

    if(!$debt_id || $amount <= 0){
        $flash = "Please select a debt and enter a valid amount."; $flashType='err';
    } else {
        // Get debt info
        $dr = $conn->query("SELECT * FROM debts WHERE id=$debt_id LIMIT 1");
        $debt = $dr ? $dr->fetch_assoc() : null;

        if(!$debt){
            $flash = "Debt not found."; $flashType='err';
        } else {
            $remaining = floatval($debt['remaining_amount']);
            if($amount > $remaining + 0.01){
                $flash = "Payment amount ($".number_format($amount,2).") exceeds remaining balance ($".number_format($remaining,2).").";
                $flashType='err';
            } else {
                $pnum = genNum($conn,'PAY','payments','payment_number');
                $conn->begin_transaction();
                try {
                    // Insert payment
                    $conn->query("INSERT INTO payments(payment_number,debt_id,amount,payment_date,notes,received_by,created_by)
                        VALUES('$pnum',$debt_id,$amount,'$pay_date','$notes',$recv_by,$uid)");

                    // Update debt
                    $new_paid = floatval($debt['paid_amount']) + $amount;
                    $new_status = ($new_paid >= floatval($debt['original_amount']) - 0.01) ? 'paid' : 'partially_paid';
                    $conn->query("UPDATE debts SET paid_amount=$new_paid,
                        last_payment_date='$pay_date', status='$new_status'
                        WHERE id=$debt_id");

                    // Update sale paid_amount if linked
                    if($debt['sale_id']){
                        $sid = (int)$debt['sale_id'];
                        $conn->query("UPDATE sales SET paid_amount=paid_amount+$amount,
                            payment_status=CASE
                                WHEN paid_amount+$amount >= total_amount THEN 'paid'
                                WHEN paid_amount+$amount > 0 THEN 'partial'
                                ELSE 'unpaid' END
                            WHERE id=$sid");
                    }

                    $conn->query("INSERT INTO audit_logs(user_id,user_name,action,table_name,record_id,description)
                        VALUES($uid,'$user','CREATE','payments',$debt_id,
                        'Payment $pnum of $$amount recorded for debt #{$debt_id}')");

                    $conn->commit();
                    $flash = "Payment <strong>$pnum</strong> of <strong>$".number_format($amount,2)."</strong> recorded successfully.";
                    $flashType='ok';
                } catch(Exception $e){
                    $conn->rollback();
                    $flash = "Error: ".htmlspecialchars($e->getMessage()); $flashType='err';
                }
            }
        }
    }
    header("Location: payments.php?flash=".urlencode($flash)."&ft=$flashType"); exit;
}

// ── POST: delete payment ────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='delete_payment' && $canDelete){
    $pid = (int)($_POST['payment_id'] ?? 0);
    if($pid){
        // Get payment to reverse
        $pr = $conn->query("SELECT * FROM payments WHERE id=$pid LIMIT 1");
        $pay = $pr ? $pr->fetch_assoc() : null;
        if($pay){
            $conn->begin_transaction();
            try{
                $amt = floatval($pay['amount']);
                $did = (int)($pay['debt_id'] ?? 0);
                if($did){
                    $conn->query("UPDATE debts SET paid_amount=GREATEST(0,paid_amount-$amt),
                        status=CASE WHEN GREATEST(0,paid_amount-$amt)<=0 THEN 'active'
                                    ELSE 'partially_paid' END
                        WHERE id=$did");
                }
                $conn->query("DELETE FROM payments WHERE id=$pid");
                $conn->query("INSERT INTO audit_logs(user_id,user_name,action,table_name,record_id,description)
                    VALUES($uid,'$user','DELETE','payments',$pid,'Payment #$pid deleted, amount $".$amt." reversed')");
                $conn->commit();
                $flash="Payment deleted and debt balance restored."; $flashType='warn';
            }catch(Exception $e){
                $conn->rollback();
                $flash="Error: ".htmlspecialchars($e->getMessage()); $flashType='err';
            }
        }
    }
    header("Location: payments.php?flash=".urlencode($flash)."&ft=$flashType"); exit;
}

if(isset($_GET['flash'])){ $flash=urldecode($_GET['flash']); $flashType=$_GET['ft']??'ok'; }

// ── DATA ────────────────────────────────────────────────────────
// Summary stats
function sv($conn,$sql){$r=$conn->query($sql);return $r?($r->fetch_assoc()['v']??0):0;}
$stat_total_payments = (float)sv($conn,"SELECT COALESCE(SUM(amount),0) AS v FROM payments");
$stat_this_month     = (float)sv($conn,"SELECT COALESCE(SUM(amount),0) AS v FROM payments WHERE MONTH(payment_date)=MONTH(NOW()) AND YEAR(payment_date)=YEAR(NOW())");
$stat_today          = (float)sv($conn,"SELECT COALESCE(SUM(amount),0) AS v FROM payments WHERE DATE(payment_date)=CURDATE()");
$stat_outstanding    = (float)sv($conn,"SELECT COALESCE(SUM(remaining_amount),0) AS v FROM debts WHERE status IN('active','partially_paid','overdue')");
$stat_debts_open     = (int)sv($conn,"SELECT COUNT(*) AS v FROM debts WHERE status IN('active','partially_paid','overdue')");
$stat_overdue        = (int)sv($conn,"SELECT COUNT(*) AS v FROM debts WHERE status='overdue' OR (due_date < CURDATE() AND status IN('active','partially_paid'))");

// Recent payments
$recent_payments = $conn->query("SELECT p.*,
    TRIM(CONCAT(IFNULL(c.first_name,''),' ',IFNULL(c.last_name,''))) AS customer_name,
    IFNULL(c.business_name,'') AS biz,
    d.debt_number, d.original_amount AS debt_total, d.remaining_amount AS debt_remaining,
    u.username AS received_by_name
    FROM payments p
    LEFT JOIN debts d ON d.id=p.debt_id
    LEFT JOIN customers c ON c.id=d.customer_id
    LEFT JOIN users u ON u.id=p.received_by
    ORDER BY p.created_at DESC LIMIT 40");

// Users for "Received by" dropdown
$users_list = $conn->query("SELECT id,username,role FROM users WHERE is_active=1 ORDER BY username");

// Outstanding debts for quick list
$open_debts = $conn->query("SELECT d.id, d.debt_number, d.remaining_amount, d.due_date, d.status,
    TRIM(CONCAT(IFNULL(c.first_name,''),' ',IFNULL(c.last_name,''))) AS customer_name,
    IFNULL(c.business_name,'') AS biz
    FROM debts d
    LEFT JOIN customers c ON c.id=d.customer_id
    WHERE d.status IN('active','partially_paid','overdue')
    ORDER BY d.due_date ASC LIMIT 50");

$current_page = 'payments';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SalesOS &mdash; Payments</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* THEME */
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
/* BASE */
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
.ucard{display:flex;align-items:center;gap:10px;padding:10px;background:var(--bg3);border:1px solid var(--br);border-radius:11px;cursor:pointer;transition:background .15s}
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
.content{padding:26px 28px;display:flex;flex-direction:column;gap:22px}

/* PILLS */
.pill{display:inline-flex;align-items:center;padding:2px 9px;border-radius:100px;font-size:.65rem;font-weight:700;white-space:nowrap}
.pill.ok  {background:var(--grd);color:var(--gr)}
.pill.warn{background:var(--god);color:var(--go)}
.pill.err {background:var(--red);color:var(--re)}
.pill.info{background:var(--ag); color:var(--ac)}
.pill.pu  {background:var(--pud);color:var(--pu)}
.pill.nt  {background:var(--bg3);color:var(--t3);border:1px solid var(--br)}

/* FLASH */
.flash{display:flex;align-items:center;gap:10px;padding:11px 16px;border-radius:10px;
  font-size:.82rem;font-weight:500;animation:fadeUp .35s cubic-bezier(.16,1,.3,1)}
.flash.ok  {background:var(--grd);border:1px solid rgba(52,211,153,.2);color:var(--gr)}
.flash.warn{background:var(--god);border:1px solid rgba(245,166,35,.2);color:var(--go)}
.flash.err {background:var(--red);border:1px solid rgba(248,113,113,.2);color:var(--re)}
.flash svg{width:15px;height:15px;flex-shrink:0}
@keyframes fadeUp{from{opacity:0;transform:translateY(7px)}to{opacity:1;transform:none}}

/* STATS */
.stats-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:14px}
.stat-card{background:var(--bg2);border:1px solid var(--br);border-radius:13px;padding:18px 20px;
  transition:all .2s;animation:fadeUp .4s cubic-bezier(.16,1,.3,1) both}
.stat-card:hover{transform:translateY(-2px);box-shadow:var(--sh2)}
.stat-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.stat-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center}
.stat-icon svg{width:18px;height:18px}
.stat-badge{font-size:.63rem;font-weight:700;padding:3px 8px;border-radius:100px}
.stat-val{font-size:1.5rem;font-weight:800;color:var(--tx);letter-spacing:-.04em;line-height:1}
.stat-lbl{font-size:.71rem;color:var(--t2);margin-top:5px;font-weight:500}

/* PAGE HEADER */
.page-hdr{display:flex;align-items:flex-start;justify-content:space-between;gap:16px}
.ph-title{font-size:1.45rem;font-weight:800;color:var(--tx);letter-spacing:-.03em}
.ph-sub{font-size:.78rem;color:var(--t2);margin-top:4px}

/* MAIN GRID */
.main-grid{display:grid;grid-template-columns:1fr 380px;gap:20px;align-items:start}

/* PAYMENT FORM PANEL */
.panel{background:var(--bg2);border:1px solid var(--br);border-radius:14px;overflow:hidden}
.panel-head{padding:18px 22px;border-bottom:1px solid var(--br);display:flex;align-items:center;justify-content:space-between}
.panel-title{font-size:.92rem;font-weight:800;color:var(--tx);display:flex;align-items:center;gap:8px}
.panel-title svg{width:16px;height:16px;color:var(--ac)}
.panel-sub{font-size:.7rem;color:var(--t3);margin-top:2px}
.panel-body{padding:20px 22px;display:flex;flex-direction:column;gap:14px}

/* FORM */
.frow2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.frow3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
.fgrp{display:flex;flex-direction:column;gap:5px}
.flbl{font-size:.68rem;font-weight:700;color:var(--t2);text-transform:uppercase;letter-spacing:.04em}
.flbl .req{color:var(--re)}
.finput,.fsel,.ftextarea{width:100%;background:var(--bg3);border:1.5px solid var(--br);border-radius:9px;
  padding:9px 12px;font-family:inherit;font-size:.82rem;color:var(--tx);outline:none;transition:border-color .2s}
.finput:focus,.fsel:focus,.ftextarea:focus{border-color:var(--ac);background:var(--bg)}
.finput::placeholder,.ftextarea::placeholder{color:var(--t3)}
.ftextarea{resize:vertical;min-height:68px}
.finput.big{font-size:1.2rem;font-weight:800;padding:11px 14px;letter-spacing:-.02em}

/* Debt selector */
.debt-search-box{position:relative}
.debt-search-input{width:100%;background:var(--bg3);border:1.5px solid var(--br);border-radius:9px;
  padding:9px 12px;font-family:inherit;font-size:.82rem;color:var(--tx);outline:none;transition:border-color .2s}
.debt-search-input:focus{border-color:var(--ac)}
.debt-search-input::placeholder{color:var(--t3)}
.debt-dropdown{position:absolute;top:calc(100% + 4px);left:0;right:0;background:var(--bg2);
  border:1px solid var(--br2);border-radius:11px;box-shadow:var(--sh2);z-index:300;
  max-height:220px;overflow-y:auto;display:none}
.debt-dropdown.open{display:block}
.debt-opt{padding:11px 14px;cursor:pointer;border-bottom:1px solid var(--br);transition:background .1s}
.debt-opt:last-child{border:none}
.debt-opt:hover{background:var(--bg3)}
.debt-opt-name{font-size:.8rem;font-weight:700;color:var(--tx);margin-bottom:3px}
.debt-opt-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.debt-opt-meta span{font-size:.68rem;color:var(--t2)}
.debt-opt-meta .amt{font-weight:700;color:var(--re)}
.debt-opt-meta .num{color:var(--ac)}

/* Selected debt card */
.debt-sel-card{background:var(--bg3);border:1.5px solid var(--br);border-radius:10px;padding:14px 16px}
.debt-sel-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:10px}
.debt-sel-name{font-size:.88rem;font-weight:800;color:var(--tx)}
.debt-sel-num{font-size:.7rem;color:var(--ac);margin-top:2px;font-weight:600}
.debt-change-btn{background:none;border:none;font-size:.71rem;color:var(--ac);cursor:pointer;font-family:inherit;font-weight:600;padding:3px 8px;border-radius:6px;transition:background .15s}
.debt-change-btn:hover{background:var(--ag)}
.debt-amounts{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px}
.debt-amt-item{background:var(--bg2);border-radius:8px;padding:9px 11px;text-align:center}
.debt-amt-lbl{font-size:.6rem;color:var(--t3);text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px;font-weight:600}
.debt-amt-val{font-size:.88rem;font-weight:800}
.debt-status-row{display:flex;align-items:center;gap:8px;margin-top:10px;padding-top:10px;border-top:1px solid var(--br)}

/* Quick fill buttons */
.quick-fill{display:flex;gap:6px;flex-wrap:wrap}
.qf-btn{padding:5px 11px;background:var(--bg3);border:1px solid var(--br);border-radius:7px;
  font-family:inherit;font-size:.71rem;font-weight:700;color:var(--t2);cursor:pointer;transition:all .15s}
.qf-btn:hover{background:var(--ag);border-color:rgba(79,142,247,.3);color:var(--ac)}

/* Change preview */
.pay-preview{background:var(--bg3);border-radius:10px;padding:12px 14px;display:flex;flex-direction:column;gap:5px}
.prow{display:flex;justify-content:space-between;align-items:center;font-size:.78rem}
.prow .pl{color:var(--t2)}.prow .pv{font-weight:600;color:var(--tx)}
.prow.result{border-top:1px solid var(--br);margin-top:4px;padding-top:8px}
.prow.result .pl,.prow.result .pv{font-size:.88rem;font-weight:800}
.prow.result.paid .pv{color:var(--gr)}
.prow.result.partial .pv{color:var(--go)}

/* Submit btn */
.submit-btn{width:100%;padding:13px;background:linear-gradient(135deg,var(--ac),var(--ac2));
  border:none;border-radius:11px;color:#fff;font-family:inherit;font-size:.88rem;font-weight:800;
  cursor:pointer;transition:all .2s;box-shadow:0 4px 18px var(--ag);display:flex;align-items:center;justify-content:center;gap:8px}
.submit-btn:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 6px 24px var(--ag)}
.submit-btn:disabled{opacity:.38;cursor:not-allowed}
.submit-btn svg{width:16px;height:16px}

/* OPEN DEBTS PANEL (right side) */
.debt-list{max-height:520px;overflow-y:auto}
.debt-list::-webkit-scrollbar{width:4px}
.debt-list::-webkit-scrollbar-thumb{background:var(--br2);border-radius:10px}
.debt-row{padding:13px 18px;border-bottom:1px solid var(--br);cursor:pointer;transition:background .12s;display:flex;align-items:center;gap:12px}
.debt-row:last-child{border:none}
.debt-row:hover{background:var(--bg3)}
.debt-row.overdue{border-left:3px solid var(--re)}
.debt-row.partial{border-left:3px solid var(--go)}
.debt-row.active{border-left:3px solid var(--ac)}
.dr-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0}
.dr-info{flex:1;min-width:0}
.dr-name{font-size:.79rem;font-weight:700;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dr-meta{font-size:.68rem;color:var(--t2);margin-top:2px;display:flex;align-items:center;gap:5px}
.dr-amt{text-align:right;flex-shrink:0}
.dr-remaining{font-size:.88rem;font-weight:800;color:var(--re)}
.dr-due{font-size:.65rem;color:var(--t2);margin-top:2px}
.dr-due.overdue{color:var(--re)}

/* RECENT PAYMENTS TABLE */
.dtbl{width:100%;border-collapse:collapse}
.dtbl th{font-size:.62rem;color:var(--t3);text-transform:uppercase;letter-spacing:.1em;
  padding:9px 14px;text-align:left;border-bottom:1px solid var(--br);font-weight:700;white-space:nowrap}
.dtbl td{padding:11px 14px;font-size:.8rem;border-bottom:1px solid var(--br);vertical-align:middle}
.dtbl tr:last-child td{border-bottom:none}
.dtbl tbody tr{transition:background .1s;cursor:pointer}
.dtbl tbody tr:hover td{background:rgba(255,255,255,.025)}
[data-theme="light"] .dtbl tbody tr:hover td{background:rgba(0,0,0,.02)}

/* MODALS */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:400;display:flex;
  align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;
  transition:opacity .25s;backdrop-filter:blur(6px)}
.overlay.open{opacity:1;pointer-events:all}
.modal{background:var(--bg2);border:1px solid var(--br2);border-radius:18px;width:100%;
  max-width:600px;max-height:92vh;overflow-y:auto;box-shadow:var(--sh2);
  transform:translateY(20px) scale(.97);transition:transform .3s cubic-bezier(.16,1,.3,1)}
.overlay.open .modal{transform:none}
.mhead{display:flex;align-items:center;justify-content:space-between;padding:20px 24px;
  border-bottom:1px solid var(--br);position:sticky;top:0;background:var(--bg2);z-index:1}
.mtitle{font-size:.95rem;font-weight:800;color:var(--tx)}
.msub{font-size:.71rem;color:var(--t2);margin-top:2px}
.mclose{width:30px;height:30px;border-radius:7px;border:1px solid var(--br);background:transparent;
  color:var(--t2);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:1.1rem;transition:all .15s}
.mclose:hover{background:var(--red);color:var(--re)}
.mbody{padding:22px 24px}
.mfoot{padding:14px 24px;border-top:1px solid var(--br);display:flex;justify-content:flex-end;gap:10px;position:sticky;bottom:0;background:var(--bg2)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:9px;
  font-family:inherit;font-size:.79rem;font-weight:700;cursor:pointer;border:none;transition:all .18s}
.btn-ghost{background:var(--bg3);border:1px solid var(--br);color:var(--t2)}
.btn-ghost:hover{color:var(--tx);border-color:var(--br2)}
.btn-danger{background:var(--red);border:1px solid rgba(248,113,113,.3);color:var(--re)}
.btn-danger:hover{background:var(--reb)}
.btn svg{width:13px;height:13px}

/* MISC */
.spinner{width:28px;height:28px;border:3px solid var(--br);border-top-color:var(--ac);
  border-radius:50%;animation:spin .7s linear infinite;margin:30px auto}
@keyframes spin{to{transform:rotate(360deg)}}
.empty-state{padding:40px 20px;text-align:center;color:var(--t3)}
.empty-state svg{width:36px;height:36px;margin-bottom:10px;opacity:.3;display:block;margin:0 auto 10px}
.empty-state p{font-size:.8rem}

/* RESPONSIVE */
@media(max-width:1200px){.stats-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:1000px){.main-grid{grid-template-columns:1fr}}
@media(max-width:900px){
  .sidebar{transform:translateX(-100%)}.sidebar.open{transform:none}
  .main{margin-left:0}.mob-btn{display:block}.sbar{display:none}
}
@media(max-width:640px){.stats-grid{grid-template-columns:repeat(2,1fr)}.content{padding:16px}.frow2,.frow3{grid-template-columns:1fr}}
</style>
</head>
<body>

<?php $current_page = 'payments'; include 'sidebar.php'; ?>

<div class="main">

<?php $breadcrumbs = [['label'=>'Sales'],['label'=>'Payments']]; include 'topnav.php'; ?>

<div class="content">

<!-- Flash -->
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

<!-- Page header -->
<div class="page-hdr">
  <div>
    <div class="ph-title">Payments</div>
    <div class="ph-sub">Record payments &amp; manage outstanding debts</div>
  </div>
</div>

<!-- Stats -->
<div class="stats-grid">
  <div class="stat-card" style="--dl:.0s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--grd)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--gr)" stroke-width="1.8"><rect x="1" y="4" width="14" height="9" rx="1.5"/><path d="M1 8h14M5 4V2M11 4V2"/></svg></div>
      <span class="stat-badge" style="background:var(--grd);color:var(--gr)">Today</span>
    </div>
    <div class="stat-val"><?= fmt($stat_today) ?></div>
    <div class="stat-lbl">Collected Today</div>
  </div>
  <div class="stat-card" style="--dl:.06s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--ag)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--ac)" stroke-width="1.8"><path d="M8 1v14M1 8h14"/></svg></div>
      <span class="stat-badge" style="background:var(--ag);color:var(--ac)">Month</span>
    </div>
    <div class="stat-val"><?= fmt($stat_this_month) ?></div>
    <div class="stat-lbl">This Month</div>
  </div>
  <div class="stat-card" style="--dl:.12s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--grd)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--gr)" stroke-width="1.8"><path d="M3 10l3 3 4-5 4 4"/></svg></div>
      <span class="stat-badge" style="background:var(--grd);color:var(--gr)">All-time</span>
    </div>
    <div class="stat-val"><?= fmt($stat_total_payments) ?></div>
    <div class="stat-lbl">Total Collected</div>
  </div>
  <div class="stat-card" style="--dl:.18s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--red)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--re)" stroke-width="1.8"><circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 2"/></svg></div>
      <span class="stat-badge" style="background:var(--red);color:var(--re)"><?= $stat_debts_open ?> open</span>
    </div>
    <div class="stat-val"><?= fmt($stat_outstanding) ?></div>
    <div class="stat-lbl">Outstanding Debt</div>
  </div>
  <div class="stat-card" style="--dl:.24s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--god)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--go)" stroke-width="1.8"><path d="M8 2v12M2 8h12"/></svg></div>
      <span class="stat-badge" style="background:var(--god);color:var(--go)">Debts</span>
    </div>
    <div class="stat-val"><?= $stat_debts_open ?></div>
    <div class="stat-lbl">Open Debts</div>
  </div>
  <div class="stat-card" style="--dl:.3s">
    <div class="stat-top">
      <div class="stat-icon" style="background:var(--red)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--re)" stroke-width="1.8"><path d="M8 1L1 13h14z"/><path d="M8 6v3M8 11v1"/></svg></div>
      <span class="stat-badge" style="background:var(--red);color:var(--re)">Overdue</span>
    </div>
    <div class="stat-val"><?= $stat_overdue ?></div>
    <div class="stat-lbl">Overdue Debts</div>
  </div>
</div>

<!-- Main grid: form + open debts -->
<div class="main-grid">

  <!-- LEFT: Payment Form -->
  <div style="display:flex;flex-direction:column;gap:20px">

    <!-- Record Payment Panel -->
    <div class="panel">
      <div class="panel-head">
        <div>
          <div class="panel-title">
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="1" y="4" width="14" height="9" rx="1.5"/><path d="M1 8h14"/><circle cx="4.5" cy="12" r="1"/></svg>
            Record Payment
          </div>
          <div class="panel-sub">Apply payment to an outstanding debt</div>
        </div>
      </div>
      <div class="panel-body">

        <?php if($canEdit): ?>
        <form method="POST" action="payments.php" id="payForm">
          <input type="hidden" name="action" value="record_payment">
          <input type="hidden" name="debt_id" id="hidDebtId">

          <!-- Debt selector -->
          <div class="fgrp">
            <div class="flbl">Debt / Customer <span class="req">*</span></div>

            <!-- Search box (shown when no debt selected) -->
            <div class="debt-search-box" id="debtSearchWrap">
              <input type="text" class="debt-search-input" id="debtSearchInput"
                placeholder="Search by customer name, debt number, or phone&#x2026;"
                autocomplete="off">
              <div class="debt-dropdown" id="debtDropdown">
                <div style="padding:16px;text-align:center;color:var(--t3);font-size:.78rem">
                  Type to search outstanding debts&#x2026;
                </div>
              </div>
            </div>

            <!-- Selected debt card (shown after selecting) -->
            <div id="debtSelCard" style="display:none">
              <div class="debt-sel-card">
                <div class="debt-sel-header">
                  <div>
                    <div class="debt-sel-name" id="dscName"></div>
                    <div class="debt-sel-num" id="dscNum"></div>
                  </div>
                  <button type="button" class="debt-change-btn" onclick="clearDebt()">&#x2715; Change</button>
                </div>
                <div class="debt-amounts">
                  <div class="debt-amt-item">
                    <div class="debt-amt-lbl">Original</div>
                    <div class="debt-amt-val" id="dscOriginal" style="color:var(--t2)"></div>
                  </div>
                  <div class="debt-amt-item">
                    <div class="debt-amt-lbl">Paid</div>
                    <div class="debt-amt-val" id="dscPaid" style="color:var(--gr)"></div>
                  </div>
                  <div class="debt-amt-item">
                    <div class="debt-amt-lbl">Remaining</div>
                    <div class="debt-amt-val" id="dscRemaining" style="color:var(--re)"></div>
                  </div>
                </div>
                <div class="debt-status-row">
                  <span id="dscStatusPill"></span>
                  <span style="font-size:.7rem;color:var(--t2)" id="dscDue"></span>
                </div>
              </div>
            </div>
          </div>

          <!-- Amount -->
          <div class="fgrp">
            <div class="flbl">Payment Amount <span class="req">*</span></div>
            <input type="number" name="amount" id="payAmount" class="finput big"
              min="0.01" step="0.01" placeholder="0.00" required>
            <div class="quick-fill" id="quickFill" style="display:none">
              <span style="font-size:.68rem;color:var(--t3);font-weight:600;align-self:center">Quick fill:</span>
              <button type="button" class="qf-btn" id="qfFull">Full Balance</button>
              <button type="button" class="qf-btn" id="qfHalf">50%</button>
              <button type="button" class="qf-btn" id="qfCustom25">25%</button>
            </div>
          </div>

          <!-- Payment preview -->
          <div class="pay-preview" id="payPreview" style="display:none">
            <div class="prow"><span class="pl">Current Balance</span><span class="pv" id="prevBalance"></span></div>
            <div class="prow"><span class="pl">This Payment</span><span class="pv" style="color:var(--gr)" id="prevAmount"></span></div>
            <div class="prow result" id="prevResult">
              <span class="pl" id="prevResultLbl">Remaining</span>
              <span class="pv" id="prevResultVal"></span>
            </div>
          </div>

          <!-- Date + Received by -->
          <div class="frow2">
            <div class="fgrp">
              <div class="flbl">Payment Date</div>
              <input type="date" name="payment_date" class="finput" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="fgrp">
              <div class="flbl">Received By</div>
              <select name="received_by" class="fsel">
                <?php if($users_list) while($u=$users_list->fetch_assoc()): ?>
                <option value="<?= $u['id'] ?>" <?= $u['id']==$uid?'selected':'' ?>>
                  <?= htmlspecialchars($u['username']) ?> (<?= ucfirst($u['role']) ?>)
                </option>
                <?php endwhile; ?>
              </select>
            </div>
          </div>

          <!-- Notes -->
          <div class="fgrp">
            <div class="flbl">Notes (optional)</div>
            <textarea name="notes" class="ftextarea" placeholder="Payment method, reference number, or any additional notes&#x2026;"></textarea>
          </div>

          <button type="submit" class="submit-btn" id="submitBtn" disabled>
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="8" cy="8" r="7"/><path d="M5 8l2 2 4-4"/></svg>
            Record Payment
          </button>
        </form>

        <?php else: ?>
        <div class="empty-state">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 2h12v12H2z"/><path d="M5 8h6M5 5h3"/></svg>
          <p>You don't have permission to record payments.</p>
        </div>
        <?php endif; ?>

      </div>
    </div>

    <!-- Recent Payments Table -->
    <div class="panel">
      <div class="panel-head">
        <div>
          <div class="panel-title">
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 4h12M2 8h9M2 12h6"/></svg>
            Recent Payments
          </div>
          <div class="panel-sub">Last 40 transactions</div>
        </div>
      </div>
      <div style="overflow-x:auto">
        <?php if($recent_payments && $recent_payments->num_rows > 0): ?>
        <table class="dtbl">
          <thead>
            <tr>
              <th>Payment #</th>
              <th>Customer</th>
              <th>Debt #</th>
              <th>Amount</th>
              <th>Date</th>
              <th>Received By</th>
              <th>Notes</th>
              <?php if($canDelete): ?><th></th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
          <?php while($p=$recent_payments->fetch_assoc()):
            $cname = trim($p['customer_name']) ?: ($p['biz'] ?: '—');
          ?>
          <tr onclick="openDebtDetail(<?= $p['debt_id'] ?? 0 ?>)" style="<?= !$p['debt_id']?'cursor:default':'' ?>">
            <td><b style="color:var(--ac)"><?= htmlspecialchars($p['payment_number']) ?></b></td>
            <td>
              <div style="font-weight:600;color:var(--tx);font-size:.79rem"><?= htmlspecialchars($cname) ?></div>
              <?php if($p['biz'] && trim($p['customer_name'])): ?>
              <div style="font-size:.68rem;color:var(--t2)"><?= htmlspecialchars($p['biz']) ?></div>
              <?php endif; ?>
            </td>
            <td style="color:var(--ac);font-size:.74rem;font-family:monospace"><?= htmlspecialchars($p['debt_number']??'—') ?></td>
            <td>
              <span style="font-weight:800;color:var(--gr);font-size:.88rem">+<?= fmt($p['amount']) ?></span>
            </td>
            <td style="color:var(--t2);white-space:nowrap"><?= date('M j, Y', strtotime($p['payment_date'])) ?></td>
            <td style="color:var(--t2)"><?= htmlspecialchars($p['received_by_name']??'—') ?></td>
            <td style="color:var(--t2);max-width:180px">
              <span style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:160px" title="<?= htmlspecialchars($p['notes']??'') ?>">
                <?= htmlspecialchars($p['notes']??'—') ?>
              </span>
            </td>
            <?php if($canDelete): ?>
            <td onclick="event.stopPropagation()">
              <form method="POST" style="display:inline" onsubmit="return confirm('Delete this payment and reverse the debt balance?')">
                <input type="hidden" name="action" value="delete_payment">
                <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                <button type="submit" class="btn btn-danger" style="padding:4px 9px;font-size:.69rem">
                  <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 4h12M5 4V2h6v2M6 7v4M10 7v4M3 4l1 8h8l1-8"/></svg>
                  Delete
                </button>
              </form>
            </td>
            <?php endif; ?>
          </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1" y="4" width="14" height="9" rx="1.5"/><path d="M1 8h14"/></svg>
          <p>No payments recorded yet.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /left column -->

  <!-- RIGHT: Outstanding Debts -->
  <div class="panel" style="position:sticky;top:78px">
    <div class="panel-head">
      <div>
        <div class="panel-title">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 2"/></svg>
          Outstanding Debts
        </div>
        <div class="panel-sub"><?= $stat_debts_open ?> open &mdash; click to select</div>
      </div>
    </div>
    <div class="debt-list">
      <?php if($open_debts && $open_debts->num_rows > 0):
        while($d=$open_debts->fetch_assoc()):
          $dname = trim($d['customer_name']) ?: $d['biz'];
          $today = date('Y-m-d');
          $is_overdue = $d['due_date'] && $d['due_date'] < $today;
          $row_cls = $is_overdue ? 'overdue' : ($d['status']==='partially_paid'?'partial':'active');
          $dot_clr = $is_overdue ? 'var(--re)' : ($d['status']==='partially_paid'?'var(--go)':'var(--ac)');
          $days_due = $d['due_date'] ? ceil((strtotime($d['due_date'])-strtotime($today))/86400) : null;
      ?>
      <div class="debt-row <?= $row_cls ?>"
        onclick="selectDebtFromList(<?= $d['id'] ?>,'<?= htmlspecialchars(addslashes($dname)) ?>','<?= htmlspecialchars($d['debt_number']) ?>',<?= floatval($d['remaining_amount']) ?>,'<?= $d['status'] ?>','<?= $d['due_date']??'' ?>')">
        <div class="dr-dot" style="background:<?= $dot_clr ?>"></div>
        <div class="dr-info">
          <div class="dr-name"><?= htmlspecialchars($dname) ?></div>
          <div class="dr-meta">
            <span class="num" style="color:var(--ac);font-family:monospace;font-size:.67rem"><?= htmlspecialchars($d['debt_number']) ?></span>
            <span>&middot;</span>
            <span class="pill <?= $is_overdue?'err':($d['status']==='partially_paid'?'warn':'info') ?>" style="font-size:.6rem;padding:1px 6px"><?= $is_overdue?'Overdue':ucfirst(str_replace('_',' ',$d['status'])) ?></span>
          </div>
        </div>
        <div class="dr-amt">
          <div class="dr-remaining"><?= fmt($d['remaining_amount']) ?></div>
          <?php if($d['due_date']): ?>
          <div class="dr-due <?= $is_overdue?'overdue':'' ?>">
            <?php if($is_overdue): echo abs($days_due).' day'.( abs($days_due)!=1?'s':'').' overdue';
                  elseif($days_due===0): echo 'Due today';
                  else: echo 'Due in '.$days_due.' day'.($days_due!=1?'s':'');
            endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endwhile; ?>
      <?php else: ?>
      <div class="empty-state">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="7"/><path d="M5 8l2 2 4-4"/></svg>
        <p>No outstanding debts!</p>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /main-grid -->
</div><!-- /content -->
</div><!-- /main -->

<!-- DEBT DETAIL MODAL -->
<div class="overlay" id="detailOverlay" onclick="if(event.target===this)closeOv('detailOverlay')">
  <div class="modal">
    <div class="mhead">
      <div><div class="mtitle" id="detTitle">Debt Details</div><div class="msub" id="detSub"></div></div>
      <button class="mclose" onclick="closeOv('detailOverlay')">&#x2715;</button>
    </div>
    <div class="mbody" id="detBody"><div class="spinner"></div></div>
    <div class="mfoot">
      <button class="btn btn-ghost" onclick="closeOv('detailOverlay')">Close</button>
      <button class="btn" style="background:var(--ac);color:#fff" id="detPayBtn">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="1" y="4" width="14" height="9" rx="1.5"/><path d="M1 8h14"/></svg>
        Pay This Debt
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

// ── Debt state ────────────────────────────────────────────────
let selectedDebt = null;

function selectDebt(id, name, debtNum, original, paid, remaining, status, dueDate) {
  selectedDebt = {id, name, debtNum, original:parseFloat(original), paid:parseFloat(paid), remaining:parseFloat(remaining), status, dueDate};
  document.getElementById('hidDebtId').value = id;

  // Show card
  document.getElementById('debtSearchWrap').style.display = 'none';
  const card = document.getElementById('debtSelCard');
  card.style.display = 'block';
  document.getElementById('dscName').textContent = name;
  document.getElementById('dscNum').textContent  = debtNum + (dueDate ? '  ·  Due: ' + dueDate : '');
  document.getElementById('dscOriginal').textContent  = '$' + parseFloat(original).toFixed(2);
  document.getElementById('dscPaid').textContent      = '$' + parseFloat(paid).toFixed(2);
  document.getElementById('dscRemaining').textContent = '$' + parseFloat(remaining).toFixed(2);

  // Status pill
  const isPast = dueDate && dueDate < new Date().toISOString().slice(0,10);
  const sMap = {active:'info', partially_paid:'warn', overdue:'err', paid:'ok'};
  const sCls = isPast ? 'err' : (sMap[status]||'nt');
  const sTxt = isPast ? 'Overdue' : status.replace('_',' ').replace(/\b\w/g,c=>c.toUpperCase());
  document.getElementById('dscStatusPill').outerHTML = `<span class="pill ${sCls}" id="dscStatusPill">${sTxt}</span>`;

  // Due date display
  if(dueDate){
    const diff = Math.ceil((new Date(dueDate)-new Date()) / 86400000);
    let dueTxt = diff < 0 ? Math.abs(diff)+' days overdue' : diff===0 ? 'Due today' : 'Due in '+diff+' days';
    document.getElementById('dscDue').textContent = dueTxt;
  }

  // Quick fill buttons
  document.getElementById('quickFill').style.display = 'flex';
  document.getElementById('qfFull').textContent = 'Full Balance: $' + parseFloat(remaining).toFixed(2);
  document.getElementById('qfHalf').textContent = '50%: $' + (parseFloat(remaining)*0.5).toFixed(2);
  document.getElementById('qfCustom25').textContent = '25%: $' + (parseFloat(remaining)*0.25).toFixed(2);

  document.getElementById('qfFull').onclick  = ()=>{document.getElementById('payAmount').value=parseFloat(remaining).toFixed(2);updatePreview();};
  document.getElementById('qfHalf').onclick  = ()=>{document.getElementById('payAmount').value=(parseFloat(remaining)*0.5).toFixed(2);updatePreview();};
  document.getElementById('qfCustom25').onclick=()=>{document.getElementById('payAmount').value=(parseFloat(remaining)*0.25).toFixed(2);updatePreview();};

  document.getElementById('submitBtn').disabled = false;
  updatePreview();
}

function clearDebt(){
  selectedDebt = null;
  document.getElementById('hidDebtId').value = '';
  document.getElementById('debtSearchWrap').style.display = 'block';
  document.getElementById('debtSelCard').style.display = 'none';
  document.getElementById('quickFill').style.display = 'none';
  document.getElementById('payPreview').style.display = 'none';
  document.getElementById('payAmount').value = '';
  document.getElementById('debtSearchInput').value = '';
  document.getElementById('submitBtn').disabled = true;
}

function selectDebtFromList(id, name, debtNum, remaining, status, dueDate){
  // We need full data — fetch it
  fetch(`payments.php?ajax=debt_detail&id=${id}`)
    .then(r=>r.json())
    .then(d=>{
      if(!d.ok) return;
      const debt = d.debt;
      selectDebt(debt.id, name || debt.customer_name?.trim() || debt.biz,
        debt.debt_number, debt.original_amount, debt.paid_amount,
        debt.remaining_amount, debt.status, debt.due_date);
      // Scroll to form
      document.getElementById('payForm')?.scrollIntoView({behavior:'smooth',block:'start'});
    });
}

// ── Debt search ───────────────────────────────────────────────
const dsInput = document.getElementById('debtSearchInput');
const dsDrop  = document.getElementById('debtDropdown');
let dsTimer;

if(dsInput){
  dsInput.addEventListener('input', ()=>{
    clearTimeout(dsTimer);
    const q = dsInput.value.trim();
    if(!q){ dsDrop.innerHTML='<div style="padding:16px;text-align:center;color:var(--t3);font-size:.78rem">Type to search outstanding debts…</div>'; dsDrop.classList.add('open'); return; }
    dsTimer = setTimeout(()=>searchDebts(q), 300);
  });
  dsInput.addEventListener('focus', ()=>dsDrop.classList.add('open'));
  document.addEventListener('click', e=>{
    const wrap = document.getElementById('debtSearchWrap');
    if(wrap && !wrap.contains(e.target)) dsDrop.classList.remove('open');
  });
}

async function searchDebts(q){
  dsDrop.classList.add('open');
  dsDrop.innerHTML='<div style="padding:16px;text-align:center"><div class="spinner" style="margin:0 auto;width:20px;height:20px;border-width:2px"></div></div>';
  try{
    const r = await fetch(`payments.php?ajax=debts&q=${encodeURIComponent(q)}`);
    const d = await r.json();
    renderDebtDropdown(d.debts||[]);
  }catch{ dsDrop.innerHTML='<div style="padding:12px;color:var(--re);font-size:.78rem">Search failed.</div>'; }
}

function renderDebtDropdown(debts){
  if(!debts.length){
    dsDrop.innerHTML='<div style="padding:16px;text-align:center;color:var(--t3);font-size:.78rem">No outstanding debts found.</div>';
    return;
  }
  const today = new Date().toISOString().slice(0,10);
  dsDrop.innerHTML = debts.map(d=>{
    const name = (d.customer_name?.trim() || d.biz || 'Unknown');
    const isPast = d.due_date && d.due_date < today;
    const sCls = isPast?'err':d.status==='partially_paid'?'warn':'info';
    const sTxt = isPast?'Overdue':d.status.replace('_',' ').replace(/\b\w/g,c=>c.toUpperCase());
    return `<div class="debt-opt" onclick="selectDebt(${d.id},'${xss(name).replace(/'/g,"\\'")}','${d.debt_number}',${d.original_amount},${d.paid_amount},${d.remaining_amount},'${d.status}','${d.due_date||''}')">
      <div class="debt-opt-name">${xss(name)} ${d.biz&&d.customer_name?.trim()?'<span style="font-size:.68rem;color:var(--t2)">· '+xss(d.biz)+'</span>':''}</div>
      <div class="debt-opt-meta">
        <span class="num">${d.debt_number}</span>
        ${d.sale_number?`<span>· Sale: ${d.sale_number}</span>`:''}
        <span>·</span><span class="pill ${sCls}" style="font-size:.6rem;padding:1px 6px">${sTxt}</span>
        <span>·</span><span class="amt">$${parseFloat(d.remaining_amount).toFixed(2)} remaining</span>
        ${d.due_date?`<span>· Due: ${d.due_date}</span>`:''}
      </div>
    </div>`;
  }).join('');
}

function xss(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ── Payment preview ───────────────────────────────────────────
const payAmountEl = document.getElementById('payAmount');
if(payAmountEl) payAmountEl.addEventListener('input', updatePreview);

function updatePreview(){
  if(!selectedDebt) return;
  const amt = parseFloat(payAmountEl?.value)||0;
  if(amt <= 0){ document.getElementById('payPreview').style.display='none'; return; }
  const remaining = selectedDebt.remaining;
  const newBal = Math.max(0, remaining - amt);
  const preview = document.getElementById('payPreview');
  preview.style.display = 'flex';
  document.getElementById('prevBalance').textContent = '$'+remaining.toFixed(2);
  document.getElementById('prevAmount').textContent  = '$'+amt.toFixed(2);
  const resultRow = document.getElementById('prevResult');
  if(amt >= remaining){
    resultRow.className = 'prow result paid';
    document.getElementById('prevResultLbl').textContent = 'Fully Paid ✓';
    document.getElementById('prevResultVal').textContent = '$0.00';
  } else {
    resultRow.className = 'prow result partial';
    document.getElementById('prevResultLbl').textContent = 'Remaining';
    document.getElementById('prevResultVal').textContent = '$'+newBal.toFixed(2);
  }
}

// ── Debt detail modal ─────────────────────────────────────────
function openDebtDetail(did){
  if(!did) return;
  document.getElementById('detBody').innerHTML='<div class="spinner"></div>';
  document.getElementById('detTitle').textContent='Debt Details';
  document.getElementById('detSub').textContent='Loading…';
  openOv('detailOverlay');
  fetch(`payments.php?ajax=debt_detail&id=${did}`)
    .then(r=>r.json())
    .then(d=>{
      if(!d.ok){ document.getElementById('detBody').innerHTML='<p style="color:var(--re)">Failed to load.</p>'; return; }
      buildDebtDetail(d.debt, d.history);
      const name = d.debt.customer_name?.trim() || d.debt.biz || 'Unknown';
      document.getElementById('detTitle').textContent = name;
      document.getElementById('detSub').textContent   = d.debt.debt_number + '  ·  ' + d.debt.status.replace('_',' ');
      document.getElementById('detPayBtn').onclick = ()=>{
        closeOv('detailOverlay');
        selectDebt(d.debt.id, name, d.debt.debt_number, d.debt.original_amount,
          d.debt.paid_amount, d.debt.remaining_amount, d.debt.status, d.debt.due_date);
        document.getElementById('payForm')?.scrollIntoView({behavior:'smooth'});
      };
    });
}

function buildDebtDetail(debt, history){
  const orig = parseFloat(debt.original_amount)||0;
  const paid = parseFloat(debt.paid_amount)||0;
  const rem  = parseFloat(debt.remaining_amount)||0;
  const pct  = orig > 0 ? Math.min(100, (paid/orig*100)) : 0;

  const today = new Date().toISOString().slice(0,10);
  const isPast = debt.due_date && debt.due_date < today;
  const sCls = isPast?'err':debt.status==='partially_paid'?'warn':debt.status==='paid'?'ok':'info';
  const sTxt = isPast?'Overdue':debt.status.replace('_',' ').replace(/\b\w/g,c=>c.toUpperCase());

  let histHtml = '';
  if(history.length){
    histHtml = `<div style="margin-top:18px">
      <div style="font-size:.68rem;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.09em;margin-bottom:10px">Payment History</div>
      <div style="display:flex;flex-direction:column;gap:6px">
        ${history.map(h=>`<div style="display:flex;align-items:center;justify-content:space-between;background:var(--bg3);border-radius:9px;padding:10px 13px">
          <div>
            <div style="font-size:.78rem;font-weight:700;color:var(--tx)">${xss(h.payment_number)}</div>
            <div style="font-size:.68rem;color:var(--t2);margin-top:2px">${h.payment_date}${h.received_by_name?' · by '+xss(h.received_by_name):''}</div>
            ${h.notes?`<div style="font-size:.68rem;color:var(--t2);margin-top:2px;font-style:italic">${xss(h.notes)}</div>`:''}
          </div>
          <div style="font-size:.92rem;font-weight:800;color:var(--gr)">+$${parseFloat(h.amount).toFixed(2)}</div>
        </div>`).join('')}
      </div>
    </div>`;
  } else {
    histHtml = `<div style="padding:20px 0;text-align:center;color:var(--t3);font-size:.78rem">No payments recorded yet.</div>`;
  }

  document.getElementById('detBody').innerHTML = `
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:16px">
      <div style="background:var(--bg3);border-radius:9px;padding:12px;text-align:center">
        <div style="font-size:.6rem;color:var(--t3);text-transform:uppercase;letter-spacing:.09em;margin-bottom:6px;font-weight:700">Original</div>
        <div style="font-size:1.1rem;font-weight:800;color:var(--tx)">$${orig.toFixed(2)}</div>
      </div>
      <div style="background:var(--grb, rgba(52,211,153,.12));border-radius:9px;padding:12px;text-align:center">
        <div style="font-size:.6rem;color:var(--t3);text-transform:uppercase;letter-spacing:.09em;margin-bottom:6px;font-weight:700">Paid</div>
        <div style="font-size:1.1rem;font-weight:800;color:var(--gr)">$${paid.toFixed(2)}</div>
      </div>
      <div style="background:var(--reb,rgba(248,113,113,.12));border-radius:9px;padding:12px;text-align:center">
        <div style="font-size:.6rem;color:var(--t3);text-transform:uppercase;letter-spacing:.09em;margin-bottom:6px;font-weight:700">Remaining</div>
        <div style="font-size:1.1rem;font-weight:800;color:${rem>0?'var(--re)':'var(--gr)'}">$${rem.toFixed(2)}</div>
      </div>
    </div>
    <!-- Progress bar -->
    <div style="margin-bottom:14px">
      <div style="display:flex;justify-content:space-between;font-size:.68rem;color:var(--t2);margin-bottom:5px">
        <span>Progress</span><span>${pct.toFixed(1)}% paid</span>
      </div>
      <div style="height:8px;background:var(--br);border-radius:4px;overflow:hidden">
        <div style="height:100%;width:${pct}%;background:${pct>=100?'var(--gr)':pct>50?'var(--go)':'var(--re)'};border-radius:4px;transition:width .8s cubic-bezier(.16,1,.3,1)"></div>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;flex-wrap:wrap">
      <span class="pill ${sCls}">${sTxt}</span>
      ${debt.due_date?`<span style="font-size:.71rem;color:${isPast?'var(--re)':'var(--t2)'};font-weight:${isPast?700:400}">Due: ${debt.due_date}</span>`:''}
      ${debt.sale_number?`<span style="font-size:.71rem;color:var(--t2)">Sale: <span style="color:var(--ac)">${xss(debt.sale_number)}</span></span>`:''}
    </div>
    ${debt.notes?`<div style="padding:10px 13px;background:var(--bg3);border-radius:8px;font-size:.76rem;color:var(--t2);margin-bottom:8px"><strong style="color:var(--tx)">Notes:</strong> ${xss(debt.notes)}</div>`:''}
    ${histHtml}`;
}

// ── Overlay helpers ───────────────────────────────────────────
function openOv(id){ document.getElementById(id).classList.add('open'); document.body.style.overflow='hidden'; }
function closeOv(id){ document.getElementById(id).classList.remove('open'); document.body.style.overflow=''; }

// Flash dismiss
setTimeout(()=>{
  const f=document.getElementById('flashMsg');
  if(f){f.style.transition='opacity .5s';f.style.opacity='0';setTimeout(()=>f?.remove(),520);}
},5000);
</script>
</body>
</html>
