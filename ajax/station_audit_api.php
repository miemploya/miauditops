<?php
/**
 * MIAUDITOPS — Station Audit API (AJAX Handler)
 * Handles: create_session, save_system_sales, save_pump_table, save_pump_readings,
 *          close_pump_table, save_tank_dipping, save_haulage, get_session_data, sign_off, delete_session
 */
require_once '../includes/functions.php';
require_once '../includes/trash_helper.php';
header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$company_id = $_SESSION['company_id'];
$client_id  = get_active_client();
require_non_viewer();
$user_id = $_SESSION['user_id'];
$action  = $_POST['action'] ?? $_GET['action'] ?? '';

// Helper: reject input containing '&' character
function reject_ampersand($value, $field_label = 'Name') {
    if (strpos($value, '&') !== false) {
        echo json_encode(['success' => false, 'message' => "$field_label cannot contain the '&' symbol. Please use 'and' instead."]);
        return true;
    }
    return false;
}

// Auto-create tables if they don't exist
$pdo->exec("CREATE TABLE IF NOT EXISTS station_expense_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    company_id INT NOT NULL,
    category_name VARCHAR(150) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_session (session_id, company_id)
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS station_expense_ledger (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    company_id INT NOT NULL,
    entry_date DATE,
    description VARCHAR(255) DEFAULT '',
    debit DECIMAL(15,2) DEFAULT 0,
    credit DECIMAL(15,2) DEFAULT 0,
    payment_method VARCHAR(50) DEFAULT 'cash',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_category (category_id, company_id)
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS station_debtor_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    company_id INT NOT NULL,
    customer_name VARCHAR(150) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_session (session_id, company_id)
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS station_debtor_ledger (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    company_id INT NOT NULL,
    entry_date DATE,
    description VARCHAR(255) DEFAULT '',
    debit DECIMAL(15,2) DEFAULT 0,
    credit DECIMAL(15,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_account (account_id, company_id)
)");;

$pdo->exec("CREATE TABLE IF NOT EXISTS station_audit_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    session_id INT DEFAULT NULL,
    uploaded_by INT NOT NULL,
    original_name VARCHAR(255) NOT NULL DEFAULT '',
    stored_name VARCHAR(255) NOT NULL DEFAULT '',
    file_path VARCHAR(500) NOT NULL DEFAULT '',
    file_size BIGINT NOT NULL DEFAULT 0,
    file_type VARCHAR(100) DEFAULT '',
    doc_label VARCHAR(255) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company (company_id),
    INDEX idx_session (session_id, company_id)
)");

ensure_trash_table($pdo);
purge_expired_trash($pdo);

try {
    switch ($action) {

        // ───────── Create Audit Session ─────────
        case 'create_session':
            $outlet_id = intval($_POST['outlet_id'] ?? 0);
            $date_from = $_POST['date_from'] ?? date('Y-m-d');
            $date_to   = $_POST['date_to'] ?? date('Y-m-d');

            if (!$outlet_id) {
                echo json_encode(['success' => false, 'message' => 'Please select a station/outlet']);
                break;
            }

            $stmt = $pdo->prepare("INSERT INTO station_audit_sessions (company_id, client_id, outlet_id, date_from, date_to, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, 'draft', ?, NOW())");
            $stmt->execute([$company_id, $client_id, $outlet_id, $date_from, $date_to, $user_id]);
            $sid = $pdo->lastInsertId();

            // Auto-create system_sales record
            $stmt = $pdo->prepare("INSERT INTO station_system_sales (session_id, company_id, pos_amount, cash_amount, transfer_amount, teller_amount, total, notes) VALUES (?, ?, 0, 0, 0, 0, 0, '')");
            $stmt->execute([$sid, $company_id]);

            // ── Carry-over from most recent previous session for this outlet ──
            $tanks_copied = 0;
            $pumps_copied = 0;
            $stmt = $pdo->prepare("SELECT id FROM station_audit_sessions WHERE outlet_id = ? AND company_id = ? AND id != ? ORDER BY date_to DESC, id DESC LIMIT 1");
            $stmt->execute([$outlet_id, $company_id, $sid]);
            $prev_session = $stmt->fetchColumn();

            if ($prev_session) {
                // Copy pump tables (latest per product, closed ones) + their readings + their tanks
                $stmt = $pdo->prepare("SELECT DISTINCT product FROM station_pump_tables WHERE session_id = ? AND company_id = ?");
                $stmt->execute([$prev_session, $company_id]);
                $products = $stmt->fetchAll(PDO::FETCH_COLUMN);

                foreach ($products as $prod) {
                    // Get the latest pump table for this product (highest sort_order)
                    $stmt = $pdo->prepare("SELECT * FROM station_pump_tables WHERE session_id = ? AND company_id = ? AND product = ? ORDER BY sort_order DESC LIMIT 1");
                    $stmt->execute([$prev_session, $company_id, $prod]);
                    $prev_pt = $stmt->fetch();
                    if (!$prev_pt) continue;

                    // Get its readings
                    $stmt = $pdo->prepare("SELECT pump_name, closing FROM station_pump_readings WHERE pump_table_id = ? AND company_id = ? ORDER BY sort_order");
                    $stmt->execute([$prev_pt['id'], $company_id]);
                    $prev_readings = $stmt->fetchAll();

                    // Create new pump table in new session
                    $stmt = $pdo->prepare("INSERT INTO station_pump_tables (session_id, company_id, product, station_location, rate, date_from, date_to, is_closed, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 1)");
                    $stmt->execute([$sid, $company_id, $prod, $prev_pt['station_location'], $prev_pt['rate'], $date_from, $date_to]);
                    $new_pt_id = $pdo->lastInsertId();

                    // Copy readings with closing → opening (if any)
                    if (!empty($prev_readings)) {
                        $ins = $pdo->prepare("INSERT INTO station_pump_readings (pump_table_id, company_id, pump_name, opening, rtt, closing, sort_order) VALUES (?, ?, ?, ?, 0, 0, ?)");
                        $order = 0;
                        foreach ($prev_readings as $pr) {
                            $ins->execute([$new_pt_id, $company_id, $pr['pump_name'], floatval($pr['closing']), $order++]);
                            $pumps_copied++;
                        }
                    }

                    // Copy tanks from this pump table: closing → opening
                    $stmt = $pdo->prepare("SELECT tank_name, product, closing FROM station_tank_dipping WHERE pump_table_id = ? AND company_id = ? ORDER BY tank_name");
                    $stmt->execute([$prev_pt['id'], $company_id]);
                    $prev_tanks = $stmt->fetchAll();

                    $tank_ins = $pdo->prepare("INSERT INTO station_tank_dipping (session_id, pump_table_id, company_id, tank_name, product, opening, added, closing, capacity_kg, max_fill_percent) VALUES (?, ?, ?, ?, ?, ?, 0, 0, ?, ?)");
                    foreach ($prev_tanks as $t) {
                        $tank_ins->execute([$sid, $new_pt_id, $company_id, $t['tank_name'], $t['product'], floatval($t['closing']), floatval($t['capacity_kg'] ?? 0), floatval($t['max_fill_percent'] ?? 100)]);
                        $tanks_copied++;
                    }
                }

                // Copy lube store items from previous session (closing → opening)
                $stmt = $pdo->prepare("SELECT * FROM station_lube_store_items WHERE session_id = ? AND company_id = ? ORDER BY sort_order");
                $stmt->execute([$prev_session, $company_id]);
                $prev_store_items = $stmt->fetchAll();
                $store_id_map = []; // old_id => new_id

                $si_ins = $pdo->prepare("INSERT INTO station_lube_store_items (session_id, company_id, item_name, opening, received, return_out, selling_price, sort_order) VALUES (?, ?, ?, ?, 0, 0, ?, ?)");
                foreach ($prev_store_items as $si) {
                    // Compute closing of previous session: opening + received - issued - return_out
                    $issued_stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM station_lube_issues WHERE store_item_id = ? AND company_id = ?");
                    $issued_stmt->execute([$si['id'], $company_id]);
                    $prev_issued = floatval($issued_stmt->fetchColumn());
                    $prev_closing = floatval($si['opening']) + floatval($si['received']) - $prev_issued - floatval($si['return_out']);
                    $si_ins->execute([$sid, $company_id, $si['item_name'], max(0, $prev_closing), floatval($si['selling_price']), $si['sort_order']]);
                    $store_id_map[$si['id']] = $pdo->lastInsertId();
                }

                // Copy lube sections + items from previous session
                $stmt = $pdo->prepare("SELECT * FROM station_lube_sections WHERE session_id = ? AND company_id = ? ORDER BY sort_order");
                $stmt->execute([$prev_session, $company_id]);
                $prev_lube_secs = $stmt->fetchAll();

                foreach ($prev_lube_secs as $ls) {
                    $stmt = $pdo->prepare("INSERT INTO station_lube_sections (session_id, company_id, name, sort_order) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$sid, $company_id, $ls['name'], $ls['sort_order']]);
                    $new_ls_id = $pdo->lastInsertId();

                    $stmt = $pdo->prepare("SELECT * FROM station_lube_items WHERE section_id = ? AND company_id = ? ORDER BY sort_order");
                    $stmt->execute([$ls['id'], $company_id]);
                    $prev_items = $stmt->fetchAll();

                    $item_ins = $pdo->prepare("INSERT INTO station_lube_items (section_id, store_item_id, company_id, item_name, opening, received, sold, closing, selling_price, sort_order) VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?, ?)");
                    foreach ($prev_items as $li) {
                        $new_si_id = isset($li['store_item_id']) && $li['store_item_id'] ? ($store_id_map[$li['store_item_id']] ?? null) : null;
                        $carry_opening = floatval($li['closing']);
                        // closing starts equal to opening so Sold (= Opening + Received − Closing) starts at 0
                        $item_ins->execute([$new_ls_id, $new_si_id, $company_id, $li['item_name'], $carry_opening, $carry_opening, floatval($li['selling_price']), $li['sort_order']]);
                    }
                }

                // Copy expense categories from previous session (names only, no ledger entries)
                $stmt = $pdo->prepare("SELECT category_name FROM station_expense_categories WHERE session_id = ? AND company_id = ? ORDER BY category_name");
                $stmt->execute([$prev_session, $company_id]);
                $prev_cats = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $cat_ins = $pdo->prepare("INSERT INTO station_expense_categories (session_id, company_id, category_name) VALUES (?, ?, ?)");
                foreach ($prev_cats as $cname) {
                    $cat_ins->execute([$sid, $company_id, $cname]);
                }

                // Copy debtor accounts from previous session (names only, no ledger entries)
                $stmt = $pdo->prepare("SELECT customer_name FROM station_debtor_accounts WHERE session_id = ? AND company_id = ? ORDER BY customer_name");
                $stmt->execute([$prev_session, $company_id]);
                $prev_debtors = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $deb_ins = $pdo->prepare("INSERT INTO station_debtor_accounts (session_id, company_id, customer_name) VALUES (?, ?, ?)");
                foreach ($prev_debtors as $dname) {
                    $deb_ins->execute([$sid, $company_id, $dname]);
                }
            }

            log_audit($company_id, $user_id, 'station_session_created', 'station_audit', $sid, "Audit session $date_from to $date_to");
            echo json_encode(['success' => true, 'session_id' => $sid, 'tanks_copied' => $tanks_copied, 'pumps_copied' => $pumps_copied]);
            break;

        // ───────── Update Session Dates ─────────
        case 'update_session_dates':
            $session_id = intval($_POST['session_id'] ?? 0);
            $date_from  = clean_input($_POST['date_from'] ?? '');
            $date_to    = clean_input($_POST['date_to'] ?? '');
            if (!$session_id || !$date_from || !$date_to) {
                echo json_encode(['success' => false, 'message' => 'Missing required fields']);
                break;
            }
            $pdo->prepare("UPDATE station_audit_sessions SET date_from=?, date_to=? WHERE id=? AND company_id=?")
                ->execute([$date_from, $date_to, $session_id, $company_id]);
            echo json_encode(['success' => true]);
            break;

        // ───────── Outlet Terminals ─────────
        case 'save_outlet_terminals':
            $outlet_id = intval($_POST['outlet_id'] ?? 0);
            $type      = in_array($_POST['terminal_type'] ?? '', ['pos','transfer']) ? $_POST['terminal_type'] : '';
            $names_json = $_POST['terminal_names'] ?? '[]';
            $names      = json_decode($names_json, true) ?: [];
            if (!$outlet_id || !$type) {
                echo json_encode(['success' => false, 'message' => 'Missing outlet or type']);
                break;
            }
            // Clear existing terminals of this type for this outlet
            $pdo->prepare("DELETE FROM station_outlet_terminals WHERE company_id=? AND outlet_id=? AND terminal_type=?")
                ->execute([$company_id, $outlet_id, $type]);
            // Insert fresh
            $ins = $pdo->prepare("INSERT INTO station_outlet_terminals (company_id, outlet_id, terminal_name, terminal_type, sort_order) VALUES (?,?,?,?,?)");
            foreach ($names as $i => $name) {
                $name = trim($name);
                if ($name !== '') $ins->execute([$company_id, $outlet_id, $name, $type, $i]);
            }
            echo json_encode(['success' => true]);
            break;

        case 'get_outlet_terminals':
            $outlet_id = intval($_POST['outlet_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT terminal_name, terminal_type FROM station_outlet_terminals WHERE company_id=? AND outlet_id=? ORDER BY terminal_type, sort_order");
            $stmt->execute([$company_id, $outlet_id]);
            $terminals = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'terminals' => $terminals]);
            break;

        // ───────── Save System Sales ─────────
        case 'save_system_sales':
            $session_id = intval($_POST['session_id'] ?? 0);
            $pos        = floatval($_POST['pos_amount'] ?? 0);
            $cash       = floatval($_POST['cash_amount'] ?? 0);
            $transfer   = floatval($_POST['transfer_amount'] ?? 0);
            $teller     = floatval($_POST['teller_amount'] ?? 0);
            $total      = $pos + $cash + $transfer + $teller;
            $notes      = clean_input($_POST['notes'] ?? '');
            $denom_json = $_POST['denomination_json'] ?? '';
            $teller_url = clean_input($_POST['teller_proof_url'] ?? '');
            $pos_url    = clean_input($_POST['pos_proof_url'] ?? '');
            $pos_terms  = $_POST['pos_terminals_json'] ?? '';
            $xfer_terms = $_POST['transfer_terminals_json'] ?? '';

            $stmt = $pdo->prepare("UPDATE station_system_sales SET pos_amount = ?, cash_amount = ?, transfer_amount = ?, teller_amount = ?, total = ?, notes = ?, denomination_json = ?, teller_proof_url = ?, pos_proof_url = ?, pos_terminals_json = ?, transfer_terminals_json = ? WHERE session_id = ? AND company_id = ?");
            $stmt->execute([$pos, $cash, $transfer, $teller, $total, $notes, $denom_json, $teller_url, $pos_url, $pos_terms, $xfer_terms, $session_id, $company_id]);

            echo json_encode(['success' => true, 'total' => $total]);
            break;

        // ───────── Create/Save Pump Table ─────────
        case 'save_pump_table':
            $session_id = intval($_POST['session_id'] ?? 0);
            $id         = intval($_POST['id'] ?? 0);
            $product    = clean_input($_POST['product'] ?? 'PMS');
            $station    = clean_input($_POST['station_location'] ?? '');
            $rate       = floatval($_POST['rate'] ?? 0);
            $date_from  = $_POST['date_from'] ?? '';
            $date_to    = $_POST['date_to'] ?? '';

            if ($id) {
                // Update existing
                $stmt = $pdo->prepare("UPDATE station_pump_tables SET product = ?, station_location = ?, rate = ?, date_from = ?, date_to = ? WHERE id = ? AND company_id = ?");
                $stmt->execute([$product, $station, $rate, $date_from, $date_to, $id, $company_id]);
                echo json_encode(['success' => true, 'id' => $id]);
            } else {
                // Get sort order
                $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM station_pump_tables WHERE session_id = ? AND product = ?");
                $stmt->execute([$session_id, $product]);
                $sort = $stmt->fetchColumn();

                $stmt = $pdo->prepare("INSERT INTO station_pump_tables (session_id, company_id, product, station_location, rate, date_from, date_to, is_closed, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)");
                $stmt->execute([$session_id, $company_id, $product, $station, $rate, $date_from, $date_to, $sort]);
                echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            }
            break;

        // ───────── Save Pump Readings (Bulk) ─────────
        case 'save_pump_readings':
            $pump_table_id = intval($_POST['pump_table_id'] ?? 0);
            $readings_json = $_POST['readings'] ?? '[]';
            $readings = json_decode($readings_json, true);

            if (!is_array($readings)) {
                echo json_encode(['success' => false, 'message' => 'Invalid readings data']);
                break;
            }

            // Delete existing readings for this pump table, then re-insert
            $stmt = $pdo->prepare("DELETE FROM station_pump_readings WHERE pump_table_id = ? AND company_id = ?");
            $stmt->execute([$pump_table_id, $company_id]);

            $insert = $pdo->prepare("INSERT INTO station_pump_readings (pump_table_id, company_id, pump_name, opening, rtt, closing, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $order = 0;
            foreach ($readings as $r) {
                $insert->execute([
                    $pump_table_id,
                    $company_id,
                    clean_input($r['pump_name'] ?? 'Pump ' . ($order + 1)),
                    floatval($r['opening'] ?? 0),
                    floatval($r['rtt'] ?? 0),
                    floatval($r['closing'] ?? 0),
                    $order++
                ]);
            }

            echo json_encode(['success' => true, 'count' => $order]);
            break;

        // ───────── Close Pump Table ─────────
        case 'close_pump_table':
            $id = intval($_POST['id'] ?? 0);

            $stmt = $pdo->prepare("UPDATE station_pump_tables SET is_closed = 1 WHERE id = ? AND company_id = ?");
            $stmt->execute([$id, $company_id]);

            echo json_encode(['success' => true]);
            break;

        // ───────── Delete Pump Table ─────────
        case 'delete_pump_table':
            $id = intval($_POST['id'] ?? 0);

            // Snapshot pump table + readings + tanks
            $stmt = $pdo->prepare("SELECT * FROM station_pump_tables WHERE id = ? AND company_id = ?");
            $stmt->execute([$id, $company_id]);
            $pt_row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($pt_row) {
                $snap = $pt_row;
                $stmt2 = $pdo->prepare("SELECT * FROM station_pump_readings WHERE pump_table_id = ? AND company_id = ?");
                $stmt2->execute([$id, $company_id]);
                $snap['readings'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                $stmt3 = $pdo->prepare("SELECT * FROM station_tank_dipping WHERE pump_table_id = ? AND company_id = ?");
                $stmt3->execute([$id, $company_id]);
                $snap['tanks'] = $stmt3->fetchAll(PDO::FETCH_ASSOC);
                $label = ($pt_row['product'] ?? 'Pump Table') . ' — ' . ($pt_row['date_from'] ?? '') . ' to ' . ($pt_row['date_to'] ?? '');
                move_to_trash($pdo, $company_id, 'pump_table', $id, $label, $snap, $user_id);
            }

            // Cascade delete
            $pdo->prepare("DELETE FROM station_pump_readings WHERE pump_table_id = ? AND company_id = ?")->execute([$id, $company_id]);
            $pdo->prepare("DELETE FROM station_tank_dipping WHERE pump_table_id = ? AND company_id = ?")->execute([$id, $company_id]);
            $pdo->prepare("DELETE FROM station_pump_tables WHERE id = ? AND company_id = ?")->execute([$id, $company_id]);

            echo json_encode(['success' => true]);
            break;

        // ───────── New Rate Period (Chain Tables) ─────────
        case 'new_rate_period':
            $prev_table_id = intval($_POST['prev_table_id'] ?? 0);
            $session_id    = intval($_POST['session_id'] ?? 0);
            $product       = clean_input($_POST['product'] ?? 'PMS');
            $new_rate      = floatval($_POST['rate'] ?? 0);
            $date_from     = $_POST['date_from'] ?? '';
            $date_to       = $_POST['date_to'] ?? '';
            $station       = clean_input($_POST['station_location'] ?? '');

            // Close previous table first
            $stmt = $pdo->prepare("UPDATE station_pump_tables SET is_closed = 1 WHERE id = ? AND company_id = ?");
            $stmt->execute([$prev_table_id, $company_id]);

            // Get previous pump readings (closing values become new opening)
            $stmt = $pdo->prepare("SELECT pump_name, closing FROM station_pump_readings WHERE pump_table_id = ? AND company_id = ? ORDER BY sort_order");
            $stmt->execute([$prev_table_id, $company_id]);
            $prev_readings = $stmt->fetchAll();

            // Get sort order
            $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM station_pump_tables WHERE session_id = ? AND product = ?");
            $stmt->execute([$session_id, $product]);
            $sort = $stmt->fetchColumn();

            // Create new table
            $stmt = $pdo->prepare("INSERT INTO station_pump_tables (session_id, company_id, product, station_location, rate, date_from, date_to, is_closed, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)");
            $stmt->execute([$session_id, $company_id, $product, $station, $new_rate, $date_from, $date_to, $sort]);
            $new_table_id = $pdo->lastInsertId();

            // Copy pumps with closing→opening
            $insert = $pdo->prepare("INSERT INTO station_pump_readings (pump_table_id, company_id, pump_name, opening, rtt, closing, sort_order) VALUES (?, ?, ?, ?, 0, 0, ?)");
            $order = 0;
            foreach ($prev_readings as $pr) {
                $insert->execute([$new_table_id, $company_id, $pr['pump_name'], floatval($pr['closing']), $order++]);
            }

            // Copy tanks from previous pump table: closing → opening, preserving capacity_kg
            $stmt = $pdo->prepare("SELECT tank_name, product, closing, capacity_kg FROM station_tank_dipping WHERE pump_table_id = ? AND company_id = ? ORDER BY tank_name");
            $stmt->execute([$prev_table_id, $company_id]);
            $prev_tanks = $stmt->fetchAll();

            $tanks_copied = 0;
            $copied_tanks = [];
            $tank_ins = $pdo->prepare("INSERT INTO station_tank_dipping (session_id, pump_table_id, company_id, tank_name, product, opening, added, closing, capacity_kg, max_fill_percent) VALUES (?, ?, ?, ?, ?, ?, 0, 0, ?, ?)");
            foreach ($prev_tanks as $t) {
                $cap = floatval($t['capacity_kg'] ?? 0);
                $mfp = floatval($t['max_fill_percent'] ?? 100);
                $tank_ins->execute([$session_id, $new_table_id, $company_id, $t['tank_name'], $t['product'], floatval($t['closing']), $cap, $mfp]);
                $copied_tanks[] = [
                    'id' => $pdo->lastInsertId(),
                    'tank_name' => $t['tank_name'],
                    'product' => $t['product'],
                    'opening' => floatval($t['closing']),
                    'added' => 0,
                    'closing' => 0,
                    'capacity_kg' => $cap,
                    'max_fill_percent' => $mfp
                ];
                $tanks_copied++;
            }

            echo json_encode(['success' => true, 'id' => $new_table_id, 'pumps_copied' => $order, 'tanks_copied' => $tanks_copied, 'tanks' => $copied_tanks]);
            break;

        // ───────── Add Pump to Table ─────────
        case 'add_pump':
            $pump_table_id = intval($_POST['pump_table_id'] ?? 0);
            $pump_name     = clean_input($_POST['pump_name'] ?? '');

            $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM station_pump_readings WHERE pump_table_id = ?");
            $stmt->execute([$pump_table_id]);
            $sort = $stmt->fetchColumn();

            $stmt = $pdo->prepare("INSERT INTO station_pump_readings (pump_table_id, company_id, pump_name, opening, rtt, closing, sort_order) VALUES (?, ?, ?, 0, 0, 0, ?)");
            $stmt->execute([$pump_table_id, $company_id, $pump_name, $sort]);

            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            break;

        // ───────── Add Single Tank ─────────
        case 'add_tank':
            $pump_table_id = intval($_POST['pump_table_id'] ?? 0);
            $tank_name     = clean_input($_POST['tank_name'] ?? '');
            // Get session_id and product from the pump table
            $stmt = $pdo->prepare("SELECT session_id, product FROM station_pump_tables WHERE id = ? AND company_id = ?");
            $stmt->execute([$pump_table_id, $company_id]);
            $ptInfo = $stmt->fetch();
            if (!$ptInfo) {
                echo json_encode(['success' => false, 'message' => 'Pump table not found']);
                break;
            }
            $stmt = $pdo->prepare("INSERT INTO station_tank_dipping (session_id, pump_table_id, company_id, tank_name, product, opening, added, closing, capacity_kg, max_fill_percent) VALUES (?,?,?,?,?,0,0,0,0,100)");
            $stmt->execute([$ptInfo['session_id'], $pump_table_id, $company_id, $tank_name, $ptInfo['product']]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            break;

        // ───────── Save Tank Dipping (Bulk) ─────────
        case 'save_tank_dipping':
            $pump_table_id = intval($_POST['pump_table_id'] ?? 0);
            $tanks_json = $_POST['tanks'] ?? '[]';
            $tanks = json_decode($tanks_json, true);

            if (!is_array($tanks)) {
                echo json_encode(['success' => false, 'message' => 'Invalid tank data']);
                break;
            }

            // Delete and re-insert scoped to pump_table_id
            $stmt = $pdo->prepare("DELETE FROM station_tank_dipping WHERE pump_table_id = ? AND company_id = ?");
            $stmt->execute([$pump_table_id, $company_id]);

            $insert = $pdo->prepare("INSERT INTO station_tank_dipping (session_id, pump_table_id, company_id, tank_name, product, opening, added, closing, capacity_kg, max_fill_percent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            // Get session_id from the pump table
            $stmt2 = $pdo->prepare("SELECT session_id, product FROM station_pump_tables WHERE id = ? AND company_id = ?");
            $stmt2->execute([$pump_table_id, $company_id]);
            $ptInfo = $stmt2->fetch();
            $pt_session_id = $ptInfo ? $ptInfo['session_id'] : 0;

            foreach ($tanks as $t) {
                $insert->execute([
                    $pt_session_id,
                    $pump_table_id,
                    $company_id,
                    clean_input($t['tank_name'] ?? ''),
                    clean_input($t['product'] ?? 'PMS'),
                    floatval($t['opening'] ?? 0),
                    floatval($t['added'] ?? 0),
                    floatval($t['closing'] ?? 0),
                    floatval($t['capacity_kg'] ?? 0),
                    floatval($t['max_fill_percent'] ?? 100),
                ]);
            }

            // ── Sync closing → opening of next rate period's tanks ──
            $synced = 0;
            if ($ptInfo) {
                $pt_product = $ptInfo['product'];
                // Find the next pump table for same product in same session (higher sort_order)
                $stmt3 = $pdo->prepare("SELECT pt2.id FROM station_pump_tables pt1
                    JOIN station_pump_tables pt2 ON pt2.session_id = pt1.session_id AND pt2.product = pt1.product AND pt2.sort_order > pt1.sort_order
                    WHERE pt1.id = ? AND pt1.company_id = ?
                    ORDER BY pt2.sort_order ASC LIMIT 1");
                $stmt3->execute([$pump_table_id, $company_id]);
                $next_pt_id = $stmt3->fetchColumn();

                if ($next_pt_id) {
                    // Sync closing→opening AND carry forward capacity_kg + max_fill_percent
                    $upd = $pdo->prepare("UPDATE station_tank_dipping SET opening = ?, capacity_kg = ?, max_fill_percent = ? WHERE pump_table_id = ? AND company_id = ? AND tank_name = ?");
                    foreach ($tanks as $t) {
                        $upd->execute([floatval($t['closing'] ?? 0), floatval($t['capacity_kg'] ?? 0), floatval($t['max_fill_percent'] ?? 100), $next_pt_id, $company_id, $t['tank_name'] ?? '']);
                        $synced += $upd->rowCount();
                    }
                }
            }

            echo json_encode(['success' => true, 'count' => count($tanks), 'synced' => $synced]);
            break;

        // ───────── Save Haulage (Bulk) ─────────
        case 'save_haulage':
            $session_id = intval($_POST['session_id'] ?? 0);
            $entries_json = $_POST['entries'] ?? '[]';
            $entries = json_decode($entries_json, true);

            if (!is_array($entries)) {
                echo json_encode(['success' => false, 'message' => 'Invalid haulage data']);
                break;
            }

            $stmt = $pdo->prepare("DELETE FROM station_haulage WHERE session_id = ? AND company_id = ?");
            $stmt->execute([$session_id, $company_id]);

            $insert = $pdo->prepare("INSERT INTO station_haulage (session_id, company_id, delivery_date, tank_name, product, quantity, waybill_qty) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($entries as $e) {
                $insert->execute([
                    $session_id,
                    $company_id,
                    $e['delivery_date'] ?? date('Y-m-d'),
                    clean_input($e['tank_name'] ?? ''),
                    clean_input($e['product'] ?? 'PMS'),
                    floatval($e['quantity'] ?? 0),
                    floatval($e['waybill_qty'] ?? 0),
                ]);
            }

            // ── Recalculate tank 'added' from haulage entries ──
            // Reset all tank 'added' fields for this session first
            $pdo->prepare("UPDATE station_tank_dipping td
                JOIN station_pump_tables pt ON td.pump_table_id = pt.id
                SET td.added = 0
                WHERE td.session_id = ? AND td.company_id = ?")
                ->execute([$session_id, $company_id]);

            // Sum haulage quantity per tank_name per pump_table (matched by date range)
            $tank_updates = [];
            $tanks_updated = 0;
            $hStmt = $pdo->prepare("SELECT h.tank_name, h.product, h.delivery_date, h.quantity
                FROM station_haulage h
                WHERE h.session_id = ? AND h.company_id = ? AND h.tank_name != ''
                ORDER BY h.delivery_date");
            $hStmt->execute([$session_id, $company_id]);
            $haulageRows = $hStmt->fetchAll();

            // Group haulage by tank_name + pump_table_id
            $tankPtSums = []; // key = "pump_table_id|tank_name" => total
            foreach ($haulageRows as $hr) {
                // Find the pump table whose date range covers this delivery date
                $ptStmt = $pdo->prepare("SELECT id FROM station_pump_tables
                    WHERE session_id = ? AND company_id = ? AND product = ?
                    AND date_from <= ? AND date_to >= ?
                    ORDER BY sort_order ASC LIMIT 1");
                $ptStmt->execute([$session_id, $company_id, $hr['product'], $hr['delivery_date'], $hr['delivery_date']]);
                $ptId = $ptStmt->fetchColumn();

                if (!$ptId) {
                    // Fallback: assign to the last pump table for this product
                    $ptStmt2 = $pdo->prepare("SELECT id FROM station_pump_tables
                        WHERE session_id = ? AND company_id = ? AND product = ?
                        ORDER BY sort_order DESC LIMIT 1");
                    $ptStmt2->execute([$session_id, $company_id, $hr['product']]);
                    $ptId = $ptStmt2->fetchColumn();
                }

                if ($ptId) {
                    $key = $ptId . '|' . $hr['tank_name'];
                    if (!isset($tankPtSums[$key])) $tankPtSums[$key] = 0;
                    $tankPtSums[$key] += floatval($hr['quantity']);
                }
            }

            // Apply the sums as 'added' to each tank
            $updStmt = $pdo->prepare("UPDATE station_tank_dipping SET added = ? WHERE pump_table_id = ? AND company_id = ? AND tank_name = ?");
            foreach ($tankPtSums as $key => $total) {
                list($ptId, $tankName) = explode('|', $key, 2);
                $updStmt->execute([$total, $ptId, $company_id, $tankName]);
                if ($updStmt->rowCount() > 0) {
                    $tanks_updated++;
                    $tank_updates[] = ['pump_table_id' => intval($ptId), 'tank_name' => $tankName, 'total_added' => $total];
                }
            }

            echo json_encode(['success' => true, 'count' => count($entries), 'tanks_updated' => $tanks_updated, 'tank_updates' => $tank_updates]);
            break;

        // ───────── Get Full Session Data (for JS hydration) ─────────
        case 'get_session_data':
            $session_id = intval($_GET['session_id'] ?? $_POST['session_id'] ?? 0);

            // Session
            $stmt = $pdo->prepare("SELECT s.*, co.name as outlet_name FROM station_audit_sessions s LEFT JOIN client_outlets co ON s.outlet_id = co.id WHERE s.id = ? AND s.company_id = ? AND s.client_id = ?");
            $stmt->execute([$session_id, $company_id, $client_id]);
            $session = $stmt->fetch();

            if (!$session) {
                echo json_encode(['success' => false, 'message' => 'Session not found']);
                break;
            }

            // System sales
            $stmt = $pdo->prepare("SELECT * FROM station_system_sales WHERE session_id = ? AND company_id = ?");
            $stmt->execute([$session_id, $company_id]);
            $system_sales = $stmt->fetch();

            // Pump tables + readings
            $stmt = $pdo->prepare("SELECT * FROM station_pump_tables WHERE session_id = ? AND company_id = ? ORDER BY product, sort_order");
            $stmt->execute([$session_id, $company_id]);
            $pump_tables = $stmt->fetchAll();

            foreach ($pump_tables as &$pt) {
                $stmt = $pdo->prepare("SELECT * FROM station_pump_readings WHERE pump_table_id = ? AND company_id = ? ORDER BY sort_order");
                $stmt->execute([$pt['id'], $company_id]);
                $pt['readings'] = $stmt->fetchAll();

                // Tanks linked to this pump table
                $stmt = $pdo->prepare("SELECT * FROM station_tank_dipping WHERE pump_table_id = ? AND company_id = ? ORDER BY tank_name");
                $stmt->execute([$pt['id'], $company_id]);
                $pt['tanks'] = $stmt->fetchAll();
            }

            // Haulage
            $stmt = $pdo->prepare("SELECT * FROM station_haulage WHERE session_id = ? AND company_id = ? ORDER BY delivery_date, tank_name");
            $stmt->execute([$session_id, $company_id]);
            $haulage = $stmt->fetchAll();

            // Expense Categories + Ledger
            $stmt = $pdo->prepare("SELECT * FROM station_expense_categories WHERE session_id = ? AND company_id = ? ORDER BY category_name");
            $stmt->execute([$session_id, $company_id]);
            $expense_categories = $stmt->fetchAll();
            foreach ($expense_categories as &$ec) {
                $lstmt = $pdo->prepare("SELECT * FROM station_expense_ledger WHERE category_id = ? AND company_id = ? ORDER BY entry_date, id");
                $lstmt->execute([$ec['id'], $company_id]);
                $ec['ledger'] = $lstmt->fetchAll();
            }

            // Debtor Accounts + Ledger
            $stmt = $pdo->prepare("SELECT * FROM station_debtor_accounts WHERE session_id = ? AND company_id = ? ORDER BY customer_name");
            $stmt->execute([$session_id, $company_id]);
            $debtor_accounts = $stmt->fetchAll();
            foreach ($debtor_accounts as &$da) {
                $lstmt = $pdo->prepare("SELECT * FROM station_debtor_ledger WHERE account_id = ? AND company_id = ? ORDER BY entry_date, id");
                $lstmt->execute([$da['id'], $company_id]);
                $da['ledger'] = $lstmt->fetchAll();
            }

            // Lube store items
            $stmt = $pdo->prepare("SELECT * FROM station_lube_store_items WHERE session_id = ? AND company_id = ? ORDER BY sort_order");
            $stmt->execute([$session_id, $company_id]);
            $lube_store_items = $stmt->fetchAll();

            // Lube issues (store → counter)
            $stmt = $pdo->prepare("SELECT li.* FROM station_lube_issues li INNER JOIN station_lube_store_items si ON li.store_item_id = si.id WHERE si.session_id = ? AND li.company_id = ?");
            $stmt->execute([$session_id, $company_id]);
            $lube_issues = $stmt->fetchAll();

            // Lube issue log (for history display)
            $stmt = $pdo->prepare("SELECT il.* FROM station_lube_issue_log il INNER JOIN station_lube_store_items si ON il.store_item_id = si.id WHERE si.session_id = ? AND il.company_id = ? ORDER BY il.created_at DESC");
            $stmt->execute([$session_id, $company_id]);
            $lube_issue_log = $stmt->fetchAll();

            // Lube sections + items
            $stmt = $pdo->prepare("SELECT * FROM station_lube_sections WHERE session_id = ? AND company_id = ? ORDER BY sort_order");
            $stmt->execute([$session_id, $company_id]);
            $lube_sections = $stmt->fetchAll();

            foreach ($lube_sections as &$ls) {
                $stmt = $pdo->prepare("SELECT * FROM station_lube_items WHERE section_id = ? AND company_id = ? ORDER BY sort_order");
                $stmt->execute([$ls['id'], $company_id]);
                $ls['items'] = $stmt->fetchAll();
            }

            // Counter stock counts (per section)
            $csc_stmt = $pdo->prepare("SELECT csc.* FROM station_counter_stock_counts csc 
                INNER JOIN station_lube_sections ls ON csc.section_id = ls.id 
                WHERE ls.session_id = ? AND csc.company_id = ? ORDER BY csc.date_from DESC");
            $csc_stmt->execute([$session_id, $company_id]);
            $counter_stock_counts = [];
            foreach ($csc_stmt->fetchAll(PDO::FETCH_ASSOC) as $csc) {
                $csc_items = $pdo->prepare("SELECT * FROM station_counter_stock_count_items WHERE count_id=? AND company_id=? ORDER BY id");
                $csc_items->execute([$csc['id'], $company_id]);
                $csc['items'] = $csc_items->fetchAll(PDO::FETCH_ASSOC);
                $counter_stock_counts[] = $csc;
            }

            echo json_encode([
                'success' => true,
                'session' => $session,
                'system_sales' => $system_sales,
                'pump_tables' => $pump_tables,
                'haulage' => $haulage,
                'expense_categories' => $expense_categories,
                'debtor_accounts' => $debtor_accounts,
                'lube_store_items' => $lube_store_items,
                'lube_issues' => $lube_issues,
                'lube_sections' => $lube_sections,
                'lube_issue_log' => $lube_issue_log,
                'counter_stock_counts' => $counter_stock_counts,
            ]);
            break;

        // ───────── Upload Teller Proof ─────────
        case 'upload_teller_proof':
            $session_id = intval($_POST['session_id'] ?? 0);
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
                break;
            }
            // 2MB file size limit
            if ($_FILES['file']['size'] > 2 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'File too large. Maximum 2MB allowed.']);
                break;
            }

            $upload_dir = '../uploads/teller_proofs/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','webp','pdf'];
            if (!in_array($ext, $allowed)) {
                echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: ' . implode(', ', $allowed)]);
                break;
            }

            $filename = 'teller_' . $company_id . '_' . $session_id . '_' . time() . '.' . $ext;
            $filepath = $upload_dir . $filename;

            if (move_uploaded_file($_FILES['file']['tmp_name'], $filepath)) {
                $url = '../uploads/teller_proofs/' . $filename;
                $stmt = $pdo->prepare("UPDATE station_system_sales SET teller_proof_url = ? WHERE session_id = ? AND company_id = ?");
                $stmt->execute([$url, $session_id, $company_id]);
                echo json_encode(['success' => true, 'url' => $url]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to save file']);
            }
            break;

        // ───────── Upload POS Proof ─────────
        case 'upload_pos_proof':
            $session_id = intval($_POST['session_id'] ?? 0);
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
                break;
            }
            // 2MB file size limit
            if ($_FILES['file']['size'] > 2 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'File too large. Maximum 2MB allowed.']);
                break;
            }

            $upload_dir = '../uploads/pos_proofs/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','webp','pdf'];
            if (!in_array($ext, $allowed)) {
                echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: ' . implode(', ', $allowed)]);
                break;
            }

            $filename = 'pos_' . $company_id . '_' . $session_id . '_' . time() . '.' . $ext;
            $filepath = $upload_dir . $filename;

            if (move_uploaded_file($_FILES['file']['tmp_name'], $filepath)) {
                $url = '../uploads/pos_proofs/' . $filename;
                $stmt = $pdo->prepare("UPDATE station_system_sales SET pos_proof_url = ? WHERE session_id = ? AND company_id = ?");
                $stmt->execute([$url, $session_id, $company_id]);
                echo json_encode(['success' => true, 'url' => $url]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to save file']);
            }
            break;

        // ───────── Sign Off ─────────
        case 'sign_off':
            $session_id = intval($_POST['session_id'] ?? 0);
            $role = $_POST['role'] ?? '';
            $comments = clean_input($_POST['comments'] ?? '');

            if ($role === 'auditor') {
                $stmt = $pdo->prepare("UPDATE station_audit_sessions SET status = 'submitted', auditor_id = ?, auditor_signed_at = NOW(), auditor_comments = ? WHERE id = ? AND company_id = ?");
                $stmt->execute([$user_id, $comments, $session_id, $company_id]);
            } elseif ($role === 'manager') {
                $stmt = $pdo->prepare("UPDATE station_audit_sessions SET status = 'approved', manager_id = ?, manager_signed_at = NOW(), manager_comments = ? WHERE id = ? AND company_id = ?");
                $stmt->execute([$user_id, $comments, $session_id, $company_id]);
            }

            log_audit($company_id, $user_id, "station_signoff_$role", 'station_audit', $session_id);
            echo json_encode(['success' => true]);
            break;

        // ───────── Delete Session (→ Trash) ─────────
        case 'delete_session':
            $session_id = intval($_POST['session_id'] ?? 0);

            // 1. Snapshot the session + all children into JSON
            $stmt = $pdo->prepare("SELECT s.*, co.name as outlet_name FROM station_audit_sessions s LEFT JOIN client_outlets co ON s.outlet_id = co.id WHERE s.id = ? AND s.company_id = ?");
            $stmt->execute([$session_id, $company_id]);
            $session_row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$session_row) { echo json_encode(['success' => false, 'message' => 'Session not found']); break; }

            $snapshot = ['session' => $session_row];

            // Pump tables + readings
            $stmt = $pdo->prepare("SELECT * FROM station_pump_tables WHERE session_id = ? AND company_id = ?");
            $stmt->execute([$session_id, $company_id]);
            $snapshot['pump_tables'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $pt_ids = array_column($snapshot['pump_tables'], 'id');
            if ($pt_ids) {
                $in = implode(',', array_map('intval', $pt_ids));
                $snapshot['pump_readings'] = $pdo->query("SELECT * FROM station_pump_readings WHERE pump_table_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
            } else { $snapshot['pump_readings'] = []; }

            // System sales
            $stmt = $pdo->prepare("SELECT * FROM station_system_sales WHERE session_id = ? AND company_id = ?");
            $stmt->execute([$session_id, $company_id]);
            $snapshot['system_sales'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Tank dipping
            $stmt = $pdo->prepare("SELECT * FROM station_tank_dipping WHERE session_id = ? AND company_id = ?");
            $stmt->execute([$session_id, $company_id]);
            $snapshot['tank_dipping'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Haulage
            $stmt = $pdo->prepare("SELECT * FROM station_haulage WHERE session_id = ? AND company_id = ?");
            $stmt->execute([$session_id, $company_id]);
            $snapshot['haulage'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Expense categories + ledger
            $stmt = $pdo->prepare("SELECT * FROM station_expense_categories WHERE session_id = ? AND company_id = ?");
            $stmt->execute([$session_id, $company_id]);
            $snapshot['expense_categories'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $ec_ids = array_column($snapshot['expense_categories'], 'id');
            if ($ec_ids) {
                $in = implode(',', array_map('intval', $ec_ids));
                $snapshot['expense_ledger'] = $pdo->query("SELECT * FROM station_expense_ledger WHERE category_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
            } else { $snapshot['expense_ledger'] = []; }

            // Debtor accounts + ledger
            $stmt = $pdo->prepare("SELECT * FROM station_debtor_accounts WHERE session_id = ? AND company_id = ?");
            $stmt->execute([$session_id, $company_id]);
            $snapshot['debtor_accounts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $da_ids = array_column($snapshot['debtor_accounts'], 'id');
            if ($da_ids) {
                $in = implode(',', array_map('intval', $da_ids));
                $snapshot['debtor_ledger'] = $pdo->query("SELECT * FROM station_debtor_ledger WHERE account_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
            } else { $snapshot['debtor_ledger'] = []; }

            // Lube sections + items
            $stmt = $pdo->prepare("SELECT * FROM station_lube_sections WHERE session_id = ? AND company_id = ?");
            $stmt->execute([$session_id, $company_id]);
            $snapshot['lube_sections'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $ls_ids = array_column($snapshot['lube_sections'], 'id');
            if ($ls_ids) {
                $in = implode(',', array_map('intval', $ls_ids));
                $snapshot['lube_items'] = $pdo->query("SELECT * FROM station_lube_items WHERE section_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
            } else { $snapshot['lube_items'] = []; }

            // Lube store items + issues
            $stmt = $pdo->prepare("SELECT * FROM station_lube_store_items WHERE session_id = ? AND company_id = ?");
            $stmt->execute([$session_id, $company_id]);
            $snapshot['lube_store_items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $lsi_ids = array_column($snapshot['lube_store_items'], 'id');
            if ($lsi_ids) {
                $in = implode(',', array_map('intval', $lsi_ids));
                $snapshot['lube_issues'] = $pdo->query("SELECT * FROM station_lube_issues WHERE store_item_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
            } else { $snapshot['lube_issues'] = []; }

            // 2. Move to trash
            $label = ($session_row['outlet_name'] ?? 'Station') . ' — ' . $session_row['date_from'] . ' to ' . $session_row['date_to'];
            move_to_trash($pdo, $company_id, 'audit_session', $session_id, $label, $snapshot, $user_id);

            // 3. Cascade delete original records
            if ($pt_ids) { $pdo->exec("DELETE FROM station_pump_readings WHERE pump_table_id IN (" . implode(',', array_map('intval', $pt_ids)) . ")"); }
            $pdo->prepare("DELETE FROM station_pump_tables WHERE session_id = ? AND company_id = ?")->execute([$session_id, $company_id]);
            $pdo->prepare("DELETE FROM station_system_sales WHERE session_id = ? AND company_id = ?")->execute([$session_id, $company_id]);
            $pdo->prepare("DELETE FROM station_tank_dipping WHERE session_id = ? AND company_id = ?")->execute([$session_id, $company_id]);
            $pdo->prepare("DELETE FROM station_haulage WHERE session_id = ? AND company_id = ?")->execute([$session_id, $company_id]);
            if ($ec_ids) { $pdo->exec("DELETE FROM station_expense_ledger WHERE category_id IN (" . implode(',', array_map('intval', $ec_ids)) . ")"); }
            $pdo->prepare("DELETE FROM station_expense_categories WHERE session_id = ? AND company_id = ?")->execute([$session_id, $company_id]);
            if ($da_ids) { $pdo->exec("DELETE FROM station_debtor_ledger WHERE account_id IN (" . implode(',', array_map('intval', $da_ids)) . ")"); }
            $pdo->prepare("DELETE FROM station_debtor_accounts WHERE session_id = ? AND company_id = ?")->execute([$session_id, $company_id]);
            if ($ls_ids) { $pdo->exec("DELETE FROM station_lube_items WHERE section_id IN (" . implode(',', array_map('intval', $ls_ids)) . ")"); }
            $pdo->prepare("DELETE FROM station_lube_sections WHERE session_id = ? AND company_id = ?")->execute([$session_id, $company_id]);
            if ($lsi_ids) { $pdo->exec("DELETE FROM station_lube_issues WHERE store_item_id IN (" . implode(',', array_map('intval', $lsi_ids)) . ")"); }
            $pdo->prepare("DELETE FROM station_lube_store_items WHERE session_id = ? AND company_id = ?")->execute([$session_id, $company_id]);
            $pdo->prepare("DELETE FROM station_audit_sessions WHERE id = ? AND company_id = ?")->execute([$session_id, $company_id]);

            log_audit($company_id, $user_id, 'station_session_trashed', 'station_audit', $session_id);
            echo json_encode(['success' => true, 'message' => 'Session moved to trash (60-day retention)']);
            break;

        // ───────── Restore Session from Trash ─────────
        case 'restore_session':
            $trash_id = intval($_POST['trash_id'] ?? 0);
            $row = restore_from_trash($pdo, $trash_id, $company_id);
            if (!$row) { echo json_encode(['success' => false, 'message' => 'Trash item not found']); break; }

            $d = $row['item_data'];
            $s = $d['session'];

            // Re-insert session
            $pdo->prepare("INSERT INTO station_audit_sessions (id, company_id, client_id, outlet_id, date_from, date_to, status, created_by, created_at, auditor_id, auditor_signed_at, auditor_comments, manager_id, manager_signed_at, manager_comments) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$s['id'], $s['company_id'], $s['client_id'], $s['outlet_id'], $s['date_from'], $s['date_to'], $s['status'], $s['created_by'], $s['created_at'], $s['auditor_id'] ?? null, $s['auditor_signed_at'] ?? null, $s['auditor_comments'] ?? null, $s['manager_id'] ?? null, $s['manager_signed_at'] ?? null, $s['manager_comments'] ?? null]);

            // Re-insert system sales
            foreach ($d['system_sales'] ?? [] as $r) {
                $pdo->prepare("INSERT INTO station_system_sales (id, session_id, company_id, pos_amount, cash_amount, transfer_amount, teller_amount, total, notes) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$r['id'], $r['session_id'], $r['company_id'], $r['pos_amount'], $r['cash_amount'], $r['transfer_amount'], $r['teller_amount'], $r['total'], $r['notes']]);
            }

            // Re-insert pump tables
            foreach ($d['pump_tables'] ?? [] as $r) {
                $pdo->prepare("INSERT INTO station_pump_tables (id, session_id, company_id, product, sort_order, opening_date, closing_date, is_closed, created_at) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$r['id'], $r['session_id'], $r['company_id'], $r['product'], $r['sort_order'], $r['opening_date'], $r['closing_date'], $r['is_closed'], $r['created_at']]);
            }
            // Re-insert pump readings
            foreach ($d['pump_readings'] ?? [] as $r) {
                $pdo->prepare("INSERT INTO station_pump_readings (id, pump_table_id, pump_name, opening, closing, litres, rate, amount) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$r['id'], $r['pump_table_id'], $r['pump_name'], $r['opening'], $r['closing'], $r['litres'], $r['rate'], $r['amount']]);
            }

            // Re-insert tank dipping
            foreach ($d['tank_dipping'] ?? [] as $r) {
                $pdo->prepare("INSERT INTO station_tank_dipping (id, session_id, company_id, pump_table_id, tank_name, product, opening_dip, closing_dip, received, sold, variance) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$r['id'], $r['session_id'], $r['company_id'], $r['pump_table_id'], $r['tank_name'], $r['product'], $r['opening_dip'], $r['closing_dip'], $r['received'], $r['sold'], $r['variance']]);
            }

            // Re-insert haulage
            foreach ($d['haulage'] ?? [] as $r) {
                $pdo->prepare("INSERT INTO station_haulage (id, session_id, company_id, product, quantity, truck_no, waybill_no, supplier, received_date) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$r['id'], $r['session_id'], $r['company_id'], $r['product'], $r['quantity'], $r['truck_no'], $r['waybill_no'], $r['supplier'], $r['received_date']]);
            }

            // Re-insert expense categories + ledger
            foreach ($d['expense_categories'] ?? [] as $r) {
                $pdo->prepare("INSERT INTO station_expense_categories (id, session_id, company_id, name, created_at) VALUES (?,?,?,?,?)")
                    ->execute([$r['id'], $r['session_id'], $r['company_id'], $r['name'], $r['created_at']]);
            }
            foreach ($d['expense_ledger'] ?? [] as $r) {
                $pdo->prepare("INSERT INTO station_expense_ledger (id, category_id, company_id, entry_date, description, debit, credit, payment_method, created_at) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$r['id'], $r['category_id'], $r['company_id'], $r['entry_date'], $r['description'], $r['debit'], $r['credit'], $r['payment_method'], $r['created_at']]);
            }

            // Re-insert debtor accounts + ledger
            foreach ($d['debtor_accounts'] ?? [] as $r) {
                $pdo->prepare("INSERT INTO station_debtor_accounts (id, session_id, company_id, name, created_at) VALUES (?,?,?,?,?)")
                    ->execute([$r['id'], $r['session_id'], $r['company_id'], $r['name'], $r['created_at']]);
            }
            foreach ($d['debtor_ledger'] ?? [] as $r) {
                $pdo->prepare("INSERT INTO station_debtor_ledger (id, account_id, company_id, entry_date, description, debit, credit, created_at) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$r['id'], $r['account_id'], $r['company_id'], $r['entry_date'], $r['description'], $r['debit'], $r['credit'], $r['created_at']]);
            }

            // Re-insert lube sections + items
            foreach ($d['lube_sections'] ?? [] as $r) {
                $pdo->prepare("INSERT INTO station_lube_sections (id, session_id, company_id, name, created_at) VALUES (?,?,?,?,?)")
                    ->execute([$r['id'], $r['session_id'], $r['company_id'], $r['name'], $r['created_at']]);
            }
            foreach ($d['lube_items'] ?? [] as $r) {
                $pdo->prepare("INSERT INTO station_lube_items (id, section_id, company_id, product_name, opening_stock, received, sold, closing_stock, selling_price, amount) VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$r['id'], $r['section_id'], $r['company_id'], $r['product_name'], $r['opening_stock'], $r['received'], $r['sold'], $r['closing_stock'], $r['selling_price'], $r['amount']]);
            }

            // Re-insert lube store items + issues
            foreach ($d['lube_store_items'] ?? [] as $r) {
                $pdo->prepare("INSERT INTO station_lube_store_items (id, session_id, company_id, product_id, product_name, opening_stock, received, issued, closing_stock, cost_price, selling_price) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$r['id'], $r['session_id'], $r['company_id'], $r['product_id'], $r['product_name'], $r['opening_stock'], $r['received'], $r['issued'], $r['closing_stock'], $r['cost_price'], $r['selling_price']]);
            }
            foreach ($d['lube_issues'] ?? [] as $r) {
                $pdo->prepare("INSERT INTO station_lube_issues (id, store_item_id, company_id, section_id, qty, created_at) VALUES (?,?,?,?,?,?)")
                    ->execute([$r['id'], $r['store_item_id'], $r['company_id'], $r['section_id'], $r['qty'], $r['created_at']]);
            }

            log_audit($company_id, $user_id, 'station_session_restored', 'station_audit', $s['id']);
            echo json_encode(['success' => true, 'message' => 'Session restored from trash']);
            break;

        // ───────── List Trash Items ─────────
        case 'list_trash':
            $type = $_POST['item_type'] ?? 'audit_session';
            $items = list_trash($pdo, $company_id, $type);
            echo json_encode(['success' => true, 'items' => $items]);
            break;

        // ───────── Permanent Delete from Trash ─────────
        case 'permanent_delete_trash':
            $trash_id = intval($_POST['trash_id'] ?? 0);
            $ok = permanent_delete_trash($pdo, $trash_id, $company_id);
            log_audit($company_id, $user_id, 'trash_permanent_delete', 'system_trash', $trash_id);
            echo json_encode(['success' => $ok]);
            break;

        // ───────── Create Lube Section ─────────
        case 'create_lube_section':
            $session_id = intval($_POST['session_id'] ?? 0);
            $name = clean_input($_POST['name'] ?? 'Counter 1');

            // Check max 3
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM station_lube_sections WHERE session_id = ? AND company_id = ?");
            $stmt->execute([$session_id, $company_id]);
            if ($stmt->fetchColumn() >= 3) {
                echo json_encode(['success' => false, 'message' => 'Maximum 3 lube counters allowed per session']);
                break;
            }

            $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM station_lube_sections WHERE session_id = ? AND company_id = ?");
            $stmt->execute([$session_id, $company_id]);
            $next_order = $stmt->fetchColumn();

            $stmt = $pdo->prepare("INSERT INTO station_lube_sections (session_id, company_id, name, sort_order) VALUES (?, ?, ?, ?)");
            $stmt->execute([$session_id, $company_id, $name, $next_order]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            break;

        // ───────── Delete Lube Section ─────────
        case 'delete_lube_section':
            $section_id = intval($_POST['section_id'] ?? 0);

            // Snapshot
            $stmt = $pdo->prepare("SELECT * FROM station_lube_sections WHERE id = ? AND company_id = ?");
            $stmt->execute([$section_id, $company_id]);
            $sec_row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($sec_row) {
                $snap = $sec_row;
                $stmt2 = $pdo->prepare("SELECT * FROM station_lube_items WHERE section_id = ? AND company_id = ?");
                $stmt2->execute([$section_id, $company_id]);
                $snap['items'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                move_to_trash($pdo, $company_id, 'lube_section', $section_id, $sec_row['name'] ?? 'Lube Section', $snap, $user_id);
            }

            $pdo->prepare("DELETE FROM station_lube_items WHERE section_id = ? AND company_id = ?")->execute([$section_id, $company_id]);
            $pdo->prepare("DELETE FROM station_lube_sections WHERE id = ? AND company_id = ?")->execute([$section_id, $company_id]);
            echo json_encode(['success' => true]);
            break;

        // ───────── Save Lube Items (Bulk) ─────────
        case 'save_lube_items':
            $section_id = intval($_POST['section_id'] ?? 0);
            $items_json = $_POST['items'] ?? '[]';
            $items = json_decode($items_json, true);

            if (!is_array($items)) {
                echo json_encode(['success' => false, 'message' => 'Invalid item data']);
                break;
            }

            // Delete & re-insert
            $pdo->prepare("DELETE FROM station_lube_items WHERE section_id = ? AND company_id = ?")->execute([$section_id, $company_id]);

            $ins = $pdo->prepare("INSERT INTO station_lube_items (section_id, store_item_id, company_id, item_name, opening, received, sold, closing, selling_price, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $order = 0;
            foreach ($items as $it) {
                $ins->execute([
                    $section_id,
                    !empty($it['store_item_id']) ? intval($it['store_item_id']) : null,
                    $company_id,
                    clean_input($it['item_name'] ?? ''),
                    floatval($it['opening'] ?? 0),
                    floatval($it['received'] ?? 0),
                    floatval($it['sold'] ?? 0),
                    floatval($it['closing'] ?? 0),
                    floatval($it['selling_price'] ?? 0),
                    $order++
                ]);
            }

            echo json_encode(['success' => true, 'count' => count($items)]);
            break;

        // ───────── Save Lube Store Items (Bulk) ─────────
        case 'save_lube_store_items':
            $session_id = intval($_POST['session_id'] ?? 0);
            $items_json = $_POST['items'] ?? '[]';
            $items = json_decode($items_json, true);

            if (!is_array($items)) {
                echo json_encode(['success' => false, 'message' => 'Invalid item data']);
                break;
            }

            // Delete & re-insert store items (and their issues)
            $pdo->prepare("DELETE FROM station_lube_issues WHERE store_item_id IN (SELECT id FROM station_lube_store_items WHERE session_id = ? AND company_id = ?)")->execute([$session_id, $company_id]);
            $pdo->prepare("DELETE FROM station_lube_store_items WHERE session_id = ? AND company_id = ?")->execute([$session_id, $company_id]);

            $ins = $pdo->prepare("INSERT INTO station_lube_store_items (session_id, company_id, item_name, opening, received, return_out, adjustment, selling_price, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $order = 0;
            $new_ids = [];
            foreach ($items as $it) {
                $ins->execute([
                    $session_id, $company_id,
                    clean_input($it['item_name'] ?? ''),
                    floatval($it['opening'] ?? 0),
                    floatval($it['received'] ?? 0),
                    floatval($it['return_out'] ?? 0),
                    floatval($it['adjustment'] ?? 0),
                    floatval($it['selling_price'] ?? 0),
                    $order++
                ]);
                $new_ids[] = $pdo->lastInsertId();
            }

            echo json_encode(['success' => true, 'count' => count($items), 'ids' => $new_ids]);
            break;

        // ───────── Issue Lube to Counter ─────────
        case 'issue_lube_to_counter':
            $store_item_id = intval($_POST['store_item_id'] ?? 0);
            $section_id    = intval($_POST['section_id'] ?? 0);
            $quantity      = floatval($_POST['quantity'] ?? 0);

            if (!$store_item_id || !$section_id) {
                echo json_encode(['success' => false, 'message' => 'Missing store_item_id or section_id']);
                break;
            }

            // Accumulative upsert (unique key on store_item_id + section_id)
            $stmt = $pdo->prepare("INSERT INTO station_lube_issues (store_item_id, section_id, company_id, quantity)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)");
            $stmt->execute([$store_item_id, $section_id, $company_id, $quantity]);

            // Log the issue event for audit history
            $pname_stmt = $pdo->prepare("SELECT item_name FROM station_lube_store_items WHERE id=? AND company_id=?");
            $pname_stmt->execute([$store_item_id, $company_id]);
            $product_name = $pname_stmt->fetchColumn() ?: '';
            $cname_stmt = $pdo->prepare("SELECT name FROM station_lube_sections WHERE id=? AND company_id=?");
            $cname_stmt->execute([$section_id, $company_id]);
            $counter_name = $cname_stmt->fetchColumn() ?: '';
            $pdo->prepare("INSERT INTO station_lube_issue_log (store_item_id, section_id, company_id, quantity, product_name, counter_name) VALUES (?,?,?,?,?,?)")
                ->execute([$store_item_id, $section_id, $company_id, $quantity, $product_name, $counter_name]);

            echo json_encode(['success' => true]);
            break;

        // ───────── Lube Products CRUD ─────────
        case 'get_lube_products':
            $stmt = $pdo->prepare("SELECT * FROM station_lube_products WHERE company_id = ? ORDER BY product_name");
            $stmt->execute([$company_id]);
            echo json_encode(['success' => true, 'products' => $stmt->fetchAll()]);
            break;

        case 'save_lube_product':
            $id            = intval($_POST['id'] ?? 0);
            $product_name  = clean_input($_POST['product_name'] ?? '');
            $unit          = clean_input($_POST['unit'] ?? 'Litre');
            $cost_price    = floatval($_POST['cost_price'] ?? 0);
            $selling_price = floatval($_POST['selling_price'] ?? 0);
            $reorder_level = floatval($_POST['reorder_level'] ?? 0);
            if (!$product_name) { echo json_encode(['success' => false, 'message' => 'Product name required']); break; }
            if ($id) {
                $pdo->prepare("UPDATE station_lube_products SET product_name=?, unit=?, cost_price=?, selling_price=?, reorder_level=? WHERE id=? AND company_id=?")
                    ->execute([$product_name, $unit, $cost_price, $selling_price, $reorder_level, $id, $company_id]);
                echo json_encode(['success' => true, 'id' => $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO station_lube_products (company_id, product_name, unit, cost_price, selling_price, reorder_level) VALUES (?,?,?,?,?,?)");
                $stmt->execute([$company_id, $product_name, $unit, $cost_price, $selling_price, $reorder_level]);
                echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            }
            break;

        case 'delete_lube_product':
            $id = intval($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM station_lube_products WHERE id=? AND company_id=?");
            $stmt->execute([$id, $company_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) move_to_trash($pdo, $company_id, 'lube_product', $id, $row['product_name'] ?? 'Lube Product', $row, $user_id);
            $pdo->prepare("DELETE FROM station_lube_products WHERE id=? AND company_id=?")->execute([$id, $company_id]);
            echo json_encode(['success' => true]);
            break;

        // ───────── Lube Suppliers CRUD ─────────
        case 'get_lube_suppliers':
            $stmt = $pdo->prepare("SELECT * FROM station_lube_suppliers WHERE company_id = ? ORDER BY supplier_name");
            $stmt->execute([$company_id]);
            echo json_encode(['success' => true, 'suppliers' => $stmt->fetchAll()]);
            break;

        case 'save_lube_supplier':
            $id             = intval($_POST['id'] ?? 0);
            $supplier_name  = clean_input($_POST['supplier_name'] ?? '');
            $contact_person = clean_input($_POST['contact_person'] ?? '');
            $phone          = clean_input($_POST['phone'] ?? '');
            $email          = clean_input($_POST['email'] ?? '');
            $address        = clean_input($_POST['address'] ?? '');
            if (!$supplier_name) { echo json_encode(['success' => false, 'message' => 'Supplier name required']); break; }
            if ($id) {
                $pdo->prepare("UPDATE station_lube_suppliers SET supplier_name=?, contact_person=?, phone=?, email=?, address=? WHERE id=? AND company_id=?")
                    ->execute([$supplier_name, $contact_person, $phone, $email, $address, $id, $company_id]);
                echo json_encode(['success' => true, 'id' => $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO station_lube_suppliers (company_id, supplier_name, contact_person, phone, email, address) VALUES (?,?,?,?,?,?)");
                $stmt->execute([$company_id, $supplier_name, $contact_person, $phone, $email, $address]);
                echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            }
            break;

        case 'delete_lube_supplier':
            $id = intval($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM station_lube_suppliers WHERE id=? AND company_id=?");
            $stmt->execute([$id, $company_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) move_to_trash($pdo, $company_id, 'lube_supplier', $id, $row['supplier_name'] ?? 'Supplier', $row, $user_id);
            $pdo->prepare("DELETE FROM station_lube_suppliers WHERE id=? AND company_id=?")->execute([$id, $company_id]);
            echo json_encode(['success' => true]);
            break;

        // ───────── GRN CRUD ─────────
        case 'get_lube_grns':
            $grn_session_id = intval($_POST['session_id'] ?? 0);
            if ($grn_session_id) {
                $stmt = $pdo->prepare("SELECT g.*, s.supplier_name FROM station_lube_grn g LEFT JOIN station_lube_suppliers s ON g.supplier_id=s.id WHERE g.company_id=? AND g.session_id=? ORDER BY g.grn_date DESC, g.id DESC LIMIT 100");
                $stmt->execute([$company_id, $grn_session_id]);
            } else {
                $stmt = $pdo->prepare("SELECT g.*, s.supplier_name FROM station_lube_grn g LEFT JOIN station_lube_suppliers s ON g.supplier_id=s.id WHERE g.company_id=? ORDER BY g.grn_date DESC, g.id DESC LIMIT 100");
                $stmt->execute([$company_id]);
            }
            $grns = $stmt->fetchAll();
            foreach ($grns as &$grn) {
                $stmt2 = $pdo->prepare("SELECT gi.* FROM station_lube_grn_items gi WHERE gi.grn_id=?");
                $stmt2->execute([$grn['id']]);
                $grn['items'] = $stmt2->fetchAll();
            }
            echo json_encode(['success' => true, 'grns' => $grns]);
            break;

        case 'save_lube_grn':
            $id             = intval($_POST['id'] ?? 0);
            $supplier_id    = intval($_POST['supplier_id'] ?? 0) ?: null;
            $session_id_grn = intval($_POST['session_id'] ?? 0) ?: null;
            $grn_number     = clean_input($_POST['grn_number'] ?? '');
            $grn_date       = clean_input($_POST['grn_date'] ?? date('Y-m-d'));
            $invoice_number = clean_input($_POST['invoice_number'] ?? '');
            $notes          = clean_input($_POST['notes'] ?? '');
            $items_json     = $_POST['items'] ?? '[]';
            $items          = json_decode($items_json, true) ?: [];

            $total_cost = 0;
            foreach ($items as $it) { $total_cost += floatval($it['total_cost'] ?? (floatval($it['quantity'] ?? 0) * floatval($it['cost_price'] ?? 0))); }

            if ($id) {
                $pdo->prepare("UPDATE station_lube_grn SET supplier_id=?, session_id=?, grn_number=?, grn_date=?, invoice_number=?, notes=?, total_cost=? WHERE id=? AND company_id=?")
                    ->execute([$supplier_id, $session_id_grn, $grn_number, $grn_date, $invoice_number, $notes, $total_cost, $id, $company_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO station_lube_grn (company_id, supplier_id, session_id, grn_number, grn_date, invoice_number, notes, total_cost) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->execute([$company_id, $supplier_id, $session_id_grn, $grn_number, $grn_date, $invoice_number, $notes, $total_cost]);
                $id = $pdo->lastInsertId();
            }
            $pdo->prepare("DELETE FROM station_lube_grn_items WHERE grn_id=? AND company_id=?")->execute([$id, $company_id]);
            $ins = $pdo->prepare("INSERT INTO station_lube_grn_items (grn_id, company_id, product_id, product_name, quantity, unit, cost_price, selling_price, line_total) VALUES (?,?,?,?,?,?,?,?,?)");
            foreach ($items as $it) {
                $qty    = floatval($it['quantity'] ?? 0);
                $cp     = floatval($it['cost_price'] ?? 0);
                $sp     = floatval($it['selling_price'] ?? 0);
                $pid    = !empty($it['product_id']) ? intval($it['product_id']) : null;
                $pname  = clean_input($it['product_name'] ?? '');
                $unit_v = clean_input($it['unit'] ?? 'Litre');
                $ins->execute([$id, $company_id, $pid, $pname, $qty, $unit_v, $cp, $sp, floatval($it['total_cost'] ?? $qty * $cp)]);

                // GRN cost overrides product catalog price
                if ($pid && ($cp > 0 || $sp > 0)) {
                    $upd_fields = [];
                    $upd_vals   = [];
                    if ($cp > 0) { $upd_fields[] = 'cost_price=?';    $upd_vals[] = $cp; }
                    if ($sp > 0) { $upd_fields[] = 'selling_price=?'; $upd_vals[] = $sp; }
                    $upd_vals[] = $pid;
                    $upd_vals[] = $company_id;
                    $pdo->prepare("UPDATE station_lube_products SET " . implode(', ', $upd_fields) . " WHERE id=? AND company_id=?")
                        ->execute($upd_vals);
                }
            }

            // ── Sync GRN received quantities → Lube Store ──
            // Recalculate total received per product from ALL GRNs for this session
            if ($session_id_grn) {
                $grn_totals = $pdo->prepare("
                    SELECT gi.product_name, SUM(gi.quantity) AS total_qty, MAX(gi.selling_price) AS sell_price
                    FROM station_lube_grn_items gi
                    JOIN station_lube_grn g ON g.id = gi.grn_id AND g.company_id = gi.company_id
                    WHERE g.session_id = ? AND g.company_id = ?
                    GROUP BY gi.product_name
                ");
                $grn_totals->execute([$session_id_grn, $company_id]);
                $received_map = $grn_totals->fetchAll(PDO::FETCH_ASSOC);

                foreach ($received_map as $row) {
                    $pname_clean = $row['product_name'];
                    $recv_qty    = floatval($row['total_qty']);
                    $sell_price  = floatval($row['sell_price']);
                    // Check if a store item already exists for this product + session
                    $existing = $pdo->prepare("SELECT id FROM station_lube_store_items WHERE session_id=? AND company_id=? AND item_name=?");
                    $existing->execute([$session_id_grn, $company_id, $pname_clean]);
                    $store_row = $existing->fetch(PDO::FETCH_ASSOC);

                    if ($store_row) {
                        // Update received quantity
                        $pdo->prepare("UPDATE station_lube_store_items SET received=?, selling_price=CASE WHEN selling_price=0 THEN ? ELSE selling_price END WHERE id=?")
                            ->execute([$recv_qty, $sell_price, $store_row['id']]);
                    } else {
                        // Create new store item with received quantity
                        $pdo->prepare("INSERT INTO station_lube_store_items (session_id, company_id, item_name, opening, received, return_out, selling_price, sort_order) VALUES (?,?,?,0,?,0,?,999)")
                            ->execute([$session_id_grn, $company_id, $pname_clean, $recv_qty, $sell_price]);
                    }
                }
            }

            echo json_encode(['success' => true, 'id' => $id, 'total_cost' => $total_cost]);
            break;

        case 'delete_lube_grn':
            $id = intval($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM station_lube_grn WHERE id=? AND company_id=?");
            $stmt->execute([$id, $company_id]);
            $grn_row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($grn_row) {
                $snap = $grn_row;
                $stmt2 = $pdo->prepare("SELECT * FROM station_lube_grn_items WHERE grn_id=? AND company_id=?");
                $stmt2->execute([$id, $company_id]);
                $snap['items'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                $label = 'GRN #' . $id . ' — ' . ($grn_row['grn_date'] ?? '');
                move_to_trash($pdo, $company_id, 'lube_grn', $id, $label, $snap, $user_id);
            }
            $pdo->prepare("DELETE FROM station_lube_grn_items WHERE grn_id=? AND company_id=?")->execute([$id, $company_id]);
            $pdo->prepare("DELETE FROM station_lube_grn WHERE id=? AND company_id=?")->execute([$id, $company_id]);
            echo json_encode(['success' => true]);
            break;

        // ───────── Lube Stock Count ─────────
        case 'get_lube_stock_counts':
            $sc_session = intval($_POST['session_id'] ?? 0);
            if ($sc_session) {
                $counts = $pdo->prepare("SELECT * FROM station_lube_stock_counts WHERE company_id=? AND session_id=? ORDER BY date_from DESC");
                $counts->execute([$company_id, $sc_session]);
            } else {
                $counts = $pdo->prepare("SELECT * FROM station_lube_stock_counts WHERE company_id=? ORDER BY date_from DESC");
                $counts->execute([$company_id]);
            }
            $result = [];
            foreach ($counts->fetchAll(PDO::FETCH_ASSOC) as $sc) {
                $items_stmt = $pdo->prepare("SELECT * FROM station_lube_stock_count_items WHERE count_id=? AND company_id=? ORDER BY id");
                $items_stmt->execute([$sc['id'], $company_id]);
                $sc['items'] = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
                $result[] = $sc;
            }
            echo json_encode(['success' => true, 'counts' => $result]);
            break;

        case 'save_lube_stock_count':
            $id        = intval($_POST['id'] ?? 0);
            $session_id_sc = intval($_POST['session_id'] ?? 0) ?: null;
            $date_from = clean_input($_POST['date_from'] ?? date('Y-m-d'));
            $date_to   = clean_input($_POST['date_to'] ?? date('Y-m-d'));
            $notes     = clean_input($_POST['notes'] ?? '');
            $items_json = $_POST['items'] ?? '[]';
            $items      = json_decode($items_json, true) ?: [];

            if ($id) {
                $pdo->prepare("UPDATE station_lube_stock_counts SET session_id=?, date_from=?, date_to=?, notes=? WHERE id=? AND company_id=?")
                    ->execute([$session_id_sc, $date_from, $date_to, $notes, $id, $company_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO station_lube_stock_counts (company_id, session_id, date_from, date_to, notes) VALUES (?,?,?,?,?)");
                $stmt->execute([$company_id, $session_id_sc, $date_from, $date_to, $notes]);
                $id = $pdo->lastInsertId();
            }
            // Delete & re-insert items
            $pdo->prepare("DELETE FROM station_lube_stock_count_items WHERE count_id=? AND company_id=?")->execute([$id, $company_id]);
            $ins = $pdo->prepare("INSERT INTO station_lube_stock_count_items (count_id, company_id, product_name, system_stock, physical_count, variance, cost_price, selling_price, sold_qty, sold_value_cost) VALUES (?,?,?,?,?,?,?,?,?,?)");
            foreach ($items as $it) {
                $sys   = intval($it['system_stock'] ?? 0);
                $phys  = intval($it['physical_count'] ?? 0);
                $var   = $phys - $sys;
                $cp    = floatval($it['cost_price'] ?? 0);
                $sp    = floatval($it['selling_price'] ?? 0);
                $sold  = intval($it['sold_qty'] ?? 0);
                $sold_val = $sold * $cp;
                $ins->execute([$id, $company_id, clean_input($it['product_name'] ?? ''), $sys, $phys, $var, $cp, $sp, $sold, $sold_val]);
            }
            echo json_encode(['success' => true, 'id' => $id]);
            break;

        case 'delete_lube_stock_count':
            $id = intval($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM station_lube_stock_counts WHERE id=? AND company_id=?");
            $stmt->execute([$id, $company_id]);
            $sc_row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($sc_row) {
                $snap = $sc_row;
                $stmt2 = $pdo->prepare("SELECT * FROM station_lube_stock_count_items WHERE count_id=? AND company_id=?");
                $stmt2->execute([$id, $company_id]);
                $snap['items'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                $label = 'Stock Count — ' . ($sc_row['date_from'] ?? '') . ' to ' . ($sc_row['date_to'] ?? '');
                move_to_trash($pdo, $company_id, 'lube_stock_count', $id, $label, $snap, $user_id);
            }
            $pdo->prepare("DELETE FROM station_lube_stock_count_items WHERE count_id=? AND company_id=?")->execute([$id, $company_id]);
            $pdo->prepare("DELETE FROM station_lube_stock_counts WHERE id=? AND company_id=?")->execute([$id, $company_id]);
            echo json_encode(['success' => true]);
            break;

        case 'finalize_lube_stock_count':
            $id = intval($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE station_lube_stock_counts SET status='closed' WHERE id=? AND company_id=?")->execute([$id, $company_id]);
            echo json_encode(['success' => true]);
            break;

        case 'save_lube_adjustment':
            $store_item_id = intval($_POST['store_item_id'] ?? 0);
            $adjustment_qty = floatval($_POST['adjustment_qty'] ?? 0);
            $reason = clean_input($_POST['adjustment_reason'] ?? '');
            $pdo->prepare("UPDATE station_lube_store_items SET adjustment=? WHERE id=? AND company_id=?")
                ->execute([$adjustment_qty, $store_item_id, $company_id]);
            echo json_encode(['success' => true]);
            break;

        // ───────── Counter Stock Count (period-based per counter) ─────────
        case 'get_counter_stock_counts':
            $section_id = intval($_POST['section_id'] ?? 0);
            $counts = $pdo->prepare("SELECT * FROM station_counter_stock_counts WHERE company_id=? AND section_id=? ORDER BY date_from DESC");
            $counts->execute([$company_id, $section_id]);
            $result = [];
            foreach ($counts->fetchAll(PDO::FETCH_ASSOC) as $sc) {
                $items_stmt = $pdo->prepare("SELECT * FROM station_counter_stock_count_items WHERE count_id=? AND company_id=? ORDER BY id");
                $items_stmt->execute([$sc['id'], $company_id]);
                $sc['items'] = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
                $result[] = $sc;
            }
            echo json_encode(['success' => true, 'counts' => $result]);
            break;

        case 'save_counter_stock_count':
            $id         = intval($_POST['id'] ?? 0);
            $section_id = intval($_POST['section_id'] ?? 0);
            $date_from  = clean_input($_POST['date_from'] ?? date('Y-m-d'));
            $date_to    = clean_input($_POST['date_to'] ?? date('Y-m-d'));
            $notes      = clean_input($_POST['notes'] ?? '');
            $items_json = $_POST['items'] ?? '[]';
            $items      = json_decode($items_json, true) ?: [];

            if ($id) {
                $pdo->prepare("UPDATE station_counter_stock_counts SET section_id=?, date_from=?, date_to=?, notes=? WHERE id=? AND company_id=?")
                    ->execute([$section_id, $date_from, $date_to, $notes, $id, $company_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO station_counter_stock_counts (company_id, section_id, date_from, date_to, notes) VALUES (?,?,?,?,?)");
                $stmt->execute([$company_id, $section_id, $date_from, $date_to, $notes]);
                $id = $pdo->lastInsertId();
            }
            // Delete & re-insert items
            $pdo->prepare("DELETE FROM station_counter_stock_count_items WHERE count_id=? AND company_id=?")->execute([$id, $company_id]);
            $ins = $pdo->prepare("INSERT INTO station_counter_stock_count_items (count_id, company_id, product_name, system_stock, physical_count, variance, selling_price, sold_qty, sold_value) VALUES (?,?,?,?,?,?,?,?,?)");
            foreach ($items as $it) {
                $sys   = intval($it['system_stock'] ?? 0);
                $phys  = intval($it['physical_count'] ?? 0);
                $var   = $phys - $sys;
                $sp    = floatval($it['selling_price'] ?? 0);
                $sold  = intval($it['sold_qty'] ?? 0);
                $sold_val = $sold * $sp;
                $ins->execute([$id, $company_id, clean_input($it['product_name'] ?? ''), $sys, $phys, $var, $sp, $sold, $sold_val]);
            }
            echo json_encode(['success' => true, 'id' => $id]);
            break;

        case 'delete_counter_stock_count':
            $id = intval($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM station_counter_stock_counts WHERE id=? AND company_id=?");
            $stmt->execute([$id, $company_id]);
            $csc_row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($csc_row) {
                $snap = $csc_row;
                $stmt2 = $pdo->prepare("SELECT * FROM station_counter_stock_count_items WHERE count_id=? AND company_id=?");
                $stmt2->execute([$id, $company_id]);
                $snap['items'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                $label = 'Counter Stock — ' . ($csc_row['date_from'] ?? '') . ' to ' . ($csc_row['date_to'] ?? '');
                move_to_trash($pdo, $company_id, 'counter_stock_count', $id, $label, $snap, $user_id);
            }
            $pdo->prepare("DELETE FROM station_counter_stock_count_items WHERE count_id=? AND company_id=?")->execute([$id, $company_id]);
            $pdo->prepare("DELETE FROM station_counter_stock_counts WHERE id=? AND company_id=?")->execute([$id, $company_id]);
            echo json_encode(['success' => true]);
            break;

        case 'finalize_counter_stock_count':
            $id = intval($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE station_counter_stock_counts SET status='closed' WHERE id=? AND company_id=?")->execute([$id, $company_id]);
            echo json_encode(['success' => true]);
            break;

        // ───────── Create Expense Category ─────────
        case 'create_expense_category':
            $session_id = intval($_POST['session_id'] ?? 0);
            $category_name = clean_input($_POST['category_name'] ?? '');
            if (empty($category_name)) {
                echo json_encode(['success' => false, 'message' => 'Category name is required']);
                break;
            }
            if (reject_ampersand($category_name, 'Category name')) break;
            $stmt = $pdo->prepare("INSERT INTO station_expense_categories (session_id, company_id, category_name) VALUES (?, ?, ?)");
            $stmt->execute([$session_id, $company_id, $category_name]);
            $cat_id = $pdo->lastInsertId();
            echo json_encode(['success' => true, 'id' => $cat_id, 'category_name' => $category_name]);
            break;

        // ───────── Add Expense Ledger Entry ─────────
        case 'add_expense_entry':
            $category_id    = intval($_POST['category_id'] ?? 0);
            $entry_date     = $_POST['entry_date'] ?? date('Y-m-d');
            $description    = clean_input($_POST['description'] ?? '');
            $debit          = floatval($_POST['debit'] ?? 0);
            $credit         = floatval($_POST['credit'] ?? 0);
            $payment_method = clean_input($_POST['payment_method'] ?? 'cash');

            $stmt = $pdo->prepare("INSERT INTO station_expense_ledger (category_id, company_id, entry_date, description, debit, credit, payment_method) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$category_id, $company_id, $entry_date, $description, $debit, $credit, $payment_method]);
            $entry_id = $pdo->lastInsertId();
            echo json_encode(['success' => true, 'id' => $entry_id]);
            break;

        // ───────── Delete Expense Ledger Entry ─────────
        case 'delete_expense_entry':
            $entry_id = intval($_POST['entry_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM station_expense_ledger WHERE id = ? AND company_id = ?");
            $stmt->execute([$entry_id, $company_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) move_to_trash($pdo, $company_id, 'expense_entry', $entry_id, ($row['description'] ?? 'Expense') . ' — ' . ($row['entry_date'] ?? ''), $row, $user_id);
            $pdo->prepare("DELETE FROM station_expense_ledger WHERE id = ? AND company_id = ?")->execute([$entry_id, $company_id]);
            echo json_encode(['success' => true]);
            break;

        // ───────── Update Expense Ledger Entry ─────────
        case 'update_expense_entry':
            $entry_id       = intval($_POST['entry_id'] ?? 0);
            $entry_date     = $_POST['entry_date'] ?? date('Y-m-d');
            $description    = clean_input($_POST['description'] ?? '');
            $debit          = floatval($_POST['debit'] ?? 0);
            $credit         = floatval($_POST['credit'] ?? 0);
            $payment_method = clean_input($_POST['payment_method'] ?? 'cash');
            $stmt = $pdo->prepare("UPDATE station_expense_ledger SET entry_date = ?, description = ?, debit = ?, credit = ?, payment_method = ? WHERE id = ? AND company_id = ?");
            $stmt->execute([$entry_date, $description, $debit, $credit, $payment_method, $entry_id, $company_id]);
            echo json_encode(['success' => true]);
            break;

        // ───────── Delete Expense Category ─────────
        case 'delete_expense_category':
            $category_id = intval($_POST['category_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM station_expense_categories WHERE id = ? AND company_id = ?");
            $stmt->execute([$category_id, $company_id]);
            $cat_row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($cat_row) {
                $snap = $cat_row;
                $stmt2 = $pdo->prepare("SELECT * FROM station_expense_ledger WHERE category_id = ? AND company_id = ?");
                $stmt2->execute([$category_id, $company_id]);
                $snap['ledger'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                move_to_trash($pdo, $company_id, 'expense_category', $category_id, $cat_row['category_name'] ?? 'Expense Category', $snap, $user_id);
            }
            $pdo->prepare("DELETE FROM station_expense_ledger WHERE category_id = ? AND company_id = ?")->execute([$category_id, $company_id]);
            $pdo->prepare("DELETE FROM station_expense_categories WHERE id = ? AND company_id = ?")->execute([$category_id, $company_id]);
            echo json_encode(['success' => true]);
            break;

        // ───────── Rename Expense Category ─────────
        case 'rename_expense_category':
            $category_id = intval($_POST['category_id'] ?? 0);
            $new_name = clean_input($_POST['new_name'] ?? '');
            if (empty($new_name)) {
                echo json_encode(['success' => false, 'message' => 'Category name is required']);
                break;
            }
            if (reject_ampersand($new_name, 'Category name')) break;
            $stmt = $pdo->prepare("UPDATE station_expense_categories SET category_name = ? WHERE id = ? AND company_id = ?");
            $stmt->execute([$new_name, $category_id, $company_id]);
            echo json_encode(['success' => true, 'new_name' => $new_name]);
            break;

        // ───────── Create Debtor Account ─────────
        case 'create_debtor_account':
            $session_id = intval($_POST['session_id'] ?? 0);
            $customer_name = clean_input($_POST['customer_name'] ?? '');
            if (empty($customer_name)) {
                echo json_encode(['success' => false, 'message' => 'Customer name is required']);
                break;
            }
            if (reject_ampersand($customer_name, 'Customer name')) break;
            $stmt = $pdo->prepare("INSERT INTO station_debtor_accounts (session_id, company_id, customer_name) VALUES (?, ?, ?)");
            $stmt->execute([$session_id, $company_id, $customer_name]);
            $acct_id = $pdo->lastInsertId();
            echo json_encode(['success' => true, 'id' => $acct_id, 'customer_name' => $customer_name]);
            break;

        // ───────── Add Debtor Ledger Entry ─────────
        case 'add_debtor_entry':
            $account_id  = intval($_POST['account_id'] ?? 0);
            $entry_date  = $_POST['entry_date'] ?? date('Y-m-d');
            $description = clean_input($_POST['description'] ?? '');
            $debit       = floatval($_POST['debit'] ?? 0);
            $credit      = floatval($_POST['credit'] ?? 0);

            $stmt = $pdo->prepare("INSERT INTO station_debtor_ledger (account_id, company_id, entry_date, description, debit, credit) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$account_id, $company_id, $entry_date, $description, $debit, $credit]);
            $entry_id = $pdo->lastInsertId();
            echo json_encode(['success' => true, 'id' => $entry_id]);
            break;

        // ───────── Delete Debtor Ledger Entry ─────────
        case 'delete_debtor_entry':
            $entry_id = intval($_POST['entry_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM station_debtor_ledger WHERE id = ? AND company_id = ?");
            $stmt->execute([$entry_id, $company_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) move_to_trash($pdo, $company_id, 'debtor_entry', $entry_id, ($row['description'] ?? 'Debtor Entry') . ' — ' . ($row['entry_date'] ?? ''), $row, $user_id);
            $pdo->prepare("DELETE FROM station_debtor_ledger WHERE id = ? AND company_id = ?")->execute([$entry_id, $company_id]);
            echo json_encode(['success' => true]);
            break;

        // ───────── Update Debtor Ledger Entry ─────────
        case 'update_debtor_entry':
            $entry_id    = intval($_POST['entry_id'] ?? 0);
            $entry_date  = $_POST['entry_date'] ?? date('Y-m-d');
            $description = clean_input($_POST['description'] ?? '');
            $debit       = floatval($_POST['debit'] ?? 0);
            $credit      = floatval($_POST['credit'] ?? 0);
            $stmt = $pdo->prepare("UPDATE station_debtor_ledger SET entry_date = ?, description = ?, debit = ?, credit = ? WHERE id = ? AND company_id = ?");
            $stmt->execute([$entry_date, $description, $debit, $credit, $entry_id, $company_id]);
            echo json_encode(['success' => true]);
            break;

        // ───────── Delete Debtor Account ─────────
        case 'delete_debtor_account':
            $account_id = intval($_POST['account_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM station_debtor_accounts WHERE id = ? AND company_id = ?");
            $stmt->execute([$account_id, $company_id]);
            $acct_row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($acct_row) {
                $snap = $acct_row;
                $stmt2 = $pdo->prepare("SELECT * FROM station_debtor_ledger WHERE account_id = ? AND company_id = ?");
                $stmt2->execute([$account_id, $company_id]);
                $snap['ledger'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                move_to_trash($pdo, $company_id, 'debtor_account', $account_id, $acct_row['customer_name'] ?? 'Debtor Account', $snap, $user_id);
            }
            $pdo->prepare("DELETE FROM station_debtor_ledger WHERE account_id = ? AND company_id = ?")->execute([$account_id, $company_id]);
            $pdo->prepare("DELETE FROM station_debtor_accounts WHERE id = ? AND company_id = ?")->execute([$account_id, $company_id]);
            echo json_encode(['success' => true]);
            break;

        // ───────── Rename Debtor Account ─────────
        case 'rename_debtor_account':
            $account_id = intval($_POST['account_id'] ?? 0);
            $new_name = clean_input($_POST['new_name'] ?? '');
            if (empty($new_name)) {
                echo json_encode(['success' => false, 'message' => 'Customer name is required']);
                break;
            }
            if (reject_ampersand($new_name, 'Customer name')) break;
            $stmt = $pdo->prepare("UPDATE station_debtor_accounts SET customer_name = ? WHERE id = ? AND company_id = ?");
            $stmt->execute([$new_name, $account_id, $company_id]);
            echo json_encode(['success' => true, 'new_name' => $new_name]);
            break;

        // ───────── Upload Document (with 2MB + 1GB quota) ─────────
        case 'upload_document':
            $session_id = intval($_POST['session_id'] ?? 0);
            $doc_label  = clean_input($_POST['doc_label'] ?? '');

            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
                break;
            }

            // 2MB per-file limit
            $max_file = 2 * 1024 * 1024;
            if ($_FILES['file']['size'] > $max_file) {
                echo json_encode(['success' => false, 'message' => 'File too large. Maximum 2MB per file.']);
                break;
            }

            // 1GB company storage quota check
            $storage_limit = 1 * 1024 * 1024 * 1024; // 1GB
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(file_size), 0) FROM station_audit_documents WHERE company_id = ?");
            $stmt->execute([$company_id]);
            $used = (int)$stmt->fetchColumn();
            if (($used + $_FILES['file']['size']) > $storage_limit) {
                $usedMB  = round($used / 1024 / 1024, 1);
                $limitMB = round($storage_limit / 1024 / 1024, 0);
                echo json_encode(['success' => false, 'message' => "Storage quota exceeded. Used: {$usedMB}MB / {$limitMB}MB. Delete some files first."]);
                break;
            }

            // Validate file type
            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx'];
            if (!in_array($ext, $allowed)) {
                echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: ' . implode(', ', $allowed)]);
                break;
            }

            $upload_dir = '../uploads/audit_documents/' . $company_id . '/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            $stored_name = 'doc_' . $company_id . '_' . $session_id . '_' . time() . '_' . mt_rand(1000,9999) . '.' . $ext;
            $filepath = $upload_dir . $stored_name;

            if (move_uploaded_file($_FILES['file']['tmp_name'], $filepath)) {
                $original = clean_input($_FILES['file']['name']);
                $label = $doc_label ?: pathinfo($original, PATHINFO_FILENAME);
                $ftype = $_FILES['file']['type'] ?: 'application/octet-stream';

                $stmt = $pdo->prepare("INSERT INTO station_audit_documents (company_id, session_id, uploaded_by, original_name, stored_name, file_path, file_size, file_type, doc_label) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$company_id, $session_id ?: null, $user_id, $original, $stored_name, $filepath, $_FILES['file']['size'], $ftype, $label]);

                echo json_encode([
                    'success' => true,
                    'id' => $pdo->lastInsertId(),
                    'file_path' => $filepath,
                    'doc_label' => $label,
                    'file_size' => $_FILES['file']['size']
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to save file']);
            }
            break;

        // ───────── List Documents ─────────
        case 'list_documents':
            $session_filter = $_GET['session_id'] ?? $_POST['session_id'] ?? '';

            // Storage usage
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(file_size), 0) FROM station_audit_documents WHERE company_id = ?");
            $stmt->execute([$company_id]);
            $total_used = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM station_audit_documents WHERE company_id = ?");
            $stmt->execute([$company_id]);
            $total_count = (int)$stmt->fetchColumn();

            // Documents list
            if ($session_filter && $session_filter !== 'all') {
                $stmt = $pdo->prepare("SELECT d.*, s.date_from, s.date_to, co.name as outlet_name FROM station_audit_documents d LEFT JOIN station_audit_sessions s ON d.session_id = s.id LEFT JOIN client_outlets co ON s.outlet_id = co.id WHERE d.company_id = ? AND d.session_id = ? ORDER BY d.created_at DESC");
                $stmt->execute([$company_id, intval($session_filter)]);
            } else {
                $stmt = $pdo->prepare("SELECT d.*, s.date_from, s.date_to, co.name as outlet_name FROM station_audit_documents d LEFT JOIN station_audit_sessions s ON d.session_id = s.id LEFT JOIN client_outlets co ON s.outlet_id = co.id WHERE d.company_id = ? ORDER BY d.created_at DESC");
                $stmt->execute([$company_id]);
            }
            $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $storage_limit = 1 * 1024 * 1024 * 1024;
            echo json_encode([
                'success' => true,
                'documents' => $docs,
                'storage' => [
                    'used' => $total_used,
                    'limit' => $storage_limit,
                    'count' => $total_count,
                    'percent' => $storage_limit > 0 ? round($total_used / $storage_limit * 100, 2) : 0
                ]
            ]);
            break;

        // ───────── Get Storage Usage ─────────
        case 'get_storage_usage':
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(file_size), 0) FROM station_audit_documents WHERE company_id = ?");
            $stmt->execute([$company_id]);
            $total_used = (int)$stmt->fetchColumn();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM station_audit_documents WHERE company_id = ?");
            $stmt->execute([$company_id]);
            $total_count = (int)$stmt->fetchColumn();
            $storage_limit = 1 * 1024 * 1024 * 1024;
            echo json_encode([
                'success' => true,
                'used' => $total_used,
                'limit' => $storage_limit,
                'count' => $total_count,
                'percent' => $storage_limit > 0 ? round($total_used / $storage_limit * 100, 2) : 0
            ]);
            break;

        // ───────── Rename Document ─────────
        case 'rename_document':
            $doc_id = intval($_POST['doc_id'] ?? 0);
            $new_label = clean_input($_POST['doc_label'] ?? '');
            if (!$doc_id || !$new_label) {
                echo json_encode(['success' => false, 'message' => 'Missing document ID or label']);
                break;
            }
            $stmt = $pdo->prepare("UPDATE station_audit_documents SET doc_label = ? WHERE id = ? AND company_id = ?");
            $stmt->execute([$new_label, $doc_id, $company_id]);
            echo json_encode(['success' => true]);
            break;

        // ───────── Delete Document ─────────
        case 'delete_document':
            $doc_id = intval($_POST['doc_id'] ?? 0);
            if (!$doc_id) {
                echo json_encode(['success' => false, 'message' => 'Missing document ID']);
                break;
            }
            // Get file path first
            $stmt = $pdo->prepare("SELECT file_path, original_name FROM station_audit_documents WHERE id = ? AND company_id = ?");
            $stmt->execute([$doc_id, $company_id]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$doc) {
                echo json_encode(['success' => false, 'message' => 'Document not found']);
                break;
            }
            // Delete physical file
            if ($doc['file_path'] && file_exists($doc['file_path'])) {
                @unlink($doc['file_path']);
            }
            // Delete DB record
            $pdo->prepare("DELETE FROM station_audit_documents WHERE id = ? AND company_id = ?")->execute([$doc_id, $company_id]);
            log_audit($company_id, $user_id, 'document_deleted', 'station_audit_documents', $doc_id, $doc['original_name'] ?? '');
            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
    }
} catch (Exception $e) {
    error_log("Station Audit API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
