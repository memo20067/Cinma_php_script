<?php
// Global Search Controller

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/iptv.php';

$query = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';

$results = [];
$iptvResults = [];
if (!empty($query)) {
    $searchTerm = "%{$query}%";
    $results = DB::fetchAll("SELECT * FROM {prefix}media WHERE title LIKE ? OR plot LIKE ? ORDER BY id DESC", [$searchTerm, $searchTerm]);
    ensure_iptv_schema();
    $iptvResults = DB::fetchAll(
        'SELECT i.id, i.source_id, i.item_type, i.title, i.category_name, i.poster_url, s.name AS source_name
         FROM {prefix}iptv_items i
         JOIN {prefix}iptv_sources s ON s.id = i.source_id
         WHERE s.enabled = 1 AND i.item_type IN (\'live\', \'movie\', \'series\') AND i.title LIKE ?
         ORDER BY s.name, i.title LIMIT 100',
        [$searchTerm]
    );
}

render_header(tr('نتائج البحث: ', 'Search Results: ') . $query);
?>

<div style="margin-bottom: 1.5rem;">
    <h1><?= sanitize(tr('نتائج البحث عن', 'Search Results for')) ?> "<?= sanitize($query) ?>"</h1>
    <p style="color: #94a3b8;"><?= (int)count($results) + count($iptvResults) ?> <?= sanitize(tr('نتيجة مطابقة في مكتبة المحتوى.', 'matching result(s) found in the media catalog.')) ?></p>
</div>

<?php if (empty($results) && empty($iptvResults)): ?>
    <div class="alert alert-info"><?= sanitize(tr('لا يوجد محتوى يطابق عبارة البحث.', 'No media or content matches your search term.')) ?></div>
<?php endif; ?>
<?php if (!empty($results)): ?>
    <div class="grid">
        <?php foreach ($results as $item):
            $target = ($item['type'] === 'tv') ? 'show.php' : (($item['type'] === 'movie') ? 'watch.php' : 'download.php');
            $url = site_url($target . '?id=' . $item['id']);
        ?>
            <div class="media-card">
                <div class="media-card-artwork">
                    <a class="media-card-image-link" href="<?= sanitize($url) ?>" aria-label="<?= sanitize($item['title']) ?>">
                        <?php if (!empty($item['poster_path'])): ?>
                            <img src="<?= sanitize(site_url($item['poster_path'])) ?>" alt="<?= sanitize($item['title']) ?>" class="media-poster" loading="lazy">
                        <?php else: ?>
                            <span class="media-poster media-poster-placeholder"><?= sanitize(tr('لا توجد صورة', 'No cover')) ?></span>
                        <?php endif; ?>
                    </a>
                    <div class="media-card-overlay">
                        <strong class="media-card-overlay-title"><?= sanitize($item['title']) ?></strong>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php if (!empty($iptvResults)): ?>
    <section class="search-iptv-results">
        <h2><?= sanitize(tr('نتائج مصادر البث', 'Streaming source results')) ?></h2>
        <div class="grid iptv-grid">
            <?php foreach ($iptvResults as $item): ?>
                <a class="iptv-card <?= in_array($item['item_type'], ['movie', 'series'], true) ? 'iptv-card--poster' : '' ?>" href="<?= sanitize(site_url('iptv.php?source=' . (int)$item['source_id'] . '&item=' . (int)$item['id'])) ?>">
                    <span class="iptv-card-artwork">
                        <?php if ($item['poster_url'] !== ''): ?>
                            <img src="<?= sanitize(site_url('iptv_image.php?source=' . (int)$item['source_id'] . '&item=' . (int)$item['id'])) ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <span class="iptv-card-placeholder" aria-hidden="true">&#9654;</span>
                        <?php endif; ?>
                        <span class="iptv-card-overlay"><?= sanitize($item['title']) ?></span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php
render_footer();
