<?php
/**
 * DEBUG: Lube Stock Unsold Breakdown
 * Access via: /MIIAUDITOPS/dashboard/debug_lube.php?session=8
 * DELETE THIS FILE AFTER DEBUGGING
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    // Fallback: try to get PDO from the config
    require_once __DIR__ . '/../config/database.php';
}
$company_id = $_SESSION['company_id'] ?? 0;

$session_id = intval($_GET['session'] ?? 0);
if (!$session_id) die('Usage: ?session=8');

// Get session info
$sess = $pdo->prepare("SELECT s.*, co.name as outlet_name FROM station_audit_sessions s LEFT JOIN client_outlets co ON s.outlet_id=co.id WHERE s.id=?");
$sess->execute([$session_id]);
$session = $sess->fetch(PDO::FETCH_ASSOC);
if (!$session) die('Session not found');
$company_id = $session['company_id'];
?>
<!DOCTYPE html>
<html><head><title>Debug: Lube Unsold</title>
<style>
body { font-family: monospace; padding: 20px; background: #1e1e2e; color: #cdd6f4; }
h1,h2,h3 { color: #89b4fa; }
table { border-collapse: collapse; margin: 10px 0; }
th,td { border: 1px solid #45475a; padding: 6px 12px; text-align: right; }
th { background: #313244; color: #f5c2e7; }
.total { font-weight: bold; color: #a6e3a1; font-size: 1.1em; }
.warn { color: #f38ba8; font-weight: bold; }
.info { color: #89dceb; }
.section { background: #313244; padding: 15px; border-radius: 8px; margin: 15px 0; }
</style>
</head><body>
<h1>🔍 Lube Stock Unsold Debug</h1>
<div class="info">
    Session: <strong>#<?= $session_id ?></strong> |
    Outlet: <strong><?= htmlspecialchars($session['outlet_name'] ?? 'N/A') ?></strong> |
    Period: <strong><?= $session['date_from'] ?> → <?= $session['date_to'] ?></strong> |
    Company ID: <strong><?= $company_id ?></strong>
</div>

<?php
// ═══ STORE STOCK COUNTS ═══
$sc_stmt = $pdo->prepare("SELECT * FROM station_lube_stock_counts WHERE session_id=? AND company_id=? ORDER BY date_to DESC");
$sc_stmt->execute([$session_id, $company_id]);
$storeCounts = $sc_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="section">
<h2>📦 Store Stock Counts (<?= count($storeCounts) ?> total)</h2>
<?php foreach ($storeCounts as $i => $sc): ?>
    <p>[<?= $i ?>] ID:<?= $sc['id'] ?> | Period: <?= $sc['date_from'] ?> → <?= $sc['date_to'] ?> | Status: <?= $sc['status'] ?? 'open' ?></p>
<?php endforeach; ?>

<?php
$storeTotal = 0;
if (count($storeCounts) > 0):
    $latest = $storeCounts[0];
    $items_stmt = $pdo->prepare("SELECT * FROM station_lube_stock_count_items WHERE count_id=? AND company_id=?");
    $items_stmt->execute([$latest['id'], $company_id]);
    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
    <h3>★ USING LATEST: ID:<?= $latest['id'] ?> (<?= $latest['date_from'] ?> → <?= $latest['date_to'] ?>)</h3>
    <table>
        <tr><th>Product</th><th>Physical Count</th><th>Cost Price</th><th>Value</th></tr>
        <?php foreach ($items as $it):
            $closing = intval($it['physical_count'] ?? 0);
            $cp = floatval($it['cost_price'] ?? 0);
            $val = $closing * $cp;
            $storeTotal += $val;
        ?>
        <tr>
            <td style="text-align:left"><?= htmlspecialchars($it['product_name'] ?? '') ?></td>
            <td><?= $closing ?></td>
            <td>₦<?= number_format($cp, 2) ?></td>
            <td>₦<?= number_format($val, 2) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="total">
            <td colspan="3">STORE SUBTOTAL</td>
            <td>₦<?= number_format($storeTotal, 2) ?></td>
        </tr>
    </table>
<?php else: ?>
    <p class="warn">⚠️ No store stock counts found → ₦0</p>
<?php endif; ?>
</div>

<?php
// ═══ COUNTER STOCK COUNTS ═══
// Get all sections for this session
$sec_stmt = $pdo->prepare("SELECT * FROM station_lube_sections WHERE session_id=? AND company_id=? ORDER BY sort_order");
$sec_stmt->execute([$session_id, $company_id]);
$sections = $sec_stmt->fetchAll(PDO::FETCH_ASSOC);

$counterTotal = 0;
?>

<div class="section">
<h2>🏪 Counter Stock Counts (<?= count($sections) ?> counter(s))</h2>

<?php foreach ($sections as $sec):
    $csc_stmt = $pdo->prepare("SELECT * FROM station_counter_stock_counts WHERE section_id=? AND company_id=? ORDER BY date_to DESC");
    $csc_stmt->execute([$sec['id'], $company_id]);
    $counterCounts = $csc_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
    <h3><?= htmlspecialchars($sec['name']) ?> (section_id=<?= $sec['id'] ?>) — <?= count($counterCounts) ?> stock count(s)</h3>
    <?php foreach ($counterCounts as $i => $cc): ?>
        <p style="margin-left:20px">[<?= $i ?>] ID:<?= $cc['id'] ?> | Period: <?= $cc['date_from'] ?> → <?= $cc['date_to'] ?></p>
    <?php endforeach; ?>

    <?php if (count($counterCounts) > 0):
        $clatest = $counterCounts[0];
        $citems_stmt = $pdo->prepare("SELECT * FROM station_counter_stock_count_items WHERE count_id=? AND company_id=?");
        $citems_stmt->execute([$clatest['id'], $company_id]);
        $citems = $citems_stmt->fetchAll(PDO::FETCH_ASSOC);
        $sectionTotal = 0;
    ?>
        <p class="info" style="margin-left:20px">★ USING: ID:<?= $clatest['id'] ?> (<?= $clatest['date_from'] ?> → <?= $clatest['date_to'] ?>)</p>
        <table style="margin-left:20px">
            <tr><th>Product</th><th>Physical Count</th><th>Cost Price</th><th>Value</th></tr>
            <?php foreach ($citems as $cit):
                $cclosing = intval($cit['physical_count'] ?? 0);
                $ccp = floatval($cit['cost_price'] ?? 0);
                $cval = $cclosing * $ccp;
                $sectionTotal += $cval;
            ?>
            <tr>
                <td style="text-align:left"><?= htmlspecialchars($cit['product_name'] ?? '') ?></td>
                <td><?= $cclosing ?></td>
                <td>₦<?= number_format($ccp, 2) ?></td>
                <td>₦<?= number_format($cval, 2) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="total">
                <td colspan="3">SECTION SUBTOTAL</td>
                <td>₦<?= number_format($sectionTotal, 2) ?></td>
            </tr>
        </table>
        <?php $counterTotal += $sectionTotal; ?>
    <?php else: ?>
        <p class="warn" style="margin-left:20px">No stock counts for this counter</p>
    <?php endif; ?>
<?php endforeach; ?>

<p class="total">COUNTER GRAND SUBTOTAL: ₦<?= number_format($counterTotal, 2) ?></p>
</div>

<?php $grandTotal = $storeTotal + $counterTotal; ?>

<div class="section" style="border: 2px solid #89b4fa;">
<h2>═══ FINAL BREAKDOWN ═══</h2>
<table>
    <tr><td style="text-align:left">STORE</td><td class="total">₦<?= number_format($storeTotal, 2) ?></td></tr>
    <tr><td style="text-align:left">COUNTER</td><td class="total">₦<?= number_format($counterTotal, 2) ?></td></tr>
    <tr style="border-top:3px solid #89b4fa"><td style="text-align:left"><strong>TOTAL (Lube Stock Unsold)</strong></td><td class="total" style="font-size:1.3em">₦<?= number_format($grandTotal, 2) ?></td></tr>
</table>
</div>

<p style="color:#6c7086; margin-top:30px">⚠️ DELETE this file after debugging: dashboard/debug_lube.php</p>
</body></html>
