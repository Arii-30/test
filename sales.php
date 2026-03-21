<?php
require_once 'connection.php';
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$user  = htmlspecialchars($_SESSION['username'] ?? 'Admin');
$role  = htmlspecialchars(ucfirst($_SESSION['role'] ?? 'admin'));
$ava   = strtoupper(substr($user, 0, 1));
$uid   = (int)$_SESSION['user_id'];
$canDelete = in_array($_SESSION['role'], ['admin','accountant']);
$isRep     = ($_SESSION['role'] === 'representative');

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

// AJAX: barcode
if(isset($_GET['ajax'])&&$_GET['ajax']==='barcode'){
    header('Content-Type: application/json');
    $bc=esc($conn,trim($_GET['q']??''));
    $r=$conn->query("SELECT p.id,p.name,p.selling_price,p.unit_of_measure,p.image_path,
        COALESCE(i.quantity_available,0) AS stock
        FROM products p LEFT JOIN inventory i ON i.product_id=p.id
        WHERE (p.barcode='$bc' OR p.id='$bc') AND p.is_active=1 LIMIT 1");
    echo ($r&&$r->num_rows)?json_encode(['ok'=>true,'product'=>$r->fetch_assoc()]):json_encode(['ok'=>false]);
    exit;
}
// AJAX: search
if(isset($_GET['ajax'])&&$_GET['ajax']==='search'){
    header('Content-Type: application/json');
    $q=esc($conn,trim($_GET['q']??''));
    $cat=(int)($_GET['cat']??0);
    $w="p.is_active=1";
    if($q)   $w.=" AND (p.name LIKE '%$q%' OR p.barcode LIKE '%$q%' OR p.brand LIKE '%$q%')";
    if($cat) $w.=" AND p.category_id=$cat";
    $r=$conn->query("SELECT p.id,p.name,p.selling_price,p.unit_of_measure,p.image_path,
        p.barcode,c.name AS category,COALESCE(i.quantity_available,0) AS stock
        FROM products p LEFT JOIN categories c ON c.id=p.category_id
        LEFT JOIN inventory i ON i.product_id=p.id
        WHERE $w ORDER BY p.is_featured DESC,p.name ASC LIMIT 48");
    $rows=[];
    if($r) while($row=$r->fetch_assoc()) $rows[]=$row;
    echo json_encode(['ok'=>true,'products'=>$rows]);
    exit;
}
// AJAX: invoice
if(isset($_GET['ajax'])&&$_GET['ajax']==='invoice'){
    header('Content-Type: application/json');
    $sid=(int)($_GET['id']??0);
    $sr=$conn->query("SELECT s.*,
        TRIM(CONCAT(IFNULL(c.first_name,''),' ',IFNULL(c.last_name,''))) AS customer_name,
        c.phone AS customer_phone,c.address AS customer_address,
        TRIM(CONCAT(IFNULL(e.first_name,''),' ',IFNULL(e.last_name,''))) AS rep_name,
        u.username AS created_by_name
        FROM sales s LEFT JOIN customers c ON c.id=s.customer_id
        LEFT JOIN employees e ON e.id=s.representative_id
        LEFT JOIN users u ON u.id=s.created_by WHERE s.id=$sid LIMIT 1");
    if(!$sr||!$sr->num_rows){echo json_encode(['ok'=>false]);exit;}
    $sale=$sr->fetch_assoc();
    $ir=$conn->query("SELECT si.*,p.name AS product_name,p.unit_of_measure
        FROM sale_items si JOIN products p ON p.id=si.product_id WHERE si.sale_id=$sid");
    $sitems=[];
    if($ir) while($row=$ir->fetch_assoc()) $sitems[]=$row;
    echo json_encode(['ok'=>true,'sale'=>$sale,'items'=>$sitems]);
    exit;
}
// POST: submit sale
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='submit_sale'){
    $cid   =(int)($_POST['customer_id']??0)?:null;
    $sdate = $_POST['sale_date']??date('Y-m-d');
    $otype = $isRep?'representative':($_POST['order_type']??'direct');
    $stype = $_POST['sale_type']??'cash';
    $dpct  = floatval($_POST['discount_percent']??0);
    $paid  = floatval($_POST['paid_amount']??0);
    $notes = esc($conn,trim($_POST['notes']??''));
    $items = json_decode($_POST['cart_items']??'[]',true)?:[];
    $apprv = $isRep?'pending':'approved';
    if(empty($items)){$flash="Cart is empty.";$flashType='err';}
    else{
        $sub=0; foreach($items as $it) $sub+=floatval($it['price'])*intval($it['qty']);
        $da=round($sub*$dpct/100,2); $tot=round($sub-$da,2);
        $ps='unpaid';
        if($paid>=$tot) $ps='paid'; elseif($paid>0) $ps='partial';
        $os=$apprv==='approved'?'confirmed':'pending';
        $sn=genNum($conn,'SL','sales','sale_number');
        $in=genNum($conn,'INV','sales','invoice_number');
        $c=$cid??'NULL';
        if($conn->query("INSERT INTO sales(sale_number,invoice_number,customer_id,sale_date,
            subtotal,discount_percent,discount_amount,total_amount,paid_amount,
            payment_status,order_status,order_type,sale_type,approval_status,notes,created_by)
            VALUES('$sn','$in',$c,'$sdate',$sub,$dpct,$da,$tot,$paid,
            '$ps','$os','$otype','$stype','$apprv','$notes',$uid)")){
            $sid=$conn->insert_id;
            foreach($items as $it){
                $p=(int)$it['id'];$q=(int)$it['qty'];$u=floatval($it['price']);
                $conn->query("INSERT INTO sale_items(sale_id,product_id,quantity,unit_price)VALUES($sid,$p,$q,$u)");
            }
            if($paid<$tot&&$cid&&$stype==='credit'){
                $dn=genNum($conn,'DBT','debts','debt_number');
                $ds=$paid>0?'partially_paid':'active';
                $dd=date('Y-m-d',strtotime('+30 days'));
                $conn->query("INSERT INTO debts(debt_number,customer_id,sale_id,original_amount,paid_amount,due_date,status,created_by)
                    VALUES('$dn',$cid,$sid,$tot,$paid,'$dd','$ds',$uid)");
            }
            $conn->query("INSERT INTO audit_logs(user_id,user_name,action,table_name,record_id,description)
                VALUES($uid,'$user','CREATE','sales',$sid,'Sale $sn created')");
            header("Location: sales.php?flash=".urlencode("Sale <strong>$sn</strong> created.")."&ft=ok&new_sale=$sid");exit;
        } else {$flash="Error: ".htmlspecialchars($conn->error);$flashType='err';}
    }
}
// POST: cancel
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='delete_sale'&&$canDelete){
    $sid=(int)($_POST['sale_id']??0);
    if($sid){
        $conn->query("UPDATE sales SET order_status='cancelled' WHERE id=$sid");
        $conn->query("INSERT INTO audit_logs(user_id,user_name,action,table_name,record_id,description)
            VALUES($uid,'$user','DELETE','sales',$sid,'Sale #$sid cancelled')");
    }
    header("Location: sales.php?flash=".urlencode("Sale cancelled.")."&ft=warn");exit;
}
if(isset($_GET['flash'])){$flash=urldecode($_GET['flash']);$flashType=$_GET['ft']??'ok';}
$new_sale_id=(int)($_GET['new_sale']??0);

// Data
$categories=$conn->query("SELECT id,name FROM categories WHERE is_active=1 ORDER BY name");
$cr=$conn->query("SELECT id,TRIM(CONCAT(first_name,' ',IFNULL(last_name,''))) AS full_name,
    IFNULL(business_name,'') AS biz,phone,current_balance,credit_limit
    FROM customers WHERE status='active' ORDER BY first_name");
$custs=[];
if($cr) while($c=$cr->fetch_assoc()) $custs[]=$c;

$recent=$conn->query("SELECT s.id,s.sale_number,s.sale_date,s.total_amount,
    s.paid_amount,s.payment_status,s.order_status,s.approval_status,
    TRIM(CONCAT(IFNULL(c.first_name,''),' ',IFNULL(c.last_name,''))) AS customer_name,
    COUNT(si.id) AS item_count
    FROM sales s LEFT JOIN customers c ON c.id=s.customer_id
    LEFT JOIN sale_items si ON si.sale_id=s.id
    GROUP BY s.id ORDER BY s.created_at DESC LIMIT 30");

$current_page='sales';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SalesOS — Sales Orders</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* THEME */
[data-theme="dark"]{
  --bg:#0D0F14;--bg2:#13161D;--bg3:#191D27;--bg4:#1F2433;
  --br:rgba(255,255,255,.06);--br2:rgba(255,255,255,.11);
  --tx:#EDF0F7;--t2:#7B82A0;--t3:#3E4460;
  --ac:#4F8EF7;--ac2:#6BA3FF;--ag:rgba(79,142,247,.15);
  --go:#F5A623;--god:rgba(245,166,35,.12);
  --gr:#34D399;--grd:rgba(52,211,153,.12);
  --re:#F87171;--red:rgba(248,113,113,.12);
  --pu:#A78BFA;--pud:rgba(167,139,250,.12);
  --te:#22D3EE;--ted:rgba(34,211,238,.12);
  --sh:0 2px 16px rgba(0,0,0,.35);--sh2:0 8px 32px rgba(0,0,0,.45);
}
[data-theme="light"]{
  --bg:#F0EEE9;--bg2:#FFFFFF;--bg3:#F7F5F1;--bg4:#EDE9E2;
  --br:rgba(0,0,0,.07);--br2:rgba(0,0,0,.13);
  --tx:#1A1C27;--t2:#6B7280;--t3:#C0C5D4;
  --ac:#3B7DD8;--ac2:#2563EB;--ag:rgba(59,125,216,.12);
  --go:#D97706;--god:rgba(217,119,6,.1);
  --gr:#059669;--grd:rgba(5,150,105,.1);
  --re:#DC2626;--red:rgba(220,38,38,.1);
  --pu:#7C3AED;--pud:rgba(124,58,237,.1);
  --te:#0891B2;--ted:rgba(8,145,178,.1);
  --sh:0 2px 12px rgba(0,0,0,.07);--sh2:0 8px 28px rgba(0,0,0,.1);
}
/* BASE */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{background:var(--bg);color:var(--tx);font-family:'Plus Jakarta Sans',sans-serif;
  font-size:.875rem;min-height:100vh;display:flex;transition:background .4s,color .4s}
/* SIDEBAR CSS */
.sidebar{width:248px;min-height:100vh;background:var(--bg2);border-right:1px solid var(--br);
  display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;transition:transform .3s ease}
.slogo{display:flex;align-items:center;gap:11px;padding:22px 20px;border-bottom:1px solid var(--br)}
.logo-icon{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,var(--ac),var(--ac2));
  display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px var(--ag);flex-shrink:0}
.logo-icon svg{width:18px;height:18px}
.logo-txt{font-size:1.1rem;font-weight:800;color:var(--tx);letter-spacing:-.02em}
.logo-txt span{color:var(--ac)}
.nav-sec{padding:14px 12px 4px}
.nav-lbl{font-size:.62rem;color:var(--t3);letter-spacing:.12em;text-transform:uppercase;
  padding:0 8px 8px;display:block;font-weight:600}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:9px;
  color:var(--t2);text-decoration:none;transition:all .15s;margin-bottom:1px;
  position:relative;font-size:.82rem;font-weight:500}
.nav-item:hover{background:rgba(255,255,255,.05);color:var(--tx)}
[data-theme="light"] .nav-item:hover{background:rgba(0,0,0,.04)}
.nav-item.active{background:var(--ag);color:var(--ac);font-weight:600}
.nav-item.active::before{content:'';position:absolute;left:0;top:20%;bottom:20%;
  width:3px;background:var(--ac);border-radius:0 3px 3px 0}
.nav-item svg{width:16px;height:16px;flex-shrink:0}
.nbadge{margin-left:auto;background:var(--re);color:#fff;font-size:.6rem;font-weight:700;
  padding:2px 7px;border-radius:100px;line-height:1.4}
.nbadge.g{background:var(--gr)}.nbadge.b{background:var(--ac)}
.sfooter{margin-top:auto;padding:14px 12px;border-top:1px solid var(--br)}
.ucard{display:flex;align-items:center;gap:10px;padding:10px;background:var(--bg3);
  border:1px solid var(--br);border-radius:11px;cursor:pointer;transition:background .15s}
.ucard:hover{background:var(--bg4)}
.ava{width:34px;height:34px;border-radius:9px;flex-shrink:0;
  background:linear-gradient(135deg,var(--ac),var(--ac2));display:flex;
  align-items:center;justify-content:center;font-size:.9rem;font-weight:800;color:#fff}
.uinfo{flex:1;min-width:0}
.uname{font-size:.8rem;font-weight:700;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.urole{font-size:.68rem;color:var(--ac);font-weight:500;margin-top:1px}
/* TOPNAV CSS */
.topnav{height:58px;background:var(--bg2);border-bottom:1px solid var(--br);position:sticky;
  top:0;z-index:50;display:flex;align-items:center;padding:0 28px;gap:14px;transition:background .4s}
.mob-btn{display:none;background:none;border:none;cursor:pointer;color:var(--t2);padding:4px}
.mob-btn svg{width:20px;height:20px}
.bc{display:flex;align-items:center;gap:7px;font-size:.75rem;color:var(--t3)}
.bc .sep{opacity:.4}.bc .cur{color:var(--tx);font-weight:600}
.bc a{color:var(--t2);text-decoration:none}.bc a:hover{color:var(--tx)}
.tnr{margin-left:auto;display:flex;align-items:center;gap:8px}
.sbar{display:flex;align-items:center;gap:8px;background:var(--bg3);border:1px solid var(--br);
  border-radius:9px;padding:7px 14px;transition:border-color .2s}
.sbar:focus-within{border-color:var(--ac)}
.sbar svg{width:14px;height:14px;color:var(--t3);flex-shrink:0}
.sbar input{background:none;border:none;outline:none;font-family:inherit;font-size:.78rem;color:var(--tx);width:180px}
.sbar input::placeholder{color:var(--t3)}
.ibtn{width:36px;height:36px;border-radius:9px;background:var(--bg3);border:1px solid var(--br);
  display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .2s;
  position:relative;color:var(--t2);text-decoration:none}
.ibtn:hover{background:var(--ag);border-color:rgba(79,142,247,.3);color:var(--ac)}
.ibtn svg{width:16px;height:16px}
.ibtn .dot{position:absolute;top:-4px;right:-4px;width:16px;height:16px;background:var(--re);
  border-radius:50%;font-size:.56rem;color:#fff;display:flex;align-items:center;
  justify-content:center;border:2px solid var(--bg2);font-weight:700}
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
/* POS */
.pos-wrap{display:flex;flex:1;overflow:hidden;height:calc(100vh - 58px)}
/* PRODUCT PANEL */
.prod-panel{flex:1;display:flex;flex-direction:column;overflow:hidden;border-right:1px solid var(--br)}
.prod-toolbar{padding:14px 18px;border-bottom:1px solid var(--br);background:var(--bg2);display:flex;flex-direction:column;gap:10px}
.scan-row{display:flex;align-items:center;gap:10px}
.scan-lbl{display:flex;align-items:center;gap:6px;font-size:.72rem;font-weight:700;color:var(--t2);white-space:nowrap;flex-shrink:0}
.scan-lbl svg{width:15px;height:15px;color:var(--ac)}
.scan-ping{width:7px;height:7px;border-radius:50%;background:var(--gr);animation:ping 1.8s ease-in-out infinite}
@keyframes ping{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.3;transform:scale(1.5)}}
.barcode-inp{flex:1;background:var(--bg3);border:2px solid var(--br);border-radius:10px;
  padding:9px 14px;font-family:inherit;font-size:.84rem;color:var(--tx);outline:none;transition:border-color .2s}
.barcode-inp:focus{border-color:var(--ac)}
.barcode-inp::placeholder{color:var(--t3)}
.search-row{display:flex;gap:8px}
.searchbox{flex:1;display:flex;align-items:center;gap:7px;background:var(--bg3);border:1px solid var(--br);
  border-radius:9px;padding:8px 12px;transition:border-color .2s}
.searchbox:focus-within{border-color:var(--ac)}
.searchbox svg{width:13px;height:13px;color:var(--t3);flex-shrink:0}
.searchbox input{background:none;border:none;outline:none;font-family:inherit;font-size:.8rem;color:var(--tx);width:100%}
.searchbox input::placeholder{color:var(--t3)}
.cat-sel{background:var(--bg3);border:1px solid var(--br);border-radius:9px;padding:8px 12px;
  font-family:inherit;font-size:.78rem;color:var(--tx);outline:none;cursor:pointer}
.cat-sel:focus{border-color:var(--ac)}
.prod-grid{flex:1;overflow-y:auto;padding:14px;
  display:grid;grid-template-columns:repeat(auto-fill,minmax(148px,1fr));gap:12px;align-content:start}
.prod-grid::-webkit-scrollbar{width:5px}
.prod-grid::-webkit-scrollbar-thumb{background:var(--br2);border-radius:10px}
.prod-card{background:var(--bg2);border:1px solid var(--br);border-radius:12px;cursor:pointer;
  transition:all .18s;overflow:hidden;display:flex;flex-direction:column;position:relative;user-select:none}
.prod-card:hover{border-color:var(--ac);transform:translateY(-2px);box-shadow:var(--sh)}
.prod-card:active{transform:translateY(0)}
.prod-card.out{opacity:.45;cursor:not-allowed;pointer-events:none}
.prod-card.adding{animation:pop .22s cubic-bezier(.16,1,.3,1)}
@keyframes pop{0%{transform:scale(.95)}60%{transform:scale(1.05)}100%{transform:scale(1)}}
.prod-img-wrap{width:100%;aspect-ratio:1;background:var(--bg3);display:flex;align-items:center;justify-content:center;overflow:hidden;font-size:2.2rem}
.prod-img-wrap img{width:100%;height:100%;object-fit:cover}
.prod-body{padding:9px 10px 11px}
.prod-name{font-size:.75rem;font-weight:700;color:var(--tx);line-height:1.3;margin-bottom:5px;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:2.2em}
.prod-price{font-size:.95rem;font-weight:800;color:var(--ac)}
.prod-stock{font-size:.64rem;color:var(--t2);margin-top:3px}
.prod-stock.low{color:var(--go)}.prod-stock.zero{color:var(--re)}
.out-tag{position:absolute;top:8px;right:8px;background:var(--re);color:#fff;
  font-size:.57rem;font-weight:800;padding:2px 6px;border-radius:5px}
.grid-empty{grid-column:1/-1;text-align:center;padding:56px 20px;color:var(--t3)}
.grid-empty svg{width:40px;height:40px;margin-bottom:12px;opacity:.3;display:block;margin:0 auto 12px}
.grid-empty p{font-size:.82rem}
/* CART */
.cart-panel{width:360px;flex-shrink:0;display:flex;flex-direction:column;background:var(--bg2)}
.cart-head{padding:15px 18px;border-bottom:1px solid var(--br);display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.cart-title{font-size:.9rem;font-weight:800;color:var(--tx);display:flex;align-items:center;gap:7px}
.cart-title svg{width:15px;height:15px;color:var(--ac)}
.cart-count{font-size:.7rem;color:var(--t2);font-weight:500}
.cart-clear-btn{font-size:.71rem;color:var(--re);background:none;border:none;cursor:pointer;
  font-family:inherit;font-weight:600;padding:4px 8px;border-radius:6px;transition:background .15s}
.cart-clear-btn:hover{background:var(--red)}
.cart-list{flex:1;overflow-y:auto;padding:10px 12px;display:flex;flex-direction:column;gap:7px}
.cart-list::-webkit-scrollbar{width:4px}
.cart-list::-webkit-scrollbar-thumb{background:var(--br2);border-radius:10px}
.cart-empty{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;
  color:var(--t3);padding:40px 20px;text-align:center;gap:14px}
.cart-empty svg{width:48px;height:48px;opacity:.25}
.cart-empty p{font-size:.79rem;line-height:1.6}
.ci{background:var(--bg3);border:1px solid var(--br);border-radius:10px;padding:9px 11px;
  display:flex;align-items:center;gap:9px;animation:slideIn .2s cubic-bezier(.16,1,.3,1)}
@keyframes slideIn{from{opacity:0;transform:translateX(10px)}to{opacity:1;transform:none}}
.ci-thumb{width:36px;height:36px;border-radius:7px;background:var(--bg4);overflow:hidden;
  flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1rem}
.ci-thumb img{width:100%;height:100%;object-fit:cover}
.ci-info{flex:1;min-width:0}
.ci-name{font-size:.74rem;font-weight:600;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ci-uprice{font-size:.67rem;color:var(--t2);margin-top:2px}
.ci-qty{display:flex;align-items:center;gap:5px;flex-shrink:0}
.qty-btn{width:23px;height:23px;border-radius:6px;border:1px solid var(--br);background:var(--bg4);
  color:var(--t2);cursor:pointer;display:flex;align-items:center;justify-content:center;
  font-size:.88rem;transition:all .15s;font-family:inherit;line-height:1}
.qty-btn:hover{background:var(--ag);border-color:rgba(79,142,247,.3);color:var(--ac)}
.qty-num{font-size:.8rem;font-weight:700;color:var(--tx);min-width:22px;text-align:center}
.ci-total{font-size:.8rem;font-weight:700;color:var(--ac);min-width:50px;text-align:right;flex-shrink:0}
.ci-del{width:20px;height:20px;border-radius:5px;border:none;background:transparent;
  color:var(--t3);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s;flex-shrink:0}
.ci-del:hover{background:var(--red);color:var(--re)}
.ci-del svg{width:11px;height:11px}
/* CHECKOUT */
.checkout{flex-shrink:0;border-top:1px solid var(--br);padding:13px 15px;
  display:flex;flex-direction:column;gap:10px;background:var(--bg2)}
.co-row2{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.fgrp{display:flex;flex-direction:column;gap:4px}
.flbl{font-size:.66rem;font-weight:700;color:var(--t2);text-transform:uppercase;letter-spacing:.04em}
.finput,.fsel{width:100%;background:var(--bg3);border:1px solid var(--br);border-radius:8px;
  padding:8px 10px;font-family:inherit;font-size:.8rem;color:var(--tx);outline:none;transition:border-color .2s}
.finput:focus,.fsel:focus{border-color:var(--ac)}
.finput::placeholder{color:var(--t3)}
.ptt-wrap{display:flex;gap:3px;background:var(--bg3);border-radius:9px;padding:3px}
.ptt{flex:1;padding:6px;text-align:center;border-radius:6px;font-size:.72rem;font-weight:700;
  cursor:pointer;border:none;font-family:inherit;color:var(--t2);background:transparent;transition:all .15s}
.ptt.on{background:var(--bg2);color:var(--tx);box-shadow:var(--sh)}
.totals-box{background:var(--bg3);border-radius:10px;padding:11px 13px;display:flex;flex-direction:column;gap:4px}
.trow{display:flex;justify-content:space-between;align-items:center;font-size:.77rem}
.trow .lbl{color:var(--t2)}.trow .val{font-weight:600;color:var(--tx)}
.trow.grand{padding-top:7px;border-top:1px solid var(--br);margin-top:3px}
.trow.grand .lbl,.trow.grand .val{font-size:.92rem;font-weight:800}
.trow.grand .val{color:var(--ac)}
.trow.change .val{color:var(--gr);font-weight:700}
.trow.debt   .val{color:var(--re);font-weight:700}
.cust-wrap{position:relative}
.cust-drop{position:absolute;top:calc(100% + 4px);left:0;right:0;background:var(--bg2);
  border:1px solid var(--br2);border-radius:10px;box-shadow:var(--sh2);z-index:200;
  max-height:170px;overflow-y:auto;display:none}
.cust-drop.open{display:block}
.cust-item{padding:9px 13px;cursor:pointer;border-bottom:1px solid var(--br);font-size:.78rem;transition:background .1s}
.cust-item:last-child{border:none}
.cust-item:hover{background:var(--bg3)}
.cust-item strong{display:block;color:var(--tx);font-size:.79rem;font-weight:600}
.cust-item span{color:var(--t2);font-size:.7rem}
.cust-sel-card{background:var(--ag);border:1px solid rgba(79,142,247,.25);border-radius:8px;
  padding:7px 11px;display:flex;align-items:center;justify-content:space-between}
.cust-sel-name{font-size:.79rem;font-weight:700;color:var(--ac)}
.cust-sel-info{font-size:.67rem;color:var(--t2);margin-top:2px}
.cust-clear-btn{background:none;border:none;color:var(--t3);cursor:pointer;font-size:.95rem;transition:color .15s}
.cust-clear-btn:hover{color:var(--re)}
.submit-btn{width:100%;padding:13px;background:linear-gradient(135deg,var(--ac),var(--ac2));
  border:none;border-radius:11px;color:#fff;font-family:inherit;font-size:.88rem;font-weight:800;
  cursor:pointer;transition:all .2s;box-shadow:0 4px 18px var(--ag);display:flex;
  align-items:center;justify-content:center;gap:8px}
.submit-btn:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 6px 24px var(--ag)}
.submit-btn:disabled{opacity:.38;cursor:not-allowed}
.submit-btn svg{width:16px;height:16px}
/* TABS */
.view-tabs{display:flex;border-bottom:1px solid var(--br);background:var(--bg2);padding:0 20px;flex-shrink:0}
.vtab{padding:12px 16px;font-size:.8rem;font-weight:600;color:var(--t2);cursor:pointer;
  border-bottom:2px solid transparent;transition:all .15s;white-space:nowrap;user-select:none}
.vtab:hover{color:var(--tx)}.vtab.on{color:var(--ac);border-bottom-color:var(--ac)}
/* RECENT SALES */
.list-wrap{padding:16px 20px;overflow-y:auto}
.stbl{width:100%;border-collapse:collapse}
.stbl th{font-size:.62rem;color:var(--t3);text-transform:uppercase;letter-spacing:.1em;
  padding:8px 12px;text-align:left;border-bottom:1px solid var(--br);font-weight:700;white-space:nowrap}
.stbl td{padding:11px 12px;font-size:.79rem;border-bottom:1px solid var(--br);vertical-align:middle}
.stbl tr:last-child td{border-bottom:none}
.stbl tbody tr{cursor:pointer;transition:background .1s}
.stbl tbody tr:hover td{background:rgba(255,255,255,.025)}
[data-theme="light"] .stbl tbody tr:hover td{background:rgba(0,0,0,.025)}
/* PILLS */
.pill{display:inline-flex;align-items:center;padding:2px 8px;border-radius:100px;font-size:.64rem;font-weight:700;white-space:nowrap}
.pill.ok  {background:var(--grd);color:var(--gr)}
.pill.warn{background:var(--god);color:var(--go)}
.pill.err {background:var(--red);color:var(--re)}
.pill.info{background:var(--ag); color:var(--ac)}
.pill.pu  {background:var(--pud);color:var(--pu)}
.pill.nt  {background:var(--bg3);color:var(--t3);border:1px solid var(--br)}
/* FLASH */
.flash{display:flex;align-items:center;gap:10px;padding:11px 18px;font-size:.82rem;font-weight:500;
  border-radius:10px;margin:12px 20px 0;animation:fadeUp .35s cubic-bezier(.16,1,.3,1)}
.flash.ok  {background:var(--grd);border:1px solid rgba(52,211,153,.2);color:var(--gr)}
.flash.warn{background:var(--god);border:1px solid rgba(245,166,35,.2);color:var(--go)}
.flash.err {background:var(--red);border:1px solid rgba(248,113,113,.2);color:var(--re)}
.flash svg{width:15px;height:15px;flex-shrink:0}
.flash-actions{margin-left:auto;display:flex;gap:6px}
@keyframes fadeUp{from{opacity:0;transform:translateY(7px)}to{opacity:1;transform:none}}
/* MODALS */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:400;display:flex;
  align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;
  transition:opacity .25s;backdrop-filter:blur(6px)}
.overlay.open{opacity:1;pointer-events:all}
.modal{background:var(--bg2);border:1px solid var(--br2);border-radius:18px;width:100%;
  max-width:680px;max-height:92vh;overflow-y:auto;box-shadow:var(--sh2);
  transform:translateY(20px) scale(.97);transition:transform .3s cubic-bezier(.16,1,.3,1)}
.overlay.open .modal{transform:none}
.mhead{display:flex;align-items:center;justify-content:space-between;padding:20px 24px;
  border-bottom:1px solid var(--br);position:sticky;top:0;background:var(--bg2);z-index:1}
.mtitle{font-size:.96rem;font-weight:800;color:var(--tx)}
.msub{font-size:.72rem;color:var(--t2);margin-top:2px}
.mclose{width:30px;height:30px;border-radius:7px;border:1px solid var(--br);background:transparent;
  color:var(--t2);cursor:pointer;display:flex;align-items:center;justify-content:center;
  font-size:1.1rem;transition:all .15s}
.mclose:hover{background:var(--red);color:var(--re)}
.mbody{padding:22px 24px}
.mfoot{padding:14px 24px;border-top:1px solid var(--br);display:flex;align-items:center;
  justify-content:flex-end;gap:10px;position:sticky;bottom:0;background:var(--bg2)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:9px;
  font-family:inherit;font-size:.79rem;font-weight:700;cursor:pointer;border:none;transition:all .18s}
.btn-primary{background:var(--ac);color:#fff}.btn-primary:hover{background:var(--ac2)}
.btn-ghost{background:var(--bg3);border:1px solid var(--br);color:var(--t2)}
.btn-ghost:hover{color:var(--tx);border-color:var(--br2)}
.btn svg{width:13px;height:13px}
/* INVOICE */
.inv-header{display:flex;justify-content:space-between;align-items:flex-start;
  margin-bottom:24px;padding-bottom:18px;border-bottom:2px solid var(--br)}
.inv-brand{display:flex;align-items:center;gap:11px}
.inv-logo{width:38px;height:38px;border-radius:9px;
  background:linear-gradient(135deg,var(--ac),var(--ac2));display:flex;align-items:center;justify-content:center}
.inv-logo svg{width:19px;height:19px}
.inv-bname{font-size:1.25rem;font-weight:800;color:var(--tx)}
.inv-bname span{color:var(--ac)}
.inv-bsub{font-size:.7rem;color:var(--t2);margin-top:2px}
.inv-meta{text-align:right}
.inv-num{font-size:.9rem;font-weight:800;color:var(--ac)}
.inv-date{font-size:.72rem;color:var(--t2);margin-top:3px}
.inv-parties{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px}
.inv-party h4{font-size:.63rem;font-weight:700;color:var(--t3);text-transform:uppercase;
  letter-spacing:.1em;margin-bottom:7px}
.inv-party p{font-size:.8rem;font-weight:600;color:var(--tx);margin-bottom:2px}
.inv-party span{font-size:.73rem;color:var(--t2)}
.inv-tbl{width:100%;border-collapse:collapse;margin-bottom:18px}
.inv-tbl th{font-size:.63rem;color:var(--t3);text-transform:uppercase;letter-spacing:.09em;
  padding:8px 10px;border-bottom:2px solid var(--br);font-weight:700}
.inv-tbl th,.inv-tbl td{text-align:left}
.inv-tbl .r{text-align:right}
.inv-tbl td{padding:9px 10px;font-size:.79rem;border-bottom:1px solid var(--br)}
.inv-tbl tr:last-child td{border-bottom:none}
.inv-totals{margin-left:auto;width:240px;background:var(--bg3);border-radius:10px;padding:13px 15px}
.itr{display:flex;justify-content:space-between;font-size:.78rem;padding:3px 0}
.itr .l{color:var(--t2)}.itr .r{font-weight:600;color:var(--tx)}
.itr.grand{border-top:1px solid var(--br);margin-top:7px;padding-top:9px}
.itr.grand .l,.itr.grand .r{font-size:.9rem;font-weight:800}
.itr.grand .r{color:var(--ac)}.itr.paid .r{color:var(--gr)}.itr.due .r{color:var(--re)}
.inv-footer{margin-top:22px;padding-top:14px;border-top:1px solid var(--br);
  display:flex;justify-content:space-between;font-size:.7rem;color:var(--t2)}
.inv-note{margin-top:14px;padding:11px 13px;background:var(--bg3);border-radius:8px;font-size:.76rem;color:var(--t2)}
.inv-note strong{color:var(--tx)}
/* MISC */
.spinner{width:30px;height:30px;border:3px solid var(--br);border-top-color:var(--ac);
  border-radius:50%;animation:spin .7s linear infinite;margin:36px auto}
@keyframes spin{to{transform:rotate(360deg)}}
/* RESPONSIVE */
@media(max-width:1100px){.cart-panel{width:310px}}
@media(max-width:900px){
  .sidebar{transform:translateX(-100%)}.sidebar.open{transform:none}
  .main{margin-left:0}.mob-btn{display:block}.sbar{display:none}
  .pos-wrap{flex-direction:column;height:auto;overflow:visible}
  .cart-panel{width:100%;border-left:none;border-top:1px solid var(--br);height:auto}
}
@media(max-width:640px){.prod-grid{grid-template-columns:repeat(2,1fr)}}
/* PRINT */
@media print{
  body *{visibility:hidden}
  #invPrintArea,#invPrintArea *{visibility:visible}
  #invPrintArea{position:fixed;inset:0;background:#fff;padding:24px;z-index:9999}
}
</style>
</head>
<body>

<?php $current_page = 'sales'; include 'sidebar.php'; ?>

<div class="main">

<?php $breadcrumbs = [['label'=>'Sales'],['label'=>'Sales Orders']]; include 'topnav.php'; ?>

<?php if($flash): ?>
<div class="flash <?= $flashType ?>" id="flashMsg">
  <?php if($flashType==='ok'): ?>
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8" cy="8" r="7"/><path d="M5 8l2 2 4-4"/></svg>
  <?php else: ?>
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 1L1 13h14z"/><path d="M8 6v3M8 11v1"/></svg>
  <?php endif; ?>
  <span><?= $flash ?></span>
  <?php if($new_sale_id): ?>
  <div class="flash-actions">
    <button class="btn btn-ghost" style="padding:4px 10px;font-size:.72rem" onclick="openInvoice(<?= $new_sale_id ?>)">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6V1h8v5M2 6h12a1 1 0 011 1v5H1V7a1 1 0 011-1z"/><path d="M4 11v4h8v-4"/></svg>
      Print Invoice
    </button>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- View Tabs -->
<div class="view-tabs">
  <div class="vtab on" id="tabPOS" onclick="switchView('pos')">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" style="width:13px;height:13px;vertical-align:middle;margin-right:4px"><rect x="1" y="3" width="14" height="11" rx="1.5"/><path d="M1 7h14M5 3V1M11 3V1"/></svg>
    New Sale
  </div>
  <div class="vtab" id="tabList" onclick="switchView('list')">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" style="width:13px;height:13px;vertical-align:middle;margin-right:4px"><path d="M2 4h12M2 8h9M2 12h6"/></svg>
    Recent Sales
  </div>
</div>

<!-- POS VIEW -->
<div id="viewPOS" class="pos-wrap">

  <!-- LEFT: Products -->
  <div class="prod-panel">
    <div class="prod-toolbar">
      <div class="scan-row">
        <span class="scan-lbl">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="1" y="3" width="2" height="10"/><rect x="5" y="3" width="1" height="10"/><rect x="8" y="3" width="2" height="10"/><rect x="12" y="3" width="1" height="10"/><rect x="14" y="3" width="1" height="10"/></svg>
          <span class="scan-ping"></span>Scan:
        </span>
        <input type="text" id="barcodeInput" class="barcode-inp"
          placeholder="Scan barcode or type product ID then press Enter…" autocomplete="off" autofocus>
      </div>
      <div class="search-row">
        <div class="searchbox">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="7" cy="7" r="4.5"/><path d="M11 11l3 3"/></svg>
          <input type="text" id="prodSearch" placeholder="Search products…">
        </div>
        <select id="catFilter" class="cat-sel">
          <option value="">All Categories</option>
          <?php if($categories) while($cat=$categories->fetch_assoc()): ?>
            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
          <?php endwhile; ?>
        </select>
      </div>
    </div>
    <div class="prod-grid" id="prodGrid">
      <div class="grid-empty"><div class="spinner"></div></div>
    </div>
  </div>

  <!-- RIGHT: Cart -->
  <div class="cart-panel">
    <div class="cart-head">
      <div class="cart-title">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 1h2l2.5 8h7L15 4H4"/><circle cx="6.5" cy="13" r="1.2"/><circle cx="12" cy="13" r="1.2"/></svg>
        Cart <span class="cart-count" id="cartCount"></span>
      </div>
      <button class="cart-clear-btn" onclick="clearCart()">Clear all</button>
    </div>

    <div id="cartList" class="cart-list">
      <div class="cart-empty">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1 1h2l2.5 8h7L15 4H4"/><circle cx="6.5" cy="13" r="1.2"/><circle cx="12" cy="13" r="1.2"/></svg>
        <p>Cart is empty.<br>Click a product or scan a barcode.</p>
      </div>
    </div>

    <form method="POST" action="sales.php" id="saleForm">
      <input type="hidden" name="action" value="submit_sale">
      <input type="hidden" name="cart_items" id="cartInput">
      <input type="hidden" name="customer_id" id="hidCustId">
      <input type="hidden" name="sale_type" id="saleTypeInput" value="cash">

      <div class="checkout">

        <!-- Customer -->
        <div>
          <div class="flbl" style="margin-bottom:5px">Customer <span style="color:var(--t3);font-weight:400">(optional)</span></div>
          <div class="cust-wrap" id="custWrap">
            <div class="searchbox" id="custInputBox">
              <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="6" cy="5" r="3"/><path d="M1 14c0-3 2.24-5 5-5h4c2.76 0 5 2 5 5"/></svg>
              <input type="text" id="custSearch" placeholder="Search customer">
            </div>
            <div class="cust-drop" id="custDrop">
              <?php foreach($custs as $c): $dn=trim($c['full_name'])?:$c['biz']; ?>
              <div class="cust-item"
                onclick="selCust(<?= $c['id'] ?>,'<?= htmlspecialchars(addslashes($dn)) ?>',<?= floatval($c['credit_limit']) ?>,<?= floatval($c['current_balance']) ?>)">
                <strong><?= htmlspecialchars($dn) ?></strong>
                <span><?= htmlspecialchars($c['phone']) ?><?= $c['biz'] ? '  '.$c['biz'] : '' ?></span>
              </div>
              <?php endforeach; ?>
            </div>
            <div class="cust-sel-card" id="custSelCard" style="display:none">
              <div>
                <div class="cust-sel-name" id="custSelName"></div>
                <div class="cust-sel-info" id="custSelInfo"></div>
              </div>
              <button type="button" class="cust-clear-btn" onclick="clearCust()">…</button>
            </div>
          </div>
        </div>

        <!-- Date + Type -->
        <div class="co-row2">
          <div class="fgrp">
            <div class="flbl">Sale Date</div>
            <input type="date" name="sale_date" class="finput" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="fgrp">
            <div class="flbl">Order Type</div>
            <select name="order_type" class="fsel" <?= $isRep?'disabled':'' ?>>
              <option value="direct" <?= !$isRep?'selected':'' ?>>Direct</option>
              <option value="representative" <?= $isRep?'selected':'' ?>>Representative</option>
              <option value="phone">Phone</option>
              <option value="online">Online</option>
            </select>
          </div>
        </div>

        <!-- Payment type -->
        <div class="fgrp">
          <div class="flbl" style="margin-bottom:5px">Payment Type</div>
          <div class="ptt-wrap">
            <button type="button" class="ptt on" id="ptCash"   onclick="setPayType('cash')">💰 Cash</button>
            <button type="button" class="ptt"    id="ptCredit" onclick="setPayType('credit')">💳 Credit / Debt</button>
          </div>
        </div>

        <!-- Discount + Paid -->
        <div class="co-row2">
          <div class="fgrp">
            <div class="flbl">Discount %</div>
            <input type="number" name="discount_percent" id="discInput" class="finput" min="0" max="100" step="0.5" value="0">
          </div>
          <div class="fgrp">
            <div class="flbl">Paid Amount</div>
            <input type="number" name="paid_amount" id="paidInput" class="finput" min="0" step="0.01" placeholder="0.00">
          </div>
        </div>

        <!-- Totals -->
        <div class="totals-box">
          <div class="trow"><span class="lbl">Subtotal</span><span class="val" id="tSub">$0.00</span></div>
          <div class="trow" id="tDiscRow" style="display:none"><span class="lbl">Discount</span><span class="val" id="tDisc" style="color:var(--gr)">-$0.00</span></div>
          <div class="trow grand"><span class="lbl">Total</span><span class="val" id="tTotal">$0.00</span></div>
          <div class="trow change" id="tChangeRow" style="display:none"><span class="lbl">Change</span><span class="val" id="tChange"></span></div>
          <div class="trow debt"   id="tDebtRow"   style="display:none"><span class="lbl">Remaining Debt</span><span class="val" id="tDebt"></span></div>
        </div>

        <div class="fgrp">
          <div class="flbl">Notes (optional)</div>
          <input type="text" name="notes" class="finput" placeholder="Add a note…">
        </div>

        <button type="submit" class="submit-btn" id="submitBtn" disabled>
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M1 8h14M9 3l5 5-5 5"/></svg>
          Complete Sale
        </button>

      </div>
    </form>
  </div><!-- /cart-panel -->

</div><!-- /pos-wrap -->

<!-- RECENT SALES -->
<div id="viewList" style="display:none;flex:1;overflow-y:auto">
  <div class="list-wrap">
    <?php if($recent&&$recent->num_rows>0): ?>
    <table class="stbl">
      <thead><tr>
        <th>Sale #</th><th>Customer</th><th>Date</th>
        <th>Items</th><th>Total</th><th>Payment</th>
        <th>Status</th><th>Approval</th><th></th>
      </tr></thead>
      <tbody>
      <?php while($s=$recent->fetch_assoc()):
        $cn=trim($s['customer_name'])?:'—';
        $pp=match($s['payment_status']){'paid'=>['ok','Paid'],'partial'=>['warn','Partial'],default=>['nt','Unpaid']};
        $op=match($s['order_status']){'delivered'=>['ok','Delivered'],'confirmed'=>['info','Confirmed'],'processing'=>['info','Processing'],'cancelled'=>['err','Cancelled'],'returned'=>['err','Returned'],'pending'=>['warn','Pending'],'packed'=>['info','Packed'],'shipped'=>['info','Shipped'],default=>['nt',ucfirst($s['order_status'])]};
        $ap=match($s['approval_status']){'approved'=>['ok','Approved'],'rejected'=>['err','Rejected'],default=>['warn','Pending']};
      ?>
      <tr onclick="openDetail(<?= $s['id'] ?>)">
        <td><b style="color:var(--tx)"><?= htmlspecialchars($s['sale_number']) ?></b></td>
        <td style="color:var(--t2)"><?= htmlspecialchars($cn) ?></td>
        <td style="color:var(--t2);white-space:nowrap"><?= date('M j, Y',strtotime($s['sale_date'])) ?></td>
        <td style="color:var(--t2)"><?= $s['item_count'] ?></td>
        <td style="font-weight:700;color:var(--tx)"><?= fmt($s['total_amount']) ?></td>
        <td><span class="pill <?= $pp[0] ?>"><?= $pp[1] ?></span></td>
        <td><span class="pill <?= $op[0] ?>"><?= $op[1] ?></span></td>
        <td><span class="pill <?= $ap[0] ?>"><?= $ap[1] ?></span></td>
        <td onclick="event.stopPropagation()">
          <div style="display:flex;gap:5px">
            <button class="btn btn-ghost" style="padding:4px 9px;font-size:.7rem" onclick="openInvoice(<?= $s['id'] ?>)">
              <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" style="width:11px;height:11px"><path d="M4 6V1h8v5M2 6h12a1 1 0 011 1v5H1V7a1 1 0 011-1z"/><path d="M4 11v4h8v-4"/></svg>Invoice
            </button>
            <?php if($canDelete&&$s['order_status']!=='cancelled'): ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('Cancel this sale?')">
              <input type="hidden" name="action" value="delete_sale">
              <input type="hidden" name="sale_id" value="<?= $s['id'] ?>">
              <button type="submit" class="btn btn-ghost" style="padding:4px 9px;font-size:.7rem;color:var(--re)">Cancel</button>
            </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div style="text-align:center;padding:60px 20px;color:var(--t3)">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" style="width:40px;height:40px;margin-bottom:12px;opacity:.3;display:block;margin:0 auto 12px"><path d="M1 1h2l2.5 8h7L15 4H4"/></svg>
      <p style="font-size:.82rem">No sales recorded yet.</p>
    </div>
    <?php endif; ?>
  </div>
</div>

</div><!-- /main -->

<!-- DETAIL MODAL -->
<div class="overlay" id="detailOverlay" onclick="if(event.target===this)closeOv('detailOverlay')">
  <div class="modal" style="max-width:520px">
    <div class="mhead">
      <div><div class="mtitle" id="detTitle">Sale Details</div><div class="msub" id="detSub"></div></div>
      <button class="mclose" onclick="closeOv('detailOverlay')">✕</button>
    </div>
    <div class="mbody" id="detBody"><div class="spinner"></div></div>
    <div class="mfoot">
      <button class="btn btn-ghost" onclick="closeOv('detailOverlay')">Close</button>
      <button class="btn btn-primary" id="detPrintBtn">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6V1h8v5M2 6h12a1 1 0 011 1v5H1V7a1 1 0 011-1z"/><path d="M4 11v4h8v-4"/></svg>
        Print Invoice
      </button>
    </div>
  </div>
</div>

<!-- INVOICE MODAL -->
<div class="overlay" id="invoiceOverlay" onclick="if(event.target===this)closeOv('invoiceOverlay')">
  <div class="modal">
    <div class="mhead">
      <div><div class="mtitle">Invoice</div><div class="msub" id="invSub">Loading…</div></div>
      <button class="mclose" onclick="closeOv('invoiceOverlay')">✕</button>
    </div>
    <div id="invPrintArea" style="padding:28px"><div class="spinner"></div></div>
    <div class="mfoot">
      <button class="btn btn-ghost" onclick="closeOv('invoiceOverlay')">Close</button>
      <button class="btn btn-primary" onclick="window.print()">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6V1h8v5M2 6h12a1 1 0 011 1v5H1V7a1 1 0 011-1z"/><path d="M4 11v4h8v-4"/></svg>
        Print
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

// Mobile sidebar
document.addEventListener('click',e=>{
  const sb=document.getElementById('sidebar');
  if(sb&&window.innerWidth<=900&&!sb.contains(e.target)&&!e.target.closest('.mob-btn'))sb.classList.remove('open');
});

// View switch
function switchView(v){
  document.getElementById('viewPOS').style.display=v==='pos'?'flex':'none';
  document.getElementById('viewList').style.display=v==='pos'?'none':'block';
  document.getElementById('tabPOS').classList.toggle('on',v==='pos');
  document.getElementById('tabList').classList.toggle('on',v!=='pos');
}

// Cart
let cart={};
function addToCart(p){
  const id=String(p.id),stock=parseInt(p.stock)||9999;
  if(cart[id]){if(cart[id].qty>=stock){toast('Max stock reached','warn');return;}cart[id].qty++;}
  else cart[id]={id:p.id,name:p.name,price:parseFloat(p.selling_price),image:p.image_path||'',uom:p.unit_of_measure||'pc',qty:1,stock};
  renderCart();calcTotals();
  const c=document.querySelector(`.prod-card[data-id="${id}"]`);
  if(c){c.classList.add('adding');setTimeout(()=>c.classList.remove('adding'),250);}
}
function qtyChange(id,d){
  if(!cart[id])return;cart[id].qty+=d;
  if(cart[id].qty<=0)delete cart[id];else if(cart[id].qty>cart[id].stock)cart[id].qty=cart[id].stock;
  renderCart();calcTotals();
}
function removeFromCart(id){delete cart[id];renderCart();calcTotals();}
function clearCart(){cart={};renderCart();calcTotals();}

function renderCart(){
  const items=Object.values(cart);
  const tot=items.reduce((s,i)=>s+i.qty,0);
  document.getElementById('cartCount').textContent=tot>0?`(${tot} item${tot>1?'s':''})`:'';
  const list=document.getElementById('cartList');
  if(!items.length){
    list.innerHTML='<div class="cart-empty"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1 1h2l2.5 8h7L15 4H4"/><circle cx="6.5" cy="13" r="1.2"/><circle cx="12" cy="13" r="1.2"/></svg><p>Cart is empty.<br>Click a product or scan a barcode.</p></div>';
    document.getElementById('cartInput').value='[]';
    document.getElementById('submitBtn').disabled=true;
    return;
  }
  list.innerHTML=items.map(it=>{
    const tp=(it.price*it.qty).toFixed(2);
    const img=it.image?`<img src="${it.image}" alt="" onerror="this.parentNode.innerHTML='📦'">`:' 📦';
    return `<div class="ci"><div class="ci-thumb">${img}</div>
      <div class="ci-info"><div class="ci-name" title="${xss(it.name)}">${xss(it.name)}</div>
      <div class="ci-uprice">$${it.price.toFixed(2)} / ${it.uom}</div></div>
      <div class="ci-qty">
        <button type="button" class="qty-btn" onclick="qtyChange('${it.id}',-1)">−</button>
        <span class="qty-num">${it.qty}</span>
        <button type="button" class="qty-btn" onclick="qtyChange('${it.id}',1)">+</button>
      </div>
      <div class="ci-total">$${tp}</div>
      <button type="button" class="ci-del" onclick="removeFromCart('${it.id}')">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 4h12M5 4V2h6v2M6 7v4M10 7v4M3 4l1 8h8l1-8"/></svg>
      </button></div>`;
  }).join('');
  document.getElementById('cartInput').value=JSON.stringify(items.map(i=>({id:i.id,qty:i.qty,price:i.price})));
  document.getElementById('submitBtn').disabled=false;
}
function xss(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

// Totals
function calcTotals(){
  const items=Object.values(cart);
  const sub=items.reduce((s,i)=>s+(i.price*i.qty),0);
  const dp=parseFloat(document.getElementById('discInput').value)||0;
  const disc=sub*dp/100,tot=sub-disc;
  const paid=parseFloat(document.getElementById('paidInput').value)||0;
  document.getElementById('tSub').textContent='$'+sub.toFixed(2);
  document.getElementById('tTotal').textContent='$'+tot.toFixed(2);
  const dr=document.getElementById('tDiscRow');
  dr.style.display=disc>0?'flex':'none';
  if(disc>0)document.getElementById('tDisc').textContent='-$'+disc.toFixed(2);
  const diff=paid-tot;
  const cr=document.getElementById('tChangeRow'),debr=document.getElementById('tDebtRow');
  if(paid>0&&diff>=0){cr.style.display='flex';debr.style.display='none';document.getElementById('tChange').textContent='$'+diff.toFixed(2);}
  else if(tot>0&&diff<0){cr.style.display='none';debr.style.display='flex';document.getElementById('tDebt').textContent='$'+Math.abs(diff).toFixed(2);}
  else if(tot>0&&paid===0){cr.style.display='none';debr.style.display='flex';document.getElementById('tDebt').textContent='$'+tot.toFixed(2);}
  else{cr.style.display='none';debr.style.display='none';}
}
document.getElementById('discInput').addEventListener('input',calcTotals);
document.getElementById('paidInput').addEventListener('input',calcTotals);

// Pay type
function setPayType(t){
  document.getElementById('saleTypeInput').value=t;
  document.getElementById('ptCash').classList.toggle('on',t==='cash');
  document.getElementById('ptCredit').classList.toggle('on',t==='credit');
  document.getElementById('paidInput').placeholder=t==='cash'?'Cash received':'Down payment (0 = full debt)';
}

// Customer
const custIn=document.getElementById('custSearch');
const custDrop=document.getElementById('custDrop');
const custItems=Array.from(custDrop.querySelectorAll('.cust-item'));
custIn.addEventListener('focus',()=>custDrop.classList.add('open'));
custIn.addEventListener('input',()=>{
  const q=custIn.value.toLowerCase();
  custItems.forEach(el=>el.style.display=el.textContent.toLowerCase().includes(q)?'':'none');
  custDrop.classList.add('open');
});
document.addEventListener('click',e=>{if(!document.getElementById('custWrap').contains(e.target))custDrop.classList.remove('open');});
function selCust(id,name,limit,bal){
  document.getElementById('hidCustId').value=id;
  document.getElementById('custInputBox').style.display='none';
  document.getElementById('custSelCard').style.display='flex';
  document.getElementById('custSelName').textContent=name;
  document.getElementById('custSelInfo').textContent=`Balance: $${parseFloat(bal).toFixed(2)}  ·  Limit: $${parseFloat(limit).toFixed(2)}`;
  custDrop.classList.remove('open');
}
function clearCust(){
  document.getElementById('hidCustId').value='';
  document.getElementById('custSelCard').style.display='none';
  document.getElementById('custInputBox').style.display='flex';
  custIn.value='';custItems.forEach(el=>el.style.display='');
}

// Barcode
const barcodeEl=document.getElementById('barcodeInput');
barcodeEl.addEventListener('keydown',async e=>{
  if(e.key!=='Enter')return;e.preventDefault();
  const v=barcodeEl.value.trim();if(!v)return;barcodeEl.value='';
  try{const r=await fetch(`sales.php?ajax=barcode&q=${encodeURIComponent(v)}`);
    const d=await r.json();
    if(d.ok){addToCart(d.product);toast('Added: '+d.product.name,'ok');}
    else toast('Not found: '+v,'err');
  }catch{toast('Lookup failed','err');}
});
let bcT;
barcodeEl.addEventListener('input',()=>{clearTimeout(bcT);bcT=setTimeout(()=>{const v=barcodeEl.value.trim();if(v.length>=2){document.getElementById('prodSearch').value=v;loadProds();}},380);});

// Products
async function loadProds(){
  const q=document.getElementById('prodSearch').value.trim();
  const cat=document.getElementById('catFilter').value;
  document.getElementById('prodGrid').innerHTML='<div class="grid-empty"><div class="spinner"></div></div>';
  try{const r=await fetch(`sales.php?ajax=search&q=${encodeURIComponent(q)}&cat=${encodeURIComponent(cat)}`);
    const d=await r.json();renderProds(d.products||[]);
  }catch{document.getElementById('prodGrid').innerHTML='<div class="grid-empty"><p style="color:var(--re)">Failed to load.</p></div>';}
}
function renderProds(prods){
  const g=document.getElementById('prodGrid');
  if(!prods.length){g.innerHTML='<div class="grid-empty"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="7" cy="7" r="5"/><path d="M11 11l3 3"/></svg><p>No products found.</p></div>';return;}
  g.innerHTML=prods.map(p=>{
    const stock=parseInt(p.stock)||0,isOut=stock<=0;
    const cls=isOut?'zero':stock<=10?'low':'';
    const stxt=isOut?'Out of stock':stock<=10?`Low: ${stock} left`:`${stock} in stock`;
    const img=p.image_path?`<div class="prod-img-wrap"><img src="${p.image_path}" alt="" onerror="this.parentNode.innerHTML='📦'"></div>`:'<div class="prod-img-wrap">📦</div>';
    return `<div class="prod-card${isOut?' out':''}" data-id="${p.id}" onclick='addToCart(${JSON.stringify(p)})'>
      ${isOut?'<div class="out-tag">OUT</div>':''}${img}
      <div class="prod-body">
        <div class="prod-name">${xss(p.name)}</div>
        <div class="prod-price">$${parseFloat(p.selling_price).toFixed(2)}</div>
        <div class="prod-stock ${cls}">${stxt}</div>
      </div></div>`;
  }).join('');
}
let srchT;
document.getElementById('prodSearch').addEventListener('input',()=>{clearTimeout(srchT);srchT=setTimeout(loadProds,300);});
document.getElementById('catFilter').addEventListener('change',loadProds);
loadProds();

// Toast
function toast(msg,type='ok'){
  const t=document.createElement('div');
  t.className=`flash ${type}`;
  t.style.cssText='position:fixed;bottom:22px;right:22px;z-index:600;max-width:300px;padding:10px 15px;font-size:.79rem;box-shadow:var(--sh2)';
  t.textContent=msg;document.body.appendChild(t);
  setTimeout(()=>{t.style.transition='opacity .4s';t.style.opacity='0';setTimeout(()=>t.remove(),420);},2400);
}

// Overlays
function openOv(id){document.getElementById(id).classList.add('open');document.body.style.overflow='hidden';}
function closeOv(id){document.getElementById(id).classList.remove('open');document.body.style.overflow='';}

// Invoice
async function openInvoice(sid){
  document.getElementById('invPrintArea').innerHTML='<div class="spinner"></div>';
  document.getElementById('invSub').textContent='Loading…';
  openOv('invoiceOverlay');
  try{const r=await fetch(`sales.php?ajax=invoice&id=${sid}`);const d=await r.json();
    if(!d.ok){document.getElementById('invPrintArea').innerHTML='<p style="color:var(--re);padding:20px">Failed.</p>';return;}
    buildInv(d.sale,d.items);document.getElementById('invSub').textContent=d.sale.invoice_number||d.sale.sale_number;
  }catch{document.getElementById('invPrintArea').innerHTML='<p style="color:var(--re);padding:20px">Error.</p>';}
}
function buildInv(s,items){
  const pb=s.payment_status==='paid'?['#34D399','Paid']:s.payment_status==='partial'?['#F5A623','Partial']:['#F87171','Unpaid'];
  const tot=parseFloat(s.total_amount)||0,paid=parseFloat(s.paid_amount)||0,due=tot-paid;
  const disc=parseFloat(s.discount_amount)||0,sub=parseFloat(s.subtotal)||0;
  document.getElementById('invPrintArea').innerHTML=`
  <div class="inv-header">
    <div class="inv-brand">
      <div class="inv-logo"><svg viewBox="0 0 18 18" fill="none" stroke="#fff" stroke-width="2"><path d="M3 9l3 3 4-5 4 4"/><rect x="1" y="1" width="16" height="16" rx="3"/></svg></div>
      <div><div class="inv-bname">Sales<span>OS</span></div><div class="inv-bsub">Point of Sale</div></div>
    </div>
    <div class="inv-meta">
      <div class="inv-num">${xss(s.invoice_number||s.sale_number)}</div>
      <div class="inv-date">Sale: ${xss(s.sale_number)}</div>
      <div class="inv-date">Date: ${xss(s.sale_date)}</div>
      <div style="margin-top:6px"><span style="padding:3px 9px;border-radius:100px;font-size:.68rem;font-weight:700;background:${pb[0]}22;color:${pb[0]};border:1px solid ${pb[0]}44">${pb[1]}</span></div>
    </div>
  </div>
  <div class="inv-parties">
    <div class="inv-party"><h4>Bill To</h4>
      <p>${xss(s.customer_name?.trim()||'Walk-in Customer')}</p>
      ${s.customer_phone?`<span>${xss(s.customer_phone)}</span>`:''}
    </div>
    <div class="inv-party"><h4>Sale Info</h4>
      ${s.rep_name?.trim()?`<p>Rep: ${xss(s.rep_name)}</p>`:'<p>Direct Sale</p>'}
      <span>Type: ${xss(s.order_type)}</span><br><span>By: ${xss(s.created_by_name||'')}</span>
    </div>
  </div>
  <table class="inv-tbl">
    <thead><tr><th>Item</th><th class="r">Qty</th><th class="r">Unit Price</th><th class="r">Total</th></tr></thead>
    <tbody>${items.map(it=>`<tr><td>${xss(it.product_name)}</td><td class="r">${it.quantity}</td><td class="r">$${parseFloat(it.unit_price).toFixed(2)}</td><td class="r">$${parseFloat(it.total_price).toFixed(2)}</td></tr>`).join('')}</tbody>
  </table>
  <div style="display:flex;justify-content:flex-end">
    <div class="inv-totals">
      <div class="itr"><span class="l">Subtotal</span><span class="r">$${sub.toFixed(2)}</span></div>
      ${disc>0?`<div class="itr"><span class="l">Discount</span><span class="r" style="color:var(--gr)">-$${disc.toFixed(2)}</span></div>`:''}
      <div class="itr grand"><span class="l">Total</span><span class="r">$${tot.toFixed(2)}</span></div>
      <div class="itr paid"><span class="l">Paid</span><span class="r">$${paid.toFixed(2)}</span></div>
      ${due>0?`<div class="itr due"><span class="l">Balance Due</span><span class="r">$${due.toFixed(2)}</span></div>`:''}
    </div>
  </div>
  ${s.notes?`<div class="inv-note"><strong>Notes:</strong> ${xss(s.notes)}</div>`:''}
  <div class="inv-footer"><span>Thank you for your business!</span><span>Printed ${new Date().toLocaleDateString()}</span></div>`;
}

// Sale detail
async function openDetail(sid){
  document.getElementById('detBody').innerHTML='<div class="spinner"></div>';
  document.getElementById('detTitle').textContent='Sale Details';
  document.getElementById('detSub').textContent='Loading…';
  document.getElementById('detPrintBtn').onclick=()=>openInvoice(sid);
  openOv('detailOverlay');
  try{const r=await fetch(`sales.php?ajax=invoice&id=${sid}`);const d=await r.json();
    if(!d.ok)return;const s=d.sale;
    document.getElementById('detTitle').textContent=s.sale_number;
    document.getElementById('detSub').textContent=`${s.sale_date} · ${s.order_type} · ${s.sale_type}`;
    const pp=s.payment_status==='paid'?'ok':s.payment_status==='partial'?'warn':'nt';
    const ap=s.approval_status==='approved'?'ok':s.approval_status==='rejected'?'err':'warn';
    document.getElementById('detBody').innerHTML=`
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px">
        <div style="background:var(--bg3);border-radius:9px;padding:11px">
          <div style="font-size:.62rem;color:var(--t3);text-transform:uppercase;letter-spacing:.08em;margin-bottom:5px">Customer</div>
          <div style="font-weight:700;color:var(--tx)">${xss(s.customer_name?.trim()||'Walk-in')}</div>
          ${s.customer_phone?`<div style="font-size:.71rem;color:var(--t2);margin-top:2px">${xss(s.customer_phone)}</div>`:''}
        </div>
        <div style="background:var(--bg3);border-radius:9px;padding:11px">
          <div style="font-size:.62rem;color:var(--t3);text-transform:uppercase;letter-spacing:.08em;margin-bottom:5px">Created By</div>
          <div style="font-weight:700;color:var(--tx)">${xss(s.created_by_name||'—')}</div>
          ${s.rep_name?.trim()?`<div style="font-size:.71rem;color:var(--t2);margin-top:2px">Rep: ${xss(s.rep_name)}</div>`:''}
        </div>
      </div>
      <table style="width:100%;border-collapse:collapse;margin-bottom:14px">
        <thead><tr>
          <th style="font-size:.61rem;color:var(--t3);padding:7px 10px;text-align:left;border-bottom:1px solid var(--br);text-transform:uppercase;letter-spacing:.08em">Item</th>
          <th style="font-size:.61rem;color:var(--t3);padding:7px 10px;text-align:center;border-bottom:1px solid var(--br);text-transform:uppercase">Qty</th>
          <th style="font-size:.61rem;color:var(--t3);padding:7px 10px;text-align:right;border-bottom:1px solid var(--br);text-transform:uppercase">Price</th>
          <th style="font-size:.61rem;color:var(--t3);padding:7px 10px;text-align:right;border-bottom:1px solid var(--br);text-transform:uppercase">Total</th>
        </tr></thead>
        <tbody>${d.items.map(it=>`<tr>
          <td style="padding:9px 10px;font-size:.78rem;border-bottom:1px solid var(--br);color:var(--tx)">${xss(it.product_name)}</td>
          <td style="padding:9px 10px;font-size:.78rem;border-bottom:1px solid var(--br);text-align:center;color:var(--t2)">${it.quantity}</td>
          <td style="padding:9px 10px;font-size:.78rem;border-bottom:1px solid var(--br);text-align:right;color:var(--t2)">$${parseFloat(it.unit_price).toFixed(2)}</td>
          <td style="padding:9px 10px;font-size:.78rem;border-bottom:1px solid var(--br);text-align:right;font-weight:700;color:var(--tx)">$${parseFloat(it.total_price).toFixed(2)}</td>
        </tr>`).join('')}</tbody>
      </table>
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <span class="pill ${pp}">${s.payment_status}</span>
          <span class="pill ${ap}">${s.approval_status}</span>
          <span class="pill nt">${s.order_status}</span>
        </div>
        <div style="text-align:right">
          <div style="font-size:.77rem;color:var(--t2)">Total: <b style="color:var(--tx);font-size:.88rem">$${parseFloat(s.total_amount).toFixed(2)}</b></div>
          <div style="font-size:.73rem;color:var(--t2);margin-top:2px">Paid: <span style="color:var(--gr);font-weight:700">$${parseFloat(s.paid_amount).toFixed(2)}</span></div>
        </div>
      </div>`;
  }catch{}
}

<?php if($new_sale_id): ?>
setTimeout(()=>openInvoice(<?= $new_sale_id ?>),500);
<?php endif; ?>
setTimeout(()=>{const f=document.getElementById('flashMsg');if(f){f.style.transition='opacity .5s';f.style.opacity='0';setTimeout(()=>f?.remove(),520);}},6000);
</script>
</body>
</html>