<?php
// Login Page

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    header('Location: ' . site_url('index.php'));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameOrEmail = trim($_POST['username_or_email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($usernameOrEmail) || empty($password)) {
        $error = tr('يرجى تعبئة جميع الحقول.', 'Please fill in all fields.');
    } else {
        $user = DB::fetch("SELECT * FROM {prefix}users WHERE username = ? OR email = ?", [$usernameOrEmail, $usernameOrEmail]);
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_role'] = $user['role'];

            if (check_permission('moderator')) {
                header('Location: ' . site_url('admin/index.php'));
            } else {
                header('Location: ' . site_url('index.php'));
            }
            exit;
        } else {
            $error = tr('اسم المستخدم أو البريد الإلكتروني أو كلمة المرور غير صحيحة.', 'Invalid username/email or password.');
        }
    }
}

render_header(tr('تسجيل الدخول', 'Sign In'));
?>

<div style="max-width: 420px; margin: 3rem auto; background: #1e293b; padding: 2rem; border-radius: 8px; border: 1px solid #334155;">
    <h2 style="font-size: 1.5rem; margin-bottom: 1.5rem; text-align: center; color: #38bdf8;"><?= sanitize(tr('تسجيل الدخول إلى الحساب', 'Account Login')) ?></h2>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= sanitize($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label><?= sanitize(tr('اسم المستخدم أو البريد الإلكتروني', 'Username or Email Address')) ?></label>
            <input type="text" name="username_or_email" class="form-control" value="<?= sanitize($_POST['username_or_email'] ?? '') ?>" required autofocus autocomplete="username">
        </div>

        <div class="form-group">
            <label><?= sanitize(tr('كلمة المرور', 'Password')) ?></label>
            <input type="password" name="password" class="form-control" required autocomplete="current-password">
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 0.6rem; font-size: 1rem; font-weight: 600; margin-top: 0.5rem;"><?= sanitize(tr('دخول', 'Log In')) ?></button>
    </form>

    <div style="text-align: center; margin-top: 1.5rem; font-size: 0.9rem; color: #94a3b8;">
        <?= sanitize(tr('ليس لديك حساب؟', "Don't have an account?")) ?> <a href="<?= sanitize(site_url('register.php')) ?>"><?= sanitize(tr('أنشئ حسابًا', 'Register here')) ?></a>
    </div>
</div>

<?php
render_footer();
