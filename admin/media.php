<?php
// Admin Media Upload & Management Controller

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tmdb.php';

require_permission('moderator');

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'upload_media') {
        $title = trim($_POST['title'] ?? '');
        $type = $_POST['type'] ?? 'movie';
        $plot = trim($_POST['plot'] ?? '');
        $releaseYear = (int)($_POST['release_year'] ?? 0);
        $accessLevel = $_POST['access_level'] ?? 'public';
        $tmdbId = (int)($_POST['tmdb_id'] ?? 0);
        $serverFilePath = trim($_POST['server_file_path'] ?? '');

        if (empty($title)) {
            $error = "Title is required.";
        } else {
            $uploadedFilePath = '';

            // Handle Direct Web File Upload if provided
            if (isset($_FILES['media_file']) && $_FILES['media_file']['error'] === UPLOAD_ERR_OK) {
                $tmpName = $_FILES['media_file']['tmp_name'];
                $fileName = time() . '_' . basename($_FILES['media_file']['name']);
                $targetDir = __DIR__ . '/../uploads/files/';
                if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
                $targetPath = $targetDir . $fileName;
                if (move_uploaded_file($tmpName, $targetPath)) {
                    $uploadedFilePath = $targetPath;
                }
            } elseif (!empty($serverFilePath)) {
                $uploadedFilePath = $serverFilePath;
            }

            // Handle Poster Upload if provided
            $posterPath = '';
            if (isset($_FILES['poster_file']) && $_FILES['poster_file']['error'] === UPLOAD_ERR_OK) {
                $tmpName = $_FILES['poster_file']['tmp_name'];
                $fileName = time() . '_poster_' . basename($_FILES['poster_file']['name']);
                $targetDir = __DIR__ . '/../uploads/covers/';
                if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
                if (move_uploaded_file($tmpName, $targetDir . $fileName)) {
                    $posterPath = '/uploads/covers/' . $fileName;
                }
            }

            // Insert Media Record
            $mediaId = DB::insert('media', [
                'title' => $title,
                'type' => $type,
                'plot' => $plot,
                'release_year' => $releaseYear,
                'poster_path' => $posterPath,
                'tmdb_id' => $tmdbId,
                'access_level' => $accessLevel
            ]);

            // Insert Media File record if file was provided
            if (!empty($uploadedFilePath)) {
                $fileSize = file_exists($uploadedFilePath) ? filesize($uploadedFilePath) : 0;
                DB::insert('media_files', [
                    'media_id' => $mediaId,
                    'file_path' => $uploadedFilePath,
                    'file_size' => $fileSize,
                    'file_type' => ($type === 'movie' || $type === 'tv') ? 'video' : 'binary',
                    'access_level' => $accessLevel
                ]);
            }

            // If TMDB ID provided, auto scrape metadata and poster
            if ($tmdbId > 0) {
                $tmdb = new TMDB();
                if ($type === 'movie') {
                    $tmdb->importMovieMetadata($tmdbId, $accessLevel);
                } elseif ($type === 'tv') {
                    $tmdb->importTVShowMetadata($tmdbId, $accessLevel);
                }
            }

            $message = "Successfully created new " . ucfirst($type) . ": " . sanitize($title);
        }
    } elseif ($action === 'delete_media') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            DB::delete('media', 'id = ?', [$id]);
            DB::delete('media_files', 'media_id = ?', [$id]);
            DB::delete('episodes', 'media_id = ?', [$id]);
            DB::delete('cast_members', 'media_id = ?', [$id]);
            $message = "Media item deleted successfully.";
        }
    }
}

$mediaItems = DB::fetchAll("SELECT m.*, (SELECT COUNT(*) FROM {prefix}media_files WHERE media_id = m.id) as file_count FROM {prefix}media m ORDER BY m.id DESC");

render_header("Manage Media & Content");
?>

<div style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center;">
    <h1>Media Management (Uploads & Catalog)</h1>
    <a href="/admin/index.php" class="btn">&larr; Back to Admin Dashboard</a>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= $message ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><?= $error ?></div>
<?php endif; ?>

<!-- Add Media Form -->
<div style="background: #1e293b; padding: 1.5rem; border-radius: 8px; border: 1px solid #334155; margin-bottom: 2rem;">
    <h2 style="font-size: 1.25rem; margin-bottom: 1.25rem; color: #38bdf8;">Add / Upload New Media / Software / Game</h2>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="upload_media">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
            <div class="form-group">
                <label>Title *</label>
                <input type="text" name="title" class="form-control" required placeholder="Title...">
            </div>
            <div class="form-group">
                <label>Content Type</label>
                <select name="type" class="form-control">
                    <option value="movie">Movie</option>
                    <option value="tv">TV Series</option>
                    <option value="software">Software / App</option>
                    <option value="game">Game</option>
                </select>
            </div>
            <div class="form-group">
                <label>Release Year</label>
                <input type="number" name="release_year" class="form-control" value="<?= date('Y') ?>">
            </div>
            <div class="form-group">
                <label>Access Level Permission</label>
                <select name="access_level" class="form-control">
                    <option value="public">Public (Everyone)</option>
                    <option value="registered">Registered Members Only</option>
                    <option value="subscriber">Paid Subscribers Only</option>
                    <option value="admin">Admins Only</option>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label>Plot / Description</label>
            <textarea name="plot" class="form-control" rows="3" placeholder="Summary details..."></textarea>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
            <div class="form-group">
                <label>Direct File Upload (Max <?= ini_get('upload_max_filesize') ?>)</label>
                <input type="file" name="media_file" class="form-control">
            </div>
            <div class="form-group">
                <label>OR Local Server File Path</label>
                <input type="text" name="server_file_path" class="form-control" placeholder="/var/uploads/movie.mp4">
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
            <div class="form-group">
                <label>Poster / Cover Image Upload</label>
                <input type="file" name="poster_file" class="form-control" accept="image/*">
            </div>
            <div class="form-group">
                <label>TMDB ID (Optional Auto-Scraper)</label>
                <input type="number" name="tmdb_id" class="form-control" placeholder="e.g. 550">
            </div>
        </div>

        <button type="submit" class="btn btn-success" style="padding: 0.6rem 1.25rem;">Save & Upload Media</button>
    </form>
</div>

<!-- Catalog Table -->
<h2 style="font-size: 1.2rem; margin-bottom: 1rem;">Current Media & File Catalog</h2>
<table style="width: 100%; border-collapse: collapse; background: #1e293b; border-radius: 8px; overflow: hidden; border: 1px solid #334155;">
    <thead>
        <tr style="background: #334155; text-align: left;">
            <th style="padding: 0.75rem;">Cover</th>
            <th style="padding: 0.75rem;">Title</th>
            <th style="padding: 0.75rem;">Type</th>
            <th style="padding: 0.75rem;">Access Level</th>
            <th style="padding: 0.75rem;">Files</th>
            <th style="padding: 0.75rem;">Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($mediaItems as $item): ?>
            <tr style="border-bottom: 1px solid #334155;">
                <td style="padding: 0.5rem; width: 60px;">
                    <?php if (!empty($item['poster_path'])): ?>
                        <img src="<?= sanitize($item['poster_path']) ?>" style="width: 45px; height: 60px; object-fit: cover; border-radius: 4px;">
                    <?php else: ?>
                        <div style="width: 45px; height: 60px; background: #334155; border-radius: 4px;"></div>
                    <?php endif; ?>
                </td>
                <td style="padding: 0.75rem;">
                    <strong><?= sanitize($item['title']) ?></strong> (<?= $item['release_year'] ?>)<br>
                    <span style="font-size: 0.8rem; color: #94a3b8;"><?= sanitize(substr($item['plot'], 0, 80)) ?>...</span>
                </td>
                <td style="padding: 0.75rem;">
                    <span class="badge" style="background: #0284c7;"><?= strtoupper($item['type']) ?></span>
                </td>
                <td style="padding: 0.75rem;">
                    <span class="badge badge-<?= $item['access_level'] ?>"><?= ucfirst($item['access_level']) ?></span>
                </td>
                <td style="padding: 0.75rem; font-size: 0.9rem;">
                    <?= $item['file_count'] ?> file(s)
                </td>
                <td style="padding: 0.75rem;">
                    <div style="display: flex; gap: 0.5rem;">
                        <a href="/watch.php?id=<?= $item['id'] ?>" class="btn btn-sm">View</a>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this media catalog item?');">
                            <input type="hidden" name="action" value="delete_media">
                            <input type="hidden" name="id" value="<?= $item['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php
render_footer();
