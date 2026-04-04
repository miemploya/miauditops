<?php
/**
 * Get Import Detail — Returns a single import with records and breakdown
 * GET: ?id=<import_id>
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid import ID']);
    exit;
}

try {
    // Get import
    $stmt = $pdo->prepare("SELECT * FROM hotel_imports WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $company_id]);
    $import = $stmt->fetch();

    if (!$import) {
        http_response_code(404);
        echo json_encode(['error' => 'Import not found']);
        exit;
    }

    // Get records
    $stmt = $pdo->prepare("SELECT * FROM hotel_import_records WHERE import_id = ? ORDER BY id");
    $stmt->execute([$id]);
    $records = $stmt->fetchAll();

    // Get breakdown
    $stmt = $pdo->prepare("SELECT * FROM hotel_import_breakdown WHERE import_id = ? ORDER BY subtotal DESC");
    $stmt->execute([$id]);
    $breakdown = $stmt->fetchAll();

    // Get company settings
    $stmt = $pdo->prepare("SELECT * FROM hotel_settings WHERE company_id = ? LIMIT 1");
    $stmt->execute([$company_id]);
    $settings = $stmt->fetch();

    echo json_encode([
        'success' => true,
        'import' => $import,
        'records' => $records,
        'breakdown' => $breakdown,
        'settings' => $settings
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
