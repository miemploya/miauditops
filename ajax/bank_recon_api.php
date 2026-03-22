<?php
/**
 * MIAUDITOPS — Bank Reconciliation API
 * CRUD for bank reconciliation statements (Nigerian standard format).
 */
require_once '../includes/functions.php';
header('Content-Type: application/json');
if (!is_logged_in()) { echo json_encode(['success' => false, 'message' => 'Not authenticated']); exit; }

function br_clean($d) { return trim(stripslashes($d)); }

$company_id = $_SESSION['company_id'];
$client_id  = get_active_client();
$user_id    = $_SESSION['user_id'];
$action     = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Auto-create table ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS bank_reconciliations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        client_id INT NOT NULL,
        title VARCHAR(255) DEFAULT '',
        bank_name VARCHAR(255) DEFAULT '',
        account_number VARCHAR(50) DEFAULT '',
        statement_date DATE DEFAULT NULL,
        bank_balance DECIMAL(15,2) DEFAULT 0,
        cashbook_balance DECIMAL(15,2) DEFAULT 0,
        add_items JSON DEFAULT NULL,
        less_items JSON DEFAULT NULL,
        cb_debits JSON DEFAULT NULL,
        cb_credits JSON DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        status VARCHAR(20) DEFAULT 'draft',
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_cc (company_id, client_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Migration: add new columns if table exists from old schema
    $cols = $pdo->query("SHOW COLUMNS FROM bank_reconciliations")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('cashbook_balance', $cols)) $pdo->exec("ALTER TABLE bank_reconciliations ADD COLUMN cashbook_balance DECIMAL(15,2) DEFAULT 0 AFTER bank_balance");
    if (!in_array('add_items', $cols)) $pdo->exec("ALTER TABLE bank_reconciliations ADD COLUMN add_items JSON DEFAULT NULL AFTER cashbook_balance");
    if (!in_array('less_items', $cols)) $pdo->exec("ALTER TABLE bank_reconciliations ADD COLUMN less_items JSON DEFAULT NULL AFTER add_items");
    if (!in_array('cb_debits', $cols)) $pdo->exec("ALTER TABLE bank_reconciliations ADD COLUMN cb_debits JSON DEFAULT NULL AFTER less_items");
    if (!in_array('cb_credits', $cols)) $pdo->exec("ALTER TABLE bank_reconciliations ADD COLUMN cb_credits JSON DEFAULT NULL AFTER cb_debits");
} catch (Exception $e) {}

// ── LIST ──
if ($action === 'list') {
    $stmt = $pdo->prepare("SELECT br.*, u.first_name FROM bank_reconciliations br LEFT JOIN users u ON br.created_by = u.id WHERE br.company_id = ? AND br.client_id = ? ORDER BY br.created_at DESC");
    $stmt->execute([$company_id, $client_id]);
    echo json_encode(['success' => true, 'records' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ── CREATE ──
if ($action === 'create') {
    $title = br_clean($_POST['title'] ?? '');
    if (!$title) { echo json_encode(['success' => false, 'message' => 'Title required']); exit; }
    $stmt = $pdo->prepare("INSERT INTO bank_reconciliations (company_id, client_id, title, bank_name, account_number, statement_date, created_by) VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([$company_id, $client_id, $title, br_clean($_POST['bank_name'] ?? ''), br_clean($_POST['account_number'] ?? ''), br_clean($_POST['statement_date'] ?? date('Y-m-d')), $user_id]);
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    exit;
}

// ── GET ──
if ($action === 'get') {
    $id = intval($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM bank_reconciliations WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $company_id]);
    $rec = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rec) { echo json_encode(['success' => false, 'message' => 'Not found']); exit; }
    echo json_encode(['success' => true, 'record' => $rec]);
    exit;
}

// ── SAVE ──
if ($action === 'save') {
    $id = intval($_POST['id'] ?? 0);
    $fields = [
        'title'            => br_clean($_POST['title'] ?? ''),
        'bank_name'        => br_clean($_POST['bank_name'] ?? ''),
        'account_number'   => br_clean($_POST['account_number'] ?? ''),
        'statement_date'   => br_clean($_POST['statement_date'] ?? date('Y-m-d')),
        'bank_balance'     => floatval($_POST['bank_balance'] ?? 0),
        'cashbook_balance' => floatval($_POST['cashbook_balance'] ?? 0),
        'add_items'        => $_POST['add_items'] ?? '[]',
        'less_items'       => $_POST['less_items'] ?? '[]',
        'cb_debits'        => $_POST['cb_debits'] ?? '[]',
        'cb_credits'       => $_POST['cb_credits'] ?? '[]',
        'notes'            => $_POST['notes'] ?? '',
        'status'           => br_clean($_POST['status'] ?? 'draft'),
    ];
    $sets = []; $vals = [];
    foreach ($fields as $k => $v) { $sets[] = "$k = ?"; $vals[] = $v; }
    $vals[] = $id; $vals[] = $company_id;
    $pdo->prepare("UPDATE bank_reconciliations SET " . implode(', ', $sets) . " WHERE id = ? AND company_id = ?")->execute($vals);
    echo json_encode(['success' => true]);
    exit;
}

// ── DELETE ──
if ($action === 'delete') {
    $id = intval($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM bank_reconciliations WHERE id = ? AND company_id = ?")->execute([$id, $company_id]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
