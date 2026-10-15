<?php

require_once __DIR__ . '/functions.php';

function ensure_iptv_schema() {
    static $ready = false;
    if ($ready) {
        return;
    }

    $driver = DB::getInstance()->getDriver();
    $timestampType = $driver === 'sqlite'
        ? 'DATETIME NULL'
        : 'TIMESTAMP NULL DEFAULT NULL';
    $textType = $driver === 'sqlite' ? 'TEXT' : 'MEDIUMTEXT';
    $tableSuffix = $driver === 'sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $itemIndex = $driver === 'sqlite'
        ? ''
        : ', INDEX iptv_source_type_category (source_id, item_type, category_name(80))';

    DB::query(
        'CREATE TABLE IF NOT EXISTS {prefix}iptv_sources (
            id INTEGER PRIMARY KEY ' . ($driver === 'sqlite' ? 'AUTOINCREMENT' : 'AUTO_INCREMENT') . ',
            name VARCHAR(160) NOT NULL,
            source_type VARCHAR(16) NOT NULL,
            base_url VARCHAR(1000) NOT NULL DEFAULT \'\',
            username VARCHAR(255) NOT NULL DEFAULT \'\',
            password ' . $textType . ' NULL,
            playlist_url ' . $textType . ' NULL,
            epg_url ' . $textType . ' NULL,
            enabled INTEGER NOT NULL DEFAULT 1,
            last_synced_at ' . $timestampType . ',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )' . $tableSuffix
    );
    $sourceColumns = [];
    if ($driver === 'sqlite') {
        foreach (DB::fetchAll('PRAGMA table_info("' . DB::getInstance()->table('iptv_sources') . '")') as $column) {
            $sourceColumns[] = $column['name'];
        }
    } else {
        foreach (DB::fetchAll('SHOW COLUMNS FROM {prefix}iptv_sources') as $column) {
            $sourceColumns[] = $column['Field'];
        }
    }
    if (!in_array('epg_url', $sourceColumns, true)) {
        DB::query('ALTER TABLE {prefix}iptv_sources ADD COLUMN epg_url ' . $textType . ' NULL');
    }
    DB::query(
        'CREATE TABLE IF NOT EXISTS {prefix}iptv_items (
            id INTEGER PRIMARY KEY ' . ($driver === 'sqlite' ? 'AUTOINCREMENT' : 'AUTO_INCREMENT') . ',
            source_id INTEGER NOT NULL,
            item_hash CHAR(64) NOT NULL,
            external_id VARCHAR(255) NOT NULL,
            item_type VARCHAR(16) NOT NULL,
            parent_external_id VARCHAR(255) NOT NULL DEFAULT \'\',
            title VARCHAR(500) NOT NULL,
            category_name VARCHAR(255) NOT NULL DEFAULT \'\',
            stream_url ' . $textType . ' NULL,
            poster_url VARCHAR(2000) NOT NULL DEFAULT \'\',
            description ' . $textType . ' NULL,
            epg_id VARCHAR(255) NOT NULL DEFAULT \'\',
            rating VARCHAR(32) NOT NULL DEFAULT \'\',
            season_number INTEGER NOT NULL DEFAULT 0,
            episode_number INTEGER NOT NULL DEFAULT 0,
            epg_cache ' . $textType . ' NULL,
            epg_synced_at ' . $timestampType . ',
            children_synced_at ' . $timestampType . ',
            UNIQUE (source_id, item_hash)' . $itemIndex . '
        )' . $tableSuffix
    );
    $categoryIndex = $driver === 'sqlite'
        ? ''
        : ', INDEX iptv_category_lookup (source_id, item_type, category_hash)';
    DB::query(
        'CREATE TABLE IF NOT EXISTS {prefix}iptv_category_cache (
            category_hash CHAR(64) PRIMARY KEY,
            source_id INTEGER NOT NULL,
            item_type VARCHAR(16) NOT NULL,
            category_name VARCHAR(255) NOT NULL,
            item_count INTEGER NOT NULL DEFAULT 0' . $categoryIndex . '
        )' . $tableSuffix
    );
    $itemColumns = [];
    if ($driver === 'sqlite') {
        foreach (DB::fetchAll('PRAGMA table_info("' . DB::getInstance()->table('iptv_items') . '")') as $column) {
            $itemColumns[] = $column['name'];
        }
    } else {
        foreach (DB::fetchAll('SHOW COLUMNS FROM {prefix}iptv_items') as $column) {
            $itemColumns[] = $column['Field'];
        }
    }
    if (!in_array('details_cache', $itemColumns, true)) {
        DB::query('ALTER TABLE {prefix}iptv_items ADD COLUMN details_cache ' . $textType . ' NULL');
    }
    if (!in_array('details_synced_at', $itemColumns, true)) {
        DB::query('ALTER TABLE {prefix}iptv_items ADD COLUMN details_synced_at ' . $timestampType);
    }
    DB::query(
        'CREATE TABLE IF NOT EXISTS {prefix}iptv_resources (
            token CHAR(64) PRIMARY KEY,
            source_id INTEGER NOT NULL,
            target_hash CHAR(64) NOT NULL DEFAULT \'\',
            target_url ' . $textType . ' NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )' . $tableSuffix
    );
    DB::query(
        'CREATE TABLE IF NOT EXISTS {prefix}iptv_image_cache (
            source_id INTEGER NOT NULL,
            item_type VARCHAR(16) NOT NULL,
            image_hash CHAR(64) NOT NULL,
            extension VARCHAR(8) NOT NULL,
            PRIMARY KEY (source_id, item_type, image_hash)
        )' . $tableSuffix
    );
    $epgIndex = $driver === 'sqlite'
        ? ''
        : ', INDEX iptv_epg_source_channel_start (source_id, channel_id(80), start_time)';
    DB::query(
        'CREATE TABLE IF NOT EXISTS {prefix}iptv_epg (
            id INTEGER PRIMARY KEY ' . ($driver === 'sqlite' ? 'AUTOINCREMENT' : 'AUTO_INCREMENT') . ',
            source_id INTEGER NOT NULL,
            channel_id VARCHAR(255) NOT NULL,
            program_hash CHAR(64) NOT NULL,
            title VARCHAR(500) NOT NULL,
            description ' . $textType . ' NULL,
            start_time DATETIME NOT NULL,
            end_time DATETIME NOT NULL,
            UNIQUE (source_id, program_hash)' . $epgIndex . '
        )' . $tableSuffix
    );
    $resourceColumns = [];
    if ($driver === 'sqlite') {
        foreach (DB::fetchAll('PRAGMA table_info("' . DB::getInstance()->table('iptv_resources') . '")') as $column) {
            $resourceColumns[] = $column['name'];
        }
    } else {
        foreach (DB::fetchAll('SHOW COLUMNS FROM {prefix}iptv_resources') as $column) {
            $resourceColumns[] = $column['Field'];
        }
    }
    if (!in_array('target_hash', $resourceColumns, true)) {
        DB::query('ALTER TABLE {prefix}iptv_resources ADD COLUMN target_hash CHAR(64) NOT NULL DEFAULT \'\'');
    }
    $ready = true;
}

function iptv_get_sources($enabledOnly = false) {
    ensure_iptv_schema();
    $where = $enabledOnly ? ' WHERE enabled = 1' : '';
    return DB::fetchAll("SELECT id, name, source_type, enabled, last_synced_at FROM {prefix}iptv_sources" . $where . ' ORDER BY id');
}

function iptv_valid_http_url($url) {
    if (!is_string($url) || $url === '' || strlen($url) > 4000 || !filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    return in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
}

function iptv_register_cached_image($sourceId, $itemType, $imageHash, $extension) {
    $itemType = $itemType === 'episode' ? 'series' : $itemType;
    if (!in_array($itemType, ['live', 'movie', 'series'], true)) {
        return;
    }
    $driver = DB::getInstance()->getDriver();
    $insertPrefix = $driver === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
    DB::query(
        $insertPrefix . ' INTO {prefix}iptv_image_cache (source_id, item_type, image_hash, extension) VALUES (?, ?, ?, ?)',
        [(int)$sourceId, $itemType, $imageHash, $extension]
    );
}

function iptv_track_existing_item_images($sourceId) {
    $prefix = 'uploads/iptv/' . (int)$sourceId . '/';
    $items = DB::fetchAll(
        'SELECT item_type, poster_url FROM {prefix}iptv_items WHERE source_id = ? AND poster_url LIKE ?',
        [(int)$sourceId, $prefix . '%']
    );
    foreach ($items as $item) {
        if (preg_match('~^uploads/iptv/' . (int)$sourceId . '/([a-f0-9]{64})\.(jpg|png|gif|webp)$~i', (string)$item['poster_url'], $matches)) {
            iptv_register_cached_image($sourceId, $item['item_type'], strtolower($matches[1]), strtolower($matches[2]));
        }
    }
}

function iptv_cache_image($url, $sourceId, $itemType = '') {
    if (!iptv_valid_http_url($url)) {
        return '';
    }
    $directory = __DIR__ . '/../uploads/iptv/' . (int)$sourceId;
    $urlHash = hash('sha256', $url);
    foreach (['jpg', 'png', 'gif', 'webp'] as $extension) {
        $cached = $directory . DIRECTORY_SEPARATOR . $urlHash . '.' . $extension;
        if (is_file($cached) && filesize($cached) > 0) {
            iptv_register_cached_image($sourceId, $itemType, $urlHash, $extension);
            return 'uploads/iptv/' . (int)$sourceId . '/' . $urlHash . '.' . $extension;
        }
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException(tr('امتداد cURL غير مفعّل في PHP.', 'The PHP cURL extension is not enabled.'));
    }
    $body = '';
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'CinemaCMS/1.0',
        CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$body) {
            if (strlen($body) + strlen($chunk) > 5 * 1024 * 1024) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        }
    ]);
    $success = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    if ($success === false || $status < 200 || $status >= 300) {
        throw new RuntimeException(tr('تعذر تخزين صورة المصدر مؤقتًا.', 'Could not cache a source image.') . ($error !== '' ? ' ' . $error : ' HTTP ' . $status));
    }

    $image = @getimagesizefromstring($body);
    $extensions = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_WEBP => 'webp'
    ];
    if (!$image || !isset($extensions[$image[2]])) {
        throw new RuntimeException(tr('رابط الملصق لا يعرض صورة مدعومة.', 'The artwork URL did not return a supported image.'));
    }
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException(tr('تعذر إنشاء مجلد تخزين صور المصدر.', 'Could not create the source image cache directory.'));
    }
    $extension = $extensions[$image[2]];
    $target = $directory . DIRECTORY_SEPARATOR . $urlHash . '.' . $extension;
    if (file_put_contents($target, $body, LOCK_EX) === false) {
        throw new RuntimeException(tr('تعذر حفظ صورة المصدر.', 'Could not save a source image.'));
    }
    iptv_register_cached_image($sourceId, $itemType, $urlHash, $extension);
    return 'uploads/iptv/' . (int)$sourceId . '/' . $urlHash . '.' . $extension;
}

function iptv_slice($value, $length) {
    return function_exists('mb_substr')
        ? mb_substr((string)$value, 0, $length, 'UTF-8')
        : substr((string)$value, 0, $length);
}

function iptv_http_get($url, $timeout = 30, $maxBytes = 0, &$effectiveUrl = null) {
    if (!iptv_valid_http_url($url)) {
        throw new RuntimeException(tr('رابط المصدر غير صالح.', 'The source URL is invalid.'));
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException(tr('امتداد cURL غير مفعّل في PHP.', 'The PHP cURL extension is not enabled.'));
    }

    $body = '';
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => $maxBytes > 0 ? false : true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'CinemaCMS/1.0'
    ]);
    if ($maxBytes > 0) {
        curl_setopt($handle, CURLOPT_WRITEFUNCTION, function ($curl, $chunk) use (&$body, $maxBytes) {
            if (strlen($body) + strlen($chunk) > $maxBytes) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        });
    }
    $response = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $effectiveUrl = (string)curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
    $error = curl_error($handle);
    if ($response === false || $status < 200 || $status >= 300) {
        curl_close($handle);
        throw new RuntimeException(
            tr('تعذر جلب بيانات المصدر.', 'Could not fetch source data.')
            . ($error !== '' ? ' ' . $error : ' HTTP ' . $status)
        );
    }
    if (!iptv_valid_http_url($effectiveUrl)) {
        curl_close($handle);
        throw new RuntimeException(tr('رابط التحويل من المصدر غير صالح.', 'The source redirected to an invalid URL.'));
    }
    curl_close($handle);
    return $maxBytes > 0 ? $body : $response;
}

function iptv_resolve_url($baseUrl, $reference) {
    $reference = trim((string)$reference);
    if (preg_match('~^https?://~i', $reference)) {
        return iptv_valid_http_url($reference) ? $reference : '';
    }
    $base = parse_url($baseUrl);
    if (!$base || empty($base['scheme']) || empty($base['host'])) {
        return '';
    }
    $origin = strtolower($base['scheme']) . '://' . $base['host']
        . (isset($base['port']) ? ':' . (int)$base['port'] : '');
    if (strpos($reference, '//') === 0) {
        $resolved = $base['scheme'] . ':' . $reference;
        return iptv_valid_http_url($resolved) ? $resolved : '';
    }
    if (strpos($reference, '#') === 0) {
        $reference = (string)($base['path'] ?? '/') . (isset($base['query']) ? '?' . $base['query'] : '') . $reference;
    } elseif (strpos($reference, '?') === 0) {
        $reference = (string)($base['path'] ?? '/') . $reference;
    } elseif (strpos($reference, '/') !== 0) {
        $basePath = (string)($base['path'] ?? '/');
        $reference = substr($basePath, 0, strrpos($basePath, '/') + 1) . $reference;
    }
    $fragment = '';
    $query = '';
    if (strpos($reference, '#') !== false) {
        list($reference, $fragment) = explode('#', $reference, 2);
        $fragment = '#' . $fragment;
    }
    if (strpos($reference, '?') !== false) {
        list($reference, $query) = explode('?', $reference, 2);
        $query = '?' . $query;
    }
    $segments = [];
    foreach (explode('/', $reference) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
        } else {
            $segments[] = $segment;
        }
    }
    $resolved = $origin . '/' . implode('/', $segments) . $query . $fragment;
    return iptv_valid_http_url($resolved) ? $resolved : '';
}

function iptv_download_playlist_file($url, $timeout = 60, $maxBytes = 50 * 1024 * 1024) {
    if (!iptv_valid_http_url($url)) {
        throw new RuntimeException(tr('رابط المصدر غير صالح.', 'The source URL is invalid.'));
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException(tr('امتداد cURL غير مفعّل في PHP.', 'The PHP cURL extension is not enabled.'));
    }
    $path = tempnam(sys_get_temp_dir(), 'iptv_');
    if ($path === false) {
        throw new RuntimeException(tr('تعذر إنشاء ملف مؤقت لقائمة المصدر.', 'Could not create a temporary playlist file.'));
    }
    $file = fopen($path, 'wb');
    if ($file === false) {
        unlink($path);
        throw new RuntimeException(tr('تعذر فتح ملف مؤقت لقائمة المصدر.', 'Could not open the temporary playlist file.'));
    }
    $bytes = 0;
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'CinemaCMS/1.0',
        CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use ($file, &$bytes, $maxBytes) {
            $length = strlen($chunk);
            if ($bytes + $length > $maxBytes || fwrite($file, $chunk) !== $length) {
                return 0;
            }
            $bytes += $length;
            return $length;
        }
    ]);
    $success = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    fclose($file);
    if ($success === false || $status < 200 || $status >= 300) {
        unlink($path);
        throw new RuntimeException(
            tr('تعذر جلب قائمة المصدر أو تجاوزت الحجم المسموح.', 'Could not fetch the playlist or it exceeded the size limit.')
            . ($error !== '' ? ' ' . $error : ' HTTP ' . $status)
        );
    }
    return $path;
}

function iptv_xtream_api($source, $action, array $parameters = []) {
    $query = array_merge([
        'username' => $source['username'],
        'password' => $source['password'],
        'action' => $action
    ], $parameters);
    $response = iptv_http_get(rtrim($source['base_url'], '/') . '/player_api.php?' . http_build_query($query), 12);
    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException(tr('استجابة Xtream غير صالحة.', 'Xtream returned an invalid response.'));
    }
    return $data;
}

function iptv_ffmpeg_available() {
    static $available = null;
    if ($available !== null) {
        return $available;
    }
    $binary = function_exists('configured_ffmpeg_path') ? configured_ffmpeg_path() : '';
    if ($binary === '' || !is_file($binary) || !function_exists('proc_open')) {
        $available = false;
        return $available;
    }
    $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $pipes = [];
    $process = @proc_open([$binary, '-version'], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['file', $nullDevice, 'a']
    ], $pipes);
    if (!is_resource($process)) {
        $available = false;
        return $available;
    }
    fclose($pipes[0]);
    $version = stream_get_contents($pipes[1], 1024);
    fclose($pipes[1]);
    $available = proc_close($process) === 0 && strpos((string)$version, 'ffmpeg version') !== false;
    return $available;
}

function iptv_add_item(array &$items, $sourceId, $type, $externalId, $title, $category, $url = '', $poster = '', $description = '', $epgId = '', $rating = '', $parentId = '', $season = 0, $episode = 0) {
    $externalId = (string)$externalId;
    if ($externalId === '' || trim((string)$title) === '') {
        return;
    }
    $items[] = [
        'source_id' => (int)$sourceId,
        'item_hash' => hash('sha256', $type . "\0" . $externalId),
        'external_id' => substr($externalId, 0, 255),
        'item_type' => $type,
        'parent_external_id' => substr((string)$parentId, 0, 255),
        'title' => iptv_slice(trim((string)$title), 500),
        'category_name' => iptv_slice(trim((string)$category), 255),
        'stream_url' => $url,
        'poster_url' => iptv_slice((string)$poster, 2000),
        'description' => (string)$description,
        'epg_id' => iptv_slice((string)$epgId, 255),
        'rating' => iptv_slice((string)$rating, 32),
        'season_number' => (int)$season,
        'episode_number' => (int)$episode
    ];
}

function iptv_refresh_xtream($source, $onlyType = null) {
    $items = [];
    foreach ([
        ['live', 'get_live_categories', 'get_live_streams'],
        ['movie', 'get_vod_categories', 'get_vod_streams'],
        ['series', 'get_series_categories', 'get_series']
    ] as $group) {
        list($type, $categoryAction, $itemAction) = $group;
        if ($onlyType !== null && $type !== $onlyType) {
            continue;
        }
        $categories = iptv_xtream_api($source, $categoryAction);
        $entries = iptv_xtream_api($source, $itemAction);
        if (!is_array($categories) || !is_array($entries)
            || ($categories && array_keys($categories) !== range(0, count($categories) - 1))
            || ($entries && array_keys($entries) !== range(0, count($entries) - 1))) {
            throw new RuntimeException(tr('تعذر قراءة فئات أو عناصر Xtream.', 'Could not read Xtream categories or items.'));
        }
        $categoryNames = [];
        foreach ($categories as $category) {
            if (isset($category['category_id'])) {
                $categoryNames[(string)$category['category_id']] = (string)($category['category_name'] ?? '');
            }
        }
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $externalId = (string)($entry['stream_id'] ?? $entry['series_id'] ?? '');
            $category = $categoryNames[(string)($entry['category_id'] ?? '')] ?? '';
            if ($type === 'live') {
                $streamUrl = rtrim($source['base_url'], '/') . '/live/'
                    . rawurlencode($source['username']) . '/' . rawurlencode($source['password'])
                    . '/' . rawurlencode($externalId) . '.m3u8';
            } elseif ($type === 'movie') {
                $extension = preg_replace('/[^a-z0-9]/i', '', $entry['container_extension'] ?? 'mp4') ?: 'mp4';
                $streamUrl = rtrim($source['base_url'], '/') . '/movie/'
                    . rawurlencode($source['username']) . '/' . rawurlencode($source['password'])
                    . '/' . rawurlencode($externalId) . '.' . $extension;
            } else {
                $streamUrl = '';
            }
            iptv_add_item(
                $items,
                $source['id'],
                $type,
                $externalId,
                $entry['name'] ?? '',
                $category,
                $streamUrl,
                $entry['stream_icon'] ?? $entry['cover'] ?? '',
                $entry['plot'] ?? '',
                $entry['epg_channel_id'] ?? '',
                $entry['rating'] ?? ''
            );
        }
        unset($categories, $entries, $categoryNames);
    }
    return $items;
}

function iptv_classify_m3u_item(array $entry, $url) {
    $title = trim((string)$entry['title']);
    $category = trim((string)$entry['category']);
    $attributes = $entry['attributes'];
    $path = strtolower(rawurldecode((string)parse_url($url, PHP_URL_PATH)));
    $metadata = strtolower($category . ' ' . $title . ' '
        . (string)($attributes['tvg-type'] ?? '') . ' '
        . (string)($attributes['content-type'] ?? '') . ' '
        . (string)($attributes['type'] ?? ''));
    $season = 0;
    $episode = 0;
    $seriesTitle = '';

    if (preg_match('/(?:^|[\s._-])s(\d{1,3})[\s._-]*e(\d{1,4})(?:$|[\s._-])/i', $title, $match)
        || preg_match('/(?:^|[\s._-])(\d{1,3})x(\d{1,4})(?:$|[\s._-])/i', $title, $match)) {
        $season = (int)$match[1];
        $episode = (int)$match[2];
        $seriesTitle = trim(substr($title, 0, (int)strpos($title, $match[0])), " ._-:\t");
    }

    $isSeries = strpos($metadata, 'series') !== false
        || strpos($metadata, 'tv show') !== false
        || strpos($metadata, 'tv-show') !== false
        || strpos($metadata, 'مسلسلات') !== false
        || strpos($metadata, 'مسلسل') !== false
        || strpos($metadata, 'حلقات') !== false
        || strpos($path, '/series/') !== false
        || $season > 0;
    if ($isSeries) {
        if ($seriesTitle === '') {
            $genericSeriesCategory = preg_match('/^(series|tv shows?)$/i', $category)
                || strpos($category, 'مسلسلات') !== false
                || strpos($category, 'مسلسل') !== false
                || strpos($category, 'حلقات') !== false;
            $seriesTitle = $category !== '' && !$genericSeriesCategory
                ? $category
                : $title;
        }
        return [
            'type' => 'episode',
            'series_title' => $seriesTitle,
            'season' => $season,
            'episode' => $episode
        ];
    }

    $movieExtension = preg_match('/\.(mp4|mkv|avi|mov|m4v|wmv|mpg|mpeg)(?:$|\/)/i', $path);
    $movieMarker = preg_match('/\b(vod|movie|movies|film|films|cinema)\b/i', $metadata)
        || strpos($metadata, 'أفلام') !== false
        || strpos($metadata, 'افلام') !== false
        || strpos($metadata, 'فيلم') !== false
        || strpos($path, '/movie/') !== false
        || strpos($path, '/vod/') !== false;
    $liveMarker = preg_match('/\b(live|linear|channel|channels|radio)\b/i', $metadata)
        || strpos($metadata, 'قناة') !== false
        || strpos($metadata, 'قنوات') !== false
        || strpos($metadata, 'مباشر') !== false;
    $contentType = strtolower((string)($attributes['tvg-type'] ?? $attributes['content-type'] ?? $attributes['type'] ?? ''));

    return [
        'type' => $movieMarker || $contentType === 'movie' || ($movieExtension && !$liveMarker) ? 'movie' : 'live',
        'series_title' => '',
        'season' => 0,
        'episode' => 0
    ];
}

function iptv_refresh_m3u($source) {
    $playlistPath = iptv_download_playlist_file($source['playlist_url']);
    $playlist = fopen($playlistPath, 'rb');
    if ($playlist === false) {
        unlink($playlistPath);
        throw new RuntimeException(tr('تعذر قراءة ملف قائمة المصدر.', 'Could not read the source playlist file.'));
    }
    $items = [];
    $epgUrl = '';
    $pending = null;
    $seenHashes = [];
    try {
        while (($line = fgets($playlist)) !== false) {
            $line = trim($line);
            if (strpos($line, '#EXTM3U') === 0
                && preg_match('/(?:url-tvg|x-tvg-url|tvg-url)="([^"]+)"/i', $line, $epgMatch)) {
                $epgUrl = html_entity_decode($epgMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            } elseif (strpos($line, '#EXTINF:') === 0) {
                $attributes = [];
                preg_match_all('/([\w-]+)="([^"]*)"/', $line, $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    $attributes[$match[1]] = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                $separator = strpos($line, ',');
                $title = $separator === false ? '' : trim(substr($line, $separator + 1));
                if ($title === '') {
                    $title = $attributes['tvg-name'] ?? '';
                }
                $pending = [
                    'title' => $title,
                    'category' => $attributes['group-title'] ?? '',
                    'poster' => $attributes['tvg-logo'] ?? '',
                    'epg' => $attributes['tvg-id'] ?? '',
                    'attributes' => $attributes
                ];
            } elseif ($line !== '' && $line[0] !== '#' && $pending !== null) {
                if (iptv_valid_http_url($line)) {
                    $classification = iptv_classify_m3u_item($pending, $line);
                    $stableId = hash('sha256', $line . "\0" . $pending['title'] . "\0" . $pending['category']);
                    if ($classification['type'] === 'episode') {
                        $parentId = 'm3u-series-' . hash('sha256', $classification['series_title']);
                        $parentHash = hash('sha256', 'series' . "\0" . $parentId);
                        $episodeHash = hash('sha256', 'episode' . "\0" . $stableId);
                        if (!isset($seenHashes[$parentHash])) {
                            iptv_add_item(
                                $items, $source['id'], 'series', $parentId, $classification['series_title'],
                                $pending['category'], '', $pending['poster']
                            );
                            $seenHashes[$parentHash] = true;
                        }
                        if (!isset($seenHashes[$episodeHash])) {
                            iptv_add_item(
                                $items, $source['id'], 'episode', $stableId, $pending['title'], $pending['category'],
                                $line, $pending['poster'], '', $pending['epg'], '', $parentId,
                                $classification['season'], $classification['episode']
                            );
                            $seenHashes[$episodeHash] = true;
                        }
                    } else {
                        $itemHash = hash('sha256', $classification['type'] . "\0" . $stableId);
                        if (!isset($seenHashes[$itemHash])) {
                            iptv_add_item(
                                $items, $source['id'], $classification['type'], $stableId, $pending['title'],
                                $pending['category'], $line, $pending['poster'], '', $pending['epg']
                            );
                            $seenHashes[$itemHash] = true;
                        }
                    }
                }
                $pending = null;
            }
        }
    } finally {
        fclose($playlist);
        unlink($playlistPath);
    }
    if (!$items) {
        throw new RuntimeException(tr('لم يتم العثور على عناصر صالحة في قائمة M3U.', 'No valid items were found in the M3U playlist.'));
    }
    if ($epgUrl !== '' && !iptv_valid_http_url($epgUrl)) {
        throw new RuntimeException(tr('رابط دليل البرامج المضمّن في قائمة M3U غير صالح.', 'The EPG URL embedded in the M3U playlist is invalid.'));
    }
    return ['items' => $items, 'epg' => [], 'epg_url' => $epgUrl];
}

function iptv_parse_xmltv($sourceId, $url) {
    if (!function_exists('simplexml_load_string')) {
        throw new RuntimeException(tr('امتداد SimpleXML غير مفعّل في PHP، ولا يمكن قراءة دليل البرامج.', 'The PHP SimpleXML extension is required to read the program guide.'));
    }
    $xml = iptv_http_get($url, 60, 50 * 1024 * 1024);
    if (substr($xml, 0, 2) === "\x1f\x8b") {
        if (!function_exists('gzdecode')) {
            throw new RuntimeException(tr('امتداد gzip غير مفعّل في PHP لقراءة دليل البرامج المضغوط.', 'The PHP zlib extension is required to read the compressed program guide.'));
        }
        $xml = gzdecode($xml, 150 * 1024 * 1024);
        if ($xml === false || strlen($xml) > 150 * 1024 * 1024) {
            throw new RuntimeException(tr('تعذر فك ضغط دليل البرامج أو تجاوز حجمه الحد المسموح.', 'The compressed program guide is invalid or exceeds the size limit.'));
        }
    }
    $previousLibxmlState = libxml_use_internal_errors(true);
    $document = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
    $xmlErrors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previousLibxmlState);
    if (!$document || $document->getName() !== 'tv') {
        $details = $xmlErrors ? ' ' . trim($xmlErrors[0]->message) : '';
        throw new RuntimeException(tr('ملف دليل البرامج المضمّن ليس XMLTV صالحًا.', 'The embedded program guide is not a valid XMLTV document.') . $details);
    }
    $entries = [];
    foreach ($document->programme as $programme) {
        $channelId = (string)($programme['channel'] ?? '');
        $title = trim((string)($programme->title ?? ''));
        $start = iptv_xmltv_datetime((string)($programme['start'] ?? ''));
        $end = iptv_xmltv_datetime((string)($programme['stop'] ?? ''));
        if ($channelId === '' || $title === '' || $start === '' || $end === '') {
            continue;
        }
        $entries[] = [
            'source_id' => (int)$sourceId,
            'channel_id' => iptv_slice($channelId, 255),
            'program_hash' => hash('sha256', $channelId . "\0" . $start . "\0" . $end . "\0" . $title),
            'title' => iptv_slice($title, 500),
            'description' => (string)($programme->desc ?? ''),
            'start_time' => $start,
            'end_time' => $end
        ];
        if (count($entries) >= 50000) {
            break;
        }
    }
    return $entries;
}

function iptv_xmltv_datetime($value) {
    if (!preg_match('/^(\d{14})(?:\s*([+-]\d{4}))?/', trim($value), $matches)) {
        return '';
    }
    $timezone = $matches[2] ?? '+0000';
    $date = DateTime::createFromFormat('!YmdHisO', $matches[1] . $timezone);
    if (!$date) {
        return '';
    }
    $date->setTimezone(new DateTimeZone('UTC'));
    return $date->format('Y-m-d H:i:s');
}

function iptv_refresh_source($sourceId, $force = false, $onlyType = null) {
    ensure_iptv_schema();
    if ($onlyType !== null && !in_array($onlyType, ['live', 'movie', 'series'], true)) {
        throw new InvalidArgumentException(tr('نوع الكاش المحدد غير صالح.', 'The selected cache type is invalid.'));
    }
    $source = DB::fetch('SELECT * FROM {prefix}iptv_sources WHERE id = ? AND enabled = 1', [(int)$sourceId]);
    if (!$source) {
        throw new RuntimeException(tr('لم يتم العثور على مصدر بث مفعل.', 'The enabled streaming source was not found.'));
    }
    if (!$force && !empty($source['last_synced_at']) && strtotime($source['last_synced_at']) > time() - 12 * 60 * 60) {
        return false;
    }
    $catalog = $source['source_type'] === 'xtream'
        ? ['items' => iptv_refresh_xtream($source, $onlyType), 'epg' => [], 'epg_url' => '']
        : iptv_refresh_m3u($source);
    $refreshTypes = $onlyType === 'series'
        ? ['series', 'episode']
        : ($onlyType === null ? null : [$onlyType]);
    if ($refreshTypes !== null) {
        $catalog['items'] = array_values(array_filter($catalog['items'], function ($item) use ($refreshTypes) {
            return in_array($item['item_type'], $refreshTypes, true);
        }));
        $catalog['epg'] = [];
    }

    $pdo = DB::getInstance()->getPDO();
    $pdo->beginTransaction();
    try {
        if ($refreshTypes === null) {
            DB::query('DELETE FROM {prefix}iptv_items WHERE source_id = ?', [(int)$sourceId]);
            DB::query('DELETE FROM {prefix}iptv_epg WHERE source_id = ?', [(int)$sourceId]);
            DB::delete('iptv_category_cache', 'source_id = ?', [(int)$sourceId]);
        } else {
            $typePlaceholders = implode(', ', array_fill(0, count($refreshTypes), '?'));
            DB::query(
                'DELETE FROM {prefix}iptv_items WHERE source_id = ? AND item_type IN (' . $typePlaceholders . ')',
                array_merge([(int)$sourceId], $refreshTypes)
            );
            DB::query(
                'DELETE FROM {prefix}iptv_category_cache WHERE source_id = ? AND item_type = ?',
                [(int)$sourceId, $onlyType]
            );
            if ($onlyType === 'live') {
                DB::query('DELETE FROM {prefix}iptv_epg WHERE source_id = ?', [(int)$sourceId]);
            }
        }
        if ($catalog['items']) {
            $columns = array_keys($catalog['items'][0]);
            $quotedColumns = implode(', ', array_map(function ($column) use ($pdo) {
                return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '"' . $column . '"' : '`' . $column . '`';
            }, $columns));
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $table = DB::getInstance()->table('iptv_items');
            $quote = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '"' : '`';
            $insert = $pdo->prepare('INSERT INTO ' . $quote . $table . $quote . ' (' . $quotedColumns . ') VALUES (' . $placeholders . ')');
            foreach ($catalog['items'] as $item) {
                $insert->execute(array_values($item));
            }
            $categoryCounts = [];
            foreach ($catalog['items'] as $item) {
                if (!in_array($item['item_type'], ['live', 'movie', 'series'], true)) {
                    continue;
                }
                $category = (string)$item['category_name'];
                $categoryKey = (int)$sourceId . "\0" . $item['item_type'] . "\0" . $category;
                if (!isset($categoryCounts[$categoryKey])) {
                    $categoryCounts[$categoryKey] = [
                        'source_id' => (int)$sourceId,
                        'item_type' => $item['item_type'],
                        'category_name' => $category,
                        'item_count' => 0
                    ];
                }
                $categoryCounts[$categoryKey]['item_count']++;
            }
            foreach ($categoryCounts as $categoryKey => $category) {
                DB::insert('iptv_category_cache', [
                    'category_hash' => hash('sha256', $categoryKey),
                    'source_id' => $category['source_id'],
                    'item_type' => $category['item_type'],
                    'category_name' => $category['category_name'],
                    'item_count' => $category['item_count']
                ]);
            }
        }
        foreach ($catalog['epg'] as $program) {
            DB::insert('iptv_epg', $program);
        }
        $sourceUpdates = [];
        if ($refreshTypes === null) {
            $sourceUpdates['last_synced_at'] = date('Y-m-d H:i:s');
            $sourceUpdates['epg_url'] = $catalog['epg_url'] ?? ($source['epg_url'] ?? '');
        }
        if ($sourceUpdates) {
            DB::update('iptv_sources', $sourceUpdates, 'id = ?', [(int)$sourceId]);
        }
        DB::query(
            'DELETE FROM {prefix}iptv_resources WHERE source_id = ? AND created_at < ?',
            [(int)$sourceId, date('Y-m-d H:i:s', time() - 30 * 24 * 60 * 60)]
        );
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
    return true;
}

function iptv_ensure_fresh($sourceId) {
    return iptv_refresh_source($sourceId, false);
}

function iptv_fetch_item($sourceId, $itemId) {
    return DB::fetch('SELECT i.*, s.name AS source_name, s.source_type, s.base_url, s.username, s.password, s.epg_url AS source_epg_url
        FROM {prefix}iptv_items i
        JOIN {prefix}iptv_sources s ON s.id = i.source_id
        WHERE i.source_id = ? AND i.id = ? AND s.enabled = 1', [(int)$sourceId, (int)$itemId]);
}

function iptv_fetch_categories($sourceId, $type) {
    $cached = DB::fetchAll(
        'SELECT category_name, item_count FROM {prefix}iptv_category_cache
         WHERE source_id = ? AND item_type = ? ORDER BY category_name',
        [(int)$sourceId, $type]
    );
    if ($cached) {
        return $cached;
    }

    $categories = DB::fetchAll(
        'SELECT category_name, COUNT(*) AS item_count FROM {prefix}iptv_items
         WHERE source_id = ? AND item_type = ? GROUP BY category_name ORDER BY category_name',
        [(int)$sourceId, $type]
    );
    $driver = DB::getInstance()->getDriver();
    $insertPrefix = $driver === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
    foreach ($categories as $category) {
        DB::query(
            $insertPrefix . ' INTO {prefix}iptv_category_cache
                (category_hash, source_id, item_type, category_name, item_count)
             VALUES (?, ?, ?, ?, ?)',
            [
                hash('sha256', (int)$sourceId . "\0" . $type . "\0" . (string)$category['category_name']),
                (int)$sourceId,
                $type,
                $category['category_name'],
                (int)$category['item_count']
            ]
        );
    }
    return $categories;
}

function iptv_get_program_guide($item) {
    if ($item['item_type'] !== 'live') {
        return [];
    }
    if ($item['source_type'] === 'm3u') {
        $hasPrograms = DB::fetch(
            'SELECT id FROM {prefix}iptv_epg WHERE source_id = ? LIMIT 1',
            [(int)$item['source_id']]
        );
        if (!$hasPrograms && !empty($item['source_epg_url'])) {
            $programs = iptv_parse_xmltv($item['source_id'], $item['source_epg_url']);
            $pdo = DB::getInstance()->getPDO();
            $pdo->beginTransaction();
            try {
                foreach ($programs as $program) {
                    DB::insert('iptv_epg', $program);
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                $pdo->rollBack();
                throw $exception;
            }
        }
        $now = gmdate('Y-m-d H:i:s');
        $rows = DB::fetchAll(
            'SELECT title, description, start_time AS program_start, end_time AS program_end
             FROM {prefix}iptv_epg
             WHERE source_id = ? AND channel_id = ?
               AND ((start_time <= ? AND end_time > ?) OR start_time > ?)
             ORDER BY start_time LIMIT 8',
            [(int)$item['source_id'], (string)$item['epg_id'], $now, $now, $now]
        );
        return array_map(function ($program) {
            return [
                'title' => $program['title'],
                'description' => $program['description'],
                'start' => $program['program_start'],
                'end' => $program['program_end']
            ];
        }, $rows);
    }
    if ($item['source_type'] !== 'xtream') {
        return [];
    }
    if (!empty($item['epg_cache']) && !empty($item['epg_synced_at'])
        && strtotime($item['epg_synced_at']) > time() - 6 * 60 * 60) {
        $cached = json_decode($item['epg_cache'], true);
        return is_array($cached) ? $cached : [];
    }
    $programs = iptv_xtream_api($item, 'get_short_epg', ['stream_id' => $item['external_id']]);
    $rows = $programs['epg_listings'] ?? [];
    $guide = [];
    if (is_array($rows)) {
        foreach (array_slice($rows, 0, 8) as $program) {
            $guide[] = [
                'title' => base64_decode($program['title'] ?? '', true) ?: ($program['title'] ?? ''),
                'description' => base64_decode($program['description'] ?? '', true) ?: ($program['description'] ?? ''),
                'start' => $program['start'] ?? '',
                'end' => $program['end'] ?? ''
            ];
        }
    }
    DB::update('iptv_items', [
        'epg_cache' => json_encode($guide, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        'epg_synced_at' => date('Y-m-d H:i:s')
    ], 'id = ?', [(int)$item['id']]);
    return $guide;
}

function iptv_load_series_episodes($series) {
    $existing = DB::fetchAll(
        'SELECT * FROM {prefix}iptv_items WHERE source_id = ? AND item_type = \'episode\' AND parent_external_id = ?
         ORDER BY season_number, episode_number',
        [(int)$series['source_id'], (string)$series['external_id']]
    );
    if ($existing || (!empty($series['children_synced_at']) && strtotime($series['children_synced_at']) > time() - 12 * 60 * 60)) {
        return $existing;
    }
    $details = iptv_xtream_api($series, 'get_series_info', ['series_id' => $series['external_id']]);
    $episodes = $details['episodes'] ?? [];
    if (!is_array($episodes)) {
        throw new RuntimeException(tr('استجابة حلقات المسلسل غير صالحة.', 'The series episode response is invalid.'));
    }
    iptv_store_series_episodes($series, $episodes);
    return DB::fetchAll(
        'SELECT * FROM {prefix}iptv_items WHERE source_id = ? AND item_type = \'episode\' AND parent_external_id = ?
         ORDER BY season_number, episode_number',
        [(int)$series['source_id'], (string)$series['external_id']]
    );
}

function iptv_store_series_episodes($series, array $episodes) {
    $toInsert = [];
    foreach ($episodes as $season => $seasonEpisodes) {
        if (!is_array($seasonEpisodes)) {
            continue;
        }
        foreach ($seasonEpisodes as $episode) {
            $id = (string)($episode['id'] ?? '');
            $extension = preg_replace('/[^a-z0-9]/i', '', $episode['container_extension'] ?? 'mp4') ?: 'mp4';
            $url = rtrim($series['base_url'], '/') . '/series/'
                . rawurlencode($series['username']) . '/' . rawurlencode($series['password'])
                . '/' . rawurlencode($id) . '.' . $extension;
            iptv_add_item(
                $toInsert,
                $series['source_id'],
                'episode',
                $id,
                $episode['title'] ?? tr('حلقة', 'Episode'),
                $series['category_name'],
                $url,
                $episode['info']['movie_image'] ?? $series['poster_url'],
                $episode['info']['plot'] ?? '',
                '',
                '',
                $series['external_id'],
                $season,
                $episode['episode_num'] ?? 0
            );
        }
    }
    $pdo = DB::getInstance()->getPDO();
    $pdo->beginTransaction();
    try {
        DB::delete('iptv_items', 'source_id = ? AND item_type = ? AND parent_external_id = ?', [
            (int)$series['source_id'], 'episode', (string)$series['external_id']
        ]);
        foreach ($toInsert as $episode) {
            DB::insert('iptv_items', $episode);
        }
        DB::update('iptv_items', ['children_synced_at' => date('Y-m-d H:i:s')], 'id = ?', [(int)$series['id']]);
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function iptv_detail_value(array $data, array $keys, $default = '') {
    foreach ($keys as $key) {
        if (!array_key_exists($key, $data) || is_array($data[$key]) || is_object($data[$key])) {
            continue;
        }
        $value = trim((string)$data[$key]);
        if ($value !== '') {
            return $value;
        }
    }
    return $default;
}

function iptv_detail_list($value, $limit = 20) {
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $value = $decoded;
        } else {
            $value = preg_split('/\s*(?:,|;|\||\n)\s*/u', $value) ?: [];
        }
    }
    if (!is_array($value)) {
        return [];
    }
    $items = [];
    foreach ($value as $entry) {
        if (is_string($entry) || is_numeric($entry)) {
            $name = trim((string)$entry);
            $person = ['name' => $name];
        } elseif (is_array($entry)) {
            $name = trim((string)($entry['name'] ?? ''));
            $person = [
                'name' => $name,
                'character' => trim((string)($entry['character'] ?? $entry['character_name'] ?? '')),
                'job' => trim((string)($entry['job'] ?? '')),
                'profile' => trim((string)($entry['profile_path'] ?? $entry['profile'] ?? ''))
            ];
        } else {
            continue;
        }
        if ($name !== '') {
            $items[] = $person;
        }
        if (count($items) >= $limit) {
            break;
        }
    }
    return $items;
}

function iptv_detail_title_key($title) {
    $title = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$title), 'UTF-8') : strtolower(trim((string)$title));
    $title = preg_replace('/\s*[\[(](?:19|20)\d{2}[\])]\s*/u', ' ', $title);
    $title = preg_replace('/\b(?:1080p|720p|2160p|4k|bluray|web-dl|webdl|hdtv)\b/iu', ' ', $title);
    return preg_replace('/[^\p{L}\p{N}]+/u', '', $title) ?: '';
}

function iptv_tmdb_image_url($path, $type) {
    if (!is_string($path) || $path === '' || !in_array($type, ['covers', 'backdrops', 'actors'], true)) {
        return '';
    }
    return 'tmdb_image.php?type=' . rawurlencode($type) . '&path=' . rawurlencode($path);
}

function iptv_tmdb_metadata($item, array $metadata) {
    if (!get_setting('tmdb_api_key', '')) {
        return $metadata;
    }
    require_once __DIR__ . '/tmdb.php';
    $tmdb = new TMDB();
    $isSeries = in_array($item['item_type'], ['series', 'episode'], true);
    $provider = $metadata['provider_info'];
    $tmdbId = iptv_detail_value($provider, ['tmdb_id', 'tmdb', 'themoviedb_id']);
    $result = null;
    if ($tmdbId !== '' && ctype_digit($tmdbId)) {
        $result = $isSeries ? $tmdb->getTVDetails($tmdbId) : $tmdb->getMovieDetails($tmdbId);
    } else {
        $search = $isSeries
            ? $tmdb->searchTV($item['title'])
            : $tmdb->searchMovie($item['title']);
        $results = $search['results'] ?? [];
        $titleKey = iptv_detail_title_key($item['title']);
        foreach ($results as $candidate) {
            $candidateTitle = $candidate[$isSeries ? 'name' : 'title'] ?? '';
            if ($titleKey !== '' && iptv_detail_title_key($candidateTitle) === $titleKey && !empty($candidate['id'])) {
                $tmdbId = (string)$candidate['id'];
                $result = $isSeries ? $tmdb->getTVDetails($tmdbId) : $tmdb->getMovieDetails($tmdbId);
                break;
            }
        }
    }
    if (!is_array($result) || isset($result['error'])) {
        return $metadata;
    }

    $metadata['tmdb_id'] = (string)($result['id'] ?? $tmdbId);
    $metadata['description'] = $metadata['description'] !== ''
        ? $metadata['description']
        : (string)($result['overview'] ?? '');
    $metadata['tagline'] = (string)($result['tagline'] ?? '');
    $metadata['genres'] = implode(', ', array_column($result['genres'] ?? [], 'name'));
    $metadata['runtime'] = (int)($result['runtime'] ?? ($result['episode_run_time'][0] ?? 0));
    $metadata['release_date'] = (string)($result['release_date'] ?? $result['first_air_date'] ?? '');
    $metadata['rating'] = (string)($result['vote_average'] ?? $metadata['rating']);
    $metadata['production_countries'] = implode(', ', array_column($result['production_countries'] ?? [], 'name'));
    if ($metadata['production_countries'] === '' && !empty($result['origin_country'])) {
        $metadata['production_countries'] = implode(', ', $result['origin_country']);
    }
    $metadata['original_language'] = (string)($result['original_language'] ?? '');
    foreach ($result['videos']['results'] ?? [] as $video) {
        if (($video['site'] ?? '') === 'YouTube' && ($video['type'] ?? '') === 'Trailer' && !empty($video['key'])) {
            $metadata['trailer'] = (string)$video['key'];
            break;
        }
    }
    if ($metadata['poster_path'] === '') {
        $metadata['poster_path'] = iptv_tmdb_image_url((string)($result['poster_path'] ?? ''), 'covers');
    }
    $metadata['backdrop_path'] = iptv_tmdb_image_url((string)($result['backdrop_path'] ?? ''), 'backdrops');

    $credits = $result['credits'] ?? [];
    $cast = [];
    foreach (array_slice($credits['cast'] ?? [], 0, 20) as $person) {
        $cast[] = [
            'name' => (string)($person['name'] ?? ''),
            'character' => (string)($person['character'] ?? ''),
            'profile' => iptv_tmdb_image_url((string)($person['profile_path'] ?? ''), 'actors')
        ];
    }
    if ($cast) {
        $metadata['cast'] = $cast;
    }

    $crew = [];
    $knownJobs = ['Director', 'Writer', 'Screenplay', 'Producer', 'Executive Producer', 'Creator', 'Director of Photography', 'Editor'];
    foreach ($credits['crew'] ?? [] as $person) {
        $job = trim((string)($person['job'] ?? ''));
        if (!in_array($job, $knownJobs, true) || count($crew) >= 15) {
            continue;
        }
        $crew[] = [
            'name' => (string)($person['name'] ?? ''),
            'job' => $job,
            'profile' => iptv_tmdb_image_url((string)($person['profile_path'] ?? ''), 'actors')
        ];
    }
    foreach ($result['created_by'] ?? [] as $person) {
        if (count($crew) >= 15) {
            break;
        }
        $crew[] = [
            'name' => (string)($person['name'] ?? ''),
            'job' => tr('منشئ', 'Creator'),
            'profile' => iptv_tmdb_image_url((string)($person['profile_path'] ?? ''), 'actors')
        ];
    }
    if ($crew) {
        $metadata['crew'] = $crew;
    }
    $metadata['metadata_source'] = 'tmdb';
    return $metadata;
}

function iptv_get_item_details($item) {
    if (!in_array($item['item_type'], ['movie', 'series', 'episode'], true)) {
        return [];
    }
    if (!empty($item['details_cache']) && !empty($item['details_synced_at'])
        && strtotime($item['details_synced_at']) > time() - 12 * 60 * 60) {
        $cached = json_decode($item['details_cache'], true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $info = [];
    $episodes = [];
    $metadataSource = '';
    if ($item['source_type'] === 'xtream' && in_array($item['item_type'], ['movie', 'series'], true)) {
        if ($item['item_type'] === 'movie') {
            $response = iptv_xtream_api($item, 'get_vod_info', ['vod_id' => $item['external_id']]);
            $info = array_merge(
                is_array($response['movie_data'] ?? null) ? $response['movie_data'] : [],
                is_array($response['info'] ?? null) ? $response['info'] : []
            );
        } else {
            $response = iptv_xtream_api($item, 'get_series_info', ['series_id' => $item['external_id']]);
            $info = $response['info'] ?? [];
            $episodes = $response['episodes'] ?? [];
        }
        if (!is_array($info)) {
            $info = [];
        }
        if (!is_array($episodes)) {
            $episodes = [];
        }
        $metadataSource = 'xtream';
    }

    $cast = iptv_detail_list($info['cast'] ?? [], 20);
    $crewNames = iptv_detail_list($info['director'] ?? $info['directors'] ?? [], 15);
    $crew = [];
    foreach ($crewNames as $person) {
        $crew[] = [
            'name' => $person['name'],
            'job' => tr('إخراج', 'Director'),
            'profile' => ''
        ];
    }
    $runtimeValue = iptv_detail_value($info, ['duration_secs', 'runtime', 'duration'], '0');
    $runtime = 0;
    if (!empty($info['duration_secs']) && is_numeric($info['duration_secs'])) {
        $runtime = (int)ceil((int)$info['duration_secs'] / 60);
    } elseif (preg_match('/^(\d{1,3}):(\d{2})(?::(\d{2}))?$/', $runtimeValue, $durationParts)) {
        $runtime = isset($durationParts[3])
            ? ((int)$durationParts[1] * 60 + (int)$durationParts[2])
            : (int)$durationParts[1];
    } elseif (is_numeric($runtimeValue)) {
        $runtime = (int)$runtimeValue;
    }
    $metadata = [
        'description' => iptv_detail_value($info, ['plot', 'description', 'overview'], (string)($item['description'] ?? '')),
        'tagline' => iptv_detail_value($info, ['tagline']),
        'genres' => iptv_detail_value($info, ['genre', 'genres']),
        'runtime' => $runtime,
        'release_date' => iptv_detail_value($info, ['releasedate', 'release_date', 'year']),
        'rating' => iptv_detail_value($info, ['rating', 'rating_5based'], (string)($item['rating'] ?? '')),
        'production_countries' => iptv_detail_value($info, ['country', 'production_countries']),
        'original_language' => iptv_detail_value($info, ['language', 'original_language']),
        'trailer' => iptv_detail_value($info, ['youtube_trailer', 'trailer']),
        'poster_path' => (string)($item['poster_url'] ?? ''),
        'backdrop_path' => '',
        'cast' => $cast,
        'crew' => $crew,
        'tmdb_id' => iptv_detail_value($info, ['tmdb_id', 'tmdb']),
        'provider_info' => $info,
        'metadata_source' => $metadataSource
    ];
    $metadata = iptv_tmdb_metadata($item, $metadata);
    unset($metadata['provider_info']);

    if ($item['item_type'] === 'series' && $episodes) {
        iptv_store_series_episodes($item, $episodes);
    }
    DB::update('iptv_items', [
        'details_cache' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        'details_synced_at' => date('Y-m-d H:i:s')
    ], 'id = ?', [(int)$item['id']]);
    return $metadata;
}

function iptv_resource_token($sourceId, $targetUrl) {
    if (!iptv_valid_http_url($targetUrl)) {
        throw new RuntimeException(tr('رابط البث غير صالح.', 'The stream URL is invalid.'));
    }
    $targetHash = hash('sha256', $targetUrl);
    $existing = DB::fetch('SELECT token FROM {prefix}iptv_resources WHERE source_id = ? AND target_hash = ? LIMIT 1', [
        (int)$sourceId, $targetHash
    ]);
    if ($existing) {
        return $existing['token'];
    }
    $token = bin2hex(random_bytes(32));
    DB::insert('iptv_resources', [
        'token' => $token,
        'source_id' => (int)$sourceId,
        'target_hash' => $targetHash,
        'target_url' => $targetUrl
    ]);
    return $token;
}

function iptv_proxy_url($sourceId, $itemId, $download = false) {
    $query = 'source=' . (int)$sourceId . '&item=' . (int)$itemId;
    if ($download) {
        $query .= '&download=1';
    }
    return site_url('iptv_stream.php?' . $query);
}

function iptv_clear_source_cache($sourceId, $cacheType) {
    ensure_iptv_schema();
    if (!in_array($cacheType, ['all', 'live', 'movie', 'series', 'resources'], true)) {
        throw new InvalidArgumentException(tr('نوع الكاش المحدد غير صالح.', 'The selected cache type is invalid.'));
    }
    if (!DB::fetch('SELECT id FROM {prefix}iptv_sources WHERE id = ?', [(int)$sourceId])) {
        throw new RuntimeException(tr('المصدر غير موجود.', 'The source was not found.'));
    }

    $pdo = DB::getInstance()->getPDO();
    $pdo->beginTransaction();
    try {
        if ($cacheType === 'all') {
            DB::query('DELETE FROM {prefix}iptv_items WHERE source_id = ?', [(int)$sourceId]);
            DB::delete('iptv_category_cache', 'source_id = ?', [(int)$sourceId]);
            DB::query('DELETE FROM {prefix}iptv_resources WHERE source_id = ?', [(int)$sourceId]);
            DB::query('DELETE FROM {prefix}iptv_epg WHERE source_id = ?', [(int)$sourceId]);
        } elseif ($cacheType === 'resources') {
            DB::query('DELETE FROM {prefix}iptv_resources WHERE source_id = ?', [(int)$sourceId]);
        } else {
            iptv_track_existing_item_images($sourceId);
            $itemTypes = $cacheType === 'series' ? ['series', 'episode'] : [$cacheType];
            $placeholders = implode(', ', array_fill(0, count($itemTypes), '?'));
            DB::query(
                'DELETE FROM {prefix}iptv_items WHERE source_id = ? AND item_type IN (' . $placeholders . ')',
                array_merge([(int)$sourceId], $itemTypes)
            );
            DB::delete('iptv_category_cache', 'source_id = ? AND item_type = ?', [(int)$sourceId, $cacheType]);
            if ($cacheType === 'live') {
                DB::query('DELETE FROM {prefix}iptv_epg WHERE source_id = ?', [(int)$sourceId]);
            }
        }
        if ($cacheType === 'all') {
            DB::delete('iptv_image_cache', 'source_id = ?', [(int)$sourceId]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    if ($cacheType === 'all') {
        iptv_delete_cached_images($sourceId);
    } elseif (in_array($cacheType, ['live', 'movie', 'series'], true)) {
        iptv_delete_cached_images($sourceId, $cacheType);
    }
}

function iptv_delete_cached_images($sourceId, $itemType = null) {
    $root = realpath(__DIR__ . '/../uploads/iptv');
    $directory = __DIR__ . '/../uploads/iptv/' . (int)$sourceId;
    if ($root === false) {
        return;
    }
    if ($itemType !== null) {
        if (!in_array($itemType, ['live', 'movie', 'series'], true)) {
            throw new InvalidArgumentException(tr('نوع الصور المخزنة المحدد غير صالح.', 'The selected cached image type is invalid.'));
        }
        $images = DB::fetchAll(
            'SELECT image_hash, extension FROM {prefix}iptv_image_cache WHERE source_id = ? AND item_type = ?',
            [(int)$sourceId, $itemType]
        );
        DB::delete('iptv_image_cache', 'source_id = ? AND item_type = ?', [(int)$sourceId, $itemType]);
        if (!is_dir($directory)) {
            return;
        }
        if (is_link($directory)) {
            throw new RuntimeException(tr('تعذر حذف صور المصدر لأن مجلده رابط رمزي.', 'Cannot remove source artwork because its cache directory is a symbolic link.'));
        }
        $resolvedDirectory = realpath($directory);
        if ($resolvedDirectory === false || strpos($resolvedDirectory, $root . DIRECTORY_SEPARATOR) !== 0) {
            throw new RuntimeException(tr('مسار صور المصدر خارج مجلد التخزين المسموح.', 'The source artwork path is outside the allowed cache directory.'));
        }
        foreach ($images as $image) {
            $stillUsed = DB::fetch(
                'SELECT image_hash FROM {prefix}iptv_image_cache WHERE source_id = ? AND image_hash = ? LIMIT 1',
                [(int)$sourceId, $image['image_hash']]
            );
            if ($stillUsed) {
                continue;
            }
            $extension = (string)$image['extension'];
            if (!in_array($extension, ['jpg', 'png', 'gif', 'webp'], true)) {
                throw new RuntimeException(tr('امتداد ملف صورة مخزنة غير صالح.', 'A cached image file extension is invalid.'));
            }
            $path = $resolvedDirectory . DIRECTORY_SEPARATOR . $image['image_hash'] . '.' . $extension;
            if (is_link($path) || (is_file($path) && !unlink($path))) {
                throw new RuntimeException(tr('تعذر حذف ملف صورة من ذاكرة المصدر.', 'Could not remove a source image cache file.'));
            }
        }
        if (!array_diff(scandir($resolvedDirectory) ?: [], ['.', '..']) && !rmdir($resolvedDirectory)) {
            throw new RuntimeException(tr('تعذر حذف مجلد صور المصدر.', 'Could not remove the source artwork directory.'));
        }
        return;
    }
    if (!is_dir($directory)) {
        return;
    }
    if (is_link($directory)) {
        throw new RuntimeException(tr('تعذر حذف صور المصدر لأن مجلده رابط رمزي.', 'Cannot remove source artwork because its cache directory is a symbolic link.'));
    }
    $resolvedDirectory = realpath($directory);
    if ($resolvedDirectory === false || strpos($resolvedDirectory, $root . DIRECTORY_SEPARATOR) !== 0) {
        throw new RuntimeException(tr('مسار صور المصدر خارج مجلد التخزين المسموح.', 'The source artwork path is outside the allowed cache directory.'));
    }
    foreach (scandir($resolvedDirectory) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $resolvedDirectory . DIRECTORY_SEPARATOR . $name;
        if (is_link($path) || !is_file($path) || !unlink($path)) {
            throw new RuntimeException(tr('تعذر حذف ملف صورة من ذاكرة المصدر.', 'Could not remove a source image cache file.'));
        }
    }
    if (!rmdir($resolvedDirectory)) {
        throw new RuntimeException(tr('تعذر حذف مجلد صور المصدر.', 'Could not remove the source artwork directory.'));
    }
}
