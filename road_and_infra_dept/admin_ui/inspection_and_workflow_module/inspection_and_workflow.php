<?php
// Start session and include authentication
session_start();
require_once '../config/auth.php';

// Require login and engineer/admin role to access this page
$auth->requireAnyRole(['engineer', 'admin']);

// Log page access
$auth->logActivity('page_access', 'Accessed inspection and workflow module');
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Inspection & Workflow | Admin</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
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

    /* ✅ ENABLE SCROLL */
    body {
      min-height: 100vh;
      background: url("assets/img/cityhall.jpeg") center/cover no-repeat fixed;
      position: relative;
      color: var(--text-main);
      overflow: hidden;
    }

    body::before {
      content: "";
      position: fixed;
      inset: 0;
      backdrop-filter: blur(8px);
      background: rgba(15, 23, 42, 0.4);
      z-index: 0;
    }

    /* Sidebar stays fixed */
    .sidebar-nav {
      position: fixed;
      width: 260px;
      height: 100vh;
      z-index: 1000;
    }

    /* ✅ SCROLLABLE CONTENT AREA */
    .main-content {
      position: relative;
      margin-left: 260px;
      height: 100vh;
      padding: 40px 60px;
      display: flex;
      flex-direction: column;
      gap: 24px;
      overflow-y: auto;
      z-index: 1;
    }

    /* Cards */
    .card {
      background: var(--glass-bg);
      backdrop-filter: blur(15px);
      border-radius: 16px;
      border: 1px solid var(--glass-border);
      padding: 28px;
      box-shadow: 0 10px 25px -3px rgba(0, 0, 0, 0.15),
        0 4px 8px -2px rgba(0, 0, 0, 0.08);
      transition: transform 0.2s, box-shadow 0.2s;
    }

    .card:hover {
      transform: translateY(-2px);
      box-shadow: 0 15px 30px -3px rgba(0, 0, 0, 0.2),
        0 6px 12px -2px rgba(0, 0, 0, 0.1);
    }

    .card h2 {
      font-size: 1.25rem;
      font-weight: 600;
      color: var(--text-main);
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .card h2 i {
      color: var(--primary);
      font-size: 1.1rem;
    }

    /* Stats */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 20px;
    }

    .stat-card {
      position: relative;
      overflow: hidden;
    }

    .stat-card::before {
      content: '';
      position: absolute;
      top: 0;
      right: 0;
      width: 60px;
      height: 60px;
      background: linear-gradient(135deg, rgba(37, 99, 235, 0.1), rgba(37, 99, 235, 0.05));
      border-radius: 50%;
      transform: translate(20px, -20px);
    }

    .stat-card h3 {
      font-size: 0.8rem;
      text-transform: uppercase;
      color: var(--text-muted);
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 12px;
    }

    .stat-card h3 i {
      font-size: 0.9rem;
      color: var(--primary);
    }

    .stat-number {
      font-size: 2.25rem;
      font-weight: 700;
      color: var(--text-main);
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .stat-number i {
      font-size: 1.5rem;
      opacity: 0.3;
    }

    /* Tables */
    table {
      width: 100%;
      border-collapse: collapse;
    }

    th {
      text-align: left;
      font-size: 0.75rem;
      text-transform: uppercase;
      color: var(--text-muted);
      padding: 12px;
      border-bottom: 1px solid #e2e8f0;
    }

    td {
      padding: 14px 12px;
      font-size: 0.9rem;
      border-bottom: 1px solid #f1f5f9;
    }

    tr:hover td {
      background: rgba(248, 250, 252, 0.5);
    }

    /* Badges */
    .badge {
      padding: 6px 12px;
      border-radius: 6px;
      font-size: 0.75rem;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      text-transform: capitalize;
    }

    .badge i {
      font-size: 0.7rem;
    }

    .badge-pending {
      background: #fef3c7;
      color: #92400e;
      border: 1px solid rgba(146, 64, 14, 0.2);
    }

    .badge-approved {
      background: #dcfce7;
      color: #166534;
      border: 1px solid rgba(22, 101, 52, 0.2);
    }

    .badge-progress {
      background: #dbeafe;
      color: #1e40af;
      border: 1px solid rgba(30, 64, 175, 0.2);
    }

    .badge-completed {
      background: #dcfce7;
      color: #166534;
      border: 1px solid rgba(22, 101, 52, 0.2);
    }

    /* Buttons */
    .btn-primary {
      padding: 8px 16px;
      border-radius: 8px;
      border: none;
      background: var(--primary);
      color: #fff;
      font-size: 0.85rem;
      font-weight: 500;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.2s;
      box-shadow: 0 2px 4px rgba(37, 99, 235, 0.2);
    }

    .btn-primary:hover {
      background: var(--primary-hover);
      transform: translateY(-1px);
      box-shadow: 0 4px 8px rgba(37, 99, 235, 0.3);
    }

    .btn-primary i {
      font-size: 0.75rem;
    }

    .page-header {
      grid-column: span 2;
      color: white;
      margin-bottom: 10px;
    }

    .page-header h1 {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .page-header h1 i {
      font-size: 1.4rem;
      opacity: 0.9;
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
      background-color: rgba(0, 0, 0, 0.6);
      backdrop-filter: blur(4px);
    }

    .modal-content {
      background: var(--glass-bg);
      backdrop-filter: blur(20px);
      margin: 3% auto;
      padding: 0;
      border-radius: 16px;
      width: 90%;
      max-width: 800px;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
      animation: modalSlideIn 0.3s ease-out;
      max-height: 90vh;
      overflow-y: auto;
      border: 1px solid var(--glass-border);
    }

    @keyframes modalSlideIn {
      from {
        opacity: 0;
        transform: translateY(-30px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .modal-header {
      padding: 24px 30px;
      border-bottom: 1px solid var(--glass-border);
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: linear-gradient(135deg, rgba(37, 99, 235, 0.05), rgba(37, 99, 235, 0.02));
    }

    .modal-header h3 {
      margin: 0;
      color: var(--text-main);
      font-size: 1.5rem;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .modal-header h3 i {
      color: var(--primary);
    }

    .close {
      color: #94a3b8;
      font-size: 24px;
      font-weight: bold;
      cursor: pointer;
      background: none;
      border: none;
      padding: 8px;
      width: 36px;
      height: 36px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
      transition: all 0.2s;
    }

    .close:hover,
    .close:focus {
      color: var(--text-main);
      background: rgba(0, 0, 0, 0.05);
    }

    .modal-body {
      padding: 30px;
    }

    .info-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px;
      margin-bottom: 24px;
    }

    .info-item {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .info-item.full-width {
      grid-column: span 2;
    }

    .info-label {
      font-size: 0.75rem;
      font-weight: 600;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .info-label i {
      font-size: 0.7rem;
      color: var(--primary);
    }

    .info-value {
      font-size: 0.95rem;
      color: var(--text-main);
      font-weight: 500;
    }

    .severity-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 12px;
      border-radius: 6px;
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
    }

    .severity-low {
      background: #dcfce7;
      color: #166534;
      border: 1px solid rgba(22, 101, 52, 0.2);
    }

    .severity-medium {
      background: #fef3c7;
      color: #92400e;
      border: 1px solid rgba(146, 64, 14, 0.2);
    }

    .severity-high {
      background: #fed7aa;
      color: #9a3412;
      border: 1px solid rgba(154, 52, 18, 0.2);
    }

    .severity-critical {
      background: #fee2e2;
      color: #991b1b;
      border: 1px solid rgba(153, 27, 27, 0.2);
    }

    .description-box {
      background: #f8fafc;
      padding: 16px;
      border-radius: 10px;
      border: 1px solid #e2e8f0;
      min-height: 100px;
      font-size: 0.9rem;
      color: var(--text-main);
      line-height: 1.6;
    }

    .image-gallery {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
      gap: 12px;
      margin-top: 12px;
    }

    .image-item {
      width: 100%;
      height: 150px;
      object-fit: cover;
      border-radius: 10px;
      border: 2px solid #e2e8f0;
      cursor: pointer;
      transition: transform 0.2s, border-color 0.2s;
    }

    .image-item:hover {
      transform: scale(1.05);
      border-color: var(--primary);
    }

    .modal-footer {
      padding: 24px 30px;
      border-top: 1px solid var(--glass-border);
      display: flex;
      gap: 12px;
      justify-content: flex-end;
      background: linear-gradient(135deg, rgba(37, 99, 235, 0.02), rgba(37, 99, 235, 0.05));
    }

    .btn-accept {
      padding: 12px 24px;
      border-radius: 8px;
      border: none;
      font-weight: 600;
      color: #fff;
      background: linear-gradient(135deg, #10b981, #059669);
      cursor: pointer;
      font-size: 0.9rem;
      transition: all 0.2s;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
    }

    .btn-accept:hover {
      background: linear-gradient(135deg, #059669, #047857);
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
    }

    .btn-reject {
      padding: 12px 24px;
      border-radius: 8px;
      border: none;
      font-weight: 600;
      color: #fff;
      background: linear-gradient(135deg, #ef4444, #dc2626);
      cursor: pointer;
      font-size: 0.9rem;
      transition: all 0.2s;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
    }

    .btn-reject:hover {
      background: linear-gradient(135deg, #dc2626, #b91c1c);
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
    }

    .btn-cancel {
      padding: 12px 24px;
      border-radius: 8px;
      border: 1px solid #cbd5e1;
      font-weight: 500;
      color: #475569;
      background: #fff;
      cursor: pointer;
      font-size: 0.9rem;
      transition: all 0.2s;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }

    .btn-cancel:hover {
      background: #f1f5f9;
      border-color: #94a3b8;
    }

    /* Table enhancements */
    th {
      font-weight: 600;
    }

    td {
      vertical-align: middle;
    }

    /* Progress bar */
    .progress-bar {
      width: 100%;
      height: 8px;
      background: #e2e8f0;
      border-radius: 4px;
      overflow: hidden;
      margin-top: 4px;
    }

    .progress-fill {
      height: 100%;
      background: linear-gradient(90deg, var(--primary), var(--primary-hover));
      border-radius: 4px;
      transition: width 0.3s;
    }
  </style>
</head>

<body>
  <!-- SIDEBAR (REUSED) -->
  <?php include '../sidebar/sidebar.php'; ?>

  <!-- MAIN CONTENT -->
  <div class="main-content">
    <!-- Header -->

    <header class="page-header">
      <h1 style="font-size: 1.5rem; font-weight: 700">
        <i class="fas fa-clipboard-check"></i> Inspection & Workflow
      </h1>
      <p style="opacity: 0.8; font-size: 0.9rem">
        Manage inspection activities, approvals, and repair progress in
        real-time.
      </p>

      <!-- Divider -->
      <hr class="divider" />
    </header>

    <!-- Stats -->
    <div class="stats-grid">
      <div class="card stat-card">
        <h3><i class="fas fa-clipboard-list"></i> Total Inspections</h3>
        <p class="stat-number">128<i class="fas fa-chart-line"></i></p>
      </div>
      <div class="card stat-card">
        <h3><i class="fas fa-clock"></i> Pending Approvals</h3>
        <p class="stat-number">14<i class="fas fa-hourglass-half"></i></p>
      </div>
      <div class="card stat-card">
        <h3><i class="fas fa-tools"></i> Repairs In Progress</h3>
        <p class="stat-number">9<i class="fas fa-cog fa-spin"></i></p>
      </div>
      <div class="card stat-card">
        <h3><i class="fas fa-check-circle"></i> Completed Repairs</h3>
        <p class="stat-number">105<i class="fas fa-trophy"></i></p>
      </div>
    </div>

    <!-- Inspection Reports -->
    <div class="card">
      <h2><i class="fas fa-file-alt"></i> Inspection Reports</h2>
      <table>
        <thead>
          <tr>
            <th><i class="fas fa-hashtag"></i> ID</th>
            <th><i class="fas fa-map-marker-alt"></i> Location</th>
            <th><i class="fas fa-calendar"></i> Date</th>
            <th><i class="fas fa-info-circle"></i> Status</th>
            <th style="text-align: right"><i class="fas fa-cog"></i> Action</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td><strong>INSP-1023</strong></td>
            <td><i class="fas fa-map-pin" style="color: var(--primary); margin-right: 6px;"></i>Main Road, Brgy. 3</td>
            <td>Dec 10, 2025</td>
            <td><span class="badge badge-pending"><i class="fas fa-clock"></i> Pending</span></td>
            <td style="text-align: right">
              <button class="btn-primary" onclick="openReportModal('INSP-1023', 'pending')">
                <i class="fas fa-eye"></i> Review
              </button>
            </td>
          </tr>
          <tr>
            <td><strong>INSP-1024</strong></td>
            <td><i class="fas fa-map-pin" style="color: var(--primary); margin-right: 6px;"></i>Market Street</td>
            <td>Dec 11, 2025</td>
            <td><span class="badge badge-approved"><i class="fas fa-check"></i> Approved</span></td>
            <td style="text-align: right">
              <button class="btn-primary" onclick="openReportModal('INSP-1024', 'approved')">
                <i class="fas fa-eye"></i> View
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Repair Workflow -->
    <div class="card">
      <h2><i class="fas fa-tasks"></i> Repair Workflow</h2>
      <table>
        <thead>
          <tr>
            <th><i class="fas fa-hashtag"></i> Task ID</th>
            <th><i class="fas fa-clipboard-check"></i> Inspection</th>
            <th><i class="fas fa-user"></i> Assigned To</th>
            <th><i class="fas fa-chart-line"></i> Progress</th>
            <th><i class="fas fa-info-circle"></i> Status</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td><strong>REP-552</strong></td>
            <td>INSP-1024</td>
            <td><i class="fas fa-user-tie" style="color: var(--primary); margin-right: 6px;"></i>Engineer Cruz</td>
            <td>
              <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-weight: 600;">60%</span>
                <div class="progress-bar" style="flex: 1; max-width: 100px;">
                  <div class="progress-fill" style="width: 60%;"></div>
                </div>
              </div>
            </td>
            <td><span class="badge badge-progress"><i class="fas fa-spinner fa-spin"></i> In Progress</span></td>
          </tr>
          <tr>
            <td><strong>REP-553</strong></td>
            <td>INSP-1019</td>
            <td><i class="fas fa-user-tie" style="color: var(--primary); margin-right: 6px;"></i>Engineer Reyes</td>
            <td>
              <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-weight: 600;">100%</span>
                <div class="progress-bar" style="flex: 1; max-width: 100px;">
                  <div class="progress-fill" style="width: 100%;"></div>
                </div>
              </div>
            </td>
            <td><span class="badge badge-completed"><i class="fas fa-check-circle"></i> Completed</span></td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Extra spacing so scroll is obvious -->
    <div style="height: 60px"></div>
  </div>

  <!-- Report Details Modal -->
  <div id="reportModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3><i class="fas fa-road"></i> Road Damage Report Details</h3>
        <span class="close" onclick="closeReportModal()">&times;</span>
      </div>
      <div class="modal-body">
        <div class="info-grid">
          <div class="info-item">
            <span class="info-label"><i class="fas fa-hashtag"></i> Report ID</span>
            <span class="info-value" id="modal-report-id">-</span>
          </div>
          <div class="info-item">
            <span class="info-label"><i class="fas fa-clipboard-check"></i> Inspection ID</span>
            <span class="info-value" id="modal-inspection-id">-</span>
          </div>
          <div class="info-item full-width">
            <span class="info-label"><i class="fas fa-map-marker-alt"></i> Location</span>
            <span class="info-value" id="modal-location">-</span>
          </div>
          <div class="info-item">
            <span class="info-label"><i class="fas fa-exclamation-triangle"></i> Severity</span>
            <span class="info-value" id="modal-severity">-</span>
          </div>
          <div class="info-item">
            <span class="info-label"><i class="fas fa-info-circle"></i> Status</span>
            <span class="info-value" id="modal-status">-</span>
          </div>
          <div class="info-item">
            <span class="info-label"><i class="fas fa-calendar"></i> Reported Date</span>
            <span class="info-value" id="modal-reported-date">-</span>
          </div>
          <div class="info-item">
            <span class="info-label"><i class="fas fa-dollar-sign"></i> Estimated Cost</span>
            <span class="info-value" id="modal-estimated-cost">-</span>
          </div>
          <div class="info-item">
            <span class="info-label"><i class="fas fa-user"></i> Reporter</span>
            <span class="info-value" id="modal-reporter">-</span>
          </div>
          <div class="info-item">
            <span class="info-label"><i class="fas fa-globe"></i> Coordinates</span>
            <span class="info-value" id="modal-coordinates">-</span>
          </div>
          <div class="info-item full-width">
            <span class="info-label"><i class="fas fa-align-left"></i> Description</span>
            <div class="description-box" id="modal-description">-</div>
          </div>
          <div class="info-item full-width">
            <span class="info-label"><i class="fas fa-images"></i> Images</span>
            <div class="image-gallery" id="modal-images">
              <!-- Images will be inserted here -->
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn-cancel" onclick="closeReportModal()">
          <i class="fas fa-times"></i> Close
        </button>
        <button class="btn-reject" id="btn-reject" onclick="handleReject()">
          <i class="fas fa-times-circle"></i> Reject
        </button>
        <button class="btn-accept" id="btn-accept" onclick="handleAccept()">
          <i class="fas fa-check-circle"></i> Approve
        </button>
      </div>
    </div>
  </div>

  <script>
    let currentReportData = null;
    let currentStatus = null;

    // Sample data - replace with actual data fetching
    const sampleReports = {
      'INSP-1023': {
        report_id: 'DR-2025-001',
        inspection_id: 'INSP-1023',
        location: 'Main Road, Brgy. 3',
        severity: 'high',
        status: 'pending',
        reported_date: 'Dec 10, 2025',
        estimated_cost: '₱45,000.00',
        reporter: 'Juan Dela Cruz',
        coordinates: '14.5995° N, 120.9842° E',
        description: 'Large pothole on the main road causing traffic issues. The damage is approximately 2 meters wide and 30cm deep. Water accumulation during rainy days makes it dangerous for vehicles.',
        images: ['assets/img/sample-id.jpg', 'assets/img/sample-id.jpg']
      },
      'INSP-1024': {
        report_id: 'DR-2025-002',
        inspection_id: 'INSP-1024',
        location: 'Market Street',
        severity: 'medium',
        status: 'approved',
        reported_date: 'Dec 11, 2025',
        estimated_cost: '₱25,000.00',
        reporter: 'Maria Santos',
        coordinates: '14.6000° N, 120.9850° E',
        description: 'Crack on the road surface along Market Street. The crack extends about 5 meters and is approximately 2cm wide. Needs immediate attention to prevent further deterioration.',
        images: ['assets/img/sample-id.jpg']
      }
    };

    function openReportModal(inspectionId, status) {
      currentStatus = status;

      // Get report data (in real implementation, fetch from API)
      const reportData = sampleReports[inspectionId] || {
        report_id: inspectionId,
        inspection_id: inspectionId,
        location: 'N/A',
        severity: 'medium',
        status: status,
        reported_date: new Date().toLocaleDateString(),
        estimated_cost: 'N/A',
        reporter: 'N/A',
        coordinates: 'N/A',
        description: 'No description available.',
        images: []
      };

      currentReportData = reportData;

      // Populate modal
      document.getElementById('modal-report-id').textContent = reportData.report_id;
      document.getElementById('modal-inspection-id').textContent = reportData.inspection_id;
      document.getElementById('modal-location').textContent = reportData.location;

      // Severity badge
      const severityEl = document.getElementById('modal-severity');
      severityEl.innerHTML = `<span class="severity-badge severity-${reportData.severity}"><i class="fas fa-exclamation-triangle"></i> ${reportData.severity.toUpperCase()}</span>`;

      document.getElementById('modal-status').textContent = reportData.status.charAt(0).toUpperCase() + reportData.status.slice(1);
      document.getElementById('modal-reported-date').textContent = reportData.reported_date;
      document.getElementById('modal-estimated-cost').textContent = reportData.estimated_cost;
      document.getElementById('modal-reporter').textContent = reportData.reporter;
      document.getElementById('modal-coordinates').textContent = reportData.coordinates;
      document.getElementById('modal-description').textContent = reportData.description;

      // Images
      const imagesContainer = document.getElementById('modal-images');
      if (reportData.images && reportData.images.length > 0) {
        imagesContainer.innerHTML = reportData.images.map(img =>
          `<img src="${img}" class="image-item" alt="Damage photo" />`
        ).join('');
      } else {
        imagesContainer.innerHTML = '<span class="info-value"><i class="fas fa-image" style="margin-right: 8px;"></i>No images available</span>';
      }

      // Show/hide buttons based on status
      const acceptBtn = document.getElementById('btn-accept');
      const rejectBtn = document.getElementById('btn-reject');

      if (status === 'pending') {
        acceptBtn.style.display = 'inline-flex';
        rejectBtn.style.display = 'inline-flex';
      } else {
        acceptBtn.style.display = 'none';
        rejectBtn.style.display = 'none';
      }

      // Show modal
      document.getElementById('reportModal').style.display = 'block';
    }

    function closeReportModal() {
      document.getElementById('reportModal').style.display = 'none';
      currentReportData = null;
      currentStatus = null;
    }

    function handleAccept() {
      if (!currentReportData) return;

      if (confirm('Are you sure you want to approve this inspection report?')) {
        // Front-end only - show alert for now
        alert('Report approved! (Backend functionality to be implemented)');
        console.log('Approve report:', currentReportData);
        closeReportModal();
      }
    }

    function handleReject() {
      if (!currentReportData) return;

      if (confirm('Are you sure you want to reject this inspection report?')) {
        // Front-end only - show alert for now
        alert('Report rejected! (Backend functionality to be implemented)');
        console.log('Reject report:', currentReportData);
        closeReportModal();
      }
    }

    // Close modal when clicking outside of it
    window.onclick = function (event) {
      const modal = document.getElementById('reportModal');
      if (event.target == modal) {
        closeReportModal();
      }
    }
  </script>

  <!-- SIDEBAR SCRIPT (UNCHANGED) -->
  <script src="/js/sidebar.js"></script>
</body>

</html>