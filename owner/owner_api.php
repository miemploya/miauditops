<?php
/**
 * MIAUDITOPS — Owner Portal API
 * All AJAX endpoints for the owner management console
 */
session_start();
header('Content-Type: application/json');

// Gate: only platform owners
if (empty($_SESSION['is_platform_owner'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../config/db.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // ── Dashboard Stats ──
    case 'stats':
        $companies = $pdo->query("SELECT COUNT(*) FROM companies WHERE deleted_at IS NULL")->fetchColumn();
        $users = $pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL")->fetchColumn();
        $active_subs = $pdo->query("SELECT COUNT(*) FROM company_subscriptions WHERE status = 'active'")->fetchColumn();
        $trial_subs = $pdo->query("SELECT COUNT(*) FROM company_subscriptions WHERE status = 'trial'")->fetchColumn();
        $expired_subs = $pdo->query("SELECT COUNT(*) FROM company_subscriptions WHERE status IN ('expired','suspended')")->fetchColumn();

        // Recent companies
        $recent = $pdo->query("SELECT c.id, c.name, c.code, c.email, c.is_active, c.created_at,
            (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id AND u.deleted_at IS NULL) as user_count,
            cs.plan_name, cs.status as sub_status
            FROM companies c
            LEFT JOIN company_subscriptions cs ON cs.company_id = c.id
            WHERE c.deleted_at IS NULL
            ORDER BY c.created_at DESC LIMIT 10")->fetchAll();

        echo json_encode(['success' => true, 'data' => [
            'companies' => (int)$companies,
            'users' => (int)$users,
            'active_subs' => (int)$active_subs,
            'trial_subs' => (int)$trial_subs,
            'expired_subs' => (int)$expired_subs,
            'recent' => $recent
        ]]);
        break;

    // ── List Companies ──
    case 'list_companies':
        $rows = $pdo->query("SELECT c.*,
            (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id AND u.deleted_at IS NULL) as user_count,
            (SELECT COUNT(*) FROM client_outlets co WHERE co.company_id = c.id AND co.deleted_at IS NULL) as outlet_count,
            cs.plan_name, cs.status as sub_status, cs.expires_at, cs.billing_cycle,
            cs.max_users, cs.max_outlets, cs.max_products, cs.max_departments, cs.max_clients,
            cs.data_retention_days, cs.pdf_export, cs.viewer_role, cs.station_audit
            FROM companies c
            LEFT JOIN company_subscriptions cs ON cs.company_id = c.id
            WHERE c.deleted_at IS NULL
            ORDER BY c.name")->fetchAll();
        echo json_encode(['success' => true, 'data' => $rows]);
        break;

    // ── List Users ──
    case 'list_users':
        $rows = $pdo->query("SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.is_active, u.last_login, u.created_at,
            c.name as company_name, c.code as company_code
            FROM users u
            JOIN companies c ON c.id = u.company_id AND c.deleted_at IS NULL
            WHERE u.deleted_at IS NULL
            ORDER BY u.created_at DESC")->fetchAll();
        echo json_encode(['success' => true, 'data' => $rows]);
        break;

    // ── Toggle Company Active ──
    case 'toggle_company':
        $id = (int)($_POST['id'] ?? 0);
        $active = (int)($_POST['is_active'] ?? 0);
        $pdo->prepare("UPDATE companies SET is_active = ? WHERE id = ?")->execute([$active, $id]);
        echo json_encode(['success' => true]);
        break;

    // ── Toggle User Active ──
    case 'toggle_user':
        $id = (int)($_POST['id'] ?? 0);
        $active = (int)($_POST['is_active'] ?? 0);
        $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?")->execute([$active, $id]);
        echo json_encode(['success' => true]);
        break;

    // ── Update Subscription ──
    case 'update_subscription':
        require_once '../config/subscription_plans.php';
        $company_id  = (int)($_POST['company_id'] ?? 0);
        $plan        = $_POST['plan_name'] ?? 'starter';
        $status      = $_POST['status'] ?? 'trial';
        $expires     = $_POST['expires_at'] ?? null;
        $billing     = $_POST['billing_cycle'] ?? 'monthly';
        $notes       = $_POST['notes'] ?? '';

        // Load plan defaults from config
        $plan_cfg = get_plan_config($plan);
        $max_users       = (int)($_POST['max_users']       ?? $plan_cfg['max_users']);
        $max_outlets     = (int)($_POST['max_outlets']     ?? $plan_cfg['max_outlets']);
        $max_products    = (int)($_POST['max_products']    ?? $plan_cfg['max_products']);
        $max_departments = (int)($_POST['max_departments'] ?? $plan_cfg['max_departments']);
        $max_clients     = (int)($_POST['max_clients']     ?? $plan_cfg['max_clients']);
        $data_retention  = (int)($_POST['data_retention_days'] ?? $plan_cfg['data_retention_days']);
        $pdf_export      = (int)($_POST['pdf_export']      ?? ($plan_cfg['pdf_export'] ? 1 : 0));
        $viewer_role     = (int)($_POST['viewer_role']     ?? ($plan_cfg['viewer_role'] ? 1 : 0));
        $station_audit   = (int)($_POST['station_audit']   ?? ($plan_cfg['station_audit'] ? 1 : 0));

        // Upsert
        $existing = $pdo->prepare("SELECT id FROM company_subscriptions WHERE company_id = ?");
        $existing->execute([$company_id]);

        $fields = [
            'plan_name' => $plan, 'status' => $status, 'expires_at' => $expires ?: null,
            'billing_cycle' => $billing,
            'max_users' => $max_users, 'max_outlets' => $max_outlets,
            'max_products' => $max_products, 'max_departments' => $max_departments,
            'max_clients' => $max_clients, 'data_retention_days' => $data_retention,
            'pdf_export' => $pdf_export, 'viewer_role' => $viewer_role,
            'station_audit' => $station_audit, 'notes' => $notes,
        ];

        if ($existing->fetch()) {
            $set = implode(', ', array_map(fn($k) => "$k=?", array_keys($fields)));
            $pdo->prepare("UPDATE company_subscriptions SET $set WHERE company_id=?")
                ->execute([...array_values($fields), $company_id]);
        } else {
            $fields['company_id'] = $company_id;
            $fields['started_at'] = date('Y-m-d');
            $cols = implode(', ', array_keys($fields));
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $pdo->prepare("INSERT INTO company_subscriptions ($cols) VALUES ($placeholders)")
                ->execute(array_values($fields));
        }
        echo json_encode(['success' => true, 'plan_applied' => $plan, 'limits' => $fields]);
        break;


    // ── Reset Password for Company Admin ──
    case 'reset_password':
        $company_id = (int)($_POST['company_id'] ?? 0);
        $new_password = trim($_POST['new_password'] ?? '');
        $user_id = (int)($_POST['user_id'] ?? 0);

        if (!$new_password || strlen($new_password) < 6) {
            echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
            break;
        }
        // Find the target user: either specified user_id, or the business_owner for the company
        if ($user_id) {
            $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM users WHERE id = ? AND company_id = ? AND deleted_at IS NULL LIMIT 1");
            $stmt->execute([$user_id, $company_id]);
        } else {
            $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM users WHERE company_id = ? AND deleted_at IS NULL ORDER BY CASE role WHEN 'business_owner' THEN 0 ELSE 1 END, id LIMIT 1");
            $stmt->execute([$company_id]);
        }
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'No user found for this company.']);
            break;
        }
        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $user['id']]);
        echo json_encode(['success' => true, 'message' => 'Password reset for ' . $user['first_name'] . ' ' . $user['last_name']]);
        break;

    // ── Delete Company (soft) ──
    case 'delete_company':
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE companies SET deleted_at = NOW() WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        break;


    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
}
