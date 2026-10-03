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
                $message = "Successfully scraped and imported " . ucfirst($type) . " (TMDB ID: {$tmdbId}) with local covers & metadata!";
            } else {
                $error = "Failed to fetch metadata from TMDB. Please check your API key.";
            }
        }
    }
}

render_header("TMDB Auto-Scraper");
?>

<div style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center;">
    <h1>TMDB Metadata & Artwork Auto-Scraper</h1>
    <a href="/admin/index.php" class="btn">&larr; Back to Admin Dashboard</a>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= sanitize($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><?= sanitize($error) ?></div>
<?php endif; ?>

<div style="background: #1e293b; padding: 1.5rem; border-radius: 8px; border: 1px solid #334155; margin-bottom: 2rem;">
    <h2 style="font-size: 1.2rem; color: #38bdf8; margin-bottom: 1rem;">Search TMDB Database</h2>
    <form method="POST">
        <input type="hidden" name="action" value="search">
        <div style="display: grid; grid-template-columns: 2fr 1fr 1fr auto; gap: 1rem; align-items: end;">
            <div class="form-group" style="margin:0;">
                <label>Movie or TV Series Title</label>
                <input type="text" name="query" class="form-control" placeholder="e.g. Inception, Breaking Bad..." value="<?= sanitize($_POST['query'] ?? '') ?>" required>
            </div>
            <div class="form-group" style="margin:0;">
                <label>Type</label>
                <select name="type" class="form-control">
                    <option value="movie" <?= ($_POST['type'] ?? '') === 'movie' ? 'selected' : '' ?>>Movie</option>
                    <option value="tv" <?= ($_POST['type'] ?? '') === 'tv' ? 'selected' : '' ?>>TV Series</option>
                </select>
            </div>
            <div class="form-group" style="margin:0;">
                <label>Release Year (Optional)</label>
                <input type="number" name="year" class="form-control" placeholder="YYYY" value="<?= sanitize($_POST['year'] ?? '') ?>">
            </div>
            <button type="submit" class="btn btn-primary" style="height: 42px;">Search Online</button>
        </div>
    </form>
</div>

<?php if ($searchResults && isset($searchResults['results'])): ?>
    <h2 style="font-size: 1.25rem; margin-bottom: 1rem;">Search Results (<?= count($searchResults['results']) ?> items found)</h2>
    <div class="grid">
        <?php foreach ($searchResults['results'] as $item):
            $title = $item['title'] ?? $item['name'] ?? 'Untitled';
            $date = $item['release_date'] ?? $item['first_air_date'] ?? '';
            $year = !empty($date) ? substr($date, 0, 4) : 'N/A';
            $tmdbId = $item['id'];
            $type = isset($item['title']) ? 'movie' : 'tv';
            $poster = !empty($item['poster_path']) ? 'https://image.tmdb.org/t/p/w300' . $item['poster_path'] : '';
        ?>
            <div class="media-card">
                <?php if ($poster): ?>
                    <img src="<?= $poster ?>" class="media-poster">
                <?php else: ?>
                    <div class="media-poster" style="display: flex; align-items: center; justify-content: center; color: #64748b;">No Image</div>
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
                                <option value="public">Public Access</option>
                                <option value="registered">Registered Members</option>
                                <option value="subscriber">Paid Subscribers</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-sm btn-success" style="width: 100%;">Scrape & Save Locally</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
render_footer();
