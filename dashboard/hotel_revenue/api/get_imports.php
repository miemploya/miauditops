<?php
/**
 * Get Imports — List all saved imports
 * GET: returns array of imports with summary data
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

try {
    $stmt = $pdo->prepare("SELECT * FROM hotel_imports WHERE company_id = ? ORDER BY created_at DESC");
    $stmt->execute([$company_id]);
    $imports = $stmt->fetchAll();

    echo json_encode(['success' => true, 'imports' => $imports]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
