<?php
// Minimal PHP test - no dependencies
header('Content-Type: text/html');
?>
<!DOCTYPE html>
<html>
<head>
    <title>PHP Status Check</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .success { color: green; font-size: 18px; }
        .error { color: red; font-size: 18px; }
        .info { background: #f0f0f0; padding: 10px; margin: 10px 0; }
    </style>
</head>
<body>
    <h1>PHP Status Check</h1>
    
    <?php if (phpversion()): ?>
        <div class="success">✅ PHP is Working!</div>
        <div class="info">
            <strong>PHP Version:</strong> <?php echo phpversion(); ?><br>
            <strong>Current Time:</strong> <?php echo date('Y-m-d H:i:s'); ?><br>
            <strong>Server:</strong> <?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?>
        </div>
        
        <h2>Next Steps:</h2>
        <ol>
            <li>Update login.php to use dashboard_hybrid.php</li>
            <li>Test the hybrid dashboard</li>
            <li>If working, enable full database features</li>
        </ol>
        
        <p><a href="dashboard_hybrid.php">Go to Hybrid Dashboard</a></p>
        
    <?php else: ?>
        <div class="error">❌ PHP is NOT Working</div>
        <div class="info">
            <h3>Solution Steps:</h3>
            <ol>
                <li>Open XAMPP Control Panel</li>
                <li>Stop Apache service</li>
                <li>Wait 3 seconds</li>
                <li>Start Apache service</li>
                <li>Refresh this page</li>
            </ol>
        </div>
    <?php endif; ?>
    
    <div class="info">
        <strong>Debug Info:</strong><br>
        Document Root: <?php echo $_SERVER['DOCUMENT_ROOT'] ?? 'Not set'; ?><br>
        Request URI: <?php echo $_SERVER['REQUEST_URI'] ?? 'Not set'; ?><br>
        Script Name: <?php echo $_SERVER['SCRIPT_NAME'] ?? 'Not set'; ?>
    </div>
</body>
</html>
