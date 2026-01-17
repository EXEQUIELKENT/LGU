<?php
// Publication Management - LGU Officer Module
// Manages publishing of verified completed road issues for public viewing
session_start();
require_once '../config/auth.php';
require_once '../config/database.php';

// Require LGU officer role
$auth->requireAnyRole(['lgu_officer', 'admin']);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $database = new Database();
    $conn = $database->getConnection();
    
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'publish_report':
                // Publish a damage report for public viewing
                $damageReportId = (int)$_POST['damage_report_id'];
                $roadName = $_POST['road_name'];
                $issueSummary = $_POST['issue_summary'];
                $issueType = $_POST['issue_type'];
                $severityPublic = $_POST['severity_public'];
                $statusPublic = $_POST['status_public'];
                $dateReported = $_POST['date_reported'];
                $repairStartDate = $_POST['repair_start_date'] ?: null;
                $completionDate = $_POST['completion_date'] ?: null;
                
                // Generate publication ID
                $publicationId = 'PUB-' . date('Y') . '-' . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
                
                // Calculate repair duration
                $repairDuration = null;
                if ($repairStartDate && $completionDate) {
                    $start = new DateTime($repairStartDate);
                    $end = new DateTime($completionDate);
                    $repairDuration = $start->diff($end)->days;
                }
                
                // Insert publication
                $stmt = $conn->prepare("
                    INSERT INTO public_publications (
                        publication_id, damage_report_id, road_name, issue_summary, issue_type,
                        severity_public, status_public, date_reported, repair_start_date,
                        completion_date, repair_duration_days, is_published, publication_date, published_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), ?)
                ");
                
                $stmt->bind_param("sissssssssii", 
                    $publicationId, $damageReportId, $roadName, $issueSummary, $issueType,
                    $severityPublic, $statusPublic, $dateReported, $repairStartDate,
                    $completionDate, $repairDuration, $_SESSION['user_id']
                );
                
                if ($stmt->execute()) {
                    // Log activity
                    $auth->logActivity('report_publication', "Published road issue: $publicationId");
                    
                    // Add initial progress entry
                    $newPublicationId = $conn->insert_id;
                    $currentDate = date('Y-m-d');
                    $progressStmt = $conn->prepare("
                        INSERT INTO publication_progress (publication_id, progress_date, status, description, created_by)
                        VALUES (?, ?, 'published', 'Published for public viewing', ?)
                    ");
                    $progressStmt->bind_param("isi", $newPublicationId, $currentDate, $_SESSION['user_id']);
                    $progressStmt->execute();
                    
                    // Create notification for engineers
                    $notificationStmt = $conn->prepare("
                        INSERT INTO notifications (user_id, type, title, message, related_id, created_at)
                        SELECT u.id, 'publication', 'Report Published', ?, ?, NOW()
                        FROM users u
                        WHERE u.role = 'engineer'
                    ");
                    $notificationMessage = "Report published: {$issueSummary} for {$roadName}";
                    $notificationStmt->bind_param("si", $notificationMessage, $newPublicationId);
                    $notificationStmt->execute();
                    
                    $_SESSION['success'] = "Report published successfully!";
                } else {
                    $_SESSION['error'] = "Failed to publish report: " . $conn->error;
                }
                break;
                
            case 'create_new_publication':
                // Create a new publication without linking to damage report
                $roadName = $_POST['road_name'];
                $publicationTitle = $_POST['publication_title'];
                $issueSummary = $_POST['issue_summary'];
                $issueType = $_POST['issue_type'];
                $severityPublic = $_POST['severity_public'];
                $statusPublic = $_POST['status_public'];
                $dateReported = $_POST['date_reported'];
                $repairStartDate = $_POST['repair_start_date'] ?: null;
                $completionDate = $_POST['completion_date'] ?: null;
                $additionalNotes = $_POST['additional_notes'] ?: null;
                
                // Generate publication ID
                $publicationId = 'PUB-' . date('Y') . '-' . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
                
                // Calculate repair duration
                $repairDuration = null;
                if ($repairStartDate && $completionDate) {
                    $start = new DateTime($repairStartDate);
                    $end = new DateTime($completionDate);
                    $repairDuration = $start->diff($end)->days;
                }
                
                // Insert publication without damage_report_id (null)
                $stmt = $conn->prepare("
                    INSERT INTO public_publications (
                        publication_id, damage_report_id, road_name, issue_summary, issue_type,
                        severity_public, status_public, date_reported, repair_start_date,
                        completion_date, repair_duration_days, is_published, publication_date, published_by
                    ) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), ?)
                ");
                
                $stmt->bind_param("sssssssssii", 
                    $publicationId, $roadName, $issueSummary, $issueType,
                    $severityPublic, $statusPublic, $dateReported, $repairStartDate,
                    $completionDate, $repairDuration, $_SESSION['user_id']
                );
                
                if ($stmt->execute()) {
                    // Log activity
                    $auth->logActivity('new_publication', "Created new publication: $publicationId");
                    
                    // Add initial progress entry
                    $newPublicationId = $conn->insert_id;
                    $currentDate = date('Y-m-d');
                    $description = "Publication created: " . $publicationTitle;
                    if ($additionalNotes) {
                        $description .= " - " . $additionalNotes;
                    }
                    
                    $progressStmt = $conn->prepare("
                        INSERT INTO publication_progress (publication_id, progress_date, status, description, created_by)
                        VALUES (?, ?, 'created', ?, ?)
                    ");
                    $progressStmt->bind_param("issi", $newPublicationId, $currentDate, $description, $_SESSION['user_id']);
                    $progressStmt->execute();
                    
                    // Create notification for engineers
                    $notificationStmt = $conn->prepare("
                        INSERT INTO notifications (user_id, type, title, message, related_id, created_at)
                        SELECT u.id, 'publication', 'New Publication Created', ?, ?, NOW()
                        FROM users u
                        WHERE u.role = 'engineer'
                    ");
                    $notificationMessage = "New publication: {$publicationTitle} for {$roadName}";
                    $notificationStmt->bind_param("si", $notificationMessage, $newPublicationId);
                    $notificationStmt->execute();
                    
                    $_SESSION['success'] = "New publication created successfully!";
                } else {
                    $_SESSION['error'] = "Failed to create publication: " . $conn->error;
                }
                break;
                
            case 'update_publication':
                // Update existing publication
                $publicationId = (int)$_POST['publication_id'];
                $statusPublic = $_POST['status_public'];
                $completionDate = $_POST['completion_date'] ?: null;
                
                // Update publication
                $stmt = $conn->prepare("
                    UPDATE public_publications 
                    SET status_public = ?, completion_date = ?, last_updated = NOW()
                    WHERE id = ?
                ");
                
                $stmt->bind_param("ssi", $statusPublic, $completionDate, $publicationId);
                
                if ($stmt->execute()) {
                    // Add progress entry
                    $progressStmt = $conn->prepare("
                        INSERT INTO publication_progress (publication_id, progress_date, status, description, created_by)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $description = "Status updated to: " . ucfirst(str_replace('_', ' ', $statusPublic));
                    $currentDate = date('Y-m-d');
                    $progressStmt->bind_param("isssi", $publicationId, $currentDate, $statusPublic, $description, $_SESSION['user_id']);
                    $progressStmt->execute();
                    
                    $auth->logActivity('publication_update', "Updated publication: $publicationId");
                    $_SESSION['success'] = "Publication updated successfully!";
                } else {
                    $_SESSION['error'] = "Failed to update publication: " . $conn->error;
                }
                break;
                
            case 'archive_publication':
                // Archive publication (remove from public view)
                $publicationId = (int)$_POST['publication_id'];
                $archiveReason = $_POST['archive_reason'];
                
                $stmt = $conn->prepare("
                    UPDATE public_publications 
                    SET archived = 1, archive_reason = ?, is_published = 0, last_updated = NOW()
                    WHERE id = ?
                ");
                
                $stmt->bind_param("si", $archiveReason, $publicationId);
                
                if ($stmt->execute()) {
                    $auth->logActivity('publication_archive', "Archived publication: $publicationId");
                    $_SESSION['success'] = "Publication archived successfully!";
                } else {
                    $_SESSION['error'] = "Failed to archive publication: " . $conn->error;
                }
                break;
        }
        
        // Redirect to prevent form resubmission
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Get data for display
$database = new Database();
$conn = $database->getConnection();

// Get pending damage reports that can be published
$pendingReports = [];
$stmt = $conn->prepare("
    SELECT dr.*, u.first_name, u.last_name 
    FROM damage_reports dr
    LEFT JOIN users u ON dr.reporter_id = u.id
    WHERE dr.status IN ('resolved', 'closed') 
    AND dr.id NOT IN (SELECT damage_report_id FROM public_publications WHERE archived = 0)
    ORDER BY dr.updated_at DESC
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $pendingReports[] = $row;
}

// Get published reports
$publishedReports = [];
$stmt = $conn->prepare("
    SELECT pp.*, dr.report_id, dr.severity as internal_severity
    FROM public_publications pp
    LEFT JOIN damage_reports dr ON pp.damage_report_id = dr.id
    WHERE pp.archived = 0
    ORDER BY pp.publication_date DESC
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $publishedReports[] = $row;
}

// Get statistics
$stats = [
    'pending_publication' => count($pendingReports),
    'published' => 0,
    'completed_public' => 0,
    'under_repair_public' => 0
];

foreach ($publishedReports as $report) {
    $stats['published']++;
    if ($report['status_public'] === 'completed' || $report['status_public'] === 'fixed') {
        $stats['completed_public']++;
    } elseif ($report['status_public'] === 'under_repair') {
        $stats['under_repair_public']++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Publication Management | LGU Officer</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url("https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap");
        
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
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
            padding: 40px 60px;
            display: flex;
            flex-direction: column;
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
        }

        .module-header p {
            font-size: 1rem;
            opacity: 0.9;
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            border: 1px solid var(--glass-border);
            padding: 24px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
            font-size: 1.25rem;
            color: white;
        }

        .stat-icon.pending { background: var(--warning); }
        .stat-icon.published { background: var(--primary); }
        .stat-icon.completed { background: var(--success); }
        .stat-icon.repair { background: var(--danger); }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            color: var(--text-muted);
            font-size: 0.875rem;
        }

        /* Content Sections */
        .content-card {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            border: 1px solid var(--glass-border);
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            margin-bottom: 30px;
        }

        .section-title {
            color: #1e40af;
            font-size: 1.4rem;
            font-weight: 700;
            margin-bottom: 20px;
        }

        /* Table Styling */
        .table-container {
            width: 100%;
            overflow-x: auto;
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: #f1f5f9;
            color: #1e293b;
            font-weight: 700;
            text-align: left;
            padding: 15px;
            font-size: 0.9rem;
        }

        td {
            padding: 15px;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            font-size: 0.9rem;
            color: #334155;
        }

        tr:last-child td {
            border-bottom: none;
        }

        /* Buttons */
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.8rem;
        }

        /* Forms */
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

        select.form-control {
            cursor: pointer;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        /* Modal */
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
            margin: 5% auto;
            padding: 30px;
            border-radius: 16px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-main);
        }

        .close {
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-muted);
            background: none;
            border: none;
        }

        .close:hover {
            color: var(--text-main);
        }

        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-pending { background: #fef3c7; color: #92400e; }
        .status-published { background: #dbeafe; color: #1e40af; }
        .status-completed { background: #dcfce7; color: #166534; }
        .status-under-repair { background: #fef3c7; color: #92400e; }
        .status-fixed { background: #dcfce7; color: #166534; }

        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .alert-error {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .main-content {
                padding: 20px;
                margin-left: 0;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include '../sidebar/sidebar.php'; ?>

    <main class="main-content">
        <header class="module-header">
            <h1><i class="fas fa-newspaper"></i> Publication Management</h1>
            <p>Publish verified completed road issues for public transparency</p>
            <hr class="header-divider">
        </header>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <!-- Statistics Overview -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon pending">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value"><?php echo $stats['pending_publication']; ?></div>
                <div class="stat-label">Pending Publication</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon published">
                    <i class="fas fa-eye"></i>
                </div>
                <div class="stat-value"><?php echo $stats['published']; ?></div>
                <div class="stat-label">Published Reports</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon completed">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value"><?php echo $stats['completed_public']; ?></div>
                <div class="stat-label">Completed (Public)</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon repair">
                    <i class="fas fa-tools"></i>
                </div>
                <div class="stat-value"><?php echo $stats['under_repair_public']; ?></div>
                <div class="stat-label">Under Repair (Public)</div>
            </div>
        </div>

        <!-- Create New Publication -->
        <div class="content-card">
            <h2 class="section-title"><i class="fas fa-plus-circle"></i> Create New Publication</h2>
            <p style="color: var(--text-muted); margin-bottom: 20px;">Create a new public announcement or road infrastructure update for citizens.</p>
            
            <form method="POST" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                <input type="hidden" name="action" value="create_new_publication">
                
                <div class="form-group">
                    <label class="form-label">Road Name *</label>
                    <input type="text" name="road_name" class="form-control" placeholder="e.g., Main Street, Highway 1" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Publication Title *</label>
                    <input type="text" name="publication_title" class="form-control" placeholder="e.g., Road Repair Notice" required>
                </div>
                
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label class="form-label">Issue Summary/Description *</label>
                    <textarea name="issue_summary" class="form-control" placeholder="Provide detailed information about the road issue, maintenance work, or infrastructure update..." required></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Issue Type *</label>
                    <select name="issue_type" class="form-control" required>
                        <option value="">Select Issue Type</option>
                        <option value="pothole">Pothole Repair</option>
                        <option value="crack">Crack Repair</option>
                        <option value="drainage">Drainage Maintenance</option>
                        <option value="surface_damage">Surface Resurfacing</option>
                        <option value="construction">New Construction</option>
                        <option value="maintenance">Routine Maintenance</option>
                        <option value="closure">Road Closure</option>
                        <option value="announcement">Public Announcement</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Public Severity Level *</label>
                    <select name="severity_public" class="form-control" required>
                        <option value="">Select Severity</option>
                        <option value="low">Low - Minor Issue</option>
                        <option value="medium">Medium - Moderate Impact</option>
                        <option value="high">High - Major Impact</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Public Status *</label>
                    <select name="status_public" class="form-control" required>
                        <option value="">Select Status</option>
                        <option value="reported">Reported</option>
                        <option value="under_repair">Under Repair</option>
                        <option value="completed">Completed</option>
                        <option value="fixed">Fixed</option>
                        <option value="scheduled">Scheduled</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Date Reported *</label>
                    <input type="date" name="date_reported" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Expected Start Date</label>
                    <input type="date" name="repair_start_date" class="form-control">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Expected Completion Date</label>
                    <input type="date" name="completion_date" class="form-control">
                </div>
                
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label class="form-label">Additional Notes</label>
                    <textarea name="additional_notes" class="form-control" placeholder="Any additional information for the public (detour routes, contact information, etc.)..." rows="3"></textarea>
                </div>
                
                <div style="grid-column: 1 / -1; display: flex; gap: 10px; justify-content: flex-end; margin-top: 10px;">
                    <button type="button" class="btn" style="background: #e5e7eb; color: #374151;" onclick="this.form.reset()">
                        <i class="fas fa-times"></i> Clear Form
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-newspaper"></i> Create Publication
                    </button>
                </div>
            </form>
        </div>

        <!-- Pending Reports for Publication -->
        <div class="content-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 class="section-title" style="margin-bottom: 0;"><i class="fas fa-hourglass-half"></i> Pending Reports for Publication</h2>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <span style="background: var(--warning); color: white; padding: 5px 12px; border-radius: 20px; font-size: 0.875rem; font-weight: 600;">
                        <?php echo count($pendingReports); ?> Reports Ready
                    </span>
                    <button class="btn btn-primary btn-sm" onclick="refreshPendingReports()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
            </div>
            
            <div style="background: #f8fafc; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid var(--warning);">
                <p style="margin: 0; color: #475569; font-size: 0.9rem;">
                    <i class="fas fa-info-circle" style="color: var(--warning); margin-right: 8px;"></i>
                    <strong>Ready to Publish:</strong> These reports have been marked as resolved or closed and are ready for public transparency. Review and publish to inform citizens about completed road work.
                </p>
            </div>
            
            <?php if (empty($pendingReports)): ?>
                <div style="text-align: center; padding: 60px 20px;">
                    <i class="fas fa-check-circle" style="font-size: 3rem; color: var(--success); margin-bottom: 15px; display: block;"></i>
                    <h3 style="color: var(--text-main); margin-bottom: 10px;">All Caught Up!</h3>
                    <p style="color: var(--text-muted); margin-bottom: 20px;">No pending reports ready for publication.</p>
                    <div style="background: #f1f5f9; padding: 15px; border-radius: 8px; max-width: 400px; margin: 0 auto;">
                        <p style="margin: 0; font-size: 0.9rem; color: #64748b;">
                            <i class="fas fa-lightbulb" style="color: var(--primary); margin-right: 8px;"></i>
                            Reports will appear here once they are marked as "resolved" or "closed" in the system.
                        </p>
                    </div>
                </div>
            <?php else: ?>
                <!-- Filter and Sort Options -->
                <div style="display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 200px;">
                        <select class="form-control" id="severityFilter" onchange="filterPendingReports()" style="font-size: 0.875rem;">
                            <option value="">All Severities</option>
                            <option value="low">Low Only</option>
                            <option value="medium">Medium Only</option>
                            <option value="high">High Only</option>
                        </select>
                    </div>
                    <div style="flex: 1; min-width: 200px;">
                        <select class="form-control" id="statusFilter" onchange="filterPendingReports()" style="font-size: 0.875rem;">
                            <option value="">All Statuses</option>
                            <option value="resolved">Resolved Only</option>
                            <option value="closed">Closed Only</option>
                        </select>
                    </div>
                    <div style="flex: 1; min-width: 200px;">
                        <select class="form-control" id="sortBy" onchange="sortPendingReports()" style="font-size: 0.875rem;">
                            <option value="newest">Newest First</option>
                            <option value="oldest">Oldest First</option>
                            <option value="severity">Severity (High to Low)</option>
                            <option value="location">Location (A-Z)</option>
                        </select>
                    </div>
                </div>
                
                <!-- Quick Actions Bar -->
                <div style="background: #f8fafc; padding: 12px; border-radius: 8px; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center;">
                    <div style="font-size: 0.9rem; color: #64748b;">
                        <i class="fas fa-filter" style="margin-right: 5px;"></i>
                        <span id="filterInfo">Showing all <?php echo count($pendingReports); ?> reports</span>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button class="btn btn-success btn-sm" onclick="publishAllReports()">
                            <i class="fas fa-rocket"></i> Publish All
                        </button>
                        <button class="btn btn-sm" style="background: #e5e7eb; color: #374151;" onclick="exportPendingReports()">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                </div>
                
                <div class="table-container">
                    <table id="pendingReportsTable">
                        <thead>
                            <tr>
                                <th style="width: 120px;">Report ID</th>
                                <th>Location</th>
                                <th>Description</th>
                                <th style="width: 100px;">Severity</th>
                                <th style="width: 100px;">Status</th>
                                <th style="width: 120px;">Date Resolved</th>
                                <th style="width: 150px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingReports as $report): ?>
                                <tr data-severity="<?php echo $report['severity']; ?>" data-status="<?php echo $report['status']; ?>" data-date="<?php echo $report['updated_at']; ?>" data-location="<?php echo htmlspecialchars($report['location']); ?>">
                                    <td>
                                        <strong><?php echo $report['report_id']; ?></strong>
                                        <br>
                                        <small style="color: var(--text-muted); font-size: 0.75rem;">
                                            <?php echo date('M j', strtotime($report['created_at'])); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <i class="fas fa-map-marker-alt" style="color: var(--primary); font-size: 0.8rem;"></i>
                                            <?php echo htmlspecialchars($report['location']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="max-width: 250px;">
                                            <?php echo htmlspecialchars(substr($report['description'], 0, 80)) . (strlen($report['description']) > 80 ? '...' : ''); ?>
                                            <br>
                                            <small style="color: var(--text-muted);">
                                                <?php if ($report['first_name'] && $report['last_name']): ?>
                                                    Reported by: <?php echo htmlspecialchars($report['first_name'] . ' ' . $report['last_name']); ?>
                                                <?php else: ?>
                                                    Anonymous report
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $report['severity']; ?>">
                                            <?php echo ucfirst($report['severity']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $report['status']; ?>">
                                            <?php echo ucfirst($report['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small style="color: var(--text-muted);">
                                            <?php echo date('M j, Y', strtotime($report['updated_at'])); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                            <button class="btn btn-primary btn-sm" onclick="openPublishModal(<?php echo $report['id']; ?>, '<?php echo htmlspecialchars($report['location']); ?>', '<?php echo htmlspecialchars($report['description']); ?>', '<?php echo $report['severity']; ?>')" title="Publish this report">
                                                <i class="fas fa-publish"></i> Publish
                                            </button>
                                            <button class="btn btn-sm" style="background: #f3f4f6; color: #6b7280;" onclick="viewReportDetails(<?php echo $report['id']; ?>)" title="View full details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Summary Statistics -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-top: 20px;">
                    <div style="background: #f8fafc; padding: 12px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 1.2rem; font-weight: 700; color: var(--danger);">
                            <?php echo count(array_filter($pendingReports, fn($r) => $r['severity'] === 'high')); ?>
                        </div>
                        <div style="font-size: 0.8rem; color: var(--text-muted);">High Priority</div>
                    </div>
                    <div style="background: #f8fafc; padding: 12px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 1.2rem; font-weight: 700; color: var(--warning);">
                            <?php echo count(array_filter($pendingReports, fn($r) => $r['severity'] === 'medium')); ?>
                        </div>
                        <div style="font-size: 0.8rem; color: var(--text-muted);">Medium Priority</div>
                    </div>
                    <div style="background: #f8fafc; padding: 12px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 1.2rem; font-weight: 700; color: var(--success);">
                            <?php echo count(array_filter($pendingReports, fn($r) => $r['severity'] === 'low')); ?>
                        </div>
                        <div style="font-size: 0.8rem; color: var(--text-muted);">Low Priority</div>
                    </div>
                    <div style="background: #f8fafc; padding: 12px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 1.2rem; font-weight: 700; color: var(--primary);">
                            <?php echo count(array_filter($pendingReports, fn($r) => $r['status'] === 'resolved')); ?>
                        </div>
                        <div style="font-size: 0.8rem; color: var(--text-muted);">Resolved</div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Published Reports Management -->
        <div class="content-card">
            <h2 class="section-title"><i class="fas fa-newspaper"></i> Published Reports Management</h2>
            
            <?php if (empty($publishedReports)): ?>
                <p style="color: var(--text-muted); text-align: center; padding: 40px;">
                    <i class="fas fa-file-alt" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                    No reports have been published yet.
                </p>
            <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Publication ID</th>
                                <th>Road Name</th>
                                <th>Issue Summary</th>
                                <th>Public Status</th>
                                <th>Publication Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($publishedReports as $report): ?>
                                <tr>
                                    <td><strong><?php echo $report['publication_id']; ?></strong></td>
                                    <td><?php echo htmlspecialchars($report['road_name']); ?></td>
                                    <td><?php echo htmlspecialchars($report['issue_summary']); ?></td>
                                    <td><span class="status-badge status-<?php echo str_replace('_', '-', $report['status_public']); ?>"><?php echo ucfirst(str_replace('_', ' ', $report['status_public'])); ?></span></td>
                                    <td><?php echo date('M j, Y', strtotime($report['publication_date'])); ?></td>
                                    <td>
                                        <button class="btn btn-warning btn-sm" onclick="openUpdateModal(<?php echo $report['id']; ?>, '<?php echo $report['status_public']; ?>', '<?php echo $report['completion_date']; ?>')">
                                            <i class="fas fa-edit"></i> Update
                                        </button>
                                        <button class="btn btn-danger btn-sm" onclick="openArchiveModal(<?php echo $report['id']; ?>)">
                                            <i class="fas fa-archive"></i> Archive
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Publish Modal -->
    <div id="publishModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Publish Report for Public Viewing</h3>
                <button class="close" onclick="closeModal('publishModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="publish_report">
                <input type="hidden" id="publish_damage_report_id" name="damage_report_id">
                
                <div class="form-group">
                    <label class="form-label">Road Name *</label>
                    <input type="text" id="publish_road_name" name="road_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Issue Summary *</label>
                    <textarea id="publish_issue_summary" name="issue_summary" class="form-control" required></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Issue Type *</label>
                    <select id="publish_issue_type" name="issue_type" class="form-control" required>
                        <option value="">Select Issue Type</option>
                        <option value="pothole">Pothole</option>
                        <option value="crack">Crack</option>
                        <option value="drainage">Drainage Issue</option>
                        <option value="surface_damage">Surface Damage</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Public Severity Level *</label>
                    <select id="publish_severity_public" name="severity_public" class="form-control" required>
                        <option value="">Select Severity</option>
                        <option value="low">Low</option>
                        <option value="medium">Medium</option>
                        <option value="high">High</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Public Status *</label>
                    <select id="publish_status_public" name="status_public" class="form-control" required>
                        <option value="">Select Status</option>
                        <option value="reported">Reported</option>
                        <option value="under_repair">Under Repair</option>
                        <option value="completed">Completed</option>
                        <option value="fixed">Fixed</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Date Reported *</label>
                    <input type="date" id="publish_date_reported" name="date_reported" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Repair Start Date</label>
                    <input type="date" id="publish_repair_start_date" name="repair_start_date" class="form-control">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Completion Date</label>
                    <input type="date" id="publish_completion_date" name="completion_date" class="form-control">
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn" style="background: #e5e7eb; color: #374151;" onclick="closeModal('publishModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-publish"></i> Publish Report
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Update Modal -->
    <div id="updateModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Update Publication Status</h3>
                <button class="close" onclick="closeModal('updateModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_publication">
                <input type="hidden" id="update_publication_id" name="publication_id">
                
                <div class="form-group">
                    <label class="form-label">Public Status *</label>
                    <select id="update_status_public" name="status_public" class="form-control" required>
                        <option value="reported">Reported</option>
                        <option value="under_repair">Under Repair</option>
                        <option value="completed">Completed</option>
                        <option value="fixed">Fixed</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Completion Date</label>
                    <input type="date" id="update_completion_date" name="completion_date" class="form-control">
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn" style="background: #e5e7eb; color: #374151;" onclick="closeModal('updateModal')">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-edit"></i> Update Publication
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Archive Modal -->
    <div id="archiveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Archive Publication</h3>
                <button class="close" onclick="closeModal('archiveModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="archive_publication">
                <input type="hidden" id="archive_publication_id" name="publication_id">
                
                <div class="form-group">
                    <label class="form-label">Archive Reason *</label>
                    <select id="archive_reason" name="archive_reason" class="form-control" required>
                        <option value="">Select Reason</option>
                        <option value="Report declined">Report Declined</option>
                        <option value="Information outdated">Information Outdated</option>
                        <option value="Data correction needed">Data Correction Needed</option>
                        <option value="Administrative removal">Administrative Removal</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn" style="background: #e5e7eb; color: #374151;" onclick="closeModal('archiveModal')">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-archive"></i> Archive Publication
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openPublishModal(reportId, location, description, severity) {
            document.getElementById('publish_damage_report_id').value = reportId;
            document.getElementById('publish_road_name').value = location;
            document.getElementById('publish_issue_summary').value = description;
            document.getElementById('publish_date_reported').value = new Date().toISOString().split('T')[0];
            
            // Set severity based on internal severity
            if (severity === 'critical') {
                document.getElementById('publish_severity_public').value = 'high';
            } else {
                document.getElementById('publish_severity_public').value = severity;
            }
            
            document.getElementById('publishModal').style.display = 'block';
        }

        function openUpdateModal(publicationId, currentStatus, completionDate) {
            document.getElementById('update_publication_id').value = publicationId;
            document.getElementById('update_status_public').value = currentStatus;
            if (completionDate) {
                document.getElementById('update_completion_date').value = completionDate;
            }
            document.getElementById('updateModal').style.display = 'block';
        }

        function openArchiveModal(publicationId) {
            document.getElementById('archive_publication_id').value = publicationId;
            document.getElementById('archiveModal').style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Enhanced functions for pending reports
        function refreshPendingReports() {
            location.reload();
        }

        function filterPendingReports() {
            const severityFilter = document.getElementById('severityFilter').value;
            const statusFilter = document.getElementById('statusFilter').value;
            const table = document.getElementById('pendingReportsTable');
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            let visibleCount = 0;

            for (let row of rows) {
                const severity = row.getAttribute('data-severity');
                const status = row.getAttribute('data-status');
                
                const severityMatch = !severityFilter || severity === severityFilter;
                const statusMatch = !statusFilter || status === statusFilter;
                
                if (severityMatch && statusMatch) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            }

            updateFilterInfo(visibleCount);
        }

        function sortPendingReports() {
            const sortBy = document.getElementById('sortBy').value;
            const table = document.getElementById('pendingReportsTable');
            const tbody = table.getElementsByTagName('tbody')[0];
            const rows = Array.from(tbody.getElementsByTagName('tr'));

            rows.sort((a, b) => {
                switch (sortBy) {
                    case 'newest':
                        return new Date(b.getAttribute('data-date')) - new Date(a.getAttribute('data-date'));
                    case 'oldest':
                        return new Date(a.getAttribute('data-date')) - new Date(b.getAttribute('data-date'));
                    case 'severity':
                        const severityOrder = { high: 3, medium: 2, low: 1, critical: 4 };
                        return severityOrder[b.getAttribute('data-severity')] - severityOrder[a.getAttribute('data-severity')];
                    case 'location':
                        return a.getAttribute('data-location').localeCompare(b.getAttribute('data-location'));
                    default:
                        return 0;
                }
            });

            // Re-append sorted rows
            rows.forEach(row => tbody.appendChild(row));
        }

        function updateFilterInfo(visibleCount) {
            const filterInfo = document.getElementById('filterInfo');
            const totalCount = document.getElementById('pendingReportsTable').getElementsByTagName('tbody')[0].getElementsByTagName('tr').length;
            
            if (visibleCount === totalCount) {
                filterInfo.textContent = `Showing all ${totalCount} reports`;
            } else {
                filterInfo.textContent = `Showing ${visibleCount} of ${totalCount} reports`;
            }
        }

        function publishAllReports() {
            if (confirm('Are you sure you want to publish all pending reports? This will make them visible to the public.')) {
                // Create a form to submit all reports
                const form = document.createElement('form');
                form.method = 'POST';
                
                // Add action input
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'publish_all_pending';
                form.appendChild(actionInput);
                
                // Submit the form
                document.body.appendChild(form);
                form.submit();
            }
        }

        function exportPendingReports() {
            const table = document.getElementById('pendingReportsTable');
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            let csv = 'Report ID,Location,Description,Severity,Status,Date Resolved,Reported By\n';
            
            for (let row of rows) {
                if (row.style.display !== 'none') {
                    const cells = row.getElementsByTagName('td');
                    const reportId = cells[0].textContent.trim();
                    const location = cells[1].textContent.trim();
                    const description = cells[2].textContent.trim();
                    const severity = cells[3].textContent.trim();
                    const status = cells[4].textContent.trim();
                    const dateResolved = cells[5].textContent.trim();
                    const reportedBy = cells[6].textContent.trim();
                    
                    csv += `"${reportId}","${location}","${description}","${severity}","${status}","${dateResolved}","${reportedBy}"\n`;
                }
            }
            
            // Create download link
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `pending_reports_${new Date().toISOString().split('T')[0]}.csv`;
            a.click();
            window.URL.revokeObjectURL(url);
        }

        function viewReportDetails(reportId) {
            // This could open a modal with full report details
            // For now, we'll show a simple alert
            alert(`Viewing full details for report ID: ${reportId}\n\nThis feature could show complete report information including photos, full description, and timeline.`);
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>
