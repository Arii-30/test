<?php
// stock_history.php — AJAX endpoint for stock transaction history modal
require_once 'connection.php';
session_start();
if (!isset($_SESSION['user_id'])) { echo '<div style="color:var(--re);padding:20px">Unauthorized</div>'; exit; }

$pid = (int)($_GET['pid'] ?? 0);
if (!$pid) { echo '<div style="padding:20px;color:var(--t3)">Invalid product.</div>'; exit; }

// Product info
$pr = $conn->query("SELECT p.name, p.unit_of_measure,
    COALESCE(i.quantity_in_stock,0) AS stock,
    COALESCE(i.quantity_available,0) AS avail,
    COALESCE(i.quantity_reserved,0) AS reserved
    FROM products p LEFT JOIN inventory i ON i.product_id=p.id WHERE p.id=$pid");
$prod = $pr ? $pr->fetch_assoc() : null;
if (!$prod) { echo '<div style="padding:20px;color:var(--t3)">Product not found.</div>'; exit; }

// Transactions
$txns = $conn->query("SELECT it.*, 'System' AS done_by
    FROM inventory_transactions it
    WHERE it.product_id=$pid
    ORDER BY it.created_at DESC LIMIT 50");

$txnConfig = [
    'purchase'   => ['var(--grd)','var(--gr)',  '+',  'ok',    'Purchase'],
    'sale'       => ['var(--ag)', 'var(--ac)',  '−',  'info',  'Sale'],
    'damage'     => ['var(--red)','var(--re)',  '−',  'err',   'Damage'],
    'adjustment' => ['var(--god)','var(--go)',  '±',  'warn',  'Adjustment'],
    'return'     => ['var(--pud)','var(--pu)',  '+',  'pu',    'Return'],
    'transfer'   => ['var(--ted)','var(--te)',  '→',  'nt',    'Transfer'],
];
?>
<style>
  .htbl{width:100%;border-collapse:collapse}
  .htbl th{font-size:.63rem;color:var(--t3);text-transform:uppercase;letter-spacing:.09em;padding:10px 16px;text-align:left;border-bottom:1px solid var(--br);font-weight:700;white-space:nowrap}
  .htbl td{padding:10px 16px;font-size:.79rem;border-bottom:1px solid var(--br);vertical-align:middle}
  .htbl tr:last-child td{border-bottom:none}
  .htbl tbody tr:hover td{background:rgba(255,255,255,.02)}
  .ht-icon{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:.78rem;font-weight:800;flex-shrink:0}
  .pill{display:inline-flex;align-items:center;padding:2px 8px;border-radius:100px;font-size:.64rem;font-weight:700;white-space:nowrap}
  .pill.ok  {background:var(--grd);color:var(--gr)}
  .pill.warn{background:var(--god);color:var(--go)}
  .pill.err {background:var(--red);color:var(--re)}
  .pill.info{background:var(--ag); color:var(--ac)}
  .pill.pu  {background:var(--pud);color:var(--pu)}
  .pill.nt  {background:var(--bg3);color:var(--t3)}
</style>

<!-- Product summary strip -->
<div style="display:flex;gap:24px;padding:16px 20px;border-bottom:1px solid var(--br);background:var(--bg3)">
  <div>
    <div style="font-size:.65rem;color:var(--t3);font-weight:600;text-transform:uppercase;letter-spacing:.08em">In Stock</div>
    <div style="font-size:1.1rem;font-weight:800;color:var(--tx);margin-top:3px"><?= number_format($prod['stock']) ?> <span style="font-size:.72rem;font-weight:500;color:var(--t2)"><?= htmlspecialchars($prod['unit_of_measure']??'pc') ?></span></div>
  </div>
  <div>
    <div style="font-size:.65rem;color:var(--t3);font-weight:600;text-transform:uppercase;letter-spacing:.08em">Available</div>
    <div style="font-size:1.1rem;font-weight:800;color:var(--gr);margin-top:3px"><?= number_format($prod['avail']) ?></div>
  </div>
  <div>
    <div style="font-size:.65rem;color:var(--t3);font-weight:600;text-transform:uppercase;letter-spacing:.08em">Reserved</div>
    <div style="font-size:1.1rem;font-weight:800;color:var(--pu);margin-top:3px"><?= number_format($prod['reserved']) ?></div>
  </div>
</div>

<?php if ($txns && $txns->num_rows > 0): ?>
<div style="overflow-x:auto">
  <table class="htbl">
    <thead>
      <tr>
        <th>Type</th>
        <th>Qty</th>
        <th>Reference</th>
        <th>Notes</th>
        <th>By</th>
        <th>Date</th>
      </tr>
    </thead>
    <tbody>
    <?php while ($t = $txns->fetch_assoc()):
      $tc = $txnConfig[$t['transaction_type']] ?? ['var(--bg3)','var(--t2)','±','nt','Move'];
      [$tbg,$tclr,$tsym,$tpill,$tlbl] = $tc;
      $qty = (int)$t['quantity'];
      $isPos = $qty >= 0;
    ?>
    <tr>
      <td>
        <div style="display:flex;align-items:center;gap:8px">
          <div class="ht-icon" style="background:<?= $tbg ?>;color:<?= $tclr ?>"><?= $tsym ?></div>
          <span class="pill <?= $tpill ?>"><?= $tlbl ?></span>
        </div>
      </td>
      <td>
        <span style="font-size:.88rem;font-weight:800;font-variant-numeric:tabular-nums;color:<?= $isPos?'var(--gr)':'var(--re)' ?>">
          <?= $isPos?'+':'' ?><?= number_format($qty) ?>
        </span>
      </td>
      <td style="color:var(--t2);font-family:monospace;font-size:.76rem"><?= htmlspecialchars($t['reference_number']??'—') ?></td>
      <td style="color:var(--t2);max-width:180px">
        <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;max-width:180px" title="<?= htmlspecialchars($t['notes']??'') ?>">
          <?= htmlspecialchars($t['notes']??'—') ?>
        </span>
      </td>
      <td style="color:var(--t2);font-size:.75rem"><?= htmlspecialchars($t['done_by']??'System') ?></td>
      <td style="color:var(--t2);font-size:.75rem;white-space:nowrap">
        <?= $t['created_at'] ? date('M j Y, g:i a', strtotime($t['created_at'])) : '—' ?>
      </td>
    </tr>
    <?php endwhile; ?>
    </tbody>
  </table>
</div>
<?php else: ?>
<div style="padding:36px;text-align:center;color:var(--t3);font-size:.82rem">
  No transactions recorded for this product yet.
</div>
<?php endif; ?>
