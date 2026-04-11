<?php
require_once 'config/db.php';
$stmt = $pdo->query("DESCRIBE station_audit_settings");
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "--- station_audit_settings ---\n";
foreach($cols as $c) echo $c['Field'] . " (" . $c['Type'] . ")\n";
?>
