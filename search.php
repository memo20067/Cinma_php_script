<?php
// Global Search Controller

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$query = trim($_GET['q'] ?? '');

$results = [];
if (!empty($query)) {
    $searchTerm = "%{$query}%";
    $results = DB::fetchAll("SELECT * FROM {prefix}media WHERE title LIKE ? OR plot LIKE ? ORDER BY id DESC", [$searchTerm, $searchTerm]);
}

render_header("Search Results: " . $query);
?>

<div style="margin-bottom: 1.5rem;">
    <h1>Search Results for "<?= sanitize($query) ?>"</h1>
    <p style="color: #94a3b8;"><?= count($results) ?> matching result(s) found in media catalog.</p>
</div>

<?php if (empty($results)): ?>
    <div class="alert alert-info">No media or content matching your search term.</div>
<?php else: ?>
    <div class="grid">
        <?php foreach ($results as $item):
            $url = ($item['type'] === 'tv') ? "/show.php?id=" . $item['id'] : (($item['type'] === 'movie') ? "/watch.php?id=" . $item['id'] : "/download.php?id=" . $item['id']);
        ?>
            <div class="media-card">
                <a href="<?= $url ?>">
                    <?php if (!empty($item['poster_path'])): ?>
                        <img src="<?= sanitize($item['poster_path']) ?>" class="media-poster">
                    <?php else: ?>
                        <div class="media-poster" style="display:flex; align-items:center; justify-content:center; color:#64748b;">No Cover</div>
                    <?php endif; ?>
                </a>
                <div class="media-info">
                    <div class="media-title">
                        <a href="<?= $url ?>"><?= sanitize($item['title']) ?></a>
                    </div>
                    <div class="media-meta">
                        <span style="text-transform: uppercase; font-weight: bold; color: #38bdf8;"><?= $item['type'] ?></span>
                        <span class="badge badge-<?= $item['access_level'] ?>"><?= ucfirst($item['access_level']) ?></span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
render_footer();
