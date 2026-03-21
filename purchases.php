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
function fmtFull($v) { return '$'.number_format($v,2); }
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
function statusPill($s) {
    $map = [
        'paid'      => ['ok',   'Paid'],
        'partial'   => ['warn', 'Partial'],
        'unpaid'    => ['err',  'Unpaid'],
    ];
    [$cl,$lb] = $map[$s] ?? ['nt', ucfirst($s)];
    return '<span class="pill '.$cl.'">'.$lb.'</span>';
}

$flash = $flashType = '';

// ═══════════════════════════════════════════════════════════════
// POST HANDLERS
// ═══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $action = $_POST['action'] ?? '';

    // ─── ADD PURCHASE ─────────────────────────────────────────
    if ($action === 'add') {
        $supplier   = trim($_POST['supplier_name'] ?? '');
        $supp_phone = trim($_POST['supplier_phone'] ?? '') ?: null;
        $pur_date   = $_POST['purchase_date'] ?? date('Y-m-d');
        $subtotal   = floatval($_POST['subtotal'] ?? 0);
        $discount   = floatval($_POST['discount'] ?? 0);
        $total      = floatval($_POST['total'] ?? 0);
        $paid       = floatval($_POST['paid_amount'] ?? 0);
        $notes      = trim($_POST['notes'] ?? '') ?: null;
        $items      = $_POST['items'] ?? []; // array of product_id, qty, cost, expiry

        if (!$supplier || empty($items)) {
            $flash = "Supplier and at least one item are required."; $flashType = 'err';
            header("Location: purchases.php?flash=".urlencode($flash)."&ft=$flashType"); exit;
        }

        $conn->begin_transaction();
        try {
            // Generate purchase number (e.g., PO-20250314-0001)
            $prefix = 'PO-'.date('Ymd').'-';
            $res = $conn->query("SELECT COUNT(*) AS c FROM purchases WHERE purchase_number LIKE '$prefix%'");
            $cnt = $res ? (int)$res->fetch_assoc()['c'] + 1 : 1;
            $pur_num = $prefix . str_pad($cnt, 4, '0', STR_PAD_LEFT);

            $stmt = $conn->prepare("INSERT INTO purchases
                (purchase_number, supplier_name, supplier_phone, purchase_date,
                 subtotal, discount_amount, total_amount, paid_amount, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('ssssddddsi', $pur_num, $supplier, $supp_phone, $pur_date,
                $subtotal, $discount, $total, $paid, $notes, $uid);
            $stmt->execute();
            $pur_id = $conn->insert_id;
            $stmt->close();

            // Insert items
            $item_stmt = $conn->prepare("INSERT INTO purchase_items
                (purchase_id, product_id, quantity, unit_cost, expiry_date)
                VALUES (?,?,?,?,?)");
            foreach ($items as $itm) {
                $pid = (int)($itm['product_id'] ?? 0);
                $qty = (int)($itm['quantity'] ?? 0);
                $cost = floatval($itm['unit_cost'] ?? 0);
                $exp = !empty($itm['expiry']) ? $itm['expiry'] : null;
                if ($pid && $qty > 0 && $cost > 0) {
                    $item_stmt->bind_param('iiids', $pur_id, $pid, $qty, $cost, $exp);
                    $item_stmt->execute();
                }
            }
            $item_stmt->close();

            // Audit log
            $desc = esc($conn, "Purchase $pur_num created with $total total");
            $conn->query("INSERT INTO audit_logs (user_id,user_name,action,table_name,record_id,description)
                VALUES ($uid,'$user','CREATE','purchases',$pur_id,'$desc')");

            $conn->commit();
            $flash = "Purchase <strong>$pur_num</strong> created successfully."; $flashType = 'ok';
        } catch (Exception $e) {
            $conn->rollback();
            $flash = "Error: ".$e->getMessage(); $flashType = 'err';
        }
        header("Location: purchases.php?flash=".urlencode($flash)."&ft=$flashType"); exit;
    }

    // ─── EDIT PURCHASE ────────────────────────────────────────
    if ($action === 'edit') {
        $pur_id     = (int)($_POST['id'] ?? 0);
        $supplier   = trim($_POST['supplier_name'] ?? '');
        $supp_phone = trim($_POST['supplier_phone'] ?? '') ?: null;
        $pur_date   = $_POST['purchase_date'] ?? date('Y-m-d');
        $subtotal   = floatval($_POST['subtotal'] ?? 0);
        $discount   = floatval($_POST['discount'] ?? 0);
        $total      = floatval($_POST['total'] ?? 0);
        $paid       = floatval($_POST['paid_amount'] ?? 0);
        $notes      = trim($_POST['notes'] ?? '') ?: null;
        $items      = $_POST['items'] ?? [];

        if (!$pur_id || !$supplier || empty($items)) {
            $flash = "Invalid request."; $flashType = 'err';
            header("Location: purchases.php?flash=".urlencode($flash)."&ft=$flashType"); exit;
        }

        // Optional: prevent editing if already paid
        // In the edit action, after fetching purchase number:
$check = $conn->query("SELECT payment_status, received_at FROM purchases WHERE id=$pur_id")->fetch_assoc();
if ($check['payment_status'] === 'paid' || $check['received_at'] !== null) {
    $flash = "Paid or received purchases cannot be edited."; $flashType = 'err';
    header("Location: purchases.php?flash=".urlencode($flash)."&ft=$flashType"); exit;
}

        $conn->begin_transaction();
        try {
            // Update purchase header
            $stmt = $conn->prepare("UPDATE purchases SET
                supplier_name=?, supplier_phone=?, purchase_date=?,
                subtotal=?, discount_amount=?, total_amount=?, paid_amount=?, notes=?
                WHERE id=?");
            $stmt->bind_param('sssddddsi', $supplier, $supp_phone, $pur_date,
                $subtotal, $discount, $total, $paid, $notes, $pur_id);
            $stmt->execute();
            $stmt->close();

            // Delete old items
            $conn->query("DELETE FROM purchase_items WHERE purchase_id=$pur_id");

            // Insert new items
            $item_stmt = $conn->prepare("INSERT INTO purchase_items
                (purchase_id, product_id, quantity, unit_cost, expiry_date)
                VALUES (?,?,?,?,?)");
            foreach ($items as $itm) {
                $pid = (int)($itm['product_id'] ?? 0);
                $qty = (int)($itm['quantity'] ?? 0);
                $cost = floatval($itm['unit_cost'] ?? 0);
                $exp = !empty($itm['expiry']) ? $itm['expiry'] : null;
                if ($pid && $qty > 0 && $cost > 0) {
                    $item_stmt->bind_param('iiids', $pur_id, $pid, $qty, $cost, $exp);
                    $item_stmt->execute();
                }
            }
            $item_stmt->close();

            // Audit log
            $pur_num = $conn->query("SELECT purchase_number FROM purchases WHERE id=$pur_id")->fetch_assoc()['purchase_number'];
            $desc = esc($conn, "Purchase $pur_num updated");
            $conn->query("INSERT INTO audit_logs (user_id,user_name,action,table_name,record_id,description)
                VALUES ($uid,'$user','UPDATE','purchases',$pur_id,'$desc')");

            $conn->commit();
            $flash = "Purchase updated successfully."; $flashType = 'ok';
        } catch (Exception $e) {
            $conn->rollback();
            $flash = "Error: ".$e->getMessage(); $flashType = 'err';
        }
        header("Location: purchases.php?flash=".urlencode($flash)."&ft=$flashType"); exit;
    }

    // ─── RECEIVE PURCHASE (update inventory) ──────────────────
    // ─── RECEIVE PURCHASE (update inventory) ──────────────────
if ($action === 'receive') {
    $pur_id = (int)($_POST['pur_id'] ?? 0);
    if (!$pur_id) { $flash="Invalid purchase."; $flashType='err'; }
    else {
        // Check if already received
        $chk = $conn->query("SELECT received_at FROM purchases WHERE id=$pur_id");
        $row = $chk->fetch_assoc();
        if ($row && $row['received_at'] !== null) {
            $flash = "This purchase has already been received."; $flashType = 'err';
            header("Location: purchases.php?flash=".urlencode($flash)."&ft=$flashType"); exit;
        }

        $conn->begin_transaction();
        try {
            // Fetch items
            $items = $conn->query("SELECT product_id, quantity FROM purchase_items WHERE purchase_id=$pur_id");
            while ($it = $items->fetch_assoc()) {
                $pid = $it['product_id'];
                $qty = $it['quantity'];
                // Update inventory
                $inv = $conn->query("SELECT quantity_in_stock FROM inventory WHERE product_id=$pid");
                if ($inv->num_rows) {
                    $conn->query("UPDATE inventory SET quantity_in_stock = quantity_in_stock + $qty, last_restock_date = CURDATE() WHERE product_id=$pid");
                } else {
                    $conn->query("INSERT INTO inventory (product_id, quantity_in_stock) VALUES ($pid, $qty)");
                }
                // Log transaction
                $conn->query("INSERT INTO inventory_transactions (product_id, transaction_type, quantity, notes)
                    VALUES ($pid, 'purchase', $qty, 'Purchase received, PO #$pur_id')");
            }
            // Mark purchase as received
            $conn->query("UPDATE purchases SET received_at = NOW() WHERE id = $pur_id");
            // Audit log
            $conn->query("INSERT INTO audit_logs (user_id,user_name,action,table_name,record_id,description)
                VALUES ($uid,'$user','UPDATE','purchases',$pur_id,'Purchase received, inventory updated')");
            $conn->commit();
            $flash = "Purchase received and inventory updated."; $flashType = 'ok';
        } catch (Exception $e) {
            $conn->rollback();
            $flash = "Error: ".$e->getMessage(); $flashType = 'err';
        }
    }
    header("Location: purchases.php?flash=".urlencode($flash)."&ft=$flashType"); exit;
}

    // ─── DELETE PURCHASE ──────────────────────────────────────
    if ($action === 'delete' && $canDelete) {
        $pur_id = (int)($_POST['pur_id'] ?? 0);
        if ($pur_id) {
            $conn->begin_transaction();
            try {
                $conn->query("DELETE FROM purchase_items WHERE purchase_id=$pur_id");
                $conn->query("DELETE FROM purchases WHERE id=$pur_id");
                $conn->query("INSERT INTO audit_logs (user_id,user_name,action,table_name,record_id,description)
                    VALUES ($uid,'$user','DELETE','purchases',$pur_id,'Purchase #$pur_id deleted')");
                $conn->commit();
                $flash = "Purchase deleted."; $flashType = 'warn';
            } catch (Exception $e) {
                $conn->rollback();
                $flash = "Error: ".$e->getMessage(); $flashType = 'err';
            }
        }
        header("Location: purchases.php?flash=".urlencode($flash)."&ft=$flashType"); exit;
    }
}

if (isset($_GET['flash'])) { $flash = urldecode($_GET['flash']); $flashType = $_GET['ft'] ?? 'ok'; }

// ═══════════════════════════════════════════════════════════════
// FILTERS & LIST
// ═══════════════════════════════════════════════════════════════
$search   = trim($_GET['q']      ?? '');
$supplier = trim($_GET['supplier'] ?? '');
$from     = $_GET['from']        ?? '';
$to       = $_GET['to']          ?? '';
$status   = $_GET['status']      ?? '';
$page     = max(1,(int)($_GET['page']??1));
$perPage  = 15;
$offset   = ($page-1)*$perPage;

$where = "WHERE 1=1";
if ($search) {
    $s = esc($conn, $search);
    $where .= " AND (purchase_number LIKE '%$s%' OR supplier_name LIKE '%$s%' OR supplier_phone LIKE '%$s%')";
}
if ($supplier) {
    $s = esc($conn, $supplier);
    $where .= " AND supplier_name LIKE '%$s%'";
}
if ($from) $where .= " AND purchase_date >= '$from'";
if ($to)   $where .= " AND purchase_date <= '$to'";
if ($status) {
    if ($status === 'paid') $where .= " AND payment_status='paid'";
    elseif ($status === 'unpaid') $where .= " AND payment_status='unpaid'";
    elseif ($status === 'partial') $where .= " AND payment_status='partial'";
}

$totalR = $conn->query("SELECT COUNT(*) AS c FROM purchases $where");
$total  = $totalR ? (int)$totalR->fetch_assoc()['c'] : 0;
$pages  = max(1, ceil($total/$perPage));

$purchases = $conn->query("SELECT * FROM purchases $where
    ORDER BY purchase_date DESC, id DESC
    LIMIT $perPage OFFSET $offset");

// Stats
$statTotal   = (int)fval($conn, "SELECT COUNT(*) AS v FROM purchases");
$statUnpaid  = (int)fval($conn, "SELECT COUNT(*) AS v FROM purchases WHERE payment_status='unpaid'");
$statPartial = (int)fval($conn, "SELECT COUNT(*) AS v FROM purchases WHERE payment_status='partial'");
$statPaid    = (int)fval($conn, "SELECT COUNT(*) AS v FROM purchases WHERE payment_status='paid'");
$statTotalDue= (float)fval($conn, "SELECT COALESCE(SUM(due_amount),0) AS v FROM purchases WHERE payment_status IN ('unpaid','partial')");
$statMonth   = (float)fval($conn, "SELECT COALESCE(SUM(total_amount),0) AS v FROM purchases
    WHERE MONTH(purchase_date)=MONTH(CURDATE()) AND YEAR(purchase_date)=YEAR(CURDATE())");

// Product list for item selection
$products = $conn->query("SELECT id, name, selling_price, cost_price FROM products WHERE is_active=1 ORDER BY name");

// Supplier list for filter (distinct)
$suppliers = $conn->query("SELECT DISTINCT supplier_name FROM purchases ORDER BY supplier_name");

// ─── Sidebar/topnav vars ──────────────────────────────────────
$current_page = 'purchases';
$page_title   = 'Purchases';
$breadcrumbs  = [['label' => 'Inventory'], ['label' => 'Purchases']];

// Sidebar badges
$unread_notifs       = (int)fval($conn,"SELECT COUNT(*) AS v FROM notifications WHERE recipient_id=$uid AND is_read=FALSE");
$pending_sales_badge = (int)fval($conn,"SELECT COUNT(*) AS v FROM sales WHERE approval_status='pending'");
$debt_badge          = (int)fval($conn,"SELECT COUNT(*) AS v FROM debts WHERE status IN('active','partially_paid','overdue')");
$low_stock_count     = (int)fval($conn,"SELECT COUNT(*) AS v FROM vw_low_stock");

function buildQS($overrides=[]){
    $p = array_merge($_GET, $overrides); unset($p['page']);
    $qs = http_build_query(array_filter($p, fn($v)=>$v!=='' && $v!==null));
    return $qs ? "?$qs&" : "?";
}
$qs = buildQS();
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
.num{font-variant-numeric:tabular-nums;font-weight:700}
.num.gr{color:var(--gr)}.num.go{color:var(--go)}.num.re{color:var(--re)}.num.nt{color:var(--t3)}
.dim{color:var(--t2)}

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
.act-btn.edit:hover{background:var(--ag);border-color:rgba(79,142,247,.3);color:var(--ac)}
.act-btn.print:hover{background:var(--pud);border-color:rgba(167,139,250,.3);color:var(--pu)}
.act-btn.receive:hover{background:var(--grd);border-color:rgba(52,211,153,.3);color:var(--gr)}
.act-btn.del:hover{background:var(--red);border-color:rgba(248,113,113,.3);color:var(--re)}
.act-btn svg{width:13px;height:13px}

/* ── BUTTONS ─────────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:10px;font-family:inherit;font-size:.8rem;font-weight:700;cursor:pointer;border:none;transition:all .2s;text-decoration:none}
.btn-primary{background:var(--ac);color:#fff;box-shadow:0 4px 14px var(--ag)}
.btn-primary:hover{background:var(--ac2);transform:translateY(-1px)}
.btn-ghost{background:var(--bg3);border:1px solid var(--br);color:var(--t2)}
.btn-ghost:hover{border-color:var(--br2);color:var(--tx)}
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

/* ── MODAL ───────────────────────────────────────────────────── */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:200;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .25s;backdrop-filter:blur(4px)}
.overlay.open{opacity:1;pointer-events:all}
.modal{background:var(--bg2);border:1px solid var(--br2);border-radius:18px;width:100%;max-width:680px;max-height:90vh;overflow-y:auto;box-shadow:var(--sh2);transform:translateY(20px) scale(.97);transition:transform .28s cubic-bezier(.16,1,.3,1)}
.modal.lg{max-width:780px}
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
.fsection{font-size:.68rem;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.1em;padding:10px 0 8px;border-bottom:1px solid var(--br);margin-bottom:12px}

/* Items table inside modal */
.items-table{width:100%;border-collapse:collapse;margin:12px 0}
.items-table th{font-size:.64rem;color:var(--t3);text-transform:uppercase;padding:8px;text-align:left;border-bottom:1px solid var(--br)}
.items-table td{padding:8px;border-bottom:1px solid var(--br)}
.items-table .remove-item{color:var(--re);cursor:pointer}
.item-total{font-weight:700;color:var(--gr)}
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
        <div class="ph-title">Purchases</div>
        <div class="ph-sub">
          <?= number_format($statTotal) ?> orders · 
          Due: <strong style="color:var(--go)"><?= fmt($statTotalDue) ?></strong> · 
          This month: <strong style="color:var(--gr)"><?= fmt($statMonth) ?></strong>
        </div>
      </div>
      <div class="ph-actions">
        <?php if ($canEdit): ?>
        <button class="btn btn-primary" onclick="openAdd()">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M8 1v14M1 8h14"/></svg>
          New Purchase
        </button>
        <?php endif; ?>
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

    <!-- Stats -->
    <div class="stats6">
      <div class="scard" style="--dl:.00s">
        <div class="sc-top">
          <div class="sc-icon" style="background:var(--ag)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--ac)" stroke-width="1.8"><rect x="1" y="4" width="14" height="9" rx="1.5"/><path d="M1 8h14M5 4V2M11 4V2"/></svg></div>
        </div>
        <div class="sc-val"><?= number_format($statTotal) ?></div>
        <div class="sc-lbl">Total Orders</div>
      </div>
      <div class="scard" style="--dl:.05s">
        <div class="sc-top">
          <div class="sc-icon" style="background:var(--grd)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--gr)" stroke-width="1.8"><circle cx="8" cy="8" r="7"/><path d="M5 8l2 2 4-4"/></svg></div>
          <span class="sc-trend ok"><?= $statPaid ?></span>
        </div>
        <div class="sc-val" style="color:var(--gr)"><?= number_format($statPaid) ?></div>
        <div class="sc-lbl">Paid</div>
      </div>
      <div class="scard" style="--dl:.10s">
        <div class="sc-top">
          <div class="sc-icon" style="background:var(--god)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--go)" stroke-width="1.8"><circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 2"/></svg></div>
          <span class="sc-trend warn"><?= $statPartial ?></span>
        </div>
        <div class="sc-val" style="color:var(--go)"><?= number_format($statPartial) ?></div>
        <div class="sc-lbl">Partial</div>
      </div>
      <div class="scard" style="--dl:.15s">
        <div class="sc-top">
          <div class="sc-icon" style="background:var(--red)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--re)" stroke-width="1.8"><path d="M8 1L1 13h14z"/><path d="M8 6v3M8 11v1"/></svg></div>
          <span class="sc-trend err"><?= $statUnpaid ?></span>
        </div>
        <div class="sc-val" style="color:var(--re)"><?= number_format($statUnpaid) ?></div>
        <div class="sc-lbl">Unpaid</div>
      </div>
      <div class="scard" style="--dl:.20s">
        <div class="sc-top">
          <div class="sc-icon" style="background:var(--god)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--go)" stroke-width="1.8"><path d="M3 10l3 3 4-5 4 4"/></svg></div>
        </div>
        <div class="sc-val"><?= fmt($statTotalDue) ?></div>
        <div class="sc-lbl">Total Due</div>
      </div>
      <div class="scard" style="--dl:.25s">
        <div class="sc-top">
          <div class="sc-icon" style="background:var(--ted)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--te)" stroke-width="1.8"><path d="M2 2h12v12H2z"/><path d="M5 8h6M8 5v6"/></svg></div>
        </div>
        <div class="sc-val"><?= fmt($statMonth) ?></div>
        <div class="sc-lbl">This Month</div>
      </div>
    </div>

    <!-- Toolbar -->
    <form method="GET" action="purchases.php" id="filterForm">
      <div class="toolbar">
        <div class="searchbox">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="7" cy="7" r="4.5"/><path d="M11 11l3 3"/></svg>
          <input type="text" name="q" placeholder="PO #, supplier…" value="<?= htmlspecialchars($search) ?>">
        </div>
        <input type="text" name="supplier" placeholder="Supplier" class="finput" style="width:140px" value="<?= htmlspecialchars($supplier) ?>">
        <input type="date" name="from" class="finput" style="width:130px" value="<?= $from ?>">
        <span style="color:var(--t3)">–</span>
        <input type="date" name="to" class="finput" style="width:130px" value="<?= $to ?>">
        <select name="status" class="sel" style="width:120px">
          <option value="">All Status</option>
          <option value="paid" <?= $status==='paid'?'selected':'' ?>>Paid</option>
          <option value="partial" <?= $status==='partial'?'selected':'' ?>>Partial</option>
          <option value="unpaid" <?= $status==='unpaid'?'selected':'' ?>>Unpaid</option>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="7" cy="7" r="4.5"/><path d="M11 11l3 3"/></svg>
          Filter
        </button>
        <?php if ($search||$supplier||$from||$to||$status): ?>
          <a href="purchases.php" class="btn btn-ghost btn-sm">Clear</a>
        <?php endif; ?>
        <div class="toolbar-right">
          <span class="count-lbl"><?= number_format($total) ?> order<?= $total!=1?'s':'' ?></span>
        </div>
      </div>
    </form>

    <!-- Purchases Table -->
    <div class="panel">
      <?php if ($purchases && $purchases->num_rows > 0): ?>
      <div style="overflow-x:auto">
        <table class="dtbl">
          <thead>
            <tr>
              <th>PO #</th>
              <th>Date</th>
              <th>Supplier</th>
              <th>Total</th>
              <th>Paid</th>
              <th>Due</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php while ($pur = $purchases->fetch_assoc()):
            $due = $pur['due_amount'] ?? ($pur['total_amount'] - $pur['paid_amount']);
            $status = $pur['payment_status'];
          ?>
          <tr>
            <td><span class="num nt"><?= htmlspecialchars($pur['purchase_number']) ?></span></td>
            <td class="dim"><?= date('M j, Y', strtotime($pur['purchase_date'])) ?></td>
            <td><?= htmlspecialchars($pur['supplier_name']) ?><br><span class="dim"><?= htmlspecialchars($pur['supplier_phone']??'') ?></span></td>
            <td><span class="num gr"><?= fmt((float)$pur['total_amount']) ?></span></td>
            <td><span class="num go"><?= fmt((float)$pur['paid_amount']) ?></span></td>
            <td><span class="num re"><?= fmt((float)$due) ?></span></td>
            <td><?= statusPill($status) ?></td>
            <td>
              <div class="actions">
                <!-- View items -->
                <button class="act-btn" title="View items" onclick="viewItems(<?= $pur['id'] ?>, '<?= htmlspecialchars($pur['purchase_number'], ENT_QUOTES) ?>')">
                  <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 2h12v12H2z"/><path d="M5 8h6"/></svg>
                </button>
                <!-- Edit (only if not paid) -->
                <?php if ($canEdit && $status !== 'paid'): ?>
                <button class="act-btn edit" title="Edit" onclick="openEdit(<?= $pur['id'] ?>)">
                  <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M11 2l3 3-9 9H2v-3z"/></svg>
                </button>
                <?php endif; ?>
                <!-- Print -->
                <a href="print_purchase.php?id=<?= $pur['id'] ?>" target="_blank" class="act-btn print" title="Print">
                  <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="1" width="10" height="4" rx="1"/><path d="M3 5H1a1 1 0 00-1 1v5a1 1 0 001 1h2v3h10v-3h2a1 1 0 001-1V6a1 1 0 00-1-1h-2"/><rect x="3" y="10" width="10" height="5" rx="1"/></svg>
                </a>
                <!-- Receive (if not paid) -->
                <!-- In the actions column -->
<?php if ($canEdit && $status !== 'paid' && $pur['received_at'] === null): ?>
<button class="act-btn receive" title="Receive (add to stock)" onclick="openReceive(<?= $pur['id'] ?>, '<?= htmlspecialchars($pur['purchase_number'], ENT_QUOTES) ?>')">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 8h14M8 1v14"/></svg>
</button>
<?php endif; ?>
                <!-- Delete -->
                <?php if ($canDelete): ?>
                <button class="act-btn del" title="Delete" onclick="openDel(<?= $pur['id'] ?>, '<?= htmlspecialchars($pur['purchase_number'], ENT_QUOTES) ?>')">
                  <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 4h12M5 4V2h6v2M6 7v5M10 7v5M3 4l1 10h8l1-10"/></svg>
                </button>
                <?php endif; ?>
              </div>
            </td>
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
          <?php for ($p=max(1,$page-2); $p<=min($pages,$page+2); $p++): ?>
            <a class="pg-btn <?= $p===$page?'on':'' ?>" href="<?= $qs ?>page=<?= $p ?>"><?= $p ?></a>
          <?php endfor; ?>
          <?php if ($page<$pages): ?><a class="pg-btn" href="<?= $qs ?>page=<?= $page+1 ?>">›</a><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1" y="4" width="14" height="9" rx="1.5"/><path d="M1 7h14M5 4V2M11 4V2"/></svg></div>
        <div class="empty-title">No purchases found</div>
        <div class="empty-sub"><?= ($search||$supplier||$from||$to||$status) ? 'Try adjusting your filters.' : 'Create your first purchase order.' ?></div>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- /content -->
</div><!-- /main -->

<!-- ════════════════════════════════════════════════════════════
     ADD / EDIT MODAL (with dynamic items)
════════════════════════════════════════════════════════════ -->
<?php if ($canEdit): ?>
<div class="overlay" id="purOverlay" onclick="closeModal(event,this)">
  <div class="modal lg">
    <div class="modal-head">
      <div>
        <div class="modal-title" id="purTitle">New Purchase Order</div>
        <div class="modal-sub" id="purSub"></div>
      </div>
      <button class="close-btn" onclick="closeAll()">✕</button>
    </div>
    <form method="POST" action="purchases.php" id="purForm">
      <input type="hidden" name="action" id="purAction" value="add">
      <input type="hidden" name="id" id="purId" value="">
      <div class="modal-body">

        <div class="fsection">Supplier & Date</div>
        <div class="frow">
          <div class="fgroup">
            <label class="flabel">Supplier Name <span class="req">*</span></label>
            <input type="text" name="supplier_name" id="purSupplier" class="finput" placeholder="e.g. ABC Distributors" required>
          </div>
          <div class="fgroup">
            <label class="flabel">Supplier Phone</label>
            <input type="text" name="supplier_phone" id="purPhone" class="finput" placeholder="+1 555 0000">
          </div>
        </div>
        <div class="frow">
          <div class="fgroup">
            <label class="flabel">Purchase Date</label>
            <input type="date" name="purchase_date" id="purDate" class="finput" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="fgroup">
            <label class="flabel">Notes</label>
            <input type="text" name="notes" id="purNotes" class="finput" placeholder="Optional">
          </div>
        </div>

        <div class="fsection">Items</div>
        <table class="items-table" id="itemsTable">
          <thead>
            <tr>
              <th>Product</th>
              <th>Qty</th>
              <th>Unit Cost ($)</th>
              <th>Expiry Date</th>
              <th>Total</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="itemsBody">
            <!-- Dynamic rows go here -->
          </tbody>
        </table>
        <button type="button" class="btn btn-ghost btn-sm" onclick="addRow()" style="margin-bottom:12px">
          + Add Item
        </button>

        <!-- Totals -->
        <div style="display:flex;justify-content:flex-end;gap:20px;margin-top:10px">
          <div><span style="color:var(--t2)">Subtotal:</span> <span id="subtotal" class="num gr">$0.00</span></div>
          <div><span style="color:var(--t2)">Discount ($):</span> <input type="number" name="discount" id="discount" class="finput" style="width:100px" min="0" step="0.01" value="0" oninput="calcTotal()"></div>
          <div><span style="color:var(--t2)">Total:</span> <span id="total" class="num gr">$0.00</span></div>
          <input type="hidden" name="subtotal" id="subtotalInput">
          <input type="hidden" name="total" id="totalInput">
        </div>
        <div style="display:flex;justify-content:flex-end;gap:20px;margin-top:8px">
          <div><span style="color:var(--t2)">Paid Amount ($):</span> <input type="number" name="paid_amount" id="paid" class="finput" style="width:120px" min="0" step="0.01" value="0"></div>
        </div>

      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="closeAll()">Cancel</button>
        <button type="submit" class="btn btn-primary" id="purSubmitBtn">Create Purchase</button>
      </div>
    </form>
  </div>
</div>

<!-- Receive confirmation modal -->
<div class="overlay" id="receiveOverlay" onclick="closeModal(event,this)">
  <div class="modal sm">
    <div class="modal-body" style="padding:30px 26px 18px">
      <div class="cicon" style="background:var(--grd)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--gr)" stroke-width="1.8"><path d="M1 8h14M8 1v14"/></svg></div>
      <div class="ctitle">Receive Purchase</div>
      <div class="csub" id="receiveMsg">This will add all items to stock. Continue?</div>
    </div>
    <form method="POST" action="purchases.php">
      <input type="hidden" name="action" value="receive">
      <input type="hidden" name="pur_id" id="receiveId" value="">
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="closeAll()">Cancel</button>
        <button type="submit" class="btn btn-primary">Confirm Receive</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Delete confirmation modal -->
<?php if ($canDelete): ?>
<div class="overlay" id="delOverlay" onclick="closeModal(event,this)">
  <div class="modal sm">
    <div class="modal-body" style="padding:30px 26px 18px">
      <div class="cicon" style="background:var(--red)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--re)" stroke-width="1.8"><path d="M2 4h12M5 4V2h6v2M6 7v5M10 7v5M3 4l1 10h8l1-10"/></svg></div>
      <div class="ctitle">Delete Purchase?</div>
      <div class="csub" id="delMsg">This will permanently delete the order and its items.</div>
    </div>
    <form method="POST" action="purchases.php">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="pur_id" id="delId" value="">
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="closeAll()">Cancel</button>
        <button type="submit" class="btn btn-danger">Delete</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Items view modal (simple) -->
<div class="overlay" id="itemsOverlay" onclick="closeModal(event,this)">
  <div class="modal">
    <div class="modal-head">
      <div><div class="modal-title" id="itemsTitle">Purchase Items</div><div class="modal-sub" id="itemsSub"></div></div>
      <button class="close-btn" onclick="closeAll()">✕</button>
    </div>
    <div class="modal-body" id="itemsList" style="max-height:400px;overflow-y:auto">
      <!-- populated by JS -->
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

// ── Add / Edit purchase modal ─────────────────────────────────
let rowCount = 0;

function openAdd(){
  document.getElementById('purTitle').textContent = 'New Purchase Order';
  document.getElementById('purSub').textContent = '';
  document.getElementById('purAction').value = 'add';
  document.getElementById('purId').value = '';
  document.getElementById('purForm').reset();
  document.getElementById('purDate').value = '<?= date('Y-m-d') ?>';
  document.getElementById('purSubmitBtn').textContent = 'Create Purchase';
  // Clear items
  document.getElementById('itemsBody').innerHTML = '';
  addRow(); // create first empty row
  calcTotal();
  openOverlay('purOverlay');
}

function openEdit(id) {
  document.getElementById('purTitle').textContent = 'Edit Purchase Order';
  document.getElementById('purAction').value = 'edit';
  document.getElementById('purSubmitBtn').textContent = 'Save Changes';
  document.getElementById('purId').value = id;
  // Clear items
  document.getElementById('itemsBody').innerHTML = '';
  // Fetch data
  fetch('purchase_edit_data.php?id=' + id)
    .then(response => response.json())
    .then(data => {
      if (data.error) {
        alert(data.error);
        closeAll();
        return;
      }
      // Populate header
      document.getElementById('purSupplier').value = data.supplier_name;
      document.getElementById('purPhone').value = data.supplier_phone || '';
      document.getElementById('purDate').value = data.purchase_date;
      document.getElementById('purNotes').value = data.notes || '';
      document.getElementById('discount').value = data.discount_amount;
      document.getElementById('paid').value = data.paid_amount;
      // Populate items
      data.items.forEach((item, index) => {
        addRow(item);
      });
      calcTotal();
      openOverlay('purOverlay');
    })
    .catch(err => {
      alert('Error loading purchase data');
      console.error(err);
    });
}

function addRow(item = null) {
  const tbody = document.getElementById('itemsBody');
  const idx = rowCount++;
  const row = document.createElement('tr');
  row.className = 'item-row';
  const productOptions = `<?php 
    $product_options = '';
    if ($products) {
      $products->data_seek(0);
      while ($p = $products->fetch_assoc()) {
        $product_options .= '<option value="'.$p['id'].'" data-cost="'.$p['cost_price'].'">'.htmlspecialchars($p['name']).'</option>';
      }
    }
    echo $product_options;
  ?>`;
  const selected = item ? `value="${item.product_id}"` : '';
  row.innerHTML = `
    <td>
      <select name="items[${idx}][product_id]" class="fselect" style="width:150px" onchange="updateRowTotal(this)">
        <option value="">— Select —</option>
        ${productOptions}
      </select>
    </td>
    <td><input type="number" name="items[${idx}][quantity]" class="finput" style="width:70px" min="1" value="${item ? item.quantity : 1}" oninput="updateRowTotal(this)"></td>
    <td><input type="number" name="items[${idx}][unit_cost]" class="finput" style="width:90px" min="0" step="0.01" value="${item ? item.unit_cost : 0}" oninput="updateRowTotal(this)"></td>
    <td><input type="date" name="items[${idx}][expiry]" class="finput" style="width:120px" value="${item && item.expiry_date ? item.expiry_date : ''}"></td>
    <td><span class="item-total" id="rowTotal${idx}">$0.00</span></td>
    <td><span class="remove-item" onclick="removeRow(this)">✕</span></td>
  `;
  tbody.appendChild(row);
  if (item) {
    const select = row.querySelector('select');
    select.value = item.product_id;
    // also set the product cost if not set
    const costInput = row.querySelector('input[name*="[unit_cost]"]');
    if (costInput.value == 0) {
      const opt = select.options[select.selectedIndex];
      if (opt) costInput.value = opt.getAttribute('data-cost');
    }
  }
  updateRowTotal(row.querySelector('select'));
}

function removeRow(el) {
  if (document.querySelectorAll('.item-row').length > 1) {
    el.closest('tr').remove();
    calcTotal();
  }
}

function updateRowTotal(input) {
  const row = input.closest('tr');
  const qty = parseFloat(row.querySelector('input[name*="[quantity]"]').value) || 0;
  const cost = parseFloat(row.querySelector('input[name*="[unit_cost]"]').value) || 0;
  const total = qty * cost;
  row.querySelector('.item-total').textContent = '$' + total.toFixed(2);
  calcTotal();
  // If product selected and cost is zero, prefill with product's cost price
  if (input.tagName === 'SELECT' && input.value) {
    const opt = input.options[input.selectedIndex];
    const prodCost = opt.getAttribute('data-cost');
    const costInput = row.querySelector('input[name*="[unit_cost]"]');
    if (costInput && (costInput.value == 0 || costInput.value === '')) {
      costInput.value = prodCost;
      updateRowTotal(costInput);
    }
  }
}

function calcTotal() {
  let subtotal = 0;
  document.querySelectorAll('.item-row').forEach(row => {
    const qty = parseFloat(row.querySelector('input[name*="[quantity]"]').value) || 0;
    const cost = parseFloat(row.querySelector('input[name*="[unit_cost]"]').value) || 0;
    subtotal += qty * cost;
  });
  const discount = parseFloat(document.getElementById('discount').value) || 0;
  const total = Math.max(0, subtotal - discount);
  document.getElementById('subtotal').textContent = '$' + subtotal.toFixed(2);
  document.getElementById('total').textContent = '$' + total.toFixed(2);
  document.getElementById('subtotalInput').value = subtotal.toFixed(2);
  document.getElementById('totalInput').value = total.toFixed(2);
}

// ── Receive modal ──────────────────────────────────────────────
function openReceive(id, num) {
  document.getElementById('receiveId').value = id;
  document.getElementById('receiveMsg').textContent = `Receive purchase ${num}? All items will be added to stock.`;
  openOverlay('receiveOverlay');
}

// ── Delete modal ──────────────────────────────────────────────
function openDel(id, num) {
  document.getElementById('delId').value = id;
  document.getElementById('delMsg').textContent = `Delete purchase ${num}? This cannot be undone.`;
  openOverlay('delOverlay');
}

// ── View items modal ───────────────────────────────────────────
function viewItems(id, num) {
  document.getElementById('itemsTitle').textContent = `Purchase #${num}`;
  document.getElementById('itemsSub').textContent = 'Items list';
  document.getElementById('itemsList').innerHTML = '<div style="padding:20px;text-align:center">Loading...</div>';
  openOverlay('itemsOverlay');
  fetch(`purchase_items.php?purchase_id=${id}`)
    .then(r=>r.text())
    .then(html=>{document.getElementById('itemsList').innerHTML=html;})
    .catch(()=>{
      document.getElementById('itemsList').innerHTML = '<div style="padding:20px;text-align:center;color:var(--t3)">Could not load items.</div>';
    });
}

// ── Flash auto-dismiss ─────────────────────────────────────────
setTimeout(()=>{
  const f=document.querySelector('.flash');
  if(f){f.style.transition='opacity .5s';f.style.opacity='0';setTimeout(()=>f.remove(),500);}
},4500);
</script>
</body>
</html>