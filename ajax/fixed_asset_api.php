<?php
/**
 * MIAUDITOPS — Fixed Asset API
 * CRUD for assets and categories with auto-create tables.
 */
require_once '../includes/functions.php';
header('Content-Type: application/json');
if (!is_logged_in()) { echo json_encode(['success' => false, 'message' => 'Not authenticated']); exit; }

function fa_clean($d) { return trim(stripslashes($d)); }

$company_id = $_SESSION['company_id'];
$client_id  = get_active_client();
$user_id    = $_SESSION['user_id'];
$action     = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Auto-create tables ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fixed_asset_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        client_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        dep_rate DECIMAL(5,2) DEFAULT 0,
        sort_order INT DEFAULT 0,
        KEY idx_cc (company_id, client_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fixed_assets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        client_id INT NOT NULL,
        asset_name VARCHAR(255) NOT NULL DEFAULT '',
        asset_code VARCHAR(50) DEFAULT '',
        category VARCHAR(100) DEFAULT '',
        purchase_date DATE DEFAULT NULL,
        cost DECIMAL(15,2) DEFAULT 0,
        salvage_value DECIMAL(15,2) DEFAULT 0,
        serial_number VARCHAR(100) DEFAULT '',
        location VARCHAR(255) DEFAULT '',
        status VARCHAR(30) DEFAULT 'active',
        disposal_date DATE DEFAULT NULL,
        disposal_amount DECIMAL(15,2) DEFAULT 0,
        notes TEXT DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_cc (company_id, client_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Seed default categories if none exist for this client
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM fixed_asset_categories WHERE company_id=? AND client_id=?");
    $cnt->execute([$company_id, $client_id]);
    if ($cnt->fetchColumn() == 0) {
        $defaults = [
            ['Land', 0, 1],
            ['Building Improvement', 10, 2],
            ['Furniture & Fittings', 10, 3],
            ['Plant', 15, 4],
            ['Office Equipment', 10, 5],
            ['Motor Vehicles', 20, 6],
        ];
        $ins = $pdo->prepare("INSERT INTO fixed_asset_categories (company_id, client_id, name, dep_rate, sort_order) VALUES (?,?,?,?,?)");
        foreach ($defaults as $d) { $ins->execute([$company_id, $client_id, $d[0], $d[1], $d[2]]); }
    }
} catch (Exception $e) {}

// ── LIST CATEGORIES ──
if ($action === 'list_categories') {
    $stmt = $pdo->prepare("SELECT * FROM fixed_asset_categories WHERE company_id=? AND client_id=? ORDER BY sort_order, name");
    $stmt->execute([$company_id, $client_id]);
    echo json_encode(['success' => true, 'categories' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ── SAVE CATEGORY ──
if ($action === 'save_category') {
    $id   = intval($_POST['id'] ?? 0);
    $name = fa_clean($_POST['name'] ?? '');
    $rate = floatval($_POST['dep_rate'] ?? 0);
    $sort = intval($_POST['sort_order'] ?? 0);
    if (!$name) { echo json_encode(['success' => false, 'message' => 'Name required']); exit; }
    if ($id > 0) {
        $pdo->prepare("UPDATE fixed_asset_categories SET name=?, dep_rate=?, sort_order=? WHERE id=? AND company_id=?")->execute([$name, $rate, $sort, $id, $company_id]);
    } else {
        $pdo->prepare("INSERT INTO fixed_asset_categories (company_id, client_id, name, dep_rate, sort_order) VALUES (?,?,?,?,?)")->execute([$company_id, $client_id, $name, $rate, $sort]);
    }
    echo json_encode(['success' => true]);
    exit;
}

// ── DELETE CATEGORY ──
if ($action === 'delete_category') {
    $id = intval($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM fixed_asset_categories WHERE id=? AND company_id=?")->execute([$id, $company_id]);
    echo json_encode(['success' => true]);
    exit;
}

// ── LIST ASSETS ──
if ($action === 'list') {
    $stmt = $pdo->prepare("SELECT a.*, u.first_name FROM fixed_assets a LEFT JOIN users u ON a.created_by=u.id WHERE a.company_id=? AND a.client_id=? ORDER BY a.category, a.asset_name");
    $stmt->execute([$company_id, $client_id]);
    echo json_encode(['success' => true, 'assets' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ── CREATE ASSET ──
if ($action === 'create') {
    $name = fa_clean($_POST['asset_name'] ?? '');
    if (!$name) { echo json_encode(['success' => false, 'message' => 'Asset name required']); exit; }
    $stmt = $pdo->prepare("INSERT INTO fixed_assets (company_id, client_id, asset_name, asset_code, category, purchase_date, cost, salvage_value, serial_number, location, status, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $company_id, $client_id, $name,
        fa_clean($_POST['asset_code'] ?? ''),
        fa_clean($_POST['category'] ?? ''),
        fa_clean($_POST['purchase_date'] ?? date('Y-m-d')),
        floatval($_POST['cost'] ?? 0),
        floatval($_POST['salvage_value'] ?? 0),
        fa_clean($_POST['serial_number'] ?? ''),
        fa_clean($_POST['location'] ?? ''),
        fa_clean($_POST['status'] ?? 'active'),
        $_POST['notes'] ?? '',
        $user_id
    ]);
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    exit;
}

// ── UPDATE ASSET ──
if ($action === 'update') {
    $id = intval($_POST['id'] ?? 0);
    $stmt = $pdo->prepare("UPDATE fixed_assets SET asset_name=?, asset_code=?, category=?, purchase_date=?, cost=?, salvage_value=?, serial_number=?, location=?, status=?, disposal_date=?, disposal_amount=?, notes=? WHERE id=? AND company_id=?");
    $stmt->execute([
        fa_clean($_POST['asset_name'] ?? ''),
        fa_clean($_POST['asset_code'] ?? ''),
        fa_clean($_POST['category'] ?? ''),
        fa_clean($_POST['purchase_date'] ?? date('Y-m-d')),
        floatval($_POST['cost'] ?? 0),
        floatval($_POST['salvage_value'] ?? 0),
        fa_clean($_POST['serial_number'] ?? ''),
        fa_clean($_POST['location'] ?? ''),
        fa_clean($_POST['status'] ?? 'active'),
        $_POST['disposal_date'] ?: null,
        floatval($_POST['disposal_amount'] ?? 0),
        $_POST['notes'] ?? '',
        $id, $company_id
    ]);
    echo json_encode(['success' => true]);
    exit;
}

// ── DELETE ASSET ──
if ($action === 'delete') {
    $id = intval($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM fixed_assets WHERE id=? AND company_id=?")->execute([$id, $company_id]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
