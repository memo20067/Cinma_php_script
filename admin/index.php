<?php
// Admin Dashboard Overview & Settings

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

require_permission('moderator');

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    require_permission('admin');
    $siteName = trim($_POST['site_name'] ?? '');
    $tmdbKey = trim($_POST['tmdb_api_key'] ?? '');

    if (!empty($siteName)) {
        update_setting('site_name', $siteName);
    }
    update_setting('tmdb_api_key', $tmdbKey);

    if (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['site_logo']['tmp_name'];
        $fileName = time() . '_' . basename($_FILES['site_logo']['name']);
        $targetDir = __DIR__ . '/../uploads/';
        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
        if (move_uploaded_file($tmpName, $targetDir . $fileName)) {
            update_setting('site_logo', '/uploads/' . $fileName);
        }
    }

    $message = "Site settings updated successfully.";
}

// Fetch System Overview Stats
$totalMovies = DB::fetch("SELECT COUNT(*) as count FROM {prefix}media WHERE type = 'movie'")['count'] ?? 0;
$totalTVShows = DB::fetch("SELECT COUNT(*) as count FROM {prefix}media WHERE type = 'tv'")['count'] ?? 0;
$totalAppsGames = DB::fetch("SELECT COUNT(*) as count FROM {prefix}media WHERE type IN ('software', 'game')")['count'] ?? 0;
$totalUsers = DB::fetch("SELECT COUNT(*) as count FROM {prefix}users")['count'] ?? 0;
$totalEpisodes = DB::fetch("SELECT COUNT(*) as count FROM {prefix}episodes")['count'] ?? 0;

render_header("Admin Dashboard");
?>

<div style="margin-bottom: 2rem;">
    <h1 style="color: #38bdf8;">Admin Control Panel</h1>
    <p style="color: #94a3b8;">Manage content, users, system settings, and media scraping tools.</p>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= sanitize($message) ?></div>
<?php endif; ?>

<!-- Admin Navigation Bar -->
<div style="display: flex; gap: 1rem; margin-bottom: 2rem; flex-wrap: wrap;">
    <a href="/admin/index.php" class="btn btn-primary">Overview & Settings</a>
    <a href="/admin/media.php" class="btn">Manage Uploads & Media</a>
    <a href="/admin/scan.php" class="btn">Directory Scanner & TV Merger</a>
    <a href="/admin/tmdb.php" class="btn">TMDB Auto-Scraper Engine</a>
    <?php if (check_permission('admin')): ?>
        <a href="/admin/users.php" class="btn">User Management</a>
    <?php endif; ?>
</div>

<!-- Quick Statistics Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.25rem; margin-bottom: 2rem;">
    <div style="background: #1e293b; padding: 1.25rem; border-radius: 8px; border: 1px solid #334155; text-align: center;">
        <div style="font-size: 2rem; font-weight: bold; color: #38bdf8;"><?= $totalMovies ?></div>
        <div style="color: #94a3b8; font-size: 0.9rem;">Movies Hosting</div>
    </div>
    <div style="background: #1e293b; padding: 1.25rem; border-radius: 8px; border: 1px solid #334155; text-align: center;">
        <div style="font-size: 2rem; font-weight: bold; color: #10b981;"><?= $totalTVShows ?></div>
        <div style="color: #94a3b8; font-size: 0.9rem;">TV Series (<?= $totalEpisodes ?> Eps)</div>
    </div>
    <div style="background: #1e293b; padding: 1.25rem; border-radius: 8px; border: 1px solid #334155; text-align: center;">
        <div style="font-size: 2rem; font-weight: bold; color: #f59e0b;"><?= $totalAppsGames ?></div>
        <div style="color: #94a3b8; font-size: 0.9rem;">Software & Games</div>
    </div>
    <div style="background: #1e293b; padding: 1.25rem; border-radius: 8px; border: 1px solid #334155; text-align: center;">
        <div style="font-size: 2rem; font-weight: bold; color: #ec4899;"><?= $totalUsers ?></div>
        <div style="color: #94a3b8; font-size: 0.9rem;">Registered Users</div>
    </div>
</div>

<!-- System Settings Form (Admins Only) -->
<?php if (check_permission('admin')): ?>
    <div style="background: #1e293b; padding: 1.5rem; border-radius: 8px; border: 1px solid #334155;">
        <h2 style="font-size: 1.25rem; margin-bottom: 1.25rem; color: #f8fafc;">Global Site Settings</h2>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="update_settings" value="1">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label>Website Name</label>
                    <input type="text" name="site_name" class="form-control" value="<?= sanitize(get_setting('site_name', 'CinemaCMS')) ?>" required>
                </div>
                <div class="form-group">
                    <label>TMDB API Key</label>
                    <input type="text" name="tmdb_api_key" class="form-control" value="<?= sanitize(get_setting('tmdb_api_key', '')) ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Change Website Logo Image</label>
                <input type="file" name="site_logo" class="form-control" accept="image/*">
                <?php if (get_setting('site_logo')): ?>
                    <div style="margin-top: 0.5rem;">
                        <span style="font-size: 0.85rem; color: #94a3b8;">Current logo:</span><br>
                        <img src="<?= sanitize(get_setting('site_logo')) ?>" style="max-height: 40px; margin-top: 0.25rem;">
                    </div>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-primary" style="padding: 0.6rem 1.25rem;">Save Settings</button>
        </form>
    </div>
<?php endif; ?>

<?php
render_footer();
