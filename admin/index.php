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
    $contactEmail = trim($_POST['contact_email'] ?? '');
    $contactPhone = trim($_POST['contact_phone'] ?? '');
    $contactWhatsapp = trim($_POST['contact_whatsapp'] ?? '');
    $ffmpegPath = trim($_POST['ffmpeg_path'] ?? '');

    if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        $error = tr('يرجى إدخال بريد إلكتروني صحيح للتواصل.', 'Please enter a valid contact email address.');
    } elseif ($ffmpegPath !== '' && !is_file($ffmpegPath)) {
        $error = tr('يجب أن يشير مسار FFmpeg إلى ملف ffmpeg.exe موجود.', 'The FFmpeg path must point to an existing ffmpeg.exe file.');
    } else {
        if ($siteName !== '') {
            update_setting('site_name', $siteName);
        }
        update_setting('tmdb_api_key', $tmdbKey);
        update_setting('contact_email', $contactEmail);
        update_setting('contact_phone', $contactPhone);
        update_setting('contact_whatsapp', $contactWhatsapp);
        update_setting('ffmpeg_path', $ffmpegPath);

        if (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] === UPLOAD_ERR_OK) {
            $tmpName = $_FILES['site_logo']['tmp_name'];
            $fileName = time() . '_' . basename($_FILES['site_logo']['name']);
            $targetDir = __DIR__ . '/../uploads/';
            if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
            if (move_uploaded_file($tmpName, $targetDir . $fileName)) {
                update_setting('site_logo', 'uploads/' . $fileName);
            }
        }

        $message = tr('تم تحديث إعدادات الموقع بنجاح.', 'Site settings updated successfully.');
    }
}

// Fetch System Overview Stats
$totalMovies = DB::fetch("SELECT COUNT(*) as count FROM {prefix}media WHERE type = 'movie'")['count'] ?? 0;
$totalTVShows = DB::fetch("SELECT COUNT(*) as count FROM {prefix}media WHERE type = 'tv'")['count'] ?? 0;
$totalAppsGames = DB::fetch("SELECT COUNT(*) as count FROM {prefix}media WHERE type IN ('software', 'game')")['count'] ?? 0;
$totalUsers = DB::fetch("SELECT COUNT(*) as count FROM {prefix}users")['count'] ?? 0;
$totalEpisodes = DB::fetch("SELECT COUNT(*) as count FROM {prefix}episodes")['count'] ?? 0;

render_header(tr('لوحة الإدارة', 'Admin Dashboard'));
?>

<div style="margin-bottom: 2rem;">
    <h1 style="color: #38bdf8;"><?= sanitize(tr('لوحة تحكم الإدارة', 'Admin Control Panel')) ?></h1>
    <p style="color: #94a3b8;"><?= sanitize(tr('إدارة المحتوى والمستخدمين وإعدادات الموقع وأدوات استيراد بيانات الوسائط.', 'Manage content, users, site settings, and media import tools.')) ?></p>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= sanitize($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><?= sanitize($error) ?></div>
<?php endif; ?>

<!-- Admin Navigation Bar -->
<div style="display: flex; gap: 1rem; margin-bottom: 2rem; flex-wrap: wrap;">
    <a href="<?= sanitize(site_url('admin/index.php')) ?>" class="btn btn-primary"><?= sanitize(tr('الرئيسية والإعدادات', 'Overview & Settings')) ?></a>
    <a href="<?= sanitize(site_url('admin/media.php')) ?>" class="btn"><?= sanitize(tr('إدارة الوسائط', 'Manage Media')) ?></a>
    <a href="<?= sanitize(site_url('admin/scan.php')) ?>" class="btn"><?= sanitize(tr('فحص الملفات والمسلسلات', 'File Scanner & TV Merger')) ?></a>
    <a href="<?= sanitize(site_url('admin/tmdb.php')) ?>" class="btn"><?= sanitize(tr('استيراد TMDB', 'TMDB Importer')) ?></a>
    <a href="<?= sanitize(site_url('admin/pages.php')) ?>" class="btn"><?= sanitize(tr('صفحات الموقع', 'Site Pages')) ?></a>
    <?php if (check_permission('admin')): ?>
        <a href="<?= sanitize(site_url('admin/users.php')) ?>" class="btn"><?= sanitize(tr('المستخدمون', 'Users')) ?></a>
    <?php endif; ?>
    <?php if (check_permission('superuser')): ?>
        <a href="<?= sanitize(site_url('admin/ads.php')) ?>" class="btn"><?= sanitize(tr('إعدادات الإعلانات', 'Ad settings')) ?></a>
    <?php endif; ?>
</div>

<!-- Quick Statistics Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.25rem; margin-bottom: 2rem;">
    <div style="background: #1e293b; padding: 1.25rem; border-radius: 8px; border: 1px solid #334155; text-align: center;">
        <div style="font-size: 2rem; font-weight: bold; color: #38bdf8;"><?= $totalMovies ?></div>
        <div style="color: #94a3b8; font-size: 0.9rem;"><?= sanitize(tr('الأفلام', 'Movies')) ?></div>
    </div>
    <div style="background: #1e293b; padding: 1.25rem; border-radius: 8px; border: 1px solid #334155; text-align: center;">
        <div style="font-size: 2rem; font-weight: bold; color: #10b981;"><?= $totalTVShows ?></div>
        <div style="color: #94a3b8; font-size: 0.9rem;"><?= sanitize(tr('المسلسلات', 'TV Series')) ?> (<?= (int)$totalEpisodes ?> <?= sanitize(tr('حلقة', 'episodes')) ?>)</div>
    </div>
    <div style="background: #1e293b; padding: 1.25rem; border-radius: 8px; border: 1px solid #334155; text-align: center;">
        <div style="font-size: 2rem; font-weight: bold; color: #f59e0b;"><?= $totalAppsGames ?></div>
        <div style="color: #94a3b8; font-size: 0.9rem;"><?= sanitize(tr('البرامج والألعاب', 'Software & Games')) ?></div>
    </div>
    <div style="background: #1e293b; padding: 1.25rem; border-radius: 8px; border: 1px solid #334155; text-align: center;">
        <div style="font-size: 2rem; font-weight: bold; color: #ec4899;"><?= $totalUsers ?></div>
        <div style="color: #94a3b8; font-size: 0.9rem;"><?= sanitize(tr('المستخدمون المسجلون', 'Registered Users')) ?></div>
    </div>
</div>

<!-- System Settings Form (Admins Only) -->
<?php if (check_permission('admin')): ?>
    <div class="settings-panel">
        <h2 style="font-size: 1.25rem; margin-bottom: 1.25rem; color: #f8fafc;"><?= sanitize(tr('إعدادات الموقع العامة', 'Global Site Settings')) ?></h2>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="update_settings" value="1">
            <div class="settings-grid">
                <div class="form-group">
                    <label><?= sanitize(tr('اسم الموقع', 'Website Name')) ?></label>
                    <input type="text" name="site_name" class="form-control" value="<?= sanitize(get_setting('site_name', 'CinemaCMS')) ?>" required>
                </div>
                <div class="form-group">
                    <label><?= sanitize(tr('مفتاح TMDB', 'TMDB API Key')) ?></label>
                    <input type="text" name="tmdb_api_key" class="form-control" value="<?= sanitize(get_setting('tmdb_api_key', '')) ?>">
                </div>
                <div class="form-group">
                    <label for="contact_email"><?= sanitize(tr('البريد الإلكتروني للتواصل', 'Contact Email')) ?></label>
                    <input type="email" id="contact_email" name="contact_email" class="form-control" value="<?= sanitize(get_setting('contact_email', '')) ?>" autocomplete="email">
                </div>
                <div class="form-group">
                    <label for="contact_phone"><?= sanitize(tr('هاتف التواصل', 'Contact Phone')) ?></label>
                    <input type="tel" id="contact_phone" name="contact_phone" class="form-control" value="<?= sanitize(get_setting('contact_phone', '')) ?>" autocomplete="tel">
                </div>
                <div class="form-group">
                    <label for="contact_whatsapp"><?= sanitize(tr('رقم واتساب', 'WhatsApp Number')) ?></label>
                    <input type="tel" id="contact_whatsapp" name="contact_whatsapp" class="form-control" value="<?= sanitize(get_setting('contact_whatsapp', '')) ?>" autocomplete="tel">
                    <small class="form-help"><?= sanitize(tr('أدخل رمز الدولة، مثال: +201234567890.', 'Include the international country code, for example +201234567890.')) ?></small>
                </div>
                <div class="form-group">
                    <label for="ffmpeg_path"><?= sanitize(tr('مسار برنامج FFmpeg', 'FFmpeg executable path')) ?></label>
                    <input type="text" id="ffmpeg_path" name="ffmpeg_path" class="form-control" value="<?= sanitize(get_setting('ffmpeg_path', '')) ?>" placeholder="C:\ffmpeg\bin\ffmpeg.exe" dir="ltr">
                    <small class="form-help"><?= sanitize(tr('اختياري. عند عدم تحديد مسار مخصص، يحاول النظام تنزيل حزمة FFmpeg المحلية والتحقق منها تلقائيًا. يجب أن يوجد الملف ffprobe.exe بجوار الملف التنفيذي عند تحديد مسار مخصص.', 'Optional override. Without a custom path, the system downloads and verifies the local FFmpeg package automatically when generating qualities. A custom FFmpeg path must have ffprobe.exe beside it.')) ?></small>
                </div>
            </div>

            <div class="form-group">
                <label><?= sanitize(tr('تغيير صورة شعار الموقع', 'Change Website Logo Image')) ?></label>
                <input type="file" name="site_logo" class="form-control" accept="image/*">
                <?php if (get_setting('site_logo')): ?>
                    <div style="margin-top: 0.5rem;">
                        <span style="font-size: 0.85rem; color: #94a3b8;"><?= sanitize(tr('الشعار الحالي:', 'Current logo:')) ?></span><br>
                        <img src="<?= sanitize(site_url(get_setting('site_logo'))) ?>" alt="<?= sanitize(tr('شعار الموقع الحالي', 'Current site logo')) ?>" style="max-height: 40px; margin-top: 0.25rem;">
                    </div>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-primary" style="padding: 0.6rem 1.25rem;"><?= sanitize(tr('حفظ الإعدادات', 'Save Settings')) ?></button>
        </form>
    </div>
<?php endif; ?>

<?php
render_footer();
