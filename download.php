<?php
// Access-Controlled File Download Controller

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$mediaId = (int)($_GET['id'] ?? 0);
$fileId = (int)($_GET['file_id'] ?? 0);
$episodeId = (int)($_GET['ep'] ?? 0);

$filePath = '';
$filename = '';
$accessLevel = 'public';
$recordTable = '';
$recordId = 0;

if ($episodeId) {
    $item = DB::fetch("SELECT e.*, m.title as show_title, m.access_level as media_access FROM {prefix}episodes e JOIN {prefix}media m ON e.media_id = m.id WHERE e.id = ?", [$episodeId]);
    if ($item) {
        $filePath = $item['file_path'];
        $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $item['show_title'] . "_S" . sprintf("%02d", $item['season_number']) . "E" . sprintf("%02d", $item['episode_number']) . "_" . pathinfo($filePath, PATHINFO_BASENAME));
        $accessLevel = $item['media_access'];
    }
} elseif ($fileId) {
    $item = DB::fetch("SELECT mf.*, m.title, m.access_level as media_access FROM {prefix}media_files mf JOIN {prefix}media m ON mf.media_id = m.id WHERE mf.id = ?", [$fileId]);
    if ($item) {
        $filePath = $item['file_path'];
        $filename = basename($filePath);
        $accessLevel = !empty($item['access_level']) ? $item['access_level'] : $item['media_access'];
        $recordTable = 'media_files';
        $recordId = $item['id'];
    }
} else {
    $item = DB::fetch("SELECT m.*, mf.id as file_record_id, mf.file_path FROM {prefix}media m LEFT JOIN {prefix}media_files mf ON m.id = mf.media_id WHERE m.id = ?", [$mediaId]);
    if ($item) {
        $filePath = $item['file_path'];
        $filename = basename($filePath);
        $accessLevel = $item['access_level'];
        $recordTable = 'media_files';
        $recordId = $item['file_record_id'];
    }
}

if (empty($filePath) || !file_exists($filePath)) {
    http_response_code(404);
    die("Error: Downloadable file does not exist on server.");
}

// Role-Based Access Control Verification
if (!check_permission($accessLevel)) {
    $roleName = ucfirst($accessLevel);
    render_header("Access Denied");
    ?>
    <div style="max-width: 600px; margin: 3rem auto; background: #1e293b; padding: 2rem; border-radius: 8px; border: 1px solid #334155; text-align: center;">
        <h2 style="color: #ef4444; margin-bottom: 1rem;">Access Restricted</h2>
        <p style="margin-bottom: 1.5rem; color: #94a3b8;">This downloadable file requires <strong><?= $roleName ?></strong> access level. You are currently logged in as <strong><?= ucfirst(user_role()) ?></strong>.</p>
        <?php if (!is_logged_in()): ?>
            <a href="/login.php" class="btn btn-primary">Log In To Access</a>
        <?php else: ?>
            <p style="font-size: 0.9rem; color: #64748b;">Please upgrade your account subscription to access this content.</p>
        <?php endif; ?>
    </div>
    <?php
    render_footer();
    exit;
}

// Increment download count if media_files record exists
if ($recordTable === 'media_files' && $recordId) {
    DB::query("UPDATE {prefix}media_files SET download_count = download_count + 1 WHERE id = ?", [$recordId]);
}

// Stream file for download
$fileSize = filesize($filePath);
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . $fileSize);

ob_clean();
flush();
readfile($filePath);
exit;
