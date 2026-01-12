-- LGU Road Infrastructure Management System - Complete Database Schema
-- This schema integrates all modules with the user and access management system

-- Create database if not exists
CREATE DATABASE IF NOT EXISTS lgu_road_infra;
USE lgu_road_infra;

-- ==========================================
-- USER AND ACCESS MANAGEMENT MODULE TABLES
-- ==========================================

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100),
    last_name VARCHAR(100) NOT NULL,
    birthday DATE,
    address TEXT,
    civil_status VARCHAR(50),
    role ENUM('citizen', 'engineer', 'lgu_officer', 'admin') NOT NULL DEFAULT 'citizen',
    status ENUM('pending', 'approved', 'rejected', 'active', 'suspended') NOT NULL DEFAULT 'pending',
    id_photo_path VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- User sessions table
CREATE TABLE IF NOT EXISTS user_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_token VARCHAR(255) UNIQUE NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Permissions table
CREATE TABLE IF NOT EXISTS permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    module VARCHAR(100) NOT NULL
);

-- User permissions mapping
CREATE TABLE IF NOT EXISTS user_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    permission_id INT NOT NULL,
    granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    granted_by INT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES users(id),
    UNIQUE KEY unique_user_permission (user_id, permission_id)
);

-- Role permissions mapping
CREATE TABLE IF NOT EXISTS role_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role ENUM('citizen', 'engineer', 'lgu_officer', 'admin') NOT NULL,
    permission_id INT NOT NULL,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
    UNIQUE KEY unique_role_permission (role, permission_id)
);

-- ==========================================
-- ROAD DAMAGE REPORTING MODULE TABLES
-- ==========================================

-- Road damage reports
CREATE TABLE IF NOT EXISTS road_damage_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reporter_id INT NOT NULL,
    location_address TEXT NOT NULL,
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    damage_type ENUM('pothole', 'crack', 'erosion', 'debris', 'flooding', 'other') NOT NULL,
    severity_level ENUM('low', 'medium', 'high', 'critical') NOT NULL,
    description TEXT,
    photo_paths JSON, -- Array of photo file paths
    status ENUM('pending', 'under_review', 'assigned', 'in_progress', 'completed', 'rejected') DEFAULT 'pending',
    reported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    assigned_engineer_id INT,
    FOREIGN KEY (reporter_id) REFERENCES users(id),
    FOREIGN KEY (assigned_engineer_id) REFERENCES users(id)
);

-- Damage report updates
CREATE TABLE IF NOT EXISTS damage_report_updates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    updated_by INT NOT NULL,
    update_type ENUM('status_change', 'comment', 'photo_added') NOT NULL,
    description TEXT,
    photo_paths JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (report_id) REFERENCES road_damage_reports(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id)
);

-- ==========================================
-- GIS MAPPING AND VISUALIZATION MODULE TABLES
-- ==========================================

-- GIS map layers
CREATE TABLE IF NOT EXISTS gis_layers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    layer_name VARCHAR(100) NOT NULL,
    layer_type ENUM('road_network', 'damage_points', 'infrastructure', 'administrative', 'utilities') NOT NULL,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- GIS map features
CREATE TABLE IF NOT EXISTS gis_features (
    id INT AUTO_INCREMENT PRIMARY KEY,
    layer_id INT NOT NULL,
    feature_type ENUM('point', 'line', 'polygon') NOT NULL,
    geometry JSON NOT NULL, -- GeoJSON format
    properties JSON, -- Feature attributes
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (layer_id) REFERENCES gis_layers(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Map bookmarks
CREATE TABLE IF NOT EXISTS map_bookmarks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    bookmark_name VARCHAR(100) NOT NULL,
    center_lat DECIMAL(10, 8),
    center_lng DECIMAL(11, 8),
    zoom_level INT DEFAULT 15,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ==========================================
-- DAMAGE ASSESSMENT AND COST ESTIMATION MODULE TABLES
-- ==========================================

-- Damage assessments
CREATE TABLE IF NOT EXISTS damage_assessments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    assessor_id INT NOT NULL,
    assessment_date DATE NOT NULL,
    damage_area_sqm DECIMAL(10, 2),
    damage_depth_cm DECIMAL(5, 2),
    affected_road_length_m DECIMAL(8, 2),
    traffic_impact ENUM('none', 'minor', 'moderate', 'major', 'blocked') NOT NULL,
    urgency_level ENUM('low', 'medium', 'high', 'critical') NOT NULL,
    repair_method VARCHAR(255),
    material_requirements JSON,
    estimated_cost DECIMAL(12, 2),
    estimated_duration_days INT,
    assessment_notes TEXT,
    photo_evidence JSON,
    status ENUM('draft', 'submitted', 'approved', 'rejected') DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (report_id) REFERENCES road_damage_reports(id),
    FOREIGN KEY (assessor_id) REFERENCES users(id)
);

-- Cost estimation breakdown
CREATE TABLE IF NOT EXISTS cost_breakdown (
    id INT AUTO_INCREMENT PRIMARY KEY,
    assessment_id INT NOT NULL,
    item_category VARCHAR(100) NOT NULL,
    item_description VARCHAR(255) NOT NULL,
    quantity DECIMAL(10, 2) NOT NULL,
    unit VARCHAR(50) NOT NULL,
    unit_cost DECIMAL(10, 2) NOT NULL,
    total_cost DECIMAL(12, 2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assessment_id) REFERENCES damage_assessments(id) ON DELETE CASCADE
);

-- ==========================================
-- INSPECTION AND WORKFLOW MODULE TABLES
-- ==========================================

-- Work orders
CREATE TABLE IF NOT EXISTS work_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    assessment_id INT NOT NULL,
    work_order_number VARCHAR(50) UNIQUE NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    priority ENUM('low', 'medium', 'high', 'urgent') NOT NULL,
    assigned_to INT NOT NULL,
    assigned_by INT NOT NULL,
    scheduled_start_date DATE,
    scheduled_end_date DATE,
    actual_start_date DATETIME,
    actual_end_date DATETIME,
    status ENUM('pending', 'assigned', 'in_progress', 'completed', 'cancelled', 'on_hold') DEFAULT 'pending',
    completion_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (assessment_id) REFERENCES damage_assessments(id),
    FOREIGN KEY (assigned_to) REFERENCES users(id),
    FOREIGN KEY (assigned_by) REFERENCES users(id)
);

-- Work order updates
CREATE TABLE IF NOT EXISTS work_order_updates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    work_order_id INT NOT NULL,
    updated_by INT NOT NULL,
    update_type ENUM('status_change', 'progress_update', 'issue_reported', 'completion') NOT NULL,
    description TEXT,
    photo_paths JSON,
    completion_percentage INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (work_order_id) REFERENCES work_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id)
);

-- Inspection schedules
CREATE TABLE IF NOT EXISTS inspection_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    work_order_id INT NOT NULL,
    inspector_id INT NOT NULL,
    scheduled_date DATETIME NOT NULL,
    inspection_type ENUM('initial', 'progress', 'final', 'quality_check') NOT NULL,
    status ENUM('scheduled', 'completed', 'cancelled', 'rescheduled') DEFAULT 'scheduled',
    inspection_notes TEXT,
    photo_evidence JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (work_order_id) REFERENCES work_orders(id),
    FOREIGN KEY (inspector_id) REFERENCES users(id)
);

-- ==========================================
-- DOCUMENT AND REPORT MANAGEMENT MODULE TABLES
-- ==========================================

-- Documents
CREATE TABLE IF NOT EXISTS documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    document_type ENUM('report', 'assessment', 'photo', 'video', 'permit', 'contract', 'other') NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size_bytes INT,
    file_mime_type VARCHAR(100),
    uploaded_by INT NOT NULL,
    related_entity_type ENUM('damage_report', 'assessment', 'work_order', 'inspection', 'user') NOT NULL,
    related_entity_id INT NOT NULL,
    is_public BOOLEAN DEFAULT FALSE,
    version_number INT DEFAULT 1,
    parent_document_id INT, -- For versioning
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id),
    FOREIGN KEY (parent_document_id) REFERENCES documents(id)
);

-- Document access logs
CREATE TABLE IF NOT EXISTS document_access_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    user_id INT NOT NULL,
    action_type ENUM('viewed', 'downloaded', 'uploaded', 'updated', 'deleted') NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Report templates
CREATE TABLE IF NOT EXISTS report_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_name VARCHAR(255) NOT NULL,
    template_type ENUM('damage_assessment', 'inspection_report', 'cost_estimate', 'completion_report') NOT NULL,
    template_content TEXT, -- HTML or markdown template
    variables JSON, -- Template variables definition
    created_by INT NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- ==========================================
-- PUBLIC TRANSPARENCY MODULE TABLES
-- ==========================================

-- Public announcements
CREATE TABLE IF NOT EXISTS public_announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    announcement_type ENUM('road_closure', 'maintenance', 'project_update', 'emergency', 'general') NOT NULL,
    priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    start_date DATETIME NOT NULL,
    end_date DATETIME,
    affected_areas JSON, -- Array of affected locations
    is_active BOOLEAN DEFAULT TRUE,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Project status updates
CREATE TABLE IF NOT EXISTS project_status_updates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_name VARCHAR(255) NOT NULL,
    project_phase ENUM('planning', 'assessment', 'procurement', 'construction', 'inspection', 'completion') NOT NULL,
    status_description TEXT NOT NULL,
    completion_percentage INT DEFAULT 0,
    estimated_completion_date DATE,
    budget_utilized DECIMAL(12, 2),
    total_budget DECIMAL(12, 2),
    photo_updates JSON,
    is_public BOOLEAN DEFAULT TRUE,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Public feedback
CREATE TABLE IF NOT EXISTS public_feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    feedback_type ENUM('complaint', 'suggestion', 'compliment', 'inquiry') NOT NULL,
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    reporter_name VARCHAR(100),
    reporter_email VARCHAR(255),
    reporter_phone VARCHAR(20),
    status ENUM('pending', 'under_review', 'resolved', 'closed') DEFAULT 'pending',
    response TEXT,
    responded_by INT,
    responded_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (responded_by) REFERENCES users(id)
);

-- ==========================================
-- INSERT DEFAULT DATA
-- ==========================================

-- Insert default permissions
INSERT INTO permissions (name, description, module) VALUES
-- Road Damage Reporting
('road_damage_reporting', 'Can report road damage issues', 'Road Damage Reporting'),
('road_damage_review', 'Can review and approve road damage reports', 'Road Damage Reporting'),
('road_damage_assign', 'Can assign damage reports to engineers', 'Road Damage Reporting'),

-- GIS Mapping
('gis_mapping_view', 'Can view GIS mapping interface', 'GIS Mapping'),
('gis_mapping_edit', 'Can edit and update GIS maps', 'GIS Mapping'),
('gis_mapping_admin', 'Can manage GIS layers and features', 'GIS Mapping'),

-- Damage Assessment
('damage_assessment_view', 'Can view damage assessment reports', 'Damage Assessment'),
('damage_assessment_create', 'Can create damage assessment reports', 'Damage Assessment'),
('damage_assessment_approve', 'Can approve assessment reports', 'Damage Assessment'),

-- Inspection & Workflow
('inspection_workflow', 'Can manage inspection workflows', 'Inspection & Workflow'),
('work_order_create', 'Can create and assign work orders', 'Inspection & Workflow'),
('work_order_manage', 'Can manage work order status', 'Inspection & Workflow'),

-- Document Management
('document_management', 'Can manage system documents', 'Document Management'),
('document_upload', 'Can upload documents', 'Document Management'),
('document_view_public', 'Can view public documents', 'Document Management'),

-- Public Transparency
('public_transparency_view', 'Can view public transparency data', 'Public Transparency'),
('public_transparency_manage', 'Can manage public transparency data', 'Public Transparency'),
('public_feedback_manage', 'Can manage public feedback', 'Public Transparency'),

-- User Management
('user_management', 'Can manage user accounts and permissions', 'User Management'),
('user_approval', 'Can approve user registrations', 'User Management'),

-- System Administration
('system_administration', 'Full system administration access', 'System');

-- Insert default role permissions
INSERT INTO role_permissions (role, permission_id) VALUES
-- Citizen permissions
('citizen', 1), -- road_damage_reporting
('citizen', 4), -- gis_mapping_view
('citizen', 13), -- public_transparency_view
('citizen', 15), -- document_view_public

-- Engineer permissions
('engineer', 1), -- road_damage_reporting
('engineer', 2), -- road_damage_review
('engineer', 4), -- gis_mapping_view
('engineer', 5), -- gis_mapping_edit
('engineer', 7), -- damage_assessment_view
('engineer', 8), -- damage_assessment_create
('engineer', 10), -- inspection_workflow
('engineer', 11), -- work_order_create
('engineer', 12), -- work_order_manage
('engineer', 13), -- document_management
('engineer', 14), -- document_upload
('engineer', 15), -- public_transparency_view

-- LGU Officer permissions
('lgu_officer', 1), -- road_damage_reporting
('lgu_officer', 2), -- road_damage_review
('lgu_officer', 3), -- road_damage_assign
('lgu_officer', 4), -- gis_mapping_view
('lgu_officer', 7), -- damage_assessment_view
('lgu_officer', 9), -- damage_assessment_approve
('lgu_officer', 10), -- inspection_workflow
('lgu_officer', 11), -- work_order_create
('lgu_officer', 12), -- work_order_manage
('lgu_officer', 13), -- document_management
('lgu_officer', 14), -- document_upload
('lgu_officer', 15), -- public_transparency_view
('lgu_officer', 16), -- public_transparency_manage
('lgu_officer', 17), -- public_feedback_manage

-- Admin permissions (all)
('admin', 1), ('admin', 2), ('admin', 3), ('admin', 4), ('admin', 5), ('admin', 6), 
('admin', 7), ('admin', 8), ('admin', 9), ('admin', 10), ('admin', 11), ('admin', 12), 
('admin', 13), ('admin', 14), ('admin', 15), ('admin', 16), ('admin', 17), ('admin', 18), 
('admin', 19), ('admin', 20);

-- Create default admin user (password: admin123)
INSERT INTO users (email, password, first_name, middle_name, last_name, role, status) VALUES
('admin@lgu.gov.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System', '', 'Administrator', 'admin', 'active');

-- Create indexes for better performance
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_role ON users(role);
CREATE INDEX idx_users_status ON users(status);
CREATE INDEX idx_damage_reports_status ON road_damage_reports(status);
CREATE INDEX idx_damage_reports_reporter ON road_damage_reports(reporter_id);
CREATE INDEX idx_damage_reports_assigned ON road_damage_reports(assigned_engineer_id);
CREATE INDEX idx_assessments_report ON damage_assessments(report_id);
CREATE INDEX idx_assessments_assessor ON damage_assessments(assessor_id);
CREATE INDEX idx_work_orders_status ON work_orders(status);
CREATE INDEX idx_work_orders_assigned ON work_orders(assigned_to);
CREATE INDEX idx_documents_related ON documents(related_entity_type, related_entity_id);
CREATE INDEX idx_documents_type ON documents(document_type);
CREATE INDEX idx_announcements_active ON public_announcements(is_active);
CREATE INDEX idx_feedback_status ON public_feedback(status);

-- Create views for common queries
CREATE VIEW v_active_damage_reports AS
SELECT r.*, u.first_name, u.last_name, u.email as reporter_email
FROM road_damage_reports r
JOIN users u ON r.reporter_id = u.id
WHERE r.status IN ('pending', 'under_review', 'assigned', 'in_progress');

CREATE VIEW v_work_order_summary AS
SELECT 
    wo.id,
    wo.work_order_number,
    wo.title,
    wo.status,
    wo.priority,
    wo.scheduled_start_date,
    wo.scheduled_end_date,
    CONCAT(u.first_name, ' ', u.last_name) as assigned_to_name,
    da.damage_area_sqm,
    da.estimated_cost
FROM work_orders wo
JOIN users u ON wo.assigned_to = u.id
JOIN damage_assessments da ON wo.assessment_id = da.id;

CREATE VIEW v_user_permissions_summary AS
SELECT 
    u.id,
    u.email,
    u.first_name,
    u.last_name,
    u.role,
    u.status,
    COUNT(up.permission_id) as user_permission_count,
    COUNT(rp.permission_id) as role_permission_count
FROM users u
LEFT JOIN user_permissions up ON u.id = up.user_id
LEFT JOIN role_permissions rp ON u.role = rp.role
GROUP BY u.id;
