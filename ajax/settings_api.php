<?php
/**
 * MIAUDITOPS — Settings API Handler
 * Actions: update_company, change_password, add_user, update_user, toggle_user,
 *          delete_user, reset_user_password, update_user_permissions, update_user_clients,
 *          add_category, delete_category
 */
header('Content-Type: application/json');
require_once '../includes/functions.php';
require_login();

$company_id = $_SESSION['company_id'];
$user_id    = $_SESSION['user_id'];
$action     = $_POST['action'] ?? '';
require_non_viewer(); // Viewer role cannot access settings

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
            $stmt = $pdo->prepare("INSERT INTO users (company_id, first_name, last_name, email, phone, password, role, department, is_active) VALUES (?,?,?,?,?,?,?,?,1)");
            $stmt->execute([$company_id, $first, $last, $email, $phone, $hash, $role, $dept]);
            $new_user_id = $pdo->lastInsertId();

            // Set permissions
            $permissions = json_decode($_POST['permissions'] ?? '[]', true);
            if (!empty($permissions)) {
                $pstmt = $pdo->prepare("INSERT INTO user_permissions (user_id, permission, granted_by) VALUES (?, ?, ?)");
                foreach ($permissions as $perm) {
                    $pstmt->execute([$new_user_id, $perm, $user_id]);
                }
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

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
