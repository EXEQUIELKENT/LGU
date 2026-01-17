<?php
// Example of bulk rejecting pending users
require_once 'config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_reject'])) {
    try {
        $database = new Database();
        $conn = $database->getConnection();
        
        $stmt = $conn->prepare("UPDATE users SET status = 'rejected' WHERE status = 'pending'");
        $stmt->execute();
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        
        echo "Rejected $affectedRows pending users successfully!";
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    }
}
?>

<form method="POST">
    <button type="submit" name="bulk_reject" value="1" 
            onclick="return confirm('Reject ALL pending users? This cannot be undone.')">
        Reject All Pending Users
    </button>
</form>
