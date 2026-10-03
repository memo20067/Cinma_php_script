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

        $params['api_key'] = $this->apiKey;
        $url = $this->baseUrl . $endpoint . '?' . http_build_query($params);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return ['error' => "TMDB API request failed with code {$httpCode}"];
        }

        return json_decode($response, true);
    }

    public function searchMovie($query, $year = null) {
        $params = ['query' => $query];
        if ($year) $params['year'] = $year;
        return $this->request('/search/movie', $params);
    }

    public function searchTV($query, $year = null) {
        $params = ['query' => $query];
        if ($year) $params['first_air_date_year'] = $year;
        return $this->request('/search/tv', $params);
    }

    public function getMovieDetails($tmdbId) {
        return $this->request("/movie/{$tmdbId}", ['append_to_response' => 'credits']);
    }

    public function getTVDetails($tmdbId) {
        return $this->request("/tv/{$tmdbId}", ['append_to_response' => 'credits']);
    }

    public function getTVSeasonDetails($tmdbId, $seasonNumber) {
        return $this->request("/tv/{$tmdbId}/season/{$seasonNumber}");
    }

    public function downloadAndCacheImage($remotePath, $type = 'covers') {
        if (empty($remotePath)) return '';

        // If it's already a local path, return as is
        if (strpos($remotePath, 'http') !== 0 && strpos($remotePath, '/') === 0) {
            return $remotePath;
        }

        $fullRemoteUrl = $remotePath;
        if (strpos($remotePath, 'http') !== 0) {
            $base = ($type === 'backdrops') ? $this->backdropBaseUrl : $this->imageBaseUrl;
            $fullRemoteUrl = $base . $remotePath;
        }

        $ext = pathinfo(parse_url($fullRemoteUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
        $filename = md5($fullRemoteUrl) . '.' . $ext;
        $targetDir = __DIR__ . '/../uploads/' . $type . '/';

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $localFilePath = $targetDir . $filename;
        $publicUrl = '/uploads/' . $type . '/' . $filename;

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
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && !empty($data)) {
            file_put_contents($localFilePath, $data);
            return $publicUrl;
        }

        return '';
    }

    public function importMovieMetadata($tmdbId, $accessLevel = 'public') {
        $details = $this->getMovieDetails($tmdbId);
        if (isset($details['error']) || !isset($details['title'])) {
            return false;
        }

        $title = $details['title'];
        $origTitle = $details['original_title'] ?? $title;
        $plot = $details['overview'] ?? '';
        $releaseYear = !empty($details['release_date']) ? (int)substr($details['release_date'], 0, 4) : 0;
        $rating = isset($details['vote_average']) ? round($details['vote_average'], 1) : 0.0;

        // Download images locally
        $localPoster = $this->downloadAndCacheImage($details['poster_path'] ?? '', 'covers');
        $localBackdrop = $this->downloadAndCacheImage($details['backdrop_path'] ?? '', 'backdrops');

        // Check if existing record
        $existing = DB::fetch("SELECT id FROM {prefix}media WHERE tmdb_id = ? AND type = 'movie'", [$tmdbId]);
        if ($existing) {
            $mediaId = $existing['id'];
            DB::update('media', [
                'title' => $title,
                'original_title' => $origTitle,
                'plot' => $plot,
                'release_year' => $releaseYear,
                'rating' => $rating,
                'poster_path' => $localPoster,
                'backdrop_path' => $localBackdrop,
                'access_level' => $accessLevel
            ], 'id = ?', [$mediaId]);
        } else {
            $mediaId = DB::insert('media', [
                'title' => $title,
                'original_title' => $origTitle,
                'type' => 'movie',
                'plot' => $plot,
                'release_year' => $releaseYear,
                'rating' => $rating,
                'poster_path' => $localPoster,
                'backdrop_path' => $localBackdrop,
                'tmdb_id' => $tmdbId,
                'access_level' => $accessLevel
            ]);
        }

        // Import Cast Members
        if (isset($details['credits']['cast'])) {
            DB::delete('cast_members', 'media_id = ?', [$mediaId]);
            $topCast = array_slice($details['credits']['cast'], 0, 10);
            foreach ($topCast as $cast) {
                $actorPic = $this->downloadAndCacheImage($cast['profile_path'] ?? '', 'actors');
                DB::insert('cast_members', [
                    'media_id' => $mediaId,
                    'name' => $cast['name'],
                    'character_name' => $cast['character'] ?? '',
                    'profile_path' => $actorPic
                ]);
            }
        }

        return $mediaId;
    }

    public function importTVShowMetadata($tmdbId, $accessLevel = 'public') {
        $details = $this->getTVDetails($tmdbId);
        if (isset($details['error']) || !isset($details['name'])) {
            return false;
        }

        $title = $details['name'];
        $origTitle = $details['original_name'] ?? $title;
        $plot = $details['overview'] ?? '';
        $releaseYear = !empty($details['first_air_date']) ? (int)substr($details['first_air_date'], 0, 4) : 0;
        $rating = isset($details['vote_average']) ? round($details['vote_average'], 1) : 0.0;

        // Download images locally
        $localPoster = $this->downloadAndCacheImage($details['poster_path'] ?? '', 'covers');
        $localBackdrop = $this->downloadAndCacheImage($details['backdrop_path'] ?? '', 'backdrops');

        // Check if existing record
        $existing = DB::fetch("SELECT id FROM {prefix}media WHERE tmdb_id = ? AND type = 'tv'", [$tmdbId]);
        if ($existing) {
            $mediaId = $existing['id'];
            DB::update('media', [
                'title' => $title,
                'original_title' => $origTitle,
                'plot' => $plot,
                'release_year' => $releaseYear,
                'rating' => $rating,
                'poster_path' => $localPoster,
                'backdrop_path' => $localBackdrop,
                'access_level' => $accessLevel
            ], 'id = ?', [$mediaId]);
        } else {
            $mediaId = DB::insert('media', [
                'title' => $title,
                'original_title' => $origTitle,
                'type' => 'tv',
                'plot' => $plot,
                'release_year' => $releaseYear,
                'rating' => $rating,
                'poster_path' => $localPoster,
                'backdrop_path' => $localBackdrop,
                'tmdb_id' => $tmdbId,
                'access_level' => $accessLevel
            ]);
        }

        // Import Cast Members
        if (isset($details['credits']['cast'])) {
            DB::delete('cast_members', 'media_id = ?', [$mediaId]);
            $topCast = array_slice($details['credits']['cast'], 0, 10);
            foreach ($topCast as $cast) {
                $actorPic = $this->downloadAndCacheImage($cast['profile_path'] ?? '', 'actors');
                DB::insert('cast_members', [
                    'media_id' => $mediaId,
                    'name' => $cast['name'],
                    'character_name' => $cast['character'] ?? '',
                    'profile_path' => $actorPic
                ]);
            }
        }

        return $mediaId;
    }
}
