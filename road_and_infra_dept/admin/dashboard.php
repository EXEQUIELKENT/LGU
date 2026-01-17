<?php
// Start session and include authentication
session_start();
require_once '../config/auth.php';

// Require admin role to access this page
$auth->requireRole('admin');

// Include database connection
require_once '../config/database.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - LGU Portal</title>
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }

        .main-content {
            margin-left: 250px;
            padding: 2rem;
            min-height: 100vh;
            position: relative;
            z-index: 1;
        }
        
        .header {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            padding: 1rem 2rem;
            margin-left: 250px;
            position: relative;
            z-index: 1;
        }

        .header-content h1 {
            color: white;
            font-size: 2rem;
            font-weight: 600;
            margin: 0 0 0.5rem 0;
        }

        .header-content p {
            color: rgba(255, 255, 255, 0.8);
            margin: 0;
            font-size: 1rem;
        }

        .tabs-container {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            margin-left: 250px;
            position: relative;
            z-index: 1;
        }

        .tab-button {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 500;
            transition: all 0.3s;
            backdrop-filter: blur(10px);
        }

        .tab-button.active {
            background: rgba(255, 255, 255, 0.2);
            border-color: rgba(255, 255, 255, 0.4);
        }

        .tab-button:hover {
            background: rgba(255, 255, 255, 0.15);
        }

        .status-cards-container {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 2rem;
            margin-left: 250px;
            position: relative;
            z-index: 1;
        }

        .status-card {
            background: rgba(255, 255, 255, 0.95);
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            text-align: center;
            min-width: 150px;
        }

        .status-card.pending {
            border-left: 4px solid #f59e0b;
        }

        .status-card.accepted {
            border-left: 4px solid #10b981;
        }

        .status-card div:first-child {
            font-size: 0.875rem;
            color: #64748b;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .status-card .count {
            font-size: 2rem;
            font-weight: 700;
            color: #1e293b;
        }
        
        .dashboard-section {
            background: rgba(255, 255, 255, 0.95);
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            margin-left: 250px;
            position: relative;
            z-index: 1;
        }
        
        .dashboard-section h3 {
            margin: 0 0 1.5rem 0;
            color: #1e293b;
            font-size: 1.25rem;
            font-weight: 600;
        }

        .table-controls {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .sort-filter {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .sort-filter label {
            font-size: 0.875rem;
            color: #64748b;
            font-weight: 500;
        }

        .sort-filter select {
            padding: 0.5rem;
            border: 1px solid rgba(0, 0, 0, 0.1);
            border-radius: 6px;
            background: white;
            font-size: 0.875rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
        }

        th {
            background: rgba(102, 126, 234, 0.1);
            font-weight: 600;
            color: #1e293b;
            font-size: 0.875rem;
        }

        tr:hover {
            background: rgba(102, 126, 234, 0.05);
        }

        .btn-primary {
            background: #667eea;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            font-size: 0.875rem;
            transition: background 0.3s;
        }

        .btn-primary:hover {
            background: #5a67d8;
        }

        .btn-primary.view-details {
            background: #10b981;
        }

        .btn-primary.view-details:hover {
            background: #059669;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 1rem;
            margin-top: 2rem;
        }

        .pagination button {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            backdrop-filter: blur(10px);
            transition: background 0.3s;
        }

        .pagination button:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .pagination span {
            color: white;
            font-size: 0.875rem;
        }

        /* Quicklinks Section */
        .quicklinks-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
            margin-left: 250px;
            position: relative;
            z-index: 1;
        }

        .quicklink-card {
            background: rgba(255, 255, 255, 0.95);
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            text-decoration: none;
            color: inherit;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .quicklink-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
            transition: height 0.3s ease;
        }

        .quicklink-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
        }

        .quicklink-card:hover::before {
            height: 8px;
        }

        .quicklink-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
            font-size: 1.5rem;
            color: white;
        }

        .quicklink-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }

        .quicklink-description {
            font-size: 0.875rem;
            color: #64748b;
            line-height: 1.5;
            margin-bottom: 1rem;
        }

        .quicklink-stats {
            display: flex;
            gap: 1rem;
            font-size: 0.75rem;
            color: #94a3b8;
        }

        .quicklink-stat {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .quicklink-arrow {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            color: #667eea;
            font-size: 1.25rem;
            transition: transform 0.3s ease;
        }

        .quicklink-card:hover .quicklink-arrow {
            transform: translateX(5px);
        }

        /* Module specific colors */
        .quicklink-card.damage::before { background: linear-gradient(90deg, #ef4444, #dc2626); }
        .quicklink-card.damage .quicklink-icon { background: linear-gradient(135deg, #ef4444, #dc2626); }
        .quicklink-card.damage .quicklink-arrow { color: #ef4444; }

        .quicklink-card.cost::before { background: linear-gradient(90deg, #10b981, #059669); }
        .quicklink-card.cost .quicklink-icon { background: linear-gradient(135deg, #10b981, #059669); }
        .quicklink-card.cost .quicklink-arrow { color: #10b981; }

        .quicklink-card.inspection::before { background: linear-gradient(90deg, #f59e0b, #d97706); }
        .quicklink-card.inspection .quicklink-icon { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .quicklink-card.inspection .quicklink-arrow { color: #f59e0b; }

        .quicklink-card.gis::before { background: linear-gradient(90deg, #8b5cf6, #7c3aed); }
        .quicklink-card.gis .quicklink-icon { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }
        .quicklink-card.gis .quicklink-arrow { color: #8b5cf6; }

        .quicklink-card.maintenance::before { background: linear-gradient(90deg, #06b6d4, #0891b2); }
        .quicklink-card.maintenance .quicklink-icon { background: linear-gradient(135deg, #06b6d4, #0891b2); }
        .quicklink-card.maintenance .quicklink-arrow { color: #06b6d4; }

        .quicklink-card.documents::before { background: linear-gradient(90deg, #64748b, #475569); }
        .quicklink-card.documents .quicklink-icon { background: linear-gradient(135deg, #64748b, #475569); }
        .quicklink-card.documents .quicklink-arrow { color: #64748b; }
    </style>
</head>
<body>
    <!-- Include sidebar -->
    <?php require_once '../sidebar/sidebar.php'; ?>

    <div class="header">
        <div class="header-content">
            <h1 class="header-title">Admin Dashboard</h1>
            <p>Manage and monitor all LGU Road and Infrastructure modules</p>
        </div>
    </div>

    <!-- Module Quicklinks Section -->
    <div class="quicklinks-container">
        <a href="../damage_assesment_and_cost_estiation_module/damage_and_cost_dashboard.php" class="quicklink-card damage">
            <div class="quicklink-icon">🚧</div>
            <div class="quicklink-title">Damage Assessment</div>
            <div class="quicklink-description">Manage road damage reports and cost estimations</div>
            <div class="quicklink-stats">
                <div class="quicklink-stat">📊 24 Reports</div>
                <div class="quicklink-stat">⏱️ 7 Pending</div>
            </div>
            <div class="quicklink-arrow">→</div>
        </a>

        <a href="../document_and_report_management_module/damage_and_report_management.php" class="quicklink-card documents">
            <div class="quicklink-icon">📄</div>
            <div class="quicklink-title">Document Management</div>
            <div class="quicklink-description">Upload and manage reports, images, and documents</div>
            <div class="quicklink-stats">
                <div class="quicklink-stat">📁 156 Files</div>
                <div class="quicklink-stat">📈 12 New</div>
            </div>
            <div class="quicklink-arrow">→</div>
        </a>

        <a href="../gis_mapping_and_visualization_module/mapping.php" class="quicklink-card gis">
            <div class="quicklink-icon">🗺️</div>
            <div class="quicklink-title">GIS Mapping</div>
            <div class="quicklink-description">Visualize infrastructure and damage locations</div>
            <div class="quicklink-stats">
                <div class="quicklink-stat">📍 89 Features</div>
                <div class="quicklink-stat">🔍 5 Active</div>
            </div>
            <div class="quicklink-arrow">→</div>
        </a>

        <a href="../damage_assesment_and_cost_estiation_module/damage_and_cost_dashboard.php#cost-assessment" class="quicklink-card cost">
            <div class="quicklink-icon">💰</div>
            <div class="quicklink-title">Cost Assessment</div>
            <div class="quicklink-description">Review and approve project cost estimates</div>
            <div class="quicklink-stats">
                <div class="quicklink-stat">₱ 2.4M Total</div>
                <div class="quicklink-stat">📝 18 Reviews</div>
            </div>
            <div class="quicklink-arrow">→</div>
        </a>

        <a href="../damage_assesment_and_cost_estiation_module/damage_and_cost_dashboard.php#inspection" class="quicklink-card inspection">
            <div class="quicklink-icon">🔍</div>
            <div class="quicklink-title">Inspection Reports</div>
            <div class="quicklink-description">Schedule and track infrastructure inspections</div>
            <div class="quicklink-stats">
                <div class="quicklink-stat">📋 32 Reports</div>
                <div class="quicklink-stat">📅 5 Scheduled</div>
            </div>
            <div class="quicklink-arrow">→</div>
        </a>

        <a href="../damage_assesment_and_cost_estiation_module/damage_and_cost_dashboard.php#maintenance" class="quicklink-card maintenance">
            <div class="quicklink-icon">🔧</div>
            <div class="quicklink-title">Maintenance Schedule</div>
            <div class="quicklink-description">Plan and track maintenance activities</div>
            <div class="quicklink-stats">
                <div class="quicklink-stat">🗓️ 15 Tasks</div>
                <div class="quicklink-stat">⚡ 3 Urgent</div>
            </div>
            <div class="quicklink-arrow">→</div>
        </a>
    </div>

    
    
    <!-- Analytics Dashboard Section -->
    <div class="dashboard-section">
        <h3>Module Analytics Overview</h3>
        <div class="module-stats">
            <div class="module-stat">
                <h4>Road Damage Reports</h4>
                <div class="number">24</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: 75%;"></div>
                </div>
            </div>
            <div class="module-stat">
                <h4>Cost Assessments</h4>
                <div class="number">18</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: 60%;"></div>
                </div>
            </div>
            <div class="module-stat">
                <h4>Inspections</h4>
                <div class="number">32</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: 85%;"></div>
                </div>
            </div>
            <div class="module-stat">
                <h4>GIS Mapping</h4>
                <div class="number">15</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: 45%;"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="dashboard-section">
        <h3>Monthly Activity Trends</h3>
        <div class="chart-container">
            <canvas id="monthlyTrendsChart" width="400" height="200"></canvas>
        </div>
        <div class="chart-legend">
            <div class="legend-item">
                <div class="legend-color" style="background: #667eea;"></div>
                <span>Road Damage</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: #10b981;"></div>
                <span>Cost Assessment</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: #f59e0b;"></div>
                <span>Inspections</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: #ef4444;"></div>
                <span>GIS Mapping</span>
            </div>
        </div>
    </div>

    <div class="dashboard-section">
        <h3>Module Distribution</h3>
        <div class="chart-container">
            <canvas id="moduleDistributionChart" width="400" height="200"></canvas>
        </div>
    </div>

    <div class="dashboard-section">
        <h3>Recent System Activity</h3>
        <div class="activity-feed">
            <div class="activity-item">
                <div class="activity-icon new"></div>
                <div class="activity-text">New road damage report submitted - Commonwealth Avenue</div>
            </div>
            <div class="activity-item">
                <div class="activity-icon update"></div>
                <div class="activity-text">Cost assessment updated - Project #22</div>
            </div>
            <div class="activity-item">
                <div class="activity-icon new"></div>
                <div class="activity-text">New engineer registration approved</div>
            </div>
            <div class="activity-item">
                <div class="activity-icon update"></div>
                <div class="activity-text">GIS mapping completed - Area B</div>
            </div>
            <div class="activity-item">
                <div class="activity-icon delete"></div>
                <div class="activity-text">Old inspection report archived</div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Monthly Trends Line Chart
        const monthlyCtx = document.getElementById('monthlyTrendsChart').getContext('2d');
        new Chart(monthlyCtx, {
            type: 'line',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                datasets: [{
                    label: 'Road Damage',
                    data: [12, 19, 15, 25, 22, 24],
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    tension: 0.4
                }, {
                    label: 'Cost Assessment',
                    data: [8, 12, 10, 18, 14, 18],
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    tension: 0.4
                }, {
                    label: 'Inspections',
                    data: [15, 25, 20, 30, 28, 32],
                    borderColor: '#f59e0b',
                    backgroundColor: 'rgba(245, 158, 11, 0.1)',
                    tension: 0.4
                }, {
                    label: 'GIS Mapping',
                    data: [5, 8, 12, 10, 13, 15],
                    borderColor: '#ef4444',
                    backgroundColor: 'rgba(239, 68, 68, 0.1)',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        // Module Distribution Doughnut Chart
        const distributionCtx = document.getElementById('moduleDistributionChart').getContext('2d');
        new Chart(distributionCtx, {
            type: 'doughnut',
            data: {
                labels: ['Road Damage', 'Cost Assessment', 'Inspections', 'GIS Mapping'],
                datasets: [{
                    data: [24, 18, 32, 15],
                    backgroundColor: [
                        '#667eea',
                        '#10b981',
                        '#f59e0b',
                        '#ef4444'
                    ],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    </script>
</body>
</html>
