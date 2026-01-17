<?php
// Start session and include authentication
session_start();
require_once '../config/auth.php';

// Require admin role to access this page
$auth->requireRole('admin');

// Log page access
$auth->logActivity('page_access', 'Accessed registered users management');

// Handle view action for modal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'view') {
  $database = new Database();
  $conn = $database->getConnection();
  $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

  if ($userId) {
    try {
      // Get user data - select known columns
      // Optional fields (birthday, photo) will be null if they don't exist, which JavaScript handles
      $stmt = $conn->prepare("
                SELECT id, first_name, middle_name, last_name, email, role, status, 
                       email_verified, phone, address, created_at, last_login
                FROM users 
                WHERE id = ?
            ");
      $stmt->bind_param("i", $userId);
      $stmt->execute();
      $result = $stmt->get_result();
      $user = $result->fetch_assoc();
      $stmt->close();

      if (!$user) {
        echo '<div id="ajaxUserData" style="display:none;">{"error":"User not found"}</div>';
        exit;
      }

      // Add a hidden div for AJAX
      echo '<div id="ajaxUserData" style="display:none;">' . json_encode($user) . '</div>';
      exit;
    } catch (Exception $e) {
      error_log("Error fetching user details: " . $e->getMessage());
      echo '<div id="ajaxUserData" style="display:none;">{"error":"Failed to fetch user data"}</div>';
      exit;
    }
  }
}

// Get filter parameters
$filterRole = isset($_GET['role']) ? $_GET['role'] : '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>LGU Registered Users</title>
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

    .verified-badge {
      padding: 0.25rem 0.75rem;
      border-radius: 12px;
      font-size: 0.75rem;
      font-weight: 500;
    }

    .verified-yes {
      background: #d4edda;
      color: #155724;
    }

    .verified-no {
      background: #f8d7da;
      color: #721c24;
    }

    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 1rem;
      margin-bottom: 2rem;
    }

    .stat-card {
      background: rgba(255, 255, 255, 0.85);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 8px;
      padding: 1rem;
      text-align: center;
    }

    .stat-number {
      font-size: 2rem;
      font-weight: 600;
      color: #2563eb;
    }

    .stat-label {
      font-size: 0.875rem;
      color: #64748b;
      margin-top: 0.25rem;
    }

    .filters {
      background: rgba(255, 255, 255, 0.85);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 12px;
      padding: 1.5rem;
      margin-bottom: 2rem;
    }

    .filters h3 {
      font-size: 1.25rem;
      font-weight: 600;
      margin-bottom: 1rem;
      color: #1e293b;
    }

    .filter-group {
      display: flex;
      gap: 1rem;
      flex-wrap: wrap;
      align-items: center;
    }

    .filter-label {
      font-weight: 500;
      color: #475569;
      font-size: 0.875rem;
    }

    .filter-buttons {
      display: flex;
      gap: 0.5rem;
      flex-wrap: wrap;
    }

    .filter-btn {
      padding: 0.5rem 1rem;
      border: 1px solid rgba(0, 0, 0, 0.1);
      border-radius: 6px;
      background: white;
      color: #475569;
      cursor: pointer;
      font-size: 0.875rem;
      font-weight: 500;
      transition: all 0.2s;
    }

    .filter-btn:hover {
      background: #f1f5f9;
      border-color: #2563eb;
    }

    .filter-btn.active {
      background: #2563eb;
      color: white;
      border-color: #2563eb;
    }

    .view-btn {
      background: #6366f1;
      color: white;
      border: none;
      padding: 0.5rem 1rem;
      border-radius: 6px;
      font-size: 0.875rem;
      cursor: pointer;
      font-weight: 500;
      transition: all 0.2s;
    }

    .view-btn:hover {
      background: #4f46e5;
      transform: translateY(-1px);
      box-shadow: 0 2px 8px rgba(99, 102, 241, 0.3);
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
        <i class="fas fa-users"></i> Registered Users
      </h1>
      <p style="opacity: 0.8; font-size: 0.9rem">
        View and manage all registered users in the system
      </p>

      <!-- Divider -->
      <hr class="divider" />
    </header>

    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-number">
          <?php
          try {
            $database = new Database();
            $conn = $database->getConnection();
            $result = $conn->query("SELECT COUNT(*) as total FROM users");
            $row = $result->fetch_assoc();
            echo htmlspecialchars($row['total'], ENT_QUOTES, 'UTF-8');
          } catch (Exception $e) {
            echo '0';
          }
          ?>
        </div>
        <div class="stat-label">Total Users</div>
      </div>

      <div class="stat-card">
        <div class="stat-number">
          <?php
          try {
            $database = new Database();
            $conn = $database->getConnection();
            $result = $conn->query("SELECT COUNT(*) as total FROM users WHERE status = 'active'");
            $row = $result->fetch_assoc();
            echo htmlspecialchars($row['total'], ENT_QUOTES, 'UTF-8');
          } catch (Exception $e) {
            echo '0';
          }
          ?>
        </div>
        <div class="stat-label">Active Users</div>
      </div>

      <div class="stat-card">
        <div class="stat-number">
          <?php
          try {
            $database = new Database();
            $conn = $database->getConnection();
            $result = $conn->query("SELECT COUNT(*) as total FROM users WHERE email_verified = TRUE");
            $row = $result->fetch_assoc();
            echo htmlspecialchars($row['total'], ENT_QUOTES, 'UTF-8');
          } catch (Exception $e) {
            echo '0';
          }
          ?>
        </div>
        <div class="stat-label">Verified Users</div>
      </div>

      <div class="stat-card">
        <div class="stat-number">
          <?php
          try {
            $database = new Database();
            $conn = $database->getConnection();
            $result = $conn->query("SELECT COUNT(*) as total FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $row = $result->fetch_assoc();
            echo htmlspecialchars($row['total'], ENT_QUOTES, 'UTF-8');
          } catch (Exception $e) {
            echo '0';
          }
          ?>
        </div>
        <div class="stat-label">New Users (30 days)</div>
      </div>
    </div>

    <div class="filters">
      <h3>Filters</h3>
      <div class="filter-group">
        <div style="display: flex; align-items: center; gap: 1rem;">
          <span class="filter-label">Role:</span>
          <div class="filter-buttons">
            <button class="filter-btn <?php echo $filterRole === '' ? 'active' : ''; ?>"
              onclick="setFilter('role', '')">All</button>
            <button class="filter-btn <?php echo $filterRole === 'admin' ? 'active' : ''; ?>"
              onclick="setFilter('role', 'admin')">Admin</button>
            <button class="filter-btn <?php echo $filterRole === 'engineer' ? 'active' : ''; ?>"
              onclick="setFilter('role', 'engineer')">Engineer</button>
            <button class="filter-btn <?php echo $filterRole === 'lgu_officer' ? 'active' : ''; ?>"
              onclick="setFilter('role', 'lgu_officer')">LGU Officer</button>
            <button class="filter-btn <?php echo $filterRole === 'citizen' ? 'active' : ''; ?>"
              onclick="setFilter('role', 'citizen')">Citizen</button>
          </div>
        </div>
      </div>
    </div>

    <div class="users-table">
      <h3>User Registry</h3>
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Email</th>
            <th>Role</th>
            <th>Status</th>
            <th>Verified</th>
            <th>Registered</th>
            <th>Last Login</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php
          try {
            $database = new Database();
            $conn = $database->getConnection();

            // Build query with filters - only show active users
            $sql = "SELECT id, first_name, last_name, email, role, status, email_verified, created_at, last_login 
                    FROM users WHERE status = 'active'";
            $params = [];
            $types = '';

            if (!empty($filterRole)) {
              $sql .= " AND role = ?";
              $params[] = $filterRole;
              $types .= 's';
            }

            $sql .= " ORDER BY created_at DESC LIMIT 50";

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
              echo '<td><span class="verified-badge verified-' . ($user['email_verified'] ? 'yes' : 'no') . '">' . ($user['email_verified'] ? 'Verified' : 'Not Verified') . '</span></td>';
              echo '<td>' . date('M j, Y', strtotime($user['created_at'])) . '</td>';
              echo '<td>' . ($user['last_login'] ? date('M j, Y', strtotime($user['last_login'])) : 'Never') . '</td>';
              echo '<td><button class="view-btn" onclick="viewUser(' . htmlspecialchars($user['id'], ENT_QUOTES, 'UTF-8') . ')">View</button></td>';
              echo '</tr>';
            }

            $stmt->close();
          } catch (Exception $e) {
            error_log("Error fetching users: " . $e->getMessage());
            echo '<tr><td colspan="9">Error loading users</td></tr>';
          }
          ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Modal -->
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

  <script>
    console.log('Registered Users Management loaded');

    function setFilter(type, value) {
      const url = new URL(window.location);
      if (value === '') {
        url.searchParams.delete(type);
      } else {
        url.searchParams.set(type, value);
      }
      window.location.href = url.toString();
    }

    function viewUser(userId) {
      const formData = new FormData();
      formData.append('action', 'view');
      formData.append('user_id', userId);

      fetch('', {
        method: 'POST',
        body: formData
      })
        .then(res => res.text())
        .then(html => {
          const parser = new DOMParser();
          const doc = parser.parseFromString(html, 'text/html');
          const userDataEl = doc.getElementById('ajaxUserData');

          if (userDataEl) {
            const user = JSON.parse(userDataEl.textContent);
            showModal(user);
          } else {
            alert('Failed to fetch user data.');
          }
        })
        .catch(err => {
          console.error('Error:', err);
          alert('An error occurred while fetching user data.');
        });
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
        <div class="user-detail">
          <strong>Last Login</strong>
          <span>${user.last_login ? new Date(user.last_login).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : 'Never'}</span>
        </div>
        <div class="user-photo">
          <strong>Uploaded Photo</strong>
          ${photoHtml}
        </div>
      `;

      modal.style.display = 'flex';
    }

    function closeModal() {
      document.getElementById('userModal').style.display = 'none';
    }

    // Close modal when clicking outside
    window.onclick = function (event) {
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
  </script>
</body>

</html>