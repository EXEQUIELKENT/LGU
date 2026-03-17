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

// Get repairs count from repair_archive (kept for reference, no longer shown in cards)
$repairs_count = 0;
$repairs_result = $conn->query("SELECT COUNT(*) as count FROM repair_archive");
if ($repairs_result) {
    $repairs_row = $repairs_result->fetch_assoc();
    $repairs_count = (int)$repairs_row['count'];
}

// Get pending count from requests (Pending approval status)
$pending_count = 0;
$pending_result = $conn->query("SELECT COUNT(*) as count FROM requests WHERE approval_status = 'Pending'");
if ($pending_result) {
    $pending_row = $pending_result->fetch_assoc();
    $pending_count = (int)$pending_row['count'];
}

// Get maintenance schedule data for table
$maintenance_data = array();

// ── 1. Pull from maintenance_schedule ────────────────────────────────────────
$maintenance_result = $conn->query("
    SELECT sched_id, task, location, category, status, starting_date, estimated_completion_date AS end_date, budget 
    FROM maintenance_schedule 
    ORDER BY starting_date DESC
");
if ($maintenance_result) {
    while ($row = $maintenance_result->fetch_assoc()) {
        $maintenance_data[] = [
            'display_id'   => (int)$row['sched_id'],
            'modal_id'     => (int)$row['sched_id'],
            'id_label'     => '#SCH-' . str_pad($row['sched_id'], 3, '0', STR_PAD_LEFT),
            'task'         => $row['task'],
            'location'     => $row['location'],
            'category'     => $row['category'] ?? 'General Maintenance',
            'status'       => $row['status'],
            'starting_date'=> $row['starting_date'],
            'end_date'     => $row['end_date'],
            'budget'       => (float)$row['budget'],
        ];
    }
}

// ── 2. Pull from reports (joined with request_resolutions + requests) ─────────
// rep_id is offset by 10000 so modal IDs never collide with sched_ids
$report_result = $conn->query("
    SELECT
        r.rep_id, r.starting_date, r.estimated_end_date AS end_date,
        r.priority_lvl, r.budget,
        res.status AS resolution_status,
        req.infrastructure, req.location
    FROM reports r
    LEFT JOIN request_resolutions res ON r.res_id  = res.res_id
    LEFT JOIN requests             req ON res.req_id = req.req_id
    WHERE r.starting_date IS NOT NULL
    ORDER BY r.starting_date DESC
");
if ($report_result) {
    while ($rRow = $report_result->fetch_assoc()) {
        // Map resolution_status → simple display status
        $resStatus = $rRow['resolution_status'] ?? '';
        if ($resStatus === 'Completed') {
            $dispStatus = 'Completed';
        } elseif (in_array($resStatus, ['In Progress', 'Pending Completion'])) {
            $dispStatus = 'In Progress';
        } elseif ($resStatus === 'Scheduled' || $resStatus === 'Pending') {
            $dispStatus = 'Scheduled';
        } else {
            $dispStatus = 'Scheduled';
        }

        $maintenance_data[] = [
            'display_id'   => (int)$rRow['rep_id'],
            'modal_id'     => 10000 + (int)$rRow['rep_id'],
            'id_label'     => '#RPT-' . str_pad($rRow['rep_id'], 3, '0', STR_PAD_LEFT),
            'task'         => $rRow['infrastructure'] ?? 'Infrastructure Report',
            'location'     => $rRow['location'] ?? '—',
            'category'     => 'Infrastructure Report',
            'status'       => $dispStatus,
            'starting_date'=> $rRow['starting_date'],
            'end_date'     => $rRow['end_date'],
            'budget'       => (float)$rRow['budget'],
        ];
    }
}

// ── 3. Sort combined by starting_date DESC, limit 10 ─────────────────────────
usort($maintenance_data, function($a, $b) {
    return strcmp($b['starting_date'] ?? '', $a['starting_date'] ?? '');
});
$maintenance_data = array_slice($maintenance_data, 0, 10);

// ── Tally counts directly from the combined table data ───────────────────────
$count_scheduled = 0;
$count_ongoing   = 0;
$count_delayed   = 0;
$count_completed = 0;
foreach ($maintenance_data as $_item) {
    switch ($_item['status']) {
        case 'Completed':   $count_completed++; break;
        case 'In Progress': $count_ongoing++;   break;
        case 'Delayed':     $count_delayed++;   break;
        default:            $count_scheduled++; break; // Scheduled / Pending
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
            grid-template-columns: repeat(4, 1fr);
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
            padding: 24px 28px;
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
            font-size: 28px;
            background: rgba(255,255,255,.25);
            padding: 12px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        [data-theme="dark"] .stat-icon {
            background: rgba(255,255,255,.15);
        }
        /* Per-status icon tint */
        .stat-card.stat-scheduled .stat-icon { background: rgba(21,101,192,0.22); }
        .stat-card.stat-ongoing   .stat-icon { background: rgba(245,158,11,0.22); }
        .stat-card.stat-delayed   .stat-icon { background: rgba(198,40,40,0.22);  }
        .stat-card.stat-completed .stat-icon { background: rgba(46,125,50,0.22);  }
        [data-theme="dark"] .stat-card.stat-scheduled .stat-icon { background: rgba(21,101,192,0.30); }
        [data-theme="dark"] .stat-card.stat-ongoing   .stat-icon { background: rgba(245,158,11,0.28); }
        [data-theme="dark"] .stat-card.stat-delayed   .stat-icon { background: rgba(198,40,40,0.30);  }
        [data-theme="dark"] .stat-card.stat-completed .stat-icon { background: rgba(46,125,50,0.30);  }
        /* Per-status number colour */
        .stat-card.stat-scheduled .number { color: #1565c0; }
        .stat-card.stat-ongoing   .number { color: #f57f17; }
        .stat-card.stat-delayed   .number { color: #c62828; }
        .stat-card.stat-completed .number { color: #2e7d32; }
        [data-theme="dark"] .stat-card.stat-scheduled .number { color: #90caf9; }
        [data-theme="dark"] .stat-card.stat-ongoing   .number { color: #fdd835; }
        [data-theme="dark"] .stat-card.stat-delayed   .number { color: #e57373; }
        [data-theme="dark"] .stat-card.stat-completed .number { color: #81c784; }
        .stat-card h3 {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.8;
            margin-bottom: 4px;
            margin-top: 0;
            color: var(--text-primary);
        }
        .stat-card .number {
            font-size: 38px;
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
            overflow: hidden;          /* clip rounded corners */
            width: 100%;
        }

        /* Inner scroll container — header stays pinned, rows scroll */
        .table-scroll {
            max-height: 520px;         /* ~10 rows visible before scroll kicks in */
            overflow-y: auto;
            overflow-x: hidden;
            border-radius: 16px;
            scrollbar-width: thin;
            scrollbar-color: #9cafde rgba(0,0,0,.07);
        }
        .table-scroll::-webkit-scrollbar        { width: 6px; }
        .table-scroll::-webkit-scrollbar-track  { background: rgba(0,0,0,.05); border-radius: 3px; }
        .table-scroll::-webkit-scrollbar-thumb  { background: #9cafde; border-radius: 3px; }
        .table-scroll::-webkit-scrollbar-thumb:hover { background: #6a8fd8; }

        table {
            /* Fixed layout: respects explicit widths, clips overflow */
            table-layout: fixed;
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        /* Column width distribution — all % so table always fits the viewport */
        col.col-id       { width: 7%;  }   /* #SCH-XX */
        col.col-date     { width: 11%; }   /* Mar 23, 2026 */
        col.col-type     { width: 21%; }   /* task — truncated */
        col.col-location { width: 24%; }   /* location — truncated */
        col.col-budget   { width: 10%; }   /* ₱XX,XXX.XX */
        col.col-status   { width: 19%; }   /* longest: "Isinasagawa" ~11 chars */
        col.col-action   { width: 8%;  }   /* View btn */

        /* MODERN TABLE HEADER */
        thead th {
            position: sticky;
            top: 0;
            background: linear-gradient(to bottom, #fdfdfd, #f2f4f8);
            z-index: 2;
            padding: 13px 8px;
            border-bottom: 1px solid #e3e6ee;
            color: #555;
            font-size: 12px;
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

        /* TABLE CELLS */
        td {
            padding: 11px 8px;
            border-bottom: 1px solid #eef0f5;
            font-size: 13px;
            color: #374151;
            text-align: center;
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
        td:nth-child(7)    /* Action  */
        {
            white-space: nowrap;
        }

        /* Status — allow wrapping so Filipino labels fit */
        td:nth-child(6) {
            white-space: normal;
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
            padding: 4px 9px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            white-space: normal;   /* wraps for long Filipino words */
            word-break: keep-all;  /* break between words, not mid-word */
            text-align: center;
            max-width: 100%;
            line-height: 1.3;
        }
        .status-pill::before {
            content: "●";
            font-size: 9px;
            margin-right: 5px;
            flex-shrink: 0;
        }
        /* ── Status pill colors — mirrors sched.php legend exactly ── */
        .status-pending  { background: #e3f2fd; color: #1565c0; }   /* Scheduled/Pending → blue */
        .status-fixed    { background: #e8f5e9; color: #2e7d32; }   /* Completed         → green */
        .status-progress { background: #fff8e1; color: #f57f17; }   /* In Progress        → amber */
        .status-delayed  { background: #ffebee; color: #c62828; }   /* Delayed            → red */

        [data-theme="dark"] .status-pending  { background: rgba(21,101,192,0.2);   color: #90caf9; }
        [data-theme="dark"] .status-fixed    { background: rgba(76,175,80,0.2);    color: #81c784; }
        [data-theme="dark"] .status-progress { background: rgba(245,158,11,0.18);  color: #fdd835; }
        [data-theme="dark"] .status-delayed  { background: rgba(244,67,54,0.2);    color: #e57373; }

        /* ── Search toolbar — sched.php list-view-toolbar (exact match) ── */
        .search-toolbar {
            display: flex;
            align-items: center;
            width: 100%;
            padding: 8px 10px;
            border-radius: 14px;
            border: 1px solid rgba(55, 98, 200, 0.13);
            background: linear-gradient(135deg, #eef2ff 0%, #f5f7ff 100%);
            box-sizing: border-box;
            margin-bottom: 10px;
        }
        [data-theme="dark"] .search-toolbar {
            background: linear-gradient(135deg, rgba(55,98,200,0.14) 0%, rgba(22,26,46,0.85) 100%);
            border-color: rgba(95, 140, 255, 0.18);
        }

        /* ── TABLE SEARCH BAR — sched.php list-view design (exact match) ── */
        .table-search-wrapper {
            position: relative;
            display: flex;
            align-items: center;
            flex: 1;
            min-width: 0;
            width: 100%;
            max-width: 100%;
            margin-bottom: 0;
        }
        .table-search-wrapper svg {
            position: absolute;
            left: 11px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            pointer-events: none;
            flex-shrink: 0;
        }
        [data-theme="dark"] .table-search-wrapper svg { color: #64748b; }
        #requestSearch {
            width: 100%;
            height: 36px;
            padding: 0 12px 0 34px;
            border-radius: 10px;
            border: 1.5px solid rgba(55, 98, 200, 0.18);
            background: rgba(255, 255, 255, 0.85);
            font-size: 13px;
            color: var(--text-primary);
            outline: none;
            transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
            box-sizing: border-box;
            box-shadow: 0 1px 3px rgba(55,98,200,0.06);
        }
        #requestSearch:focus {
            border-color: #3762c8;
            box-shadow: 0 0 0 3px rgba(55,98,200,0.13);
            background: #fff;
        }
        #requestSearch::placeholder { color: #94a3b8; font-size: 12.5px; }
        [data-theme="dark"] #requestSearch {
            background: rgba(255,255,255,0.07);
            border-color: rgba(95,140,255,0.22);
            color: var(--text-primary);
        }
        [data-theme="dark"] #requestSearch:focus {
            border-color: #5f8cff;
            box-shadow: 0 0 0 3px rgba(95,140,255,0.18);
            background: rgba(255,255,255,0.10);
        }
        [data-theme="dark"] #requestSearch::placeholder { color: #64748b; }
        .search-highlight { background: #fff176; color: #000; padding: 1px 3px; border-radius: 4px; font-weight: 700; }
        [data-theme="dark"] .search-highlight { background: #f9a825; color: #000; }

        /* ── STATUS LEGEND ── */
        .status-legend {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px;
            padding: 7px 10px;
            background: var(--bg-tertiary, #f7f9ff);
            border: 1px solid var(--border-color, rgba(55,98,200,0.10));
            border-radius: 12px;
            margin-bottom: 18px;
        }
        [data-theme="dark"] .status-legend {
            background: rgba(255,255,255,0.04);
            border-color: rgba(255,255,255,0.09);
        }
        .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px 4px 7px;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 600;
            color: var(--text-primary);
            background: var(--bg-secondary, #fff);
            border: 1px solid var(--border-color, rgba(0,0,0,0.07));
            white-space: nowrap;
            cursor: pointer;
            user-select: none;
            transition: box-shadow 0.15s, border-color 0.15s, background 0.15s, transform 0.12s, opacity 0.15s;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        [data-theme="dark"] .legend-item {
            background: rgba(255,255,255,0.06);
            border-color: rgba(255,255,255,0.10);
            box-shadow: none;
        }
        .legend-item:hover  { transform: translateY(-1px); box-shadow: 0 3px 10px rgba(0,0,0,0.10); }
        .legend-item:active { transform: scale(0.96); }

        .legend-dot {
            width: 9px; height: 9px;
            border-radius: 50%;
            flex-shrink: 0;
            display: inline-block;
        }
        .legend-upcoming  { background: #1565c0; }
        .legend-ongoing   { background: #f59e0b; }
        .legend-delayed   { background: #c62828; }
        .legend-completed { background: #2e7d32; }

        /* Pill border accent per status */
        .legend-item:has(.legend-upcoming)  { border-color: rgba(21,101,192,0.22); }
        .legend-item:has(.legend-ongoing)   { border-color: rgba(245,158,11,0.28); }
        .legend-item:has(.legend-delayed)   { border-color: rgba(198,40,40,0.22); }
        .legend-item:has(.legend-completed) { border-color: rgba(46,125,50,0.22); }

        [data-theme="dark"] .legend-item:has(.legend-upcoming)  { border-color: rgba(21,101,192,0.40); }
        [data-theme="dark"] .legend-item:has(.legend-ongoing)   { border-color: rgba(245,158,11,0.40); }
        [data-theme="dark"] .legend-item:has(.legend-delayed)   { border-color: rgba(198,40,40,0.40); }
        [data-theme="dark"] .legend-item:has(.legend-completed) { border-color: rgba(46,125,50,0.40); }

        /* Active (selected) state */
        .legend-item.legend-active { box-shadow: 0 2px 10px rgba(0,0,0,0.13); font-weight: 700; }
        .legend-item[data-filter="upcoming"].legend-active  { background: rgba(21,101,192,0.13);  border-color: #1565c0; color: #1565c0; }
        .legend-item[data-filter="ongoing"].legend-active   { background: rgba(245,158,11,0.13);  border-color: #f59e0b; color: #b45309; }
        .legend-item[data-filter="delayed"].legend-active   { background: rgba(198,40,40,0.13);   border-color: #c62828; color: #c62828; }
        .legend-item[data-filter="completed"].legend-active { background: rgba(46,125,50,0.13);   border-color: #2e7d32; color: #2e7d32; }
        .legend-item.legend-dimmed { opacity: 0.42; }

        [data-theme="dark"] .legend-item[data-filter="upcoming"].legend-active  { background: rgba(21,101,192,0.25);  border-color: #90caf9; color: #90caf9; }
        [data-theme="dark"] .legend-item[data-filter="ongoing"].legend-active   { background: rgba(245,158,11,0.22);  border-color: #fdd835; color: #fdd835; }
        [data-theme="dark"] .legend-item[data-filter="delayed"].legend-active   { background: rgba(198,40,40,0.25);   border-color: #ef9a9a; color: #ef9a9a; }
        [data-theme="dark"] .legend-item[data-filter="completed"].legend-active { background: rgba(46,125,50,0.25);   border-color: #a5d6a7; color: #a5d6a7; }

        /* Clear-filter badge */
        #legendClearBadge {
            display: none;
            align-items: center;
            gap: 5px;
            padding: 3px 10px 3px 8px;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 700;
            background: rgba(55,98,200,0.10);
            border: 1.5px solid rgba(55,98,200,0.22);
            color: #3762c8;
            cursor: pointer;
            white-space: nowrap;
            transition: background 0.15s;
        }
        #legendClearBadge.visible { display: inline-flex; }
        #legendClearBadge:hover   { background: rgba(55,98,200,0.18); }
        [data-theme="dark"] #legendClearBadge {
            background: rgba(95,140,255,0.14);
            border-color: rgba(95,140,255,0.30);
            color: #8ab4f8;
        }

        @media (max-width: 768px) {
            .status-legend { gap: 5px; padding: 6px 8px; border-radius: 10px; }
            .legend-item   { font-size: 11px; padding: 3px 9px 3px 6px; }
        }

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
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
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
                max-height: 72vh;        /* scroll instead of expanding the page */
                overflow-y: auto;
                overflow-x: hidden;
                scrollbar-width: thin;
                scrollbar-color: #9cafde rgba(0,0,0,.07);
            }
            .mobile-maintenance-list::-webkit-scrollbar        { width: 4px; }
            .mobile-maintenance-list::-webkit-scrollbar-track  { background: rgba(0,0,0,.05); border-radius: 3px; }
            .mobile-maintenance-list::-webkit-scrollbar-thumb  { background: #9cafde; border-radius: 3px; }

            .table-search-wrapper {
                margin-top: 0;
                margin-bottom: 0;
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
                padding: 22px 14px;
                border-radius: 12px;
            }
            /* Search toolbar + legend — consistent horizontal spacing inside card */
            .search-toolbar {
                width: 100%;
                margin-bottom: 10px;
                box-sizing: border-box;
            }
            .status-legend {
                margin-bottom: 6px;
            }
        }

        @media (max-width: 500px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .stat-card { padding: 16px 10px; }
            .stat-icon { font-size: 22px; padding: 8px; }
            .stat-card .number { font-size: 26px; }
            .card-header h2 { font-size: 1.0rem; }
            .report-card { padding: 12px; }
            #requestSearch { font-size: 12.5px; padding: 0 12px 0 34px; height: 36px; }
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

        /* ═══════════════════════════════════════════════════════
           SCHEDULE DETAIL MODAL — bilingual, matches requests.php style
        ═══════════════════════════════════════════════════════ */
        .sched-modal-backdrop {
            position: fixed; inset: 0;
            background: rgba(15,23,42,.45);
            display: none; align-items: center; justify-content: center;
            z-index: 8000;
            backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
        }
        .sched-modal-backdrop.active { display: flex; }

        .sched-detail-modal {
            background: var(--bg-primary, #fff);
            border-radius: 20px;
            box-shadow: 0 12px 50px var(--shadow-color, rgba(0,0,0,.2));
            width: 92%; max-width: 560px; max-height: 88vh;
            display: flex; flex-direction: column;
            animation: schedDetailIn .3s cubic-bezier(.34,1.56,.64,1);
            border: 1px solid var(--border-color, rgba(0,0,0,.08));
            overflow: hidden;
        }
        @keyframes schedDetailIn {
            from { opacity:0; transform: scale(.9) translateY(-20px); }
            to   { opacity:1; transform: scale(1) translateY(0); }
        }

        /* Coloured top band — matches sched.php legend */
        .sched-modal-band { height: 8px; border-radius: 20px 20px 0 0; width: 100%; flex-shrink: 0; }
        .sched-modal-band.sched-completed  { background: linear-gradient(90deg,#2e7d32,#66bb6a); }
        .sched-modal-band.sched-inprogress { background: linear-gradient(90deg,#f57f17,#ffd54f); }
        .sched-modal-band.sched-delayed    { background: linear-gradient(90deg,#c62828,#ef5350); }
        .sched-modal-band.sched-pending    { background: linear-gradient(90deg,#1565c0,#42a5f5); }

        .sched-modal-header {
            display: flex; align-items: flex-start; justify-content: space-between;
            padding: 18px 24px 14px; gap: 12px;
            border-bottom: 1px solid var(--border-color, rgba(0,0,0,.08));
            background: var(--bg-tertiary, rgba(255,255,255,.9)); flex-shrink: 0;
        }
        .sched-modal-req-id {
            font-size: 11px; font-weight: 700;
            color: var(--text-secondary, #555); text-transform: uppercase;
            letter-spacing: .09em; margin-bottom: 3px;
        }
        .sched-modal-title { font-size: 19px; font-weight: 700; color: var(--text-primary, #1a1a2e); line-height: 1.25; }
        .sched-modal-close {
            background: none; border: none; font-size: 26px;
            color: var(--text-secondary, #555); cursor: pointer;
            width: 36px; height: 36px; display: flex; align-items: center;
            justify-content: center; border-radius: 8px; transition: all .2s; flex-shrink: 0; margin-top: -2px;
        }
        .sched-modal-close:hover { background: rgba(55,98,200,.1); color: #3762c8; }

        .sched-modal-body {
            padding: 0 24px 20px; overflow-y: auto; flex: 1;
            scrollbar-width: thin; scrollbar-color: #9cafde rgba(0,0,0,.07);
        }
        .sched-modal-body::-webkit-scrollbar { width: 5px; }
        .sched-modal-body::-webkit-scrollbar-track { background: rgba(0,0,0,.05); border-radius: 3px; }
        .sched-modal-body::-webkit-scrollbar-thumb { background: #9cafde; border-radius: 3px; }

        /* Status pill */
        .sched-status-row { padding-top: 16px; margin-bottom: 14px; }
        .sched-status-pill {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 700;
        }
        .sched-status-pill.sched-completed  { background: #e8f5e9; color: #2e7d32; }   /* green  */
        .sched-status-pill.sched-inprogress { background: #fff8e1; color: #f57f17; }   /* amber  */
        .sched-status-pill.sched-delayed    { background: #ffebee; color: #c62828; }   /* red    */
        .sched-status-pill.sched-pending    { background: #e3f2fd; color: #1565c0; }   /* blue   */
        [data-theme="dark"] .sched-status-pill.sched-completed  { background: rgba(76,175,80,0.2);   color: #81c784; }
        [data-theme="dark"] .sched-status-pill.sched-inprogress { background: rgba(245,158,11,0.18); color: #fdd835; }
        [data-theme="dark"] .sched-status-pill.sched-delayed    { background: rgba(244,67,54,0.2);   color: #e57373; }
        [data-theme="dark"] .sched-status-pill.sched-pending    { background: rgba(21,101,192,0.2);  color: #90caf9; }

        /* Fields */
        .sched-field        { margin-bottom: 14px; }
        .sched-field-label  { font-size: 11px; font-weight: 700; color: #3762c8; text-transform: uppercase; letter-spacing: .07em; margin-bottom: 4px; }
        .sched-field-value  { font-size: 14px; color: var(--text-primary, #1a1a2e); line-height: 1.55; }
        .sched-divider      { height: 1px; background: var(--border-color, rgba(0,0,0,.08)); margin: 14px 0; }
        .sched-grid-2       { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 18px; }

        @media (max-width: 768px) {
            .sched-detail-modal { width: 95%; max-height: 90vh; }
            .sched-modal-header, .sched-modal-body { padding-left: 18px; padding-right: 18px; }
            .sched-grid-2 { grid-template-columns: 1fr; gap: 10px; }
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
    <div class="stat-card stat-scheduled" onclick="filterByLegend('upcoming')" data-i18n-title="reports_stat_title_scheduled" title="Filter: Scheduled">
        <div class="stat-icon">📅</div>
        <div>
            <h3 data-i18n="reports_stat_scheduled">Scheduled</h3>
            <div class="number"><?= $count_scheduled ?></div>
        </div>
    </div>
    <div class="stat-card stat-ongoing" onclick="filterByLegend('ongoing')" data-i18n-title="reports_stat_title_ongoing" title="Filter: On-Going">
        <div class="stat-icon">🔄</div>
        <div>
            <h3 data-i18n="reports_stat_ongoing">On-Going</h3>
            <div class="number"><?= $count_ongoing ?></div>
        </div>
    </div>
    <div class="stat-card stat-delayed" onclick="filterByLegend('delayed')" data-i18n-title="reports_stat_title_delayed" title="Filter: Delayed">
        <div class="stat-icon">⚠️</div>
        <div>
            <h3 data-i18n="reports_stat_delayed">Delayed</h3>
            <div class="number"><?= $count_delayed ?></div>
        </div>
    </div>
    <div class="stat-card stat-completed" onclick="filterByLegend('completed')" data-i18n-title="reports_stat_title_completed" title="Filter: Completed">
        <div class="stat-icon">✅</div>
        <div>
            <h3 data-i18n="reports_stat_completed">Completed</h3>
            <div class="number"><?= $count_completed ?></div>
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

    <div class="search-toolbar">
    <div class="table-search-wrapper">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input
            id="requestSearch"
            type="text"
            data-i18n-placeholder="reports_search_placeholder"
            placeholder="Search by Date, Type, Location, Budget, or Status..."
        >
    </div>
    </div>

    <!-- STATUS LEGEND (clickable filter) -->
    <div class="status-legend">
        <span class="legend-item" data-filter="upcoming" data-i18n-title="reports_legend_filter_title_scheduled" title="Click to filter: Scheduled">
            <span class="legend-dot legend-upcoming"></span><span data-i18n="reports_legend_scheduled">Scheduled</span>
        </span>
        <span class="legend-item" data-filter="ongoing" data-i18n-title="reports_legend_filter_title_ongoing" title="Click to filter: In Progress">
            <span class="legend-dot legend-ongoing"></span><span data-i18n="reports_legend_ongoing">In Progress</span>
        </span>
        <span class="legend-item" data-filter="delayed" data-i18n-title="reports_legend_filter_title_delayed" title="Click to filter: Delayed">
            <span class="legend-dot legend-delayed"></span><span data-i18n="reports_legend_delayed">Delayed</span>
        </span>
        <span class="legend-item" data-filter="completed" data-i18n-title="reports_legend_filter_title_completed" title="Click to filter: Completed">
            <span class="legend-dot legend-completed"></span><span data-i18n="reports_legend_completed">Completed</span>
        </span>
        <span id="legendClearBadge" data-i18n-title="reports_legend_clear" title="Click to clear filter">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            <span id="legendClearLabel">Scheduled</span>
        </span>
    </div>

    <!-- DESKTOP TABLE -->
    <div class="table-wrapper">
      <div class="table-scroll">
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
                        $status_key = 'reports_status_scheduled';
                        $status_filter_key = 'upcoming';
                        if ($item['status'] === 'Completed') {
                            $status_class = 'status-fixed';
                            $status_key = 'reports_status_completed';
                            $status_filter_key = 'completed';
                        } elseif ($item['status'] === 'In Progress') {
                            $status_class = 'status-progress';
                            $status_key = 'reports_status_in_progress';
                            $status_filter_key = 'ongoing';
                        } elseif ($item['status'] === 'Delayed') {
                            $status_class = 'status-delayed';
                            $status_key = 'reports_status_delayed';
                            $status_filter_key = 'delayed';
                        }
                        $date = !empty($item['starting_date']) ? date('M d, Y', strtotime($item['starting_date'])) : '—';
                        $task_escaped     = htmlspecialchars($item['task']);
                        $location_escaped = htmlspecialchars($item['location']);
                ?>
                <tr data-status="<?php echo $status_filter_key; ?>">
                    <td class="searchable"><?php echo htmlspecialchars($item['id_label']); ?></td>
                    <td class="searchable"><?php echo $date; ?></td>
                    <td class="searchable" title="<?php echo $task_escaped; ?>"><?php echo $task_escaped; ?></td>
                    <td class="searchable" title="<?php echo $location_escaped; ?>"><?php echo $location_escaped; ?></td>
                    <td class="searchable">₱<?php echo number_format($item['budget'], 2); ?></td>
                    <td class="searchable"><span class="status-pill <?php echo $status_class; ?>" data-i18n="<?php echo $status_key; ?>"><?php echo htmlspecialchars($item['status']); ?></span></td>
                    <td><a href="#" class="link" onclick="openSchedModal(<?= (int)$item['modal_id'] ?>);return false;" data-i18n="reports_view_button">View</a></td>
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
      </div><!-- /.table-scroll -->
    </div>

    <!-- MOBILE CARDS -->
    <div class="mobile-maintenance-list">
        <?php if (!empty($maintenance_data)): ?>
            <?php foreach ($maintenance_data as $item): 
                $status_class = 'status-pending';
                $status_key = 'reports_status_scheduled';
                $status_filter_key = 'upcoming';
                if ($item['status'] === 'Completed') {
                    $status_class = 'status-fixed';
                    $status_key = 'reports_status_completed';
                    $status_filter_key = 'completed';
                } elseif ($item['status'] === 'In Progress') {
                    $status_class = 'status-progress';
                    $status_key = 'reports_status_in_progress';
                    $status_filter_key = 'ongoing';
                } elseif ($item['status'] === 'Delayed') {
                    $status_class = 'status-delayed';
                    $status_key = 'reports_status_delayed';
                    $status_filter_key = 'delayed';
                }
            ?>
                <div class="report-card" data-status="<?= $status_filter_key ?>"><?php // data-status for legend filter ?>
                    <div class="report-row">
                        <span class="label" data-i18n="reports_mobile_schedule_id">Schedule ID:</span>
                        <span class="value searchable"><?= htmlspecialchars($item['id_label']) ?></span>
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
                        <span class="value searchable"><?= !empty($item['starting_date']) ? date('M d, Y', strtotime($item['starting_date'])) : '—' ?></span>
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
                        <a href="#" class="evidence-btn" onclick="openSchedModal(<?= (int)$item['modal_id'] ?>);return false;" data-i18n="reports_view_button">View</a>
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
    const searchInput    = document.getElementById("requestSearch");
    const table          = document.querySelector("table");
    const mobileList     = document.querySelector(".mobile-maintenance-list");
    if (!table || !searchInput) return;

    const tbody          = table.querySelector("tbody");
    const rows           = Array.from(tbody.querySelectorAll("tr")).filter(r => r.id !== "noRequestResult");
    const noResultRow    = document.getElementById("noRequestResult");
    const cards          = Array.from(document.querySelectorAll(".mobile-maintenance-list .report-card")).filter(c => c.id !== "noMobileResult");
    const noMobileResult = document.getElementById("noMobileResult");

    // ── Legend filter state ───────────────────────────────────────────────────
    let activeLegendFilter = null;

    const LEGEND_LABELS = {
        upcoming:  'Scheduled',
        ongoing:   'In Progress',
        delayed:   'Delayed',
        completed: 'Completed',
    };

    // Prefer the already-translated text in the legend pill if available
    function getLegendLabel(filter) {
        const pill = document.querySelector(`.legend-item[data-filter="${filter}"] [data-i18n]`);
        return pill ? pill.textContent.trim() : (LEGEND_LABELS[filter] || filter);
    }

    const clearBadge  = document.getElementById('legendClearBadge');
    const clearLabel  = document.getElementById('legendClearLabel');

    function applyLegendFilter(filter) {
        activeLegendFilter = filter;

        // Update pill states
        document.querySelectorAll('.legend-item[data-filter]').forEach(pill => {
            const f = pill.getAttribute('data-filter');
            pill.classList.remove('legend-active', 'legend-dimmed');
            if (!filter) return;
            if (f === filter) pill.classList.add('legend-active');
            else              pill.classList.add('legend-dimmed');
        });

        // Update clear badge
        if (filter) {
            if (clearLabel) clearLabel.textContent = getLegendLabel(filter);
            clearBadge && clearBadge.classList.add('visible');
        } else {
            clearBadge && clearBadge.classList.remove('visible');
        }

        // Re-run combined filter
        runFilter();
    }

    // Wire legend pill clicks
    document.querySelectorAll('.legend-item[data-filter]').forEach(pill => {
        pill.addEventListener('click', function () {
            const f = this.getAttribute('data-filter');
            applyLegendFilter(activeLegendFilter === f ? null : f);
        });
    });
    clearBadge && clearBadge.addEventListener('click', () => applyLegendFilter(null));

    // ── Shared filter runner (search + legend combined) ───────────────────────
    function escapeRegExp(t) { return t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
    function storeOriginal(el) { if (!('original' in el.dataset)) el.dataset.original = el.innerHTML; }
    function resetEl(el) { if ('original' in el.dataset) el.innerHTML = el.dataset.original; }
    function highlightEl(el, kw) {
        if (!kw) return;
        const regex = new RegExp(`(${escapeRegExp(kw)})`, 'gi');
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

    function runFilter() {
        const q  = searchInput.value.trim();
        const ql = q.toLowerCase();

        // Reset highlights
        document.querySelectorAll('table .searchable[data-original], .mobile-maintenance-list .searchable[data-original]')
            .forEach(el => resetEl(el));

        let dMatches = [], mMatches = [];

        rows.forEach(row => {
            const statusKey = row.getAttribute('data-status') || '';
            const legendOk  = !activeLegendFilter || statusKey === activeLegendFilter;
            const els       = row.querySelectorAll('.searchable');
            els.forEach(el => storeOriginal(el));
            const searchOk  = !q || [...els].some(el => el.textContent.toLowerCase().includes(ql));
            const show      = legendOk && searchOk;
            row.style.display = show ? "" : "none";
            if (show) {
                if (q) els.forEach(el => highlightEl(el, q));
                dMatches.push(row);
            }
        });
        if (q) dMatches.forEach(row => tbody.insertBefore(row, tbody.firstChild));
        if (noResultRow) noResultRow.style.display = dMatches.length === 0 ? "" : "none";

        cards.forEach(card => {
            const statusKey = card.getAttribute('data-status') || '';
            const legendOk  = !activeLegendFilter || statusKey === activeLegendFilter;
            const els       = card.querySelectorAll('.searchable');
            els.forEach(el => storeOriginal(el));
            const searchOk  = !q || [...els].some(el => el.textContent.toLowerCase().includes(ql));
            const show      = legendOk && searchOk;
            card.style.display = show ? "" : "none";
            if (show) {
                if (q) els.forEach(el => highlightEl(el, q));
                mMatches.push(card);
            }
        });
        if (q) mMatches.forEach(card => mobileList.insertBefore(card, mobileList.firstChild));
        if (noMobileResult) noMobileResult.style.display = mMatches.length === 0 ? "" : "none";
    }

    searchInput.addEventListener("input", runFilter);

    // Expose so stat cards (onclick) can trigger legend filter
    window.filterByLegend = function(f) {
        applyLegendFilter(activeLegendFilter === f ? null : f);
        // Scroll table into view smoothly
        const card = document.querySelector('.content-card');
        if (card) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
});
</script>

<!-- ═══════════════ SCHEDULE DETAIL MODAL ═══════════════ -->
<div id="schedModalBackdrop" class="sched-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="schedModalTitle">
    <div id="schedDetailModal" class="sched-detail-modal">
        <div class="sched-modal-band" id="schedModalBand"></div>
        <div class="sched-modal-header">
            <div>
                <div class="sched-modal-req-id" id="schedModalId"></div>
                <div class="sched-modal-title"  id="schedModalTitle"></div>
            </div>
            <button class="sched-modal-close" id="schedModalClose" aria-label="Close">×</button>
        </div>
        <div class="sched-modal-body">
            <div class="sched-status-row">
                <span class="sched-status-pill" id="schedModalStatus"></span>
            </div>
            <div class="sched-field">
                <div class="sched-field-label" id="lbl-location">📍 Location</div>
                <div class="sched-field-value" id="schedModalLocation"></div>
            </div>
            <div class="sched-field">
                <div class="sched-field-label" id="lbl-category">🏷️ Category</div>
                <div class="sched-field-value" id="schedModalCategory"></div>
            </div>
            <div class="sched-divider"></div>
            <div class="sched-grid-2">
                <div class="sched-field">
                    <div class="sched-field-label" id="lbl-start">📅 Start Date</div>
                    <div class="sched-field-value" id="schedModalStart"></div>
                </div>
                <div class="sched-field">
                    <div class="sched-field-label" id="lbl-end">🏁 Est. Completion</div>
                    <div class="sched-field-value" id="schedModalEnd"></div>
                </div>
                <div class="sched-field">
                    <div class="sched-field-label" id="lbl-budget">💰 Budget</div>
                    <div class="sched-field-value" id="schedModalBudget"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════ SCHEDULE MODAL SCRIPT ═══════════════ -->
<script>
(function () {
    /* ── All schedule data from PHP ── */
    var ALL_SCHEDULES = <?php
        $modal_data = [];
        foreach ($maintenance_data as $m) {
            $modal_data[] = [
                'id'       => (int)$m['modal_id'],
                'task'     => $m['task'],
                'location' => $m['location'],
                'category' => $m['category'],
                'status'   => $m['status'],
                'start'    => !empty($m['starting_date']) ? date('M d, Y', strtotime($m['starting_date'])) : '—',
                'end'      => !empty($m['end_date'])
                                ? date('M d, Y', strtotime($m['end_date']))
                                : '—',
                'budget'   => '₱' . number_format((float)$m['budget'], 2),
            ];
        }
        echo json_encode($modal_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    ?>;

    /* ── Language detection (matches site toggle) ── */
    function getLang() {
        return (document.documentElement.lang || localStorage.getItem('lang') || 'en').substring(0, 2).toLowerCase();
    }

    /* ── Bilingual label maps ── */
    var LABELS = {
        en: {
            location : '📍 Location',
            category : '🏷️ Category',
            start    : '📅 Start Date',
            end      : '🏁 Est. Completion',
            budget   : '💰 Budget',
            noDate   : 'Not set',
        },
        tl: {
            location : '📍 Lokasyon',
            category : '🏷️ Kategorya',
            start    : '📅 Petsa ng Pagsisimula',
            end      : '🏁 Tinatayang Tapusin',
            budget   : '💰 Badyet',
            noDate   : 'Hindi pa natakda',
        }
    };

    /* ── Status → CSS class + bilingual label ── */
    var STATUS_MAP = {
        'Completed'  : { cls: 'sched-completed',  en: '✅ Completed',   tl: '✅ Natapos'      },
        'In Progress': { cls: 'sched-inprogress', en: '🔄 In Progress', tl: '🔄 Isinasagawa'  },
        'Delayed'    : { cls: 'sched-delayed',    en: '⚠️ Delayed',     tl: '⚠️ Naantala'     },
        'Pending'    : { cls: 'sched-pending',    en: '⏳ Pending',     tl: '⏳ Nakabinbin'   },
        'Scheduled'  : { cls: 'sched-pending',    en: '📅 Scheduled',   tl: '📅 Nakaplanong'  },
    };

    /* ── DOM refs ── */
    var backdrop = document.getElementById('schedModalBackdrop');
    var band     = document.getElementById('schedModalBand');
    var idEl     = document.getElementById('schedModalId');
    var titleEl  = document.getElementById('schedModalTitle');
    var statusEl = document.getElementById('schedModalStatus');
    var locEl    = document.getElementById('schedModalLocation');
    var catEl    = document.getElementById('schedModalCategory');
    var startEl  = document.getElementById('schedModalStart');
    var endEl    = document.getElementById('schedModalEnd');
    var budgetEl = document.getElementById('schedModalBudget');
    var closeBtn = document.getElementById('schedModalClose');

    /* ── Label elements ── */
    var lblLocation = document.getElementById('lbl-location');
    var lblCategory = document.getElementById('lbl-category');
    var lblStart    = document.getElementById('lbl-start');
    var lblEnd      = document.getElementById('lbl-end');
    var lblBudget   = document.getElementById('lbl-budget');

    /* ── Open ── */
    window.openSchedModal = function (schedId) {
        var rec = null;
        for (var i = 0; i < ALL_SCHEDULES.length; i++) {
            if (ALL_SCHEDULES[i].id === schedId) { rec = ALL_SCHEDULES[i]; break; }
        }
        if (!rec) return;

        var lang   = getLang();
        var lbl    = LABELS[lang] || LABELS.en;
        var smap   = STATUS_MAP[rec.status] || { cls: 'sched-pending', en: rec.status, tl: rec.status };

        /* Labels */
        lblLocation.textContent = lbl.location;
        lblCategory.textContent = lbl.category;
        lblStart.textContent    = lbl.start;
        lblEnd.textContent      = lbl.end;
        lblBudget.textContent   = lbl.budget;

        /* Band colour */
        band.className = 'sched-modal-band ' + smap.cls;

        /* Fields */
        idEl.textContent     = '#SCH-' + String(rec.id).padStart(3, '0');
        titleEl.textContent  = rec.task;
        locEl.textContent    = rec.location  || '—';
        catEl.textContent    = rec.category  || '—';
        startEl.textContent  = rec.start     || lbl.noDate;
        endEl.textContent    = rec.end       || lbl.noDate;
        budgetEl.textContent = rec.budget    || '—';

        /* Status pill */
        statusEl.textContent = (lang === 'tl') ? smap.tl : smap.en;
        statusEl.className   = 'sched-status-pill ' + smap.cls;

        /* Show */
        backdrop.classList.add('active');
        document.body.style.overflow = 'hidden';
        closeBtn.focus();
    };

    /* ── Close ── */
    function closeSchedModal() {
        backdrop.classList.remove('active');
        document.body.style.overflow = '';
    }

    closeBtn && closeBtn.addEventListener('click', closeSchedModal);
    backdrop && backdrop.addEventListener('click', function (e) {
        if (e.target === backdrop) closeSchedModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && backdrop.classList.contains('active')) closeSchedModal();
    });
})();
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
<!-- ═══════════════════════════════════════════════════════════════════
     INLINE FALLBACK TRANSLATIONS — citizenreports.php
     Same pattern as citizenrepform.php: hardcoded en/tl for every
     data-i18n key on this page. Runs on DOMContentLoaded AND on the
     i18nReady event so it catches both the initial page load and any
     toggle fired after citizen_global.php finishes its fetch.
     This ensures labels translate even if the preloaded translations
     object is stale or the fetch hasn't resolved yet.
════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    var PAGE_TRANSLATIONS = {
        en: {
            site_title:                          'InfraGovServices',
            nav_login:                           'Log in',
            nav_home:                            'Home',
            nav_reports:                         'Reports',
            nav_requests:                        'Requests',
            nav_about:                           'About',
            translate_btn_title:                 'Translate to Filipino',
            lang_label:                          'EN',
            /* ── stat cards ── */
            reports_stat_scheduled:              'Scheduled',
            reports_stat_ongoing:                'On-Going',
            reports_stat_delayed:                'Delayed',
            reports_stat_completed:              'Completed',
            reports_stat_title_scheduled:        'Filter: Scheduled',
            reports_stat_title_ongoing:          'Filter: On-Going',
            reports_stat_title_delayed:          'Filter: Delayed',
            reports_stat_title_completed:        'Filter: Completed',
            /* ── main card ── */
            reports_page_title:                  'Recent Maintenance Reports',
            reports_search_placeholder:          'Search by Date, Type, Location, Budget, or Status...',
            /* ── legend ── */
            reports_legend_scheduled:            'Scheduled',
            reports_legend_ongoing:              'In Progress',
            reports_legend_delayed:              'Delayed',
            reports_legend_completed:            'Completed',
            reports_legend_filter_title_scheduled:  'Click to filter: Scheduled',
            reports_legend_filter_title_ongoing:    'Click to filter: In Progress',
            reports_legend_filter_title_delayed:    'Click to filter: Delayed',
            reports_legend_filter_title_completed:  'Click to filter: Completed',
            reports_legend_clear:                'Click to clear filter',
            /* ── table headers ── */
            reports_table_sched:                 'Sched #',
            reports_table_date:                  'Date',
            reports_table_type:                  'Type',
            reports_table_location:              'Location',
            reports_table_budget:                'Budget',
            reports_table_status:                'Status',
            reports_table_action:                'Action',
            /* ── status pills ── */
            reports_status_scheduled:            'Scheduled',
            reports_status_completed:            'Completed',
            reports_status_in_progress:          'In Progress',
            reports_status_delayed:              'Delayed',
            /* ── misc ── */
            reports_view_button:                 'View',
            reports_no_data:                     'No maintenance schedules available',
            reports_no_match:                    'No matching data',
            /* ── mobile card labels ── */
            reports_mobile_schedule_id:          'Schedule ID:',
            reports_mobile_category:             'Category:',
            reports_mobile_task:                 'Task:',
            reports_mobile_location:             'Location:',
            reports_mobile_start_date:           'Start Date:',
            reports_mobile_budget:               'Budget:',
            reports_mobile_status:               'Status:',
            /* ── footer ── */
            footer_desc:         'Community Infrastructure Maintenance Management System for Quezon City. Dedicated to providing efficient, transparent, and responsive infrastructure services for all residents.',
            footer_quick_links:  'Quick Links',
            footer_link_home:    'Home',
            footer_link_reports: 'Reports',
            footer_link_submit:  'Submit Request',
            footer_link_about:   'About Us',
            footer_resources:    'Resources',
            footer_link_guide:   'User Guide',
            footer_link_faqs:    'FAQs',
            footer_link_areas:   'Service Areas',
            footer_link_emergency: 'Emergency Contacts',
            footer_legal:        'Legal',
            footer_link_privacy: 'Privacy Policy',
            footer_link_terms:   'Terms of Service',
            footer_link_data:    'Data Protection',
            footer_link_access:  'Accessibility',
            footer_copyright:    '© 2026 LGU Quezon City · InfraGovServices · All Rights Reserved',
        },
        tl: {
            site_title:                          'InfraGovServices',
            nav_login:                           'Mag-login',
            nav_home:                            'Tahanan',
            nav_reports:                         'Mga Ulat',
            nav_requests:                        'Mga Kahilingan',
            nav_about:                           'Tungkol Sa',
            translate_btn_title:                 'I-translate sa Ingles',
            lang_label:                          'FIL',
            /* ── stat cards ── */
            reports_stat_scheduled:              'Nakaplanong',
            reports_stat_ongoing:                'Isinasagawa',
            reports_stat_delayed:                'Naantala',
            reports_stat_completed:              'Natapos',
            reports_stat_title_scheduled:        'I-filter: Nakaplanong',
            reports_stat_title_ongoing:          'I-filter: Isinasagawa',
            reports_stat_title_delayed:          'I-filter: Naantala',
            reports_stat_title_completed:        'I-filter: Natapos',
            /* ── main card ── */
            reports_page_title:                  'Kamakailang Ulat ng Pagpapanatili',
            reports_search_placeholder:          'Maghanap ayon sa Petsa, Uri, Lokasyon, Badyet, o Katayuan...',
            /* ── legend ── */
            reports_legend_scheduled:            'Nakaplanong',
            reports_legend_ongoing:              'Isinasagawa',
            reports_legend_delayed:              'Naantala',
            reports_legend_completed:            'Natapos',
            reports_legend_filter_title_scheduled:  'I-click para i-filter: Nakaplanong',
            reports_legend_filter_title_ongoing:    'I-click para i-filter: Isinasagawa',
            reports_legend_filter_title_delayed:    'I-click para i-filter: Naantala',
            reports_legend_filter_title_completed:  'I-click para i-filter: Natapos',
            reports_legend_clear:                'I-click para alisin ang filter',
            /* ── table headers ── */
            reports_table_sched:                 'Iskedyul #',
            reports_table_date:                  'Petsa',
            reports_table_type:                  'Uri',
            reports_table_location:              'Lokasyon',
            reports_table_budget:                'Badyet',
            reports_table_status:                'Katayuan',
            reports_table_action:                'Aksyon',
            /* ── status pills ── */
            reports_status_scheduled:            'Nakaplanong',
            reports_status_completed:            'Natapos',
            reports_status_in_progress:          'Isinasagawa',
            reports_status_delayed:              'Naantala',
            /* ── misc ── */
            reports_view_button:                 'Tingnan',
            reports_no_data:                     'Walang available na iskedyul ng pagpapanatili',
            reports_no_match:                    'Walang tumutugmang data',
            /* ── mobile card labels ── */
            reports_mobile_schedule_id:          'ID ng Iskedyul:',
            reports_mobile_category:             'Kategorya:',
            reports_mobile_task:                 'Gawain:',
            reports_mobile_location:             'Lokasyon:',
            reports_mobile_start_date:           'Petsa ng Pagsisimula:',
            reports_mobile_budget:               'Badyet:',
            reports_mobile_status:               'Katayuan:',
            /* ── footer ── */
            footer_desc:         'Sistema ng Pamamahala ng Pagpapanatili ng Imprastraktura ng Komunidad para sa Lungsod Quezon. Nakatuon sa pagbibigay ng mahusay, malinaw, at matuging mga serbisyong pang-imprastraktura para sa lahat ng residente.',
            footer_quick_links:  'Mabilis na mga Link',
            footer_link_home:    'Tahanan',
            footer_link_reports: 'Mga Ulat',
            footer_link_submit:  'Magsumite ng Kahilingan',
            footer_link_about:   'Tungkol Sa Amin',
            footer_resources:    'Mga Mapagkukunan',
            footer_link_guide:   'Gabay ng Gumagamit',
            footer_link_faqs:    'Mga Madalas na Tanong',
            footer_link_areas:   'Mga Lugar ng Serbisyo',
            footer_link_emergency: 'Mga Emergency na Kontak',
            footer_legal:        'Ligal',
            footer_link_privacy: 'Patakaran sa Privacy',
            footer_link_terms:   'Mga Tuntunin ng Serbisyo',
            footer_link_data:    'Proteksyon ng Data',
            footer_link_access:  'Aksesibilidad',
            footer_copyright:    '© 2026 LGU Lungsod Quezon · InfraGovServices · Lahat ng Karapatan ay Nakalaan',
        }
    };

    /* Same helper as citizenrepform.php — checks live preload first, falls back to inline table */
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

    /* Walk every data-i18n* element and apply the translation */
    function applyPageFallbacks() {
        var lang = localStorage.getItem('lang') || 'en';

        /* textContent keys */
        document.querySelectorAll('[data-i18n]').forEach(function (el) {
            var key = el.getAttribute('data-i18n');
            var val = getTranslation(key);
            if (val && val !== key) el.textContent = val;
        });

        /* placeholder keys */
        document.querySelectorAll('[data-i18n-placeholder]').forEach(function (el) {
            var key = el.getAttribute('data-i18n-placeholder');
            var val = getTranslation(key);
            if (val && val !== key) el.placeholder = val;
        });

        /* title attribute keys */
        document.querySelectorAll('[data-i18n-title]').forEach(function (el) {
            var key = el.getAttribute('data-i18n-title');
            var val = getTranslation(key);
            if (val && val !== key) el.title = val;
        });
    }

    /* Run on initial load */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyPageFallbacks);
    } else {
        applyPageFallbacks();
    }

    /* Also re-run whenever citizen_global.php fires its i18nReady event
       (covers the toggle-to-Filipino case after a fresh fetch) */
    document.addEventListener('i18nReady', applyPageFallbacks);
})();
</script>
<?php include 'citizen_global.php'; ?>
<script>window.CHATBOT_ENDPOINT = '<?= $BASE_URL ?>chatbot.php';</script>
<?php include 'chatbot-widget.php'; ?>

</body>
</html>