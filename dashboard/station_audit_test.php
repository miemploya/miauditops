<?php
/**
 * Station Audit Diagnostic — DELETE THIS FILE AFTER DEBUGGING
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h3>Station Audit Diagnostic</h3>";

// Step 1: functions.php
echo "<p>1. Loading functions.php... ";
try {
    require_once '../includes/functions.php';
    echo "<b style='color:green'>OK</b></p>";
} catch (Throwable $e) {
    echo "<b style='color:red'>FAIL: " . $e->getMessage() . "</b></p>";
    exit;
}

// Step 2: sector_config.php
echo "<p>2. Loading sector_config.php... ";
try {
    require_once '../config/sector_config.php';
    echo "<b style='color:green'>OK</b></p>";
} catch (Throwable $e) {
    echo "<b style='color:red'>FAIL: " . $e->getMessage() . "</b></p>";
    exit;
}

// Step 3: Login check
echo "<p>3. Login check... ";
if (is_logged_in()) {
    echo "<b style='color:green'>Logged in (user " . ($_SESSION['user_id'] ?? '?') . ")</b></p>";
} else {
    echo "<b style='color:orange'>Not logged in (will redirect on real page)</b></p>";
}

// Step 4: DB connection
echo "<p>4. Database... ";
try {
    global $pdo;
    $pdo->query("SELECT 1");
    echo "<b style='color:green'>Connected</b></p>";
} catch (Throwable $e) {
    echo "<b style='color:red'>FAIL: " . $e->getMessage() . "</b></p>";
    exit;
}

// Step 5: Check subscription function
echo "<p>5. require_subscription function exists... ";
echo function_exists('require_subscription') ? "<b style='color:green'>YES</b>" : "<b style='color:red'>NO</b>";
echo "</p>";

// Step 6: Check require_active_client function
echo "<p>6. require_active_client function exists... ";
echo function_exists('require_active_client') ? "<b style='color:green'>YES</b>" : "<b style='color:red'>NO</b>";
echo "</p>";

// Step 7: Check key functions
$fns = ['get_active_client', 'get_client_outlets', 'get_company_subscription', 'get_current_plan', 
        'require_permission', 'check_client_limit', 'check_outlet_limit', 'is_admin_role', 'require_non_viewer'];
foreach ($fns as $fn) {
    echo "<p>7. $fn... ";
    echo function_exists($fn) ? "<b style='color:green'>YES</b>" : "<b style='color:red'>MISSING</b>";
    echo "</p>";
}

// Step 8: PHP memory
echo "<p>8. Memory limit: " . ini_get('memory_limit') . "</p>";
echo "<p>9. File size of station_audit.php: " . number_format(filesize(__DIR__ . '/station_audit.php')) . " bytes</p>";
echo "<p>10. PHP version: " . phpversion() . "</p>";

// Step 9: MySQL version
try {
    $ver = $pdo->query("SELECT VERSION()")->fetchColumn();
    echo "<p>11. MySQL version: $ver</p>";
} catch (Throwable $e) {
    echo "<p>11. MySQL version: error</p>";
}

// Step 10: Try the CREATE TABLE statements
echo "<p>12. Testing CREATE TABLE IF NOT EXISTS... ";
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS station_audit_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, client_id INT NOT NULL, outlet_id INT NOT NULL,
        date_from DATE, date_to DATE, status ENUM('draft','submitted','approved') DEFAULT 'draft',
        created_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        auditor_id INT NULL, auditor_signed_at DATETIME NULL, auditor_comments TEXT NULL,
        manager_id INT NULL, manager_signed_at DATETIME NULL, manager_comments TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "<b style='color:green'>OK</b></p>";
} catch (Throwable $e) {
    echo "<b style='color:red'>FAIL: " . $e->getMessage() . "</b></p>";
}

// Step 11: Check if station_audit_app.js exists
echo "<p>13. station_audit_app.js exists... ";
echo file_exists(__DIR__ . '/station_audit_app.js') ? "<b style='color:green'>YES</b>" : "<b style='color:red'>MISSING</b>";
echo "</p>";

echo "<hr><p><b>If all above are green, the 500 error is likely from the sheer file size (398KB) hitting a memory/execution limit.</b></p>";
echo "<p style='color:red'><b>⚠ DELETE THIS FILE AFTER DEBUGGING!</b></p>";
?>
