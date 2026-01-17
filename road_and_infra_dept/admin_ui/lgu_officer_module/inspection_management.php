<?php
// Inspection Management - LGU Officer Module
session_start();
require_once '../config/auth.php';
require_once '../config/database.php';

$auth->requireAnyRole(['lgu_officer', 'admin']);

// Extended Mock data for demonstration (In a real app, this would come from a database)
$inspections = [
    [
        'id' => 'INSP-1023', 
        'report_id' => 'DR-2025-001',
        'location' => 'Main Road, Brgy. 3', 
        'date' => 'Dec 10, 2025', 
        'status' => 'Pending',
        'severity' => 'High',
        'cost' => '₱45,000.00',
        'reporter' => 'Juan Dela Cruz',
        'coordinates' => '14.5995° N, 120.9842° E',
        'description' => 'Large pothole on the main road causing traffic issues. The damage is approximately 2 meters wide and 30cm deep. Water accumulation during rainy days makes it dangerous for vehicles.',
        'images' => ['damage_1.jpg', 'damage_2.jpg']
    ],
    [
        'id' => 'INSP-1024', 
        'report_id' => 'DR-2025-002',
        'location' => 'Market Street', 
        'date' => 'Dec 11, 2025', 
        'status' => 'Approved',
        'severity' => 'Medium',
        'cost' => '₱12,500.00',
        'reporter' => 'Maria Santos',
        'coordinates' => '14.6010° N, 120.9890° E',
        'description' => 'Cracks along the side of the road near the market entrance. Potential hazard for pedestrians and light vehicles.',
        'images' => ['damage_3.jpg']
    ],
];

$repairs = [
    ['id' => 'REP-552', 'inspection' => 'INSP-1024', 'assigned_to' => 'Engineer Cruz', 'progress' => 60, 'status' => 'In Progress'],
    ['id' => 'REP-553', 'inspection' => 'INSP-1019', 'assigned_to' => 'Engineer Reyes', 'progress' => 100, 'status' => 'Completed'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inspection & Workflow | LGU Officer</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url("https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap");
        
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --glass-bg: rgba(255, 255, 255, 0.85);
            --glass-border: rgba(255, 255, 255, 0.4);
            --text-main: #1e293b;
            --text-muted: #64748b;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Inter", sans-serif;
        }

        body {
            height: 100vh;
            background: url('../user_and_access_management_module/assets/img/cityhall.jpeg') center/cover no-repeat fixed;
            position: relative;
            overflow: hidden;
            color: var(--text-main);
        }

        body::before {
            content: "";
            position: absolute;
            inset: 0;
            backdrop-filter: blur(8px);
            background: rgba(15, 23, 42, 0.45);
            z-index: 0;
        }

        .main-content {
            position: relative;
            margin-left: 250px;
            height: 100vh;
            padding: 30px 40px;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            z-index: 1;
        }

        /* Module Header */
        .module-header {
            color: white;
            margin-bottom: 30px;
        }

        .module-header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .module-header p {
            font-size: 1rem;
            opacity: 0.9;
            letter-spacing: 0.5px;
        }

        .header-divider {
            border: none;
            height: 1px;
            background: rgba(255, 255, 255, 0.3);
            margin: 15px 0;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            border: 1px solid var(--glass-border);
            padding: 25px;
            position: relative;
            overflow: hidden;
        }

        .stat-card::after {
            content: "";
            position: absolute;
            top: -10px;
            right: -10px;
            width: 60px;
            height: 60px;
            background: rgba(37, 99, 235, 0.1);
            border-radius: 50%;
        }

        .stat-label {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.75rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            margin-bottom: 15px;
        }

        .stat-label i {
            font-size: 1rem;
            color: var(--primary);
        }

        .stat-value {
            font-size: 2.2rem;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .stat-value i.trend {
            font-size: 1.2rem;
            color: #94a3b8;
        }

        /* Tables Card Style */
        .content-card {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            border: 1px solid var(--glass-border);
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }

        .content-card h2 {
            font-size: 1.4rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .content-card h2 i {
            color: var(--primary);
        }

        /* Custom Table Styling */
        .custom-table {
            width: 100%;
            border-collapse: collapse;
        }

        .custom-table th {
            text-align: left;
            padding: 12px 15px;
            font-size: 0.75rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            border-bottom: 1px solid #f1f5f9;
        }

        .custom-table td {
            padding: 18px 15px;
            font-size: 0.95rem;
            color: #334155;
            border-bottom: 1px solid #f1f5f9;
        }

        .custom-table tr:last-child td {
            border-bottom: none;
        }

        .id-text {
            font-weight: 700;
            color: #0f172a;
        }

        .loc-text {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .loc-text i {
            color: var(--primary);
            font-size: 0.9rem;
        }

        /* Status Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .badge-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-approved {
            background: #dcfce7;
            color: #166534;
        }

        .badge-progress {
            background: #dbeafe;
            color: #1e40af;
        }

        /* Action Buttons */
        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 18px;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            color: white;
            background: #2563eb;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: 0.2s;
        }

        .btn-action:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
        }

        /* Progress Bar */
        .progress-container {
            display: flex;
            align-items: center;
            gap: 12px;
            width: 180px;
        }

        .progress-bar-bg {
            flex: 1;
            height: 8px;
            background: #f1f5f9;
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-bar-fill {
            height: 100%;
            background: #2563eb;
            border-radius: 10px;
        }

        .progress-text {
            font-size: 0.9rem;
            font-weight: 700;
            color: #1e293b;
            min-width: 40px;
        }

        /* Engineer Assignment */
        .assigned-box {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .assigned-box i {
            color: #3b82f6;
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-container {
            background: white;
            width: 100%;
            max-width: 900px;
            max-height: 90vh;
            border-radius: 20px;
            overflow-y: auto;
            position: relative;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            display: flex;
            flex-direction: column;
        }

        .modal-header {
            padding: 24px 32px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8fafc;
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .modal-title i {
            color: var(--primary);
        }

        .modal-close {
            background: none;
            border: none;
            color: #94a3b8;
            font-size: 1.5rem;
            cursor: pointer;
            transition: color 0.2s;
        }

        .modal-close:hover {
            color: #1e293b;
        }

        .modal-body {
            padding: 32px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px 40px;
        }

        .detail-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .detail-label {
            font-size: 0.75rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .detail-value {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1e293b;
        }

        .full-width {
            grid-column: 1 / -1;
        }

        .description-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            color: #475569;
            line-height: 1.6;
            font-size: 0.95rem;
        }

        .image-gallery {
            display: flex;
            gap: 16px;
            margin-top: 12px;
        }

        .gallery-item {
            width: 200px;
            height: 140px;
            border-radius: 12px;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            border: 1px solid #e2e8f0;
            font-size: 1.5rem;
            overflow: hidden;
        }

        .gallery-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .severity-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.8rem;
            text-transform: uppercase;
        }

        .severity-high { background: #fee2e2; color: #dc2626; }
        .severity-medium { background: #fef3c7; color: #d97706; }
        .severity-low { background: #dcfce7; color: #16a34a; }
    </style>
</head>
<body>
    <?php include '../sidebar/sidebar.php'; ?>

    <main class="main-content">
        <header class="module-header">
            <h1><i class="fas fa-clipboard-check"></i> Inspection & Workflow</h1>
            <p>Manage inspection activities, approvals, and repair progress in real-time.</p>
            <hr class="header-divider">
        </header>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label"><i class="fas fa-clipboard-list"></i> Total Inspections</div>
                <div class="stat-value">128 <i class="fas fa-chart-line trend"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-label"><i class="fas fa-clock"></i> Pending Approvals</div>
                <div class="stat-value">14 <i class="fas fa-hourglass-half trend"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-label"><i class="fas fa-tools"></i> Repairs in Progress</div>
                <div class="stat-value">9 <i class="fas fa-cog trend"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-label"><i class="fas fa-check-circle"></i> Completed Repairs</div>
                <div class="stat-value">105 <i class="fas fa-trophy trend"></i></div>
            </div>
        </div>

        <!-- Inspection Reports -->
        <div class="content-card">
            <h2><i class="fas fa-file-alt"></i> Inspection Reports</h2>
            <table class="custom-table">
                <thead>
                    <tr>
                        <th width="120"># ID</th>
                        <th><i class="fas fa-map-marker-alt"></i> Location</th>
                        <th width="150"><i class="fas fa-calendar-alt"></i> Date</th>
                        <th width="150"><i class="fas fa-info-circle"></i> Status</th>
                        <th width="120"><i class="fas fa-cog"></i> Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inspections as $ins): ?>
                    <tr>
                        <td class="id-text"><?php echo $ins['id']; ?></td>
                        <td><div class="loc-text"><i class="fas fa-map-pin"></i> <?php echo $ins['location']; ?></div></td>
                        <td><?php echo $ins['date']; ?></td>
                        <td>
                            <span class="badge badge-<?php echo strtolower($ins['status']); ?>">
                                <i class="fas <?php echo $ins['status'] === 'Pending' ? 'fa-clock' : 'fa-check'; ?>"></i>
                                <?php echo $ins['status']; ?>
                            </span>
                        </td>
                        <td>
                            <button class="btn-action" onclick='viewInspection(<?php echo json_encode($ins); ?>)'>
                                <i class="fas fa-eye"></i> <?php echo $ins['status'] === 'Pending' ? 'Review' : 'View'; ?>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Repair Workflow -->
        <div class="content-card">
            <h2><i class="fas fa-tasks"></i> Repair Workflow</h2>
            <table class="custom-table">
                <thead>
                    <tr>
                        <th width="120"># Task ID</th>
                        <th><i class="fas fa-clipboard-check"></i> Inspection</th>
                        <th><i class="fas fa-user-hard-hat"></i> Assigned To</th>
                        <th width="220"><i class="fas fa-chart-bar"></i> Progress</th>
                        <th width="150"><i class="fas fa-info-circle"></i> Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($repairs as $rep): ?>
                    <tr>
                        <td class="id-text"><?php echo $rep['id']; ?></td>
                        <td><?php echo $rep['inspection']; ?></td>
                        <td>
                            <div class="assigned-box">
                                <i class="fas fa-user-circle"></i> <?php echo $rep['assigned_to']; ?>
                            </div>
                        </td>
                        <td>
                            <div class="progress-container">
                                <div class="progress-text"><?php echo $rep['progress']; ?>%</div>
                                <div class="progress-bar-bg">
                                    <div class="progress-bar-fill" style="width: <?php echo $rep['progress']; ?>%;"></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="badge badge-<?php echo str_replace(' ', '', strtolower($rep['status'])); ?>">
                                <i class="fas <?php echo $rep['status'] === 'In Progress' ? 'fa-spinner fa-spin' : 'fa-check-circle'; ?>"></i>
                                <?php echo $rep['status']; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <!-- Inspection Details Modal -->
    <div class="modal-overlay" id="inspectionModal">
        <div class="modal-container">
            <div class="modal-header">
                <h2 class="modal-title">
                    <i class="fas fa-road"></i> Road Damage Report Details
                </h2>
                <button class="modal-close" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="detail-group">
                    <span class="detail-label"><i class="fas fa-hashtag"></i> Report ID</span>
                    <span class="detail-value" id="modal-report-id">--</span>
                </div>
                <div class="detail-group">
                    <span class="detail-label"><i class="fas fa-clipboard-check"></i> Inspection ID</span>
                    <span class="detail-value" id="modal-inspection-id">--</span>
                </div>
                <div class="detail-group full-width">
                    <span class="detail-label"><i class="fas fa-map-marker-alt"></i> Location</span>
                    <span class="detail-value" id="modal-location">--</span>
                </div>
                <div class="detail-group">
                    <span class="detail-label"><i class="fas fa-exclamation-triangle"></i> Severity</span>
                    <div id="modal-severity">
                        <span class="severity-badge severity-high"><i class="fas fa-burn"></i> High</span>
                    </div>
                </div>
                <div class="detail-group">
                    <span class="detail-label"><i class="fas fa-info-circle"></i> Status</span>
                    <span class="detail-value" id="modal-status">--</span>
                </div>
                <div class="detail-group">
                    <span class="detail-label"><i class="fas fa-calendar-alt"></i> Reported Date</span>
                    <span class="detail-value" id="modal-date">--</span>
                </div>
                <div class="detail-group">
                    <span class="detail-label"><i class="fas fa-dollar-sign"></i> Estimated Cost</span>
                    <span class="detail-value" id="modal-cost">--</span>
                </div>
                <div class="detail-group">
                    <span class="detail-label"><i class="fas fa-user"></i> Reporter</span>
                    <span class="detail-value" id="modal-reporter">--</span>
                </div>
                <div class="detail-group">
                    <span class="detail-label"><i class="fas fa-globe"></i> Coordinates</span>
                    <span class="detail-value" id="modal-coordinates">--</span>
                </div>
                
                <div class="detail-group full-width">
                    <span class="detail-label"><i class="fas fa-align-left"></i> Description</span>
                    <div class="description-box" id="modal-description">
                        --
                    </div>
                </div>

                <div class="detail-group full-width">
                    <span class="detail-label"><i class="fas fa-images"></i> Images</span>
                    <div class="image-gallery" id="modal-gallery">
                        <!-- Images will be inserted here -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function viewInspection(data) {
            document.getElementById('modal-report-id').textContent = data.report_id;
            document.getElementById('modal-inspection-id').textContent = data.id;
            document.getElementById('modal-location').textContent = data.location;
            document.getElementById('modal-status').textContent = data.status;
            document.getElementById('modal-date').textContent = data.date;
            document.getElementById('modal-cost').textContent = data.cost;
            document.getElementById('modal-reporter').textContent = data.reporter;
            document.getElementById('modal-coordinates').textContent = data.coordinates;
            document.getElementById('modal-description').textContent = data.description;

            // Handle Severity Badge
            const severityContainer = document.getElementById('modal-severity');
            const sev = data.severity.toLowerCase();
            const icon = sev === 'high' ? 'fa-burn' : (sev === 'medium' ? 'fa-exclamation' : 'fa-info');
            severityContainer.innerHTML = `<span class="severity-badge severity-${sev}"><i class="fas ${icon}"></i> ${data.severity}</span>`;

            // Handle Gallery
            const gallery = document.getElementById('modal-gallery');
            gallery.innerHTML = '';
            data.images.forEach(img => {
                gallery.innerHTML += `
                    <div class="gallery-item">
                        <i class="fas fa-image"></i>
                    </div>
                `;
            });

            document.getElementById('inspectionModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('inspectionModal').style.display = 'none';
        }

        // Close when clicking overlay
        document.getElementById('inspectionModal').addEventListener('click', (e) => {
            if (e.target === document.getElementById('inspectionModal')) {
                closeModal();
            }
        });
    </script>
</body>
</html>

