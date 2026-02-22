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
require_once '../includes/trash_helper.php';

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
            cs.data_retention_days, cs.pdf_export, cs.viewer_role, cs.station_audit, cs.addon_client_packs
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

        // Add-on packs: each pack = +1 client + 6 departments @ ₦25,000/mo
        $addon_packs = max(0, (int)($_POST['addon_client_packs'] ?? 0));

        $max_users       = (int)($_POST['max_users']       ?? $plan_cfg['max_users']);
        $max_outlets     = (int)($_POST['max_outlets']     ?? $plan_cfg['max_outlets']);
        $max_products    = (int)($_POST['max_products']    ?? $plan_cfg['max_products']);

        // Calculate effective limits from plan base + add-ons
        $base_clients    = (int)$plan_cfg['max_clients'];
        $base_departments = (int)$plan_cfg['max_departments'];
        // Owner override: if explicitly provided, use it; otherwise calculate from plan+addons
        $calculated_clients = $base_clients + $addon_packs;
        $max_clients = isset($_POST['max_clients']) && (int)$_POST['max_clients'] > 0
            ? max((int)$_POST['max_clients'], $calculated_clients) // Owner can only increase, not decrease below add-on calculation
            : $calculated_clients;
        // If plan has unlimited departments (0), keep unlimited; otherwise add 6 per pack
        $max_departments = ($base_departments === 0) ? 0 : $base_departments + ($addon_packs * 6);

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
            'station_audit' => $station_audit, 'addon_client_packs' => $addon_packs,
            'notes' => $notes,
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
        echo json_encode(['success' => true, 'plan_applied' => $plan, 'addon_packs' => $addon_packs, 'limits' => $fields]);
        break;

    // ── Admin Override: Reset Add-On Packs ──
    case 'reset_addon_packs':
        require_once '../config/subscription_plans.php';
        $company_id = (int)($_POST['company_id'] ?? 0);
        $new_packs  = max(0, min(20, (int)($_POST['addon_client_packs'] ?? 0)));

        if (!$company_id) {
            echo json_encode(['success' => false, 'message' => 'Company ID required']);
            break;
        }

        // Get current subscription
        $sub_stmt = $pdo->prepare("SELECT * FROM company_subscriptions WHERE company_id = ? ORDER BY id DESC LIMIT 1");
        $sub_stmt->execute([$company_id]);
        $current_sub = $sub_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$current_sub) {
            echo json_encode(['success' => false, 'message' => 'No subscription found']);
            break;
        }

        $old_packs  = (int)($current_sub['addon_client_packs'] ?? 0);
        $plan_key_r = $current_sub['plan_name'] ?? 'starter';
        $plan_cfg_r = get_plan_config($plan_key_r);

        // Recalculate limits
        $base_clients_r = (int)$plan_cfg_r['max_clients'];
        $base_depts_r   = (int)$plan_cfg_r['max_departments'];
        $eff_clients_r  = $base_clients_r + $new_packs;
        $eff_depts_r    = ($base_depts_r === 0) ? 0 : $base_depts_r + ($new_packs * 6);

        // Update subscription
        $pdo->prepare("
            UPDATE company_subscriptions SET addon_client_packs = ?, max_clients = ?, max_departments = ?
            WHERE company_id = ? ORDER BY id DESC LIMIT 1
        ")->execute([$new_packs, $eff_clients_r, $eff_depts_r, $company_id]);

        // Update unpaid invoice if exists
        try {
            $cycle_key_r = $current_sub['billing_cycle'] ?? 'monthly';
            $prices_r = get_dynamic_prices();
            $plan_cost_r = $prices_r[$plan_key_r . '_' . $cycle_key_r] ?? 0;
            // Fallback prices
            if ($plan_cost_r <= 0) {
                $fallback_r = ['professional_monthly'=>25000,'professional_quarterly'=>67500,'professional_annual'=>240000,'enterprise_monthly'=>75000,'enterprise_quarterly'=>202500,'enterprise_annual'=>720000];
                $plan_cost_r = $fallback_r[$plan_key_r . '_' . $cycle_key_r] ?? 0;
            }
            $cycle_cfg_r = get_cycle_config();
            $months_r = $cycle_cfg_r[$cycle_key_r]['months'] ?? 1;
            $addon_cost_r = $new_packs * 25000 * $months_r;
            $new_total_r = $plan_cost_r + $addon_cost_r;

            $notes_r = ucfirst($plan_key_r) . ' ' . ucfirst($cycle_key_r) . ' subscription';
            if ($new_packs > 0) {
                $notes_r .= ' + ' . $new_packs . ' add-on pack(s) (₦' . number_format($addon_cost_r) . ')';
            }
            $notes_r .= ' [Admin override]';

            $pdo->prepare("
                UPDATE billing_invoices SET amount_naira = ?, notes = ?
                WHERE company_id = ? AND status IN ('draft','sent','overdue') ORDER BY id DESC LIMIT 1
            ")->execute([$new_total_r, $notes_r, $company_id]);
        } catch (Exception $e) { /* table might not exist yet */ }

        // Audit log
        log_audit($company_id, $_SESSION['user_id'] ?? 0, 'admin_addon_override', 'billing', null,
            "Admin overrode add-on packs from $old_packs to $new_packs (clients: $eff_clients_r, depts: $eff_depts_r)");

        echo json_encode([
            'success' => true,
            'message' => "Add-on packs set to $new_packs. Limits: $eff_clients_r clients, " . ($eff_depts_r === 0 ? '∞' : $eff_depts_r) . " departments. Invoice updated.",
            'addon_client_packs' => $new_packs,
            'effective_clients' => $eff_clients_r,
            'effective_departments' => $eff_depts_r,
        ]);
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

        // Get company name for audit
        $cname_stmt = $pdo->prepare("SELECT name FROM companies WHERE id = ?");
        $cname_stmt->execute([$id]);
        $company_name = $cname_stmt->fetchColumn() ?: 'Unknown';

        // Soft-delete the company
        $pdo->prepare("UPDATE companies SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL")->execute([$id]);

        // Soft-delete ALL users in this company (frees their emails for re-registration)
        $user_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE company_id = ? AND deleted_at IS NULL");
        $user_count_stmt->execute([$id]);
        $affected_users = (int)$user_count_stmt->fetchColumn();
        $pdo->prepare("UPDATE users SET deleted_at = NOW() WHERE company_id = ? AND deleted_at IS NULL")->execute([$id]);

        // Audit trail
        try {
            require_once '../includes/functions.php';
            log_audit(0, $_SESSION['user_id'] ?? 0, 'company_deleted', 'owner_portal', $id,
                "Deleted company '$company_name' (ID: $id) — $affected_users user(s) soft-deleted");
        } catch (Exception $e) { /* audit table might not exist yet */ }

        echo json_encode(['success' => true, 'message' => "Company '$company_name' deleted ($affected_users users affected)"]);
        break;


    // ── List Deleted Companies ──
    case 'list_deleted_companies':
        $stmt = $pdo->query("
            SELECT c.id, c.name, c.code, c.deleted_at,
                   (SELECT u.email FROM users u WHERE u.company_id = c.id AND u.role = 'business_owner' LIMIT 1) as owner_email,
                   (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id) as user_count,
                   cs.plan_name
            FROM companies c
            LEFT JOIN company_subscriptions cs ON cs.company_id = c.id
            WHERE c.deleted_at IS NOT NULL
            ORDER BY c.deleted_at DESC
        ");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    // ── Restore Deleted Company ──
    case 'restore_company':
        $id = (int)($_POST['id'] ?? 0);

        // Get company name for audit
        $cname_stmt = $pdo->prepare("SELECT name FROM companies WHERE id = ?");
        $cname_stmt->execute([$id]);
        $company_name = $cname_stmt->fetchColumn() ?: 'Unknown';

        // Restore company
        $pdo->prepare("UPDATE companies SET deleted_at = NULL WHERE id = ?")->execute([$id]);

        // Restore all users
        $user_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE company_id = ? AND deleted_at IS NOT NULL");
        $user_count_stmt->execute([$id]);
        $restored_users = (int)$user_count_stmt->fetchColumn();
        $pdo->prepare("UPDATE users SET deleted_at = NULL WHERE company_id = ?")->execute([$id]);

        // Audit trail
        try {
            require_once '../includes/functions.php';
            log_audit(0, $_SESSION['user_id'] ?? 0, 'company_restored', 'owner_portal', $id,
                "Restored company '$company_name' (ID: $id) — $restored_users user(s) restored");
        } catch (Exception $e) { /* audit table might not exist yet */ }

        echo json_encode(['success' => true, 'message' => "Company '$company_name' restored ($restored_users users restored)"]);
        break;

    // ── Get Pricing ──
    case 'get_pricing':
        $rows = $pdo->query("SELECT setting_key, setting_value FROM platform_settings WHERE setting_key LIKE 'price_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
        $data = [];
        foreach ($rows as $key => $value) {
            // Convert 'price_professional_monthly' → 'professional_monthly'
            $short = str_replace('price_', '', $key);
            $data[$short] = (int)$value;
        }
        echo json_encode(['success' => true, 'data' => $data]);
        break;

    // ── Update Pricing ──
    case 'update_pricing':
        $fields = [
            'professional_monthly', 'professional_quarterly', 'professional_annual',
            'enterprise_monthly', 'enterprise_quarterly', 'enterprise_annual',
        ];
        $stmt = $pdo->prepare("INSERT INTO platform_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($fields as $field) {
            $value = (int)($_POST[$field] ?? 0);
            $stmt->execute(['price_' . $field, $value]);
        }
        echo json_encode(['success' => true]);
        break;


    // ── List Broadcasts ──
    case 'list_broadcasts':
        $rows = $pdo->query("SELECT * FROM platform_notifications ORDER BY created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $rows]);
        break;

    // ── Send Broadcast ──
    case 'send_broadcast':
        $title   = trim($_POST['title'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $type    = in_array($_POST['type'] ?? '', ['info','warning','success','alert']) ? $_POST['type'] : 'info';
        $target_input = trim($_POST['target'] ?? 'all');
        $expires_days = (int)($_POST['expires_days'] ?? 30);

        if (empty($title) || empty($message)) {
            echo json_encode(['success' => false, 'message' => 'Title and message are required.']);
            break;
        }

        $target = 'all';
        $target_plan = null;
        if ($target_input !== 'all') {
            $target = 'plan';
            $target_plan = $target_input;
        }

        $expires_at = null;
        if ($expires_days > 0) {
            $expires_at = date('Y-m-d H:i:s', strtotime("+{$expires_days} days"));
        }

        $stmt = $pdo->prepare("INSERT INTO platform_notifications (title, message, type, target, target_plan, expires_at, created_by) VALUES (?, ?, ?, ?, ?, ?, 'platform_owner')");
        $stmt->execute([$title, $message, $type, $target, $target_plan, $expires_at]);
        echo json_encode(['success' => true]);
        break;

    // ── Toggle Broadcast Active/Inactive ──
    case 'toggle_broadcast':
        $id = (int)($_POST['id'] ?? 0);
        $is_active = (int)($_POST['is_active'] ?? 0);
        $pdo->prepare("UPDATE platform_notifications SET is_active = ? WHERE id = ?")->execute([$is_active, $id]);
        echo json_encode(['success' => true]);
        break;

    // ── Delete Broadcast ──
    case 'delete_broadcast':
        $id = (int)($_POST['id'] ?? 0);
        // Snapshot broadcast + reads before delete
        $stmt = $pdo->prepare("SELECT * FROM platform_notifications WHERE id = ?");
        $stmt->execute([$id]);
        $notif = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($notif) {
            $reads = $pdo->prepare("SELECT * FROM notification_reads WHERE notification_id = ?");
            $reads->execute([$id]);
            $snapshot = ['notification' => $notif, 'reads' => $reads->fetchAll(PDO::FETCH_ASSOC)];
            move_to_trash($pdo, 0, 'broadcast', $id, $notif['title'] ?? 'Broadcast #'.$id, $snapshot, $_SESSION['user_id'] ?? 0);
        }
        $pdo->prepare("DELETE FROM notification_reads WHERE notification_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM platform_notifications WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        break;

    // ── List Support Tickets (Owner) ──
    case 'list_support_tickets':
        $rows = $pdo->query("
            SELECT t.*, 
                   u.first_name, u.last_name,
                   c.name as company_name
            FROM support_tickets t
            JOIN users u ON u.id = t.user_id
            JOIN companies c ON c.id = t.company_id
            ORDER BY t.created_at DESC
            LIMIT 100
        ")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $rows]);
        break;

    // ── Reply to Support Ticket ──
    case 'reply_ticket':
        $ticket_id = (int)($_POST['ticket_id'] ?? 0);
        $reply     = trim($_POST['reply'] ?? '');
        $status    = in_array($_POST['status'] ?? '', ['open','in_progress','resolved','closed']) ? $_POST['status'] : 'resolved';

        if (!$ticket_id || empty($reply)) {
            echo json_encode(['success' => false, 'message' => 'Ticket ID and reply are required.']);
            break;
        }

        // Update ticket's admin_reply column (backward compat) + status
        $stmt = $pdo->prepare("UPDATE support_tickets SET admin_reply = ?, replied_at = NOW(), status = ? WHERE id = ?");
        $stmt->execute([$reply, $status, $ticket_id]);

        // Also insert into threaded replies table
        $admin_user_id = $_SESSION['user_id'] ?? 0;
        $stmt2 = $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_id, is_admin, message) VALUES (?, ?, 1, ?)");
        $stmt2->execute([$ticket_id, $admin_user_id, $reply]);

        // Notify ticket submitter that admin replied
        try {
            $tStmt = $pdo->prepare("SELECT user_id, subject FROM support_tickets WHERE id = ?");
            $tStmt->execute([$ticket_id]);
            $tData = $tStmt->fetch(PDO::FETCH_ASSOC);
            if ($tData && $tData['user_id']) {
                $cStmt = $pdo->prepare("SELECT company_id FROM support_tickets WHERE id = ?");
                $cStmt->execute([$ticket_id]);
                $cData = $cStmt->fetch(PDO::FETCH_ASSOC);
                $cid = $cData['company_id'] ?? 0;
                $pdo->prepare("INSERT INTO app_notifications (company_id, user_id, title, message, type, link) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$cid, $tData['user_id'], '💬 Support Reply', "Admin replied to your ticket: \"{$tData['subject']}\"", 'info', 'support.php']);
            }
        } catch (Exception $e) {}

        echo json_encode(['success' => true, 'reply_id' => $pdo->lastInsertId()]);
        break;

    // ── List Ticket Replies (threaded) ──
    case 'list_ticket_replies':
        $ticket_id = (int)($_POST['ticket_id'] ?? $_GET['ticket_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT r.*, u.first_name, u.last_name
            FROM ticket_replies r
            JOIN users u ON u.id = r.user_id
            WHERE r.ticket_id = ?
            ORDER BY r.created_at ASC
        ");
        $stmt->execute([$ticket_id]);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    // ── Update Ticket Status ──
    case 'update_ticket_status':
        $ticket_id = (int)($_POST['ticket_id'] ?? 0);
        $status    = in_array($_POST['status'] ?? '', ['open','in_progress','resolved','closed']) ? $_POST['status'] : 'open';

        if (!$ticket_id) {
            echo json_encode(['success' => false, 'message' => 'Ticket ID is required.']);
            break;
        }

        $stmt = $pdo->prepare("UPDATE support_tickets SET status = ? WHERE id = ?");
        $stmt->execute([$status, $ticket_id]);
        echo json_encode(['success' => true]);
        break;


    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
}
