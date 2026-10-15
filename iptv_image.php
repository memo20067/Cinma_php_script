<?php

require_once __DIR__ . '/includes/iptv.php';
ensure_iptv_schema();

$sourceId = isset($_GET['source']) && !is_array($_GET['source']) ? (int)$_GET['source'] : 0;
$itemId = isset($_GET['item']) && !is_array($_GET['item']) ? (int)$_GET['item'] : 0;
$item = $sourceId > 0 && $itemId > 0 ? iptv_fetch_item($sourceId, $itemId) : false;
if (!$item || $item['poster_url'] === '') {
    http_response_code(404);
    exit;
}

try {
    $posterPath = (string)$item['poster_url'];
    if (iptv_valid_http_url($posterPath)) {
        $posterPath = iptv_cache_image($posterPath, $sourceId, $item['item_type']);
        if ($posterPath === '') {
            throw new RuntimeException(tr('تعذر تخزين صورة المصدر مؤقتًا.', 'Could not cache a source image.'));
        }
        DB::update('iptv_items', ['poster_url' => $posterPath], 'id = ?', [$itemId]);
    }

    $cacheRoot = realpath(__DIR__ . '/uploads/iptv/' . $sourceId);
    $imagePath = realpath(__DIR__ . '/' . ltrim($posterPath, '/\\'));
    if (!$cacheRoot || !$imagePath || strpos($imagePath, $cacheRoot . DIRECTORY_SEPARATOR) !== 0) {
        http_response_code(404);
        exit;
    }
    $image = @getimagesize($imagePath);
    if (!$image || !in_array($image['mime'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . $image['mime']);
    header('Cache-Control: public, max-age=2592000, immutable');
    header('Content-Length: ' . filesize($imagePath));
    readfile($imagePath);
} catch (Throwable $exception) {
    error_log('IPTV image cache failed: ' . $exception->getMessage());
    http_response_code(502);
}
