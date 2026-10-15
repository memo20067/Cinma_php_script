<?php
// TV Shows Directory Page

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
ensure_media_details_schema();

$category = isset($_GET['category']) && is_string($_GET['category']) ? trim($_GET['category']) : '';
$showsSql =
    "SELECT m.*,
        (SELECT COUNT(*) FROM {prefix}episodes e WHERE e.media_id = m.id) AS ep_count,
        (SELECT e.id FROM {prefix}episodes e WHERE e.media_id = m.id ORDER BY e.season_number, e.episode_number LIMIT 1) AS download_episode_id
     FROM {prefix}media m WHERE m.type = 'tv'";
$showsParams = [];
if ($category !== '') {
    $showsSql .= ' AND m.content_category = ?';
    $showsParams[] = $category;
}
$shows = DB::fetchAll($showsSql . ' ORDER BY m.content_category, m.id DESC', $showsParams);
$showGroups = [];
foreach ($shows as $show) {
    $groupName = trim((string)($show['content_category'] ?? ''));
    $showGroups[$groupName !== '' ? $groupName : tr('غير مصنف', 'Uncategorized')][] = $show;
}

render_header(tr('مكتبة المسلسلات', 'TV Series Library'));
?>

<div style="margin-bottom: 1.5rem;">
    <h1 style="color: #10b981;"><?= sanitize($category !== '' ? $category : tr('مكتبة المسلسلات', 'TV Series Library')) ?></h1>
    <p style="color: #94a3b8;"><?= sanitize($category !== '' ? tr('المسلسلات في هذا التصنيف', 'Series in this category') : tr('تصفح المسلسلات والمواسم والحلقات', 'Browse series, seasons, and episodes')) ?></p>
</div>

<?php if (empty($shows)): ?>
    <div class="alert alert-info"><?= sanitize(tr('لا توجد مسلسلات في قاعدة البيانات.', 'No TV series found in the database.')) ?></div>
<?php else: ?>
    <?php foreach ($showGroups as $groupName => $groupShows): ?>
        <section class="media-category-group">
            <h2><?= sanitize($groupName) ?></h2>
            <div class="grid">
                <?php foreach ($groupShows as $show): ?>
                    <?php render_media_card($show); ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php
render_footer();
