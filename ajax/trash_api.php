<?php
/**
 * MIAUDITOPS — Trash API (AJAX Handler)
 * Handles: list_trash, restore_item, permanent_delete
 */
require_once '../includes/functions.php';
require_once '../includes/trash_helper.php';
header('Content-Type: application/json');

if (!is_logged_in()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

$company_id = $_SESSION['company_id'];
$user_id    = $_SESSION['user_id'];
$action     = $_POST['action'] ?? $_GET['action'] ?? '';

ensure_trash_table($pdo);
purge_expired_trash($pdo);

try {
    switch ($action) {

        case 'list_trash':
            $type = $_POST['item_type'] ?? null;
            $items = list_trash($pdo, $company_id, $type ?: null);
            echo json_encode(['success' => true, 'items' => $items]);
            break;

        case 'restore_item':
            $trash_id = intval($_POST['trash_id'] ?? 0);
            $item_type = $_POST['item_type'] ?? '';

            // For audit_session, delegate to station_audit_api
            if ($item_type === 'audit_session') {
                // We need to do the restore here since this is the global trash API
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

                // Re-insert pump tables + readings
                foreach ($d['pump_tables'] ?? [] as $r) {
                    $pdo->prepare("INSERT INTO station_pump_tables (id, session_id, company_id, product, sort_order, opening_date, closing_date, is_closed, created_at) VALUES (?,?,?,?,?,?,?,?,?)")
                        ->execute([$r['id'], $r['session_id'], $r['company_id'], $r['product'], $r['sort_order'], $r['opening_date'], $r['closing_date'], $r['is_closed'], $r['created_at']]);
                }
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
                echo json_encode(['success' => true, 'message' => 'Audit session restored']);
            } else {
                // Generic restore — just remove from trash and return the data
                $row = restore_from_trash($pdo, $trash_id, $company_id);
                if (!$row) { echo json_encode(['success' => false, 'message' => 'Trash item not found']); break; }
                log_audit($company_id, $user_id, 'trash_restored', $item_type, $row['item_id']);
                echo json_encode(['success' => true, 'message' => ucfirst(str_replace('_', ' ', $item_type)) . ' restored']);
            }
            break;

        case 'permanent_delete':
            $trash_id = intval($_POST['trash_id'] ?? 0);
            $ok = permanent_delete_trash($pdo, $trash_id, $company_id);
            log_audit($company_id, $user_id, 'trash_permanent_delete', 'system_trash', $trash_id);
            echo json_encode(['success' => $ok]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
