<?php
// Admin User Management Controller

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

require_permission('admin');

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userId = (int)($_POST['user_id'] ?? 0);

    if ($action === 'update_role' && $userId) {
        $newRole = $_POST['role'] ?? 'registered';
        $allowedRoles = ['registered', 'subscriber', 'moderator', 'admin'];
        if (in_array($newRole, $allowedRoles)) {
            DB::update('users', ['role' => $newRole], 'id = ?', [$userId]);
            $message = "User role updated successfully.";
        }
    } elseif ($action === 'delete_user' && $userId) {
        $currentUser = current_user();
        if ($currentUser['id'] == $userId) {
            $error = "You cannot delete your own admin account.";
        } else {
            DB::delete('users', 'id = ?', [$userId]);
            $message = "User account deleted successfully.";
        }
    }
}

$users = DB::fetchAll("SELECT * FROM {prefix}users ORDER BY id DESC");

render_header("User Role & Access Management");
?>

<div style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center;">
    <h1>User Management & Role Permissions</h1>
    <a href="/admin/index.php" class="btn">&larr; Back to Admin Dashboard</a>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= sanitize($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><?= sanitize($error) ?></div>
<?php endif; ?>

<table style="width: 100%; border-collapse: collapse; background: #1e293b; border-radius: 8px; overflow: hidden; border: 1px solid #334155;">
    <thead>
        <tr style="background: #334155; text-align: left;">
            <th style="padding: 0.75rem;">User</th>
            <th style="padding: 0.75rem;">Email</th>
            <th style="padding: 0.75rem;">Current Role</th>
            <th style="padding: 0.75rem;">Joined</th>
            <th style="padding: 0.75rem;">Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($users as $u): ?>
            <tr style="border-bottom: 1px solid #334155;">
                <td style="padding: 0.75rem; display: flex; align-items: center; gap: 0.5rem;">
                    <?php if (!empty($u['avatar'])): ?>
                        <img src="<?= sanitize($u['avatar']) ?>" class="avatar-sm">
                    <?php endif; ?>
                    <strong><?= sanitize($u['username']) ?></strong>
                </td>
                <td style="padding: 0.75rem; font-size: 0.9rem; color: #cbd5e1;">
                    <?= sanitize($u['email']) ?>
                </td>
                <td style="padding: 0.75rem;">
                    <form method="POST" style="display: flex; gap: 0.5rem;">
                        <input type="hidden" name="action" value="update_role">
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                        <select name="role" class="form-control" style="padding: 0.2rem 0.5rem; width: auto; font-size: 0.85rem;">
                            <option value="registered" <?= $u['role'] === 'registered' ? 'selected' : '' ?>>Registered</option>
                            <option value="subscriber" <?= $u['role'] === 'subscriber' ? 'selected' : '' ?>>Paid Subscriber</option>
                            <option value="moderator" <?= $u['role'] === 'moderator' ? 'selected' : '' ?>>Moderator</option>
                            <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                        </select>
                        <button type="submit" class="btn btn-sm btn-primary">Save Role</button>
                    </form>
                </td>
                <td style="padding: 0.75rem; font-size: 0.85rem; color: #94a3b8;">
                    <?= date('Y-m-d', strtotime($u['created_at'])) ?>
                </td>
                <td style="padding: 0.75rem;">
                    <form method="POST" onsubmit="return confirm('Delete this user account?');" style="display: inline;">
                        <input type="hidden" name="action" value="delete_user">
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php
render_footer();
