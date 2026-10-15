<?php

require_once __DIR__ . '/includes/tmdb.php';

$type = isset($_GET['type']) && is_string($_GET['type']) ? $_GET['type'] : '';
$path = isset($_GET['path']) && is_string($_GET['path']) ? $_GET['path'] : '';
if (
    !in_array($type, ['covers', 'backdrops', 'actors'], true)
    || $path === ''
    || strlen($path) > 512
    || $path[0] !== '/'
    || strpos($path, '..') !== false
    || !preg_match('~^/[A-Za-z0-9_./-]+$~', $path)
) {
    http_response_code(400);
    exit;
}

try {
    $cachedPath = (new TMDB())->downloadAndCacheImage($path, $type);
    if ($cachedPath === '') {
        http_response_code(404);
        exit;
    }
    $imagePath = realpath(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cachedPath));
    $cacheDirectory = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $type);
    if (
        !$imagePath
        || !$cacheDirectory
        || strpos($imagePath, $cacheDirectory . DIRECTORY_SEPARATOR) !== 0
        || !is_file($imagePath)
    ) {
        http_response_code(404);
        exit;
    }
    $image = @getimagesize($imagePath);
    if (!$image || !in_array($image['mime'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
        http_response_code(404);
        exit;
    }

    $etag = '"' . hash('sha256', $type . ':' . $path) . '"';
    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=2592000, immutable');
    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Type: ' . $image['mime']);
    header('Content-Length: ' . filesize($imagePath));
    readfile($imagePath);
} catch (Throwable $exception) {
    error_log('TMDB image cache failed: ' . $exception->getMessage());
    http_response_code(502);
}
