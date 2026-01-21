<?php
session_start();
require_once 'db.php';

// Get repairs count from repair_archive
$repairs_count = 0;
$repairs_result = $conn->query("SELECT COUNT(*) as count FROM repair_archive");
if ($repairs_result) {
    $repairs_row = $repairs_result->fetch_assoc();
    $repairs_count = $repairs_row['count'];
}

// Get ongoing count from maintenance_schedule (In Progress status)
$ongoing_count = 0;
$ongoing_result = $conn->query("SELECT COUNT(*) as count FROM maintenance_schedule WHERE status = 'In Progress'");
if ($ongoing_result) {
    $ongoing_row = $ongoing_result->fetch_assoc();
    $ongoing_count = $ongoing_row['count'];
}

// Get pending count from requests (Pending approval status)
$pending_count = 0;
$pending_result = $conn->query("SELECT COUNT(*) as count FROM requests WHERE approval_status = 'Pending'");
if ($pending_result) {
    $pending_row = $pending_result->fetch_assoc();
    $pending_count = $pending_row['count'];
}

// Get maintenance schedule data for table
$maintenance_data = array();
$maintenance_result = $conn->query("
    SELECT sched_id, task, location, category, status, starting_date, estimated_completion_date, budget 
    FROM maintenance_schedule 
    ORDER BY starting_date DESC 
    LIMIT 10
");
if ($maintenance_result) {
    while ($row = $maintenance_result->fetch_assoc()) {
        $maintenance_data[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Citizen Dashboard - LGU Portal</title>
    <link rel="stylesheet" href="style.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap');

        /* === Added for mobile/desktop label toggling === */
        .show-on-mobile {
            display: none;
        }
        .hide-on-mobile {
            display: block;
        }

        /* Hide .show-on-mobile when in desktop view (i.e., min-width > 768px) */
        @media (min-width: 769px) {
            .show-on-mobile {
                display: none !important;
            }
        }

        body {
            min-height: 100vh;
            background: url("cityhall.jpeg") center/cover no-repeat fixed;
            margin: 0;
            padding: 0;
        }
        body::before {
            content: "";
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            backdrop-filter: blur(8px);
            background: rgba(0, 0, 0, 0.4);
            z-index: -1;
        }
        body::-webkit-scrollbar {
            display: none;
        }
        .dashboard-container {
            padding: 100px 0 40px;
            max-width: 100%;
            margin: 0;
            color: #fff;
        }
        .container {
            max-width: 1400px;
            margin: auto;
            padding: 0 40px;
        }
        .welcome-section {
            margin-bottom: 30px;
        }
        .welcome-section h1 {
            text-align: center;
            font-size: 3rem;
            font-weight: 600;
        }
        /* STAT CARDS */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 50px;
        }
        .stat-card {
            display: flex;
            align-items: center;
            gap: 15px;
            text-align: left;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            padding: 30px;
            border-radius: 18px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all .25s ease;
            cursor: pointer;
        }
        .stat-card:hover {
            transform: translateY(-4px);
            background: rgba(255, 255, 255, 0.25);
        }
        .stat-icon {
            font-size: 32px;
            background: rgba(255,255,255,.25);
            padding: 14px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .stat-card h3 {
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.8;
            margin-bottom: 4px;
            margin-top: 0;
        }
        .stat-card .number {
            font-size: 40px;
            font-weight: 600;
        }

        /* RECENT ACTIVITY TABLE & MOBILE CARDS */
        .content-card {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 18px;
            padding: 50px 60px;
            color: #333;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            transition: all .25s ease;
        }       
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            text-align: center;
            margin-bottom: 20px;
        }
        /* Center card-header h2 */
        .card-header h2 {
            margin: 0 auto;
            text-align: center;
            width: 100%;
            font-size: 30px;
        }
        /* Make card-header h2 larger on mobile view for .show-on-mobile */
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

        /* TABLE POLISH - IMPROVED TABLE CONTAINER */
        .table-wrapper {
            overflow-x: auto;
            border-radius: 16px;
            background: #ffffff;
            box-shadow: inset 0 0 0 1px rgba(0,0,0,.04);
        }

        table {
            width: 100%;
            min-width: 1200px;
            max-width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        /* MODERN TABLE HEADER - UPGRADE + STICKY */
        thead th {
            position: sticky;
            top: 0;
            background: linear-gradient(
                to bottom,
                #fdfdfd,
                #f2f4f8
            );
            z-index: 2;
            padding: 16px 18px;
            border-bottom: 1px solid #e3e6ee;
            color: #555;
            font-size: 15px;
            font-weight: 600;
            text-align: center;
            white-space: nowrap;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        th:nth-child(1) { min-width: 130px; width: 150px; }
        th:nth-child(2) { min-width: 140px; }
        th:nth-child(3) { min-width: 150px; }
        th:nth-child(4) { min-width: 120px; }
        th:nth-child(5) { min-width: 110px; }
        th:nth-child(6) { min-width: 100px; }

        /* TABLE ZEBRA + HOVER LIFT */
        td {
            padding: 16px 18px;
            border-bottom: 1px solid #eef0f5;
            font-size: 15px;
            color: #374151;
            text-align: center;
            white-space: nowrap;
        }
        td:nth-child(2),
        td:nth-child(3) {
            text-align: left;
        }

        tbody tr {
            transition: background .2s ease, transform .15s ease;
        }
        tbody tr:nth-child(even) {
            background: #fafbff;
        }
        tbody tr:hover {
            background: #eef3ff;
        }

        /* VIEW BUTTON UPGRADE */
        td a.link {
            padding: 7px 18px;
            font-size: 14px;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: #fff;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 600;
            box-shadow: 0 4px 10px rgba(59,130,246,.35);
            transition: transform .15s ease, box-shadow .15s ease, background .2s ease;
            display: inline-block;
        }
        td a.link:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(59,130,246,.45);
        }

        /* STATUS PILL - UPGRADED STYLE */
        .status-pill {
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 95px;
        }
        .status-pill::before {
            content: "●";
            font-size: 10px;
            margin-right: 6px;
        }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-fixed { background: #d4edda; color: #155724; }
        .status-progress { background: #cce5ff; color: #004085; }

        .btn-small {
            padding: 8px 16px;
            font-size: 13px;
        }
        /* Mobile maintenance list (hidden by default, replaces table on mobile) */
        .mobile-maintenance-list {
            display: none;
        }
        /* --- Begin drop-in mobile card layout --- */
        .report-card {
            width: 100%;
            font-size: 14px;
            background: #ffffff;
            border-radius: 16px;
            padding: 16px 18px;
            box-shadow: 0 8px 20px rgba(0,0,0,.12);
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        /* Modified report-row for mobile stack label and value inline instead of left/right */
        .report-row {
            display: flex;
            flex-direction: row;
            align-items: center;
            font-size: 14px;
            line-height: 1.4;
            gap: 7px;
        }
        .report-row .label {
            font-weight: 600;
            opacity: 0.7;
            margin-right: 6px;
            flex-shrink: 0;
        }
        .report-row .value {
            font-weight: 500;
            text-align: left;
            max-width: 100%;
            margin-left: 0;
            flex: 1 1 auto;
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
            background: #e3e7ee;
            color: #1f2937;
            transition: .2s ease;
        }
        .evidence-btn:hover {
            background: #d6dbe4;
        }
        /* --- End drop-in mobile card layout --- */
        .maintenance-card {
            background: #fff;
            border-radius: 16px;
            padding: 18px;
            margin-bottom: 15px;
            box-shadow: 0 6px 18px rgba(0,0,0,.08);
            color: #263238;
            transition: all .25s ease;
        }
        .maintenance-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 30px rgba(0,0,0,.15);
        }
        .maintenance-card .status-pill {
            margin-top: 8px;
        }
        .card-action {
            display: inline-block;
            margin-top: 10px;
            font-weight: 600;
            color: #005bb3;
            text-decoration: none;
        }
        /* NAVBAR UPGRADE */
        .nav {
            width: 100%;
            padding: 18px 60px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(255, 255, 255, 0.15);     /* softer glass */
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.25);  /* glowing border */
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
            color: #fff;
            font-weight: 600;
        }
        .site-logo img {
            width: 40px;
            height: auto;
            border-radius: 8px;
        }
        .nav a {
            margin-left: 25px;
            color: #fff;
            text-decoration: none;
            font-weight: 500;
            opacity: 0.85;
            transition: 0.2s;
        }
        .nav-links a {
            margin-left: 25px;
            text-decoration: none;
            cursor: pointer;
            color: #fff;
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
        @media (max-width: 1400px) {
            .container {
                max-width: 98%;
            }
            table {
                width: 100%;
                margin-left: 0;
                min-width: 900px;
            }
        }
        @media (max-width: 1150px) {
            .container {
                max-width: 100%;
                padding: 0 10px;
            }
            .content-card {
                padding: 30px 8px;
            }
            table, .table-wrapper {
                min-width: unset;
                width: 100%;
                overflow-x: auto;
            }
        }
        @media (max-width: 992px) {
            .container {
                max-width: 100%;
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

            /* === Maintenance card (same visual as request-card) === */
            .report-card {
                width: 100%;
                background: rgba(255,255,255,0.96);
                border-radius: 16px;
                padding: 16px 18px;
                box-shadow: 0 8px 20px rgba(0,0,0,.12);
                display: flex;
                flex-direction: column;
                gap: 10px;
                font-size: 14px;
            }

            /* Row layout */
            .report-row {
                display: flex;
                align-items: center;
                gap: 7px;
                line-height: 1.4;
            }

            /* Label & value formatting */
            .report-row .label {
                font-weight: 600;
                opacity: 0.7;
                flex-shrink: 0;
            }

            .report-row .value {
                font-weight: 500;
                flex: 1;
                text-align: left;
            }

            /* Footer (status + action button) */
            .report-footer {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-top: 8px;
            }

            /* View button */
            .evidence-btn {
                text-align: center;
                width: 90px;
                padding: 8px 14px;
                border-radius: 10px;
                font-size: 13px;
                font-weight: 600;
                background: #3762c8;
                color: #fff;
                border: none;
                cursor: pointer;
                transition: background .2s ease;
            }

            .evidence-btn:hover {
                background: #2851b3;
            }

            /* Status pill spacing */
            .report-footer .status-pill {
                margin-right: 6px;
            }

            .content-card {
                padding: 22px 6px;
                border-radius: 12px;
            }
            /* .show-on-mobile handled above */
            .hide-on-mobile {
                display: none !important;
            }
        }
        @media (max-width: 500px) {
            .stat-card { padding: 20px 10px; }
            .stat-icon { font-size: 25px; padding: 8px; }
            .stat-card .number { font-size: 28px; }
            .card-header h2 { font-size: 1.0rem; }
            .report-card { padding: 12px; }
        }

        /* === TABLE SEARCH BAR (DESKTOP + RESPONSIVE) === */
        .table-search-wrapper {
            width: 100%;
            max-width: 100%;
            margin-bottom: 18px;
        }
        #requestSearch {
            width: 100%;
            padding: 10px 16px;
            border-radius: 10px;
            border: 1px solid #d2d6db;
            font-size: 15px;
            outline: none;
            transition: border .2s ease, box-shadow .2s ease;
        }
        #requestSearch:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,.15);
        }
        /* === MOBILE SEARCH POSITIONING === */
        @media (max-width: 768px) {
            .table-search-wrapper {
                order: 2;
                margin-top: 10px;
                margin-bottom: 18px;
                padding: 0 10px;
            }
            .mobile-maintenance-list .card-header {
                display: flex;
                flex-direction: column;
                align-items: center;
            }
        }
        /* Prevent overflow on very small screens */
        @media (max-width: 500px) {
            #requestSearch {
                font-size: 14px;
                padding: 9px 14px;
            }
        }

    </style>
</head>
<body>

<header class="nav">
    <div class="site-logo">
        <img src="logocityhall.png" alt="LGU Logo" style="width: 40px; border-radius: 8px;">
        <span>InfraGovServices - Infrastructure and Utilities</span>
    </div>
    <div class="nav-links">
        <a href="#" class="active">Home</a>
        <a href="citizenrepform.php">Requests</a>
        <a href="about.php">About</a>
    </div>
    <div class="menu-toggle">☰</div>
</header>

<div class="main-content">
<div class="dashboard-container">
    <div class="container">
        <div class="welcome-section">
            <h1>Welcome to InfraGovServices!</h1>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">🛠️</div>
                <div>
                    <h3>Repairs</h3>
                    <div class="number"><?= $repairs_count ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">⏳</div>
                <div>
                    <h3>On-Going Repairs</h3>
                    <div class="number"><?= $ongoing_count ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📍</div>
                <div>
                    <h3>Pending</h3>
                    <div class="number"><?= $pending_count ?></div>
                </div>
            </div>
        </div>

        <div class="content-card">
            <!-- Desktop/tablet label -->
            <div class="card-header show-on-mobile">
                <h2>Recent Maintenance Reports</h2>
            </div>
            <div class="card-header">
                <h2 class="hide-on-mobile">Recent Maintenance Reports</h2>
            </div>

            <!-- SEARCH BAR: replaced with wrapper for responsive width and positioning -->
            <div class="table-search-wrapper">
                <input
                    id="requestSearch"
                    type="text"
                    placeholder="Search by Date, Type, Location, Budget, or Status..."
                >
            </div>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Location</th>
                            <th>Budget</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if (count($maintenance_data) > 0) {
                            foreach ($maintenance_data as $item) {
                                // Determine status pill class
                                $status_class = 'status-pending';
                                if ($item['status'] === 'Completed') {
                                    $status_class = 'status-fixed';
                                } elseif ($item['status'] === 'In Progress') {
                                    $status_class = 'status-progress';
                                }
                                // Format date
                                $date = date('M d, Y', strtotime($item['starting_date']));
                        ?>
                        <tr>
                            <td><?php echo $date; ?></td>
                            <td><?php echo htmlspecialchars($item['task']); ?></td>
                            <td><?php echo htmlspecialchars($item['location']); ?></td>
                            <td>₱<?php echo number_format($item['budget'], 2); ?></td>
                            <td><span class="status-pill <?php echo $status_class; ?>"><?php echo $item['status']; ?></span></td>
                            <td><a href="#" class="link">View</a></td>
                        </tr>
                        <?php 
                            }
                        } else {
                        ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: #999;">No maintenance schedules available</td>
                        </tr>
                        <?php } ?>
                        <tr id="noRequestResult" style="display:none;">
                            <td colspan="8" style="text-align:center; padding:20px; font-weight:500;">
                                No matching data
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Maintenance Cards -->
            <div class="mobile-maintenance-list">
            <!-- Mobile-only header -->
            <?php if (!empty($maintenance_data)): ?>
                <?php foreach ($maintenance_data as $item): 
                    // Status pill
                    $status_class = 'status-pending';
                    if ($item['status'] === 'Completed') {
                        $status_class = 'status-fixed';
                    } elseif ($item['status'] === 'In Progress') {
                        $status_class = 'status-progress';
                    }
                ?>
                    <div class="report-card">

                        <div class="report-row">
                            <span class="label">Schedule ID:</span>
                            <span class="value">#SCH-<?= $item['sched_id'] ?></span>
                        </div>

                        <div class="report-row">
                            <span class="label">Category:</span>
                            <span class="value"><?= htmlspecialchars($item['category']) ?></span>
                        </div>

                        <div class="report-row">
                            <span class="label">Task:</span>
                            <span class="value"><?= htmlspecialchars($item['task']) ?></span>
                        </div>

                        <div class="report-row">
                            <span class="label">Location:</span>
                            <span class="value"><?= htmlspecialchars($item['location']) ?></span>
                        </div>

                        <div class="report-row">
                            <span class="label">Start Date:</span>
                            <span class="value"><?= date('M d, Y', strtotime($item['starting_date'])) ?></span>
                        </div>

                        <div class="report-row">
                            <span class="label">Budget:</span>
                            <span class="value">₱<?= number_format($item['budget'], 2) ?></span>
                        </div>

                        <div class="report-row">
                        <span class="label">Status:</span>
                            <span class="status-pill <?= $status_class ?>">
                                <?= htmlspecialchars($item['status']) ?>
                            </span>
                        </div>

                        <div class="report-footer">
                            <a href="#" class="evidence-btn">View</a>
                        </div>

                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="report-card">No maintenance schedules available</div>
            <?php endif; ?>
            <!-- MOBILE "NO MATCHING DATA" PLACEHOLDER -->
            <div id="noMobileResult" class="report-card" style="display:none; text-align:center; font-weight:600;">
                No matching data
            </div>
            </div>
        </div>
    </div>
</div>
</div>

<script>
document.querySelector('.menu-toggle')
    .addEventListener('click', () => {
        document.querySelector('.nav-links').classList.toggle('show');
    });
</script>

<!-- TABLE LIVE SEARCH & REORDER SCRIPT (Desktop table only) -->
<script>
document.addEventListener("DOMContentLoaded", () => {
    const searchInput = document.getElementById("requestSearch");
    const table = document.querySelector("table");
    if (!table || !searchInput) return;

    const tbody = table.querySelector("tbody");
    // Exclude no-result row from movable rows.
    const rows = Array.from(tbody.querySelectorAll("tr"))
        .filter(r => r.id !== "noRequestResult");
    const noResultRow = document.getElementById("noRequestResult");

    searchInput.addEventListener("input", () => {
        const query = searchInput.value.toLowerCase().trim();
        let matches = [];

        // Reset: show all, restore original order
        if (query === "") {
            rows.forEach(row => {
                row.style.display = "";
                tbody.appendChild(row);
            });
            if (noResultRow) noResultRow.style.display = "none";
            return;
        }

        // Search and mark matches
        rows.forEach(row => {
            // Only search visible text content (all columns)
            const rowText = row.innerText.toLowerCase();
            if (rowText.includes(query)) {
                matches.push(row);
                row.style.display = "";
            } else {
                row.style.display = "none";
            }
        });

        // Move all matching rows to the top (in entered order)
        // Insert from last match upward so top-most will be first
        // (reversed order would place last match at top, which is less natural than unshift+appendChild order)
        matches.forEach(row => tbody.insertBefore(row, tbody.firstChild));

        // Show/hide "No matching data"
        if (noResultRow) noResultRow.style.display = matches.length === 0 ? "" : "none";
    });
});
</script>

<!-- MOBILE CARD SEARCH + REORDER SCRIPT -->
<script>
document.addEventListener("DOMContentLoaded", () => {
    const searchInput = document.getElementById("requestSearch");
    const mobileList = document.querySelector(".mobile-maintenance-list");
    const cards = Array.from(document.querySelectorAll(".mobile-maintenance-list .report-card"))
        .filter(card => card.id !== "noMobileResult");
    const noMobileResult = document.getElementById("noMobileResult");

    if (!searchInput || cards.length === 0) return;

    searchInput.addEventListener("input", () => {
        const query = searchInput.value.toLowerCase().trim();
        let matches = [];

        // Reset state
        if (query === "") {
            cards.forEach(card => {
                card.style.display = "";
                mobileList.appendChild(card);
            });
            if (noMobileResult) noMobileResult.style.display = "none";
            return;
        }

        // Search cards
        cards.forEach(card => {
            const cardText = card.innerText.toLowerCase();
            if (cardText.includes(query)) {
                matches.push(card);
                card.style.display = "";
            } else {
                card.style.display = "none";
            }
        });

        // Move matching cards to top
        matches.forEach(card => {
            mobileList.insertBefore(card, mobileList.firstChild);
        });

        // Show / hide "No matching data"
        if (noMobileResult) {
            noMobileResult.style.display = matches.length === 0 ? "" : "none";
        }
    });
});
</script>

</body>
</html>