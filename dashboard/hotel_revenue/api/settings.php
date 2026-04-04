<?php
/**
 * Save/Update Company Settings
 * POST: { company_name, company_address, company_phone, company_email, currency_symbol }
 * GET: returns current settings
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->prepare("SELECT * FROM hotel_settings WHERE company_id = ?");
        $stmt->execute([$company_id]);
        $settings = $stmt->fetch();
        // Fallback to MIIAUDITOPS global company_settings if nothing found? 
        // For simplicity, just return empty array if not set.
        echo json_encode(['success' => true, 'settings' => $settings ?: []]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        $stmt = $pdo->prepare("
            INSERT INTO hotel_settings (company_id, company_name, company_address, company_phone, company_email, currency_symbol)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                company_name = VALUES(company_name),
                company_address = VALUES(company_address),
                company_phone = VALUES(company_phone),
                company_email = VALUES(company_email),
                currency_symbol = VALUES(currency_symbol)
        ");
        $stmt->execute([
            $company_id,
            $input['company_name'] ?? 'Hotel Company',
            $input['company_address'] ?? '',
            $input['company_phone'] ?? '',
            $input['company_email'] ?? '',
            $input['currency_symbol'] ?? '₦'
        ]);

        echo json_encode(['success' => true, 'message' => 'Settings saved']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
