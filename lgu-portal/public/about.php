<?php
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
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body {
            background: url("<?= $BASE_URL ?>cityhall.jpeg") center/cover no-repeat fixed;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        body::before {
            content: "";
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            backdrop-filter: blur(8px);
            background: rgba(0, 0, 0, 0.4);
            z-index: -1;
        }
        .nav {
            width: 100%;
            padding: 18px 60px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(255, 255, 255, 0.87);
            backdrop-filter: blur(18px);
            border-bottom: 2px solid rgba(0, 0, 0, 0.6);
            box-shadow: 0 4px 25px rgba(0,0,0,0.25);
            position: fixed;
            top: 0;
            left: 0;
            z-index: 100;
        }
        .site-logo { display: flex; align-items: center; gap: 10px; color: black; font-weight: 600; }
        .site-logo img { width: 40px; height: auto; border-radius: 8px; }
        .nav-links a {
            margin-left: 25px;
            text-decoration: none;
            cursor: pointer;
            color: black;
            opacity: .8;
            transition: .2s;
        }
        .nav-links a.active { opacity: 1; font-weight: 600; }
        .nav-links a:hover { opacity: 1; }
        .menu-toggle { display: none; font-size: 26px; cursor: pointer; background: none; border: none; margin-left: 18px; color: black; }
        .about-section {
            flex: 1;
            padding: 120px 40px 60px;
            max-width: 900px;
            margin: 0 auto;
            color: #fff;
        }
        .about-section h1 {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            text-shadow: 2px 2px 8px #000, 0 0 6px #000;
        }
        .about-section .divider {
            width: 80px;
            height: 4px;
            background: rgba(255,255,255,0.8);
            border: none;
            margin: 0 0 1.5rem 0;
            border-radius: 2px;
        }
        .about-section p {
            font-size: 1.1rem;
            line-height: 1.7;
            opacity: 0.95;
        }
        .about-section a.btn {
            display: inline-block;
            margin-top: 1.5rem;
            padding: 12px 24px;
            background: rgba(255,255,255,0.95);
            color: #1a1a1a;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: background .2s, transform .2s;
        }
        .about-section a.btn:hover {
            background: #fff;
            transform: translateY(-2px);
        }
        .footer {
            width: 100%;
            padding: 26px 60px 22px;
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(8px);
            border-top: 1px solid rgba(255,255,255,0.18);
            margin-top: auto;
            flex-shrink: 0;
            position: relative;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
        }
        .footer-links a {
            margin-right: 25px;
            text-decoration: none;
            color: #fff;
            opacity: .8;
            transition: .2s;
        }
        .footer-links a:hover { opacity: 1; font-weight: 600; }
        .footer-logo { color: #fff; font-weight: 500; }
        @media (max-width: 768px) {
            .nav { padding: 18px 13px; }
            .nav-links { display: none; position: absolute; top: 60px; right: 10px; background: rgba(0,0,0,.86); border-radius: 12px; padding: 15px; flex-direction: column; min-width: 160px; z-index: 999; }
            .nav-links.show { display: flex; }
            .nav-links a { color: #fff !important; margin-left: 0; margin-bottom: 8px; }
            .nav-links a:last-child { margin-bottom: 0; }
            .menu-toggle { display: block; }
            .about-section { padding: 100px 20px 40px; }
            .about-section h1 { font-size: 1.75rem; }
            .footer { padding: 20px 15px; flex-direction: column; text-align: center; }
            .footer-links { margin-bottom: 10px; }
        }
    </style>
</head>
<body>

<header class="nav">
    <div class="site-logo">
        <img src="<?= $OFFICIAL_LOGO ?>" alt="LGU Logo" style="width: 40px; border-radius: 8px;">
        <span>InfraGovServices - Infrastructure and Utilities</span>
    </div>
    <div class="nav-links">
        <a href="<?= $BASE_URL ?>login.php">Log in</a>
        <a href="<?= $BASE_URL ?>citizendash.php">Home</a>
        <a href="<?= $BASE_URL ?>citizenrepform.php">Requests</a>
        <a href="<?= $BASE_URL ?>about.php" class="active">About</a>
    </div>
    <div class="menu-toggle" aria-label="Menu">☰</div>
</header>

<section class="about-section">

    <h1> About CIMMS – Quezon City</h1>
    <hr class="divider">

    <div class="section-box">
        <p>
            <b>Community Infrastructure Maintenance Management System (CIMMS)</b> is a modern digital platform developed for the 
            <b>Local Government of Quezon City</b> to improve how infrastructure concerns are reported, managed, and resolved across the city.
        </p>
        <p>
            CIMMS empowers Quezon City residents by providing a simple, fast, and transparent way to report public infrastructure problems 
            such as damaged roads, broken streetlights, clogged drainage systems, and other community facility concerns.
        </p>
    </div>  <br><br>
    
    <div class="section-box">
        <h2><b>🌐 Our Purpose</b></h2>
        <p>
            CIMMS was created to:
        </p>
        <p>
            • Improve the efficiency of public infrastructure maintenance <br>
            • Enhance communication between citizens and LGU offices <br>
            • Ensure faster response times to reported issues <br>
            • Promote transparency, accountability, and service quality
        </p>
    </div> <br><br>
    <div class="section-box">
        <h2><b>🛠 What CIMMS Offers</b></h2>
        <p>
            <b>Easy Issue Reporting</b> – Citizens can submit maintenance requests online with descriptions and photo evidence. 
            <b>Real-Time Tracking</b> – Monitor the status of submitted requests anytime. 
            <b>Faster Coordination</b> – Direct communication between LGU engineers, public works teams, and administrators. 
            <b>Secure Access</b> – Role-based system with strong data protection and authentication. 
            <b>Transparent Monitoring</b> – Dashboards and reports for performance tracking.
        </p>
    </div> <br><br>

    <div class="section-box">
        <h2><b>🤝 For Quezon City Citizens</b></h2>
        <p>
            This platform is designed exclusively for <b>Quezon City residents</b>, ensuring that infrastructure concerns within the city 
            are addressed efficiently and responsibly. CIMMS strengthens public participation and supports a smarter, safer, and more 
            responsive city government.
        </p>
    </div> <br><br>

    <div class="section-box">
        <h2><b>🎯 Our Vision</b></h2>
        <p>
            To become a trusted digital platform that enhances community engagement and delivers efficient, transparent, and responsive 
            infrastructure services for all Quezon City citizens.
        </p>
    </div> <br><br>

    <div class="section-box">
        <h2><b>🚀 Our Mission</b></h2>
        <p>
            To provide an innovative and reliable system that streamlines infrastructure maintenance operations, strengthens public 
            accountability, and improves the overall quality of urban services in Quezon City.
        </p>
    </div> <br><br>

    <a href="<?= $BASE_URL ?>citizenrepform.php" class="btn">📨 Submit a Report</a>

</section>


<footer class="footer">
    <div class="footer-links">
        <a href="<?= $BASE_URL ?>citizencimm.php">Privacy Policy</a>
        <a href="<?= $BASE_URL ?>about.php">About</a>
        <a href="#">Help</a>
    </div>
    <div class="footer-logo">© 2026 LGU Citizen Portal · All Rights Reserved</div>
</footer>

<script>
    document.querySelector('.menu-toggle')?.addEventListener('click', function() {
        document.querySelector('.nav-links').classList.toggle('show');
    });
</script>
</body>
</html>
