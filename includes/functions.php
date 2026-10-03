<?php
// Common Functions and Helpers

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

function get_setting($key, $default = '') {
    static $settingsCache = null;
    if ($settingsCache === null) {
        $settingsCache = [];
        try {
            $rows = DB::fetchAll("SELECT setting_key, setting_value FROM {prefix}settings");
            foreach ($rows as $row) {
                $settingsCache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Exception $e) {
            // Settings table might not exist yet
        }
    }
    return isset($settingsCache[$key]) ? $settingsCache[$key] : $default;
}

function update_setting($key, $value) {
    $existing = DB::fetch("SELECT setting_key FROM {prefix}settings WHERE setting_key = ?", [$key]);
    if ($existing) {
        DB::update('settings', ['setting_value' => $value], 'setting_key = ?', [$key]);
    } else {
        DB::insert('settings', ['setting_key' => $key, 'setting_value' => $value]);
    }
}

function sanitize($data) {
    return htmlspecialchars(trim($data ?? ''), ENT_QUOTES, 'UTF-8');
}

function current_user() {
    if (isset($_SESSION['user_id'])) {
        return DB::fetch("SELECT * FROM {prefix}users WHERE id = ?", [$_SESSION['user_id']]);
    }
    return null;
}

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function user_role() {
    $user = current_user();
    return $user ? $user['role'] : 'guest';
}

function check_permission($requiredRole) {
    $rolesOrder = ['guest' => 0, 'registered' => 1, 'subscriber' => 2, 'moderator' => 3, 'admin' => 4];
    $currentRole = user_role();
    $currentLevel = isset($rolesOrder[$currentRole]) ? $rolesOrder[$currentRole] : 0;
    $requiredLevel = isset($rolesOrder[$requiredRole]) ? $rolesOrder[$requiredRole] : 0;

    return $currentLevel >= $requiredLevel;
}

function require_permission($requiredRole) {
    if (!check_permission($requiredRole)) {
        if (!is_logged_in()) {
            header('Location: /login.php');
            exit;
        } else {
            http_response_code(403);
            die("Access Denied: You do not have permission to access this page.");
        }
    }
}

function format_size($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } elseif ($bytes > 1) {
        return $bytes . ' bytes';
    } elseif ($bytes == 1) {
        return '1 byte';
    } else {
        return '0 bytes';
    }
}

function slugify($text) {
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = trim($text, '-');
    $text = preg_replace('~-+~', '-', $text);
    $text = strtolower($text);
    return empty($text) ? 'n-a' : $text;
}

function render_header($title = '') {
    $siteName = get_setting('site_name', 'CinemaCMS');
    $pageTitle = $title ? "$title - $siteName" : $siteName;
    $user = current_user();
    $logo = get_setting('site_logo', '');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= sanitize($pageTitle) ?></title>
        <link rel="stylesheet" href="/assets/css/style.css">
    </head>
    <body>
        <nav class="navbar">
            <div class="nav-container">
                <a class="nav-brand" href="/index.php">
                    <?php if ($logo): ?>
                        <img src="<?= sanitize($logo) ?>" alt="<?= sanitize($siteName) ?>" class="site-logo">
                    <?php else: ?>
                        <span class="site-name"><?= sanitize($siteName) ?></span>
                    <?php endif; ?>
                </a>
                <ul class="nav-menu">
                    <li><a href="/index.php">Home</a></li>
                    <li><a href="/movies.php">Movies</a></li>
                    <li><a href="/tvshows.php">TV Series</a></li>
                    <li><a href="/software.php">Software & Games</a></li>
                </ul>
                <div class="nav-search">
                    <form action="/search.php" method="GET">
                        <input type="text" name="q" placeholder="Search movies, tv, games..." value="<?= sanitize($_GET['q'] ?? '') ?>">
                        <button type="submit">Search</button>
                    </form>
                </div>
                <div class="nav-user">
                    <?php if ($user): ?>
                        <a href="/profile.php" class="user-link">
                            <?php if (!empty($user['avatar'])): ?>
                                <img src="<?= sanitize($user['avatar']) ?>" class="avatar-sm">
                            <?php endif; ?>
                            <span><?= sanitize($user['username']) ?></span>
                            <span class="badge badge-<?= $user['role'] ?>"><?= ucfirst($user['role']) ?></span>
                        </a>
                        <?php if (check_permission('moderator')): ?>
                            <a href="/admin/index.php" class="btn btn-sm btn-admin">Admin Panel</a>
                        <?php endif; ?>
                        <a href="/logout.php" class="btn btn-sm btn-logout">Logout</a>
                    <?php else: ?>
                        <a href="/login.php" class="btn btn-sm">Login</a>
                        <a href="/register.php" class="btn btn-sm btn-primary">Register</a>
                    <?php endif; ?>
                </div>
            </div>
        </nav>
        <main class="main-content">
    <?php
}

function render_footer() {
    $siteName = get_setting('site_name', 'CinemaCMS');
    ?>
        </main>
        <footer class="footer">
            <div class="footer-container">
                <p>&copy; <?= date('Y') ?> <?= sanitize($siteName) ?>. All rights reserved.</p>
                <p class="offline-badge">Offline Capable CMS</p>
            </div>
        </footer>
    </body>
    </html>
    <?php
}
