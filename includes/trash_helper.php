<?php
/**
 * MIAUDITOPS — Centralized Trash System
 * 60-day soft-delete with restore capability.
 *
 * Usage:
 *   require_once 'includes/trash_helper.php';
 *   ensure_trash_table($pdo);
 *   purge_expired_trash($pdo);   // auto-cleanup on each request
 */

/**
 * Create the system_trash table if it doesn't exist.
 */
function ensure_trash_table($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_trash (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        item_type VARCHAR(80) NOT NULL COMMENT 'e.g. audit_session, client, outlet, department',
        item_id INT NOT NULL COMMENT 'Original PK of the deleted record',
        item_label VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Human-readable name for UI',
        item_data LONGTEXT NOT NULL COMMENT 'Full JSON snapshot of deleted data + children',
        deleted_by INT DEFAULT NULL,
        deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL COMMENT 'deleted_at + 60 days',
        INDEX idx_company_type (company_id, item_type),
        INDEX idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Move an item to trash.
 *
 * @param PDO    $pdo
 * @param int    $company_id
 * @param string $item_type   e.g. 'audit_session', 'client', 'outlet'
 * @param int    $item_id     Original PK
 * @param string $item_label  Human-readable label for the trash list
 * @param mixed  $item_data   Array/object — will be JSON-encoded
 * @param int    $deleted_by  User ID who performed the delete
 * @return int   Trash record ID
 */
function move_to_trash($pdo, $company_id, $item_type, $item_id, $item_label, $item_data, $deleted_by) {
    $json = is_string($item_data) ? $item_data : json_encode($item_data, JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare("INSERT INTO system_trash
        (company_id, item_type, item_id, item_label, item_data, deleted_by, deleted_at, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 60 DAY))");
    $stmt->execute([$company_id, $item_type, $item_id, $item_label, $json, $deleted_by]);

    return $pdo->lastInsertId();
}

/**
 * Retrieve a trash record and remove it from trash (for restore).
 *
 * @param PDO $pdo
 * @param int $trash_id
 * @param int $company_id  (security: only own company)
 * @return array|null  The trash row with decoded item_data, or null if not found.
 */
function restore_from_trash($pdo, $trash_id, $company_id) {
    $stmt = $pdo->prepare("SELECT * FROM system_trash WHERE id = ? AND company_id = ?");
    $stmt->execute([$trash_id, $company_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) return null;

    // Decode JSON
    $row['item_data'] = json_decode($row['item_data'], true);

    // Remove from trash
    $pdo->prepare("DELETE FROM system_trash WHERE id = ?")->execute([$trash_id]);

    return $row;
}

/**
 * Permanently delete a single trash item.
 */
function permanent_delete_trash($pdo, $trash_id, $company_id) {
    $stmt = $pdo->prepare("DELETE FROM system_trash WHERE id = ? AND company_id = ?");
    $stmt->execute([$trash_id, $company_id]);
    return $stmt->rowCount() > 0;
}

/**
 * Auto-purge: permanently delete all expired trash (older than 60 days).
 * Safe to call on every request — quick indexed query.
 */
function purge_expired_trash($pdo) {
    try {
        $pdo->exec("DELETE FROM system_trash WHERE expires_at < NOW()");
    } catch (Exception $e) {
        // Table may not exist yet on first run; silently ignore
    }
}

/**
 * List trash items for a company, optionally filtered by type.
 *
 * @param PDO    $pdo
 * @param int    $company_id
 * @param string $item_type  Optional filter (e.g. 'audit_session')
 * @return array
 */
function list_trash($pdo, $company_id, $item_type = null) {
    if ($item_type) {
        $stmt = $pdo->prepare("SELECT id, item_type, item_id, item_label, deleted_by, deleted_at, expires_at,
            DATEDIFF(expires_at, NOW()) as days_remaining
            FROM system_trash WHERE company_id = ? AND item_type = ? ORDER BY deleted_at DESC");
        $stmt->execute([$company_id, $item_type]);
    } else {
        $stmt = $pdo->prepare("SELECT id, item_type, item_id, item_label, deleted_by, deleted_at, expires_at,
            DATEDIFF(expires_at, NOW()) as days_remaining
            FROM system_trash WHERE company_id = ? ORDER BY deleted_at DESC");
        $stmt->execute([$company_id]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
