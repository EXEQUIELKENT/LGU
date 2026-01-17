<?php
// Start session and include authentication
session_start();
require_once '../config/auth.php';

// Require admin role to access this page
$auth->requireRole('admin');

// Log page access
$auth->logActivity('page_access', 'Accessed user permission management');

// Get filter parameters
$filterRole = isset($_GET['role']) ? $_GET['role'] : '';

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'update_role') {
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $newRole = filter_input(INPUT_POST, 'role', FILTER_SANITIZE_STRING);

    if ($userId && $newRole && in_array($newRole, ['citizen', 'engineer', 'lgu_officer', 'admin'])) {
      try {
        $database = new Database();
        $conn = $database->getConnection();

        $stmt = $conn->prepare("UPDATE users SET role = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->bind_param("si", $newRole, $userId);

        if ($stmt->execute()) {
          $message = "User role updated successfully";
          $messageType = "success";
          $auth->logActivity('role_update', "Updated role for user ID: $userId to $newRole");
        } else {
          $message = "Failed to update user role";
          $messageType = "error";
        }

        $stmt->close();
      } catch (Exception $e) {
        error_log("Error updating user role: " . $e->getMessage());
        $message = "An error occurred while updating user role";
        $messageType = "error";
      }
    }
  }

  if ($action === 'update_status') {
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $newStatus = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);

    if ($userId && $newStatus && in_array($newStatus, ['pending', 'active', 'inactive', 'suspended'])) {
      try {
        $database = new Database();
        $conn = $database->getConnection();

        $stmt = $conn->prepare("UPDATE users SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->bind_param("si", $newStatus, $userId);

        if ($stmt->execute()) {
          $message = "User status updated successfully";
          $messageType = "success";
          $auth->logActivity('status_update', "Updated status for user ID: $userId to $newStatus");
        } else {
          $message = "Failed to update user status";
          $messageType = "error";
        }

        $stmt->close();
      } catch (Exception $e) {
        error_log("Error updating user status: " . $e->getMessage());
        $message = "An error occurred while updating user status";
        $messageType = "error";
      }
    }
  }

  if ($action === 'approve_user') {
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

    if ($userId) {
      try {
        $database = new Database();
        $conn = $database->getConnection();

        $stmt = $conn->prepare("UPDATE users SET status = 'active', email_verified = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->bind_param("i", $userId);

        if ($stmt->execute()) {
          $message = "User account approved successfully";
          $messageType = "success";
          $auth->logActivity('user_approval', "Approved user ID: $userId");
        } else {
          $message = "Failed to approve user account";
          $messageType = "error";
        }

        $stmt->close();
      } catch (Exception $e) {
        error_log("Error approving user: " . $e->getMessage());
        $message = "An error occurred while approving user";
        $messageType = "error";
      }
    }
  }

  if ($action === 'delete_user') {
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

    if ($userId) {
      try {
        $database = new Database();
        $conn = $database->getConnection();

        // Prevent deletion of admin users or self
        if ($userId == $auth->getUserId()) {
          $message = "You cannot delete your own account";
          $messageType = "error";
        } else {
          // Check if user is admin before deletion
          $checkStmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
          $checkStmt->bind_param("i", $userId);
          $checkStmt->execute();
          $result = $checkStmt->get_result();
          $userToDelete = $result->fetch_assoc();

          if ($userToDelete && $userToDelete['role'] === 'admin') {
            $message = "You cannot delete admin accounts";
            $messageType = "error";
          } else {
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param("i", $userId);

            if ($stmt->execute()) {
              $message = "User account deleted successfully";
              $messageType = "success";
              $auth->logActivity('user_deletion', "Deleted user ID: $userId");
            } else {
              $message = "Failed to delete user account";
              $messageType = "error";
            }
            $stmt->close();
          }
          $checkStmt->close();
        }

      } catch (Exception $e) {
        error_log("Error deleting user: " . $e->getMessage());
        $message = "An error occurred while deleting user";
        $messageType = "error";
      }
    }
  }

  if ($action === 'view') {
    $database = new Database();
    $conn = $database->getConnection();
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    // Add a hidden div for AJAX
    echo '<div id="ajaxUserData" style="display:none;">' . json_encode($user) . '</div>';
  }

}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>LGU User Permission Management</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
  <style>
    @import url("https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap");

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: "Poppins", sans-serif;
    }

    body {
      height: 100vh;
      background: url("../sidebar/assets/img/cityhall.jpeg") center/cover no-repeat fixed;
      position: relative;
      overflow: hidden;
    }

    body::before {
      content: "";
      position: absolute;
      inset: 0;
      backdrop-filter: blur(6px);
      background: rgba(0, 0, 0, 0.35);
      z-index: 0;
    }

    .main-content {
      position: relative;
      margin-left: 250px;
      height: 100vh;
      z-index: 1;
      padding: 2rem;
      overflow-y: auto;
    }

    .page-header {
      grid-column: span 2;
      color: white;
      margin-bottom: 10px;
    }

    .page-header h1 {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .page-header h1 i {
      font-size: 1.4rem;
      opacity: 0.9;
    }

    .user-info {
      position: absolute;
      top: 1rem;
      right: 1rem;
      background: rgba(255, 255, 255, 0.85);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 8px;
      padding: 0.75rem 1rem;
      font-size: 0.875rem;
    }

    .logout-btn {
      background: #dc2626;
      color: white;
      border: none;
      padding: 0.5rem 1rem;
      border-radius: 6px;
      cursor: pointer;
      margin-left: 0.5rem;
      text-decoration: none;
      display: inline-block;
    }

    .logout-btn:hover {
      background: #b91c1c;
    }

    .message {
      padding: 1rem;
      border-radius: 8px;
      margin-bottom: 1.5rem;
      font-weight: 500;
    }

    .message.success {
      background: #d4edda;
      color: #155724;
      border: 1px solid #c3e6cb;
    }

    .message.error {
      background: #f8d7da;
      color: #721c24;
      border: 1px solid #f5c6cb;
    }

    .users-table {
      background: rgba(255, 255, 255, 0.85);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 12px;
      padding: 1.5rem;
      margin-top: 2rem;
      overflow-x: auto;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 1rem;
    }

    th,
    td {
      padding: 0.75rem;
      text-align: left;
      border-bottom: 1px solid rgba(0, 0, 0, 0.1);
    }

    th {
      background: rgba(0, 0, 0, 0.05);
      font-weight: 600;
      color: #1e293b;
    }

    .status-badge {
      padding: 0.25rem 0.75rem;
      border-radius: 12px;
      font-size: 0.75rem;
      font-weight: 500;
    }

    .status-active {
      background: #d4edda;
      color: #155724;
    }

    .status-pending {
      background: #fff3cd;
      color: #856404;
    }

    .status-inactive {
      background: #f8d7da;
      color: #721c24;
    }

    .status-suspended {
      background: #e2e3e5;
      color: #383d41;
    }

    .status-rejected {
      background: #dc2626;
      color: white;
    }

    .approve-btn {
      background: #059669;
      color: white;
      border: none;
      padding: 0.25rem 0.75rem;
      border-radius: 4px;
      font-size: 0.75rem;
      cursor: pointer;
      margin-right: 0.25rem;
    }

    .approve-btn:hover {
      background: #047857;
    }

    .reject-btn {
      background: #dc2626;
      color: white;
      border: none;
      padding: 0.25rem 0.75rem;
      border-radius: 4px;
      font-size: 0.75rem;
      cursor: pointer;
    }

    .reject-btn:hover {
      background: #b91c1c;
    }

    .view-btn {
      background: #6366f1;
      color: white;
      border: none;
      padding: 0.25rem 0.75rem;
      border-radius: 4px;
      font-size: 0.75rem;
      cursor: pointer;
    }

    .view-btn:hover {
      background: #4f46e5;
    }

    .delete-btn {
      background: #dc2626;
      color: white;
      border: none;
      padding: 0.25rem 0.75rem;
      border-radius: 4px;
      font-size: 0.75rem;
      cursor: pointer;
    }

    .delete-btn:hover {
      background: #b91c1c;
    }

    .action-dropdown {
      position: relative;
      display: inline-block;
    }

    .dropdown-content {
      display: none;
      position: absolute;
      background: white;
      min-width: 160px;
      box-shadow: 0px 8px 16px 0px rgba(0, 0, 0, 0.2);
      z-index: 1;
      border-radius: 4px;
      border: 1px solid #e5e7eb;
    }

    .dropdown-content a {
      color: black;
      padding: 8px 12px;
      text-decoration: none;
      display: block;
      font-size: 0.75rem;
      cursor: pointer;
    }

    .dropdown-content a:hover {
      background: #f3f4f6;
    }

    .dropdown-content .approve {
      color: #059669;
    }

    .dropdown-content .reject {
      color: #dc2626;
    }

    .dropdown-content .update {
      color: #2563eb;
    }

    .dropdown-content .delete {
      color: #dc2626;
    }

    .show {
      display: block;
    }

    .action-buttons {
      display: flex;
      gap: 0.5rem;
      align-items: center;
    }

    .role-badge {
      padding: 0.25rem 0.75rem;
      border-radius: 12px;
      font-size: 0.75rem;
      font-weight: 500;
    }

    .role-admin {
      background: #dc2626;
      color: white;
    }

    .role-engineer {
      background: #2563eb;
      color: white;
    }

    .role-lgu_officer {
      background: #059669;
      color: white;
    }

    .role-citizen {
      background: #6b7280;
      color: white;
    }

    .action-form {
      display: inline-block;
      margin-left: 0.5rem;
    }

    .action-form select,
    .action-form button {
      padding: 0.25rem 0.5rem;
      border-radius: 4px;
      border: 1px solid rgba(0, 0, 0, 0.2);
      font-size: 0.75rem;
    }

    .action-form button {
      background: #2563eb;
      color: white;
      border: none;
      cursor: pointer;
      margin-left: 0.25rem;
    }

    .action-form button:hover {
      background: #1d4ed8;
    }

    .section {
      background: #f8fafc;
      padding: 20px;
      border-radius: 14px;
      display: flex;
      flex-direction: column;
    }

    .section h3 {
      font-size: 15px;
      color: #1e40af;
      margin-bottom: 14px;
      border-bottom: 1px solid #dbeafe;
      padding-bottom: 6px;
    }

    .detail {
      font-size: 13px;
      margin-bottom: 8px;
      display: flex;
      justify-content: space-between;
    }

    .detail span {
      font-weight: 500;
      color: #334155;
    }

    .id-photo {
      width: 100%;
      max-width: 300px;
      margin-top: 8px;
      border-radius: 8px;
      border: 1px solid #e2e8f0;
    }

    .modal-buttons {
      display: flex;
      gap: 12px;
      justify-content: flex-end;
      margin-top: 24px;
      padding-top: 20px;
      border-top: 1px solid #e2e8f0;
    }

    .btn-accept {
      padding: 10px 24px;
      border-radius: 8px;
      border: none;
      font-weight: 600;
      color: #fff;
      background: linear-gradient(135deg, #10b981, #059669);
      cursor: pointer;
      font-size: 0.9rem;
      transition: all 0.2s;
    }

    .btn-accept:hover {
      background: linear-gradient(135deg, #059669, #047857);
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    }

    .btn-reject {
      padding: 10px 24px;
      border-radius: 8px;
      border: none;
      font-weight: 600;
      color: #fff;
      background: linear-gradient(135deg, #ef4444, #dc2626);
      cursor: pointer;
      font-size: 0.9rem;
      transition: all 0.2s;
    }

    .btn-reject:hover {
      background: linear-gradient(135deg, #dc2626, #b91c1c);
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
    }

    .modal {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.5);
      z-index: 9999;
      justify-content: center;
      align-items: center;
      backdrop-filter: blur(4px);
    }

    .modal-content {
      background: white;
      padding: 2rem;
      border-radius: 12px;
      width: 90%;
      max-width: 600px;
      max-height: 90vh;
      overflow-y: auto;
      position: relative;
      box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    }

    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.5rem;
      padding-bottom: 1rem;
      border-bottom: 1px solid #e2e8f0;
    }

    .modal-header h2 {
      font-size: 1.5rem;
      font-weight: 600;
      color: #1e293b;
    }

    .close-btn {
      background: none;
      border: none;
      font-size: 1.5rem;
      cursor: pointer;
      color: #64748b;
      padding: 0;
      width: 32px;
      height: 32px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 6px;
      transition: all 0.2s;
    }

    .close-btn:hover {
      background: #f1f5f9;
      color: #1e293b;
    }

    .user-detail {
      display: flex;
      justify-content: space-between;
      padding: 0.75rem 0;
      border-bottom: 1px solid #f1f5f9;
      font-size: 0.9375rem;
    }

    .user-detail:last-of-type {
      border-bottom: none;
    }

    .user-detail strong {
      color: #475569;
      font-weight: 600;
      min-width: 120px;
    }

    .user-detail span {
      color: #1e293b;
      text-align: right;
      flex: 1;
    }

    .user-photo {
      margin-top: 1rem;
      text-align: center;
    }

    .user-photo strong {
      display: block;
      margin-bottom: 0.5rem;
      color: #475569;
      font-weight: 600;
      font-size: 0.875rem;
    }

    .user-photo img {
      width: 100%;
      max-width: 300px;
      border-radius: 8px;
      border: 1px solid #e2e8f0;
      margin: 0 auto;
      display: block;
    }

    .no-photo {
      padding: 2rem;
      background: #f8fafc;
      border-radius: 8px;
      border: 1px dashed #cbd5e1;
      color: #64748b;
      text-align: center;
      font-size: 0.875rem;
    }

    .role-filter {
      background: rgba(255, 255, 255, 0.85);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 12px;
      padding: 1rem 1.5rem;
      margin-bottom: 1.5rem;
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .role-filter label {
      font-weight: 500;
      color: #475569;
      font-size: 0.875rem;
    }

    .role-filter select {
      padding: 0.5rem 1rem;
      border: 1px solid rgba(0, 0, 0, 0.2);
      border-radius: 6px;
      font-size: 0.875rem;
      background: white;
      color: #1e293b;
      cursor: pointer;
      min-width: 150px;
    }

    .role-filter select:hover {
      border-color: #2563eb;
    }

    .role-filter select:focus {
      outline: none;
      border-color: #2563eb;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }
  </style>
</head>

<body>
  <!-- Include Sidebar -->
  <?php include '../sidebar/sidebar.php'; ?>

  <div class="main-content">
    <!-- <div class="user-info">
        Welcome, <?php echo htmlspecialchars($auth->getUserFullName(), ENT_QUOTES, 'UTF-8'); ?> 
        (<?php echo htmlspecialchars($auth->getUserRole(), ENT_QUOTES, 'UTF-8'); ?>)
        <a href="../logout.php" class="logout-btn">Logout</a>
      </div> -->

    <header class="page-header">
      <h1 style="font-size: 1.5rem; font-weight: 700">
        <i class="fas fa-user-shield"></i> User Permission Management
      </h1>
      <p style="opacity: 0.8; font-size: 0.9rem">
        Manage user roles and permissions
      </p>

      <!-- Divider -->
      <hr class="divider" />
    </header>

    <?php if ($message): ?>
      <div class="message <?php echo htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
      </div>
    <?php endif; ?>

    <div class="role-filter">
      <label for="roleFilter">Filter by Role:</label>
      <select id="roleFilter" onchange="filterByRole(this.value)">
        <option value="">All Roles</option>
        <option value="admin" <?php echo $filterRole === 'admin' ? 'selected' : ''; ?>>Admin</option>
        <option value="engineer" <?php echo $filterRole === 'engineer' ? 'selected' : ''; ?>>Engineer</option>
        <option value="lgu_officer" <?php echo $filterRole === 'lgu_officer' ? 'selected' : ''; ?>>LGU Officer</option>
        <option value="citizen" <?php echo $filterRole === 'citizen' ? 'selected' : ''; ?>>Citizen</option>
      </select>
    </div>

    <div class="users-table">
      <h3>User Permissions</h3>
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Email</th>
            <th>Role</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php
          try {
            $database = new Database();
            $conn = $database->getConnection();

            // Build query with role filter
            $sql = "SELECT id, first_name, last_name, email, role, status 
                    FROM users";
            $params = [];
            $types = '';

            if (!empty($filterRole)) {
              $sql .= " WHERE role = ?";
              $params[] = $filterRole;
              $types .= 's';
            }

            $sql .= " ORDER BY created_at DESC LIMIT 20";

            $stmt = $conn->prepare($sql);
            if (!empty($params)) {
              $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();

            while ($user = $result->fetch_assoc()) {
              echo '<tr>';
              echo '<td>' . htmlspecialchars($user['id'], ENT_QUOTES, 'UTF-8') . '</td>';
              echo '<td>' . htmlspecialchars($user['first_name'] . ' ' . $user['last_name'], ENT_QUOTES, 'UTF-8') . '</td>';
              echo '<td>' . htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') . '</td>';
              echo '<td><span class="role-badge role-' . htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(ucfirst($user['role']), ENT_QUOTES, 'UTF-8') . '</span></td>';
              echo '<td><span class="status-badge status-' . htmlspecialchars($user['status'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(ucfirst($user['status']), ENT_QUOTES, 'UTF-8') . '</span></td>';
              echo '<td>';
              echo '<div class="action-buttons">';

              // Show view button with dropdown for all users
              echo '<div class="action-dropdown">';
              echo '<button class="view-btn" onclick="toggleDropdown(' . $user['id'] . ')">View Actions ▼</button>';
              echo '<div id="dropdown-' . $user['id'] . '" class="dropdown-content">';

              // View Btn
              echo '<a class="approve" onclick="handleAction(\'view\', ' . $user['id'] . ')">🖼️ View</a>';

              // Approve option (only for pending users)
              if ($user['status'] === 'pending') {
                echo '<a class="approve" onclick="handleAction(\'approve_user\', ' . $user['id'] . ')">✓ Approve</a>';
              }

              // Reject option (only for pending users)
              if ($user['status'] === 'pending') {
                echo '<a class="reject" onclick="handleAction(\'reject_user\', ' . $user['id'] . ')">✗ Reject</a>';
              }

              // // Update role option (for non-pending users)
              // if ($user['status'] !== 'pending') {
              //   echo '<a class="update" onclick="showRoleUpdate(' . $user['id'] . ')">📝 Update Role</a>';
              // }
          
              // Delete option (not for self or admin users)
              if ($user['id'] != $auth->getUserId() && $user['role'] !== 'admin') {
                echo '<a class="delete" onclick="handleAction(\'delete_user\', ' . $user['id'] . ')">🗑️ Delete</a>';
              }


              echo '</div>';
              echo '</div>';

              // Hidden role update form
              echo '<div id="role-form-' . $user['id'] . '" class="role-update-form" style="display:none !important; margin-top: 0.5rem; clear: both;">';
              echo '<form class="action-form" method="POST">';
              echo '<input type="hidden" name="user_id" value="' . htmlspecialchars($user['id'], ENT_QUOTES, 'UTF-8') . '">';
              echo '<select name="role">';
              echo '<option value="citizen" ' . ($user['role'] === 'citizen' ? 'selected' : '') . '>Citizen</option>';
              echo '<option value="engineer" ' . ($user['role'] === 'engineer' ? 'selected' : '') . '>Engineer</option>';
              echo '<option value="lgu_officer" ' . ($user['role'] === 'lgu_officer' ? 'selected' : '') . '>LGU Officer</option>';
              echo '<option value="admin" ' . ($user['role'] === 'admin' ? 'selected' : '') . '>Admin</option>';
              echo '</select>';
              echo '<button type="submit" name="action" value="update_role">Update</button>';
              echo '<button type="button" onclick="hideRoleUpdate(' . $user['id'] . ')">Cancel</button>';
              echo '</form>';
              echo '</div>';

              echo '</div>';
              echo '</td>';
              echo '</tr>';
            }

            $stmt->close();
          } catch (Exception $e) {
            error_log("Error fetching users: " . $e->getMessage());
            echo '<tr><td colspan="6">Error loading users</td></tr>';
          }
          ?>
        </tbody>
      </table>
    </div>
  </div>

  <script>
    function filterByRole(role) {
      const url = new URL(window.location);
      if (role === '') {
        url.searchParams.delete('role');
      } else {
        url.searchParams.set('role', role);
      }
      window.location.href = url.toString();
    }

    function toggleDropdown(userId) {
      // Close all other dropdowns
      const dropdowns = document.getElementsByClassName('dropdown-content');
      for (let i = 0; i < dropdowns.length; i++) {
        if (dropdowns[i].id !== 'dropdown-' + userId) {
          dropdowns[i].classList.remove('show');
        }
      }

      // Toggle current dropdown
      const currentDropdown = document.getElementById('dropdown-' + userId);
      currentDropdown.classList.toggle('show');
    }

    function handleAction(action, userId) {
      if (action === 'view') {
        fetchUser(userId);
        return; // Stop the normal form submission
      }
      if (action === 'approve_user') {
        if (confirm('Approve this user account?')) {
          submitForm(action, userId);
        }
      } else if (action === 'reject_user') {
        if (confirm('Reject this user account?')) {
          submitForm(action, userId);
        }
      } else if (action === 'delete_user') {
        if (confirm('Delete this user account? This action cannot be undone.')) {
          submitForm(action, userId);
        }
      }
    }

    function fetchUser(userId) {
      const formData = new FormData();
      formData.append('action', 'view');
      formData.append('user_id', userId);

      fetch('', { // Current page
        method: 'POST',
        body: formData
      })
        .then(res => res.text()) // Receive the whole page as HTML
        .then(html => {
          // Extract user data from a hidden JSON in the PHP response
          const parser = new DOMParser();
          const doc = parser.parseFromString(html, 'text/html');
          const userDataEl = doc.getElementById('ajaxUserData');
          console.log(html);
          if (userDataEl) {
            const user = JSON.parse(userDataEl.textContent);
            showModal(user);
          } else {
            alert('Failed to fetch user data.');
          }
        })
        .catch(err => console.error(err));
    }

    function showModal(user) {
      const modal = document.getElementById('userModal');
      const content = document.getElementById('modalContent');

      // Determine birthday field (try different possible column names)
      const birthday = user.birthday || user.date_of_birth || user.dob || null;
      const birthdayFormatted = birthday ? new Date(birthday).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
      }) : 'Not provided';

      // Determine photo field (try different possible column names)
      const photo = user.photo || user.profile_photo || user.profile_picture || null;
      const photoHtml = photo ?
        `<img src="${photo}" alt="User Photo" onerror="this.parentElement.innerHTML='<div class=\\'no-photo\\'>Photo not available</div>'">` :
        '<div class="no-photo">No photo uploaded</div>';

      content.innerHTML = `
        <div class="user-detail">
          <strong>User ID</strong>
          <span>${user.id || 'N/A'}</span>
        </div>
        <div class="user-detail">
          <strong>Role</strong>
          <span>${user.role ? user.role.charAt(0).toUpperCase() + user.role.slice(1) : 'N/A'}</span>
        </div>
        <div class="user-detail">
          <strong>First Name</strong>
          <span>${user.first_name || 'N/A'}</span>
        </div>
        <div class="user-detail">
          <strong>Middle Name</strong>
          <span>${user.middle_name || 'N/A'}</span>
        </div>
        <div class="user-detail">
          <strong>Last Name</strong>
          <span>${user.last_name || 'N/A'}</span>
        </div>
        <div class="user-detail">
          <strong>Birthday</strong>
          <span>${birthdayFormatted}</span>
        </div>
        <div class="user-detail">
          <strong>Email</strong>
          <span>${user.email || 'N/A'}</span>
        </div>
        <div class="user-detail">
          <strong>Address</strong>
          <span>${user.address || 'Not provided'}</span>
        </div>
        <div class="user-detail">
          <strong>Registered</strong>
          <span>${user.created_at ? new Date(user.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A'}</span>
        </div>
        <div class="user-photo">
          <strong>Uploaded Photo</strong>
          ${photoHtml}
        </div>
        <div class="modal-buttons">
          <button class="btn-reject" onclick="handleReject(${user.id})">Reject</button>
          <button class="btn-accept" onclick="handleAccept(${user.id})">Accept</button>
        </div>
      `;

      modal.style.display = 'flex';
    }

    function handleAccept(userId) {
      if (confirm('Are you sure you want to accept this user?')) {
        submitForm('approve_user', userId);
      }
    }

    function handleReject(userId) {
      if (confirm('Are you sure you want to reject this user? This will set their status to rejected.')) {
        submitForm('reject_user', userId);
      }
    }

    function closeModal() {
      document.getElementById('userModal').style.display = 'none';
    }

    function submitForm(action, userId) {
      const form = document.createElement('form');
      form.method = 'POST';
      form.style.display = 'none';

      const actionInput = document.createElement('input');
      actionInput.type = 'hidden';
      actionInput.name = 'action';
      actionInput.value = action;

      const userIdInput = document.createElement('input');
      userIdInput.type = 'hidden';
      userIdInput.name = 'user_id';
      userIdInput.value = userId;

      form.appendChild(actionInput);
      form.appendChild(userIdInput);
      document.body.appendChild(form);
      form.submit();
    }

    function showRoleUpdate(userId) {
      // Hide all dropdowns and role forms first
      const dropdowns = document.getElementsByClassName('dropdown-content');
      for (let i = 0; i < dropdowns.length; i++) {
        dropdowns[i].classList.remove('show');
      }

      const roleForms = document.getElementsByClassName('role-update-form');
      for (let i = 0; i < roleForms.length; i++) {
        roleForms[i].style.display = 'none';
      }

      // Show role update form for specific user
      const roleForm = document.getElementById('role-form-' + userId);
      if (roleForm) {
        roleForm.style.display = 'block';
      }
    }

    function hideRoleUpdate(userId) {
      const roleForm = document.getElementById('role-form-' + userId);
      if (roleForm) {
        roleForm.style.display = 'none';
      }
    }

    // Close dropdowns when clicking outside
    window.onclick = function (event) {
      if (!event.target.matches('.view-btn')) {
        const dropdowns = document.getElementsByClassName('dropdown-content');
        for (let i = 0; i < dropdowns.length; i++) {
          dropdowns[i].classList.remove('show');
        }
      }

      // Close modal when clicking outside
      const modal = document.getElementById('userModal');
      if (event.target === modal) {
        closeModal();
      }
    }

    // Close modal with ESC key
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        closeModal();
      }
    });

    console.log('User Permission Management loaded');
  </script>
  <!-- User View Modal -->
  <div id="userModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h2>User Details</h2>
        <button class="close-btn" onclick="closeModal()">&times;</button>
      </div>
      <div id="modalContent">
        <!-- User data will be injected here -->
      </div>
    </div>
  </div>
</body>

</html>