<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tmdb.php';

require_permission('moderator');
ensure_media_metadata_schema();
ensure_media_details_schema();
ensure_video_compatibility_schema();

$message = '';
$error = '';
$compatibilityWarnings = [];
$qualityCsrfToken = $_SESSION['quality_encode_csrf'] ?? '';
if ($qualityCsrfToken === '') {
    $qualityCsrfToken = bin2hex(random_bytes(32));
    $_SESSION['quality_encode_csrf'] = $qualityCsrfToken;
}
$mediaTypes = ['movie', 'tv', 'software', 'game'];
$knownCategories = DB::fetchAll(
    "SELECT DISTINCT content_category FROM {prefix}media WHERE content_category != '' ORDER BY content_category"
);
$accessLevels = ['public', 'registered', 'subscriber', 'admin'];
$softwarePlatforms = ['windows', 'linux', 'android', 'ios', 'macos', 'ipados'];
$gamePlatforms = ['windows', 'linux', 'macos', 'playstation', 'ps1', 'ps2', 'ps3', 'ps4', 'ps5', 'psp', 'ps-vita', 'xbox', 'xbox-360', 'xbox-one', 'xbox-series', 'nintendo-wii', 'nintendo-3ds', 'nintendo-switch', 'android', 'ios'];
$platformOptions = array_values(array_unique(array_merge($softwarePlatforms, $gamePlatforms)));
$platformLabels = [
    'windows' => 'Windows', 'linux' => 'Linux', 'android' => 'Android',
    'ios' => 'iPhone / iOS', 'macos' => 'Mac / macOS', 'ipados' => 'iPadOS',
    'playstation' => 'PlayStation', 'ps1' => 'PlayStation 1', 'ps2' => 'PlayStation 2',
    'ps3' => 'PlayStation 3', 'ps4' => 'PlayStation 4', 'ps5' => 'PlayStation 5',
    'psp' => 'PSP', 'ps-vita' => 'PS Vita', 'xbox' => 'Xbox',
    'xbox-360' => 'Xbox 360', 'xbox-one' => 'Xbox One', 'xbox-series' => 'Xbox Series',
    'nintendo-wii' => 'Nintendo Wii', 'nintendo-3ds' => 'Nintendo 3DS',
    'nintendo-switch' => 'Nintendo Switch'
];

if (isset($_GET['tmdb_search'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $query = trim($_GET['query'] ?? '');
    $type = $_GET['type'] ?? 'movie';
    $year = (int)($_GET['year'] ?? 0);

    if ($query === '' || !in_array($type, ['movie', 'tv'], true)) {
        http_response_code(400);
        echo json_encode(['error' => tr('أدخل اسم فيلم أو مسلسل صالحاً.', 'Enter a valid movie or series title.')]);
        exit;
    }

    try {
        $tmdb = new TMDB();
        $results = $type === 'movie'
            ? $tmdb->searchMovie($query, $year ?: null)
            : $tmdb->searchTV($query, $year ?: null);

        if (empty($results['results']) && current_language() === 'ar') {
            $results = $type === 'movie'
                ? $tmdb->searchMovie($query, $year ?: null, 'en-US')
                : $tmdb->searchTV($query, $year ?: null, 'en-US');
        }

        if (isset($results['error'])) {
            http_response_code(502);
            echo json_encode(['error' => $results['error']], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $matches = [];
        foreach (array_slice($results['results'] ?? [], 0, 12) as $result) {
            $date = $result['release_date'] ?? $result['first_air_date'] ?? '';
            $matches[] = [
                'id' => (int)$result['id'],
                'title' => $result['title'] ?? $result['name'] ?? '',
                'original_title' => $result['original_title'] ?? $result['original_name'] ?? '',
                'year' => $date ? (int)substr($date, 0, 4) : 0,
                'overview' => $result['overview'] ?? '',
                'poster_path' => $tmdb->downloadAndCacheImage($result['poster_path'] ?? '', 'covers')
            ];
        }

        echo json_encode(['results' => $matches], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $exception) {
        http_response_code(502);
        echo json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'generate_renditions') {
        $mediaId = (int)($_POST['media_id'] ?? 0);
        $sourceType = $_POST['source_type'] ?? '';
        $sourceId = (int)($_POST['source_id'] ?? 0);
        $source = false;

        $postedToken = $_POST['csrf_token'] ?? '';
        if (!isset($_SESSION['quality_encode_csrf']) || !is_string($postedToken)
            || !hash_equals($_SESSION['quality_encode_csrf'], $postedToken)) {
            $error = tr('انتهت صلاحية الطلب. حدّث الصفحة وحاول مرة أخرى.', 'The request expired. Refresh the page and try again.');
        } else {
            if ($sourceType === 'movie') {
                $source = DB::fetch(
                    "SELECT mf.file_path FROM {prefix}media_files mf
                     JOIN {prefix}media m ON m.id = mf.media_id
                     WHERE mf.id = ? AND m.id = ? AND m.type = 'movie' AND mf.file_type = 'video'",
                    [$sourceId, $mediaId]
                );
            } elseif ($sourceType === 'episode') {
                $source = DB::fetch(
                    "SELECT e.file_path FROM {prefix}episodes e
                     JOIN {prefix}media m ON m.id = e.media_id
                     WHERE e.id = ? AND m.id = ? AND m.type = 'tv'",
                    [$sourceId, $mediaId]
                );
            }

            if (!$source || empty($source['file_path'])) {
                $error = tr('لم يتم العثور على ملف فيديو صالح لإنشاء الجودات.', 'A valid video file for rendition generation was not found.');
            } else {
                $result = create_video_renditions($source['file_path']);
                if ($result['error'] !== '') {
                    $error = $result['error'];
                    if ($result['qualities']) {
                        $error .= ' ' . tr('الجودات التي اكتملت قبل الخطأ: ', 'Qualities completed before the error: ')
                            . implode(', ', $result['qualities']);
                    }
                } else {
                    $message = tr('تم إنشاء بث HLS والجودات القابلة للتنزيل: ', 'Adaptive HLS and downloadable qualities created: ')
                        . implode(', ', $result['qualities']);
                }
            }
        }
    } elseif ($action === 'save_media') {
        $id = (int)($_POST['id'] ?? 0);
        $existingMedia = $id ? DB::fetch("SELECT * FROM {prefix}media WHERE id = ?", [$id]) : false;
        $title = trim($_POST['title'] ?? '');
        $type = $_POST['type'] ?? 'movie';
        $accessLevel = $_POST['access_level'] ?? 'public';
        $tmdbId = (int)($_POST['tmdb_id'] ?? 0);
        $year = (int)($_POST['release_year'] ?? 0);
        $rating = (float)($_POST['rating'] ?? 0);
        $submittedPlatforms = $_POST['platforms'] ?? [];
        if (!is_array($submittedPlatforms)) {
            $submittedPlatforms = [];
        }
        $platforms = array_values(array_intersect($platformOptions, $submittedPlatforms));
        $trailerUrl = trim($_POST['trailer_url'] ?? '');

        if ($id && !$existingMedia) {
            $error = tr('العنصر المطلوب تعديله غير موجود.', 'The media item to edit was not found.');
        } elseif ($title === '') {
            $error = tr('اسم الفيلم أو العنصر مطلوب.', 'A title is required.');
        } elseif (!in_array($type, $mediaTypes, true)) {
            $error = tr('نوع المحتوى المحدد غير صالح.', 'The selected content type is invalid.');
        } elseif (!in_array($accessLevel, $accessLevels, true)) {
            $error = tr('مستوى الوصول المحدد غير صالح.', 'The selected access level is invalid.');
        } elseif ($year < 0 || $year > 2200 || $rating < 0 || $rating > 10) {
            $error = tr('تحقق من سنة الإصدار والتقييم.', 'Check the release year and rating values.');
        } elseif ($trailerUrl !== '' && (!filter_var($trailerUrl, FILTER_VALIDATE_URL)
            || !in_array(strtolower((string)parse_url($trailerUrl, PHP_URL_SCHEME)), ['http', 'https'], true))) {
            $error = tr('رابط المقطع الدعائي يجب أن يبدأ بـ https:// أو http://.', 'Trailer URLs must use http:// or https://.');
        } else {
            try {
                $data = [
                    'title' => $title,
                    'original_title' => trim($_POST['original_title'] ?? ''),
                    'type' => $type,
                    'plot' => trim($_POST['plot'] ?? ''),
                    'tagline' => trim($_POST['tagline'] ?? ''),
                    'genres' => trim($_POST['genres'] ?? ''),
                    'runtime' => max(0, (int)($_POST['runtime'] ?? 0)),
                    'original_language' => trim($_POST['original_language'] ?? ''),
                    'production_countries' => trim($_POST['production_countries'] ?? ''),
                    'release_date' => trim($_POST['release_date'] ?? ''),
                    'status' => trim($_POST['media_status'] ?? ''),
                    'imdb_id' => trim($_POST['imdb_id'] ?? ''),
                    'content_category' => trim($_POST['content_category'] ?? ''),
                    'platforms' => implode(',', $platforms),
                    'license_info' => trim($_POST['license_info'] ?? ''),
                    'version_info' => trim($_POST['version_info'] ?? ''),
                    'system_requirements' => trim($_POST['system_requirements'] ?? ''),
                    'trailer_url' => $trailerUrl,
                    'release_year' => $year,
                    'rating' => $rating,
                    'tmdb_id' => $tmdbId,
                    'access_level' => $accessLevel
                ];

                if ($existingMedia) {
                    DB::update('media', $data, 'id = ?', [$id]);
                    $mediaId = $id;
                } else {
                    $data['poster_path'] = '';
                    $data['backdrop_path'] = '';
                    $mediaId = DB::insert('media', $data);
                }
                $contentDirectory = media_upload_directory($mediaId, $type, $title);

                $tmdbError = '';
                if (!empty($_POST['import_tmdb']) && $tmdbId > 0 && in_array($type, ['movie', 'tv'], true)) {
                    $tmdb = new TMDB();
                    $importedId = $type === 'movie'
                        ? $tmdb->importMovieMetadata($tmdbId, $accessLevel, $mediaId)
                        : $tmdb->importTVShowMetadata($tmdbId, $accessLevel, $mediaId);

                    if (!$importedId) {
                        $tmdbError = tr('تعذر جلب بيانات TMDB. حُفظ العنصر والملف محلياً، ويمكن إعادة المحاولة أو تعديل البيانات يدوياً.', 'TMDB metadata could not be fetched. The item and video were saved locally; retry the import or edit the metadata manually.');
                    }
                }

                $cachedArtwork = DB::fetch(
                    "SELECT poster_path, backdrop_path FROM {prefix}media WHERE id = ?",
                    [$mediaId]
                );
                foreach (['poster_path', 'backdrop_path'] as $imageColumn) {
                    $cachedPath = $cachedArtwork[$imageColumn] ?? '';
                    $sourceArtwork = $cachedPath !== '' ? realpath(__DIR__ . '/../' . $cachedPath) : false;
                    if ($sourceArtwork === false || !is_managed_upload_file($sourceArtwork)) {
                        continue;
                    }
                    $imageDirectory = $contentDirectory . DIRECTORY_SEPARATOR . 'images';
                    if (!is_dir($imageDirectory) && !mkdir($imageDirectory, 0775, true) && !is_dir($imageDirectory)) {
                        throw new RuntimeException(tr('تعذر إنشاء مجلد صور المحتوى.', 'Could not create the content image directory.'));
                    }
                    $extension = strtolower(pathinfo($sourceArtwork, PATHINFO_EXTENSION));
                    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                        continue;
                    }
                    $destinationArtwork = $imageDirectory . DIRECTORY_SEPARATOR . $imageColumn . '.' . $extension;
                    if ($sourceArtwork !== realpath($destinationArtwork)
                        && !copy($sourceArtwork, $destinationArtwork)) {
                        throw new RuntimeException(tr('تعذر نسخ صورة المحتوى إلى مجلده الخاص.', 'Could not copy artwork into its content folder.'));
                    }
                    $relativeArtwork = str_replace(
                        [realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR, '\\'],
                        ['', '/'],
                        $destinationArtwork
                    );
                    DB::update('media', [$imageColumn => $relativeArtwork], 'id = ?', [$mediaId]);
                }

                foreach (['poster_file' => ['covers', 'poster_path'], 'backdrop_file' => ['backdrops', 'backdrop_path']] as $field => [$folder, $column]) {
                    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
                        throw new RuntimeException(tr('فشل رفع إحدى الصور. تحقق من حد الرفع وصلاحيات مجلد uploads.', 'An image upload failed. Check the upload limit and uploads folder permissions.'));
                    }
                    $imageInfo = getimagesize($_FILES[$field]['tmp_name']);
                    if ($imageInfo === false) {
                        throw new RuntimeException(tr('الملف المرفوع ليس صورة صالحة.', 'The uploaded file is not a valid image.'));
                    }
                    $extension = image_type_to_extension($imageInfo[2], false);
                    if (!in_array($extension, ['jpeg', 'png', 'gif', 'webp'], true)) {
                        throw new RuntimeException(tr('صيغة الصورة غير مدعومة.', 'The image format is not supported.'));
                    }
                    $directory = $contentDirectory . DIRECTORY_SEPARATOR . 'images';
                    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                        throw new RuntimeException(tr('تعذر إنشاء مجلد الصور.', 'Could not create the image upload directory.'));
                    }
                    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
                    $destination = $directory . DIRECTORY_SEPARATOR . $filename;
                    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $destination)) {
                        throw new RuntimeException(tr('تعذر حفظ الصورة المرفوعة.', 'Could not save the uploaded image.'));
                    }
                    $relativePath = str_replace(
                        [realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR, '\\'],
                        ['', '/'],
                        $destination
                    );
                    DB::update('media', [$column => $relativePath], 'id = ?', [$mediaId]);
                }

                $galleryPaths = json_decode($editing['gallery_paths'] ?? '[]', true);
                if (!is_array($galleryPaths)) {
                    $galleryPaths = [];
                }
                $galleryNames = $_FILES['gallery_files']['name'] ?? [];
                foreach ($galleryNames as $index => $galleryName) {
                    $uploadError = (int)($_FILES['gallery_files']['error'][$index] ?? UPLOAD_ERR_NO_FILE);
                    if ($uploadError === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    if ($uploadError !== UPLOAD_ERR_OK) {
                        throw new RuntimeException(tr('فشل رفع إحدى صور المعرض.', 'A gallery image upload failed.'));
                    }
                    $imageInfo = getimagesize($_FILES['gallery_files']['tmp_name'][$index]);
                    $extension = $imageInfo ? image_type_to_extension($imageInfo[2], false) : '';
                    if (!$imageInfo || !in_array($extension, ['jpeg', 'png', 'gif', 'webp'], true)) {
                        throw new RuntimeException(tr('إحدى صور المعرض بصيغة غير مدعومة.', 'A gallery image has an unsupported format.'));
                    }
                    $galleryDirectory = $contentDirectory . DIRECTORY_SEPARATOR . 'images';
                    if (!is_dir($galleryDirectory) && !mkdir($galleryDirectory, 0775, true) && !is_dir($galleryDirectory)) {
                        throw new RuntimeException(tr('تعذر إنشاء مجلد صور المحتوى.', 'Could not create the content image directory.'));
                    }
                    $galleryFilename = bin2hex(random_bytes(16)) . '.' . $extension;
                    $galleryDestination = $galleryDirectory . DIRECTORY_SEPARATOR . $galleryFilename;
                    if (!move_uploaded_file($_FILES['gallery_files']['tmp_name'][$index], $galleryDestination)) {
                        throw new RuntimeException(tr('تعذر حفظ إحدى صور المعرض.', 'Could not save a gallery image.'));
                    }
                    $galleryPaths[] = str_replace(
                        [realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR, '\\'],
                        ['', '/'],
                        $galleryDestination
                    );
                }
                DB::update('media', ['gallery_paths' => json_encode(array_values($galleryPaths), JSON_UNESCAPED_SLASHES)], 'id = ?', [$mediaId]);

                if (isset($_FILES['trailer_file']) && $_FILES['trailer_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                    if ($_FILES['trailer_file']['error'] !== UPLOAD_ERR_OK) {
                        throw new RuntimeException(tr('تعذر رفع فيديو المقطع الدعائي.', 'The trailer video upload failed.'));
                    }
                    $trailerExtension = strtolower(pathinfo(basename($_FILES['trailer_file']['name']), PATHINFO_EXTENSION));
                    if (!in_array($trailerExtension, ['mp4', 'm4v', 'mkv', 'avi', 'mov', 'webm'], true)) {
                        throw new RuntimeException(tr('صيغة فيديو المقطع الدعائي غير مدعومة.', 'The trailer video format is not supported.'));
                    }
                    $trailerDirectory = $contentDirectory . DIRECTORY_SEPARATOR . 'trailers';
                    if (!is_dir($trailerDirectory) && !mkdir($trailerDirectory, 0775, true) && !is_dir($trailerDirectory)) {
                        throw new RuntimeException(tr('تعذر إنشاء مجلد المقاطع الدعائية.', 'Could not create the trailer directory.'));
                    }
                    $trailerFilename = bin2hex(random_bytes(16)) . '.' . $trailerExtension;
                    if (!move_uploaded_file($_FILES['trailer_file']['tmp_name'], $trailerDirectory . DIRECTORY_SEPARATOR . $trailerFilename)) {
                        throw new RuntimeException(tr('تعذر حفظ فيديو المقطع الدعائي.', 'Could not save the trailer video.'));
                    }
                    $trailerPath = str_replace(
                        [realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR, '\\'],
                        ['', '/'],
                        $trailerDirectory . DIRECTORY_SEPARATOR . $trailerFilename
                    );
                    DB::update('media', ['trailer_path' => $trailerPath], 'id = ?', [$mediaId]);
                }

                $uploadVideo = function ($temporaryPath, $originalName) use ($contentDirectory) {
                    $extension = strtolower(pathinfo(basename($originalName), PATHINFO_EXTENSION));
                    if (!in_array($extension, ['mp4', 'm4v', 'mkv', 'avi', 'mov', 'webm', 'flv'], true)) {
                        throw new RuntimeException(tr('امتداد ملف الحلقة غير مدعوم. استخدم ملف فيديو مثل MP4 أو MKV.', 'Episode file extension is not supported. Use a video file such as MP4 or MKV.'));
                    }
                    $directory = $contentDirectory . DIRECTORY_SEPARATOR . 'videos';
                    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                        throw new RuntimeException(tr('تعذر إنشاء مجلد الفيديوهات.', 'Could not create the video upload directory.'));
                    }
                    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
                    $destination = $directory . DIRECTORY_SEPARATOR . $filename;
                    if (!move_uploaded_file($temporaryPath, $destination)) {
                        throw new RuntimeException(tr('تعذر حفظ ملف الفيديو المرفوع.', 'Could not save the uploaded video.'));
                    }
                    return $destination;
                };

                $makeCompatibilityCopy = function ($sourcePath) use (&$compatibilityWarnings) {
                    $result = create_compatibility_video($sourcePath, $sourcePath . '.compat.mp4');
                    if ($result['error'] !== '') {
                        $compatibilityWarnings[] = $result['error'];
                    }
                    return $result['path'];
                };

                $uploadedPaths = [];
                if ($type === 'tv') {
                    $saveEpisode = function ($videoPath, $seasonNumber, $episodeNumber, $episodeTitle) use ($mediaId, $makeCompatibilityCopy) {
                        if (!is_file($videoPath)) {
                            throw new RuntimeException(tr('ملف الحلقة المحدد غير موجود.', 'The selected episode file does not exist.'));
                        }
                        $duplicate = DB::fetch(
                            "SELECT id FROM {prefix}episodes WHERE media_id = ? AND season_number = ? AND episode_number = ?",
                            [$mediaId, $seasonNumber, $episodeNumber]
                        );
                        if ($duplicate) {
                            unlink($videoPath);
                            throw new RuntimeException(
                                tr('رقم هذه الحلقة موجود بالفعل في الموسم المحدد.', 'An episode with this season and number already exists.')
                            );
                        }
                        $compatibilityPath = $makeCompatibilityCopy($videoPath);
                        DB::insert('episodes', [
                            'media_id' => $mediaId,
                            'season_number' => $seasonNumber,
                            'episode_number' => $episodeNumber,
                            'title' => $episodeTitle,
                            'file_path' => $videoPath,
                            'compatibility_path' => $compatibilityPath,
                            'duration' => 0
                        ]);
                    };
                    $manualNames = $_FILES['episode_files']['name'] ?? [];
                    $manualCount = is_array($manualNames) ? count($manualNames) : 0;
                    for ($index = 0; $index < $manualCount; $index++) {
                        $uploadError = (int)($_FILES['episode_files']['error'][$index] ?? UPLOAD_ERR_NO_FILE);
                        if ($uploadError === UPLOAD_ERR_NO_FILE) {
                            continue;
                        }
                        if ($uploadError !== UPLOAD_ERR_OK) {
                            throw new RuntimeException(tr('فشل رفع أحد ملفات الحلقات. تحقق من حدود الرفع في PHP.', 'An episode upload failed. Check the PHP upload limits.'));
                        }
                        $seasonNumber = (int)($_POST['episode_seasons'][$index] ?? 0);
                        $episodeNumber = (int)($_POST['episode_numbers'][$index] ?? 0);
                        if ($seasonNumber < 1 || $episodeNumber < 1) {
                            throw new RuntimeException(tr('حدد رقماً صحيحاً للموسم والحلقة لكل ملف مرفوع.', 'Enter a valid season and episode number for every uploaded file.'));
                        }
                        $tempPath = $_FILES['episode_files']['tmp_name'][$index] ?? '';
                        $videoPath = $uploadVideo($tempPath, $manualNames[$index] ?? '');
                        $episodeTitle = trim($_POST['episode_titles'][$index] ?? '');
                        $saveEpisode(
                            $videoPath,
                            $seasonNumber,
                            $episodeNumber,
                            $episodeTitle !== '' ? $episodeTitle : tr('الحلقة ', 'Episode ') . $episodeNumber
                        );
                    }

                    $bulkNames = $_FILES['bulk_episode_files']['name'] ?? [];
                    $bulkCount = is_array($bulkNames) ? count($bulkNames) : 0;
                    if ($bulkCount > 0) {
                        $bulkSeason = (int)($_POST['bulk_season'] ?? 0);
                        $firstEpisode = (int)($_POST['bulk_start_episode'] ?? 0);
                        $titlePattern = trim($_POST['bulk_title_pattern'] ?? '');
                        if ($bulkSeason < 1 || $firstEpisode < 1 || strpos($titlePattern, '{episode}') === false) {
                            throw new RuntimeException(tr('حدد الموسم ورقم بداية الحلقة ونمط اسم الحلقات للرفع الجماعي.', 'Set the season, starting episode number, and episode title pattern for bulk uploads.'));
                        }
                        $bulkOffset = 0;
                        for ($index = 0; $index < $bulkCount; $index++) {
                            $uploadError = (int)($_FILES['bulk_episode_files']['error'][$index] ?? UPLOAD_ERR_NO_FILE);
                            if ($uploadError === UPLOAD_ERR_NO_FILE) {
                                continue;
                            }
                            if ($uploadError !== UPLOAD_ERR_OK) {
                                throw new RuntimeException(tr('فشل رفع أحد ملفات الحلقات الجماعية. تحقق من حدود الرفع في PHP.', 'A bulk episode upload failed. Check the PHP upload limits.'));
                            }
                            $episodeNumber = $firstEpisode + $bulkOffset;
                            $seasonToken = sprintf('%02d', $bulkSeason);
                            $episodeToken = sprintf('%02d', $episodeNumber);
                            $episodeTitle = strtr($titlePattern, [
                                '{season}' => $seasonToken,
                                '{episode}' => $episodeToken
                            ]);
                            $tempPath = $_FILES['bulk_episode_files']['tmp_name'][$index] ?? '';
                            $videoPath = $uploadVideo($tempPath, $bulkNames[$index] ?? '');
                            $saveEpisode($videoPath, $bulkSeason, $episodeNumber, $episodeTitle);
                            $bulkOffset++;
                        }
                    }
                } else {
                    $fileNames = $_FILES['media_files']['name'] ?? [];
                    $allowedExtensions = $type === 'movie'
                        ? ['mp4', 'm4v', 'mkv', 'avi', 'mov', 'webm', 'flv']
                        : ['zip', '7z', 'rar', 'tar', 'gz', 'exe', 'msi', 'apk', 'dmg', 'iso', 'pdf', 'deb', 'rpm', 'pkg'];
                    $directory = $contentDirectory . DIRECTORY_SEPARATOR . 'files';
                    if ($existingMedia) {
                        $existingFiles = DB::fetchAll(
                            "SELECT id, file_path, compatibility_path FROM {prefix}media_files WHERE media_id = ? ORDER BY id ASC",
                            [$mediaId]
                        );
                    } else {
                        $existingFiles = [];
                    }
                    if (!empty($_POST['delete_video']) && $existingMedia) {
                        foreach ($existingFiles as $existingFile) {
                            foreach (['file_path', 'compatibility_path'] as $pathColumn) {
                                $existingPath = $existingFile[$pathColumn] ?? '';
                                if ($existingPath !== '' && is_managed_upload_file($existingPath) && is_file($existingPath)) {
                                    if (!unlink($existingPath)) {
                                        throw new RuntimeException(tr('تعذر حذف أحد الملفات الحالية.', 'Could not delete one of the existing files.'));
                                    }
                                }
                            }
                        }
                        DB::delete('media_files', 'media_id = ?', [$mediaId]);
                        $existingFiles = [];
                    }
                    foreach ($fileNames as $index => $originalName) {
                        $uploadError = (int)($_FILES['media_files']['error'][$index] ?? UPLOAD_ERR_NO_FILE);
                        if ($uploadError === UPLOAD_ERR_NO_FILE) {
                            continue;
                        }
                        if ($uploadError !== UPLOAD_ERR_OK) {
                            throw new RuntimeException(tr('فشل رفع أحد الملفات. تحقق من حد الرفع في PHP.', 'A file upload failed. Check the PHP upload size limit.'));
                        }
                        $extension = strtolower(pathinfo(basename($originalName), PATHINFO_EXTENSION));
                        if (!in_array($extension, $allowedExtensions, true)) {
                            throw new RuntimeException(tr('امتداد الملف غير مسموح لهذا النوع من المحتوى.', 'This file extension is not allowed for the selected content type.'));
                        }
                        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                            throw new RuntimeException(tr('تعذر إنشاء مجلد ملفات المحتوى.', 'Could not create the content files directory.'));
                        }
                        $uploadedPath = $directory . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16)) . '.' . $extension;
                        if (!move_uploaded_file($_FILES['media_files']['tmp_name'][$index], $uploadedPath)) {
                            throw new RuntimeException(tr('تعذر حفظ الملف المرفوع.', 'Could not save the uploaded file.'));
                        }
                        $uploadedPaths[] = $uploadedPath;
                    }
                }

                if ($type !== 'tv') {
                    $serverFilePath = trim($_POST['server_file_path'] ?? '');
                    if (!$uploadedPaths && $serverFilePath !== '' && empty($_POST['delete_video'])) {
                        $allowedServerExtensions = $type === 'movie'
                            ? ['mp4', 'm4v', 'mkv', 'avi', 'mov', 'webm', 'flv']
                            : ['zip', '7z', 'rar', 'tar', 'gz', 'exe', 'msi', 'apk', 'dmg', 'iso', 'pdf', 'deb', 'rpm', 'pkg'];
                        if (!is_managed_upload_file($serverFilePath)
                            || !in_array(strtolower(pathinfo($serverFilePath, PATHINFO_EXTENSION)), $allowedServerExtensions, true)) {
                            throw new RuntimeException(tr('اختر ملفاً صالحاً بالنوع المطلوب من مجلد uploads فقط.', 'Choose a file of the correct type from inside uploads.'));
                        }
                        $uploadedPaths[] = $serverFilePath;
                    }
                    foreach ($uploadedPaths as $index => $videoPath) {
                        if ($type === 'movie') {
                            $existingFile = $existingFiles[$index] ?? false;
                        } elseif ($videoPath === $serverFilePath && $serverFilePath !== '') {
                            $existingFile = $existingFiles[0] ?? false;
                        } else {
                            $existingFile = false;
                        }
                        $compatibilityPath = $existingFile['compatibility_path'] ?? '';
                        $sourceChanged = !isset($existingFile) || $videoPath !== ($existingFile['file_path'] ?? '');
                        if ($type === 'movie' && ($sourceChanged || !empty($_POST['generate_compatibility']))) {
                            $compatibilityPath = is_file($videoPath) ? $makeCompatibilityCopy($videoPath) : '';
                        }
                        $fileData = [
                            'file_path' => $videoPath,
                            'compatibility_path' => $compatibilityPath,
                            'file_size' => is_file($videoPath) ? filesize($videoPath) : 0,
                            'file_type' => $type === 'movie' ? 'video' : 'binary',
                            'access_level' => $accessLevel
                        ];
                        if ($existingFile) {
                            DB::update('media_files', $fileData, 'id = ?', [$existingFile['id']]);
                        } else {
                            $fileData['media_id'] = $mediaId;
                            DB::insert('media_files', $fileData);
                        }
                    }
                } elseif (!empty($_POST['generate_compatibility'])) {
                    $existingEpisodes = DB::fetchAll(
                        "SELECT id, file_path FROM {prefix}episodes WHERE media_id = ?",
                        [$mediaId]
                    );
                    foreach ($existingEpisodes as $existingEpisode) {
                        if (is_file($existingEpisode['file_path'])) {
                            $compatibilityPath = $makeCompatibilityCopy($existingEpisode['file_path']);
                            DB::update('episodes', ['compatibility_path' => $compatibilityPath], 'id = ?', [$existingEpisode['id']]);
                        }
                    }
                }

                if (!empty($_POST['replace_cast'])) {
                    $names = preg_split('/\r\n|\r|\n/', trim($_POST['cast_names']));
                    $characters = preg_split('/\r\n|\r|\n/', trim($_POST['cast_characters']));
                    $existingCast = DB::fetchAll("SELECT * FROM {prefix}cast_members WHERE media_id = ?", [$mediaId]);
                    $oldImages = [];
                    foreach ($existingCast as $actor) {
                        $oldImages[$actor['name']] = $actor['profile_path'];
                    }
                    DB::delete('cast_members', 'media_id = ?', [$mediaId]);
                    foreach ($names as $index => $name) {
                        $name = trim($name);
                        if ($name === '') {
                            continue;
                        }
                        DB::insert('cast_members', [
                            'media_id' => $mediaId,
                            'name' => $name,
                            'character_name' => trim($characters[$index] ?? ''),
                            'profile_path' => $oldImages[$name] ?? ''
                        ]);
                    }
                }

                if (!empty($_POST['delete_video']) && $existingMedia && $type !== 'tv' && !$uploadedPaths) {
                    DB::delete('media_files', 'media_id = ?', [$mediaId]);
                }

                $message = $tmdbError !== ''
                    ? $tmdbError
                    : ($existingMedia
                        ? tr('تم حفظ تعديلات العنصر.', 'Media changes saved.')
                        : tr('تمت إضافة العنصر ودمج بياناته محلياً.', 'Media item added and metadata merged locally.'));
                if ($compatibilityWarnings) {
                    $message .= ' ' . implode(' ', array_unique($compatibilityWarnings));
                }
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
                if ($compatibilityWarnings) {
                    $error .= ' ' . implode(' ', array_unique($compatibilityWarnings));
                }
            }
        }
    } elseif ($action === 'delete_media') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            DB::delete('media_files', 'media_id = ?', [$id]);
            DB::delete('episodes', 'media_id = ?', [$id]);
            DB::delete('cast_members', 'media_id = ?', [$id]);
            ensure_credits_schema();
            DB::delete('media_crew', 'media_id = ?', [$id]);
            DB::delete('media', 'id = ?', [$id]);
            $message = tr('تم حذف العنصر بنجاح.', 'Media item deleted.');
        }
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$editing = $editId ? DB::fetch("SELECT * FROM {prefix}media WHERE id = ?", [$editId]) : false;
$editCast = $editing ? DB::fetchAll("SELECT * FROM {prefix}cast_members WHERE media_id = ? ORDER BY id", [$editId]) : [];
$editFile = $editing ? DB::fetch("SELECT * FROM {prefix}media_files WHERE media_id = ? ORDER BY id ASC LIMIT 1", [$editId]) : false;
$editEpisodes = $editing && $editing['type'] === 'tv'
    ? DB::fetchAll("SELECT id, season_number, episode_number, title, file_path FROM {prefix}episodes WHERE media_id = ? ORDER BY season_number, episode_number", [$editId])
    : [];
$nextEpisodeNumber = 1;
foreach ($editEpisodes as $episode) {
    if ((int)$episode['season_number'] === 1) {
        $nextEpisodeNumber = max($nextEpisodeNumber, (int)$episode['episode_number'] + 1);
    }
}
$mediaItems = DB::fetchAll("SELECT m.*, (SELECT COUNT(*) FROM {prefix}media_files WHERE media_id = m.id) as file_count FROM {prefix}media m ORDER BY m.id DESC");

render_header(tr('إدارة الوسائط', 'Media Management'));
?>

<header class="admin-page-heading">
    <div>
        <p class="eyebrow"><?= sanitize(tr('مكتبة الموقع', 'SITE LIBRARY')) ?></p>
        <h1><?= sanitize(tr('إدارة الأفلام والوسائط', 'Manage movies and media')) ?></h1>
    </div>
    <a href="<?= sanitize(site_url('admin/index.php')) ?>" class="btn"><?= sanitize(tr('لوحة الإدارة', 'Admin dashboard')) ?></a>
</header>

<?php if ($message !== ''): ?><div class="alert <?= $compatibilityWarnings || strpos($message, 'TMDB metadata') !== false || strpos($message, 'تعذر') !== false ? 'alert-info' : 'alert-success' ?>"><?= sanitize($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= sanitize($error) ?></div><?php endif; ?>

<section class="admin-panel media-editor">
    <h2><?= sanitize($editing ? tr('تعديل بيانات الوسائط', 'Edit media details') : tr('إضافة فيلم أو محتوى جديد', 'Add a movie or media item')) ?></h2>
    <p class="form-help"><?= sanitize(tr('ابحث بالاسم العربي أو الأصلي، اختر النتيجة المطابقة، ثم احفظ. تُحمّل الصور وبيانات الممثلين إلى جهازك وتُعرض محلياً.', 'Search by Arabic or original title, select the matching result, then save. Artwork and cast data are downloaded and stored locally.')) ?></p>

    <div class="tmdb-search-box media-type-fields" id="tmdb-search-panel" data-media-types="movie,tv">
        <div class="form-group">
            <label for="tmdb-query"><?= sanitize(tr('مطابقة الاسم مع TMDB', 'Match title with TMDB')) ?></label>
            <input id="tmdb-query" class="form-control" type="search" placeholder="<?= sanitize(tr('مثال: الفيل الأزرق أو The Blue Elephant', 'e.g. The Blue Elephant')) ?>">
        </div>
        <div class="search-fields">
            <select id="tmdb-type" class="form-control" aria-label="<?= sanitize(tr('نوع المحتوى للبحث', 'Search content type')) ?>">
                <option value="movie"><?= sanitize(tr('فيلم', 'Movie')) ?></option>
                <option value="tv"><?= sanitize(tr('مسلسل', 'TV series')) ?></option>
            </select>
            <input id="tmdb-year" class="form-control" type="number" min="1900" max="2200" placeholder="<?= sanitize(tr('سنة الإصدار', 'Release year')) ?>">
            <button id="tmdb-search-button" type="button" class="btn btn-primary"><?= sanitize(tr('بحث', 'Search TMDB')) ?></button>
        </div>
        <div id="tmdb-results" class="tmdb-results" aria-live="polite"></div>
    </div>

    <form method="POST" enctype="multipart/form-data" id="media-form">
        <input type="hidden" name="action" value="save_media">
        <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
        <input type="hidden" name="tmdb_id" id="tmdb-id" value="<?= (int)($editing['tmdb_id'] ?? 0) ?>">
        <input type="hidden" name="import_tmdb" id="import-tmdb" value="0">

        <div class="settings-grid">
            <div class="form-group">
                <label for="media-title"><?= sanitize(tr('اسم العرض *', 'Display title *')) ?></label>
                <input id="media-title" type="text" name="title" class="form-control" required value="<?= sanitize($editing['title'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="original-title"><?= sanitize(tr('العنوان الأصلي', 'Original title')) ?></label>
                <input id="original-title" type="text" name="original_title" class="form-control" value="<?= sanitize($editing['original_title'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label><?= sanitize(tr('نوع المحتوى', 'Content type')) ?></label>
                <select name="type" id="media-type" class="form-control">
                    <option value="movie" <?= ($editing['type'] ?? 'movie') === 'movie' ? 'selected' : '' ?>><?= sanitize(tr('فيلم', 'Movie')) ?></option>
                    <option value="tv" <?= ($editing['type'] ?? '') === 'tv' ? 'selected' : '' ?>><?= sanitize(tr('مسلسل', 'TV series')) ?></option>
                    <option value="software" <?= ($editing['type'] ?? '') === 'software' ? 'selected' : '' ?>><?= sanitize(tr('برنامج', 'Software')) ?></option>
                    <option value="game" <?= ($editing['type'] ?? '') === 'game' ? 'selected' : '' ?>><?= sanitize(tr('لعبة', 'Game')) ?></option>
                </select>
            </div>
            <div class="form-group">
                <label for="content-category"><?= sanitize(tr('التصنيف المستخدم في القائمة', 'Category used in navigation')) ?></label>
                <input id="content-category" name="content_category" list="media-categories" class="form-control" value="<?= sanitize($editing['content_category'] ?? '') ?>">
                <datalist id="media-categories">
                    <?php foreach ($knownCategories as $category): ?>
                        <option value="<?= sanitize($category['content_category']) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="form-group">
                <label><?= sanitize(tr('سنة الإصدار', 'Release year')) ?></label>
                <input type="number" name="release_year" min="0" max="2200" class="form-control" value="<?= (int)($editing['release_year'] ?? date('Y')) ?>">
            </div>
            <div class="form-group">
                <label><?= sanitize(tr('التقييم من 10', 'Rating out of 10')) ?></label>
                <input type="number" name="rating" min="0" max="10" step="0.1" class="form-control" value="<?= sanitize((string)($editing['rating'] ?? '0')) ?>">
            </div>
            <div class="form-group">
                <label><?= sanitize(tr('مستوى الوصول', 'Access level')) ?></label>
                <select name="access_level" class="form-control">
                    <?php foreach ($accessLevels as $level): ?>
                        <option value="<?= sanitize($level) ?>" <?= ($editing['access_level'] ?? 'public') === $level ? 'selected' : '' ?>><?= sanitize(tr(['public' => 'عام', 'registered' => 'الأعضاء المسجلون', 'subscriber' => 'المشتركون', 'admin' => 'المدير فقط'][$level], ucfirst($level))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <section class="media-type-fields" data-media-types="movie,tv">
        <h3><?= sanitize(tr('بيانات الفيلم أو المسلسل', 'Movie or series details')) ?></h3>
        <div class="settings-grid">
            <div class="form-group">
                <label><?= sanitize(tr('التصنيفات السينمائية', 'Film genres')) ?></label>
                <input type="text" name="genres" class="form-control" value="<?= sanitize($editing['genres'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label><?= sanitize(tr('المدة بالدقائق (مدة الحلقة للمسلسل)', 'Runtime in minutes (episode runtime for series)')) ?></label>
                <input type="number" name="runtime" min="0" class="form-control" value="<?= (int)($editing['runtime'] ?? 0) ?>">
            </div>
            <div class="form-group">
                <label><?= sanitize(tr('لغة العمل الأصلية', 'Original language')) ?></label>
                <input type="text" name="original_language" class="form-control" value="<?= sanitize($editing['original_language'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label><?= sanitize(tr('بلد الإنتاج', 'Production countries')) ?></label>
                <input type="text" name="production_countries" class="form-control" value="<?= sanitize($editing['production_countries'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label><?= sanitize(tr('تاريخ الإصدار', 'Release date')) ?></label>
                <input type="date" name="release_date" class="form-control" value="<?= sanitize($editing['release_date'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label><?= sanitize(tr('حالة العمل', 'Media status')) ?></label>
                <input type="text" name="media_status" class="form-control" value="<?= sanitize($editing['status'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label><?= sanitize(tr('معرّف IMDb', 'IMDb ID')) ?></label>
                <input type="text" name="imdb_id" class="form-control" value="<?= sanitize($editing['imdb_id'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label><?= sanitize(tr('معرّف TMDB', 'TMDB ID')) ?></label>
                <input type="number" id="tmdb-id-display" class="form-control" value="<?= (int)($editing['tmdb_id'] ?? 0) ?>" readonly>
            </div>
        </div>
        <div class="form-group">
            <label><?= sanitize(tr('الجملة التعريفية', 'Tagline')) ?></label>
            <input type="text" name="tagline" class="form-control" value="<?= sanitize($editing['tagline'] ?? '') ?>">
        </div>
        </section>

        <section class="media-type-fields" data-media-types="software,game">
            <h3><?= sanitize(tr('منصات البرنامج أو اللعبة', 'Software or game platforms')) ?></h3>
            <div class="settings-grid">
                <div class="form-group">
                    <label for="platforms"><?= sanitize(tr('أنظمة التشغيل والمنصات (يمكن اختيار أكثر من واحد)', 'Operating systems and platforms (select multiple)')) ?></label>
                    <select id="platforms" name="platforms[]" class="form-control" multiple size="6">
                        <?php foreach ($platformOptions as $platform): ?>
                            <option value="<?= sanitize($platform) ?>" <?= in_array($platform, explode(',', $editing['platforms'] ?? ''), true) ? 'selected' : '' ?>><?= sanitize($platformLabels[$platform]) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="version-info"><?= sanitize(tr('الإصدار أو رقم النسخة', 'Version or release')) ?></label>
                    <input id="version-info" type="text" name="version_info" class="form-control" value="<?= sanitize($editing['version_info'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="license-info"><?= sanitize(tr('مفتاح التفعيل أو معلومات الترخيص', 'Activation key or license information')) ?></label>
                    <textarea id="license-info" name="license_info" class="form-control" rows="3"><?= sanitize($editing['license_info'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label for="system-requirements"><?= sanitize(tr('متطلبات التشغيل (اختياري)', 'System requirements (optional)')) ?></label>
                    <textarea id="system-requirements" name="system_requirements" class="form-control" rows="3"><?= sanitize($editing['system_requirements'] ?? '') ?></textarea>
                </div>
            </div>
        </section>

        <div class="form-group">
            <label id="description-label"><?= sanitize(tr('القصة والوصف', 'Plot and description')) ?></label>
            <textarea name="plot" class="form-control" rows="5"><?= sanitize($editing['plot'] ?? '') ?></textarea>
        </div>

        <div class="media-file-single media-type-fields" id="single-media-file" data-media-types="movie">
            <div class="form-group">
                <label><?= sanitize(tr('رفع ملف الفيديو من جهازك', 'Upload video from your device')) ?></label>
                <input type="file" name="media_files[]" class="form-control" accept="video/*,.mkv,.avi,.mov" multiple>
                <small class="form-help"><?= sanitize(tr('حد الرفع الحالي', 'Current PHP upload limit')) ?>: <?= sanitize(ini_get('upload_max_filesize')) ?></small>
            </div>
            <div class="form-group">
                <label><?= sanitize(tr('مسار ملف على الخادم (اختياري)', 'Existing server file path (optional)')) ?></label>
                <input type="text" name="server_file_path" class="form-control" value="<?= sanitize($editFile['file_path'] ?? '') ?>">
            </div>
        </div>

        <section class="episode-upload-panel media-type-fields" id="episode-upload-panel" data-media-types="tv" <?= ($editing['type'] ?? '') === 'tv' ? '' : 'hidden' ?>>
            <div class="episode-upload-heading">
                <div>
                    <h3><?= sanitize(tr('إضافة حلقات المسلسل', 'Add series episodes')) ?></h3>
                    <p class="form-help"><?= sanitize(tr('أضف حلقة واحدة أو أكثر، وحدد الموسم ورقم الحلقة لكل ملف.', 'Add one or more episodes and set the season and episode number for each file.')) ?></p>
                </div>
                <button type="button" class="btn btn-primary" id="add-episode-row">+ <?= sanitize(tr('إضافة حلقة', 'Add episode')) ?></button>
            </div>
            <div id="episode-upload-rows">
                <div class="episode-upload-row">
                    <div class="form-group">
                        <label><?= sanitize(tr('ملف الحلقة', 'Episode video')) ?></label>
                        <input type="file" name="episode_files[]" class="form-control" accept="video/*,.mp4,.m4v,.mkv,.avi,.mov,.webm,.flv">
                    </div>
                    <div class="form-group">
                        <label><?= sanitize(tr('الموسم', 'Season')) ?></label>
                        <input type="number" name="episode_seasons[]" class="form-control" min="1" value="1">
                    </div>
                    <div class="form-group">
                        <label><?= sanitize(tr('رقم الحلقة', 'Episode number')) ?></label>
                        <input type="number" name="episode_numbers[]" class="form-control" min="1" value="<?= $nextEpisodeNumber ?>">
                    </div>
                    <div class="form-group">
                        <label><?= sanitize(tr('اسم الحلقة (اختياري)', 'Episode title (optional)')) ?></label>
                        <input type="text" name="episode_titles[]" class="form-control" placeholder="<?= sanitize(tr('الحلقة 1', 'Episode 1')) ?>">
                    </div>
                    <button type="button" class="btn btn-danger remove-episode-row" aria-label="<?= sanitize(tr('حذف صف الحلقة', 'Remove episode row')) ?>">×</button>
                </div>
            </div>
            <p class="form-help"><?= sanitize(tr('يمكن ترك صفوف الحلقة الإضافية فارغة، وسيتم حفظ الملفات التي تم اختيارها فقط.', 'Additional rows can be left empty; only selected episode files are saved.')) ?></p>

            <details class="bulk-episode-upload">
                <summary><?= sanitize(tr('رفع عدة حلقات دفعة واحدة', 'Upload multiple episodes at once')) ?></summary>
                <div class="bulk-episode-fields">
                    <div class="form-group">
                        <label><?= sanitize(tr('ملفات الحلقات (اخترها بالترتيب)', 'Episode files (select in order)')) ?></label>
                        <input type="file" name="bulk_episode_files[]" class="form-control" accept="video/*,.mp4,.m4v,.mkv,.avi,.mov,.webm,.flv" multiple>
                    </div>
                    <div class="form-group">
                        <label><?= sanitize(tr('الموسم', 'Season')) ?></label>
                        <input type="number" name="bulk_season" class="form-control" min="1" value="1">
                    </div>
                    <div class="form-group">
                        <label><?= sanitize(tr('رقم أول حلقة', 'First episode number')) ?></label>
                        <input type="number" name="bulk_start_episode" class="form-control" min="1" value="<?= $nextEpisodeNumber ?>">
                    </div>
                    <div class="form-group">
                        <label><?= sanitize(tr('نمط تسمية الحلقات', 'Episode title pattern')) ?></label>
                        <input type="text" name="bulk_title_pattern" class="form-control" value="<?= sanitize(tr('الحلقة {episode}', 'Episode {episode}')) ?>">
                        <small class="form-help"><?= sanitize(tr('استخدم {episode} لرقم الحلقة و{season} لرقم الموسم. مثال: الموسم {season} - الحلقة {episode}', 'Use {episode} for the episode number and {season} for the season. Example: Season {season} - Episode {episode}')) ?></small>
                    </div>
                </div>
            </details>

            <?php if ($editEpisodes): ?>
                <div class="existing-episodes">
                    <h4><?= sanitize(tr('الحلقات المضافة سابقاً', 'Previously added episodes')) ?></h4>
                    <ul>
                        <?php foreach ($editEpisodes as $episode): ?>
                            <li><?= sanitize(tr('الموسم', 'Season')) ?> <?= (int)$episode['season_number'] ?> · <?= sanitize(tr('الحلقة', 'Episode')) ?> <?= (int)$episode['episode_number'] ?> — <?= sanitize($episode['title']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </section>

        <section class="media-type-fields admin-panel" data-media-types="software,game">
            <h3><?= sanitize(tr('ملفات البرنامج أو اللعبة', 'Software or game files')) ?></h3>
            <div class="form-group">
                <label><?= sanitize(tr('ملفات التثبيت أو التشغيل (يمكن تحديد عدة ملفات)', 'Installers or program files (select multiple files)')) ?></label>
                <input type="file" name="media_files[]" class="form-control" accept=".zip,.7z,.rar,.tar,.gz,.exe,.msi,.apk,.dmg,.iso,.pdf,.deb,.rpm,.pkg" multiple>
                <small class="form-help"><?= sanitize(tr('تُحفظ الملفات داخل مجلد خاص بالمحتوى الحالي. ارفع ملف التفعيل أو معلوماته في حقل الترخيص أعلاه.', 'Files are stored in this item’s own folder. Add activation information in the license field above.')) ?></small>
            </div>
            <div class="form-group">
                <label><?= sanitize(tr('مسار ملف موجود على الخادم (اختياري)', 'Existing server file path (optional)')) ?></label>
                <input type="text" name="server_file_path" class="form-control" value="<?= sanitize($editFile['file_path'] ?? '') ?>" dir="ltr">
            </div>
            <div class="form-group">
                <label><?= sanitize(tr('صور إضافية للبرنامج أو اللعبة', 'Additional software or game screenshots')) ?></label>
                <input type="file" name="gallery_files[]" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
            </div>
            <div class="settings-grid">
                <div class="form-group">
                    <label><?= sanitize(tr('رابط فيديو شرح أو مقطع دعائي', 'Tutorial or trailer URL')) ?></label>
                    <input type="url" name="trailer_url" class="form-control" value="<?= sanitize($editing['trailer_url'] ?? '') ?>" placeholder="https://www.youtube.com/watch?v=..." dir="ltr">
                </div>
                <div class="form-group">
                    <label><?= sanitize(tr('أو رفع فيديو محلي (اختياري)', 'Or upload a local video (optional)')) ?></label>
                    <input type="file" name="trailer_file" class="form-control" accept="video/mp4,video/webm,.mkv,.mov">
                    <?php if (!empty($editing['trailer_path'])): ?>
                        <small class="form-help"><?= sanitize(tr('يوجد فيديو مرفوع حالياً؛ اترك هذا الحقل فارغاً للاحتفاظ به.', 'A local video exists. Leave this empty to keep it.')) ?></small>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <div class="settings-grid">
            <div class="form-group">
                <label><?= sanitize(tr('صورة الغلاف أو البوستر', 'Poster or cover image')) ?></label>
                <input type="file" name="poster_file" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
            </div>
            <div class="form-group media-type-fields" data-media-types="movie,tv">
                <label><?= sanitize(tr('رفع صورة خلفية يدوياً', 'Upload a custom backdrop')) ?></label>
                <input type="file" name="backdrop_file" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
            </div>
        </div>

        <div class="settings-grid media-type-fields" data-media-types="movie,tv">
            <div class="form-group">
                <label><?= sanitize(tr('أسماء الممثلين (اسم واحد في كل سطر)', 'Cast names (one per line)')) ?></label>
                <textarea name="cast_names" class="form-control" rows="5"><?= sanitize(implode("\n", array_column($editCast, 'name'))) ?></textarea>
            </div>
            <div class="form-group">
                <label><?= sanitize(tr('أسماء الشخصيات بالترتيب نفسه', 'Character names in matching order')) ?></label>
                <textarea name="cast_characters" class="form-control" rows="5"><?= sanitize(implode("\n", array_column($editCast, 'character_name'))) ?></textarea>
                <small class="form-help"><?= sanitize(tr('لا يتم استبدال قائمة الممثلين إلا عند تفعيل خيار الاستبدال. استيراد TMDB يحمّل صور الممثلين محلياً.', 'The cast is only replaced when you enable the option below. TMDB import downloads cast portraits locally.')) ?></small>
            </div>
        </div>

        <label class="check-row">
            <input type="checkbox" name="replace_cast" value="1">
            <?= sanitize(tr('استبدال قائمة الممثلين بهذه الأسماء', 'Replace the cast list with these names')) ?>
        </label>

        <?php if ($editing): ?>
            <label class="check-row">
                <input type="checkbox" name="import_tmdb" value="1">
                <?= sanitize(tr('إعادة استيراد البيانات من TMDB (سيستبدل بيانات الوصف والتصنيف الحالية)', 'Refresh metadata from TMDB (overwrites the current imported metadata)')) ?>
            </label>
            <?php if (in_array($editing['type'], ['movie', 'tv'], true)): ?>
                <label class="check-row">
                    <input type="checkbox" name="generate_compatibility" value="1">
                    <?= sanitize(tr('إنشاء أو إعادة إنشاء نسخة MP4 توافقية للفيديوهات الموجودة', 'Generate or refresh legacy-compatible MP4 copies for existing videos')) ?>
                </label>
            <?php endif; ?>
            <?php if ($editFile): ?>
                <label class="check-row danger-text">
                    <input type="checkbox" name="delete_video" value="1">
                    <?= sanitize(tr('حذف الملفات الحالية', 'Delete existing files')) ?>
                </label>
            <?php endif; ?>
        <?php endif; ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-success"><?= sanitize($editing ? tr('حفظ التعديلات', 'Save changes') : tr('حفظ المحتوى محلياً', 'Save media locally')) ?></button>
            <?php if ($editing): ?><a href="<?= sanitize(site_url('admin/media.php')) ?>" class="btn"><?= sanitize(tr('إلغاء', 'Cancel')) ?></a><?php endif; ?>
        </div>
    </form>
</section>

<?php if ($editing && in_array($editing['type'], ['movie', 'tv'], true)): ?>
    <section class="admin-panel">
        <h2><?= sanitize(tr('جودات الفيديو والبث المتكيف', 'Video qualities and adaptive streaming')) ?></h2>
        <p class="form-help"><?= sanitize(tr(
            'يبدأ الترميز يدوياً لكل فيديو. ينشئ HLS للبث المتكيف ونسخ MP4 بدقات 360p و480p و720p و1080p حسب دقة المصدر. يحتاج وقتاً ومساحة إضافية، ولا يغيّر الملف الأصلي.',
            'Encoding is started manually for each video. It creates adaptive HLS and MP4 downloads at 360p, 480p, 720p, and 1080p as supported by the source. It uses extra time and disk space and does not modify the original.'
        )) ?></p>
        <?php
        $renditionSources = [];
        if ($editing['type'] === 'movie' && $editFile && $editFile['file_type'] === 'video' && is_file($editFile['file_path'])) {
            $renditionSources[] = [
                'id' => (int)$editFile['id'],
                'kind' => 'movie',
                'title' => $editing['title'],
                'path' => $editFile['file_path']
            ];
        } elseif ($editing['type'] === 'tv') {
            foreach ($editEpisodes as $editEpisode) {
                if (is_file($editEpisode['file_path'])) {
                    $renditionSources[] = [
                        'id' => (int)$editEpisode['id'],
                        'kind' => 'episode',
                        'title' => sprintf('S%02dE%02d', $editEpisode['season_number'], $editEpisode['episode_number']) . ' - ' . $editEpisode['title'],
                        'path' => $editEpisode['file_path']
                    ];
                }
            }
        }
        ?>
        <?php if (!$renditionSources): ?>
            <p class="episode-empty"><?= sanitize(tr('ارفع ملف فيديو أولاً لحساب الجودات.', 'Upload a video file first to generate qualities.')) ?></p>
        <?php else: ?>
            <div class="rendition-admin-list">
                <?php foreach ($renditionSources as $renditionSource):
                    $existingRenditions = get_video_renditions($renditionSource['path']);
                ?>
                    <div class="rendition-admin-row">
                        <div>
                            <strong><?= sanitize($renditionSource['title']) ?></strong>
                            <p class="form-help">
                                <?= sanitize($existingRenditions['master'] !== ''
                                    ? tr('البث التكيفي جاهز؛ الجودات: ', 'Adaptive stream ready; qualities: ')
                                    : tr('لم يتم إنشاء البث التكيفي بعد.', 'Adaptive stream has not been generated yet.')) ?>
                                <?= sanitize(implode(', ', array_keys($existingRenditions['qualities']))) ?>
                            </p>
                        </div>
                        <form method="POST" onsubmit="return confirm('<?= sanitize(tr('قد يستغرق الترميز وقتاً ويستهلك مساحة إضافية. هل تريد المتابعة؟', 'Encoding may take time and use additional disk space. Continue?')) ?>');">
                            <input type="hidden" name="action" value="generate_renditions">
                            <input type="hidden" name="csrf_token" value="<?= sanitize($qualityCsrfToken) ?>">
                            <input type="hidden" name="media_id" value="<?= (int)$editing['id'] ?>">
                            <input type="hidden" name="source_type" value="<?= sanitize($renditionSource['kind']) ?>">
                            <input type="hidden" name="source_id" value="<?= (int)$renditionSource['id'] ?>">
                            <button type="submit" class="btn btn-primary"><?= sanitize(tr('إنشاء / تحديث الجودات', 'Generate / refresh qualities')) ?></button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="admin-panel">
    <h2><?= sanitize(tr('مكتبة الوسائط الحالية', 'Current media library')) ?></h2>
    <div class="table-wrap">
        <table class="admin-table">
            <thead><tr>
                <th><?= sanitize(tr('البوستر', 'Poster')) ?></th>
                <th><?= sanitize(tr('العنوان', 'Title')) ?></th>
                <th><?= sanitize(tr('النوع', 'Type')) ?></th>
                <th><?= sanitize(tr('الوصول', 'Access')) ?></th>
                <th><?= sanitize(tr('الملفات', 'Files')) ?></th>
                <th><?= sanitize(tr('الإجراءات', 'Actions')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($mediaItems as $item): ?>
                <tr>
                    <td><?php if ($item['poster_path']): ?><img src="<?= sanitize(site_url($item['poster_path'])) ?>" alt="" class="admin-poster"><?php endif; ?></td>
                    <td><strong><?= sanitize($item['title']) ?></strong> (<?= (int)$item['release_year'] ?>)<br><small><?= sanitize(mb_substr($item['plot'] ?? '', 0, 90)) ?></small></td>
                    <td><?= sanitize(strtoupper($item['type'])) ?></td>
                    <td><?= sanitize($item['access_level']) ?></td>
                    <td><?= (int)$item['file_count'] ?></td>
                    <td class="admin-actions">
                        <a href="<?= sanitize(site_url('admin/media.php?edit=' . $item['id'])) ?>" class="btn btn-sm btn-primary"><?= sanitize(tr('تعديل', 'Edit')) ?></a>
                        <form method="POST" onsubmit="return confirm('<?= sanitize(tr('هل تريد حذف هذا المحتوى؟', 'Delete this media item?')) ?>');">
                            <input type="hidden" name="action" value="delete_media">
                            <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger"><?= sanitize(tr('حذف', 'Delete')) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<script>
(() => {
    const button = document.getElementById('tmdb-search-button');
    const query = document.getElementById('tmdb-query');
    const results = document.getElementById('tmdb-results');
    const baseUrl = <?= json_encode(site_url('admin/media.php'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const messages = {
        searching: <?= json_encode(tr('جارٍ البحث في TMDB...', 'Searching TMDB...'), JSON_UNESCAPED_UNICODE) ?>,
        empty: <?= json_encode(tr('لم يتم العثور على نتائج. جرّب الاسم الأصلي أو سنة الإصدار.', 'No results. Try the original title or release year.'), JSON_UNESCAPED_UNICODE) ?>,
        choose: <?= json_encode(tr('اختيار ودمج هذه البيانات', 'Select this match'), JSON_UNESCAPED_UNICODE) ?>,
        error: <?= json_encode(tr('تعذر الاتصال بـ TMDB.', 'TMDB request failed.'), JSON_UNESCAPED_UNICODE) ?>
    };

    function node(tag, className, text) {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== undefined) element.textContent = text;
        return element;
    }

    async function search() {
        if (!query.value.trim()) {
            query.focus();
            return;
        }
        results.replaceChildren(node('p', 'form-help', messages.searching));
        const params = new URLSearchParams({
            tmdb_search: '1',
            query: query.value.trim(),
            type: document.getElementById('tmdb-type').value,
            year: document.getElementById('tmdb-year').value
        });
        try {
            const response = await fetch(baseUrl + '?' + params.toString(), { headers: { Accept: 'application/json' } });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || messages.error);
            results.replaceChildren();
            if (!data.results.length) {
                results.append(node('p', 'form-help', messages.empty));
                return;
            }
            data.results.forEach((item) => {
                const card = node('article', 'tmdb-result-card');
                if (item.poster_path) {
                    const image = node('img', 'tmdb-result-poster');
                    image.src = <?= json_encode(site_url(''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> + item.poster_path;
                    image.alt = '';
                    card.append(image);
                }
                const details = node('div', 'tmdb-result-details');
                details.append(node('strong', '', item.title + (item.year ? ' (' + item.year + ')' : '')));
                if (item.original_title && item.original_title !== item.title) {
                    details.append(node('small', '', item.original_title));
                }
                details.append(node('p', '', item.overview || ''));
                const choose = node('button', 'btn btn-sm btn-primary', messages.choose);
                choose.type = 'button';
                choose.addEventListener('click', () => {
                    document.getElementById('tmdb-id').value = item.id;
                    document.getElementById('tmdb-id-display').value = item.id;
                    document.getElementById('media-title').value = item.title;
                    if (item.original_title) document.getElementById('original-title').value = item.original_title;
                    if (item.year) document.querySelector('[name="release_year"]').value = item.year;
                    if (item.overview) document.querySelector('[name="plot"]').value = item.overview;
                    document.getElementById('media-type').value = document.getElementById('tmdb-type').value;
                    document.getElementById('media-type').dispatchEvent(new Event('change'));
                    document.getElementById('import-tmdb').value = '1';
                    document.getElementById('media-form').scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
                details.append(choose);
                card.append(details);
                results.append(card);
            });
        } catch (error) {
            results.replaceChildren(node('p', 'alert alert-error', error.message || messages.error));
        }
    }

    button.addEventListener('click', search);
    query.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            search();
        }
    });
})();

(() => {
    const typeSelect = document.getElementById('media-type');
    const rows = document.getElementById('episode-upload-rows');
    const addRowButton = document.getElementById('add-episode-row');
    const tmdbType = document.getElementById('tmdb-type');
    const descriptionLabel = document.getElementById('description-label');

    function updateFileMode() {
        const selectedType = typeSelect.value;
        document.querySelectorAll('[data-media-types]').forEach((section) => {
            const visible = section.dataset.mediaTypes.split(',').includes(selectedType);
            section.hidden = !visible;
            section.querySelectorAll('input, select, textarea, button').forEach((field) => {
                field.disabled = !visible;
            });
        });
        tmdbType.value = selectedType === 'tv' ? 'tv' : 'movie';
        descriptionLabel.textContent = selectedType === 'software'
            ? <?= json_encode(tr('وصف البرنامج وطريقة استخدامه', 'Software description and usage'), JSON_UNESCAPED_UNICODE) ?>
            : selectedType === 'game'
                ? <?= json_encode(tr('وصف اللعبة', 'Game description'), JSON_UNESCAPED_UNICODE) ?>
                : <?= json_encode(tr('القصة والوصف', 'Plot and description'), JSON_UNESCAPED_UNICODE) ?>;
    }

    function updateRowButtons() {
        const episodeRows = rows.querySelectorAll('.episode-upload-row');
        episodeRows.forEach((row) => {
            row.querySelector('.remove-episode-row').disabled = episodeRows.length === 1;
        });
    }

    addRowButton.addEventListener('click', () => {
        const row = rows.querySelector('.episode-upload-row').cloneNode(true);
        const rowCount = rows.querySelectorAll('.episode-upload-row').length;
        const firstSeason = rows.querySelector('input[name="episode_seasons[]"]').value || '1';
        const firstEpisode = Number(rows.querySelector('input[name="episode_numbers[]"]').value) || 1;
        row.querySelectorAll('input').forEach((input) => {
            if (input.type === 'file') {
                input.value = '';
            } else if (input.name === 'episode_seasons[]') {
                input.value = firstSeason;
            } else if (input.name === 'episode_numbers[]') {
                input.value = String(firstEpisode + rowCount);
            } else {
                input.value = '';
            }
        });
        rows.append(row);
        updateRowButtons();
        row.querySelector('input[type="file"]').focus();
    });

    rows.addEventListener('click', (event) => {
        const removeButton = event.target.closest('.remove-episode-row');
        if (removeButton && rows.querySelectorAll('.episode-upload-row').length > 1) {
            removeButton.closest('.episode-upload-row').remove();
            updateRowButtons();
        }
    });

    typeSelect.addEventListener('change', updateFileMode);
    updateFileMode();
    updateRowButtons();
})();
</script>

<?php render_footer(); ?>
