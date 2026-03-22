<?php
/**
 * MIAUDITOPS — Capital Allowance API
 * CRUD for CA records, entries, and rates. Integrates with fixed_assets.
 */
require_once '../includes/functions.php';
header('Content-Type: application/json');
if (!is_logged_in()) { echo json_encode(['success' => false, 'message' => 'Not authenticated']); exit; }

$company_id = $_SESSION['company_id'];
$client_id  = get_active_client();
$user_id    = $_SESSION['user_id'];
$action     = $_POST['action'] ?? $_GET['action'] ?? '';
function ca_clean($d) { return trim(stripslashes($d)); }

// ── Auto-create tables ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS capital_allowance_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        client_id INT NOT NULL,
        title VARCHAR(255) NOT NULL DEFAULT '',
        mode VARCHAR(20) DEFAULT 'manual',
        start_year INT DEFAULT 2020,
        end_year INT DEFAULT 2025,
        status VARCHAR(20) DEFAULT 'draft',
        notes TEXT DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_cc (company_id, client_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS capital_allowance_rates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        client_id INT NOT NULL,
        category VARCHAR(100) NOT NULL,
        ia_rate DECIMAL(5,2) DEFAULT 15,
        aa_rate DECIMAL(5,2) DEFAULT 0,
        sort_order INT DEFAULT 0,
        KEY idx_cc (company_id, client_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS capital_allowance_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        record_id INT NOT NULL,
        category VARCHAR(100) DEFAULT '',
        year INT DEFAULT 2020,
        type VARCHAR(20) DEFAULT 'addition',
        amount DECIMAL(15,2) DEFAULT 0,
        description VARCHAR(255) DEFAULT '',
        KEY idx_rid (record_id),
        KEY idx_year (year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Seed default rates if none exist
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM capital_allowance_rates WHERE company_id=? AND client_id=?");
    $cnt->execute([$company_id, $client_id]);
    if ($cnt->fetchColumn() == 0) {
        $defaults = [
            ['Land', 0, 0, 1],
            ['Building Improvement', 15, 10, 2],
            ['Furniture & Fittings', 15, 20, 3],
            ['Plant', 15, 25, 4],
            ['Office Equipment', 15, 20, 5],
            ['Motor Vehicles', 15, 25, 6],
        ];
        $ins = $pdo->prepare("INSERT INTO capital_allowance_rates (company_id, client_id, category, ia_rate, aa_rate, sort_order) VALUES (?,?,?,?,?,?)");
        foreach ($defaults as $d) { $ins->execute([$company_id, $client_id, $d[0], $d[1], $d[2], $d[3]]); }
    }
} catch (Exception $e) {}

// ── LIST RATES ──
if ($action === 'list_rates') {
    $stmt = $pdo->prepare("SELECT * FROM capital_allowance_rates WHERE company_id=? AND client_id=? ORDER BY sort_order, category");
    $stmt->execute([$company_id, $client_id]);
    echo json_encode(['success' => true, 'rates' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ── SAVE RATE ──
if ($action === 'save_rate') {
    $id = intval($_POST['id'] ?? 0);
    $cat = ca_clean($_POST['category'] ?? '');
    $ia = floatval($_POST['ia_rate'] ?? 0);
    $aa = floatval($_POST['aa_rate'] ?? 0);
    $sort = intval($_POST['sort_order'] ?? 0);
    if (!$cat) { echo json_encode(['success' => false, 'message' => 'Category required']); exit; }
    if ($id > 0) {
        $pdo->prepare("UPDATE capital_allowance_rates SET category=?, ia_rate=?, aa_rate=?, sort_order=? WHERE id=? AND company_id=?")->execute([$cat, $ia, $aa, $sort, $id, $company_id]);
    } else {
        $pdo->prepare("INSERT INTO capital_allowance_rates (company_id, client_id, category, ia_rate, aa_rate, sort_order) VALUES (?,?,?,?,?,?)")->execute([$company_id, $client_id, $cat, $ia, $aa, $sort]);
    }
    echo json_encode(['success' => true]);
    exit;
}

// ── DELETE RATE ──
if ($action === 'delete_rate') {
    $id = intval($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM capital_allowance_rates WHERE id=? AND company_id=?")->execute([$id, $company_id]);
    echo json_encode(['success' => true]);
    exit;
}

// ── LIST RECORDS ──
if ($action === 'list') {
    $stmt = $pdo->prepare("SELECT r.*, u.first_name FROM capital_allowance_records r LEFT JOIN users u ON r.created_by=u.id WHERE r.company_id=? AND r.client_id=? ORDER BY r.created_at DESC");
    $stmt->execute([$company_id, $client_id]);
    echo json_encode(['success' => true, 'records' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ── CREATE RECORD ──
if ($action === 'create') {
    $title = ca_clean($_POST['title'] ?? '');
    if (!$title) { echo json_encode(['success' => false, 'message' => 'Title required']); exit; }
    $stmt = $pdo->prepare("INSERT INTO capital_allowance_records (company_id, client_id, title, mode, start_year, end_year, notes, created_by) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $company_id, $client_id, $title,
        ca_clean($_POST['mode'] ?? 'manual'),
        intval($_POST['start_year'] ?? date('Y')),
        intval($_POST['end_year'] ?? date('Y')),
        $_POST['notes'] ?? '',
        $user_id
    ]);
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    exit;
}

// ── GET RECORD (with entries) ──
if ($action === 'get') {
    $id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM capital_allowance_records WHERE id=? AND company_id=?");
    $stmt->execute([$id, $company_id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$record) { echo json_encode(['success' => false, 'message' => 'Not found']); exit; }

    // Get entries
    $eStmt = $pdo->prepare("SELECT * FROM capital_allowance_entries WHERE record_id=? ORDER BY year, category, type");
    $eStmt->execute([$id]);
    $entries = $eStmt->fetchAll(PDO::FETCH_ASSOC);

    // If asset_register mode, also fetch assets
    $assets = [];
    if ($record['mode'] === 'asset_register') {
        $aStmt = $pdo->prepare("SELECT * FROM fixed_assets WHERE company_id=? AND client_id=? ORDER BY category, asset_name");
        $aStmt->execute([$company_id, $client_id]);
        $assets = $aStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode(['success' => true, 'record' => $record, 'entries' => $entries, 'assets' => $assets]);
    exit;
}

// ── SAVE RECORD (update meta) ──
if ($action === 'save') {
    $id = intval($_POST['id'] ?? 0);
    $pdo->prepare("UPDATE capital_allowance_records SET title=?, start_year=?, end_year=?, status=?, notes=? WHERE id=? AND company_id=?")->execute([
        ca_clean($_POST['title'] ?? ''),
        intval($_POST['start_year'] ?? date('Y')),
        intval($_POST['end_year'] ?? date('Y')),
        ca_clean($_POST['status'] ?? 'draft'),
        $_POST['notes'] ?? '',
        $id, $company_id
    ]);
    echo json_encode(['success' => true]);
    exit;
}

// ── SAVE ENTRIES (bulk replace for a record) ──
if ($action === 'save_entries') {
    $rid = intval($_POST['record_id'] ?? 0);
    $entries = json_decode($_POST['entries'] ?? '[]', true);
    if (!$rid) { echo json_encode(['success' => false, 'message' => 'Record ID required']); exit; }

    // Delete existing entries for this record and re-insert
    $pdo->prepare("DELETE FROM capital_allowance_entries WHERE record_id=?")->execute([$rid]);
    $ins = $pdo->prepare("INSERT INTO capital_allowance_entries (record_id, category, year, type, amount, description) VALUES (?,?,?,?,?,?)");
    foreach ($entries as $e) {
        $ins->execute([
            $rid,
            $e['category'] ?? '',
            intval($e['year'] ?? 0),
            $e['type'] ?? 'addition',
            floatval($e['amount'] ?? 0),
            $e['description'] ?? ''
        ]);
    }
    echo json_encode(['success' => true]);
    exit;
}

// ── DELETE RECORD ──
if ($action === 'delete') {
    $id = intval($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM capital_allowance_entries WHERE record_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM capital_allowance_records WHERE id=? AND company_id=?")->execute([$id, $company_id]);
    echo json_encode(['success' => true]);
    exit;
}

// ── FETCH FIXED ASSET CATEGORIES (for mode selection) ──
if ($action === 'asset_categories') {
    $stmt = $pdo->prepare("SELECT * FROM fixed_asset_categories WHERE company_id=? AND client_id=? ORDER BY sort_order, name");
    $stmt->execute([$company_id, $client_id]);
    echo json_encode(['success' => true, 'categories' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
