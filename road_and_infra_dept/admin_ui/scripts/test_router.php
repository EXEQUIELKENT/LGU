<?php
// Test the router functionality
session_start();

// Include helper functions
require_once __DIR__ . '/helpers/functions.php';

// Include SimpleAuth
require_once __DIR__ . '/user_and_access_management_module/SimpleAuth.php';

echo "<h2>Router Test</h2>";

echo "<h3>Session Status</h3>";
echo "<pre>";
echo "Session Data:\n";
print_r($_SESSION);
echo "</pre>";

echo "<h3>Authentication Test</h3>";
$auth = new SimpleAuth();
echo "<p>Is Logged In: " . ($auth->isLoggedIn() ? 'Yes' : 'No') . "</p>";
echo "<p>Is Admin: " . (hasRole('admin') ? 'Yes' : 'No') . "</p>";
echo "<p>Is Engineer: " . (hasRole('engineer') ? 'Yes' : 'No') . "</p>";

echo "<h3>Available Routes</h3>";
$routes = [
    'dashboard' => 'dashboard.php',
    'login' => 'login.php',
    'logout' => 'logout.php',
    'admin' => 'user_and_access_management_module/admin/',
    'damage_report' => 'road_damage_reporting_module/road_damage.html',
    'cost_assessment' => 'damage_assesment_and_cost_estiation_module/damage_and_cost_dashboard.php',
    'gis_mapping' => 'gis_mapping_and_visualization_module/mapping.php',
    'inspection' => 'inspection_and_workflow_module/inspection_and_workflow.html',
    'documents' => 'document_and_report_management_module/damage_and_report_management.php',
    'transparency' => 'public_transparency_module/public_transparency.html'
];

foreach ($routes as $route => $path) {
    echo "<p><strong>$route</strong> → $path</p>";
}

echo "<h3>Test Links</h3>";
echo "<ul>";
echo "<li><a href='index.php?page=dashboard'>Dashboard</a></li>";
echo "<li><a href='index.php?page=login'>Login</a></li>";
echo "<li><a href='index.php?page=admin'>Admin</a></li>";
echo "<li><a href='index.php?page=damage_report'>Damage Report</a></li>";
echo "<li><a href='index.php?page=cost_assessment'>Cost Assessment</a></li>";
echo "<li><a href='index.php?page=gis_mapping'>GIS Mapping</a></li>";
echo "<li><a href='index.php?page=inspection'>Inspection</a></li>";
echo "<li><a href='index.php?page=documents'>Documents</a></li>";
echo "<li><a href='index.php?page=transparency'>Transparency</a></li>";
echo "</ul>";

echo "<h3>File Existence Check</h3>";
foreach ($routes as $route => $path) {
    $fullPath = __DIR__ . '/' . $path;
    $exists = file_exists($fullPath) ? '✅' : '❌';
    echo "<p>$exists <strong>$route</strong>: $path</p>";
}
?>
