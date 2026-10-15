<?php
// Software & Games Library Page

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
ensure_media_details_schema();

$category = isset($_GET['category']) && is_string($_GET['category']) ? trim($_GET['category']) : '';
$type = isset($_GET['type']) && is_string($_GET['type']) ? $_GET['type'] : '';
$type = in_array($type, ['software', 'game'], true) ? $type : '';
$softwareSql =
    "SELECT m.*,
        (SELECT mf.id FROM {prefix}media_files mf WHERE mf.media_id = m.id ORDER BY mf.id LIMIT 1) AS download_file_id
     FROM {prefix}media m WHERE m.type IN ('software', 'game')";
$softwareParams = [];
if ($type !== '') {
    $softwareSql .= ' AND m.type = ?';
    $softwareParams[] = $type;
}
if ($category !== '') {
    $softwareSql .= ' AND m.content_category = ?';
    $softwareParams[] = $category;
}
$items = DB::fetchAll($softwareSql . ' ORDER BY m.content_category, m.id DESC', $softwareParams);
$itemGroups = [];
foreach ($items as $item) {
    $groupName = trim((string)($item['content_category'] ?? ''));
    $itemGroups[$groupName !== '' ? $groupName : tr('غير مصنف', 'Uncategorized')][] = $item;
}

render_header(tr('تنزيل البرامج والألعاب', 'Software & Games Downloads'));
?>

<div style="margin-bottom: 1.5rem;">
    <h1 style="color: #f59e0b;"><?= sanitize($category !== '' ? $category : ($type === 'game' ? tr('مكتبة الألعاب', 'Games Library') : ($type === 'software' ? tr('مكتبة البرامج', 'Software Library') : tr('البرامج والألعاب', 'Software & Games Library')))) ?></h1>
    <p style="color: #94a3b8;"><?= sanitize($category !== '' ? tr('المحتوى في هذا التصنيف', 'Items in this category') : tr('البرامج والألعاب المتاحة للتنزيل', 'Available software and games for download')) ?></p>
</div>

<?php if (empty($items)): ?>
    <div class="alert alert-info"><?= sanitize(tr('لا توجد برامج أو ألعاب متاحة بعد.', 'No software or games available yet.')) ?></div>
<?php else: ?>
    <?php foreach ($itemGroups as $groupName => $groupItems): ?>
        <section class="media-category-group">
            <h2><?= sanitize($groupName) ?></h2>
            <div class="grid">
                <?php foreach ($groupItems as $item): ?>
                    <?php render_media_card($item); ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php
render_footer();
