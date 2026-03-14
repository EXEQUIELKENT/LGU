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

// Get statistics for the landing page
$repairs_count = 0;
$repairs_result = $conn->query("SELECT COUNT(*) as count FROM repair_archive");
if ($repairs_result) {
    $repairs_row = $repairs_result->fetch_assoc();
    $repairs_count = $repairs_row['count'];
}

$ongoing_count = 0;
$ongoing_result = $conn->query("SELECT COUNT(*) as count FROM maintenance_schedule WHERE status = 'In Progress'");
if ($ongoing_result) {
    $ongoing_row = $ongoing_result->fetch_assoc();
    $ongoing_count = $ongoing_row['count'];
}

$pending_count = 0;
$pending_result = $conn->query("SELECT COUNT(*) as count FROM requests WHERE approval_status = 'Pending'");
if ($pending_result) {
    $pending_row = $pending_result->fetch_assoc();
    $pending_count = $pending_row['count'];
}

// Get recent maintenance for preview
$recent_maintenance = array();
$maintenance_result = $conn->query("
    SELECT sched_id, task, location, status, starting_date 
    FROM maintenance_schedule 
    ORDER BY starting_date DESC 
    LIMIT 3
");
if ($maintenance_result) {
    while ($row = $maintenance_result->fetch_assoc()) {
        $recent_maintenance[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="<?= $OFFICIAL_LOGO ?>" type="image/png">
    <title>InfraGovServices - Community Infrastructure Maintenance</title>
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
        body {
            background: url("<?= $BASE_URL ?>cityhall.jpeg") center/cover no-repeat fixed;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            transition: background 0.3s ease;
        }

        /* ============================================================
           CSS VARIABLES — Light mode (default)
           ============================================================ */
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
            --accent-primary: #2b6cb0;
            --accent-secondary: #3762c8;
            --accent-light: #e6f0ff;

            /* Light-mode card styling — blue-accent borders + blue-tinted shadows */
            --card-border: 1.5px solid rgb(47, 99, 156);
            --card-shadow: 0 4px 20px rgba(0, 0, 0, 0.45);
            --card-shadow-hover: 0 8px 32px rgba(0, 0, 0, 0.60);
        }

        /* ============================================================
           CSS VARIABLES — Dark mode overrides
           ============================================================ */
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
            --accent-primary: #4a8fd8;
            --accent-secondary: #5a9fe8;
            --accent-light: #1e3a5f;

            /* Dark-mode card styling — neutral, no blue tint */
            --card-border: 1px solid rgba(255, 255, 255, 0.08);
            --card-shadow: 0 4px 20px rgba(0, 0, 0, 0.45);
            --card-shadow-hover: 0 8px 32px rgba(0, 0, 0, 0.60);
        }

        /* ============================================================
           HERO SECTION
           ============================================================ */
        .hero-section {
            padding: 100px 20px 80px;
            text-align: center;
            color: #fff;
        }

        .hero-title {
            font-size: 4rem;
            font-weight: 900;
            margin-bottom: 20px;
            text-shadow: 2px 2px 8px #000, 0 0 6px #000, 0 0 3px #000, 0 0 1px #fff;
            animation: fadeInUp 0.8s ease;
        }

        .hero-subtitle {
            font-size: 1.5rem;
            margin-bottom: 15px;
            text-shadow: 1px 1px 4px #000;
            animation: fadeInUp 0.8s ease 0.2s backwards;
        }

        .hero-tagline {
            font-size: 1.1rem;
            margin-bottom: 40px;
            opacity: 0.95;
            text-shadow: 1px 1px 4px #000;
            animation: fadeInUp 0.8s ease 0.3s backwards;
            max-width: 700px;
            margin-left: auto;
            margin-right: auto;
        }

        .hero-cta {
            display: flex;
            gap: 20px;
            justify-content: center;
            flex-wrap: wrap;
            animation: fadeInUp 0.8s ease 0.4s backwards;
        }

        .cta-button {
            padding: 16px 40px;
            font-size: 18px;
            font-weight: 600;
            border-radius: 14px;
            text-decoration: none;
            transition: all 0.3s ease;
            cursor: pointer;
            border: none;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: fit-content;
        }

        .cta-primary {
            background: linear-gradient(135deg, #2b6cb0, #1d4ed8);
            color: #fff;
            box-shadow: 0 4px 14px rgba(43, 108, 176, 0.35);
        }

        .cta-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 22px rgba(43, 108, 176, 0.45);
        }

        .cta-secondary {
            background: rgba(255, 255, 255, 0.2);
            color: #fff;
            border: 2px solid #fff;
            backdrop-filter: blur(10px);
        }

        .cta-secondary:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-3px);
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ============================================================
           STATISTICS SECTION
           ============================================================ */
        .stats-section {
            padding: 60px 20px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 30px;
            margin-top: 40px;
        }

        .stat-card {
            background: var(--card-bg);
            backdrop-filter: blur(10px);
            padding: 40px 30px;
            border-radius: 20px;
            border: var(--card-border);
            box-shadow: var(--card-shadow);
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--card-shadow-hover);
        }

        .stat-icon { font-size: 48px; margin-bottom: 20px; }

        .stat-number {
            font-size: 48px;
            font-weight: 700;
            color: var(--accent-primary);
            margin-bottom: 10px;
        }

        .stat-label {
            font-size: 16px;
            color: var(--text-secondary);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* ============================================================
           TRUST INDICATORS
           ============================================================ */
        .trust-section {
            padding: 40px 20px;
            max-width: 1200px;
            margin: 0 auto;
        }



        .trust-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 30px;
            position: relative;
        }

        .trust-item {
            text-align: center;
            padding: 30px 20px;
            background: var(--card-bg);
            border-radius: 16px;
            border: var(--card-border);
            box-shadow: var(--card-shadow);
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
            position: relative;
            z-index: 2;
        }

        .trust-item::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 100%;
            width: 30px;
            height: 3px;
            background: linear-gradient(90deg, #2b6cb0, #3b82f6);
            transform: translateY(-50%);
            z-index: 1;
            opacity: 0;
            animation: slideInLine 0.8s ease forwards;
            box-shadow: 0 0 8px rgba(43, 108, 176, 0.5);
        }

        .trust-item::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 100%;
            width: 8px;
            height: 8px;
            background: #3b82f6;
            border-radius: 50%;
            transform: translate(-50%, -50%);
            z-index: 3;
            opacity: 0;
            box-shadow: 0 0 12px rgba(59, 130, 246, 0.8);
            animation: travelDot 2s ease-in-out infinite;
            animation-delay: 0.8s;
        }

        .trust-item:nth-child(4)::after,
        .trust-item:nth-child(4)::before { display: none; }

        @keyframes slideInLine {
            from { opacity: 0; width: 0; }
            to { opacity: 1; width: 30px; }
        }

        @keyframes travelDot {
            0%, 100% { opacity: 0; left: 100%; }
            10% { opacity: 1; }
            90% { opacity: 1; }
            50% { left: calc(100% + 15px); opacity: 1; }
        }

        .trust-item:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }

        .trust-item:hover::after {
            background: linear-gradient(90deg, #1d4ed8, #60a5fa);
            box-shadow: 0 0 15px rgba(43, 108, 176, 0.8);
        }

        .trust-icon { font-size: 36px; margin-bottom: 15px; }

        .trust-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 8px;
        }

        .trust-desc {
            font-size: 0.95rem;
            color: var(--text-secondary);
            line-height: 1.5;
        }

        /* ============================================================
           FEATURES SECTION
           ============================================================ */
        .features-section {
            padding: 80px 20px;
            background: var(--bg-secondary);
            backdrop-filter: blur(10px);
            margin: 0 20px;
            border-radius: 30px;
            border: var(--card-border);
            box-shadow: var(--card-shadow);
        }

        .section-title {
            text-align: center;
            font-size: 3rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 20px;
        }

        .section-subtitle {
            text-align: center;
            font-size: 1.2rem;
            color: var(--text-secondary);
            margin-bottom: 60px;
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 40px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .feature-card {
            background: var(--card-bg);
            padding: 40px 30px;
            border-radius: 20px;
            border: var(--card-border);
            box-shadow: var(--card-shadow);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-primary), var(--accent-secondary));
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }

        .feature-card:hover::before { transform: scaleX(1); }

        .feature-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--card-shadow-hover);
        }

        .feature-icon { font-size: 48px; margin-bottom: 20px; }

        .feature-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 15px;
        }

        .feature-description {
            font-size: 1rem;
            color: var(--text-secondary);
            line-height: 1.6;
            margin-bottom: 20px;
        }

        .feature-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--accent-primary);
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .feature-link:hover { gap: 12px; }

        /* ============================================================
           HOW IT WORKS SECTION
           ============================================================ */
        .how-it-works-section {
            padding: 80px 20px;
            max-width: 1400px;
            margin: 0 auto;
        }



        .section-header-card {
            background: var(--card-bg);
            backdrop-filter: blur(10px);
            padding: 40px 30px;
            border-radius: 20px;
            border: var(--card-border);
            box-shadow: var(--card-shadow);
            margin-bottom: 40px;
            text-align: center;
        }

        .section-header-card .section-title { margin-bottom: 15px; }
        .section-header-card .section-subtitle { margin-bottom: 0; }

        .steps-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 40px;
            margin-top: 60px;
        }

        .step-card {
            text-align: center;
            position: relative;
            padding: 30px 25px;
            background: var(--card-bg);
            border-radius: 16px;
            border: var(--card-border);
            box-shadow: var(--card-shadow);
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }

        .step-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }

        .step-number {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
            color: #fff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: 700;
            margin: 0 auto 20px;
            box-shadow: 0 4px 15px rgba(43, 108, 176, 0.3);
        }

        .step-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 10px;
        }

        .step-description {
            font-size: 1rem;
            color: var(--text-secondary);
            line-height: 1.6;
        }

        /* ============================================================
           ABOUT SECTION
           ============================================================ */
        .about-section {
            padding: 80px 20px;
            background: var(--bg-secondary);
            backdrop-filter: blur(10px);
            margin: 0 20px;
            border-radius: 30px;
            border: var(--card-border);
            box-shadow: var(--card-shadow);
        }

        .about-container { max-width: 1400px; margin: 0 auto; }

        .about-header {
            text-align: center;
            margin-bottom: 60px;
        }

        .about-header h2 {
            font-size: 3rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 20px;
        }

        .about-header p {
            font-size: 1.2rem;
            color: var(--text-secondary);
            max-width: 800px;
            margin: 0 auto;
            line-height: 1.7;
        }

        .about-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 60px;
            align-items: center;
            margin-bottom: 60px;
        }

        .about-image-container { position: relative; }

        .about-image-wrapper {
            position: relative;
            border-radius: 20px;
            overflow: hidden;
            border: var(--card-border);
            box-shadow: var(--card-shadow);
        }

        .about-image-wrapper img {
            width: 100%;
            height: auto;
            display: block;
            border-radius: 20px;
        }

        .about-image-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(to top, rgba(0,0,0,0.8), transparent);
            padding: 30px;
            color: #fff;
        }

        .about-image-overlay h3 { font-size: 1.5rem; margin-bottom: 5px; }
        .about-image-overlay p { font-size: 0.95rem; opacity: 0.9; }

        .about-text { padding: 20px 0; }

        .about-text h3 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 20px;
        }

        .about-text p {
            font-size: 1.05rem;
            color: var(--text-secondary);
            line-height: 1.8;
            margin-bottom: 20px;
        }

        .about-highlights {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin: 30px 0;
        }

        .highlight-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 15px;
            background: var(--accent-light);
            border-radius: 12px;
            border: var(--card-border);
            transition: all 0.3s ease;
        }

        .highlight-item:hover { transform: translateX(5px); }
        .highlight-icon { font-size: 24px; flex-shrink: 0; }
        .highlight-text { flex: 1; }

        .highlight-text strong {
            display: block;
            color: var(--text-primary);
            margin-bottom: 3px;
            font-weight: 600;
        }

        .highlight-text span {
            font-size: 0.9rem;
            color: var(--text-secondary);
        }

        .about-mission {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 50px;
            border: var(--card-border);
            box-shadow: var(--card-shadow);
            margin-top: 60px;
        }

        .mission-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 40px;
            margin-top: 40px;
        }

        .mission-card { text-align: center; }

        .mission-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            margin: 0 auto 20px;
            box-shadow: 0 8px 20px rgba(43, 108, 176, 0.3);
        }

        .mission-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 10px;
        }

        .mission-description {
            font-size: 1rem;
            color: var(--text-secondary);
            line-height: 1.6;
        }

        /* ============================================================
           RECENT ACTIVITY SECTION
           ============================================================ */
        .activity-section {
            padding: 80px 20px;
            max-width: 1400px;
            margin: 0 auto;
        }



        .activity-card {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 40px;
            border: var(--card-border);
            box-shadow: var(--card-shadow);
        }

        .activity-card .section-title { text-align: center; margin-bottom: 15px; }
        .activity-card .section-subtitle { text-align: center; margin-bottom: 40px; }

        .activity-list { list-style: none; padding: 0; }

        .activity-item {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.2s ease;
        }

        .activity-item:last-child { border-bottom: none; }

        .activity-item:hover {
            background: var(--bg-tertiary);
            padding-left: 30px;
        }

        .activity-info h4 { color: var(--text-primary); margin-bottom: 5px; }
        .activity-info p { color: var(--text-secondary); font-size: 0.9rem; }

        .status-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .status-pending { background: #fff3cd; color: #856404; }
        .status-progress { background: #cce5ff; color: #004085; }
        .status-completed { background: #d4edda; color: #155724; }

        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 1024px) {
            .about-content { grid-template-columns: 1fr; }
            .footer-content { grid-template-columns: 1fr 1fr; }
            .trust-grid { grid-template-columns: repeat(2, 1fr); }
            .trust-item:nth-child(2)::after, .trust-item:nth-child(2)::before,
            .trust-item:nth-child(4)::after, .trust-item:nth-child(4)::before { display: none; }
            .trust-item:nth-child(1)::after, .trust-item:nth-child(1)::before,
            .trust-item:nth-child(3)::after, .trust-item:nth-child(3)::before { display: block; }
        }

        /* SCROLL ANIMATIONS */
        .animate-on-scroll {
            opacity: 0;
            transform: translateY(30px);
            transition: opacity 0.8s ease, transform 0.8s ease;
        }

        .animate-on-scroll.animate-in { opacity: 1; transform: translateY(0); }
        .animate-on-scroll.delay-1 { transition-delay: 0.1s; }
        .animate-on-scroll.delay-2 { transition-delay: 0.2s; }
        .animate-on-scroll.delay-3 { transition-delay: 0.3s; }
        .animate-on-scroll.delay-4 { transition-delay: 0.4s; }

        @media (max-width: 768px) {
            .cta-button {
                font-size: 15px;
                padding: 14px 28px;
                white-space: normal;
                text-align: center;
                line-height: 1.3;
            }

            .hero-title { font-size: 2.5rem; }
            .hero-subtitle { font-size: 1.2rem; }
            .hero-tagline { font-size: 1rem; }
            .section-title { font-size: 2rem; }

            .about-text h3 { font-size: 2rem; text-align: center; }
            .about-text p { font-size: 1.05rem; }
            .about-header h2 { font-size: 2rem; }
            .about-highlights { grid-template-columns: 1fr; }
            .about-mission { padding: 30px 20px; }
            .mission-grid { grid-template-columns: 1fr; gap: 30px; }
            .footer-content { grid-template-columns: 1fr; }
            .footer-bottom { flex-direction: column; gap: 20px; }
            .stats-grid { grid-template-columns: 1fr; }
            .features-grid { grid-template-columns: 1fr; }
            .steps-container { grid-template-columns: 1fr; }
            .trust-grid { grid-template-columns: 1fr; }

            .features-section, .about-section { margin: 0 10px; border-radius: 20px; }

            .trust-item::after {
                top: 100%;
                left: 50%;
                width: 3px;
                height: 30px;
                background: linear-gradient(180deg, #2b6cb0, #3b82f6);
                transform: translateX(-50%);
                animation: slideInLineVertical 0.8s ease forwards;
            }

            .trust-item::before {
                top: 100%;
                left: 50%;
                transform: translate(-50%, -50%);
                animation: travelDotVertical 2s ease-in-out infinite;
                animation-delay: 0.8s;
            }

            .trust-item:nth-child(1)::after, .trust-item:nth-child(1)::before,
            .trust-item:nth-child(2)::after, .trust-item:nth-child(2)::before,
            .trust-item:nth-child(3)::after, .trust-item:nth-child(3)::before { display: block; }
            .trust-item:nth-child(4)::after, .trust-item:nth-child(4)::before { display: none; }

            @keyframes slideInLineVertical {
                from { opacity: 0; height: 0; }
                to { opacity: 1; height: 30px; }
            }

            @keyframes travelDotVertical {
                0%, 100% { opacity: 0; top: 100%; }
                10% { opacity: 1; }
                90% { opacity: 1; }
                50% { top: calc(100% + 15px); opacity: 1; }
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
        <span data-i18n="site_title_short">InfraGovServices</span>
    </a>
    
    <div class="nav-center">
        <div class="nav-links">
            <?php if ($show_login): ?>
                <a href="<?= $BASE_URL ?>login.php" data-i18n="nav_login">Log in</a>
            <?php endif; ?>
            <a href="#" class="active" data-i18n="nav_home">Home</a>
            <a href="<?= $BASE_URL ?>citizenreports.php" data-i18n="nav_reports">Reports</a>
            <a href="<?= $BASE_URL ?>citizenrepform.php" data-i18n="nav_requests">Requests</a>
            <a href="<?= $BASE_URL ?>about.php" data-i18n="nav_about">About</a>
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

            <button class="nav-btn dark-mode-btn" id="darkModeBtn" title="Toggle Dark Mode">
                <span class="dark-icon">🌙</span>
                <span class="light-icon" style="display:none;">☀️</span>
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
        
        <ul class="nav-list">
            <?php if ($show_login): ?>
                <li><a href="<?= $BASE_URL ?>login.php" class="nav-link"><i class="fas fa-sign-in-alt"></i><span data-i18n="nav_login">Log in</span></a></li>
            <?php endif; ?>
            <li><a href="#" class="nav-link active"><i class="fas fa-home"></i><span data-i18n="nav_home">Home</span></a></li>
            <li><a href="<?= $BASE_URL ?>citizenreports.php" class="nav-link"><i class="fas fa-file-alt"></i><span data-i18n="nav_reports">Reports</span></a></li>
            <li><a href="<?= $BASE_URL ?>citizenrepform.php" class="nav-link"><i class="fas fa-clipboard-list"></i><span data-i18n="nav_requests">Requests</span></a></li>
            <li><a href="<?= $BASE_URL ?>about.php" class="nav-link"><i class="fas fa-info-circle"></i><span data-i18n="nav_about">About</span></a></li>
        </ul>
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
        <span class="light-icon" style="display:none;">☀️</span>
    </button>
</div>

<!-- LANGUAGE BADGE (toast) -->
<div class="lang-badge" id="langBadge">
    <span class="badge-flag" id="badgeFlag">🇺🇸</span>
    <span id="badgeText">Switched to English</span>
</div>

<div class="main-content">
    <!-- HERO SECTION -->
    <section class="hero-section">
        <h1 class="hero-title" data-i18n="hero_title">Welcome to InfraGovServices</h1>
        <p class="hero-subtitle" data-i18n="hero_subtitle">Community Infrastructure Maintenance Management System</p>
        <p class="hero-tagline" data-i18n="hero_tagline">Empowering Quezon City residents with efficient, transparent, and responsive infrastructure services</p>
        <div class="hero-cta">
            <a href="<?= $BASE_URL ?>citizenrepform.php" class="cta-button cta-primary" data-i18n="cta_submit_report">Submit a Report</a>
            <a href="#features" class="cta-button cta-secondary" data-i18n="cta_learn_more">Learn More</a>
        </div>
    </section>

    <!-- STATISTICS SECTION -->
    <section class="stats-section animate-on-scroll">
        <div class="stats-grid">
            <div class="stat-card animate-on-scroll delay-1">
                <div class="stat-icon">🛠️</div>
                <div class="stat-number"><?= $repairs_count ?></div>
                <div class="stat-label" data-i18n="stat_completed">Completed Repairs</div>
            </div>
            <div class="stat-card animate-on-scroll delay-2">
                <div class="stat-icon">⏳</div>
                <div class="stat-number"><?= $ongoing_count ?></div>
                <div class="stat-label" data-i18n="stat_ongoing">Ongoing Repairs</div>
            </div>
            <div class="stat-card animate-on-scroll delay-3">
                <div class="stat-icon">📍</div>
                <div class="stat-number"><?= $pending_count ?></div>
                <div class="stat-label" data-i18n="stat_pending">Pending Requests</div>
            </div>
        </div><!-- end stats-grid -->
    </section>

    <!-- TRUST INDICATORS -->
    <section class="trust-section animate-on-scroll">
        <div class="trust-grid">
            <div class="trust-item animate-on-scroll delay-1">
                <div class="trust-icon" style="color: #042cb1;"><i class="fas fa-lock"></i></div>
                <div class="trust-title" data-i18n="trust_secure_title">Secure &amp; Private</div>
                <div class="trust-desc" data-i18n="trust_secure_desc">Your data is protected with strong, trusted security.</div>
            </div>
            <div class="trust-item animate-on-scroll delay-2">
                <div class="trust-icon" style="color: #042cb1;"><i class="fas fa-bolt"></i></div>
                <div class="trust-title" data-i18n="trust_fast_title">Fast Response</div>
                <div class="trust-desc" data-i18n="trust_fast_desc">Reports are handled within 24–48 hours based on priority.</div>
            </div>
            <div class="trust-item animate-on-scroll delay-3">
                <div class="trust-icon" style="color: #042cb1;"><i class="fas fa-check-circle"></i></div>
                <div class="trust-title" data-i18n="trust_verified_title">Verified Reports</div>
                <div class="trust-desc" data-i18n="trust_verified_desc">Every report is carefully checked for accuracy.</div>
            </div>
            <div class="trust-item animate-on-scroll delay-4">
                <div class="trust-icon" style="color: #042cb1;"><i class="fas fa-award"></i></div>
                <div class="trust-title" data-i18n="trust_excellence_title">Service Excellence</div>
                <div class="trust-desc" data-i18n="trust_excellence_desc">Committed to quality, transparency, and public service.</div>
            </div>
        </div><!-- end trust-grid -->
    </section>

    <!-- FEATURES SECTION -->
    <section class="features-section animate-on-scroll" id="features">
        <h2 class="section-title" data-i18n="features_title">Our Services</h2>
        <p class="section-subtitle" data-i18n="features_subtitle">Empowering Quezon City residents with efficient infrastructure management tools</p>
        <div class="features-grid">
            <div class="feature-card animate-on-scroll delay-1">
                <div class="feature-icon" style="color: #042cb1;"><i class="fas fa-clipboard-list"></i></div>
                <h3 class="feature-title" data-i18n="feat1_title">Submit Requests</h3>
                <p class="feature-description" data-i18n="feat1_desc">Report infrastructure concerns with detailed descriptions and photo evidence, ensuring fast and accurate response.</p>
                <a href="<?= $BASE_URL ?>citizenrepform.php" class="feature-link" data-i18n="feat1_link">Submit Request →</a>
            </div>
            <div class="feature-card animate-on-scroll delay-2">
                <div class="feature-icon" style="color: #042cb1;"><i class="fas fa-chart-pie"></i></div>
                <h3 class="feature-title" data-i18n="feat2_title">Track Maintenance</h3>
                <p class="feature-description" data-i18n="feat2_desc">Monitor the status of maintenance schedules, view completed repairs, and stay informed about ongoing infrastructure improvements in your area.</p>
                <a href="<?= $BASE_URL ?>citizenreports.php" class="feature-link" data-i18n="feat2_link">View Reports →</a>
            </div>
            <div class="feature-card animate-on-scroll delay-3">
                <div class="feature-icon" style="color: #042cb1;"><i class="fas fa-map-marked-alt"></i></div>
                <h3 class="feature-title" data-i18n="feat3_title">Location-Based Reporting</h3>
                <p class="feature-description" data-i18n="feat3_desc">Use interactive maps and GPS-based tagging to accurately identify problem areas.</p>
                <a href="<?= $BASE_URL ?>citizenrepform.php" class="feature-link" data-i18n="feat3_link">Try It Now →</a>
            </div>
            <div class="feature-card animate-on-scroll delay-1">
                <div class="feature-icon" style="color: #042cb1;"><i class="fas fa-bolt"></i></div>
                <h3 class="feature-title" data-i18n="feat4_title">Real-Time Updates</h3>
                <p class="feature-description" data-i18n="feat4_desc">Receive instant notifications about report progress and maintenance activities.</p>
                <a href="<?= $BASE_URL ?>citizenreports.php" class="feature-link" data-i18n="feat4_link">Check Status →</a>
            </div>
            <div class="feature-card animate-on-scroll delay-2">
                <div class="feature-icon" style="color: #042cb1;"><i class="fas fa-hands-helping"></i></div>
                <h3 class="feature-title" data-i18n="feat5_title">Community Engagement</h3>
                <p class="feature-description" data-i18n="feat5_desc">Encourage active participation of citizens in improving Quezon City's infrastructure through transparency and collaboration.</p>
                <a href="<?= $BASE_URL ?>about.php" class="feature-link" data-i18n="feat5_link">Learn More →</a>
            </div>
        </div>
    </section>

    <!-- RECENT ACTIVITY SECTION -->
    <section class="activity-section animate-on-scroll">
        <div class="activity-card">
            <h2 class="section-title" data-i18n="activity_title">Recent Maintenance Activity</h2>
            <p class="section-subtitle" data-i18n="activity_subtitle">Stay informed about the latest infrastructure maintenance in your community</p>
            
            <?php if (!empty($recent_maintenance)): ?>
                <ul class="activity-list">
                    <?php foreach ($recent_maintenance as $item): 
                        $status_class = 'status-pending';
                        if ($item['status'] === 'Completed') {
                            $status_class = 'status-completed';
                        } elseif ($item['status'] === 'In Progress') {
                            $status_class = 'status-progress';
                        }
                    ?>
                        <li class="activity-item">
                            <div class="activity-info">
                                <h4><?= htmlspecialchars($item['task']) ?></h4>
                                <p><?= htmlspecialchars($item['location']) ?> • <?= date('M d, Y', strtotime($item['starting_date'])) ?></p>
                            </div>
                            <span class="status-badge <?= $status_class ?>"><?= htmlspecialchars($item['status']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div style="text-align:center;margin-top:30px;">
                    <a href="<?= $BASE_URL ?>citizenreports.php" class="cta-button cta-primary" data-i18n="activity_view_all">View All Reports</a>
                </div>
            <?php else: ?>
                <p style="text-align:center;color:var(--text-secondary);padding:40px;" data-i18n="activity_empty">No recent maintenance activities to display.</p>
            <?php endif; ?>
        </div><!-- end activity-card -->
    </section>

    <!-- ABOUT SECTION -->
    <section class="about-section animate-on-scroll">
        <div class="about-container">
            <div class="about-header">
                <h2 data-i18n="about_title">About CIMMS – Quezon City</h2>
                <p data-i18n="about_subtitle">Building a smarter, more responsive city through innovative infrastructure management</p>
            </div>

            <div class="about-content">
                <div class="about-image-container">
                    <div class="about-image-wrapper">
                        <img src="<?= $OFFICIAL_LOGO ?>" alt="CIMMS Logo">
                        <div class="about-image-overlay">
                            <h3 data-i18n="overlay_title">Serving Quezon City</h3>
                            <p data-i18n="overlay_subtitle">Excellence in Public Infrastructure</p>
                        </div>
                    </div>
                </div>

                <div class="about-text">
                    <h3 data-i18n="about_transform_title">Transforming Infrastructure Management</h3>
                    <p data-i18n-html="about_p1">The <strong>Community Infrastructure Maintenance Management System (CIMMS)</strong> is a cutting-edge digital platform developed specifically for Quezon City's Local Government Unit to revolutionize how we manage, maintain, and improve our city's infrastructure.</p>
                    
                    <p data-i18n="about_p2">Our platform empowers residents by providing a direct, transparent channel to report infrastructure concerns ranging from damaged roads and broken streetlights to clogged drainage systems and deteriorating public facilities.</p>

                    <div class="about-highlights">
                        <div class="highlight-item">
                            <div class="highlight-icon">📱</div>
                            <div class="highlight-text">
                                <strong data-i18n="highlight1_title">Easy Reporting</strong>
                                <span data-i18n="highlight1_desc">Submit issues in seconds from any device</span>
                            </div>
                        </div>
                        <div class="highlight-item">
                            <div class="highlight-icon">📍</div>
                            <div class="highlight-text">
                                <strong data-i18n="highlight2_title">GPS Tracking</strong>
                                <span data-i18n="highlight2_desc">Precise location mapping for faster response</span>
                            </div>
                        </div>
                        <div class="highlight-item">
                            <div class="highlight-icon">🔔</div>
                            <div class="highlight-text">
                                <strong data-i18n="highlight3_title">Real-Time Updates</strong>
                                <span data-i18n="highlight3_desc">Stay informed throughout the process</span>
                            </div>
                        </div>
                        <div class="highlight-item">
                            <div class="highlight-icon">📊</div>
                            <div class="highlight-text">
                                <strong data-i18n="highlight4_title">Transparent Tracking</strong>
                                <span data-i18n="highlight4_desc">Monitor progress from report to resolution</span>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top:50px;text-align:center;">
                        <a href="<?= $BASE_URL ?>about.php" class="cta-button cta-primary" data-i18n="about_learn_more">Learn More About Us</a>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

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

<script>
/* ---------------------------------------------------------------
   SCROLL ANIMATIONS
   --------------------------------------------------------------- */
document.addEventListener('DOMContentLoaded', function() {
    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('animate-in');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });

    document.querySelectorAll('.animate-on-scroll').forEach(el => observer.observe(el));
});
</script>

<?php include 'citizen_global.php'; ?>
<?php include 'chatbot-widget.php'; ?>

</body>
</html>