<?php
// Media Directory Scanner & TV Show Episode Matcher

class MediaScanner {

    private static $allowedExtensions = ['mp4', 'm4v', 'mkv', 'avi', 'mov', 'wmv', 'flv', 'webm', 'zip', '7z', 'rar', 'tar', 'gz', 'exe', 'msi', 'apk', 'dmg', 'deb', 'rpm', 'pkg', 'iso', 'pdf'];
    private static $videoExtensions = ['mp4', 'm4v', 'mkv', 'avi', 'mov', 'wmv', 'flv', 'webm'];

    public static function scanDirectory($dirPath) {
        $realPath = realpath($dirPath);
        $uploadsRoot = realpath(__DIR__ . '/../uploads');
        if (!$realPath || !$uploadsRoot || !is_dir($realPath)
            || strncasecmp($realPath, $uploadsRoot, strlen($uploadsRoot)) !== 0
            || (strlen($realPath) > strlen($uploadsRoot)
                && substr($realPath, strlen($uploadsRoot), 1) !== DIRECTORY_SEPARATOR)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($realPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $ext = strtolower($item->getExtension());
                if (in_array($ext, self::$allowedExtensions)) {
                    $filePath = $item->getPathname();
                    $parsed = self::parseMediaInfo($item->getFilename());

                    $files[] = [
                        'file_path' => $filePath,
                        'file_name' => $item->getFilename(),
                        'file_size' => $item->getSize(),
                        'extension' => $ext,
                        'is_video' => in_array($ext, self::$videoExtensions, true),
                        'parsed_title' => $parsed['title'],
                        'season' => $parsed['season'],
                        'episode' => $parsed['episode']
                    ];
                }
            }
        }

        return $files;
    }

    public static function parseMediaInfo($filename) {
        $cleanName = pathinfo($filename, PATHINFO_FILENAME);
        // Replace dots/underscores with spaces
        $normalized = str_replace(['.', '_'], ' ', $cleanName);

        $season = null;
        $episode = null;
        $title = $normalized;

        // Regular expressions for S01E02, 1x02, Season 1 Episode 2
        $patterns = [
            '/s(\d{1,2})e(\d{1,2})/i',
            '/(\d{1,2})x(\d{1,2})/i',
            '/season\s*(\d{1,2})\s*episode\s*(\d{1,2})/i',
            '/ep\s*(\d{1,2})/i'
        ];

        foreach ($patterns as $index => $pattern) {
            if (preg_match($pattern, $normalized, $matches)) {
                if ($index === 3) { // ep 01
                    $season = 1;
                    $episode = (int)$matches[1];
                } else {
                    $season = (int)$matches[1];
                    $episode = (int)$matches[2];
                }

                // Strip the season/episode part to get show title
                $titleParts = preg_split($pattern, $normalized);
                if (!empty($titleParts[0])) {
                    $title = trim($titleParts[0]);
                }
                break;
            }
        }

        return [
            'title' => ucwords(trim($title)),
            'season' => $season,
            'episode' => $episode
        ];
    }
}
