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

// ── Auto-create payments table ──
$pdo->exec("CREATE TABLE IF NOT EXISTS payments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    company_id      INT NOT NULL,
    user_id         INT NOT NULL,
    reference       VARCHAR(100) NOT NULL UNIQUE,
    plan_name       VARCHAR(50) NOT NULL,
    billing_cycle   VARCHAR(20) NOT NULL DEFAULT 'monthly',
    amount_kobo     INT NOT NULL DEFAULT 0,
    status          ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',
    paystack_response TEXT DEFAULT NULL,
    verified_at     DATETIME DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company (company_id),
    INDEX idx_reference (reference),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Add addon_client_packs column if missing ──
try {
    $pdo->exec("ALTER TABLE company_subscriptions ADD COLUMN addon_client_packs INT DEFAULT 0");
} catch (Exception $e) { /* column already exists */ }

$company_id = $_SESSION['company_id'] ?? 0;
$user_id    = $_SESSION['user_id'] ?? 0;
$action     = $_POST['action'] ?? '';

try {
    switch ($action) {

        // ── Initialize a Paystack transaction (existing) ──
        case 'initialize':
            $plan_key  = clean_input($_POST['plan'] ?? '');
            $cycle_key = clean_input($_POST['cycle'] ?? 'monthly');

            $valid_plans = ['professional', 'enterprise', 'hotel_revenue'];
            if (!in_array($plan_key, $valid_plans)) {
                echo json_encode(['success' => false, 'message' => 'Invalid plan selected']);
                break;
            }
            $valid_cycles = ['monthly', 'quarterly', 'annual', 'triennial'];
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
                log_audit($company_id, $user_id, 'payment_initialized', 'billing', null, "Payment initialized for $plan_key plan ($cycle_key) — ref: $reference");
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
            // 1. Auto-expire subscription if needed (handles both active and trial)
            $sub_row = $pdo->prepare("SELECT * FROM company_subscriptions WHERE company_id = ? ORDER BY id DESC LIMIT 1");
            $sub_row->execute([$company_id]);
            $sub = $sub_row->fetch(PDO::FETCH_ASSOC);

            $sub_status = $sub['status'] ?? '';
            if ($sub && in_array($sub_status, ['active', 'trial']) && !empty($sub['expires_at'])) {
                if (strtotime($sub['expires_at']) < time()) {
                    if ($sub_status === 'trial') {
                        // Trial expired → downgrade to starter
                        $starter_cfg = get_plan_config('starter');
                        $pdo->prepare("
                            UPDATE company_subscriptions SET 
                                status = 'expired', plan_name = 'starter',
                                max_users = ?, max_clients = ?, max_outlets = ?,
                                max_products = ?, max_departments = ?, data_retention_days = ?
                            WHERE id = ?
                        ")->execute([
                            (int)$starter_cfg['max_users'], (int)$starter_cfg['max_clients'],
                            (int)$starter_cfg['max_outlets'], (int)$starter_cfg['max_products'],
                            (int)$starter_cfg['max_departments'], (int)$starter_cfg['data_retention_days'],
                            $sub['id']
                        ]);
                        $sub['plan_name'] = 'starter';
                        log_audit($company_id, 0, 'trial_expired', 'billing', 0, 'Trial expired on billing page — downgraded to Starter');
                    } else {
                        $pdo->prepare("UPDATE company_subscriptions SET status = 'expired' WHERE id = ?")->execute([$sub['id']]);
                    }
                    $sub['status'] = 'expired';
                }
            }

            // Build subscription data
            $plan_key = $sub['plan_name'] ?? 'starter';
            $plan_cfg = get_plan_config($plan_key);
            $prices   = get_dynamic_prices();

            $addon_packs = (int)($sub['addon_client_packs'] ?? 0);
            $base_clients = (int)($plan_cfg['max_clients'] ?? 0);
            $base_depts   = (int)($plan_cfg['max_departments'] ?? 0);
            $subscription = [
                'plan_name'     => $plan_key,
                'plan_label'    => $plan_cfg['label'] ?? ucfirst($plan_key),
                'plan_color'    => $plan_cfg['color'] ?? 'slate',
                'plan_icon'     => $plan_cfg['icon'] ?? 'rocket',
                'status'        => $sub['status'] ?? 'trial',
                'billing_cycle' => $sub['billing_cycle'] ?? 'monthly',
                'started_at'    => $sub['started_at'] ?? null,
                'expires_at'    => $sub['expires_at'] ?? null,
                'days_remaining'=> !empty($sub['expires_at'])
                    ? max(0, (int)ceil((strtotime($sub['expires_at']) - time()) / 86400))
                    : null,
                'addon_client_packs'  => $addon_packs,
                'addon_monthly_cost'  => $addon_packs * 25000,
                'addon_extra_clients' => $addon_packs,
                'addon_extra_depts'   => $addon_packs * 6,
                'base_clients'        => $base_clients,
                'base_departments'    => $base_depts,
                'effective_clients'   => $base_clients + $addon_packs,
                'effective_departments' => ($base_depts === 0) ? 0 : $base_depts + ($addon_packs * 6),
            ];

            // 2. Auto-generate invoice if needed
            try {
                auto_generate_invoice($pdo, $company_id, $sub, $plan_cfg, $prices);
            } catch (Exception $e) { /* invoice generation failed — page still loads */ }

            // 3. Fetch invoices
            $invoices = [];
            try {
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
            } catch (Exception $e) { /* billing_invoices table might not exist */ }

            // 4. Payment history
            $pay_stmt = $pdo->prepare(
                "SELECT id, reference, plan_name, billing_cycle, amount_kobo, status, created_at, verified_at
                 FROM payments WHERE company_id = ? ORDER BY created_at DESC LIMIT 20"
            );
            $pay_stmt->execute([$company_id]);
            $payments = $pay_stmt->fetchAll(PDO::FETCH_ASSOC);

            // 5. Available plans for upgrade — use features from plan config
            $all_plans = get_all_plans();
            $plan_options = [];
            foreach ($all_plans as $key => $p) {
                if ($key === 'starter') continue;
                $plan_options[] = [
                    'key'       => $key,
                    'label'     => $p['label'],
                    'color'     => $p['color'],
                    'icon'      => $p['icon'],
                    'tag'       => $p['tag'],
                    'monthly'   => $prices[$key . '_monthly'] ?? 0,
                    'quarterly' => $prices[$key . '_quarterly'] ?? 0,
                    'annual'    => $prices[$key . '_annual'] ?? 0,
                    'triennial' => $prices[$key . '_triennial'] ?? 0,
                    'features'  => $p['features'] ?? [],
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

            // 7. Compute upcoming invoice preview + addon lock status
            $upcoming_invoice = null;
            $addon_lock = ['locked' => false, 'reason' => '', 'adjustment_days_left' => null];

            if ($plan_key !== 'starter' && !empty($sub['expires_at'])) {
                $cycle_key_u = $sub['billing_cycle'] ?? 'monthly';
                $price_key_u = $plan_key . '_' . $cycle_key_u;
                $plan_cost   = $prices[$price_key_u] ?? 0;
                $cycle_cfg_u = get_cycle_config();
                $months_u    = $cycle_cfg_u[$cycle_key_u]['months'] ?? 1;
                $addon_cost_u = $addon_packs * 25000 * $months_u;
                $total_u      = $plan_cost + $addon_cost_u;

                // Check if there's already an unpaid invoice
                $unpaid = null;
                foreach ($invoices as $inv_check) {
                    if (in_array($inv_check['status'], ['draft', 'sent', 'overdue'])) {
                        $unpaid = $inv_check;
                        break;
                    }
                }

                $upcoming_invoice = [
                    'plan_cost'       => $plan_cost,
                    'addon_packs'     => $addon_packs,
                    'addon_cost'      => $addon_cost_u,
                    'total'           => $total_u,
                    'billing_cycle'   => $cycle_key_u,
                    'due_date'        => $sub['expires_at'],
                    'has_invoice'     => $unpaid !== null,
                    'invoice_id'      => $unpaid['id'] ?? null,
                    'invoice_number'  => $unpaid['invoice_number'] ?? null,
                    'invoice_status'  => $unpaid['status'] ?? null,
                    'invoice_amount'  => $unpaid ? (float)$unpaid['amount_naira'] : $total_u,
                ];

                // Addon lock-in logic:
                // Users can ONLY remove packs if: invoice is paid AND within first 7 days of cycle
                $started_at = $sub['started_at'] ?? null;
                $days_into_cycle = $started_at ? (int)ceil((time() - strtotime($started_at)) / 86400) : 999;
                $adjustment_days_left = max(0, 7 - $days_into_cycle);
                $has_unpaid = ($unpaid !== null);
                $has_addons = ($addon_packs > 0);

                if ($has_addons) {
                    if ($has_unpaid) {
                        $addon_lock = [
                            'locked' => true,
                            'reason' => 'You cannot remove add-on packs until the current invoice is paid.',
                            'adjustment_days_left' => 0,
                        ];
                    } elseif ($days_into_cycle > 7) {
                        $addon_lock = [
                            'locked' => true,
                            'reason' => 'Add-on packs are locked for this billing cycle. Changes available in the next cycle\'s adjustment window.',
                            'adjustment_days_left' => 0,
                        ];
                    } else {
                        $addon_lock = [
                            'locked' => false,
                            'reason' => '',
                            'adjustment_days_left' => $adjustment_days_left,
                        ];
                    }
                } else {
                    $addon_lock['adjustment_days_left'] = $adjustment_days_left;
                }
            }

            echo json_encode([
                'success'          => true,
                'subscription'     => $subscription,
                'invoices'         => $invoices,
                'payments'         => $payments,
                'plan_options'     => $plan_options,
                'usage'            => $usage,
                'upcoming_invoice' => $upcoming_invoice,
                'addon_lock'       => $addon_lock,
            ]);

            break;

        // ═══════════════════════════════════════════════════
        // ── Update Add-On Packs (self-service by company) ──
        // ═══════════════════════════════════════════════════
        case 'update_addon_packs':
            // Only business_owner can adjust add-ons
            $user_role = $_SESSION['user_role'] ?? '';
            if ($user_role !== 'business_owner') {
                echo json_encode(['success' => false, 'message' => 'Only the account owner can manage add-on packs']);
                break;
            }

            // Starter plan cannot add packs — must upgrade first
            $plan_check = $pdo->prepare("SELECT plan_name FROM company_subscriptions WHERE company_id = ? ORDER BY id DESC LIMIT 1");
            $plan_check->execute([$company_id]);
            $current_plan = $plan_check->fetchColumn() ?: 'starter';
            if ($current_plan === 'starter' || $current_plan === 'free') {
                echo json_encode(['success' => false, 'message' => 'Add-on packs are not available on the Starter plan. Please upgrade to Professional or Enterprise to add extra clients.']);
                break;
            }

            $new_packs = max(0, min(20, (int)($_POST['addon_client_packs'] ?? 0)));

            // Get current subscription
            $sub_stmt = $pdo->prepare("SELECT * FROM company_subscriptions WHERE company_id = ? ORDER BY id DESC LIMIT 1");
            $sub_stmt->execute([$company_id]);
            $current_sub = $sub_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$current_sub) {
                echo json_encode(['success' => false, 'message' => 'No active subscription found']);
                break;
            }

            $old_packs = (int)($current_sub['addon_client_packs'] ?? 0);
            $is_removal = $new_packs < $old_packs;

            // ── Lock-in validation for REMOVAL only ──
            if ($is_removal) {
                // Check 1: Is there an unpaid invoice?
                $unpaid_inv = $pdo->prepare(
                    "SELECT id FROM billing_invoices WHERE company_id = ? AND status IN ('draft','sent','overdue') LIMIT 1"
                );
                $unpaid_inv->execute([$company_id]);
                if ($unpaid_inv->fetch()) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'You cannot remove add-on packs until the current invoice is paid.',
                        'locked'  => true,
                    ]);
                    break;
                }

                // Check 2: Are we within first 7 days of the billing cycle?
                $started_at = $current_sub['started_at'] ?? null;
                $days_into_cycle = $started_at ? (int)ceil((time() - strtotime($started_at)) / 86400) : 999;
                if ($days_into_cycle > 7) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Add-on packs are locked for this billing cycle. You can adjust during the first 7 days of your next cycle.',
                        'locked'  => true,
                    ]);
                    break;
                }
            }

            $plan_key_a = $current_sub['plan_name'] ?? 'starter';
            $plan_cfg_a = get_plan_config($plan_key_a);

            // Calculate effective limits
            $base_clients_a = (int)$plan_cfg_a['max_clients'];
            $base_depts_a   = (int)$plan_cfg_a['max_departments'];
            $eff_clients    = $base_clients_a + $new_packs;
            $eff_depts      = ($base_depts_a === 0) ? 0 : $base_depts_a + ($new_packs * 6);

            // Update subscription
            $pdo->prepare("
                UPDATE company_subscriptions
                SET addon_client_packs = ?, max_clients = ?, max_departments = ?
                WHERE company_id = ? ORDER BY id DESC LIMIT 1
            ")->execute([$new_packs, $eff_clients, $eff_depts, $company_id]);

            log_audit($company_id, $user_id, 'addon_packs_updated', 'billing', null,
                "Add-on packs changed from $old_packs to $new_packs (clients: $eff_clients, depts: $eff_depts)");

            // If packs increased, auto-update the current unpaid invoice amount
            if ($new_packs > $old_packs) {
                try {
                    $cycle_key_a = $current_sub['billing_cycle'] ?? 'monthly';
                    $prices_a    = get_dynamic_prices();
                    $plan_cost_a = $prices_a[$plan_key_a . '_' . $cycle_key_a] ?? 0;
                    $cycle_cfg_a = get_cycle_config();
                    $months_a    = $cycle_cfg_a[$cycle_key_a]['months'] ?? 1;
                    $addon_cost_a = $new_packs * 25000 * $months_a;
                    $new_total    = $plan_cost_a + $addon_cost_a;

                    $notes_a = ucfirst($plan_key_a) . ' ' . ucfirst($cycle_key_a) . ' subscription';
                    if ($new_packs > 0) {
                        $notes_a .= ' + ' . $new_packs . ' add-on pack(s) (₦' . number_format($addon_cost_a) . ')';
                    }

                    $pdo->prepare("
                        UPDATE billing_invoices SET amount_naira = ?, notes = ?
                        WHERE company_id = ? AND status IN ('draft','sent') ORDER BY id DESC LIMIT 1
                    ")->execute([$new_total, $notes_a, $company_id]);
                } catch (Exception $e) { /* invoice table might not exist */ }
            }

            echo json_encode([
                'success' => true,
                'message' => $new_packs > $old_packs
                    ? 'Added ' . ($new_packs - $old_packs) . ' add-on pack(s). Your invoice has been updated.'
                    : ($new_packs < $old_packs
                        ? 'Removed ' . ($old_packs - $new_packs) . ' add-on pack(s) successfully.'
                        : 'No change to add-on packs.'),
                'addon_client_packs'    => $new_packs,
                'effective_clients'     => $eff_clients,
                'effective_departments' => $eff_depts,
                'addon_monthly_cost'    => $new_packs * 25000,
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
                log_audit($company_id, $user_id, 'invoice_payment_initialized', 'billing', $invoice_id, "Invoice payment initialized — ref: $reference");
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
// Generates invoice immediately when subscription is active.
// Invoice reflects plan cost + current add-on packs.
// ═══════════════════════════════════════════════════
function auto_generate_invoice($pdo, $company_id, $sub, $plan_cfg, $prices) {
    // Skip free/starter plans — they don't get invoices
    if (!$sub || in_array($sub['plan_name'] ?? 'starter', ['starter', 'free'])) return;

    // Only generate invoices for active (paid) subscriptions, NOT trials
    $status = $sub['status'] ?? '';
    if ($status !== 'active') return;

    $plan_key  = $sub['plan_name'];
    $cycle_key = $sub['billing_cycle'] ?? 'monthly';
    $started   = $sub['started_at'] ?? null;
    $expires   = $sub['expires_at'] ?? null;

    // Auto-fill missing dates so invoices can still be generated
    $cycle_cfg = get_cycle_config();
    $months = $cycle_cfg[$cycle_key]['months'] ?? 1;

    if (!$started) {
        $started = date('Y-m-d');
        $pdo->prepare("UPDATE company_subscriptions SET started_at = ? WHERE company_id = ? AND id = ?")->execute([$started, $company_id, $sub['id']]);
    }
    if (!$expires) {
        $expires = date('Y-m-d', strtotime("+{$months} months"));
        $pdo->prepare("UPDATE company_subscriptions SET expires_at = ? WHERE company_id = ? AND id = ?")->execute([$expires, $company_id, $sub['id']]);
    }

    // Check if we already have ANY unpaid invoice for this company
    $existing = $pdo->prepare(
        "SELECT id FROM billing_invoices
         WHERE company_id = ? AND status IN ('draft','sent','overdue')
         ORDER BY id DESC LIMIT 1"
    );
    $existing->execute([$company_id]);
    if ($existing->fetch()) return; // Already have one — don't duplicate

    // Generate invoice number
    $year = date('Y');
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM billing_invoices WHERE company_id = ? AND YEAR(created_at) = ?");
    $count_stmt->execute([$company_id, $year]);
    $seq = (int)$count_stmt->fetchColumn() + 1;
    $invoice_number = 'INV-' . $year . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

    // Calculate amount — try dynamic prices first, fallback to hardcoded
    $price_key = $plan_key . '_' . $cycle_key;
    $amount = $prices[$price_key] ?? 0;

    // Fallback prices if platform_settings not configured
    if ($amount <= 0) {
        $fallback = [
            'professional_monthly' => 25000, 'professional_quarterly' => 67500, 'professional_annual' => 240000,
            'enterprise_monthly' => 75000, 'enterprise_quarterly' => 202500, 'enterprise_annual' => 720000,
        ];
        $amount = $fallback[$price_key] ?? 0;
    }
    if ($amount <= 0) return;

    // Add-on costs: ₦25,000/mo per pack, scaled by billing cycle
    $addon_packs = (int)($sub['addon_client_packs'] ?? 0);
    $addon_cost = $addon_packs * 25000 * $months;
    $total_amount = $amount + $addon_cost;

    // Period: current cycle
    $period_start = $started;
    $period_end   = $expires;
    $due_date     = $expires;

    // Build invoice notes
    $notes = ucfirst($plan_key) . ' ' . ucfirst($cycle_key) . ' subscription';
    if ($addon_packs > 0) {
        $notes .= ' + ' . $addon_packs . ' add-on pack(s) (₦' . number_format($addon_cost) . ')';
    }

    $stmt = $pdo->prepare(
        "INSERT INTO billing_invoices (company_id, invoice_number, plan_name, billing_cycle, amount_naira, status, due_date, period_start, period_end, notes)
         VALUES (?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?)"
    );
    $stmt->execute([
        $company_id, $invoice_number, $plan_key, $cycle_key, $total_amount,
        $due_date, $period_start, $period_end, $notes
    ]);
    log_audit($company_id, 0, 'auto_invoice_generated', 'billing', $pdo->lastInsertId(), "Auto-generated invoice $invoice_number for $plan_key ($cycle_key) — ₦" . number_format($total_amount));
}
?>
