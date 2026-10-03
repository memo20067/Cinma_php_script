<?php
// TV Shows Directory Page

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$shows = DB::fetchAll("SELECT m.*, (SELECT COUNT(*) FROM {prefix}episodes WHERE media_id = m.id) as ep_count FROM {prefix}media m WHERE m.type = 'tv' ORDER BY m.id DESC");

render_header("TV Series Library");
?>

<div style="margin-bottom: 1.5rem;">
    <h1 style="color: #10b981;">TV Series Library</h1>
    <p style="color: #94a3b8;">Browse TV shows and organized seasons/episodes.</p>
</div>

<?php if (empty($shows)): ?>
    <div class="alert alert-info">No TV series found in the database.</div>
<?php else: ?>
    <div class="grid">
        <?php foreach ($shows as $show): ?>
            <div class="media-card">
                <a href="/show.php?id=<?= $show['id'] ?>">
                    <?php if (!empty($show['poster_path'])): ?>
                        <img src="<?= sanitize($show['poster_path']) ?>" class="media-poster">
                    <?php else: ?>
                        <div class="media-poster" style="display:flex; align-items:center; justify-content:center; color:#64748b;">No Poster</div>
                    <?php endif; ?>
                </a>
                <div class="media-info">
                    <div class="media-title">
                        <a href="/show.php?id=<?= $show['id'] ?>"><?= sanitize($show['title']) ?></a>
                    </div>
                    <div class="media-meta">
                        <span><?= $show['ep_count'] ?> Episode(s)</span>
                        <span class="badge badge-<?= $show['access_level'] ?>"><?= ucfirst($show['access_level']) ?></span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
render_footer();
