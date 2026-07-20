<?php
session_start();

// --- SERVER TIMEZONE SYNC FOR CLOCK ENHANCEMENT ---
date_default_timezone_set('Asia/Manila');
$serverTimestamp = time();

require_once __DIR__ . '/../../includes/config/auth_config.php';
require_once __DIR__ . '/../../includes/config/db.php';

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

// Get recent maintenance for preview — same combined source as citizenreports.php
$recent_maintenance = array();

// ── 1. Pull from maintenance_schedule ────────────────────────────────────────
$maint_q = $conn->query("
    SELECT sched_id, task, location, category, status, starting_date, budget
    FROM maintenance_schedule
    ORDER BY starting_date DESC
");
if ($maint_q) {
    while ($row = $maint_q->fetch_assoc()) {
        $recent_maintenance[] = [
            'id_label'     => '#SCH-' . str_pad($row['sched_id'], 3, '0', STR_PAD_LEFT),
            'task'         => $row['task'],
            'location'     => $row['location'],
            'status'       => $row['status'],
            'starting_date'=> $row['starting_date'],
        ];
    }
}

// ── 2. Pull from reports (joined with request_resolutions + requests) ─────────
$rpt_q = $conn->query("
    SELECT
        r.rep_id, r.starting_date,
        res.status AS resolution_status,
        req.infrastructure, req.location
    FROM reports r
    LEFT JOIN request_resolutions res ON r.res_id  = res.res_id
    LEFT JOIN requests             req ON res.req_id = req.req_id
    WHERE r.starting_date IS NOT NULL
    ORDER BY r.starting_date DESC
");
if ($rpt_q) {
    while ($rRow = $rpt_q->fetch_assoc()) {
        $resStatus = $rRow['resolution_status'] ?? '';
        if ($resStatus === 'Completed') {
            $dispStatus = 'Completed';
        } elseif (in_array($resStatus, ['In Progress', 'Pending Completion'])) {
            $dispStatus = 'In Progress';
        } else {
            $dispStatus = 'Scheduled';
        }
        $recent_maintenance[] = [
            'id_label'     => '#RPT-' . str_pad($rRow['rep_id'], 3, '0', STR_PAD_LEFT),
            'task'         => $rRow['infrastructure'] ?? 'Infrastructure Report',
            'location'     => $rRow['location'] ?? '—',
            'status'       => $dispStatus,
            'starting_date'=> $rRow['starting_date'],
        ];
    }
}

// ── 3. Sort combined by starting_date DESC, limit 5 ──────────────────────────
usort($recent_maintenance, function($a, $b) {
    return strcmp($b['starting_date'] ?? '', $a['starting_date'] ?? '');
});
$recent_maintenance = array_slice($recent_maintenance, 0, 5);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="<?= $OFFICIAL_LOGO ?>" type="image/png">
    <title>InfraGovServices - Community Infrastructure Maintenance</title>
    <link rel="stylesheet" href="<?= $BASE_URL ?>assets/css/citizen_global.css">
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
            background: url("<?= $BASE_URL ?>assets/img/cityhall.jpeg") center/cover no-repeat fixed;
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

        /* === Trust & Feature icon bubble (mirrors employee.php metric-icon) === */
        .trust-icon {
            font-size: 28px;
            margin-bottom: 15px;
            width: 64px;
            height: 64px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #1a56db, #3b82f6);
            box-shadow: 0 4px 14px rgba(33, 100, 243, 0.35);
            color: #ffffff !important;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .trust-icon i { color: #ffffff !important; }
        .trust-item:hover .trust-icon { transform: scale(1.1) rotate(5deg); }

        .feature-icon {
            font-size: 32px;
            margin-bottom: 20px;
            width: 72px;
            height: 72px;
            border-radius: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #1a56db, #3b82f6);
            box-shadow: 0 4px 14px rgba(33, 100, 243, 0.35);
            color: #ffffff !important;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .feature-icon i { color: #ffffff !important; }
        .feature-card:hover .feature-icon { transform: scale(1.1) rotate(5deg); }

        /* Dark mode: keep white icon + add a glowing border for visibility */
        [data-theme="dark"] .trust-icon,
        [data-theme="dark"] .trust-icon i { color: #ffffff !important; }
        [data-theme="dark"] .trust-icon {
            background: linear-gradient(135deg, #1e40af, #2563eb);
            border: 1.5px solid rgba(96, 165, 250, 0.55);
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.4);
        }
        [data-theme="dark"] .feature-icon,
        [data-theme="dark"] .feature-icon i { color: #ffffff !important; }
        [data-theme="dark"] .feature-icon {
            background: linear-gradient(135deg, #1e40af, #2563eb);
            border: 1.5px solid rgba(96, 165, 250, 0.55);
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.4);
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

        /* .feature-icon dimensions/font-size defined in bubble block above */

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

        .status-pending   { background: #fff3cd; color: #856404; }
        .status-progress  { background: #cce5ff; color: #004085; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-delayed   { background: #ffebee; color: #c62828; }

        [data-theme="dark"] .status-pending   { background: rgba(133,100,4,0.22);   color: #fdd835; }
        [data-theme="dark"] .status-progress  { background: rgba(0,64,133,0.22);    color: #90caf9; }
        [data-theme="dark"] .status-completed { background: rgba(21,87,36,0.22);    color: #81c784; }
        [data-theme="dark"] .status-delayed   { background: rgba(198,40,40,0.22);   color: #e57373; }

        .activity-id {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--accent-secondary, #3762c8);
            background: rgba(55,98,200,0.08);
            border-radius: 6px;
            padding: 1px 6px;
            margin-right: 6px;
            letter-spacing: 0.03em;
            vertical-align: middle;
        }
        [data-theme="dark"] .activity-id {
            background: rgba(95,140,255,0.15);
            color: #8ab4f8;
        }

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

        /* While the guide is active, collapse all animate-on-scroll transitions to
           zero so getBoundingClientRect() always measures the final settled position.
           Without this, a parent card mid-animation (e.g. feature-card.delay-2 =
           0.2s delay + 0.8s transition) shifts the child element's coordinates and
           the spotlight lands in the wrong place on the first visit to that step. */
        body.guide-active .animate-on-scroll {
            transition-duration: 0s !important;
            transition-delay:    0s !important;
        }

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

        /* ============================================================
           GUIDE BUTTON — entry point pulse
           ============================================================ */
        .guide-entry-btn {
            position: relative;
        }
        @keyframes guideBtnPulse {
            0%   { box-shadow: 0 0 0 0 rgba(255,255,255,0.55); }
            70%  { box-shadow: 0 0 0 14px rgba(255,255,255,0); }
            100% { box-shadow: 0 0 0 0 rgba(255,255,255,0); }
        }
        .guide-entry-btn.pulse-once {
            animation: guideBtnPulse 0.9s ease-out 2;
        }

        /* ============================================================
           GUIDE OVERLAY + SPOTLIGHT
           ============================================================ */
        #guideOverlay {
            position: fixed;
            inset: 0;
            z-index: 99980;
            pointer-events: all;
            background: transparent;
            display: none;
        }
        #guideSpotlight {
            position: fixed;
            z-index: 99982;
            border-radius: 14px;
            pointer-events: none;
            background: transparent;
            /* Smooth position/size transitions between steps */
            transition:
                top    0.22s cubic-bezier(0.4, 0, 0.2, 1),
                left   0.22s cubic-bezier(0.4, 0, 0.2, 1),
                width  0.22s cubic-bezier(0.4, 0, 0.2, 1),
                height 0.22s cubic-bezier(0.4, 0, 0.2, 1);
            /* Dark surround + glowing blue ring */
            box-shadow:
                0 0 0 9999px rgba(0, 0, 0, 0.72),
                0 0 0 2.5px #3b82f6,
                0 0 0 5px  rgba(59, 130, 246, 0.20),
                0 0 26px   rgba(59, 130, 246, 0.55);
            animation: guideSpotPulse 2.2s ease-in-out infinite;
        }
        @keyframes guideSpotPulse {
            0%, 100% {
                box-shadow:
                    0 0 0 9999px rgba(0, 0, 0, 0.72),
                    0 0 0 2.5px #3b82f6,
                    0 0 0 5px  rgba(59, 130, 246, 0.20),
                    0 0 22px   rgba(59, 130, 246, 0.50);
            }
            50% {
                box-shadow:
                    0 0 0 9999px rgba(0, 0, 0, 0.72),
                    0 0 0 3.5px #3b82f6,
                    0 0 0 8px  rgba(59, 130, 246, 0.15),
                    0 0 42px   rgba(59, 130, 246, 0.82);
            }
        }

        /* ============================================================
           GUIDE CARD (floating tooltip)
           ============================================================ */
        #guideCard {
            position: fixed;
            z-index: 99990;
            width: 340px;
            max-width: calc(100vw - 32px);
            background: var(--bg-secondary, #fff);
            border: 1.5px solid rgba(59, 130, 246, 0.45);
            border-radius: 20px;
            box-shadow:
                0 24px 56px rgba(0, 0, 0, 0.32),
                0 0 0 1px   rgba(59, 130, 246, 0.12);
            overflow: hidden;
            pointer-events: all;
        }
        @keyframes guideCardIn {
            from { opacity: 0; transform: scale(0.88) translateY(12px); }
            to   { opacity: 1; transform: scale(1)    translateY(0);    }
        }
        [data-theme="dark"] #guideCard {
            background: rgba(15, 23, 42, 0.97);
            box-shadow:
                0 24px 56px rgba(0, 0, 0, 0.65),
                0 0 0 1px   rgba(59, 130, 246, 0.28);
        }

        /* Top accent bar */
        .guide-accent-bar {
            height: 4px;
            background: linear-gradient(90deg, #1d4ed8, #3b82f6, #60a5fa, #3b82f6, #1d4ed8);
            background-size: 300% 100%;
            animation: guideBarShimmer 2.5s linear infinite;
        }
        @keyframes guideBarShimmer {
            0%   { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        /* Header row */
        .guide-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 11px 16px 4px;
        }
        .guide-step-num {
            font-size: 10.5px;
            font-weight: 800;
            color: #2b6cb0;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        [data-theme="dark"] .guide-step-num { color: #60a5fa; }
        .guide-close-btn {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 18px;
            line-height: 1;
            color: var(--text-secondary, #666);
            width: 28px;
            height: 28px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.16s;
            font-family: inherit;
        }
        .guide-close-btn:hover {
            background: rgba(59, 130, 246, 0.12);
            color: #2b6cb0;
        }

        /* Body */
        .guide-card-body {
            padding: 2px 18px 12px;
        }
        .guide-card-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-primary, #000);
            margin-bottom: 7px;
            line-height: 1.3;
        }
        .guide-card-desc {
            font-size: 13px;
            color: var(--text-secondary, #444);
            line-height: 1.65;
        }
        .guide-card-desc b {
            color: var(--text-primary, #000);
            font-weight: 600;
        }

        /* Footer */
        .guide-card-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 9px 18px 13px;
            border-top: 1px solid var(--border-color, rgba(0,0,0,0.08));
            gap: 10px;
        }
        #guideDots {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
            max-width: 140px;
        }
        .guide-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--border-color, rgba(0,0,0,0.18));
            cursor: pointer;
            flex-shrink: 0;
            transition: background 0.2s, transform 0.2s;
        }
        .guide-dot:hover  { background: rgba(59,130,246,0.45); transform: scale(1.15); }
        .guide-dot.active { background: #2b6cb0;               transform: scale(1.35); }
        [data-theme="dark"] .guide-dot.active { background: #3b82f6; }

        .guide-nav-btns {
            display: flex;
            gap: 7px;
            flex-shrink: 0;
        }
        .guide-btn {
            padding: 7px 14px;
            border-radius: 9px;
            border: none;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.16s;
            font-family: inherit;
            line-height: 1;
            white-space: nowrap;
        }
        .guide-btn-prev {
            background: var(--bg-secondary, #f1f5f9);
            color: var(--text-secondary, #555);
            border: 1px solid var(--border-color, #ddd);
        }
        .guide-btn-prev:hover:not(:disabled) { background: var(--border-color, #e2e8f0); }
        .guide-btn-prev:disabled { opacity: 0.32; cursor: not-allowed; }
        [data-theme="dark"] .guide-btn-prev {
            background: rgba(255,255,255,0.07);
            color: #ccc;
            border-color: rgba(255,255,255,0.1);
        }
        [data-theme="dark"] .guide-btn-prev:hover:not(:disabled) { background: rgba(255,255,255,0.12); }

        .guide-btn-next {
            background: linear-gradient(135deg, #2b6cb0, #1d4ed8);
            color: #fff;
            box-shadow: 0 3px 10px rgba(43,108,176,0.40);
        }
        .guide-btn-next:hover {
            transform: translateY(-1px);
            box-shadow: 0 5px 16px rgba(43,108,176,0.58);
        }

        /* Mobile: smaller card — JS handles all top/left/bottom positioning */
        @media (max-width: 639px) {
            #guideCard {
                max-width: calc(100vw - 28px);
            }
            .guide-card-header  { padding: 8px 13px 3px; }
            .guide-card-body    { padding: 1px 13px 8px; }
            .guide-card-footer  { padding: 7px 13px 10px; }
            .guide-card-title   { font-size: 13.5px; }
            .guide-card-desc    { font-size: 12px; line-height: 1.55; }
            .guide-step-num     { font-size: 9.5px; }
            .guide-btn          { padding: 6px 11px; font-size: 11.5px; }
            #guideDots          { gap: 4px; }
            .guide-dot          { width: 6px; height: 6px; }
        }
    </style>
<?php include __DIR__ . '/../../includes/partials/citizen_rendering.php'; ?>
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
            <a href="<?= $BASE_URL ?>citizen_feedback.php" data-i18n="nav_feedback">Feedback</a>
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
            <li><a href="<?= $BASE_URL ?>citizen_feedback.php" class="nav-link"><i class="fas fa-comment-dots"></i><span data-i18n="nav_feedback">Feedback</span></a></li>
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
            <button type="button" class="cta-button cta-secondary guide-entry-btn" id="guideBtn" data-i18n="cta_guide">🗺️ Guide</button>
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
                        $status_key   = 'reports_status_scheduled';
                        if ($item['status'] === 'Completed') {
                            $status_class = 'status-completed';
                            $status_key   = 'reports_status_completed';
                        } elseif ($item['status'] === 'In Progress') {
                            $status_class = 'status-progress';
                            $status_key   = 'reports_status_in_progress';
                        } elseif ($item['status'] === 'Delayed') {
                            $status_class = 'status-delayed';
                            $status_key   = 'reports_status_delayed';
                        }
                    ?>
                        <li class="activity-item">
                            <div class="activity-info">
                                <h4>
                                    <span class="activity-id"><?= htmlspecialchars($item['id_label']) ?></span>
                                    <?= htmlspecialchars($item['task']) ?>
                                </h4>
                                <p><?= htmlspecialchars($item['location']) ?> • <?= !empty($item['starting_date']) ? date('M d, Y', strtotime($item['starting_date'])) : '—' ?></p>
                            </div>
                            <span class="status-badge <?= $status_class ?>" data-i18n="<?= $status_key ?>"><?= htmlspecialchars($item['status']) ?></span>
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
                <li><a href="<?= $BASE_URL ?>citizen_feedback.php" data-i18n="footer_link_feedback">Feedback</a></li>
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

<!-- ═══════════════════════════════════════════════════════════════════
     INLINE FALLBACK TRANSLATIONS — citizencimm.php activity section
     Same pattern as citizenrepform.php / citizenreports.php.
════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    var PAGE_TRANSLATIONS = {
        en: {
            activity_title:             'Recent Maintenance Activity',
            activity_subtitle:          'Stay informed about the latest infrastructure maintenance in your community',
            activity_view_all:          'View All Reports',
            activity_empty:             'No recent maintenance activities to display.',
            reports_status_scheduled:   'Scheduled',
            reports_status_completed:   'Completed',
            reports_status_in_progress: 'In Progress',
            reports_status_delayed:     'Delayed',
            cta_guide:                  '🗺️ Guide',
            /* ── Guide UI chrome ── */
            guide_step_label:  'Step {n} of {total}',
            guide_back:        '← Back',
            guide_next:        'Next →',
            guide_finish:      'Finish ✓',
            guide_close:       'Close Guide',
            guide_dot_label:   'Go to step {n}',
            /* ── Guide step titles ── */
            guide_nav_title:      '🗺️ Navigation Bar',
            guide_lang_title:     '🌐 Language & Dark Mode Controls',
            guide_submit_title:   '📋 Submit a Report Button',
            guide_stats_title:    '📊 Live Statistics',
            guide_features_title: '🛠️ Service Action Links',
            guide_activity_title: '📂 View All Reports Button',
            guide_ongoing_title:  '🔄 Ongoing Repairs',
            guide_about_title:    'ℹ️ Learn More About Us Button',
            guide_footer_title:   '🔗 Footer',
            guide_chatbot_title:  '🤖 AI Chat Assistant',
            /* ── Guide step descriptions ── */
            guide_nav_desc:      'The top bar on every page. Use <b>Home</b>, <b>Reports</b>, <b>Requests</b>, <b>Feedback</b>, and <b>About</b> to navigate. On mobile, tap <b>☰</b> to open the sidebar menu.',
            guide_lang_desc:     'Click the <b>globe / "EN" button</b> to switch between English and Filipino. Click the <b>moon / sun button</b> to toggle Dark Mode for comfortable night-time viewing.',
            guide_lang_desc_mobile: 'This top bar contains two controls: the <b>globe button</b> (with the current language letter) — tap it to switch between <b>English</b> and <b>Filipino</b>. And the <b>sun/moon button</b> on the far right — tap it to toggle <b>Dark Mode</b>.',
            guide_submit_desc:   'Click here to open the <b>Request Form</b>. Describe the infrastructure problem, attach photos, and pin the exact location — your report goes directly to the engineering team.',
            guide_stats_desc:    'Real-time counters from the city database — <b>Completed Repairs</b>, <b>Ongoing Repairs</b>, and <b>Pending Requests</b>. Numbers update automatically as the LGU processes reports.',
            guide_features_desc: 'Each service card has a clickable action link — <b>Submit Request →</b>, <b>View Reports →</b>, <b>Try It Now →</b>, <b>Check Status →</b>, and <b>Learn More →</b>. Click any to go straight to that feature.',
            guide_activity_desc: 'Opens the complete <b>Reports page</b> where you can browse, search, and filter every infrastructure maintenance project by status, location, or date.',
            guide_activity_desc_mobile: 'Tap here to open the full <b>Reports page</b> — browse and filter every infrastructure project by status, location, or date.',
            guide_ongoing_desc:  'The <b>Recent Maintenance Activity</b> list shows the latest work in your community. Items marked <b>In Progress</b> are repairs currently underway.',
            guide_ongoing_desc_mobile: 'Items tagged <b>In Progress</b> are repairs actively being worked on right now. Scroll down to see the <b>View All Reports</b> button for the full list.',
            guide_about_desc:    'Opens the <b>About page</b> with the full CIMMS story — our mission, the team behind the system, and how the LGU is using technology to modernise public infrastructure services.',
            guide_footer_desc:   'Quick-access links at the very bottom — <b>Quick Links</b> (Home, Reports, Submit), <b>Resources</b> (User Guide, FAQs), and <b>Legal</b> pages like Privacy Policy and Terms of Service.',
            guide_chatbot_desc:  'Tap this floating button any time to open the <b>AI Chat Assistant</b>. Ask questions about services, check on a report status, or get step-by-step help submitting a new request.',
        },
        tl: {
            activity_title:             'Kamakailang Aktibidad sa Pagpapanatili',
            activity_subtitle:          'Manatiling may kaalaman tungkol sa pinakabagong pagpapanatili ng imprastraktura sa inyong komunidad',
            activity_view_all:          'Tingnan ang Lahat ng Ulat',
            activity_empty:             'Walang kamakailang aktibidad sa pagpapanatili na ipapakita.',
            reports_status_scheduled:   'Nakaplanong',
            reports_status_completed:   'Natapos',
            reports_status_in_progress: 'Isinasagawa',
            reports_status_delayed:     'Naantala',
            cta_guide:                  '🗺️ Gabay',
            /* ── Guide UI chrome ── */
            guide_step_label:  'Hakbang {n} sa {total}',
            guide_back:        '← Bumalik',
            guide_next:        'Susunod →',
            guide_finish:      'Tapos ✓',
            guide_close:       'Isara ang Gabay',
            guide_dot_label:   'Pumunta sa hakbang {n}',
            /* ── Guide step titles ── */
            guide_nav_title:      '🗺️ Bar ng Nabigasyon',
            guide_lang_title:     '🌐 Mga Kontrol ng Wika at Dark Mode',
            guide_submit_title:   '📋 Pindutan ng Pagsusumite ng Ulat',
            guide_stats_title:    '📊 Mga Istatistika',
            guide_features_title: '🛠️ Mga Link ng Aksyon sa Serbisyo',
            guide_activity_title: '📂 Pindutan ng Lahat ng Ulat',
            guide_ongoing_title:  '🔄 Mga Isinasagawang Pag-aayos',
            guide_about_title:    'ℹ️ Pindutan ng Higit Pang Impormasyon',
            guide_footer_title:   '🔗 Footer ng Pahina',
            guide_chatbot_title:  '🤖 AI Chat Assistant',
            /* ── Guide step descriptions ── */
            guide_nav_desc:      'Ang tuktok na bar sa bawat pahina. Gamitin ang <b>Home</b>, <b>Reports</b>, <b>Requests</b>, <b>Feedback</b>, at <b>About</b> para lumipat ng pahina. Sa mobile, i-tap ang <b>☰</b> para buksan ang sidebar menu.',
            guide_lang_desc:     'I-click ang <b>globe / "EN" na pindutan</b> para lumipat sa pagitan ng English at Filipino. I-click ang <b>moon / sun na pindutan</b> para i-toggle ang Dark Mode para sa komportableng panonood sa gabi.',
            guide_lang_desc_mobile: 'Ang bar na ito ay naglalaman ng dalawang kontrol: ang <b>globe na pindutan</b> (may titik ng kasalukuyang wika) — i-tap para lumipat sa <b>English</b> o <b>Filipino</b>. At ang <b>sun/moon na pindutan</b> sa kanan — i-tap para i-toggle ang <b>Dark Mode</b>.',
            guide_submit_desc:   'I-click dito para buksan ang <b>Request Form</b>. Ilarawan ang problema sa imprastraktura, mag-attach ng mga larawan, at i-pin ang eksaktong lokasyon — ang iyong ulat ay direktang napupunta sa engineering team.',
            guide_stats_desc:    'Mga real-time na counter mula sa database ng lungsod — <b>Mga Natapos na Pag-aayos</b>, <b>Mga Isinasagawang Pag-aayos</b>, at <b>Mga Nakabinbing Kahilingan</b>. Awtomatikong nag-a-update habang pinoproseso ng LGU ang mga ulat.',
            guide_features_desc: 'Ang bawat card ng serbisyo ay may clickable na link — <b>Submit Request →</b>, <b>View Reports →</b>, <b>Try It Now →</b>, <b>Check Status →</b>, at <b>Learn More →</b>. I-click ang alinman para pumunta sa feature na iyon.',
            guide_activity_desc: 'Nagbubukas ng kumpletong <b>pahina ng Mga Ulat</b> kung saan maaari kang mag-browse, maghanap, at mag-filter ng bawat proyekto ng pagpapanatili ayon sa katayuan, lokasyon, o petsa.',
            guide_activity_desc_mobile: 'I-tap dito para buksan ang buong <b>pahina ng Mga Ulat</b> — mag-browse at mag-filter ng bawat proyekto ng imprastraktura ayon sa katayuan, lokasyon, o petsa.',
            guide_ongoing_desc:  'Ang listahan ng <b>Kamakailang Aktibidad sa Pagpapanatili</b> ay nagpapakita ng pinakabagong trabaho sa inyong komunidad. Ang mga aytem na may markang <b>In Progress</b> ay mga pag-aayos na kasalukuyang isinasagawa.',
            guide_ongoing_desc_mobile: 'Ang mga aytem na may tatak na <b>In Progress</b> ay mga pag-aayos na aktibong ginagawa ngayon. Mag-scroll pababa para makita ang pindutang <b>View All Reports</b> para sa buong listahan.',
            guide_about_desc:    'Nagbubukas ng <b>pahina ng About</b> na may buong kwento ng CIMMS — ang aming misyon, ang koponan sa likod ng sistema, at kung paano ginagamit ng LGU ang teknolohiya para modernisahin ang mga pampublikong serbisyo sa imprastraktura.',
            guide_footer_desc:   'Mga mabilis na link sa pinakababa ng bawat pahina — <b>Mga Mabilis na Link</b> (Home, Reports, Submit), <b>Mga Mapagkukunan</b> (User Guide, FAQs), at <b>Mga Legal</b> na pahina tulad ng Privacy Policy at Terms of Service.',
            guide_chatbot_desc:  'I-tap ang floating button na ito anumang oras para buksan ang <b>AI Chat Assistant</b>. Magtanong tungkol sa mga serbisyo, tingnan ang katayuan ng ulat, o kumuha ng hakbang-hakbang na tulong sa pagsusumite ng bagong kahilingan.',
        }
    };

    function getTranslation(key) {
        var lang = localStorage.getItem('lang') || 'en';
        if (window.__preloadedTranslations && window.__preloadedTranslations[lang]) {
            var val = window.__preloadedTranslations[lang][key];
            if (val) return val;
        }
        return (PAGE_TRANSLATIONS[lang] && PAGE_TRANSLATIONS[lang][key])
            || (PAGE_TRANSLATIONS['en'][key])
            || key;
    }

    function applyPageFallbacks() {
        document.querySelectorAll('[data-i18n]').forEach(function (el) {
            var key = el.getAttribute('data-i18n');
            var val = getTranslation(key);
            if (val && val !== key) el.textContent = val;
        });
        document.querySelectorAll('[data-i18n-placeholder]').forEach(function (el) {
            var key = el.getAttribute('data-i18n-placeholder');
            var val = getTranslation(key);
            if (val && val !== key) el.placeholder = val;
        });
        document.querySelectorAll('[data-i18n-title]').forEach(function (el) {
            var key = el.getAttribute('data-i18n-title');
            var val = getTranslation(key);
            if (val && val !== key) el.title = val;
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyPageFallbacks);
    } else {
        applyPageFallbacks();
    }
    document.addEventListener('i18nReady', applyPageFallbacks);

    /* Expose for the guide IIFE's gT() helper */
    window.__PAGE_TRANSLATIONS_REF = PAGE_TRANSLATIONS;
})();
</script>
<?php include __DIR__ . '/../../includes/partials/citizen_global.php'; ?>
<script>window.CHATBOT_ENDPOINT = '<?= $BASE_URL ?>functionality/chatbot.php';</script>
<?php include __DIR__ . '/../../includes/partials/chatbot-widget.php'; ?>

<!-- ═══════════════════════════════════════════════════════════════
     INTERACTIVE PAGE GUIDE — overlay + spotlight + card
════════════════════════════════════════════════════════════════ -->
<div id="guideOverlay">
    <div id="guideSpotlight"></div>
    <div id="guideCard">
        <div class="guide-accent-bar"></div>
        <div class="guide-card-header">
            <span class="guide-step-num" id="guideStepNum">Step 1 of 10</span>
            <button class="guide-close-btn" id="guideCloseBtn" title="Close Guide">✕</button>
        </div>
        <div class="guide-card-body">
            <div class="guide-card-title" id="guideCardTitle"></div>
            <div class="guide-card-desc"  id="guideCardDesc"></div>
        </div>
        <div class="guide-card-footer">
            <div id="guideDots"></div>
            <div class="guide-nav-btns">
                <button class="guide-btn guide-btn-prev" id="guidePrevBtn">← Back</button>
                <button class="guide-btn guide-btn-next" id="guideNextBtn">Next →</button>
            </div>
        </div>
    </div>
</div>

<script>
/* ── Interactive Page Guide ──────────────────────────────────────────
   Spotlight tour. Activated by the "Guide" button in the hero CTA.
──────────────────────────────────────────────────────────────────── */
(function () {
    'use strict';

    /* ── Translation helper (reads preloaded JSON first, then PAGE_TRANSLATIONS fallback) ── */
    function gT(key) {
        var lang = localStorage.getItem('lang') || 'en';
        if (window.__preloadedTranslations && window.__preloadedTranslations[lang] && window.__preloadedTranslations[lang][key])
            return window.__preloadedTranslations[lang][key];
        /* Fall back to the PAGE_TRANSLATIONS defined in the inline script above */
        var pt = window.__PAGE_TRANSLATIONS_REF;
        if (pt && pt[lang] && pt[lang][key]) return pt[lang][key];
        if (pt && pt['en'] && pt['en'][key]) return pt['en'][key];
        return key;
    }

    /* ── Step definitions — use translation keys resolved at runtime ── */
    var RAW_STEPS = [
        {
            sel: '.nav, .mobile-top-nav',
            tKey: 'guide_nav_title',
            dKey: 'guide_nav_desc',
            pad: 6
        },
        {
            sel: '.nav-actions',
            selMobile: '.mobile-top-nav',           /* highlight the whole mobile top bar — contains globe + dark-mode btn */
            tKey: 'guide_lang_title',
            dKey: 'guide_lang_desc',
            dKeyMobile: 'guide_lang_desc_mobile',
            pad: 4
        },
        {
            sel: '.hero-cta .cta-primary',
            tKey: 'guide_submit_title',
            dKey: 'guide_submit_desc',
            pad: 12
        },
        {
            sel: '.stats-grid',
            selMobile: '.stats-grid .stat-card:nth-child(2)',   /* Ongoing Repairs card — avoids full-grid highlight on 1-col mobile */
            tKey: 'guide_stats_title',
            dKey: 'guide_stats_desc',
            pad: 12
        },
        {
            sel: '.features-section .feature-link',
            selMobile: '.features-section .features-grid .feature-card:nth-child(2) .feature-link',   /* "View Reports →" link in the Track Maintenance card */
            tKey: 'guide_features_title',
            dKey: 'guide_features_desc',
            pad: 8
        },
        {
            /* Desktop: whole activity list. Mobile: first "In Progress" badge — pinpoints an ongoing repair */
            sel:       '.activity-list',
            selMobile: '.status-badge.status-progress',
            tKey: 'guide_ongoing_title',
            dKey: 'guide_ongoing_desc',
            dKeyMobile: 'guide_ongoing_desc_mobile',
            pad: 8,
            optional: true
        },
        {
            /* View All Reports button — same element on both viewports */
            sel:       '.activity-section .cta-button',
            selMobile: '.activity-section .cta-button',
            tKey: 'guide_activity_title',
            dKey: 'guide_activity_desc',
            dKeyMobile: 'guide_activity_desc_mobile',
            pad: 6,
            optional: true
        },
        {
            /* Learn More About Us button inside the about section */
            sel: '.about-section .cta-button',
            tKey: 'guide_about_title',
            dKey: 'guide_about_desc',
            pad: 10
        },
        {
            sel: '.footer',
            selMobile: '.footer-links',    /* first Quick-Links column only — full footer is too tall on mobile */
            tKey: 'guide_footer_title',
            dKey: 'guide_footer_desc',
            pad: 14
        },
        {
            sel: '#chatbot-fab-btn, .chatbot-fab, .chatbot-toggle-btn, .chatbot-toggle, [class*="chatbot-fab"], [class*="chatbot-toggle"]',
            tKey: 'guide_chatbot_title',
            dKey: 'guide_chatbot_desc',
            pad: 10,
            optional: true,
            mobileCardTop: true            /* chatbot FAB is bottom-right; flip card to top so it doesn't block it */
        }
    ];

    /* ── DOM refs (populated on first startGuide call) ── */
    var steps, curStep, posTimer, lastIsMobile;
    var overlay, spotlight, card, stepNumEl, titleEl, descEl, dotsEl, prevBtn, nextBtn, closeBtn;

    function initDOM() {
        overlay    = document.getElementById('guideOverlay');
        spotlight  = document.getElementById('guideSpotlight');
        card       = document.getElementById('guideCard');
        stepNumEl  = document.getElementById('guideStepNum');
        titleEl    = document.getElementById('guideCardTitle');
        descEl     = document.getElementById('guideCardDesc');
        dotsEl     = document.getElementById('guideDots');
        prevBtn    = document.getElementById('guidePrevBtn');
        nextBtn    = document.getElementById('guideNextBtn');
        closeBtn   = document.getElementById('guideCloseBtn');

        closeBtn.addEventListener('click', closeGuide);

        nextBtn.addEventListener('click', function () {
            if (curStep < steps.length - 1) goToStep(curStep + 1);
            else closeGuide();
        });
        prevBtn.addEventListener('click', function () {
            if (curStep > 0) goToStep(curStep - 1);
        });

        /* Click transparent part of overlay → close */
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeGuide();
        });

        /* Keyboard navigation */
        document.addEventListener('keydown', function (e) {
            if (!overlay || overlay.style.display === 'none') return;
            if (e.key === 'Escape') { closeGuide(); return; }
            if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                e.preventDefault();
                if (curStep < steps.length - 1) goToStep(curStep + 1); else closeGuide();
            }
            if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (curStep > 0) goToStep(curStep - 1);
            }
        });

        /* Reposition on resize — auto-close if viewport class (mobile ↔ desktop) flips
           to avoid mismatched element selectors and layout conflicts */
        window.addEventListener('resize', function () {
            if (!overlay || overlay.style.display === 'none') return;
            var nowIsMobile = window.innerWidth < 640;
            if (nowIsMobile !== lastIsMobile) {
                /* Viewport class changed — close the guide to avoid stale selectors */
                lastIsMobile = nowIsMobile;
                closeGuide();
                return;
            }
            /* Same class — re-run the full step (scroll + resolve element + position) */
            clearTimeout(posTimer);
            posTimer = setTimeout(function () { goToStep(curStep); }, 150);
        });
    }

    /* ── Public entry point ── */
    window.startGuide = function () {
        if (!overlay) initDOM();

        steps = RAW_STEPS.filter(function (s) {
            if (s.optional) return !!getEl(s.sel);
            return true;
        });

        curStep = 0;
        lastIsMobile = window.innerWidth < 640;
        overlay.style.display = 'block';
        document.body.classList.add('guide-active');
        document.body.style.overflow = 'hidden';
        goToStep(0);
    };

    function closeGuide() {
        if (!overlay) return;
        overlay.style.display = 'none';
        document.body.style.overflow = '';
        document.body.classList.remove('guide-active');
        clearTimeout(posTimer);
    }

    /* ── Resolve a comma-separated selector — prefers visible elements ── */
    function getElForStep(step) {
        var isMobile = window.innerWidth < 640;
        var sel = (isMobile && step.selMobile) ? step.selMobile : step.sel;
        return getEl(sel);
    }

    function getEl(sel) {
        var parts = sel.split(',');
        for (var i = 0; i < parts.length; i++) {
            var el = document.querySelector(parts[i].trim());
            if (el && isElVisible(el)) return el;
        }
        for (var i = 0; i < parts.length; i++) {
            var el = document.querySelector(parts[i].trim());
            if (el) return el;
        }
        return null;
    }

    function isElVisible(el) {
        if (!el) return false;
        var s = window.getComputedStyle(el);
        if (s.display === 'none' || s.visibility === 'hidden' || parseFloat(s.opacity) === 0) return false;
        if (s.position === 'fixed') return el.offsetWidth > 0 && el.offsetHeight > 0;
        return el.offsetWidth > 0 || el.offsetHeight > 0;
    }

    function isInFixed(el) {
        var node = el;
        while (node && node !== document.body) {
            if (window.getComputedStyle(node).position === 'fixed') return true;
            node = node.parentElement;
        }
        return false;
    }

    /* ── Wait for smooth-scroll to fully settle, then call callback.
          Uses the native scrollend event when available (Chrome 114+, FF 109+)
          and falls back to a scroll-position-stopped poll otherwise.
          A hard 900 ms cap guarantees the guide never hangs. ── */
    function waitScrollEnd(cb) {
        clearTimeout(posTimer);
        var called = false;

        /* done() has rAF delay baked in from the start so every code path
           (scrollend listener, poll closure, hard-cap timeout) all go through
           the same double-rAF before calling cb.  This ensures we measure
           getBoundingClientRect *after* the browser has repainted the post-
           scroll layout (URL-bar collapse/expand, CSS transition frames, etc.) */
        function done() {
            if (called) return;
            called = true;
            clearTimeout(posTimer);
            window.removeEventListener('scrollend', done);
            requestAnimationFrame(function () {
                requestAnimationFrame(cb);
            });
        }

        if ('onscrollend' in window) {
            window.addEventListener('scrollend', done, { once: true });
            posTimer = setTimeout(done, 900);
        } else {
            /* Polling fallback: fire once Y is stable for 2 consecutive 55 ms ticks */
            var lastY  = window.scrollY;
            var steady = 0;
            function poll() {
                var y = window.scrollY;
                if (y === lastY) { if (++steady >= 2) { done(); return; } }
                else             { steady = 0; lastY = y; }
                posTimer = setTimeout(poll, 55);
            }
            posTimer = setTimeout(poll, 55);
        }
    }

    /* ── Force-complete animate-on-scroll on an element AND all its ancestors.
          Necessary because measuring getBoundingClientRect() on a child element
          returns wrong coordinates if any ancestor is still mid-animation
          (e.g. feature-card.delay-2 shifts the child .feature-link by 30 px
          during its 0.8 s translateY transition).  The body.guide-active CSS
          already zeroes transition durations, but we still need to add the
          animate-in class so the element jumps to opacity:1 / translateY(0)
          before the reflow measurement. ── */
    function forceAnimateAncestors(el) {
        var node = el;
        while (node && node !== document.body) {
            if (node.classList && node.classList.contains('animate-on-scroll')) {
                node.classList.add('animate-in');
            }
            node = node.parentElement;
        }
        /* Synchronous layout flush — commits the classList changes so the
           very next getBoundingClientRect() reads final post-animation coords */
        void el.offsetHeight;
    }

    /* ── Navigate to a step ── */
    function goToStep(idx) {
        curStep = idx;
        var step = steps[idx];
        /* Always resolve via getElForStep so selMobile is honoured from the start */
        var el   = getElForStep(step);
        if (!el) return;

        updateCard(step, idx);

        var inFixed = isInFixed(el);
        if (inFixed) {
            clearTimeout(posTimer);
            posTimer = setTimeout(function () { positionStep(idx); }, 25);
            return;
        }

        /* Force any pending animate-on-scroll transitions to their final state on
           the target element and all its ancestors before measuring the scroll
           target — a mid-animation parent shifts the child's coordinates. */
        forceAnimateAncestors(el);

        var r0 = el.getBoundingClientRect();
        /* Mobile: place element at 30% from top so the guide card below the
           spotlight has clear space.  Desktop: 50% (centred). */
        var viewFraction = (window.innerWidth < 640) ? 0.30 : 0.50;
        var targetY  = Math.max(0, window.scrollY + r0.top - window.innerHeight * viewFraction + r0.height / 2);
        var noScroll = Math.abs(window.scrollY - targetY) < 3;

        if (noScroll) {
            clearTimeout(posTimer);
            /* Same double-rAF as waitScrollEnd so we always measure post-paint */
            requestAnimationFrame(function () {
                requestAnimationFrame(function () { positionStep(idx); });
            });
        } else {
            window.scrollTo({ top: targetY, behavior: 'smooth' });
            waitScrollEnd(function () {
                /* On mobile the browser URL bar keeps animating for ~100 ms after
                   the scroll position settles — wait for it before measuring */
                if (window.innerWidth < 640) {
                    clearTimeout(posTimer);
                    posTimer = setTimeout(function () { positionStep(idx); }, 120);
                } else {
                    positionStep(idx);
                }
            });
        }
    }

    function positionStep(idx) {
        var step = steps[idx];
        var el   = getElForStep(step);
        if (!el) return;
        var pad  = step.pad || 12;

        /* Force-complete animate-on-scroll on the target and every ancestor so
           the elements are at least instructed to reach their final state before
           we start polling. */
        forceAnimateAncestors(el);

        /* ── Poll until the element's viewport position stops changing ──────
           This is the only reliable strategy on mobile: CSS transitions,
           delayed IntersectionObserver callbacks, and URL-bar resize all shift
           getBoundingClientRect() for up to ~1 s after a scroll.  Rather than
           guessing how long to wait, we sample every 30 ms and commit the
           spotlight only once three consecutive readings agree to within 0.5 px.
           A hard 1 400 ms cap guarantees the guide never hangs. */
        var STABLE_TICKS = 3;
        var TICK_MS      = 30;
        var MAX_ELAPSED  = 1400;
        var elapsed      = 0;
        var stableCnt    = 0;
        var lastTop      = null;
        var lastLeft     = null;

        clearTimeout(posTimer);

        function tick() {
            var r = el.getBoundingClientRect();

            if (
                lastTop  !== null &&
                Math.abs(r.top  - lastTop)  < 0.5 &&
                Math.abs(r.left - lastLeft) < 0.5
            ) {
                stableCnt++;
            } else {
                stableCnt = 0;
            }
            lastTop  = r.top;
            lastLeft = r.left;

            if (stableCnt >= STABLE_TICKS || elapsed >= MAX_ELAPSED) {
                /* Settled — commit spotlight and card positions */
                spotlight.style.top    = (r.top    - pad) + 'px';
                spotlight.style.left   = (r.left   - pad) + 'px';
                spotlight.style.width  = (r.width  + pad * 2) + 'px';
                spotlight.style.height = (r.height + pad * 2) + 'px';
                placeCard(r, pad, step);
            } else {
                elapsed += TICK_MS;
                posTimer = setTimeout(tick, TICK_MS);
            }
        }

        tick();
    }

    /* ── Position the guide card near the spotlight ── */
    function placeCard(r, pad, step) {
        var vw  = window.innerWidth;
        /* Use visualViewport.height when available — it reflects the actual
           visible area after the mobile URL bar and on-screen keyboard are
           accounted for, preventing the card from being clipped off-screen */
        var vh  = (window.visualViewport ? window.visualViewport.height : null) || window.innerHeight;
        var cw  = Math.min(320, vw - 28);
        /* Use the real rendered card height — avoids covering the target on desktop */
        var ch  = card.offsetHeight || 200;
        var gap = 14;

        card.style.width  = cw + 'px';
        card.style.bottom = 'auto';
        card.style.right  = 'auto';

        if (vw < 640) {
            /* Element-relative placement on mobile:
               Try to place the card just below or just above the spotlight,
               exactly the same logic as desktop but constrained to mobile viewport.
               Top clearance = 70px (mobile nav bar), bottom clearance = 14px. */
            var MOB_TOP_CLEAR = 70;   /* height of mobile-top-nav */
            var MOB_BOT_CLEAR = 14;
            var spaceBelow = vh - (r.bottom + pad) - MOB_BOT_CLEAR;
            var spaceAbove = r.top  - pad - MOB_TOP_CLEAR;
            var mobileTop;

            if (spaceBelow >= ch + gap) {
                mobileTop = r.bottom + pad + gap;
            } else if (spaceAbove >= ch + gap) {
                mobileTop = r.top - pad - ch - gap;
            } else if (spaceBelow >= spaceAbove) {
                /* Not enough room either side — anchor to whichever edge has more room */
                mobileTop = vh - ch - MOB_BOT_CLEAR;
            } else {
                mobileTop = MOB_TOP_CLEAR + 4;
            }

            /* Hard clamp so card never goes off-screen */
            mobileTop = Math.max(MOB_TOP_CLEAR + 4, Math.min(mobileTop, vh - ch - MOB_BOT_CLEAR));

            /* Use pixel left (not % + transform) so the pop-in animation's
               transform: scale/translateY doesn't fight with translateX(-50%) */
            card.classList.remove('guide-card-top');
            card.style.top       = mobileTop + 'px';
            card.style.bottom    = 'auto';
            card.style.left      = Math.round((vw - cw) / 2) + 'px';
            card.style.transform = 'none';
            card.style.right     = 'auto';
            triggerCardAnim();
            return;
        }
        card.classList.remove('guide-card-top');
        card.style.transform = 'none';

        var spaceBelow = vh - (r.bottom + pad);
        var spaceAbove = r.top - pad;
        var top, left;

        if (spaceBelow >= ch + gap) {
            top  = r.bottom + pad + gap;
            left = r.left + r.width / 2 - cw / 2;
        } else if (spaceAbove >= ch + gap) {
            top  = r.top - pad - ch - gap;
            left = r.left + r.width / 2 - cw / 2;
        } else {
            top = Math.max(16, Math.min(r.top + r.height / 2 - ch / 2, vh - ch - 16));
            if (vw - (r.right + pad) >= cw + gap) {
                left = r.right + pad + gap;
            } else {
                left = r.left - pad - cw - gap;
            }
        }

        left = Math.max(16, Math.min(left, vw - cw - 16));
        top  = Math.max(16, Math.min(top,  vh - ch - 16));

        card.style.top  = top  + 'px';
        card.style.left = left + 'px';
        triggerCardAnim();
    }

    function triggerCardAnim() {
        card.style.animation = 'none';
        void card.offsetWidth;
        card.style.animation = 'guideCardIn 0.18s cubic-bezier(0.34,1.56,0.64,1) forwards';
    }

    /* ── Update card text, dots, and button labels (all translated) ── */
    function updateCard(step, idx) {
        /* Step counter */
        var stepLabel = gT('guide_step_label')
            .replace('{n}', idx + 1)
            .replace('{total}', steps.length);
        stepNumEl.textContent = stepLabel;

        /* Title & description (use mobile-specific desc when on narrow viewport) */
        var isMob = window.innerWidth < 640;
        titleEl.textContent = gT(step.tKey);
        descEl.innerHTML    = (isMob && step.dKeyMobile) ? gT(step.dKeyMobile) : gT(step.dKey);

        /* Close button title */
        closeBtn.title = gT('guide_close');

        /* Progress dots */
        dotsEl.innerHTML = '';
        for (var i = 0; i < steps.length; i++) {
            var d = document.createElement('span');
            d.className = 'guide-dot' + (i === idx ? ' active' : '');
            d.title     = gT('guide_dot_label').replace('{n}', i + 1);
            (function (stepIdx) {
                d.addEventListener('click', function () { goToStep(stepIdx); });
            })(i);
            dotsEl.appendChild(d);
        }

        /* Nav buttons */
        prevBtn.textContent = gT('guide_back');
        prevBtn.disabled    = (idx === 0);
        nextBtn.textContent = (idx === steps.length - 1) ? gT('guide_finish') : gT('guide_next');
    }

    /* ── Wire Guide button + auto-pulse after 3 s ── */
    document.addEventListener('DOMContentLoaded', function () {
        var btn = document.getElementById('guideBtn');
        if (!btn) return;

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            startGuide();
        });

        setTimeout(function () {
            btn.classList.add('pulse-once');
            btn.addEventListener('animationend', function () {
                btn.classList.remove('pulse-once');
            }, { once: true });
        }, 3000);
    });

})();
</script>

</body>
</html>