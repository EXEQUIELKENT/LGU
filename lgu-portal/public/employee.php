<?php
session_start();
require __DIR__ . '/db.php';

// Notification system
function setNotification($type, $message) {
    $_SESSION['notification'] = [
        'type' => $type,
        'message' => $message
    ];
}
function showNotification() {
    if (!empty($_SESSION['notification'])) {
        $type = $_SESSION['notification']['type'];
        $message = htmlspecialchars($_SESSION['notification']['message']);
        $icon = ($type === 'success') ? '✔️' : (($type === 'error') ? '❌' : (($type === 'warning') ? '⚠️' : 'ℹ️'));
        echo "<div class='notif-popup notif-{$type}' id='notifPopup'>
                <span class='notif-icon'>{$icon}</span>
                <span class='notif-message'>{$message}</span>
                <button class='notif-close' onclick=\"scloseNotif()\">&times;</button>
              </div>";
        unset($_SESSION['notification']);
        echo "<script>
            function closeNotif() {
                var n = document.getElementById('notifPopup'); 
                if(n) n.style.opacity='0';
                setTimeout(()=>{if(n)n.remove();}, 400);
            }
            setTimeout(closeNotif, 2200);
        </script>";
    }
}

$firstName = isset($_SESSION['employee_first_name']) ? $_SESSION['employee_first_name'] : 'User';

// If just logged out redirect from employee page
if (isset($_GET['logout'])) {
    // Set log out notification for next login page
    setNotification('info', 'Successfully logged out.');
    // Clear all session data (but preserve notification)
    $notif = $_SESSION['notification'];
    session_unset();
    session_destroy();
    // Session destroyed, start new to save notification
    session_start();
    $_SESSION['notification'] = $notif;
    header("Location: login.php");
    exit;
}

if (!isset($_SESSION['employee_logged_in']) || $_SESSION['employee_logged_in'] !== true) {
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
<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap');
*{margin:0;padding:0;box-sizing:border-box;font-family:'Poppins',sans-serif;}

/* --- BEGIN: Desktop/mobile blur + stacking + mobile-top-nav visibility fixes --- */

/* HIDE MOBILE TOP NAV ON DESKTOP */
.mobile-top-nav {
    display: none;
}

/* Z-INDEX LAYERING SAFETY: Ensures UI is above background blur for all key elements */
body {
    height: 100vh;
    background: url("cityhall.jpeg") center center / cover no-repeat fixed;
    position: relative;
    z-index: 0;
}
body::before {
    content: "";
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    width: 100vw;
    height: 100vh;
    pointer-events: none;
    backdrop-filter: blur(6px);
    background: rgba(0,0,0,0.35);
    z-index: 0;
}

body::-webkit-scrollbar {
  display: none;
}
.sidebar-nav,
.main-content,
.mobile-top-nav {
    position: relative;
    z-index: 1;
}

/* --- END: Desktop/mobile blur + stacking + mobile-top-nav visibility fixes --- */

/* PROFILE BUTTON */

.sidebar-profile-btn {
    position: absolute;
    top: 18px;
    left: 15px;
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: #fff;
    border: 2px solid #3762c8;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    overflow: hidden;
    transition: all 0.3s ease;
    z-index: 1002;
}
.sidebar-profile-btn img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
/* Hover */

.sidebar-profile-btn:hover {
    transform: scale(1.08);
    box-shadow: 0 4px 14px rgba(55,98,200,0.35);
}

/* COLLAPSED SIDEBAR PROFILE POSITION FIX */
.sidebar-nav.collapsed .sidebar-profile-btn {
    position: relative;       /* removes overlap */
    top: auto;
    left: auto;
    margin: 52px auto 10px;   /* pushes profile BELOW toggle */
}

/* COLLAPSED SIDEBAR LAYOUT PUSH-DOWN */
.sidebar-nav.collapsed .sidebar-top {
    padding-top: 10px;
}

.sidebar-nav {
    position: fixed;
    top: 0;
    left: 0;
    width: 250px;
    height: 100vh;
    background: rgba(255, 255, 255, 0.795);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    border-bottom: 1px solid rgba(255, 255, 255, 0.25);
    box-shadow: 0 4px 25px rgba(0,0,0,0.25);
    color: #fff;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 0;
    z-index: 1000;
    transition: width 0.3s ease, left 0.3s ease;
}
.sidebar-nav.collapsed {
    width: 70px;
}
/* Toggle Button */
.sidebar-toggle {
    position: absolute;
    top: 20px;
    right: 15px;
    width: 32px;
    height: 32px;
    background: #3762c8;
    border: none;
    border-radius: 8px;
    color: #fff;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    transition: all 0.3s ease;
    z-index: 1003;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}
.sidebar-toggle:hover {
    background: #2851b3;
    transform: scale(1.08);
}
.sidebar-nav.collapsed .sidebar-toggle {
    right: 19px;
}
.toggle-icon {
    transition: transform 0.3s ease;
}
.sidebar-nav.collapsed .toggle-icon {
    transform: rotate(180deg);
}

.sidebar-top {
    display: flex;
    flex-direction: column;
    flex-grow: 1;
    padding: 20px 0;
    overflow-y: auto;
    position: relative;
}

/* --------- FIX FOR: "navlinks move at the top of the side bar fix it" ---------
   We will enforce that .sidebar-top always stretches to fill remaining height,
   and position nav-list at the correct vertical position below the logo,
   not at the top after collapse.
   Use a spacer div after .site-logo, then let nav-list and the rest flex naturally.
*/
.sidebar-top {
    display: flex;
    flex-direction: column;
    flex-grow: 1;
    min-height: 0;
    height: 100%;
    /* Ensure that .sidebar-top always fills sidebar height */
}

/* Add a flex spacer below .site-logo to enforce consistent space above nav-list */
.sidebar-logo-spacer {
    height: 16px;
    flex-shrink: 0;
}

/* --------- END FIX --------- */

/* Divider just UNDER the toggle button */
.sidebar-toggle-divider {
    border-bottom: 2px solid rgba(0,0,0,0.18);
    width: 60%;
    margin: 15px auto 9px auto;
    transition: opacity 0.3s, height 0.3s, margin 0.3s;
    opacity: 0;
    height: 0;
    pointer-events: none;
}
.sidebar-nav.collapsed .sidebar-toggle-divider {
    opacity: 1;
    height: 0;
    margin: 15px auto 14px auto;
    pointer-events: auto;
}
/* There is no need for a duplicate .collapse-toggle-divider, replaced with .sidebar-toggle-divider for clear intent */

.sidebar-divider.collapse-toggle-divider { display:none; } /* Remove the old divider line for collapsed */

/* Other existing styles unchanged ... */
/* -- LOGO VISIBILITY ON COLLAPSE -- */
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
    transition: all 0.3s ease;
    overflow: hidden;
}
.sidebar-nav .site-logo img {
    width: 120px;
    height: auto;
    object-fit: contain;
    border-radius: 10px;
    transition: all 0.3s ease, opacity 0.3s ease;
}
.sidebar-nav.collapsed .site-logo {
    margin-left: auto;
    margin-right: auto;
    width: 100%;
    margin-bottom: 0px;
}
.sidebar-nav.collapsed .site-logo img {
    opacity: 1;
    visibility: visible;
    width: 40px;
    height: auto;
}
/* --------- MODIFIED: Make logo-divider visible when collapsed --------- */
.sidebar-divider.logo-divider {
    transition: opacity 0.3s ease, width 0.3s ease, margin 0.3s ease;
    /* always display the divider; style changes below */
    opacity: 1;
    width: calc(100% - 50px);
    margin: 18px 25px 0 25px;
}
.sidebar-nav.collapsed .sidebar-divider.logo-divider {
    opacity: 1;
    width: 40px;
    margin: 5px 25px 0 25px;
}
/* --------- END MODIFICATION --------- */

/* Navigation Links */
/* Move nav-links a bit more to the left (reduce left/right padding) to fit maintenance schedule */
.sidebar-nav .nav-list {
    list-style: none;
    font-size: 14px;
    padding: 0 15px; /* changed from 0 20px to 0 10px */
    margin: 0;
    display: flex;
    flex-direction: column;
    flex-grow: 0;
    flex-shrink: 0;
    /* Ensures the nav-list stays together and never stretches vertically */
    transition: padding 0.3s ease;
}
.sidebar-nav.collapsed .nav-list {
    padding: 0 10px;
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
    white-space: nowrap;
    overflow: hidden;
    position: relative;
    /* The default size for nav links (14px font, 12px top/bottom, some left/right) */
}

.sidebar-nav .nav-link.active,
.sidebar-nav .nav-link.active:hover {
    background: #3762c8;
    color: #fff;
    transform: translateX(2px);
}
.sidebar-nav .nav-link:hover {
    background: #97a4c2;
    transform: translateX(8px) scale(1.02);
}

/* Collapsed sidebar nav link style */
.sidebar-nav.collapsed .nav-link {
    justify-content: center;
    padding: 12px 10px;  /* This is the nav-link size in collapse: 14px font, 12px top/bottom, 10px left/right */
    position: relative;
}
.sidebar-nav.collapsed .nav-link span:last-child {
    display: none;
}
.sidebar-nav.collapsed .nav-link:hover {
    transform: translateX(0) scale(1.08);
}

/* SIDEBAR HOVER TOOLTIP: Shows navlink name as pop-up at side upon hover/collapsed */
.sidebar-tooltip-pop {
    position: fixed;
    z-index: 5555;
    left: 85px;
    top: 0;
    background: #3762c8;
    color: #fff;
    border-radius: 8px;
    padding: 9px 18px;
    font-size: 15px;
    font-weight: 500;
    box-shadow: 0 6px 24px rgba(41,87,179,0.13);
    white-space: nowrap;
    pointer-events: auto;
    opacity: 0;
    transition: opacity 0.24s, transform 0.23s;
    transform: translateY(-50%) scale(0.97);
    display:none;
    letter-spacing: 0.03em;
}

/* Show/animate tooltip */
.sidebar-tooltip-pop.active {
    opacity: 1;
    display: block;
    transform: translateY(-50%) scale(1.03);
}
/* Optional: Add a little arrow - visually aligns tooltip */
.sidebar-tooltip-pop::before {
    content:"";
    position:absolute;
    left:-8px;
    top:50%;
    transform:translateY(-50%);
    border-width:8px 8px 8px 0;
    border-style:solid;
    border-color:transparent #3762c8 transparent transparent;
}

.sidebar-divider {
    border-bottom: 2px solid rgba(0, 0, 0, 0.551);
    width: calc(100% - 50px);
    margin: 20px 25px 0 25px;
    transition: all 0.3s ease;
}
.sidebar-nav.collapsed .sidebar-divider {
    width: calc(100% - 20px);
    margin: 20px 10px 0 10px;
}

.sidebar-nav .user-info {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 20px 0;
    border-top: 1px solid rgba(255,255,255,0.2);
    transition: all 0.3s ease;
}

.sidebar-nav .user-welcome,
.sidebar-nav .user-rights {
    text-align: center;
    color: #000000;
    font-weight: 600;
    font-size: 0.95rem;
    margin-bottom: 5px;
    transition: all 0.3s ease;
    white-space: nowrap;
    overflow: hidden;
}
.sidebar-nav.collapsed .user-welcome {
    display: none;
}

/* --- LOGOUT BUTTON --- */
.sidebar-nav .logout-btn {
    background: #3762c8;
    border: 1px solid rgba(255,255,255,0.3);
    color: #fff;
    padding: 8px 12px;
    border-radius: 6px;
    cursor: pointer;
    transition: 0.3s ease;
    white-space: nowrap;
    font-size: 16px;
    min-width: 0;
}
/* Expanded state is normal, below is collapsed state */
.sidebar-nav.collapsed .logout-btn {
    padding: 12px 4px !important;       /* Match tightened .nav-link in collapsed */
    width: 70%;                         /* Take full available width to match nav-links */
    border-radius: 8px;
    font-size: 0 !important;             /* Hide text like .nav-link */
    justify-content: center;
    align-items: center;
    min-width: 0;
    display: flex;
}
.sidebar-nav.collapsed .logout-btn::before {
    content: "🚪";
    font-size: 20px;
    margin-right: 0;
    display: inline-block;
    line-height: 1;
}
.sidebar-nav .logout-btn:hover {
    background: #3762c8;
    color: #fff;
    transform: translateY(-2px) scale(1.02);
}
.sidebar-nav.collapsed .logout-btn:hover {
    transform: scale(1.08);
    background: #2851b3;
}

/* Ensure the button does not shrink smaller than nav-link when collapsed */
.sidebar-nav.collapsed .logout-btn {
    box-sizing: border-box;
    max-width: 100%;
}

.sidebar-nav.collapsed .logout-btn::after {
    display: none;
}

/* END LOGOUT BUTTON */

#logoutAlertBackdrop {
    position: fixed;
    z-index: 5000;
    inset: 0;
    background: rgba(37, 59, 115, 0.20);
    display: none;
    align-items: center;
    justify-content: center;
    transition: background 0.18s;
}
#logoutAlertBackdrop.active {
    display: flex;
}
#logoutAlertModal {
    background: #fff;
    border-radius: 18px;
    box-shadow: 0 8px 42px rgba(17, 39, 77, 0.15);
    padding: 36px 28px 22px 28px;
    width: 340px;
    max-width: 95vw;
    animation: fadeIn 0.22s cubic-bezier(.6,-0.01,.52,1.23) 1;
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
}
@keyframes fadeIn {
    from{transform:translateY(34px) scale(.95); opacity:.24;}
    to  {transform:translateY(0) scale(1); opacity:1;}
}
#logoutAlertModal .icon-wrap {
    display: flex;
    justify-content: center;
    align-items: center;
    width: 62px;
    height: 62px;
    background: #fdeeed;
    border-radius: 50%;
    margin: 0 auto 13px auto;
    box-shadow: 0 2px 8px 0 rgba(236,82,82,0.11);
}
#logoutAlertModal .icon-wrap .icon {
    color: #e94444;
    font-size: 2.1rem;
    line-height: 1;
}
#logoutAlertModal .alert-title {
    font-size: 1.09rem;
    letter-spacing: 0.04em;
    font-weight: bold;
    color: #23285c;
    text-align: center;
    margin-bottom: 8px;
    margin-top: 6px;
}
#logoutAlertModal .alert-desc {
    color: #374565;
    font-size: 0.99rem;
    text-align: center;
    margin-bottom: 19px;
}
#logoutAlertModal .alert-btns {
    display: flex;
    gap: 15px;
    justify-content: center;
}
#logoutAlertModal .alert-btn {
    min-width: 95px;
    padding: 8px 0;
    border-radius: 7px;
    border: none;
    font-weight: bold;
    font-size: 1rem;
    cursor: pointer;
    transition: background .18s, color .18s;
    outline: none;
}
#logoutAlertModal .alert-btn.cancel {
    background: #f3f4fa;
    color: #353d52;
    border: 1px solid #e3e6f1;
}
#logoutAlertModal .alert-btn.cancel:hover {
    background: #e9eeff;
    color: #3650c7;
    border-color: #c7d1f3;
}
#logoutAlertModal .alert-btn.logout {
    color: #fff;
    background: #e94444;
    border: none;
    box-shadow: 0 3px 14px 0 rgba(236,82,82,0.08);
}
#logoutAlertModal .alert-btn.logout:hover {
    background: #c82d2d;
}

/* Notification Popup Styles (copied from login.php) */
.notif-popup {
    position: fixed;
    top: 30px;
    left: 50%;
    transform: translateX(-50%);
    min-width: 280px;
    max-width: 95vw;
    padding: 18px 32px;
    background: #fff;
    border-radius: 13px;
    box-shadow: 0 8px 38px rgba(34,53,126,0.23);
    z-index: 3001;
    display: flex;
    align-items: center;
    gap: 14px;
    font-family: 'Poppins', Arial, sans-serif;
    font-size: 17px;
    font-weight: 500;
    opacity: 1;
    transition: opacity .35s;
}
.notif-popup .notif-icon { font-size: 23px; }
.notif-popup.notif-success { border-left: 5px solid #4fc97a; }
.notif-popup.notif-error { border-left: 5px solid #d73f52; }
.notif-popup.notif-warning { border-left: 5px solid #dda203; }
.notif-popup.notif-info { border-left: 5px solid #527cdf; }
.notif-popup .notif-close {
    background: none;
    border: none;
    font-size: 20px;
    margin-left: auto;
    color: #888;
    cursor: pointer;
}
.main-content {
    margin-left: 250px;
    padding: 30px;
    height: 100vh;
    box-sizing: border-box;
    display: flex;
    transition: margin-left 0.3s ease;
}
.main-content.expanded {
    margin-left: 70px;
}
.main-content::-webkit-scrollbar { height: 8px; }
.main-content::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,0.3);
    border-radius: 4px;
}
.main-content::-webkit-scrollbar-track {
    background: rgba(255,255,255,0.1);
}
/* MAIN CONTAINER CARD */
.main-card {
    background: rgba(255,255,255,.9);
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
.main-card .card {
    background: rgba(255, 255, 255, 0.95);
}
.main-card::-webkit-scrollbar {
    display: none;
}
.card {
    align-self: start;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(12px);
    border-radius: 18px;
    padding: 30px 35px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.2);
    transition: 0.2s;
    display: flex;
    flex-direction: column;
    gap: 18px;
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
    text-decoration: none;
    text-align: center;
    align-items: center;
}
.btn-primary:hover {
    background: linear-gradient(135deg, #4d76d6, #1651d0);
    transform: translateY(-2px);
    text-decoration: none;
}
/* --- Custom: Logout Tooltip for Collapsed Sidebar --- */
.sidebar-tooltip-pop.logout-pop {
    min-width: 120px;
    max-width: 60vw;
    white-space: normal;
    text-align: center;
    transition: none !important; /* <--- FIX: prevent animation/resize on hide */
}

/* =========================
   MOBILE VIEW ONLY
========================= */
@media (max-width: 768px) {

    /* Show mobile top nav in mobile */
    .mobile-top-nav {
        display: flex;
    }

    /* Hide desktop sidebar initially */
    .sidebar-nav {
        left: -110%;
        width: calc(100% - 24px);
        height: calc(100% - 24px);
        top: 12px;
        bottom: 12px;
        border-radius: 18px;
        transition: left 0.35s ease;
        z-index: 4000;
    }

    /* Show sidebar when active */
    .sidebar-nav.mobile-active {
        left: 12px;
    }

    /* Disable desktop collapse behavior */
    .sidebar-nav.collapsed {
        width: calc(100% - 24px);
    }

    /* Main content always full width */
    .main-content,
    .main-content.expanded {
        margin-left: 0 !important;
        padding-top: 90px;
    }

    /* MOBILE TOP NAV */
    .mobile-top-nav {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 64px;
        background: rgba(255,255,255,0.92);
        backdrop-filter: blur(12px);
        align-items: center;
        justify-content: center;
        z-index: 5000;
        box-shadow: 0 4px 18px rgba(0,0,0,0.2);
    }

    .mobile-top-nav img {
        height: 42px;
        object-fit: contain;
    }

    .mobile-toggle {
        position: absolute;
        left: 16px;
        background: #3762c8;
        color: #fff;
        border: none;
        border-radius: 10px;
        width: 38px;
        height: 38px;
        font-size: 20px;
        cursor: pointer;
    }

    /* Sidebar internal layout for mobile */
    .sidebar-top {
        padding-top: 30px;
    }

    .sidebar-profile-btn {
        position: relative;
        margin: 10px 0 0 15px;
    }

    .site-logo {
        margin: 10px auto 20px auto;
    }

    .nav-list {
        padding: 0 20px;
    }

    .sidebar-divider,
    .sidebar-toggle,
    .sidebar-toggle-divider {
        display: none !important;
    }

    /* Logout stays bottom */
    .user-info {
        padding-bottom: 20px;
    }

    /* Hide desktop toggle */
    .sidebar-toggle {
        display: none;
    }

    /* ===============================
       🚩 MOBILE-ONLY MAIN CONTENT FIXES
       =============================== */

    /* 1️⃣ MAIN CONTENT SCROLLS (allow full height and scroll) */
    .main-content,
    .main-content.expanded {
        height: auto;
        min-height: 100vh;
        overflow-y: auto;           /* allow scrolling */
        padding: 0px;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;            /* Firefox: hide scrollbar but keep scroll */
    }

    /* Hide main-content vertical (right) scrollbar but retain scrollability */
    .main-content::-webkit-scrollbar {
        width: 0 !important;
        background: transparent;
        display: none !important;
    }
    .main-content {
        scrollbar-width: none;           /* Firefox */
        -ms-overflow-style: none;        /* Edge/IE */
    }

    /* 2️⃣ MAIN CARD no forced height; internal scroll not needed */
    .main-card {
        margin-top: 85px;
        padding: 20px;
        border-radius: 18px;
    }
    .main-card::-webkit-scrollbar {
        display: none;
    }

    /* 🧪 OPTIONAL: mobile card tighter padding for small screens */
    .card {
        padding: 22px;
    }
}
</style>
</head>
<body>

<!-- MOBILE TOP NAV -->
<div class="mobile-top-nav">
    <button class="mobile-toggle" id="mobileToggle">☰</button>
    <img src="logocityhall.png" alt="LGU Logo">
</div>

<?php showNotification(); ?>

<div class="sidebar-nav" id="sidebarNav">
    <div class="sidebar-header">
        <button class="sidebar-toggle" id="sidebarToggle">
            <span class="toggle-icon">◀</span>
        </button>
    </div>

    <!-- New Sidebar Top Section -->
    <div class="sidebar-top">

        <!-- Profile Button -->
        <div class="sidebar-profile-btn">
            <img src="profile.png" alt="Profile">
        </div>
        <!-- Logo -->
        <div class="site-logo">
            <img src="logocityhall.png" alt="LGU Logo">
            <div class="sidebar-divider logo-divider"></div>
        </div>
        <div class="sidebar-logo-spacer"></div>
        <!-- Navigation -->
        <ul class="nav-list">
            <li><a href="#" class="nav-link active"><span>📊</span><span>Dashboard</span></a></li>
            <li><a href="requests.php" class="nav-link"><span>📋</span><span>Requests</span></a></li>
            <li><a href="reports.php" class="nav-link"><span>📄</span><span>Reports</span></a></li>
            <li><a href="sched.php" class="nav-link"><span>📅</span><span>Maintenance Schedule</span></a></li>
        </ul>
        <div style="flex-grow:1;"></div>
    </div>

    <div class="sidebar-divider"></div>

    <div class="user-info">
        <div class="user-welcome">Welcome, <?= htmlspecialchars($firstName) ?></div>
        <button id="logoutBtn" class="logout-btn" data-tooltip="Log out">Logout</button>
    </div>
</div>

<!-- Tooltip container for sidebar nav-links and logout -->
<div id="sidebarNavTooltip" class="sidebar-tooltip-pop"></div>
<!-- Extra: We don't need a separate logout tooltip container, we reuse sidebarNavTooltip -->

<!-- Logout Confirmation Alert Modal (Redesigned based on sched.php) -->
<div id="logoutAlertBackdrop">
    <div id="logoutAlertModal">
        <div class="icon-wrap">
            <span class="icon">&#9888;</span>
        </div>
        <div class="alert-title">Log out of your account?</div>
        <div class="alert-desc">Are you sure you want to log out? Any ongoing activity will be ended.</div>
        <div class="alert-btns">
            <button class="alert-btn cancel" id="logoutCancelBtn">Cancel</button>
            <button class="alert-btn logout" id="logoutConfirmBtn">Log out</button>
        </div>
    </div>
</div>

<div class="main-content">
    <div class="main-card">

        <div class="card">
            <h3>Pending Requests</h3>
            <p>Track and assign new community maintenance requests submitted by citizens.</p>
            <a href="requests.php" class="btn-primary">View Requests</a>
        </div>

        <div class="card">
            <h3>Facility Status</h3>
            <p>Monitor the condition of community infrastructure and update maintenance logs.</p>
            <a href="reports.php" class="btn-primary">Update Status</a>
        </div>

        <div class="card">
            <h3>Performance Reports</h3>
            <p>Generate reports on completed requests and ongoing maintenance projects.</p>
            <a href="reports.php" class="btn-primary">Generate Report</a>
        </div>

    </div>
</div>

<!-- Logout Confirmation Alert Modal (Redesigned based on sched.php) -->
<div id="logoutAlertBackdrop">
    <div id="logoutAlertModal">
        <div class="icon-wrap">
            <span class="icon">&#9888;</span>
        </div>
        <div class="alert-title">Log out of your account?</div>
        <div class="alert-desc">Are you sure you want to log out? Any ongoing activity will be ended.</div>
        <div class="alert-btns">
            <button class="alert-btn cancel" id="logoutCancelBtn">Cancel</button>
            <button class="alert-btn logout" id="logoutConfirmBtn">Log out</button>
        </div>
    </div>
</div>

<script>
// Sidebar Toggle Functionality
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebar = document.querySelector('.sidebar-nav');
const mainContent = document.querySelector('.main-content');
const logo = document.querySelector('.site-logo img');
const logoDivider = document.querySelector('.sidebar-divider.logo-divider');

const sidebarNav = document.getElementById('sidebarNav');

// Load saved state from localStorage
const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
if (sidebarCollapsed) {
    sidebar.classList.add('collapsed');
    mainContent.classList.add('expanded');
}

sidebarToggle.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    mainContent.classList.toggle('expanded');
    // Save state to localStorage
    const isCollapsed = sidebar.classList.contains('collapsed');
    localStorage.setItem('sidebarCollapsed', isCollapsed);
    // Logo is hidden/shown by CSS transition only, extra script unnecessary
    // Hide/cleanup tooltip if present on toggle
    if (!isCollapsed) {
        sidebarNavTooltip.classList.remove('active');
        sidebarNavTooltip.style.display = 'none';
    }
});

// --- Sidebar Tooltip Pop Functionality (modified logic) ---
// Tooltip now disappears when you stop hovering a nav-link, 
// even if the mouse is still in the sidebar,
// unless you're interacting with the tooltip pop itself.

const sidebarNavTooltip = document.getElementById('sidebarNavTooltip');
let tooltipActiveLink = null;
let tooltipHideTimeout = null;

document.querySelectorAll('.sidebar-nav .nav-link').forEach(function(link) {
    // Mouse enter and focus (for accessibility)
    link.addEventListener('mouseenter', navTooltipHandler);
    link.addEventListener('focus', navTooltipHandler);

    // Mouse leave and blur
    link.addEventListener('mouseleave', navLinkMouseLeaveHandler);
    link.addEventListener('blur', hideNavTooltip);
});

// --- BEGIN: Add logout tooltip when sidebar collapsed ---
const logoutBtn = document.getElementById('logoutBtn');
logoutBtn.addEventListener('mouseenter', function(e) {
    if (!sidebar.classList.contains('collapsed')) {
        hideNavTooltipImmediate();
        return;
    }
    showLogoutTooltip(e);
});
logoutBtn.addEventListener('focus', function(e) {
    if (!sidebar.classList.contains('collapsed')) {
        hideNavTooltipImmediate();
        return;
    }
    showLogoutTooltip(e);
});
logoutBtn.addEventListener('mouseleave', function(e) {
    // Hide, unless mouse moved into tooltip itself (same as nav-link logic)
    if (
        e.relatedTarget === sidebarNavTooltip ||
        (sidebarNavTooltip.contains && sidebarNavTooltip.contains(e.relatedTarget))
    ) {
        return;
    }
    // === FIX: Hide tooltip instantly, *without* transition, to avoid resize glitch ===
    sidebarNavTooltip.classList.remove('active');
    sidebarNavTooltip.classList.remove('logout-pop');
    sidebarNavTooltip.style.display = 'none';
    tooltipActiveLink = null;
    if (tooltipHideTimeout) {
        clearTimeout(tooltipHideTimeout);
        tooltipHideTimeout = null;
    }
});
logoutBtn.addEventListener('blur', hideNavTooltip);

// Support tooltip remaining if mouse is over tooltip itself
// Done already below (mouseleave/enter on tooltip).

function showLogoutTooltip(e) {
    const tooltipText = logoutBtn.getAttribute('data-tooltip') || "Log out";
    tooltipActiveLink = logoutBtn;
    sidebarNavTooltip.textContent = tooltipText;
    sidebarNavTooltip.classList.add('logout-pop');
    sidebarNavTooltip.style.display = 'block';

    // Position tooltip beside the logout button (to the right)
    const rect = logoutBtn.getBoundingClientRect();
    const sidebarRect = sidebar.getBoundingClientRect();
    const x = sidebarRect.right + 5;
    const y = rect.top + rect.height / 2 + window.scrollY;
    sidebarNavTooltip.style.left = (x + 10) + 'px';
    sidebarNavTooltip.style.top = y + 'px';

    setTimeout(function(){
        sidebarNavTooltip.classList.add('active');
    }, 5);

    // Cancel hide timeout if any
    if (tooltipHideTimeout) {
        clearTimeout(tooltipHideTimeout);
        tooltipHideTimeout = null;
    }
}
// When hiding the logout tooltip, remove .logout-pop for style reset
function hideNavTooltipImmediate() {
    sidebarNavTooltip.classList.remove('active', 'logout-pop');
    sidebarNavTooltip.style.display = 'none';
    tooltipActiveLink = null;
    if (tooltipHideTimeout) {
        clearTimeout(tooltipHideTimeout);
        tooltipHideTimeout = null;
    }
}
function hideNavTooltip() {
    sidebarNavTooltip.classList.remove('active', 'logout-pop');
    setTimeout(function() {
        sidebarNavTooltip.style.display = 'none';
        tooltipActiveLink = null;
    }, 150);
    if (tooltipHideTimeout) {
        clearTimeout(tooltipHideTimeout);
        tooltipHideTimeout = null;
    }
}
// --- END: logout tooltip addition ---

// Show tooltip beside nav-link
function navTooltipHandler(e) {
    if (!sidebar.classList.contains('collapsed')) {
        hideNavTooltip();
        return;
    }
    const tooltipText = this.getAttribute('data-tooltip');
    if (!tooltipText) return;
    tooltipActiveLink = this;
    sidebarNavTooltip.textContent = tooltipText;
    sidebarNavTooltip.classList.remove('logout-pop');
    sidebarNavTooltip.style.display = 'block';

    // Calculate position beside hovered nav-link
    const rect = this.getBoundingClientRect();
    const sidebarRect = sidebar.getBoundingClientRect();
    const x = sidebarRect.right + 5;
    const y = rect.top + rect.height / 2 + window.scrollY;
    sidebarNavTooltip.style.left = (x + 10) + 'px';
    sidebarNavTooltip.style.top = y + 'px';

    setTimeout(function(){
        sidebarNavTooltip.classList.add('active');
    }, 5);

    // Cancel hide timeout if any
    if (tooltipHideTimeout) {
        clearTimeout(tooltipHideTimeout);
        tooltipHideTimeout = null;
    }
}

// When mouse leaves the nav-link, hide tooltip UNLESS moving directly into the tooltip itself
function navLinkMouseLeaveHandler(e) {
    // See if the destination (relatedTarget) is the tooltip itself
    if (
        e.relatedTarget === sidebarNavTooltip ||
        (sidebarNavTooltip.contains && sidebarNavTooltip.contains(e.relatedTarget))
    ) {
        // Do NOT hide yet, let the tooltip stay open
        return;
    }
    // Otherwise, start timer to hide tooltip
    tooltipHideTimeout = setTimeout(() => {
        hideNavTooltip();
        tooltipActiveLink = null;
    }, 60); // Hide *quickly* after nav-link is left
}

// Hide tooltip when mouse leaves the tooltip itself (even if still in sidebar)
sidebarNavTooltip.addEventListener('mouseleave', function() {
    tooltipHideTimeout = setTimeout(() => {
        hideNavTooltip();
        tooltipActiveLink = null;
    }, 60);
});

// If user moves into tooltip from nav-link or logout, cancel hide
sidebarNavTooltip.addEventListener('mouseenter', function() {
    if (tooltipHideTimeout) {
        clearTimeout(tooltipHideTimeout);
        tooltipHideTimeout = null;
    }
});

// On mouse entering sidebar, do not restore tooltip (changed from old code)

document.querySelectorAll('.nav-link').forEach(function(link) {
    link.addEventListener('keydown', function(e) {
        if (sidebar.classList.contains('collapsed') && (e.key === " " || e.key === "Enter")) {
            e.preventDefault();
            this.focus();
        }
    });
});
logoutBtn.addEventListener('keydown', function(e) {
    if (sidebar.classList.contains('collapsed') && (e.key === " " || e.key === "Enter")) {
        e.preventDefault();
        this.focus();
    }
});

// Immediately hide tooltip when sidebar is expanded
sidebarToggle.addEventListener('click', () => {
    sidebarNavTooltip.classList.remove('active', 'logout-pop');
    sidebarNavTooltip.style.display = 'none';
    tooltipActiveLink = null;
    if (tooltipHideTimeout) {
        clearTimeout(tooltipHideTimeout);
        tooltipHideTimeout = null;
    }
});

// Logout Alert Modal Logic - REFERENCE DESIGN FROM sched.php
const logoutAlertBackdrop = document.getElementById('logoutAlertBackdrop');
const logoutCancelBtn = document.getElementById('logoutCancelBtn');
const logoutConfirmBtn = document.getElementById('logoutConfirmBtn');

// Show modal on logout button click
logoutBtn.addEventListener('click', () => {
    logoutAlertBackdrop.classList.add("active");
    // Hide tooltip immediately
    hideNavTooltipImmediate();
});

// Hide modal on cancel
logoutCancelBtn.addEventListener('click', () => {
    logoutAlertBackdrop.classList.remove("active");
});

// Confirm logout
logoutConfirmBtn.addEventListener('click', () => {
    window.location.href = 'employee.php?logout=1';
});

// Click on backdrop (not the modal) closes modal
logoutAlertBackdrop.addEventListener('mousedown', (e) => {
    if (e.target === logoutAlertBackdrop) {
        logoutAlertBackdrop.classList.remove("active");
    }
});

// MOBILE SIDEBAR TOGGLE
const mobileToggle = document.getElementById('mobileToggle');
if (mobileToggle) {
    mobileToggle.addEventListener('click', () => {
        sidebar.classList.toggle('mobile-active');
    });
}
</script>

</body>
</html>