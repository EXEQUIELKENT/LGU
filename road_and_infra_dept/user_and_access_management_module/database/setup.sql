-- LGU Road Infrastructure Database Schema
-- User and Access Management Module

CREATE DATABASE IF NOT EXISTS lgu_road_infra;
USE lgu_road_infra;

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

-- Insert default permissions
INSERT INTO permissions (name, description, module) VALUES
('road_damage_reporting', 'Can report road damage issues', 'Road Damage Reporting'),
('road_damage_review', 'Can review and approve road damage reports', 'Road Damage Reporting'),
('gis_mapping_view', 'Can view GIS mapping interface', 'GIS Mapping'),
('gis_mapping_edit', 'Can edit and update GIS maps', 'GIS Mapping'),
('damage_assessment_view', 'Can view damage assessment reports', 'Damage Assessment'),
('damage_assessment_create', 'Can create damage assessment reports', 'Damage Assessment'),
('inspection_workflow', 'Can manage inspection workflows', 'Inspection & Workflow'),
('document_management', 'Can manage system documents', 'Document Management'),
('public_transparency_view', 'Can view public transparency data', 'Public Transparency'),
('public_transparency_manage', 'Can manage public transparency data', 'Public Transparency'),
('user_management', 'Can manage user accounts and permissions', 'User Management'),
('system_administration', 'Full system administration access', 'System');

-- Insert default role permissions
INSERT INTO role_permissions (role, permission_id) VALUES
-- Citizen permissions
('citizen', 1), -- road_damage_reporting
('citizen', 3), -- gis_mapping_view
('citizen', 9), -- public_transparency_view

-- Engineer permissions
('engineer', 1), -- road_damage_reporting
('engineer', 2), -- road_damage_review
('engineer', 3), -- gis_mapping_view
('engineer', 4), -- gis_mapping_edit
('engineer', 5), -- damage_assessment_view
('engineer', 6), -- damage_assessment_create
('engineer', 7), -- inspection_workflow
('engineer', 8), -- document_management
('engineer', 9), -- public_transparency_view

-- LGU Officer permissions
('lgu_officer', 1), -- road_damage_reporting
('lgu_officer', 2), -- road_damage_review
('lgu_officer', 3), -- gis_mapping_view
('lgu_officer', 5), -- damage_assessment_view
('lgu_officer', 7), -- inspection_workflow
('lgu_officer', 8), -- document_management
('lgu_officer', 9), -- public_transparency_view
('lgu_officer', 10), -- public_transparency_manage

-- Admin permissions (all)
('admin', 1), ('admin', 2), ('admin', 3), ('admin', 4), ('admin', 5), ('admin', 6), ('admin', 7), ('admin', 8), ('admin', 9), ('admin', 10), ('admin', 11), ('admin', 12);

-- Create default admin user (password: admin123)
INSERT INTO users (email, password, first_name, middle_name, last_name, role, status) VALUES
('admin@lgu.gov.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System', '', 'Administrator', 'admin', 'active');
