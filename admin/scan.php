<?php
// Admin Server Directory Scanner & TV Series Episode Merger

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scanner.php';

require_permission('moderator');

$message = '';
$error = '';

// Handle linking files as movies/media or tv episodes
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'link_episodes') {
        $tvSeriesId = (int)($_POST['tv_series_id'] ?? 0);
        $selectedFiles = $_POST['selected_files'] ?? [];

        if (!$tvSeriesId) {
            $error = "Please select a target TV Series.";
        } elseif (empty($selectedFiles)) {
            $error = "No files selected for merging/linking.";
        } else {
            $linkedCount = 0;
            foreach ($selectedFiles as $filePath) {
                $seasonNum = (int)($_POST['season_' . md5($filePath)] ?? 1);
                $epNum = (int)($_POST['episode_' . md5($filePath)] ?? 1);
                $epTitle = trim($_POST['title_' . md5($filePath)] ?? "Episode {$epNum}");

                if (file_exists($filePath)) {
                    $fileSize = filesize($filePath);

                    // Insert Episode
                    $epId = DB::insert('episodes', [
                        'media_id' => $tvSeriesId,
                        'season_number' => $seasonNum,
                        'episode_number' => $epNum,
                        'title' => $epTitle,
                        'file_path' => $filePath,
                        'duration' => 0
                    ]);

                    // Insert Media File record
                    DB::insert('media_files', [
                        'media_id' => $tvSeriesId,
                        'file_path' => $filePath,
                        'file_size' => $fileSize,
                        'file_type' => 'video',
                        'access_level' => 'public'
                    ]);

                    $linkedCount++;
                }
            }
            $message = "Successfully linked {$linkedCount} episode file(s) to the TV Series!";
        }
    } elseif ($action === 'add_movie') {
        $filePath = $_POST['file_path'] ?? '';
        $title = trim($_POST['title'] ?? '');
        $accessLevel = $_POST['access_level'] ?? 'public';

        if (!file_exists($filePath)) {
            $error = "File does not exist on server: " . sanitize($filePath);
        } elseif (empty($title)) {
            $error = "Movie title is required.";
        } else {
            $mediaId = DB::insert('media', [
                'title' => $title,
                'type' => 'movie',
                'access_level' => $accessLevel
            ]);

            DB::insert('media_files', [
                'media_id' => $mediaId,
                'file_path' => $filePath,
                'file_size' => filesize($filePath),
                'file_type' => 'video',
                'access_level' => $accessLevel
            ]);

            $message = "Successfully imported video file as Movie: " . sanitize($title);
        }
    }
}

$scanPath = $_GET['scan_dir'] ?? __DIR__ . '/../uploads';
$scannedFiles = is_dir($scanPath) ? MediaScanner::scanDirectory($scanPath) : [];

// Fetch TV Series list for linking dropdown
$tvShows = DB::fetchAll("SELECT id, title FROM {prefix}media WHERE type = 'tv' ORDER BY title ASC");

render_header('Server File Scanner & Episode Merger');
?>

<div style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center;">
    <h1>Server Directory Scanner & TV Series Merger</h1>
    <a href="/admin/index.php" class="btn">&larr; Back to Admin Dashboard</a>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= $message ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><?= $error ?></div>
<?php endif; ?>

<div style="background: #1e293b; padding: 1.5rem; border-radius: 8px; border: 1px solid #334155; margin-bottom: 2rem;">
    <form method="GET" style="display: flex; gap: 1rem;">
        <input type="text" name="scan_dir" class="form-control" value="<?= sanitize($scanPath) ?>" placeholder="Directory path on server..." style="flex: 1;">
        <button type="submit" class="btn btn-primary">Scan Server Directory</button>
    </form>
    <div style="font-size: 0.85rem; color: #94a3b8; margin-top: 0.5rem;">
        Scans for video (.mp4, .mkv, .avi) and downloadable media files.
    </div>
</div>

<?php if (empty($scannedFiles)): ?>
    <div class="alert alert-info">No supported media files found in specified path.</div>
<?php else: ?>
    <form method="POST">
        <input type="hidden" name="action" value="link_episodes">
        <div style="background: #1e293b; padding: 1.5rem; border-radius: 8px; border: 1px solid #334155; margin-bottom: 1.5rem;">
            <h2 style="font-size: 1.2rem; margin-bottom: 1rem; color: #38bdf8;">Bulk TV Series Episode Linking</h2>
            <div class="form-group" style="max-width: 400px;">
                <label>Select Target TV Series:</label>
                <select name="tv_series_id" class="form-control" required>
                    <option value="">-- Choose Existing TV Show --</option>
                    <?php foreach ($tvShows as $show): ?>
                        <option value="<?= $show['id'] ?>"><?= sanitize($show['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <table style="width: 100%; border-collapse: collapse; background: #1e293b; border-radius: 8px; overflow: hidden;">
            <thead>
                <tr style="background: #334155; text-align: left;">
                    <th style="padding: 0.75rem; width: 40px;"><input type="checkbox" onclick="toggleAll(this)"></th>
                    <th style="padding: 0.75rem;">File Name / Path</th>
                    <th style="padding: 0.75rem;">Detected Title</th>
                    <th style="padding: 0.75rem; width: 90px;">Season</th>
                    <th style="padding: 0.75rem; width: 90px;">Episode</th>
                    <th style="padding: 0.75rem;">Size</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($scannedFiles as $file):
                    $hash = md5($file['file_path']);
                ?>
                    <tr style="border-bottom: 1px solid #334155;">
                        <td style="padding: 0.75rem;">
                            <input type="checkbox" name="selected_files[]" value="<?= sanitize($file['file_path']) ?>">
                        </td>
                        <td style="padding: 0.75rem;">
                            <strong style="color: #f8fafc;"><?= sanitize($file['file_name']) ?></strong><br>
                            <span style="font-size: 0.8rem; color: #64748b;"><?= sanitize($file['file_path']) ?></span>
                        </td>
                        <td style="padding: 0.75rem;">
                            <input type="text" name="title_<?= $hash ?>" class="form-control" value="<?= sanitize($file['parsed_title']) ?>">
                        </td>
                        <td style="padding: 0.75rem;">
                            <input type="number" name="season_<?= $hash ?>" class="form-control" value="<?= $file['season'] ?? 1 ?>" min="1">
                        </td>
                        <td style="padding: 0.75rem;">
                            <input type="number" name="episode_<?= $hash ?>" class="form-control" value="<?= $file['episode'] ?? 1 ?>" min="1">
                        </td>
                        <td style="padding: 0.75rem; font-size: 0.85rem; color: #94a3b8;">
                            <?= format_size($file['file_size']) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top: 1.5rem; text-align: right;">
            <button type="submit" class="btn btn-success" style="padding: 0.75rem 1.5rem; font-size: 1rem;">
                Merge & Link Selected Episodes
            </button>
        </div>
    </form>
<?php endif; ?>

<script>
function toggleAll(master) {
    const checkboxes = document.querySelectorAll('input[name="selected_files[]"]');
    checkboxes.forEach(cb => cb.checked = master.checked);
}
</script>

<?php
render_footer();
