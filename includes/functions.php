<?php
/**
 * MIAUDITOPS — Core Helper Functions
 */

require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/subscription_plans.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Sanitize user input
 */
function clean_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Redirect helper
 */
function redirect($url) {
    header("Location: $url");
    exit;
}

/**
 * Check if user is logged in
 */
function is_logged_in() {
    return isset($_SESSION['user_id']) && isset($_SESSION['company_id']);
}

/**
 * Require login — redirect to login page if not authenticated
 */
function require_login() {
    if (!is_logged_in()) {
        redirect('../auth/login.php');
    }
}

/**
 * Get current user's role
 */
function get_user_role() {
    return $_SESSION['user_role'] ?? 'department_head';
}

/**
 * Check if user has permission based on role
 * Permissions are hierarchical:
 *   super_admin > business_owner > auditor / finance_officer / store_officer > department_head
 */
function check_permission($required_roles) {
    if (!is_array($required_roles)) {
        $required_roles = [$required_roles];
    }
    $user_role = get_user_role();
    
    // Super admin and business owner always have access
    if (in_array($user_role, ['super_admin', 'business_owner'])) {
        return true;
    }
    
    return in_array($user_role, $required_roles);
}

/**
 * Require specific role — redirect with error if unauthorized
 */
function require_role($roles) {
    if (!check_permission($roles)) {
        set_flash_message('error', 'You do not have permission to access this page.');
        redirect('../dashboard/index.php');
    }
}

/**
 * Set flash message in session
 */
function set_flash_message($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Display flash message (returns HTML)
 */
function display_flash_message() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        $type = $flash['type'];
        $message = $flash['message'];
        unset($_SESSION['flash']);
        
        $colors = [
            'success' => 'bg-emerald-50 border-emerald-300 text-emerald-800',
            'error'   => 'bg-red-50 border-red-300 text-red-800',
            'warning' => 'bg-amber-50 border-amber-300 text-amber-800',
            'info'    => 'bg-blue-50 border-blue-300 text-blue-800',
        ];
        $color = $colors[$type] ?? $colors['info'];
        $icons = [
            'success' => 'check-circle',
            'error'   => 'x-circle',
            'warning' => 'alert-triangle',
            'info'    => 'info',
        ];
        $icon = $icons[$type] ?? 'info';
        
        echo '<div class="mx-6 mt-4 p-4 rounded-xl border ' . $color . ' flex items-center gap-3" x-data="{show:true}" x-show="show" x-transition>';
        echo '<i data-lucide="' . $icon . '" class="w-5 h-5 shrink-0"></i>';
        echo '<span class="text-sm font-medium flex-1">' . $message . '</span>';
        echo '<button @click="show=false" class="opacity-50 hover:opacity-100"><i data-lucide="x" class="w-4 h-4"></i></button>';
        echo '</div>';
    }
}

/**
 * Log an audit trail event
 */
function log_audit($company_id, $user_id, $action, $module = null, $record_id = null, $details = null) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO audit_trail (company_id, user_id, action, module, record_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $company_id,
        $user_id,
        $action,
        $module,
        $record_id,
        $details,
        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
    ]);
}

/**
 * Create an in-app notification for a specific user
 * @param int    $company_id  Company scope
 * @param int    $target_user Target user who will see this
 * @param string $title       Short title (e.g. "Requisition Approved")
 * @param string $message     Detailed message
 * @param string $type        info|warning|success|alert
 * @param string|null $link   Optional page link (e.g. "requisitions.php")
 */
function app_notify($company_id, $target_user, $title, $message, $type = 'info', $link = null) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO app_notifications (company_id, user_id, title, message, type, link) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$company_id, $target_user, $title, $message, $type, $link]);
    } catch (Exception $e) {
        // Table may not exist yet — fail silently
    }
}

/**
 * Notify all users with a specific role in a company
 */
function notify_role($company_id, $role, $title, $message, $type = 'info', $link = null, $exclude_user_id = null) {
    global $pdo;
    try {
        $sql = "SELECT id FROM users WHERE company_id = ? AND role = ? AND is_active = 1 AND deleted_at IS NULL";
        $params = [$company_id, $role];
        if ($exclude_user_id) {
            $sql .= " AND id != ?";
            $params[] = $exclude_user_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($users as $uid) {
            app_notify($company_id, $uid, $title, $message, $type, $link);
        }
    } catch (Exception $e) {
        // Fail silently
    }
}

/**
 * Notify all users with approver roles (HOD, CEO, business_owner) in a company
 */
function notify_approvers($company_id, $title, $message, $type = 'info', $link = null, $exclude_user_id = null) {
    foreach (['hod', 'ceo', 'business_owner', 'department_head'] as $role) {
        notify_role($company_id, $role, $title, $message, $type, $link, $exclude_user_id);
    }
}

/**
 * Format currency amount
 */
function format_currency($amount, $symbol = '₦') {
    return $symbol . number_format((float)$amount, 2);
}

/**
 * Generate unique requisition number
 */
function generate_requisition_number($company_id) {
    global $pdo;
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM requisitions WHERE company_id = ? AND YEAR(created_at) = ?");
    $stmt->execute([$company_id, $year]);
    $count = $stmt->fetch()['count'] + 1;
    return 'REQ-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
}

/**
 * Generate unique PO number
 */
function generate_po_number($company_id) {
    global $pdo;
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM purchase_orders WHERE company_id = ? AND YEAR(created_at) = ?");
    $stmt->execute([$company_id, $year]);
    $count = $stmt->fetch()['count'] + 1;
    return 'PO-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
}

/**
 * Get company details
 */
function get_company($company_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM companies WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$company_id]);
    return $stmt->fetch();
}

/**
 * Get user details
 */
function get_user($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$user_id]);
    return $stmt->fetch();
}

/**
 * Register new company and admin user
 */
function register_company_and_user($company_name, $email, $password, $first_name, $last_name, $plan = 'starter', $cycle = 'monthly') {
    global $pdo;
    require_once __DIR__ . '/../config/subscription_plans.php';
    require_once __DIR__ . '/mail_helper.php';
    
    // Auto-migrate: add email verification columns if missing
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN email_verified_at DATETIME DEFAULT NULL");
    } catch (Exception $ignore) {}
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN verification_token VARCHAR(64) DEFAULT NULL");
    } catch (Exception $ignore) {}
    
    // Validate plan
    $valid_plans = ['starter', 'professional', 'enterprise'];
    if (!in_array($plan, $valid_plans)) $plan = 'starter';
    $valid_cycles = ['monthly', 'quarterly', 'annual'];
    if (!in_array($cycle, $valid_cycles)) $cycle = 'monthly';
    
    $plan_cfg = get_plan_config($plan);
    
    try {
        $pdo->beginTransaction();
        
        // Generate company code
        $code = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $company_name), 0, 4));
        $code .= rand(100, 999);
        
        // Create company
        $stmt = $pdo->prepare("INSERT INTO companies (name, code, email) VALUES (?, ?, ?)");
        $stmt->execute([$company_name, $code, $email]);
        $company_id = $pdo->lastInsertId();
        
        // Generate verification token
        $verification_token = bin2hex(random_bytes(32));
        
        // Create admin user (unverified)
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (company_id, first_name, last_name, email, password, role, verification_token) VALUES (?, ?, ?, ?, ?, 'business_owner', ?)");
        $stmt->execute([$company_id, $first_name, $last_name, $email, $hashed_password, $verification_token]);
        $user_id = $pdo->lastInsertId();
        
        // Create default expense categories
        $categories = [
            ['Cost of Sales', 'cost_of_sales'],
            ['Utilities', 'operating'],
            ['Salaries & Wages', 'operating'],
            ['Logistics & Transport', 'operating'],
            ['Maintenance', 'operating'],
            ['Office Supplies', 'administrative'],
            ['Marketing', 'operating'],
            ['Miscellaneous', 'other']
        ];
        $stmt = $pdo->prepare("INSERT INTO expense_categories (company_id, name, type) VALUES (?, ?, ?)");
        foreach ($categories as $cat) {
            $stmt->execute([$company_id, $cat[0], $cat[1]]);
        }
        
        // Create subscription with chosen plan
        // Starter = free forever, paid plans = 7-day trial
        $trial_days = ($plan === 'starter') ? 365 : 7;
        $trial_expires = date('Y-m-d', strtotime("+{$trial_days} days"));
        $pdo->prepare("
            INSERT INTO company_subscriptions 
            (company_id, plan_name, status, billing_cycle, started_at, expires_at,
             max_users, max_clients, max_outlets, max_products, max_departments, 
             data_retention_days, addon_client_packs)
            VALUES (?, ?, 'trial', ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, 0)
        ")->execute([
            $company_id, $plan, $cycle, $trial_expires,
            (int)$plan_cfg['max_users'], (int)$plan_cfg['max_clients'],
            (int)$plan_cfg['max_outlets'], (int)$plan_cfg['max_products'],
            (int)$plan_cfg['max_departments'], (int)$plan_cfg['data_retention_days']
        ]);
        
        // Log the registration
        $plan_label = ucfirst($plan);
        log_audit($company_id, $user_id, 'company_registered', 'core', $company_id, "Company '$company_name' registered with $plan_label trial ($cycle)");
        
        $pdo->commit();
        
        // Send verification email (outside transaction — non-critical)
        $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $verify_link = $base_url . dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/auth/verify_email.php?token=' . $verification_token;
        // Normalize double-slashes
        $verify_link = preg_replace('#(?<!:)//+#', '/', $verify_link);
        $verify_link = str_replace(':/', '://', $verify_link);
        
        send_verification_email($email, $first_name, $verify_link, $code);
        
        return ['company_id' => $company_id, 'user_id' => $user_id, 'code' => $code, 'plan' => $plan];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Register new company and admin user via Google OAuth
 */
function register_company_and_user_google($company_name, $email, $google_id, $first_name, $last_name, $avatar_url = '', $plan = 'starter', $cycle = 'monthly') {
    global $pdo;
    require_once __DIR__ . '/../config/subscription_plans.php';
    
    // Validate plan
    $valid_plans = ['starter', 'professional', 'enterprise'];
    if (!in_array($plan, $valid_plans)) $plan = 'starter';
    $valid_cycles = ['monthly', 'quarterly', 'annual'];
    if (!in_array($cycle, $valid_cycles)) $cycle = 'monthly';
    
    $plan_cfg = get_plan_config($plan);
    
    try {
        $pdo->beginTransaction();
        
        // Generate company code
        $code = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $company_name), 0, 4));
        $code .= rand(100, 999);
        
        // Create company
        $stmt = $pdo->prepare("INSERT INTO companies (name, code, email) VALUES (?, ?, ?)");
        $stmt->execute([$company_name, $code, $email]);
        $company_id = $pdo->lastInsertId();
        
        // Create admin user (Google-verified — email already confirmed)
        $stmt = $pdo->prepare("INSERT INTO users (company_id, first_name, last_name, email, google_id, avatar_url, role, email_verified_at) VALUES (?, ?, ?, ?, ?, ?, 'business_owner', NOW())");
        $stmt->execute([$company_id, $first_name, $last_name, $email, $google_id, $avatar_url ?: null]);
        $user_id = $pdo->lastInsertId();
        
        // Create default expense categories
        $categories = [
            ['Cost of Sales', 'cost_of_sales'],
            ['Utilities', 'operating'],
            ['Salaries & Wages', 'operating'],
            ['Logistics & Transport', 'operating'],
            ['Maintenance', 'operating'],
            ['Office Supplies', 'administrative'],
            ['Marketing', 'operating'],
            ['Miscellaneous', 'other']
        ];
        $stmt = $pdo->prepare("INSERT INTO expense_categories (company_id, name, type) VALUES (?, ?, ?)");
        foreach ($categories as $cat) {
            $stmt->execute([$company_id, $cat[0], $cat[1]]);
        }
        
        // Create subscription with chosen plan
        // Starter = free forever, paid plans = 7-day trial
        $trial_days = ($plan === 'starter') ? 365 : 7;
        $trial_expires = date('Y-m-d', strtotime("+{$trial_days} days"));
        $pdo->prepare("
            INSERT INTO company_subscriptions 
            (company_id, plan_name, status, billing_cycle, started_at, expires_at,
             max_users, max_clients, max_outlets, max_products, max_departments, 
             data_retention_days, addon_client_packs)
            VALUES (?, ?, 'trial', ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, 0)
        ")->execute([
            $company_id, $plan, $cycle, $trial_expires,
            (int)$plan_cfg['max_users'], (int)$plan_cfg['max_clients'],
            (int)$plan_cfg['max_outlets'], (int)$plan_cfg['max_products'],
            (int)$plan_cfg['max_departments'], (int)$plan_cfg['data_retention_days']
        ]);
        
        $plan_label = ucfirst($plan);
        log_audit($company_id, $user_id, 'company_registered_google', 'core', $company_id, "Company '$company_name' registered via Google OAuth with $plan_label trial ($cycle)");
        
        $pdo->commit();
        
        return ['company_id' => $company_id, 'user_id' => $user_id, 'code' => $code, 'plan' => $plan];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// =====================================================
// CLIENT & OUTLET HELPERS
// =====================================================

/**
 * Get the active client ID from session
 */
function get_active_client() {
    return $_SESSION['active_client_id'] ?? null;
}

/**
 * Set the active client in session
 */
function set_active_client($client_id) {
    $_SESSION['active_client_id'] = $client_id;
    // Also store client name for display
    global $pdo;
    $stmt = $pdo->prepare("SELECT name, code FROM clients WHERE id = ? AND company_id = ? AND deleted_at IS NULL");
    $stmt->execute([$client_id, $_SESSION['company_id']]);
    $client = $stmt->fetch();
    if ($client) {
        $_SESSION['active_client_name'] = $client['name'];
        $_SESSION['active_client_code'] = $client['code'];
    }
}

/**
 * Require an active client — redirect if none selected
 */
function require_active_client() {
    if (!get_active_client()) {
        set_flash_message('warning', 'Please select a client first.');
        redirect('company_setup.php');
    }
}

/**
 * Get all clients for the current company
 */
function get_clients($company_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT c.*, (SELECT COUNT(*) FROM client_outlets co WHERE co.client_id = c.id AND co.deleted_at IS NULL) as outlet_count FROM clients c WHERE c.company_id = ? AND c.deleted_at IS NULL ORDER BY c.name");
    $stmt->execute([$company_id]);
    return $stmt->fetchAll();
}

/**
 * Get outlets for a specific client (company-scoped)
 */
function get_client_outlets($client_id, $company_id = null) {
    global $pdo;
    if ($company_id) {
        $stmt = $pdo->prepare("SELECT * FROM client_outlets WHERE client_id = ? AND company_id = ? AND deleted_at IS NULL ORDER BY name");
        $stmt->execute([$client_id, $company_id]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM client_outlets WHERE client_id = ? AND deleted_at IS NULL ORDER BY name");
        $stmt->execute([$client_id]);
    }
    return $stmt->fetchAll();
}

/**
 * Get a specific client
 */
function get_client($client_id, $company_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? AND company_id = ? AND deleted_at IS NULL");
    $stmt->execute([$client_id, $company_id]);
    return $stmt->fetch();
}

/**
 * Generate unique client code
 */
function generate_client_code($company_id, $name) {
    global $pdo;
    $base = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $name), 0, 3));
    $stmt = $pdo->prepare("SELECT COUNT(*) as c FROM clients WHERE company_id = ? AND code LIKE ?");
    $stmt->execute([$company_id, $base . '%']);
    $n = $stmt->fetch()['c'] + 1;
    return $base . str_pad($n, 2, '0', STR_PAD_LEFT);
}

// =====================================================
// USER PERMISSIONS & CLIENT ASSIGNMENT HELPERS
// =====================================================

/**
 * All available module permissions
 */
function get_all_permissions() {
    return [
        'dashboard'        => ['label' => 'Dashboard',          'icon' => 'layout-dashboard', 'desc' => 'View the main dashboard overview'],
        'company_setup'    => ['label' => 'Company Setup',      'icon' => 'building-2',       'desc' => 'Manage clients and outlets'],
        'audit'            => ['label' => 'Daily Audit',        'icon' => 'clipboard-check',  'desc' => 'Sales entry, variance tracking'],
        'stock'            => ['label' => 'Stock Audit',        'icon' => 'package',           'desc' => 'Stock counts, wastage records'],
        'main_store'       => ['label' => 'Main Store',         'icon' => 'warehouse',         'desc' => 'Catalog, movements, deliveries'],
        'department_store' => ['label' => 'Department Store',   'icon' => 'store',             'desc' => 'Department-level stock tracking'],
        'finance'          => ['label' => 'Financial Control',  'icon' => 'trending-up',       'desc' => 'Revenue, expenses, P&L'],
        'requisitions'     => ['label' => 'Requisitions',       'icon' => 'file-text',         'desc' => 'Purchase requests & approvals'],
        'reports'          => ['label' => 'Reports',            'icon' => 'bar-chart-3',       'desc' => 'Audit trail, financial reports'],
        'settings'         => ['label' => 'Settings',           'icon' => 'settings',          'desc' => 'Company profile, users, categories'],
    ];
}

/**
 * Check if user role is admin-level (super_admin or business_owner)
 */
function is_admin_role($role = null) {
    $role = $role ?? get_user_role();
    return in_array($role, ['super_admin', 'business_owner']);
}

/**
 * Check if current user is a viewer (read-only role)
 */
function is_viewer($role = null) {
    $role = $role ?? get_user_role();
    return $role === 'viewer';
}

/**
 * Block write actions for viewer role — returns JSON error and exits
 * Call this at the top of any API action that modifies data
 */
function require_non_viewer() {
    if (is_viewer()) {
        echo json_encode(['success' => false, 'message' => 'Your account has view-only access. You cannot make changes.']);
        exit;
    }
}

/**
 * Get all permission keys granted to a user
 */
function get_user_permissions($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT permission FROM user_permissions WHERE user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Check if user has a specific module permission
 * Admin roles always return true
 */
function has_permission($permission, $user_id = null) {
    $user_id = $user_id ?? ($_SESSION['user_id'] ?? 0);
    // Admin roles bypass permission checks
    if (is_admin_role()) {
        return true;
    }
    $perms = get_user_permissions($user_id);
    return in_array($permission, $perms);
}

/**
 * Require a specific module permission — show access denied if not permitted
 */
function require_permission($permission) {
    if (!has_permission($permission)) {
        // Don't redirect to index.php — that could loop. Show inline access-denied.
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><title>Access Denied</title>';
        echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">';
        echo '<script src="https://cdn.tailwindcss.com"></script></head>';
        echo '<body class="font-[Inter] bg-slate-950 text-white min-h-screen flex items-center justify-center">';
        echo '<div class="text-center max-w-md"><div class="w-20 h-20 mx-auto mb-6 rounded-2xl bg-red-500/20 flex items-center justify-center">';
        echo '<svg class="w-10 h-10 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg></div>';
        echo '<h1 class="text-2xl font-black mb-2">Access Denied</h1>';
        echo '<p class="text-slate-400 mb-6">You do not have permission to access this module. Contact your administrator to request access.</p>';
        echo '<div class="flex gap-3 justify-center">';
        echo '<a href="index.php" class="inline-block px-6 py-3 bg-violet-600 hover:bg-violet-500 text-white font-bold rounded-xl transition-all">← Back to Dashboard</a>';
        echo '<a href="../auth/logout.php" class="inline-block px-6 py-3 bg-white/10 hover:bg-white/20 border border-white/10 text-white font-bold rounded-xl transition-all">Sign Out</a>';
        echo '</div>';
        echo '</div></body></html>';
        exit;
    }
}

/**
 * Get client IDs assigned to a user
 */
function get_user_clients($user_id, $company_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT uc.client_id FROM user_clients uc JOIN clients c ON c.id = uc.client_id AND c.deleted_at IS NULL WHERE uc.user_id = ? AND c.company_id = ?");
    $stmt->execute([$user_id, $company_id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Get clients visible to the current user
 * Admin roles see all clients; other users see only assigned clients
 */
function get_clients_for_user($company_id, $user_id = null) {
    global $pdo;
    $user_id = $user_id ?? ($_SESSION['user_id'] ?? 0);

    if (is_admin_role()) {
        return get_clients($company_id);
    }

    $stmt = $pdo->prepare(
        "SELECT c.*, (SELECT COUNT(*) FROM client_outlets co WHERE co.client_id = c.id AND co.deleted_at IS NULL) as outlet_count
         FROM clients c
         JOIN user_clients uc ON uc.client_id = c.id AND uc.user_id = ?
         WHERE c.company_id = ? AND c.deleted_at IS NULL
         ORDER BY c.name"
    );
    $stmt->execute([$user_id, $company_id]);
    return $stmt->fetchAll();
}

/**
 * Set permissions for a user (replace all)
 */
function set_user_permissions($user_id, $permissions, $granted_by) {
    global $pdo;
    // Delete existing
    $stmt = $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ?");
    $stmt->execute([$user_id]);
    // Insert new
    $stmt = $pdo->prepare("INSERT INTO user_permissions (user_id, permission, granted_by) VALUES (?, ?, ?)");
    foreach ($permissions as $perm) {
        $stmt->execute([$user_id, $perm, $granted_by]);
    }
}

/**
 * Set client assignments for a user (replace all)
 */
function set_user_clients($user_id, $client_ids, $assigned_by) {
    global $pdo;
    // Delete existing
    $stmt = $pdo->prepare("DELETE FROM user_clients WHERE user_id = ?");
    $stmt->execute([$user_id]);
    // Insert new
    $stmt = $pdo->prepare("INSERT INTO user_clients (user_id, client_id, assigned_by) VALUES (?, ?, ?)");
    foreach ($client_ids as $cid) {
        $stmt->execute([$user_id, $cid, $assigned_by]);
    }
}

// =====================================================
// SUBSCRIPTION ENFORCEMENT
// =====================================================

/**
 * Get the active subscription for a company.
 * Returns the subscription row merged with plan config defaults.
 * Cached per-request in a static variable.
 */
function get_company_subscription($company_id = null) {
    static $cache = [];
    $company_id = $company_id ?? ($_SESSION['company_id'] ?? 0);
    if (isset($cache[$company_id])) return $cache[$company_id];

    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT * FROM company_subscriptions WHERE company_id = ? ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$company_id]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sub) {
        // No subscription row — treat as starter
        $sub = [
            'plan_name' => 'starter',
            'status'    => 'active',
            'max_users' => 2,
            'max_outlets' => 2,
            'max_products' => 20,
            'max_departments' => 1,
            'max_clients' => 1,
            'data_retention_days' => 90,
            'billing_cycle' => 'monthly',
            'pdf_export' => 0,
            'viewer_role' => 0,
            'station_audit' => 0,
        ];
    }

    // Merge plan config for convenience
    $sub['plan'] = get_plan_config($sub['plan_name']);
    $cache[$company_id] = $sub;
    return $sub;
}

/**
 * Get the current company's plan key.
 */
function get_current_plan() {
    $sub = get_company_subscription();
    return $sub['plan_name'] ?? 'starter';
}

/**
 * Check if the current subscription includes a module.
 */
function subscription_has_module($module) {
    $plan = get_current_plan();
    return plan_includes_module($plan, $module);
}

/**
 * Require subscription access to a module.
 * Shows a branded "Upgrade Required" page if the module is not in the plan.
 * Call this AFTER require_login() and BEFORE require_permission().
 */
function require_subscription($module) {
    // Super-admin / platform owner bypass
    if (get_user_role() === 'super_admin') return;

    // Pages ALWAYS accessible (even when expired)
    $always_allowed = ['dashboard', 'billing', 'support', 'company_setup'];
    if (in_array($module, $always_allowed)) return;

    $sub = get_company_subscription();
    $plan = $sub['plan'] ?? get_plan_config('starter');

    // Auto-expire if past expiry date
    $current_status = $sub['status'] ?? '';
    if (in_array($current_status, ['active', 'trial']) && !empty($sub['expires_at'])) {
        if (strtotime($sub['expires_at']) < time()) {
            global $pdo;
            $company_id = $_SESSION['company_id'] ?? 0;
            
            if ($current_status === 'trial') {
                // Trial expired → downgrade to starter (free plan)
                require_once __DIR__ . '/../config/subscription_plans.php';
                $starter = get_plan_config('starter');
                $pdo->prepare("
                    UPDATE company_subscriptions SET 
                        status = 'expired', plan_name = 'starter',
                        max_users = ?, max_clients = ?, max_outlets = ?,
                        max_products = ?, max_departments = ?, data_retention_days = ?
                    WHERE company_id = ?
                ")->execute([
                    (int)$starter['max_users'], (int)$starter['max_clients'],
                    (int)$starter['max_outlets'], (int)$starter['max_products'],
                    (int)$starter['max_departments'], (int)$starter['data_retention_days'],
                    $company_id
                ]);
                log_audit($company_id, 0, 'trial_expired', 'billing', 0, 'Trial period expired — downgraded to Starter');
            } else {
                // Active subscription expired
                $pdo->prepare("UPDATE company_subscriptions SET status = 'expired' WHERE company_id = ?")
                    ->execute([$company_id]);
            }
            $sub['status'] = 'expired';
        }
    }

    // Check subscription status
    if (in_array($sub['status'] ?? '', ['expired', 'suspended'])) {
        $was_trial = ($current_status === 'trial');
        show_subscription_expired_page($plan, $was_trial);
        exit;
    }

    // Check module access
    if (!in_array($module, $plan['modules'])) {
        show_upgrade_required_page($module, $plan);
        exit;
    }
}

/**
 * Check if a tab within a module is allowed by the current plan.
 * Returns true if allowed, false if locked.
 */
function subscription_allows_tab($module, $tab) {
    if (get_user_role() === 'super_admin') return true;
    $plan_key = get_current_plan();
    return plan_allows_tab($plan_key, $module, $tab);
}

/**
 * Check if a feature flag is enabled on the current plan.
 * @param string $flag  e.g. 'pdf_export', 'viewer_role', 'station_audit'
 */
function subscription_has_feature($flag) {
    if (get_user_role() === 'super_admin') return true;
    $sub = get_company_subscription();
    return !empty($sub[$flag]) || !empty($sub['plan'][$flag]);
}

/**
 * Get the data retention cutoff date for the current plan.
 * Returns null if unlimited.
 */
function get_subscription_retention_cutoff() {
    $sub = get_company_subscription();
    $days = (int)($sub['data_retention_days'] ?? 90);
    if ($days <= 0) return null;
    return date('Y-m-d', strtotime("-{$days} days"));
}

// ── Limit-check helpers ──

/**
 * Check a resource count against the subscription limit.
 * Returns ['allowed' => bool, 'current' => int, 'max' => int]
 * Max of 0 means unlimited.
 */
function check_subscription_limit($limit_key, $current_count) {
    $sub = get_company_subscription();
    $max = (int)($sub[$limit_key] ?? 0);
    if ($max <= 0) return ['allowed' => true, 'current' => $current_count, 'max' => 0];
    return [
        'allowed' => $current_count < $max,
        'current' => $current_count,
        'max'     => $max,
    ];
}

function check_user_limit($company_id = null) {
    global $pdo;
    $company_id = $company_id ?? $_SESSION['company_id'];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE company_id = ? AND deleted_at IS NULL AND is_active = 1");
    $stmt->execute([$company_id]);
    return check_subscription_limit('max_users', (int)$stmt->fetchColumn());
}

function check_client_limit($company_id = null) {
    global $pdo;
    $company_id = $company_id ?? $_SESSION['company_id'];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE company_id = ? AND deleted_at IS NULL");
    $stmt->execute([$company_id]);
    return check_subscription_limit('max_clients', (int)$stmt->fetchColumn());
}

function check_outlet_limit($company_id = null) {
    global $pdo;
    $company_id = $company_id ?? $_SESSION['company_id'];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM client_outlets WHERE company_id = ? AND deleted_at IS NULL");
    $stmt->execute([$company_id]);
    return check_subscription_limit('max_outlets', (int)$stmt->fetchColumn());
}

function check_product_limit($company_id = null, $client_id = null) {
    global $pdo;
    $company_id = $company_id ?? $_SESSION['company_id'];
    $client_id = $client_id ?? get_active_client();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE company_id = ? AND client_id = ? AND deleted_at IS NULL");
    $stmt->execute([$company_id, $client_id]);
    return check_subscription_limit('max_products', (int)$stmt->fetchColumn());
}

function check_department_limit($company_id = null, $client_id = null) {
    global $pdo;
    $company_id = $company_id ?? $_SESSION['company_id'];
    $client_id = $client_id ?? get_active_client();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_departments WHERE company_id = ? AND client_id = ? AND deleted_at IS NULL");
    $stmt->execute([$company_id, $client_id]);
    return check_subscription_limit('max_departments', (int)$stmt->fetchColumn());
}

// ── Upgrade / Expired pages ──

function show_upgrade_required_page($module, $plan) {
    $all_plans = get_all_plans();
    $module_label = get_all_permissions()[$module]['label'] ?? ucfirst($module);
    $current_label = $plan['label'] ?? 'Starter';

    http_response_code(403);
    echo '<!DOCTYPE html><html><head><title>Upgrade Required — MIAUDITOPS</title>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">';
    echo '<script src="https://cdn.tailwindcss.com"></script>';
    echo '<script src="https://unpkg.com/lucide@latest"></script>';
    echo '</head><body class="font-[Inter] bg-slate-950 text-white min-h-screen flex items-center justify-center p-6">';
    echo '<div class="max-w-lg text-center">';

    // Icon
    echo '<div class="w-20 h-20 mx-auto mb-6 rounded-2xl bg-gradient-to-br from-violet-500/30 to-amber-500/30 flex items-center justify-center">';
    echo '<i data-lucide="lock" class="w-10 h-10 text-amber-400"></i></div>';

    // Message
    echo '<h1 class="text-3xl font-black mb-2">Upgrade Required</h1>';
    echo '<p class="text-slate-400 mb-1">The <span class="text-white font-semibold">' . htmlspecialchars($module_label) . '</span> module is not included in your current plan.</p>';
    echo '<p class="text-slate-500 text-sm mb-8">You are on the <span class="font-bold text-violet-400">' . htmlspecialchars($current_label) . '</span> plan.</p>';

    // Plan comparison mini-cards
    echo '<div class="grid grid-cols-3 gap-3 mb-8">';
    foreach ($all_plans as $key => $p) {
        $active = ($key === ($plan['label'] ? strtolower($plan['label']) : 'starter'));
        $border = $active ? 'border-violet-500 bg-violet-500/10' : 'border-slate-700 bg-slate-900/50 hover:border-slate-600';
        $has_module = in_array($module, $p['modules']);
        echo '<div class="p-4 rounded-xl border ' . $border . ' text-center transition-all">';
        echo '<div class="text-xs uppercase tracking-wider text-slate-500 mb-1">' . $p['label'] . '</div>';
        if ($active) echo '<div class="text-[10px] text-violet-400 mb-1">Current</div>';
        echo '<div class="text-lg">' . ($has_module ? '✅' : '🔒') . '</div>';
        echo '</div>';
    }
    echo '</div>';

    // Buttons
    echo '<div class="flex gap-3 justify-center">';
    echo '<a href="index.php" class="px-6 py-3 bg-white/10 hover:bg-white/20 border border-white/10 text-white font-bold rounded-xl transition-all">← Dashboard</a>';
    echo '<a href="settings.php" class="px-6 py-3 bg-gradient-to-r from-violet-600 to-amber-500 hover:from-violet-500 hover:to-amber-400 text-white font-bold rounded-xl transition-all">Upgrade Plan</a>';
    echo '</div>';

    echo '</div>';
    echo '<script>lucide.createIcons();</script>';
    echo '</body></html>';
    exit;
}

function show_subscription_expired_page($plan, $was_trial = false) {
    http_response_code(403);
    $title = $was_trial ? 'Trial Period Ended' : 'Subscription Expired';
    $message = $was_trial 
        ? 'Your free trial has ended. Upgrade to a paid plan to continue using all features, or stay on the free Starter plan with limited access.'
        : 'Your subscription has expired or been suspended. Please settle your outstanding invoice to continue using MIAUDITOPS.';
    $cta_text = $was_trial ? 'Upgrade Now' : 'Go to Billing';
    
    echo '<!DOCTYPE html><html><head><title>' . $title . ' — MIAUDITOPS</title>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">';
    echo '<script src="https://cdn.tailwindcss.com"></script>';
    echo '<script src="https://unpkg.com/lucide@latest"></script>';
    echo '</head><body class="font-[Inter] bg-slate-950 text-white min-h-screen flex items-center justify-center p-6">';
    echo '<div class="max-w-md text-center">';
    echo '<div class="w-20 h-20 mx-auto mb-6 rounded-2xl bg-' . ($was_trial ? 'amber' : 'red') . '-500/20 flex items-center justify-center">';
    echo '<i data-lucide="' . ($was_trial ? 'clock' : 'alert-triangle') . '" class="w-10 h-10 text-' . ($was_trial ? 'amber' : 'red') . '-400"></i></div>';
    echo '<h1 class="text-3xl font-black mb-2">' . $title . '</h1>';
    echo '<p class="text-slate-400 mb-4">' . $message . '</p>';
    echo '<p class="text-sm text-slate-500 mb-8">You can still access the <strong class="text-white">Dashboard</strong>, <strong class="text-white">Billing</strong>, <strong class="text-white">Support</strong>, and <strong class="text-white">Company Setup</strong>.</p>';
    echo '<div class="flex gap-3 justify-center">';
    echo '<a href="index.php" class="px-6 py-3 bg-white/10 hover:bg-white/20 border border-white/10 text-white font-bold rounded-xl transition-all">← Dashboard</a>';
    echo '<a href="billing.php" class="px-6 py-3 bg-gradient-to-r from-emerald-600 to-teal-500 hover:from-emerald-500 hover:to-teal-400 text-white font-bold rounded-xl transition-all">' . $cta_text . '</a>';
    echo '</div>';
    echo '</div>';
    echo '<script>lucide.createIcons();</script>';
    echo '</body></html>';
    exit;
}

?>
