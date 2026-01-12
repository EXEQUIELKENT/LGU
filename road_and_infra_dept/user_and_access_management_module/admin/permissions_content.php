<?php
// Permission management content
require_once '../config/database.php';
require_once '../classes/Permission.php';

$database = new Database();
$db = $database->getConnection();

$permission = new Permission($db);
$allPermissions = $permission->getAllPermissions();
$modules = $permission->getModules();
?>

<div class="card">
    <h3>Permission Management</h3>
    <p style="color: #666; margin-bottom: 20px;">Manage system permissions and role assignments.</p>
    
    <div class="page-tabs">
        <a href="?page=permissions&view=modules" class="tab-btn">Modules</a>
        <a href="?page=permissions&view=roles" class="tab-btn">Role Permissions</a>
        <a href="?page=permissions&view=users" class="tab-btn">User Permissions</a>
    </div>
    
    <?php
    $view = $_GET['view'] ?? 'modules';
    
    if ($view === 'modules') {
        // Show modules overview
        echo '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 20px;">';
        
        foreach ($modules as $module) {
            $modulePermissions = array_filter($allPermissions, function($p) use ($module) {
                return $p['module'] === $module;
            });
            
            echo '<div class="card" style="padding: 20px;">';
            echo '<h4 style="color: #3762c8; margin-bottom: 15px;">📁 ' . htmlspecialchars($module) . '</h4>';
            echo '<p style="color: #666; margin-bottom: 10px;">' . count($modulePermissions) . ' permissions</p>';
            echo '<button class="control-btn" onclick="viewModulePermissions(\'' . htmlspecialchars($module) . '\')">View Permissions</button>';
            echo '</div>';
        }
        
        echo '</div>';
        
    } elseif ($view === 'roles') {
        // Show role permissions
        $roles = ['citizen', 'engineer', 'lgu_officer', 'admin'];
        
        echo '<div style="margin-top: 20px;">';
        echo '<table style="width: 100%; border-collapse: collapse;">';
        echo '<thead><tr style="background: #f8f9fa;"><th style="padding: 12px; text-align: left;">Role</th><th style="padding: 12px; text-align: left;">Permissions</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($roles as $role) {
            $rolePermissions = $permission->getRolePermissions($role);
            echo '<tr><td style="padding: 12px; font-weight: 600;">' . ucfirst(htmlspecialchars($role)) . '</td>';
            echo '<td style="padding: 12px;">' . count($rolePermissions) . ' permissions</td></tr>';
        }
        
        echo '</tbody></table></div>';
        
    } elseif ($view === 'users') {
        // Show user permissions (placeholder)
        echo '<div style="text-align: center; padding: 50px; color: #666;">';
        echo '<h4>👥 User Permission Management</h4>';
        echo '<p>Individual user permission assignment would be implemented here.</p>';
        echo '<p>This would allow overriding default role permissions for specific users.</p>';
        echo '</div>';
    }
    ?>
</div>

<script>
function viewModulePermissions(module) {
    alert('View detailed permissions for module: ' + module);
}
</script>
