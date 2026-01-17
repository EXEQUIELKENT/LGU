# LGU Road and Infrastructure Department - Database Documentation

## Overview
This document describes the complete database structure for the LGU Road and Infrastructure Department system.

## Database Name: `lgu_road_infra`

## Tables Structure

### 1. Users Table (`users`)
**Purpose**: Stores user accounts and authentication information
**Columns**:
- `id` (INT, PRIMARY KEY) - Unique user identifier
- `first_name` (VARCHAR 50) - User's first name
- `middle_name` (VARCHAR 50, NULLABLE) - User's middle name
- `last_name` (VARCHAR 50) - User's last name
- `email` (VARCHAR 100, UNIQUE) - User's email address
- `password` (VARCHAR 255) - Hashed password
- `role` (ENUM) - User role: 'admin', 'lgu_officer', 'engineer', 'citizen'
- `status` (ENUM) - Account status: 'active', 'inactive', 'suspended', 'pending'
- `email_verified` (BOOLEAN) - Email verification status
- `phone` (VARCHAR 20, NULLABLE) - Phone number
- `address` (TEXT, NULLABLE) - Physical address
- `created_at` (TIMESTAMP) - Account creation date
- `updated_at` (TIMESTAMP) - Last update date
- `last_login` (TIMESTAMP, NULLABLE) - Last login timestamp

### 2. Damage Reports Table (`damage_reports`)
**Purpose**: Stores road damage reports submitted by users
**Columns**:
- `id` (INT, PRIMARY KEY) - Unique report identifier
- `report_id` (VARCHAR 20, UNIQUE) - Human-readable report ID
- `reporter_id` (INT, FOREIGN KEY) - References users.id
- `location` (VARCHAR 255) - Damage location
- `description` (TEXT) - Detailed damage description
- `severity` (ENUM) - 'low', 'medium', 'high', 'critical'
- `status` (ENUM) - 'pending', 'in_progress', 'resolved', 'closed'
- `latitude` (DECIMAL 10,8) - GPS latitude
- `longitude` (DECIMAL 11,8) - GPS longitude
- `estimated_cost` (DECIMAL 10,2) - Estimated repair cost
- `images` (TEXT) - JSON array of image paths
- `reported_at` (TIMESTAMP) - Report submission date
- `updated_at` (TIMESTAMP) - Last update date
- `assigned_to` (INT, FOREIGN KEY) - Assigned engineer/user

### 3. Cost Assessments Table (`cost_assessments`)
**Purpose**: Stores cost analysis for damage reports
**Columns**:
- `id` (INT, PRIMARY KEY) - Unique assessment identifier
- `assessment_id` (VARCHAR 20, UNIQUE) - Human-readable assessment ID
- `damage_report_id` (INT, FOREIGN KEY) - References damage_reports.id
- `assessor_id` (INT, FOREIGN KEY) - References users.id
- `labor_cost` (DECIMAL 10,2) - Labor cost estimate
- `material_cost` (DECIMAL 10,2) - Material cost estimate
- `equipment_cost` (DECIMAL 10,2) - Equipment cost estimate
- `total_cost` (DECIMAL 10,2) - Total estimated cost
- `assessment_notes` (TEXT) - Detailed assessment notes
- `status` (ENUM) - 'draft', 'submitted', 'approved', 'rejected'
- `assessment_date` (TIMESTAMP) - Assessment creation date
- `approved_by` (INT, FOREIGN KEY) - Approving authority
- `approved_at` (TIMESTAMP, NULLABLE) - Approval timestamp

### 4. Inspection Reports Table (`inspection_reports`)
**Purpose**: Stores inspection schedules and results
**Columns**:
- `id` (INT, PRIMARY KEY) - Unique inspection identifier
- `inspection_id` (VARCHAR 20, UNIQUE) - Human-readable inspection ID
- `inspector_id` (INT, FOREIGN KEY) - References users.id
- `damage_report_id` (INT, FOREIGN KEY) - References damage_reports.id
- `location` (VARCHAR 255) - Inspection location
- `inspection_type` (ENUM) - 'initial', 'follow_up', 'final', 'special'
- `findings` (TEXT) - Inspection findings
- `recommendations` (TEXT) - Repair recommendations
- `inspection_status` (ENUM) - 'scheduled', 'in_progress', 'completed', 'cancelled'
- `scheduled_date` (DATE) - Scheduled inspection date
- `completed_date` (TIMESTAMP, NULLABLE) - Completion timestamp
- `next_inspection_date` (DATE, NULLABLE) - Follow-up inspection date
- `priority` (ENUM) - 'low', 'medium', 'high', 'urgent'
- `images` (TEXT) - JSON array of inspection images
- `created_at` (TIMESTAMP) - Inspection creation date
- `updated_at` (TIMESTAMP) - Last update date

### 5. GIS Data Table (`gis_data`)
**Purpose**: Stores geographic information system data
**Columns**:
- `id` (INT, PRIMARY KEY) - Unique feature identifier
- `feature_id` (VARCHAR 20, UNIQUE) - Human-readable feature ID
- `feature_type` (ENUM) - 'damage', 'infrastructure', 'maintenance', 'zone'
- `name` (VARCHAR 255) - Feature name
- `description` (TEXT, NULLABLE) - Feature description
- `latitude` (DECIMAL 10,8) - GPS latitude
- `longitude` (DECIMAL 11,8) - GPS longitude
- `properties` (JSON, NULLABLE) - Additional feature properties
- `status` (ENUM) - 'active', 'inactive', 'maintenance'
- `created_by` (INT, FOREIGN KEY) - References users.id
- `created_at` (TIMESTAMP) - Feature creation date
- `updated_at` (TIMESTAMP) - Last update date

### 6. Documents Table (`documents`)
**Purpose**: Stores uploaded documents and files
**Columns**:
- `id` (INT, PRIMARY KEY) - Unique document identifier
- `document_id` (VARCHAR 20, UNIQUE) - Human-readable document ID
- `title` (VARCHAR 255) - Document title
- `description` (TEXT, NULLABLE) - Document description
- `document_type` (ENUM) - 'report', 'image', 'video', 'pdf', 'other'
- `category` (ENUM) - 'damage_report', 'cost_assessment', 'inspection', 'maintenance', 'general'
- `file_path` (VARCHAR 500) - Server file path
- `file_size` (BIGINT, NULLABLE) - File size in bytes
- `mime_type` (VARCHAR 100, NULLABLE) - MIME type
- `uploaded_by` (INT, FOREIGN KEY) - References users.id
- `related_id` (INT, NULLABLE) - Related record ID
- `related_type` (VARCHAR 50, NULLABLE) - Related record type
- `is_public` (BOOLEAN) - Public visibility flag
- `download_count` (INT) - Download counter
- `created_at` (TIMESTAMP) - Upload timestamp
- `updated_at` (TIMESTAMP) - Last update date

### 7. Maintenance Schedule Table (`maintenance_schedule`)
**Purpose**: Stores maintenance tasks and schedules
**Columns**:
- `id` (INT, PRIMARY KEY) - Unique task identifier
- `task_id` (VARCHAR 20, UNIQUE) - Human-readable task ID
- `task_name` (VARCHAR 255) - Task description
- `description` (TEXT, NULLABLE) - Detailed task description
- `location` (VARCHAR 255) - Task location
- `task_type` (ENUM) - 'routine', 'emergency', 'inspection', 'repair'
- `priority` (ENUM) - 'low', 'medium', 'high', 'urgent'
- `status` (ENUM) - 'scheduled', 'in_progress', 'completed', 'cancelled', 'postponed'
- `scheduled_date` (DATETIME) - Scheduled date and time
- `estimated_duration` (INT, NULLABLE) - Estimated duration in minutes
- `assigned_to` (INT, FOREIGN KEY) - References users.id
- `completed_date` (TIMESTAMP, NULLABLE) - Completion timestamp
- `actual_duration` (INT, NULLABLE) - Actual duration in minutes
- `cost` (DECIMAL 10,2, NULLABLE) - Actual cost
- `materials_used` (TEXT, NULLABLE) - Materials used description
- `notes` (TEXT, NULLABLE) - Additional notes
- `created_by` (INT, FOREIGN KEY) - References users.id
- `created_at` (TIMESTAMP) - Task creation date
- `updated_at` (TIMESTAMP) - Last update date

### 8. Public Announcements Table (`public_announcements`)
**Purpose**: Stores public announcements and notifications
**Columns**:
- `id` (INT, PRIMARY KEY) - Unique announcement identifier
- `announcement_id` (VARCHAR 20, UNIQUE) - Human-readable announcement ID
- `title` (VARCHAR 255) - Announcement title
- `content` (TEXT) - Announcement content
- `announcement_type` (ENUM) - 'general', 'maintenance', 'alert', 'holiday'
- `priority` (ENUM) - 'low', 'medium', 'high', 'urgent'
- `is_active` (BOOLEAN) - Active status flag
- `start_date` (TIMESTAMP) - Announcement start date
- `end_date` (TIMESTAMP, NULLABLE) - Announcement end date
- `target_audience` (ENUM) - 'all', 'citizens', 'staff', 'engineers', 'lgu_officers'
- `created_by` (INT, FOREIGN KEY) - References users.id
- `created_at` (TIMESTAMP) - Creation timestamp
- `updated_at` (TIMESTAMP) - Last update date

## Supporting Tables

### 9. Login Attempts Table (`login_attempts`)
**Purpose**: Tracks login attempts for security monitoring
**Columns**:
- `id` (INT, PRIMARY KEY) - Unique attempt identifier
- `email` (VARCHAR 100) - Login email used
- `ip_address` (VARCHAR 45, NULLABLE) - Client IP address
- `success` (BOOLEAN) - Login success status
- `user_agent` (TEXT, NULLABLE) - Browser user agent
- `attempt_time` (TIMESTAMP) - Attempt timestamp

### 10. User Sessions Table (`user_sessions`)
**Purpose**: Active user session management
**Columns**:
- `id` (INT, PRIMARY KEY) - Unique session identifier
- `user_id` (INT, FOREIGN KEY) - References users.id
- `session_id` (VARCHAR 255, UNIQUE) - PHP session ID
- `ip_address` (VARCHAR 45, NULLABLE) - Client IP address
- `user_agent` (TEXT, NULLABLE) - Browser user agent
- `created_at` (TIMESTAMP) - Session creation time
- `expires_at` (TIMESTAMP) - Session expiration time
- `is_active` (BOOLEAN) - Session active status

### 11. User Activity Log Table (`user_activity_log`)
**Purpose**: Audit trail for user actions
**Columns**:
- `id` (INT, PRIMARY KEY) - Unique activity identifier
- `user_id` (INT, FOREIGN KEY) - References users.id
- `activity_type` (VARCHAR 50) - Type of activity performed
- `activity_description` (TEXT, NULLABLE) - Activity description
- `ip_address` (VARCHAR 45, NULLABLE) - Client IP address
- `user_agent` (TEXT, NULLABLE) - Browser user agent
- `created_at` (TIMESTAMP) - Activity timestamp

## Default Users

### Admin Account
- **Email**: admin@lgu.gov.ph
- **Password**: password
- **Role**: admin
- **Status**: active

### Sample Users
- **Juan De la Cruz**: engineer@lgu.gov.ph (pending)
- **Maria Reyes**: citizen@example.com (active)
- **Carlos Garcia**: lgu_officer@lgu.gov.ph (active)

## Sample Data

### Damage Reports
- **DR-2025-001**: Commonwealth Avenue - High severity - ₱150,000

### Cost Assessments
- **CA-2025-001**: Approved - ₱150,000 total cost

### Inspection Reports
- **IN-2025-001**: Commonwealth Avenue - Completed - High priority

## Module Integration

### Road Damage Reporting Module
- Uses: `damage_reports`, `documents`, `gis_data`
- Features: Report submission, image uploads, GPS mapping

### Cost Assessment Module
- Uses: `damage_reports`, `cost_assessments`, `documents`
- Features: Cost calculation, approval workflow, PDF generation

### Inspection & Workflow Module
- Uses: `inspection_reports`, `damage_reports`, `maintenance_schedule`
- Features: Scheduling, mobile inspections, workflow management

### GIS Mapping Module
- Uses: `gis_data`, `damage_reports`, `documents`
- Features: Interactive maps, layer management, spatial analysis

### Document Management Module
- Uses: `documents` (all categories)
- Features: File upload, version control, access permissions

### Public Transparency Module
- Uses: `public_announcements`, `maintenance_schedule`
- Features: Public announcements, holiday schedules, service alerts

### User & Access Management Module
- Uses: `users`, `login_attempts`, `user_activity_log`
- Features: User registration, role management, audit trails

## Security Features

1. **Password Hashing**: Uses bcrypt for secure password storage
2. **Session Management**: Secure session handling with expiration
3. **Activity Logging**: Complete audit trail of all user actions
4. **Login Attempt Tracking**: Monitors failed login attempts
5. **Role-Based Access**: Granular permissions by user role
6. **Data Validation**: Input sanitization and validation

## Performance Considerations

1. **Indexing**: All foreign keys and frequently queried columns are indexed
2. **Storage**: JSON fields for flexible data storage
3. **Normalization**: Proper relationships between tables
4. **Scalability**: Designed for high-volume data processing

## Backup and Maintenance

1. **Regular Backups**: Daily automated backups recommended
2. **Log Rotation**: Archive old activity logs periodically
3. **Data Cleanup**: Remove expired sessions and old logs
4. **Performance Monitoring**: Monitor query performance and table sizes

---

**Last Updated**: January 10, 2026
**Database Version**: 1.0
**Compatible with**: MariaDB 10.4+, MySQL 5.7+
