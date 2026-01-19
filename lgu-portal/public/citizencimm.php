<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Citizen Dashboard - LGU Portal</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* DASHBOARD SPECIFIC STYLING */
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

        .dashboard-container {
            padding: 100px 0 40px;
            max-width: 100%;
            margin: 0;
            color: #fff;
        }

        .welcome-section {
            margin-bottom: 30px;
            margin-left: 3cm;
            margin-right: 3cm;
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
            margin-left: 3cm;
            margin-right: 3cm;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            padding: 30px;
            border-radius: 18px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            text-align: center;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            background: rgba(255, 255, 255, 0.25);
        }

        .stat-card h3 {
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.8;
            margin-bottom: 10px;
        }

        .stat-card .number {
            font-size: 40px;
            font-weight: 600;
        }

        /* RECENT ACTIVITY TABLE */
        .content-card {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 0;
            padding: 40px 40px;
            color: #333;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            margin: 0 3cm;
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

        th {
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

        .status-pill {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }

        .status-pending { background: #fff3cd; color: #856404; }
        .status-fixed { background: #d4edda; color: #155724; }
        .status-progress { background: #cce5ff; color: #004085; }

        .btn-small {
            padding: 8px 16px;
            font-size: 13px;
        }

        /* NAVBAR - styled like Employee sidebar */
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
            text-decoration: none;   /* ⛔ Removes underline */
            cursor: pointer;
            color: #fff;
            opacity: .8;
            transition: .2s;
        }

        .nav-links a.active {
            opacity: 1;
            text-decoration: none;   /* ⛔ Removes underline */
            font-weight: 600;
        }

        .nav-links a:hover {
            opacity: 1;
            text-decoration: none;
        }

        @media (max-width: 768px) {
            .dashboard-container { padding: 100px 20px 40px; }
            .nav { padding: 18px 20px; }
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
        <a href="citizencimm.php" class="active">Home</a>
        <a href="citizenrepform.php">Report Issue</a>
        <a href="services.php">Services</a>
    </div>
</header>

<div class="dashboard-container">
    <div class="welcome-section">
        <h1>Welcome to InfraGovServices!</h1>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <h3>Repairs</h3>
            <div class="number">10</div>
        </div>
        <div class="stat-card">
            <h3>On-Going Repairs</h3>
            <div class="number">7</div>
        </div>
        <div class="stat-card">
            <h3>Pending</h3>
            <div class="number">13</div>
        </div>
    </div>

    <div class="content-card">
        <div class="card-header">
            <h2>Recent Maintenance Reports</h2>
            <button class="btn-primary btn-small" onclick="location.href='citizenrepform.php'" style="width: auto; margin-top: 0;">+ New Report</button>
        </div>
        
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Location</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Oct 24, 2023</td>
                        <td>Street Lights</td>
                        <td>Poblacion Ward II</td>
                        <td><span class="status-pill status-pending">Pending</span></td>
                        <td><a href="#" class="link">View</a></td>
                    </tr>
                    <tr>
                        <td>Oct 20, 2023</td>
                        <td>Drainage</td>
                        <td>Brgy. San Jose</td>
                        <td><span class="status-pill status-progress">In Progress</span></td>
                        <td><a href="#" class="link">View</a></td>
                    </tr>
                    <tr>
                        <td>Oct 15, 2023</td>
                        <td>Road Repair</td>
                        <td>Mabini St.</td>
                        <td><span class="status-pill status-fixed">Resolved</span></td>
                        <td><a href="#" class="link">View</a></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div></body>
</html>