<?php
// Test redirect paths
echo "<h1>Redirect Path Test</h1>";

$base_path = "/LGU-kristine/road_and_infra_dept/user_and_access_management_module";

$paths = [
    'admin' => $base_path . "/admin/dashboard.php",
    'lgu_officer' => $base_path . "/lgu_officer/dashboard.html",
    'engineer' => $base_path . "/engineer/dashboard.html",
    'citizen' => $base_path . "/citizen/dashboard.html"
];

echo "<h2>Current Redirect Paths:</h2>";
echo "<ul>";
foreach ($paths as $role => $path) {
    echo "<li><strong>$role:</strong> <a href='$path'>$path</a></li>";
}
echo "</ul>";

echo "<h2>File Existence Check:</h2>";
echo "<ul>";
foreach ($paths as $role => $path) {
    $file_path = __DIR__ . "/../" . str_replace($base_path . "/", "", $path);
    $exists = file_exists($file_path);
    $status = $exists ? "✅ Exists" : "❌ Missing";
    echo "<li><strong>$role:</strong> $status</li>";
}
echo "</ul>";

echo "<h2>Current Directory:</h2>";
echo "<p><strong>__DIR__:</strong> " . __DIR__ . "</p>";
echo "<p><strong>Document Root:</strong> " . $_SERVER['DOCUMENT_ROOT'] . "</p>";
echo "<p><strong>Request URI:</strong> " . $_SERVER['REQUEST_URI'] . "</p>";
?>
