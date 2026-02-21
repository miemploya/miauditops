<?php
/**
 * MIAUDITOPS — Payment & Billing API
 * Handles: initialize, get_billing_data, pay_invoice
 */
header('Content-Type: application/json');
require_once '../includes/functions.php';
require_once '../config/paystack.php';
require_once '../config/subscription_plans.php';
require_login();

global $pdo;

// ── Auto-create billing_invoices table ──
$pdo->exec("CREATE TABLE IF NOT EXISTS billing_invoices (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    company_id      INT NOT NULL,
    invoice_number  VARCHAR(50) NOT NULL,
    plan_name       VARCHAR(50) NOT NULL DEFAULT 'professional',
    billing_cycle   VARCHAR(20) NOT NULL DEFAULT 'monthly',
    amount_naira    DECIMAL(15,2) NOT NULL DEFAULT 0,
    status          ENUM('draft','sent','paid','overdue','cancelled') NOT NULL DEFAULT 'draft',
    due_date        DATE NOT NULL,
    period_start    DATE DEFAULT NULL,
    period_end      DATE DEFAULT NULL,
    paid_at         DATETIME DEFAULT NULL,
    payment_reference VARCHAR(100) DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company (company_id),
    INDEX idx_status (status),
    INDEX idx_due (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$company_id = $_SESSION['company_id'] ?? 0;
$user_id    = $_SESSION['user_id'] ?? 0;
$action     = $_POST['action'] ?? '';

try {
    switch ($action) {

        // ── Initialize a Paystack transaction (existing) ──
        case 'initialize':
            $plan_key  = clean_input($_POST['plan'] ?? '');
            $cycle_key = clean_input($_POST['cycle'] ?? 'monthly');

            $valid_plans = ['professional', 'enterprise'];
            if (!in_array($plan_key, $valid_plans)) {
                echo json_encode(['success' => false, 'message' => 'Invalid plan selected']);
                break;
            }
            $valid_cycles = ['monthly', 'quarterly', 'annual'];
            if (!in_array($cycle_key, $valid_cycles)) {
                $cycle_key = 'monthly';
            }

            $amount_kobo = calculate_amount_kobo($plan_key, $cycle_key);
            if ($amount_kobo <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid amount']);
                break;
            }

            $reference = 'MIAO_' . strtoupper($plan_key[0]) . '_' . $company_id . '_' . time() . '_' . rand(1000, 9999);

            $user_email = $_SESSION['email'] ?? '';
            if (!$user_email) {
                $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user_email = $stmt->fetchColumn() ?: 'unknown@example.com';
            }

            $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                . '://' . $_SERVER['HTTP_HOST'];
            $script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
            $callback_url = $base_url . dirname($script_dir) . '/payment_callback.php';

            $stmt = $pdo->prepare("INSERT INTO payments (company_id, user_id, reference, plan_name, billing_cycle, amount_kobo, status) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$company_id, $user_id, $reference, $plan_key, $cycle_key, $amount_kobo, 'pending']);

            $result = paystack_request('POST', '/transaction/initialize', [
                'email'        => $user_email,
                'amount'       => $amount_kobo,
                'reference'    => $reference,
                'currency'     => 'NGN',
                'callback_url' => $callback_url,
                'channels'     => ['card', 'bank', 'bank_transfer', 'ussd'],
                'metadata'     => [
                    'company_id'    => $company_id,
                    'user_id'       => $user_id,
                    'plan_name'     => $plan_key,
                    'billing_cycle' => $cycle_key,
                    'custom_fields' => [
                        ['display_name' => 'Plan', 'variable_name' => 'plan', 'value' => ucfirst($plan_key)],
                        ['display_name' => 'Cycle', 'variable_name' => 'cycle', 'value' => ucfirst($cycle_key)],
                    ],
                ],
            ]);

            if (!empty($result['status']) && $result['status'] === true) {
                echo json_encode([
                    'success'           => true,
                    'authorization_url' => $result['data']['authorization_url'],
                    'reference'         => $reference,
                    'access_code'       => $result['data']['access_code'] ?? '',
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => $result['message'] ?? 'Failed to initialize payment',
                ]);
            }
            break;

        // ═══════════════════════════════════════════════════
        // ── Get Billing Dashboard Data ──
        // ═══════════════════════════════════════════════════
        case 'get_billing_data':
            // 1. Auto-expire subscription if needed
            $sub_row = $pdo->prepare("SELECT * FROM company_subscriptions WHERE company_id = ? ORDER BY id DESC LIMIT 1");
            $sub_row->execute([$company_id]);
            $sub = $sub_row->fetch(PDO::FETCH_ASSOC);

            if ($sub && $sub['status'] === 'active' && !empty($sub['expires_at'])) {
                if (strtotime($sub['expires_at']) < time()) {
                    $pdo->prepare("UPDATE company_subscriptions SET status = 'expired' WHERE id = ?")->execute([$sub['id']]);
                    $sub['status'] = 'expired';
                }
            }

            // Build subscription data
            $plan_key = $sub['plan_name'] ?? 'starter';
            $plan_cfg = get_plan_config($plan_key);
            $prices   = get_dynamic_prices();

            $subscription = [
                'plan_name'     => $plan_key,
                'plan_label'    => $plan_cfg['label'] ?? ucfirst($plan_key),
                'plan_color'    => $plan_cfg['color'] ?? 'slate',
                'plan_icon'     => $plan_cfg['icon'] ?? 'rocket',
                'status'        => $sub['status'] ?? 'active',
                'billing_cycle' => $sub['billing_cycle'] ?? 'monthly',
                'started_at'    => $sub['started_at'] ?? null,
                'expires_at'    => $sub['expires_at'] ?? null,
                'days_remaining'=> !empty($sub['expires_at'])
                    ? max(0, (int)ceil((strtotime($sub['expires_at']) - time()) / 86400))
                    : null,
            ];

            // 2. Auto-generate invoice if needed
            auto_generate_invoice($pdo, $company_id, $sub, $plan_cfg, $prices);

            // 3. Fetch invoices
            $inv_stmt = $pdo->prepare(
                "SELECT * FROM billing_invoices WHERE company_id = ? ORDER BY due_date DESC LIMIT 50"
            );
            $inv_stmt->execute([$company_id]);
            $invoices = $inv_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Mark overdue invoices
            foreach ($invoices as &$inv) {
                if ($inv['status'] === 'draft' || $inv['status'] === 'sent') {
                    if (strtotime($inv['due_date']) < time()) {
                        $pdo->prepare("UPDATE billing_invoices SET status = 'overdue' WHERE id = ?")->execute([$inv['id']]);
                        $inv['status'] = 'overdue';
                    }
                }
            }
            unset($inv);

            // 4. Payment history
            $pay_stmt = $pdo->prepare(
                "SELECT id, reference, plan_name, billing_cycle, amount_kobo, status, created_at, verified_at
                 FROM payments WHERE company_id = ? ORDER BY created_at DESC LIMIT 20"
            );
            $pay_stmt->execute([$company_id]);
            $payments = $pay_stmt->fetchAll(PDO::FETCH_ASSOC);

            // 5. Available plans for upgrade — include features for comparison
            $all_plans = get_all_plans();
            $plan_options = [];
            // Build human-readable feature list per plan
            $module_labels = [
                'dashboard'=>'Dashboard','company_setup'=>'Company Setup','audit'=>'Sales Audit',
                'stock'=>'Stock Management','main_store'=>'Main Store','department_store'=>'Department Store',
                'finance'=>'Financial Reports','requisitions'=>'Requisitions & Procurement',
                'reports'=>'Reports & Analytics','station_audit'=>'Station Audit','support'=>'Priority Support'
            ];
            foreach ($all_plans as $key => $p) {
                if ($key === 'starter') continue;
                // Build features list
                $features = [];
                $features[] = ($p['max_users'] == 0 ? 'Unlimited' : $p['max_users']) . ' Users';
                $features[] = ($p['max_clients'] == 0 ? 'Unlimited' : $p['max_clients']) . ' Clients';
                $features[] = ($p['max_outlets'] == 0 ? 'Unlimited' : $p['max_outlets']) . ' Outlets';
                $features[] = ($p['max_products'] == 0 ? 'Unlimited' : $p['max_products']) . ' Products';
                $features[] = ($p['max_departments'] == 0 ? 'Unlimited' : $p['max_departments']) . ' Departments';
                $features[] = ($p['data_retention_days'] == 0 ? 'Unlimited' : $p['data_retention_days'] . ' days') . ' Data Retention';
                // List specific modules included (skip utility ones like settings, trash, billing)
                $skip_modules = ['dashboard', 'company_setup', 'settings', 'trash', 'billing'];
                foreach ($p['modules'] as $mod) {
                    if (in_array($mod, $skip_modules)) continue;
                    if (isset($module_labels[$mod])) {
                        $label = $module_labels[$mod];
                        // Check if tab-locked
                        if (!empty($p['tab_locks'][$mod])) {
                            $label .= ' (Limited)';
                        }
                        $features[] = $label;
                    }
                }
                // Feature flags
                if (!empty($p['pdf_export'])) $features[] = 'PDF Export';
                if (!empty($p['viewer_role'])) $features[] = 'Viewer Role';
                if (!empty($p['audit_export'])) $features[] = 'Audit Export';
                if (!empty($p['station_audit'])) $features[] = 'Station Audit';
                if (!empty($p['support_services'])) $features[] = 'Priority Support';

                $plan_options[] = [
                    'key'       => $key,
                    'label'     => $p['label'],
                    'color'     => $p['color'],
                    'icon'      => $p['icon'],
                    'tag'       => $p['tag'],
                    'monthly'   => $prices[$key . '_monthly'] ?? 0,
                    'quarterly' => $prices[$key . '_quarterly'] ?? 0,
                    'annual'    => $prices[$key . '_annual'] ?? 0,
                    'features'  => $features,
                    'limits'    => [
                        'max_users'       => $p['max_users'],
                        'max_clients'     => $p['max_clients'],
                        'max_outlets'     => $p['max_outlets'],
                        'max_products'    => $p['max_products'],
                        'max_departments' => $p['max_departments'],
                    ],
                ];
            }

            // 6. Current usage stats
            $usage = [];
            $c = $pdo->prepare("SELECT COUNT(*) FROM users WHERE company_id = ?");
            $c->execute([$company_id]);
            $usage['users'] = (int)$c->fetchColumn();

            // Outlets (stations)
            try {
                $c = $pdo->prepare("SELECT COUNT(*) FROM audit_outlets WHERE company_id = ?");
                $c->execute([$company_id]);
                $usage['outlets'] = (int)$c->fetchColumn();
            } catch(Exception $e) { $usage['outlets'] = 0; }

            // Departments
            try {
                $c = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE company_id = ?");
                $c->execute([$company_id]);
                $usage['departments'] = (int)$c->fetchColumn();
            } catch(Exception $e) { $usage['departments'] = 0; }

            // Current plan limits
            $usage['plan_limits'] = [
                'max_users'       => $plan_cfg['max_users'] ?? 0,
                'max_outlets'     => $plan_cfg['max_outlets'] ?? 0,
                'max_departments' => $plan_cfg['max_departments'] ?? 0,
            ];

            echo json_encode([
                'success'      => true,
                'subscription' => $subscription,
                'invoices'     => $invoices,
                'payments'     => $payments,
                'plan_options' => $plan_options,
                'usage'        => $usage,
            ]);

            break;

        // ═══════════════════════════════════════════════════
        // ── Pay Invoice (initialize Paystack for invoice) ──
        // ═══════════════════════════════════════════════════
        case 'pay_invoice':
            $invoice_id = (int)($_POST['invoice_id'] ?? 0);
            if ($invoice_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid invoice']);
                break;
            }

            // Fetch invoice
            $inv = $pdo->prepare("SELECT * FROM billing_invoices WHERE id = ? AND company_id = ?");
            $inv->execute([$invoice_id, $company_id]);
            $invoice = $inv->fetch(PDO::FETCH_ASSOC);

            if (!$invoice) {
                echo json_encode(['success' => false, 'message' => 'Invoice not found']);
                break;
            }
            if ($invoice['status'] === 'paid') {
                echo json_encode(['success' => false, 'message' => 'Invoice already paid']);
                break;
            }

            $plan_key  = $invoice['plan_name'];
            $cycle_key = $invoice['billing_cycle'];
            $amount_kobo = (int)round($invoice['amount_naira'] * 100);

            if ($amount_kobo <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid invoice amount']);
                break;
            }

            $reference = 'MIAO_INV_' . $invoice_id . '_' . $company_id . '_' . time() . '_' . rand(1000, 9999);

            // Get user email
            $user_email = $_SESSION['email'] ?? '';
            if (!$user_email) {
                $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user_email = $stmt->fetchColumn() ?: 'unknown@example.com';
            }

            $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                . '://' . $_SERVER['HTTP_HOST'];
            $script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
            $callback_url = $base_url . dirname($script_dir) . '/payment_callback.php';

            // Store payment record
            $stmt = $pdo->prepare("INSERT INTO payments (company_id, user_id, reference, plan_name, billing_cycle, amount_kobo, status) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$company_id, $user_id, $reference, $plan_key, $cycle_key, $amount_kobo, 'pending']);

            // Link invoice to reference
            $pdo->prepare("UPDATE billing_invoices SET payment_reference = ?, status = 'sent' WHERE id = ?")->execute([$reference, $invoice_id]);

            $result = paystack_request('POST', '/transaction/initialize', [
                'email'        => $user_email,
                'amount'       => $amount_kobo,
                'reference'    => $reference,
                'currency'     => 'NGN',
                'callback_url' => $callback_url,
                'channels'     => ['card', 'bank', 'bank_transfer', 'ussd'],
                'metadata'     => [
                    'company_id'    => $company_id,
                    'user_id'       => $user_id,
                    'plan_name'     => $plan_key,
                    'billing_cycle' => $cycle_key,
                    'invoice_id'    => $invoice_id,
                    'custom_fields' => [
                        ['display_name' => 'Invoice', 'variable_name' => 'invoice', 'value' => $invoice['invoice_number']],
                        ['display_name' => 'Plan', 'variable_name' => 'plan', 'value' => ucfirst($plan_key)],
                    ],
                ],
            ]);

            if (!empty($result['status']) && $result['status'] === true) {
                echo json_encode([
                    'success'           => true,
                    'authorization_url' => $result['data']['authorization_url'],
                    'reference'         => $reference,
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => $result['message'] ?? 'Failed to initialize payment',
                ]);
            }
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

// ═══════════════════════════════════════════════════
// ── Auto-Generate Invoice Helper ──
// ═══════════════════════════════════════════════════
function auto_generate_invoice($pdo, $company_id, $sub, $plan_cfg, $prices) {
    if (!$sub || ($sub['plan_name'] ?? 'starter') === 'starter') return;

    $plan_key  = $sub['plan_name'];
    $cycle_key = $sub['billing_cycle'] ?? 'monthly';
    $expires   = $sub['expires_at'] ?? null;

    if (!$expires) return;

    // Only generate if within 7 days of expiry or already expired
    $days_to_expiry = (strtotime($expires) - time()) / 86400;
    if ($days_to_expiry > 7) return;

    // Check if we already have an unpaid invoice for this period
    $existing = $pdo->prepare(
        "SELECT id FROM billing_invoices
         WHERE company_id = ? AND status IN ('draft','sent','overdue')
         AND plan_name = ? AND billing_cycle = ?
         ORDER BY id DESC LIMIT 1"
    );
    $existing->execute([$company_id, $plan_key, $cycle_key]);
    if ($existing->fetch()) return; // Already have one

    // Generate invoice number
    $year = date('Y');
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM billing_invoices WHERE company_id = ? AND YEAR(created_at) = ?");
    $count_stmt->execute([$company_id, $year]);
    $seq = (int)$count_stmt->fetchColumn() + 1;
    $invoice_number = 'INV-' . $year . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

    // Calculate amount
    $price_key = $plan_key . '_' . $cycle_key;
    $amount = $prices[$price_key] ?? 0;
    if ($amount <= 0) return;

    // Period
    $period_start = $expires;
    $cycle_cfg = get_cycle_config();
    $months = $cycle_cfg[$cycle_key]['months'] ?? 1;
    $period_end = date('Y-m-d', strtotime($period_start . " +{$months} months"));
    $due_date = $expires; // Due on expiry date

    $stmt = $pdo->prepare(
        "INSERT INTO billing_invoices (company_id, invoice_number, plan_name, billing_cycle, amount_naira, status, due_date, period_start, period_end, notes)
         VALUES (?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?)"
    );
    $stmt->execute([
        $company_id, $invoice_number, $plan_key, $cycle_key, $amount,
        $due_date, $period_start, $period_end,
        ucfirst($plan_key) . ' ' . ucfirst($cycle_key) . ' subscription renewal'
    ]);
}
?>
