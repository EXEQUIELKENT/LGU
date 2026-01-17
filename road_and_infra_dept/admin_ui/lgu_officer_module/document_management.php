<?php
// Document Management - LGU Officer Module
session_start();
require_once '../config/auth.php';
require_once '../config/database.php';

$auth->requireAnyRole(['lgu_officer', 'admin']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Document & Report | LGU Officer</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url("https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap");
        
        :root {
            --primary: #2563eb;
            --glass-bg: rgba(255, 255, 255, 0.85);
            --glass-border: rgba(255, 255, 255, 0.4);
            --text-main: #1e293b;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        
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
            padding: 40px 60px;
            overflow-y: auto;
            z-index: 1;
        }

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

        .card {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            border: 1px solid var(--glass-border);
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .card-header {
            margin-bottom: 25px;
        }

        .card-header h2 {
            color: var(--text-main);
            margin-bottom: 10px;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-header p {
            color: var(--text-muted);
            line-height: 1.6;
            margin-bottom: 15px;
        }

        .table {
            width: 100%;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .table thead {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
        }

        .table th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: var(--text-main);
            font-size: 0.9rem;
        }

        .table td {
            padding: 15px;
            border-bottom: 1px solid #f1f5f9;
            color: var(--text-main);
        }

        .table tr:hover {
            background: #fdfdfd;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .status-approved {
            background: #dcfce7;
            color: #166534;
        }

        .status-completed {
            background: #dbeafe;
            color: #1e40af;
        }

        .btn {
            padding: 8px 15px;
            border-radius: 6px;
            border: none;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            margin-right: 5px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .btn-upload {
            background: #3b82f6;
            color: white;
        }

        .btn-upload:hover {
            background: #2563eb;
        }

        .btn-download {
            background: #10b981;
            color: white;
        }

        .btn-download:hover {
            background: #059669;
        }

        .btn-send {
            background: #8b5cf6;
            color: white;
        }

        .btn-send:hover {
            background: #7c3aed;
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
    <?php include '../sidebar/sidebar.php'; ?>
    
    <div class="main-content">
      <header class="module-header">
        <h1><i class="fas fa-file-alt"></i> Document & Report</h1>
        <p style="margin-top: 10px;">Manage and track all system-generated reports and documents</p>
        <hr class="header-divider">
      </header>

      <div class="card">
        <div class="card-header">
          <h2><i class="fas fa-chart-bar"></i> Generated Reports</h2>
          <p>All reports are system-generated and verified for accuracy.</p>
        </div>
        <div class="card-body">
          <table class="table report-table">
            <thead>
              <tr>
                <th><i class="fas fa-hashtag"></i> Report ID</th>
                <th><i class="fas fa-file-alt"></i> Report Type</th>
                <th><i class="fas fa-cube"></i> Module</th>
                <th><i class="fas fa-info-circle"></i> Status</th>
                <th><i class="fas fa-cogs"></i> Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>RPT-001</td>
                <td><i class="fas fa-exclamation-triangle"></i> Damage Assessment Report</td>
                <td><i class="fas fa-dollar-sign"></i> Cost Estimation</td>
                <td><span class="status-badge approved"><i class="fas fa-check-circle"></i> Approved</span></td>
                <td>
                  <button class="btn btn-upload"><i class="fas fa-upload"></i> Upload</button>
                  <button class="btn btn-download"><i class="fas fa-download"></i> Download</button>
                  <button class="btn btn-send"><i class="fas fa-paper-plane"></i> Send to Systems</button>
                </td>
              </tr>
              <tr>
                <td>RPT-002</td>
                <td><i class="fas fa-clipboard-list"></i> Inspection Summary</td>
                <td><i class="fas fa-tasks"></i> Inspection & Workflow</td>
                <td><span class="status-badge approved"><i class="fas fa-check-circle"></i> Approved</span></td>
                <td>
                  <button class="btn btn-upload"><i class="fas fa-upload"></i> Upload</button>
                  <button class="btn btn-download"><i class="fas fa-download"></i> Download</button>
                  <button class="btn btn-send"><i class="fas fa-paper-plane"></i> Send to Systems</button>
                </td>
              </tr>
              <tr>
                <td>RPT-003</td>
                <td><i class="fas fa-wrench"></i> Repair Progress Report</td>
                <td><i class="fas fa-road"></i> Road Maintenance</td>
                <td><span class="status-badge completed"><i class="fas fa-check-circle"></i> Completed</span></td>
                <td>
                  <button class="btn btn-upload"><i class="fas fa-upload"></i> Upload</button>
                  <button class="btn btn-download"><i class="fas fa-download"></i> Download</button>
                  <button class="btn btn-send"><i class="fas fa-paper-plane"></i> Send to Systems</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
</body>
</html>
