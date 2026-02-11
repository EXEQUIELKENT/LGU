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
    <title>Privacy Policy - InfraGovServices | LGU Portal</title>
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
            width: 10px;
        }

        body::-webkit-scrollbar-track {
            background: var(--bg-secondary);
        }

        body::-webkit-scrollbar-thumb {
            background: #2b6cb0;
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

        .site-logo img {
            width: clamp(30px, 5vw, 40px);
            height: auto;
            border-radius: 8px;
            flex-shrink: 0;
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

        /* Nav divider */
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

        /* FOOTER - Updated from citizencimm.php */
        .footer {
            width: 100%;
            padding: 60px 20px 30px;
            background: rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border-top: 1px solid var(--border-color);
            box-shadow: 0 -2px 12px var(--shadow-color);
            margin-top: auto;
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
            background: #2b6cb0;
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
            <a href="<?= $BASE_URL ?>citizenreports.php">Reports</a>
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
            <li><a href="<?= $BASE_URL ?>citizencimm.php" class="nav-link"><span>🏠</span><span>Home</span></a></li>
            <li><a href="<?= $BASE_URL ?>citizenreports.php" class="nav-link"><span>📄</span><span>Reports</span></a></li>
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

<div class="form-wrapper">
    <div class="about-card">
        <h1>Privacy Policy</h1>

        <div class="section-box intro">
            <p>
                This Privacy Policy may be updated periodically to ensure continued compliance with applicable laws, regulations,
                and institutional requirements. Continued use of the System signifies acceptance of any revisions to this Policy.
            </p>
            <p>
                This Privacy Policy shall be governed by and construed in accordance with the laws of the Republic of the
                Philippines, particularly the <strong>Data Privacy Act of 2012 (RA 10173)</strong>.
            </p>
        </div>

        <div class="section-box">
            <h2><span class="icon">📋</span> Data Collection and Processing</h2>
            <p>
                In compliance with the Data Privacy Act of 2012 (Republic Act No. 10173), its Implementing Rules and Regulations,
                and relevant issuances of the National Privacy Commission (NPC), the System Development for Enhanced Public Works
                Coordination and Data-Driven Infrastructure Planning Using AI-assisted Decision Support Technologies is committed
                to protecting the privacy and security of all personal data collected, stored, and processed through the System.
            </p>
        </div>

        <div class="section-box">
            <h2><span class="icon">⚖️</span> Lawful Processing Principles</h2>
            <p>
                All personal data shall be processed fairly, lawfully, and transparently, and shall be collected only for legitimate
                and declared purposes directly related to system operations, coordination, analysis, and academic evaluation.
            </p>
        </div>

        <div class="section-box">
            <h2><span class="icon">🔍</span> Types of Information Collected</h2>
            <p>The System may collect personal and non-personal information including:</p>
            <ul class="purpose-list">
                <li>Names or user identifiers</li>
                <li>Usernames and account credentials</li>
                <li>Contact information when applicable</li>
                <li>Location data related to infrastructure reports</li>
                <li>System activity logs and timestamps</li>
            </ul>
        </div>

        <div class="section-box">
            <h2><span class="icon">🔐</span> Data Security and Protection</h2>
            <p>
                We implement appropriate technical and organizational measures to ensure the security of your personal data
                against unauthorized access, alteration, disclosure, or destruction. All data is encrypted during transmission
                and storage.
            </p>
        </div>

        <div class="section-box">
            <h2><span class="icon">👤</span> Your Rights as a Data Subject</h2>
            <p>Under the Data Privacy Act of 2012, you have the right to:</p>
            <ul class="purpose-list">
                <li>Be informed about the collection and processing of your personal data</li>
                <li>Access your personal data and request corrections</li>
                <li>Object to the processing of your personal data</li>
                <li>Request erasure or blocking of your personal data</li>
                <li>File a complaint with the National Privacy Commission</li>
            </ul>
        </div>

        <div class="section-box">
            <h2><span class="icon">🤝</span> User Consent and Agreement</h2>
            <p>By using this System, I confirm that I have read and understood the Terms of Use and Privacy Policy of the
                AI-Assisted Public Works Coordination and Infrastructure Management System.</p>
            <p>I voluntarily consent to:</p>
            <ul class="purpose-list">
                <li>The collection, processing, and storage of my personal data in accordance with the Data Privacy Act of 2012 (RA 10173)</li>
                <li>The use of AI-generated recommendations for decision support purposes only</li>
                <li>Understanding that AI recommendations do not replace human judgment or official authority</li>
            </ul>
        </div>

        <div class="section-box">
            <h2><span class="icon">📞</span> Contact Information</h2>
            <p>
                For questions or concerns regarding this Privacy Policy or the handling of your personal data, please contact our
                Data Protection Officer at:
            </p>
            <p style="margin-top: 10px;">
                <strong>Email:</strong> dpo@infragovservices.com<br>
                <strong>Phone:</strong> (02) 8988-4242
            </p>
        </div>

        <div class="section-box">
            <h2><span class="icon">📅</span> Policy Updates</h2>
            <p>
                This Privacy Policy may be updated periodically to ensure continued compliance with applicable laws, regulations,
                and institutional requirements. Continued use of the System signifies acceptance of any revisions to this Policy.
            </p>
            <p style="margin-top: 10px;">
                <strong>Last Updated:</strong> February 2026
            </p>
        </div>

        <div class="btn-wrap">
            <a href="<?= $BASE_URL ?>citizencimm.php" class="btn">Back to Home</a>
        </div>
    </div>
</div>

<!-- FOOTER - Updated from citizencimm.php -->
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
                <li><a href="#">Privacy Policy</a></li>
                <li><a href="<?= $BASE_URL ?>termcon.php">Terms of Service</a></li>
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