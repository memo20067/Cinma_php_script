<?php
// User Registration Page

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    header('Location: /index.php');
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
        $error = "All fields are required.";
    } elseif ($password !== $passwordConfirm) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        $existing = DB::fetch("SELECT id FROM {prefix}users WHERE username = ? OR email = ?", [$username, $email]);
        if ($existing) {
            $error = "Username or Email address is already registered.";
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

            header('Location: /profile.php');
            exit;
        }
    }
}

render_header("Create Account");
?>

<div style="max-width: 450px; margin: 3rem auto; background: #1e293b; padding: 2rem; border-radius: 8px; border: 1px solid #334155;">
    <h2 style="font-size: 1.5rem; margin-bottom: 1.5rem; text-align: center; color: #38bdf8;">Create Free Account</h2>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= sanitize($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>Username</label>
            <input type="text" name="username" class="form-control" value="<?= sanitize($_POST['username'] ?? '') ?>" required>
        </div>

        <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" class="form-control" value="<?= sanitize($_POST['email'] ?? '') ?>" required>
        </div>

        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" class="form-control" required>
        </div>

        <div class="form-group">
            <label>Confirm Password</label>
            <input type="password" name="password_confirm" class="form-control" required>
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 0.6rem; font-size: 1rem; font-weight: 600; margin-top: 0.5rem;">Register Account</button>
    </form>

    <div style="text-align: center; margin-top: 1.5rem; font-size: 0.9rem; color: #94a3b8;">
        Already registered? <a href="/login.php">Log in here</a>
    </div>
</div>

<?php
render_footer();
