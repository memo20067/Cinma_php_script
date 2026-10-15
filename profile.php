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
        $error = tr('يرجى إدخال بريد إلكتروني صحيح.', 'A valid email address is required.');
    } else {
        $data = ['email' => $email];

        if (!empty($newPass)) {
            if (strlen($newPass) < 6) {
                $error = tr('يجب ألا تقل كلمة المرور عن 6 أحرف.', 'Password must be at least 6 characters.');
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
                    $data['avatar'] = 'uploads/avatars/' . $filename;
                }
            } else {
                $error = tr('يجب أن تكون صورة الحساب بصيغة JPG أو PNG أو WebP.', 'The avatar must be an image file (JPG, PNG, or WebP).');
            }
        }

        if (empty($error)) {
            DB::update('users', $data, 'id = ?', [$user['id']]);
            $message = tr('تم تحديث بيانات الملف الشخصي بنجاح.', 'Profile details updated successfully.');
            $user = current_user(); // Refresh user data
        }
    }
}

render_header(tr('الملف الشخصي', 'My Account Profile'));
?>

<div style="max-width: 650px; margin: 2rem auto; background: #1e293b; padding: 2rem; border-radius: 8px; border: 1px solid #334155;">
    <div style="display: flex; align-items: center; gap: 1.5rem; margin-bottom: 2rem; border-bottom: 1px solid #334155; padding-bottom: 1.5rem;">
        <?php if (!empty($user['avatar'])): ?>
            <img src="<?= sanitize(site_url($user['avatar'])) ?>" alt="<?= sanitize($user['username']) ?> avatar" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 2px solid #0284c7;">
        <?php else: ?>
            <div style="width: 80px; height: 80px; border-radius: 50%; background: #334155; display: flex; align-items: center; justify-content: center; font-size: 2rem; color: #94a3b8; font-weight: bold;">
                <?= strtoupper(substr($user['username'], 0, 1)) ?>
            </div>
        <?php endif; ?>
        <div>
            <h1 style="font-size: 1.5rem; color: #f8fafc;"><?= sanitize($user['username']) ?></h1>
            <p style="color: #94a3b8; font-size: 0.9rem; margin-top: 0.25rem;">
                <?= sanitize(tr('رتبة العضوية:', 'Member role:')) ?> <span class="badge badge-<?= sanitize($user['role']) ?>"><?= sanitize(tr(
                    ['superuser' => 'مدير عام', 'admin' => 'مدير', 'moderator' => 'مشرف', 'subscriber' => 'مشترك', 'registered' => 'عضو مسجل'][$user['role']] ?? 'زائر',
                    ['superuser' => 'Superuser', 'admin' => 'Administrator', 'moderator' => 'Moderator', 'subscriber' => 'Subscriber', 'registered' => 'Registered member'][$user['role']] ?? 'Guest'
                )) ?></span>
            </p>
            <p style="color: #64748b; font-size: 0.8rem; margin-top: 0.25rem;">
                <?= sanitize(tr('تاريخ الانضمام:', 'Joined:')) ?>
                <?php
                $joinedAt = new DateTimeImmutable($user['created_at']);
                $dateFormatter = class_exists('IntlDateFormatter')
                    ? new IntlDateFormatter(current_language() === 'ar' ? 'ar' : 'en_US', IntlDateFormatter::LONG, IntlDateFormatter::NONE)
                    : null;
                echo sanitize($dateFormatter ? $dateFormatter->format($joinedAt) : $joinedAt->format('Y-m-d'));
                ?>
            </p>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= sanitize($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= sanitize($error) ?></div>
    <?php endif; ?>

    <h2 style="font-size: 1.2rem; margin-bottom: 1rem; color: #38bdf8;"><?= sanitize(tr('تحديث بيانات الملف الشخصي', 'Update Profile Details')) ?></h2>
    <form method="POST" enctype="multipart/form-data">
        <div class="form-group">
            <label><?= sanitize(tr('اسم المستخدم (للقراءة فقط)', 'Username (Read Only)')) ?></label>
            <input type="text" class="form-control" value="<?= sanitize($user['username']) ?>" disabled>
        </div>

        <div class="form-group">
            <label><?= sanitize(tr('البريد الإلكتروني', 'Email Address')) ?></label>
            <input type="email" name="email" class="form-control" value="<?= sanitize($user['email']) ?>" required>
        </div>

        <div class="form-group">
            <label><?= sanitize(tr('تغيير صورة الحساب', 'Change Avatar Picture')) ?></label>
            <input type="file" name="avatar" class="form-control" accept="image/*">
        </div>

        <div class="form-group">
            <label><?= sanitize(tr('كلمة مرور جديدة (اتركها فارغة للإبقاء على الحالية)', 'New Password (Leave blank to keep current password)')) ?></label>
            <input type="password" name="new_password" class="form-control" placeholder="••••••••">
        </div>

        <button type="submit" class="btn btn-primary" style="padding: 0.6rem 1.25rem;"><?= sanitize(tr('حفظ التغييرات', 'Save Profile Changes')) ?></button>
    </form>
</div>

<?php
render_footer();
