<?php
require_once 'config/db.php';
$stmt = $pdo->query("DESCRIBE station_lube_store_items");
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "--- station_lube_store_items ---\n";
foreach($cols as $c) echo $c['Field'] . " (" . $c['Type'] . ")\n";

$stmt = $pdo->query("DESCRIBE station_lube_items");
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "\n--- station_lube_items ---\n";
foreach($cols as $c) echo $c['Field'] . " (" . $c['Type'] . ")\n";
?>
