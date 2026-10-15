<?php
// TMDB API Integration & Local File Caching Engine

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

class TMDB {
    private $apiKey;
    private $baseUrl = 'https://api.themoviedb.org/3';
    private $imageBaseUrl = 'https://image.tmdb.org/t/p/w500';
    private $backdropBaseUrl = 'https://image.tmdb.org/t/p/w1280';

    public function __construct($apiKey = null) {
        $this->apiKey = $apiKey ?? get_setting('tmdb_api_key', '');
    }

    private function request($endpoint, $params = []) {
        if (empty($this->apiKey)) {
            return ['error' => 'TMDB API key is not configured in site settings.'];
        }

        $params['language'] = $params['language'] ?? (current_language() === 'ar' ? 'ar-SA' : 'en-US');
        $params['api_key'] = $this->apiKey;
        $url = $this->baseUrl . $endpoint . '?' . http_build_query($params);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false || $response === '') {
            return ['error' => $curlError ?: "TMDB API request failed with code {$httpCode}"];
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : ['error' => 'TMDB returned an invalid response.'];
    }

    public function searchMovie($query, $year = null, $language = null) {
        $params = ['query' => $query];
        if ($year) $params['year'] = $year;
        if ($language) $params['language'] = $language;
        return $this->request('/search/movie', $params);
    }

    public function searchTV($query, $year = null, $language = null) {
        $params = ['query' => $query];
        if ($year) $params['first_air_date_year'] = $year;
        if ($language) $params['language'] = $language;
        return $this->request('/search/tv', $params);
    }

    public function getMovieDetails($tmdbId) {
        return $this->request("/movie/{$tmdbId}", ['append_to_response' => 'credits,external_ids,videos']);
    }

    public function getTVDetails($tmdbId) {
        return $this->request("/tv/{$tmdbId}", ['append_to_response' => 'credits,external_ids,videos']);
    }

    public function getTVSeasonDetails($tmdbId, $seasonNumber) {
        return $this->request("/tv/{$tmdbId}/season/{$seasonNumber}");
    }

    public function downloadAndCacheImage($remotePath, $type = 'covers') {
        if (empty($remotePath)) return '';

        if (!in_array($type, ['covers', 'backdrops', 'actors'], true)) {
            return '';
        }

        $normalizedPath = ltrim($remotePath, '/');
        if (strpos($normalizedPath, 'uploads/') === 0) {
            return $normalizedPath;
        }

        $fullRemoteUrl = $remotePath;
        if (strpos($remotePath, 'http') !== 0) {
            $base = ($type === 'backdrops') ? $this->backdropBaseUrl : $this->imageBaseUrl;
            $fullRemoteUrl = $base . $remotePath;
        }

        $imageUrl = parse_url($fullRemoteUrl);
        if (
            !is_array($imageUrl)
            || ($imageUrl['scheme'] ?? '') !== 'https'
            || ($imageUrl['host'] ?? '') !== 'image.tmdb.org'
        ) {
            return '';
        }

        $ext = strtolower(pathinfo($imageUrl['path'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $ext = 'jpg';
        }
        $filename = md5($fullRemoteUrl) . '.' . $ext;
        $targetDir = __DIR__ . '/../uploads/' . $type . '/';

        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            return '';
        }

        $localFilePath = $targetDir . $filename;
        $publicUrl = 'uploads/' . $type . '/' . $filename;

        // If file already exists locally, reuse it
        if (file_exists($localFilePath) && filesize($localFilePath) > 0) {
            return $publicUrl;
        }

        // Download file
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fullRemoteUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_MAXFILESIZE, 15728640);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && !empty($data)) {
            if (getimagesizefromstring($data) === false) {
                return '';
            }
            if (file_put_contents($localFilePath, $data) === false) {
                return '';
            }
            return $publicUrl;
        }

        return '';
    }

    public function importMovieMetadata($tmdbId, $accessLevel = 'public', $mediaId = null) {
        ensure_credits_schema();
        $details = $this->getMovieDetails($tmdbId);
        if (isset($details['error']) || !isset($details['title'])) {
            return false;
        }

        $title = $details['title'];
        $origTitle = $details['original_title'] ?? $title;
        $plot = $details['overview'] ?? '';
        $releaseYear = !empty($details['release_date']) ? (int)substr($details['release_date'], 0, 4) : 0;
        $rating = isset($details['vote_average']) ? round($details['vote_average'], 1) : 0.0;
        $genres = implode(', ', array_column($details['genres'] ?? [], 'name'));
        $countries = implode(', ', array_column($details['production_countries'] ?? [], 'name'));

        $localPoster = $this->downloadAndCacheImage($details['poster_path'] ?? '', 'covers');
        $localBackdrop = $this->downloadAndCacheImage($details['backdrop_path'] ?? '', 'backdrops');

        $existing = $mediaId
            ? DB::fetch("SELECT id FROM {prefix}media WHERE id = ? AND type = 'movie'", [$mediaId])
            : DB::fetch("SELECT id FROM {prefix}media WHERE tmdb_id = ? AND type = 'movie'", [$tmdbId]);
        $metadata = [
            'title' => $title,
            'original_title' => $origTitle,
            'plot' => $plot,
            'tagline' => $details['tagline'] ?? '',
            'genres' => $genres,
            'runtime' => (int)($details['runtime'] ?? 0),
            'original_language' => $details['original_language'] ?? '',
            'production_countries' => $countries,
            'release_date' => $details['release_date'] ?? '',
            'status' => $details['status'] ?? '',
            'imdb_id' => $details['imdb_id'] ?? ($details['external_ids']['imdb_id'] ?? ''),
            'release_year' => $releaseYear,
            'rating' => $rating,
            'poster_path' => $localPoster,
            'backdrop_path' => $localBackdrop,
            'tmdb_id' => $tmdbId,
            'access_level' => $accessLevel
        ];
        if ($existing) {
            $mediaId = $existing['id'];
            DB::update('media', $metadata, 'id = ?', [$mediaId]);
        } else {
            $metadata['type'] = 'movie';
            $mediaId = DB::insert('media', $metadata);
        }

        if (isset($details['credits']['cast'])) {
            DB::delete('cast_members', 'media_id = ?', [$mediaId]);
            foreach ($details['credits']['cast'] as $cast) {
                $actorPic = $this->downloadAndCacheImage($cast['profile_path'] ?? '', 'actors');
                DB::insert('cast_members', [
                    'media_id' => $mediaId,
                    'name' => $cast['name'],
                    'character_name' => $cast['character'] ?? '',
                    'profile_path' => $actorPic
                ]);
            }
        }
        $this->saveCrew($mediaId, $details['credits']['crew'] ?? []);

        return $mediaId;
    }

    public function importTVShowMetadata($tmdbId, $accessLevel = 'public', $mediaId = null) {
        ensure_credits_schema();
        $details = $this->getTVDetails($tmdbId);
        if (isset($details['error']) || !isset($details['name'])) {
            return false;
        }

        $title = $details['name'];
        $origTitle = $details['original_name'] ?? $title;
        $plot = $details['overview'] ?? '';
        $releaseYear = !empty($details['first_air_date']) ? (int)substr($details['first_air_date'], 0, 4) : 0;
        $rating = isset($details['vote_average']) ? round($details['vote_average'], 1) : 0.0;
        $genres = implode(', ', array_column($details['genres'] ?? [], 'name'));
        $countries = implode(', ', $details['origin_country'] ?? []);

        // Download images locally
        $localPoster = $this->downloadAndCacheImage($details['poster_path'] ?? '', 'covers');
        $localBackdrop = $this->downloadAndCacheImage($details['backdrop_path'] ?? '', 'backdrops');

        // Check if existing record
        $existing = $mediaId
            ? DB::fetch("SELECT id FROM {prefix}media WHERE id = ? AND type = 'tv'", [$mediaId])
            : DB::fetch("SELECT id FROM {prefix}media WHERE tmdb_id = ? AND type = 'tv'", [$tmdbId]);
        $metadata = [
            'title' => $title,
            'original_title' => $origTitle,
            'plot' => $plot,
            'tagline' => $details['tagline'] ?? '',
            'genres' => $genres,
            'runtime' => (int)($details['episode_run_time'][0] ?? 0),
            'original_language' => $details['original_language'] ?? '',
            'production_countries' => $countries,
            'release_date' => $details['first_air_date'] ?? '',
            'status' => $details['status'] ?? '',
            'imdb_id' => $details['external_ids']['imdb_id'] ?? '',
            'release_year' => $releaseYear,
            'rating' => $rating,
            'poster_path' => $localPoster,
            'backdrop_path' => $localBackdrop,
            'tmdb_id' => $tmdbId,
            'access_level' => $accessLevel
        ];
        if ($existing) {
            $mediaId = $existing['id'];
            DB::update('media', $metadata, 'id = ?', [$mediaId]);
        } else {
            $metadata['type'] = 'tv';
            $mediaId = DB::insert('media', $metadata);
        }

        // Import Cast Members
        if (isset($details['credits']['cast'])) {
            DB::delete('cast_members', 'media_id = ?', [$mediaId]);
            foreach ($details['credits']['cast'] as $cast) {
                $actorPic = $this->downloadAndCacheImage($cast['profile_path'] ?? '', 'actors');
                DB::insert('cast_members', [
                    'media_id' => $mediaId,
                    'name' => $cast['name'],
                    'character_name' => $cast['character'] ?? '',
                    'profile_path' => $actorPic
                ]);
            }
        }
        $crew = $details['credits']['crew'] ?? [];
        foreach ($details['created_by'] ?? [] as $creator) {
            $crew[] = [
                'id' => $creator['id'] ?? null,
                'name' => $creator['name'] ?? '',
                'department' => 'Production',
                'job' => 'Creator',
                'profile_path' => $creator['profile_path'] ?? ''
            ];
        }
        $this->saveCrew($mediaId, $crew);

        return $mediaId;
    }

    private function saveCrew($mediaId, $crew) {
        $knownJobs = [
            'Director', 'Producer', 'Executive Producer', 'Co-Producer',
            'Writer', 'Screenplay', 'Story', 'Creator', 'Composer',
            'Director of Photography', 'Cinematography', 'Editor'
        ];
        $selected = [];
        foreach ($crew as $member) {
            $job = trim($member['job'] ?? '');
            $name = trim($member['name'] ?? '');
            if ($name === '' || !in_array($job, $knownJobs, true)) {
                continue;
            }
            $key = strtolower($name . '|' . $job);
            $selected[$key] = $member;
        }

        DB::delete('media_crew', 'media_id = ?', [$mediaId]);
        foreach ($selected as $member) {
            DB::insert('media_crew', [
                'media_id' => $mediaId,
                'name' => trim($member['name']),
                'department' => trim($member['department'] ?? ''),
                'job' => trim($member['job']),
                'profile_path' => $this->downloadAndCacheImage($member['profile_path'] ?? '', 'actors')
            ]);
        }
    }
}
