<?php
/**
 * MIAUDITOPS — Settings API Handler
 * Actions: update_company, change_password, add_user, update_user, toggle_user,
 *          delete_user, reset_user_password, update_user_permissions, update_user_clients,
 *          add_category, delete_category, get_audit_trail
 */
header('Content-Type: application/json');
require_once '../includes/functions.php';
require_login();

$company_id = $_SESSION['company_id'];
$user_id    = $_SESSION['user_id'];
$action     = $_POST['action'] ?? '';

// Viewer role cannot write, but can read audit trail
if ($action !== 'get_audit_trail') {
    require_non_viewer();
}

try {
    switch ($action) {

        case 'update_company':
            $name    = clean_input($_POST['name'] ?? '');
            $email   = clean_input($_POST['email'] ?? '');
            $phone   = clean_input($_POST['phone'] ?? '');
            $address = clean_input($_POST['address'] ?? '');

            if (!$name || !$email) { echo json_encode(['success' => false, 'message' => 'Name & Email required']); break; }

            $stmt = $pdo->prepare("UPDATE companies SET name = ?, email = ?, phone = ?, address = ? WHERE id = ?");
            $stmt->execute([$name, $email, $phone, $address, $company_id]);
            log_audit($company_id, $user_id, 'update_company', 'settings', $company_id, "Updated company profile");
            echo json_encode(['success' => true]);
            break;

        case 'change_password':
            $current = $_POST['current_password'] ?? '';
            $new     = $_POST['new_password'] ?? '';

            if (strlen($new) < 6) { echo json_encode(['success' => false, 'message' => 'Password must be at least 6 chars']); break; }

            $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();

            if (!password_verify($current, $user['password'])) {
                echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
                break;
            }

            $hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hash, $user_id]);
            log_audit($company_id, $user_id, 'change_password', 'settings', $user_id, "Changed own password");
            echo json_encode(['success' => true]);
            break;

        case 'add_user':
            if (!is_admin_role()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); break; }

            // ── Subscription limit check ──
            $limit = check_user_limit($company_id);
            if (!$limit['allowed']) {
                echo json_encode(['success' => false, 'message' => "User limit reached ({$limit['current']}/{$limit['max']}). Upgrade your plan to add more users."]);
                break;
            }

            $first    = clean_input($_POST['first_name'] ?? '');
            $last     = clean_input($_POST['last_name'] ?? '');
            $email    = clean_input($_POST['email'] ?? '');
            $phone    = clean_input($_POST['phone'] ?? '');
            $role     = clean_input($_POST['role'] ?? 'department_head');
            $dept     = clean_input($_POST['department'] ?? '');
            $password = $_POST['password'] ?? '';

            if (!$first || !$last || !$email || !$password) {
                echo json_encode(['success' => false, 'message' => 'First name, Last name, Email and Password are required']); break;
            }

            // Check duplicate email within company
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND company_id = ? AND deleted_at IS NULL");
            $stmt->execute([$email, $company_id]);
            if ($stmt->fetch()) { echo json_encode(['success' => false, 'message' => 'Email already in use']); break; }

            $pdo->beginTransaction();

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (company_id, first_name, last_name, email, phone, password, role, department, is_active, email_verified_at) VALUES (?,?,?,?,?,?,?,?,1, NOW())");
            $stmt->execute([$company_id, $first, $last, $email, $phone, $hash, $role, $dept]);
            $new_user_id = $pdo->lastInsertId();

            // Set permissions — always include dashboard + company_setup
            $permissions = json_decode($_POST['permissions'] ?? '[]', true);
            // Ensure core permissions are always present
            foreach (['dashboard', 'company_setup'] as $core) {
                if (!in_array($core, $permissions)) {
                    $permissions[] = $core;
                }
            }
            $pstmt = $pdo->prepare("INSERT INTO user_permissions (user_id, permission, granted_by) VALUES (?, ?, ?)");
            foreach ($permissions as $perm) {
                $pstmt->execute([$new_user_id, $perm, $user_id]);
            }

            // Set client assignments
            $client_ids = json_decode($_POST['client_ids'] ?? '[]', true);
            if (!empty($client_ids)) {
                $cstmt = $pdo->prepare("INSERT INTO user_clients (user_id, client_id, assigned_by) VALUES (?, ?, ?)");
                foreach ($client_ids as $cid) {
                    $cstmt->execute([$new_user_id, intval($cid), $user_id]);
                }
            }

            $pdo->commit();
            log_audit($company_id, $user_id, 'add_user', 'settings', $new_user_id, "Added user: $first $last ($role)");
            echo json_encode(['success' => true]);
            break;

        case 'update_user':
            if (!is_admin_role()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); break; }

            $target_id = intval($_POST['user_id'] ?? 0);
            $first     = clean_input($_POST['first_name'] ?? '');
            $last      = clean_input($_POST['last_name'] ?? '');
            $email     = clean_input($_POST['email'] ?? '');
            $phone     = clean_input($_POST['phone'] ?? '');
            $role      = clean_input($_POST['role'] ?? '');
            $dept      = clean_input($_POST['department'] ?? '');

            if (!$target_id || !$first || !$last || !$email) {
                echo json_encode(['success' => false, 'message' => 'First name, Last name and Email are required']); break;
            }

            // Check duplicate email (excluding self)
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND company_id = ? AND id != ? AND deleted_at IS NULL");
            $stmt->execute([$email, $company_id, $target_id]);
            if ($stmt->fetch()) { echo json_encode(['success' => false, 'message' => 'Email already in use by another user']); break; }

            $stmt = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ?, role = ?, department = ? WHERE id = ? AND company_id = ?");
            $stmt->execute([$first, $last, $email, $phone, $role, $dept, $target_id, $company_id]);

            log_audit($company_id, $user_id, 'update_user', 'settings', $target_id, "Updated user: $first $last");
            echo json_encode(['success' => true]);
            break;

        case 'toggle_user':
            if (!is_admin_role()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); break; }

            $target_id = intval($_POST['user_id'] ?? 0);
            if ($target_id === $user_id) { echo json_encode(['success' => false, 'message' => 'Cannot toggle own account']); break; }

            $stmt = $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ? AND company_id = ?");
            $stmt->execute([$target_id, $company_id]);
            log_audit($company_id, $user_id, 'toggle_user', 'settings', $target_id, "Toggled user active status");
            echo json_encode(['success' => true]);
            break;

        case 'delete_user':
            if (!is_admin_role()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); break; }

            $target_id = intval($_POST['user_id'] ?? 0);
            if ($target_id === $user_id) { echo json_encode(['success' => false, 'message' => 'Cannot delete own account']); break; }

            $stmt = $pdo->prepare("UPDATE users SET deleted_at = NOW() WHERE id = ? AND company_id = ?");
            $stmt->execute([$target_id, $company_id]);
            // Clean up permissions and client assignments
            $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ?")->execute([$target_id]);
            $pdo->prepare("DELETE FROM user_clients WHERE user_id = ?")->execute([$target_id]);

            log_audit($company_id, $user_id, 'delete_user', 'settings', $target_id, "Deleted user");
            echo json_encode(['success' => true]);
            break;

        case 'reset_user_password':
            if (!is_admin_role()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); break; }

            $target_id   = intval($_POST['user_id'] ?? 0);
            $new_password = $_POST['new_password'] ?? '';

            if (strlen($new_password) < 6) { echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']); break; }

            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ? AND company_id = ?");
            $stmt->execute([$hash, $target_id, $company_id]);

            log_audit($company_id, $user_id, 'reset_password', 'settings', $target_id, "Admin reset password");
            echo json_encode(['success' => true]);
            break;

        case 'update_user_permissions':
            if (!is_admin_role()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); break; }

            $target_id   = intval($_POST['user_id'] ?? 0);
            $permissions = json_decode($_POST['permissions'] ?? '[]', true);

            if (!$target_id) { echo json_encode(['success' => false, 'message' => 'Invalid user']); break; }

            // Ensure core permissions are always present
            foreach (['dashboard', 'company_setup'] as $core) {
                if (!in_array($core, $permissions)) {
                    $permissions[] = $core;
                }
            }

            set_user_permissions($target_id, $permissions, $user_id);
            log_audit($company_id, $user_id, 'update_permissions', 'settings', $target_id, "Updated permissions: " . implode(', ', $permissions));
            echo json_encode(['success' => true]);
            break;

        case 'update_user_clients':
            if (!is_admin_role()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); break; }

            $target_id  = intval($_POST['user_id'] ?? 0);
            $client_ids = json_decode($_POST['client_ids'] ?? '[]', true);

            if (!$target_id) { echo json_encode(['success' => false, 'message' => 'Invalid user']); break; }

            set_user_clients($target_id, $client_ids, $user_id);
            log_audit($company_id, $user_id, 'update_user_clients', 'settings', $target_id, "Updated client assignments");
            echo json_encode(['success' => true]);
            break;

        case 'add_category':
            $name = clean_input($_POST['name'] ?? '');
            $type = clean_input($_POST['type'] ?? 'operating');
            $desc = clean_input($_POST['description'] ?? '');

            if (!$name) { echo json_encode(['success' => false, 'message' => 'Category name required']); break; }

            $stmt = $pdo->prepare("INSERT INTO expense_categories (company_id, name, type, description) VALUES (?,?,?,?)");
            $stmt->execute([$company_id, $name, $type, $desc]);
            log_audit($company_id, $user_id, 'add_category', 'settings', $pdo->lastInsertId(), "Added expense category: $name ($type)");
            echo json_encode(['success' => true]);
            break;

        case 'delete_category':
            $cat_id = intval($_POST['category_id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE expense_categories SET deleted_at = NOW() WHERE id = ? AND company_id = ?");
            $stmt->execute([$cat_id, $company_id]);
            log_audit($company_id, $user_id, 'delete_category', 'settings', $cat_id, "Deleted expense category");
            echo json_encode(['success' => true]);
            break;

        case 'get_audit_trail':
            $page     = max(1, intval($_POST['page'] ?? 1));
            $per_page = min(100, max(10, intval($_POST['per_page'] ?? 25)));
            $offset   = ($page - 1) * $per_page;

            $where = ['a.company_id = ?'];
            $params = [$company_id];

            // Date range filter
            if (!empty($_POST['date_from'])) {
                $where[] = 'a.created_at >= ?';
                $params[] = $_POST['date_from'] . ' 00:00:00';
            }
            if (!empty($_POST['date_to'])) {
                $where[] = 'a.created_at <= ?';
                $params[] = $_POST['date_to'] . ' 23:59:59';
            }
            // Module filter
            if (!empty($_POST['module'])) {
                $where[] = 'a.module = ?';
                $params[] = $_POST['module'];
            }
            // User filter
            if (!empty($_POST['user_id'])) {
                $where[] = 'a.user_id = ?';
                $params[] = intval($_POST['user_id']);
            }
            // Search filter (action or details)
            if (!empty($_POST['search'])) {
                $search = '%' . $_POST['search'] . '%';
                $where[] = '(a.action LIKE ? OR a.details LIKE ?)';
                $params[] = $search;
                $params[] = $search;
            }

            $where_sql = implode(' AND ', $where);

            // Get total count
            $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_trail a WHERE $where_sql");
            $count_stmt->execute($params);
            $total = (int) $count_stmt->fetchColumn();

            // Fetch logs with user names
            $sql = "SELECT a.id, a.action, a.module, a.record_id, a.details, a.ip_address, a.created_at,
                           u.first_name, u.last_name
                    FROM audit_trail a
                    LEFT JOIN users u ON u.id = a.user_id
                    WHERE $where_sql
                    ORDER BY a.created_at DESC
                    LIMIT $per_page OFFSET $offset";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success'     => true,
                'logs'        => $logs,
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $per_page,
                'total_pages' => max(1, ceil($total / $per_page)),
            ]);
            break;

        case 'bulk_toggle_users':
            if (!is_admin_role()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); break; }

            $user_ids = json_decode($_POST['user_ids'] ?? '[]', true);
            $activate = intval($_POST['activate'] ?? 0);
            $user_ids = array_filter(array_map('intval', $user_ids), fn($id) => $id > 0 && $id !== $user_id);

            if (empty($user_ids)) { echo json_encode(['success' => false, 'message' => 'No valid users selected']); break; }

            $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
            $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE id IN ($placeholders) AND company_id = ?");
            $stmt->execute(array_merge([$activate], $user_ids, [$company_id]));

            $label = $activate ? 'activated' : 'deactivated';
            log_audit($company_id, $user_id, 'bulk_toggle_users', 'settings', 0, "Bulk $label " . count($user_ids) . " user(s)");
            echo json_encode(['success' => true]);
            break;

        case 'bulk_change_role':
            if (!is_admin_role()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); break; }

            $user_ids = json_decode($_POST['user_ids'] ?? '[]', true);
            $role = clean_input($_POST['role'] ?? '');
            $valid_roles = ['business_owner','auditor','finance_officer','store_officer','department_head','staff','hod','ceo','viewer'];
            $user_ids = array_filter(array_map('intval', $user_ids), fn($id) => $id > 0 && $id !== $user_id);

            if (empty($user_ids) || !in_array($role, $valid_roles)) {
                echo json_encode(['success' => false, 'message' => 'Invalid selection']); break;
            }

            $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
            $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id IN ($placeholders) AND company_id = ?");
            $stmt->execute(array_merge([$role], $user_ids, [$company_id]));

            log_audit($company_id, $user_id, 'bulk_change_role', 'settings', 0, "Bulk changed " . count($user_ids) . " user(s) to role: $role");
            echo json_encode(['success' => true]);
            break;

        case 'bulk_delete_users':
            if (!is_admin_role()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); break; }

            $user_ids = json_decode($_POST['user_ids'] ?? '[]', true);
            $user_ids = array_filter(array_map('intval', $user_ids), fn($id) => $id > 0 && $id !== $user_id);

            if (empty($user_ids)) { echo json_encode(['success' => false, 'message' => 'No valid users selected']); break; }

            $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
            $pdo->prepare("UPDATE users SET deleted_at = NOW() WHERE id IN ($placeholders) AND company_id = ?")->execute(array_merge($user_ids, [$company_id]));
            $pdo->prepare("DELETE FROM user_permissions WHERE user_id IN ($placeholders)")->execute($user_ids);
            $pdo->prepare("DELETE FROM user_clients WHERE user_id IN ($placeholders)")->execute($user_ids);

            log_audit($company_id, $user_id, 'bulk_delete_users', 'settings', 0, "Bulk deleted " . count($user_ids) . " user(s)");
            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
