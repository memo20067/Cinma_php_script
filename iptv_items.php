<?php

require_once __DIR__ . '/includes/iptv.php';
ensure_iptv_schema();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$sourceId = isset($_GET['source']) && !is_array($_GET['source']) ? (int)$_GET['source'] : 0;
$type = isset($_GET['type']) && is_string($_GET['type']) ? $_GET['type'] : '';
$category = isset($_GET['category']) && is_string($_GET['category']) ? trim($_GET['category']) : '';
$query = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$page = isset($_GET['page']) && !is_array($_GET['page']) ? max(1, min(100000, (int)$_GET['page'])) : 1;

if (isset($_GET['categories']) && $_GET['categories'] === '1') {
    if ($sourceId < 1 || !in_array($type, ['live', 'movie', 'series'], true)) {
        http_response_code(400);
        echo json_encode(['error' => tr('طلب غير صالح.', 'Invalid request.')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $source = DB::fetch(
        'SELECT id FROM {prefix}iptv_sources WHERE id = ? AND enabled = 1',
        [$sourceId]
    );
    if (!$source) {
        http_response_code(404);
        echo json_encode(['error' => tr('المصدر غير موجود.', 'Source not found.')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Cache-Control: private, max-age=3600');
    echo json_encode([
        'categories' => iptv_fetch_categories($sourceId, $type)
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($sourceId < 1 || !in_array($type, ['live', 'movie', 'series'], true) || strlen($query) > 200) {
    http_response_code(400);
    echo json_encode(['error' => tr('طلب غير صالح.', 'Invalid request.')], JSON_UNESCAPED_UNICODE);
    exit;
}

$source = DB::fetch(
    'SELECT id FROM {prefix}iptv_sources WHERE id = ? AND enabled = 1',
    [$sourceId]
);
if (!$source) {
    http_response_code(404);
    echo json_encode(['error' => tr('المصدر غير موجود.', 'Source not found.')], JSON_UNESCAPED_UNICODE);
    exit;
}

$where = ' WHERE source_id = ? AND item_type = ?';
$params = [$sourceId, $type];
if ($category !== '') {
    $where .= ' AND category_name = ?';
    $params[] = $category;
}
if ($query !== '') {
    $where .= ' AND title LIKE ?';
    $params[] = '%' . $query . '%';
}

$total = DB::fetch('SELECT COUNT(*) AS item_count FROM {prefix}iptv_items' . $where, $params);
$totalItems = (int)($total['item_count'] ?? 0);
$perPage = 20;
$offset = ($page - 1) * $perPage;
$items = DB::fetchAll(
    'SELECT id, title, poster_url FROM {prefix}iptv_items' . $where
    . ' ORDER BY category_name, title LIMIT ' . $perPage . ' OFFSET ' . $offset,
    $params
);

echo json_encode([
    'page' => $page,
    'items' => $items,
    'has_more' => $offset + count($items) < $totalItems
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
