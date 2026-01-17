<?php
// Start session and include authentication
session_start();
require_once '../config/auth.php';

// Require login to access this page
$auth->requireLogin();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>LGU Document & Report Management</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
  <style>
    @import url("https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap");

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: "Inter", sans-serif;
    }

    body {
      height: 100vh;
      background: url("assets/img/cityhall.jpeg") center/cover no-repeat fixed;
      position: relative;
      overflow: hidden;
    }

    body::before {
      content: "";
      position: absolute;
      inset: 0;
      backdrop-filter: blur(6px);
      background: rgba(0, 0, 0, 0.35);
      z-index: 0;
    }

    .user-info {
      text-align: center;
      padding: 20px;
      font-weight: 500;
    }

    .logout-btn {
      margin-top: 8px;
      padding: 8px 14px;
      background: #3762c8;
      color: #fff;
      border: none;
      border-radius: 6px;
      cursor: pointer;
    }

    /* Main Content */
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

    .card {
      background: rgba(255, 255, 255, 0.85);
      backdrop-filter: blur(15px);
      border-radius: 18px;
      padding: 32px;
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
      transition: transform 0.2s, box-shadow 0.2s;
      border: 1px solid rgba(255, 255, 255, 0.3);
    }

    .card:hover {
      transform: translateY(-2px);
      box-shadow: 0 12px 30px rgba(0, 0, 0, 0.25);
    }

    h3 {
      margin-bottom: 12px;
      font-size: 1.25rem;
      font-weight: 600;
      color: #1e293b;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    h3 i {
      color: #2563eb;
      font-size: 1.1rem;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 14px;
    }

    th,
    td {
      padding: 14px 12px;
      border-bottom: 1px solid #e2e8f0;
      text-align: left;
    }

    th {
      background: linear-gradient(135deg, #f8fafc, #f1f5f9);
      font-weight: 600;
      color: #475569;
      text-transform: uppercase;
      font-size: 0.75rem;
      letter-spacing: 0.5px;
    }

    th i {
      margin-right: 6px;
      color: #2563eb;
      font-size: 0.7rem;
    }

    td {
      vertical-align: middle;
    }

    tr:hover td {
      background: rgba(248, 250, 252, 0.6);
    }

    .status {
      font-weight: 600;
      color: #2563eb;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      background: rgba(37, 99, 235, 0.1);
      border-radius: 6px;
      font-size: 0.85rem;
    }

    .status i {
      font-size: 0.7rem;
    }

    .btn-primary {
      padding: 8px 16px;
      border-radius: 10px;
      border: none;
      font-weight: 600;
      color: #fff;
      background: linear-gradient(135deg, #6384d2, #285ccd);
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.2s;
      box-shadow: 0 2px 6px rgba(99, 132, 210, 0.3);
      font-size: 0.85rem;
    }

    .btn-primary:hover {
      background: linear-gradient(135deg, #4567b5, #1f3a9e);
      transform: translateY(-1px);
      box-shadow: 0 4px 10px rgba(99, 132, 210, 0.4);
    }

    .btn-primary i {
      font-size: 0.75rem;
    }

    .btn-secondary {
      padding: 8px 14px;
      border-radius: 8px;
      border: none;
      font-weight: 500;
      cursor: pointer;
      background: #e5e7eb;
      color: #475569;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.2s;
      font-size: 0.85rem;
    }

    .btn-secondary:hover {
      background: #cbd5e1;
      transform: translateY(-1px);
    }

    .btn-secondary i {
      font-size: 0.75rem;
    }

    .btn-upload {
      padding: 8px 14px;
      border-radius: 8px;
      border: none;
      font-weight: 500;
      cursor: pointer;
      background: linear-gradient(135deg, #8b5cf6, #7c3aed);
      color: #fff;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.2s;
      font-size: 0.85rem;
      box-shadow: 0 2px 6px rgba(139, 92, 246, 0.3);
    }

    .btn-upload:hover {
      background: linear-gradient(135deg, #7c3aed, #6d28d9);
      transform: translateY(-1px);
      box-shadow: 0 4px 10px rgba(139, 92, 246, 0.4);
    }

    .btn-upload i {
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
      background-color: rgba(0, 0, 0, 0.5);
      backdrop-filter: blur(4px);
    }

    .modal-content {
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(15px);
      margin: 5% auto;
      padding: 30px;
      border-radius: 18px;
      width: 90%;
      max-width: 600px;
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
    }

    .close {
      color: #aaa;
      float: right;
      font-size: 28px;
      font-weight: bold;
      cursor: pointer;
    }

    .close:hover,
    .close:focus {
      color: #000;
    }

    .modal-header {
      margin-bottom: 20px;
    }

    .modal-header h3 {
      margin: 0;
      color: #1e293b;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .modal-header h3 i {
      color: #2563eb;
      font-size: 1.2rem;
    }

    .system-checkbox {
      margin: 15px 0;
      padding: 14px;
      background: #f8fafc;
      border-radius: 10px;
      border: 1px solid #e2e8f0;
      cursor: pointer;
      transition: all 0.2s;
    }

    .system-checkbox:hover {
      background: #f1f5f9;
      border-color: #6384d2;
      transform: translateX(4px);
      box-shadow: 0 2px 8px rgba(99, 132, 210, 0.15);
    }

    .system-checkbox label span {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .system-checkbox label span i {
      color: #2563eb;
      font-size: 0.9rem;
    }

    .system-checkbox input[type="checkbox"] {
      margin-right: 12px;
      width: 18px;
      height: 18px;
      cursor: pointer;
    }

    .system-checkbox label {
      cursor: pointer;
      font-weight: 500;
      color: #334155;
      display: flex;
      align-items: center;
    }

    .modal-actions {
      margin-top: 30px;
      display: flex;
      gap: 12px;
      justify-content: flex-end;
    }

    .btn-success {
      padding: 10px 20px;
      border-radius: 8px;
      border: none;
      font-weight: 600;
      color: #fff;
      background: linear-gradient(135deg, #10b981, #059669);
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all 0.2s;
      box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
    }

    .btn-success:hover {
      background: linear-gradient(135deg, #059669, #047857);
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
    }

    .btn-success i {
      font-size: 0.85rem;
    }

    .btn-cancel {
      padding: 10px 20px;
      border-radius: 8px;
      border: 1px solid #cbd5e1;
      font-weight: 500;
      color: #475569;
      background: #fff;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all 0.2s;
    }

    .btn-cancel:hover {
      background: #f1f5f9;
      border-color: #94a3b8;
    }

    .btn-cancel i {
      font-size: 0.85rem;
    }

    .loading {
      display: inline-block;
      width: 16px;
      height: 16px;
      border: 2px solid #ffffff;
      border-radius: 50%;
      border-top-color: transparent;
      animation: spin 0.8s linear infinite;
      margin-left: 8px;
    }

    @keyframes spin {
      to {
        transform: rotate(360deg);
      }
    }

    .status-message {
      margin-top: 15px;
      padding: 12px;
      border-radius: 8px;
      font-size: 14px;
      display: none;
    }

    .status-message.success {
      background: #d1fae5;
      color: #065f46;
      border: 1px solid #10b981;
      display: block;
    }

    .status-message.error {
      background: #fee2e2;
      color: #991b1b;
      border: 1px solid #ef4444;
      display: block;
    }

    /* Upload Modal Styles */
    .modal-content.large {
      max-width: 800px;
      max-height: 90vh;
      overflow-y: auto;
    }

    .upload-section {
      margin-bottom: 30px;
      padding: 20px;
      background: #f8fafc;
      border-radius: 12px;
      border: 2px dashed #cbd5e1;
      transition: all 0.2s;
    }

    .upload-section:hover {
      border-color: #8b5cf6;
      background: #f1f5f9;
    }

    .file-input-wrapper {
      position: relative;
      display: inline-block;
      width: 100%;
    }

    .file-input-wrapper input[type="file"] {
      width: 100%;
      padding: 12px;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      background: #fff;
      cursor: pointer;
      font-size: 14px;
    }

    .upload-history {
      margin-top: 30px;
    }

    .upload-history h4 {
      margin-bottom: 15px;
      color: #1e293b;
      font-size: 1rem;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .upload-history h4 i {
      color: #8b5cf6;
    }

    .file-history-list {
      list-style: none;
      padding: 0;
      margin: 0;
    }

    .file-history-item {
      padding: 15px;
      margin-bottom: 12px;
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      transition: all 0.2s;
    }

    .file-history-item:hover {
      background: #f8fafc;
      border-color: #cbd5e1;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    }

    .file-info {
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .file-name {
      font-weight: 600;
      color: #1e293b;
      font-size: 14px;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .file-name i {
      color: #8b5cf6;
      font-size: 0.9rem;
    }

    .file-date {
      font-size: 12px;
      color: #64748b;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .file-date i {
      color: #94a3b8;
      font-size: 0.75rem;
    }

    .file-actions {
      display: flex;
      gap: 8px;
    }

    .btn-download-small {
      padding: 6px 12px;
      border-radius: 6px;
      border: none;
      font-weight: 500;
      cursor: pointer;
      background: linear-gradient(135deg, #6384d2, #285ccd);
      color: #fff;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.2s;
      font-size: 0.8rem;
      box-shadow: 0 1px 4px rgba(99, 132, 210, 0.3);
    }

    .btn-download-small:hover {
      background: linear-gradient(135deg, #4567b5, #1f3a9e);
      transform: translateY(-1px);
      box-shadow: 0 2px 6px rgba(99, 132, 210, 0.4);
    }

    .btn-download-small i {
      font-size: 0.7rem;
    }

    .empty-history {
      text-align: center;
      padding: 40px 20px;
      color: #94a3b8;
      font-size: 14px;
    }

    .empty-history i {
      font-size: 2rem;
      margin-bottom: 10px;
      display: block;
      opacity: 0.5;
    }
  </style>
</head>

<body>
  <!-- SIDEBAR (REUSED) -->
  <?php include '../sidebar/sidebar.php'; ?>

  <!-- Main Content -->
  <div class="main-content">
    <!-- Reports Overview -->
    <header class="page-header">
      <h1 style="font-size: 1.5rem; font-weight: 700">
        <i class="fas fa-file-alt"></i> Document & Report
      </h1>
      <p style="opacity: 0.8; font-size: 0.9rem">
        Manage and track all system-generated reports and documents
      </p>

      <!-- Divider -->
      <hr class="divider" />
    </header>

    <!-- Reports Table -->
    <div class="card">
      <h3><i class="fas fa-clipboard-list"></i> Generated Reports</h3>
      <p style="color: #64748b; margin-bottom: 20px;">All reports are system-generated and verified for accuracy.</p>

      <table>
        <tr>
          <th><i class="fas fa-hashtag"></i> Report ID</th>
          <th><i class="fas fa-file-alt"></i> Report Type</th>
          <th><i class="fas fa-folder"></i> Module</th>
          <th><i class="fas fa-info-circle"></i> Status</th>
          <th><i class="fas fa-cog"></i> Actions</th>
        </tr>
        <tr>
          <td><strong>RPT-001</strong></td>
          <td><i class="fas fa-file-chart-line" style="color: #2563eb; margin-right: 6px;"></i>Damage Assessment Report
          </td>
          <td><i class="fas fa-calculator" style="color: #2563eb; margin-right: 6px;"></i>Cost Estimation</td>
          <td><span class="status"><i class="fas fa-check-circle"></i> Approved</span></td>
          <td>
            <button class="btn-upload" onclick="openUploadModal('RPT-001')" style="margin-left: 8px;">
              <i class="fas fa-upload"></i> Upload
            </button>
            <button class="btn-primary" onclick="downloadReport('RPT-001')">
              <i class="fas fa-download"></i> Download
            </button>
            <button class="btn-secondary" onclick="openSendModal('RPT-001')" style="margin-left: 8px;">
              <i class="fas fa-paper-plane"></i> Send to Systems
            </button>
          </td>
        </tr>
        <tr>
          <td><strong>RPT-002</strong></td>
          <td><i class="fas fa-clipboard-check" style="color: #2563eb; margin-right: 6px;"></i>Inspection Summary</td>
          <td><i class="fas fa-tasks" style="color: #2563eb; margin-right: 6px;"></i>Inspection & Workflow</td>
          <td><span class="status"><i class="fas fa-check-circle"></i> Approved</span></td>
          <td>
            <button class="btn-upload" onclick="openUploadModal('RPT-002')" style="margin-left: 8px;">
              <i class="fas fa-upload"></i> Upload
            </button>
            <button class="btn-primary" onclick="downloadReport('RPT-002')">
              <i class="fas fa-download"></i> Download
            </button>
            <button class="btn-secondary" onclick="openSendModal('RPT-002')" style="margin-left: 8px;">
              <i class="fas fa-paper-plane"></i> Send to Systems
            </button>
          </td>
        </tr>
        <tr>
          <td><strong>RPT-003</strong></td>
          <td><i class="fas fa-tools" style="color: #2563eb; margin-right: 6px;"></i>Repair Progress Report</td>
          <td><i class="fas fa-wrench" style="color: #2563eb; margin-right: 6px;"></i>Road Maintenance</td>
          <td><span class="status"><i class="fas fa-check-double"></i> Completed</span></td>
          <td>
            <button class="btn-upload" onclick="openUploadModal('RPT-003')" style="margin-left: 8px;">
              <i class="fas fa-upload"></i> Upload
            </button>
            <button class="btn-primary" onclick="downloadReport('RPT-003')">
              <i class="fas fa-download"></i> Download
            </button>
            <button class="btn-secondary" onclick="openSendModal('RPT-003')" style="margin-left: 8px;">
              <i class="fas fa-paper-plane"></i> Send to Systems
            </button>

          </td>
        </tr>
      </table>
    </div>
  </div>

  <!-- Upload Modal -->
  <div id="uploadModal" class="modal">
    <div class="modal-content large">
      <span class="close" onclick="closeUploadModal()">&times;</span>
      <div class="modal-header">
        <h3><i class="fas fa-upload"></i> Upload File</h3>
        <p style="color: #64748b; font-size: 14px; margin-top: 5px;">
          Upload a file for this report:
        </p>
      </div>

      <form id="uploadFileForm">
        <input type="hidden" id="currentUploadReportId" />

        <div class="upload-section">
          <label style="display: block; margin-bottom: 10px; font-weight: 500; color: #334155;">
            Select File to Upload:
          </label>
          <div class="file-input-wrapper">
            <input type="file" id="fileInput" name="file" required />
          </div>
        </div>

        <div class="status-message" id="uploadStatusMessage"></div>

        <div class="modal-actions">
          <button type="button" class="btn-cancel" onclick="closeUploadModal()">
            <i class="fas fa-times"></i> Cancel
          </button>
          <button type="submit" class="btn-success" id="uploadButton">
            <i class="fas fa-upload"></i> Upload File
          </button>
        </div>
      </form>

      <div class="upload-history">
        <h4><i class="fas fa-history"></i> Upload History</h4>
        <ul class="file-history-list" id="fileHistoryList">
          <li class="empty-history">
            <i class="fas fa-folder-open"></i>
            <div>No files uploaded yet</div>
          </li>
        </ul>
      </div>
    </div>
  </div>

  <!-- Send to Systems Modal -->
  <div id="sendModal" class="modal">
    <div class="modal-content">
      <span class="close" onclick="closeSendModal()">&times;</span>
      <div class="modal-header">
        <h3><i class="fas fa-share-alt"></i> Send Report to Integrated Systems</h3>
        <p style="color: #64748b; font-size: 14px; margin-top: 5px;">
          Select the systems you want to send this report to:
        </p>
      </div>

      <form id="sendToSystemsForm">
        <input type="hidden" id="currentReportId" />

        <div class="system-checkbox">
          <label>
            <input type="checkbox" name="systems" value="community_infrastructure_maintenance" />
            <span><i class="fas fa-building"></i> Community Infrastructure Maintenance Systems</span>
          </label>
        </div>

        <div class="system-checkbox">
          <label>
            <input type="checkbox" name="systems" value="urban_planning_development" />
            <span><i class="fas fa-city"></i> Urban Planning and Development Systems</span>
          </label>
        </div>

        <div class="system-checkbox">
          <label>
            <input type="checkbox" name="systems" value="infrastructure_project_management" />
            <span><i class="fas fa-project-diagram"></i> Infrastructure Project Management Systems</span>
          </label>
        </div>

        <div class="system-checkbox">
          <label>
            <input type="checkbox" name="systems" value="maintenance_management" />
            <span><i class="fas fa-tools"></i> Maintenance Management Systems</span>
          </label>
        </div>

        <div class="system-checkbox">
          <label>
            <input type="checkbox" name="systems" value="external_planning_management" />
            <span><i class="fas fa-globe"></i> External Planning and Management Systems</span>
          </label>
        </div>

        <div class="status-message" id="statusMessage"></div>

        <div class="modal-actions">
          <button type="button" class="btn-cancel" onclick="closeSendModal()">
            <i class="fas fa-times"></i> Cancel
          </button>
          <button type="submit" class="btn-success" id="sendButton">
            <i class="fas fa-paper-plane"></i> Send to Selected Systems
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
    // Get authentication token (adjust based on your auth implementation)
    function getAuthToken() {
      return localStorage.getItem('authToken') || sessionStorage.getItem('authToken');
    }

    // Open send modal
    function openSendModal(reportId) {
      document.getElementById('currentReportId').value = reportId;
      document.getElementById('sendModal').style.display = 'block';
      document.getElementById('statusMessage').style.display = 'none';
      document.getElementById('statusMessage').className = 'status-message';
      // Reset checkboxes
      document.querySelectorAll('input[name="systems"]').forEach(cb => cb.checked = false);
    }

    // Close send modal
    function closeSendModal() {
      document.getElementById('sendModal').style.display = 'none';
      document.getElementById('statusMessage').style.display = 'none';
    }

    // Close modal when clicking outside
    window.onclick = function (event) {
      const sendModal = document.getElementById('sendModal');
      const uploadModal = document.getElementById('uploadModal');
      if (event.target == sendModal) {
        closeSendModal();
      }
      if (event.target == uploadModal) {
        closeUploadModal();
      }
    }

    // Download report function
    function downloadReport(reportId) {
      // Implement download functionality
      console.log('Downloading report:', reportId);
      // Add your download logic here
    }

    // Send file to integrated systems
    async function sendToIntegratedSystems(reportId, systems) {
      const token = getAuthToken();

      try {
        const response = await fetch('../backend/documents/send-to-systems', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${token}`
          },
          body: JSON.stringify({
            report_id: reportId,
            systems: systems
          })
        });

        const data = await response.json();

        if (response.ok) {
          return { success: true, data: data };
        } else {
          return { success: false, error: data.error || 'Failed to send to systems' };
        }
      } catch (error) {
        console.error('Error sending to systems:', error);
        return { success: false, error: 'Network error occurred' };
      }
    }

    // Handle form submission
    document.getElementById('sendToSystemsForm').addEventListener('submit', async function (e) {
      e.preventDefault();

      const reportId = document.getElementById('currentReportId').value;
      const selectedSystems = Array.from(document.querySelectorAll('input[name="systems"]:checked'))
        .map(cb => cb.value);

      if (selectedSystems.length === 0) {
        showStatus('Please select at least one system to send to.', 'error');
        return;
      }

      const sendButton = document.getElementById('sendButton');
      const originalText = sendButton.innerHTML;
      sendButton.disabled = true;
      sendButton.innerHTML = 'Sending... <span class="loading"></span>';

      const statusMessage = document.getElementById('statusMessage');
      statusMessage.style.display = 'none';

      try {
        const result = await sendToIntegratedSystems(reportId, selectedSystems);

        if (result.success) {
          showStatus(
            `Successfully sent report to ${selectedSystems.length} system(s)!`,
            'success'
          );

          // Close modal after 2 seconds
          setTimeout(() => {
            closeSendModal();
          }, 2000);
        } else {
          showStatus(result.error || 'Failed to send to systems', 'error');
        }
      } catch (error) {
        showStatus('An error occurred while sending to systems', 'error');
      } finally {
        sendButton.disabled = false;
        sendButton.innerHTML = originalText;
      }
    });

    // Show status message
    function showStatus(message, type) {
      const statusMessage = document.getElementById('statusMessage');
      statusMessage.textContent = message;
      statusMessage.className = `status-message ${type}`;
      statusMessage.style.display = 'block';
    }

    // Upload Modal Functions
    function openUploadModal(reportId) {
      document.getElementById('currentUploadReportId').value = reportId;
      document.getElementById('uploadModal').style.display = 'block';
      document.getElementById('uploadStatusMessage').style.display = 'none';
      document.getElementById('uploadStatusMessage').className = 'status-message';
      document.getElementById('fileInput').value = '';

      // Load file history
      loadFileHistory(reportId);
    }

    function closeUploadModal() {
      document.getElementById('uploadModal').style.display = 'none';
      document.getElementById('uploadStatusMessage').style.display = 'none';
      document.getElementById('fileInput').value = '';
    }

    // Load file history for a report
    async function loadFileHistory(reportId) {
      const token = getAuthToken();
      const historyList = document.getElementById('fileHistoryList');

      try {
        const response = await fetch(`../backend/documents/report/${reportId}/files`, {
          method: 'GET',
          headers: {
            'Authorization': `Bearer ${token}`
          }
        });

        if (response.ok) {
          const data = await response.json();
          displayFileHistory(data.files || []);
        } else {
          // If endpoint doesn't exist yet, show empty state
          displayFileHistory([]);
        }
      } catch (error) {
        console.error('Error loading file history:', error);
        displayFileHistory([]);
      }
    }

    // Display file history
    function displayFileHistory(files) {
      const historyList = document.getElementById('fileHistoryList');

      if (files.length === 0) {
        historyList.innerHTML = `
            <li class="empty-history">
              <i class="fas fa-folder-open"></i>
              <div>No files uploaded yet</div>
            </li>
          `;
        return;
      }

      historyList.innerHTML = files.map(file => {
        const uploadDate = new Date(file.uploaded_at || file.created_at);
        const formattedDate = uploadDate.toLocaleDateString('en-US', {
          year: 'numeric',
          month: 'short',
          day: 'numeric',
          hour: '2-digit',
          minute: '2-digit'
        });

        return `
            <li class="file-history-item">
              <div class="file-info">
                <div class="file-name">
                  <i class="fas fa-file"></i>
                  ${file.file_name || file.name || 'Unknown File'}
                </div>
                <div class="file-date">
                  <i class="fas fa-clock"></i>
                  Uploaded: ${formattedDate}
                </div>
              </div>
              <div class="file-actions">
                <button class="btn-download-small" onclick="downloadUploadedFile('${file.id || file.file_id}', '${file.file_name || file.name || 'file'}')">
                  <i class="fas fa-download"></i> Download
                </button>
              </div>
            </li>
          `;
      }).join('');
    }

    // Upload file
    document.getElementById('uploadFileForm').addEventListener('submit', async function (e) {
      e.preventDefault();

      const reportId = document.getElementById('currentUploadReportId').value;
      const fileInput = document.getElementById('fileInput');
      const file = fileInput.files[0];

      if (!file) {
        showUploadStatus('Please select a file to upload.', 'error');
        return;
      }

      const uploadButton = document.getElementById('uploadButton');
      const originalText = uploadButton.innerHTML;
      uploadButton.disabled = true;
      uploadButton.innerHTML = 'Uploading... <span class="loading"></span>';

      const statusMessage = document.getElementById('uploadStatusMessage');
      statusMessage.style.display = 'none';

      try {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('report_id', reportId);

        const token = getAuthToken();
        const response = await fetch('../backend/documents/upload', {
          method: 'POST',
          headers: {
            'Authorization': `Bearer ${token}`
          },
          body: formData
        });

        const data = await response.json();

        if (response.ok) {
          showUploadStatus('File uploaded successfully!', 'success');
          fileInput.value = '';

          // Reload file history
          setTimeout(() => {
            loadFileHistory(reportId);
          }, 500);
        } else {
          showUploadStatus(data.error || 'Failed to upload file', 'error');
        }
      } catch (error) {
        console.error('Error uploading file:', error);
        showUploadStatus('An error occurred while uploading the file', 'error');
      } finally {
        uploadButton.disabled = false;
        uploadButton.innerHTML = originalText;
      }
    });

    // Show upload status message
    function showUploadStatus(message, type) {
      const statusMessage = document.getElementById('uploadStatusMessage');
      statusMessage.textContent = message;
      statusMessage.className = `status-message ${type}`;
      statusMessage.style.display = 'block';
    }

    // Download uploaded file
    async function downloadUploadedFile(fileId, fileName) {
      const token = getAuthToken();

      try {
        const response = await fetch(`../backend/documents/${fileId}/download`, {
          method: 'GET',
          headers: {
            'Authorization': `Bearer ${token}`
          }
        });

        if (response.ok) {
          const blob = await response.blob();
          const url = window.URL.createObjectURL(blob);
          const a = document.createElement('a');
          a.href = url;
          a.download = fileName;
          document.body.appendChild(a);
          a.click();
          window.URL.revokeObjectURL(url);
          document.body.removeChild(a);
        } else {
          const data = await response.json();
          alert(data.error || 'Failed to download file');
        }
      } catch (error) {
        console.error('Error downloading file:', error);
        alert('An error occurred while downloading the file');
      }
    }
  </script>
</body>

</html>