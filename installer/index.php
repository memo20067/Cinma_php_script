<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If config.php already exists, installer should be locked
if (file_exists(__DIR__ . '/../config.php')) {
    die('<!DOCTYPE html><html><head><title>Installer Locked</title><link rel="stylesheet" href="/assets/css/style.css"></head><body style="padding: 2rem;"><div style="max-width:600px; margin: 0 auto; background: #1e293b; padding: 2rem; border-radius: 8px;"><h2>System Already Installed</h2><p style="margin-top: 1rem;"><code>config.php</code> already exists. If you want to reinstall, please delete <code>config.php</code> from the root folder first.</p><p style="margin-top: 1rem;"><a href="/index.php" class="btn btn-primary">Go to Homepage</a></p></div></body></html>');
}

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$error = '';
$success = '';

// Step handling
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['step_2'])) {
        // DB Configuration
        $db_driver = $_POST['db_driver'] ?? 'mysql';
        $db_host = trim($_POST['db_host'] ?? 'localhost');
        $db_name = trim($_POST['db_name'] ?? '');
        $db_user = trim($_POST['db_user'] ?? '');
        $db_pass = $_POST['db_pass'] ?? '';
        $db_prefix = trim($_POST['db_prefix'] ?? 'cms_');

        try {
            if ($db_driver === 'sqlite') {
                $dbFile = !empty($db_name) ? $db_name : __DIR__ . '/../data/database.sqlite';
                if ($db_name === './data/database.sqlite' || $db_name === 'data/database.sqlite') {
                    $dbFile = __DIR__ . '/../data/database.sqlite';
                }
                $dir = dirname($dbFile);
                if (!is_dir($dir)) mkdir($dir, 0777, true);
                $testPdo = new PDO("sqlite:" . $dbFile);
            } else {
                $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
                $testPdo = new PDO($dsn, $db_user, $db_pass);
            }
            $testPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $_SESSION['install_db'] = [
                'driver' => $db_driver,
                'host' => $db_host,
                'name' => ($db_driver === 'sqlite') ? (realpath(dirname($dbFile)) . '/' . basename($dbFile)) : $db_name,
                'user' => $db_user,
                'pass' => $db_pass,
                'prefix' => preg_replace('/[^a-zA-Z0-9_]/', '', $db_prefix)
            ];
            if (php_sapi_name() !== 'cli') {
                header('Location: index.php?step=3');
                exit;
            }
        } catch (PDOException $e) {
            $error = "Database Connection Failed: " . $e->getMessage();
        }
    } elseif (isset($_POST['step_3'])) {
        // Site Configuration
        $site_name = trim($_POST['site_name'] ?? 'CinemaCMS');
        $admin_user = trim($_POST['admin_user'] ?? '');
        $admin_email = trim($_POST['admin_email'] ?? '');
        $admin_pass = $_POST['admin_pass'] ?? '';
        $admin_pass_confirm = $_POST['admin_pass_confirm'] ?? '';

        if (empty($admin_user) || empty($admin_email) || empty($admin_pass)) {
            $error = "All admin fields are required.";
        } elseif ($admin_pass !== $admin_pass_confirm) {
            $error = "Admin passwords do not match.";
        } else {
            // Handle logo upload if provided
            $logoPath = '';
            if (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] === UPLOAD_ERR_OK) {
                $tmpName = $_FILES['site_logo']['tmp_name'];
                $fileName = time() . '_' . basename($_FILES['site_logo']['name']);
                $targetDir = __DIR__ . '/../uploads/';
                if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
                move_uploaded_file($tmpName, $targetDir . $fileName);
                $logoPath = '/uploads/' . $fileName;
            }

            $_SESSION['install_site'] = [
                'site_name' => $site_name,
                'logo' => $logoPath,
                'admin_user' => $admin_user,
                'admin_email' => $admin_email,
                'admin_pass' => $admin_pass
            ];
            if (php_sapi_name() !== 'cli') {
                header('Location: index.php?step=4');
                exit;
            }
        }
    } elseif (isset($_POST['step_4'])) {
        // API Configuration & Installation Execution
        $tmdb_api_key = trim($_POST['tmdb_api_key'] ?? '');
        $_SESSION['install_api'] = [
            'tmdb_api_key' => $tmdb_api_key
        ];

        // Execute Installation
        $dbConfig = $_SESSION['install_db'] ?? null;
        $siteConfig = $_SESSION['install_site'] ?? null;

        if (!$dbConfig || !$siteConfig) {
            $error = "Installation session expired or missing parameters. Please start again.";
        } else {
            try {
                // Connect to DB
                if ($dbConfig['driver'] === 'sqlite') {
                    $dbFile = !empty($dbConfig['name']) ? $dbConfig['name'] : __DIR__ . '/../data/database.sqlite';
                    $pdo = new PDO("sqlite:" . $dbFile);
                } else {
                    $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['name']};charset=utf8mb4";
                    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass']);
                }
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                $prefix = $dbConfig['prefix'];
                $isSqlite = ($dbConfig['driver'] === 'sqlite');

                // SQL Queries for Tables
                if ($isSqlite) {
                    $queries = [
                        "CREATE TABLE IF NOT EXISTS `{$prefix}users` (
                            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                            `username` TEXT UNIQUE NOT NULL,
                            `email` TEXT UNIQUE NOT NULL,
                            `password` TEXT NOT NULL,
                            `role` TEXT DEFAULT 'registered',
                            `avatar` TEXT DEFAULT '',
                            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
                        );",
                        "CREATE TABLE IF NOT EXISTS `{$prefix}categories` (
                            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                            `name` TEXT NOT NULL,
                            `slug` TEXT UNIQUE NOT NULL,
                            `type` TEXT DEFAULT 'movie'
                        );",
                        "CREATE TABLE IF NOT EXISTS `{$prefix}media` (
                            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                            `title` TEXT NOT NULL,
                            `original_title` TEXT DEFAULT '',
                            `type` TEXT NOT NULL,
                            `plot` TEXT DEFAULT '',
                            `release_year` INTEGER DEFAULT 0,
                            `rating` REAL DEFAULT 0.0,
                            `poster_path` TEXT DEFAULT '',
                            `backdrop_path` TEXT DEFAULT '',
                            `tmdb_id` INTEGER DEFAULT 0,
                            `access_level` TEXT DEFAULT 'public',
                            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
                        );",
                        "CREATE TABLE IF NOT EXISTS `{$prefix}episodes` (
                            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                            `media_id` INTEGER NOT NULL,
                            `season_number` INTEGER DEFAULT 1,
                            `episode_number` INTEGER DEFAULT 1,
                            `title` TEXT DEFAULT '',
                            `plot` TEXT DEFAULT '',
                            `file_path` TEXT DEFAULT '',
                            `duration` INTEGER DEFAULT 0,
                            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
                        );",
                        "CREATE TABLE IF NOT EXISTS `{$prefix}media_files` (
                            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                            `media_id` INTEGER NOT NULL,
                            `file_path` TEXT NOT NULL,
                            `file_size` INTEGER DEFAULT 0,
                            `file_type` TEXT DEFAULT 'video',
                            `access_level` TEXT DEFAULT 'public',
                            `download_count` INTEGER DEFAULT 0,
                            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
                        );",
                        "CREATE TABLE IF NOT EXISTS `{$prefix}cast_members` (
                            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                            `media_id` INTEGER NOT NULL,
                            `name` TEXT NOT NULL,
                            `character_name` TEXT DEFAULT '',
                            `profile_path` TEXT DEFAULT ''
                        );",
                        "CREATE TABLE IF NOT EXISTS `{$prefix}settings` (
                            `setting_key` TEXT PRIMARY KEY,
                            `setting_value` TEXT DEFAULT ''
                        );"
                    ];
                } else {
                    $queries = [
                        "CREATE TABLE IF NOT EXISTS `{$prefix}users` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `username` VARCHAR(50) UNIQUE NOT NULL,
                            `email` VARCHAR(100) UNIQUE NOT NULL,
                            `password` VARCHAR(255) NOT NULL,
                            `role` VARCHAR(20) DEFAULT 'registered',
                            `avatar` VARCHAR(255) DEFAULT '',
                            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
                        "CREATE TABLE IF NOT EXISTS `{$prefix}categories` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `name` VARCHAR(100) NOT NULL,
                            `slug` VARCHAR(100) UNIQUE NOT NULL,
                            `type` VARCHAR(20) DEFAULT 'movie'
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
                        "CREATE TABLE IF NOT EXISTS `{$prefix}media` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `title` VARCHAR(255) NOT NULL,
                            `original_title` VARCHAR(255) DEFAULT '',
                            `type` VARCHAR(20) NOT NULL,
                            `plot` TEXT,
                            `release_year` INT DEFAULT 0,
                            `rating` DECIMAL(3,1) DEFAULT 0.0,
                            `poster_path` VARCHAR(255) DEFAULT '',
                            `backdrop_path` VARCHAR(255) DEFAULT '',
                            `tmdb_id` INT DEFAULT 0,
                            `access_level` VARCHAR(20) DEFAULT 'public',
                            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
                        "CREATE TABLE IF NOT EXISTS `{$prefix}episodes` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `media_id` INT NOT NULL,
                            `season_number` INT DEFAULT 1,
                            `episode_number` INT DEFAULT 1,
                            `title` VARCHAR(255) DEFAULT '',
                            `plot` TEXT,
                            `file_path` VARCHAR(255) DEFAULT '',
                            `duration` INT DEFAULT 0,
                            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
                        "CREATE TABLE IF NOT EXISTS `{$prefix}media_files` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `media_id` INT NOT NULL,
                            `file_path` VARCHAR(255) NOT NULL,
                            `file_size` BIGINT DEFAULT 0,
                            `file_type` VARCHAR(20) DEFAULT 'video',
                            `access_level` VARCHAR(20) DEFAULT 'public',
                            `download_count` INT DEFAULT 0,
                            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
                        "CREATE TABLE IF NOT EXISTS `{$prefix}cast_members` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `media_id` INT NOT NULL,
                            `name` VARCHAR(100) NOT NULL,
                            `character_name` VARCHAR(100) DEFAULT '',
                            `profile_path` VARCHAR(255) DEFAULT ''
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
                        "CREATE TABLE IF NOT EXISTS `{$prefix}settings` (
                            `setting_key` VARCHAR(50) PRIMARY KEY,
                            `setting_value` TEXT
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
                    ];
                }

                foreach ($queries as $sql) {
                    $pdo->exec($sql);
                }

                // Insert Default Categories
                $defaultCats = [
                    ['Movies', 'movies', 'movie'],
                    ['TV Series', 'tv-series', 'tv'],
                    ['Software', 'software', 'software'],
                    ['Games', 'games', 'game']
                ];
                $stmtCat = $pdo->prepare("INSERT OR IGNORE INTO `{$prefix}categories` (name, slug, type) VALUES (?, ?, ?)");
                if (!$isSqlite) {
                    $stmtCat = $pdo->prepare("INSERT IGNORE INTO `{$prefix}categories` (name, slug, type) VALUES (?, ?, ?)");
                }
                foreach ($defaultCats as $cat) {
                    $stmtCat->execute($cat);
                }

                // Insert Admin User
                $adminPassHash = password_hash($siteConfig['admin_pass'], PASSWORD_BCRYPT);
                $stmtUser = $pdo->prepare("INSERT INTO `{$prefix}users` (username, email, password, role) VALUES (?, ?, ?, 'admin')");
                $stmtUser->execute([$siteConfig['admin_user'], $siteConfig['admin_email'], $adminPassHash]);

                // Insert Settings
                $settings = [
                    'site_name' => $siteConfig['site_name'],
                    'site_logo' => $siteConfig['logo'],
                    'tmdb_api_key' => $tmdb_api_key,
                    'installed_at' => date('Y-m-d H:i:s')
                ];
                $stmtSet = $pdo->prepare("REPLACE INTO `{$prefix}settings` (setting_key, setting_value) VALUES (?, ?)");
                if (!$isSqlite) {
                    $stmtSet = $pdo->prepare("INSERT INTO `{$prefix}settings` (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                }
                foreach ($settings as $k => $v) {
                    $stmtSet->execute([$k, $v]);
                }

                // Generate config.php
                $configContent = "<?php\n";
                $configContent .= "// CinemaCMS Configuration File\n";
                $configContent .= "define('DB_DRIVER', " . var_export($dbConfig['driver'], true) . ");\n";
                $configContent .= "define('DB_HOST', " . var_export($dbConfig['host'], true) . ");\n";
                $configContent .= "define('DB_NAME', " . var_export($dbConfig['name'], true) . ");\n";
                $configContent .= "define('DB_USER', " . var_export($dbConfig['user'], true) . ");\n";
                $configContent .= "define('DB_PASS', " . var_export($dbConfig['pass'], true) . ");\n";
                $configContent .= "define('DB_PREFIX', " . var_export($prefix, true) . ");\n";

                file_put_contents(__DIR__ . '/../config.php', $configContent);

                // Clear session install data
                unset($_SESSION['install_db'], $_SESSION['install_site'], $_SESSION['install_api']);

                if (php_sapi_name() !== 'cli') {
                    header('Location: index.php?step=5');
                    exit;
                }
            } catch (Exception $e) {
                $error = "Installation Failed: " . $e->getMessage();
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CinemaCMS Web Installer</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background-color: #0f172a; color: #f8fafc; padding: 2rem 1rem; }
        .installer-card { max-width: 700px; margin: 0 auto; background: #1e293b; border-radius: 12px; border: 1px solid #334155; padding: 2rem; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        .header { text-align: center; border-bottom: 1px solid #334155; padding-bottom: 1.5rem; margin-bottom: 1.5rem; }
        .header h1 { font-size: 1.75rem; color: #38bdf8; }
        .header p { color: #94a3b8; font-size: 0.95rem; margin-top: 0.25rem; }

        .steps-bar { display: flex; justify-content: space-between; margin-bottom: 2rem; position: relative; }
        .steps-bar::before { content: ''; position: absolute; top: 18px; left: 0; right: 0; height: 2px; background: #334155; z-index: 1; }
        .step-item { position: relative; z-index: 2; background: #1e293b; padding: 0 0.5rem; text-align: center; }
        .step-circle { width: 36px; height: 36px; border-radius: 50%; background: #334155; color: #94a3b8; display: flex; align-items: center; justify-content: center; font-weight: bold; margin: 0 auto 0.4rem; border: 2px solid #1e293b; }
        .step-item.active .step-circle { background: #0284c7; color: #fff; border-color: #38bdf8; }
        .step-item.done .step-circle { background: #16a34a; color: #fff; }
        .step-label { font-size: 0.75rem; color: #94a3b8; }
        .step-item.active .step-label { color: #38bdf8; font-weight: bold; }

        .form-group { margin-bottom: 1.25rem; }
        .form-group label { display: block; margin-bottom: 0.4rem; font-size: 0.9rem; font-weight: 500; }
        .form-control { width: 100%; padding: 0.6rem 0.8rem; border-radius: 6px; border: 1px solid #334155; background: #0f172a; color: #fff; font-size: 0.95rem; }
        .form-control:focus { outline: none; border-color: #38bdf8; }
        .help-text { font-size: 0.8rem; color: #94a3b8; margin-top: 0.3rem; }

        .btn { display: inline-block; padding: 0.6rem 1.25rem; border-radius: 6px; border: none; cursor: pointer; background: #0284c7; color: #fff; font-size: 1rem; font-weight: 600; width: 100%; text-align: center; text-decoration: none; }
        .btn:hover { background: #0369a1; text-decoration: none; }

        .alert { padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1.25rem; font-size: 0.9rem; }
        .alert-danger { background: #7f1d1d; color: #fca5a5; border: 1px solid #b91c1c; }

        .req-list { list-style: none; margin-bottom: 1.5rem; }
        .req-item { display: flex; justify-content: space-between; padding: 0.6rem 0; border-bottom: 1px solid #334155; }
        .badge { padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.8rem; font-weight: bold; }
        .badge-success { background: #064e3b; color: #6ee7b7; }
        .badge-danger { background: #7f1d1d; color: #fca5a5; }
    </style>
</head>
<body>

<div class="installer-card">
    <div class="header">
        <h1>CinemaCMS Installation Wizard</h1>
        <p>Step-by-step graphical installer</p>
    </div>

    <div class="steps-bar">
        <div class="step-item <?= $step >= 1 ? ($step == 1 ? 'active' : 'done') : '' ?>">
            <div class="step-circle">1</div>
            <div class="step-label">Requirements</div>
        </div>
        <div class="step-item <?= $step >= 2 ? ($step == 2 ? 'active' : 'done') : '' ?>">
            <div class="step-circle">2</div>
            <div class="step-label">Database</div>
        </div>
        <div class="step-item <?= $step >= 3 ? ($step == 3 ? 'active' : 'done') : '' ?>">
            <div class="step-circle">3</div>
            <div class="step-label">Site Setup</div>
        </div>
        <div class="step-item <?= $step >= 4 ? ($step == 4 ? 'active' : 'done') : '' ?>">
            <div class="step-circle">4</div>
            <div class="step-label">API Config</div>
        </div>
        <div class="step-item <?= $step >= 5 ? 'active' : '' ?>">
            <div class="step-circle">5</div>
            <div class="step-label">Finish</div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($step == 1): ?>
        <?php
        $checks = [
            'PHP Version >= 8.0' => version_compare(PHP_VERSION, '8.0.0', '>='),
            'PDO Extension' => extension_loaded('pdo'),
            'PDO SQLite or MySQL' => extension_loaded('pdo_sqlite') || extension_loaded('pdo_mysql'),
            'cURL Extension' => extension_loaded('curl') || ini_get('allow_url_fopen'),
            'Uploads Directory Writable' => is_writable(__DIR__ . '/../uploads') || is_writable(__DIR__ . '/..'),
            'Cache Directory Writable' => is_writable(__DIR__ . '/../cache') || is_writable(__DIR__ . '/..'),
            'Root Directory Writable' => is_writable(__DIR__ . '/..')
        ];
        $allPassed = !in_array(false, $checks, true);
        ?>
        <h2 style="font-size: 1.2rem; margin-bottom: 1rem;">System Requirements & Permissions Check</h2>
        <ul class="req-list">
            <?php foreach ($checks as $label => $pass): ?>
                <li class="req-item">
                    <span><?= htmlspecialchars($label) ?></span>
                    <span class="badge <?= $pass ? 'badge-success' : 'badge-danger' ?>">
                        <?= $pass ? 'PASSED' : 'FAILED' ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($allPassed): ?>
            <a href="index.php?step=2" class="btn">Continue to Database Setup &rarr;</a>
        <?php else: ?>
            <div class="alert alert-danger">Please fix directory permissions and missing PHP extensions before proceeding.</div>
        <?php endif; ?>

    <?php elseif ($step == 2): ?>
        <h2 style="font-size: 1.2rem; margin-bottom: 1rem;">Database Configuration</h2>
        <form method="POST">
            <input type="hidden" name="step_2" value="1">
            <div class="form-group">
                <label>Database Engine</label>
                <select name="db_driver" class="form-control" onchange="toggleDbFields(this.value)">
                    <option value="mysql">MySQL / MariaDB</option>
                    <option value="sqlite">SQLite (File-based DB, zero config needed)</option>
                </select>
            </div>
            <div id="mysql-fields">
                <div class="form-group">
                    <label>Database Host</label>
                    <input type="text" name="db_host" class="form-control" value="127.0.0.1">
                </div>
                <div class="form-group">
                    <label>Database Name</label>
                    <input type="text" name="db_name" class="form-control" value="cinemacms">
                </div>
                <div class="form-group">
                    <label>Database User</label>
                    <input type="text" name="db_user" class="form-control" value="root">
                </div>
                <div class="form-group">
                    <label>Database Password</label>
                    <input type="password" name="db_pass" class="form-control" value="">
                </div>
            </div>
            <div class="form-group">
                <label>Table Prefix</label>
                <input type="text" name="db_prefix" class="form-control" value="cms_">
                <div class="help-text">Prefix for all database tables (e.g. cms_)</div>
            </div>
            <button type="submit" class="btn">Test Connection & Continue &rarr;</button>
        </form>
        <script>
        function toggleDbFields(val) {
            document.getElementById('mysql-fields').style.display = (val === 'sqlite') ? 'none' : 'block';
        }
        </script>

    <?php elseif ($step == 3): ?>
        <h2 style="font-size: 1.2rem; margin-bottom: 1rem;">Site & Admin Account Setup</h2>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="step_3" value="1">
            <div class="form-group">
                <label>Website Name</label>
                <input type="text" name="site_name" class="form-control" value="CinemaCMS" required>
            </div>
            <div class="form-group">
                <label>Site Logo (Optional)</label>
                <input type="file" name="site_logo" class="form-control" accept="image/*">
            </div>
            <hr style="border-color: #334155; margin: 1.5rem 0;">
            <div class="form-group">
                <label>Admin Username</label>
                <input type="text" name="admin_user" class="form-control" value="admin" required>
            </div>
            <div class="form-group">
                <label>Admin Email</label>
                <input type="email" name="admin_email" class="form-control" value="admin@example.com" required>
            </div>
            <div class="form-group">
                <label>Admin Password</label>
                <input type="password" name="admin_pass" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Confirm Admin Password</label>
                <input type="password" name="admin_pass_confirm" class="form-control" required>
            </div>
            <button type="submit" class="btn">Continue to API Config &rarr;</button>
        </form>

    <?php elseif ($step == 4): ?>
        <h2 style="font-size: 1.2rem; margin-bottom: 1rem;">API & Integration Configuration</h2>
        <form method="POST">
            <input type="hidden" name="step_4" value="1">
            <div class="form-group">
                <label>TMDB (The Movie Database) API Key (Optional)</label>
                <input type="text" name="tmdb_api_key" class="form-control" placeholder="e.g. 1a2b3c4d5e6f...">
                <div class="help-text">Used to automatically scrape movie & TV metadata, posters, backdrops, and cast details. You can configure or change this key later in the Admin Panel.</div>
            </div>
            <button type="submit" class="btn" style="background: #16a34a;">Run Installation Now &rarr;</button>
        </form>

    <?php elseif ($step == 5): ?>
        <div style="text-align: center; padding: 1rem 0;">
            <div style="font-size: 3rem; color: #16a34a; margin-bottom: 0.5rem;">✓</div>
            <h2 style="font-size: 1.5rem; margin-bottom: 0.5rem;">Installation Complete!</h2>
            <p style="color: #94a3b8; margin-bottom: 1.5rem;">CinemaCMS has been successfully installed and configured. Database tables were created and admin user was set up.</p>
            <div style="display: flex; gap: 1rem;">
                <a href="/index.php" class="btn">Visit Website</a>
                <a href="/admin/index.php" class="btn" style="background: #0284c7;">Go to Admin Panel</a>
            </div>
        </div>
    <?php endif; ?>

</div>

</body>
</html>
