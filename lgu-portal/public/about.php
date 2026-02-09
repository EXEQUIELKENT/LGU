<?php
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
        }

        body::-webkit-scrollbar {
            display: none;
        }
        .nav {
            width: 100%;
            padding: 18px 60px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(255, 255, 255, 0.87);;
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border-bottom: 2px solid rgba(0, 0, 0, 0.6);
            box-shadow: 0 4px 25px rgba(0,0,0,0.25);
            position: fixed;
            top: 0;
            left: 0;
            z-index: 100;
        }
        .site-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            color: black;
            font-weight: 600;
        }
        .site-logo img {
            width: 40px; height: auto; border-radius: 8px;
        }
        .nav a {
            margin-left: 25px;
            color: black;
            text-decoration: none;
            font-weight: 500;
            opacity: 0.85;
            transition: 0.2s;
        }
        .nav-links a {
            margin-left: 25px;
            text-decoration: none;
            cursor: pointer;
            color: black;
            opacity: .8;
            transition: .2s;
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
        .menu-toggle {
            display: none;
            font-size: 26px;
            cursor: pointer;
            color: #fff;
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
        .about-card {
            width: 100%;
            max-width: 900px;
            background: rgba(235, 234, 234, 0.95);
            padding: 48px 44px 44px;
            border-radius: 22px;
            box-shadow: 0 20px 45px rgba(0,0,0,.25), 0 0 0 1px rgba(0,0,0,.06);
            transition: all .25s ease;
            color: #333;
            border-top: 4px solid #2b6cb0;
        }
        .about-card h1 {
            margin-bottom: 20px;
            font-size: 2rem;
            line-height: 1.25;
            color: #212121;
            text-align: center;
            letter-spacing: .02em;
            font-weight: 700;
        }
        .about-card .divider {
            width: 80px;
            height: 4px;
            background: linear-gradient(90deg, #2b6cb0, #3b82f6);
            border: none;
            margin: 0 auto 32px auto;
            border-radius: 2px;
        }
        .about-card .section-box {
            margin-bottom: 24px;
            padding: 22px 24px;
            background: #f8fafc;
            border-radius: 14px;
            border-left: 4px solid #2b6cb0;
            transition: box-shadow .2s ease, transform .15s ease;
        }
        .about-card .section-box:hover {
            box-shadow: 0 4px 12px rgba(43, 108, 176, 0.08);
        }
        .about-card .section-box.intro {
            background: linear-gradient(135deg, #f0f7ff 0%, #f8fafc 100%);
        }
        .about-card h2 {
            font-size: 1.35rem;
            color: #1e3c72;
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
            color: #374151;
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
            color: #374151;
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
            width: fit-content;
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
            .dashboard-container { padding: 100px 13px 40px; }
            .container { padding: 0 5px; }
            .nav { padding: 18px 13px;}
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 18px;
            }
            .welcome-section h1 {
            text-align: center;
            font-size: 2rem;
            font-weight: 600;
            }
            .nav-links {
                display: none;
                position: absolute;
                top: 60px;
                right: 10px;
                background: rgba(0,0,0,.86);
                border-radius: 12px;
                padding: 15px;
                flex-direction: column;
                box-shadow: 0 4px 18px rgba(0,0,0,.25);
                min-width: 160px;
                z-index: 999;
            }
            .nav-links.show {
                display: flex;
            }
            .nav-links a {
                color: #fff !important;
            }
            .nav {
            background: #fff;    /* softer glass */
            }
            .nav span{
            color: black;  
            }
            .menu-toggle {
            color: black;
            margin-right: 10px;
            }
            .menu-toggle {
                display: block;
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
            .dashboard-container { padding: 100px 13px 40px; }
            .container { padding: 0 5px; }
            .nav { padding: 18px 13px;}
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 18px;
            }
            .welcome-section h1 {
            text-align: center;
            font-size: 2rem;
            font-weight: 600;
            }
            .nav-links {
                display: none;
                position: absolute;
                top: 60px;
                right: 10px;
                background: rgba(0,0,0,.86);
                border-radius: 12px;
                padding: 15px;
                flex-direction: column;
                box-shadow: 0 4px 18px rgba(0,0,0,.25);
                min-width: 160px;
                z-index: 999;
            }
            .nav-links.show {
                display: flex;
            }
            .nav-links a {
                color: #fff !important;
            }
            .nav {
            background: #fff;    /* softer glass */
            }
            .nav span{
            color: black;  
            }
            .menu-toggle {
            color: black;
            margin-right: 10px;
            }
            .menu-toggle {
                display: block;
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
            .form-wrapper {
                margin-top: 20px !important;
                padding: 100px 5vw 40px !important;
            }
            .about-card {
                padding: 17px 0vw 17px 7vw !important; /* Added more left and right padding */
                max-width: 99vw;
            }
            .about-card h1 {
                font-size: 1.75rem;
                padding: 18px 6vw 0;
            }
            .about-card h2 {
                font-size: 1.25rem;
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
</head>
<body>

<header class="nav">
        <div class="site-logo">
            <img src="assets/img/officiallogo.png" alt="LGU Logo" style="width: 40px; border-radius: 8px;">
            <span>InfraGovServices - Infrastructure and Utilities</span>
        </div>
        <div class="nav-links">
        <?php if ($show_login): ?>
        <a href="login.php">Log in</a>
        <?php endif; ?>
            <a href="citizencimm.php">Home</a>
            <a href="citizenrepform.php">Requests</a>
            <a href="#" class="active">About</a>
        </div>
        <div class="menu-toggle">☰</div>
    </header>

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
document.querySelector('.menu-toggle')
    .addEventListener('click', () => {
        document.querySelector('.nav-links').classList.toggle('show');
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
</body>
</html>
