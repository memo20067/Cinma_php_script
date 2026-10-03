<?php
// Movies Directory Page

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$movies = DB::fetchAll("SELECT * FROM {prefix}media WHERE type = 'movie' ORDER BY id DESC");

render_header("Movies Library");
?>

<div style="margin-bottom: 1.5rem;">
    <h1 style="color: #38bdf8;">Movies Library</h1>
    <p style="color: #94a3b8;">Stream HD uploaded movies directly from local server storage.</p>
</div>

<?php if (empty($movies)): ?>
    <div class="alert alert-info">No movies found in the database.</div>
<?php else: ?>
    <div class="grid">
        <?php foreach ($movies as $movie): ?>
            <div class="media-card">
                <a href="/watch.php?id=<?= $movie['id'] ?>">
                    <?php if (!empty($movie['poster_path'])): ?>
                        <img src="<?= sanitize($movie['poster_path']) ?>" class="media-poster">
                    <?php else: ?>
                        <div class="media-poster" style="display:flex; align-items:center; justify-content:center; color:#64748b;">No Poster</div>
                    <?php endif; ?>
                </a>
                <div class="media-info">
                    <div class="media-title">
                        <a href="/watch.php?id=<?= $movie['id'] ?>"><?= sanitize($movie['title']) ?></a>
                    </div>
                    <div class="media-meta">
                        <span><?= $movie['release_year'] ?></span>
                        <span class="badge badge-<?= $movie['access_level'] ?>"><?= ucfirst($movie['access_level']) ?></span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
render_footer();
