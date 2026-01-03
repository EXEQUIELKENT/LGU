<?php
session_start();
<<<<<<< HEAD
require __DIR__ . '/db.php';

$firstName = $_SESSION['employee_first_name'] ?? 'User';

=======
>>>>>>> 048455f66d273420c27e240de9cca7cfa7ba0ac0
if (!isset($_SESSION['employee_logged_in']) || $_SESSION['employee_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

// Handle logout request
if (isset($_GET['logout'])) {
    // Clear all session data
    session_unset();
    session_destroy();

    // Redirect to login page
    header("Location: login.php");
    exit;
}
<<<<<<< HEAD

// Fetch reports
$sql = "SELECT * FROM reports ORDER BY id DESC";
$result = $conn->query($sql);

?>



=======
?>

>>>>>>> 048455f66d273420c27e240de9cca7cfa7ba0ac0
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Maintenance Reports</title>

<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap');
*{margin:0;padding:0;box-sizing:border-box;font-family:'Poppins',sans-serif;}

body{
    height:100vh;
    background:url("cityhall.jpeg") center/cover no-repeat fixed;
    position:relative;
}
body::before{
    content:"";
    position:absolute;
    inset:0;
    backdrop-filter:blur(6px);
    background:rgba(0,0,0,0.35);
    z-index:0;
}

/* Sidebar Navigation */
.sidebar-nav {
    position: fixed;
    top: 0;
    left: 0;
    width: 250px;
    height: 100vh;
    background: rgba(255, 255, 255, 0.795);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    border-bottom: 1px solid rgba(255, 255, 255, 0.25);  /* glowing border */
    box-shadow: 0 4px 25px rgba(0,0,0,0.25);
    color: #fff;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 0;
    z-index: 1000;
}

/* Top area: logo + nav links */
.sidebar-top {
    display: flex;
    flex-direction: column;
    flex-grow: 1;
    padding: 20px 0;
    overflow-y: auto;
}

/* LGU Logo */
.sidebar-nav .site-logo {
    margin-top: 5px;
    flex-direction: column;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    padding-bottom: 5px;
    width: calc(100% - 50px);
    margin-left: 25px;
    margin-right: 25px;
    box-sizing: border-box;
    margin-bottom: 20px;
    color: #fff;
}

.sidebar-nav .site-logo img {
    width: 120px;
    height: auto;
    object-fit: contain;
    border-radius: 10px;
}

/* Navigation Links */
.sidebar-nav .nav-list {
    list-style: none;
    font-size: 14px;
    padding: 0 20px;
    margin: 0;
    display: flex;
    flex-direction: column;
    flex-grow: 1;
}

.sidebar-nav .nav-list li {
    width: 100%;
    margin: 3px 0;
}

.sidebar-nav .nav-link {
    display: flex;
    align-items: center;
    gap: 12px;
    color: #000000;
    text-decoration: none;
    padding: 12px 20px;
    transition: all 0.3s ease;
    border-radius: 8px;
}

.sidebar-nav .nav-link.active,
.sidebar-nav .nav-link.active:hover {
    background: #3762c8; /* slightly lighter */
    color: #fff;
    transform: translateX(2px);
}

.sidebar-nav .nav-link:hover {
    background: #97a4c2; /* slightly lighter */
    transform: translateX(8px) scale(1.02);
}

/* Divider */
.sidebar-divider {
    border-bottom: 2px solid rgba(0, 0, 0, 0.551);
    width: calc(100% - 50px);
    margin: 20px 25px 0 25px;
}

/* User info at bottom */
.sidebar-nav .user-info {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 20px 0;
    border-top: 1px solid rgba(255,255,255,0.2);
}

.sidebar-nav .user-welcome,
.sidebar-nav .user-rights {
    text-align: center;
    color: #000000;
    font-weight: 600;
    font-size: 0.95rem;
    margin-bottom: 5px;
}

.sidebar-nav .logout-btn {
    background: #3762c8; /* slightly lighter */
    border: 1px solid rgba(255,255,255,0.3);
    color: #fff;
    padding: 8px 12px;
    border-radius: 6px;
    cursor: pointer;
    transition: 0.3s ease;
}

.sidebar-nav .logout-btn:hover {
    background: #3762c8; /* slightly lighter */
    color: #fff;
    transform: translateY(-2px) scale(1.02);
}

/* CONTENT */
.main-content{
    margin-left:250px;
    padding:60px 80px;
    position:relative;
    z-index:1;
}
.page-title{color:#fff;font-size:28px;margin-bottom:25px}
.card{
    background:rgba(255,255,255,.9);
    border-radius:22px;
    padding:30px;
    box-shadow:0 10px 30px rgba(0,0,0,.25);
}

/* TABLE */
<<<<<<< HEAD
=======
/* TABLE */
>>>>>>> 048455f66d273420c27e240de9cca7cfa7ba0ac0
table {
    width: 100%;
    border-collapse: separate; /* IMPORTANT */
    border-spacing: 0;
}

thead {
    background: #3762c8;
    color: #fff;
}

thead th {
    padding: 14px;
    font-size: 14px;
    text-align: left;
}

/* Rounded corners for TH */
thead th:first-child {
    border-top-left-radius: 12px;
}

thead th:last-child {
    border-top-right-radius: 12px;
}
th,td{padding:14px;font-size:14px;text-align:left}
tbody tr{border-bottom:1px solid rgba(0,0,0,.1)}
tbody tr:hover{background:rgba(55,98,200,.08)}

.status{padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600}
.completed{background:#a5d6a7;color:#1b5e20}
.on-going{background:#fff59d;color:#f57f17}
</style>
</head>

<body>

<div class="sidebar-nav">
    <div class="sidebar-top">
        <div class="site-logo">
            <img src="logocityhall.png" alt="LGU Logo">
    <div class="sidebar-divider"></div>
        </div>
        <ul class="nav-list">
            <li><a href="employee.php" class="nav-link">Dashboard</a></li>
            <li><a href="requests.php" class="nav-link">Requests</a></li>
            <li><a href="#" class="nav-link active">Reports</a></li>
            <li><a href="sched.php" class="nav-link">Schedule</a></li>
        </ul>
    </div>

    <div class="sidebar-divider"></div>

    <div class="user-info">
<<<<<<< HEAD
        <div class="user-welcome">Welcome, <?= htmlspecialchars($firstName) ?></div>
=======
        <div class="user-welcome">Welcome, User</div>
>>>>>>> 048455f66d273420c27e240de9cca7cfa7ba0ac0
    <button id="logoutBtn" class="logout-btn">Logout</button>
    </div>
</div>

<div class="main-content">
    <h2 class="page-title">Maintenance Reports</h2>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>Report ID</th>
                    <th>Infrastructure</th>
                    <th>Location</th>
                    <th>Work Done</th>
                    <th>Date Completed</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
<<<<<<< HEAD

            <?php if ($result->num_rows > 0): ?>
                <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td>#REP-<?php echo $row['id']; ?></td>
                    <td><?php echo htmlspecialchars($row['infrastructure']); ?></td>
                    <td><?php echo htmlspecialchars($row['location']); ?></td>
                    <td><?php echo htmlspecialchars($row['work_done']); ?></td>
                    <td><?php echo $row['date_completed']; ?></td>
                    <td>
                        <span class="status <?php echo $row['status'] === 'Completed' ? 'completed' : 'on-going'; ?>">
                            <?php echo $row['status']; ?>
                        </span>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6" style="text-align:center;">No reports found</td>
                </tr>
            <?php endif; ?>

=======
                <tr>
                    <td>#REP-101</td>
                    <td>Road</td>
                    <td>Barangay San Juan</td>
                    <td>Pothole Patching</td>
                    <td>2025-04-20</td>
                    <td><span class="status on-going">On-Going</span></td>
                </tr>
                <tr>
                    <td>#REP-102</td>
                    <td>Drainage</td>
                    <td>Market Area</td>
                    <td>Cleaning & Desilting</td>
                    <td>2025-04-22</td>
                    <td><span class="status completed">Completed</span></td>
                </tr>
>>>>>>> 048455f66d273420c27e240de9cca7cfa7ba0ac0
            </tbody>
        </table>
    </div>
</div>

<script>
    const logoutBtn = document.getElementById('logoutBtn');

    logoutBtn.addEventListener('click', () => {
        if (confirm('Are you sure you want to logout?')) {
            // Redirect to logout handler
            window.location.href = 'employee.php?logout=1';
        }
    });
</script>

</body>
</html>
