<?php
/**
 * MIAUDITOPS — Company Setup API
 * Handles client & outlet CRUD + active client switching
 */
require_once '../includes/functions.php';
require_once '../config/sector_config.php';
require_login();

$company_id = $_SESSION['company_id'] ?? 0;
$user_id    = $_SESSION['user_id'] ?? 0;

// Validate company exists — auto-correct if session has stale/wrong ID
if ($company_id) {
    $chk = $pdo->prepare("SELECT id FROM companies WHERE id = ?");
    $chk->execute([$company_id]);
    if (!$chk->fetch()) {
        // Session company_id is invalid — look up from user record
        $chk2 = $pdo->prepare("SELECT company_id FROM users WHERE id = ?");
        $chk2->execute([$user_id]);
        $real = $chk2->fetchColumn();
        if ($real) {
            $company_id = $real;
            $_SESSION['company_id'] = $real;
        }
    }
}

// Handle GET request for set_active_client (sidebar dropdown uses GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'set_active_client') {
    $client_id = intval($_GET['client_id'] ?? 0);
    $redirect  = $_GET['redirect'] ?? '';
    
    // Fall back to the referring page so the user stays where they are
    if (empty($redirect)) {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $redirect = $referer ? basename(parse_url($referer, PHP_URL_PATH)) : 'index.php';
    }
    
    // Verify client belongs to company
    $client = get_client($client_id, $company_id);
    if ($client) {
        set_active_client($client_id);
        set_flash_message('success', 'Switched to client: ' . $client['name']);
    } else {
        set_flash_message('error', 'Invalid client selected.');
    }
    redirect('../dashboard/' . basename($redirect));
    exit;
}

// POST requests
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$action = $_POST['action'] ?? '';

// Block write actions for viewer role — only get_outlets is read-only
if ($action !== 'get_outlets') {
    require_non_viewer();
}

try {
    switch ($action) {

        case 'add_client':
            $name     = clean_input($_POST['name'] ?? '');
            $contact  = clean_input($_POST['contact_person'] ?? '');
            $email    = clean_input($_POST['email'] ?? '');
            $phone    = clean_input($_POST['phone'] ?? '');
            $address  = clean_input($_POST['address'] ?? '');
            $industry = clean_input($_POST['industry'] ?? '');
            
            if (empty($name)) {
                echo json_encode(['success' => false, 'message' => 'Client name is required']);
                break;
            }
            
            // ── Subscription limit check ──
            $limit = check_client_limit($company_id);
            if (!$limit['allowed']) {
                echo json_encode(['success' => false, 'message' => "Client limit reached ({$limit['current']}/{$limit['max']}). Upgrade your plan to add more clients."]);
                break;
            }
            
            $code = generate_client_code($company_id, $name);
            
            $stmt = $pdo->prepare("INSERT INTO clients (company_id, name, code, contact_person, email, phone, address, industry) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([$company_id, $name, $code, $contact, $email, $phone, $address, $industry]);
            $client_id = $pdo->lastInsertId();
            
            log_audit($company_id, $user_id, 'client_created', 'setup', $client_id, "Client '$name' created (Code: $code)");
            
            // Auto-set as active if first client
            $clients = get_clients($company_id);
            if (count($clients) === 1) {
                set_active_client($client_id);
            }
            
            echo json_encode(['success' => true, 'id' => $client_id, 'code' => $code, 'message' => "Client '$name' created successfully"]);
            break;

        case 'update_client':
            $id       = intval($_POST['client_id'] ?? 0);
            $name     = clean_input($_POST['name'] ?? '');
            $contact  = clean_input($_POST['contact_person'] ?? '');
            $email    = clean_input($_POST['email'] ?? '');
            $phone    = clean_input($_POST['phone'] ?? '');
            $address  = clean_input($_POST['address'] ?? '');
            $industry = clean_input($_POST['industry'] ?? '');
            
            $stmt = $pdo->prepare("UPDATE clients SET name=?, contact_person=?, email=?, phone=?, address=?, industry=?, updated_at=NOW() WHERE id=? AND company_id=?");
            $stmt->execute([$name, $contact, $email, $phone, $address, $industry, $id, $company_id]);
            
            // Refresh session if this is the active client
            if (get_active_client() == $id) {
                set_active_client($id);
            }
            
            log_audit($company_id, $user_id, 'client_updated', 'setup', $id, "Client '$name' updated");
            echo json_encode(['success' => true, 'message' => 'Client updated']);
            break;

        case 'toggle_client':
            $id = intval($_POST['client_id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE clients SET is_active = NOT is_active WHERE id=? AND company_id=?");
            $stmt->execute([$id, $company_id]);
            log_audit($company_id, $user_id, 'client_toggled', 'setup', $id, "Client activation toggled");
            echo json_encode(['success' => true, 'message' => 'Client status updated']);
            break;

        case 'delete_client':
            $id = intval($_POST['client_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT name FROM clients WHERE id=? AND company_id=? AND deleted_at IS NULL");
            $stmt->execute([$id, $company_id]);
            $client = $stmt->fetch();
            if (!$client) {
                echo json_encode(['success' => false, 'message' => 'Client not found']);
                break;
            }
            // Soft-delete the client and its outlets
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE clients SET deleted_at = NOW(), is_active = 0 WHERE id=? AND company_id=?");
            $stmt->execute([$id, $company_id]);
            $stmt = $pdo->prepare("UPDATE client_outlets SET deleted_at = NOW(), is_active = 0 WHERE client_id=? AND company_id=?");
            $stmt->execute([$id, $company_id]);
            $pdo->commit();
            // Clear active client if it was the deleted one
            if (get_active_client() == $id) {
                unset($_SESSION['active_client_id'], $_SESSION['active_client_name'], $_SESSION['active_client_code']);
            }
            log_audit($company_id, $user_id, 'client_deleted', 'setup', $id, "Client '{$client['name']}' deleted");
            echo json_encode(['success' => true, 'message' => "Client '{$client['name']}' deleted"]);
            break;

        case 'add_outlet':
            $client_id   = intval($_POST['client_id'] ?? 0);
            $name        = clean_input($_POST['name'] ?? '');
            $type        = clean_input($_POST['type'] ?? 'other');
            $code        = clean_input($_POST['code'] ?? '');
            $description = clean_input($_POST['description'] ?? '');
            
            // Auto-append "Outlet" if not already ending with it
            if ($name && !preg_match('/\bOutlet$/i', trim($name))) {
                $name = trim($name) . ' Outlet';
            }
            
            if (empty($name) || !$client_id) {
                echo json_encode(['success' => false, 'message' => 'Outlet name and client are required']);
                break;
            }
            
            // Verify client belongs to company
            $client = get_client($client_id, $company_id);
            if (!$client) {
                echo json_encode(['success' => false, 'message' => 'Invalid client']);
                break;
            }
            
            // ── Subscription limit check ──
            $limit = check_outlet_limit($company_id);
            if (!$limit['allowed']) {
                echo json_encode(['success' => false, 'message' => "Outlet limit reached ({$limit['current']}/{$limit['max']}). Upgrade your plan to add more outlets."]);
                break;
            }
            
            if (empty($code)) {
                $code = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $name), 0, 4));
            }
            
            $stmt = $pdo->prepare("INSERT INTO client_outlets (client_id, company_id, name, code, type, description) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$client_id, $company_id, $name, $code, $type, $description]);
            $outlet_id = $pdo->lastInsertId();
            
            log_audit($company_id, $user_id, 'outlet_created', 'setup', $outlet_id, "Outlet '$name' created for client '{$client['name']}'");
            
            // Auto-create Kitchen departments for Restaurant outlets if sector supports kitchens
            $kitchens_created = 0;
            // Look up the client's sector
            $client_industry = strtolower($client['industry'] ?? 'other');
            $client_sector = get_sector_config($client_industry);
            if ($client_sector['has_kitchen'] && strtolower($type) === 'restaurant') {
                $kitchen_count = max(0, min(3, intval($_POST['kitchen_count'] ?? 1)));
                
                // Check how many kitchens already exist for this client — cap total at 3
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_departments WHERE company_id = ? AND client_id = ? AND type = 'kitchen' AND deleted_at IS NULL");
                $stmt->execute([$company_id, $client_id]);
                $existing_kitchens = (int)$stmt->fetchColumn();
                $allowed = max(0, 3 - $existing_kitchens);
                $kitchen_count = min($kitchen_count, $allowed);
                
                // Number new kitchens starting after the highest existing number
                $start_num = $existing_kitchens + 1;
                for ($i = 0; $i < $kitchen_count; $i++) {
                    $num = $start_num + $i;
                    $kitchen_name = "Kitchen $num Dept";
                    $kitchen_desc = "Kitchen for restaurant outlet '$name' — receives from Main Store, supplies finished goods to restaurants";
                    $stmt = $pdo->prepare("INSERT INTO stock_departments (company_id, client_id, name, type, outlet_id, description, created_by) VALUES (?,?,?,?,?,?,?)");
                    $stmt->execute([$company_id, $client_id, $kitchen_name, 'kitchen', $outlet_id, $kitchen_desc, $user_id]);
                    $kitchen_id = $pdo->lastInsertId();
                    $kitchens_created++;
                    log_audit($company_id, $user_id, 'kitchen_auto_created', 'stock', $kitchen_id, "Kitchen '$kitchen_name' auto-created for restaurant outlet '$name'");
                }
            }
            
            $msg = "Outlet '$name' created";
            if ($kitchens_created > 0) $msg .= ". $kitchens_created kitchen(s) auto-created under Stock Audit.";
            echo json_encode(['success' => true, 'id' => $outlet_id, 'kitchens_created' => $kitchens_created, 'message' => $msg]);
            break;

        case 'update_outlet':
            $id          = intval($_POST['outlet_id'] ?? 0);
            $name        = clean_input($_POST['name'] ?? '');
            $type        = clean_input($_POST['type'] ?? 'other');
            $code        = clean_input($_POST['code'] ?? '');
            $description = clean_input($_POST['description'] ?? '');
            
            $stmt = $pdo->prepare("UPDATE client_outlets SET name=?, code=?, type=?, description=? WHERE id=? AND company_id=?");
            $stmt->execute([$name, $code, $type, $description, $id, $company_id]);
            
            log_audit($company_id, $user_id, 'outlet_updated', 'setup', $id, "Outlet '$name' updated");
            echo json_encode(['success' => true, 'message' => 'Outlet updated']);
            break;

        case 'toggle_outlet':
            $id = intval($_POST['outlet_id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE client_outlets SET is_active = NOT is_active WHERE id=? AND company_id=?");
            $stmt->execute([$id, $company_id]);
            log_audit($company_id, $user_id, 'outlet_toggled', 'setup', $id, "Outlet activation toggled");
            echo json_encode(['success' => true, 'message' => 'Outlet status updated']);
            break;

        case 'delete_outlet':
            $id = intval($_POST['outlet_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT name FROM client_outlets WHERE id=? AND company_id=?");
            $stmt->execute([$id, $company_id]);
            $outlet = $stmt->fetch();
            if (!$outlet) {
                echo json_encode(['success' => false, 'message' => 'Outlet not found']);
                break;
            }
            $stmt = $pdo->prepare("UPDATE client_outlets SET deleted_at = NOW(), is_active = 0 WHERE id=? AND company_id=?");
            $stmt->execute([$id, $company_id]);
            log_audit($company_id, $user_id, 'outlet_deleted', 'setup', $id, "Outlet '{$outlet['name']}' deleted");
            echo json_encode(['success' => true, 'message' => "Outlet '{$outlet['name']}' deleted"]);
            break;

        case 'get_outlets':
            $client_id = intval($_POST['client_id'] ?? 0);
            $outlets = get_client_outlets($client_id);
            echo json_encode(['success' => true, 'outlets' => $outlets]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
