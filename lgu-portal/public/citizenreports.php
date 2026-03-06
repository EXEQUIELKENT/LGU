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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

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
            padding: 30px 0 40px;
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

        /* CONTENT CARD */
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
        .card-header h2 {
            margin: 0 auto;
            text-align: center;
            width: 100%;
            font-size: 30px;
            color: var(--text-primary);
        }
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

        /* =============================================
           TABLE — FIXED LAYOUT + OVERFLOW CONTAINMENT
        =============================================== */
        .table-wrapper {
            border-radius: 16px;
            background: var(--card-bg);
            box-shadow: inset 0 0 0 1px var(--border-color);
            transition: background 0.3s ease;
            /* Allow horizontal scroll only when truly needed */
            overflow-x: auto;
            width: 100%;
        }

        table {
            /* Fixed layout: respects explicit widths, clips overflow */
            table-layout: fixed;
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            /* Minimum width before horizontal scroll kicks in */
            min-width: 700px;
        }

        /* Column width distribution */
        col.col-id       { width: 90px;  }
        col.col-date     { width: 110px; }
        col.col-type     { width: 22%;   }   /* flexible, clips overflow */
        col.col-location { width: 30%;   }   /* flexible, clips overflow */
        col.col-budget   { width: 110px; }
        col.col-status   { width: 120px; }
        col.col-action   { width: 80px;  }

        /* MODERN TABLE HEADER */
        thead th {
            position: sticky;
            top: 0;
            background: linear-gradient(to bottom, #fdfdfd, #f2f4f8);
            z-index: 2;
            padding: 16px 14px;
            border-bottom: 1px solid #e3e6ee;
            color: #555;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
            white-space: nowrap;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            overflow: hidden;
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

        /* TABLE CELLS — allow wrapping by default */
        td {
            padding: 14px 14px;
            border-bottom: 1px solid #eef0f5;
            font-size: 14px;
            color: #374151;
            text-align: center;
            /* Allow natural wrapping — no global nowrap */
            white-space: normal;
            word-break: break-word;
            vertical-align: middle;
        }

        [data-theme="dark"] td {
            border-bottom-color: var(--border-color);
            color: var(--text-secondary);
        }

        /* Columns that should NOT wrap (short fixed values) */
        td:nth-child(1),   /* Sched # */
        td:nth-child(2),   /* Date    */
        td:nth-child(5),   /* Budget  */
        td:nth-child(6),   /* Status  */
        td:nth-child(7)    /* Action  */
        {
            white-space: nowrap;
        }

        /* Task & Location: truncate with ellipsis on single line */
        td:nth-child(3),   /* Type/Task */
        td:nth-child(4)    /* Location  */
        {
            text-align: left;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 0; /* triggers ellipsis in table-layout:fixed */
        }

        /* Show full text on hover via title attribute (tooltip) */
        td:nth-child(3):hover,
        td:nth-child(4):hover {
            overflow: visible;
            white-space: normal;
            word-break: break-word;
            /* Slight highlight to indicate expanded */
            background: #f0f5ff;
            position: relative;
            z-index: 1;
        }

        [data-theme="dark"] td:nth-child(3):hover,
        [data-theme="dark"] td:nth-child(4):hover {
            background: rgba(55, 98, 200, 0.15);
        }

        /* TABLE ZEBRA + HOVER */
        tbody tr {
            transition: background .2s ease;
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

        /* VIEW BUTTON */
        td a.link {
            padding: 6px 16px;
            font-size: 13px;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: #fff;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 600;
            box-shadow: 0 4px 10px rgba(59,130,246,.35);
            transition: transform .15s ease, box-shadow .15s ease;
            display: inline-block;
            white-space: nowrap;
        }
        td a.link:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(59,130,246,.45);
        }

        /* STATUS PILL */
        .status-pill {
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
        }
        .status-pill::before {
            content: "●";
            font-size: 9px;
            margin-right: 5px;
        }
        .status-pending  { background: #fff3cd; color: #856404; }
        .status-fixed    { background: #d4edda; color: #155724; }
        .status-progress { background: #cce5ff; color: #004085; }
        .status-delayed  { background: #f8d7da; color: #721c24; }

        /* TABLE SEARCH BAR */
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
            box-sizing: border-box;
        }
        [data-theme="dark"] #requestSearch {
            border-color: var(--border-color);
        }
        #requestSearch:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,.15);
        }
        .search-highlight { background: #fff176; color: #000; padding: 1px 3px; border-radius: 4px; font-weight: 700; }
        [data-theme="dark"] .search-highlight { background: #f9a825; color: #000; }

        /* MOBILE MAINTENANCE LIST */
        .mobile-maintenance-list {
            display: none;
        }

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
            box-sizing: border-box;
        }
        .report-row {
            display: flex;
            flex-direction: row;
            align-items: flex-start;
            font-size: 14px;
            line-height: 1.5;
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
            flex: 1 1 auto;
            color: var(--text-secondary);
            /* Allow long text to wrap on mobile cards */
            word-break: break-word;
            white-space: normal;
        }
        .report-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 8px;
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
        .evidence-btn:hover { background: #2851b3; }

        @media (max-width: 1024px) {
            .footer-content {
                grid-template-columns: 1fr 1fr;
            }
        }

        /* ===== MOBILE BREAKPOINT ===== */
        @media (max-width: 768px) {
            .nav { display: none !important; }

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
            .mobile-toggle:active { transform: scale(0.95); }

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

            .dashboard-container { padding: 20px 13px 40px; }
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

            /* Hide desktop table, show mobile cards */
            table { display: none !important; }
            .mobile-maintenance-list {
                display: flex;
                flex-direction: column;
                gap: 20px;
                width: 100%;
                padding: 8px 10px;
                box-sizing: border-box;
            }

            .table-search-wrapper {
                order: 2;
                margin-top: 10px;
                margin-bottom: 18px;
                padding: 0 10px;
                box-sizing: border-box;
            }
            .mobile-maintenance-list .card-header {
                display: flex;
                flex-direction: column;
                align-items: center;
            }

            .footer { padding: 40px 20px 20px; }
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

            .content-card {
                padding: 22px 6px;
                border-radius: 12px;
            }
        }

        @media (max-width: 500px) {
            .stat-card { padding: 20px 10px; }
            .stat-icon { font-size: 25px; padding: 8px; }
            .stat-card .number { font-size: 28px; }
            .card-header h2 { font-size: 1.0rem; }
            .report-card { padding: 12px; }
            #requestSearch { font-size: 14px; padding: 9px 14px; }
        }

        @media (max-width: 360px) {
            .mobile-clock { font-size: 12px; right: 52px; }
            .report-card { padding: 12px 3vw !important; }
        }

        @media (min-width: 769px) {
            .mobile-top-nav { display: none !important; }
            .sidebar-nav { display: none !important; }
            .nav { display: flex !important; }
        }
    </style>
    <?php include 'citizen_rendering.php'; ?>
</head>
<body>

<!-- DESKTOP NAVIGATION -->
<header class="nav">
        <a href="https://infragovservices.com/" class="site-logo" target="_blank" rel="noopener noreferrer">
            <img src="assets/img/officiallogo.png" alt="LGU Logo" style="width: 40px; border-radius: 8px;">
            <span data-i18n="site_title">InfraGovServices</span>
        </a>
        
        <div class="nav-center">
            <div class="nav-links">
                <?php if ($show_login): ?>
                <a href="login.php" data-i18n="nav_login">Log in</a>
                <?php endif; ?>
                <a href="citizencimm.php" data-i18n="nav_home">Home</a>
                <a href="#" class="active" data-i18n="nav_reports">Reports</a>
                <a href="citizenrepform.php" data-i18n="nav_requests">Requests</a>
                <a href="about.php" data-i18n="nav_about">About</a>
            </div>
            
            <div class="nav-divider"></div>
            
            <div class="nav-actions">
                <div class="desktop-clock" id="desktopClock"></div>

                <button class="translate-btn" id="translateBtn" data-i18n-title="translate_btn_title" title="Translate to Filipino">
                    <span class="globe-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="2" y1="12" x2="22" y2="12"/>
                            <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                        </svg>
                    </span>
                    <span class="lang-label" id="langLabel" data-i18n="lang_label">EN</span>
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
                <img src="assets/img/officiallogo.png" alt="LGU Logo">
                <div class="sidebar-divider logo-divider"></div>
            </a>
            <div class="sidebar-logo-spacer"></div>
            
            <ul class="nav-list">
                <?php if ($show_login): ?>
                <li><a href="login.php" class="nav-link"><i class="fas fa-sign-in-alt"></i><span data-i18n="nav_login">Log in</span></a></li>
                <?php endif; ?>
                <li><a href="citizencimm.php" class="nav-link"><i class="fas fa-home"></i><span data-i18n="nav_home">Home</span></a></li>
                <li><a href="#"class="nav-link active"><i class="fas fa-file-alt"></i><span data-i18n="nav_reports">Reports</span></a></li>
                <li><a href="citizenrepform.php" class="nav-link"><i class="fas fa-clipboard-list"></i><span data-i18n="nav_requests">Requests</span></a></li>
                <li><a href="about.php" class="nav-link"><i class="fas fa-info-circle"></i><span data-i18n="nav_about">About</span></a></li>
            </ul>
        </div>
    </div>

    <!-- MOBILE TOP NAV -->
    <div class="mobile-top-nav">
        <button class="mobile-toggle" id="mobileToggle">☰</button>

        <button class="mobile-translate-btn" id="mobileTranslateBtn" data-i18n-title="translate_btn_title" title="Translate">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/>
                <line x1="2" y1="12" x2="22" y2="12"/>
                <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
            </svg>
            <span class="mobile-lang-label" id="mobileLangLabel">E</span>
        </button>

        <a href="https://infragovservices.com/" target="_blank" rel="noopener noreferrer">
            <img src="assets/img/officiallogo.png" alt="LGU Logo">
        </a>
        <div class="mobile-clock" id="mobileClock"></div>
        <button class="nav-btn dark-mode-btn mobile-dark-mode-btn dark-toggle" id="mobileDarkModeBtn" title="Toggle Dark Mode">
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
            <h3 data-i18n="reports_stat_repairs">Repairs</h3>
            <div class="number"><?= $repairs_count ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">⏳</div>
        <div>
            <h3 data-i18n="reports_stat_ongoing">On-Going Repairs</h3>
            <div class="number"><?= $ongoing_count ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">📍</div>
        <div>
            <h3 data-i18n="reports_stat_pending">Pending</h3>
            <div class="number"><?= $pending_count ?></div>
        </div>
    </div>
</div>

<div class="content-card">
    <!-- Mobile header -->
    <div class="card-header show-on-mobile">
        <h2 data-i18n="reports_page_title">Recent Maintenance Reports</h2>
    </div>
    <!-- Desktop header -->
    <div class="card-header">
        <h2 class="hide-on-mobile" data-i18n="reports_page_title">Recent Maintenance Reports</h2>
    </div>

    <div class="table-search-wrapper">
        <input
            id="requestSearch"
            type="text"
            data-i18n-placeholder="reports_search_placeholder"
            placeholder="Search by Date, Type, Location, Budget, or Status..."
        >
    </div>

    <!-- DESKTOP TABLE -->
    <div class="table-wrapper">
        <table>
            <!-- colgroup drives the fixed-layout widths -->
            <colgroup>
                <col class="col-id">
                <col class="col-date">
                <col class="col-type">
                <col class="col-location">
                <col class="col-budget">
                <col class="col-status">
                <col class="col-action">
            </colgroup>
            <thead>
                <tr>
                    <th data-i18n="reports_table_sched">Sched #</th>
                    <th data-i18n="reports_table_date">Date</th>
                    <th data-i18n="reports_table_type">Type</th>
                    <th data-i18n="reports_table_location">Location</th>
                    <th data-i18n="reports_table_budget">Budget</th>
                    <th data-i18n="reports_table_status">Status</th>
                    <th data-i18n="reports_table_action">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                if (count($maintenance_data) > 0) {
                    foreach ($maintenance_data as $item) {
                        $status_class = 'status-pending';
                        $status_key = 'reports_status_pending';
                        if ($item['status'] === 'Completed') {
                            $status_class = 'status-fixed';
                            $status_key = 'reports_status_completed';
                        } elseif ($item['status'] === 'In Progress') {
                            $status_class = 'status-progress';
                            $status_key = 'reports_status_in_progress';
                        } elseif ($item['status'] === 'Delayed') {
                            $status_class = 'status-delayed';
                            $status_key = 'reports_status_delayed';
                        }
                        $date = date('M d, Y', strtotime($item['starting_date']));
                        // title attributes allow full text on hover
                        $task_escaped     = htmlspecialchars($item['task']);
                        $location_escaped = htmlspecialchars($item['location']);
                ?>
                <tr>
                    <td class="searchable">#SCH-<?php echo $item['sched_id']; ?></td>
                    <td class="searchable"><?php echo $date; ?></td>
                    <td class="searchable" title="<?php echo $task_escaped; ?>"><?php echo $task_escaped; ?></td>
                    <td class="searchable" title="<?php echo $location_escaped; ?>"><?php echo $location_escaped; ?></td>
                    <td class="searchable">₱<?php echo number_format($item['budget'], 2); ?></td>
                    <td class="searchable"><span class="status-pill <?php echo $status_class; ?>" data-i18n="<?php echo $status_key; ?>"><?php echo $item['status']; ?></span></td>
                    <td><a href="#" class="link" data-i18n="reports_view_button">View</a></td>
                </tr>
                <?php 
                    }
                } else {
                ?>
                <tr>
                    <td colspan="7" style="text-align: center; color: #999;" data-i18n="reports_no_data">No maintenance schedules available</td>
                </tr>
                <?php } ?>
                <tr id="noRequestResult" style="display:none;">
                    <td colspan="7" style="text-align:center; padding:20px; font-weight:500;" data-i18n="reports_no_match">
                        No matching data
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- MOBILE CARDS -->
    <div class="mobile-maintenance-list">
        <?php if (!empty($maintenance_data)): ?>
            <?php foreach ($maintenance_data as $item): 
                $status_class = 'status-pending';
                $status_key = 'reports_status_pending';
                if ($item['status'] === 'Completed') {
                    $status_class = 'status-fixed';
                    $status_key = 'reports_status_completed';
                } elseif ($item['status'] === 'In Progress') {
                    $status_class = 'status-progress';
                    $status_key = 'reports_status_in_progress';
                } elseif ($item['status'] === 'Delayed') {
                    $status_class = 'status-delayed';
                    $status_key = 'reports_status_delayed';
                }
            ?>
                <div class="report-card">
                    <div class="report-row">
                        <span class="label" data-i18n="reports_mobile_schedule_id">Schedule ID:</span>
                        <span class="value searchable">#SCH-<?= $item['sched_id'] ?></span>
                    </div>
                    <div class="report-row">
                        <span class="label" data-i18n="reports_mobile_category">Category:</span>
                        <span class="value searchable"><?= htmlspecialchars($item['category']) ?></span>
                    </div>
                    <div class="report-row">
                        <span class="label" data-i18n="reports_mobile_task">Task:</span>
                        <span class="value searchable"><?= htmlspecialchars($item['task']) ?></span>
                    </div>
                    <div class="report-row">
                        <span class="label" data-i18n="reports_mobile_location">Location:</span>
                        <span class="value searchable"><?= htmlspecialchars($item['location']) ?></span>
                    </div>
                    <div class="report-row">
                        <span class="label" data-i18n="reports_mobile_start_date">Start Date:</span>
                        <span class="value searchable"><?= date('M d, Y', strtotime($item['starting_date'])) ?></span>
                    </div>
                    <div class="report-row">
                        <span class="label" data-i18n="reports_mobile_budget">Budget:</span>
                        <span class="value searchable">₱<?= number_format($item['budget'], 2) ?></span>
                    </div>
                    <div class="report-row">
                        <span class="label" data-i18n="reports_mobile_status">Status:</span>
                        <span class="status-pill searchable <?= $status_class ?>" data-i18n="<?= $status_key ?>">
                            <?= htmlspecialchars($item['status']) ?>
                        </span>
                    </div>
                    <div class="report-footer">
                        <a href="#" class="evidence-btn" data-i18n="reports_view_button">View</a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="report-card" data-i18n="reports_no_data">No maintenance schedules available</div>
        <?php endif; ?>
        <div id="noMobileResult" class="report-card" style="display:none; text-align:center; font-weight:600;" data-i18n="reports_no_match">
            No matching data
        </div>
    </div>
</div>
    </div>
</div>
</div>

<!-- TABLE & MOBILE LIVE SEARCH WITH HIGHLIGHT -->
<script>
document.addEventListener("DOMContentLoaded", () => {
    const searchInput  = document.getElementById("requestSearch");
    const table        = document.querySelector("table");
    const mobileList   = document.querySelector(".mobile-maintenance-list");
    if (!table || !searchInput) return;

    const tbody        = table.querySelector("tbody");
    const rows         = Array.from(tbody.querySelectorAll("tr")).filter(r => r.id !== "noRequestResult");
    const noResultRow  = document.getElementById("noRequestResult");
    const cards        = Array.from(document.querySelectorAll(".mobile-maintenance-list .report-card")).filter(c => c.id !== "noMobileResult");
    const noMobileResult = document.getElementById("noMobileResult");

    function escapeRegExp(t) { return t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
    function storeOriginal(el) { if (!('original' in el.dataset)) el.dataset.original = el.innerHTML; }
    function resetEl(el) { if ('original' in el.dataset) el.innerHTML = el.dataset.original; }
    function highlightEl(el, kw) {
        if (!kw) return;
        const regex = new RegExp(`(${escapeRegExp(kw)})`, 'gi');
        // Walk only text nodes — never touch tag names or attribute values
        const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, null, false);
        const textNodes = [];
        let node;
        while ((node = walker.nextNode())) textNodes.push(node);
        textNodes.forEach(tn => {
            if (!tn.nodeValue.trim()) return;
            const parts = tn.nodeValue.split(regex);
            if (parts.length < 2) return;
            const frag = document.createDocumentFragment();
            parts.forEach((part, i) => {
                if (i % 2 === 1) {
                    const mark = document.createElement('span');
                    mark.className = 'search-highlight';
                    mark.textContent = part;
                    frag.appendChild(mark);
                } else {
                    frag.appendChild(document.createTextNode(part));
                }
            });
            tn.parentNode.replaceChild(frag, tn);
        });
    }

    searchInput.addEventListener("input", () => {
        const q  = searchInput.value.trim();
        const ql = q.toLowerCase();

        document.querySelectorAll('table .searchable[data-original], .mobile-maintenance-list .searchable[data-original]')
            .forEach(el => resetEl(el));

        if (!q) {
            rows.forEach(row  => { row.style.display  = ""; tbody.appendChild(row); });
            if (noResultRow)    noResultRow.style.display    = "none";
            cards.forEach(card => { card.style.display = ""; mobileList.appendChild(card); });
            if (noMobileResult) noMobileResult.style.display = "none";
            return;
        }

        let dMatches = [], mMatches = [];

        rows.forEach(row => {
            const els = row.querySelectorAll('.searchable');
            els.forEach(el => storeOriginal(el));
            const match = [...els].some(el => el.textContent.toLowerCase().includes(ql));
            row.style.display = match ? "" : "none";
            if (match) { els.forEach(el => highlightEl(el, q)); dMatches.push(row); }
        });
        dMatches.forEach(row => tbody.insertBefore(row, tbody.firstChild));
        if (noResultRow) noResultRow.style.display = dMatches.length === 0 ? "" : "none";

        cards.forEach(card => {
            const els = card.querySelectorAll('.searchable');
            els.forEach(el => storeOriginal(el));
            const match = [...els].some(el => el.textContent.toLowerCase().includes(ql));
            card.style.display = match ? "" : "none";
            if (match) { els.forEach(el => highlightEl(el, q)); mMatches.push(card); }
        });
        mMatches.forEach(card => mobileList.insertBefore(card, mobileList.firstChild));
        if (noMobileResult) noMobileResult.style.display = mMatches.length === 0 ? "" : "none";
    });
});
</script>

<!-- FOOTER -->
<footer class="footer" style="margin-top:50px;">
    <div class="footer-content">
        <div class="footer-about">
            <h3>InfraGovServices</h3>
            <p data-i18n="footer_desc">Community Infrastructure Maintenance Management System for Quezon City. Dedicated to providing efficient, transparent, and responsive infrastructure services for all residents.</p>
            <div class="footer-contact">
                <div class="contact-item"><i class="fas fa-envelope"></i><span>contact@infragovservices.com</span></div>
                <div class="contact-item"><i class="fas fa-phone"></i><span>(02) 8988-4242</span></div>
                <div class="contact-item"><i class="fas fa-map-marker-alt"></i><span>Quezon City Hall, Quezon City</span></div>
            </div>
        </div>
        
        <div class="footer-links">
            <h4 data-i18n="footer_quick_links">Quick Links</h4>
            <ul>
                <li><a href="<?= $BASE_URL ?>citizencimm.php" data-i18n="footer_link_home">Home</a></li>
                <li><a href="<?= $BASE_URL ?>citizenreports.php" data-i18n="footer_link_reports">Reports</a></li>
                <li><a href="<?= $BASE_URL ?>citizenrepform.php" data-i18n="footer_link_submit">Submit Request</a></li>
                <li><a href="<?= $BASE_URL ?>about.php" data-i18n="footer_link_about">About Us</a></li>
            </ul>
        </div>
        
        <div class="footer-links">
            <h4 data-i18n="footer_resources">Resources</h4>
            <ul>
                <li><a href="#" data-i18n="footer_link_guide">User Guide</a></li>
                <li><a href="#" data-i18n="footer_link_faqs">FAQs</a></li>
                <li><a href="#" data-i18n="footer_link_areas">Service Areas</a></li>
                <li><a href="#" data-i18n="footer_link_emergency">Emergency Contacts</a></li>
            </ul>
        </div>
        
        <div class="footer-links">
            <h4 data-i18n="footer_legal">Legal</h4>
            <ul>
                <li><a href="privacy.php" data-i18n="footer_link_privacy">Privacy Policy</a></li>
                <li><a href="termcon.php" data-i18n="footer_link_terms">Terms of Service</a></li>
                <li><a href="#" data-i18n="footer_link_data">Data Protection</a></li>
                <li><a href="#" data-i18n="footer_link_access">Accessibility</a></li>
            </ul>
        </div>
    </div>
    
    <div class="footer-bottom">
        <div data-i18n="footer_copyright">© 2026 LGU Quezon City · InfraGovServices · All Rights Reserved</div>
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
<script>window.CHATBOT_ENDPOINT = '<?= $BASE_URL ?>chatbot.php';</script>
<?php include 'chatbot-widget.php'; ?>

</body>
</html>