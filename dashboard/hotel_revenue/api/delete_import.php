<?php
/**
 * Delete Import — Remove an import and its related records + breakdown
 * POST: { id: <import_id> }
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$id = intval($input['id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid import ID']);
    exit;
}

try {
    // Check ownership
    $stmt = $pdo->prepare("SELECT id FROM hotel_imports WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $company_id]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Permission denied or import not found']);
        exit;
    }

    $pdo->beginTransaction();
    
    // Delete related records first (cascade)
    $stmt = $pdo->prepare("DELETE FROM hotel_import_records WHERE import_id = ?");
    $stmt->execute([$id]);
    
    // Delete related breakdown
    $stmt = $pdo->prepare("DELETE FROM hotel_import_breakdown WHERE import_id = ?");
    $stmt->execute([$id]);
    
    // Delete the import itself
    $stmt = $pdo->prepare("DELETE FROM hotel_imports WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $company_id]);
    
    $pdo->commit();
    
    echo json_encode(['success' => true, 'message' => 'Import and related data deleted']);
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
