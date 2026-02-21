<?php
/**
 * MIAUDITOPS — Notification API
 * Handles marking notifications as read for dashboard users.
 */
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user_id = (int)$_SESSION['user_id'];

switch ($action) {

    case 'mark_notification_read':
        $nid = (int)($_POST['notification_id'] ?? 0);
        if ($nid > 0) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO notification_reads (notification_id, user_id) VALUES (?, ?)");
            $stmt->execute([$nid, $user_id]);
        }
        echo json_encode(['success' => true]);
        break;

    case 'mark_all_read':
        // Mark all active notifications as read for this user
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO notification_reads (notification_id, user_id)
            SELECT id, ? FROM platform_notifications WHERE is_active = 1
        ");
        $stmt->execute([$user_id]);
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
}
?>
