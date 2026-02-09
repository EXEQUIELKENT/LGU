<?php
session_start();

// --- SERVER TIMEZONE SYNC FOR CLOCK ENHANCEMENT ---
date_default_timezone_set('Asia/Manila');
$serverTimestamp = time();

require_once 'auth_config.php';
// Same base path logic as other public pages (no DB required)
if ($_SERVER['HTTP_HOST'] === 'localhost') {
    $BASE_URL = '/LGU/lgu-portal/public/';
    $OFFICIAL_LOGO = '/LGU/lgu-portal/public/assets/img/officiallogo.png';
} else {
    $BASE_URL = '/lgu-portal/public/';
    $OFFICIAL_LOGO = '/lgu-portal/public/assets/img/officiallogo.png';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="<?= $OFFICIAL_LOGO ?>" type="image/png">
    <title>About - InfraGovServices | LGU Portal</title>
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
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background: url("cityhall.jpeg") center/cover no-repeat fixed;
            position: relative;
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

        [data-theme="dark"] body::before {
            background: rgba(0, 0, 0, 0.6);
        }

        body::-webkit-scrollbar {
            display: none;
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

        .menu-toggle {
            display: none;
            font-size: 26px;
            cursor: pointer;
            color: var(--text-primary);
            background: none;
            border: none;
            margin-left: 18px;
        }
        .form-wrapper {
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 110px 16px 40px;
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

        /* MOBILE TOP NAV */
        .mobile-top-nav {
            display: none;
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
        }

        .about-card {
            width: 100%;
            max-width: 900px;
            background: var(--content-card-bg);
            padding: 48px 44px 44px;
            border-radius: 22px;
            box-shadow: 0 20px 45px var(--shadow-color), 0 0 0 1px var(--border-color);
            transition: all .25s ease;
            color: var(--text-secondary);
            border-top: 4px solid #2b6cb0;
        }
        .about-card h1 {
            margin-bottom: 30px;
            font-size: 2rem;
            line-height: 1.25;
            color: var(--text-primary);
            text-align: center;
            letter-spacing: .02em;
            font-weight: 700;
        }
        .about-card .section-box {
            margin-bottom: 24px;
            padding: 22px 24px;
            background: var(--bg-secondary);
            border-radius: 14px;
            border-left: 4px solid #2b6cb0;
            transition: box-shadow .2s ease, transform .15s ease;
        }
        .about-card .section-box:hover {
            box-shadow: 0 4px 12px rgba(43, 108, 176, 0.08);
        }
        .about-card .section-box.intro {
            background: linear-gradient(135deg, #f0f7ff 0%, var(--bg-secondary) 100%);
        }
        [data-theme="dark"] .about-card .section-box.intro {
            background: linear-gradient(135deg, rgba(30, 50, 80, 0.3) 0%, var(--bg-secondary) 100%);
        }
        .about-card h2 {
            font-size: 1.35rem;
            color: var(--text-primary);
            margin-bottom: 14px;
            margin-top: 0;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .about-card h2 .icon {
            font-size: 1.4rem;
            line-height: 1;
        }
        .about-card .section-box h2 { margin-top: 0; }
        .about-card p {
            font-size: 1rem;
            line-height: 1.8;
            color: var(--text-secondary);
            margin-bottom: 12px;
        }
        .about-card .section-box p:last-child {
            margin-bottom: 0;
        }
        .about-card .purpose-list {
            list-style: none;
            padding-left: 0;
            margin: 12px 0 0;
        }
        .about-card .purpose-list li {
            position: relative;
            padding-left: 28px;
            margin-bottom: 10px;
            line-height: 1.7;
            color: var(--text-secondary);
        }
        .about-card .purpose-list li::before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #2b6cb0;
            font-weight: 700;
            font-size: 1rem;
        }
        .about-card .btn-wrap {
            margin-top: 40px;
            text-align: center;
        }
        .about-card a.btn {
            display: inline-block;
            width: 40%;
            margin: 0;
            padding: 14px 38px;
            background: linear-gradient(135deg, #2b6cb0 0%, #2563eb 100%);
            color: #fff;
            text-decoration: none;
            border-radius: 14px;
            font-weight: 600;
            font-size: 18px;
            transition: all .25s;
            text-align: center;
            box-shadow: 0 4px 14px rgba(43, 108, 176, 0.35);
        }
        .about-card a.btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 22px rgba(43, 108, 176, 0.4);
            background: linear-gradient(135deg, #245a96 0%, #1d4ed8 100%);
        }
        /* Footer */
        .footer {
            width: 100%;
            padding: 26px 0 22px;
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border-top: 1px solid rgba(255,255,255,0.18);
            box-shadow: 0 -2px 12px rgba(44,66,133,0.08);
            margin-top: auto;
            flex-shrink: 0;
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: row;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            padding: 20px 15px;
        }
        .footer-links {
            position: absolute;
            left: 60px;
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
        .footer-logo {
            text-align: center;
            font-weight: 500;
            color: #fff;
            width: 100%;
            text-align: center;
            margin-top: 12px;
        }
        @media (max-width: 950px) {
            .about-card {
                padding: 28px 8vw 32px;
            }
            .about-card .section-box {
                padding: 18px 20px;
            }
        }
        @media (max-width: 768px) {
            .form-wrapper {
                margin-top: 20px !important;
                padding: 100px 5vw 40px !important;
            }
            .about-card {
                padding: 17px 0vw 17px 7vw !important;
                max-width: 99vw;
            }
            .about-card h1 {
                font-size: 1.75rem;
                padding: 18px 6vw 0;
            }
            .about-card h2 {
                font-size: 1.25rem;
            }
            .about-card a.btn {
                margin-bottom: 20px;
                width: 300px !important;
            }
        }
        @media (max-width: 580px) {
            .about-card {
                padding: 12px 2vw !important;
            }
        }
        @media (max-width: 480px) {
            .form-wrapper {
                padding: 90px 3vw 24px !important;
            }
            .about-card h1 {
                font-size: 1.5rem;
            }
            .about-card a.btn {
                width: 90%;
                padding: 14px 20px;
                font-size: 16px;
            }
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
            <a href="<?= $BASE_URL ?>citizencimm.php">Home</a>
            <a href="<?= $BASE_URL ?>citizenrepform.php">Requests</a>
            <a href="<?= $BASE_URL ?>about.php" class="active">About</a>
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
            <li><a href="<?= $BASE_URL ?>citizencimm.php" class="nav-link"><span>🏠</span><span>Home</span></a></li>
            <li><a href="<?= $BASE_URL ?>citizenrepform.php" class="nav-link"><span>📋</span><span>Requests</span></a></li>
            <li><a href="<?= $BASE_URL ?>about.php" class="nav-link active"><span>ℹ️</span><span>About</span></a></li>
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

<div class="form-wrapper">
    <div class="about-card">
        <h1>About CIMMS – Quezon City</h1>

        <div class="section-box intro">
            <p>
                <b>Community Infrastructure Maintenance Management System (CIMMS)</b> is a modern digital platform developed for the 
                <b>Local Government of Quezon City</b> to improve how infrastructure concerns are reported, managed, and resolved across the city.
            </p>
            <p>
                CIMMS empowers Quezon City residents by providing a simple, fast, and transparent way to report public infrastructure problems 
                such as damaged roads, broken streetlights, clogged drainage systems, and other community facility concerns.
            </p>
        </div>

        <div class="section-box">
            <h2><span class="icon">🌐</span> Our Purpose</h2>
            <p>CIMMS was created to:</p>
            <ul class="purpose-list">
                <li>Improve the efficiency of public infrastructure maintenance</li>
                <li>Enhance communication between citizens and LGU offices</li>
                <li>Ensure faster response times to reported issues</li>
                <li>Promote transparency, accountability, and service quality</li>
            </ul>
        </div>

        <div class="section-box">
            <h2><span class="icon">🛠</span> What CIMMS Offers</h2>
            <p><b>Easy Issue Reporting</b> – Citizens can submit maintenance requests online with descriptions and photo evidence.</p>
            <p><b>Real-Time Tracking</b> – Monitor the status of submitted requests anytime.</p>
            <p><b>Faster Coordination</b> – Direct communication between LGU engineers, public works teams, and administrators.</p>
            <p><b>Secure Access</b> – Role-based system with strong data protection and authentication.</p>
            <p><b>Transparent Monitoring</b> – Dashboards and reports for performance tracking.</p>
        </div>

        <div class="section-box">
            <h2><span class="icon">🤝</span> For Quezon City Citizens</h2>
            <p>
                This platform is designed exclusively for <b>Quezon City residents</b>, ensuring that infrastructure concerns within the city 
                are addressed efficiently and responsibly. CIMMS strengthens public participation and supports a smarter, safer, and more 
                responsive city government.
            </p>
        </div>

        <div class="section-box">
            <h2><span class="icon">🎯</span> Our Vision</h2>
            <p>
                To become a trusted digital platform that enhances community engagement and delivers efficient, transparent, and responsive 
                infrastructure services for all Quezon City citizens.
            </p>
        </div>

        <div class="section-box">
            <h2><span class="icon">🚀</span> Our Mission</h2>
            <p>
                To provide an innovative and reliable system that streamlines infrastructure maintenance operations, strengthens public 
                accountability, and improves the overall quality of urban services in Quezon City.
            </p>
        </div>

        <div class="btn-wrap">
            <a href="<?= $BASE_URL ?>citizenrepform.php" class="btn">Submit a Report</a>
        </div>
    </div>
</div>


<footer class="footer">
    <div class="footer-links">
        <a href="<?= $BASE_URL ?>citizencimm.php">Privacy Policy</a>
        <a href="<?= $BASE_URL ?>about.php">About</a>
        <a href="#">Help</a>
    </div>
    <div class="footer-logo">© 2026 LGU Citizen Portal · All Rights Reserved</div>
</footer>

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

<!-- URL CLEANER: Removes ?staff=field2026 from address bar after authentication -->
<script>
// Clean URL after secret key authentication to prevent sharing
if (window.location.search.includes('staff=infrastructure_staff_2026_qr8p')) {
    // Remove the parameter from URL without reloading
    const cleanUrl = window.location.pathname;
    window.history.replaceState({}, document.title, cleanUrl);
}
</script>
</body>
</html>
