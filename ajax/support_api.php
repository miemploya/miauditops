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
        $ticket_id = $pdo->lastInsertId();
        log_audit($company_id, $user_id, 'submit_ticket', 'support', $ticket_id, "Support ticket submitted: $subject ($category)");
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

    // ── List Replies for a Ticket ──
    case 'list_replies':
        $id = (int)($_POST['ticket_id'] ?? $_GET['ticket_id'] ?? 0);
        // Verify ticket belongs to this company
        $check = $pdo->prepare("SELECT id FROM support_tickets WHERE id = ? AND company_id = ?");
        $check->execute([$id, $company_id]);
        if (!$check->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Ticket not found.']);
            break;
        }
        $stmt = $pdo->prepare("
            SELECT r.*, u.first_name, u.last_name
            FROM ticket_replies r
            JOIN users u ON u.id = r.user_id
            WHERE r.ticket_id = ?
            ORDER BY r.created_at ASC
        ");
        $stmt->execute([$id]);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    // ── Client Reply to Ticket ──
    case 'reply_ticket':
        $ticket_id = (int)($_POST['ticket_id'] ?? 0);
        $message   = trim($_POST['message'] ?? '');

        if (!$ticket_id || empty($message)) {
            echo json_encode(['success' => false, 'message' => 'Ticket ID and message are required.']);
            break;
        }

        // Verify ticket belongs to this company
        $check = $pdo->prepare("SELECT id, status FROM support_tickets WHERE id = ? AND company_id = ?");
        $check->execute([$ticket_id, $company_id]);
        $ticket = $check->fetch(PDO::FETCH_ASSOC);
        if (!$ticket) {
            echo json_encode(['success' => false, 'message' => 'Ticket not found.']);
            break;
        }

        $stmt = $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_id, is_admin, message) VALUES (?, ?, 0, ?)");
        $stmt->execute([$ticket_id, $user_id, $message]);
        $reply_id = $pdo->lastInsertId();

        // Re-open ticket if it was resolved/closed
        if (in_array($ticket['status'], ['resolved', 'closed'])) {
            $pdo->prepare("UPDATE support_tickets SET status = 'open' WHERE id = ?")->execute([$ticket_id]);
        }

        log_audit($company_id, $user_id, 'reply_ticket', 'support', $ticket_id, "Client replied to ticket #$ticket_id");
        echo json_encode(['success' => true, 'reply_id' => $reply_id]);
        break;
}
