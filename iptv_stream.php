<?php

require_once __DIR__ . '/includes/iptv.php';
ensure_iptv_schema();

$resourceToken = $_GET['r'] ?? '';
$sourceId = 0;
$targetUrl = '';
$title = 'stream';
$item = false;

if (is_string($resourceToken) && preg_match('/^[a-f0-9]{64}$/', $resourceToken)) {
    $resource = DB::fetch(
        'SELECT r.source_id, r.target_url FROM {prefix}iptv_resources r
         JOIN {prefix}iptv_sources s ON s.id = r.source_id
         WHERE r.token = ? AND s.enabled = 1',
        [$resourceToken]
    );
    if ($resource) {
        $sourceId = (int)$resource['source_id'];
        $targetUrl = $resource['target_url'];
    }
} else {
    $sourceId = (int)($_GET['source'] ?? 0);
    $itemId = (int)($_GET['item'] ?? 0);
    $item = iptv_fetch_item($sourceId, $itemId);
    if ($item && !empty($item['stream_url'])) {
        $targetUrl = $item['stream_url'];
        $title = $item['title'];
    }
}

if ($sourceId < 1 || !iptv_valid_http_url($targetUrl)) {
    http_response_code(404);
    exit('Stream not found.');
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$transcodeRequested = isset($_GET['transcode']) && $_GET['transcode'] === '1';
$isResourceRequest = is_string($resourceToken) && preg_match('/^[a-f0-9]{64}$/', $resourceToken) === 1;
if ($transcodeRequested) {
    if (!$isResourceRequest && (!$item || $item['item_type'] !== 'live')) {
        http_response_code(400);
        exit('Audio conversion is available for live channels only.');
    }
    if (!function_exists('proc_open') || configured_ffmpeg_path() === '') {
        http_response_code(503);
        exit('FFmpeg is not installed or configured on this server.');
    }
}

$path = strtolower((string)parse_url($targetUrl, PHP_URL_PATH));
$isPlaylist = preg_match('/\.m3u8?$/', $path) === 1;
if ($isPlaylist) {
    try {
        $body = iptv_http_get($targetUrl, 30, 10 * 1024 * 1024, $playlistUrl);
    } catch (Throwable $exception) {
        error_log('IPTV playlist request failed: ' . $exception->getMessage());
        http_response_code(502);
        exit('The stream playlist could not be loaded.');
    }

    $proxyResource = function ($uri, $transcodeResource = false) use ($sourceId, $playlistUrl) {
        $resolved = iptv_resolve_url($playlistUrl, $uri);
        if ($resolved === '') {
            return $uri;
        }
        $token = iptv_resource_token($sourceId, $resolved);
        return site_url('iptv_stream.php?r=' . rawurlencode($token))
            . ($transcodeResource ? '&transcode=1' : '');
    };
    $lines = preg_split('/\r\n|\r|\n/', $body);
    foreach ($lines as &$line) {
        if ($line === '' || $line[0] === '#') {
            $isEncryptionOrInit = preg_match('/^#EXT-X-(KEY|MAP):/i', $line) === 1;
            $line = preg_replace_callback('/URI="([^"]+)"/', function ($match) use ($proxyResource, $transcodeRequested, $isEncryptionOrInit) {
                return 'URI="' . $proxyResource($match[1], $transcodeRequested && !$isEncryptionOrInit) . '"';
            }, $line);
        } else {
            $line = $proxyResource(trim($line), $transcodeRequested);
        }
    }
    unset($line);
    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-store');
    echo implode("\n", $lines);
    exit;
}

if ($transcodeRequested) {
    $ffmpegPath = configured_ffmpeg_path();
    $pipes = [];
    if (function_exists('set_time_limit')) {
        set_time_limit(0);
    }
    $process = @proc_open([
        $ffmpegPath,
        '-hide_banner',
        '-loglevel', 'error',
        '-nostdin',
        '-user_agent', 'CinemaCMS/1.0',
        '-rw_timeout', '15000000',
        '-i', $targetUrl,
        '-map', '0:v:0?',
        '-map', '0:a:0?',
        '-c:v', 'copy',
        '-c:a', 'aac',
        '-profile:a', 'aac_low',
        '-ar', '48000',
        '-b:a', '128k',
        '-ac', '2',
        '-mpegts_flags', '+resend_headers',
        '-f', 'mpegts',
        'pipe:1'
    ], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'a']
    ], $pipes);
    if (!is_resource($process)) {
        http_response_code(503);
        exit('Could not start FFmpeg. Check PHP process permissions.');
    }

    fclose($pipes[0]);
    stream_set_timeout($pipes[1], 30);
    $firstChunk = '';
    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 16384);
        if ($chunk === false) {
            break;
        }
        if ($chunk !== '') {
            $firstChunk = $chunk;
            break;
        }
        $pipeStatus = stream_get_meta_data($pipes[1]);
        $processStatus = proc_get_status($process);
        if (!empty($pipeStatus['timed_out']) || empty($processStatus['running'])) {
            break;
        }
        usleep(100000);
    }
    if ($firstChunk === '') {
        fclose($pipes[1]);
        proc_terminate($process);
        proc_close($process);
        http_response_code(502);
        exit('FFmpeg could not convert this live stream segment.');
    }

    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    header('Content-Type: video/mp2t');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $firstChunk;
    flush();
    while (!feof($pipes[1])) {
        if (connection_aborted()) {
            proc_terminate($process);
            break;
        }
        $chunk = fread($pipes[1], 16384);
        if ($chunk === false) {
            break;
        }
        if ($chunk === '') {
            $pipeStatus = stream_get_meta_data($pipes[1]);
            $processStatus = proc_get_status($process);
            if (!empty($pipeStatus['timed_out']) || empty($processStatus['running'])) {
                break;
            }
            usleep(100000);
            continue;
        }
        echo $chunk;
        flush();
    }
    fclose($pipes[1]);
    proc_close($process);
    exit;
}

$handle = curl_init($targetUrl);
$requestHeaders = [];
if (!empty($_SERVER['HTTP_RANGE'])) {
    $requestHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
}
$headerStarted = false;
curl_setopt_array($handle, [
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_HTTPHEADER => $requestHeaders,
    CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$headerStarted) {
        $length = strlen($line);
        $trimmed = trim($line);
        if (preg_match('/^HTTP\/\S+\s+([0-9]{3})/', $trimmed, $status)) {
            http_response_code((int)$status[1]);
            $headerStarted = true;
        } elseif ($trimmed !== '' && strpos($trimmed, ':') !== false) {
            list($name, $value) = array_map('trim', explode(':', $trimmed, 2));
            if (in_array(strtolower($name), ['content-type', 'content-length', 'content-range', 'accept-ranges', 'last-modified', 'etag'], true)) {
                header($name . ': ' . $value);
            }
        }
        return $length;
    },
    CURLOPT_WRITEFUNCTION => function ($curl, $chunk) {
        echo $chunk;
        flush();
        return strlen($chunk);
    }
]);
if (isset($_GET['download']) && $_GET['download'] === '1') {
    $extension = pathinfo((string)parse_url($targetUrl, PHP_URL_PATH), PATHINFO_EXTENSION);
    $safeTitle = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $title);
    header('Content-Disposition: attachment; filename="' . $safeTitle . ($extension !== '' ? '.' . $extension : '') . '"');
}
$result = curl_exec($handle);
$status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
$error = curl_error($handle);
curl_close($handle);
if ($result === false) {
    error_log('IPTV stream request failed: ' . $error);
    if (!$headerStarted && !headers_sent()) {
        http_response_code(502);
    }
}
