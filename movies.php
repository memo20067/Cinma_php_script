<?php
// Movies Directory Page

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
ensure_media_details_schema();

$category = isset($_GET['category']) && is_string($_GET['category']) ? trim($_GET['category']) : '';
$movieSql =
    "SELECT m.*,
        (SELECT mf.id FROM {prefix}media_files mf WHERE mf.media_id = m.id ORDER BY mf.id LIMIT 1) AS download_file_id
     FROM {prefix}media m WHERE m.type = 'movie'";
$movieParams = [];
if ($category !== '') {
    $movieSql .= ' AND m.content_category = ?';
    $movieParams[] = $category;
}
$movies = DB::fetchAll($movieSql . ' ORDER BY m.content_category, m.id DESC', $movieParams);
$movieGroups = [];
foreach ($movies as $movie) {
    $groupName = trim((string)($movie['content_category'] ?? ''));
    $movieGroups[$groupName !== '' ? $groupName : tr('غير مصنف', 'Uncategorized')][] = $movie;
}

render_header(tr('مكتبة الأفلام', 'Movies Library'));
?>

<div style="margin-bottom: 1.5rem;">
    <h1 style="color: #38bdf8;"><?= sanitize($category !== '' ? $category : tr('مكتبة الأفلام', 'Movies Library')) ?></h1>
    <p style="color: #94a3b8;"><?= sanitize($category !== '' ? tr('الأفلام في هذا التصنيف', 'Movies in this category') : tr('جميع الأفلام المتاحة', 'All available movies')) ?></p>
</div>

<?php if (empty($movies)): ?>
    <div class="alert alert-info"><?= sanitize(tr('لا توجد أفلام في قاعدة البيانات.', 'No movies found in the database.')) ?></div>
<?php else: ?>
    <?php foreach ($movieGroups as $groupName => $groupMovies): ?>
        <section class="media-category-group">
            <h2><?= sanitize($groupName) ?></h2>
            <div class="grid">
                <?php foreach ($groupMovies as $movie): ?>
                    <?php render_media_card($movie); ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php
render_footer();
