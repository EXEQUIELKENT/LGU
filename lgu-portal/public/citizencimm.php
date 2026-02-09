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
    <title>Citizen Dashboard - LGU Portal</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

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
            background: url("<?= $BASE_URL ?>cityhall.jpeg") center/cover no-repeat fixed;
            height: 100vh;
            display: flex;
            flex-direction: column;
            transition: background 0.3s ease;
        }

        body::before {
            content: "";
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            backdrop-filter: blur(8px);
            background: rgba(0, 0, 0, 0.4);
            z-index: -1;
            transition: background 0.3s ease;
        }

        [data-theme="dark"] body::before {
            background: rgba(0, 0, 0, 0.6);
        }

        body::-webkit-scrollbar {
            display: none;
        }
        
        /* === Added for mobile/desktop label toggling === */
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

        .dashboard-container {
            padding: 100px 0 40px;
            max-width: 100%;
            margin: 0;
            color: var(--text-primary);
            transition: color 0.3s ease;
        }
        .container {
            max-width: 1400px;
            margin: auto;
            padding: 0 40px;
        }
        .welcome-section {
            margin-bottom: 30px;
        }
        .welcome-section h1 {
            text-align: center;
            font-size: 3rem;
            font-weight: 900; 
            text-shadow: 2px 2px 8px #000, 0 0 6px #000, 0 0 3px #000, 0 0 1px #fff;
            color: #fff;
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
        /* ===========================
        MOBILE SIDEBAR STYLES
        =========================== */
        .sidebar-nav {
            position: fixed;
            top: 0;
            left: -110%;
            width: calc(100% - 24px);
            height: calc(100% - 24px);
            top: 12px;
            bottom: 12px;
            border-radius: 18px;
            background: var(--bg-secondary);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            box-shadow: 0 4px 25px var(--shadow-color);
            color: var(--text-primary);
            display: none;
            flex-direction: column;
            justify-content: space-between;
            padding: 0;
            z-index: 4000;
            transition: left 0.35s ease, background 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
            border: 1px solid var(--border-color);
        }

        .sidebar-nav.mobile-active {
            left: 12px;
        }

        .sidebar-top {
            display: flex;
            flex-direction: column;
            flex-grow: 1;
            min-height: 0;
            height: 100%;
            padding: 20px 0;
            overflow-y: auto;
            position: relative;
        }

        .sidebar-logo-spacer {
            height: 16px;
            flex-shrink: 0;
        }

        .sidebar-nav .site-logo {
            margin-top: 60px;
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
            color: var(--text-primary);
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

        .sidebar-divider.logo-divider {
            transition: opacity 0.3s ease, width 0.3s ease, margin 0.3s ease;
            opacity: 1;
            width: calc(100% - 50px);
            margin: 18px 25px 0 25px;
            border-bottom: 2px solid rgba(0, 0, 0, 0.551);
        }

        [data-theme="dark"] .sidebar-divider.logo-divider {
            border-bottom-color: rgba(255, 255, 255, 0.3);
        }

        .sidebar-nav .nav-list {
            list-style: none;
            font-size: 14px;
            padding: 0 15px;
            margin: 0;
            display: flex;
            flex-direction: column;
            flex-grow: 0;
            flex-shrink: 0;
            transition: padding 0.3s ease;
        }

        .sidebar-nav .nav-list li {
            width: 100%;
            margin: 3px 0;
        }

        .sidebar-nav .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--text-primary);
            text-decoration: none;
            padding: 12px 20px;
            transition: all 0.3s ease;
            border-radius: 8px;
            white-space: nowrap;
            overflow: hidden;
            position: relative;
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

        @media (max-width: 768px) {
            .sidebar-nav {
                display: flex;
            }
            
            .nav-links {
                display: none !important;
            }
            
            .menu-toggle {
                display: none !important;
            }
        }

        @media (min-width: 769px) {
            .sidebar-nav {
                display: none !important;
            }
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
        
        /* DESKTOP NAVIGATION */
        .nav {
            width: 100%;
            padding: 18px 60px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--nav-bg);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border-bottom: 2px solid var(--border-color);
            box-shadow: 0 4px 25px var(--shadow-color);
            position: fixed;
            top: 0;
            left: 0;
            z-index: 100;
            transition: background 0.3s ease, border-color 0.3s ease;
        }
        
        .site-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text-primary);
            font-weight: 600;
            transition: color 0.3s ease;
            text-decoration: none;
            cursor: pointer;
        }
        
        .site-logo:hover {
            opacity: 0.85;
        }
        
        .site-logo img {
            width: 40px; 
            height: auto; 
            border-radius: 8px;
        }
        
        /* Updated nav center section */
        .nav-center {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-left: auto;
        }
        
        .nav-links {
            display: flex;
            align-items: center;
            gap: 25px;
        }
        
        .nav-links a {
            margin-left: 0;
            text-decoration: none;
            cursor: pointer;
            color: var(--text-primary);
            opacity: .8;
            transition: .2s;
            font-weight: 500;
        }
        
        .nav-links a.active {
            opacity: 1;
            text-decoration: none;
            font-weight: 600;
        }
        
        .nav-links a:hover {
            opacity: 1;
            text-decoration: none;
        }

        /* Nav divider */
        .nav-divider {
            width: 2px;
            height: 30px;
            background: var(--border-color);
            margin: 0;
        }

        /* Nav Actions (Clock and Dark Mode) */
        .nav-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .desktop-clock {
            font-size: 14px;
            font-weight: 500;
            color: var(--text-primary);
            white-space: nowrap;
            position: relative;
            transition: color 0.3s ease;
            text-align: right;
            min-width: 420px;
            display: inline-block;
        }

        .desktop-clock .date-part {
            opacity: 0.6;
            font-weight: 400;
        }

        .desktop-clock .time-part {
            font-weight: 700;
            letter-spacing: 0.03em;
        }

        .time-part span {
            display: inline-block;
            transition: transform 0.25s ease, opacity 0.25s ease;
        }

        .time-part.flip span {
            transform: translateY(-4px);
            opacity: 0.6;
        }

        .nav-btn {
            position: relative;
            width: 38px;
            height: 38px;
            border: none;
            border-radius: 10px;
            background: rgba(55, 98, 200, 0.1);
            color: var(--text-primary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            transition: all 0.3s ease;
            backdrop-filter: blur(8px);
        }

        .nav-btn:hover {
            background: rgba(55, 98, 200, 0.2);
            transform: scale(1.05);
        }

        .nav-btn:active {
            transform: scale(0.95);
        }

        .nav-btn.dark-mode-btn {
            animation: none;
        }

        .nav-btn.dark-mode-btn.active {
            animation: rotateSun 0.5s ease;
        }

        @keyframes rotateSun {
            0% { transform: rotate(0deg) scale(1); }
            50% { transform: rotate(180deg) scale(1.2); }
            100% { transform: rotate(360deg) scale(1); }
        }

        /* MOBILE TOP NAV */
        .mobile-top-nav {
            display: none;
        }

        .menu-toggle {
            display: none;
            font-size: 26px;
            cursor: pointer;
            color: var(--text-primary);
            background: none;
            border: none;
            margin-left: 18px;
        }

        @media (max-width: 1400px) {
            .container {
                max-width: 98%;
            }
        }
        @media (max-width: 1150px) {
            .container {
                max-width: 100%;
                padding: 0 10px;
            }
            .content-card {
                padding: 30px 8px;
            }
        }
        @media (max-width: 992px) {
            .container {
                max-width: 100%;
            }
        }
        @media (max-width: 768px) {
            /* Hide desktop nav, show mobile nav */
            .nav {
                display: none;
            }

            .mobile-top-nav {
                display: flex;
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
                transition: background 0.3s ease, box-shadow 0.3s ease, border-color 0.3s ease;
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
            .footer {
                flex-direction: column;
                align-items: center;
                text-align: center;
                padding: 18px 10px;
            }
            .footer-links {
                justify-content: center;
                margin-bottom: 10px;
                gap: 12px;
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
        @media (max-width: 500px) {
            .stat-card { padding: 20px 10px; }
            .stat-icon { font-size: 25px; padding: 8px; }
            .stat-card .number { font-size: 28px; }
            .card-header h2 { font-size: 1.0rem; }
            .report-card { padding: 12px; }
            .footer {
                flex-direction: column;
                align-items: center;
                text-align: center;
                padding: 18px 10px;
            }
            .footer-links {
                justify-content: center;
                margin-bottom: 10px;
                gap: 12px;
            }
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
        /* === MOBILE SEARCH POSITIONING === */
        @media (max-width: 768px) {
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
        }
        /* Prevent overflow on very small screens */
        @media (max-width: 500px) {
            #requestSearch {
                font-size: 14px;
                padding: 9px 14px;
            }
        }
        /* FOOTER — same design as NAVBAR */
        .footer {
            width: 100%;
            padding: 26px 0 22px;
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border-top: 1px solid var(--border-color);
            box-shadow: 0 -2px 12px var(--shadow-color);
            margin-top: auto;
            flex-shrink: 0;
            position: relative;
            z-index: 1;
            transition: background 0.3s ease, border-color 0.3s ease;
        }

        [data-theme="dark"] .footer {
            background: rgba(26, 26, 26, 0.15);
        }

        /* Left-aligned links */
        .footer-links {
            position: absolute;
            left: 60px;
        }

        .footer-links a {
            margin-right: 25px;
            text-decoration: none;
            cursor: pointer;
            color: #fff;
            opacity: .8;
            transition: .2s;
        }

        .footer-links a:hover {
            opacity: 1;
            text-decoration: none;
            font-weight: 600;
        }

        /* Center copyright */
        .footer-logo {
            text-align: center;
            font-weight: 500;
            color: #fff;
        }
        /* FOOTER FIXES FOR MOBILE */
        .footer {
            display: flex;
            flex-direction: row;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            padding: 20px 15px;
        }

        .footer-links {
            position: static;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 0;
        }

        .footer-links a {
            margin: 0;
        }

        .footer-logo {
            width: 100%;
            text-align: center;
            margin-top: 12px;
        }

    </style>
    <script>
    const SERVER_TIME = <?= $serverTimestamp ?> * 1000;

    (function() {
        try {
            let savedTheme = localStorage.getItem('theme');
            
            if (savedTheme !== 'dark' && savedTheme !== 'light') {
                savedTheme = 'light';
            }
            
            if (savedTheme === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
            } else {
                document.documentElement.removeAttribute('data-theme');
            }
            
            localStorage.setItem('theme', savedTheme);
            
        } catch (e) {
            console.error('Theme initialization error:', e);
            document.documentElement.removeAttribute('data-theme');
        }
    })();
    </script>
</head>
<body>

<!-- DESKTOP NAVIGATION -->
<header class="nav">
    <a href="https://infragovservices.com/" class="site-logo" target="_blank" rel="noopener noreferrer">
        <img src="<?= $OFFICIAL_LOGO ?>" alt="LGU Logo" style="width: 40px; border-radius: 8px;">
        <span>InfraGovServices - Infrastructure and Utilities</span>
    </a>
    
    <div class="nav-center">
        <div class="nav-links">
            <?php if ($show_login): ?>
                <a href="<?= $BASE_URL ?>login.php">Log in</a>
            <?php endif; ?>
            <a href="<?= $BASE_URL ?>citizendash.php" class="active">Home</a>
            <a href="<?= $BASE_URL ?>citizenrepform.php">Requests</a>
            <a href="<?= $BASE_URL ?>about.php">About</a>
        </div>
        
        <div class="nav-divider"></div>
        
        <div class="nav-actions">
            <div class="desktop-clock" id="desktopClock"></div>
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
            <li><a href="<?= $BASE_URL ?>citizendash.php" class="nav-link active"><span>🏠</span><span>Home</span></a></li>
            <li><a href="<?= $BASE_URL ?>citizenrepform.php" class="nav-link"><span>📋</span><span>Requests</span></a></li>
            <li><a href="<?= $BASE_URL ?>about.php" class="nav-link"><span>ℹ️</span><span>About</span></a></li>
        </ul>
        <div style="flex-grow:1;"></div>
    </div>
</div>

<!-- MOBILE TOP NAV -->
<div class="mobile-top-nav">
    <button class="mobile-toggle" id="mobileToggle">☰</button>
    <img src="<?= $OFFICIAL_LOGO ?>" alt="LGU Logo">
    <div class="mobile-clock" id="mobileClock"></div>
    <button class="nav-btn dark-mode-btn mobile-dark-mode-btn dark-toggle" id="mobileDarkModeBtn" title="Toggle Dark Mode">
        <span class="dark-icon">🌙</span>
        <span class="light-icon" style="display: none;">☀️</span>
    </button>
</div>

<div class="main-content">
<div class="dashboard-container">
    <div class="container">
        <div class="welcome-section">
            <h1>Welcome to InfraGovServices!</h1>
        </div>

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

<script>
// Mobile sidebar toggle
document.addEventListener('DOMContentLoaded', function() {
    const mobileToggle = document.getElementById('mobileToggle');
    const sidebar = document.getElementById('sidebarNav');
    
    if (mobileToggle) {
        mobileToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            if (sidebar) sidebar.classList.toggle('mobile-active');
        });
    }
    
    // Close sidebar when clicking outside
    document.addEventListener('click', (e) => {
        if (sidebar && sidebar.classList.contains('mobile-active')) {
            if (!sidebar.contains(e.target) && e.target !== mobileToggle) {
                sidebar.classList.remove('mobile-active');
            }
        }
    });
    
    // Prevent sidebar from closing when clicking inside it
    if (sidebar) {
        sidebar.addEventListener('click', (e) => {
            e.stopPropagation();
        });
    }
    
    // Close sidebar when clicking a link (for better UX)
    const navLinks = sidebar?.querySelectorAll('.nav-link');
    navLinks?.forEach(link => {
        link.addEventListener('click', () => {
            sidebar.classList.remove('mobile-active');
        });
    });
});
</script>

<!-- URL CLEANER: Removes ?staff=field2026 from address bar after authentication -->
<script>
// Clean URL after secret key authentication to prevent sharing
if (window.location.search.includes('staff=infrastructure_staff_2026_qr8p')) {
    // Remove the parameter from URL without reloading
    const cleanUrl = window.location.pathname;
    window.history.replaceState({}, document.title, cleanUrl);
}
</script>

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

<script>
// Clock Script
const RESYNC_MINUTES = 5;
let currentServerTime = SERVER_TIME;
let clockInterval = null;
let lastSecond = null;

function getTimezoneLabel() {
    const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
    const offset = -new Date().getTimezoneOffset() / 60;
    const sign = offset >= 0 ? '+' : '-';
    return `${tz} (GMT${sign}${Math.abs(offset)})`;
}

function renderClock(now) {
    const datePart = now.toLocaleDateString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });

    const timeStr = now.toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
        hour12: true
    });

    const t = timeStr.match(/^(\d+):(\d+):(\d+)\s?(AM|PM)$/i);
    let h = t ? t[1] : "--";
    let m = t ? t[2] : "--";
    let s = t ? t[3] : "--";
    let ampm = t ? t[4] : "";

    const desktopClock = document.getElementById('desktopClock');
    const mobileClock = document.getElementById('mobileClock');

    function flipSpan(str) {
        return str.split('').map(chr => `<span>${chr}</span>`).join('');
    }

    if (desktopClock) {
        desktopClock.innerHTML = `
            <span class="date-part">${datePart}</span>
            &nbsp;&nbsp;&nbsp;
            <span class="time-part">
                ${flipSpan(h)}:${flipSpan(m)}:${flipSpan(s)} ${ampm}
            </span>
        `;
    }

    if (mobileClock) {
        mobileClock.textContent = `${h}:${m}:${s} ${ampm}`;
    }
}

function tick() {
    const now = new Date(currentServerTime);
    const sec = now.getSeconds();

    if (sec !== lastSecond) {
        document.querySelectorAll('.time-part').forEach(el => {
            el.classList.add('flip');
            setTimeout(() => el.classList.remove('flip'), 250);
        });
        lastSecond = sec;
    }

    renderClock(now);
    currentServerTime += 1000;
}

function startClock() {
    if (clockInterval) return;
    tick();
    clockInterval = setInterval(tick, 1000);
}

document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        clearInterval(clockInterval);
        clockInterval = null;
    } else {
        startClock();
    }
});

setInterval(() => {
    fetch(location.href, { method: 'HEAD' })
        .then(() => {
            currentServerTime = SERVER_TIME;
        });
}, RESYNC_MINUTES * 60 * 1000);

startClock();
</script>

<script>
// Dark Mode Toggle
(function() {
    const darkModeBtn = document.getElementById('darkModeBtn');
    const mobileDarkModeBtn = document.getElementById('mobileDarkModeBtn');
    if (!darkModeBtn && !mobileDarkModeBtn) return;

    const darkIcon = darkModeBtn?.querySelector('.dark-icon') || mobileDarkModeBtn?.querySelector('.dark-icon');
    const lightIcon = darkModeBtn?.querySelector('.light-icon') || mobileDarkModeBtn?.querySelector('.light-icon');
    const mobileDarkIcon = mobileDarkModeBtn?.querySelector('.dark-icon');
    const mobileLightIcon = mobileDarkModeBtn?.querySelector('.light-icon');
    const html = document.documentElement;

    const THEME_KEY = 'theme';
    const THEME_BACKUP_KEY = 'theme_backup';

    function updateTheme(isDark, animate = false) {
        try {
            const themeValue = isDark ? 'dark' : 'light';
            
            if (isDark) {
                html.setAttribute('data-theme', 'dark');
            } else {
                html.removeAttribute('data-theme');
            }
            
            localStorage.setItem(THEME_KEY, themeValue);
            localStorage.setItem(THEME_BACKUP_KEY, themeValue);
            
            if (darkIcon) darkIcon.style.display = isDark ? 'none' : 'inline';
            if (lightIcon) lightIcon.style.display = isDark ? 'inline' : 'none';
            if (mobileDarkIcon) mobileDarkIcon.style.display = isDark ? 'none' : 'inline';
            if (mobileLightIcon) mobileLightIcon.style.display = isDark ? 'inline' : 'none';
            
            if (animate) {
                if (darkModeBtn) darkModeBtn.classList.add('active');
                if (mobileDarkModeBtn) mobileDarkModeBtn.classList.add('active');
                setTimeout(() => {
                    if (darkModeBtn) darkModeBtn.classList.remove('active');
                    if (mobileDarkModeBtn) mobileDarkModeBtn.classList.remove('active');
                }, 500);
            }
        } catch (e) {
            console.error('Theme update error:', e);
        }
    }

    try {
        let savedTheme = localStorage.getItem(THEME_KEY);
        
        if (savedTheme !== 'dark' && savedTheme !== 'light') {
            savedTheme = localStorage.getItem(THEME_BACKUP_KEY);
        }
        
        if (savedTheme !== 'dark' && savedTheme !== 'light') {
            savedTheme = 'light';
        }
        
        updateTheme(savedTheme === 'dark', false);
    } catch (e) {
        console.error('Theme load error:', e);
        updateTheme(false, false);
    }

    function toggleTheme() {
        const isDark = html.getAttribute('data-theme') === 'dark';
        updateTheme(!isDark, true);
    }

    if (darkModeBtn) darkModeBtn.addEventListener('click', toggleTheme);
    if (mobileDarkModeBtn) mobileDarkModeBtn.addEventListener('click', toggleTheme);

    window.addEventListener('beforeunload', function() {
        try {
            const currentTheme = html.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
            localStorage.setItem(THEME_KEY, currentTheme);
            localStorage.setItem(THEME_BACKUP_KEY, currentTheme);
        } catch (e) {
            console.error('Theme save error:', e);
        }
    });
})();
</script>

<footer class="footer">
    <div class="footer-links">
        <a href="#">Privacy Policy</a>
        <a href="#">About</a>
        <a href="#">Help</a>
    </div>
    <div class="footer-logo">© 2026 LGU Citizen Portal · All Rights Reserved</div>
</footer>

</body>
</html>