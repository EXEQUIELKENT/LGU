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
    <link rel="stylesheet" href="<?= $BASE_URL ?>citizen_global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

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
        }
        .form-wrapper {
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 110px 16px 40px;
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

        @media (max-width: 1024px) {
            .footer-content {
                grid-template-columns: 1fr 1fr;
            }
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

            .footer-content {
                grid-template-columns: 1fr;
            }

            .footer-bottom {
                flex-direction: column;
                gap: 20px;
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
    <?php include 'citizen_rendering.php'; ?>
</head>
<body>

<!-- DESKTOP NAVIGATION -->
<header class="nav">
    <a href="https://infragovservices.com/" class="site-logo" target="_blank" rel="noopener noreferrer">
        <img src="<?= $OFFICIAL_LOGO ?>" alt="LGU Logo" style="width: 40px; border-radius: 8px;">
        <span data-i18n="site_title">InfraGovServices</span>
    </a>
    
    <div class="nav-center">
        <div class="nav-links">
            <?php if ($show_login): ?>
                <a href="<?= $BASE_URL ?>login.php" data-i18n="nav_login">Log in</a>
            <?php endif; ?>
            <a href="<?= $BASE_URL ?>citizencimm.php" data-i18n="nav_home">Home</a>
            <a href="<?= $BASE_URL ?>citizenreports.php" data-i18n="nav_reports">Reports</a>
            <a href="<?= $BASE_URL ?>citizenrepform.php" data-i18n="nav_requests">Requests</a>
            <a href="<?= $BASE_URL ?>about.php" class="active" data-i18n="nav_about">About</a>
        </div>
        
        <div class="nav-divider"></div>
        
        <div class="nav-actions">
            <div class="desktop-clock" id="desktopClock"></div>

            <!-- TRANSLATE BUTTON (desktop) -->
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
            <img src="<?= $OFFICIAL_LOGO ?>" alt="LGU Logo">
            <div class="sidebar-divider logo-divider"></div>
        </a>
        <div class="sidebar-logo-spacer"></div>
        
        <ul class="nav-list">
            <?php if ($show_login): ?>
                <li><a href="<?= $BASE_URL ?>login.php" class="nav-link"><i class="fas fa-sign-in-alt"></i><span data-i18n="nav_login">Log in</span></a></li>
            <?php endif; ?>
            <li><a href="<?= $BASE_URL ?>citizencimm.php" class="nav-link"><i class="fas fa-home"></i><span data-i18n="nav_home">Home</span></a></li>
            <li><a href="<?= $BASE_URL ?>citizenreports.php" class="nav-link"><i class="fas fa-file-alt"></i><span data-i18n="nav_reports">Reports</span></a></li>
            <li><a href="<?= $BASE_URL ?>citizenrepform.php" class="nav-link"><i class="fas fa-clipboard-list"></i><span data-i18n="nav_requests">Requests</span></a></li>
            <li><a href="<?= $BASE_URL ?>about.php" class="nav-link active"><i class="fas fa-info-circle"></i><span data-i18n="nav_about">About</span></a></li>
        </ul>
    </div>
</div>

<!-- MOBILE TOP NAV -->
<div class="mobile-top-nav">
    <button class="mobile-toggle" id="mobileToggle">☰</button>
    <!-- MOBILE TRANSLATE BUTTON -->
    <button class="mobile-translate-btn" id="mobileTranslateBtn" data-i18n-title="translate_btn_title" title="Translate">
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

<div class="form-wrapper">
    <div class="about-card">
        <h1 data-i18n="about_title">About CIMMS – Quezon City</h1>

        <div class="section-box intro">
            <p data-i18n-html="about_intro_p1">
                <b>Community Infrastructure Maintenance Management System (CIMMS)</b> is a modern digital platform developed for the 
                <b>Local Government of Quezon City</b> to improve how infrastructure concerns are reported, managed, and resolved across the city.
            </p>
            <p data-i18n-html="about_intro_p2">
                CIMMS empowers Quezon City residents by providing a simple, fast, and transparent way to report public infrastructure problems 
                such as damaged roads, broken streetlights, clogged drainage systems, and other community facility concerns.
            </p>
        </div>

        <div class="section-box">
            <h2><span class="icon"><i class="fas fa-globe"></i></span> <span data-i18n="about_purpose_title">Our Purpose</span></h2>
            <p data-i18n="about_purpose_intro">CIMMS was created to:</p>
            <ul class="purpose-list">
                <li data-i18n="about_purpose_item1">Improve the efficiency of public infrastructure maintenance</li>
                <li data-i18n="about_purpose_item2">Enhance communication between citizens and LGU offices</li>
                <li data-i18n="about_purpose_item3">Ensure faster response times to reported issues</li>
                <li data-i18n="about_purpose_item4">Promote transparency, accountability, and service quality</li>
            </ul>
        </div>

        <div class="section-box">
            <h2><span class="icon"><i class="fas fa-tools"></i></span> <span data-i18n="about_offers_title">What CIMMS Offers</span></h2>
            <p data-i18n-html="about_offers_feature1"><b>Easy Issue Reporting</b> – Citizens can submit maintenance requests online with descriptions and photo evidence.</p>
            <p data-i18n-html="about_offers_feature2"><b>Real-Time Tracking</b> – Monitor the status of submitted requests anytime.</p>
            <p data-i18n-html="about_offers_feature3"><b>Faster Coordination</b> – Direct communication between LGU engineers, public works teams, and administrators.</p>
            <p data-i18n-html="about_offers_feature4"><b>Secure Access</b> – Role-based system with strong data protection and authentication.</p>
            <p data-i18n-html="about_offers_feature5"><b>Transparent Monitoring</b> – Dashboards and reports for performance tracking.</p>
        </div>

        <div class="section-box">
            <h2><span class="icon"><i class="fas fa-handshake"></i></span> <span data-i18n="about_citizens_title">For Quezon City Citizens</span></h2>
            <p data-i18n-html="about_citizens_desc">
                This platform is designed exclusively for <b>Quezon City residents</b>, ensuring that infrastructure concerns within the city 
                are addressed efficiently and responsibly. CIMMS strengthens public participation and supports a smarter, safer, and more 
                responsive city government.
            </p>
        </div>

        <div class="section-box">
            <h2><span class="icon"><i class="fas fa-bullseye"></i></span> <span data-i18n="about_vision_title">Our Vision</span></h2>
            <p data-i18n="about_vision_desc">
                To become a trusted digital platform that enhances community engagement and delivers efficient, transparent, and responsive 
                infrastructure services for all Quezon City citizens.
            </p>
        </div>

        <div class="section-box">
            <h2><span class="icon"><i class="fas fa-rocket"></i></span> <span data-i18n="about_mission_title">Our Mission</span></h2>
            <p data-i18n="about_mission_desc">
                To provide an innovative and reliable system that streamlines infrastructure maintenance operations, strengthens public 
                accountability, and improves the overall quality of urban services in Quezon City.
            </p>
        </div>

        <div class="section-box">
            <h2><span class="icon"><i class="fas fa-heart"></i></span> <span data-i18n="about_values_title">Our Core Values</span></h2>
            <p data-i18n-html="about_values_intro"><b>Committed to building a safer, more sustainable Quezon City for every resident.</b></p>

            <p data-i18n-html="about_values_efficiency"><b><i class="fas fa-bullseye"></i> Efficiency</b> – We work smart and fast to ensure quick action and better service delivery.</p>

            <p data-i18n-html="about_values_transparency"><b><i class="fas fa-star"></i> Transparency</b> – We keep everything clear, open, and trackable from report to resolution.</p>

            <p data-i18n-html="about_values_community"><b><i class="fas fa-handshake"></i> Community First</b> – We listen, care, and prioritize the needs of Quezon City citizens.</p>

            <p data-i18n-html="about_values_security"><b><i class="fas fa-lock"></i> Security</b> – We protect your personal data and ensure safe, reliable system access.</p>
        </div>

        <div class="btn-wrap">
            <a href="<?= $BASE_URL ?>citizenrepform.php" class="btn" data-i18n="about_cta_button">Submit a Report</a>
        </div>
    </div>
</div>

<!-- FOOTER -->
<footer class="footer">
    <div class="footer-content">
        <div class="footer-about">
            <h3>InfraGovServices</h3>
            <p>Community Infrastructure Maintenance Management System for Quezon City. Dedicated to providing efficient, transparent, and responsive infrastructure services for all residents.</p>
            <div class="footer-contact">
                <div class="contact-item">
                    <span><i class="fas fa-envelope"></i></span>
                    <span>contact@infragovservices.com</span>
                </div>
                <div class="contact-item">
                    <span><i class="fas fa-phone"></i></span>
                    <span>(02) 8988-4242</span>
                </div>
                <div class="contact-item">
                    <span><i class="fas fa-map-marker-alt"></i></span>
                    <span>Quezon City Hall, Quezon City</span>
                </div>
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
<?php include 'chatbot-widget.php'; ?>

</body>
</html>