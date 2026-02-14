<?php
session_start();

// --- SERVER TIMEZONE SYNC FOR CLOCK ENHANCEMENT ---
date_default_timezone_set('Asia/Manila');
$serverTimestamp = time();

require_once 'auth_config.php';
require_once 'db.php';

// For local development and domain (show correct path for logo)
if ($_SERVER['HTTP_HOST'] === 'localhost') {
    $BASE_URL = '/LGU/lgu-portal/public/';
    $OFFICIAL_LOGO = '/LGU/lgu-portal/public/assets/img/officiallogo.png';
} else {
    $BASE_URL = '/lgu-portal/public/';
    $OFFICIAL_LOGO = '/lgu-portal/public/assets/img/officiallogo.png';
}

// Get repairs count from repair_archive
$repairs_count = 0;
$repairs_result = $conn->query("SELECT COUNT(*) as count FROM repair_archive");
if ($repairs_result) {
    $repairs_row = $repairs_result->fetch_assoc();
    $repairs_count = $repairs_row['count'];
}

// Get ongoing count from maintenance_schedule (In Progress status)
$ongoing_count = 0;
$ongoing_result = $conn->query("SELECT COUNT(*) as count FROM maintenance_schedule WHERE status = 'In Progress'");
if ($ongoing_result) {
    $ongoing_row = $ongoing_result->fetch_assoc();
    $ongoing_count = $ongoing_row['count'];
}

// Get pending count from requests (Pending approval status)
$pending_count = 0;
$pending_result = $conn->query("SELECT COUNT(*) as count FROM requests WHERE approval_status = 'Pending'");
if ($pending_result) {
    $pending_row = $pending_result->fetch_assoc();
    $pending_count = $pending_row['count'];
}

// Get maintenance schedule data for table
$maintenance_data = array();
$maintenance_result = $conn->query("
    SELECT sched_id, task, location, category, status, starting_date, estimated_completion_date, budget 
    FROM maintenance_schedule 
    ORDER BY starting_date DESC 
    LIMIT 10
");
if ($maintenance_result) {
    while ($row = $maintenance_result->fetch_assoc()) {
        $maintenance_data[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="<?= $OFFICIAL_LOGO ?>" type="image/png">
    <title>Citizen Reports - LGU Portal</title>
    <link rel="stylesheet" href="<?= $BASE_URL ?>citizen_global.css">

    <!-- CRITICAL: Block rendering FIRST - before anything else loads -->
    <script>
    (function() {
        const currentLang = localStorage.getItem('lang') || 'en';
        if (currentLang === 'tl') {
            document.documentElement.style.cssText = 'visibility: hidden !important;';
        }
    })();
    </script>
    <style>
        /* =======================
           Dark Mode Variables
        ========================== */
        :root {
            --bg-primary: #ffffff;
            --bg-secondary: rgba(255, 255, 255, 0.95);
            --bg-tertiary: rgba(255, 255, 255, 0.9);
            --text-primary: #000000;
            --text-secondary: #333333;
            --border-color: rgba(0, 0, 0, 0.1);
            --shadow-color: rgba(0, 0, 0, 0.2);
            --card-bg: #ffffff;
            --nav-bg: rgba(255, 255, 255, 0.87);
            --stat-card-bg: rgba(255, 255, 255, 0.2);
            --content-card-bg: rgba(255, 255, 255, 0.9);
        }

        [data-theme="dark"] {
            --bg-primary: #1a1a1a;
            --bg-secondary: rgba(26, 26, 26, 0.95);
            --bg-tertiary: rgba(30, 30, 30, 0.9);
            --text-primary: #ffffff;
            --text-secondary: #e0e0e0;
            --border-color: rgba(255, 255, 255, 0.1);
            --shadow-color: rgba(0, 0, 0, 0.5);
            --card-bg: rgba(30, 30, 30, 0.95);
            --nav-bg: rgba(26, 26, 26, 0.87);
            --stat-card-bg: rgba(255, 255, 255, 0.1);
            --content-card-bg: rgba(30, 30, 30, 0.95);
        }

        body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background: url("cityhall.jpeg") center/cover no-repeat fixed;
            position: relative;
            transition: background 0.3s ease;
        }

        body::before {
            content: "";
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            backdrop-filter: blur(8px);
            background: rgba(0,0,0,0.4);
            z-index: -1;
            transition: background 0.3s ease;
        }

        .dashboard-container {
            padding: 100px 0 40px;
            max-width: 100%;
            margin: 0;
            color: var(--text-primary);
            transition: color 0.3s ease;
            flex: 1;
        }

        .show-on-mobile {
            display: none;
        }
        .hide-on-mobile {
            display: block;
        }

        /* Hide .show-on-mobile when in desktop view (i.e., min-width > 768px) */
        @media (min-width: 769px) {
            .show-on-mobile {
                display: none !important;
            }
        }

        .container {
            max-width: 1400px;
            margin: auto;
            padding: 0 40px;
        }
        /* STAT CARDS */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 50px;
        }
        .stat-card {
            display: flex;
            align-items: center;
            gap: 15px;
            text-align: left;
            background: var(--stat-card-bg);
            backdrop-filter: blur(10px);
            padding: 30px;
            border-radius: 18px;
            border: 1px solid var(--border-color);
            transition: all .25s ease;
            cursor: pointer;
        }
        .stat-card:hover {
            transform: translateY(-4px);
            background: rgba(255, 255, 255, 0.25);
        }
        [data-theme="dark"] .stat-card:hover {
            background: rgba(255, 255, 255, 0.15);
        }
        .stat-icon {
            font-size: 32px;
            background: rgba(255,255,255,.25);
            padding: 14px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        [data-theme="dark"] .stat-icon {
            background: rgba(255,255,255,.15);
        }
        .stat-card h3 {
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.8;
            margin-bottom: 4px;
            margin-top: 0;
            color: var(--text-primary);
        }
        .stat-card .number {
            font-size: 40px;
            font-weight: 600;
            color: var(--text-primary);
        }

        /* RECENT ACTIVITY TABLE & MOBILE CARDS */
        .content-card {
            background: var(--content-card-bg);
            border-radius: 18px;
            padding: 50px 60px;
            color: var(--text-secondary);
            box-shadow: 0 10px 30px var(--shadow-color);
            transition: all .25s ease;
            border: 1px solid var(--border-color);
        }       
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            text-align: center;
            margin-bottom: 20px;
        }
        /* Center card-header h2 */
        .card-header h2 {
            margin: 0 auto;
            text-align: center;
            width: 100%;
            font-size: 30px;
            color: var(--text-primary);
        }
        /* Make card-header h2 larger on mobile view for .show-on-mobile */
        @media (max-width: 768px) {
            .show-on-mobile.card-header h2 {
                font-size: 30px !important;
                font-weight: 700 !important;
                margin-bottom: 10px !important;
            }
            .show-on-mobile {
                display: block !important;
            }
            .hide-on-mobile {
                display: none !important;
            }
        }

        /* TABLE POLISH - IMPROVED TABLE CONTAINER */
        .table-wrapper {
            border-radius: 16px;
            background: var(--card-bg);
            box-shadow: inset 0 0 0 1px var(--border-color);
            transition: background 0.3s ease;
            overflow-x: visible;
        }

        @media (max-width: 1150px) {
            .table-wrapper {
                overflow-x: auto;
            }
            table {
                min-width: 900px;
            }
        }

        table {
            width: 100%;
            max-width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        /* MODERN TABLE HEADER - UPGRADE + STICKY */
        thead th {
            position: sticky;
            top: 0;
            background: linear-gradient(
                to bottom,
                #fdfdfd,
                #f2f4f8
            );
            z-index: 2;
            padding: 16px 18px;
            border-bottom: 1px solid #e3e6ee;
            color: #555;
            font-size: 15px;
            font-weight: 600;
            text-align: center;
            white-space: nowrap;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        [data-theme="dark"] thead th {
            background: linear-gradient(
                to bottom,
                rgba(40, 40, 40, 0.95),
                rgba(35, 35, 35, 0.95)
            );
            color: var(--text-secondary);
            border-bottom-color: var(--border-color);
        }

        th:nth-child(1) { width: 100px; }
        th:nth-child(2) { width: 130px; }
        th:nth-child(3) { width: auto; min-width: 140px; }
        th:nth-child(4) { width: auto; min-width: 150px; }
        th:nth-child(5) { width: 120px; }
        th:nth-child(6) { width: 110px; }
        th:nth-child(7) { width: 100px; }

        /* TABLE ZEBRA + HOVER LIFT */
        td {
            padding: 16px 18px;
            border-bottom: 1px solid #eef0f5;
            font-size: 15px;
            color: #374151;
            text-align: center;
            white-space: nowrap;
        }

        [data-theme="dark"] td {
            border-bottom-color: var(--border-color);
            color: var(--text-secondary);
        }

        td:nth-child(1) {
            text-align: center;
        }
        td:nth-child(3),
        td:nth-child(4) {
            text-align: left;
        }

        tbody tr {
            transition: background .2s ease, transform .15s ease;
        }
        tbody tr:nth-child(even) {
            background: #fafbff;
        }
        [data-theme="dark"] tbody tr:nth-child(even) {
            background: rgba(255, 255, 255, 0.02);
        }
        tbody tr:hover {
            background: #eef3ff;
        }
        [data-theme="dark"] tbody tr:hover {
            background: rgba(55, 98, 200, 0.1);
        }

        /* VIEW BUTTON UPGRADE */
        td a.link {
            padding: 7px 18px;
            font-size: 14px;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: #fff;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 600;
            box-shadow: 0 4px 10px rgba(59,130,246,.35);
            transition: transform .15s ease, box-shadow .15s ease, background .2s ease;
            display: inline-block;
        }
        td a.link:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(59,130,246,.45);
        }

        /* STATUS PILL - UPGRADED STYLE */
        .status-pill {
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 95px;
        }
        .status-pill::before {
            content: "●";
            font-size: 10px;
            margin-right: 6px;
        }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-fixed { background: #d4edda; color: #155724; }
        .status-progress { background: #cce5ff; color: #004085; }
        .status-delayed { background: #f8d7da; color: #721c24; }

        .btn-small {
            padding: 8px 16px;
            font-size: 13px;
        }
        /* Mobile maintenance list (hidden by default, replaces table on mobile) */
        .mobile-maintenance-list {
            display: none;
        }
        /* --- Begin drop-in mobile card layout --- */
        .report-card {
            width: 100%;
            font-size: 14px;
            background: var(--card-bg);
            border-radius: 16px;
            padding: 16px 18px;
            box-shadow: 0 8px 20px var(--shadow-color);
            display: flex;
            flex-direction: column;
            gap: 10px;
            transition: background 0.3s ease, box-shadow 0.3s ease;
        }
        /* Modified report-row for mobile stack label and value inline instead of left/right */
        .report-row {
            display: flex;
            flex-direction: row;
            align-items: center;
            font-size: 14px;
            line-height: 1.4;
            gap: 7px;
        }
        .report-row .label {
            font-weight: 600;
            opacity: 0.7;
            margin-right: 6px;
            flex-shrink: 0;
            color: var(--text-primary);
        }
        .report-row .value {
            font-weight: 500;
            text-align: left;
            max-width: 100%;
            margin-left: 0;
            flex: 1 1 auto;
            color: var(--text-secondary);
        }
        .evidence-btn {
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            background: #3762c8;
            color: #fff;
            transition: .2s ease;
            border: none;
            cursor: pointer;
        }
        .evidence-btn:hover {
            background: #2851b3;
        }
        /* --- End drop-in mobile card layout --- */
        .maintenance-card {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 18px;
            margin-bottom: 15px;
            box-shadow: 0 6px 18px var(--shadow-color);
            color: var(--text-primary);
            transition: all .25s ease;
        }
        .maintenance-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 30px var(--shadow-color);
        }
        .maintenance-card .status-pill {
            margin-top: 8px;
        }
        .card-action {
            display: inline-block;
            margin-top: 10px;
            font-weight: 600;
            color: #005bb3;
            text-decoration: none;
        }

        /* === TABLE SEARCH BAR (DESKTOP + RESPONSIVE) === */
        .table-search-wrapper {
            width: 100%;
            max-width: 100%;
            margin-bottom: 18px;
        }
        #requestSearch {
            width: 100%;
            padding: 10px 16px;
            border-radius: 10px;
            border: 1px solid #d2d6db;
            font-size: 15px;
            outline: none;
            transition: border .2s ease, box-shadow .2s ease, background 0.3s ease, color 0.3s ease;
            background: var(--card-bg);
            color: var(--text-primary);
        }
        
        [data-theme="dark"] #requestSearch {
            border-color: var(--border-color);
        }
        
        #requestSearch:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,.15);
        }

    /* ===== MOBILE BREAKPOINT (768px and below) ===== */
    @media (max-width: 768px) {
            /* HIDE DESKTOP NAVIGATION */
            .nav {
                display: none !important;
            }

            /* SHOW MOBILE TOP NAV */
            .mobile-top-nav {
                display: flex !important;
                position: fixed;
                top: 0;
                left: 0;
                height: 64px;
                width: 100%;
                align-items: center;
                justify-content: center;
                background: var(--nav-bg);
                backdrop-filter: blur(8px);
                -webkit-backdrop-filter: blur(8px);
                z-index: 5000;
                box-shadow: 0 4px 18px var(--shadow-color);
                border-bottom: 1px solid var(--border-color);
                transition: all 0.3s ease;
                padding: 0 14px;
            }

            .mobile-toggle {
                position: absolute;
                left: 14px;
                background: #3762c8;
                color: #fff;
                border: none;
                border-radius: 10px;
                width: 38px;
                height: 38px;
                font-size: 20px;
                cursor: pointer;
                transition: all 0.3s ease;
            }

            .mobile-toggle:active {
                transform: scale(0.95);
            }

            .mobile-top-nav img {
                height: 42px;
                object-fit: contain;
            }

            .mobile-clock {
                position: absolute;
                right: 56px;
                font-size: 14px;
                font-weight: 600;
                color: var(--text-primary);
                white-space: nowrap;
                transition: color 0.3s ease;
            }

            .mobile-dark-mode-btn {
                position: absolute;
                right: 12px;
                width: 38px;
                height: 38px;
                z-index: 1;
            }

            .dashboard-container { 
                padding: 100px 13px 40px; 
            }
            .container { padding: 0 5px; }
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 18px;
            }
            .welcome-section h1 {
                text-align: center;
                font-size: 2rem;
                font-weight: 600;
            }
            table { display: none !important; }
            
            .mobile-maintenance-list {
                display: flex;
                flex-direction: column;
                gap: 20px;
                width: 100%;
                padding: 8px 20px;
            }

            /* === MOBILE SEARCH POSITIONING === */
            .table-search-wrapper {
                order: 2;
                margin-top: 10px;
                margin-bottom: 18px;
                padding: 0 10px;
            }
            .mobile-maintenance-list .card-header {
                display: flex;
                flex-direction: column;
                align-items: center;
            }

            /* Add inside the existing section */
            .footer {
                padding: 40px 20px 20px;
            }

            .footer-content {
                grid-template-columns: 1fr;
                gap: 30px;
                margin-bottom: 30px;
            }

            .footer-bottom {
                flex-direction: column;
                gap: 20px;
                padding-top: 20px;
                margin-top: 20px;
            }

            /* === Maintenance card (same visual as request-card) === */
            .report-card {
                width: 100%;
                background: var(--card-bg);
                border-radius: 16px;
                padding: 16px 18px;
                box-shadow: 0 8px 20px var(--shadow-color);
                display: flex;
                flex-direction: column;
                gap: 10px;
                font-size: 14px;
            }

            /* Row layout */
            .report-row {
                display: flex;
                align-items: center;
                gap: 7px;
                line-height: 1.4;
            }

            /* Label & value formatting */
            .report-row .label {
                font-weight: 600;
                opacity: 0.7;
                flex-shrink: 0;
            }

            .report-row .value {
                font-weight: 500;
                flex: 1;
                text-align: left;
            }

            /* Footer (status + action button) */
            .report-footer {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-top: 8px;
            }

            /* View button */
            .evidence-btn {
                text-align: center;
                width: 90px;
                padding: 8px 14px;
                border-radius: 10px;
                font-size: 13px;
                font-weight: 600;
                background: #3762c8;
                color: #fff;
                border: none;
                cursor: pointer;
                transition: background .2s ease;
            }

            .evidence-btn:hover {
                background: #2851b3;
            }

            /* Status pill spacing */
            .report-footer .status-pill {
                margin-right: 6px;
            }

            .content-card {
                padding: 22px 6px;
                border-radius: 12px;
            }
        }

        /* ===== SMALLER MOBILE (500px and below) ===== */
        @media (max-width: 500px) {
            .stat-card { padding: 20px 10px; }
            .stat-icon { font-size: 25px; padding: 8px; }
            .stat-card .number { font-size: 28px; }
            .card-header h2 { font-size: 1.0rem; }
            .report-card { padding: 12px; }
            
            #requestSearch {
                font-size: 14px;
                padding: 9px 14px;
            }
        }

        /* ===== VERY SMALL MOBILE (360px and below) ===== */
        @media (max-width: 360px) {
            .mobile-clock {
                font-size: 12px;
                right: 52px;
            }

            .report-card {
                padding: 12px 3vw !important;
            }
        }

        /* ===== ENSURE DESKTOP NAV SHOWS ON LARGE SCREENS ===== */
        @media (min-width: 769px) {
            .mobile-top-nav {
                display: none !important;
            }

            .sidebar-nav {
                display: none !important;
            }

            .nav {
                display: flex !important;
            }
        }
    </style>
    <?php include 'citizen_rendering.php'; ?>
</head>
<body>

<!-- DESKTOP NAVIGATION -->
<header class="nav">
    <a href="https://infragovservices.com/" class="site-logo" target="_blank" rel="noopener noreferrer">
        <img src="<?= $OFFICIAL_LOGO ?>" alt="LGU Logo" style="width: 40px; border-radius: 8px;">
        <span>InfraGovServices</span>
    </a>
    
    <div class="nav-center">
        <div class="nav-links">
            <?php if ($show_login): ?>
                <a href="<?= $BASE_URL ?>login.php">Log in</a>
            <?php endif; ?>
            <a href="<?= $BASE_URL ?>citizencimm.php">Home</a>
            <a href="#" class="active">Reports</a>
            <a href="<?= $BASE_URL ?>citizenrepform.php">Requests</a>
            <a href="<?= $BASE_URL ?>about.php">About</a>
        </div>
        
        <div class="nav-divider"></div>
        
        <div class="nav-actions">
            <div class="desktop-clock" id="desktopClock"></div>

             <!-- TRANSLATE BUTTON (desktop) -->
             <button class="translate-btn" id="translateBtn" title="Translate to Filipino">
                <span class="globe-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="2" y1="12" x2="22" y2="12"/>
                        <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                    </svg>
                </span>
                <span class="lang-label" id="langLabel">EN</span>
            </button>

            <button class="nav-btn dark-mode-btn dark-toggle" id="darkModeBtn" title="Toggle Dark Mode">
                <span class="dark-icon">🌙</span>
                <span class="light-icon" style="display: none;">☀️</span>
            </button>
        </div>
    </div>
</header>

<!-- MOBILE SIDEBAR -->
<div class="sidebar-nav" id="sidebarNav">
    <div class="sidebar-top">
        <a href="https://infragovservices.com/" class="site-logo" target="_blank" rel="noopener noreferrer">
            <img src="<?= $OFFICIAL_LOGO ?>" alt="LGU Logo">
            <div class="sidebar-divider logo-divider"></div>
        </a>
        <div class="sidebar-logo-spacer"></div>
        
        <ul class="nav-list">
            <?php if ($show_login): ?>
                <li><a href="<?= $BASE_URL ?>login.php" class="nav-link"><span>🔐</span><span>Log in</span></a></li>
            <?php endif; ?>
            <li><a href="<?= $BASE_URL ?>citizencimm.php" class="nav-link"><span>🏠</span><span>Home</span></a></li>
            <li><a href="#" class="nav-link active"><span>📄</span><span>Reports</span></a></li>
            <li><a href="<?= $BASE_URL ?>citizenrepform.php" class="nav-link"><span>📋</span><span>Requests</span></a></li>
            <li><a href="<?= $BASE_URL ?>about.php" class="nav-link"><span>ℹ️</span><span>About</span></a></li>
        </ul>
        <div style="flex-grow:1;"></div>
    </div>
</div>

<!-- MOBILE TOP NAV -->
<div class="mobile-top-nav">
    <button class="mobile-toggle" id="mobileToggle">☰</button>

    <!-- MOBILE TRANSLATE BUTTON -->
    <button class="mobile-translate-btn" id="mobileTranslateBtn" title="Translate">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="12" r="10"/>
            <line x1="2" y1="12" x2="22" y2="12"/>
            <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
        </svg>
        <span class="mobile-lang-label" id="mobileLangLabel">E</span>
    </button>

    <a href="https://infragovservices.com/" target="_blank" rel="noopener noreferrer">
        <img src="<?= $OFFICIAL_LOGO ?>" alt="LGU Logo">
    </a>
    <div class="mobile-clock" id="mobileClock"></div>
    <button class="nav-btn dark-mode-btn mobile-dark-mode-btn" id="mobileDarkModeBtn" title="Toggle Dark Mode">
        <span class="dark-icon">🌙</span>
        <span class="light-icon" style="display: none;">☀️</span>
    </button>
</div>

<!-- LANGUAGE BADGE (toast) -->
<div class="lang-badge" id="langBadge">
    <span class="badge-flag" id="badgeFlag">🇺🇸</span>
    <span id="badgeText">Switched to English</span>
</div>

<div class="main-content">
<div class="dashboard-container">
    <div class="container">
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">🛠️</div>
                <div>
                    <h3>Repairs</h3>
                    <div class="number"><?= $repairs_count ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">⏳</div>
                <div>
                    <h3>On-Going Repairs</h3>
                    <div class="number"><?= $ongoing_count ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📍</div>
                <div>
                    <h3>Pending</h3>
                    <div class="number"><?= $pending_count ?></div>
                </div>
            </div>
        </div>

        <div class="content-card">
            <!-- Desktop/tablet label -->
            <div class="card-header show-on-mobile">
                <h2>Recent Maintenance Reports</h2>
            </div>
            <div class="card-header">
                <h2 class="hide-on-mobile">Recent Maintenance Reports</h2>
            </div>

            <!-- SEARCH BAR: replaced with wrapper for responsive width and positioning -->
            <div class="table-search-wrapper">
                <input
                    id="requestSearch"
                    type="text"
                    placeholder="Search by Date, Type, Location, Budget, or Status..."
                >
            </div>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Sched #</th>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Location</th>
                            <th>Budget</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if (count($maintenance_data) > 0) {
                            foreach ($maintenance_data as $item) {
                                // Determine status pill class
                                $status_class = 'status-pending';
                                if ($item['status'] === 'Completed') {
                                    $status_class = 'status-fixed';
                                } elseif ($item['status'] === 'In Progress') {
                                    $status_class = 'status-progress';
                                } elseif ($item['status'] === 'Delayed') {
                                    $status_class = 'status-delayed';
                                }
                                // Format date
                                $date = date('M d, Y', strtotime($item['starting_date']));
                        ?>
                        <tr>
                            <td>#SCH-<?php echo $item['sched_id']; ?></td>
                            <td><?php echo $date; ?></td>
                            <td><?php echo htmlspecialchars($item['task']); ?></td>
                            <td><?php echo htmlspecialchars($item['location']); ?></td>
                            <td>₱<?php echo number_format($item['budget'], 2); ?></td>
                            <td><span class="status-pill <?php echo $status_class; ?>"><?php echo $item['status']; ?></span></td>
                            <td><a href="#" class="link">View</a></td>
                        </tr>
                        <?php 
                            }
                        } else {
                        ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: #999;">No maintenance schedules available</td>
                        </tr>
                        <?php } ?>
                        <tr id="noRequestResult" style="display:none;">
                            <td colspan="7" style="text-align:center; padding:20px; font-weight:500;">
                                No matching data
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Maintenance Cards -->
            <div class="mobile-maintenance-list">
            <!-- Mobile-only header -->
            <?php if (!empty($maintenance_data)): ?>
                <?php foreach ($maintenance_data as $item): 
                    // Status pill
                    $status_class = 'status-pending';
                    if ($item['status'] === 'Completed') {
                        $status_class = 'status-fixed';
                    } elseif ($item['status'] === 'In Progress') {
                        $status_class = 'status-progress';
                    } elseif ($item['status'] === 'Delayed') {
                        $status_class = 'status-delayed';
                    }
                ?>
                    <div class="report-card">

                        <div class="report-row">
                            <span class="label">Schedule ID:</span>
                            <span class="value">#SCH-<?= $item['sched_id'] ?></span>
                        </div>

                        <div class="report-row">
                            <span class="label">Category:</span>
                            <span class="value"><?= htmlspecialchars($item['category']) ?></span>
                        </div>

                        <div class="report-row">
                            <span class="label">Task:</span>
                            <span class="value"><?= htmlspecialchars($item['task']) ?></span>
                        </div>

                        <div class="report-row">
                            <span class="label">Location:</span>
                            <span class="value"><?= htmlspecialchars($item['location']) ?></span>
                        </div>

                        <div class="report-row">
                            <span class="label">Start Date:</span>
                            <span class="value"><?= date('M d, Y', strtotime($item['starting_date'])) ?></span>
                        </div>

                        <div class="report-row">
                            <span class="label">Budget:</span>
                            <span class="value">₱<?= number_format($item['budget'], 2) ?></span>
                        </div>

                        <div class="report-row">
                        <span class="label">Status:</span>
                            <span class="status-pill <?= $status_class ?>">
                                <?= htmlspecialchars($item['status']) ?>
                            </span>
                        </div>

                        <div class="report-footer">
                            <a href="#" class="evidence-btn">View</a>
                        </div>

                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="report-card">No maintenance schedules available</div>
            <?php endif; ?>
            <!-- MOBILE "NO MATCHING DATA" PLACEHOLDER -->
            <div id="noMobileResult" class="report-card" style="display:none; text-align:center; font-weight:600;">
                No matching data
            </div>
            </div>
        </div>
    </div>
</div>
</div>

<!-- TABLE LIVE SEARCH & REORDER SCRIPT (Desktop table only) -->
<script>
document.addEventListener("DOMContentLoaded", () => {
    const searchInput = document.getElementById("requestSearch");
    const table = document.querySelector("table");
    if (!table || !searchInput) return;

    const tbody = table.querySelector("tbody");
    // Exclude no-result row from movable rows.
    const rows = Array.from(tbody.querySelectorAll("tr"))
        .filter(r => r.id !== "noRequestResult");
    const noResultRow = document.getElementById("noRequestResult");

    searchInput.addEventListener("input", () => {
        const query = searchInput.value.toLowerCase().trim();
        let matches = [];

        // Reset: show all, restore original order
        if (query === "") {
            rows.forEach(row => {
                row.style.display = "";
                tbody.appendChild(row);
            });
            if (noResultRow) noResultRow.style.display = "none";
            return;
        }

        // Search and mark matches
        rows.forEach(row => {
            // Only search visible text content (all columns)
            const rowText = row.innerText.toLowerCase();
            if (rowText.includes(query)) {
                matches.push(row);
                row.style.display = "";
            } else {
                row.style.display = "none";
            }
        });

        // Move all matching rows to the top (in entered order)
        matches.forEach(row => tbody.insertBefore(row, tbody.firstChild));

        // Show/hide "No matching data"
        if (noResultRow) noResultRow.style.display = matches.length === 0 ? "" : "none";
    });
});
</script>

<!-- MOBILE CARD SEARCH + REORDER SCRIPT -->
<script>
document.addEventListener("DOMContentLoaded", () => {
    const searchInput = document.getElementById("requestSearch");
    const mobileList = document.querySelector(".mobile-maintenance-list");
    const cards = Array.from(document.querySelectorAll(".mobile-maintenance-list .report-card"))
        .filter(card => card.id !== "noMobileResult");
    const noMobileResult = document.getElementById("noMobileResult");

    if (!searchInput || cards.length === 0) return;

    searchInput.addEventListener("input", () => {
        const query = searchInput.value.toLowerCase().trim();
        let matches = [];

        // Reset state
        if (query === "") {
            cards.forEach(card => {
                card.style.display = "";
                mobileList.appendChild(card);
            });
            if (noMobileResult) noMobileResult.style.display = "none";
            return;
        }

        // Search cards
        cards.forEach(card => {
            const cardText = card.innerText.toLowerCase();
            if (cardText.includes(query)) {
                matches.push(card);
                card.style.display = "";
            } else {
                card.style.display = "none";
            }
        });

        // Move matching cards to top
        matches.forEach(card => {
            mobileList.insertBefore(card, mobileList.firstChild);
        });

        // Show / hide "No matching data"
        if (noMobileResult) {
            noMobileResult.style.display = matches.length === 0 ? "" : "none";
        }
    });
});
</script>

<footer class="footer">
    <div class="footer-content">
        <div class="footer-about">
            <h3>InfraGovServices</h3>
            <p>Community Infrastructure Maintenance Management System for Quezon City. Dedicated to providing efficient, transparent, and responsive infrastructure services for all residents.</p>
            <div class="footer-contact">
                <div class="contact-item">
                    <span>📧</span>
                    <span>contact@infragovservices.com</span>
                </div>
                <div class="contact-item">
                    <span>📞</span>
                    <span>(02) 8988-4242</span>
                </div>
                <div class="contact-item">
                    <span>📍</span>
                    <span>Quezon City Hall, Quezon City</span>
                </div>
            </div>
        </div>
        
        <div class="footer-links">
            <h4>Quick Links</h4>
            <ul>
                <li><a href="<?php echo htmlspecialchars($basePath); ?>citizencimm.php">Home</a></li>
                <li><a href="<?php echo htmlspecialchars($basePath); ?>citizenreports.php">Reports</a></li>
                <li><a href="<?php echo htmlspecialchars($basePath); ?>citizenrepform.php">Submit Request</a></li>
                <li><a href="<?php echo htmlspecialchars($basePath); ?>about.php">About Us</a></li>
            </ul>
        </div>
        
        <div class="footer-links">
            <h4>Resources</h4>
            <ul>
                <li><a href="#">User Guide</a></li>
                <li><a href="#">FAQs</a></li>
                <li><a href="#">Service Areas</a></li>
                <li><a href="#">Emergency Contacts</a></li>
            </ul>
        </div>
        
        <div class="footer-links">
            <h4>Legal</h4>
            <ul>
                <li><a href="privacy.php">Privacy Policy</a></li>
                <li><a href="termcon.php">Terms of Service</a></li>
                <li><a href="#">Data Protection</a></li>
                <li><a href="#">Accessibility</a></li>
            </ul>
        </div>
    </div>
    
    <div class="footer-bottom">
        <div>© 2026 LGU Quezon City · InfraGovServices · All Rights Reserved</div>
        <div class="footer-social">
            <a href="#" class="social-link" title="Facebook">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                </svg>
            </a>
            <a href="#" class="social-link" title="Twitter">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
                </svg>
            </a>
            <a href="#" class="social-link" title="Instagram">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/>
                </svg>
            </a>
            <a href="#" class="social-link" title="Email">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>
                </svg>
            </a>
        </div>
    </div>
</footer>

<?php include 'citizen_global.php'; ?>
<?php include 'chatbot-widget.php'; ?>

</body>
</html>