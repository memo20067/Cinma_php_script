<?php
// TV Show Detail Page - Seasons & Episodes View

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$showId = (int)($_GET['id'] ?? 0);
$show = DB::fetch("SELECT * FROM {prefix}media WHERE id = ? AND type = 'tv'", [$showId]);

if (!$show) {
    http_response_code(404);
    die("TV Show not found.");
}

// Fetch Cast Members
$cast = DB::fetchAll("SELECT * FROM {prefix}cast_members WHERE media_id = ?", [$showId]);

// Fetch Episodes grouped by season
$episodes = DB::fetchAll("SELECT * FROM {prefix}episodes WHERE media_id = ? ORDER BY season_number ASC, episode_number ASC", [$showId]);

$seasons = [];
foreach ($episodes as $ep) {
    $sNum = $ep['season_number'];
    if (!isset($seasons[$sNum])) {
        $seasons[$sNum] = [];
    }
    $seasons[$sNum][] = $ep;
}

render_header($show['title']);
?>

<!-- Show Banner / Overview -->
<div style="background: #1e293b; border-radius: 12px; border: 1px solid #334155; padding: 2rem; margin-bottom: 2rem; display: flex; gap: 2rem; flex-wrap: wrap;">
    <div style="width: 220px; flex-shrink: 0;">
        <?php if (!empty($show['poster_path'])): ?>
            <img src="<?= sanitize($show['poster_path']) ?>" style="width: 100%; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.5);">
        <?php else: ?>
            <div style="width: 100%; aspect-ratio: 2/3; background: #334155; border-radius: 8px;"></div>
        <?php endif; ?>
    </div>
    <div style="flex: 1; min-width: 280px;">
        <h1 style="font-size: 2rem; color: #f8fafc; margin-bottom: 0.5rem;"><?= sanitize($show['title']) ?> (<?= $show['release_year'] ?>)</h1>
        <div style="margin-bottom: 1rem;">
            <span class="badge badge-<?= $show['access_level'] ?>"><?= ucfirst($show['access_level']) ?> Access</span>
            <?php if ($show['rating'] > 0): ?>
                <span style="color: #f59e0b; font-weight: bold; margin-left: 0.5rem;">★ <?= $show['rating'] ?>/10</span>
            <?php endif; ?>
        </div>
        <p style="color: #cbd5e1; font-size: 1rem; line-height: 1.6; margin-bottom: 1.5rem;"><?= sanitize($show['plot']) ?></p>

        <!-- Cast List -->
        <?php if (!empty($cast)): ?>
            <h3 style="font-size: 1rem; color: #94a3b8; margin-bottom: 0.5rem;">Top Cast</h3>
            <div style="display: flex; gap: 1rem; overflow-x: auto; padding-bottom: 0.5rem;">
                <?php foreach ($cast as $actor): ?>
                    <div style="text-align: center; width: 80px; flex-shrink: 0;">
                        <?php if (!empty($actor['profile_path'])): ?>
                            <img src="<?= sanitize($actor['profile_path']) ?>" style="width: 60px; height: 60px; border-radius: 50%; object-fit: cover;">
                        <?php else: ?>
                            <div style="width: 60px; height: 60px; border-radius: 50%; background: #334155; margin: 0 auto;"></div>
                        <?php endif; ?>
                        <div style="font-size: 0.75rem; color: #f8fafc; font-weight: 500; margin-top: 0.25rem; word-break: break-word;"><?= sanitize($actor['name']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Seasons & Episodes Accordion -->
<h2 style="font-size: 1.5rem; margin-bottom: 1rem; color: #10b981;">Seasons & Episodes</h2>

<?php if (empty($seasons)): ?>
    <div class="alert alert-info">No episodes available yet for this TV series.</div>
<?php else: ?>
    <?php foreach ($seasons as $seasonNum => $epList): ?>
        <div style="background: #1e293b; border-radius: 8px; border: 1px solid #334155; margin-bottom: 1.5rem; overflow: hidden;">
            <div style="background: #334155; padding: 0.75rem 1.25rem; font-weight: bold; font-size: 1.1rem; color: #38bdf8;">
                Season <?= $seasonNum ?> (<?= count($epList) ?> Episodes)
            </div>
            <div style="padding: 1rem;">
                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <?php foreach ($epList as $ep): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; background: #0f172a; padding: 0.75rem 1rem; border-radius: 6px; border: 1px solid #1e293b;">
                            <div>
                                <strong style="color: #f8fafc;">E<?= sprintf("%02d", $ep['episode_number']) ?> - <?= sanitize($ep['title']) ?></strong>
                            </div>
                            <div style="display: flex; gap: 0.5rem;">
                                <a href="/watch.php?ep=<?= $ep['id'] ?>" class="btn btn-sm btn-primary">Watch Episode</a>
                                <a href="/download.php?ep=<?= $ep['id'] ?>" class="btn btn-sm btn-success">Download File</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php
render_footer();
