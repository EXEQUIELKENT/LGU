<?php
session_start();
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
<title>Maintenance Schedule</title>

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

.schedule-item{
    display:flex;
    justify-content:space-between;
    padding:15px;
    border-bottom:1px solid rgba(0,0,0,.1);
}
.schedule-item:last-child{border-bottom:none}
.schedule-date{font-weight:600;color:#3762c8}
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
            <li><a href="reports.php" class="nav-link">Reports</a></li>
            <li><a href="#" class="nav-link active">Schedule</a></li>
        </ul>
    </div>

    <div class="sidebar-divider"></div>

    <div class="user-info">
        <div class="user-welcome">Welcome, User</div>
    <button id="logoutBtn" class="logout-btn">Logout</button>
    </div>
</div>

<div class="main-content">
    <h2 class="page-title">Maintenance Schedule</h2>

    <div class="card">
        <div class="schedule-item">
            <div>
                <strong>Road Repair</strong><br>
                Barangay San Juan
            </div>
            <div class="schedule-date">April 28, 2025</div>
        </div>

        <div class="schedule-item">
            <div>
                <strong>Street Light Maintenance</strong><br>
                Main Highway
            </div>
            <div class="schedule-date">April 30, 2025</div>
        </div>

        <div class="schedule-item">
            <div>
                <strong>Drainage Cleaning</strong><br>
                Market Area
            </div>
            <div class="schedule-date">May 3, 2025</div>
        </div>
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
