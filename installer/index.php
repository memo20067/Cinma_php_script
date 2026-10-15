<?php
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
require_once __DIR__ . '/../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$installerLanguage = isset($_GET['language']) && in_array($_GET['language'], ['ar', 'en'], true)
    ? $_GET['language']
    : ($_SESSION['language'] ?? 'ar');
$_SESSION['language'] = $installerLanguage;

function installer_tr($arabic, $english) {
    global $installerLanguage;
    return $installerLanguage === 'en' ? $english : $arabic;
}

// Keep the installer locked after setup, while still showing its completion page.
if (file_exists(__DIR__ . '/../config.php') && $step !== 5) {
    die('<!DOCTYPE html><html lang="' . htmlspecialchars($installerLanguage, ENT_QUOTES, 'UTF-8') . '" dir="' . ($installerLanguage === 'ar' ? 'rtl' : 'ltr') . '"><head><meta charset="UTF-8"><title>' . htmlspecialchars(installer_tr('المثبت مقفل', 'Installer locked'), ENT_QUOTES, 'UTF-8') . '</title><link rel="stylesheet" href="' . htmlspecialchars(site_url('assets/css/style.css'), ENT_QUOTES, 'UTF-8') . '"></head><body style="padding: 2rem;"><div style="max-width:600px; margin: 0 auto; background: #1e293b; padding: 2rem; border-radius: 8px;"><h2>' . htmlspecialchars(installer_tr('تم تثبيت النظام بالفعل', 'System already installed'), ENT_QUOTES, 'UTF-8') . '</h2><p style="margin-top: 1rem;">' . htmlspecialchars(installer_tr('ملف', 'The file')) . ' <code>config.php</code> ' . htmlspecialchars(installer_tr('موجود بالفعل. لإعادة التثبيت، احذف هذا الملف من المجلد الرئيسي أولاً.', 'already exists. To reinstall, delete it from the root folder first.'), ENT_QUOTES, 'UTF-8') . '</p><p style="margin-top: 1rem;"><a href="' . htmlspecialchars(site_url('index.php'), ENT_QUOTES, 'UTF-8') . '" class="btn btn-primary">' . htmlspecialchars(installer_tr('الذهاب إلى الصفحة الرئيسية', 'Go to homepage'), ENT_QUOTES, 'UTF-8') . '</a></p></div></body></html>');
}

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
            error_log('Installer database connection failed: ' . $e->getMessage());
            $error = installer_tr('تعذر الاتصال بقاعدة البيانات. تحقق من الإعدادات ثم أعد المحاولة.', 'Database connection failed. Check the settings and try again.');
        }
    } elseif (isset($_POST['step_3'])) {
        // Site Configuration
        $site_name = trim($_POST['site_name'] ?? 'CinemaCMS');
        $admin_user = trim($_POST['admin_user'] ?? '');
        $admin_email = trim($_POST['admin_email'] ?? '');
        $admin_pass = $_POST['admin_pass'] ?? '';
        $admin_pass_confirm = $_POST['admin_pass_confirm'] ?? '';

        if (empty($admin_user) || empty($admin_email) || empty($admin_pass)) {
            $error = installer_tr('جميع حقول المدير مطلوبة.', 'All administrator fields are required.');
        } elseif ($admin_pass !== $admin_pass_confirm) {
            $error = installer_tr('كلمتا مرور المدير غير متطابقتين.', 'Administrator passwords do not match.');
        } else {
            // Handle logo upload if provided
            $logoPath = '';
            if (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] === UPLOAD_ERR_OK) {
                $tmpName = $_FILES['site_logo']['tmp_name'];
                $fileName = time() . '_' . basename($_FILES['site_logo']['name']);
                $targetDir = __DIR__ . '/../uploads/';
                if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
                move_uploaded_file($tmpName, $targetDir . $fileName);
                $logoPath = 'uploads/' . $fileName;
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
            $error = installer_tr('انتهت جلسة التثبيت أو أن بعض البيانات مفقودة. يرجى البدء من جديد.', 'The installation session expired or required information is missing. Please start again.');
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
                            `tagline` TEXT DEFAULT '',
                            `genres` TEXT DEFAULT '',
                            `runtime` INTEGER DEFAULT 0,
                            `original_language` TEXT DEFAULT '',
                            `production_countries` TEXT DEFAULT '',
                            `release_date` TEXT DEFAULT '',
                            `status` TEXT DEFAULT '',
                            `imdb_id` TEXT DEFAULT '',
                            `release_year` INTEGER DEFAULT 0,
                            `rating` REAL DEFAULT 0.0,
                            `poster_path` TEXT DEFAULT '',
                            `backdrop_path` TEXT DEFAULT '',
                            `tmdb_id` INTEGER DEFAULT 0,
                            `access_level` TEXT DEFAULT 'public',
                            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
                        );",
                        "CREATE TABLE IF NOT EXISTS `{$prefix}pages` (
                            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                            `slug` TEXT UNIQUE NOT NULL,
                            `title` TEXT NOT NULL,
                            `content_html` TEXT NOT NULL,
                            `status` TEXT DEFAULT 'published',
                            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
                        );",
                        "CREATE TABLE IF NOT EXISTS `{$prefix}episodes` (
                            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                            `media_id` INTEGER NOT NULL,
                            `season_number` INTEGER DEFAULT 1,
                            `episode_number` INTEGER DEFAULT 1,
                            `title` TEXT DEFAULT '',
                            `plot` TEXT DEFAULT '',
                            `file_path` TEXT DEFAULT '',
                            `compatibility_path` TEXT DEFAULT '',
                            `duration` INTEGER DEFAULT 0,
                            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
                        );",
                        "CREATE TABLE IF NOT EXISTS `{$prefix}media_files` (
                            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                            `media_id` INTEGER NOT NULL,
                            `file_path` TEXT NOT NULL,
                            `compatibility_path` TEXT DEFAULT '',
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
                        "CREATE TABLE IF NOT EXISTS `{$prefix}media_crew` (
                            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                            `media_id` INTEGER NOT NULL,
                            `name` TEXT NOT NULL,
                            `department` TEXT DEFAULT '',
                            `job` TEXT DEFAULT '',
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
                            `tagline` TEXT,
                            `genres` TEXT,
                            `runtime` INT DEFAULT 0,
                            `original_language` VARCHAR(16) DEFAULT '',
                            `production_countries` TEXT,
                            `release_date` VARCHAR(16) DEFAULT '',
                            `status` VARCHAR(100) DEFAULT '',
                            `imdb_id` VARCHAR(32) DEFAULT '',
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
                            `compatibility_path` VARCHAR(255) DEFAULT '',
                            `duration` INT DEFAULT 0,
                            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
                        "CREATE TABLE IF NOT EXISTS `{$prefix}media_files` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `media_id` INT NOT NULL,
                            `file_path` VARCHAR(255) NOT NULL,
                            `compatibility_path` VARCHAR(255) DEFAULT '',
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
                        "CREATE TABLE IF NOT EXISTS `{$prefix}media_crew` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `media_id` INT NOT NULL,
                            `name` VARCHAR(255) NOT NULL,
                            `department` VARCHAR(255) DEFAULT '',
                            `job` VARCHAR(255) DEFAULT '',
                            `profile_path` VARCHAR(255) DEFAULT '',
                            INDEX (`media_id`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
                        "CREATE TABLE IF NOT EXISTS `{$prefix}settings` (
                            `setting_key` VARCHAR(50) PRIMARY KEY,
                            `setting_value` TEXT
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
                        ,
                        "CREATE TABLE IF NOT EXISTS `{$prefix}pages` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `slug` VARCHAR(160) UNIQUE NOT NULL,
                            `title` VARCHAR(255) NOT NULL,
                            `content_html` MEDIUMTEXT NOT NULL,
                            `status` VARCHAR(20) DEFAULT 'published',
                            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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

                // Reuse the admin created by an earlier incomplete install attempt.
                $stmtUser = $pdo->prepare("SELECT id, username, email, role FROM `{$prefix}users` WHERE username = ? OR email = ?");
                $stmtUser->execute([$siteConfig['admin_user'], $siteConfig['admin_email']]);
                $existingAdmin = $stmtUser->fetch(PDO::FETCH_ASSOC);

                if ($existingAdmin) {
                    if (
                        $existingAdmin['username'] !== $siteConfig['admin_user']
                        || $existingAdmin['email'] !== $siteConfig['admin_email']
                        || $existingAdmin['role'] !== 'admin'
                    ) {
                        throw new RuntimeException(
                            'The configured admin username or email already belongs to a different account. Return to step 3 and choose unused admin details.'
                        );
                    }
                } else {
                    $adminPassHash = password_hash($siteConfig['admin_pass'], PASSWORD_BCRYPT);
                    $stmtUser = $pdo->prepare("INSERT INTO `{$prefix}users` (username, email, password, role) VALUES (?, ?, ?, 'admin')");
                    $stmtUser->execute([$siteConfig['admin_user'], $siteConfig['admin_email'], $adminPassHash]);
                }

                // Insert Settings
                $settings = [
                    'site_name' => $siteConfig['site_name'],
                    'site_logo' => $siteConfig['logo'],
                    'tmdb_api_key' => $tmdb_api_key,
                    'ffmpeg_path' => '',
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
                $configContent .= "define('AD_SLOT_HEADER', '');\n";
                $configContent .= "define('AD_SLOT_CONTENT_TOP', '');\n";
                $configContent .= "define('AD_SLOT_CONTENT_BOTTOM', '');\n";
                $configContent .= "define('AD_SLOT_FOOTER', '');\n";

                file_put_contents(__DIR__ . '/../config.php', $configContent);

                // Clear session install data
                unset($_SESSION['install_db'], $_SESSION['install_site'], $_SESSION['install_api']);

                if (php_sapi_name() !== 'cli') {
                    header('Location: index.php?step=5');
                    exit;
                }
            } catch (Exception $e) {
                error_log('Installer setup failed: ' . $e->getMessage());
                $error = installer_tr('فشل التثبيت. راجع سجل الأخطاء لمزيد من التفاصيل.', 'Installation failed. Check the error log for details.');
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($installerLanguage, ENT_QUOTES, 'UTF-8') ?>" dir="<?= $installerLanguage === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CinemaCMS - <?= htmlspecialchars(installer_tr('معالج التثبيت', 'Web installer'), ENT_QUOTES, 'UTF-8') ?></title>
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
        <h1>CinemaCMS - <?= htmlspecialchars(installer_tr('معالج التثبيت', 'Installation wizard'), ENT_QUOTES, 'UTF-8') ?></h1>
        <p><?= htmlspecialchars(installer_tr('خطوات مرئية لإعداد الموقع', 'Step-by-step graphical installer'), ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <div class="steps-bar">
        <div class="step-item <?= $step >= 1 ? ($step == 1 ? 'active' : 'done') : '' ?>">
            <div class="step-circle">1</div>
            <div class="step-label"><?= htmlspecialchars(installer_tr('المتطلبات', 'Requirements'), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div class="step-item <?= $step >= 2 ? ($step == 2 ? 'active' : 'done') : '' ?>">
            <div class="step-circle">2</div>
            <div class="step-label"><?= htmlspecialchars(installer_tr('قاعدة البيانات', 'Database'), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div class="step-item <?= $step >= 3 ? ($step == 3 ? 'active' : 'done') : '' ?>">
            <div class="step-circle">3</div>
            <div class="step-label"><?= htmlspecialchars(installer_tr('إعداد الموقع', 'Site setup'), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div class="step-item <?= $step >= 4 ? ($step == 4 ? 'active' : 'done') : '' ?>">
            <div class="step-circle">4</div>
            <div class="step-label"><?= htmlspecialchars(installer_tr('إعدادات API', 'API settings'), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div class="step-item <?= $step >= 5 ? 'active' : '' ?>">
            <div class="step-circle">5</div>
            <div class="step-label"><?= htmlspecialchars(installer_tr('الإنهاء', 'Finish'), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($step == 1): ?>
        <?php
        $checks = [
            installer_tr('إصدار PHP >= 8.0', 'PHP version >= 8.0') => version_compare(PHP_VERSION, '8.0.0', '>='),
            installer_tr('امتداد PDO', 'PDO extension') => extension_loaded('pdo'),
            installer_tr('PDO مع SQLite أو MySQL', 'PDO with SQLite or MySQL') => extension_loaded('pdo_sqlite') || extension_loaded('pdo_mysql'),
            installer_tr('امتداد cURL', 'cURL extension') => extension_loaded('curl') || ini_get('allow_url_fopen'),
            installer_tr('صلاحية الكتابة في مجلد الرفع', 'Uploads directory writable') => is_writable(__DIR__ . '/../uploads') || is_writable(__DIR__ . '/..'),
            installer_tr('صلاحية الكتابة في مجلد التخزين المؤقت', 'Cache directory writable') => is_writable(__DIR__ . '/../cache') || is_writable(__DIR__ . '/..'),
            installer_tr('صلاحية الكتابة في المجلد الرئيسي', 'Root directory writable') => is_writable(__DIR__ . '/..')
        ];
        $allPassed = !in_array(false, $checks, true);
        ?>
        <h2 style="font-size: 1.2rem; margin-bottom: 1rem;"><?= htmlspecialchars(installer_tr('متطلبات النظام وفحص الصلاحيات', 'System requirements and permissions check'), ENT_QUOTES, 'UTF-8') ?></h2>
        <ul class="req-list">
            <?php foreach ($checks as $label => $pass): ?>
                <li class="req-item">
                    <span><?= htmlspecialchars($label) ?></span>
                    <span class="badge <?= $pass ? 'badge-success' : 'badge-danger' ?>">
                        <?= htmlspecialchars($pass ? installer_tr('ناجح', 'Passed') : installer_tr('فشل', 'Failed'), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($allPassed): ?>
            <a href="index.php?step=2" class="btn"><?= htmlspecialchars(installer_tr('متابعة إعداد قاعدة البيانات', 'Continue to database setup'), ENT_QUOTES, 'UTF-8') ?> &rarr;</a>
        <?php else: ?>
            <div class="alert alert-danger"><?= htmlspecialchars(installer_tr('يرجى إصلاح صلاحيات المجلدات وتفعيل امتدادات PHP المطلوبة قبل المتابعة.', 'Please fix directory permissions and enable the required PHP extensions before proceeding.'), ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

    <?php elseif ($step == 2): ?>
        <h2 style="font-size: 1.2rem; margin-bottom: 1rem;"><?= htmlspecialchars(installer_tr('إعداد قاعدة البيانات', 'Database configuration'), ENT_QUOTES, 'UTF-8') ?></h2>
        <form method="POST">
            <input type="hidden" name="step_2" value="1">
            <div class="form-group">
                <label><?= htmlspecialchars(installer_tr('نوع قاعدة البيانات', 'Database engine'), ENT_QUOTES, 'UTF-8') ?></label>
                <select name="db_driver" class="form-control" onchange="toggleDbFields(this.value)">
                    <option value="mysql">MySQL / MariaDB</option>
                    <option value="sqlite">SQLite (<?= htmlspecialchars(installer_tr('قاعدة بيانات ملفية، لا تحتاج إلى إعداد', 'file-based database, no configuration required'), ENT_QUOTES, 'UTF-8') ?>)</option>
                </select>
            </div>
            <div id="mysql-fields">
                <div class="form-group">
                    <label><?= htmlspecialchars(installer_tr('مضيف قاعدة البيانات', 'Database host'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input type="text" name="db_host" class="form-control" value="127.0.0.1">
                </div>
                <div class="form-group">
                    <label><?= htmlspecialchars(installer_tr('اسم قاعدة البيانات', 'Database name'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input type="text" name="db_name" class="form-control" value="cinemacms">
                </div>
                <div class="form-group">
                    <label><?= htmlspecialchars(installer_tr('مستخدم قاعدة البيانات', 'Database user'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input type="text" name="db_user" class="form-control" value="root">
                </div>
                <div class="form-group">
                    <label><?= htmlspecialchars(installer_tr('كلمة مرور قاعدة البيانات', 'Database password'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input type="password" name="db_pass" class="form-control" value="">
                </div>
            </div>
            <div class="form-group">
                <label><?= htmlspecialchars(installer_tr('بادئة الجداول', 'Table prefix'), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="text" name="db_prefix" class="form-control" value="cms_">
                <div class="help-text"><?= htmlspecialchars(installer_tr('بادئة تُضاف إلى أسماء جميع جداول قاعدة البيانات (مثال: cms_).', 'Prefix added to all database table names (e.g. cms_).'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <button type="submit" class="btn"><?= htmlspecialchars(installer_tr('اختبار الاتصال والمتابعة', 'Test connection and continue'), ENT_QUOTES, 'UTF-8') ?> &rarr;</button>
        </form>
        <script>
        function toggleDbFields(val) {
            document.getElementById('mysql-fields').style.display = (val === 'sqlite') ? 'none' : 'block';
        }
        </script>

    <?php elseif ($step == 3): ?>
        <h2 style="font-size: 1.2rem; margin-bottom: 1rem;"><?= htmlspecialchars(installer_tr('إعداد الموقع وحساب المدير', 'Site and administrator account setup'), ENT_QUOTES, 'UTF-8') ?></h2>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="step_3" value="1">
            <div class="form-group">
                <label><?= htmlspecialchars(installer_tr('اسم الموقع', 'Website name'), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="text" name="site_name" class="form-control" value="CinemaCMS" required>
            </div>
            <div class="form-group">
                <label><?= htmlspecialchars(installer_tr('شعار الموقع (اختياري)', 'Site logo (optional)'), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="file" name="site_logo" class="form-control" accept="image/*">
            </div>
            <hr style="border-color: #334155; margin: 1.5rem 0;">
            <div class="form-group">
                <label><?= htmlspecialchars(installer_tr('اسم مستخدم المدير', 'Administrator username'), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="text" name="admin_user" class="form-control" value="admin" required>
            </div>
            <div class="form-group">
                <label><?= htmlspecialchars(installer_tr('البريد الإلكتروني للمدير', 'Administrator email'), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="email" name="admin_email" class="form-control" value="admin@example.com" required>
            </div>
            <div class="form-group">
                <label><?= htmlspecialchars(installer_tr('كلمة مرور المدير', 'Administrator password'), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="password" name="admin_pass" class="form-control" required>
            </div>
            <div class="form-group">
                <label><?= htmlspecialchars(installer_tr('تأكيد كلمة مرور المدير', 'Confirm administrator password'), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="password" name="admin_pass_confirm" class="form-control" required>
            </div>
            <button type="submit" class="btn"><?= htmlspecialchars(installer_tr('متابعة إلى إعدادات API', 'Continue to API settings'), ENT_QUOTES, 'UTF-8') ?> &rarr;</button>
        </form>

    <?php elseif ($step == 4): ?>
        <h2 style="font-size: 1.2rem; margin-bottom: 1rem;"><?= htmlspecialchars(installer_tr('إعداد API والربط بالخدمات', 'API and integration settings'), ENT_QUOTES, 'UTF-8') ?></h2>
        <form method="POST">
            <input type="hidden" name="step_4" value="1">
            <div class="form-group">
                <label><?= htmlspecialchars(installer_tr('مفتاح TMDB (اختياري)', 'TMDB API key (optional)'), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="text" name="tmdb_api_key" class="form-control" placeholder="<?= htmlspecialchars(installer_tr('أدخل مفتاح الخدمة', 'Enter the API key'), ENT_QUOTES, 'UTF-8') ?>">
                <div class="help-text"><?= htmlspecialchars(installer_tr('يُستخدم لجلب بيانات الأفلام والمسلسلات والملصقات والخلفيات وطاقم العمل تلقائيًا. يمكنك إضافة المفتاح أو تغييره لاحقًا من لوحة الإدارة.', 'Used to fetch movie and TV metadata, posters, backdrops, and cast details. You can add or change this key later in the Admin Panel.'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <button type="submit" class="btn" style="background: #16a34a;"><?= htmlspecialchars(installer_tr('بدء التثبيت الآن', 'Run installation now'), ENT_QUOTES, 'UTF-8') ?> &rarr;</button>
        </form>

    <?php elseif ($step == 5): ?>
        <div style="text-align: center; padding: 1rem 0;">
            <div style="font-size: 3rem; color: #16a34a; margin-bottom: 0.5rem;">✓</div>
            <h2 style="font-size: 1.5rem; margin-bottom: 0.5rem;"><?= htmlspecialchars(installer_tr('اكتمل التثبيت!', 'Installation complete!'), ENT_QUOTES, 'UTF-8') ?></h2>
            <p style="color: #94a3b8; margin-bottom: 1.5rem;"><?= htmlspecialchars(installer_tr('تم تثبيت CinemaCMS وإعداده بنجاح. أُنشئت جداول قاعدة البيانات وأُعد حساب المدير.', 'CinemaCMS has been installed and configured successfully. Database tables were created and the administrator account was set up.'), ENT_QUOTES, 'UTF-8') ?></p>
            <div style="display: flex; gap: 1rem;">
                <a href="<?= htmlspecialchars(site_url('index.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn"><?= htmlspecialchars(installer_tr('زيارة الموقع', 'Visit website'), ENT_QUOTES, 'UTF-8') ?></a>
                <a href="<?= htmlspecialchars(site_url('admin/index.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn" style="background: #0284c7;"><?= htmlspecialchars(installer_tr('الذهاب إلى لوحة الإدارة', 'Go to Admin Panel'), ENT_QUOTES, 'UTF-8') ?></a>
            </div>
        </div>
    <?php endif; ?>

</div>

</body>
</html>
