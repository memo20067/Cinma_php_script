<?php
// Software & Games Library Page

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$items = DB::fetchAll("SELECT * FROM {prefix}media WHERE type IN ('software', 'game') ORDER BY id DESC");

render_header("Software & Games Downloads");
?>

<div style="margin-bottom: 1.5rem;">
    <h1 style="color: #f59e0b;">Software & Games Library</h1>
    <p style="color: #94a3b8;">Download applications, software installers, binaries, and games.</p>
</div>

<?php if (empty($items)): ?>
    <div class="alert alert-info">No software or games available yet.</div>
<?php else: ?>
    <div class="grid">
        <?php foreach ($items as $item): ?>
            <div class="media-card">
                <a href="/download.php?id=<?= $item['id'] ?>">
                    <?php if (!empty($item['poster_path'])): ?>
                        <img src="<?= sanitize($item['poster_path']) ?>" class="media-poster">
                    <?php else: ?>
                        <div class="media-poster" style="display:flex; align-items:center; justify-content:center; color:#64748b;">No Icon</div>
                    <?php endif; ?>
                </a>
                <div class="media-info">
                    <div class="media-title">
                        <a href="/download.php?id=<?= $item['id'] ?>"><?= sanitize($item['title']) ?></a>
                    </div>
                    <div class="media-meta">
                        <span style="text-transform: uppercase; font-weight: bold; color: #f59e0b;"><?= $item['type'] ?></span>
                        <span class="badge badge-<?= $item['access_level'] ?>"><?= ucfirst($item['access_level']) ?></span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
render_footer();
