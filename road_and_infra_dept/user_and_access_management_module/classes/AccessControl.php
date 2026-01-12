<?php
require_once 'Auth.php';
require_once 'Permission.php';

class AccessControl {
    private $auth;
    private $permission;
    private $conn;
    
    public function __construct($db) {
        $this->conn = $db;
        $this->auth = new Auth($db);
        $this->permission = new Permission($db);
    }
    
    // Check if current user can access a module
    public function canAccessModule($module_name) {
        if (!$this->auth->isLoggedIn()) {
            return false;
        }
        
        $user = $this->auth->getCurrentUser();
        $permissions = $this->permission->getUserEffectivePermissions($user['id']);
        
        foreach ($permissions as $permission) {
            if (strtolower($permission['module']) === strtolower($module_name)) {
                return true;
            }
        }
        
        return false;
    }
    
    // Check if current user has specific permission
    public function hasPermission($permission_name) {
        if (!$this->auth->isLoggedIn()) {
            return false;
        }
        
        $user = $this->auth->getCurrentUser();
        return $this->permission->hasPermission($user['id'], $permission_name);
    }
    
    // Require module access (redirect if no access)
    public function requireModuleAccess($module_name) {
        if (!$this->canAccessModule($module_name)) {
            header('HTTP/1.0 403 Forbidden');
            echo '<h1>403 Forbidden</h1><p>You do not have permission to access this module.</p>';
            exit();
        }
    }
    
    // Require specific permission
    public function requirePermission($permission_name) {
        if (!$this->hasPermission($permission_name)) {
            header('HTTP/1.0 403 Forbidden');
            echo '<h1>403 Forbidden</h1><p>You do not have the required permission.</p>';
            exit();
        }
    }
    
    // Get user's accessible modules
    public function getUserModules() {
        if (!$this->auth->isLoggedIn()) {
            return [];
        }
        
        $user = $this->auth->getCurrentUser();
        $permissions = $this->permission->getUserEffectivePermissions($user['id']);
        
        $modules = [];
        foreach ($permissions as $permission) {
            if (!in_array($permission['module'], $modules)) {
                $modules[] = $permission['module'];
            }
        }
        
        return array_unique($modules);
    }
    
    // Get navigation menu based on user permissions
    public function getUserNavigation() {
        if (!$this->auth->isLoggedIn()) {
            return [];
        }
        
        $user = $this->auth->getCurrentUser();
        $permissions = $this->permission->getUserEffectivePermissions($user['id']);
        
        $navigation = [];
        
        // Group permissions by module
        $modules = [];
        foreach ($permissions as $permission) {
            if (!isset($modules[$permission['module']])) {
                $modules[$permission['module']] = [];
            }
            $modules[$permission['module']][] = $permission;
        }
        
        // Build navigation structure
        foreach ($modules as $module_name => $module_permissions) {
            $navigation[] = [
                'module' => $module_name,
                'permissions' => $module_permissions,
                'url' => $this->getModuleUrl($module_name)
            ];
        }
        
        return $navigation;
    }
    
    // Get module URL based on module name
    private function getModuleUrl($module_name) {
        $urls = [
            'Road Damage Reporting' => '../road_damage_reporting_module/index.php',
            'GIS Mapping' => '../gis_mapping_and_visualization_module/index.php',
            'Damage Assessment' => '../damage_assesment_and_cost_estiation_module/index.php',
            'Inspection & Workflow' => '../inspection_and_workflow_module/index.php',
            'Document Management' => '../document_and_report_management_module/index.php',
            'Public Transparency' => '../public_transparency_module/index.php',
            'User Management' => '../user_and_access_management_module/admin.php'
        ];
        
        return $urls[$module_name] ?? '#';
    }
    
    // Check if user can manage other users
    public function canManageUsers() {
        return $this->hasPermission('user_management') || $this->auth->hasRole('admin');
    }
    
    // Check if user can approve registrations
    public function canApproveRegistrations() {
        return $this->hasPermission('user_management') || $this->auth->hasRole('admin');
    }
    
    // Filter data based on user permissions
    public function filterDataByPermission($data, $permission_field = 'required_permission') {
        if (!$this->auth->isLoggedIn()) {
            return [];
        }
        
        $user = $this->auth->getCurrentUser();
        $user_permissions = $this->permission->getUserEffectivePermissions($user['id']);
        $permission_names = array_column($user_permissions, 'name');
        
        $filtered_data = [];
        foreach ($data as $item) {
            if (!isset($item[$permission_field]) || 
                in_array(strtolower($item[$permission_field]), array_map('strtolower', $permission_names))) {
                $filtered_data[] = $item;
            }
        }
        
        return $filtered_data;
    }
    
    // Get dashboard URL based on user role
    public function getDashboardUrl() {
        if (!$this->auth->isLoggedIn()) {
            return 'login.html';
        }
        
        $user = $this->auth->getCurrentUser();
        
        switch($user['role']) {
            case 'admin':
                return '../admin/dashboard.php';
            case 'lgu_officer':
                return '../lgu_officer/dashboard.html';
            case 'engineer':
                return '../engineer/dashboard.html';
            case 'citizen':
                return '../citizen/dashboard.html';
            default:
                return '../dashboard.php';
        }
    }
    
    // Add permission for a role
    public function addPermission($role, $module, $permission) {
        // Validate inputs
        if (empty($role) || empty($module) || empty($permission)) {
            throw new InvalidArgumentException("Role, module, and permission are required");
        }
        
        // Start transaction
        $this->conn->beginTransaction();
        
        try {
            // First check if permission exists
            $query = "SELECT id FROM " . $this->permission->permissions_table . " 
                     WHERE module = :module AND name = :permission LIMIT 1";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":module", $module);
            $stmt->bindParam(":permission", $permission);
            $stmt->execute();
            
            $permission_id = null;
            if ($stmt->rowCount() > 0) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $permission_id = $row['id'];
            } else {
                // Create new permission
                $query = "INSERT INTO " . $this->permission->permissions_table . " 
                         (module, name, description) VALUES (:module, :permission, :description)";
                
                $stmt = $this->conn->prepare($query);
                $description = ucfirst($permission) . " permission for " . $module;
                $stmt->bindParam(":module", $module);
                $stmt->bindParam(":permission", $permission);
                $stmt->bindParam(":description", $description);
                
                if ($stmt->execute()) {
                    $permission_id = $this->conn->lastInsertId();
                } else {
                    throw new Exception("Failed to create permission");
                }
            }
            
            if ($permission_id) {
                // Check if role permission already exists
                $query = "SELECT id FROM " . $this->permission->role_permissions_table . " 
                         WHERE role = :role AND permission_id = :permission_id LIMIT 1";
                
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(":role", $role);
                $stmt->bindParam(":permission_id", $permission_id);
                $stmt->execute();
                
                if ($stmt->rowCount() === 0) {
                    // Add role permission
                    $query = "INSERT INTO " . $this->permission->role_permissions_table . " 
                             (role, permission_id) VALUES (:role, :permission_id)";
                    
                    $stmt = $this->conn->prepare($query);
                    $stmt->bindParam(":role", $role);
                    $stmt->bindParam(":permission_id", $permission_id);
                    
                    if ($stmt->execute()) {
                        $this->conn->commit();
                        return true;
                    } else {
                        throw new Exception("Failed to assign permission to role");
                    }
                } else {
                    // Permission already exists for this role
                    $this->conn->commit();
                    return false;
                }
            }
            
            throw new Exception("Failed to create or find permission");
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }
    
    // Remove permission
    public function removePermission($permission_id) {
        // Validate input
        if (empty($permission_id) || !is_numeric($permission_id)) {
            throw new InvalidArgumentException("Valid permission ID is required");
        }
        
        // Start transaction
        $this->conn->beginTransaction();
        
        try {
            // Remove from role permissions
            $query = "DELETE FROM " . $this->permission->role_permissions_table . " 
                     WHERE permission_id = :permission_id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":permission_id", $permission_id);
            $role_result = $stmt->execute();
            
            // Remove from user permissions
            $query = "DELETE FROM " . $this->permission->user_permissions_table . " 
                     WHERE permission_id = :permission_id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":permission_id", $permission_id);
            $user_result = $stmt->execute();
            
            // Remove the permission itself
            $query = "DELETE FROM " . $this->permission->permissions_table . " 
                     WHERE id = :permission_id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":permission_id", $permission_id);
            $permission_result = $stmt->execute();
            
            if ($role_result && $user_result && $permission_result) {
                $this->conn->commit();
                return true;
            } else {
                throw new Exception("Failed to remove permission completely");
            }
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }
    
    // Get all permissions
    public function getAllPermissions() {
        $query = "SELECT p.id, p.module, p.name as permission, p.description, rp.role 
                 FROM " . $this->permission->permissions_table . " p
                 LEFT JOIN " . $this->permission->role_permissions_table . " rp ON p.id = rp.permission_id
                 ORDER BY p.module, p.name, rp.role";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        $permissions = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $permissions[] = [
                'id' => $row['id'],
                'role' => $row['role'],
                'module' => $row['module'],
                'permission' => $row['permission'],
                'description' => $row['description']
            ];
        }
        
        return $permissions;
    }
}
?>
