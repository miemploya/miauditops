<?php
/**
 * Save Import — Stores extracted data to database
 * POST: { fileName, importDate, notes, records: [{original, roomType, amount}], breakdown: [{roomType, count, subtotal, percentage}] }
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['records'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No records provided']);
    exit;
}

try {
    $pdo->beginTransaction();

    $records = $input['records'];
    $breakdown = $input['breakdown'] ?? [];
    $totalRevenue = array_sum(array_column($records, 'amount'));
    $totalRecords = count($records);
    $totalTypes = count($breakdown);
    $avgRate = $totalRecords > 0 ? $totalRevenue / $totalRecords : 0;

    // Insert import
    $stmt = $pdo->prepare("
        INSERT INTO hotel_imports (company_id, file_name, total_revenue, total_records, total_room_types, average_rate, import_date, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $company_id,
        $input['fileName'] ?? 'Unknown',
        $totalRevenue,
        $totalRecords,
        $totalTypes,
        $avgRate,
        $input['importDate'] ?? date('Y-m-d'),
        $input['notes'] ?? null
    ]);
    $importId = $pdo->lastInsertId();

    // Insert records
    $stmt = $pdo->prepare("INSERT INTO hotel_import_records (import_id, original_text, room_type, amount) VALUES (?, ?, ?, ?)");
    foreach ($records as $r) {
        $stmt->execute([$importId, $r['original'], $r['roomType'], $r['amount']]);
    }

    // Insert breakdown
    $stmt = $pdo->prepare("INSERT INTO hotel_import_breakdown (import_id, room_type, count, subtotal, percentage) VALUES (?, ?, ?, ?, ?)");
    foreach ($breakdown as $b) {
        $stmt->execute([$importId, $b['roomType'], $b['count'], $b['subtotal'], $b['percentage']]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'importId' => (int) $importId,
        'message' => 'Import saved successfully'
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
