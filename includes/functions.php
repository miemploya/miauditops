<?php
/**
 * MIAUDITOPS — Core Helper Functions
 */

require_once dirname(__DIR__) . '/config/db.php';

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
function register_company_and_user($company_name, $email, $password, $first_name, $last_name) {
    global $pdo;
    
    try {
        $pdo->beginTransaction();
        
        // Generate company code
        $code = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $company_name), 0, 4));
        $code .= rand(100, 999);
        
        // Create company
        $stmt = $pdo->prepare("INSERT INTO companies (name, code, email) VALUES (?, ?, ?)");
        $stmt->execute([$company_name, $code, $email]);
        $company_id = $pdo->lastInsertId();
        
        // Create admin user
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (company_id, first_name, last_name, email, password, role) VALUES (?, ?, ?, ?, ?, 'business_owner')");
        $stmt->execute([$company_id, $first_name, $last_name, $email, $hashed_password]);
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
        
        // Log the registration
        log_audit($company_id, $user_id, 'company_registered', 'core', $company_id, "Company '$company_name' registered");
        
        $pdo->commit();
        
        return ['company_id' => $company_id, 'user_id' => $user_id, 'code' => $code];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Register new company and admin user via Google OAuth
 */
function register_company_and_user_google($company_name, $email, $google_id, $first_name, $last_name, $avatar_url = '') {
    global $pdo;
    
    try {
        $pdo->beginTransaction();
        
        // Generate company code
        $code = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $company_name), 0, 4));
        $code .= rand(100, 999);
        
        // Create company
        $stmt = $pdo->prepare("INSERT INTO companies (name, code, email) VALUES (?, ?, ?)");
        $stmt->execute([$company_name, $code, $email]);
        $company_id = $pdo->lastInsertId();
        
        // Create admin user (no password — Google-authenticated)
        $stmt = $pdo->prepare("INSERT INTO users (company_id, first_name, last_name, email, google_id, avatar_url, role) VALUES (?, ?, ?, ?, ?, ?, 'business_owner')");
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
        
        log_audit($company_id, $user_id, 'company_registered_google', 'core', $company_id, "Company '$company_name' registered via Google OAuth");
        
        $pdo->commit();
        
        return ['company_id' => $company_id, 'user_id' => $user_id, 'code' => $code];
        
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

?>
