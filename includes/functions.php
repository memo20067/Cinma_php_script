<?php
// Common Functions and Helpers

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

function get_setting($key, $default = '') {
    static $settingsCache = null;
    if ($settingsCache === null) {
        $settingsCache = [];
        try {
            $rows = DB::fetchAll("SELECT setting_key, setting_value FROM {prefix}settings");
            foreach ($rows as $row) {
                $settingsCache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Exception $e) {
            // Settings table might not exist yet
        }
    }
    return isset($settingsCache[$key]) ? $settingsCache[$key] : $default;
}

function update_setting($key, $value) {
    $existing = DB::fetch("SELECT setting_key FROM {prefix}settings WHERE setting_key = ?", [$key]);
    if ($existing) {
        DB::update('settings', ['setting_value' => $value], 'setting_key = ?', [$key]);
    } else {
        DB::insert('settings', ['setting_key' => $key, 'setting_value' => $value]);
    }
}

function sanitize($data) {
    return htmlspecialchars(trim($data ?? ''), ENT_QUOTES, 'UTF-8');
}

function current_language() {
    if (isset($_GET['language']) && in_array($_GET['language'], ['ar', 'en'], true)) {
        $_SESSION['language'] = $_GET['language'];
    }

    return $_SESSION['language'] ?? 'ar';
}

function tr($arabic, $english) {
    return current_language() === 'ar' ? $arabic : $english;
}

function language_switch_url($language) {
    $requestUri = $_SERVER['REQUEST_URI'] ?? site_url('index.php');
    $path = strtok($requestUri, '?');
    $query = [];
    $rawQuery = parse_url($requestUri, PHP_URL_QUERY);
    if ($rawQuery) {
        parse_str($rawQuery, $query);
    }
    $query['language'] = $language;

    return $path . '?' . http_build_query($query);
}

function ensure_media_metadata_schema() {
    $columns = [
        'tagline' => 'TEXT',
        'genres' => 'TEXT',
        'runtime' => 'INTEGER NOT NULL DEFAULT 0',
        'original_language' => 'VARCHAR(16) NOT NULL DEFAULT \'\'',
        'production_countries' => 'TEXT',
        'release_date' => 'VARCHAR(16) NOT NULL DEFAULT \'\'',
        'status' => 'VARCHAR(100) NOT NULL DEFAULT \'\'',
        'imdb_id' => 'VARCHAR(32) NOT NULL DEFAULT \'\''
    ];
    $driver = DB::getInstance()->getDriver();
    $existingColumns = [];

    if ($driver === 'sqlite') {
        $rows = DB::fetchAll('PRAGMA table_info("' . DB::getInstance()->table('media') . '")');
        foreach ($rows as $row) {
            $existingColumns[] = $row['name'];
        }
    } else {
        $rows = DB::fetchAll('SHOW COLUMNS FROM {prefix}media');
        foreach ($rows as $row) {
            $existingColumns[] = $row['Field'];
        }
    }

    foreach ($columns as $column => $definition) {
        if (!in_array($column, $existingColumns, true)) {
            DB::query('ALTER TABLE {prefix}media ADD COLUMN `' . $column . '` ' . $definition);
        }
    }
}

function ensure_pages_schema() {
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }

    $driver = DB::getInstance()->getDriver();
    $contentType = $driver === 'sqlite' ? 'TEXT NOT NULL' : 'MEDIUMTEXT NOT NULL';
    $timestampType = $driver === 'sqlite'
        ? 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
        : 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP';

    DB::query(
        'CREATE TABLE IF NOT EXISTS {prefix}pages (
            id INTEGER PRIMARY KEY ' . ($driver === 'sqlite' ? 'AUTOINCREMENT' : 'AUTO_INCREMENT') . ',
            slug VARCHAR(160) NOT NULL UNIQUE,
            title VARCHAR(255) NOT NULL,
            content_html ' . $contentType . ',
            status VARCHAR(20) NOT NULL DEFAULT \'published\',
            created_at ' . $timestampType . ',
            updated_at ' . $timestampType . '
        )' . ($driver === 'sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')
    );
    $columns = [];
    if ($driver === 'sqlite') {
        foreach (DB::fetchAll('PRAGMA table_info("' . DB::getInstance()->table('pages') . '")') as $column) {
            $columns[] = $column['name'];
        }
    } else {
        foreach (DB::fetchAll('SHOW COLUMNS FROM {prefix}pages') as $column) {
            $columns[] = $column['Field'];
        }
    }
    $pageColumnType = $driver === 'sqlite' ? 'TEXT': 'MEDIUMTEXT NULL';
    foreach (['page_css', 'page_js'] as $column) {
        if (!in_array($column, $columns, true)) {
            DB::query('ALTER TABLE {prefix}pages ADD COLUMN `' . $column . '` ' . $pageColumnType);
        }
    }
    $schemaReady = true;
}

function ensure_media_details_schema() {
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }

    $driver = DB::getInstance()->getDriver();
    $columns = [];
    if ($driver === 'sqlite') {
        foreach (DB::fetchAll('PRAGMA table_info("' . DB::getInstance()->table('media') . '")') as $column) {
            $columns[] = $column['name'];
        }
    } else {
        foreach (DB::fetchAll('SHOW COLUMNS FROM {prefix}media') as $column) {
            $columns[] = $column['Field'];
        }
    }
    $definitions = [
        'content_category' => "VARCHAR(160) NOT NULL DEFAULT ''",
        'platforms' => 'TEXT NULL',
        'license_info' => 'TEXT NULL',
        'version_info' => "VARCHAR(100) NOT NULL DEFAULT ''",
        'system_requirements' => 'TEXT NULL',
        'trailer_url' => "VARCHAR(1000) NOT NULL DEFAULT ''",
        'trailer_path' => "VARCHAR(1000) NOT NULL DEFAULT ''",
        'gallery_paths' => 'MEDIUMTEXT NULL'
    ];
    if ($driver === 'sqlite') {
        $definitions['gallery_paths'] = 'TEXT';
    }
    foreach ($definitions as $column => $definition) {
        if (!in_array($column, $columns, true)) {
            DB::query('ALTER TABLE {prefix}media ADD COLUMN `' . $column . '` ' . $definition);
        }
    }
    $schemaReady = true;
}

function media_upload_directory(int $mediaId, string $type, string $title): string {
    $folders = ['movie' => 'movies', 'tv' => 'series', 'software' => 'software', 'game' => 'games'];
    if ($mediaId < 1 || !isset($folders[$type])) {
        throw new InvalidArgumentException('Invalid media directory request.');
    }
    $directory = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR
        . 'uploads' . DIRECTORY_SEPARATOR . $folders[$type] . DIRECTORY_SEPARATOR
        . $mediaId . '-' . slugify($title);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException(tr('تعذر إنشاء مجلد خاص بهذا المحتوى داخل uploads.', 'Could not create the content-specific uploads directory.'));
    }
    $resolvedDirectory = realpath($directory);
    if ($resolvedDirectory === false) {
        throw new RuntimeException(tr('تعذر تحديد مجلد المحتوى الحقيقي.', 'Could not resolve the content directory.'));
    }
    return $resolvedDirectory;
}

function is_managed_upload_file(string $path): bool {
    $uploadsRoot = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'uploads');
    $resolvedPath = realpath($path);
    return $uploadsRoot !== false
        && $resolvedPath !== false
        && is_file($resolvedPath)
        && strncasecmp($resolvedPath, $uploadsRoot . DIRECTORY_SEPARATOR, strlen($uploadsRoot) + 1) === 0;
}

function render_ad_slot(string $slot): void {
    $allowedSlots = ['header', 'content_top', 'content_bottom', 'footer'];
    if (!in_array($slot, $allowedSlots, true)) {
        return;
    }
    $adCode = get_setting('ad_slot_' . $slot, '');
    if ($adCode === '' && defined('AD_SLOT_' . strtoupper($slot))) {
        $constant = 'AD_SLOT_' . strtoupper($slot);
        $adCode = constant($constant);
    }
    if ($adCode !== '') {
        echo '<div class="ad-slot ad-slot-' . sanitize($slot) . '">' . $adCode . '</div>';
    }
}

function ensure_credits_schema() {
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }

    $driver = DB::getInstance()->getDriver();
    $idType = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $textType = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(255)';

    DB::query(
        'CREATE TABLE IF NOT EXISTS {prefix}media_crew (
            id ' . $idType . ',
            media_id INTEGER NOT NULL,
            name ' . $textType . ' NOT NULL,
            department ' . $textType . ' NOT NULL DEFAULT \'\',
            job ' . $textType . ' NOT NULL DEFAULT \'\',
            profile_path ' . $textType . ' NOT NULL DEFAULT \'\'
        )' . ($driver === 'sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')
    );
    $schemaReady = true;
}

function ensure_video_compatibility_schema() {
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }

    $driver = DB::getInstance()->getDriver();
    foreach (['media_files', 'episodes'] as $table) {
        $existingColumns = [];
        if ($driver === 'sqlite') {
            $rows = DB::fetchAll('PRAGMA table_info("' . DB::getInstance()->table($table) . '")');
            foreach ($rows as $row) {
                $existingColumns[] = $row['name'];
            }
        } else {
            $rows = DB::fetchAll('SHOW COLUMNS FROM {prefix}' . $table);
            foreach ($rows as $row) {
                $existingColumns[] = $row['Field'];
            }
        }

        if (!in_array('compatibility_path', $existingColumns, true)) {
            $columnType = $driver === 'sqlite' ? 'TEXT NOT NULL DEFAULT \'\'': 'VARCHAR(255) NOT NULL DEFAULT \'\'';
            DB::query('ALTER TABLE {prefix}' . $table . ' ADD COLUMN compatibility_path ' . $columnType);
        }
    }

    $schemaReady = true;
}

function create_compatibility_video(string $sourcePath, string $outputPath): array {
    $ffmpegPath = configured_ffmpeg_path();
    if ($ffmpegPath === '') {
        return [
            'path' => '',
            'error' => tr(
                'لم تُنشأ نسخة التوافق: ثبّت FFmpeg بإنشاء الجودات أولاً أو حدّد مساره من إعدادات الإدارة.',
                'Compatibility copy was not created: install FFmpeg by generating qualities first, or set its path in admin settings.'
            )
        ];
    }

    if (!is_file($sourcePath)) {
        return [
            'path' => '',
            'error' => tr('ملف الفيديو الأصلي غير موجود للتحويل.', 'The original video file is missing.')
        ];
    }
    if (!function_exists('proc_open')) {
        return [
            'path' => '',
            'error' => tr('تحويل الفيديو غير متاح لأن proc_open معطّل في إعدادات PHP.', 'Video conversion is unavailable because proc_open is disabled in PHP.')
        ];
    }

    $outputDirectory = dirname($outputPath);
    if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
        return [
            'path' => '',
            'error' => tr('تعذر إنشاء مجلد نسخة التوافق.', 'Could not create the compatibility video directory.')
        ];
    }

    $command = [
        $ffmpegPath,
        '-hide_banner',
        '-loglevel',
        'error',
        '-nostdin',
        '-y',
        '-i',
        $sourcePath,
        '-map',
        '0:v:0',
        '-map',
        '0:a:0?',
        '-vf',
        'fps=30,scale=w=720:h=480:force_original_aspect_ratio=decrease:force_divisible_by=2',
        '-c:v',
        'libx264',
        '-preset',
        'veryfast',
        '-crf',
        '23',
        '-threads',
        '2',
        '-profile:v',
        'baseline',
        '-level',
        '3.0',
        '-maxrate',
        '4000k',
        '-bufsize',
        '8000k',
        '-pix_fmt',
        'yuv420p',
        '-c:a',
        'aac',
        '-b:a',
        '128k',
        '-ac',
        '2',
        '-movflags',
        '+faststart',
        $outputPath
    ];

    $logPath = tempnam(sys_get_temp_dir(), 'cinemacms-ffmpeg-');
    if ($logPath === false) {
        return [
            'path' => '',
            'error' => tr('تعذر إنشاء ملف سجل مؤقت لتحويل الفيديو.', 'Could not create a temporary FFmpeg log file.')
        ];
    }

    $pipes = [];
    if (function_exists('set_time_limit')) {
        set_time_limit(0);
    }
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['file', $logPath, 'a'],
        2 => ['file', $logPath, 'a']
    ], $pipes);
    if (!is_resource($process)) {
        unlink($logPath);
        return [
            'path' => '',
            'error' => tr('تعذر تشغيل FFmpeg. تحقق من صلاحيات PHP وإعداداته.', 'Could not start FFmpeg. Check PHP permissions and configuration.')
        ];
    }

    fclose($pipes[0]);
    $exitCode = proc_close($process);
    $output = trim((string)file_get_contents($logPath));
    unlink($logPath);

    if ($exitCode !== 0 || !is_file($outputPath) || filesize($outputPath) === 0) {
        if (is_file($outputPath)) {
            unlink($outputPath);
        }
        $details = $output !== '' ? ' ' . mb_substr($output, 0, 1000) : '';
        return [
            'path' => '',
            'error' => tr('فشل تحويل الفيديو إلى MP4 التوافقي.', 'FFmpeg failed to create the compatible MP4.') . $details
        ];
    }

    return ['path' => $outputPath, 'error' => ''];
}

function video_rendition_directory(string $sourcePath): string {
    $resolvedPath = realpath($sourcePath);
    if ($resolvedPath === false) {
        return '';
    }

    return __DIR__ . '/../uploads/renditions/' . hash('sha256', $resolvedPath);
}

function get_video_renditions(string $sourcePath): array {
    $directory = video_rendition_directory($sourcePath);
    $qualities = [];
    foreach ($directory !== '' ? (glob($directory . DIRECTORY_SEPARATOR . '[0-9][0-9][0-9]*p.mp4') ?: []) : [] as $path) {
        $quality = pathinfo($path, PATHINFO_FILENAME);
        if (preg_match('/^[0-9]{3,4}p$/', $quality) && is_file($path) && filesize($path) > 0) {
            $qualities[$quality] = $path;
        }
    }
    uksort($qualities, function ($left, $right) {
        return (int)$left <=> (int)$right;
    });

    return [
        'directory' => $directory,
        'qualities' => $qualities,
        'master' => $directory !== '' && is_file($directory . DIRECTORY_SEPARATOR . 'master.m3u8')
            ? $directory . DIRECTORY_SEPARATOR . 'master.m3u8'
            : ''
    ];
}

function run_video_command(array $command): array {
    $logPath = tempnam(sys_get_temp_dir(), 'cinemacms-video-');
    if ($logPath === false) {
        return ['exit_code' => -1, 'output' => tr('تعذر إنشاء سجل مؤقت لعملية الفيديو.', 'Could not create a temporary video process log.')];
    }

    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['file', $logPath, 'a'],
        2 => ['file', $logPath, 'a']
    ], $pipes);
    if (!is_resource($process)) {
        unlink($logPath);
        return ['exit_code' => -1, 'output' => tr('تعذر تشغيل أداة الفيديو.', 'Could not start the video utility.')];
    }

    fclose($pipes[0]);
    $exitCode = proc_close($process);
    $output = trim((string)file_get_contents($logPath));
    unlink($logPath);

    return ['exit_code' => $exitCode, 'output' => $output];
}

function bundled_ffmpeg_path(): string {
    return __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR
        . 'tools' . DIRECTORY_SEPARATOR . 'ffmpeg' . DIRECTORY_SEPARATOR . 'bin'
        . DIRECTORY_SEPARATOR . 'ffmpeg.exe';
}

function configured_ffmpeg_path(): string {
    $configuredPath = trim(get_setting('ffmpeg_path', ''));
    if ($configuredPath !== '') {
        return is_file($configuredPath) ? $configuredPath : '';
    }

    $bundledPath = bundled_ffmpeg_path();
    return is_file($bundledPath) ? $bundledPath : '';
}

function install_bundled_ffmpeg(): array {
    $ffmpegPath = bundled_ffmpeg_path();
    if (is_file($ffmpegPath) && is_file(dirname($ffmpegPath) . DIRECTORY_SEPARATOR . 'ffprobe.exe')) {
        return ['path' => $ffmpegPath, 'error' => ''];
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        return [
            'path' => '',
            'error' => tr('الحزمة المضمّنة مخصصة لـ Windows/XAMPP فقط.', 'The bundled encoder package is for Windows/XAMPP only.')
        ];
    }
    if (!function_exists('proc_open')) {
        return [
            'path' => '',
            'error' => tr('تعذر تثبيت FFmpeg لأن proc_open معطّل في إعدادات PHP.', 'Could not install FFmpeg because proc_open is disabled in PHP.')
        ];
    }

    $systemRoot = getenv('SystemRoot') ?: 'C:\\Windows';
    $powershellPath = $systemRoot . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
    if (!is_file($powershellPath)) {
        return [
            'path' => '',
            'error' => tr('لم يتم العثور على Windows PowerShell اللازم لتثبيت FFmpeg.', 'Windows PowerShell required to install FFmpeg was not found.')
        ];
    }

    $installerPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR
        . 'tools' . DIRECTORY_SEPARATOR . 'install-ffmpeg.ps1';
    if (!is_file($installerPath)) {
        return [
            'path' => '',
            'error' => tr('ملف إعداد FFmpeg غير موجود داخل المشروع.', 'The FFmpeg setup script is missing from the project.')
        ];
    }

    if (function_exists('set_time_limit')) {
        set_time_limit(0);
    }
    $install = run_video_command([
        $powershellPath,
        '-NoLogo',
        '-NoProfile',
        '-NonInteractive',
        '-ExecutionPolicy',
        'Bypass',
        '-File',
        $installerPath,
        '-Destination',
        dirname($ffmpegPath)
    ]);
    if ($install['exit_code'] !== 0 || !is_file($ffmpegPath)
        || !is_file(dirname($ffmpegPath) . DIRECTORY_SEPARATOR . 'ffprobe.exe')) {
        $details = $install['output'] !== '' ? ' ' . substr($install['output'], 0, 1000) : '';
        return [
            'path' => '',
            'error' => tr('تعذر تنزيل FFmpeg والتحقق منه. تحقق من اتصال الإنترنت وصلاحية الكتابة داخل مجلد tools.', 'Could not download and verify FFmpeg. Check the internet connection and write permission on the tools directory.') . $details
        ];
    }

    return ['path' => $ffmpegPath, 'error' => ''];
}

function create_video_renditions(string $sourcePath): array {
    $ffmpegPath = configured_ffmpeg_path();
    if ($ffmpegPath === '' && trim(get_setting('ffmpeg_path', '')) === '') {
        $installation = install_bundled_ffmpeg();
        $ffmpegPath = $installation['path'];
        if ($installation['error'] !== '') {
            return ['qualities' => [], 'error' => $installation['error']];
        }
    }
    if ($ffmpegPath === '') {
        return [
            'qualities' => [],
            'error' => tr('مسار ffmpeg.exe المحدد في الإعدادات غير صالح.', 'The configured ffmpeg.exe path is invalid.')
        ];
    }
    if (!function_exists('proc_open')) {
        return [
            'qualities' => [],
            'error' => tr('الترميز غير متاح لأن proc_open معطّل في إعدادات PHP.', 'Encoding is unavailable because proc_open is disabled in PHP.')
        ];
    }
    if (!is_file($sourcePath) || !is_readable($sourcePath)) {
        return [
            'qualities' => [],
            'error' => tr('ملف الفيديو الأصلي غير موجود أو غير قابل للقراءة.', 'The original video file is missing or unreadable.')
        ];
    }

    $directory = video_rendition_directory($sourcePath);
    if ($directory === '') {
        return [
            'qualities' => [],
            'error' => tr('تعذر تحديد المسار الحقيقي لملف الفيديو.', 'Could not resolve the original video path.')
        ];
    }

    $ffprobePath = dirname($ffmpegPath) . DIRECTORY_SEPARATOR
        . (DIRECTORY_SEPARATOR === '\\' ? 'ffprobe.exe' : 'ffprobe');
    if (!is_file($ffprobePath)) {
        return [
            'qualities' => [],
            'error' => tr('لم يتم العثور على ffprobe بجانب ffmpeg.exe. ثبّت نسخة FFmpeg الكاملة التي تتضمن الأداتين.', 'ffprobe was not found beside ffmpeg.exe. Install a full FFmpeg build that includes both tools.')
        ];
    }

    $probe = run_video_command([
        $ffprobePath,
        '-v', 'error',
        '-select_streams', 'v:0',
        '-show_entries', 'stream=width,height',
        '-of', 'csv=s=x:p=0',
        $sourcePath
    ]);
    if ($probe['exit_code'] !== 0 || !preg_match('/^([1-9][0-9]*)x([1-9][0-9]*)$/', trim($probe['output']), $dimensions)) {
        return [
            'qualities' => [],
            'error' => tr('تعذر قراءة أبعاد الفيديو بواسطة ffprobe.', 'ffprobe could not read the video dimensions.')
                . ($probe['output'] !== '' ? ' ' . mb_substr($probe['output'], 0, 500) : '')
        ];
    }

    $width = (int)$dimensions[1];
    $height = (int)$dimensions[2];
    $targets = [];
    foreach ([360, 480, 720, 1080] as $target) {
        if ($height >= $target) {
            $targets[$target . 'p'] = $target;
        }
    }
    if (!$targets) {
        $sourceHeight = max(2, $height - ($height % 2));
        $targets[$sourceHeight . 'p'] = $sourceHeight;
    }

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return [
            'qualities' => [],
            'error' => tr('تعذر إنشاء مجلد الجودات. تحقق من صلاحيات مجلد uploads.', 'Could not create the rendition directory. Check permissions on uploads.')
        ];
    }
    if (is_link($directory)) {
        return [
            'qualities' => [],
            'error' => tr('مجلد الجودات لا يمكن أن يكون رابطاً رمزياً.', 'The rendition directory cannot be a symbolic link.')
        ];
    }

    $available = [];
    $bitrates = ['360p' => 800, '480p' => 1400, '720p' => 2800, '1080p' => 5000];
    if (function_exists('set_time_limit')) {
        set_time_limit(0);
    }
    @unlink($directory . DIRECTORY_SEPARATOR . 'master.m3u8');

    foreach ($targets as $quality => $targetHeight) {
        $mp4Path = $directory . DIRECTORY_SEPARATOR . $quality . '.mp4';
        $playlistPath = $directory . DIRECTORY_SEPARATOR . $quality . '.m3u8';
        foreach (glob($directory . DIRECTORY_SEPARATOR . $quality . '_*.ts') ?: [] as $staleSegment) {
            if (is_file($staleSegment) && !is_link($staleSegment)) {
                unlink($staleSegment);
            }
        }

        $encode = run_video_command([
            $ffmpegPath,
            '-hide_banner', '-loglevel', 'error', '-nostdin', '-y',
            '-i', $sourcePath,
            '-map', '0:v:0', '-map', '0:a:0?',
            '-vf', 'scale=-2:min(' . $targetHeight . '\\,ih):force_original_aspect_ratio=decrease:force_divisible_by=2',
            '-r', '30',
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '22',
            '-maxrate', ($bitrates[$quality] ?? 800) . 'k',
            '-bufsize', (($bitrates[$quality] ?? 800) * 2) . 'k',
            '-g', '180', '-keyint_min', '180', '-sc_threshold', '0',
            '-profile:v', 'main', '-level:v', '4.1', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '128k', '-ac', '2',
            '-movflags', '+faststart',
            $mp4Path
        ]);
        if ($encode['exit_code'] !== 0 || !is_file($mp4Path) || filesize($mp4Path) === 0) {
            @unlink($mp4Path);
            $details = $encode['output'] !== '' ? ' ' . mb_substr($encode['output'], 0, 800) : '';
            return [
                'qualities' => array_keys($available),
                'error' => tr('فشل إنشاء جودة ', 'Failed to create the ') . $quality . '.' . $details
            ];
        }

        $segmentPattern = $directory . DIRECTORY_SEPARATOR . $quality . '_%05d.ts';
        $hls = run_video_command([
            $ffmpegPath,
            '-hide_banner', '-loglevel', 'error', '-nostdin', '-y',
            '-i', $mp4Path,
            '-map', '0:v:0', '-map', '0:a:0?',
            '-c', 'copy',
            '-f', 'hls', '-hls_time', '6', '-hls_playlist_type', 'vod',
            '-hls_segment_filename', $segmentPattern,
            $playlistPath
        ]);
        if ($hls['exit_code'] !== 0 || !is_file($playlistPath) || filesize($playlistPath) === 0) {
            @unlink($playlistPath);
            $details = $hls['output'] !== '' ? ' ' . mb_substr($hls['output'], 0, 800) : '';
            return [
                'qualities' => array_keys($available),
                'error' => tr('فشل تجهيز بث HLS لجودة ', 'Failed to prepare the HLS stream for ') . $quality . '.' . $details
            ];
        }
        $available[$quality] = [
            'height' => $targetHeight,
            'width' => max(2, (int)(($width * $targetHeight / $height) / 2) * 2),
            'bandwidth' => ($bitrates[$quality] ?? 800) * 1000 + 128000
        ];
    }

    $masterLines = ['#EXTM3U', '#EXT-X-VERSION:3'];
    foreach ($available as $quality => $details) {
        $masterLines[] = '#EXT-X-STREAM-INF:BANDWIDTH=' . $details['bandwidth']
            . ',RESOLUTION=' . $details['width'] . 'x' . $details['height'];
        $masterLines[] = $quality . '.m3u8';
    }
    $masterTemporaryPath = $directory . DIRECTORY_SEPARATOR . 'master.m3u8.tmp';
    if (file_put_contents($masterTemporaryPath, implode("\n", $masterLines) . "\n", LOCK_EX) === false
        || !rename($masterTemporaryPath, $directory . DIRECTORY_SEPARATOR . 'master.m3u8')) {
        @unlink($masterTemporaryPath);
        return [
            'qualities' => array_keys($available),
            'error' => tr('تعذر حفظ قائمة بث HLS الرئيسية.', 'Could not save the master HLS playlist.')
        ];
    }

    foreach (glob($directory . DIRECTORY_SEPARATOR . '[0-9][0-9][0-9]*p.mp4') ?: [] as $oldMp4Path) {
        $oldQuality = pathinfo($oldMp4Path, PATHINFO_FILENAME);
        if (!preg_match('/^[0-9]{3,4}p$/', $oldQuality) || isset($available[$oldQuality])) {
            continue;
        }
        foreach (array_merge(
            [$oldMp4Path, $directory . DIRECTORY_SEPARATOR . $oldQuality . '.m3u8'],
            glob($directory . DIRECTORY_SEPARATOR . $oldQuality . '_*.ts') ?: []
        ) as $oldGeneratedPath) {
            if (is_file($oldGeneratedPath) && !is_link($oldGeneratedPath)) {
                unlink($oldGeneratedPath);
            }
        }
    }

    return ['qualities' => array_keys($available), 'error' => ''];
}

function current_user() {
    if (isset($_SESSION['user_id'])) {
        return DB::fetch("SELECT * FROM {prefix}users WHERE id = ?", [$_SESSION['user_id']]);
    }
    return null;
}

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function user_role() {
    $user = current_user();
    return $user ? $user['role'] : 'guest';
}

function check_permission($requiredRole) {
    $rolesOrder = ['guest' => 0, 'registered' => 1, 'subscriber' => 2, 'moderator' => 3, 'admin' => 4, 'superuser' => 5];
    $currentRole = user_role();
    $currentLevel = isset($rolesOrder[$currentRole]) ? $rolesOrder[$currentRole] : 0;
    $requiredLevel = isset($rolesOrder[$requiredRole]) ? $rolesOrder[$requiredRole] : 0;

    return $currentLevel >= $requiredLevel;
}

function require_permission($requiredRole) {
    if (!check_permission($requiredRole)) {
        if (!is_logged_in()) {
            header('Location: ' . site_url('login.php'));
            exit;
        } else {
            http_response_code(403);
            die("Access Denied: You do not have permission to access this page.");
        }
    }
}

function format_size($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } elseif ($bytes > 1) {
        return $bytes . ' bytes';
    } elseif ($bytes == 1) {
        return '1 byte';
    } else {
        return '0 bytes';
    }
}

function slugify($text) {
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = trim($text, '-');
    $text = preg_replace('~-+~', '-', $text);
    $text = strtolower($text);
    return empty($text) ? 'n-a' : $text;
}

function render_media_card($media) {
    $type = $media['type'] ?? '';
    $isMovie = $type === 'movie';
    $isSeries = $type === 'tv';
    $detailPath = $isMovie
        ? 'watch.php?id=' . (int)$media['id']
        : ($isSeries
            ? 'show.php?id=' . (int)$media['id']
            : 'details.php?id=' . (int)$media['id']);
    $downloadId = $isSeries
        ? (int)($media['download_episode_id'] ?? 0)
        : (int)($media['download_file_id'] ?? 0);
    $downloadPath = $downloadId
        ? 'download.php?' . ($isSeries ? 'ep=' : 'file_id=') . $downloadId
        : '';
    $typeLabels = [
        'movie' => tr('فيلم', 'Movie'),
        'tv' => tr('مسلسل', 'Series'),
        'software' => tr('برنامج', 'Software'),
        'game' => tr('لعبة', 'Game')
    ];
    $title = (string)($media['title'] ?? '');
    $poster = (string)($media['poster_path'] ?? '');
    $year = (int)($media['release_year'] ?? 0);
    $plot = trim((string)($media['plot'] ?? ''));
    $genres = trim((string)($media['genres'] ?? ''));
    $rating = (float)($media['rating'] ?? 0);
    $category = trim((string)($media['content_category'] ?? ''));
    ?>
    <article class="media-card">
        <div class="media-card-artwork">
            <a class="media-card-image-link" href="<?= sanitize(site_url($detailPath)) ?>" aria-label="<?= sanitize($title) ?>">
                <?php if ($poster !== '' && is_file(__DIR__ . '/../' . $poster)): ?>
                    <img src="<?= sanitize(site_url($poster)) ?>" alt="<?= sanitize($title) ?>" class="media-poster" loading="lazy">
                <?php else: ?>
                    <span class="media-poster media-poster-placeholder"><?= sanitize(tr('لا توجد صورة', 'No poster')) ?></span>
                <?php endif; ?>
            </a>
            <div class="media-card-overlay">
                <strong class="media-card-overlay-title"><?= sanitize($title) ?></strong>
                <span class="media-card-overlay-meta">
                    <?= sanitize($typeLabels[$type] ?? $type) ?>
                    <?php if ($year): ?> · <?= $year ?><?php endif; ?>
                    <?php if ($rating > 0): ?> · ★ <?= sanitize(number_format($rating, 1)) ?><?php endif; ?>
                </span>
                <?php if ($isSeries && isset($media['ep_count'])): ?>
                    <span class="media-card-overlay-meta"><?= (int)$media['ep_count'] ?> <?= sanitize(tr('حلقة', 'episodes')) ?></span>
                <?php endif; ?>
                <?php if ($category !== ''): ?>
                    <span class="media-card-overlay-meta"><?= sanitize($category) ?></span>
                <?php elseif ($genres !== ''): ?>
                    <span class="media-card-overlay-meta"><?= sanitize($genres) ?></span>
                <?php endif; ?>
                <?php if ($plot !== ''): ?>
                    <span class="media-card-overlay-plot"><?= sanitize($plot) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="media-card-actions">
            <a class="btn btn-sm btn-primary" href="<?= sanitize(site_url($detailPath)) ?>">
                <?= sanitize($isMovie || $isSeries ? tr('مشاهدة', 'Watch') : tr('التفاصيل', 'Details')) ?>
            </a>
            <?php if ($downloadPath !== ''): ?>
                <a class="btn btn-sm btn-download" href="<?= sanitize(site_url($downloadPath)) ?>"><?= sanitize(tr('تحميل', 'Download')) ?></a>
            <?php else: ?>
                <span class="btn btn-sm btn-download is-disabled" aria-disabled="true"><?= sanitize(tr('غير متاح', 'Unavailable')) ?></span>
            <?php endif; ?>
        </div>
    </article>
    <?php
}

function render_header($title = '') {
    $language = current_language();
    $siteName = get_setting('site_name', 'CinemaCMS');
    $pageTitle = $title ? "$title - $siteName" : $siteName;
    $user = current_user();
    $logo = get_setting('site_logo', '');
    $isAdminArea = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') !== false
        && $user
        && check_permission('moderator');
    ensure_media_details_schema();
    ensure_pages_schema();
    require_once __DIR__ . '/iptv.php';
    ensure_iptv_schema();
    $customPages = DB::fetchAll("SELECT slug, title FROM {prefix}pages WHERE status = 'published' ORDER BY title");
    $mediaCategories = [];
    foreach (['movie', 'tv', 'software', 'game'] as $mediaType) {
        $mediaCategories[$mediaType] = DB::fetchAll(
            'SELECT DISTINCT content_category FROM {prefix}media
             WHERE type = ? AND content_category != \'\' ORDER BY content_category',
            [$mediaType]
        );
    }
    $iptvSources = iptv_get_sources(true);
    $GLOBALS['renderedAdminShell'] = $isAdminArea;
    ?>
    <!DOCTYPE html>
    <html lang="<?= sanitize($language) ?>" dir="<?= $language === 'ar' ? 'rtl' : 'ltr' ?>">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= sanitize($pageTitle) ?></title>
        <link rel="stylesheet" href="<?= sanitize(site_url('assets/css/style.css')) ?>">
    </head>
    <body class="<?= $isAdminArea ? 'admin-body' : '' ?>">
        <nav class="navbar">
            <div class="nav-container">
                <div class="nav-start">
                    <a class="nav-brand" href="<?= sanitize(site_url('index.php')) ?>">
                        <?php if ($logo): ?>
                            <img src="<?= sanitize(site_url($logo)) ?>" alt="<?= sanitize($siteName) ?>" class="site-logo">
                        <?php else: ?>
                            <span class="site-name"><?= sanitize($siteName) ?></span>
                        <?php endif; ?>
                    </a>
                    <details class="nav-drawer">
                        <summary class="nav-drawer-trigger" aria-label="<?= sanitize(tr('فتح قائمة الصفحات', 'Open page menu')) ?>">
                            <span></span><span></span><span></span>
                        </summary>
                        <div class="nav-drawer-panel">
                            <a class="nav-drawer-home" href="<?= sanitize(site_url('index.php')) ?>"><?= sanitize(tr('الرئيسية', 'Home')) ?></a>
                            <?php
                            $localSections = [
                                'movie' => ['movies.php', tr('الأفلام', 'Movies')],
                                'tv' => ['tvshows.php', tr('المسلسلات', 'TV Series')],
                                'software' => ['software.php?type=software', tr('البرامج', 'Software')],
                                'game' => ['software.php?type=game', tr('الألعاب', 'Games')]
                            ];
                            foreach ($localSections as $mediaType => $section):
                            ?>
                                <div class="nav-drawer-row">
                                    <a href="<?= sanitize(site_url($section[0])) ?>"><?= sanitize($section[1]) ?></a>
                                    <button class="nav-category-toggle" type="button" aria-expanded="false" aria-label="<?= sanitize(tr('عرض التصنيفات', 'Show categories')) ?>">+</button>
                                </div>
                                <div class="nav-category-list" hidden>
                                    <?php foreach ($mediaCategories[$mediaType] as $category): ?>
                                        <a href="<?= sanitize(site_url($section[0] . (strpos($section[0], '?') === false ? '?' : '&') . 'category=' . rawurlencode($category['content_category']))) ?>"><?= sanitize($category['content_category']) ?></a>
                                    <?php endforeach; ?>
                                    <?php if (!$mediaCategories[$mediaType]): ?><span><?= sanitize(tr('لا توجد تصنيفات', 'No categories')) ?></span><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <a href="<?= sanitize(site_url('contact.php')) ?>"><?= sanitize(tr('اتصل بنا', 'Contact')) ?></a>
                            <?php foreach ($customPages as $page): ?>
                                <a href="<?= sanitize(site_url('page.php?slug=' . rawurlencode($page['slug']))) ?>"><?= sanitize($page['title']) ?></a>
                            <?php endforeach; ?>
                            <?php foreach ($iptvSources as $iptvSource): ?>
                                <details class="nav-source-menu">
                                    <summary><?= sanitize($iptvSource['name']) ?></summary>
                                    <?php foreach ([
                                        'live' => tr('البث المباشر', 'Live TV'),
                                        'movie' => tr('الأفلام', 'Movies'),
                                        'series' => tr('المسلسلات', 'Series')
                                    ] as $sourceType => $sourceTypeLabel): ?>
                                        <div class="nav-drawer-row nav-source-row">
                                            <a href="<?= sanitize(site_url('iptv.php?source=' . (int)$iptvSource['id'] . '&type=' . $sourceType)) ?>"><?= sanitize($sourceTypeLabel) ?></a>
                                            <button class="nav-category-toggle" type="button" aria-expanded="false" aria-label="<?= sanitize(tr('عرض التصنيفات', 'Show categories')) ?>">+</button>
                                        </div>
                                        <div class="nav-category-list nav-source-categories" hidden
                                             data-source-id="<?= (int)$iptvSource['id'] ?>"
                                             data-source-type="<?= sanitize($sourceType) ?>"
                                             data-source-version="<?= sanitize((string)($iptvSource['last_synced_at'] ?? '')) ?>">
                                            <span class="nav-category-status"><?= sanitize(tr('افتح لعرض التصنيفات', 'Open to load categories')) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </details>
                            <?php endforeach; ?>
                        </div>
                    </details>
                </div>
                <div class="nav-search">
                    <form action="<?= sanitize(site_url('search.php')) ?>" method="GET">
                        <input type="search" name="q" aria-label="<?= sanitize(tr('ابحث في المحتوى', 'Search the catalog')) ?>" placeholder="<?= sanitize(tr('ابحث عن فيلم أو مسلسل أو لعبة...', 'Search movies, series, games...')) ?>" value="<?= sanitize($_GET['q'] ?? '') ?>">
                        <button type="submit" aria-label="<?= sanitize(tr('بحث', 'Search')) ?>"><span aria-hidden="true"></span></button>
                    </form>
                </div>
                <div class="nav-card-scale" aria-label="<?= sanitize(tr('حجم البطاقات', 'Card size')) ?>">
                    <button type="button" id="card-size-decrease" aria-label="<?= sanitize(tr('تصغير البطاقات', 'Decrease card size')) ?>">−</button>
                    <label class="sr-only" for="card-size-range"><?= sanitize(tr('تغيير حجم البطاقات', 'Adjust card size')) ?></label>
                    <input type="range" id="card-size-range" min="100" max="500" step="1" value="190">
                    <button type="button" id="card-size-increase" aria-label="<?= sanitize(tr('تكبير البطاقات', 'Increase card size')) ?>">+</button>
                </div>
                <div class="nav-user">
                    <div class="language-switch" aria-label="<?= sanitize(tr('لغة الواجهة', 'Interface language')) ?>">
                        <a href="<?= sanitize(language_switch_url('ar')) ?>" lang="ar" hreflang="ar" <?= $language === 'ar' ? 'aria-current="page"' : '' ?>>عربي</a>
                        <span aria-hidden="true">|</span>
                        <a href="<?= sanitize(language_switch_url('en')) ?>" lang="en" hreflang="en" <?= $language === 'en' ? 'aria-current="page"' : '' ?>>EN</a>
                    </div>
                    <?php if ($user): ?>
                        <details class="account-menu">
                            <summary class="account-menu-trigger">
                                <?php if (!empty($user['avatar']) && is_file(__DIR__ . '/../' . $user['avatar'])): ?>
                                    <img src="<?= sanitize(site_url($user['avatar'])) ?>" alt="" class="avatar-sm">
                                <?php else: ?>
                                    <span class="avatar-sm avatar-initial"><?= sanitize(strtoupper(substr($user['username'], 0, 1))) ?></span>
                                <?php endif; ?>
                                <span class="account-menu-username"><?= sanitize($user['username']) ?></span>
                                <span class="account-menu-chevron" aria-hidden="true">▾</span>
                            </summary>
                            <div class="account-menu-panel">
                                <?php if (check_permission('moderator')): ?>
                                    <a href="<?= sanitize(site_url('admin/index.php')) ?>"><?= sanitize(tr('لوحة الإدارة', 'Admin panel')) ?></a>
                                <?php endif; ?>
                                <a href="<?= sanitize(site_url('profile.php')) ?>"><?= sanitize(tr('إعدادات الملف الشخصي', 'Profile settings')) ?></a>
                                <a href="<?= sanitize(site_url('logout.php')) ?>"><?= sanitize(tr('تسجيل الخروج', 'Log out')) ?></a>
                            </div>
                        </details>
                    <?php else: ?>
                        <a href="<?= sanitize(site_url('login.php')) ?>" class="btn btn-sm"><?= sanitize(tr('دخول', 'Login')) ?></a>
                        <a href="<?= sanitize(site_url('register.php')) ?>" class="btn btn-sm btn-primary"><?= sanitize(tr('حساب جديد', 'Register')) ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </nav>
        <button class="page-back-button" id="page-back-button" type="button" aria-label="<?= sanitize(tr('العودة للصفحة السابقة', 'Go back to the previous page')) ?>" title="<?= sanitize(tr('رجوع', 'Back')) ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5m0 0 7-7m-7 7 7 7"></path></svg>
        </button>
        <button class="page-edge-button" id="page-edge-button" type="button" aria-label="<?= sanitize(tr('الانتقال إلى أسفل الصفحة', 'Go to the bottom of the page')) ?>" title="<?= sanitize(tr('إلى أسفل الصفحة', 'Go to page bottom')) ?>" data-direction="down">↓</button>
        <?php if (!$isAdminArea) render_ad_slot('header'); ?>
        <?php if ($isAdminArea): ?>
            <div class="admin-layout">
                <aside class="admin-sidebar">
                    <p class="admin-sidebar-title"><?= sanitize(tr('إدارة الموقع', 'Site administration')) ?></p>
                    <a href="<?= sanitize(site_url('admin/index.php')) ?>"><?= sanitize(tr('الرئيسية والإعدادات', 'Overview & settings')) ?></a>
                    <a href="<?= sanitize(site_url('admin/media.php')) ?>"><?= sanitize(tr('الأفلام والوسائط', 'Movies & media')) ?></a>
                    <?php if (check_permission('admin')): ?>
                        <a href="<?= sanitize(site_url('admin/sources.php')) ?>"><?= sanitize(tr('مصادر البث', 'Streaming sources')) ?></a>
                    <?php endif; ?>
                    <a href="<?= sanitize(site_url('admin/tmdb.php')) ?>"><?= sanitize(tr('استيراد TMDB', 'TMDB importer')) ?></a>
                    <a href="<?= sanitize(site_url('admin/scan.php')) ?>"><?= sanitize(tr('فحص الملفات', 'File scanner')) ?></a>
                    <?php if (check_permission('admin')): ?>
                        <a href="<?= sanitize(site_url('admin/pages.php')) ?>"><?= sanitize(tr('صفحات الموقع', 'Site pages')) ?></a>
                        <a href="<?= sanitize(site_url('admin/users.php')) ?>"><?= sanitize(tr('المستخدمون والصلاحيات', 'Users & permissions')) ?></a>
                    <?php endif; ?>
                    <?php if (check_permission('superuser')): ?>
                        <a href="<?= sanitize(site_url('admin/ads.php')) ?>"><?= sanitize(tr('إعدادات الإعلانات', 'Ad settings')) ?></a>
                    <?php endif; ?>
                </aside>
                <main class="main-content admin-content">
        <?php else: ?>
        <main class="main-content">
        <?php endif; ?>
        <?php if (empty($GLOBALS['renderedAdminShell'])) render_ad_slot('content_top'); ?>
    <?php
}

function render_footer() {
    $siteName = get_setting('site_name', 'CinemaCMS');
    $contactEmail = get_setting('contact_email', '');
    $contactPhone = get_setting('contact_phone', '');
    $contactWhatsapp = preg_replace('/\D+/', '', get_setting('contact_whatsapp', ''));
    if (empty($GLOBALS['renderedAdminShell'])) {
        render_ad_slot('content_bottom');
    }
    ?>
        </main>
        <?php if (!empty($GLOBALS['renderedAdminShell'])): ?>
            </div>
        <?php endif; ?>
        <?php if (empty($GLOBALS['renderedAdminShell'])) render_ad_slot('footer'); ?>
        <footer class="footer">
            <div class="footer-container">
                <div class="footer-copy">
                    <p>&copy; <?= date('Y') ?> <?= sanitize($siteName) ?>. <?= sanitize(tr('جميع الحقوق محفوظة.', 'All rights reserved.')) ?></p>
                    <p class="offline-badge"><?= sanitize(tr('نظام إدارة محتوى يعمل دون اتصال', 'Offline-capable CMS')) ?></p>
                </div>
                <div class="footer-contact" aria-label="<?= sanitize(tr('خيارات التواصل', 'Contact options')) ?>">
                    <a href="<?= sanitize(site_url('contact.php')) ?>"><?= sanitize(tr('اتصل بنا', 'Contact us')) ?></a>
                    <?php if ($contactEmail !== ''): ?>
                        <a href="mailto:<?= sanitize($contactEmail) ?>"><?= sanitize(tr('البريد الإلكتروني', 'Email')) ?></a>
                    <?php endif; ?>
                    <?php if ($contactPhone !== ''): ?>
                        <a href="tel:<?= sanitize($contactPhone) ?>"><?= sanitize(tr('اتصال', 'Call')) ?></a>
                    <?php endif; ?>
                    <?php if ($contactWhatsapp !== ''): ?>
                        <a href="https://wa.me/<?= sanitize($contactWhatsapp) ?>" target="_blank" rel="noopener noreferrer"><?= sanitize(tr('واتساب', 'WhatsApp')) ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </footer>
        <script>
        (function () {
            var backButton = document.getElementById('page-back-button');
            var edgeButton = document.getElementById('page-edge-button');
            var drawers = document.querySelectorAll('.nav-drawer');

            function scrollToPageEdge(direction) {
                window.scrollTo({
                    top: direction === 'down' ? document.documentElement.scrollHeight : 0,
                    behavior: 'smooth'
                });
            }

            function updatePageEdgeButton() {
                if (!edgeButton) {
                    return;
                }
                var atTop = window.scrollY <= 80;
                edgeButton.dataset.direction = atTop ? 'down' : 'up';
                edgeButton.textContent = atTop ? '↓' : '↑';
                edgeButton.setAttribute('aria-label', atTop
                    ? '<?= sanitize(tr('الانتقال إلى أسفل الصفحة', 'Go to the bottom of the page')) ?>'
                    : '<?= sanitize(tr('الانتقال إلى أعلى الصفحة', 'Go to the top of the page')) ?>');
                edgeButton.title = atTop
                    ? '<?= sanitize(tr('إلى أسفل الصفحة', 'Go to page bottom')) ?>'
                    : '<?= sanitize(tr('إلى أعلى الصفحة', 'Go to page top')) ?>';
            }

            if (backButton) {
                backButton.addEventListener('click', function () {
                    var referrerIsLocal = false;
                    if (document.referrer) {
                        try {
                            referrerIsLocal = new URL(document.referrer).origin === window.location.origin;
                        } catch (invalidReferrer) {
                            referrerIsLocal = false;
                        }
                    }
                    if (referrerIsLocal && window.history.length > 1) {
                        window.history.back();
                    } else {
                        window.location.href = '<?= sanitize(site_url('index.php')) ?>';
                    }
                });
            }
            if (edgeButton) {
                edgeButton.addEventListener('click', function () {
                    scrollToPageEdge(edgeButton.dataset.direction);
                });
                window.addEventListener('scroll', updatePageEdgeButton, { passive: true });
                window.addEventListener('resize', updatePageEdgeButton);
                document.addEventListener('keydown', function (event) {
                    var target = event.target;
                    var editable = target && (
                        target.isContentEditable
                        || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName)
                    );
                    if (editable || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) {
                        return;
                    }
                    if (Array.prototype.some.call(drawers, function (drawer) {
                        return drawer.open && drawer.contains(target);
                    })) {
                        return;
                    }
                    if (event.key === 'Home' || event.key === 'End') {
                        event.preventDefault();
                        scrollToPageEdge(event.key === 'End' ? 'down' : 'up');
                    }
                });
                updatePageEdgeButton();
            }

            var cardSizeRange = document.getElementById('card-size-range');
            var minimumCardSize = 100;
            var maximumCardSize = 500;
            var cardSizeStorageKey = 'cinemacms-card-size';

            function setCardSize(value, persist) {
                var size = Math.max(minimumCardSize, Math.min(maximumCardSize, Math.round(Number(value) || 190)));
                document.documentElement.style.setProperty('--content-card-width', size + 'px');
                cardSizeRange.value = String(size);
                if (persist) {
                    try {
                        window.localStorage.setItem(cardSizeStorageKey, String(size));
                    } catch (storageError) {
                        console.warn('Could not save card size preference.', storageError);
                    }
                }
            }

            if (cardSizeRange) {
                var savedCardSize = null;
                try {
                    savedCardSize = window.localStorage.getItem(cardSizeStorageKey);
                } catch (storageError) {
                    console.warn('Could not read card size preference.', storageError);
                }
                setCardSize(savedCardSize === null ? cardSizeRange.value : savedCardSize, false);
                cardSizeRange.addEventListener('input', function () {
                    setCardSize(cardSizeRange.value, true);
                });
                document.getElementById('card-size-decrease').addEventListener('click', function () {
                    setCardSize(Number(cardSizeRange.value) - 10, true);
                });
                document.getElementById('card-size-increase').addEventListener('click', function () {
                    setCardSize(Number(cardSizeRange.value) + 10, true);
                });
            }

            document.querySelectorAll('.nav-category-toggle').forEach(function (button) {
                button.addEventListener('click', function () {
                    var row = button.closest('.nav-drawer-row');
                    var categories = row && row.nextElementSibling;
                    if (!categories || !categories.classList.contains('nav-category-list')) {
                        return;
                    }
                    var expanded = button.getAttribute('aria-expanded') === 'true';
                    button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                    button.textContent = expanded ? '+' : '−';
                    categories.hidden = expanded;
                    if (!expanded && categories.classList.contains('nav-source-categories')
                        && categories.dataset.loaded !== 'true'
                        && categories.dataset.loading !== 'true') {
                        categories.dataset.loading = 'true';
                        categories.replaceChildren();
                        var status = document.createElement('span');
                        status.className = 'nav-category-status';
                        status.textContent = '<?= sanitize(tr('جارٍ تحميل التصنيفات…', 'Loading categories…')) ?>';
                        categories.appendChild(status);
                        var categoryUrl = '<?= sanitize(site_url('iptv_items.php')) ?>?categories=1'
                            + '&source=' + encodeURIComponent(categories.dataset.sourceId)
                            + '&type=' + encodeURIComponent(categories.dataset.sourceType)
                            + '&version=' + encodeURIComponent(categories.dataset.sourceVersion);
                        fetch(categoryUrl, { headers: { Accept: 'application/json' } })
                            .then(function (response) {
                                if (!response.ok) {
                                    throw new Error('HTTP ' + response.status);
                                }
                                return response.json();
                            })
                            .then(function (data) {
                                categories.replaceChildren();
                                if (!Array.isArray(data.categories) || data.categories.length === 0) {
                                    var empty = document.createElement('span');
                                    empty.textContent = '<?= sanitize(tr('لا توجد تصنيفات', 'No categories')) ?>';
                                    categories.appendChild(empty);
                                } else {
                                    data.categories.forEach(function (entry) {
                                        var link = document.createElement('a');
                                        var categoryName = entry.category_name || '';
                                        link.href = '<?= sanitize(site_url('iptv.php')) ?>?source='
                                            + encodeURIComponent(categories.dataset.sourceId)
                                            + '&type=' + encodeURIComponent(categories.dataset.sourceType)
                                            + '&category=' + encodeURIComponent(categoryName);
                                        link.textContent = (categoryName || '<?= sanitize(tr('غير مصنف', 'Uncategorized')) ?>')
                                            + ' (' + entry.item_count + ')';
                                        categories.appendChild(link);
                                    });
                                }
                                categories.dataset.loaded = 'true';
                            })
                            .catch(function (error) {
                                console.error('Could not load IPTV categories.', error);
                                categories.replaceChildren();
                                var failure = document.createElement('span');
                                failure.className = 'nav-category-status';
                                failure.textContent = '<?= sanitize(tr('تعذر تحميل التصنيفات. اضغط + لإعادة المحاولة.', 'Could not load categories. Press + to retry.')) ?>';
                                categories.appendChild(failure);
                            })
                            .finally(function () {
                                categories.dataset.loading = 'false';
                            });
                    }
                });
            });
            drawers.forEach(function (drawer) {
                drawer.addEventListener('click', function (event) {
                    if (drawer.open && event.target === drawer) {
                        drawer.open = false;
                    }
                });
            });
            document.addEventListener('click', function (event) {
                drawers.forEach(function (drawer) {
                    if (drawer.open && (!drawer.contains(event.target) || event.target === drawer)) {
                        drawer.open = false;
                    }
                });
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    drawers.forEach(function (drawer) { drawer.open = false; });
                }
            });
        }());
        </script>
    </body>
    </html>
    <?php
}
