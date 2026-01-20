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
            max-width: 1200px;
            margin: auto;
            padding: 0 20px;
        }

        .welcome-section {
            margin-bottom: 30px;
        }
        .welcome-section h1 {
            font-size: 2rem;
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
            padding: 40px 40px;
            color: #333;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            transition: all .25s ease;
        }       
        .content-card:hover {
            /* subtle lift effect if needed */
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .table-wrapper {
            overflow-x: auto;
            border-radius: 18px;
            overflow: hidden;
        }
        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            text-align: left;
        }
        thead th {
            position: sticky;
            top: 0;
            background: #f9f9f9;
            z-index: 2;
            padding: 18px;
            border-bottom: 2px solid #eee;
            color: #666;
            font-size: 16px;
        }
        td {
            padding: 20px 18px;
            border-bottom: 1px solid #eee;
            font-size: 16px;
        }
        tbody tr:hover {
            background: #f5f8ff;
        }
        .status-pill {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            display: inline-block;
            min-width: 90px;
            text-align: center;
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
        .card-action:hover {
            text-decoration: underline;
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
            .menu-toggle {
                display: block;
            }
            table { display: none !important; }
            .mobile-maintenance-list { display: block; }
            .content-card {
                padding: 22px 6px;
                border-radius: 12px;
            }
        }
        @media (max-width: 500px) {
            .stat-card { padding: 20px 10px; }
            .stat-icon { font-size: 25px; padding: 8px; }
            .stat-card .number { font-size: 28px; }
            .card-header h2 { font-size: 1.0rem; }
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
            <div class="card-header">
                <h2>Recent Maintenance Reports</h2>
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
                    </tbody>
                </table>
            </div>

            <!-- Mobile Maintenance Cards -->
            <div class="mobile-maintenance-list">
                <?php 
                if (count($maintenance_data) > 0) {
                    foreach ($maintenance_data as $item) {
                        $status_class = 'status-pending';
                        if ($item['status'] === 'Completed') {
                            $status_class = 'status-fixed';
                        } elseif ($item['status'] === 'In Progress') {
                            $status_class = 'status-progress';
                        }
                ?>
                <div class="maintenance-card">
                    <div><strong>Date:</strong> <?= date('M d, Y', strtotime($item['starting_date'])) ?></div>
                    <div><strong>Task:</strong> <?= htmlspecialchars($item['task']) ?></div>
                    <div><strong>Location:</strong> <?= htmlspecialchars($item['location']) ?></div>
                    <div><strong>Budget:</strong> ₱<?= number_format($item['budget'], 2) ?></div>
                    <div class="status-pill <?= $status_class ?>"><?= $item['status'] ?></div>
                    <a href="#" class="card-action">View Details</a>
                </div>
                <?php 
                    }
                } else { ?>
                <div class="maintenance-card" style="text-align:center; color:#999;">
                    No maintenance schedules available
                </div>
                <?php } ?>
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
</body>
</html>