# LGU Road Infrastructure Management System - Database Schema

This comprehensive database schema integrates all modules within the road_and_infra_dept folder with a unified user and access management system.

## Database Structure Overview

### Core Modules Integrated:
1. **User and Access Management** - Authentication, roles, and permissions
2. **Road Damage Reporting** - Public damage reporting and tracking
3. **GIS Mapping and Visualization** - Geographic information system
4. **Damage Assessment and Cost Estimation** - Technical assessments and cost calculations
5. **Inspection and Workflow** - Work order management and inspections
6. **Document and Report Management** - File storage and versioning
7. **Public Transparency** - Public announcements and feedback

## Installation Instructions

### 1. Database Setup
```bash
# Create the database and import schema
mysql -u root -p < complete_schema.sql
```

### 2. Verify Installation
```sql
-- Check if all tables were created
SHOW TABLES;

-- Verify default admin user
SELECT * FROM users WHERE email = 'admin@lgu.gov.ph';

-- Check permissions setup
SELECT COUNT(*) as total_permissions FROM permissions;
SELECT COUNT(*) as role_permissions FROM role_permissions;
```

## Table Relationships

### User Management Core
- `users` - Central user table linked to all modules
- `permissions` - Granular permission definitions
- `user_permissions` - User-specific permission overrides
- `role_permissions` - Role-based permission assignments

### Module Integration Flow
1. **Road Damage Reports** → **Damage Assessments** → **Work Orders** → **Inspections**
2. All entities link back to `users` for accountability
3. `documents` table stores files for all modules
4. `gis_features` provide geographic context for all location-based data

## Key Features

### Security & Access Control
- **Hierarchical Roles**: Citizen → Engineer → LGU Officer → Admin
- **Granular Permissions**: 20 specific permissions across all modules
- **Session Management**: Secure token-based authentication
- **Audit Trails**: Comprehensive logging for document access and system changes

### Data Integrity
- **Foreign Key Constraints**: Maintains referential integrity
- **Status Workflows**: Proper state management for all processes
- **Version Control**: Document versioning with parent-child relationships
- **JSON Fields**: Flexible storage for arrays and complex data

### Performance Optimization
- **Strategic Indexes**: Optimized for common query patterns
- **Database Views**: Pre-computed complex queries
- **Proper Data Types**: Optimized storage and performance

## Module-Specific Tables

### Road Damage Reporting
- `road_damage_reports` - Main damage reports with location data
- `damage_report_updates` - Status change tracking and communication

### GIS Mapping
- `gis_layers` - Organized map layers by type
- `gis_features` - GeoJSON feature storage
- `map_bookmarks` - User-saved map views

### Damage Assessment
- `damage_assessments` - Technical evaluation data
- `cost_breakdown` - Detailed cost estimation items

### Inspection & Workflow
- `work_orders` - Task assignment and tracking
- `work_order_updates` - Progress monitoring
- `inspection_schedules` - Inspection planning and results

### Document Management
- `documents` - Centralized file storage with metadata
- `document_access_logs` - Complete audit trail
- `report_templates` - Standardized reporting formats

### Public Transparency
- `public_announcements` - Public communications
- `project_status_updates` - Project progress tracking
- `public_feedback` - Citizen engagement system

## Default Credentials

### Admin Account
- **Email**: admin@lgu.gov.ph
- **Password**: admin123
- **Role**: Administrator (full system access)

## Permission Matrix

| Module | Citizen | Engineer | LGU Officer | Admin |
|--------|---------|----------|-------------|-------|
| Road Damage Reporting | ✅ Report | ✅ Report & Review | ✅ Report, Review & Assign | ✅ All |
| GIS Mapping | ✅ View | ✅ View & Edit | ✅ View | ✅ All |
| Damage Assessment | ❌ | ✅ View & Create | ✅ View & Approve | ✅ All |
| Inspection & Workflow | ❌ | ✅ Manage | ✅ Manage | ✅ All |
| Document Management | ✅ View Public | ✅ Upload & Manage | ✅ Upload & Manage | ✅ All |
| Public Transparency | ✅ View | ✅ View | ✅ Manage | ✅ All |
| User Management | ❌ | ❌ | ❌ | ✅ All |

## Data Flow Examples

### Typical Road Damage Process
1. **Citizen** reports damage via `road_damage_reports`
2. **LGU Officer** reviews and assigns to **Engineer**
3. **Engineer** creates `damage_assessments` with cost estimates
4. **LGU Officer** approves assessment and creates `work_orders`
5. **Engineer** performs work and logs updates
6. **Inspector** conducts quality checks via `inspection_schedules`
7. **Documents** are uploaded at each stage for evidence

### Geographic Integration
- All location-based data includes lat/lng coordinates
- GIS features can be linked to damage reports, work orders, and inspections
- Map bookmarks allow users to save frequently accessed areas

### Document Versioning
- Each upload creates a new document record
- `parent_document_id` links versions together
- Access logs track all document interactions

## Maintenance & Backup

### Regular Maintenance
```sql
-- Clean expired sessions (run daily)
DELETE FROM user_sessions WHERE expires_at < NOW();

-- Archive old completed work orders (run monthly)
UPDATE work_orders SET status = 'archived' 
WHERE status = 'completed' AND updated_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);
```

### Backup Strategy
- **Daily**: Full database backup
- **Hourly**: Transaction log backup
- **Real-time**: Replication for high availability

## API Integration Points

The database schema supports the following API endpoints:
- Authentication and session management
- CRUD operations for all entities
- File upload and document management
- Geographic data queries
- Reporting and analytics

## Security Considerations

1. **Input Validation**: All inputs should be validated at application level
2. **SQL Injection Prevention**: Use prepared statements
3. **Access Control**: Implement permission checks in application logic
4. **Data Encryption**: Sensitive data should be encrypted at rest
5. **Audit Logging**: All sensitive operations are logged

## Troubleshooting

### Common Issues
- **Foreign Key Constraints**: Ensure data is inserted in correct order
- **JSON Fields**: Use proper JSON functions for data manipulation
- **Permissions**: Verify role and permission assignments

### Performance Tuning
- Monitor slow query logs
- Analyze execution plans for complex queries
- Consider partitioning for large tables (documents, gis_features)

## Future Enhancements

Potential additions to consider:
- Real-time notifications system
- Mobile API endpoints
- Advanced analytics and reporting
- Integration with external mapping services
- Automated workflow triggers
