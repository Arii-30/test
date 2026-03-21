<?php
// ─── Bootstrap ────────────────────────────────────────────────
require_once 'connection.php';
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$user = htmlspecialchars($_SESSION['username'] ?? 'Admin');
$role = htmlspecialchars(ucfirst($_SESSION['role'] ?? 'admin'));
$ava  = strtoupper(substr($user, 0, 1));
$uid  = (int)$_SESSION['user_id'];

$canEdit   = in_array($_SESSION['role'], ['admin', 'accountant']);
$canDelete = ($_SESSION['role'] === 'admin');

// ─── Helpers ──────────────────────────────────────────────────
function fmt($v) {
    if ($v >= 1_000_000) return '$'.number_format($v/1_000_000,2).'M';
    if ($v >= 1_000)     return '$'.number_format($v/1_000,1).'k';
    return '$'.number_format($v,2);
}
function fval($conn,$sql,$col='v'){
    $r=$conn->query($sql); if(!$r) return 0;
    $row=$r->fetch_assoc(); return $row[$col]??0;
}
function esc($conn,$v){ return $conn->real_escape_string($v); }
function timeAgo($dt){
    if(!$dt) return '—';
    $d=time()-strtotime($dt);
    if($d<60)   return $d.'s ago';
    if($d<3600) return floor($d/60).'m ago';
    if($d<86400)return floor($d/3600).'h ago';
    if($d<172800)return 'Yesterday';
    return date('M j',strtotime($dt));
}

$flash=$flashType='';

// ═══════════════════════════════════════════════════════════════
// POST — Stock Adjustments
// ═══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD']==='POST' && $canEdit) {
    $action = $_POST['action'] ?? '';

    if ($action === 'adjust_stock') {
        $prod_id  = (int)($_POST['prod_id']   ?? 0);
        $adj_type = $_POST['adj_type']         ?? 'add';   // add|subtract|set
        $qty      = (int)($_POST['qty']        ?? 0);
        $reason   = trim($_POST['reason']      ?? '');
        $notes    = trim($_POST['notes']       ?? '') ?: null;
        $ref      = trim($_POST['reference']   ?? '') ?: null;

        if ($prod_id && $qty > 0) {
            // Get current stock
            $cr = $conn->query("SELECT quantity_in_stock, quantity_reserved FROM inventory WHERE product_id=$prod_id");
            $cur = $cr ? $cr->fetch_assoc() : null;
            $cur_stock = (int)($cur['quantity_in_stock'] ?? 0);
            $cur_reserved = (int)($cur['quantity_reserved'] ?? 0);

            if ($adj_type === 'add') {
                $new_stock = $cur_stock + $qty;
                $txn_qty   = $qty;
                $txn_type  = 'adjustment';
            } elseif ($adj_type === 'subtract') {
                $new_stock = max(0, $cur_stock - $qty);
                $txn_qty   = -($cur_stock - $new_stock);
                $txn_type  = 'damage';
            } else { // set
                $new_stock = $qty;
                $txn_qty   = $qty - $cur_stock;
                $txn_type  = 'adjustment';
            }

            if ($cur) {
                $conn->query("UPDATE inventory SET quantity_in_stock=$new_stock WHERE product_id=$prod_id");
            } else {
                $conn->query("INSERT INTO inventory (product_id,quantity_in_stock,quantity_reserved) VALUES ($prod_id,$new_stock,0)");
            }

            // Log transaction
            $nn = $notes  ? "'".esc($conn,$notes)."'"  : 'NULL';
            $conn->query("INSERT INTO inventory_transactions
                (product_id,transaction_type,quantity,notes)
                VALUES ($prod_id,'$txn_type',$txn_qty,$nn)");

            // Audit log
            $desc = esc($conn,"Stock adjusted for product #$prod_id: $adj_type $qty units. Reason: $reason");
            $conn->query("INSERT INTO audit_logs (user_id,user_name,action,table_name,record_id,description)
                VALUES ($uid,'$user','UPDATE','inventory',$prod_id,'$desc')");

            $flash = "Stock updated successfully."; $flashType = 'ok';
        } else {
            $flash = "Invalid product or quantity."; $flashType = 'err';
        }
        header("Location: stock.php?flash=".urlencode($flash)."&ft=$flashType"); exit;
    }

    if ($action === 'bulk_reorder') {
        // Mark all low-stock items as reorder triggered (insert adjustment note)
        $lr = $conn->query("SELECT product_id FROM vw_low_stock");
        $cnt = 0;
        if ($lr) while ($lrow = $lr->fetch_assoc()) {
            $pid2 = (int)$lrow['product_id'];
            $conn->query("INSERT INTO inventory_transactions
                (product_id,transaction_type,quantity,notes)
                VALUES ($pid2,'adjustment',0,'Reorder triggered by bulk action')");
            $cnt++;
        }
        $flash = "Reorder triggered for $cnt products."; $flashType = 'ok';
        header("Location: stock.php?flash=".urlencode($flash)."&ft=$flashType"); exit;
    }
}

if (isset($_GET['flash'])) { $flash=urldecode($_GET['flash']); $flashType=$_GET['ft']??'ok'; }

// ═══════════════════════════════════════════════════════════════
// FILTERS
// ═══════════════════════════════════════════════════════════════
$search   = trim($_GET['q']         ?? '');
$catF     = (int)($_GET['cat']      ?? 0);
$stockF   = $_GET['stock']          ?? '';   // low|out|ok|all
$page     = max(1,(int)($_GET['page']??1));
$perPage  = 20;
$offset   = ($page-1)*$perPage;

$w = "WHERE p.is_active=1";
if ($search) {
    $s=esc($conn,$search);
    $w .= " AND (p.name LIKE '%$s%' OR p.barcode LIKE '%$s%' OR p.brand LIKE '%$s%')";
}
if ($catF)  $w .= " AND p.category_id=$catF";
if ($stockF === 'low') $w .= " AND COALESCE(i.quantity_available,0) > 0 AND COALESCE(i.quantity_available,0) <= p.reorder_level";
if ($stockF === 'out') $w .= " AND COALESCE(i.quantity_available,0) = 0";
if ($stockF === 'ok')  $w .= " AND COALESCE(i.quantity_available,0) > p.reorder_level";

$totalR = $conn->query("SELECT COUNT(*) AS c FROM products p
    LEFT JOIN inventory i ON i.product_id=p.id $w");
$total = $totalR ? (int)$totalR->fetch_assoc()['c'] : 0;
$pages = max(1,ceil($total/$perPage));

$rows = $conn->query("SELECT p.id, p.name, p.barcode, p.brand, p.cost_price, p.selling_price,
    p.unit_of_measure, p.min_stock_level, p.max_stock_level, p.reorder_level,
    p.expiry_date, p.is_featured,
    c.name AS cat_name,
    COALESCE(i.quantity_in_stock,0)  AS qty_stock,
    COALESCE(i.quantity_reserved,0)  AS qty_reserved,
    COALESCE(i.quantity_available,0) AS qty_avail,
    i.updated_at AS last_updated
    FROM products p
    LEFT JOIN categories c ON c.id=p.category_id
    LEFT JOIN inventory i ON i.product_id=p.id
    $w
    ORDER BY
      CASE WHEN COALESCE(i.quantity_available,0)=0 THEN 0
           WHEN COALESCE(i.quantity_available,0)<=p.reorder_level THEN 1
           ELSE 2 END ASC,
      p.name ASC
    LIMIT $perPage OFFSET $offset");

// ─── Stats ────────────────────────────────────────────────────
$statTotal    = (int)fval($conn,"SELECT COUNT(*) AS v FROM products WHERE is_active=1");
$statStockQty = (int)fval($conn,"SELECT COALESCE(SUM(quantity_in_stock),0) AS v FROM inventory");
$statLow      = (int)fval($conn,"SELECT COUNT(*) AS v FROM vw_low_stock");
$statOut      = (int)fval($conn,"SELECT COUNT(*) AS v FROM inventory i
    JOIN products p ON p.id=i.product_id WHERE p.is_active=1 AND i.quantity_available=0");
$statValue    = (float)fval($conn,"SELECT COALESCE(SUM(p.cost_price*i.quantity_in_stock),0) AS v
    FROM products p JOIN inventory i ON i.product_id=p.id WHERE p.is_active=1");
$statReserved = (int)fval($conn,"SELECT COALESCE(SUM(quantity_reserved),0) AS v FROM inventory");

// ─── Recent transactions (last 10) ────────────────────────────
$recent_txns = $conn->query("SELECT it.*, p.name AS prod_name, 'System' AS done_by
    FROM inventory_transactions it
    JOIN products p ON p.id=it.product_id
    ORDER BY it.created_at DESC LIMIT 10");

// ─── Low stock items ──────────────────────────────────────────
$low_items = $conn->query("SELECT * FROM vw_low_stock ORDER BY quantity_available ASC LIMIT 8");

// ─── Category options ─────────────────────────────────────────
$catOpts = $conn->query("SELECT id,name FROM categories WHERE is_active=1 ORDER BY name");

// ─── Sidebar badges ───────────────────────────────────────────
$unread_notifs       = (int)fval($conn,"SELECT COUNT(*) AS v FROM notifications WHERE recipient_id=$uid AND is_read=FALSE");
$pending_sales_badge = (int)fval($conn,"SELECT COUNT(*) AS v FROM sales WHERE approval_status='pending'");
$debt_badge          = (int)fval($conn,"SELECT COUNT(*) AS v FROM debts WHERE status IN('active','partially_paid','overdue')");

function buildQS($overrides=[]){
    $p=array_merge($_GET,$overrides); unset($p['page']);
    $qs=http_build_query(array_filter($p,fn($v)=>$v!==''&&$v!==false&&$v!==0&&$v!=='0'));
    return $qs?"?$qs&":"?";
}
$qs=buildQS();

// Transaction type config
$txnConfig = [
    'purchase'   => ['var(--grd)','var(--gr)',  '+',  'Purchase'],
    'sale'       => ['var(--ag)', 'var(--ac)',  '−',  'Sale'],
    'damage'     => ['var(--red)','var(--re)',  '−',  'Damage'],
    'adjustment' => ['var(--god)','var(--go)',  '±',  'Adjustment'],
    'return'     => ['var(--pud)','var(--pu)',  '+',  'Return'],
    'transfer'   => ['var(--ted)','var(--te)',  '→',  'Transfer'],
];

// ─── Sidebar/topnav vars ──────────────────────────────────────
$current_page = 'stock';
$page_title   = 'Stock Management';
$breadcrumbs  = [['label' => 'Inventory'], ['label' => 'Stock']];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SalesOS — <?= $page_title ?></title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ── VARIABLES ───────────────────────────────────────────────── */
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
  --sh:0 2px 16px rgba(0,0,0,.35);--sh2:0 8px 32px rgba(0,0,0,.4);
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
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{background:var(--bg);color:var(--tx);font-family:'Plus Jakarta Sans',sans-serif;font-size:.875rem;min-height:100vh;display:flex;transition:background .4s,color .4s}

/* ── SIDEBAR ─────────────────────────────────────────────────── */
.sidebar{width:248px;min-height:100vh;background:var(--bg2);border-right:1px solid var(--br);display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;transition:transform .3s;overflow-y:auto}
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

/* ── MAIN ────────────────────────────────────────────────────── */
.main{margin-left:248px;flex:1;display:flex;flex-direction:column;min-height:100vh}
.topnav{height:58px;background:var(--bg2);border-bottom:1px solid var(--br);position:sticky;top:0;z-index:50;display:flex;align-items:center;padding:0 28px;gap:14px;flex-shrink:0}
.mob-btn{display:none;background:none;border:none;cursor:pointer;color:var(--t2);padding:4px}
.mob-btn svg{width:20px;height:20px}
.bc{display:flex;align-items:center;gap:7px;font-size:.75rem;color:var(--t3)}
.bc .sep{opacity:.4}.bc .cur{color:var(--tx);font-weight:600}
.bc a{color:var(--t2);text-decoration:none}.bc a:hover{color:var(--tx)}
.tnr{margin-left:auto;display:flex;align-items:center;gap:8px}
.sbar{display:flex;align-items:center;gap:8px;background:var(--bg3);border:1px solid var(--br);border-radius:9px;padding:7px 14px;transition:border-color .2s}
.sbar:focus-within{border-color:var(--ac)}
.sbar svg{width:14px;height:14px;color:var(--t3);flex-shrink:0}
.sbar input{background:none;border:none;outline:none;font-family:inherit;font-size:.78rem;color:var(--tx);width:160px}
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

/* ── CONTENT ─────────────────────────────────────────────────── */
.content{padding:28px;display:flex;flex-direction:column;gap:20px}
@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}

/* ── PAGE HEADER ─────────────────────────────────────────────── */
.page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}
.ph-title{font-size:1.5rem;font-weight:800;color:var(--tx);letter-spacing:-.03em}
.ph-sub{font-size:.78rem;color:var(--t2);margin-top:4px}
.ph-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}

/* ── STATS ───────────────────────────────────────────────────── */
.stats6{display:grid;grid-template-columns:repeat(6,1fr);gap:12px}
.scard{background:var(--bg2);border:1px solid var(--br);border-radius:13px;padding:16px 18px;animation:fadeUp .4s cubic-bezier(.16,1,.3,1) both;transition:transform .2s,box-shadow .2s}
.scard:hover{transform:translateY(-2px);box-shadow:var(--sh2)}
.sc-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.sc-icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center}
.sc-icon svg{width:16px;height:16px}
.sc-val{font-size:1.3rem;font-weight:800;color:var(--tx);letter-spacing:-.04em;line-height:1}
.sc-lbl{font-size:.68rem;color:var(--t2);margin-top:4px;font-weight:500}
.sc-trend{font-size:.65rem;font-weight:700;padding:2px 7px;border-radius:100px}
.sc-trend.ok{background:var(--grd);color:var(--gr)}
.sc-trend.warn{background:var(--god);color:var(--go)}
.sc-trend.err{background:var(--red);color:var(--re)}
.sc-trend.nt{background:var(--bg3);color:var(--t3)}

/* ── LAYOUT ──────────────────────────────────────────────────── */
.layout{display:grid;grid-template-columns:1fr 320px;gap:18px;align-items:start}

/* ── PANEL ───────────────────────────────────────────────────── */
.panel{background:var(--bg2);border:1px solid var(--br);border-radius:14px;overflow:hidden;animation:fadeUp .35s .06s cubic-bezier(.16,1,.3,1) both}
.phead{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--br)}
.ptitle{font-size:.9rem;font-weight:700;color:var(--tx)}
.psub{font-size:.68rem;color:var(--t3);margin-top:3px;font-weight:500}
.plink{font-size:.72rem;color:var(--ac);text-decoration:none;font-weight:600}
.plink:hover{opacity:.75}

/* ── TOOLBAR ─────────────────────────────────────────────────── */
.toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:14px 20px;border-bottom:1px solid var(--br)}
.searchbox{display:flex;align-items:center;gap:8px;background:var(--bg3);border:1px solid var(--br);border-radius:10px;padding:8px 13px;flex:1;min-width:160px;max-width:240px;transition:border-color .2s}
.searchbox:focus-within{border-color:var(--ac)}
.searchbox svg{width:13px;height:13px;color:var(--t3);flex-shrink:0}
.searchbox input{background:none;border:none;outline:none;font-family:inherit;font-size:.8rem;color:var(--tx);width:100%}
.searchbox input::placeholder{color:var(--t3)}
.sel{background:var(--bg3);border:1px solid var(--br);border-radius:10px;padding:8px 11px;font-family:inherit;font-size:.8rem;color:var(--tx);cursor:pointer;outline:none;transition:border-color .2s}
.sel:focus{border-color:var(--ac)}
.stock-chips{display:flex;gap:5px;flex-wrap:wrap}
.chip{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:9px;font-family:inherit;font-size:.74rem;font-weight:600;cursor:pointer;border:1px solid var(--br);background:var(--bg3);color:var(--t2);text-decoration:none;transition:all .15s}
.chip:hover{border-color:var(--br2);color:var(--tx)}
.chip.on{border-color:rgba(79,142,247,.3);background:var(--ag);color:var(--ac)}
.chip.err.on{border-color:rgba(248,113,113,.3);background:var(--red);color:var(--re)}
.chip.warn.on{border-color:rgba(245,166,35,.25);background:var(--god);color:var(--go)}
.chip.ok.on{border-color:rgba(52,211,153,.25);background:var(--grd);color:var(--gr)}
.toolbar-right{margin-left:auto;display:flex;align-items:center;gap:8px}
.count-lbl{font-size:.75rem;color:var(--t2)}

/* ── TABLE ───────────────────────────────────────────────────── */
.dtbl{width:100%;border-collapse:collapse}
.dtbl th{font-size:.64rem;color:var(--t3);text-transform:uppercase;letter-spacing:.1em;padding:11px 16px;text-align:left;border-bottom:1px solid var(--br);font-weight:700;white-space:nowrap}
.dtbl td{padding:11px 16px;font-size:.81rem;border-bottom:1px solid var(--br);vertical-align:middle}
.dtbl tr:last-child td{border-bottom:none}
.dtbl tbody tr{transition:background .1s}
.dtbl tbody tr:hover td{background:rgba(255,255,255,.022)}
[data-theme="light"] .dtbl tbody tr:hover td{background:rgba(0,0,0,.02)}
.prod-cell{display:flex;align-items:center;gap:10px}
.prod-thumb{width:34px;height:34px;border-radius:9px;background:var(--bg3);border:1px solid var(--br);display:flex;align-items:center;justify-content:center;font-size:.76rem;font-weight:800;color:var(--t2);flex-shrink:0}
.prod-name{font-size:.83rem;font-weight:700;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px}
.prod-meta{font-size:.68rem;color:var(--t2);margin-top:2px}
.num{font-variant-numeric:tabular-nums;font-weight:700}
.num.gr{color:var(--gr)}.num.go{color:var(--go)}.num.re{color:var(--re)}.num.nt{color:var(--t3)}
.dim{color:var(--t2)}

/* Stock level bar */
.stk-wrap{min-width:100px}
.stk-nums{display:flex;align-items:baseline;gap:3px;margin-bottom:4px}
.stk-main{font-size:.88rem;font-weight:800;font-variant-numeric:tabular-nums}
.stk-sub{font-size:.67rem;color:var(--t2)}
.stk-bar{height:4px;background:var(--br);border-radius:2px;overflow:hidden;width:90px}
.stk-fill{height:100%;border-radius:2px;transition:width .6s cubic-bezier(.16,1,.3,1)}

/* ── PILLS ───────────────────────────────────────────────────── */
.pill{display:inline-flex;align-items:center;padding:3px 9px;border-radius:100px;font-size:.67rem;font-weight:700;white-space:nowrap}
.pill.ok  {background:var(--grd);color:var(--gr)}
.pill.warn{background:var(--god);color:var(--go)}
.pill.err {background:var(--red);color:var(--re)}
.pill.info{background:var(--ag); color:var(--ac)}
.pill.pu  {background:var(--pud);color:var(--pu)}
.pill.nt  {background:var(--bg3);color:var(--t3)}

/* ── ACTION BUTTONS ──────────────────────────────────────────── */
.actions{display:flex;align-items:center;gap:4px}
.act-btn{width:29px;height:29px;border-radius:7px;border:1px solid var(--br);background:transparent;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .15s;color:var(--t2)}
.act-btn:hover{border-color:var(--br2);color:var(--tx)}
.act-btn.adj:hover{background:var(--ag);border-color:rgba(79,142,247,.3);color:var(--ac)}
.act-btn.hist:hover{background:var(--pud);border-color:rgba(167,139,250,.3);color:var(--pu)}
.act-btn svg{width:13px;height:13px}

/* ── BUTTONS ─────────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:10px;font-family:inherit;font-size:.8rem;font-weight:700;cursor:pointer;border:none;transition:all .2s;text-decoration:none}
.btn-primary{background:var(--ac);color:#fff;box-shadow:0 4px 14px var(--ag)}
.btn-primary:hover{background:var(--ac2);transform:translateY(-1px)}
.btn-ghost{background:var(--bg3);border:1px solid var(--br);color:var(--t2)}
.btn-ghost:hover{border-color:var(--br2);color:var(--tx)}
.btn-warn{background:var(--god);border:1px solid rgba(245,166,35,.25);color:var(--go)}
.btn-warn:hover{background:rgba(245,166,35,.2)}
.btn-sm{padding:6px 12px;font-size:.75rem;border-radius:8px}
.btn svg{width:15px;height:15px}

/* ── FLASH ───────────────────────────────────────────────────── */
.flash{display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:11px;font-size:.82rem;font-weight:500;animation:fadeUp .4s cubic-bezier(.16,1,.3,1)}
.flash.ok  {background:var(--grd);border:1px solid rgba(52,211,153,.2);color:var(--gr)}
.flash.warn{background:var(--god);border:1px solid rgba(245,166,35,.2);color:var(--go)}
.flash.err {background:var(--red);border:1px solid rgba(248,113,113,.2);color:var(--re)}
.flash svg{width:15px;height:15px;flex-shrink:0}

/* ── PAGINATION ──────────────────────────────────────────────── */
.pager{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-top:1px solid var(--br)}
.pager-info{font-size:.75rem;color:var(--t2)}
.pager-btns{display:flex;gap:4px}
.pg-btn{min-width:32px;height:32px;padding:0 8px;border-radius:8px;border:1px solid var(--br);background:var(--bg3);color:var(--t2);font-family:inherit;font-size:.78rem;font-weight:600;cursor:pointer;transition:all .15s;text-decoration:none;display:flex;align-items:center;justify-content:center}
.pg-btn:hover{border-color:var(--br2);color:var(--tx)}
.pg-btn.on{background:var(--ac);border-color:var(--ac);color:#fff}

/* ── EMPTY ───────────────────────────────────────────────────── */
.empty-state{padding:52px 20px;text-align:center}
.empty-icon{width:50px;height:50px;border-radius:13px;background:var(--bg3);border:1px solid var(--br);margin:0 auto 14px;display:flex;align-items:center;justify-content:center}
.empty-icon svg{width:22px;height:22px;color:var(--t3)}
.empty-title{font-size:.95rem;font-weight:700;color:var(--tx);margin-bottom:4px}
.empty-sub{font-size:.78rem;color:var(--t2)}

/* ── SIDE PANELS ─────────────────────────────────────────────── */
.side-col{display:flex;flex-direction:column;gap:16px}
.item-row{display:flex;align-items:center;gap:10px;padding:10px 20px;border-bottom:1px solid var(--br)}
.item-row:last-child{border-bottom:none}
.item-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.item-name{flex:1;font-size:.79rem;font-weight:600;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.item-qty{font-size:.76rem;font-weight:800;font-variant-numeric:tabular-nums}
.item-meta{font-size:.67rem;color:var(--t2);margin-top:2px}
.txn-row{display:flex;align-items:flex-start;gap:10px;padding:11px 20px;border-bottom:1px solid var(--br)}
.txn-row:last-child{border-bottom:none}
.txn-icon{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.85rem;font-weight:800;line-height:1}
.txn-body{flex:1;min-width:0}
.txn-name{font-size:.78rem;font-weight:600;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.txn-sub{font-size:.66rem;color:var(--t2);margin-top:2px}
.txn-qty{font-size:.8rem;font-weight:800;font-variant-numeric:tabular-nums;flex-shrink:0}

/* ── ALERT BANNER ────────────────────────────────────────────── */
.alert-banner{display:flex;align-items:center;gap:12px;padding:13px 18px;border-radius:12px;background:var(--red);border:1px solid rgba(248,113,113,.25);animation:fadeUp .3s cubic-bezier(.16,1,.3,1)}
.alert-banner.warn{background:var(--god);border-color:rgba(245,166,35,.25)}
.alert-banner svg{width:18px;height:18px;color:var(--re);flex-shrink:0}
.alert-banner.warn svg{color:var(--go)}
.alert-txt{flex:1;font-size:.82rem;font-weight:600;color:var(--re)}
.alert-banner.warn .alert-txt{color:var(--go)}

/* ── MODAL ───────────────────────────────────────────────────── */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:200;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .25s;backdrop-filter:blur(4px)}
.overlay.open{opacity:1;pointer-events:all}
.modal{background:var(--bg2);border:1px solid var(--br2);border-radius:18px;width:100%;max-width:480px;max-height:90vh;overflow-y:auto;box-shadow:var(--sh2);transform:translateY(20px) scale(.97);transition:transform .28s cubic-bezier(.16,1,.3,1)}
.modal.lg{max-width:680px}
.overlay.open .modal{transform:none}
.modal-head{display:flex;align-items:center;justify-content:space-between;padding:20px 24px;border-bottom:1px solid var(--br);position:sticky;top:0;background:var(--bg2);z-index:1}
.modal-title{font-size:1rem;font-weight:800;color:var(--tx)}
.modal-sub{font-size:.72rem;color:var(--t2);margin-top:2px}
.close-btn{width:30px;height:30px;border-radius:8px;border:1px solid var(--br);background:transparent;color:var(--t2);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s;font-size:1rem}
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
.ftextarea{resize:vertical;min-height:60px}
.type-btns{display:flex;gap:6px}
.type-btn{flex:1;padding:10px 8px;border-radius:9px;border:1px solid var(--br);background:var(--bg3);font-family:inherit;font-size:.78rem;font-weight:700;cursor:pointer;transition:all .15s;text-align:center;color:var(--t2)}
.type-btn:hover{border-color:var(--br2);color:var(--tx)}
.type-btn.on.add{background:var(--grd);border-color:rgba(52,211,153,.3);color:var(--gr)}
.type-btn.on.sub{background:var(--red);border-color:rgba(248,113,113,.3);color:var(--re)}
.type-btn.on.set{background:var(--ag);border-color:rgba(79,142,247,.3);color:var(--ac)}
.stock-preview{background:var(--bg3);border:1px solid var(--br);border-radius:10px;padding:12px 14px;display:flex;align-items:center;justify-content:space-between;margin-top:8px}
.sp-label{font-size:.72rem;color:var(--t2)}
.sp-val{font-size:1rem;font-weight:800;color:var(--tx)}
.sp-arrow{font-size:.85rem;color:var(--t3);margin:0 6px}
.sp-new{font-size:1rem;font-weight:800}
.fsection{font-size:.68rem;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.1em;padding:10px 0 8px;border-bottom:1px solid var(--br);margin-bottom:12px}

/* History table in modal */
.htbl{width:100%;border-collapse:collapse}
.htbl th{font-size:.63rem;color:var(--t3);text-transform:uppercase;letter-spacing:.09em;padding:8px 12px;text-align:left;border-bottom:1px solid var(--br);font-weight:700}
.htbl td{padding:10px 12px;font-size:.79rem;border-bottom:1px solid var(--br);vertical-align:middle}
.htbl tr:last-child td{border-bottom:none}
.htbl-icon{width:26px;height:26px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:800;flex-shrink:0}

/* ── RESPONSIVE ──────────────────────────────────────────────── */
@media(max-width:1280px){.stats6{grid-template-columns:repeat(3,1fr)}}
@media(max-width:1100px){.layout{grid-template-columns:1fr}.side-col{display:grid;grid-template-columns:1fr 1fr}}
@media(max-width:900px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:none}.main{margin-left:0}.mob-btn{display:block}.sbar{display:none}.frow{grid-template-columns:1fr}}
@media(max-width:640px){.content{padding:16px}.stats6{grid-template-columns:repeat(2,1fr)}.page-header{flex-direction:column}.side-col{grid-template-columns:1fr}}
</style>
</head>
<body>

<!-- SIDEBAR -->
<?php include 'sidebar.php'; ?>

<!-- MAIN -->
<div class="main">
  <!-- TOPNAV -->
  <?php include 'topnav.php'; ?>

  <div class="content">

    <!-- Page Header -->
    <div class="page-header">
      <div>
        <div class="ph-title">Stock Management</div>
        <div class="ph-sub"><?= number_format($statTotal) ?> products tracked &nbsp;·&nbsp; <?= number_format($statStockQty) ?> total units &nbsp;·&nbsp; Value: <strong style="color:var(--gr)"><?= fmt($statValue) ?></strong></div>
      </div>
      <div class="ph-actions">
        <?php if ($statLow > 0 && $canEdit): ?>
        <form method="POST" style="display:inline">
          <input type="hidden" name="action" value="bulk_reorder">
          <button type="submit" class="btn btn-warn btn-sm">
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 1v6M8 1l-3 3M8 1l3 3"/><path d="M1 10v3a1 1 0 001 1h12a1 1 0 001-1v-3"/></svg>
            Trigger Reorder (<?= $statLow ?>)
          </button>
        </form>
        <?php endif; ?>
        <a href="products.php" class="btn btn-ghost btn-sm">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 4l6-2 6 2v8l-6 2-6-2V4z"/></svg>
          Products
        </a>
      </div>
    </div>

    <!-- Flash -->
    <?php if ($flash): ?>
    <div class="flash <?= $flashType ?>">
      <?php if ($flashType==='ok'): ?>
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8" cy="8" r="7"/><path d="M5 8l2 2 4-4"/></svg>
      <?php else: ?>
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 1L1 13h14z"/><path d="M8 6v3M8 11v1"/></svg>
      <?php endif; ?>
      <span><?= $flash ?></span>
    </div>
    <?php endif; ?>

    <!-- Alert banner -->
    <?php if ($statOut > 0): ?>
    <div class="alert-banner">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 1L1 13h14z"/><path d="M8 6v3M8 11v1"/></svg>
      <div class="alert-txt"><strong><?= $statOut ?> product<?= $statOut!=1?'s':'' ?></strong> are completely out of stock and need immediate attention.</div>
      <a href="stock.php?stock=out" class="btn btn-sm" style="background:rgba(248,113,113,.2);border:1px solid rgba(248,113,113,.3);color:var(--re);padding:6px 12px;font-size:.72rem">View →</a>
    </div>
    <?php elseif ($statLow > 0): ?>
    <div class="alert-banner warn">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 1L1 13h14z"/><path d="M8 6v3M8 11v1"/></svg>
      <div class="alert-txt"><strong><?= $statLow ?> product<?= $statLow!=1?'s':'' ?></strong> are below their reorder level and should be restocked soon.</div>
      <a href="stock.php?stock=low" class="btn btn-sm" style="background:rgba(245,166,35,.15);border:1px solid rgba(245,166,35,.3);color:var(--go);padding:6px 12px;font-size:.72rem">View →</a>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats6">
      <div class="scard" style="--dl:.00s">
        <div class="sc-top">
          <div class="sc-icon" style="background:var(--ag)">
            <svg viewBox="0 0 16 16" fill="none" stroke="var(--ac)" stroke-width="1.8"><path d="M2 4l6-2 6 2v8l-6 2-6-2V4z"/><path d="M8 2v12"/></svg>
          </div>
          <span class="sc-trend nt">SKUs</span>
        </div>
        <div class="sc-val"><?= number_format($statTotal) ?></div>
        <div class="sc-lbl">Total Products</div>
      </div>
      <div class="scard" style="--dl:.05s">
        <div class="sc-top">
          <div class="sc-icon" style="background:var(--pud)">
            <svg viewBox="0 0 16 16" fill="none" stroke="var(--pu)" stroke-width="1.8"><rect x="2" y="6" width="12" height="8" rx="1"/><path d="M5 6V4a3 3 0 016 0v2"/></svg>
          </div>
          <span class="sc-trend nt">Units</span>
        </div>
        <div class="sc-val"><?= number_format($statStockQty) ?></div>
        <div class="sc-lbl">Total Stock Units</div>
      </div>
      <div class="scard" style="--dl:.10s">
        <div class="sc-top">
          <div class="sc-icon" style="background:var(--grd)">
            <svg viewBox="0 0 16 16" fill="none" stroke="var(--gr)" stroke-width="1.8"><path d="M3 10l3 3 4-5 4 4"/></svg>
          </div>
          <span class="sc-trend ok">Value</span>
        </div>
        <div class="sc-val"><?= fmt($statValue) ?></div>
        <div class="sc-lbl">Stock Value</div>
      </div>
      <div class="scard" style="--dl:.15s">
        <div class="sc-top">
          <div class="sc-icon" style="background:var(--god)">
            <svg viewBox="0 0 16 16" fill="none" stroke="var(--go)" stroke-width="1.8"><path d="M8 1L1 13h14z"/><path d="M8 6v3M8 11v1"/></svg>
          </div>
          <span class="sc-trend warn"><?= $statLow ?> items</span>
        </div>
        <div class="sc-val" style="color:<?= $statLow>0?'var(--go)':'var(--tx)' ?>"><?= $statLow ?></div>
        <div class="sc-lbl">Low Stock Alerts</div>
      </div>
      <div class="scard" style="--dl:.20s">
        <div class="sc-top">
          <div class="sc-icon" style="background:var(--red)">
            <svg viewBox="0 0 16 16" fill="none" stroke="var(--re)" stroke-width="1.8"><path d="M8 2v4M8 10v4M3 7l-2 1 2 1M13 7l2 1-2 1"/></svg>
          </div>
          <span class="sc-trend err"><?= $statOut ?> items</span>
        </div>
        <div class="sc-val" style="color:<?= $statOut>0?'var(--re)':'var(--tx)' ?>"><?= $statOut ?></div>
        <div class="sc-lbl">Out of Stock</div>
      </div>
      <div class="scard" style="--dl:.25s">
        <div class="sc-top">
          <div class="sc-icon" style="background:var(--ted)">
            <svg viewBox="0 0 16 16" fill="none" stroke="var(--te)" stroke-width="1.8"><path d="M1 8h14M8 1v14"/></svg>
          </div>
          <span class="sc-trend nt">Reserved</span>
        </div>
        <div class="sc-val"><?= number_format($statReserved) ?></div>
        <div class="sc-lbl">Reserved Units</div>
      </div>
    </div>

    <!-- Main layout -->
    <div class="layout">

      <!-- ── Left: Inventory table ── -->
      <div class="panel">

        <!-- Toolbar -->
        <form method="GET" action="stock.php" id="filterForm">
          <div class="toolbar">
            <div class="searchbox">
              <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="7" cy="7" r="4.5"/><path d="M11 11l3 3"/></svg>
              <input type="text" name="q" placeholder="Name, barcode…" value="<?= htmlspecialchars($search) ?>" id="searchInp">
            </div>
            <select name="cat" class="sel" onchange="this.form.submit()">
              <option value="">All Categories</option>
              <?php if ($catOpts): while ($co=$catOpts->fetch_assoc()): ?>
              <option value="<?= $co['id'] ?>" <?= $catF==$co['id']?'selected':'' ?>><?= htmlspecialchars($co['name']) ?></option>
              <?php endwhile; endif; ?>
            </select>
            <div class="stock-chips">
              <?php
                $chips = [
                  [''   ,'All',   ''],
                  ['low','Low Stock','warn'],
                  ['out','Out of Stock','err'],
                  ['ok' ,'In Stock','ok'],
                ];
                foreach ($chips as [$v,$lbl,$cls]):
                  $on = ($stockF===$v) ? 'on '.$cls : '';
              ?>
              <a class="chip <?= $on ?>" href="stock.php?<?= http_build_query(array_merge($_GET,['stock'=>$v,'page'=>1])) ?>"><?= $lbl ?></a>
              <?php endforeach; ?>
            </div>
            <div class="toolbar-right">
              <span class="count-lbl"><?= number_format($total) ?> items</span>
              <?php if ($search||$catF||$stockF): ?>
              <a href="stock.php" class="btn btn-ghost btn-sm">Clear</a>
              <?php endif; ?>
            </div>
          </div>
        </form>

        <!-- Table -->
        <?php if ($rows && $rows->num_rows > 0): ?>
        <div style="overflow-x:auto">
          <table class="dtbl">
            <thead>
              <tr>
                <th>Product</th>
                <th>Category</th>
                <th>Stock Level</th>
                <th>Reserved</th>
                <th>Available</th>
                <th>Cost</th>
                <th>Stock Value</th>
                <th>Last Updated</th>
                <th>Status</th>
                <?php if ($canEdit): ?><th>Actions</th><?php endif; ?>
              </tr>
            </thead>
            <tbody>
            <?php while ($r = $rows->fetch_assoc()):
              $stock   = (int)$r['qty_stock'];
              $res     = (int)$r['qty_reserved'];
              $avail   = (int)$r['qty_avail'];
              $min     = (int)$r['min_stock_level'];
              $max     = (int)$r['max_stock_level'];
              $reorder = (int)$r['reorder_level'];
              $maxBar  = max($max, $stock, 1);
              $pct     = min(round($stock / $maxBar * 100), 100);
              $pct     = max($pct, 2);
              if ($avail <= 0)         { $sColor='var(--re)'; $sStatus='err'; $sLabel='Out'; }
              elseif ($avail<=$reorder){ $sColor='var(--re)'; $sStatus='err'; $sLabel='Critical'; }
              elseif ($avail<=$min)    { $sColor='var(--go)'; $sStatus='warn'; $sLabel='Low'; }
              else                     { $sColor='var(--gr)'; $sStatus='ok'; $sLabel='OK'; }
              $stockVal = round($r['cost_price'] * $stock, 2);
            ?>
            <tr>
              <td>
                <div class="prod-cell">
                  <div class="prod-thumb" style="color:<?= $sColor ?>"><?= strtoupper(substr($r['name'],0,2)) ?></div>
                  <div>
                    <div class="prod-name"><?= htmlspecialchars($r['name']) ?></div>
                    <div class="prod-meta">
                      <?php if ($r['barcode']): ?><span style="font-family:monospace"><?= htmlspecialchars($r['barcode']) ?></span><?php endif; ?>
                      <?php if ($r['brand']): ?> · <?= htmlspecialchars($r['brand']) ?><?php endif; ?>
                    </div>
                  </div>
                </div>
              </td>
              <td>
                <?php if ($r['cat_name']): ?>
                  <span class="pill info"><?= htmlspecialchars($r['cat_name']) ?></span>
                <?php else: ?><span class="dim">—</span><?php endif; ?>
              </td>
              <td>
                <div class="stk-wrap">
                  <div class="stk-nums">
                    <span class="stk-main" style="color:<?= $sColor ?>"><?= number_format($stock) ?></span>
                    <span class="stk-sub"><?= htmlspecialchars($r['unit_of_measure']??'pc') ?></span>
                  </div>
                  <div class="stk-bar">
                    <div class="stk-fill" style="width:<?= $pct ?>%;background:<?= $sColor ?>"></div>
                  </div>
                </div>
              </td>
              <td>
                <?php if ($res > 0): ?>
                  <span class="num" style="color:var(--pu)"><?= number_format($res) ?></span>
                <?php else: ?><span class="dim">0</span><?php endif; ?>
              </td>
              <td><span class="num <?= $sStatus ==='ok'?'gr':($sStatus==='warn'?'go':'re') ?>"><?= number_format($avail) ?></span></td>
              <td><span class="num dim"><?= fmt((float)$r['cost_price']) ?></span></td>
              <td><span class="num"><?= fmt($stockVal) ?></span></td>
              <td><span class="dim" style="font-size:.72rem"><?= $r['last_updated'] ? timeAgo($r['last_updated']) : '—' ?></span></td>
              <td><span class="pill <?= $sStatus ?>"><?= $sLabel ?></span></td>
              <?php if ($canEdit): ?>
              <td>
                <div class="actions">
                  <button class="act-btn adj" title="Adjust stock"
onclick='openAdj(<?= json_encode([
    "id" => $r['id'],
    "name" => $r['name'],
    "stock" => $stock,
    "avail" => $avail,
    "res" => $res,
    "min" => $min,
    "max" => $max,
    "reorder" => $reorder,
    "uom" => $r['unit_of_measure'] ?? "pc"
]) ?>)'>
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 1v14M1 8h14"/></svg>
                  </button>
                  <button class="act-btn hist" title="View history"
                    onclick="loadHistory(<?= $r['id'] ?>,'<?= htmlspecialchars($r['name'],ENT_QUOTES) ?>')">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 2"/></svg>
                  </button>
                </div>
              </td>
              <?php endif; ?>
            </tr>
            <?php endwhile; ?>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <?php if ($pages > 1): ?>
        <div class="pager">
          <span class="pager-info">Showing <?= $offset+1 ?>–<?= min($offset+$perPage,$total) ?> of <?= $total ?></span>
          <div class="pager-btns">
            <?php if ($page>1): ?><a class="pg-btn" href="<?= $qs ?>page=<?= $page-1 ?>">‹</a><?php endif; ?>
            <?php for ($p=max(1,$page-2);$p<=min($pages,$page+2);$p++): ?>
              <a class="pg-btn <?= $p===$page?'on':'' ?>" href="<?= $qs ?>page=<?= $p ?>"><?= $p ?></a>
            <?php endfor; ?>
            <?php if ($page<$pages): ?><a class="pg-btn" href="<?= $qs ?>page=<?= $page+1 ?>">›</a><?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="empty-state">
          <div class="empty-icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="6" width="12" height="8" rx="1"/><path d="M5 6V4a3 3 0 016 0v2"/></svg></div>
          <div class="empty-title">No stock records found</div>
          <div class="empty-sub"><?= ($search||$catF||$stockF) ? 'Try adjusting your filters.' : 'Add products to start tracking stock.' ?></div>
        </div>
        <?php endif; ?>
      </div>

      <!-- ── Right: Side panels ── -->
      <div class="side-col">

        <!-- Low Stock Panel -->
        <div class="panel">
          <div class="phead">
            <div>
              <div class="ptitle">⚠ Low Stock</div>
              <div class="psub"><?= $statLow ?> items need restocking</div>
            </div>
            <a href="stock.php?stock=low" class="plink">View all →</a>
          </div>
          <?php if ($low_items && $low_items->num_rows > 0):
            while ($li = $low_items->fetch_assoc()):
              $lqty   = (int)$li['quantity_available'];
              $lmin   = (int)$li['min_stock_level'];
              $lreorder=(int)$li['reorder_level'];
              $lcolor = $lqty <= 0 ? 'var(--re)' : ($lqty <= $lreorder ? 'var(--re)' : 'var(--go)');
          ?>
          <div class="item-row">
            <div class="item-dot" style="background:<?= $lcolor ?>"></div>
            <div style="flex:1;min-width:0">
              <div class="item-name"><?= htmlspecialchars($li['product_name']) ?></div>
              <div class="item-meta">Min: <?= $lmin ?> · Reorder: <?= $lreorder ?></div>
            </div>
            <div>
              <div class="item-qty" style="color:<?= $lcolor ?>"><?= $lqty ?></div>
              <div style="font-size:.65rem;color:var(--t3);text-align:right">left</div>
            </div>
          </div>
          <?php endwhile;
          else: ?>
          <div style="padding:24px;text-align:center;color:var(--gr);font-size:.8rem;font-weight:600">✓ All stock levels healthy</div>
          <?php endif; ?>
        </div>

        <!-- Recent Transactions Panel -->
        <div class="panel">
          <div class="phead">
            <div>
              <div class="ptitle">Recent Transactions</div>
              <div class="psub">Last inventory movements</div>
            </div>
          </div>
          <?php if ($recent_txns && $recent_txns->num_rows > 0):
            while ($txn = $recent_txns->fetch_assoc()):
              $tc = $txnConfig[$txn['transaction_type']] ?? ['var(--bg3)','var(--t2)','±','Move'];
              [$tbg,$tclr,$tsym,$tlbl] = $tc;
              $tqty = (int)$txn['quantity'];
              $isPos = $tqty >= 0;
          ?>
          <div class="txn-row">
            <div class="txn-icon" style="background:<?= $tbg ?>;color:<?= $tclr ?>"><?= $tsym ?></div>
            <div class="txn-body">
              <div class="txn-name"><?= htmlspecialchars($txn['prod_name']) ?></div>
              <div class="txn-sub">
                <span class="pill <?= $tlbl==='Purchase'||$tlbl==='Return'?'ok':($tlbl==='Damage'?'err':($tlbl==='Sale'?'info':'warn')) ?>" style="font-size:.6rem"><?= $tlbl ?></span>
                &nbsp;<?= htmlspecialchars($txn['done_by']??'System') ?> · <?= timeAgo($txn['created_at']) ?>
              </div>
            </div>
            <div class="txn-qty" style="color:<?= $isPos?'var(--gr)':'var(--re)' ?>">
              <?= $isPos?'+':'' ?><?= number_format($tqty) ?>
            </div>
          </div>
          <?php endwhile;
          else: ?>
          <div style="padding:24px;text-align:center;color:var(--t3);font-size:.8rem">No transactions yet.</div>
          <?php endif; ?>
        </div>

      </div><!-- /side-col -->
    </div><!-- /layout -->

  </div><!-- /content -->
</div><!-- /main -->


<!-- ════════════════════════════════════════════════════════════
     ADJUST STOCK MODAL
════════════════════════════════════════════════════════════ -->
<?php if ($canEdit): ?>
<div class="overlay" id="adjOverlay" onclick="closeModal(event,this)">
  <div class="modal">
    <div class="modal-head">
      <div>
        <div class="modal-title" id="adjTitle">Adjust Stock</div>
        <div class="modal-sub" id="adjSub">Update inventory quantity</div>
      </div>
      <button class="close-btn" onclick="closeAll()">✕</button>
    </div>
    <form method="POST" action="stock.php" id="adjForm">
      <input type="hidden" name="action" value="adjust_stock">
      <input type="hidden" name="prod_id" id="adjProdId" value="">
      <input type="hidden" name="adj_type" id="adjTypeInput" value="add">
      <div class="modal-body">

        <!-- Type selector -->
        <div class="fgroup" style="margin-bottom:14px">
          <label class="flabel">Adjustment Type <span class="req">*</span></label>
          <div class="type-btns">
            <button type="button" class="type-btn on add" id="btnAdd"   onclick="setType('add')">
              ➕ Add Stock
            </button>
            <button type="button" class="type-btn sub" id="btnSub"   onclick="setType('subtract')">
              ➖ Remove Stock
            </button>
            <button type="button" class="type-btn set" id="btnSet"   onclick="setType('set')">
              🎯 Set Exact
            </button>
          </div>
        </div>

        <!-- Quantity + preview -->
        <div class="frow">
          <div class="fgroup">
            <label class="flabel" id="qtyLabel">Quantity to Add <span class="req">*</span></label>
            <input type="number" name="qty" id="adjQty" class="finput" min="1" placeholder="0" required oninput="updatePreview()">
          </div>
          <div class="fgroup">
            <label class="flabel">Unit of Measure</label>
            <input type="text" id="adjUom" class="finput" readonly style="background:var(--bg4);color:var(--t2)">
          </div>
        </div>

        <!-- Stock preview -->
        <div class="stock-preview" id="stockPreview">
          <div>
            <div class="sp-label">Current Stock</div>
            <div class="sp-val" id="spCur">—</div>
          </div>
          <span class="sp-arrow">→</span>
          <div>
            <div class="sp-label">New Stock</div>
            <div class="sp-new" id="spNew" style="color:var(--ac)">—</div>
          </div>
          <div>
            <div class="sp-label">Available</div>
            <div class="sp-new" id="spAvail" style="color:var(--gr)">—</div>
          </div>
        </div>

        <!-- Reason + notes -->
        <div class="fsection">Details</div>
        <div class="frow full">
          <div class="fgroup">
            <label class="flabel">Reason <span class="req">*</span></label>
            <select name="reason" id="adjReason" class="fselect" required>
              <option value="">— Select reason —</option>
              <optgroup label="Add Stock">
                <option value="Purchase received">Purchase received</option>
                <option value="Customer return">Customer return</option>
                <option value="Transfer in">Transfer in</option>
                <option value="Initial count">Initial count</option>
              </optgroup>
              <optgroup label="Remove Stock">
                <option value="Damaged goods">Damaged goods</option>
                <option value="Expired goods">Expired goods</option>
                <option value="Transfer out">Transfer out</option>
                <option value="Theft/Loss">Theft / Loss</option>
              </optgroup>
              <optgroup label="Adjustment">
                <option value="Stock count correction">Stock count correction</option>
                <option value="System correction">System correction</option>
                <option value="Other">Other</option>
              </optgroup>
            </select>
          </div>
        </div>
        <div class="frow">
          <div class="fgroup">
            <label class="flabel">Reference / PO Number</label>
            <input type="text" name="reference" class="finput" placeholder="e.g. PO-0042">
          </div>
          <div class="fgroup">
            <label class="flabel">Notes</label>
            <input type="text" name="notes" class="finput" placeholder="Optional note…">
          </div>
        </div>

        <!-- Thresholds info -->
        <div id="threshInfo" style="background:var(--bg3);border:1px solid var(--br);border-radius:10px;padding:11px 14px;margin-top:8px;display:flex;gap:18px">
          <div><div style="font-size:.65rem;color:var(--t3);font-weight:600;text-transform:uppercase;letter-spacing:.08em">Min Level</div><div style="font-size:.88rem;font-weight:800;color:var(--tx);margin-top:3px" id="infoMin">—</div></div>
          <div><div style="font-size:.65rem;color:var(--t3);font-weight:600;text-transform:uppercase;letter-spacing:.08em">Reorder At</div><div style="font-size:.88rem;font-weight:800;color:var(--go);margin-top:3px" id="infoReorder">—</div></div>
          <div><div style="font-size:.65rem;color:var(--t3);font-weight:600;text-transform:uppercase;letter-spacing:.08em">Max Level</div><div style="font-size:.88rem;font-weight:800;color:var(--tx);margin-top:3px" id="infoMax">—</div></div>
        </div>

      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="closeAll()">Cancel</button>
        <button type="submit" class="btn btn-primary" id="adjSubmit">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M2 8l4 4 8-8"/></svg>
          Apply Adjustment
        </button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════
     HISTORY MODAL
════════════════════════════════════════════════════════════ -->
<div class="overlay" id="histOverlay" onclick="closeModal(event,this)">
  <div class="modal lg">
    <div class="modal-head">
      <div>
        <div class="modal-title" id="histTitle">Transaction History</div>
        <div class="modal-sub" id="histSub">All inventory movements</div>
      </div>
      <button class="close-btn" onclick="closeAll()">✕</button>
    </div>
    <div class="modal-body" style="padding:0">
      <div id="histContent" style="padding:20px;text-align:center;color:var(--t3);font-size:.82rem">Loading…</div>
    </div>
  </div>
</div>


<script>
// ── Theme ─────────────────────────────────────────────────────
const html=document.documentElement,thi=document.getElementById('thi');
function applyTheme(t){html.dataset.theme=t;thi.textContent=t==='dark'?'☀️':'🌙';}
document.getElementById('thm').addEventListener('click',()=>{
  const nt=html.dataset.theme==='dark'?'light':'dark';
  applyTheme(nt);localStorage.setItem('pos_theme',nt);
});
(()=>{const sv=localStorage.getItem('pos_theme');if(sv)applyTheme(sv);})();

// ── Sidebar mobile ─────────────────────────────────────────────
document.addEventListener('click',e=>{
  const sb=document.getElementById('sidebar');
  if(window.innerWidth<=900&&sb&&!sb.contains(e.target)&&!e.target.closest('.mob-btn'))
    sb.classList.remove('open');
});

// ── Modal helpers ──────────────────────────────────────────────
function closeModal(e,el){if(e.target===el)closeAll();}
function closeAll(){document.querySelectorAll('.overlay').forEach(o=>o.classList.remove('open'));document.body.style.overflow='';}
function openOverlay(id){document.getElementById(id).classList.add('open');document.body.style.overflow='hidden';}
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeAll();});

// ── Adjust Stock modal ─────────────────────────────────────────
let adjData = {};
function openAdj(data) {
  adjData = data;
  document.getElementById('adjTitle').textContent = 'Adjust Stock — ' + data.name;
  document.getElementById('adjSub').textContent   = 'Current: ' + data.stock + ' ' + data.uom + ' in stock';
  document.getElementById('adjProdId').value = data.id;
  document.getElementById('adjQty').value   = '';
  document.getElementById('adjUom').value   = data.uom;
  document.getElementById('infoMin').textContent    = data.min;
  document.getElementById('infoReorder').textContent= data.reorder;
  document.getElementById('infoMax').textContent    = data.max;
  setType('add');
  updatePreview();
  openOverlay('adjOverlay');
  setTimeout(()=>document.getElementById('adjQty').focus(),200);
}

function setType(t) {
  document.getElementById('adjTypeInput').value = t;
  ['add','sub','set'].forEach(x=>{
    const b=document.getElementById('btn'+x.charAt(0).toUpperCase()+x.slice(1));
    b.className='type-btn '+x+(t===x?' on':'');
  });
  const labels={'add':'Quantity to Add','subtract':'Quantity to Remove','set':'Set Stock To'};
  document.getElementById('qtyLabel').innerHTML = (labels[t]||'Quantity') + ' <span class="req">*</span>';
  updatePreview();
}

function updatePreview() {
  const t   = document.getElementById('adjTypeInput').value;
  const qty = parseInt(document.getElementById('adjQty').value)||0;
  const cur = adjData.stock||0;
  const res = adjData.res||0;
  let nw;
  if      (t==='add')      nw = cur + qty;
  else if (t==='subtract') nw = Math.max(0, cur - qty);
  else                     nw = qty;
  const navail = Math.max(0, nw - res);
  document.getElementById('spCur').textContent   = cur;
  document.getElementById('spNew').textContent   = nw;
  document.getElementById('spAvail').textContent = navail;
  const color = navail<=0?'var(--re)':navail<=(adjData.reorder||0)?'var(--go)':'var(--gr)';
  document.getElementById('spNew').style.color   = nw>=cur?'var(--gr)':'var(--re)';
  document.getElementById('spAvail').style.color = color;
}

// ── History modal (AJAX) ───────────────────────────────────────
function loadHistory(pid, name) {
  document.getElementById('histTitle').textContent = 'Transaction History';
  document.getElementById('histSub').textContent   = name;
  document.getElementById('histContent').innerHTML = '<div style="padding:32px;text-align:center;color:var(--t3)">Loading…</div>';
  openOverlay('histOverlay');

  fetch(`stock_history.php?pid=${pid}`)
    .then(r=>r.text())
    .then(html=>{document.getElementById('histContent').innerHTML=html;})
    .catch(()=>{
      // Inline fallback — load via hidden form
      document.getElementById('histContent').innerHTML = `
        <div style="padding:20px">
          <div style="text-align:center;color:var(--t3);font-size:.82rem;padding:20px 0">
            History endpoint not available. Check <code>stock_history.php</code>.
          </div>
          <div style="margin-top:10px;text-align:center">
            <a href="stock.php?history=${pid}" class="btn btn-primary btn-sm">View Full History Page →</a>
          </div>
        </div>`;
    });
}

// ── Flash auto-dismiss ─────────────────────────────────────────
setTimeout(()=>{
  const f=document.querySelector('.flash');
  if(f){f.style.transition='opacity .5s';f.style.opacity='0';setTimeout(()=>f.remove(),500);}
},4500);

// ── Search debounce ────────────────────────────────────────────
let st;
document.getElementById('searchInp')?.addEventListener('input',function(){
  clearTimeout(st);
  st=setTimeout(()=>document.getElementById('filterForm').submit(),600);
});
</script>
</body>
</html>