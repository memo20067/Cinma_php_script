<?php
// User Profile & Dashboard Page

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

require_permission('registered');

$user = current_user();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $newPass = $_POST['new_password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Valid email address is required.";
    } else {
        $data = ['email' => $email];

        if (!empty($newPass)) {
            if (strlen($newPass) < 6) {
                $error = "Password must be at least 6 characters.";
            } else {
                $data['password'] = password_hash($newPass, PASSWORD_BCRYPT);
            }
        }

        // Avatar Upload
        if (empty($error) && isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $tmpName = $_FILES['avatar']['tmp_name'];
            $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $filename = 'avatar_' . $user['id'] . '_' . time() . '.' . $ext;
                $targetDir = __DIR__ . '/uploads/avatars/';
                if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
                if (move_uploaded_file($tmpName, $targetDir . $filename)) {
                    $data['avatar'] = '/uploads/avatars/' . $filename;
                }
            } else {
                $error = "Avatar must be an image file (jpg, png, webp).";
            }
        }

        if (empty($error)) {
            DB::update('users', $data, 'id = ?', [$user['id']]);
            $message = "Profile details updated successfully.";
            $user = current_user(); // Refresh user data
        }
    }
}

render_header("My Account Profile");
?>

<div style="max-width: 650px; margin: 2rem auto; background: #1e293b; padding: 2rem; border-radius: 8px; border: 1px solid #334155;">
    <div style="display: flex; align-items: center; gap: 1.5rem; margin-bottom: 2rem; border-bottom: 1px solid #334155; padding-bottom: 1.5rem;">
        <?php if (!empty($user['avatar'])): ?>
            <img src="<?= sanitize($user['avatar']) ?>" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 2px solid #0284c7;">
        <?php else: ?>
            <div style="width: 80px; height: 80px; border-radius: 50%; background: #334155; display: flex; align-items: center; justify-content: center; font-size: 2rem; color: #94a3b8; font-weight: bold;">
                <?= strtoupper(substr($user['username'], 0, 1)) ?>
            </div>
        <?php endif; ?>
        <div>
            <h1 style="font-size: 1.5rem; color: #f8fafc;"><?= sanitize($user['username']) ?></h1>
            <p style="color: #94a3b8; font-size: 0.9rem; margin-top: 0.25rem;">
                Member Role: <span class="badge badge-<?= $user['role'] ?>"><?= ucfirst($user['role']) ?></span>
            </p>
            <p style="color: #64748b; font-size: 0.8rem; margin-top: 0.25rem;">
                Joined: <?= date('F j, Y', strtotime($user['created_at'])) ?>
            </p>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= sanitize($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= sanitize($error) ?></div>
    <?php endif; ?>

    <h2 style="font-size: 1.2rem; margin-bottom: 1rem; color: #38bdf8;">Update Profile Details</h2>
    <form method="POST" enctype="multipart/form-data">
        <div class="form-group">
            <label>Username (Read Only)</label>
            <input type="text" class="form-control" value="<?= sanitize($user['username']) ?>" disabled>
        </div>

        <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" class="form-control" value="<?= sanitize($user['email']) ?>" required>
        </div>

        <div class="form-group">
            <label>Change Avatar Picture</label>
            <input type="file" name="avatar" class="form-control" accept="image/*">
        </div>

        <div class="form-group">
            <label>New Password (Leave blank to keep current password)</label>
            <input type="password" name="new_password" class="form-control" placeholder="••••••••">
        </div>

        <button type="submit" class="btn btn-primary" style="padding: 0.6rem 1.25rem;">Save Profile Changes</button>
    </form>
</div>

<?php
render_footer();
