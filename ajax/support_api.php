<?php
/**
 * MIAUDITOPS — Support Ticket API
 * Endpoints for enterprise client support ticket management
 */
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../config/db.php';
require_once '../config/subscription_plans.php';
require_once '../includes/functions.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user_id = $_SESSION['user_id'];
$company_id = $_SESSION['company_id'];

switch ($action) {

    // ── Submit a Support Ticket ──
    case 'submit_ticket':
        // Gate: Enterprise plan only
        $plan = get_current_plan();
        if ($plan !== 'enterprise') {
            echo json_encode(['success' => false, 'message' => 'Support Services is available exclusively for Enterprise plan subscribers. Please upgrade to access this feature.']);
            break;
        }

        $category = in_array($_POST['category'] ?? '', ['complaint','enquiry','request','support']) ? $_POST['category'] : 'enquiry';
        $subject  = trim($_POST['subject'] ?? '');
        $message  = trim($_POST['message'] ?? '');

        if (empty($subject) || empty($message)) {
            echo json_encode(['success' => false, 'message' => 'Subject and message are required.']);
            break;
        }

        if (strlen($subject) > 255) {
            echo json_encode(['success' => false, 'message' => 'Subject must be 255 characters or less.']);
            break;
        }

        $stmt = $pdo->prepare("INSERT INTO support_tickets (company_id, user_id, category, subject, message) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$company_id, $user_id, $category, $subject, $message]);
        echo json_encode(['success' => true, 'message' => 'Your support ticket has been submitted. We will respond shortly.']);
        break;

    // ── List Tickets for Company ──
    case 'list_tickets':
        $stmt = $pdo->prepare("
            SELECT t.*, 
                   u.first_name, u.last_name
            FROM support_tickets t
            JOIN users u ON u.id = t.user_id
            WHERE t.company_id = ?
            ORDER BY t.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$company_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $rows]);
        break;

    // ── View Single Ticket ──
    case 'view_ticket':
        $id = (int)($_POST['ticket_id'] ?? $_GET['ticket_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT t.*, u.first_name, u.last_name
            FROM support_tickets t
            JOIN users u ON u.id = t.user_id
            WHERE t.id = ? AND t.company_id = ?
        ");
        $stmt->execute([$id, $company_id]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ticket) {
            echo json_encode(['success' => false, 'message' => 'Ticket not found.']);
            break;
        }
        echo json_encode(['success' => true, 'data' => $ticket]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
}
