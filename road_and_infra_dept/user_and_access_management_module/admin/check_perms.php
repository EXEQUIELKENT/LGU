<?php
require_once '../config/database.php';
$db = new Database();
$pdo = $db->getConnection();

echo "Available permissions:\n";
$query = "SELECT * FROM permissions ORDER BY name";
$stmt = $pdo->prepare($query);
$stmt->execute();
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "- " . $row['name'] . "\n";
}
?>
