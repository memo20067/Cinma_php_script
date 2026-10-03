<?php
// HTML5 Streaming with HTTP 206 Partial Content Support

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$mediaId = (int)($_GET['id'] ?? 0);
$episodeId = (int)($_GET['ep'] ?? 0);

if ($episodeId) {
    $item = DB::fetch("SELECT e.*, m.title as show_title, m.access_level FROM {prefix}episodes e JOIN {prefix}media m ON e.media_id = m.id WHERE e.id = ?", [$episodeId]);
    if (!$item) {
        http_response_code(404);
        die("Episode not found.");
    }
    $filePath = $item['file_path'];
    $title = $item['show_title'] . " - S" . sprintf("%02d", $item['season_number']) . "E" . sprintf("%02d", $item['episode_number']) . " " . $item['title'];
    $accessLevel = $item['access_level'];
} else {
    $item = DB::fetch("SELECT m.*, mf.file_path FROM {prefix}media m LEFT JOIN {prefix}media_files mf ON m.id = mf.media_id WHERE m.id = ?", [$mediaId]);
    if (!$item || empty($item['file_path'])) {
        http_response_code(404);
        die("Media stream not found.");
    }
    $filePath = $item['file_path'];
    $title = $item['title'];
    $accessLevel = $item['access_level'];
}

// Check access level permissions
if (!check_permission($accessLevel)) {
    http_response_code(403);
    die("Access Denied: You do not have permission to stream this media. Required rank: " . ucfirst($accessLevel));
}

// Ensure file exists
if (!file_exists($filePath)) {
    http_response_code(404);
    die("Media file does not exist on server at path: " . sanitize($filePath));
}

// Check if this is a direct video stream request
if (isset($_GET['stream']) && $_GET['stream'] == '1') {
    stream_video_file($filePath);
    exit;
}

render_header("Watching: " . $title);
?>

<div style="max-width: 900px; margin: 0 auto;">
    <h1 style="margin-bottom: 1rem; color: #f8fafc; font-size: 1.75rem;"><?= sanitize($title) ?></h1>

    <div style="background: #000; border-radius: 8px; overflow: hidden; border: 1px solid #334155; margin-bottom: 1.5rem;">
        <video controls style="width: 100%; height: auto; max-height: 500px; display: block;" autoplay>
            <source src="/watch.php?<?= $episodeId ? 'ep='.$episodeId : 'id='.$mediaId ?>&stream=1" type="video/mp4">
            Your browser does not support HTML5 video streaming.
        </video>
    </div>

    <div style="background: #1e293b; padding: 1.5rem; border-radius: 8px; border: 1px solid #334155; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <span class="badge badge-<?= $accessLevel ?>"><?= ucfirst($accessLevel) ?> Access</span>
            <span style="color: #94a3b8; font-size: 0.9rem; margin-left: 0.5rem;"><?= format_size(filesize($filePath)) ?></span>
        </div>
        <div>
            <a href="/download.php?<?= $episodeId ? 'ep='.$episodeId : 'id='.$mediaId ?>" class="btn btn-success">Download File</a>
            <a href="javascript:history.back()" class="btn" style="margin-left: 0.5rem;">&larr; Back</a>
        </div>
    </div>
</div>

<?php
render_footer();

function stream_video_file($filePath) {
    $fileSize = filesize($filePath);
    $file = fopen($filePath, 'rb');
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    $mimeTypes = [
        'mp4' => 'video/mp4',
        'mkv' => 'video/x-matroska',
        'avi' => 'video/x-msvideo',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime'
    ];
    $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';

    $length = $fileSize;
    $start = 0;
    $end = $fileSize - 1;

    header("Content-Type: " . $contentType);
    header("Accept-Ranges: bytes");

    if (isset($_SERVER['HTTP_RANGE'])) {
        $c_start = $start;
        $c_end = $end;

        list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
        if (strpos($range, ',') !== false) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            header("Content-Range: bytes $start-$end/$fileSize");
            exit;
        }

        if ($range === '-') {
            $c_start = $fileSize - substr($range, 1);
        } else {
            $range = explode('-', $range);
            $c_start = $range[0];
            $c_end = (isset($range[1]) && is_numeric($range[1])) ? $range[1] : $fileSize - 1;
        }

        $c_end = ($c_end > $fileSize - 1) ? $fileSize - 1 : $c_end;
        if ($c_start > $c_end || $c_start > $fileSize - 1 || $c_end >= $fileSize) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            header("Content-Range: bytes $start-$end/$fileSize");
            exit;
        }

        $start = $c_start;
        $end = $c_end;
        $length = $end - $start + 1;

        fseek($file, $start);
        header('HTTP/1.1 206 Partial Content');
        header("Content-Length: " . $length);
        header("Content-Range: bytes $start-$end/$fileSize");
    } else {
        header("Content-Length: " . $fileSize);
    }

    $buffer = 1024 * 64; // 64KB
    while (!feof($file) && ($p = ftell($file)) <= $end) {
        if ($p + $buffer > $end) {
            $buffer = $end - $p + 1;
        }
        set_time_limit(0);
        echo fread($file, $buffer);
        flush();
    }
    fclose($file);
    exit;
}
