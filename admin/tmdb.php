<?php
// Admin TMDB Auto-Scraper UI

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tmdb.php';

require_permission('moderator');

$message = '';
$error = '';
$searchResults = null;

$tmdb = new TMDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'search') {
        $query = trim($_POST['query'] ?? '');
        $type = $_POST['type'] ?? 'movie';
        $year = (int)($_POST['year'] ?? 0);

        if ($query) {
            if ($type === 'movie') {
                $searchResults = $tmdb->searchMovie($query, $year ? $year : null);
            } else {
                $searchResults = $tmdb->searchTV($query, $year ? $year : null);
            }
        }
    } elseif ($action === 'import') {
        $tmdbId = (int)($_POST['tmdb_id'] ?? 0);
        $type = $_POST['type'] ?? 'movie';
        $accessLevel = $_POST['access_level'] ?? 'public';

        if ($tmdbId) {
            if ($type === 'movie') {
                $mediaId = $tmdb->importMovieMetadata($tmdbId, $accessLevel);
            } else {
                $mediaId = $tmdb->importTVShowMetadata($tmdbId, $accessLevel);
            }

            if ($mediaId) {
                $message = sprintf(
                    tr('تم استيراد %s بنجاح مع الملصقات والبيانات المحفوظة محليًا (معرّف TMDB: %d).', 'Successfully imported %s with locally cached artwork and metadata (TMDB ID: %d).'),
                    $type === 'movie' ? tr('الفيلم', 'the movie') : tr('المسلسل', 'the TV series'),
                    $tmdbId
                );
            } else {
                $error = tr('تعذر جلب البيانات من TMDB. يرجى التحقق من مفتاح الخدمة.', 'Failed to fetch metadata from TMDB. Please check your API key.');
            }
        }
    }
}

render_header(tr('استيراد بيانات TMDB', 'TMDB Metadata Import'));
?>

<div style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center;">
    <h1><?= sanitize(tr('استيراد بيانات وصور TMDB', 'Import TMDB metadata and artwork')) ?></h1>
    <a href="<?= sanitize(site_url('admin/index.php')) ?>" class="btn">&larr; <?= sanitize(tr('العودة إلى لوحة الإدارة', 'Back to Admin Dashboard')) ?></a>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= sanitize($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><?= sanitize($error) ?></div>
<?php endif; ?>

<div style="background: #1e293b; padding: 1.5rem; border-radius: 8px; border: 1px solid #334155; margin-bottom: 2rem;">
    <h2 style="font-size: 1.2rem; color: #38bdf8; margin-bottom: 1rem;"><?= sanitize(tr('البحث في قاعدة بيانات TMDB', 'Search the TMDB database')) ?></h2>
    <form method="POST">
        <input type="hidden" name="action" value="search">
        <div style="display: grid; grid-template-columns: 2fr 1fr 1fr auto; gap: 1rem; align-items: end;">
            <div class="form-group" style="margin:0;">
                <label><?= sanitize(tr('عنوان الفيلم أو المسلسل', 'Movie or TV series title')) ?></label>
                <input type="text" name="query" class="form-control" placeholder="<?= sanitize(tr('مثال: الفيل الأزرق أو Inception', 'e.g. Inception or Breaking Bad')) ?>" value="<?= sanitize($_POST['query'] ?? '') ?>" required>
            </div>
            <div class="form-group" style="margin:0;">
                <label><?= sanitize(tr('النوع', 'Type')) ?></label>
                <select name="type" class="form-control">
                    <option value="movie" <?= ($_POST['type'] ?? '') === 'movie' ? 'selected' : '' ?>><?= sanitize(tr('فيلم', 'Movie')) ?></option>
                    <option value="tv" <?= ($_POST['type'] ?? '') === 'tv' ? 'selected' : '' ?>><?= sanitize(tr('مسلسل', 'TV Series')) ?></option>
                </select>
            </div>
            <div class="form-group" style="margin:0;">
                <label><?= sanitize(tr('سنة الإصدار (اختياري)', 'Release year (optional)')) ?></label>
                <input type="number" name="year" class="form-control" placeholder="<?= sanitize(tr('السنة', 'Year')) ?>" value="<?= sanitize($_POST['year'] ?? '') ?>">
            </div>
            <button type="submit" class="btn btn-primary" style="height: 42px;"><?= sanitize(tr('البحث عبر الإنترنت', 'Search online')) ?></button>
        </div>
    </form>
</div>

<?php if ($searchResults && isset($searchResults['results'])): ?>
    <h2 style="font-size: 1.25rem; margin-bottom: 1rem;"><?= sanitize(tr('نتائج البحث', 'Search results')) ?> (<?= count($searchResults['results']) ?> <?= sanitize(tr('عنصر', 'items found')) ?>)</h2>
    <div class="grid">
        <?php foreach ($searchResults['results'] as $item):
            $title = $item['title'] ?? $item['name'] ?? tr('بدون عنوان', 'Untitled');
            $date = $item['release_date'] ?? $item['first_air_date'] ?? '';
            $year = !empty($date) ? substr($date, 0, 4) : tr('غير متاح', 'N/A');
            $tmdbId = $item['id'];
            $type = isset($item['title']) ? 'movie' : 'tv';
            $poster = !empty($item['poster_path']) ? $tmdb->downloadAndCacheImage($item['poster_path'], 'covers') : '';
        ?>
            <div class="media-card">
                <?php if ($poster): ?>
                    <img src="<?= sanitize(site_url($poster)) ?>" alt="<?= sanitize($title) ?> poster" class="media-poster">
                <?php else: ?>
                    <div class="media-poster" style="display: flex; align-items: center; justify-content: center; color: #64748b;"><?= sanitize(tr('لا توجد صورة', 'No image')) ?></div>
                <?php endif; ?>
                <div class="media-info">
                    <div class="media-title"><?= sanitize($title) ?> (<?= $year ?>)</div>
                    <p style="font-size: 0.8rem; color: #94a3b8; margin-bottom: 0.75rem;"><?= sanitize(substr($item['overview'] ?? '', 0, 90)) ?>...</p>

                    <form method="POST" style="margin-top: auto;">
                        <input type="hidden" name="action" value="import">
                        <input type="hidden" name="tmdb_id" value="<?= $tmdbId ?>">
                        <input type="hidden" name="type" value="<?= $type ?>">
                        <div class="form-group" style="margin-bottom: 0.5rem;">
                            <select name="access_level" class="form-control" style="font-size: 0.8rem; padding: 0.2rem 0.4rem;">
                                <option value="public"><?= sanitize(tr('متاح للجميع', 'Public access')) ?></option>
                                <option value="registered"><?= sanitize(tr('للأعضاء المسجلين', 'Registered members')) ?></option>
                                <option value="subscriber"><?= sanitize(tr('للمشتركين', 'Paid subscribers')) ?></option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-sm btn-success" style="width: 100%;"><?= sanitize(tr('استيراد وحفظ محليًا', 'Import and save locally')) ?></button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
render_footer();
