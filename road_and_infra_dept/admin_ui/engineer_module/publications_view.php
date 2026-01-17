<?php
// Publications View - Engineer Module
// View publications created by LGU officers
session_start();
require_once '../config/auth.php';
require_once '../config/database.php';

// Require engineer role
$auth->requireRole('engineer');

// Get publication ID from URL if specified
$publicationId = isset($_GET['id']) ? (int)$_GET['id'] : null;

// Get publications data
$database = new Database();
$conn = $database->getConnection();

// Get all publications
$publications = [];
$stmt = $conn->prepare("
    SELECT pp.*, u.first_name, u.last_name
    FROM public_publications pp
    LEFT JOIN users u ON pp.published_by = u.id
    WHERE pp.is_published = 1 
    AND pp.archived = 0
    ORDER BY pp.publication_date DESC
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $publications[] = $row;
}

// Get specific publication if ID is provided
$specificPublication = null;
if ($publicationId) {
    $stmt = $conn->prepare("
        SELECT pp.*, u.first_name, u.last_name
        FROM public_publications pp
        LEFT JOIN users u ON pp.published_by = u.id
        WHERE pp.id = ? AND pp.is_published = 1 AND pp.archived = 0
    ");
    $stmt->bind_param("i", $publicationId);
    $stmt->execute();
    $result = $stmt->get_result();
    $specificPublication = $result->fetch_assoc();
}

// Get statistics
$stats = [
    'total' => count($publications),
    'completed' => 0,
    'under_repair' => 0,
    'reported' => 0,
    'high_severity' => 0
];

foreach ($publications as $pub) {
    if ($pub['status_public'] === 'completed' || $pub['status_public'] === 'fixed') {
        $stats['completed']++;
    } elseif ($pub['status_public'] === 'under_repair') {
        $stats['under_repair']++;
    } elseif ($pub['status_public'] === 'reported') {
        $stats['reported']++;
    }
    
    if ($pub['severity_public'] === 'high') {
        $stats['high_severity']++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Publications View | Engineer Module</title>
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
            padding: 30px 40px;
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

        .stat-icon.total { background: var(--primary); }
        .stat-icon.completed { background: var(--success); }
        .stat-icon.repair { background: var(--warning); }
        .stat-icon.high { background: var(--danger); }

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

        /* Publication Detail */
        .publication-detail {
            background: #f8fafc;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 20px;
            border-left: 4px solid var(--primary);
        }

        .publication-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }

        .publication-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 8px;
        }

        .publication-meta {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 15px;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.9rem;
            color: var(--text-muted);
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

        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-reported { background: #fef3c7; color: #92400e; }
        .status-under-repair { background: #fef3c7; color: #92400e; }
        .status-completed { background: #dcfce7; color: #166534; }
        .status-fixed { background: #dcfce7; color: #166534; }

        .severity-low { background: #dbeafe; color: #1e40af; }
        .severity-medium { background: #fef3c7; color: #92400e; }
        .severity-high { background: #fecaca; color: #dc2626; }

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

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.8rem;
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
    <?php include 'sidebar_engineer.php'; ?>

    <main class="main-content">
        <header class="module-header">
            <h1><i class="fas fa-newspaper"></i> Publications View</h1>
            <div style="background: rgba(255,255,255,0.1); padding: 5px 15px; border-radius: 20px; color: #fff; font-size: 0.8rem; display: inline-block; margin-top: 5px;">
                <i class="fas fa-sync"></i> Live Updates
            </div>
            <p style="margin-top: 10px;">View and monitor public infrastructure publications from LGU officers</p>
            <hr class="header-divider">
        </header>

        <!-- Statistics Overview -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon total">
                    <i class="fas fa-newspaper"></i>
                </div>
                <div class="stat-value"><?php echo $stats['total']; ?></div>
                <div class="stat-label">Total Publications</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon completed">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value"><?php echo $stats['completed']; ?></div>
                <div class="stat-label">Completed Works</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon repair">
                    <i class="fas fa-tools"></i>
                </div>
                <div class="stat-value"><?php echo $stats['under_repair']; ?></div>
                <div class="stat-label">Under Repair</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon high">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-value"><?php echo $stats['high_severity']; ?></div>
                <div class="stat-label">High Priority</div>
            </div>
        </div>

        <?php if ($specificPublication): ?>
            <!-- Specific Publication Detail -->
            <div class="content-card">
                <h2 class="section-title">
                    <i class="fas fa-file-alt"></i> Publication Details
                    <a href="publications_view.php" class="btn btn-primary btn-sm" style="margin-left: auto;">
                        <i class="fas fa-arrow-left"></i> Back to All
                    </a>
                </h2>
                
                <div class="publication-detail">
                    <div class="publication-header">
                        <div>
                            <div class="publication-title"><?php echo htmlspecialchars($specificPublication['publication_id']); ?></div>
                            <div class="publication-meta">
                                <div class="meta-item">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?php echo htmlspecialchars($specificPublication['road_name']); ?>
                                </div>
                                <div class="meta-item">
                                    <i class="fas fa-calendar"></i>
                                    <?php echo date('M j, Y', strtotime($specificPublication['publication_date'])); ?>
                                </div>
                                <div class="meta-item">
                                    <i class="fas fa-user"></i>
                                    <?php echo htmlspecialchars($specificPublication['first_name'] . ' ' . $specificPublication['last_name']); ?>
                                </div>
                            </div>
                        </div>
                        <div>
                            <span class="status-badge status-<?php echo str_replace('_', '-', $specificPublication['status_public']); ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $specificPublication['status_public'])); ?>
                            </span>
                            <span class="status-badge severity-<?php echo $specificPublication['severity_public']; ?>" style="margin-left: 8px;">
                                <?php echo ucfirst($specificPublication['severity_public']); ?> Severity
                            </span>
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <h4 style="margin-bottom: 10px; color: var(--text-main);">Issue Summary</h4>
                        <p style="color: var(--text-muted); line-height: 1.6;">
                            <?php echo htmlspecialchars($specificPublication['issue_summary']); ?>
                        </p>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                        <div>
                            <h5 style="margin-bottom: 8px; color: var(--text-muted); font-size: 0.9rem;">Issue Type</h5>
                            <p style="font-weight: 600;"><?php echo ucfirst(str_replace('_', ' ', $specificPublication['issue_type'])); ?></p>
                        </div>
                        <div>
                            <h5 style="margin-bottom: 8px; color: var(--text-muted); font-size: 0.9rem;">Date Reported</h5>
                            <p style="font-weight: 600;"><?php echo date('M j, Y', strtotime($specificPublication['date_reported'])); ?></p>
                        </div>
                        <?php if ($specificPublication['repair_start_date']): ?>
                        <div>
                            <h5 style="margin-bottom: 8px; color: var(--text-muted); font-size: 0.9rem;">Repair Start Date</h5>
                            <p style="font-weight: 600;"><?php echo date('M j, Y', strtotime($specificPublication['repair_start_date'])); ?></p>
                        </div>
                        <?php endif; ?>
                        <?php if ($specificPublication['completion_date']): ?>
                        <div>
                            <h5 style="margin-bottom: 8px; color: var(--text-muted); font-size: 0.9rem;">Completion Date</h5>
                            <p style="font-weight: 600;"><?php echo date('M j, Y', strtotime($specificPublication['completion_date'])); ?></p>
                        </div>
                        <?php endif; ?>
                        <?php if ($specificPublication['repair_duration_days']): ?>
                        <div>
                            <h5 style="margin-bottom: 8px; color: var(--text-muted); font-size: 0.9rem;">Repair Duration</h5>
                            <p style="font-weight: 600;"><?php echo $specificPublication['repair_duration_days']; ?> days</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- All Publications List -->
        <div class="content-card">
            <h2 class="section-title">
                <i class="fas fa-list"></i> All Publications
                <?php if ($specificPublication): ?>
                    <a href="publications_view.php" class="btn btn-primary btn-sm" style="margin-left: auto;">
                        <i class="fas fa-arrow-left"></i> Back to All
                    </a>
                <?php endif; ?>
            </h2>
            
            <?php if (empty($publications)): ?>
                <div style="text-align: center; padding: 60px 20px;">
                    <i class="fas fa-newspaper" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 15px; display: block;"></i>
                    <h3 style="color: var(--text-main); margin-bottom: 10px;">No Publications Available</h3>
                    <p style="color: var(--text-muted);">No publications have been created by LGU officers yet.</p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Publication ID</th>
                                <th>Road Name</th>
                                <th>Issue Summary</th>
                                <th>Type</th>
                                <th>Severity</th>
                                <th>Status</th>
                                <th>Publication Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($publications as $publication): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($publication['publication_id']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($publication['road_name']); ?></td>
                                    <td><?php echo htmlspecialchars(substr($publication['issue_summary'], 0, 50)) . '...'; ?></td>
                                    <td><?php echo ucfirst(str_replace('_', ' ', $publication['issue_type'])); ?></td>
                                    <td><span class="status-badge severity-<?php echo $publication['severity_public']; ?>"><?php echo ucfirst($publication['severity_public']); ?></span></td>
                                    <td><span class="status-badge status-<?php echo str_replace('_', '-', $publication['status_public']); ?>"><?php echo ucfirst(str_replace('_', ' ', $publication['status_public'])); ?></span></td>
                                    <td><?php echo date('M j, Y', strtotime($publication['publication_date'])); ?></td>
                                    <td>
                                        <a href="publications_view.php?id=<?php echo $publication['id']; ?>" class="btn btn-primary btn-sm">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
