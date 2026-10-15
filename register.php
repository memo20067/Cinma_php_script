<?php
// User Registration Page

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    header('Location: ' . site_url('index.php'));
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    if (empty($username) || empty($email) || empty($password)) {
        $error = tr('جميع الحقول مطلوبة.', 'All fields are required.');
    } elseif ($password !== $passwordConfirm) {
        $error = tr('كلمتا المرور غير متطابقتين.', 'Passwords do not match.');
    } elseif (strlen($password) < 6) {
        $error = tr('يجب ألا تقل كلمة المرور عن 6 أحرف.', 'Password must be at least 6 characters long.');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = tr('يرجى إدخال بريد إلكتروني صحيح.', 'Please enter a valid email address.');
    } else {
        $existing = DB::fetch("SELECT id FROM {prefix}users WHERE username = ? OR email = ?", [$username, $email]);
        if ($existing) {
            $error = tr('اسم المستخدم أو البريد الإلكتروني مسجل بالفعل.', 'Username or email address is already registered.');
        } else {
            $passHash = password_hash($password, PASSWORD_BCRYPT);
            $userId = DB::insert('users', [
                'username' => $username,
                'email' => $email,
                'password' => $passHash,
                'role' => 'registered'
            ]);

            $_SESSION['user_id'] = $userId;
            $_SESSION['user_role'] = 'registered';

            header('Location: ' . site_url('profile.php'));
            exit;
        }
    }
}

render_header(tr('إنشاء حساب', 'Create Account'));
?>

<div style="max-width: 450px; margin: 3rem auto; background: #1e293b; padding: 2rem; border-radius: 8px; border: 1px solid #334155;">
    <h2 style="font-size: 1.5rem; margin-bottom: 1.5rem; text-align: center; color: #38bdf8;"><?= sanitize(tr('إنشاء حساب مجاني', 'Create Free Account')) ?></h2>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= sanitize($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label><?= sanitize(tr('اسم المستخدم', 'Username')) ?></label>
            <input type="text" name="username" class="form-control" value="<?= sanitize($_POST['username'] ?? '') ?>" required autocomplete="username">
        </div>

        <div class="form-group">
            <label><?= sanitize(tr('البريد الإلكتروني', 'Email Address')) ?></label>
            <input type="email" name="email" class="form-control" value="<?= sanitize($_POST['email'] ?? '') ?>" required autocomplete="email">
        </div>

        <div class="form-group">
            <label><?= sanitize(tr('كلمة المرور', 'Password')) ?></label>
            <input type="password" name="password" class="form-control" required autocomplete="new-password">
        </div>

        <div class="form-group">
            <label><?= sanitize(tr('تأكيد كلمة المرور', 'Confirm Password')) ?></label>
            <input type="password" name="password_confirm" class="form-control" required autocomplete="new-password">
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 0.6rem; font-size: 1rem; font-weight: 600; margin-top: 0.5rem;"><?= sanitize(tr('تسجيل الحساب', 'Register Account')) ?></button>
    </form>

    <div style="text-align: center; margin-top: 1.5rem; font-size: 0.9rem; color: #94a3b8;">
        <?= sanitize(tr('لديك حساب بالفعل؟', 'Already registered?')) ?> <a href="<?= sanitize(site_url('login.php')) ?>"><?= sanitize(tr('سجّل الدخول', 'Log in here')) ?></a>
    </div>
</div>

<?php
render_footer();
