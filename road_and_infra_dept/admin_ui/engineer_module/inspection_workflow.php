<?php
// Start session and include authentication
session_start();
require_once '../config/auth.php';
require_once '../config/database.php';

// Require engineer role to access this page
$auth->requireRole('engineer');

// Log page access
$auth->logActivity('page_access', 'Accessed inspection and workflow module');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inspection & Workflow | Engineer Portal</title>
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

        /* Content Card */
        .content-card {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            border: 1px solid var(--glass-border);
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .content-card h2 {
            color: var(--text-main);
            margin-bottom: 20px;
            font-size: 1.5rem;
        }

        .content-card p {
            color: var(--text-muted);
            line-height: 1.6;
            margin-bottom: 15px;
        }

        /* Stats Cards Container */
        .stats-cards-container {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            border: 1px solid var(--glass-border);
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }

        .stat-card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }

        .stat-icon {
            color: var(--primary);
            font-size: 1.2rem;
        }

        .stat-title {
            color: var(--text-muted);
            font-size: 0.85rem;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .stat-card-body {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .stat-number {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--text-main);
        }

        .stat-trend-icon {
            color: var(--primary);
            font-size: 1rem;
            opacity: 0.7;
        }

        /* Table Styles */
        .table-container {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            border: 1px solid var(--glass-border);
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .table-container h2 {
            color: var(--text-main);
            margin-bottom: 20px;
            font-size: 1.5rem;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            background: rgba(37, 99, 235, 0.1);
            color: var(--primary);
            padding: 12px;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
            border-bottom: 2px solid rgba(37, 99, 235, 0.2);
        }

        .data-table td {
            padding: 12px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            color: var(--text-main);
        }

        .data-table tr:hover {
            background: rgba(37, 99, 235, 0.05);
        }

        /* Status Indicators */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .status-pending {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
        }

        .status-approved {
            background: rgba(34, 197, 94, 0.1);
            color: #22c55e;
        }

        .status-rejected {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }

        .status-progress {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }

        .status-completed {
            background: rgba(34, 197, 94, 0.1);
            color: #22c55e;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: currentColor;
        }

        /* Progress Bar */
        .progress-bar {
            width: 100px;
            height: 6px;
            background: rgba(0, 0, 0, 0.1);
            border-radius: 3px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: var(--primary);
            border-radius: 3px;
        }

        /* Action Buttons */
        .btn-action {
            padding: 6px 16px;
            border: none;
            border-radius: 6px;
            font-size: 0.85rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s;
        }

        .btn-review {
            background: var(--primary);
            color: white;
        }

        .btn-view {
            background: rgba(37, 99, 235, 0.1);
            color: var(--primary);
        }

        .btn-action:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .btn-review:hover {
            background: #1d4ed8;
            color: white;
        }

        .btn-view:hover {
            background: var(--primary);
            color: white;
        }

        /* Add pulse animation for pending inspections */
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(245, 158, 11, 0); }
            100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0); }
        }

        .status-pending {
            animation: pulse 2s infinite;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: white;
            margin: 2% auto;
            padding: 0;
            border-radius: 16px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            background: var(--primary);
            color: white;
            padding: 20px 30px;
            border-radius: 16px 16px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 1.3rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 5px;
            border-radius: 4px;
            transition: background 0.3s ease;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .modal-body {
            padding: 30px;
        }

        .modal-footer {
            padding: 20px 30px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            background: #f8fafc;
            border-radius: 0 0 16px 16px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-main);
        }

        .form-control {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: border-color 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .inspection-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .detail-card {
            background: #f8fafc;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid var(--primary);
        }

        .detail-label {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 5px;
        }

        .detail-value {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-main);
        }

        .severity-high { color: var(--danger); }
        .severity-medium { color: var(--warning); }
        .severity-low { color: var(--success); }

        .photos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .photo-item {
            background: #f8fafc;
            border-radius: 8px;
            padding: 10px;
            text-align: center;
            border: 1px solid #e2e8f0;
        }

        .photo-placeholder {
            width: 100%;
            height: 100px;
            background: #e2e8f0;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        /* Scrollbar Styling */
        .main-content::-webkit-scrollbar { width: 10px; }
        .main-content::-webkit-scrollbar-track { background: rgba(255, 255, 255, 0.1); }
        .main-content::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 10px;
            border: 2px solid transparent;
            background-clip: content-box;
        }
        .main-content::-webkit-scrollbar-thumb:hover { background: #555; background-clip: content-box; }
    </style>
</head>
<body>
    <?php include 'sidebar_engineer.php'; ?>

    <div class="main-content">
        <header class="module-header">
            <h1><i class="fas fa-clipboard-check"></i> Inspection & Workflow</h1>
            <div style="background: rgba(255,255,255,0.1); padding: 5px 15px; border-radius: 20px; color: #fff; font-size: 0.8rem; display: inline-block; margin-top: 5px;">
                <i class="fas fa-tasks"></i> Process Management
            </div>
            <p style="margin-top: 10px;">Manage inspection schedules and workflow processes</p>
            <hr class="header-divider">
        </header>

        <!-- Stats Cards -->
        <div class="stats-cards-container">
            <div class="stat-card">
                <div class="stat-card-header">
                    <i class="fas fa-clipboard-list stat-icon"></i>
                    <span class="stat-title">TOTAL INSPECTIONS</span>
                </div>
                <div class="stat-card-body">
                    <span class="stat-number">128</span>
                    <i class="fas fa-chart-line stat-trend-icon"></i>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-card-header">
                    <i class="fas fa-hourglass-half stat-icon"></i>
                    <span class="stat-title">PENDING APPROVALS</span>
                </div>
                <div class="stat-card-body">
                    <span class="stat-number">14</span>
                    <i class="fas fa-hourglass stat-trend-icon"></i>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-card-header">
                    <i class="fas fa-tools stat-icon"></i>
                    <span class="stat-title">REPAIRS IN PROGRESS</span>
                </div>
                <div class="stat-card-body">
                    <span class="stat-number">9</span>
                    <i class="fas fa-cog stat-trend-icon"></i>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-card-header">
                    <i class="fas fa-check-circle stat-icon"></i>
                    <span class="stat-title">COMPLETED REPAIRS</span>
                </div>
                <div class="stat-card-body">
                    <span class="stat-number">105</span>
                    <i class="fas fa-trophy stat-trend-icon"></i>
                </div>
            </div>
        </div>

        <!-- Inspection Reports Table -->
        <div class="table-container">
            <h2>Inspection Reports</h2>
            <div id="inspectionsLoading" style="text-align: center; padding: 40px;">
                <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: var(--primary); margin-bottom: 10px; display: block;"></i>
                <p style="color: var(--text-muted);">Loading inspections...</p>
            </div>
            <table class="data-table" id="inspectionsTable" style="display: none;">
                <thead>
                    <tr>
                        <th>#ID</th>
                        <th>Location</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="inspectionsTableBody">
                    <!-- Dynamic content will be loaded here -->
                </tbody>
            </table>
        </div>

        <!-- Repair Workflow Table -->
        <div class="table-container">
            <h2>Repair Workflow</h2>
            <div id="repairTasksLoading" style="text-align: center; padding: 40px;">
                <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: var(--primary); margin-bottom: 10px; display: block;"></i>
                <p style="color: var(--text-muted);">Loading repair tasks...</p>
            </div>
            <table class="data-table" id="repairTasksTable" style="display: none;">
                <thead>
                    <tr>
                        <th>#Task ID</th>
                        <th>Inspection</th>
                        <th>Assigned To</th>
                        <th>Progress</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="repairTasksTableBody">
                    <!-- Dynamic content will be loaded here -->
                </tbody>
            </table>
        </div>
    </div>

    <!-- Review Modal -->
    <div id="reviewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-clipboard-check"></i>
                    Review Inspection
                </h3>
                <button class="modal-close" onclick="closeModal('reviewModal')">&times;</button>
            </div>
            <form id="reviewForm" onsubmit="handleReviewSubmit(event)">
                <div class="modal-body">
                    <input type="hidden" id="reviewInspectionId" name="inspection_id">
                    
                    <div class="inspection-details-grid">
                        <div class="detail-card">
                            <div class="detail-label">Inspection ID</div>
                            <div class="detail-value" id="reviewId">INSP-1023</div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">Location</div>
                            <div class="detail-value" id="reviewLocation">Main Road, Brgy. 3</div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">Inspection Date</div>
                            <div class="detail-value" id="reviewDate">Dec 10, 2025</div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">Inspector</div>
                            <div class="detail-value" id="reviewInspector">Inspector Santos</div>
                        </div>
                    </div>

                    <div style="margin-bottom: 25px;">
                        <h4 style="margin-bottom: 10px; color: var(--text-main);">Description</h4>
                        <p style="color: var(--text-muted); line-height: 1.6;" id="reviewDescription">
                            Large pothole approximately 2 feet in diameter causing traffic hazards. Immediate repair recommended.
                        </p>
                    </div>

                    <div style="margin-bottom: 25px;">
                        <h4 style="margin-bottom: 15px; color: var(--text-main);">Inspection Photos</h4>
                        <div class="photos-grid">
                            <div class="photo-item">
                                <div class="photo-placeholder">
                                    <i class="fas fa-image" style="font-size: 1.5rem;"></i>
                                </div>
                                <small style="color: var(--text-muted);">pothole1.jpg</small>
                            </div>
                            <div class="photo-item">
                                <div class="photo-placeholder">
                                    <i class="fas fa-image" style="font-size: 1.5rem;"></i>
                                </div>
                                <small style="color: var(--text-muted);">pothole2.jpg</small>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Priority Assessment</label>
                        <select name="priority" class="form-control" required>
                            <option value="low">Low Priority</option>
                            <option value="medium" selected>Medium Priority</option>
                            <option value="high">High Priority</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Estimated Repair Cost (₱)</label>
                        <input type="number" name="estimated_cost" class="form-control" placeholder="0.00" step="0.01" min="0">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Review Notes</label>
                        <textarea name="notes" class="form-control" placeholder="Add your review notes, recommendations, or additional observations..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" onclick="handleReviewAction('reject')">
                        <i class="fas fa-times"></i> Reject Inspection
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check"></i> Approve & Create Task
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-eye"></i>
                    Inspection Details
                </h3>
                <button class="modal-close" onclick="closeModal('viewModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="inspection-details-grid">
                    <div class="detail-card">
                        <div class="detail-label">Inspection ID</div>
                        <div class="detail-value" id="viewId">INSP-1024</div>
                    </div>
                    <div class="detail-card">
                        <div class="detail-label">Location</div>
                        <div class="detail-value" id="viewLocation">Market Street</div>
                    </div>
                    <div class="detail-card">
                        <div class="detail-label">Inspection Date</div>
                        <div class="detail-value" id="viewDate">Dec 11, 2025</div>
                    </div>
                    <div class="detail-card">
                        <div class="detail-label">Inspector</div>
                        <div class="detail-value" id="viewInspector">Inspector Reyes</div>
                    </div>
                    <div class="detail-card">
                        <div class="detail-label">Status</div>
                        <div class="detail-value">
                            <span class="status-badge status-approved">
                                <i class="fas fa-check-circle"></i>
                                Approved
                            </span>
                        </div>
                    </div>
                    <div class="detail-card">
                        <div class="detail-label">Priority</div>
                        <div class="detail-value severity-low">Low Priority</div>
                    </div>
                    <div class="detail-card">
                        <div class="detail-label">Estimated Cost</div>
                        <div class="detail-value">₱5,000.00</div>
                    </div>
                    <div class="detail-card">
                        <div class="detail-label">Estimated Damage</div>
                        <div class="detail-value">Surface crack requiring sealant application</div>
                    </div>
                </div>

                <div style="margin-bottom: 25px;">
                    <h4 style="margin-bottom: 10px; color: var(--text-main);">Description</h4>
                    <p style="color: var(--text-muted); line-height: 1.6;" id="viewDescription">
                        Minor crack in road surface approximately 1 meter long. No immediate danger but should be monitored.
                    </p>
                </div>

                <div style="margin-bottom: 25px; background: #f8fafc; padding: 20px; border-radius: 12px; border-left: 4px solid var(--success);">
                    <h4 style="margin-bottom: 15px; color: var(--text-main);">
                        <i class="fas fa-clipboard-check" style="color: var(--success); margin-right: 8px;"></i>
                        Review Information
                    </h4>
                    <div style="margin-bottom: 15px;">
                        <strong>Reviewed by:</strong> Engineer Cruz
                    </div>
                    <div style="margin-bottom: 15px;">
                        <strong>Review Date:</strong> Dec 12, 2025
                    </div>
                    <div>
                        <strong>Review Notes:</strong>
                        <p style="margin-top: 8px; color: var(--text-muted); line-height: 1.6;">
                            Approved for routine maintenance. Schedule for next maintenance cycle.
                        </p>
                    </div>
                </div>

                <div style="margin-bottom: 25px;">
                    <h4 style="margin-bottom: 15px; color: var(--text-main);">Inspection Photos</h4>
                    <div class="photos-grid">
                        <div class="photo-item">
                            <div class="photo-placeholder">
                                <i class="fas fa-image" style="font-size: 1.5rem;"></i>
                            </div>
                            <small style="color: var(--text-muted);">crack1.jpg</small>
                        </div>
                        <div class="photo-item">
                            <div class="photo-placeholder">
                                <i class="fas fa-image" style="font-size: 1.5rem;"></i>
                            </div>
                            <small style="color: var(--text-muted);">crack2.jpg</small>
                        </div>
                    </div>
                </div>

                <div style="margin-bottom: 25px;">
                    <h4 style="margin-bottom: 15px; color: var(--text-main);">
                        <i class="fas fa-tools" style="color: var(--success); margin-right: 8px;"></i>
                        Related Repair Task
                    </h4>
                    <div style="background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%); border-radius: 12px; padding: 20px; border-left: 4px solid var(--success);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                            <div style="font-size: 1.1rem; font-weight: 700; color: var(--text-main);">REP-552</div>
                            <div style="background: rgba(34, 197, 94, 0.1); color: #22c55e; padding: 4px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: 600;">
                                <i class="fas fa-clock"></i>
                                Pending
                            </div>
                        </div>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                            <div>
                                <div style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 5px;">Assigned To</div>
                                <div style="font-weight: 600;">Maintenance Team A</div>
                            </div>
                            <div>
                                <div style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 5px;">Created Date</div>
                                <div style="font-weight: 600;">Dec 12, 2025</div>
                            </div>
                            <div>
                                <div style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 5px;">Est. Completion</div>
                                <div style="font-weight: 600;">Dec 20, 2025</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('viewModal')">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <script>
        // Global data storage
        let inspectionsData = {};
        let repairTasksData = {};

        // Load data from database when page loads
        document.addEventListener('DOMContentLoaded', function() {
            loadInspections();
            loadRepairTasks();
            updateStats();
        });

        // Load inspections from API
        async function loadInspections() {
            try {
                const response = await fetch('api/get_inspections.php');
                const inspections = await response.json();
                
                if (response.ok) {
                    inspectionsData = {};
                    inspections.forEach(inspection => {
                        inspectionsData[inspection.inspection_id] = inspection;
                    });
                    
                    renderInspectionsTable(inspections);
                    document.getElementById('inspectionsLoading').style.display = 'none';
                    document.getElementById('inspectionsTable').style.display = 'table';
                } else {
                    throw new Error(inspections.error || 'Failed to load inspections');
                }
            } catch (error) {
                console.error('Error loading inspections:', error);
                document.getElementById('inspectionsLoading').innerHTML = `
                    <i class="fas fa-exclamation-triangle" style="font-size: 2rem; color: var(--danger); margin-bottom: 10px; display: block;"></i>
                    <p style="color: var(--danger);">Failed to load inspections</p>
                `;
            }
        }

        // Load repair tasks from API
        async function loadRepairTasks() {
            try {
                const response = await fetch('api/get_repair_tasks.php');
                const tasks = await response.json();
                
                if (response.ok) {
                    repairTasksData = {};
                    tasks.forEach(task => {
                        repairTasksData[task.task_id] = task;
                    });
                    
                    renderRepairTasksTable(tasks);
                    document.getElementById('repairTasksLoading').style.display = 'none';
                    document.getElementById('repairTasksTable').style.display = 'table';
                } else {
                    throw new Error(tasks.error || 'Failed to load repair tasks');
                }
            } catch (error) {
                console.error('Error loading repair tasks:', error);
                document.getElementById('repairTasksLoading').innerHTML = `
                    <i class="fas fa-exclamation-triangle" style="font-size: 2rem; color: var(--danger); margin-bottom: 10px; display: block;"></i>
                    <p style="color: var(--danger);">Failed to load repair tasks</p>
                `;
            }
        }

        // Render inspections table
        function renderInspectionsTable(inspections) {
            const tbody = document.getElementById('inspectionsTableBody');
            tbody.innerHTML = '';
            
            inspections.forEach(inspection => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td><strong>${inspection.inspection_id}</strong></td>
                    <td>${inspection.location}</td>
                    <td>${inspection.date}</td>
                    <td>
                        ${getStatusBadge(inspection.status)}
                    </td>
                    <td>
                        ${getActionButton(inspection)}
                    </td>
                `;
                tbody.appendChild(row);
            });
        }

        // Render repair tasks table
        function renderRepairTasksTable(tasks) {
            const tbody = document.getElementById('repairTasksTableBody');
            tbody.innerHTML = '';
            
            tasks.forEach(task => {
                const progress = getTaskProgress(task.status);
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td><strong>${task.task_id}</strong></td>
                    <td>${task.inspection_id}</td>
                    <td>${task.assigned_to}</td>
                    <td>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: ${progress.percentage}%;"></div>
                        </div>
                        <small style="color: var(--text-muted);">${progress.percentage}%</small>
                    </td>
                    <td>
                        ${getTaskStatusBadge(task.status)}
                    </td>
                `;
                tbody.appendChild(row);
            });
        }

        // Get status badge HTML
        function getStatusBadge(status) {
            const badges = {
                'pending': '<span class="status-badge status-pending"><span class="status-dot"></span>Pending</span>',
                'approved': '<span class="status-badge status-approved"><i class="fas fa-check-circle"></i>Approved</span>',
                'rejected': '<span class="status-badge status-rejected"><i class="fas fa-times-circle"></i>Rejected</span>'
            };
            return badges[status] || badges['pending'];
        }

        // Get action button HTML
        function getActionButton(inspection) {
            if (inspection.status === 'pending') {
                return `<button class="btn-action btn-review" onclick="openReviewModal('${inspection.inspection_id}')">
                    <i class="fas fa-search"></i> Review
                </button>`;
            } else {
                return `<button class="btn-action btn-view" onclick="openViewModal('${inspection.inspection_id}')">
                    <i class="fas fa-eye"></i> View
                </button>`;
            }
        }

        // Get task progress
        function getTaskProgress(status) {
            const progress = {
                'pending': { percentage: 0 },
                'in_progress': { percentage: 60 },
                'completed': { percentage: 100 }
            };
            return progress[status] || progress['pending'];
        }

        // Get task status badge
        function getTaskStatusBadge(status) {
            const badges = {
                'pending': '<span class="status-badge status-pending"><i class="fas fa-clock"></i>Pending</span>',
                'in_progress': '<span class="status-badge status-progress"><i class="fas fa-tools"></i>In Progress</span>',
                'completed': '<span class="status-badge status-completed"><span class="status-dot"></span>Completed</span>'
            };
            return badges[status] || badges['pending'];
        }

        // Update statistics
        function updateStats() {
            const inspections = Object.values(inspectionsData);
            const tasks = Object.values(repairTasksData);
            
            // Update stats cards
            const stats = {
                total: inspections.length,
                pending: inspections.filter(i => i.status === 'pending').length,
                inProgress: tasks.filter(t => t.status === 'in_progress').length,
                completed: tasks.filter(t => t.status === 'completed').length
            };
            
            // Update stat numbers (you can add IDs to the stat cards to update them)
            console.log('Statistics:', stats);
        }

        // Modal functions
        function openReviewModal(inspectionId) {
            const data = inspectionsData[inspectionId];
            if (!data) return;

            // Populate modal with inspection data
            document.getElementById('reviewInspectionId').value = inspectionId;
            document.getElementById('reviewId').textContent = data.inspection_id;
            document.getElementById('reviewLocation').textContent = data.location;
            document.getElementById('reviewDate').textContent = data.date;
            document.getElementById('reviewInspector').textContent = data.inspector;
            document.getElementById('reviewDescription').textContent = data.description;

            // Populate photos
            const photosContainer = document.querySelector('#reviewModal .photos-grid');
            photosContainer.innerHTML = '';
            if (data.photos && data.photos.length > 0) {
                data.photos.forEach(photo => {
                    photosContainer.innerHTML += `
                        <div class="photo-item">
                            <div class="photo-placeholder">
                                <i class="fas fa-image" style="font-size: 1.5rem;"></i>
                            </div>
                            <small style="color: var(--text-muted);">${photo}</small>
                        </div>
                    `;
                });
            }

            // Show modal
            document.getElementById('reviewModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function openViewModal(inspectionId) {
            const data = inspectionsData[inspectionId];
            if (!data) return;

            // Populate modal with inspection data
            document.getElementById('viewId').textContent = data.inspection_id;
            document.getElementById('viewLocation').textContent = data.location;
            document.getElementById('viewDate').textContent = data.date;
            document.getElementById('viewInspector').textContent = data.inspector;
            document.getElementById('viewDescription').textContent = data.description;

            // Show modal
            document.getElementById('viewModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.body.style.overflow = 'auto';
            
            // Reset form if it's the review modal
            if (modalId === 'reviewModal') {
                document.getElementById('reviewForm').reset();
            }
        }

        // Handle review submission
        async function handleReviewSubmit(event) {
            event.preventDefault();
            
            const formData = new FormData(event.target);
            const reviewData = {
                inspection_id: formData.get('inspection_id'),
                action: 'approve',
                priority: formData.get('priority'),
                estimated_cost: formData.get('estimated_cost'),
                notes: formData.get('notes')
            };
            
            try {
                const response = await fetch('api/process_review.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(reviewData)
                });
                
                const result = await response.json();
                
                if (response.ok && result.success) {
                    showNotification(result.message, 'success');
                    closeModal('reviewModal');
                    
                    // Reload data to update the table
                    await loadInspections();
                    await loadRepairTasks();
                    updateStats();
                } else {
                    throw new Error(result.error || 'Failed to process review');
                }
            } catch (error) {
                console.error('Error processing review:', error);
                showNotification('Failed to process review: ' + error.message, 'error');
            }
        }

        // Handle review action (reject)
        async function handleReviewAction(action) {
            const formData = new FormData(document.getElementById('reviewForm'));
            const reviewData = {
                inspection_id: formData.get('inspection_id'),
                action: action,
                notes: formData.get('notes')
            };
            
            if (action === 'reject') {
                if (!confirm('Are you sure you want to reject this inspection?')) {
                    return;
                }
            }
            
            try {
                const response = await fetch('api/process_review.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(reviewData)
                });
                
                const result = await response.json();
                
                if (response.ok && result.success) {
                    showNotification(result.message, 'success');
                    closeModal('reviewModal');
                    
                    // Reload data to update the table
                    await loadInspections();
                    updateStats();
                } else {
                    throw new Error(result.error || 'Failed to process review');
                }
            } catch (error) {
                console.error('Error processing review:', error);
                showNotification('Failed to process review: ' + error.message, 'error');
            }
        }

        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                ${message}
            `;
            
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#2563eb'};
                color: white;
                padding: 15px 20px;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                z-index: 9999;
                display: flex;
                align-items: center;
                gap: 10px;
                font-weight: 500;
                animation: slideIn 0.3s ease;
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => {
                    if (document.body.contains(notification)) {
                        document.body.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        }

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const modals = document.querySelectorAll('.modal');
                modals.forEach(modal => {
                    if (modal.style.display === 'block') {
                        modal.style.display = 'none';
                        document.body.style.overflow = 'auto';
                    }
                });
            }
        });

        // Add CSS animations
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
