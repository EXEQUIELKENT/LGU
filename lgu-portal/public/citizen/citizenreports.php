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
        res.status AS resolution_status, res.req_id,
        req.infrastructure, req.location, req.issue, req.district,
        GROUP_CONCAT(ev.img_path ORDER BY ev.uploaded_at ASC SEPARATOR ',') AS evidence_images
    FROM reports r
    LEFT JOIN request_resolutions res ON r.res_id  = res.res_id
    LEFT JOIN requests             req ON res.req_id = req.req_id
    LEFT JOIN evidence_images      ev  ON res.req_id = ev.req_id
    WHERE r.starting_date IS NOT NULL
    GROUP BY r.rep_id
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

        $evImgs = [];
        if (!empty($rRow['evidence_images'])) {
            $evImgs = array_values(array_filter(explode(',', $rRow['evidence_images'])));
        }
        $maintenance_data[] = [
            'display_id'      => (int)$rRow['rep_id'],
            'modal_id'        => 10000 + (int)$rRow['rep_id'],
            'id_label'        => '#RPT-' . str_pad($rRow['rep_id'], 3, '0', STR_PAD_LEFT),
            'task'            => $rRow['infrastructure'] ?? 'Infrastructure Report',
            'location'        => $rRow['location'] ?? '—',
            'district'        => $rRow['district'] ?? '',
            'status'          => $dispStatus,
            'starting_date'   => $rRow['starting_date'],
            'end_date'        => $rRow['end_date'],
            'budget'          => (float)$rRow['budget'],
            'priority'        => $rRow['priority_lvl'] ?? '',
            'issue'           => $rRow['issue'] ?? '',
            'evidence_images' => $evImgs,
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
$count_completed = 0;
foreach ($maintenance_data as $_item) {
    switch ($_item['status']) {
        case 'Completed':   $count_completed++; break;
        case 'In Progress': $count_ongoing++;   break;
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
    <link rel="stylesheet" href="<?= $BASE_URL ?>assets/css/citizen_global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

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
            background: url("../assets/img/cityhall.jpeg") center/cover no-repeat fixed;
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
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 50px;
            justify-items: stretch;
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
        .stat-card.stat-completed .stat-icon { background: rgba(46,125,50,0.22);  }
        [data-theme="dark"] .stat-card.stat-scheduled .stat-icon { background: rgba(21,101,192,0.30); }
        [data-theme="dark"] .stat-card.stat-ongoing   .stat-icon { background: rgba(245,158,11,0.28); }
        [data-theme="dark"] .stat-card.stat-completed .stat-icon { background: rgba(46,125,50,0.30);  }
        /* Per-status number colour */
        .stat-card.stat-scheduled .number { color: #1565c0; }
        .stat-card.stat-ongoing   .number { color: #f57f17; }
        .stat-card.stat-completed .number { color: #2e7d32; }
        [data-theme="dark"] .stat-card.stat-scheduled .number { color: #90caf9; }
        [data-theme="dark"] .stat-card.stat-ongoing   .number { color: #fdd835; }
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
        col.col-status   { width: 29%; }   /* longest: "Isinasagawa" ~11 chars */
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
        td:nth-child(6)    /* Action  */
        {
            white-space: nowrap;
        }

        /* Status — allow wrapping so Filipino labels fit */
        td:nth-child(5) {
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

        [data-theme="dark"] .status-pending  { background: rgba(21,101,192,0.2);   color: #90caf9; }
        [data-theme="dark"] .status-fixed    { background: rgba(76,175,80,0.2);    color: #81c784; }
        [data-theme="dark"] .status-progress { background: rgba(245,158,11,0.18);  color: #fdd835; }

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
            border: 1.5px solid #94a3b8;
            background: #fff;
            font-size: 13px;
            color: var(--text-primary);
            outline: none;
            transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
            box-sizing: border-box;
            box-shadow: 0 1px 5px rgba(55,98,200,0.14);
        }
        #requestSearch:focus {
            border-color: #3762c8;
            box-shadow: 0 0 0 3px rgba(55,98,200,0.20);
            background: #fff;
        }
        #requestSearch::placeholder { color: #94a3b8; font-size: 12.5px; }

/* ═══════════════════════════════════════════════════════
   SORT DROPDOWN
═══════════════════════════════════════════════════════ */
.search-toolbar { display: flex; align-items: center; gap: 10px; }
.table-search-wrapper { flex: 1; min-width: 0; }
.sort-dropdown-wrap { position: relative; flex-shrink: 0; }
.sort-btn {
    display: inline-flex; align-items: center; gap: 6px;
    height: 36px; padding: 0 13px;
    background: linear-gradient(135deg, #3762c8, #2851b3);
    color: #fff; border: none; border-radius: 10px;
    font-size: 12.5px; font-weight: 700; cursor: pointer;
    transition: all .22s ease; box-shadow: 0 2px 8px rgba(55,98,200,.30);
    white-space: nowrap; font-family: inherit;
}
.sort-btn:hover { background: linear-gradient(135deg,#2851b3,#1f3e99); transform: translateY(-1px); box-shadow: 0 4px 14px rgba(55,98,200,.40); }
.sort-btn i { font-size: 12px; }
.sort-chevron { font-size: 10px !important; transition: transform .2s; }
.sort-dropdown-wrap.open .sort-chevron { transform: rotate(180deg); }
.sort-btn-label { display: inline; }
@media (max-width: 520px) { .sort-btn-label { display: none; } }
.sort-dropdown {
    display: none; position: absolute; top: calc(100% + 6px); right: 0;
    background: var(--bg-secondary,#fff); border: 1.5px solid rgba(55,98,200,.18);
    border-radius: 12px; box-shadow: 0 8px 28px rgba(0,0,0,.16);
    z-index: 9999; min-width: 190px; overflow: hidden; animation: sortDropIn .18s ease;
}
.sort-dropdown-wrap.open .sort-dropdown { display: block; }
@keyframes sortDropIn { from{opacity:0;transform:translateY(-6px) scale(.97)} to{opacity:1;transform:translateY(0) scale(1)} }
.sort-option {
    display: flex; align-items: center; gap: 9px; padding: 10px 16px;
    font-size: 13px; font-weight: 500; color: var(--text-secondary,#333);
    cursor: pointer; transition: background .15s,color .15s; border-left: 3px solid transparent;
}
.sort-option:hover { background: rgba(55,98,200,.07); color: #3762c8; }
.sort-option.active { background: rgba(55,98,200,.10); color: #3762c8; font-weight: 700; border-left-color: #3762c8; }
.sort-option i { width: 14px; text-align: center; font-size: 12px; }
.sort-dropdown-divider { height:1px; background: var(--border-color,rgba(0,0,0,.08)); margin: 3px 0; }
[data-theme="dark"] .sort-dropdown { background: rgba(30,30,40,.98); border-color: rgba(95,140,255,.22); box-shadow: 0 8px 28px rgba(0,0,0,.45); }
[data-theme="dark"] .sort-option { color: var(--text-secondary,#ccc); }
[data-theme="dark"] .sort-option:hover { background: rgba(95,140,255,.12); color: #8fb4ff; }
[data-theme="dark"] .sort-option.active { background: rgba(95,140,255,.18); color: #8fb4ff; border-left-color: #5f8cff; }
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

        /* ── LIST / MAP VIEW TOGGLE ── */
        .view-toggle-wrap {
            display: flex; flex-shrink: 0; gap: 4px;
            background: rgba(55,98,200,0.08); border-radius: 10px; padding: 3px;
        }
        [data-theme="dark"] .view-toggle-wrap { background: rgba(95,140,255,0.12); }
        .view-toggle-btn {
            display: inline-flex; align-items: center; gap: 6px;
            height: 30px; padding: 0 12px; border: none; border-radius: 8px;
            background: transparent; color: var(--text-secondary, #333);
            font-size: 12.5px; font-weight: 700; cursor: pointer; font-family: inherit;
            transition: all .18s ease; white-space: nowrap;
        }
        .view-toggle-btn i { font-size: 12px; }
        .view-toggle-btn:hover { color: #3762c8; }
        .view-toggle-btn.active {
            background: linear-gradient(135deg, #3762c8, #2851b3);
            color: #fff; box-shadow: 0 2px 8px rgba(55,98,200,.30);
        }
        [data-theme="dark"] .view-toggle-btn.active { color: #fff; }
        @media (max-width: 520px) { .view-toggle-btn span { display: none; } .view-toggle-btn { padding: 0 10px; } }

        /* ── MAP VIEW — unified toolbar + map + legend card (gis-combined-card language) ── */
        .reports-map-view {
            background: var(--bg-secondary, var(--card-bg));
            border-radius: 18px; border: 1px solid var(--border-color);
            box-shadow: 0 4px 18px var(--shadow-color); margin-bottom: 10px;
            overflow: hidden;
        }
        .reports-map-toolbar {
            display: flex; align-items: center; justify-content: space-between; gap: 10px;
            padding: 12px 18px; border-bottom: 1px solid var(--border-color);
        }
        .reports-map-title { font-size: 14px; font-weight: 700; color: var(--text-primary); }
        .reports-map-title i { color: #3762c8; margin-right: 4px; }
        .reports-map-layer-btn {
            display: inline-flex; align-items: center; gap: 6px;
            height: 32px; padding: 0 13px; border-radius: 9px; cursor: pointer;
            background: var(--bg-primary); border: 1.5px solid var(--border-color);
            color: var(--text-primary); font-size: 12px; font-weight: 700; font-family: inherit;
            transition: all .18s ease; box-shadow: 0 1px 4px var(--shadow-color);
        }
        .reports-map-layer-btn:hover { border-color: #3762c8; color: #3762c8; }
        .reports-map-layer-btn.active { background: #3762c8; border-color: #3762c8; color: #fff; }

        .reports-map-toolbar-tools { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-left: auto; }

        /* ── Search — same look as the admin GIS request map's search box ── */
        .gis-search-wrap { position: relative; display: flex; align-items: center; width: 200px; }
        .gis-search-wrap svg { position: absolute; left: 11px; color: #94a3b8; pointer-events: none; flex-shrink: 0; z-index: 1; }
        [data-theme="dark"] .gis-search-wrap svg { color: #64748b; }
        #reportsMapSearch {
            width: 100%; height: 32px; padding: 0 28px 0 30px; border-radius: 9px; box-sizing: border-box;
            border: 1.5px solid var(--border-color); background: var(--bg-primary); color: var(--text-primary);
            font-size: 12.5px; font-family: inherit; outline: none; transition: border-color .15s, box-shadow .15s;
        }
        #reportsMapSearch::placeholder { color: #94a3b8; }
        #reportsMapSearch:focus { border-color: #3762c8; box-shadow: 0 0 0 3px rgba(55,98,200,.18); }
        [data-theme="dark"] #reportsMapSearch::placeholder { color: #64748b; }
        .gis-search-clear {
            position: absolute; right: 7px; background: none; border: none; cursor: pointer;
            color: var(--text-secondary); font-size: 15px; line-height: 1; padding: 2px 4px;
            border-radius: 4px; display: none; align-items: center; justify-content: center; opacity: .5; transition: opacity .2s; z-index: 2;
        }
        .gis-search-clear:hover { opacity: 1; }
        .gis-search-clear.visible { display: flex; }
        .gis-search-results-badge {
            position: fixed; display: none; align-items: center; gap: 6px; padding: 5px 12px;
            background: #dce6f8; border: 1.5px solid #3762c8; border-radius: 8px;
            font-size: 12px; font-weight: 600; color: #3762c8; white-space: nowrap; z-index: 99999;
            pointer-events: none; box-shadow: 0 2px 8px rgba(55,98,200,.2); min-width: 120px;
        }
        .gis-search-results-badge.visible { display: flex; }
        .gis-search-results-badge.no-results { background: #fde8e8; border-color: #f44336; color: #f44336; }
        [data-theme="dark"] .gis-search-results-badge { background: #1e3160; border-color: #5f8cff; color: #a0b8ff; }
        [data-theme="dark"] .gis-search-results-badge.no-results { background: #3b1414; border-color: #f44336; color: #f87171; }

        /* ── Dropdown menus — same look as the admin GIS request map's filter dropdowns ── */
        .gis-dd-wrap { position: relative; flex-shrink: 0; }
        .gis-dd-btn {
            display: inline-flex; align-items: center; gap: 5px; height: 32px; padding: 0 11px;
            background: var(--bg-primary); border: 1.5px solid var(--border-color);
            color: var(--text-primary); border-radius: 9px; font-size: 12px; font-weight: 700; cursor: pointer;
            transition: all .18s ease; white-space: nowrap; font-family: inherit; box-shadow: 0 1px 4px var(--shadow-color);
        }
        .gis-dd-btn:hover { border-color: #3762c8; color: #3762c8; background: rgba(55,98,200,.06); }
        .gis-dd-btn.has-filter { background: #3762c8; border-color: #3762c8; color: #fff; }
        .gis-dd-chevron { font-size: 9px !important; transition: transform .18s; }
        .gis-dd-wrap.open .gis-dd-chevron { transform: rotate(180deg); }
        .gis-dd-menu {
            display: none; position: fixed;
            background: var(--bg-secondary, var(--card-bg)); border: 1.5px solid rgba(55,98,200,.18);
            border-radius: 12px; box-shadow: 0 8px 28px rgba(0,0,0,.16);
            z-index: 99999; min-width: 190px; overflow: hidden; animation: gisDropIn .18s ease;
        }
        .gis-dd-wrap.open .gis-dd-menu { display: block; }
        @keyframes gisDropIn { from{opacity:0;transform:translateY(-6px) scale(.97)} to{opacity:1;transform:none} }
        .gis-dd-item {
            display: flex; align-items: center; gap: 9px; padding: 9px 14px;
            font-size: 12.5px; font-weight: 500; color: var(--text-secondary);
            cursor: pointer; transition: background .13s,color .13s; border-left: 3px solid transparent; white-space: nowrap;
        }
        .gis-dd-item:hover { background: rgba(55,98,200,.07); color: #3762c8; }
        .gis-dd-item.active { background: rgba(55,98,200,.10); color: #3762c8; font-weight: 700; border-left-color: #3762c8; }
        .gis-dd-item i { width: 14px; text-align: center; font-size: 11px; }
        .gis-dd-divider { height: 1px; background: var(--border-color); margin: 3px 0; }
        [data-theme="dark"] .gis-dd-menu { background: rgba(30,30,40,.98); border-color: rgba(95,140,255,.22); box-shadow: 0 8px 28px rgba(0,0,0,.45); }
        [data-theme="dark"] .gis-dd-item { color: var(--text-secondary); }
        [data-theme="dark"] .gis-dd-item:hover { background: rgba(95,140,255,.12); color: #8fb4ff; }
        [data-theme="dark"] .gis-dd-item.active { background: rgba(95,140,255,.16); color: #8fb4ff; border-left-color: #5f8cff; }
        @media (max-width: 768px) {
            #reportsMapPeriodMenu { min-width: 210px; max-height: min(320px, 60vh); overflow-y: auto; }
        }

        /* Expand button — same look/position as the admin GIS request map's
           #gisExpandBtn: absolute top-right of the map itself, not the toolbar. */
        .reports-map-expand-btn {
            position: absolute; top: 12px; right: 12px; z-index: 1000;
            background: rgba(255,255,255,.92); color: #3762c8; border: 1.5px solid #c7d1f3;
            width: 34px; height: 34px; border-radius: 8px; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 2px 8px rgba(0,0,0,.22); transition: background .2s, transform .15s;
            backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
        }
        .reports-map-expand-btn:hover { background: #fff; transform: scale(1.1); }
        [data-theme="dark"] .reports-map-expand-btn { background: rgba(30,30,30,.88); color: #8ab4f8; border-color: rgba(74,143,216,.4); }
        [data-theme="dark"] .reports-map-expand-btn:hover { background: rgba(45,45,45,.95); }
        /* Once the map is already fullscreen, the in-map expand button would
           just duplicate the header's close button — hide it there. */
        .reports-map-fs-body .reports-map-expand-btn { display: none; }
        @media (max-width: 768px) {
            .reports-map-toolbar { flex-wrap: wrap; }
            .reports-map-toolbar-tools { width: 100%; margin-left: 0; }
            .gis-search-wrap { flex: 1 1 auto; width: auto; }
            .reports-map-title { display: none; }
            .gis-dd-btn { font-size: 11px; height: 30px; padding: 0 9px; }
            .gis-dd-menu { min-width: 160px; max-height: min(280px, 48vh); overflow-y: auto; }
            .reports-map-legend { padding: 8px 12px; gap: 8px; }
            .reports-map-legend-item { font-size: 10px; gap: 4px; }
            .reports-map-dot { width: 8px; height: 8px; }
            .reports-map-legend-hint { font-size: 10px; }
        }

        /* ── Fullscreen map overlay — covers the entire viewport edge-to-edge,
           same as the admin GIS request map's expanded fullscreen modal
           (no padding around it, no rounded corners, no max-width cap). ── */
        .reports-map-fs-backdrop {
            display: none; position: fixed; inset: 0; z-index: 5000;
            background: rgba(0,0,0,.6); align-items: center; justify-content: center; padding: 0;
        }
        .reports-map-fs-backdrop.active { display: flex; animation: reportsMapFsFade .2s ease; }
        @keyframes reportsMapFsFade { from { opacity: 0; } to { opacity: 1; } }
        .reports-map-fs-modal {
            width: 100%; height: 100%; max-width: 100%; background: var(--bg-secondary, var(--card-bg));
            border-radius: 0; overflow: hidden; display: flex; flex-direction: column;
            animation: reportsMapFsPop .3s cubic-bezier(.34,1.56,.64,1);
        }
        @keyframes reportsMapFsPop {
            from { opacity: 0; transform: scale(.92) translateY(-18px); }
            to   { opacity: 1; transform: scale(1) translateY(0); }
        }
        .reports-map-fs-head {
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
            padding: 12px 18px; border-bottom: 1px solid var(--border-color); flex-shrink: 0;
        }
        .reports-map-fs-head strong { font-size: 14px; color: var(--text-primary); flex-shrink: 0; }
        .reports-map-fs-head-tools { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-left: auto; }
        /* 100dvh excludes mobile browser chrome (address bar/toolbar) so the
           header and close button are never hidden off-screen. */
        @media (max-width: 768px) {
            .reports-map-fs-modal { height: 100dvh; }
            .reports-map-fs-head-tools { width: 100%; margin-left: 0; order: 3; }
            .reports-map-fs-head .gis-search-wrap { flex: 1 1 auto; width: auto; }
            .reports-map-fs-head .gis-dd-btn { font-size: 11px; height: 30px; padding: 0 9px; }
        }
        .reports-map-fs-close {
            width: 32px; height: 32px; border-radius: 9px; border: 1.5px solid var(--border-color);
            background: var(--bg-primary); color: var(--text-primary); cursor: pointer; font-size: 16px;
            display: flex; align-items: center; justify-content: center;
        }
        .reports-map-fs-close:hover { border-color: #dc2626; color: #dc2626; }
        .reports-map-fs-body { flex: 1; min-height: 0; position: relative; isolation: isolate; }
        .reports-map-fs-body .reports-map-inner,
        .reports-map-fs-body #reportsMap { width: 100%; height: 100%; }

        /* isolation:isolate traps Leaflet's internal z-index values (controls/
           popups default to 1000+) inside this card so they can never render
           above the fixed top nav, regardless of the nav's own z-index. */
        .reports-map-inner { position: relative; isolation: isolate; }
        #reportsMap { width: 100%; height: 480px; background: #dde3ee; }
        @media (max-width: 768px) { #reportsMap { height: 380px; } }
        .reports-map-empty {
            position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%);
            background: var(--bg-secondary, var(--card-bg)); border: 1px solid var(--border-color); border-radius: 16px;
            padding: 24px 32px; text-align: center; box-shadow: 0 8px 32px var(--shadow-color);
            z-index: 400; display: none; pointer-events: none;
        }
        .reports-map-empty.visible { display: block; }
        .reports-map-empty .no-results-icon { font-size: 32px; margin-bottom: 8px; }
        .reports-map-empty .no-results-text { font-size: 14px; font-weight: 600; color: var(--text-primary); margin-bottom: 4px; }
        .reports-map-empty .no-results-sub  { font-size: 12px; color: var(--text-secondary); }
        .reports-map-loading {
            position: absolute; inset: 0; z-index: 500; background: var(--bg-secondary, var(--card-bg));
            display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px;
            font-size: 13px; font-weight: 600; color: var(--text-secondary);
            transition: opacity .25s ease;
        }
        .reports-map-loading.hidden { opacity: 0; pointer-events: none; }
        .reports-map-spinner {
            width: 40px; height: 40px; border: 4px solid var(--border-color);
            border-top-color: #3762c8; border-radius: 50%; animation: reportsMapSpin .8s linear infinite;
        }
        @keyframes reportsMapSpin { to { transform: rotate(360deg); } }

        .reports-map-legend {
            display: flex; flex-wrap: wrap; align-items: center; gap: 14px;
            padding: 10px 18px; border-top: 1px solid var(--border-color);
        }
        .reports-map-legend-item { display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: var(--text-secondary); }
        .reports-map-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; box-shadow: 0 0 0 2px rgba(0,0,0,.06); }
        .rmp-dot-scheduled { background: #1565c0; }
        .rmp-dot-progress  { background: #f59e0b; }
        .rmp-dot-completed { background: #2e7d32; }
        .reports-map-legend-hint { margin-left: auto; font-size: 11.5px; color: var(--text-secondary); opacity: .75; }
        .reports-map-legend-hint i { margin-right: 3px; }
        @media (max-width: 560px) { .reports-map-legend-hint { margin-left: 0; width: 100%; } }

        /* ── Leaflet zoom control — redesigned (same as the admin GIS map) ── */
        #reportsMap .leaflet-bar,
        #reportsMap .leaflet-control-zoom {
            border: none !important;
            box-shadow: 0 4px 16px rgba(0,0,0,.18), 0 1px 4px rgba(0,0,0,.12) !important;
            border-radius: 14px !important; overflow: hidden !important;
            backdrop-filter: blur(8px) !important; -webkit-backdrop-filter: blur(8px) !important;
        }
        #reportsMap .leaflet-control-zoom-in,
        #reportsMap .leaflet-control-zoom-out {
            width: 36px !important; height: 36px !important; line-height: 36px !important;
            font-size: 18px !important; font-weight: 400 !important; color: #2b6cb0 !important;
            background: rgba(255,255,255,.92) !important; border: none !important;
            display: flex !important; align-items: center !important; justify-content: center !important;
            transition: background .15s ease, color .15s ease, transform .12s ease !important;
            text-decoration: none !important; position: relative !important;
        }
        #reportsMap .leaflet-control-zoom-in  { border-radius: 14px 14px 0 0 !important; }
        #reportsMap .leaflet-control-zoom-out { border-radius: 0 0 14px 14px !important; border-top: 1px solid rgba(43,108,176,.12) !important; }
        #reportsMap .leaflet-control-zoom-in:hover,
        #reportsMap .leaflet-control-zoom-out:hover { background: #2b6cb0 !important; color: #fff !important; }
        #reportsMap .leaflet-control-zoom-in:active,
        #reportsMap .leaflet-control-zoom-out:active { background: #245a96 !important; color: #fff !important; transform: scale(.94) !important; }
        [data-theme="dark"] #reportsMap .leaflet-control-zoom-in,
        [data-theme="dark"] #reportsMap .leaflet-control-zoom-out { background: rgba(26,26,26,.88) !important; color: #8ab4f8 !important; }
        [data-theme="dark"] #reportsMap .leaflet-control-zoom-out { border-top: 1px solid rgba(255,255,255,.08) !important; }
        [data-theme="dark"] #reportsMap .leaflet-control-zoom-in:hover,
        [data-theme="dark"] #reportsMap .leaflet-control-zoom-out:hover { background: #3762c8 !important; color: #fff !important; }
        [data-theme="dark"] #reportsMap .leaflet-bar,
        [data-theme="dark"] #reportsMap .leaflet-control-zoom { box-shadow: 0 4px 20px rgba(0,0,0,.45), 0 1px 4px rgba(0,0,0,.3) !important; }

        /* ── Pin markers (teardrop pin, same technique as the admin GIS map) ── */
        .rmp-marker-wrap { position: relative; display: flex; flex-direction: column; align-items: center; }
        .rmp-pin {
            width: 30px; height: 30px; border-radius: 50% 50% 50% 0;
            transform: rotate(-45deg); border: 3px solid #fff;
            box-shadow: 0 3px 12px rgba(0,0,0,.35); display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: transform .15s, box-shadow .15s;
        }
        .rmp-pin:hover { transform: rotate(-45deg) scale(1.1); box-shadow: 0 6px 18px rgba(0,0,0,.45); }
        .rmp-pin-inner { transform: rotate(45deg); font-size: 13px; line-height: 1; }
        .rmp-pin.rmp-scheduled { background: #1565c0; }
        .rmp-pin.rmp-in-progress { background: #f59e0b; }
        .rmp-pin.rmp-completed { background: #2e7d32; }

        /* ── Popup (matches admin GIS popup treatment) ── */
        .leaflet-popup-content-wrapper { border-radius: 12px !important; padding: 0 !important; overflow: hidden; box-shadow: 0 6px 24px rgba(0,0,0,.2) !important; }
        .leaflet-popup-content { margin: 0 !important; }
        .reports-map-popup { font-size: 13px; line-height: 1.5; min-width: 190px; padding: 12px 16px; }
        .reports-map-popup strong { display: block; font-size: 13.5px; margin-bottom: 3px; }
        .reports-map-popup .rmp-status {
            display: inline-block; margin-top: 4px; padding: 2px 9px; border-radius: 20px;
            font-size: 11px; font-weight: 700;
        }
        .rmp-scheduled { background: rgba(21,101,192,0.14); color: #1565c0; }
        .rmp-in-progress { background: rgba(245,158,11,0.16); color: #b45309; }
        .rmp-completed { background: rgba(46,125,50,0.14); color: #2e7d32; }

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
        .legend-completed { background: #2e7d32; }

        /* Pill border accent per status */
        .legend-item:has(.legend-upcoming)  { border-color: rgba(21,101,192,0.22); }
        .legend-item:has(.legend-ongoing)   { border-color: rgba(245,158,11,0.28); }
        .legend-item:has(.legend-completed) { border-color: rgba(46,125,50,0.22); }

        [data-theme="dark"] .legend-item:has(.legend-upcoming)  { border-color: rgba(21,101,192,0.40); }
        [data-theme="dark"] .legend-item:has(.legend-ongoing)   { border-color: rgba(245,158,11,0.40); }
        [data-theme="dark"] .legend-item:has(.legend-completed) { border-color: rgba(46,125,50,0.40); }

        /* Active (selected) state */
        .legend-item.legend-active { box-shadow: 0 2px 10px rgba(0,0,0,0.13); font-weight: 700; }
        .legend-item[data-filter="upcoming"].legend-active  { background: rgba(21,101,192,0.13);  border-color: #1565c0; color: #1565c0; }
        .legend-item[data-filter="ongoing"].legend-active   { background: rgba(245,158,11,0.13);  border-color: #f59e0b; color: #b45309; }
        .legend-item[data-filter="completed"].legend-active { background: rgba(46,125,50,0.13);   border-color: #2e7d32; color: #2e7d32; }
        .legend-item.legend-dimmed { opacity: 0.42; }

        [data-theme="dark"] .legend-item[data-filter="upcoming"].legend-active  { background: rgba(21,101,192,0.25);  border-color: #90caf9; color: #90caf9; }
        [data-theme="dark"] .legend-item[data-filter="ongoing"].legend-active   { background: rgba(245,158,11,0.22);  border-color: #fdd835; color: #fdd835; }
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
            /* Center the lone 3rd card so it doesn't sit left-aligned */
            .stats-grid .stat-card:last-child:nth-child(odd) {
                grid-column: 1 / -1;
                max-width: calc(50% - 6px); /* match the column width (half grid minus half gap) */
                margin-left: auto;
                margin-right: auto;
                width: 100%;
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
            /* Adjust centered 3rd card for the tighter gap */
            .stats-grid .stat-card:last-child:nth-child(odd) {
                max-width: calc(50% - 5px); /* match column width (half grid minus half gap) */
            }
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
        .sched-status-pill.sched-pending    { background: #e3f2fd; color: #1565c0; }   /* blue   */
        [data-theme="dark"] .sched-status-pill.sched-completed  { background: rgba(76,175,80,0.2);   color: #81c784; }
        [data-theme="dark"] .sched-status-pill.sched-inprogress { background: rgba(245,158,11,0.18); color: #fdd835; }
        [data-theme="dark"] .sched-status-pill.sched-pending    { background: rgba(21,101,192,0.2);  color: #90caf9; }

        /* Progress timeline — themed background frame, colour reflects overall status */
        .sched-timeline {
            display: flex; align-items: flex-start; margin: 4px 0 20px;
            padding: 16px 14px 12px; border-radius: 14px; border: 1px solid transparent;
            --tl-accent: #2563eb;
        }
        .sched-timeline-theme-pending   { --tl-accent: #1565c0; background: rgba(21,101,192,0.07);  border-color: rgba(21,101,192,0.16); }
        .sched-timeline-theme-progress  { --tl-accent: #f59e0b; background: rgba(245,158,11,0.08);  border-color: rgba(245,158,11,0.20); }
        .sched-timeline-theme-completed { --tl-accent: #2e7d32; background: rgba(46,125,50,0.08);   border-color: rgba(46,125,50,0.18); }
        [data-theme="dark"] .sched-timeline-theme-pending   { background: rgba(21,101,192,0.14);  border-color: rgba(90,150,255,0.28); }
        [data-theme="dark"] .sched-timeline-theme-progress  { background: rgba(245,158,11,0.14);  border-color: rgba(253,216,53,0.28); }
        [data-theme="dark"] .sched-timeline-theme-completed { background: rgba(46,125,50,0.16);   border-color: rgba(129,199,132,0.30); }
        .sched-timeline-step { flex: 1; text-align: center; position: relative; }
        .sched-timeline-dot {
            width: 26px; height: 26px; border-radius: 50%; margin: 0 auto 6px;
            display: flex; align-items: center; justify-content: center;
            background: #e5e7eb; color: #9ca3af; font-size: 11px; font-weight: 800;
            border: 3px solid var(--card-bg, #fff); box-shadow: 0 0 0 2px #e5e7eb; position: relative; z-index: 2;
            transition: background .2s ease, color .2s ease, box-shadow .2s ease;
        }
        .sched-timeline-step::before {
            content: ''; position: absolute; top: 12px; left: -50%; width: 100%; height: 3px;
            background: #e5e7eb; z-index: 1;
        }
        .sched-timeline-step:first-child::before { display: none; }
        .sched-timeline-label { font-size: 10px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .03em; }
        .sched-timeline-step.done .sched-timeline-dot { background: var(--tl-accent); color: #fff; box-shadow: 0 0 0 2px var(--tl-accent); }
        .sched-timeline-step.done::before { background: var(--tl-accent); }
        .sched-timeline-step.current .sched-timeline-dot { background: #fff; color: var(--tl-accent); box-shadow: 0 0 0 3px var(--tl-accent); }
        .sched-timeline-step.current .sched-timeline-label { color: var(--tl-accent); }
        [data-theme="dark"] .sched-timeline-step.current .sched-timeline-dot { background: var(--card-bg, #1e1e1e); }

        /* Fields */
        .sched-field        { margin-bottom: 14px; }
        .sched-field-label  { font-size: 11px; font-weight: 700; color: #3762c8; text-transform: uppercase; letter-spacing: .07em; margin-bottom: 4px; }
        .sched-field-value  { font-size: 14px; color: var(--text-primary, #1a1a2e); line-height: 1.55; }
        .sched-divider      { height: 1px; background: var(--border-color, rgba(0,0,0,.08)); margin: 14px 0; }
        .sched-grid-2       { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 18px; }

        /* Evidence strip */
        .sched-evidence-strip { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px; }
        .sched-evidence-thumb {
            width: 80px; height: 80px; border-radius: 10px; object-fit: cover;
            border: 2px solid var(--border-color, rgba(0,0,0,.1));
            cursor: pointer; transition: transform .2s, box-shadow .2s;
        }
        .sched-evidence-thumb:hover { transform: scale(1.07); box-shadow: 0 4px 14px rgba(55,98,200,.3); }
        .sched-no-evidence { font-size: 13px; color: var(--text-secondary, #64748b); opacity: .7; font-style: italic; }

        /* Priority badges */
        .sched-priority-badge {
            display: inline-block; padding: 3px 10px; border-radius: 20px;
            font-size: 12px; font-weight: 700;
        }
        .sched-priority-badge.p-low      { background:#d1fae5; color:#065f46; }
        .sched-priority-badge.p-medium   { background:#fef3c7; color:#92400e; }
        .sched-priority-badge.p-high     { background:#fde8e8; color:#9b1c1c; }
        .sched-priority-badge.p-critical { background:#fce7f3; color:#831843; }
        [data-theme="dark"] .sched-priority-badge.p-low      { background:rgba(6,95,70,.3);    color:#6ee7b7; }
        [data-theme="dark"] .sched-priority-badge.p-medium   { background:rgba(146,64,14,.3);  color:#fcd34d; }
        [data-theme="dark"] .sched-priority-badge.p-high     { background:rgba(155,28,28,.3);  color:#fca5a5; }
        [data-theme="dark"] .sched-priority-badge.p-critical { background:rgba(131,24,67,.3);  color:#f9a8d4; }

        /* Lightbox */
        .sched-lightbox {
            position: fixed; inset: 0; background: rgba(0,0,0,.88);
            display: none; align-items: center; justify-content: center;
            z-index: 9999; flex-direction: column; overflow: hidden;
        }
        .sched-lightbox.active { display: flex; }
        .sched-lightbox img {
            max-width: 88vw; max-height: 80vh; border-radius: 10px;
            cursor: zoom-in; transition: transform .15s ease;
            transform-origin: center center;
            user-select: none; -webkit-user-select: none;
        }
        .sched-lightbox img.zoomed { cursor: grab; }
        .sched-lightbox img.dragging { cursor: grabbing; }
        .sched-lightbox-close {
            position: absolute; top: 16px; right: 20px;
            background: rgba(255,255,255,.15); border: none; color: #fff;
            font-size: 28px; width: 44px; height: 44px; border-radius: 50%;
            cursor: pointer; display: flex; align-items: center; justify-content: center;
            z-index: 1;
        }
        .sched-lightbox-close:hover { background: rgba(255,255,255,.3); }
        .sched-lightbox-hint {
            position: absolute; bottom: 20px; left: 50%; transform: translateX(-50%);
            color: rgba(255,255,255,.5); font-size: 12px; pointer-events: none;
            transition: opacity .4s;
        }

        @media (max-width: 768px) {
            .sched-detail-modal { width: 95%; max-height: 90vh; }
            .sched-modal-header, .sched-modal-body { padding-left: 18px; padding-right: 18px; }
            .sched-grid-2 { grid-template-columns: 1fr; gap: 10px; }
        }

        /* ── District badge ── */
        .district-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 11px 3px 8px; border-radius: 999px;
            font-size: 10px; font-weight: 800; letter-spacing: .08em;
            text-transform: uppercase; vertical-align: middle;
            margin-left: 9px; white-space: nowrap; border: none;
            line-height: 1.5; position: relative; cursor: default;
            transition: transform .18s cubic-bezier(.34,1.56,.64,1), box-shadow .18s ease, filter .18s ease;
            animation: districtPop .3s cubic-bezier(.34,1.56,.64,1) both;
        }
        @keyframes districtPop {
            from { opacity: 0; transform: scale(.7) translateY(2px); }
            to   { opacity: 1; transform: scale(1) translateY(0); }
        }
        .district-badge:hover { transform: translateY(-2px) scale(1.05); filter: brightness(1.08); }
        .district-badge i { font-size: 10px; flex-shrink: 0; filter: drop-shadow(0 1px 1px rgba(0,0,0,.18)); }
        .district-badge.d1 { background: linear-gradient(135deg,#3762c8 0%,#5b8aff 100%); color:#fff; box-shadow:0 2px 10px rgba(55,98,200,.40),0 0 0 2px rgba(55,98,200,.15); }
        .district-badge.d2 { background: linear-gradient(135deg,#1a7a42 0%,#34c774 100%); color:#fff; box-shadow:0 2px 10px rgba(26,122,66,.40),0 0 0 2px rgba(26,122,66,.15); }
        .district-badge.d3 { background: linear-gradient(135deg,#b85c00 0%,#f59033 100%); color:#fff; box-shadow:0 2px 10px rgba(184,92,0,.40),0 0 0 2px rgba(184,92,0,.15); }
        .district-badge.d4 { background: linear-gradient(135deg,#ad1457 0%,#ec4899 100%); color:#fff; box-shadow:0 2px 10px rgba(173,20,87,.40),0 0 0 2px rgba(173,20,87,.15); }
        .district-badge.d5 { background: linear-gradient(135deg,#512da8 0%,#8b5cf6 100%); color:#fff; box-shadow:0 2px 10px rgba(81,45,168,.40),0 0 0 2px rgba(81,45,168,.15); }
        .district-badge.d6 { background: linear-gradient(135deg,#00607a 0%,#0ea5c9 100%); color:#fff; box-shadow:0 2px 10px rgba(0,96,122,.40),0 0 0 2px rgba(0,96,122,.15); }
        .district-badge.d-other { background: linear-gradient(135deg,#4b5563 0%,#9ca3af 100%); color:#fff; box-shadow:0 2px 10px rgba(75,85,99,.30),0 0 0 2px rgba(75,85,99,.12); }
        [data-theme="dark"] .district-badge.d1     { background:linear-gradient(135deg,#2851b3 0%,#5b8aff 100%); box-shadow:0 2px 14px rgba(91,138,255,.50),0 0 0 2px rgba(91,138,255,.22); }
        [data-theme="dark"] .district-badge.d2     { background:linear-gradient(135deg,#156335 0%,#34c774 100%); box-shadow:0 2px 14px rgba(52,199,116,.50),0 0 0 2px rgba(52,199,116,.22); }
        [data-theme="dark"] .district-badge.d3     { background:linear-gradient(135deg,#a04f00 0%,#f59033 100%); box-shadow:0 2px 14px rgba(245,144,51,.50),0 0 0 2px rgba(245,144,51,.22); }
        [data-theme="dark"] .district-badge.d4     { background:linear-gradient(135deg,#9b1050 0%,#ec4899 100%); box-shadow:0 2px 14px rgba(236,72,153,.50),0 0 0 2px rgba(236,72,153,.22); }
        [data-theme="dark"] .district-badge.d5     { background:linear-gradient(135deg,#47259a 0%,#8b5cf6 100%); box-shadow:0 2px 14px rgba(139,92,246,.50),0 0 0 2px rgba(139,92,246,.22); }
        [data-theme="dark"] .district-badge.d6     { background:linear-gradient(135deg,#00526a 0%,#0ea5c9 100%); box-shadow:0 2px 14px rgba(14,165,201,.50),0 0 0 2px rgba(14,165,201,.22); }
        [data-theme="dark"] .district-badge.d-other{ background:linear-gradient(135deg,#374151 0%,#6b7280 100%); box-shadow:0 2px 14px rgba(107,114,128,.40),0 0 0 2px rgba(107,114,128,.18); }
    </style>
    <?php include __DIR__ . '/../../includes/partials/citizen_rendering.php'; ?>
</head>
<body>

<!-- DESKTOP NAVIGATION -->
<header class="nav">
        <a href="https://infragovservices.com/" class="site-logo" target="_blank" rel="noopener noreferrer">
            <img src="../assets/img/officiallogo.png" alt="LGU Logo" style="width: 40px; border-radius: 8px;">
            <span data-i18n="site_title">InfraGovServices</span>
        </a>
        
        <div class="nav-center">
            <div class="nav-links">
                <?php if ($show_login): ?>
                <a href="login.php" data-i18n="nav_login">Log in</a>
                <?php endif; ?>
                <a href="citizencimm.php" data-i18n="nav_home">Home</a>
                <a href="#" class="active" data-i18n="nav_reports">Reports</a>
                <a href="track_report.php" data-i18n="nav_track">Track</a>
                <a href="citizenrepform.php" data-i18n="nav_requests">Requests</a>
                <a href="citizen_feedback.php" data-i18n="nav_feedback">Feedback</a>
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
                <img src="../assets/img/officiallogo.png" alt="LGU Logo">
                <div class="sidebar-divider logo-divider"></div>
            </a>
            <div class="sidebar-logo-spacer"></div>
            
            <ul class="nav-list">
                <?php if ($show_login): ?>
                <li><a href="login.php" class="nav-link"><i class="fas fa-sign-in-alt"></i><span data-i18n="nav_login">Log in</span></a></li>
                <?php endif; ?>
                <li><a href="citizencimm.php" class="nav-link"><i class="fas fa-home"></i><span data-i18n="nav_home">Home</span></a></li>
                <li><a href="#"class="nav-link active"><i class="fas fa-file-alt"></i><span data-i18n="nav_reports">Reports</span></a></li>
                <li><a href="track_report.php" class="nav-link"><i class="fas fa-magnifying-glass-location"></i><span data-i18n="nav_track">Track</span></a></li>
                <li><a href="citizenrepform.php" class="nav-link"><i class="fas fa-clipboard-list"></i><span data-i18n="nav_requests">Requests</span></a></li>
                <li><a href="citizen_feedback.php" class="nav-link"><i class="fas fa-comment-dots"></i><span data-i18n="nav_feedback">Feedback</span></a></li>
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
            <img src="../assets/img/officiallogo.png" alt="LGU Logo">
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
            placeholder="Search by Date, Type, Location, or Status..."
        >
    </div>
    <div class="sort-dropdown-wrap" id="schedSortWrap">
        <button class="sort-btn" id="schedSortBtn" data-i18n-title="sort_btn_title" title="Sort records">
            <i class="fas fa-sort"></i>
            <span class="sort-btn-label" data-i18n="sort_btn_label">Sort</span>
            <i class="fas fa-chevron-down sort-chevron"></i>
        </button>
        <div class="sort-dropdown" id="schedSortDropdown">
            <div class="sort-option active" data-sort="date-asc"><i class="fas fa-calendar-plus"></i> <span data-i18n="sort_date_asc">Date (Earliest)</span></div>
            <div class="sort-option" data-sort="date-desc"><i class="fas fa-calendar-minus"></i> <span data-i18n="sort_date_desc">Date (Latest)</span></div>
            <div class="sort-dropdown-divider"></div>
            <div class="sort-option" data-sort="id-asc"><i class="fas fa-sort-numeric-up-alt"></i> <span data-i18n="sort_id_asc">ID (Ascending)</span></div>
            <div class="sort-option" data-sort="id-desc"><i class="fas fa-sort-numeric-down-alt"></i> <span data-i18n="sort_id_desc">ID (Descending)</span></div>
            <div class="sort-dropdown-divider"></div>
            <div class="sort-option" data-sort="alpha-asc"><i class="fas fa-sort-alpha-up"></i> <span data-i18n="sort_alpha_asc">Type A → Z</span></div>
            <div class="sort-option" data-sort="alpha-desc"><i class="fas fa-sort-alpha-down-alt"></i> <span data-i18n="sort_alpha_desc">Type Z → A</span></div>
        </div>
    </div>
    <div class="view-toggle-wrap" role="group" aria-label="View toggle">
        <button type="button" class="view-toggle-btn active" id="listViewBtn" data-i18n-title="reports_view_list_title" title="List view">
            <i class="fas fa-list"></i> <span data-i18n="reports_view_list">List</span>
        </button>
        <button type="button" class="view-toggle-btn" id="mapViewBtn" data-i18n-title="reports_view_map_title" title="Map view">
            <i class="fas fa-map-marked-alt"></i> <span data-i18n="reports_view_map">Map</span>
        </button>
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
        <span class="legend-item" data-filter="completed" data-i18n-title="reports_legend_filter_title_completed" title="Click to filter: Completed">
            <span class="legend-dot legend-completed"></span><span data-i18n="reports_legend_completed">Completed</span>
        </span>
        <span id="legendClearBadge" data-i18n-title="reports_legend_clear" title="Click to clear filter">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            <span id="legendClearLabel">Scheduled</span>
        </span>
    </div>

    <!-- MAP VIEW -->
    <div class="reports-map-view" id="reportsMapView" style="display:none;">
        <div class="reports-map-toolbar">
            <span class="reports-map-title"><i class="fas fa-layer-group"></i> <span data-i18n="reports_map_toolbar_title">Reported Issues Map</span></span>
            <div class="reports-map-toolbar-tools" id="reportsMapToolbarTools">
                <div class="gis-search-wrap" id="reportsMapSearchWrap">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" id="reportsMapSearch" data-i18n-placeholder="reports_map_search_placeholder" placeholder="Search location or type…" autocomplete="off">
                    <button class="gis-search-clear" id="reportsMapSearchClear" type="button" title="Clear">&times;</button>
                    <span class="gis-search-results-badge" id="reportsMapResultsBadge">
                        <i class="fas fa-map-marker-alt"></i>
                        Showing&nbsp;<strong id="reportsMapResultsCount">0</strong>&nbsp;of&nbsp;<strong id="reportsMapTotalCount">0</strong>&nbsp;report(s)
                    </span>
                </div>
                <div class="gis-dd-wrap" id="reportsMapSortWrap">
                    <button class="gis-dd-btn" id="reportsMapSortBtn" type="button" title="Which pin draws on top when reports overlap">
                        <i class="fas fa-arrow-down-wide-short"></i>
                        <span id="reportsMapSortLabel" data-i18n="reports_map_sort_newest">Newest on top</span>
                        <i class="fas fa-chevron-down gis-dd-chevron"></i>
                    </button>
                    <div class="gis-dd-menu" id="reportsMapSortMenu">
                        <div class="gis-dd-item active" data-val="newest"><i class="fas fa-clock"></i> <span data-i18n="reports_map_sort_newest">Newest on top</span></div>
                        <div class="gis-dd-item" data-val="oldest"><i class="fas fa-clock-rotate-left"></i> <span data-i18n="reports_map_sort_oldest">Oldest on top</span></div>
                        <div class="gis-dd-item" data-val="status"><i class="fas fa-circle-half-stroke"></i> <span data-i18n="reports_map_sort_status">Status on top</span></div>
                    </div>
                </div>
                <div class="gis-dd-wrap" id="reportsMapStatusWrap">
                    <button class="gis-dd-btn" id="reportsMapStatusBtn" type="button">
                        <i class="fas fa-circle-half-stroke"></i>
                        <span id="reportsMapStatusLabel" data-i18n="reports_map_filter_all_status">All Status</span>
                        <i class="fas fa-chevron-down gis-dd-chevron"></i>
                    </button>
                    <div class="gis-dd-menu" id="reportsMapStatusMenu">
                        <div class="gis-dd-item active" data-val="all"><i class="fas fa-layer-group"></i> <span data-i18n="reports_map_filter_all_status">All Status</span></div>
                        <div class="gis-dd-item" data-val="upcoming"><i class="fas fa-calendar-days" style="color:#1565c0"></i> <span data-i18n="reports_legend_scheduled">Scheduled</span></div>
                        <div class="gis-dd-item" data-val="ongoing"><i class="fas fa-wrench" style="color:#f59e0b"></i> <span data-i18n="reports_legend_ongoing">In Progress</span></div>
                        <div class="gis-dd-item" data-val="completed"><i class="fas fa-circle-check" style="color:#2e7d32"></i> <span data-i18n="reports_legend_completed">Completed</span></div>
                    </div>
                </div>
                <div class="gis-dd-wrap" id="reportsMapDistrictWrap">
                    <button class="gis-dd-btn" id="reportsMapDistrictBtn" type="button">
                        <i class="fas fa-map-marker-alt"></i>
                        <span id="reportsMapDistrictLabel" data-i18n="reports_map_filter_all_districts">All Districts</span>
                        <i class="fas fa-chevron-down gis-dd-chevron"></i>
                    </button>
                    <div class="gis-dd-menu" id="reportsMapDistrictMenu">
                        <div class="gis-dd-item active" data-val="all"><i class="fas fa-globe-asia"></i> <span data-i18n="reports_map_filter_all_districts">All Districts</span></div>
                        <div class="gis-dd-divider"></div>
                        <div class="gis-dd-item" data-val="district 1"><i class="fas fa-location-dot"></i> District 1</div>
                        <div class="gis-dd-item" data-val="district 2"><i class="fas fa-location-dot"></i> District 2</div>
                        <div class="gis-dd-item" data-val="district 3"><i class="fas fa-location-dot"></i> District 3</div>
                        <div class="gis-dd-item" data-val="district 4"><i class="fas fa-location-dot"></i> District 4</div>
                        <div class="gis-dd-item" data-val="district 5"><i class="fas fa-location-dot"></i> District 5</div>
                        <div class="gis-dd-item" data-val="district 6"><i class="fas fa-location-dot"></i> District 6</div>
                        <div class="gis-dd-divider"></div>
                        <div class="gis-dd-item" data-val="other"><i class="fas fa-question-circle"></i> <span data-i18n="reports_map_filter_other_district">Other / Unspecified</span></div>
                    </div>
                </div>
                <div class="gis-dd-wrap" id="reportsMapPeriodWrap">
                    <button class="gis-dd-btn" id="reportsMapPeriodBtn" type="button">
                        <i class="fas fa-calendar-alt"></i>
                        <span id="reportsMapPeriodLabel" data-i18n="reports_map_filter_all_time">All Time</span>
                        <i class="fas fa-chevron-down gis-dd-chevron"></i>
                    </button>
                    <div class="gis-dd-menu" id="reportsMapPeriodMenu">
                        <div class="gis-dd-item active" data-val="all"><i class="fas fa-infinity"></i> <span data-i18n="reports_map_filter_all_time">All Time</span></div>
                        <div class="gis-dd-divider"></div>
                        <div class="gis-dd-item" data-val="today"><i class="fas fa-sun"></i> <span data-i18n="reports_map_filter_today">Today</span></div>
                        <div class="gis-dd-item" data-val="yesterday"><i class="fas fa-history"></i> <span data-i18n="reports_map_filter_yesterday">Yesterday</span></div>
                        <div class="gis-dd-item" data-val="week"><i class="fas fa-calendar-week"></i> <span data-i18n="reports_map_filter_week">This Week</span></div>
                        <div class="gis-dd-item" data-val="month"><i class="fas fa-calendar-day"></i> <span data-i18n="reports_map_filter_month">This Month</span></div>
                        <div class="gis-dd-item" data-val="year"><i class="fas fa-calendar-alt"></i> <span data-i18n="reports_map_filter_year">This Year</span></div>
                        <div class="gis-dd-item" data-val="lastyear"><i class="fas fa-undo"></i> <span data-i18n="reports_map_filter_lastyear">Last Year</span></div>
                    </div>
                </div>
                <button class="reports-map-layer-btn" id="reportsMapLayerBtn" type="button">🛰️ <span data-i18n="reports_map_satellite">Satellite</span></button>
            </div>
        </div>
        <div class="reports-map-inner">
            <button class="reports-map-expand-btn" id="reportsMapExpandBtn" type="button" title="Expand map to fullscreen" data-i18n-title="reports_map_expand_title">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="15 3 21 3 21 9"></polyline>
                    <polyline points="9 21 3 21 3 15"></polyline>
                    <line x1="21" y1="3" x2="14" y2="10"></line>
                    <line x1="3" y1="21" x2="10" y2="14"></line>
                </svg>
            </button>
            <div class="reports-map-loading" id="reportsMapLoading">
                <div class="reports-map-spinner"></div>
                <span data-i18n="reports_map_loading">Loading pinned reports…</span>
            </div>
            <div id="reportsMap"></div>
            <div class="reports-map-empty" id="reportsMapEmpty">
                <div class="no-results-icon">🔍</div>
                <div class="no-results-text" data-i18n="reports_map_empty">No pinned reports match the current filter</div>
                <div class="no-results-sub" data-i18n="reports_map_empty_sub">Try a different keyword or clear the status filter</div>
            </div>
        </div>
        <div class="reports-map-legend">
            <div class="reports-map-legend-item"><span class="reports-map-dot rmp-dot-scheduled"></span><span data-i18n="reports_legend_scheduled">Scheduled</span></div>
            <div class="reports-map-legend-item"><span class="reports-map-dot rmp-dot-progress"></span><span data-i18n="reports_legend_ongoing">In Progress</span></div>
            <div class="reports-map-legend-item"><span class="reports-map-dot rmp-dot-completed"></span><span data-i18n="reports_legend_completed">Completed</span></div>
            <div class="reports-map-legend-hint"><i class="fas fa-info-circle"></i> <span data-i18n="reports_map_hint">Click a pin to view report details</span></div>
        </div>
    </div>

    <!-- FULLSCREEN MAP OVERLAY -->
    <div class="reports-map-fs-backdrop" id="reportsMapFsBackdrop">
        <div class="reports-map-fs-modal">
            <div class="reports-map-fs-head">
                <strong><i class="fas fa-layer-group"></i> <span data-i18n="reports_map_toolbar_title">Reported Issues Map</span></strong>
                <div class="reports-map-fs-head-tools" id="reportsMapFsHeadTools"></div>
                <button class="reports-map-fs-close" id="reportsMapFsClose" type="button" title="Close">&times;</button>
            </div>
            <div class="reports-map-fs-body" id="reportsMapFsBody"></div>
        </div>
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
                <col class="col-status">
                <col class="col-action">
            </colgroup>
            <thead>
                <tr>
                    <th data-i18n="reports_table_sched">Sched #</th>
                    <th data-i18n="reports_table_date">Date</th>
                    <th data-i18n="reports_table_type">Type</th>
                    <th data-i18n="reports_table_location">Location</th>
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
                        }
                        $date = !empty($item['starting_date']) ? date('M d, Y', strtotime($item['starting_date'])) : '—';
                        $task_escaped     = htmlspecialchars($item['task']);
                        $location_escaped = htmlspecialchars($item['location']);
                ?>
                <tr data-status="<?php echo $status_filter_key; ?>"
                    data-date="<?php echo !empty($item['starting_date']) ? htmlspecialchars($item['starting_date']) : ''; ?>"
                    data-id-label="<?php echo htmlspecialchars($item['id_label']); ?>"
                    data-type="<?php echo htmlspecialchars(strtolower($item['task'])); ?>">
                    <td class="searchable"><?php echo htmlspecialchars($item['id_label']); ?></td>
                    <td class="searchable"><?php echo $date; ?></td>
                    <td class="searchable" title="<?php echo $task_escaped; ?>"><?php echo $task_escaped; ?></td>
                    <td class="searchable" title="<?php echo $location_escaped; ?>"><?php echo $location_escaped; ?></td>
                    <td class="searchable"><span class="status-pill <?php echo $status_class; ?>" data-i18n="<?php echo $status_key; ?>"><?php echo htmlspecialchars($item['status']); ?></span></td>
                    <td><a href="#" class="link" onclick="openSchedModal(<?= (int)$item['modal_id'] ?>);return false;" data-i18n="reports_view_button">View</a></td>
                </tr>
                <?php 
                    }
                } else {
                ?>
                <tr>
                    <td colspan="6" style="text-align: center; color: #999;" data-i18n="reports_no_data">No maintenance schedules available</td>
                </tr>
                <?php } ?>
                <tr id="noRequestResult" style="display:none;">
                    <td colspan="6" style="text-align:center; padding:20px; font-weight:500;" data-i18n="reports_no_match">
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
                }
            ?>
                <div class="report-card" data-status="<?= $status_filter_key ?>"
                 data-date="<?= !empty($item['starting_date']) ? htmlspecialchars($item['starting_date']) : '' ?>"
                 data-id-label="<?= htmlspecialchars($item['id_label']) ?>"
                 data-type="<?= htmlspecialchars(strtolower($item['task'])) ?>"><?php // data-status for legend filter ?>
                    <div class="report-row">
                        <span class="label" data-i18n="reports_mobile_schedule_id">Schedule ID:</span>
                        <span class="value searchable"><?= htmlspecialchars($item['id_label']) ?></span>
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

        // Keep the map view (if initialized) in sync with the same filter
        window.__reportsActiveLegendFilter = filter;
        if (window.__reportsMapApplyFilter) window.__reportsMapApplyFilter(filter);
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
    // Expose a direct (non-toggling) setter too, so the map's own Status
    // filter dropdown can set an exact value without guessing current state.
    window.applyLegendFilter = applyLegendFilter;
    window.getActiveLegendFilter = function() { return activeLegendFilter; };
});
</script>

<!-- ═══════════════ LIST / MAP VIEW TOGGLE + ISSUE MAP ═══════════════ -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function () {
    const API_BASE   = <?= json_encode($BASE_URL) ?>;
    const listBtn    = document.getElementById('listViewBtn');
    const mapBtn     = document.getElementById('mapViewBtn');
    const mapView    = document.getElementById('reportsMapView');
    const mapEmpty   = document.getElementById('reportsMapEmpty');
    const mapLoading = document.getElementById('reportsMapLoading');
    const layerBtn   = document.getElementById('reportsMapLayerBtn');
    const tableWrap  = document.querySelector('.table-wrapper');
    const mobileList = document.querySelector('.mobile-maintenance-list');
    const mapSearchEl      = document.getElementById('reportsMapSearch');
    const mapSearchClearEl = document.getElementById('reportsMapSearchClear');
    const searchWrapEl      = document.getElementById('reportsMapSearchWrap');
    const resultsBadge      = document.getElementById('reportsMapResultsBadge');
    const resultsCountEl    = document.getElementById('reportsMapResultsCount');
    const totalCountEl      = document.getElementById('reportsMapTotalCount');
    const sortWrap           = document.getElementById('reportsMapSortWrap');
    const sortBtn             = document.getElementById('reportsMapSortBtn');
    const sortMenu            = document.getElementById('reportsMapSortMenu');
    const sortLabelEl         = document.getElementById('reportsMapSortLabel');
    const statusWrap          = document.getElementById('reportsMapStatusWrap');
    const statusBtn           = document.getElementById('reportsMapStatusBtn');
    const statusMenu          = document.getElementById('reportsMapStatusMenu');
    const statusLabelEl       = document.getElementById('reportsMapStatusLabel');
    const districtWrap        = document.getElementById('reportsMapDistrictWrap');
    const districtBtn         = document.getElementById('reportsMapDistrictBtn');
    const districtMenu        = document.getElementById('reportsMapDistrictMenu');
    const districtLabelEl     = document.getElementById('reportsMapDistrictLabel');
    const periodWrap          = document.getElementById('reportsMapPeriodWrap');
    const periodBtn           = document.getElementById('reportsMapPeriodBtn');
    const periodMenu          = document.getElementById('reportsMapPeriodMenu');
    const periodLabelEl       = document.getElementById('reportsMapPeriodLabel');
    const expandBtn          = document.getElementById('reportsMapExpandBtn');
    const fsBackdrop         = document.getElementById('reportsMapFsBackdrop');
    const fsBody             = document.getElementById('reportsMapFsBody');
    const fsClose            = document.getElementById('reportsMapFsClose');
    const fsHeadTools        = document.getElementById('reportsMapFsHeadTools');
    if (!listBtn || !mapBtn || !mapView) return;

    // Guard against the same containing-block trap as the admin map: if any
    // ancestor card ever gets a backdrop-filter/filter/transform, a fixed
    // overlay nested inside it would shrink to that card instead of the
    // viewport. Body-level placement sidesteps that regardless (also applies
    // to every dropdown menu and the search results badge, all fixed-position).
    if (fsBackdrop && fsBackdrop.parentElement !== document.body) document.body.appendChild(fsBackdrop);
    if (sortMenu && sortMenu.parentElement !== document.body) document.body.appendChild(sortMenu);
    if (statusMenu && statusMenu.parentElement !== document.body) document.body.appendChild(statusMenu);
    if (districtMenu && districtMenu.parentElement !== document.body) document.body.appendChild(districtMenu);
    if (periodMenu && periodMenu.parentElement !== document.body) document.body.appendChild(periodMenu);
    if (resultsBadge && resultsBadge.parentElement !== document.body) document.body.appendChild(resultsBadge);

    // Status → legend filter key / colour class / pin emoji, shared with the existing legend pills.
    const STATUS_META = {
        'Scheduled':   { filter: 'upcoming',  cls: 'rmp-scheduled',   emoji: '📅' },
        'In Progress': { filter: 'ongoing',   cls: 'rmp-in-progress', emoji: '🔧' },
        'Completed':   { filter: 'completed', cls: 'rmp-completed',   emoji: '✅' },
    };
    // Rank used by the "Status" sort — higher ranks draw on top when pins
    // overlap. In Progress surfaces first since it reflects an active,
    // possibly disruptive worksite; Completed sits lowest as purely historical.
    const STATUS_RANK = { 'Completed': 1, 'Scheduled': 2, 'In Progress': 3 };

    let map = null, satelliteLayer = null, streetLayer = null;
    let markers = [];   // { marker, filter }
    let loaded = false;

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    // Teardrop pin — same shape/technique as the admin GIS map (a rotated
    // rounded square gives the classic map-pin silhouette without an image asset).
    function makePinIcon(meta, label) {
        const html = `<div class="rmp-marker-wrap">
            <div class="rmp-pin ${meta.cls}"><div class="rmp-pin-inner">${meta.emoji}</div></div>
        </div>`;
        return L.divIcon({ html, className: '', iconSize: [30, 40], iconAnchor: [15, 32], popupAnchor: [0, -34] });
    }

    function fmtShortDate(s) {
        if (!s) return null;
        const d = new Date(String(s).replace(' ', 'T'));
        if (isNaN(d)) return null;
        return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: '2-digit' });
    }

    // Builds the same "rec" shape ALL_SCHEDULES entries use, straight from a
    // map pin's already-fetched data, so a click can open the full detail
    // modal without needing the item to be one of the 10 most recent reports.
    function recordFromMapItem(item) {
        const hasRep = !!item.rep_id;
        return {
            id: hasRep ? (10000 + item.rep_id) : null,
            idLabel: hasRep ? ('#RPT-' + String(item.rep_id).padStart(3, '0')) : ('#REQ-' + String(item.req_id).padStart(3, '0')),
            isReport: true,
            task: item.type || 'Infrastructure Report',
            location: item.location,
            district: item.district || '',
            status: item.status,
            start: fmtShortDate(item.start_date),
            end: fmtShortDate(item.end_date),
            priority: item.priority || '',
            issue: item.issue || '',
            evidence_images: (item.evidence_images || []).map(p => '../' + String(p).replace(/^\/+/, '')),
        };
    }

    function loadMarkers() {
        if (loaded) return;
        loaded = true;
        fetch(`${API_BASE}api/reports-map.php`)
            .then(r => r.json())
            .then(json => {
                if (mapLoading) mapLoading.classList.add('hidden');
                if (!json || !json.success || !Array.isArray(json.data)) return;
                json.data.forEach(item => {
                    const meta = STATUS_META[item.status];
                    if (!meta || typeof item.lat !== 'number' || typeof item.lng !== 'number') return;
                    const dateStr = item.created_at ? new Date(item.created_at.replace(' ', 'T')).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : '';
                    const marker = L.marker([item.lat, item.lng], { icon: makePinIcon(meta) });
                    marker.bindPopup(
                        `<div class="reports-map-popup">
                            <strong>${escapeHtml(item.type || 'Report')}</strong>
                            ${escapeHtml(item.location || '')}
                            <span class="rmp-status ${meta.cls}">${escapeHtml(item.status)}</span>
                            ${dateStr ? `<div style="margin-top:4px;color:#888;">${dateStr}</div>` : ''}
                        </div>`
                    );
                    // Hover previews the popup; click closes it and opens the full detail modal.
                    marker.on('mouseover', function () { this.openPopup(); });
                    marker.on('mouseout', function () { this.closePopup(); });
                    marker.on('click', function () {
                        this.closePopup();
                        if (window.openSchedModalFromRecord) window.openSchedModalFromRecord(recordFromMapItem(item));
                    });
                    marker.addTo(map);
                    markers.push({
                        marker, filter: meta.filter,
                        searchText: ((item.type || '') + ' ' + (item.location || '')).toLowerCase(),
                        createdAt: item.created_at || '',
                        statusRank: STATUS_RANK[item.status] || 0,
                        district: (item.district || '').toLowerCase().trim(),
                    });
                });
                sortMarkers(currentSort);
                applyMapFilter(window.__reportsActiveLegendFilter || null);
            })
            .catch(() => { if (mapLoading) mapLoading.classList.add('hidden'); });
    }

    function updateEmptyState(visibleCount) {
        if (!mapEmpty) return;
        mapEmpty.classList.toggle('visible', markers.length > 0 && visibleCount === 0);
    }

    const KNOWN_DISTRICTS = ['district 1', 'district 2', 'district 3', 'district 4', 'district 5', 'district 6'];

    // Same date-range math as the admin GIS request map's Period filter.
    function getDateFilterRange(filter) {
        const now = new Date();
        const y = now.getFullYear(), m = now.getMonth(), d = now.getDate(), dow = now.getDay();
        if (filter === 'today')     { const s=new Date(y,m,d); s.setHours(0,0,0,0); const e=new Date(y,m,d+1); e.setHours(0,0,0,0); return {from:s,to:e}; }
        if (filter === 'yesterday') { const s=new Date(y,m,d-1); s.setHours(0,0,0,0); const e=new Date(y,m,d); e.setHours(0,0,0,0); return {from:s,to:e}; }
        if (filter === 'week')      { const s=new Date(y,m,d-dow); s.setHours(0,0,0,0); const e=new Date(y,m,d+1); e.setHours(0,0,0,0); return {from:s,to:e}; }
        if (filter === 'month')     { return {from:new Date(y,m,1), to:new Date(y,m+1,1)}; }
        if (filter === 'year')      { return {from:new Date(y,0,1), to:new Date(y+1,0,1)}; }
        if (filter === 'lastyear')  { return {from:new Date(y-1,0,1), to:new Date(y,0,1)}; }
        return null;
    }

    let searchQuery = '';
    let districtFilterVal = 'all';
    let periodFilterVal = 'all';
    let currentStatusFilter = null;

    // Plain hardcoded labels (same convention as SORT_LABELS above) — this
    // dropdown's own translation is handled separately via data-i18n on its
    // markup; this JS-side map only needs to pick the right default text.
    const STATUS_FILTER_LABELS = { all: 'All Status', upcoming: 'Scheduled', ongoing: 'In Progress', completed: 'Completed' };
    function statusLabelFor(val) {
        return STATUS_FILTER_LABELS[val] || 'All Status';
    }
    function syncStatusDropdownUI(filter) {
        const val = filter || 'all';
        if (statusLabelEl) statusLabelEl.textContent = statusLabelFor(val);
        if (statusBtn) statusBtn.classList.toggle('has-filter', val !== 'all');
        if (statusMenu) statusMenu.querySelectorAll('.gis-dd-item').forEach(i => i.classList.toggle('active', i.dataset.val === val));
    }

    function applyMapFilter(filter) {
        if (!map) return;
        currentStatusFilter = filter;
        syncStatusDropdownUI(filter);
        let visible = 0;
        const dateRange = getDateFilterRange(periodFilterVal);
        markers.forEach(({ marker, filter: f, searchText, district, createdAt }) => {
            let dateOk = true;
            if (dateRange && createdAt) {
                const normalized = createdAt.replace(' ', 'T');
                const dt = new Date(normalized.includes('+') || normalized.endsWith('Z') ? normalized : normalized + '+08:00');
                if (dateRange.from && dt < dateRange.from) dateOk = false;
                if (dateRange.to   && dt >= dateRange.to)  dateOk = false;
            }
            const districtOk = districtFilterVal === 'all'
                || (districtFilterVal === 'other' ? !KNOWN_DISTRICTS.includes(district) : district === districtFilterVal);
            const show = (!filter || f === filter) && (!searchQuery || searchText.indexOf(searchQuery) !== -1) && districtOk && dateOk;
            if (show) { if (!map.hasLayer(marker)) marker.addTo(map); visible++; }
            else if (map.hasLayer(marker)) map.removeLayer(marker);
        });
        if (searchQuery && resultsBadge) {
            resultsBadge.classList.add('visible');
            resultsBadge.classList.toggle('no-results', visible === 0);
            if (resultsCountEl) resultsCountEl.textContent = visible;
            if (totalCountEl) totalCountEl.textContent = markers.length;
            positionResultsBadge();
        } else if (resultsBadge) {
            resultsBadge.classList.remove('visible');
        }
        updateEmptyState(visible);
    }
    window.__reportsMapApplyFilter = applyMapFilter;

    // Position the (fixed-position) search results badge directly under the
    // search input, in viewport coordinates.
    function positionResultsBadge() {
        if (!resultsBadge || !mapSearchEl || !resultsBadge.classList.contains('visible')) return;
        const rect = mapSearchEl.getBoundingClientRect();
        const vw = window.innerWidth;
        let left = rect.left;
        const bw = resultsBadge.offsetWidth || 120;
        if (left + bw > vw - 8) left = vw - bw - 8;
        resultsBadge.style.top  = (rect.bottom + 6) + 'px';
        resultsBadge.style.left = left + 'px';
    }

    // "Sort" controls marker paint order — later-added pins render on top,
    // so this decides which report wins when several pins overlap on the map.
    let currentSort = 'newest';
    function sortMarkers(order) {
        currentSort = order;
        markers.sort((a, b) => {
            if (order === 'status') return a.statusRank - b.statusRank;
            const cmp = (a.createdAt || '').localeCompare(b.createdAt || '');
            return order === 'oldest' ? -cmp : cmp;
        });
        markers.forEach(({ marker }) => { if (map.hasLayer(marker)) { marker.remove(); marker.addTo(map); } });
    }

    function toggleLayer() {
        if (!map) return;
        const onSatellite = map.hasLayer(satelliteLayer);
        if (onSatellite) {
            map.removeLayer(satelliteLayer);
            streetLayer.addTo(map);
            layerBtn.classList.remove('active');
        } else {
            map.removeLayer(streetLayer);
            satelliteLayer.addTo(map);
            layerBtn.classList.add('active');
        }
    }

    function showMap() {
        listBtn.classList.remove('active');
        mapBtn.classList.add('active');
        if (tableWrap) tableWrap.style.display = 'none';
        if (mobileList) mobileList.style.display = 'none';
        mapView.style.display = '';

        if (!map) {
            const QC_POLY = [[14.7646242,121.1095933],[14.7639251,121.1093054],[14.7631436,121.1090833],[14.7627981,121.1073723],[14.7622963,121.105793],[14.7618357,121.104773],[14.7638675,121.1025355],[14.7655348,121.1016249],[14.7654178,121.1012409],[14.7651862,121.0997995],[14.7640376,121.0997537],[14.7626015,121.0990606],[14.7623292,121.0984063],[14.7615898,121.0964583],[14.7615413,121.0956111],[14.7609386,121.0948137],[14.7598163,121.0934468],[14.7591997,121.0925497],[14.7585362,121.091745],[14.7579449,121.0907068],[14.7582575,121.0896539],[14.7582657,121.089366],[14.7579696,121.0887985],[14.758085,121.0857106],[14.7578089,121.0856433],[14.7566921,121.0853354],[14.7558102,121.0851033],[14.7556543,121.08507],[14.7552569,121.0850078],[14.753781,121.0849007],[14.7533543,121.0848696],[14.7520288,121.0847854],[14.7421927,121.0663291],[14.7421837,121.0587677],[14.742157,121.0531742],[14.7422036,121.0464397],[14.7421201,121.0404931],[14.740294,121.0385103],[14.7380574,121.0362582],[14.732682,121.0308457],[14.7298826,121.0280557],[14.7292097,121.0273872],[14.7275181,121.0257601],[14.7243718,121.0224236],[14.7225911,121.0205352],[14.7204784,121.0183472],[14.7159085,121.0136441],[14.708755,121.0161294],[14.7033858,121.0179631],[14.6884807,121.0223396],[14.6851812,121.0192022],[14.6806545,121.014895],[14.6710675,121.0058529],[14.667334,121.0022246],[14.6653244,121.0003125],[14.664741,120.9997577],[14.6643627,120.9994174],[14.663877,120.9994138],[14.6634339,120.9994033],[14.661943,120.9993861],[14.6581224,120.999302],[14.6551673,120.9976659],[14.6543814,120.9972619],[14.6539536,120.9970642],[14.6528858,120.9965706],[14.6521912,120.9962495],[14.6507248,120.9955689],[14.6497136,120.9951615],[14.6480502,120.9945753],[14.6374219,120.9925993],[14.6362678,120.9921888],[14.6359804,120.9930436],[14.6305282,120.9912426],[14.6262495,120.9898201],[14.6245355,120.9913147],[14.6235329,120.9926137],[14.6226129,120.9938057],[14.6217104,120.9949749],[14.6200392,120.997134],[14.6193355,120.9978929],[14.6170829,121.0009647],[14.6150944,121.003646],[14.6139723,121.0052731],[14.6125167,121.0069471],[14.6115939,121.0081408],[14.6107331,121.0092936],[14.6098411,121.0104299],[14.607205,121.0139822],[14.6061298,121.0153858],[14.6053799,121.0163648],[14.6044948,121.0175128],[14.6029514,121.0193839],[14.607049,121.0510734],[14.6063175,121.0513718],[14.6048031,121.051977],[14.6065867,121.0567956],[14.602265,121.0590045],[14.5986502,121.0597438],[14.5983444,121.0597432],[14.5896463,121.0582621],[14.5900235,121.0596451],[14.5904899,121.0614237],[14.5919521,121.0680469],[14.5930667,121.0695316],[14.5923335,121.07788],[14.5905369,121.0826503],[14.5921634,121.0827285],[14.5951453,121.0823165],[14.5989494,121.082531],[14.6017929,121.0823531],[14.6033745,121.083786],[14.6022288,121.0863878],[14.6003282,121.0874234],[14.599318,121.0879024],[14.599072,121.0895263],[14.6001564,121.0904543],[14.6024379,121.0900155],[14.6054058,121.0883546],[14.6138249,121.079012],[14.6155269,121.0784392],[14.616765,121.0784541],[14.6177381,121.0788822],[14.6195429,121.0758218],[14.6208781,121.0765039],[14.6218147,121.0764557],[14.6228017,121.0759409],[14.6237732,121.0750915],[14.6264184,121.0747689],[14.6279073,121.0744536],[14.6286421,121.074425],[14.628847,121.0751483],[14.6296256,121.0769013],[14.6309563,121.0774626],[14.6322159,121.0776147],[14.6333002,121.0787821],[14.6336149,121.0795619],[14.6345357,121.0802379],[14.6362589,121.0806885],[14.636861,121.0813323],[14.6379116,121.0819219],[14.6383388,121.0816883],[14.6391565,121.0814591],[14.6400111,121.0817834],[14.640833,121.0823068],[14.6413518,121.0824574],[14.6424372,121.0823549],[14.6433858,121.0831803],[14.6439511,121.0835988],[14.6436446,121.084572],[14.6437206,121.0853712],[14.6444918,121.0855999],[14.6448987,121.0876123],[14.6458583,121.0874867],[14.6464517,121.0889727],[14.6468726,121.0896603],[14.6485394,121.0877901],[14.6493282,121.0868934],[14.6514982,121.0865934],[14.651506,121.0874307],[14.652202,121.0866746],[14.6527812,121.0858927],[14.6545518,121.0861472],[14.6554682,121.0857081],[14.6562612,121.0859908],[14.6566853,121.0867891],[14.6573361,121.0874608],[14.6566672,121.0882081],[14.6596216,121.0912009],[14.6609324,121.0914765],[14.6617729,121.0920319],[14.6634173,121.0935248],[14.6643486,121.0936995],[14.6646918,121.0941136],[14.6649347,121.0948585],[14.6652424,121.0956829],[14.6648805,121.0961861],[14.6642299,121.0967374],[14.6637413,121.0979213],[14.664832,121.0983915],[14.667012,121.0987996],[14.6678005,121.0987592],[14.66828,121.0989231],[14.6692092,121.0993176],[14.6700618,121.1002379],[14.6723195,121.103246],[14.6744874,121.1050187],[14.6752513,121.105877],[14.6757895,121.1066178],[14.6772824,121.1079596],[14.6787885,121.1088846],[14.6808973,121.1101685],[14.6834048,121.1116706],[14.6844409,121.1119916],[14.6852978,121.1121855],[14.6892498,121.1113444],[14.6912424,121.1113873],[14.6930258,121.1115295],[14.6957288,121.1114141],[14.6964194,121.1121743],[14.6973898,121.112502],[14.6979009,121.1134183],[14.6980488,121.1139303],[14.7208067,121.1171018],[14.7298888,121.1183676],[14.7327323,121.118638],[14.7332343,121.1176351],[14.7340306,121.1166812],[14.7343126,121.1160177],[14.7344121,121.1157523],[14.7350341,121.1148897],[14.735565,121.1144336],[14.7372321,121.1137369],[14.7376302,121.1141598],[14.7379454,121.1151634],[14.7385508,121.1157523],[14.7396788,121.1166398],[14.7398421,121.1167681],[14.7406808,121.1175255],[14.7413675,121.117651],[14.7420636,121.1178619],[14.7428784,121.1180428],[14.7434952,121.1183029],[14.74502,121.1181852],[14.745882,121.1176944],[14.7462763,121.1177004],[14.7464168,121.1177821],[14.7475179,121.1186965],[14.7495936,121.1181479],[14.7509132,121.1196186],[14.7520088,121.1206314],[14.7527807,121.1208202],[14.7539178,121.1210519],[14.7550217,121.1207944],[14.7559513,121.1213609],[14.7568643,121.1211807],[14.7578437,121.1215498],[14.7579018,121.123069],[14.7598938,121.1235239],[14.7608898,121.1253091],[14.7626983,121.125776],[14.7631133,121.1251752],[14.764273,121.1246215],[14.7645778,121.1239254],[14.7658129,121.1247996],[14.7668581,121.1259981],[14.7681074,121.1269178],[14.7693315,121.1272269],[14.7700103,121.1278939],[14.7714835,121.1290096],[14.7713221,121.1297934],[14.7714603,121.1308227],[14.771775,121.1322758],[14.7720049,121.132411],[14.7741422,121.1327295],[14.7752992,121.1337681],[14.7756687,121.1331762],[14.7764137,121.1332033],[14.7764085,121.1317064],[14.7758509,121.1311391],[14.7751283,121.1309266],[14.7762065,121.1289228],[14.7760592,121.1272065],[14.7757419,121.126301],[14.7733002,121.123635],[14.774863,121.1204059],[14.7740299,121.1191841],[14.7723201,121.1175027],[14.772087,121.116914],[14.7712492,121.1139187],[14.7693916,121.1134127],[14.7679537,121.112593],[14.7673232,121.112048],[14.7665244,121.1113289],[14.7651342,121.1099963],[14.7646242,121.1095933]];
            map = L.map('reportsMap', { scrollWheelZoom: false }).setView([14.6760, 121.0437], 12);
            L.polygon(QC_POLY, { color: '#3762c8', weight: 3, fillColor: '#3762c8', fillOpacity: .05, dashArray: '10,6', interactive: false }).addTo(map);
            streetLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19, attribution: '&copy; OpenStreetMap contributors',
            }).addTo(map);
            satelliteLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
                maxZoom: 19, attribution: 'Tiles &copy; Esri',
            });
            if (layerBtn) layerBtn.addEventListener('click', toggleLayer);
        }
        setTimeout(() => map.invalidateSize(), 60);
        loadMarkers();
    }

    function showList() {
        mapBtn.classList.remove('active');
        listBtn.classList.add('active');
        mapView.style.display = 'none';
        if (tableWrap) tableWrap.style.display = '';
        if (mobileList) mobileList.style.display = '';
    }

    mapBtn.addEventListener('click', showMap);
    listBtn.addEventListener('click', showList);

    // ── Search ──
    if (mapSearchEl) {
        mapSearchEl.addEventListener('input', () => {
            searchQuery = mapSearchEl.value.trim().toLowerCase();
            if (mapSearchClearEl) mapSearchClearEl.classList.toggle('visible', searchQuery.length > 0);
            applyMapFilter(window.__reportsActiveLegendFilter || null);
        });
        window.addEventListener('resize', positionResultsBadge);
        window.addEventListener('scroll', positionResultsBadge, true);
    }
    if (mapSearchClearEl) {
        mapSearchClearEl.addEventListener('click', () => {
            mapSearchEl.value = '';
            searchQuery = '';
            mapSearchClearEl.classList.remove('visible');
            applyMapFilter(window.__reportsActiveLegendFilter || null);
            mapSearchEl.focus();
        });
    }

    // ── Generic fixed-position dropdown — same pattern as the admin GIS
    // request map's filter menus (Status/Type/District/Period). Handles
    // open/close, viewport-aware positioning, and item-click wiring; the
    // caller just supplies what happens when an item is picked. ──
    function setupDropdown(wrap, btn, menu, labelEl, labels, defaultVal, onPick) {
        if (!wrap || !btn || !menu) return;
        function position() {
            const rect = btn.getBoundingClientRect();
            const vw = window.innerWidth, vh = window.innerHeight;
            const mw = menu.offsetWidth || 190, mh = menu.offsetHeight || 0;
            let left = rect.right - mw, top = rect.bottom + 6;
            if (left < 8) left = 8;
            if (left + mw > vw - 8) left = vw - mw - 8;
            if (top + mh > vh - 8 && rect.top > mh + 6) top = rect.top - mh - 6;
            menu.style.left = left + 'px';
            menu.style.top  = top + 'px';
        }
        function open() {
            wrap.classList.add('open');
            menu.style.display = 'block';
            position();
            void menu.offsetHeight;
            window.addEventListener('resize', position);
            window.addEventListener('scroll', position, true);
        }
        function close() {
            wrap.classList.remove('open');
            menu.style.display = 'none';
            window.removeEventListener('resize', position);
            window.removeEventListener('scroll', position, true);
        }
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            if (wrap.classList.contains('open')) close(); else open();
        });
        document.addEventListener('click', (e) => {
            if (!wrap.contains(e.target) && !menu.contains(e.target)) close();
        });
        menu.querySelectorAll('.gis-dd-item').forEach(opt => {
            opt.addEventListener('click', () => {
                const val = opt.dataset.val;
                menu.querySelectorAll('.gis-dd-item').forEach(o => o.classList.toggle('active', o === opt));
                if (labelEl) labelEl.textContent = (labels && labels[val]) || opt.textContent.trim();
                if (btn) btn.classList.toggle('has-filter', val !== defaultVal);
                onPick(val);
                close();
            });
        });
    }

    const SORT_LABELS = { newest: 'Newest on top', oldest: 'Oldest on top', status: 'Status on top' };
    setupDropdown(sortWrap, sortBtn, sortMenu, sortLabelEl, SORT_LABELS, 'newest', (val) => sortMarkers(val));

    setupDropdown(statusWrap, statusBtn, statusMenu, statusLabelEl, STATUS_FILTER_LABELS, 'all', (val) => {
        // Routes through the shared legend filter setter so the legend pills
        // and table above stay in sync with whatever the map's own dropdown picks.
        if (window.applyLegendFilter) window.applyLegendFilter(val === 'all' ? null : val);
        else applyMapFilter(val === 'all' ? null : val);
    });

    const DISTRICT_LABELS = {
        all: 'All Districts', 'district 1': 'District 1', 'district 2': 'District 2', 'district 3': 'District 3',
        'district 4': 'District 4', 'district 5': 'District 5', 'district 6': 'District 6', other: 'Other / Unspecified',
    };
    setupDropdown(districtWrap, districtBtn, districtMenu, districtLabelEl, DISTRICT_LABELS, 'all', (val) => {
        districtFilterVal = val;
        applyMapFilter(currentStatusFilter);
    });

    const PERIOD_LABELS = {
        all: 'All Time', today: 'Today', yesterday: 'Yesterday', week: 'This Week',
        month: 'This Month', year: 'This Year', lastyear: 'Last Year',
    };
    setupDropdown(periodWrap, periodBtn, periodMenu, periodLabelEl, PERIOD_LABELS, 'all', (val) => {
        periodFilterVal = val;
        applyMapFilter(currentStatusFilter);
    });

    // ── Fullscreen expand — reparents the live map DOM node (plus the search
    // box and sort dropdown, so they stay usable while fullscreen) into the
    // overlay. Leaflet/inputs don't care where their container lives, they
    // just need to still be in the document — so there's nothing to keep in
    // sync, unlike a cloned/second copy would require.
    // Scroll-wheel zoom is only enabled while fullscreen — the embedded card view
    // keeps it off so an accidental scroll over the map doesn't hijack page scroll. ──
    const fsMoved = []; // { el, parent, nextSibling }
    function fsMoveIn(el, target) {
        if (!el || !target) return;
        fsMoved.push({ el, parent: el.parentNode, nextSibling: el.nextSibling });
        target.appendChild(el);
    }
    function fsMoveBack() {
        while (fsMoved.length) {
            const { el, parent, nextSibling } = fsMoved.pop();
            parent.insertBefore(el, nextSibling);
        }
    }
    function openFullscreen() {
        if (!map || !fsBackdrop || !fsBody) return;
        const inner = document.querySelector('.reports-map-view .reports-map-inner');
        if (!inner) return;
        fsMoveIn(inner, fsBody);
        fsMoveIn(searchWrapEl, fsHeadTools);
        fsMoveIn(sortWrap, fsHeadTools);
        fsBackdrop.classList.add('active');
        document.body.style.overflow = 'hidden';
        map.scrollWheelZoom.enable();
        setTimeout(() => map.invalidateSize(), 60);
    }
    function closeFullscreen() {
        if (!fsBackdrop) return;
        fsMoveBack();
        fsBackdrop.classList.remove('active');
        document.body.style.overflow = '';
        if (map) { map.scrollWheelZoom.disable(); setTimeout(() => map.invalidateSize(), 60); }
    }
    if (expandBtn) expandBtn.addEventListener('click', openFullscreen);
    if (fsClose) fsClose.addEventListener('click', closeFullscreen);
    if (fsBackdrop) fsBackdrop.addEventListener('click', (e) => { if (e.target === fsBackdrop) closeFullscreen(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && fsBackdrop && fsBackdrop.classList.contains('active')) closeFullscreen(); });
})();
</script>

<!-- ═══════════════ SCHEDULE DETAIL MODAL ═══════════════ -->
<!-- Evidence Lightbox -->
<div id="schedLightbox" class="sched-lightbox">
    <button class="sched-lightbox-close" id="schedLightboxClose">&#215;</button>
    <img id="schedLightboxImg" src="" alt="Evidence" draggable="false">
    <div class="sched-lightbox-hint" id="schedLightboxHint">Double-tap to zoom &nbsp;·&nbsp; Scroll to zoom</div>
</div>

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
            <div class="sched-timeline" id="schedModalTimeline"></div>
            <div class="sched-field">
                <div class="sched-field-label" id="lbl-location">📍 Location</div>
                <div class="sched-field-value" id="schedModalLocation"></div>
            </div>
            <div class="sched-field" id="sched-issue-field" style="display:none;">
                <div class="sched-field-label" id="lbl-issue">📝 Issue / Notes</div>
                <div class="sched-field-value" id="schedModalIssue"></div>
            </div>
            <div class="sched-divider"></div>
            <div class="sched-grid-2">
                <div class="sched-field">
                    <div class="sched-field-label" id="lbl-priority">🚦 Priority</div>
                    <div class="sched-field-value" id="schedModalPriority"></div>
                </div>
                <div class="sched-field">
                    <div class="sched-field-label" id="lbl-start">📅 Start Date</div>
                    <div class="sched-field-value" id="schedModalStart"></div>
                </div>
                <div class="sched-field">
                    <div class="sched-field-label" id="lbl-end">🏁 Est. Completion</div>
                    <div class="sched-field-value" id="schedModalEnd"></div>
                </div>
            </div>
            <div class="sched-divider" id="sched-evidence-divider" style="display:none;"></div>
            <div class="sched-field" id="sched-evidence-field" style="display:none;">
                <div class="sched-field-label" id="lbl-evidence">🖼️ Evidence Photos</div>
                <div class="sched-evidence-strip" id="schedModalEvidence"></div>
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
                'id'              => (int)$m['modal_id'],
                'task'            => $m['task'],
                'location'        => $m['location'],
                'district'        => $m['district'] ?? '',
                'status'          => $m['status'],
                'start'           => !empty($m['starting_date']) ? date('M d, Y', strtotime($m['starting_date'])) : '—',
                'end'             => !empty($m['end_date'])
                                       ? date('M d, Y', strtotime($m['end_date']))
                                       : '—',
                'priority'        => $m['priority'] ?? '',
                'issue'           => $m['issue'] ?? '',
                // Evidence paths are stored relative to public/ (e.g. "uploads/evidence/x.jpg"),
                // but this page lives in public/citizen/ — without the "../" prefix the browser
                // resolves them against public/citizen/uploads/... which doesn't exist, so every
                // evidence thumbnail 404'd silently.
                'evidence_images' => array_map(fn($p) => '../' . ltrim($p, '/'), $m['evidence_images'] ?? []),
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
            issue    : '📝 Issue / Notes',
            priority : '🚦 Priority',
            start    : '📅 Start Date',
            end      : '🏁 Est. Completion',
            evidence : '🖼️ Evidence Photos',
            noDate   : 'Not set',
            noPriority: 'Not specified',
        },
        tl: {
            location : '📍 Lokasyon',
            issue    : '📝 Isyu / Mga Tala',
            priority : '🚦 Priyoridad',
            start    : '📅 Petsa ng Pagsisimula',
            end      : '🏁 Tinatayang Tapusin',
            evidence : '🖼️ Mga Larawan ng Ebidensya',
            noDate   : 'Hindi pa natakda',
            noPriority: 'Hindi tinukoy',
        }
    };

    /* ── Status → CSS class + bilingual label ── */
    var STATUS_MAP = {
        'Completed'  : { cls: 'sched-completed',  en: '✅ Completed',   tl: '✅ Natapos'      },
        'In Progress': { cls: 'sched-inprogress', en: '🔄 In Progress', tl: '🔄 Isinasagawa'  },
        'Pending'    : { cls: 'sched-pending',    en: '⏳ Pending',     tl: '⏳ Nakabinbin'   },
        'Scheduled'  : { cls: 'sched-pending',    en: '📅 Scheduled',   tl: '📅 Nakaplanong'  },
    };

    /* ── DOM refs ── */
    var backdrop         = document.getElementById('schedModalBackdrop');
    var band             = document.getElementById('schedModalBand');
    var idEl             = document.getElementById('schedModalId');
    var titleEl          = document.getElementById('schedModalTitle');
    var statusEl         = document.getElementById('schedModalStatus');
    var locEl            = document.getElementById('schedModalLocation');
    var issueEl          = document.getElementById('schedModalIssue');
    var issueFld         = document.getElementById('sched-issue-field');
    var priorityEl       = document.getElementById('schedModalPriority');
    var startEl          = document.getElementById('schedModalStart');
    var endEl            = document.getElementById('schedModalEnd');
    var evidenceEl       = document.getElementById('schedModalEvidence');
    var evidenceFld      = document.getElementById('sched-evidence-field');
    var evidenceDivider  = document.getElementById('sched-evidence-divider');
    var closeBtn         = document.getElementById('schedModalClose');
    var lightbox         = document.getElementById('schedLightbox');
    var lightboxImg      = document.getElementById('schedLightboxImg');
    var lightboxClose    = document.getElementById('schedLightboxClose');

    /* ── Label elements ── */
    var lblLocation = document.getElementById('lbl-location');
    var lblIssue    = document.getElementById('lbl-issue');
    var lblPriority = document.getElementById('lbl-priority');
    var lblStart    = document.getElementById('lbl-start');
    var lblEnd      = document.getElementById('lbl-end');
    var lblEvidence = document.getElementById('lbl-evidence');

    /* ── Priority badge helper ── */
    var PRIORITY_MAP = {
        'Low':      'p-low',
        'Medium':   'p-medium',
        'High':     'p-high',
        'Critical': 'p-critical',
    };

    /* ── District badge helper ── */
    function makeDistrictBadge(district) {
        if (!district) return '';
        var map = {
            'district 1': 'd1', 'district 2': 'd2', 'district 3': 'd3',
            'district 4': 'd4', 'district 5': 'd5', 'district 6': 'd6'
        };
        var cls = map[(district || '').toLowerCase().trim()] || 'd-other';
        return '<span class="district-badge ' + cls + '"><i class="fas fa-location-dot"></i>' + district + '</span>';
    }

    /* ── Render (shared by ID-lookup opens and direct map-pin opens) ── */
    function renderSchedModalRecord(rec) {
        var lang   = getLang();
        var lbl    = LABELS[lang] || LABELS.en;
        var smap   = STATUS_MAP[rec.status] || { cls: 'sched-pending', en: rec.status, tl: rec.status };
        var isRpt  = (typeof rec.isReport === 'boolean') ? rec.isReport : (rec.id >= 10000); // reports are offset by 10000

        /* Labels */
        lblLocation.textContent = lbl.location;
        if (lblIssue)    lblIssue.textContent    = lbl.issue;
        if (lblPriority) lblPriority.textContent = lbl.priority;
        lblStart.textContent    = lbl.start;
        lblEnd.textContent      = lbl.end;
        if (lblEvidence) lblEvidence.textContent = lbl.evidence;

        /* Band colour */
        band.className = 'sched-modal-band ' + smap.cls;

        /* ID prefix differs: SCH- for maintenance, RPT- for reports (or an explicit label for map-sourced records) */
        if (rec.idLabel) {
            idEl.textContent = rec.idLabel;
        } else if (isRpt) {
            idEl.textContent = '#RPT-' + String(rec.id - 10000).padStart(3, '0');
        } else {
            idEl.textContent = '#SCH-' + String(rec.id).padStart(3, '0');
        }
        titleEl.textContent  = rec.task;
        locEl.innerHTML      = (rec.location ? rec.location.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') : '—') + makeDistrictBadge(rec.district || '');
        startEl.textContent  = rec.start    || lbl.noDate;
        endEl.textContent    = rec.end      || lbl.noDate;

        /* Issue / Notes — only for reports */
        if (issueFld) {
            if (isRpt && rec.issue && rec.issue.trim()) {
                issueEl.textContent = rec.issue;
                issueFld.style.display = '';
            } else {
                issueFld.style.display = 'none';
            }
        }

        /* Priority — only for reports */
        if (priorityEl) {
            if (isRpt && rec.priority) {
                var pClass = PRIORITY_MAP[rec.priority] || 'p-low';
                priorityEl.innerHTML = '<span class="sched-priority-badge ' + pClass + '">' + rec.priority + '</span>';
            } else if (isRpt) {
                priorityEl.textContent = lbl.noPriority;
            } else {
                priorityEl.textContent = '—';
            }
        }

        /* Evidence images — only for reports */
        if (evidenceFld && evidenceEl) {
            var imgs = (rec.evidence_images && rec.evidence_images.length) ? rec.evidence_images : [];
            if (isRpt && imgs.length > 0) {
                evidenceEl.innerHTML = '';
                imgs.forEach(function (src) {
                    var img = document.createElement('img');
                    img.src = src;
                    img.alt = 'Evidence';
                    img.className = 'sched-evidence-thumb';
                    img.onclick = function () { openSchedLightbox(src); };
                    evidenceEl.appendChild(img);
                });
                evidenceFld.style.display = '';
                if (evidenceDivider) evidenceDivider.style.display = '';
            } else {
                evidenceFld.style.display = 'none';
                if (evidenceDivider) evidenceDivider.style.display = 'none';
            }
        }

        /* Status pill */
        statusEl.textContent = (lang === 'tl') ? smap.tl : smap.en;
        statusEl.className   = 'sched-status-pill ' + smap.cls;

        /* Progress timeline — Scheduled → In Progress → Completed */
        var timelineEl = document.getElementById('schedModalTimeline');
        if (timelineEl) {
            var STAGE_LABELS = {
                en: ['📅 Scheduled', '🔄 In Progress', '✅ Completed'],
                tl: ['📅 Nakaplanong', '🔄 Isinasagawa', '✅ Natapos'],
            };
            var labels = STAGE_LABELS[lang] || STAGE_LABELS.en;
            var stage = rec.status === 'Completed' ? 2 : (rec.status === 'In Progress' ? 1 : 0);
            // The final stage (Completed) has no "next" step to be "current" about —
            // once reached it's done too, so it gets the checkmark like every earlier
            // step instead of sitting as an unchecked "current" circle showing "3".
            var isFinalStageReached = stage >= labels.length - 1;
            timelineEl.innerHTML = labels.map(function (label, i) {
                var isDone = i < stage || (i === stage && isFinalStageReached);
                var cls  = isDone ? 'done' : (i === stage ? 'current' : '');
                var icon = isDone ? '✓' : (i + 1);
                return '<div class="sched-timeline-step ' + cls + '">' +
                           '<div class="sched-timeline-dot">' + icon + '</div>' +
                           '<div class="sched-timeline-label">' + label + '</div>' +
                       '</div>';
            }).join('');

            // Themed background frame — colour reflects overall status so the
            // timeline reads at a glance instead of floating bare on the modal.
            var THEME_CLASS = { 'sched-completed': 'sched-timeline-theme-completed', 'sched-inprogress': 'sched-timeline-theme-progress', 'sched-pending': 'sched-timeline-theme-pending' };
            timelineEl.className = 'sched-timeline ' + (THEME_CLASS[smap.cls] || 'sched-timeline-theme-pending');
        }

        /* Show */
        backdrop.classList.add('active');
        document.body.style.overflow = 'hidden';
        closeBtn.focus();
    }

    /* ── Open by ID (table/card "View" links) ── */
    window.openSchedModal = function (schedId) {
        var rec = null;
        for (var i = 0; i < ALL_SCHEDULES.length; i++) {
            if (ALL_SCHEDULES[i].id === schedId) { rec = ALL_SCHEDULES[i]; break; }
        }
        if (!rec) return;
        renderSchedModalRecord(rec);
    };

    /* ── Open from a map pin — the map's data isn't limited to the 10 most
       recent items in ALL_SCHEDULES, so pins render straight from the
       already-fetched map record instead of an ID lookup. ── */
    window.openSchedModalFromRecord = function (rec) {
        renderSchedModalRecord(rec);
    };

    /* ── Lightbox with zoom/pan ── */
    var lbScale = 1, lbTX = 0, lbTY = 0;
    var lbDragging = false, lbDragStartX = 0, lbDragStartY = 0;
    var LB_BASE_ZOOM = 2.5, LB_MAX_ZOOM = 5;
    var lbHintTimer = null;

    function lbSetTransform() {
        lightboxImg.style.transform = 'scale(' + lbScale + ') translate(' + lbTX + 'px,' + lbTY + 'px)';
    }

    function lbReset() {
        lbScale = 1; lbTX = 0; lbTY = 0;
        lbSetTransform();
        lightboxImg.classList.remove('zoomed','dragging');
        lightboxImg.style.cursor = 'zoom-in';
    }

    function openSchedLightbox(src) {
        if (!lightbox || !lightboxImg) return;
        lbReset();
        lightboxImg.src = src;
        lightbox.classList.add('active');
        document.body.style.overflow = 'hidden';
        /* Show hint briefly */
        var hint = document.getElementById('schedLightboxHint');
        if (hint) {
            hint.style.opacity = '1';
            clearTimeout(lbHintTimer);
            lbHintTimer = setTimeout(function(){ hint.style.opacity = '0'; }, 2500);
        }
    }

    function closeSchedLightbox() {
        if (!lightbox) return;
        lbReset();
        lightbox.classList.remove('active');
        lightboxImg.src = '';
        document.body.style.overflow = '';
    }

    /* Double-click / double-tap to zoom */
    lightboxImg && lightboxImg.addEventListener('dblclick', function(e) {
        if (lbScale > 1) { lbReset(); return; }
        var rect = lightboxImg.getBoundingClientRect();
        var px = (e.clientX - rect.left) / rect.width  - 0.5;
        var py = (e.clientY - rect.top)  / rect.height - 0.5;
        lbScale = LB_BASE_ZOOM;
        lbTX = -px * rect.width  * (LB_BASE_ZOOM - 1) / LB_BASE_ZOOM;
        lbTY = -py * rect.height * (LB_BASE_ZOOM - 1) / LB_BASE_ZOOM;
        lbSetTransform();
        lightboxImg.classList.add('zoomed');
        lightboxImg.style.cursor = 'grab';
    });

    /* Mouse wheel zoom */
    lightboxImg && lightboxImg.addEventListener('wheel', function(e) {
        e.preventDefault();
        var rect = lightboxImg.getBoundingClientRect();
        var px = (e.clientX - rect.left) / rect.width  - 0.5;
        var py = (e.clientY - rect.top)  / rect.height - 0.5;
        var delta = e.deltaY < 0 ? 0.25 : -0.25;
        var newScale = Math.min(Math.max(lbScale + delta, 1), LB_MAX_ZOOM);
        if (newScale === 1) { lbReset(); return; }
        var scaleDelta = newScale / lbScale;
        lbTX = lbTX * scaleDelta - px * rect.width  * (scaleDelta - 1) / newScale;
        lbTY = lbTY * scaleDelta - py * rect.height * (scaleDelta - 1) / newScale;
        lbScale = newScale;
        lbSetTransform();
        lightboxImg.classList.add('zoomed');
        lightboxImg.style.cursor = 'grab';
    }, { passive: false });

    /* Mouse drag */
    lightboxImg && lightboxImg.addEventListener('mousedown', function(e) {
        if (lbScale <= 1 || e.button !== 0) return;
        lbDragging = true;
        lbDragStartX = e.clientX - lbTX * lbScale;
        lbDragStartY = e.clientY - lbTY * lbScale;
        lightboxImg.classList.add('dragging');
        e.preventDefault();
    });
    document.addEventListener('mousemove', function(e) {
        if (!lbDragging) return;
        lbTX = (e.clientX - lbDragStartX) / lbScale;
        lbTY = (e.clientY - lbDragStartY) / lbScale;
        lbSetTransform();
    });
    document.addEventListener('mouseup', function() {
        if (!lbDragging) return;
        lbDragging = false;
        if (lightboxImg) lightboxImg.classList.remove('dragging');
    });

    /* Touch pinch-to-zoom + swipe */
    var lbTouchStartDist = null, lbTouchStartScale = 1;
    var lbTouchStartX = 0, lbSwipeStartX = 0;
    lightboxImg && lightboxImg.addEventListener('touchstart', function(e) {
        if (e.touches.length === 2) {
            lbTouchStartDist = Math.hypot(
                e.touches[1].clientX - e.touches[0].clientX,
                e.touches[1].clientY - e.touches[0].clientY
            );
            lbTouchStartScale = lbScale;
        } else if (e.touches.length === 1) {
            lbSwipeStartX = e.touches[0].clientX;
            if (lbScale > 1) {
                lbDragging = true;
                lbDragStartX = e.touches[0].clientX - lbTX * lbScale;
                lbDragStartY = e.touches[0].clientY - lbTY * lbScale;
            }
        }
    }, { passive: true });
    lightboxImg && lightboxImg.addEventListener('touchmove', function(e) {
        if (e.touches.length === 2 && lbTouchStartDist) {
            e.preventDefault();
            var dist = Math.hypot(
                e.touches[1].clientX - e.touches[0].clientX,
                e.touches[1].clientY - e.touches[0].clientY
            );
            lbScale = Math.min(Math.max(lbTouchStartScale * (dist / lbTouchStartDist), 1), LB_MAX_ZOOM);
            lbSetTransform();
            if (lbScale > 1) lightboxImg.classList.add('zoomed');
        } else if (e.touches.length === 1 && lbDragging && lbScale > 1) {
            lbTX = (e.touches[0].clientX - lbDragStartX) / lbScale;
            lbTY = (e.touches[0].clientY - lbDragStartY) / lbScale;
            lbSetTransform();
        }
    }, { passive: false });
    lightboxImg && lightboxImg.addEventListener('touchend', function(e) {
        lbTouchStartDist = null;
        lbDragging = false;
        if (lbScale <= 1) lbReset();
    }, { passive: true });

    lightboxClose && lightboxClose.addEventListener('click', closeSchedLightbox);
    lightbox && lightbox.addEventListener('click', function(e) {
        if (e.target === lightbox) closeSchedLightbox();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && lightbox && lightbox.classList.contains('active')) {
            closeSchedLightbox();
        }
    });

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
                <li><a href="<?= $BASE_URL ?>track_report.php" data-i18n="footer_link_track">Track My Report</a></li>
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
            nav_track:                           'Track',
            nav_feedback:                        'Feedback',
            nav_about:                           'About',
            translate_btn_title:                 'Translate to Filipino',
            lang_label:                          'EN',
            /* ── stat cards ── */
            reports_stat_scheduled:              'Scheduled',
            reports_stat_ongoing:                'On-Going',
            reports_stat_completed:              'Completed',
            reports_stat_title_scheduled:        'Filter: Scheduled',
            reports_stat_title_ongoing:          'Filter: On-Going',
            reports_stat_title_completed:        'Filter: Completed',
            /* ── sort ── */
            sort_btn_label:                      'Sort',
            sort_btn_title:                      'Sort records',
            sort_date_asc:                       'Date (Earliest)',
            sort_date_desc:                      'Date (Latest)',
            sort_id_asc:                         'ID (Ascending)',
            sort_id_desc:                        'ID (Descending)',
            sort_alpha_asc:                      'Type A → Z',
            sort_alpha_desc:                     'Type Z → A',
            /* ── main card ── */
            reports_page_title:                  'Recent Maintenance Reports',
            reports_search_placeholder:          'Search by Date, Type, Location, or Status...',
            /* ── list/map view toggle ── */
            reports_view_list:                   'List',
            reports_view_list_title:             'List view',
            reports_view_map:                    'Map',
            reports_view_map_title:              'Map view',
            /* ── legend ── */
            reports_legend_scheduled:            'Scheduled',
            reports_legend_ongoing:              'In Progress',
            reports_legend_completed:            'Completed',
            reports_legend_filter_title_scheduled:  'Click to filter: Scheduled',
            reports_legend_filter_title_ongoing:    'Click to filter: In Progress',
            reports_legend_filter_title_completed:  'Click to filter: Completed',
            reports_legend_clear:                'Click to clear filter',
            /* ── issue map view ── */
            reports_map_toolbar_title:           'Reported Issues Map',
            reports_map_satellite:               'Satellite',
            reports_map_loading:                 'Loading pinned reports…',
            reports_map_empty:                   'No pinned reports match the current filter',
            reports_map_empty_sub:               'Try a different keyword or clear the status filter',
            reports_map_hint:                    'Click a pin to view report details',
            reports_map_search_placeholder:      'Search location or type…',
            reports_map_sort_newest:             'Newest on top',
            reports_map_sort_oldest:             'Oldest on top',
            reports_map_sort_status:             'Status on top',
            reports_map_expand_title:            'Expand map to fullscreen',
            reports_map_filter_all_status:       'All Status',
            reports_map_filter_all_districts:    'All Districts',
            reports_map_filter_other_district:   'Other / Unspecified',
            reports_map_filter_all_time:         'All Time',
            reports_map_filter_today:            'Today',
            reports_map_filter_yesterday:        'Yesterday',
            reports_map_filter_week:             'This Week',
            reports_map_filter_month:            'This Month',
            reports_map_filter_year:             'This Year',
            reports_map_filter_lastyear:         'Last Year',
            /* ── table headers ── */
            reports_table_sched:                 'Sched #',
            reports_table_date:                  'Date',
            reports_table_type:                  'Type',
            reports_table_location:              'Location',
            reports_table_status:                'Status',
            reports_table_action:                'Action',
            /* ── status pills ── */
            reports_status_scheduled:            'Scheduled',
            reports_status_completed:            'Completed',
            reports_status_in_progress:          'In Progress',
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
            reports_mobile_status:               'Status:',
            /* ── footer ── */
            footer_desc:         'Community Infrastructure Maintenance Management System for Quezon City. Dedicated to providing efficient, transparent, and responsive infrastructure services for all residents.',
            footer_quick_links:  'Quick Links',
            footer_link_home:    'Home',
            footer_link_reports: 'Reports',
            footer_link_submit:  'Submit Request',
            footer_link_track:   'Track My Report',
            footer_link_feedback: 'Feedback',
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
            nav_track:                           'Subaybayan',
            nav_feedback:                        'Puna',
            nav_about:                           'Tungkol Sa',
            translate_btn_title:                 'I-translate sa Ingles',
            lang_label:                          'FIL',
            /* ── stat cards ── */
            reports_stat_scheduled:              'Nakaplanong',
            reports_stat_ongoing:                'Isinasagawa',
            reports_stat_completed:              'Natapos',
            reports_stat_title_scheduled:        'I-filter: Nakaplanong',
            reports_stat_title_ongoing:          'I-filter: Isinasagawa',
            reports_stat_title_completed:        'I-filter: Natapos',
            /* ── sort ── */
            sort_btn_label:                      'Ayusin',
            sort_btn_title:                      'Ayusin ang mga rekord',
            sort_date_asc:                       'Petsa (Pinaka-maaga)',
            sort_date_desc:                      'Petsa (Pinaka-bago)',
            sort_id_asc:                         'ID (Pataas)',
            sort_id_desc:                        'ID (Pababa)',
            sort_alpha_asc:                      'Uri A → Z',
            sort_alpha_desc:                     'Uri Z → A',
            /* ── main card ── */
            reports_page_title:                  'Kamakailang Ulat ng Pagpapanatili',
            reports_search_placeholder:          'Maghanap ayon sa Petsa, Uri, Lokasyon, o Katayuan...',
            /* ── list/map view toggle ── */
            reports_view_list:                   'Listahan',
            reports_view_list_title:             'Tanawing Listahan',
            reports_view_map:                    'Mapa',
            reports_view_map_title:              'Tanawing Mapa',
            /* ── legend ── */
            reports_legend_scheduled:            'Nakaplanong',
            reports_legend_ongoing:              'Isinasagawa',
            reports_legend_completed:            'Natapos',
            reports_legend_filter_title_scheduled:  'I-click para i-filter: Nakaplanong',
            reports_legend_filter_title_ongoing:    'I-click para i-filter: Isinasagawa',
            reports_legend_filter_title_completed:  'I-click para i-filter: Natapos',
            reports_legend_clear:                'I-click para alisin ang filter',
            /* ── issue map view ── */
            reports_map_toolbar_title:           'Mapa ng mga Iniulat na Isyu',
            reports_map_satellite:               'Satellite',
            reports_map_loading:                 'Ikinakarga ang mga nakapinong ulat…',
            reports_map_empty:                   'Walang nakapinong ulat na tumutugma sa kasalukuyang filter',
            reports_map_empty_sub:               'Subukan ang ibang keyword o alisin ang filter ng katayuan',
            reports_map_hint:                    'I-click ang pin para tingnan ang detalye ng ulat',
            reports_map_search_placeholder:      'Maghanap ng lokasyon o uri…',
            reports_map_sort_newest:             'Pinakabago sa taas',
            reports_map_sort_oldest:             'Pinakaluma sa taas',
            reports_map_sort_status:             'Katayuan sa taas',
            reports_map_expand_title:            'Palakihin ang mapa sa buong screen',
            reports_map_filter_all_status:       'Lahat ng Katayuan',
            reports_map_filter_all_districts:    'Lahat ng Distrito',
            reports_map_filter_other_district:   'Iba / Hindi Tinukoy',
            reports_map_filter_all_time:         'Lahat ng Oras',
            reports_map_filter_today:            'Ngayon',
            reports_map_filter_yesterday:        'Kahapon',
            reports_map_filter_week:             'Ngayong Linggo',
            reports_map_filter_month:            'Ngayong Buwan',
            reports_map_filter_year:             'Ngayong Taon',
            reports_map_filter_lastyear:         'Nakaraang Taon',
            /* ── table headers ── */
            reports_table_sched:                 'Iskedyul #',
            reports_table_date:                  'Petsa',
            reports_table_type:                  'Uri',
            reports_table_location:              'Lokasyon',
            reports_table_status:                'Katayuan',
            reports_table_action:                'Aksyon',
            /* ── status pills ── */
            reports_status_scheduled:            'Nakaplanong',
            reports_status_completed:            'Natapos',
            reports_status_in_progress:          'Isinasagawa',
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
            reports_mobile_status:               'Katayuan:',
            /* ── footer ── */
            footer_desc:         'Sistema ng Pamamahala ng Pagpapanatili ng Imprastraktura ng Komunidad para sa Lungsod Quezon. Nakatuon sa pagbibigay ng mahusay, malinaw, at matuging mga serbisyong pang-imprastraktura para sa lahat ng residente.',
            footer_quick_links:  'Mabilis na mga Link',
            footer_link_home:    'Tahanan',
            footer_link_reports: 'Mga Ulat',
            footer_link_submit:  'Magsumite ng Kahilingan',
            footer_link_track:   'Subaybayan ang Aking Ulat',
            footer_link_feedback: 'Puna',
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
<?php include __DIR__ . '/../../includes/partials/citizen_global.php'; ?>
<script>window.CHATBOT_ENDPOINT = '<?= $BASE_URL ?>functionality/chatbot.php';
// ═══════════════════════════════════════════════════════
//  SORT — Citizen Reports / Schedule Table
// ═══════════════════════════════════════════════════════
(function initCitizenSort() {
    const wrap     = document.getElementById('schedSortWrap');
    const btn      = document.getElementById('schedSortBtn');
    const dropdown = document.getElementById('schedSortDropdown');
    if (!wrap || !btn || !dropdown) return;

    btn.addEventListener('click', e => { e.stopPropagation(); wrap.classList.toggle('open'); });
    document.addEventListener('click', e => { if (!wrap.contains(e.target)) wrap.classList.remove('open'); });

    dropdown.querySelectorAll('.sort-option').forEach(opt => {
        opt.addEventListener('click', () => {
            dropdown.querySelectorAll('.sort-option').forEach(o => o.classList.remove('active'));
            opt.classList.add('active');
            wrap.classList.remove('open');
            applySort(opt.dataset.sort);
        });
    });

    function parseIdNum(idLabel) {
        // e.g. "REP-007" -> 7, "SCHED-003" -> 3
        const m = (idLabel || '').match(/\d+/);
        return m ? parseInt(m[0], 10) : 0;
    }

    function applySort(mode) {
        // Desktop tbody rows
        const tbody = document.querySelector('table tbody');
        if (tbody) {
            const noRow = document.getElementById('noRequestResult');
            const rows  = Array.from(tbody.querySelectorAll('tr[data-date]'));
            rows.sort((a, b) => compare(a, b, mode));
            rows.forEach(r => tbody.appendChild(r));
            if (noRow) tbody.appendChild(noRow);
        }
        // Mobile cards
        const mList = document.querySelector('.mobile-maintenance-list');
        if (mList) {
            const cards = Array.from(mList.querySelectorAll('[data-date]'));
            cards.sort((a, b) => compare(a, b, mode));
            cards.forEach(c => mList.appendChild(c));
        }
    }

    function compare(a, b, mode) {
        const da = a.dataset.date || '', db = b.dataset.date || '';
        if (mode === 'date-asc')  return da.localeCompare(db);
        if (mode === 'date-desc') return db.localeCompare(da);
        const ia = parseIdNum(a.dataset.idLabel), ib = parseIdNum(b.dataset.idLabel);
        if (mode === 'id-asc')    return ia - ib;
        if (mode === 'id-desc')   return ib - ia;
        const ta = (a.dataset.type||'').toLowerCase(), tb = (b.dataset.type||'').toLowerCase();
        if (mode === 'alpha-asc')  return ta.localeCompare(tb);
        if (mode === 'alpha-desc') return tb.localeCompare(ta);
        return 0;
    }
})();

</script>
<?php include __DIR__ . '/../../includes/partials/chatbot-widget.php'; ?>

</body>
</html>
