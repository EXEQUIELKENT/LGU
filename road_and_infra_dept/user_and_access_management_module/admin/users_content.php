<?php
// User management content
require_once '../config/database.php';
require_once '../classes/User.php';

$database = new Database();
$db = $database->getConnection();

$user = new User($db);
$allUsers = $user->getAllUsers();

// Filter by status
$filter = $_GET['filter'] ?? 'all';
$filteredUsers = $allUsers;

if ($filter !== 'all') {
    $filteredUsers = array_filter($allUsers, function($u) use ($filter) {
        return $u['status'] === $filter;
    });
}
?>

<div style="margin-bottom: 20px;">
    <div class="page-tabs">
        <a href="?page=users&filter=all" class="tab-btn <?php echo $filter === 'all' ? 'active' : ''; ?>">All Users</a>
        <a href="?page=users&filter=active" class="tab-btn <?php echo $filter === 'active' ? 'active' : ''; ?>">Active</a>
        <a href="?page=users&filter=pending" class="tab-btn <?php echo $filter === 'pending' ? 'active' : ''; ?>">Pending</a>
        <a href="?page=users&filter=suspended" class="tab-btn <?php echo $filter === 'suspended' ? 'active' : ''; ?>">Suspended</a>
    </div>
</div>

<div class="card">
    <h3>User Management (<?php echo count($filteredUsers); ?> users)</h3>
    
    <?php if (empty($filteredUsers)): ?>
        <p style="color: #666; text-align: center; padding: 20px;">No users found</p>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; margin-top: 10px;">
                <thead>
                    <tr style="background: #f8f9fa; border-bottom: 2px solid #3762c8;">
                        <th style="padding: 12px; text-align: left; font-weight: 600;">Name</th>
                        <th style="padding: 12px; text-align: left; font-weight: 600;">Email</th>
                        <th style="padding: 12px; text-align: left; font-weight: 600;">Role</th>
                        <th style="padding: 12px; text-align: left; font-weight: 600;">Status</th>
                        <th style="padding: 12px; text-align: left; font-weight: 600;">Registered</th>
                        <th style="padding: 12px; text-align: center; font-weight: 600;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($filteredUsers as $user_item): ?>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 12px;">
                                <?php echo htmlspecialchars($user_item['first_name'] . ' ' . $user_item['last_name']); ?>
                            </td>
                            <td style="padding: 12px;"><?php echo htmlspecialchars($user_item['email']); ?></td>
                            <td style="padding: 12px;">
                                <span style="background: #e9ecef; padding: 4px 8px; border-radius: 4px; font-size: 12px;">
                                    <?php echo ucfirst(htmlspecialchars($user_item['role'])); ?>
                                </span>
                            </td>
                            <td style="padding: 12px;">
                                <?php
                                $status_colors = [
                                    'active' => '#28a745',
                                    'pending' => '#ffc107',
                                    'suspended' => '#dc3545',
                                    'rejected' => '#6c757d'
                                ];
                                $color = $status_colors[$user_item['status']] ?? '#6c757d';
                                ?>
                                <span style="background: <?php echo $color; ?>; color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px;">
                                    <?php echo ucfirst(htmlspecialchars($user_item['status'])); ?>
                                </span>
                            </td>
                            <td style="padding: 12px;"><?php echo date('M d, Y', strtotime($user_item['created_at'])); ?></td>
                            <td style="padding: 12px; text-align: center;">
                                <button class="control-btn" onclick="editUser(<?php echo $user_item['id']; ?>)" style="margin-right: 5px;">Edit</button>
                                <button class="control-btn" onclick="suspendUser(<?php echo $user_item['id']; ?>)" style="background: #dc3545;">Suspend</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
function editUser(userId) {
    alert('Edit user functionality would be implemented here. User ID: ' + userId);
}

function suspendUser(userId) {
    if (confirm('Are you sure you want to suspend this user?')) {
        alert('Suspend user functionality would be implemented here. User ID: ' + userId);
    }
}
</script>
