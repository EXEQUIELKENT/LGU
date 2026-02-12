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
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

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
            --accent-primary: #4a8fd8;
            --accent-secondary: #5a9fe8;
            --accent-light: #1e3a5f;
        }

        body {
            background: url("<?= $BASE_URL ?>cityhall.jpeg") center/cover no-repeat fixed;
            min-height: 100vh;
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
            width: 10px;
        }

        body::-webkit-scrollbar-track {
            background: var(--bg-secondary);
        }

        body::-webkit-scrollbar-thumb {
            background: var(--accent-primary);
            border-radius: 5px;
        }

        /* FIX 3: Make navbar flexible with responsive spacing */
        .nav {
            width: 100%;
            padding: 18px clamp(20px, 4vw, 60px);
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
            transition: all 0.3s ease;
            gap: clamp(10px, 2vw, 20px);
            flex-wrap: wrap;
        }
                
        /* FIX 4: Responsive site logo */
        .site-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text-primary);
            font-weight: 600;
            text-decoration: none;
            transition: color 0.3s ease;
            font-size: clamp(12px, 1.5vw, 16px);
            white-space: nowrap;
            flex-shrink: 1;
            min-width: 0;
        }
        
        .site-logo:hover {
            opacity: 0.85;
        }
        
        .site-logo img {
            width: clamp(30px, 5vw, 40px);
            height: auto;
            border-radius: 8px;
            flex-shrink: 0;
        }

        .site-logo span {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        /* FIX 5: Responsive nav center section */
        .nav-center {
            display: flex;
            align-items: center;
            gap: clamp(8px, 1.5vw, 15px);
            margin-left: auto;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        
        /* FIX 6: Responsive nav links */
        .nav-links {
            display: flex;
            align-items: center;
            gap: clamp(12px, 2vw, 25px);
            flex-wrap: wrap;
        }
        
        .nav-links a {
            margin-left: 0;
            text-decoration: none;
            cursor: pointer;
            color: var(--text-primary);
            opacity: .8;
            transition: .2s;
            font-weight: 500;
            font-size: clamp(13px, 1.4vw, 16px);
            white-space: nowrap;
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

        .nav-divider {
            width: 2px;
            height: 30px;
            background: var(--border-color);
            margin: 0;
        }

        /* FIX 7: Responsive nav actions */
        .nav-actions {
            display: flex;
            align-items: center;
            gap: clamp(8px, 1.2vw, 12px);
            flex-wrap: wrap;
        }

        /* FIX 8: Desktop clock - SINGLE LINE LAYOUT */
        .desktop-clock {
            font-size: clamp(12px, 1.3vw, 14px);
            font-weight: 500;
            color: var(--text-primary);
            white-space: nowrap !important;    /* Force single line */
            position: relative;
            transition: color 0.3s ease;
            text-align: right;
            min-width: 420px;
            display: inline-block;
            overflow: visible;
            line-height: 1.4;
        }

        .desktop-clock .date-part {
            opacity: 0.6;
            font-weight: 400;
            display: inline;
            white-space: nowrap;
        }

        .desktop-clock .time-part {
            font-weight: 700;
            letter-spacing: 0.03em;
            display: inline;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        .time-part span {
            display: inline-block;
            transition: transform 0.25s ease, opacity 0.25s ease;
            white-space: nowrap;
        }

        .time-part.flip span {
            transform: translateY(-4px);
            opacity: 0.6;
        }

        /* FIX 9: Responsive nav buttons */
        .nav-btn {
            position: relative;
            width: clamp(34px, 5vw, 38px);
            height: clamp(34px, 5vw, 38px);
            border: none;
            border-radius: 10px;
            background: rgba(55, 98, 200, 0.1);
            color: var(--text-primary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: clamp(16px, 2vw, 18px);
            transition: all 0.3s ease;
            backdrop-filter: blur(8px);
            flex-shrink: 0;
        }

        .nav-btn:hover {
            background: rgba(55, 98, 200, 0.2);
            transform: scale(1.05);
        }

        .nav-btn:active {
            transform: scale(0.95);
        }

        .nav-btn.dark-mode-btn.active {
            animation: rotateSun 0.5s ease;
        }

        @keyframes rotateSun {
            0% { transform: rotate(0deg) scale(1); }
            50% { transform: rotate(180deg) scale(1.2); }
            100% { transform: rotate(360deg) scale(1); }
        }

        /* MOBILE SIDEBAR */
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

        /* MOBILE TOP NAV */
        .mobile-top-nav {
            display: none;
        }

        /* MAIN CONTENT */
        .main-content {
            flex: 1;
            padding-top: 80px;
        }

        /* HERO SECTION */
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
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* STATISTICS SECTION */
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
            border: 1px solid var(--border-color);
            box-shadow: 0 10px 30px var(--shadow-color);
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 40px var(--shadow-color);
        }

        .stat-icon {
            font-size: 48px;
            margin-bottom: 20px;
        }

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

        /* TRUST INDICATORS */
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

        /* Connecting Lines - Desktop (Horizontal) */
        .trust-item {
            text-align: center;
            padding: 30px 20px;
            background: var(--card-bg);
            border-radius: 16px;
            border: 1px solid var(--border-color);
            box-shadow: 0 8px 24px var(--shadow-color);
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
            position: relative;
            z-index: 2;
        }

        /* Horizontal connecting line (desktop) */
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

        /* Animated dot traveling along the line */
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

        /* Remove line from last item */
        .trust-item:nth-child(4)::after,
        .trust-item:nth-child(4)::before {
            display: none;
        }

        @keyframes slideInLine {
            from {
                opacity: 0;
                width: 0;
            }
            to {
                opacity: 1;
                width: 30px;
            }
        }

        @keyframes travelDot {
            0%, 100% {
                opacity: 0;
                left: 100%;
            }
            10% {
                opacity: 1;
            }
            90% {
                opacity: 1;
            }
            50% {
                left: calc(100% + 15px);
                opacity: 1;
            }
        }

        .trust-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 32px var(--shadow-color);
        }

        .trust-item:hover::after {
            background: linear-gradient(90deg, #1d4ed8, #60a5fa);
            box-shadow: 0 0 15px rgba(43, 108, 176, 0.8);
        }

        .trust-icon {
            font-size: 36px;
            margin-bottom: 15px;
        }

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

        /* FEATURES SECTION */
        .features-section {
            padding: 80px 20px;
            background: var(--bg-secondary);
            backdrop-filter: blur(10px);
            margin: 0 20px;
            border-radius: 30px;
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
            border: 1px solid var(--border-color);
            box-shadow: 0 10px 30px var(--shadow-color);
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

        .feature-card:hover::before {
            transform: scaleX(1);
        }

        .feature-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 40px var(--shadow-color);
        }

        .feature-icon {
            font-size: 48px;
            margin-bottom: 20px;
        }

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

        .feature-link:hover {
            gap: 12px;
        }

        /* HOW IT WORKS SECTION */
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
            border: 1px solid var(--border-color);
            box-shadow: 0 10px 30px var(--shadow-color);
            margin-bottom: 40px;
            text-align: center;
        }

        .section-header-card .section-title {
            margin-bottom: 15px;
        }

        .section-header-card .section-subtitle {
            margin-bottom: 0;
        }

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
            border: 1px solid var(--border-color);
            box-shadow: 0 8px 24px var(--shadow-color);
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }

        .step-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 32px var(--shadow-color);
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

        /* ABOUT SECTION - IMPROVED */
        .about-section {
            padding: 80px 20px;
            background: var(--bg-secondary);
            backdrop-filter: blur(10px);
            margin: 0 20px;
            border-radius: 30px;
        }

        .about-container {
            max-width: 1400px;
            margin: 0 auto;
        }

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

        .about-image-container {
            position: relative;
        }

        .about-image-wrapper {
            position: relative;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 20px 60px var(--shadow-color);
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

        .about-image-overlay h3 {
            font-size: 1.5rem;
            margin-bottom: 5px;
        }

        .about-image-overlay p {
            font-size: 0.95rem;
            opacity: 0.9;
        }

        .about-text {
            padding: 20px 0;
        }

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
            transition: all 0.3s ease;
        }

        .highlight-item:hover {
            transform: translateX(5px);
        }

        .highlight-icon {
            font-size: 24px;
            flex-shrink: 0;
        }

        .highlight-text {
            flex: 1;
        }

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
            border: 1px solid var(--border-color);
            box-shadow: 0 10px 30px var(--shadow-color);
            margin-top: 60px;
        }

        .mission-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 40px;
            margin-top: 40px;
        }

        .mission-card {
            text-align: center;
        }

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

        /* RECENT ACTIVITY SECTION */
        .activity-section {
            padding: 80px 20px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .activity-card {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 40px;
            border: 1px solid var(--border-color);
            box-shadow: 0 10px 30px var(--shadow-color);
        }

        .activity-card .section-title {
            text-align: center;
            margin-bottom: 15px;
        }

        .activity-card .section-subtitle {
            text-align: center;
            margin-bottom: 40px;
        }

        .activity-list {
            list-style: none;
            padding: 0;
        }

        .activity-item {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.2s ease;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-item:hover {
            background: var(--bg-tertiary);
            padding-left: 30px;
        }

        .activity-info h4 {
            color: var(--text-primary);
            margin-bottom: 5px;
        }

        .activity-info p {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .status-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-progress {
            background: #cce5ff;
            color: #004085;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }
        /* FIX 2: Update footer positioning */
        .footer {
            width: 100%;
            padding: 60px 20px 30px;
            background: rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border-top: 1px solid var(--border-color);
            box-shadow: 0 -2px 12px var(--shadow-color);
            margin-top: 0;
            flex-shrink: 0;
        }

        .footer-content {
            max-width: 1400px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 40px;
            margin-bottom: 40px;
        }

        .footer-about h3 {
            color: #fff;
            margin-bottom: 15px;
            font-size: 1.3rem;
        }

        .footer-about p {
            color: rgba(255, 255, 255, 0.8);
            line-height: 1.7;
            margin-bottom: 20px;
        }

        .footer-contact {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .contact-item {
            display: flex;
            align-items: center;
            gap: 10px;
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.95rem;
        }

        .footer-links h4 {
            color: #fff;
            margin-bottom: 15px;
            font-size: 1.1rem;
        }

        .footer-links ul {
            list-style: none;
        }

        .footer-links li {
            margin-bottom: 10px;
        }

        .footer-links a {
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.2s ease;
            font-size: 0.95rem;
        }

        .footer-links a:hover {
            color: #fff;
            padding-left: 5px;
        }

        .footer-bottom {
            text-align: center;
            padding-top: 30px;
            margin-top: 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.8);
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin-left: auto;
            margin-right: auto;
        }

        .footer-social {
            display: flex;
            gap: 15px;
        }

        .social-link {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            text-decoration: none;
            font-size: 18px;
            transition: all 0.3s ease;
        }

        .social-link:hover {
            background: var(--accent-primary);
            transform: translateY(-3px);
        }

        /* FIX 10: Clock width adjustments - KEEP SINGLE LINE */
        @media (min-width: 769px) and (max-width: 1200px) {
            .desktop-clock {
                min-width: 380px;
                font-size: clamp(11px, 1.2vw, 13px);
                white-space: nowrap !important;
            }
        }

        @media (min-width: 769px) and (max-width: 1000px) {
            .desktop-clock {
                min-width: 320px;
                font-size: clamp(10px, 1.1vw, 12px);
                white-space: nowrap !important;
            }
        }

        /* FIX 11: Tall screens - only stack on VERY narrow screens */
        @media (min-width: 769px) and (min-aspect-ratio: 9/16) and (max-width: 500px) {
            .nav {
                padding: 12px clamp(15px, 3vw, 40px);
            }
            
            .desktop-clock {
                min-width: 280px;
            }
            
            /* Only stack when truly necessary */
            .desktop-clock .date-part {
                display: block;
                text-align: center;
                margin-bottom: 2px;
                font-variant-numeric: tabular-nums;
            }
            
            .desktop-clock .time-part {
                display: block;
                text-align: center;
                font-variant-numeric: tabular-nums;
            }
        }

        /* For wider tall screens - keep inline */
        @media (min-width: 769px) and (min-aspect-ratio: 9/16) and (min-width: 501px) {
            .nav {
                padding: 12px clamp(15px, 3vw, 40px);
            }
            
            .desktop-clock {
                min-width: 400px;
                white-space: nowrap !important;
            }
            
            .desktop-clock .date-part,
            .desktop-clock .time-part {
                display: inline;
                white-space: nowrap;
            }
        }

        /* FIX 12: Phones in desktop mode - stack vertically */
        @media (min-width: 769px) and (max-width: 600px) {
            .nav {
                flex-wrap: nowrap;
                padding: 12px 15px;
            }
            
            .site-logo span {
                display: none;
            }
            
            .nav-links {
                flex-wrap: nowrap;
                gap: 10px;
            }
            
            .nav-links a {
                font-size: 13px;
            }
            
            .desktop-clock {
                font-size: 11px;
                min-width: auto;
                max-width: 150px;
                width: 150px;
            }
            
            .desktop-clock .date-part,
            .desktop-clock .time-part {
                display: block;
                text-align: right;
                line-height: 1.2;
                font-variant-numeric: tabular-nums;
            }
            
            .nav-btn {
                width: 32px;
                height: 32px;
                font-size: 16px;
            }
        }

        /* RESPONSIVE */
        @media (max-width: 1024px) {
            .about-content {
                grid-template-columns: 1fr;
            }

            .footer-content {
                grid-template-columns: 1fr 1fr;
            }

            .trust-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            /* Adjust connecting lines for 2-column layout */
            .trust-item:nth-child(2)::after,
            .trust-item:nth-child(2)::before,
            .trust-item:nth-child(4)::after,
            .trust-item:nth-child(4)::before {
                display: none;
            }

            .trust-item:nth-child(1)::after,
            .trust-item:nth-child(1)::before,
            .trust-item:nth-child(3)::after,
            .trust-item:nth-child(3)::before {
                display: block;
            }
        }

        /* SCROLL ANIMATIONS */
        .animate-on-scroll {
            opacity: 0;
            transform: translateY(30px);
            transition: opacity 0.8s ease, transform 0.8s ease;
        }

        .animate-on-scroll.animate-in {
            opacity: 1;
            transform: translateY(0);
        }

        .animate-on-scroll.delay-1 {
            transition-delay: 0.1s;
        }

        .animate-on-scroll.delay-2 {
            transition-delay: 0.2s;
        }

        .animate-on-scroll.delay-3 {
            transition-delay: 0.3s;
        }

        .animate-on-scroll.delay-4 {
            transition-delay: 0.4s;
        }

        @media (max-width: 768px) {
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
                z-index: 5000;
                box-shadow: 0 4px 18px var(--shadow-color);
                border-bottom: 1px solid var(--border-color);
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
            }

            .mobile-dark-mode-btn {
                position: absolute;
                right: 12px;
                width: 38px;
                height: 38px;
            }

            .sidebar-nav {
                display: flex;
            }

            .hero-title {
                font-size: 2.5rem;
            }

            .hero-subtitle {
                font-size: 1.2rem;
            }

            .hero-tagline {
                font-size: 1rem;
            }

            .section-title {
                font-size: 2rem;
            }  

            .about-text h3 {
                font-size: 2rem;
                text-align: center;
            }

            .about-text p {
                font-size: 1.05rem;
            }

            .about-header h2 {
                font-size: 2rem;
            }

            .about-highlights {
                grid-template-columns: 1fr;
            }

            .about-mission {
                padding: 30px 20px;
            }

            .mission-grid {
                grid-template-columns: 1fr;
                gap: 30px;
            }

            .footer-content {
                grid-template-columns: 1fr;
            }

            .footer-bottom {
                flex-direction: column;
                gap: 20px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .features-grid {
                grid-template-columns: 1fr;
            }

            .steps-container {
                grid-template-columns: 1fr;
            }

            .trust-grid {
                grid-template-columns: 1fr;
            }

            /* Mobile: Vertical connecting lines */
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

            /* Remove line from last item in mobile */
            .trust-item:nth-child(1)::after,
            .trust-item:nth-child(1)::before,
            .trust-item:nth-child(2)::after,
            .trust-item:nth-child(2)::before,
            .trust-item:nth-child(3)::after,
            .trust-item:nth-child(3)::before {
                display: block;
            }

            .trust-item:nth-child(4)::after,
            .trust-item:nth-child(4)::before {
                display: none;
            }

            @keyframes slideInLineVertical {
                from {
                    opacity: 0;
                    height: 0;
                }
                to {
                    opacity: 1;
                    height: 30px;
                }
            }

            @keyframes travelDotVertical {
                0%, 100% {
                    opacity: 0;
                    top: 100%;
                }
                10% {
                    opacity: 1;
                }
                90% {
                    opacity: 1;
                }
                50% {
                    top: calc(100% + 15px);
                    opacity: 1;
                }
            }

            .features-section,
            .about-section {
                margin: 0 10px;
                border-radius: 20px;
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
        <img src="<?= $OFFICIAL_LOGO ?>" alt="LGU Logo">
        <span>InfraGovServices - Infrastructure and Utilities</span>
    </a>
    
    <div class="nav-center">
        <div class="nav-links">
            <?php if ($show_login): ?>
                <a href="<?= $BASE_URL ?>login.php">Log in</a>
            <?php endif; ?>
            <a href="#" class="active">Home</a>
            <a href="<?= $BASE_URL ?>citizenreports.php">Reports</a>
            <a href="<?= $BASE_URL ?>citizenrepform.php">Requests</a>
            <a href="<?= $BASE_URL ?>about.php">About</a>
        </div>
        
        <div class="nav-divider"></div>
        
        <div class="nav-actions">
            <div class="desktop-clock" id="desktopClock"></div>
            <button class="nav-btn dark-mode-btn" id="darkModeBtn" title="Toggle Dark Mode">
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
        
        <ul class="nav-list">
            <?php if ($show_login): ?>
                <li><a href="<?= $BASE_URL ?>login.php" class="nav-link"><span>🔐</span><span>Log in</span></a></li>
            <?php endif; ?>
            <li><a href="#" class="nav-link active"><span>🏠</span><span>Home</span></a></li>
            <li><a href="<?= $BASE_URL ?>citizenreports.php" class="nav-link"><span>📄</span><span>Reports</span></a></li>
            <li><a href="<?= $BASE_URL ?>citizenrepform.php" class="nav-link"><span>📋</span><span>Requests</span></a></li>
            <li><a href="<?= $BASE_URL ?>about.php" class="nav-link"><span>ℹ️</span><span>About</span></a></li>
        </ul>
    </div>
</div>

<!-- MOBILE TOP NAV -->
<div class="mobile-top-nav">
    <button class="mobile-toggle" id="mobileToggle">☰</button>
    <a href="https://infragovservices.com/" target="_blank" rel="noopener noreferrer">
        <img src="<?= $OFFICIAL_LOGO ?>" alt="LGU Logo">
    </a>
    <div class="mobile-clock" id="mobileClock"></div>
    <button class="nav-btn dark-mode-btn mobile-dark-mode-btn" id="mobileDarkModeBtn" title="Toggle Dark Mode">
        <span class="dark-icon">🌙</span>
        <span class="light-icon" style="display: none;">☀️</span>
    </button>
</div>

<div class="main-content">
    <!-- HERO SECTION -->
    <section class="hero-section">
        <h1 class="hero-title">Welcome to InfraGovServices</h1>
        <p class="hero-subtitle">Community Infrastructure Maintenance Management System</p>
        <p class="hero-tagline">Empowering Quezon City residents with efficient, transparent, and responsive infrastructure services</p>
        <div class="hero-cta">
            <a href="<?= $BASE_URL ?>citizenrepform.php" class="cta-button cta-primary">Submit a Report</a>
            <a href="#features" class="cta-button cta-secondary">Learn More</a>
        </div>
    </section>

    <!-- STATISTICS SECTION -->
    <section class="stats-section animate-on-scroll">
        <div class="stats-grid">
            <div class="stat-card animate-on-scroll delay-1">
                <div class="stat-icon">🛠️</div>
                <div class="stat-number"><?= $repairs_count ?></div>
                <div class="stat-label">Completed Repairs</div>
            </div>
            <div class="stat-card animate-on-scroll delay-2">
                <div class="stat-icon">⏳</div>
                <div class="stat-number"><?= $ongoing_count ?></div>
                <div class="stat-label">Ongoing Repairs</div>
            </div>
            <div class="stat-card animate-on-scroll delay-3">
                <div class="stat-icon">📍</div>
                <div class="stat-number"><?= $pending_count ?></div>
                <div class="stat-label">Pending Requests</div>
            </div>
        </div>
    </section>

    <!-- TRUST INDICATORS -->
    <section class="trust-section animate-on-scroll">
        <div class="trust-grid">
            <div class="trust-item animate-on-scroll delay-1">
                <div class="trust-icon">🔒</div>
                <div class="trust-title">Secure & Private</div>
                <div class="trust-desc">Your data is protected with strong, trusted security.</div>
            </div>
            <div class="trust-item animate-on-scroll delay-2">
                <div class="trust-icon">⚡</div>
                <div class="trust-title">Fast Response</div>
                <div class="trust-desc">Reports are handled within 24–48 hours based on priority.</div>
            </div>
            <div class="trust-item animate-on-scroll delay-3">
                <div class="trust-icon">🎯</div>
                <div class="trust-title">Verified Reports</div>
                <div class="trust-desc">Every report is carefully checked for accuracy.</div>
            </div>
            <div class="trust-item animate-on-scroll delay-4">
                <div class="trust-icon">🏆</div>
                <div class="trust-title">Service Excellence</div>
                <div class="trust-desc">Committed to quality, transparency, and public service.</div>
            </div>
        </div>
    </section>

    <!-- HOW IT WORKS SECTION -->
    <section class="how-it-works-section animate-on-scroll">
        <div class="section-header-card">
            <h2 class="section-title">How CIMMS Works</h2>
            <p class="section-subtitle">Simple, fast, and effective — report infrastructure issues in just a few steps.</p>
            <div class="steps-container">
            <div class="step-card animate-on-scroll delay-1">
                <div class="step-number">1</div>
                <h3 class="step-title">Report the Issue</h3>
                <p class="step-description">Residents submit infrastructure concerns through the system using a simple form, including photos, descriptions, and exact location details.</p>
            </div>
            <div class="step-card animate-on-scroll delay-2">
                <div class="step-number">2</div>
                <h3 class="step-title">Review & Verification</h3>
                <p class="step-description">Office staff review submissions within 24 hours, validate the information, and AI will determine urgency and service priority.</p>
            </div>
            <div class="step-card animate-on-scroll delay-3">
                <div class="step-number">3</div>
                <h3 class="step-title">Maintenance Scheduled</h3>
                <p class="step-description">Approved reports are forwarded to engineering and public works teams for scheduling and task assignment. Citizens receive progress updates.</p>
            </div>
            <div class="step-card animate-on-scroll delay-4">
                <div class="step-number">4</div>
                <h3 class="step-title">Issue Resolved</h3>
                <p class="step-description">Maintenance work is completed, documented, and marked as completed, ensuring transparency and accountability.</p>
            </div>
        </div>
        </div>
        
        
    </section>

    <!-- FEATURES SECTION -->
    <section class="features-section animate-on-scroll" id="features">
        <h2 class="section-title">Our Services</h2>
        <p class="section-subtitle">Empowering Quezon City residents with efficient infrastructure management tools</p>
        
        <div class="features-grid">
            <div class="feature-card animate-on-scroll delay-1">
                <div class="feature-icon">📋</div>
                <h3 class="feature-title">Submit Requests</h3>
                <p class="feature-description">Report infrastructure concerns with detailed descriptions and photo evidence, ensuring fast and accurate response.</p>
                <a href="<?= $BASE_URL ?>citizenrepform.php" class="feature-link">Submit Request →</a>
            </div>

            <div class="feature-card animate-on-scroll delay-2">
                <div class="feature-icon">📊</div>
                <h3 class="feature-title">Track Maintenance</h3>
                <p class="feature-description">Monitor the status of maintenance schedules, view completed repairs, and stay informed about ongoing infrastructure improvements in your area.</p>
                <a href="<?= $BASE_URL ?>citizenreports.php" class="feature-link">View Reports →</a>
            </div>

            <div class="feature-card animate-on-scroll delay-3">
                <div class="feature-icon">🗺️</div>
                <h3 class="feature-title">Location-Based Reporting</h3>
                <p class="feature-description">Use interactive maps and GPS-based tagging to accurately identify problem areas.</p>
                <a href="<?= $BASE_URL ?>citizenrepform.php" class="feature-link">Try It Now →</a>
            </div>

            <div class="feature-card animate-on-scroll delay-1">
                <div class="feature-icon">⚡</div>
                <h3 class="feature-title">Real-Time Updates</h3>
                <p class="feature-description">Receive instant notifications about report progress and maintenance activities.</p>
                <a href="<?= $BASE_URL ?>citizenreports.php" class="feature-link">Check Status →</a>
            </div>

            <div class="feature-card animate-on-scroll delay-2">
                <div class="feature-icon">🤝</div>
                <h3 class="feature-title">Community Engagement</h3>
                <p class="feature-description">Encourage active participation of citizens in improving Quezon City’s infrastructure through transparency and collaboration.</p>
                <a href="<?= $BASE_URL ?>about.php" class="feature-link">Learn More →</a>
            </div>
        </div>
    </section>

    <!-- RECENT ACTIVITY SECTION -->
    <section class="activity-section animate-on-scroll">
        <div class="activity-card">
            <h2 class="section-title">Recent Maintenance Activity</h2>
            <p class="section-subtitle">Stay informed about the latest infrastructure maintenance in your community</p>
            
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
                <div style="text-align: center; margin-top: 30px;">
                    <a href="<?= $BASE_URL ?>citizenreports.php" class="cta-button cta-primary">View All Reports</a>
                </div>
            <?php else: ?>
                <p style="text-align: center; color: var(--text-secondary); padding: 40px;">No recent maintenance activities to display.</p>
            <?php endif; ?>
        </div>
    </section>

    <!-- ABOUT SECTION - IMPROVED -->
    <section class="about-section animate-on-scroll">
        <div class="about-container">
            <div class="about-header">
                <h2>About CIMMS – Quezon City</h2>
                <p>Building a smarter, more responsive city through innovative infrastructure management</p>
            </div>

            <div class="about-content">
                <div class="about-image-container">
                    <div class="about-image-wrapper">
                        <img src="<?= $OFFICIAL_LOGO ?>" alt="CIMMS Logo">
                        <div class="about-image-overlay">
                            <h3>Serving Quezon City</h3>
                            <p>Excellence in Public Infrastructure</p>
                        </div>
                    </div>
                </div>

                <div class="about-text">
                    <h3>Transforming Infrastructure Management</h3>
                    <p>The <strong>Community Infrastructure Maintenance Management System (CIMMS)</strong> is a cutting-edge digital platform developed specifically for Quezon City's Local Government Unit to revolutionize how we manage, maintain, and improve our city's infrastructure.</p>
                    
                    <p>Our platform empowers residents by providing a direct, transparent channel to report infrastructure concerns ranging from damaged roads and broken streetlights to clogged drainage systems and deteriorating public facilities.</p>

                    <div class="about-highlights">
                        <div class="highlight-item">
                            <div class="highlight-icon">📱</div>
                            <div class="highlight-text">
                                <strong>Easy Reporting</strong>
                                <span>Submit issues in seconds from any device</span>
                            </div>
                        </div>
                        <div class="highlight-item">
                            <div class="highlight-icon">📍</div>
                            <div class="highlight-text">
                                <strong>GPS Tracking</strong>
                                <span>Precise location mapping for faster response</span>
                            </div>
                        </div>
                        <div class="highlight-item">
                            <div class="highlight-icon">🔔</div>
                            <div class="highlight-text">
                                <strong>Real-Time Updates</strong>
                                <span>Stay informed throughout the process</span>
                            </div>
                        </div>
                        <div class="highlight-item">
                            <div class="highlight-icon">📊</div>
                            <div class="highlight-text">
                                <strong>Transparent Tracking</strong>
                                <span>Monitor progress from report to resolution</span>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top: 50px; text-align: center;">
                        <a href="<?= $BASE_URL ?>about.php" class="cta-button cta-primary">Learn More About Us</a>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- FOOTER -->
<footer class="footer" style="margin-top: 50px;">
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
                <li><a href="<?= $BASE_URL ?>citizencimm.php">Home</a></li>
                <li><a href="<?= $BASE_URL ?>citizenreports.php">Reports</a></li>
                <li><a href="<?= $BASE_URL ?>citizenrepform.php">Submit Request</a></li>
                <li><a href="<?= $BASE_URL ?>about.php">About Us</a></li>
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
    
    document.addEventListener('click', (e) => {
        if (sidebar && sidebar.classList.contains('mobile-active')) {
            if (!sidebar.contains(e.target) && e.target !== mobileToggle) {
                sidebar.classList.remove('mobile-active');
            }
        }
    });
    
    if (sidebar) {
        sidebar.addEventListener('click', (e) => {
            e.stopPropagation();
        });
    }
    
    const navLinks = sidebar?.querySelectorAll('.nav-link');
    navLinks?.forEach(link => {
        link.addEventListener('click', () => {
            sidebar.classList.remove('mobile-active');
        });
    });

    // Smooth scroll for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
});

// Clock Script
const RESYNC_MINUTES = 5;
let currentServerTime = SERVER_TIME;
let clockInterval = null;
let lastSecond = null;

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

<script>
// Scroll Animation
document.addEventListener('DOMContentLoaded', function() {
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };

    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('animate-in');
                observer.unobserve(entry.target);
            }
        });
    }, observerOptions);

    const animateElements = document.querySelectorAll('.animate-on-scroll');
    animateElements.forEach(element => {
        observer.observe(element);
    });
});
</script>

<?php include 'chatbot-widget.php'; ?>

</body>
</html>