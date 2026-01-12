# LGU User and Access Management Module

A comprehensive user authentication and role-based access control system for the LGU Road Infrastructure Management System.

## Features

### Authentication System
- Secure user login with password hashing
- Session management with secure tokens
- Automatic session expiration
- CSRF protection
- Password strength validation

### User Management
- Multi-step registration process
- User role assignment (Citizen, Engineer, LGU Officer, Admin)
- User status management (Pending, Approved, Active, Suspended)
- ID photo upload support

### Role-Based Access Control (RBAC)
- Hierarchical role system
- Granular permission management
- Module-based access control
- Dynamic permission assignment

### Security Features
- Secure session handling
- SQL injection prevention
- XSS protection
- Password hashing with bcrypt
- Input validation and sanitization

## Installation

### Database Setup
1. Import the database schema:
```sql
mysql -u root -p < database/setup.sql
```

### Configuration
1. Update database credentials in `config/database.php`
2. Ensure proper file permissions for uploads directory

### Default Admin Account
- Email: `admin@lgu.gov.ph`
- Password: `admin123`
- Role: Administrator

## File Structure

```
user_and_access_management_module/
├── config/
│   └── database.php          # Database configuration
├── classes/
│   ├── User.php              # User management class
│   ├── Auth.php              # Authentication class
│   ├── Permission.php        # Permission management class
│   └── AccessControl.php     # Access control class
├── api/
│   ├── login.php             # Login API endpoint
│   ├── register.php          # Registration API endpoint
│   └── logout.php            # Logout API endpoint
├── admin/
│   ├── approval.php          # User approval interface
│   └── permission_review.php # Permission management interface
├── database/
│   └── setup.sql             # Database schema
├── assets/                   # Static assets
├── styles/                   # CSS files
├── login.html                # Login/registration form
├── permission.html           # Permission review interface
└── README.md                 # This file
```

## API Endpoints

### Login
- **POST** `/api/login.php`
- **Request Body:**
  ```json
  {
    "email": "user@example.com",
    "password": "password123"
  }
  ```
- **Response:**
  ```json
  {
    "success": true,
    "message": "Login successful",
    "user": {
      "id": 1,
      "email": "user@example.com",
      "first_name": "John",
      "role": "citizen"
    },
    "redirect_url": "../citizen/dashboard.html"
  }
  ```

### Register
- **POST** `/api/register.php`
- **Request Body:**
  ```json
  {
    "email": "user@example.com",
    "password": "password123",
    "first_name": "John",
    "last_name": "Doe",
    "role": "citizen"
  }
  ```
- **Response:**
  ```json
  {
    "success": true,
    "message": "Registration successful. Your account is pending approval.",
    "user_id": 123
  }
  ```

### Logout
- **POST** `/api/logout.php`
- **Response:**
  ```json
  {
    "success": true,
    "message": "Logout successful"
  }
  ```

## User Roles and Permissions

### Role Hierarchy
1. **Citizen** - Basic access for reporting issues
2. **Engineer** - Technical access for assessments and inspections
3. **LGU Officer** - Administrative access for managing operations
4. **Admin** - Full system access

### Default Permissions by Role

#### Citizen
- Road Damage Reporting
- GIS Mapping (View)
- Public Transparency (View)

#### Engineer
- All Citizen permissions
- Road Damage Review
- GIS Mapping (Edit)
- Damage Assessment (Create)
- Inspection Workflow
- Document Management

#### LGU Officer
- All Citizen permissions
- Road Damage Review
- Damage Assessment (View)
- Inspection Workflow
- Document Management
- Public Transparency (Manage)

#### Admin
- All permissions including User Management and System Administration

## Usage Examples

### Implementing Access Control

```php
<?php
require_once 'config/database.php';
require_once 'classes/Auth.php';
require_once 'classes/AccessControl.php';

// Start session
Auth::secureSessionStart();

// Initialize database and access control
$database = new Database();
$db = $database->getConnection();
$accessControl = new AccessControl($db);

// Require login
$accessControl->requireLogin();

// Require specific role
$accessControl->requireRole('engineer');

// Require specific permission
$accessControl->requirePermission('damage_assessment_create');

// Check module access
if ($accessControl->canAccessModule('GIS Mapping')) {
    // Show GIS mapping interface
}

// Get user navigation
$navigation = $accessControl->getUserNavigation();
?>
```

### Checking User Permissions

```php
<?php
// Check if user has specific permission
if ($accessControl->hasPermission('road_damage_review')) {
    // Show review interface
}

// Check if user can manage other users
if ($accessControl->canManageUsers()) {
    // Show user management interface
}

// Get current user info
$user = $accessControl->auth->getCurrentUser();
echo "Welcome, " . $user['first_name'];
?>
```

## Security Considerations

1. **Password Security**: All passwords are hashed using bcrypt
2. **Session Security**: Secure session tokens with expiration
3. **Input Validation**: All user inputs are validated and sanitized
4. **SQL Injection Prevention**: Prepared statements used throughout
5. **XSS Protection**: Output escaping and content security headers
6. **CSRF Protection**: Token-based CSRF protection

## Integration with Other Modules

This module provides authentication and authorization services to all other modules in the LGU system:

- Road Damage Reporting Module
- GIS Mapping and Visualization Module
- Damage Assessment Module
- Inspection and Workflow Module
- Document Management Module
- Public Transparency Module

Each module should include the access control classes and implement proper permission checks before allowing access to specific features.

## Support

For technical support or questions about this module, please contact the system administrator.
