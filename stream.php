<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$episodeId = (int)($_GET['ep'] ?? 0);
$mediaId = (int)($_GET['id'] ?? 0);

if ($episodeId > 0) {
    $item = DB::fetch(
        "SELECT e.file_path, e.compatibility_path, m.id AS media_id, m.access_level
         FROM {prefix}episodes e
         JOIN {prefix}media m ON m.id = e.media_id
         WHERE e.id = ?",
        [$episodeId]
    );
} else {
    $item = DB::fetch(
        "SELECT mf.file_path, mf.compatibility_path, m.id AS media_id, m.access_level
         FROM {prefix}media_files mf
         JOIN {prefix}media m ON m.id = mf.media_id
         WHERE m.id = ? AND m.type = 'movie' AND mf.file_type = 'video'
         ORDER BY mf.id ASC LIMIT 1",
        [$mediaId]
    );
}

if (!$item || !check_permission($item['access_level'])) {
    http_response_code($item ? 403 : 404);
    exit($item ? 'Access denied.' : 'Video not found.');
}

$sourcePath = $item['file_path'];
if (!is_file($sourcePath)) {
    http_response_code(404);
    exit('Video file not found.');
}
$compatibilityPath = $item['compatibility_path'] ?? '';
$defaultPath = $compatibilityPath !== '' && is_file($compatibilityPath) ? $compatibilityPath : $sourcePath;
$requestKey = $episodeId > 0 ? 'ep=' . $episodeId : 'id=' . (int)$item['media_id'];
$hlsMode = is_string($_GET['hls'] ?? null) ? $_GET['hls'] : '';

if ($hlsMode !== '') {
    $renditions = get_video_renditions($sourcePath);
    $directory = $renditions['directory'];
    $quality = is_string($_GET['quality'] ?? null) ? $_GET['quality'] : '';

    if ($directory === '' || !in_array($hlsMode, ['master', 'variant', 'segment'], true)) {
        http_response_code(404);
        exit('Stream not found.');
    }

    if ($hlsMode === 'master') {
        $playlistPath = $renditions['master'];
    } elseif (!preg_match('/^[0-9]{3,4}p$/', $quality)) {
        http_response_code(400);
        exit('Invalid stream quality.');
    } elseif ($hlsMode === 'variant') {
        $playlistPath = $directory . DIRECTORY_SEPARATOR . $quality . '.m3u8';
    } else {
        $segment = is_string($_GET['file'] ?? null) ? $_GET['file'] : '';
        if (!preg_match('/^' . preg_quote($quality, '/') . '_[0-9]{5}\.ts$/', $segment)) {
            http_response_code(400);
            exit('Invalid stream segment.');
        }
        $segmentPath = $directory . DIRECTORY_SEPARATOR . $segment;
        if (!is_file($segmentPath)) {
            http_response_code(404);
            exit('Stream segment not found.');
        }
        session_write_close();
        protected_video_file($segmentPath, 'video/mp2t');
    }

    if ($playlistPath === '' || !is_file($playlistPath)) {
        http_response_code(404);
        exit('Stream playlist not found.');
    }
    $playlist = file($playlistPath, FILE_IGNORE_NEW_LINES);
    if ($playlist === false) {
        http_response_code(500);
        exit('Could not read stream playlist.');
    }

    foreach ($playlist as &$line) {
        $line = rtrim($line, "\r");
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $playlistUri = basename(str_replace('\\', '/', $line));
        if ($hlsMode === 'master' && preg_match('/^([0-9]{3,4}p)\.m3u8$/', $playlistUri, $match)) {
            $line = site_url('stream.php?' . $requestKey . '&hls=variant&quality=' . rawurlencode($match[1]));
        } elseif ($hlsMode === 'variant' && preg_match('/^' . preg_quote($quality, '/') . '_[0-9]{5}\.ts$/', $playlistUri, $match)) {
            $line = site_url('stream.php?' . $requestKey . '&hls=segment&quality=' . rawurlencode($quality) . '&file=' . rawurlencode($match[0]));
        } else {
            http_response_code(500);
            exit('Invalid URI in stream playlist.');
        }
    }
    unset($line);

    session_write_close();
    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: private, no-cache, no-store, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    echo implode("\n", $playlist) . "\n";
    exit;
}

if (isset($_GET['quality'])) {
    $quality = is_string($_GET['quality']) ? $_GET['quality'] : '';
    $renditions = get_video_renditions($sourcePath);
    if (!preg_match('/^[0-9]{3,4}p$/', $quality) || !isset($renditions['qualities'][$quality])) {
        http_response_code(404);
        exit('Requested quality is not available.');
    }
    $defaultPath = $renditions['qualities'][$quality];
}

if (empty($_GET['stream'])) {
    http_response_code(400);
    exit('A stream or quality must be specified.');
}

session_write_close();
$mimeTypes = [
    'mp4' => 'video/mp4',
    'm4v' => 'video/mp4',
    'webm' => 'video/webm',
    'ogv' => 'video/ogg',
    'mov' => 'video/quicktime',
    'mkv' => 'video/x-matroska',
    'avi' => 'video/x-msvideo'
];
$contentType = $mimeTypes[strtolower(pathinfo($defaultPath, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
protected_video_file($defaultPath, $contentType);

function protected_video_file(string $path, string $contentType): void {
    $fileSize = filesize($path);
    $file = fopen($path, 'rb');
    if ($file === false) {
        http_response_code(500);
        exit('Could not open video file.');
    }

    $start = 0;
    $end = $fileSize - 1;
    $status = 200;
    if (!empty($_SERVER['HTTP_RANGE'])) {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $match)
            || ($match[1] === '' && $match[2] === '')) {
            fclose($file);
            http_response_code(416);
            header('Content-Range: bytes */' . $fileSize);
            exit;
        }
        if ($match[1] === '') {
            $start = max(0, $fileSize - (int)$match[2]);
        } else {
            $start = (int)$match[1];
            if ($match[2] !== '') {
                $end = min($end, (int)$match[2]);
            }
        }
        if ($start > $end || $start >= $fileSize) {
            fclose($file);
            http_response_code(416);
            header('Content-Range: bytes */' . $fileSize);
            exit;
        }
        $status = 206;
    }

    http_response_code($status);
    header('Content-Type: ' . $contentType);
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . ($end - $start + 1));
    if ($status === 206) {
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
        fseek($file, $start);
        $remaining = $end - $start + 1;
        while ($remaining > 0 && !feof($file)) {
            $chunk = fread($file, min(65536, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
            flush();
        }
    }
    fclose($file);
    exit;
}
