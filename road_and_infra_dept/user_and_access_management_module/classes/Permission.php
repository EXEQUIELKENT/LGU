<?php
class Permission {
    private $conn;
    public $permissions_table = "permissions";
    public $user_permissions_table = "user_permissions";
    public $role_permissions_table = "role_permissions";
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    // Get all permissions
    public function getAllPermissions() {
        $query = "SELECT * FROM " . $this->permissions_table . " ORDER BY module, name";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get permissions by module
    public function getPermissionsByModule($module) {
        $query = "SELECT * FROM " . $this->permissions_table . " 
                 WHERE module = :module ORDER BY name";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":module", $module);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get user permissions
    public function getUserPermissions($user_id) {
        $query = "SELECT p.* FROM " . $this->permissions_table . " p
                 JOIN " . $this->user_permissions_table . " up ON p.id = up.permission_id
                 WHERE up.user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":user_id", $user_id);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Add permission to user
    public function addPermission($user_id, $permission_name) {
        $query = "INSERT INTO " . $this->user_permissions_table . " (user_id, permission_id) 
                 VALUES (:user_id, :permission_id)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":user_id", $user_id);
        $stmt->bindParam(":permission_id", $permission_name);
        return $stmt->execute();
    }
    
    // Get role permissions
    public function getRolePermissions($role) {
        $query = "SELECT p.* FROM " . $this->permissions_table . " p
                 JOIN " . $this->role_permissions_table . " rp ON p.id = rp.permission_id
                 WHERE rp.role = :role";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":role", $role);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Check if user has specific permission
    public function hasPermission($user_id, $permission_name) {
        // First check user-specific permissions
        $query = "SELECT COUNT(*) as count FROM " . $this->permissions_table . " p
                 JOIN " . $this->user_permissions_table . " up ON p.id = up.permission_id
                 WHERE up.user_id = :user_id AND p.name = :permission_name";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":user_id", $user_id);
        $stmt->bindParam(":permission_name", $permission_name);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result['count'] > 0) {
            return true;
        }
        
        // If no user-specific permission, check role permissions
        $query = "SELECT COUNT(*) as count FROM " . $this->permissions_table . " p
                 JOIN " . $this->role_permissions_table . " rp ON p.id = rp.permission_id
                 JOIN users u ON u.role = rp.role
                 WHERE u.id = :user_id AND p.name = :permission_name";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":user_id", $user_id);
        $stmt->bindParam(":permission_name", $permission_name);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    }
    
    // Grant permission to user
    public function grantUserPermission($user_id, $permission_id, $granted_by = null) {
        // Check if permission already exists
        $query = "SELECT id FROM " . $this->user_permissions_table . " 
                 WHERE user_id = :user_id AND permission_id = :permission_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":user_id", $user_id);
        $stmt->bindParam(":permission_id", $permission_id);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            return false; // Permission already exists
        }
        
        // Insert new permission
        $query = "INSERT INTO " . $this->user_permissions_table . " 
                 (user_id, permission_id, granted_by) 
                 VALUES (:user_id, :permission_id, :granted_by)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":user_id", $user_id);
        $stmt->bindParam(":permission_id", $permission_id);
        $stmt->bindParam(":granted_by", $granted_by);
        
        return $stmt->execute();
    }
    
    // Revoke permission from user
    public function revokeUserPermission($user_id, $permission_id) {
        $query = "DELETE FROM " . $this->user_permissions_table . " 
                 WHERE user_id = :user_id AND permission_id = :permission_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":user_id", $user_id);
        $stmt->bindParam(":permission_id", $permission_id);
        
        return $stmt->execute();
    }
    
    // Get all available modules
    public function getModules() {
        $query = "SELECT DISTINCT module FROM " . $this->permissions_table . " ORDER BY module";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    // Get user effective permissions (user + role permissions)
    public function getUserEffectivePermissions($user_id) {
        $query = "SELECT DISTINCT p.* FROM " . $this->permissions_table . " p
                 LEFT JOIN " . $this->user_permissions_table . " up ON p.id = up.permission_id AND up.user_id = :user_id
                 LEFT JOIN " . $this->role_permissions_table . " rp ON p.id = rp.permission_id
                 LEFT JOIN users u ON u.id = :user_id AND u.role = rp.role
                 WHERE up.user_id IS NOT NULL OR rp.role IS NOT NULL
                 ORDER BY p.module, p.name";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":user_id", $user_id);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
