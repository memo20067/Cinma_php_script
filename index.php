<?php
// Homepage - Main Portal

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

// Fetch Latest Movies
$movies = DB::fetchAll("SELECT * FROM {prefix}media WHERE type = 'movie' ORDER BY id DESC LIMIT 6");

// Fetch Latest TV Shows
$tvShows = DB::fetchAll("SELECT * FROM {prefix}media WHERE type = 'tv' ORDER BY id DESC LIMIT 6");

// Fetch Latest Software & Games
$appsGames = DB::fetchAll("SELECT * FROM {prefix}media WHERE type IN ('software', 'game') ORDER BY id DESC LIMIT 6");

render_header();
?>

<!-- Hero Section -->
<div style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); padding: 3rem 2rem; border-radius: 12px; border: 1px solid #334155; margin-bottom: 2.5rem; text-align: center;">
    <h1 style="font-size: 2.5rem; color: #38bdf8; margin-bottom: 0.75rem;">Welcome to <?= sanitize(get_setting('site_name', 'CinemaCMS')) ?></h1>
    <p style="font-size: 1.1rem; color: #cbd5e1; max-width: 700px; margin: 0 auto 1.5rem;">
        Your ultimate local-first content hub for streaming uploaded movies, TV series, downloadable software, and games. Completely functional offline.
    </p>
    <div style="display: flex; gap: 1rem; justify-content: center;">
        <a href="/movies.php" class="btn btn-primary" style="padding: 0.6rem 1.25rem;">Browse Movies</a>
        <a href="/tvshows.php" class="btn btn-success" style="padding: 0.6rem 1.25rem;">Watch TV Series</a>
        <a href="/software.php" class="btn" style="padding: 0.6rem 1.25rem; background: #f59e0b; color: #000; font-weight: 600;">Apps & Games</a>
    </div>
</div>

<!-- Movies Section -->
<div style="margin-bottom: 3rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <h2 style="font-size: 1.5rem; color: #f8fafc; border-left: 4px solid #38bdf8; padding-left: 0.75rem;">Latest Movies</h2>
        <a href="/movies.php" style="font-size: 0.9rem; color: #38bdf8;">View All Movies &rarr;</a>
    </div>
    <?php if (empty($movies)): ?>
        <div class="alert alert-info">No movies available yet.</div>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($movies as $movie): ?>
                <div class="media-card">
                    <a href="/watch.php?id=<?= $movie['id'] ?>">
                        <?php if (!empty($movie['poster_path'])): ?>
                            <img src="<?= sanitize($movie['poster_path']) ?>" class="media-poster">
                        <?php else: ?>
                            <div class="media-poster" style="display:flex; align-items:center; justify-content:center; color:#64748b;">No Cover</div>
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
</div>

<!-- TV Series Section -->
<div style="margin-bottom: 3rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <h2 style="font-size: 1.5rem; color: #f8fafc; border-left: 4px solid #10b981; padding-left: 0.75rem;">Popular TV Series</h2>
        <a href="/tvshows.php" style="font-size: 0.9rem; color: #10b981;">View All TV Shows &rarr;</a>
    </div>
    <?php if (empty($tvShows)): ?>
        <div class="alert alert-info">No TV series available yet.</div>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($tvShows as $show): ?>
                <div class="media-card">
                    <a href="/show.php?id=<?= $show['id'] ?>">
                        <?php if (!empty($show['poster_path'])): ?>
                            <img src="<?= sanitize($show['poster_path']) ?>" class="media-poster">
                        <?php else: ?>
                            <div class="media-poster" style="display:flex; align-items:center; justify-content:center; color:#64748b;">No Cover</div>
                        <?php endif; ?>
                    </a>
                    <div class="media-info">
                        <div class="media-title">
                            <a href="/show.php?id=<?= $show['id'] ?>"><?= sanitize($show['title']) ?></a>
                        </div>
                        <div class="media-meta">
                            <span><?= $show['release_year'] ?></span>
                            <span class="badge badge-<?= $show['access_level'] ?>"><?= ucfirst($show['access_level']) ?></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Software & Games Section -->
<div style="margin-bottom: 3rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <h2 style="font-size: 1.5rem; color: #f8fafc; border-left: 4px solid #f59e0b; padding-left: 0.75rem;">Software, Apps & Games</h2>
        <a href="/software.php" style="font-size: 0.9rem; color: #f59e0b;">View All Downloads &rarr;</a>
    </div>
    <?php if (empty($appsGames)): ?>
        <div class="alert alert-info">No software or games available yet.</div>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($appsGames as $app): ?>
                <div class="media-card">
                    <a href="/download.php?id=<?= $app['id'] ?>">
                        <?php if (!empty($app['poster_path'])): ?>
                            <img src="<?= sanitize($app['poster_path']) ?>" class="media-poster">
                        <?php else: ?>
                            <div class="media-poster" style="display:flex; align-items:center; justify-content:center; color:#64748b;">No Cover</div>
                        <?php endif; ?>
                    </a>
                    <div class="media-info">
                        <div class="media-title">
                            <a href="/download.php?id=<?= $app['id'] ?>"><?= sanitize($app['title']) ?></a>
                        </div>
                        <div class="media-meta">
                            <span style="text-transform: uppercase; font-weight: bold; color: #f59e0b;"><?= $app['type'] ?></span>
                            <span class="badge badge-<?= $app['access_level'] ?>"><?= ucfirst($app['access_level']) ?></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php
render_footer();
