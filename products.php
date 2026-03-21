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

// ─── Sidebar / topnav vars ──────────────────────────────────
$current_page = 'products';
$page_title   = 'Products & Categories';
$breadcrumbs  = [['label' => 'Inventory'], ['label' => 'Products & Categories']];

// Sidebar badge counts
$unread_notifs       = (int)($conn->query("SELECT COUNT(*) AS v FROM notifications WHERE recipient_id=$uid AND is_read=FALSE")->fetch_assoc()['v'] ?? 0);
$pending_sales_badge = (int)($conn->query("SELECT COUNT(*) AS v FROM sales WHERE approval_status='pending'")->fetch_assoc()['v'] ?? 0);
$debt_badge          = (int)($conn->query("SELECT COUNT(*) AS v FROM debts WHERE status IN ('active','partially_paid','overdue')")->fetch_assoc()['v'] ?? 0);
$low_stock_count     = (int)($conn->query("SELECT COUNT(*) AS v FROM vw_low_stock")->fetch_assoc()['v'] ?? 0);

// ─── Helpers ──────────────────────────────────────────────────
function fmt($v) {
    if ($v >= 1000000) return '$' . number_format($v/1000000, 2) . 'M';
    if ($v >= 1000)    return '$' . number_format($v/1000, 1)    . 'k';
    return '$' . number_format($v, 2);
}
function fval($conn, $sql, $col = 'v') {
    $r = $conn->query($sql);
    if (!$r) return 0;
    $row = $r->fetch_assoc();
    return $row[$col] ?? 0;
}
function esc($conn, $v) { return $conn->real_escape_string($v); }

// ─── Image upload helper ──────────────────────────────────────
function uploadProductImage($file, $oldPath = null) {
    $targetDir = __DIR__ . '/uploads/products/';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 2 * 1024 * 1024; // 2MB

    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return $oldPath; // no new file, keep old
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return false; // upload error
    }
    if (!in_array($file['type'], $allowedTypes)) {
        return false; // invalid type
    }
    if ($file['size'] > $maxSize) {
        return false; // too large
    }

    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $targetFile = $targetDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $targetFile)) {
        // delete old image if exists
        if ($oldPath && file_exists(__DIR__ . '/' . $oldPath)) {
            unlink(__DIR__ . '/' . $oldPath);
        }
        return 'uploads/products/' . $filename;
    }
    return false;
}

// ─── Active tab: 'products' or 'categories' ───────────────────
$tab = $_GET['tab'] ?? 'products';
if (!in_array($tab, ['products','categories'])) $tab = 'products';

// ═══════════════════════════════════════════════════════════════
// POST HANDLERS
// ═══════════════════════════════════════════════════════════════
$flash = $flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $action = $_POST['action'] ?? '';

    // ──────────────────────────────────────────────────────────
    // CATEGORY ACTIONS
    // ──────────────────────────────────────────────────────────
    if ($action === 'add_category') {
        $name = trim($_POST['cat_name'] ?? '');
        $desc = trim($_POST['cat_desc'] ?? '') ?: null;
        if ($name) {
            $stmt = $conn->prepare("INSERT INTO categories (name, description, is_active) VALUES (?,?,1)");
            $stmt->bind_param('ss', $name, $desc);
            if ($stmt->execute()) {
                $nid = $conn->insert_id;
                $conn->query("INSERT INTO audit_logs (user_id,user_name,action,table_name,record_id,description)
                    VALUES ($uid,'$user','CREATE','categories',$nid,'Category \"$name\" created')");
                $flash = "Category <strong>$name</strong> added."; $flashType = 'ok';
            } else { $flash = "Error: ".htmlspecialchars($conn->error); $flashType = 'err'; }
            $stmt->close();
        } else { $flash = "Category name is required."; $flashType = 'err'; }
        header("Location: products.php?tab=categories&flash=".urlencode($flash)."&ft=$flashType"); exit;
    }

    if ($action === 'edit_category') {
        $id   = (int)($_POST['cat_id'] ?? 0);
        $name = trim($_POST['cat_name'] ?? '');
        $desc = trim($_POST['cat_desc'] ?? '') ?: null;
        $active = isset($_POST['cat_active']) ? 1 : 0;
        if ($id && $name) {
            $stmt = $conn->prepare("UPDATE categories SET name=?, description=?, is_active=? WHERE id=?");
            $stmt->bind_param('ssii', $name, $desc, $active, $id);
            if ($stmt->execute()) {
                $conn->query("INSERT INTO audit_logs (user_id,user_name,action,table_name,record_id,description)
                    VALUES ($uid,'$user','UPDATE','categories',$id,'Category #$id updated')");
                $flash = "Category updated."; $flashType = 'ok';
            } else { $flash = "Error: ".htmlspecialchars($conn->error); $flashType = 'err'; }
            $stmt->close();
        }
        header("Location: products.php?tab=categories&flash=".urlencode($flash)."&ft=$flashType"); exit;
    }

    if ($action === 'delete_category' && $canDelete) {
        $id = (int)($_POST['cat_id'] ?? 0);
        if ($id) {
            // Detach products first
            $conn->query("UPDATE products SET category_id=NULL WHERE category_id=$id");
            $conn->query("DELETE FROM categories WHERE id=$id");
            $conn->query("INSERT INTO audit_logs (user_id,user_name,action,table_name,record_id,description)
                VALUES ($uid,'$user','DELETE','categories',$id,'Category #$id deleted')");
            $flash = "Category deleted."; $flashType = 'warn';
        }
        header("Location: products.php?tab=categories&flash=".urlencode($flash)."&ft=$flashType"); exit;
    }

    // ──────────────────────────────────────────────────────────
    // PRODUCT ACTIONS
    // ──────────────────────────────────────────────────────────
    if ($action === 'add_product' || $action === 'edit_product') {
        $pid          = (int)($_POST['prod_id'] ?? 0);
        $name         = trim($_POST['prod_name']        ?? '');
        $barcode      = trim($_POST['prod_barcode']     ?? '') ?: null;
        $cat_id       = (int)($_POST['prod_category']   ?? 0) ?: null;
        $brand        = trim($_POST['prod_brand']       ?? '') ?: null;
        $description  = trim($_POST['prod_description'] ?? '') ?: null;
        $cost         = floatval($_POST['prod_cost']    ?? 0);
        $sell         = floatval($_POST['prod_sell']    ?? 0);
        $wholesale    = floatval($_POST['prod_wholesale']?? 0) ?: null;
        $uom          = $_POST['prod_uom']              ?? 'piece';
        $upc          = (int)($_POST['prod_upc']        ?? 1);
        $weight       = floatval($_POST['prod_weight']  ?? 0) ?: null;
        $expiry       = trim($_POST['prod_expiry']      ?? '') ?: null;
        $min_stock    = (int)($_POST['prod_min']        ?? 10);
        $max_stock    = (int)($_POST['prod_max']        ?? 5000);
        $reorder      = (int)($_POST['prod_reorder']    ?? 20);
        $is_active    = isset($_POST['prod_active'])    ? 1 : 0;
        $is_featured  = isset($_POST['prod_featured'])  ? 1 : 0;

        if (!$name || $cost <= 0 || $sell <= 0) {
            $flash = "Name, cost price and selling price are required."; $flashType = 'err';
            header("Location: products.php?tab=products&flash=".urlencode($flash)."&ft=$flashType"); exit;
        }

        // Handle image upload
        $imagePath = null;
        if ($action === 'edit_product' && $pid) {
            // fetch old image path
            $oldImg = $conn->query("SELECT image_path FROM products WHERE id=$pid")->fetch_assoc()['image_path'] ?? null;
        } else {
            $oldImg = null;
        }

        if (isset($_FILES['prod_image']) && $_FILES['prod_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $uploaded = uploadProductImage($_FILES['prod_image'], $oldImg);
            if ($uploaded === false) {
                $flash = "Image upload failed. Check file type (JPG,PNG,GIF,WEBP) and size (max 2MB)."; $flashType = 'err';
                header("Location: products.php?tab=products&flash=".urlencode($flash)."&ft=$flashType"); exit;
            }
            $imagePath = $uploaded;
        } else {
            $imagePath = $oldImg; // keep old if editing, null if adding
        }

        if ($action === 'add_product') {
            // ADD: 18 parameters
            $stmt = $conn->prepare("INSERT INTO products
                (name, barcode, category_id, brand, description, cost_price, selling_price,
                 wholesale_price, unit_of_measure, units_per_case, weight_kg, expiry_date,
                 min_stock_level, max_stock_level, reorder_level, is_active, is_featured, image_path)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            // Type string: s s i s s d d d s i d s i i i i i s (18 chars)
            $stmt->bind_param('ssissdddsidsiiiiis',
                $name, $barcode, $cat_id, $brand, $description,
                $cost, $sell, $wholesale,
                $uom, $upc, $weight, $expiry,
                $min_stock, $max_stock, $reorder,
                $is_active, $is_featured, $imagePath);

            if ($stmt->execute()) {
                $nid = $conn->insert_id;
                $conn->query("INSERT INTO audit_logs (user_id,user_name,action,table_name,record_id,description)
                    VALUES ($uid,'$user','CREATE','products',$nid,'Product \"$name\" created')");
                $flash = "Product <strong>$name</strong> added."; $flashType = 'ok';
            } else {
                $flash = "Error: ".htmlspecialchars($conn->error); $flashType = 'err';
            }
            $stmt->close();
        } else {
            // EDIT: 19 parameters (including pid)
            if ($pid) {
                $stmt = $conn->prepare("UPDATE products SET
                    name=?, barcode=?, category_id=?, brand=?, description=?,
                    cost_price=?, selling_price=?, wholesale_price=?,
                    unit_of_measure=?, units_per_case=?, weight_kg=?, expiry_date=?,
                    min_stock_level=?, max_stock_level=?, reorder_level=?,
                    is_active=?, is_featured=?, image_path=?
                    WHERE id=?");
                // Type string: s s i s s d d d s i d s i i i i i s i (19 chars)
                $stmt->bind_param('ssissdddsidsiiiiisi',
                    $name, $barcode, $cat_id, $brand, $description,
                    $cost, $sell, $wholesale,
                    $uom, $upc, $weight, $expiry,
                    $min_stock, $max_stock, $reorder,
                    $is_active, $is_featured, $imagePath,
                    $pid);

                if ($stmt->execute()) {
                    $conn->query("INSERT INTO audit_logs (user_id,user_name,action,table_name,record_id,description)
                        VALUES ($uid,'$user','UPDATE','products',$pid,'Product #$pid updated')");
                    $flash = "Product updated."; $flashType = 'ok';
                } else {
                    $flash = "Error: ".htmlspecialchars($conn->error); $flashType = 'err';
                }
                $stmt->close();
            }
        }
        header("Location: products.php?tab=products&flash=".urlencode($flash)."&ft=$flashType"); exit;
    }

    if ($action === 'toggle_product') {
        $id  = (int)($_POST['prod_id'] ?? 0);
        $val = (int)($_POST['is_active'] ?? 0);
        if ($id) {
            $conn->query("UPDATE products SET is_active=$val WHERE id=$id");
            $flash = $val ? "Product activated." : "Product deactivated."; $flashType = 'ok';
        }
        header("Location: products.php?tab=products&flash=".urlencode($flash)."&ft=$flashType"); exit;
    }

    if ($action === 'delete_product' && $canDelete) {
        $id = (int)($_POST['prod_id'] ?? 0);
        if ($id) {
            // Optionally delete image file
            $img = $conn->query("SELECT image_path FROM products WHERE id=$id")->fetch_assoc()['image_path'] ?? null;
            if ($img && file_exists(__DIR__ . '/' . $img)) {
                unlink(__DIR__ . '/' . $img);
            }
            $conn->query("UPDATE products SET is_active=0 WHERE id=$id");
            $conn->query("INSERT INTO audit_logs (user_id,user_name,action,table_name,record_id,description)
                VALUES ($uid,'$user','DELETE','products',$id,'Product #$id deactivated')");
            $flash = "Product deactivated."; $flashType = 'warn';
        }
        header("Location: products.php?tab=products&flash=".urlencode($flash)."&ft=$flashType"); exit;
    }
}

if (isset($_GET['flash'])) { $flash = urldecode($_GET['flash']); $flashType = $_GET['ft'] ?? 'ok'; }

// ═══════════════════════════════════════════════════════════════
// DATA — CATEGORIES TAB
// ═══════════════════════════════════════════════════════════════
$categories = $conn->query("SELECT c.*,
    COUNT(p.id) AS product_count
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.id AND p.is_active = 1
    GROUP BY c.id ORDER BY c.name ASC");

// ═══════════════════════════════════════════════════════════════
// DATA — PRODUCTS TAB
// ═══════════════════════════════════════════════════════════════
$pSearch   = trim($_GET['q']      ?? '');
$pCat      = (int)($_GET['cat']   ?? 0);
$pBrand    = trim($_GET['brand']  ?? '');
$pStatus   = $_GET['pstatus']     ?? '';
$pFeatured = $_GET['featured']    ?? '';
$pExpiring = $_GET['expiring']    ?? '';
$pPage     = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 15;
$pOffset   = ($pPage - 1) * $perPage;

$pwhere = "WHERE 1=1";
if ($pSearch) {
    $s = esc($conn, $pSearch);
    $pwhere .= " AND (p.name LIKE '%$s%' OR p.barcode LIKE '%$s%' OR p.brand LIKE '%$s%' OR p.description LIKE '%$s%')";
}
if ($pCat)     $pwhere .= " AND p.category_id=$pCat";
if ($pBrand)   $pwhere .= " AND p.brand='".esc($conn,$pBrand)."'";
if ($pStatus === '1') $pwhere .= " AND p.is_active=1";
if ($pStatus === '0') $pwhere .= " AND p.is_active=0";
if ($pFeatured==='1') $pwhere .= " AND p.is_featured=1";
if ($pExpiring==='1') $pwhere .= " AND p.expiry_date IS NOT NULL AND p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)";

$pTotalR = $conn->query("SELECT COUNT(*) AS c FROM products p $pwhere");
$pTotal  = $pTotalR ? (int)$pTotalR->fetch_assoc()['c'] : 0;
$pPages  = max(1, ceil($pTotal / $perPage));

$products = $conn->query("SELECT p.*,
    c.name AS category_name,
    COALESCE(i.quantity_in_stock,0) AS stock,
    COALESCE(i.quantity_available,0) AS available
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN inventory i ON i.product_id = p.id
    $pwhere
    ORDER BY p.created_at DESC
    LIMIT $perPage OFFSET $pOffset");

// Stats
$statTotal    = (int)fval($conn, "SELECT COUNT(*) AS v FROM products WHERE is_active=1");
$statCats     = (int)fval($conn, "SELECT COUNT(*) AS v FROM categories WHERE is_active=1");
$statLowStock = (int)fval($conn, "SELECT COUNT(*) AS v FROM vw_low_stock");
$statExpiring = (int)fval($conn, "SELECT COUNT(*) AS v FROM products WHERE is_active=1
    AND expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
$statFeatured = (int)fval($conn, "SELECT COUNT(*) AS v FROM products WHERE is_active=1 AND is_featured=1");
$totalStockVal= (float)fval($conn, "SELECT COALESCE(SUM(p.cost_price * i.quantity_in_stock),0) AS v
    FROM products p JOIN inventory i ON i.product_id=p.id WHERE p.is_active=1");

// Active categories for dropdowns
$catOpts = $conn->query("SELECT id, name FROM categories WHERE is_active=1 ORDER BY name");

// Distinct brands
$brandOpts = $conn->query("SELECT DISTINCT brand FROM products WHERE brand IS NOT NULL AND brand!='' AND is_active=1 ORDER BY brand");

// UOM options
$uomOpts = ['piece','box','carton','kg','gram','liter','pack'];

function buildQS($overrides = []) {
    $p = array_merge($_GET, $overrides); unset($p['page']);
    $qs = http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== false && $v !== 0));
    return $qs ? "?$qs&" : "?";
}
$qs = buildQS();

// Category color palette
$catPalette = [
    ['var(--ac)', 'var(--ag)'],
    ['var(--gr)', 'var(--grd)'],
    ['var(--go)', 'var(--god)'],
    ['var(--pu)', 'var(--pud)'],
    ['var(--te)', 'var(--ted)'],
    ['var(--re)', 'var(--red)'],
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
/* ── VARIABLES ── */
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

/* ── RESET ── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{background:var(--bg);color:var(--tx);font-family:'Plus Jakarta Sans',sans-serif;font-size:.875rem;min-height:100vh;display:flex;transition:background .4s,color .4s}

/* ═══════════════════ SIDEBAR ═══════════════════ */
.sidebar{width:248px;min-height:100vh;background:var(--bg2);border-right:1px solid var(--br);display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;transition:transform .3s ease;overflow-y:auto}
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

/* ═══════════════════ MAIN / TOPNAV ═══════════════════ */
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

/* ═══════════════════ LAYOUT ═══════════════════ */
.content{padding:28px;display:flex;flex-direction:column;gap:20px}
@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}

/* Page header */
.page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}
.ph-title{font-size:1.5rem;font-weight:800;color:var(--tx);letter-spacing:-.03em}
.ph-sub{font-size:.78rem;color:var(--t2);margin-top:4px}
.ph-actions{display:flex;gap:8px;align-items:center}

/* Tabs */
.tab-bar{display:flex;gap:4px;background:var(--bg2);border:1px solid var(--br);border-radius:12px;padding:4px;width:fit-content}
.tab-btn{display:flex;align-items:center;gap:7px;padding:8px 18px;border-radius:9px;font-family:inherit;font-size:.82rem;font-weight:600;cursor:pointer;border:none;transition:all .2s;text-decoration:none;color:var(--t2)}
.tab-btn:hover{color:var(--tx)}
.tab-btn.on{background:var(--ac);color:#fff;box-shadow:0 3px 12px var(--ag)}
.tab-btn svg{width:14px;height:14px}
.tab-cnt{background:rgba(255,255,255,.18);color:inherit;font-size:.62rem;font-weight:800;padding:1px 6px;border-radius:100px;line-height:1.5}
.tab-btn:not(.on) .tab-cnt{background:var(--bg3);color:var(--t3)}

/* Buttons */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:10px;font-family:inherit;font-size:.8rem;font-weight:700;cursor:pointer;border:none;transition:all .2s;text-decoration:none}
.btn-primary{background:var(--ac);color:#fff;box-shadow:0 4px 14px var(--ag)}
.btn-primary:hover{background:var(--ac2);transform:translateY(-1px)}
.btn-ghost{background:var(--bg3);border:1px solid var(--br);color:var(--t2)}
.btn-ghost:hover{border-color:var(--br2);color:var(--tx)}
.btn-danger{background:var(--red);border:1px solid rgba(248,113,113,.3);color:var(--re)}
.btn-danger:hover{background:rgba(248,113,113,.2)}
.btn-sm{padding:6px 12px;font-size:.75rem;border-radius:8px}
.btn svg{width:15px;height:15px}

/* Flash */
.flash{display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:11px;font-size:.82rem;font-weight:500;animation:fadeUp .4s cubic-bezier(.16,1,.3,1)}
.flash.ok  {background:var(--grd);border:1px solid rgba(52,211,153,.2);color:var(--gr)}
.flash.warn{background:var(--god);border:1px solid rgba(245,166,35,.2);color:var(--go)}
.flash.err {background:var(--red);border:1px solid rgba(248,113,113,.2);color:var(--re)}
.flash svg{width:15px;height:15px;flex-shrink:0}

/* Stats */
.stats6{display:grid;grid-template-columns:repeat(6,1fr);gap:12px}
.scard{background:var(--bg2);border:1px solid var(--br);border-radius:13px;padding:16px 18px;animation:fadeUp .4s cubic-bezier(.16,1,.3,1) both;transition:transform .2s,box-shadow .2s}
.scard:hover{transform:translateY(-2px);box-shadow:var(--sh2)}
.sc-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.sc-icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center}
.sc-icon svg{width:16px;height:16px}
.sc-val{font-size:1.3rem;font-weight:800;color:var(--tx);letter-spacing:-.04em;line-height:1}
.sc-lbl{font-size:.68rem;color:var(--t2);margin-top:4px;font-weight:500}

/* Panel */
.panel{background:var(--bg2);border:1px solid var(--br);border-radius:14px;overflow:hidden;animation:fadeUp .35s .06s cubic-bezier(.16,1,.3,1) both}
.phead{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--br)}
.ptitle{font-size:.9rem;font-weight:700;color:var(--tx)}
.psub{font-size:.68rem;color:var(--t3);margin-top:3px;font-weight:500}
.plink{font-size:.72rem;color:var(--ac);text-decoration:none;font-weight:600}
.plink:hover{opacity:.75}

/* Category cards */
.cat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px;padding:20px}
.cat-card{background:var(--bg3);border:1px solid var(--br);border-radius:13px;padding:18px 20px;transition:all .18s;animation:fadeUp .3s cubic-bezier(.16,1,.3,1) both;display:flex;flex-direction:column;gap:12px}
.cat-card:hover{transform:translateY(-3px);box-shadow:var(--sh2);border-color:var(--br2)}
.cat-card.inactive{opacity:.55}
.cc-top{display:flex;align-items:flex-start;gap:12px}
.cc-icon{width:42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.cc-icon svg{width:20px;height:20px}
.cc-name{font-size:.9rem;font-weight:800;color:var(--tx);line-height:1.2}
.cc-desc{font-size:.74rem;color:var(--t2);margin-top:3px;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.cc-foot{display:flex;align-items:center;justify-content:space-between}
.cc-count{display:flex;align-items:center;gap:5px;font-size:.74rem;color:var(--t2);font-weight:600}
.cc-count strong{color:var(--tx)}
.cat-actions{display:flex;gap:5px}
.add-cat-card{background:transparent;border:2px dashed var(--br2);border-radius:13px;padding:18px 20px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;cursor:pointer;transition:all .18s;min-height:130px}
.add-cat-card:hover{border-color:var(--ac);background:var(--ag)}
.add-cat-card svg{width:24px;height:24px;color:var(--t3)}
.add-cat-card:hover svg{color:var(--ac)}
.add-cat-card span{font-size:.8rem;font-weight:600;color:var(--t3)}
.add-cat-card:hover span{color:var(--ac)}

/* Pills */
.pill{display:inline-flex;align-items:center;padding:3px 9px;border-radius:100px;font-size:.67rem;font-weight:700;white-space:nowrap}
.pill.ok  {background:var(--grd);color:var(--gr)}
.pill.warn{background:var(--god);color:var(--go)}
.pill.err {background:var(--red);color:var(--re)}
.pill.info{background:var(--ag); color:var(--ac)}
.pill.pu  {background:var(--pud);color:var(--pu)}
.pill.nt  {background:var(--bg3);color:var(--t3)}

/* Action buttons */
.actions{display:flex;align-items:center;gap:4px}
.act-btn{width:29px;height:29px;border-radius:7px;border:1px solid var(--br);background:transparent;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .15s;color:var(--t2)}
.act-btn:hover{border-color:var(--br2);color:var(--tx)}
.act-btn.edit:hover{background:var(--ag);border-color:rgba(79,142,247,.3);color:var(--ac)}
.act-btn.del:hover{background:var(--red);border-color:rgba(248,113,113,.3);color:var(--re)}
.act-btn svg{width:13px;height:13px}

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
.filter-chip{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;background:var(--ag);border:1px solid rgba(79,142,247,.25);border-radius:100px;font-size:.7rem;font-weight:600;color:var(--ac);cursor:pointer;text-decoration:none}
.filter-chip:hover{background:var(--re);border-color:rgba(248,113,113,.3);color:var(--re);background:var(--red)}

/* Product Table */
.dtbl{width:100%;border-collapse:collapse}
.dtbl th{font-size:.64rem;color:var(--t3);text-transform:uppercase;letter-spacing:.1em;padding:11px 16px;text-align:left;border-bottom:1px solid var(--br);font-weight:700;white-space:nowrap}
.dtbl td{padding:12px 16px;font-size:.81rem;border-bottom:1px solid var(--br);vertical-align:middle}
.dtbl tr:last-child td{border-bottom:none}
.dtbl tbody tr{transition:background .1s}
.dtbl tbody tr:hover td{background:rgba(255,255,255,.022)}
[data-theme="light"] .dtbl tbody tr:hover td{background:rgba(0,0,0,.02)}
.prod-cell{display:flex;align-items:center;gap:11px}
.prod-thumb{width:40px;height:40px;border-radius:8px;background:var(--bg3);border:1px solid var(--br);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.7rem;color:var(--t2);object-fit:cover}
.prod-thumb img{width:100%;height:100%;object-fit:cover;border-radius:7px}
.prod-name{font-size:.84rem;font-weight:700;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px}
.prod-bar{font-size:.69rem;color:var(--t2);margin-top:2px;font-family:monospace}
.num{font-variant-numeric:tabular-nums;font-weight:700}
.num.gr{color:var(--gr)}
.num.go{color:var(--go)}
.num.re{color:var(--re)}
.dim{color:var(--t2)}
.stock-bar{height:4px;background:var(--br);border-radius:2px;overflow:hidden;margin-top:4px;width:70px}
.stock-fill{height:100%;border-radius:2px}
.feat-star{color:var(--go);font-size:.9rem}
.feat-star.off{color:var(--t3)}

/* Empty state */
.empty-state{padding:56px 20px;text-align:center}
.empty-icon{width:52px;height:52px;border-radius:14px;background:var(--bg3);border:1px solid var(--br);margin:0 auto 14px;display:flex;align-items:center;justify-content:center}
.empty-icon svg{width:24px;height:24px;color:var(--t3)}
.empty-title{font-size:.95rem;font-weight:700;color:var(--tx);margin-bottom:4px}
.empty-sub{font-size:.78rem;color:var(--t2)}

/* Pagination */
.pager{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-top:1px solid var(--br)}
.pager-info{font-size:.75rem;color:var(--t2)}
.pager-btns{display:flex;gap:4px}
.pg-btn{min-width:32px;height:32px;padding:0 8px;border-radius:8px;border:1px solid var(--br);background:var(--bg3);color:var(--t2);font-family:inherit;font-size:.78rem;font-weight:600;cursor:pointer;transition:all .15s;text-decoration:none;display:flex;align-items:center;justify-content:center}
.pg-btn:hover{border-color:var(--br2);color:var(--tx)}
.pg-btn.on{background:var(--ac);border-color:var(--ac);color:#fff}

/* Modal */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:200;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .25s;backdrop-filter:blur(4px)}
.overlay.open{opacity:1;pointer-events:all}
.modal{background:var(--bg2);border:1px solid var(--br2);border-radius:18px;width:100%;max-width:680px;max-height:90vh;overflow-y:auto;box-shadow:var(--sh2);transform:translateY(20px) scale(.97);transition:transform .28s cubic-bezier(.16,1,.3,1)}
.modal.sm{max-width:420px}
.overlay.open .modal{transform:none}
.modal-head{display:flex;align-items:center;justify-content:space-between;padding:20px 24px;border-bottom:1px solid var(--br);position:sticky;top:0;background:var(--bg2);z-index:1}
.modal-title{font-size:1rem;font-weight:800;color:var(--tx)}
.modal-sub{font-size:.72rem;color:var(--t2);margin-top:2px}
.close-btn{width:30px;height:30px;border-radius:8px;border:1px solid var(--br);background:transparent;color:var(--t2);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s;font-size:1rem;line-height:1}
.close-btn:hover{background:var(--red);border-color:rgba(248,113,113,.3);color:var(--re)}
.modal-body{padding:22px 24px}
.modal-foot{padding:14px 24px;border-top:1px solid var(--br);display:flex;align-items:center;justify-content:flex-end;gap:10px;position:sticky;bottom:0;background:var(--bg2)}

/* Form */
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
.fcheck-row{display:flex;gap:18px;flex-wrap:wrap}
.fcheck{display:flex;align-items:center;gap:7px;font-size:.81rem;color:var(--tx);cursor:pointer}
.fcheck input[type=checkbox]{width:15px;height:15px;accent-color:var(--ac);cursor:pointer}
.price-hint{font-size:.69rem;color:var(--t2);margin-top:3px}
.price-hint strong{color:var(--gr)}
/* Image preview */
.image-preview{max-width:100px;max-height:100px;border-radius:8px;border:1px solid var(--br);margin-top:5px;object-fit:cover}
/* Confirm */
.cicon{width:50px;height:50px;border-radius:13px;display:flex;align-items:center;justify-content:center;margin:0 auto 14px}
.cicon svg{width:22px;height:22px}
.ctitle{font-size:1rem;font-weight:800;text-align:center;margin-bottom:6px}
.csub{font-size:.79rem;color:var(--t2);text-align:center;line-height:1.55}

/* Responsive */
@media(max-width:1280px){.stats6{grid-template-columns:repeat(3,1fr)}}
@media(max-width:900px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:none}.main{margin-left:0}.mob-btn{display:block}.sbar{display:none}.frow,.frow.three{grid-template-columns:1fr}}
@media(max-width:640px){.content{padding:16px}.stats6{grid-template-columns:repeat(2,1fr)}.page-header{flex-direction:column}}
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
        <div class="ph-title">Products &amp; Categories</div>
        <div class="ph-sub"><?= number_format($statTotal) ?> active products &nbsp;·&nbsp; <?= $statCats ?> categories &nbsp;·&nbsp; Stock value: <strong style="color:var(--gr)"><?= fmt($totalStockVal) ?></strong></div>
      </div>
      <div class="ph-actions">
        <?php if ($canEdit): ?>
          <?php if ($tab === 'categories'): ?>
          <button class="btn btn-primary" onclick="openAddCat()">
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M8 1v14M1 8h14"/></svg>
            Add Category
          </button>
          <?php else: ?>
          <button class="btn btn-primary" onclick="openAddProd()">
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M8 1v14M1 8h14"/></svg>
            Add Product
          </button>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Flash -->
    <?php if ($flash): ?>
    <div class="flash <?= $flashType ?>">
      <?php if ($flashType === 'ok'): ?>
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
        <div class="sc-top"><div class="sc-icon" style="background:var(--ag)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--ac)" stroke-width="1.8"><path d="M2 4l6-2 6 2v8l-6 2-6-2V4z"/><path d="M8 2v12M2 4l6 2 6-2"/></svg></div></div>
        <div class="sc-val"><?= number_format($statTotal) ?></div>
        <div class="sc-lbl">Active Products</div>
      </div>
      <div class="scard" style="--dl:.05s">
        <div class="sc-top"><div class="sc-icon" style="background:var(--pud)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--pu)" stroke-width="1.8"><path d="M2 2h12v3H2zM2 7h12v3H2zM2 12h7"/></svg></div></div>
        <div class="sc-val"><?= $statCats ?></div>
        <div class="sc-lbl">Categories</div>
      </div>
      <div class="scard" style="--dl:.10s">
        <div class="sc-top"><div class="sc-icon" style="background:var(--red)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--re)" stroke-width="1.8"><path d="M8 1L1 13h14z"/><path d="M8 6v3M8 11v1"/></svg></div></div>
        <div class="sc-val" style="color:var(--re)"><?= $statLowStock ?></div>
        <div class="sc-lbl">Low Stock Alerts</div>
      </div>
      <div class="scard" style="--dl:.15s">
        <div class="sc-top"><div class="sc-icon" style="background:var(--god)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--go)" stroke-width="1.8"><circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 2"/></svg></div></div>
        <div class="sc-val" style="color:var(--go)"><?= $statExpiring ?></div>
        <div class="sc-lbl">Expiring ≤ 30 Days</div>
      </div>
      <div class="scard" style="--dl:.20s">
        <div class="sc-top"><div class="sc-icon" style="background:var(--god)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--go)" stroke-width="1.8"><path d="M8 1l1.8 3.6L14 5.6l-3 2.9.7 4.1L8 10.4l-3.7 2.2.7-4.1-3-2.9 4.2-.6z"/></svg></div></div>
        <div class="sc-val"><?= $statFeatured ?></div>
        <div class="sc-lbl">Featured Products</div>
      </div>
      <div class="scard" style="--dl:.25s">
        <div class="sc-top"><div class="sc-icon" style="background:var(--grd)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--gr)" stroke-width="1.8"><path d="M3 10l3 3 4-5 4 4"/></svg></div></div>
        <div class="sc-val"><?= fmt($totalStockVal) ?></div>
        <div class="sc-lbl">Stock Value</div>
      </div>
    </div>

    <!-- Tab Bar -->
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
      <div class="tab-bar">
        <a class="tab-btn <?= $tab==='products'?'on':'' ?>" href="products.php?tab=products">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 4l6-2 6 2v8l-6 2-6-2V4z"/></svg>
          Products
          <span class="tab-cnt"><?= number_format($statTotal) ?></span>
        </a>
        <a class="tab-btn <?= $tab==='categories'?'on':'' ?>" href="products.php?tab=categories">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 2h12v3H2zM2 7h12v3H2zM2 12h7"/></svg>
          Categories
          <span class="tab-cnt"><?= $statCats ?></span>
        </a>
      </div>

      <?php if ($tab === 'products' && ($statLowStock > 0 || $statExpiring > 0)): ?>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <?php if ($statLowStock > 0): ?>
        <a class="filter-chip" href="products.php?tab=products&pstatus=1&expiring=0" style="background:var(--red);border-color:rgba(248,113,113,.25);color:var(--re)">
          ⚠ <?= $statLowStock ?> low stock
        </a>
        <?php endif; ?>
        <?php if ($statExpiring > 0): ?>
        <a class="filter-chip" href="products.php?tab=products&expiring=1" style="background:var(--god);border-color:rgba(245,166,35,.25);color:var(--go)">
          ⏰ <?= $statExpiring ?> expiring soon
        </a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════
         CATEGORIES TAB
    ═══════════════════════════════════════════ -->
    <?php if ($tab === 'categories'): ?>
    <div class="panel">
      <div class="phead">
        <div><div class="ptitle">All Categories</div><div class="psub">Organise products into logical groups</div></div>
      </div>
      <div class="cat-grid">
        <?php
        $ci = 0;
        if ($categories && $categories->num_rows > 0):
          while ($cat = $categories->fetch_assoc()):
            [$clr, $cbg] = $catPalette[$ci % count($catPalette)];
            $inactive = !$cat['is_active'] ? 'inactive' : '';
        ?>
        <div class="cat-card <?= $inactive ?>">
          <div class="cc-top">
            <div class="cc-icon" style="background:<?= $cbg ?>">
              <svg viewBox="0 0 16 16" fill="none" stroke="<?= $clr ?>" stroke-width="1.8">
                <path d="M2 2h12v3H2zM2 7h12v3H2zM2 12h7"/>
              </svg>
            </div>
            <div style="flex:1;min-width:0">
              <div class="cc-name"><?= htmlspecialchars($cat['name']) ?></div>
              <?php if ($cat['description']): ?>
              <div class="cc-desc"><?= htmlspecialchars($cat['description']) ?></div>
              <?php endif; ?>
            </div>
          </div>
          <div class="cc-foot">
            <div class="cc-count">
              <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" width="13" height="13"><path d="M2 4l6-2 6 2v8l-6 2-6-2V4z"/></svg>
              <strong><?= $cat['product_count'] ?></strong> product<?= $cat['product_count'] != 1 ? 's' : '' ?>
              &nbsp;
              <?php if (!$cat['is_active']): ?>
                <span class="pill nt" style="font-size:.6rem">Inactive</span>
              <?php else: ?>
                <span class="pill ok" style="font-size:.6rem">Active</span>
              <?php endif; ?>
            </div>
            <?php if ($canEdit): ?>
            <div class="cat-actions">
              <button class="act-btn edit" title="Edit category" onclick='openEditCat(<?= json_encode($cat) ?>)'>
                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M11 2l3 3-9 9H2v-3z"/></svg>
              </button>
              <?php if ($canDelete && $cat['product_count'] == 0): ?>
              <button class="act-btn del" title="Delete category" onclick="openDelCat(<?= $cat['id'] ?>,'<?= htmlspecialchars($cat['name'],ENT_QUOTES) ?>')">
                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 4h12M5 4V2h6v2M6 7v5M10 7v5M3 4l1 10h8l1-10"/></svg>
              </button>
              <?php elseif ($cat['product_count'] > 0): ?>
              <button class="act-btn" title="Has products — move them first to delete" style="opacity:.35;cursor:not-allowed">
                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 4h12M5 4V2h6v2M6 7v5M10 7v5M3 4l1 10h8l1-10"/></svg>
              </button>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php $ci++; endwhile; endif; ?>

        <!-- Add Category Card -->
        <?php if ($canEdit): ?>
        <div class="add-cat-card" onclick="openAddCat()">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 1v14M1 8h14"/></svg>
          <span>New Category</span>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; /* end categories tab */ ?>

    <!-- ═══════════════════════════════════════════
         PRODUCTS TAB
    ═══════════════════════════════════════════ -->
    <?php if ($tab === 'products'): ?>

    <!-- Toolbar -->
    <form method="GET" action="products.php" id="filterForm">
      <input type="hidden" name="tab" value="products">
      <div class="toolbar">
        <div class="searchbox">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="7" cy="7" r="4.5"/><path d="M11 11l3 3"/></svg>
          <input type="text" name="q" placeholder="Name, barcode, brand…" value="<?= htmlspecialchars($pSearch) ?>" id="searchInp">
        </div>
        <select name="cat" class="sel" onchange="this.form.submit()">
          <option value="">All Categories</option>
          <?php if ($catOpts): $catOpts->data_seek(0); while ($co = $catOpts->fetch_assoc()): ?>
          <option value="<?= $co['id'] ?>" <?= $pCat==$co['id']?'selected':'' ?>><?= htmlspecialchars($co['name']) ?></option>
          <?php endwhile; endif; ?>
        </select>
        <select name="brand" class="sel" onchange="this.form.submit()">
          <option value="">All Brands</option>
          <?php if ($brandOpts): while ($bo = $brandOpts->fetch_assoc()): ?>
          <option value="<?= htmlspecialchars($bo['brand']) ?>" <?= $pBrand===$bo['brand']?'selected':'' ?>><?= htmlspecialchars($bo['brand']) ?></option>
          <?php endwhile; endif; ?>
        </select>
        <select name="pstatus" class="sel" onchange="this.form.submit()">
          <option value="">All Status</option>
          <option value="1" <?= $pStatus==='1'?'selected':'' ?>>Active</option>
          <option value="0" <?= $pStatus==='0'?'selected':'' ?>>Inactive</option>
        </select>
        <select name="featured" class="sel" onchange="this.form.submit()">
          <option value="">All</option>
          <option value="1" <?= $pFeatured==='1'?'selected':'' ?>>⭐ Featured</option>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="7" cy="7" r="4.5"/><path d="M11 11l3 3"/></svg>
          Filter
        </button>
        <?php if ($pSearch||$pCat||$pBrand||$pStatus!==''||$pFeatured||$pExpiring): ?>
        <a href="products.php?tab=products" class="btn btn-ghost btn-sm">Clear</a>
        <?php endif; ?>
        <div class="toolbar-right">
          <span class="count-lbl"><?= number_format($pTotal) ?> product<?= $pTotal!=1?'s':'' ?></span>
        </div>
      </div>
    </form>

    <!-- Products Table -->
    <div class="panel">
      <?php if ($products && $products->num_rows > 0): ?>
      <div style="overflow-x:auto">
        <table class="dtbl">
          <thead>
            <tr>
              <th>Product</th>
              <th>Category</th>
              <th>Brand</th>
              <th>Cost</th>
              <th>Sell Price</th>
              <th>Stock</th>
              <th>Expiry</th>
              <th>Status</th>
              <?php if ($canEdit): ?><th>Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
          <?php while ($prod = $products->fetch_assoc()):
            $stock   = (int)$prod['stock'];
            $avail   = (int)$prod['available'];
            $reorder = (int)$prod['reorder_level'];
            $minS    = (int)$prod['min_stock_level'];
            $stockPct = $minS > 0 ? min(round($avail/$minS*100), 100) : 100;
            $stockColor = $avail <= 0 ? 'var(--re)' : ($avail <= $reorder ? 'var(--re)' : ($avail <= $minS ? 'var(--go)' : 'var(--gr)'));
            $isExpiring = $prod['expiry_date'] && strtotime($prod['expiry_date']) <= strtotime('+30 days');
            $isExpired  = $prod['expiry_date'] && strtotime($prod['expiry_date']) < time();
            $margin     = $prod['cost_price'] > 0
                          ? round(($prod['selling_price'] - $prod['cost_price']) / $prod['selling_price'] * 100)
                          : 0;
            $img = $prod['image_path'] ? htmlspecialchars($prod['image_path']) : null;
          ?>
          <tr>
            <td>
              <div class="prod-cell">
                <div class="prod-thumb">
                  <?php if ($img && file_exists(__DIR__ . '/' . $img)): ?>
                    <img src="<?= $img ?>" alt="<?= htmlspecialchars($prod['name']) ?>">
                  <?php else: ?>
                    <?= strtoupper(substr($prod['name'],0,2)) ?>
                  <?php endif; ?>
                </div>
                <div>
                  <div class="prod-name"><?= htmlspecialchars($prod['name']) ?></div>
                  <?php if ($prod['barcode']): ?>
                  <div class="prod-bar"><?= htmlspecialchars($prod['barcode']) ?></div>
                  <?php endif; ?>
                </div>
                <?php if ($prod['is_featured']): ?>&nbsp;<span class="feat-star" title="Featured">★</span><?php endif; ?>
              </div>
            </td>
            <td>
              <?php if ($prod['category_name']): ?>
                <span class="pill info"><?= htmlspecialchars($prod['category_name']) ?></span>
              <?php else: ?>
                <span class="dim">—</span>
              <?php endif; ?>
            </td>
            <td class="dim"><?= htmlspecialchars($prod['brand'] ?? '—') ?></td>
            <td><span class="num"><?= fmt((float)$prod['cost_price']) ?></span></td>
            <td>
              <span class="num gr"><?= fmt((float)$prod['selling_price']) ?></span>
              <?php if ($margin > 0): ?>
              <div style="font-size:.66rem;color:var(--t2);margin-top:2px"><?= $margin ?>% margin</div>
              <?php endif; ?>
            </td>
            <td>
              <span class="num" style="color:<?= $stockColor ?>"><?= $avail ?></span>
              <div class="stock-bar">
                <div class="stock-fill" style="width:<?= max($stockPct,2) ?>%;background:<?= $stockColor ?>"></div>
              </div>
            </td>
            <td>
              <?php if ($prod['expiry_date']): ?>
                <?php if ($isExpired): ?>
                  <span class="pill err">Expired</span>
                <?php elseif ($isExpiring): ?>
                  <span class="pill warn"><?= date('M j Y', strtotime($prod['expiry_date'])) ?></span>
                <?php else: ?>
                  <span style="font-size:.77rem;color:var(--tx)"><?= date('M j Y', strtotime($prod['expiry_date'])) ?></span>
                <?php endif; ?>
              <?php else: ?>
                <span class="dim">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($prod['is_active']): ?>
                <span class="pill ok">Active</span>
              <?php else: ?>
                <span class="pill nt">Inactive</span>
              <?php endif; ?>
            </td>
            <?php if ($canEdit): ?>
            <td>
              <div class="actions">
                <button class="act-btn edit" title="Edit" onclick='openEditProd(<?= json_encode($prod) ?>)'>
                  <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M11 2l3 3-9 9H2v-3z"/></svg>
                </button>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="action" value="toggle_product">
                  <input type="hidden" name="prod_id" value="<?= $prod['id'] ?>">
                  <input type="hidden" name="is_active" value="<?= $prod['is_active'] ? 0 : 1 ?>">
                  <button type="submit" class="act-btn" title="<?= $prod['is_active']?'Deactivate':'Activate' ?>" style="color:<?= $prod['is_active']?'var(--go)':'var(--gr)' ?>">
                    <?php if ($prod['is_active']): ?>
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 1a7 7 0 100 14A7 7 0 008 1z"/><path d="M6 6v4M10 6v4"/></svg>
                    <?php else: ?>
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8" cy="8" r="7"/><path d="M5 8l2 2 4-4"/></svg>
                    <?php endif; ?>
                  </button>
                </form>
                <?php if ($canDelete): ?>
                <button class="act-btn del" title="Deactivate" onclick="openDelProd(<?= $prod['id'] ?>,'<?= htmlspecialchars($prod['name'],ENT_QUOTES) ?>')">
                  <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 4h12M5 4V2h6v2M6 7v5M10 7v5M3 4l1 10h8l1-10"/></svg>
                </button>
                <?php endif; ?>
              </div>
            </td>
            <?php endif; ?>
          </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php if ($pPages > 1): ?>
      <div class="pager">
        <span class="pager-info">Showing <?= $pOffset+1 ?>–<?= min($pOffset+$perPage,$pTotal) ?> of <?= $pTotal ?></span>
        <div class="pager-btns">
          <?php if ($pPage>1): ?><a class="pg-btn" href="<?= $qs ?>page=<?= $pPage-1 ?>">‹</a><?php endif; ?>
          <?php for ($p=max(1,$pPage-2);$p<=min($pPages,$pPage+2);$p++): ?>
            <a class="pg-btn <?= $p===$pPage?'on':'' ?>" href="<?= $qs ?>page=<?= $p ?>"><?= $p ?></a>
          <?php endfor; ?>
          <?php if ($pPage<$pPages): ?><a class="pg-btn" href="<?= $qs ?>page=<?= $pPage+1 ?>">›</a><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 4l6-2 6 2v8l-6 2-6-2V4z"/><path d="M8 2v12"/></svg></div>
        <div class="empty-title">No products found</div>
        <div class="empty-sub"><?= ($pSearch||$pCat||$pBrand||$pStatus!=='')?'Try adjusting your filters.':'Add your first product to get started.' ?></div>
      </div>
      <?php endif; ?>
    </div>

    <?php endif; /* end products tab */ ?>

  </div><!-- /content -->
</div><!-- /main -->


<!-- ════════════════════════════════════════════
     CATEGORY MODALS
════════════════════════════════════════════ -->
<?php if ($canEdit): ?>
<div class="overlay" id="catOverlay" onclick="closeModal(event,this)">
  <div class="modal sm">
    <div class="modal-head">
      <div><div class="modal-title" id="catMTitle">Add Category</div><div class="modal-sub" id="catMSub">Create a new product group</div></div>
      <button class="close-btn" onclick="closeAll()">✕</button>
    </div>
    <form method="POST" action="products.php">
      <input type="hidden" name="action" id="catAction" value="add_category">
      <input type="hidden" name="cat_id" id="catId" value="">
      <div class="modal-body">
        <div class="frow full">
          <div class="fgroup">
            <label class="flabel">Category Name <span class="req">*</span></label>
            <input type="text" name="cat_name" id="catName" class="finput" placeholder="e.g. Electronics" required>
          </div>
        </div>
        <div class="frow full" style="margin-top:10px">
          <div class="fgroup">
            <label class="flabel">Description</label>
            <textarea name="cat_desc" id="catDesc" class="ftextarea" placeholder="Optional description…"></textarea>
          </div>
        </div>
        <div class="frow full" style="margin-top:10px" id="catActiveRow" style="display:none">
          <label class="fcheck">
            <input type="checkbox" name="cat_active" id="catActive" checked>
            Category is active
          </label>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="closeAll()">Cancel</button>
        <button type="submit" class="btn btn-primary">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M2 8l4 4 8-8"/></svg>
          <span id="catSubmitTxt">Add Category</span>
        </button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($canDelete): ?>
<div class="overlay" id="delCatOverlay" onclick="closeModal(event,this)">
  <div class="modal sm">
    <div class="modal-body" style="padding:30px 26px 18px">
      <div class="cicon" style="background:var(--red)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--re)" stroke-width="1.8"><path d="M2 4h12M5 4V2h6v2M6 7v5M10 7v5M3 4l1 10h8l1-10"/></svg></div>
      <div class="ctitle">Delete Category?</div>
      <div class="csub" id="delCatMsg">This will permanently delete the category.</div>
    </div>
    <form method="POST" action="products.php">
      <input type="hidden" name="action" value="delete_category">
      <input type="hidden" name="cat_id" id="delCatId" value="">
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="closeAll()">Cancel</button>
        <button type="submit" class="btn btn-danger">Delete</button>
      </div>
    </form>
  </div>
</div>

<div class="overlay" id="delProdOverlay" onclick="closeModal(event,this)">
  <div class="modal sm">
    <div class="modal-body" style="padding:30px 26px 18px">
      <div class="cicon" style="background:var(--red)"><svg viewBox="0 0 16 16" fill="none" stroke="var(--re)" stroke-width="1.8"><path d="M2 4h12M5 4V2h6v2M6 7v5M10 7v5M3 4l1 10h8l1-10"/></svg></div>
      <div class="ctitle">Deactivate Product?</div>
      <div class="csub" id="delProdMsg">This product will be marked as inactive.</div>
    </div>
    <form method="POST" action="products.php">
      <input type="hidden" name="action" value="delete_product">
      <input type="hidden" name="prod_id" id="delProdId" value="">
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="closeAll()">Cancel</button>
        <button type="submit" class="btn btn-danger">Deactivate</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════════
     PRODUCT ADD / EDIT MODAL (with image upload)
════════════════════════════════════════════ -->
<?php if ($canEdit): ?>
<div class="overlay" id="prodOverlay" onclick="closeModal(event,this)">
  <div class="modal lg">
    <div class="modal-head">
      <div><div class="modal-title" id="prodMTitle">Add Product</div><div class="modal-sub" id="prodMSub">Fill in all product details</div></div>
      <button class="close-btn" onclick="closeAll()">✕</button>
    </div>
    <form method="POST" action="products.php" id="prodForm" enctype="multipart/form-data">
      <input type="hidden" name="action" id="prodAction" value="add_product">
      <input type="hidden" name="prod_id" id="prodId" value="">
      <div class="modal-body">

        <div class="fsection">Basic Information</div>
        <div class="frow full">
          <div class="fgroup">
            <label class="flabel">Product Name <span class="req">*</span></label>
            <input type="text" name="prod_name" id="pName" class="finput" placeholder="e.g. Wireless Keyboard Pro" required>
          </div>
        </div>
        <div class="frow">
          <div class="fgroup">
            <label class="flabel">Barcode / SKU</label>
            <input type="text" name="prod_barcode" id="pBarcode" class="finput" placeholder="e.g. 1234567890123">
          </div>
          <div class="fgroup">
            <label class="flabel">Brand</label>
            <input type="text" name="prod_brand" id="pBrand" class="finput" placeholder="e.g. Logitech">
          </div>
        </div>
        <div class="frow">
          <div class="fgroup">
            <label class="flabel">Category</label>
            <select name="prod_category" id="pCategory" class="fselect">
              <option value="">— No Category —</option>
              <?php if ($catOpts): $catOpts->data_seek(0); while ($co = $catOpts->fetch_assoc()): ?>
              <option value="<?= $co['id'] ?>"><?= htmlspecialchars($co['name']) ?></option>
              <?php endwhile; endif; ?>
            </select>
          </div>
          <div class="fgroup">
            <label class="flabel">Unit of Measure</label>
            <select name="prod_uom" id="pUom" class="fselect">
              <?php foreach ($uomOpts as $u): ?>
              <option value="<?= $u ?>"><?= ucfirst($u) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="frow full">
          <div class="fgroup">
            <label class="flabel">Description</label>
            <textarea name="prod_description" id="pDesc" class="ftextarea" placeholder="Product description…"></textarea>
          </div>
        </div>

        <div class="fsection">Pricing</div>
        <div class="frow three">
          <div class="fgroup">
            <label class="flabel">Cost Price ($) <span class="req">*</span></label>
            <input type="number" name="prod_cost" id="pCost" class="finput" min="0" step="0.01" placeholder="0.00" oninput="calcMargin()" required>
          </div>
          <div class="fgroup">
            <label class="flabel">Selling Price ($) <span class="req">*</span></label>
            <input type="number" name="prod_sell" id="pSell" class="finput" min="0" step="0.01" placeholder="0.00" oninput="calcMargin()" required>
            <div class="price-hint" id="marginHint">Margin: <strong id="marginVal">—</strong></div>
          </div>
          <div class="fgroup">
            <label class="flabel">Wholesale Price ($)</label>
            <input type="number" name="prod_wholesale" id="pWholesale" class="finput" min="0" step="0.01" placeholder="0.00">
          </div>
        </div>

        <div class="fsection">Inventory Thresholds</div>
        <div class="frow three">
          <div class="fgroup">
            <label class="flabel">Min Stock Level</label>
            <input type="number" name="prod_min" id="pMin" class="finput" min="0" value="10">
          </div>
          <div class="fgroup">
            <label class="flabel">Reorder Level</label>
            <input type="number" name="prod_reorder" id="pReorder" class="finput" min="0" value="20">
          </div>
          <div class="fgroup">
            <label class="flabel">Max Stock Level</label>
            <input type="number" name="prod_max" id="pMax" class="finput" min="0" value="5000">
          </div>
        </div>

        <div class="fsection">Additional Details</div>
        <div class="frow">
          <div class="fgroup">
            <label class="flabel">Units Per Case</label>
            <input type="number" name="prod_upc" id="pUpc" class="finput" min="1" value="1">
          </div>
          <div class="fgroup">
            <label class="flabel">Weight (kg)</label>
            <input type="number" name="prod_weight" id="pWeight" class="finput" min="0" step="0.001" placeholder="0.000">
          </div>
        </div>
        <div class="frow">
          <div class="fgroup">
            <label class="flabel">Expiry Date</label>
            <input type="date" name="prod_expiry" id="pExpiry" class="finput">
          </div>
          <div class="fgroup">
            <label class="flabel">Product Image</label>
            <input type="file" name="prod_image" id="pImage" class="finput" accept="image/jpeg,image/png,image/gif,image/webp">
            <div style="margin-top:5px" id="currentImage"></div>
          </div>
        </div>
        <div class="fcheck-row" style="margin-top:10px">
          <label class="fcheck"><input type="checkbox" name="prod_active" id="pActive" checked> Product is Active</label>
          <label class="fcheck"><input type="checkbox" name="prod_featured" id="pFeatured"> Mark as Featured ★</label>
        </div>

      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="closeAll()">Cancel</button>
        <button type="submit" class="btn btn-primary">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M2 8l4 4 8-8"/></svg>
          <span id="prodSubmitTxt">Add Product</span>
        </button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
// ── Theme ────────────────────────────────────────────────────
const html=document.documentElement,thi=document.getElementById('thi');
function applyTheme(t){html.dataset.theme=t;thi.textContent=t==='dark'?'☀️':'🌙';}
document.getElementById('thm').addEventListener('click',()=>{
  const nt=html.dataset.theme==='dark'?'light':'dark';
  applyTheme(nt);localStorage.setItem('pos_theme',nt);
});
(()=>{const sv=localStorage.getItem('pos_theme');if(sv)applyTheme(sv);})();

// ── Sidebar mobile ────────────────────────────────────────────
document.addEventListener('click',e=>{
  const sb=document.getElementById('sidebar');
  if(window.innerWidth<=900&&sb&&!sb.contains(e.target)&&!e.target.closest('.mob-btn'))
    sb.classList.remove('open');
});

// ── Modal helpers ─────────────────────────────────────────────
function closeModal(e,el){if(e.target===el)closeAll();}
function closeAll(){document.querySelectorAll('.overlay').forEach(o=>o.classList.remove('open'));document.body.style.overflow='';}
function openOverlay(id){document.getElementById(id).classList.add('open');document.body.style.overflow='hidden';}
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeAll();});

// ── Category modals ───────────────────────────────────────────
function openAddCat(){
  document.getElementById('catMTitle').textContent='Add Category';
  document.getElementById('catMSub').textContent='Create a new product group';
  document.getElementById('catAction').value='add_category';
  document.getElementById('catId').value='';
  document.getElementById('catName').value='';
  document.getElementById('catDesc').value='';
  document.getElementById('catActiveRow').style.display='none';
  document.getElementById('catSubmitTxt').textContent='Add Category';
  openOverlay('catOverlay');
}
function openEditCat(c){
  document.getElementById('catMTitle').textContent='Edit Category';
  document.getElementById('catMSub').textContent='#'+c.id+' — Update details';
  document.getElementById('catAction').value='edit_category';
  document.getElementById('catId').value=c.id;
  document.getElementById('catName').value=c.name||'';
  document.getElementById('catDesc').value=c.description||'';
  document.getElementById('catActiveRow').style.display='block';
  document.getElementById('catActive').checked=(c.is_active==1||c.is_active===true);
  document.getElementById('catSubmitTxt').textContent='Save Changes';
  openOverlay('catOverlay');
}
function openDelCat(id,name){
  document.getElementById('delCatId').value=id;
  document.getElementById('delCatMsg').textContent='Delete category "'+name+'"? Products in this category will be uncategorized.';
  openOverlay('delCatOverlay');
}

// ── Product modals ────────────────────────────────────────────
function openAddProd(){
  document.getElementById('prodMTitle').textContent='Add Product';
  document.getElementById('prodMSub').textContent='Fill in all product details';
  document.getElementById('prodAction').value='add_product';
  document.getElementById('prodId').value='';
  document.getElementById('prodForm').reset();
  document.getElementById('pMin').value=10;
  document.getElementById('pReorder').value=20;
  document.getElementById('pMax').value=5000;
  document.getElementById('pUpc').value=1;
  document.getElementById('pActive').checked=true;
  document.getElementById('prodSubmitTxt').textContent='Add Product';
  document.getElementById('marginHint').style.display='none';
  document.getElementById('currentImage').innerHTML='';
  openOverlay('prodOverlay');
}
function openEditProd(p){
  document.getElementById('prodMTitle').textContent='Edit Product';
  document.getElementById('prodMSub').textContent='#'+p.id+' — '+p.name;
  document.getElementById('prodAction').value='edit_product';
  document.getElementById('prodId').value=p.id;
  document.getElementById('pName').value=p.name||'';
  document.getElementById('pBarcode').value=p.barcode||'';
  document.getElementById('pBrand').value=p.brand||'';
  document.getElementById('pCategory').value=p.category_id||'';
  document.getElementById('pUom').value=p.unit_of_measure||'piece';
  document.getElementById('pDesc').value=p.description||'';
  document.getElementById('pCost').value=parseFloat(p.cost_price||0).toFixed(2);
  document.getElementById('pSell').value=parseFloat(p.selling_price||0).toFixed(2);
  document.getElementById('pWholesale').value=parseFloat(p.wholesale_price||0).toFixed(2);
  document.getElementById('pMin').value=p.min_stock_level||10;
  document.getElementById('pReorder').value=p.reorder_level||20;
  document.getElementById('pMax').value=p.max_stock_level||5000;
  document.getElementById('pUpc').value=p.units_per_case||1;
  document.getElementById('pWeight').value=p.weight_kg||'';
  document.getElementById('pExpiry').value=p.expiry_date||'';
  document.getElementById('pActive').checked=(p.is_active==1||p.is_active===true);
  document.getElementById('pFeatured').checked=(p.is_featured==1||p.is_featured===true);
  // Show current image if any
  if(p.image_path){
    document.getElementById('currentImage').innerHTML='<img src="'+p.image_path+'" class="image-preview">';
  } else {
    document.getElementById('currentImage').innerHTML='';
  }
  document.getElementById('prodSubmitTxt').textContent='Save Changes';
  calcMargin();
  openOverlay('prodOverlay');
}
function openDelProd(id,name){
  document.getElementById('delProdId').value=id;
  document.getElementById('delProdMsg').textContent='Deactivate "'+name+'"? You can reactivate it later.';
  openOverlay('delProdOverlay');
}

// ── Margin calculator ─────────────────────────────────────────
function calcMargin(){
  const cost=parseFloat(document.getElementById('pCost').value)||0;
  const sell=parseFloat(document.getElementById('pSell').value)||0;
  const hint=document.getElementById('marginHint');
  if(cost>0&&sell>0){
    const m=((sell-cost)/sell*100).toFixed(1);
    const profit=(sell-cost).toFixed(2);
    document.getElementById('marginVal').textContent=m+'%  (+$'+profit+')';
    document.getElementById('marginVal').style.color=sell>=cost?'var(--gr)':'var(--re)';
    hint.style.display='block';
  } else { hint.style.display='none'; }
}

// ── Flash auto-dismiss ────────────────────────────────────────
setTimeout(()=>{
  const f=document.querySelector('.flash');
  if(f){f.style.transition='opacity .5s';f.style.opacity='0';setTimeout(()=>f.remove(),500);}
},4500);
</script>
</body>
</html>