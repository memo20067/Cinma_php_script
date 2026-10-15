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

    if ($action === 'create_user') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $newRole = $_POST['role'] ?? 'registered';
        $password = $_POST['password'] ?? '';
        $allowedRoles = ['registered', 'subscriber', 'moderator', 'admin', 'superuser'];
        $duplicate = DB::fetch(
            "SELECT id FROM {prefix}users WHERE username = ? OR email = ?",
            [$username, $email]
        );

        if (strlen($username) < 3 || strlen($username) > 50 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = tr('أدخل اسم مستخدم من 3 إلى 50 حرفاً وبريداً إلكترونياً صحيحاً.', 'Enter a username between 3 and 50 characters and a valid email address.');
        } elseif ($duplicate) {
            $error = tr('اسم المستخدم أو البريد الإلكتروني مستخدم بالفعل.', 'That username or email address is already in use.');
        } elseif (!in_array($newRole, $allowedRoles, true)) {
            $error = tr('الصلاحية المحددة غير صالحة.', 'The selected user role is invalid.');
        } elseif (strlen($password) < 8) {
            $error = tr('يجب ألا تقل كلمة المرور عن 8 أحرف.', 'The password must contain at least 8 characters.');
        } else {
            DB::insert('users', [
                'username' => $username,
                'email' => $email,
                'password' => password_hash($password, PASSWORD_BCRYPT),
                'role' => $newRole,
                'avatar' => ''
            ]);
            $message = tr('تم إنشاء حساب المستخدم.', 'User account created.');
        }
    } elseif ($action === 'update_user' && $userId) {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $newRole = $_POST['role'] ?? 'registered';
        $password = $_POST['password'] ?? '';
        $allowedRoles = ['registered', 'subscriber', 'moderator', 'admin', 'superuser'];
        $existing = DB::fetch("SELECT id FROM {prefix}users WHERE id = ?", [$userId]);
        $duplicate = DB::fetch(
            "SELECT id FROM {prefix}users WHERE (username = ? OR email = ?) AND id != ?",
            [$username, $email, $userId]
        );
        $currentUser = current_user();

        if (!$existing) {
            $error = tr('حساب المستخدم غير موجود.', 'User account not found.');
        } elseif (strlen($username) < 3 || strlen($username) > 50 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = tr('أدخل اسم مستخدم من 3 إلى 50 حرفاً وبريداً إلكترونياً صحيحاً.', 'Enter a username between 3 and 50 characters and a valid email address.');
        } elseif ($duplicate) {
            $error = tr('اسم المستخدم أو البريد الإلكتروني مستخدم بالفعل.', 'That username or email address is already in use.');
        } elseif (!in_array($newRole, $allowedRoles, true)) {
            $error = tr('الصلاحية المحددة غير صالحة.', 'The selected user role is invalid.');
        } elseif ($currentUser['id'] == $userId && !in_array($newRole, ['admin', 'superuser'], true)) {
            $error = tr('لا يمكنك إزالة صلاحية المدير من حسابك.', 'You cannot remove administrator access from your own account.');
        } elseif ($password !== '' && strlen($password) < 8) {
            $error = tr('يجب ألا تقل كلمة المرور الجديدة عن 8 أحرف.', 'A new password must contain at least 8 characters.');
        } else {
            $userData = ['username' => $username, 'email' => $email, 'role' => $newRole];
            if ($password !== '') {
                $userData['password'] = password_hash($password, PASSWORD_BCRYPT);
            }
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
                    $error = tr('فشل رفع الصورة الشخصية.', 'Avatar upload failed.');
                } else {
                    $imageInfo = getimagesize($_FILES['avatar']['tmp_name']);
                    $extension = $imageInfo ? image_type_to_extension($imageInfo[2], false) : '';
                    if (!$imageInfo || !in_array($extension, ['jpeg', 'png', 'gif', 'webp'], true)) {
                        $error = tr('ارفع صورة صالحة بصيغة JPG أو PNG أو GIF أو WebP.', 'Upload a valid JPG, PNG, GIF, or WebP image.');
                    } else {
                        $directory = __DIR__ . '/../uploads/avatars/';
                        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                            $error = tr('تعذر إنشاء مجلد رفع الصور الشخصية.', 'Could not create the avatar upload directory.');
                        } else {
                            $filename = bin2hex(random_bytes(16)) . '.' . $extension;
                            if (!move_uploaded_file($_FILES['avatar']['tmp_name'], $directory . $filename)) {
                                $error = tr('تعذر حفظ الصورة الشخصية المرفوعة.', 'Could not save the uploaded avatar.');
                            } else {
                                $userData['avatar'] = 'uploads/avatars/' . $filename;
                            }
                        }
                    }
                }
            }
            if ($error === '') {
                DB::update('users', $userData, 'id = ?', [$userId]);
                $message = tr('تم تحديث ملف المستخدم بنجاح.', 'User profile updated successfully.');
            }
        }
    } elseif ($action === 'delete_user' && $userId) {
        $currentUser = current_user();
        if ($currentUser['id'] == $userId) {
            $error = tr('لا يمكنك حذف حساب المدير الخاص بك.', 'You cannot delete your own admin account.');
        } else {
            DB::delete('users', 'id = ?', [$userId]);
            $message = tr('تم حذف حساب المستخدم بنجاح.', 'User account deleted successfully.');
        }
    }
}

$users = DB::fetchAll("SELECT * FROM {prefix}users ORDER BY id DESC");
$editId = (int)($_GET['edit'] ?? 0);
$editingUser = $editId ? DB::fetch("SELECT * FROM {prefix}users WHERE id = ?", [$editId]) : false;

render_header(tr('إدارة المستخدمين', 'User Management'));
?>

<?php if ($editingUser): ?>
    <section class="admin-panel">
        <h2><?= sanitize(tr('تعديل الملف الشخصي', 'Edit user profile')) ?>: <?= sanitize($editingUser['username']) ?></h2>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="update_user">
            <input type="hidden" name="user_id" value="<?= (int)$editingUser['id'] ?>">
            <div class="settings-grid">
                <div class="form-group">
                    <label for="edit-username"><?= sanitize(tr('اسم المستخدم', 'Username')) ?></label>
                    <input id="edit-username" class="form-control" type="text" name="username" minlength="3" maxlength="50" required value="<?= sanitize($editingUser['username']) ?>">
                </div>
                <div class="form-group">
                    <label for="edit-email"><?= sanitize(tr('البريد الإلكتروني', 'Email address')) ?></label>
                    <input id="edit-email" class="form-control" type="email" name="email" required value="<?= sanitize($editingUser['email']) ?>">
                </div>
                <div class="form-group">
                    <label for="edit-role"><?= sanitize(tr('الصلاحية', 'Role')) ?></label>
                    <select id="edit-role" class="form-control" name="role">
                        <?php foreach (['registered', 'subscriber', 'moderator', 'admin', 'superuser'] as $role): ?>
                            <option value="<?= sanitize($role) ?>" <?= $editingUser['role'] === $role ? 'selected' : '' ?>><?= sanitize(tr(['registered' => 'عضو', 'subscriber' => 'مشترك', 'moderator' => 'مشرف', 'admin' => 'مدير', 'superuser' => 'مدير أعلى - إعدادات الإعلانات'][$role], ucfirst($role))) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="edit-password"><?= sanitize(tr('كلمة مرور جديدة (اختياري)', 'New password (optional)')) ?></label>
                    <input id="edit-password" class="form-control" type="password" name="password" minlength="8" autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label for="edit-avatar"><?= sanitize(tr('تغيير الصورة الشخصية', 'Change profile picture')) ?></label>
                    <input id="edit-avatar" class="form-control" type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp">
                </div>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= sanitize(tr('حفظ الملف الشخصي', 'Save profile')) ?></button>
                <a class="btn" href="<?= sanitize(site_url('admin/users.php')) ?>"><?= sanitize(tr('إلغاء', 'Cancel')) ?></a>
            </div>
        </form>
    </section>
<?php endif; ?>

<div style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center;">
    <h1><?= sanitize(tr('المستخدمون والصلاحيات', 'Users & permissions')) ?></h1>
    <a href="<?= sanitize(site_url('admin/index.php')) ?>" class="btn">&larr; <?= sanitize(tr('العودة إلى لوحة الإدارة', 'Back to Admin Dashboard')) ?></a>
</div>

<section class="admin-panel">
    <h2><?= sanitize(tr('إنشاء مستخدم جديد', 'Create a user account')) ?></h2>
    <form method="POST">
        <input type="hidden" name="action" value="create_user">
        <div class="settings-grid">
            <div class="form-group">
                <label for="new-username"><?= sanitize(tr('اسم المستخدم', 'Username')) ?></label>
                <input id="new-username" class="form-control" type="text" name="username" minlength="3" maxlength="50" required autocomplete="username">
            </div>
            <div class="form-group">
                <label for="new-email"><?= sanitize(tr('البريد الإلكتروني', 'Email address')) ?></label>
                <input id="new-email" class="form-control" type="email" name="email" required autocomplete="email">
            </div>
            <div class="form-group">
                <label for="new-password"><?= sanitize(tr('كلمة المرور', 'Password')) ?></label>
                <input id="new-password" class="form-control" type="password" name="password" minlength="8" required autocomplete="new-password">
            </div>
            <div class="form-group">
                <label for="new-role"><?= sanitize(tr('الصلاحية', 'Role')) ?></label>
                <select id="new-role" class="form-control" name="role">
                    <?php foreach (['registered', 'subscriber', 'moderator', 'admin', 'superuser'] as $role): ?>
                        <option value="<?= sanitize($role) ?>"><?= sanitize(tr(['registered' => 'عضو', 'subscriber' => 'مشترك', 'moderator' => 'مشرف', 'admin' => 'مدير', 'superuser' => 'مدير أعلى - إعدادات الإعلانات'][$role], ucfirst($role))) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="form-help"><?= sanitize(tr('امنح رتبة المدير الأعلى لحساب موثوق فقط؛ تتيح تعديل إعدادات الإعلانات.', 'Only assign superuser to a trusted account; it grants access to ad settings.')) ?></small>
            </div>
        </div>
        <button type="submit" class="btn btn-primary"><?= sanitize(tr('إنشاء المستخدم', 'Create user')) ?></button>
    </form>
</section>

<?php if ($message): ?>
    <div class="alert alert-success"><?= sanitize($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><?= sanitize($error) ?></div>
<?php endif; ?>

<table style="width: 100%; border-collapse: collapse; background: #1e293b; border-radius: 8px; overflow: hidden; border: 1px solid #334155;">
    <thead>
        <tr style="background: #334155; text-align: left;">
            <th style="padding: 0.75rem;"><?= sanitize(tr('المستخدم', 'User')) ?></th>
            <th style="padding: 0.75rem;"><?= sanitize(tr('البريد الإلكتروني', 'Email')) ?></th>
            <th style="padding: 0.75rem;"><?= sanitize(tr('الصلاحية الحالية', 'Current role')) ?></th>
            <th style="padding: 0.75rem;"><?= sanitize(tr('تاريخ الانضمام', 'Joined')) ?></th>
            <th style="padding: 0.75rem;"><?= sanitize(tr('الإجراءات', 'Actions')) ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($users as $u): ?>
            <tr style="border-bottom: 1px solid #334155;">
                <td style="padding: 0.75rem; display: flex; align-items: center; gap: 0.5rem;">
                    <?php if (!empty($u['avatar'])): ?>
                        <img src="<?= sanitize(site_url($u['avatar'])) ?>" alt="<?= sanitize(tr('الصورة الشخصية للمستخدم', 'User avatar') . ': ' . $u['username']) ?>" class="avatar-sm">
                    <?php endif; ?>
                    <strong><?= sanitize($u['username']) ?></strong>
                </td>
                <td style="padding: 0.75rem; font-size: 0.9rem; color: #cbd5e1;">
                    <?= sanitize($u['email']) ?>
                </td>
                <td style="padding: 0.75rem;">
                    <span class="badge badge-<?= sanitize($u['role']) ?>"><?= sanitize(tr(
                        ['registered' => 'عضو مسجل', 'subscriber' => 'مشترك', 'moderator' => 'مشرف', 'admin' => 'مدير', 'superuser' => 'مدير أعلى'][$u['role']] ?? 'زائر',
                        ['registered' => 'Registered member', 'subscriber' => 'Subscriber', 'moderator' => 'Moderator', 'admin' => 'Administrator', 'superuser' => 'Superuser'][$u['role']] ?? 'Guest'
                    )) ?></span>
                </td>
                <td style="padding: 0.75rem; font-size: 0.85rem; color: #94a3b8;">
                    <?= date('Y-m-d', strtotime($u['created_at'])) ?>
                </td>
                <td style="padding: 0.75rem;">
                    <a href="<?= sanitize(site_url('admin/users.php?edit=' . $u['id'])) ?>" class="btn btn-sm btn-primary"><?= sanitize(tr('تعديل', 'Edit')) ?></a>
                    <form method="POST" onsubmit="return confirm(<?= htmlspecialchars(json_encode(tr('هل تريد حذف حساب المستخدم؟', 'Delete this user account?'), JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>);" style="display: inline;">
                        <input type="hidden" name="action" value="delete_user">
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger"><?= sanitize(tr('حذف', 'Delete')) ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php
render_footer();
