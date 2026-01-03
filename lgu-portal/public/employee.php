<?php
session_start();
require __DIR__ . '/db.php'; // Make sure your db.php connects $conn

$firstName = $_SESSION['employee_first_name'] ?? 'User';

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
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LGU Employee Portal</title>
<link rel="stylesheet" href="style - Copy.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap');

body {
    height: 100vh;
    display: flex;
    flex-direction: column;

    /* NEW — background image + blur */
    background: url("cityhall.jpeg") center/cover no-repeat fixed;
    position: relative;
    overflow: hidden;
}

/* NEW — Blur overlay */
body::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;

    backdrop-filter: blur(6px); /* actual blur */
    background: rgba(0, 0, 0, 0.35); /* dark overlay */
    z-index: 0; /* keeps blur behind content */
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

.main-content {
    margin-left: 250px;
    padding: 30px;

    height: 100vh;
    box-sizing: border-box;

    display: flex;
}


/* Optional: smooth scrolling */
.main-content::-webkit-scrollbar {
    height: 8px;
}

.main-content::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,0.3);
    border-radius: 4px;
}

.main-content::-webkit-scrollbar-track {
    background: rgba(255,255,255,0.1);
}

/* MAIN CONTAINER CARD */
.main-card {
    background: rgba(255, 255, 255, 0.88);
    backdrop-filter: blur(14px);
    border-radius: 26px;

    padding: 40px;
    margin: 20px;

    width: 100%;
    height: calc(100vh - 100px); /* ✅ fills screen even without content */

    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 30px;

    box-sizing: border-box;
    overflow-y: auto;

    box-shadow: 0 12px 35px rgba(0,0,0,0.18);
}



/* Keep inner cards slightly floating */
.main-card .card {
    background: rgba(255, 255, 255, 0.95);
}

.card {
    align-self: start; /* ✅ prevents forced equal height */

    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(12px);
    border-radius: 18px;

    padding: 30px 35px;

    box-shadow: 0 6px 20px rgba(0,0,0,0.2);
    transition: 0.2s;
}


.card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.3);
}

.card h3 {
    margin-bottom: 12px;
}

.card p {
    font-size: 14px;
    color: #000000;
}

/* Buttons */
.btn-primary {
    padding: 10px 20px;
    border-radius: 12px;
    border: none;
    font-weight: 600;
    color: #fff;
    background: linear-gradient(135deg, #6384d2, #285ccd);
    cursor: pointer;
    transition: 0.25s;
}

.btn-primary:hover {
    background: linear-gradient(135deg, #4d76d6, #1651d0);
    transform: translateY(-2px);
}
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
            <li><a href="#" class="nav-link active">Dashboard</a></li>
            <li><a href="requests.php" class="nav-link">Requests</a></li>
            <li><a href="reports.php" class="nav-link">Reports</a></li>
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
    <div class="main-card">

        <div class="card">
            <h3>Pending Requests</h3>
            <p>Track and assign new community maintenance requests submitted by citizens.</p>
            <button class="btn-primary">View Requests</button>
        </div>

        <div class="card">
            <h3>Facility Status</h3>
            <p>Monitor the condition of community infrastructure and update maintenance logs.</p>
            <button class="btn-primary">Update Status</button>
        </div>

        <div class="card">
            <h3>Performance Reports</h3>
            <p>Generate reports on completed requests and ongoing maintenance projects.</p>
            <button class="btn-primary">Generate Report</button>
        </div>

    </div>
</div>

</body>

<script>
    const logoutBtn = document.getElementById('logoutBtn');

    logoutBtn.addEventListener('click', () => {
        if (confirm('Are you sure you want to logout?')) {
            // Redirect to logout handler
            window.location.href = 'employee.php?logout=1';
        }
    });
</script>
</html>
