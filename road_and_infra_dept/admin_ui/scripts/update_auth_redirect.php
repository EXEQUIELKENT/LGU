<?php
// Script to update Auth redirect to main dashboard

echo "<h2>Updating Auth Redirects</h2>";

// Update new Auth class
$newAuthPath = __DIR__ . '/user_and_access_management_module/backend/Auth.php';
if (file_exists($newAuthPath)) {
    $content = file_get_contents($newAuthPath);
    $newContent = str_replace(
        "header('Location: ../admin/dashboard.php');",
        "header('Location: ../dashboard.php');",
        $content
    );
    $newContent = str_replace(
        "header('Location: ../lgu-portal/dashboard.html');",
        "header('Location: ../dashboard.php');",
        $newContent
    );
    $newContent = str_replace(
        "header('Location: dashboard_updated.php');",
        "header('Location: ../dashboard.php');",
        $newContent
    );
    $newContent = str_replace(
        "header('Location: ../citizen.html');",
        "header('Location: ../dashboard.php');",
        $newContent
    );
    
    file_put_contents($newAuthPath, $newContent);
    echo "<p>✅ Updated new Auth class</p>";
} else {
    echo "<p>❌ New Auth class not found</p>";
}

// Update old Auth class
$oldAuthPath = __DIR__ . '/config/auth.php';
if (file_exists($oldAuthPath)) {
    $content = file_get_contents($oldAuthPath);
    $newContent = str_replace(
        "header('Location: ../admin/dashboard.php');",
        "header('Location: dashboard.php');",
        $content
    );
    $newContent = str_replace(
        "header('Location: ../lgu-portal/dashboard.html');",
        "header('Location: dashboard.php');",
        $newContent
    );
    $newContent = str_replace(
        "header('Location: ../user_and_access_management_module/dashboard_updated.php');",
        "header('Location: dashboard.php');",
        $newContent
    );
    $newContent = str_replace(
        "header('Location: ../citizen.html');",
        "header('Location: dashboard.php');",
        $newContent
    );
    
    file_put_contents($oldAuthPath, $newContent);
    echo "<p>✅ Updated old Auth class</p>";
} else {
    echo "<p>❌ Old Auth class not found</p>";
}

echo "<p><a href='dashboard.php'>Test Main Dashboard</a></p>";
echo "<p><a href='login_updated.php'>Test Login</a></p>";
?>
