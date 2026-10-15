<?php
// HTML5 Streaming with HTTP 206 Partial Content Support

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

ensure_video_compatibility_schema();

$mediaId = (int)($_GET['id'] ?? 0);
$episodeId = (int)($_GET['ep'] ?? 0);
$episode = null;
$media = null;
$episodes = [];

if ($episodeId) {
    $episode = DB::fetch("SELECT * FROM {prefix}episodes WHERE id = ?", [$episodeId]);
    if (!$episode) {
        http_response_code(404);
        die("Episode not found.");
    }
    $mediaId = (int)$episode['media_id'];
}

if ($mediaId > 0) {
    $media = DB::fetch("SELECT * FROM {prefix}media WHERE id = ?", [$mediaId]);
}
if (!$media) {
    http_response_code(404);
    die("Media not found.");
}

if ($media['type'] === 'tv') {
    $episodes = DB::fetchAll(
        "SELECT * FROM {prefix}episodes WHERE media_id = ? AND file_path != '' ORDER BY season_number ASC, episode_number ASC",
        [$mediaId]
    );
    $episodes = array_values(array_filter($episodes, function ($row) {
        return is_file($row['file_path']);
    }));
    if (!$episode && $episodes) {
        $episode = $episodes[0];
        $episodeId = (int)$episode['id'];
    }
}

if ($episode) {
    $sourceFilePath = $episode['file_path'];
    $compatibilityPath = $episode['compatibility_path'] ?? '';
    $filePath = $compatibilityPath !== '' && is_file($compatibilityPath) ? $compatibilityPath : $sourceFilePath;
    $episodeLabel = tr('الموسم', 'Season') . ' ' . sprintf('%02d', $episode['season_number'])
        . ' · ' . tr('الحلقة', 'Episode') . ' ' . sprintf('%02d', $episode['episode_number'])
        . ' - ' . $episode['title'];
    $title = $media['title'] . ' — ' . $episodeLabel;
} else {
    $videoFile = DB::fetch(
        "SELECT file_path, compatibility_path FROM {prefix}media_files WHERE media_id = ? AND file_type = 'video' ORDER BY id ASC LIMIT 1",
        [$mediaId]
    );
    $sourceFilePath = $videoFile['file_path'] ?? '';
    $compatibilityPath = $videoFile['compatibility_path'] ?? '';
    $filePath = $compatibilityPath !== '' && is_file($compatibilityPath) ? $compatibilityPath : $sourceFilePath;
    $title = $media['title'];
}
$accessLevel = $media['access_level'];

// Check access level permissions
if (!check_permission($accessLevel)) {
    http_response_code(403);
    die("Access Denied: You do not have permission to stream this media. Required rank: " . ucfirst($accessLevel));
}

// Ensure file exists
if ($sourceFilePath === '' || !is_file($sourceFilePath) || $filePath === '' || !is_file($filePath)) {
    http_response_code(404);
    die("Media file does not exist on server.");
}

// Check if this is a direct video stream request
if (isset($_GET['stream']) && $_GET['stream'] == '1') {
    stream_video_file($filePath);
    exit;
}

render_header(tr('مشاهدة: ', 'Watching: ') . $title);
$cast = DB::fetchAll("SELECT * FROM {prefix}cast_members WHERE media_id = ? ORDER BY id", [$mediaId]);
ensure_credits_schema();
$crew = DB::fetchAll(
    "SELECT * FROM {prefix}media_crew WHERE media_id = ? ORDER BY CASE job WHEN 'Director' THEN 0 WHEN 'Creator' THEN 1 WHEN 'Producer' THEN 2 WHEN 'Executive Producer' THEN 3 ELSE 4 END, name",
    [$mediaId]
);
$episodeGroups = [];
foreach ($episodes as $playlistEpisode) {
    $season = (int)$playlistEpisode['season_number'];
    $episodeGroups[$season][] = $playlistEpisode;
}
$downloadUrl = site_url('download.php?' . ($episodeId ? 'ep=' . $episodeId : 'id=' . $mediaId));
$streamKey = $episodeId ? 'ep=' . $episodeId : 'id=' . $mediaId;
$streamEndpoint = site_url('stream.php');
$streamUrl = $streamEndpoint . '?' . $streamKey . '&stream=1';
$videoRenditions = get_video_renditions($sourceFilePath);
$backUrl = $media['type'] === 'tv' ? site_url('show.php?id=' . $mediaId) : 'javascript:history.back()';
$mimeTypeForPath = function ($path) {
    $mimeTypes = [
        'mp4' => 'video/mp4',
        'm4v' => 'video/mp4',
        'webm' => 'video/webm',
        'ogv' => 'video/ogg',
        'mov' => 'video/quicktime',
        'mkv' => 'video/x-matroska',
        'avi' => 'video/x-msvideo'
    ];
    return $mimeTypes[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
};
$playbackMimeType = $mimeTypeForPath($filePath);
$playbackExtension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
?>

<div class="watch-page">
    <div class="watch-layout <?= $media['type'] === 'tv' ? 'has-episodes' : '' ?>">
        <section class="watch-main">
            <h1 id="watch-title" class="watch-title"><?= sanitize($title) ?></h1>
            <div class="player-stage">
                <div class="video-frame" id="video-frame">
                    <video id="media-player" controls playsinline preload="metadata" autoplay data-format="<?= sanitize($playbackExtension) ?>">
                        <source id="media-source" src="<?= sanitize($streamUrl) ?>" type="<?= sanitize($playbackMimeType) ?>">
                        <?= sanitize(tr('متصفحك لا يدعم تشغيل الفيديو.', 'Your browser does not support HTML5 video.')) ?>
                    </video>
                </div>
                <div class="player-switcher" id="player-switcher" aria-label="<?= sanitize(tr('اختر مشغل الفيديو', 'Choose a video player')) ?>">
                    <button type="button" class="player-engine-button is-active" data-engine="native" aria-pressed="true"><?= sanitize(tr('مشغل 1', 'Player 1')) ?></button>
                    <button type="button" class="player-engine-button" data-engine="plyr" aria-pressed="false"><?= sanitize(tr('مشغل 2', 'Player 2')) ?></button>
                    <button type="button" class="player-engine-button" data-engine="videojs" aria-pressed="false"><?= sanitize(tr('مشغل 3', 'Player 3')) ?></button>
                    <button type="button" class="player-engine-button" data-engine="mediaelement" aria-pressed="false"><?= sanitize(tr('مشغل 4', 'Player 4')) ?></button>
                    <button type="button" class="player-engine-button" data-engine="vidstack" aria-pressed="false"><?= sanitize(tr('مشغل 5', 'Player 5')) ?></button>
                    <button type="button" class="player-engine-button" data-engine="clappr" aria-pressed="false"><?= sanitize(tr('مشغل 6', 'Player 6')) ?></button>
                    <button type="button" class="player-engine-button" data-engine="fluidplayer" aria-pressed="false"><?= sanitize(tr('مشغل 7', 'Player 7')) ?></button>
                    <button type="button" class="player-engine-button" data-engine="shaka" aria-pressed="false"><?= sanitize(tr('مشغل 8', 'Player 8')) ?></button>
                </div>
            </div>
            <p id="player-status" class="player-status" aria-live="polite"><?= sanitize(tr('يعمل الآن مشغل المتصفح.', 'Browser player is active.')) ?></p>
            <div class="quality-control">
                <label for="video-quality"><?= sanitize(tr('جودة التشغيل', 'Playback quality')) ?></label>
                <select id="video-quality" class="form-control">
                    <?php if ($videoRenditions['master'] !== ''): ?>
                        <?php $adaptiveDownloadUrl = $videoRenditions['qualities']
                            ? $downloadUrl . '&quality=' . rawurlencode((string)array_key_last($videoRenditions['qualities']))
                            : $downloadUrl; ?>
                        <option value="auto" data-url="<?= sanitize($streamEndpoint . '?' . $streamKey . '&hls=master') ?>" data-mime="application/vnd.apple.mpegurl" data-format="m3u8" data-download="<?= sanitize($adaptiveDownloadUrl) ?>"><?= sanitize(tr('تلقائي - بث متكيف', 'Auto - adaptive streaming')) ?></option>
                    <?php endif; ?>
                    <?php foreach ($videoRenditions['qualities'] as $quality => $qualityPath): ?>
                        <option value="<?= sanitize($quality) ?>" data-url="<?= sanitize($streamEndpoint . '?' . $streamKey . '&stream=1&quality=' . rawurlencode($quality)) ?>" data-mime="video/mp4" data-format="mp4" data-download="<?= sanitize($downloadUrl . '&quality=' . rawurlencode($quality)) ?>"><?= sanitize($quality) ?></option>
                    <?php endforeach; ?>
                    <option value="source" data-url="<?= sanitize($streamUrl) ?>" data-mime="<?= sanitize($playbackMimeType) ?>" data-format="<?= sanitize($playbackExtension) ?>" data-download="<?= sanitize($downloadUrl) ?>" selected><?= sanitize(tr('الأصل / التوافقية', 'Original / compatibility copy')) ?></option>
                </select>
                <small class="form-help"><?= sanitize(tr('زر التحميل ينزّل الجودة المختارة؛ جودة الأصل تحفظ الملف المرفوع كما هو.', 'The download button saves the selected quality; Original downloads the uploaded file unchanged.')) ?></small>
            </div>
            <div class="watch-actions">
                <span class="badge badge-<?= sanitize($accessLevel) ?>"><?= sanitize(tr('وصول ', 'Access: ') . ucfirst($accessLevel)) ?></span>
                <span class="watch-file-size"><?= sanitize(format_size(filesize($sourceFilePath))) ?></span>
                <div class="watch-action-buttons">
                    <a id="download-link" href="<?= sanitize($downloadUrl) ?>" class="btn btn-success"><?= sanitize(tr('تحميل الجودة المختارة', 'Download selected quality')) ?></a>
                    <a href="<?= sanitize($backUrl) ?>" class="btn"><?= sanitize(tr('الرجوع', 'Back')) ?></a>
                </div>
            </div>
        </section>

        <?php if ($media['type'] === 'tv'): ?>
            <aside class="episode-sidebar" aria-label="<?= sanitize(tr('قائمة الحلقات', 'Episode list')) ?>">
                <h2><?= sanitize(tr('الحلقات', 'Episodes')) ?></h2>
                <?php if (!$episodeGroups): ?>
                    <p class="episode-empty"><?= sanitize(tr('لا توجد حلقات متاحة للمشاهدة حالياً.', 'No playable episodes are available yet.')) ?></p>
                <?php else: ?>
                    <?php foreach ($episodeGroups as $seasonNumber => $seasonEpisodes): ?>
                        <section class="episode-season">
                            <h3><?= sanitize(tr('الموسم', 'Season')) ?> <?= (int)$seasonNumber ?></h3>
                            <?php foreach ($seasonEpisodes as $playlistEpisode):
                                $playlistTitle = sprintf('S%02dE%02d', $playlistEpisode['season_number'], $playlistEpisode['episode_number']) . ' - ' . $playlistEpisode['title'];
                                $isCurrentEpisode = (int)$playlistEpisode['id'] === $episodeId;
                                $playlistPath = !empty($playlistEpisode['compatibility_path']) && is_file($playlistEpisode['compatibility_path'])
                                    ? $playlistEpisode['compatibility_path']
                                    : $playlistEpisode['file_path'];
                                $episodeRenditions = get_video_renditions($playlistEpisode['file_path']);
                            ?>
                                <button type="button" class="episode-choice<?= $isCurrentEpisode ? ' is-current' : '' ?>"
                                    data-stream="<?= sanitize($streamEndpoint . '?ep=' . $playlistEpisode['id'] . '&stream=1') ?>"
                                    data-mime="<?= sanitize($mimeTypeForPath($playlistPath)) ?>"
                                    data-format="<?= sanitize(strtolower(pathinfo($playlistPath, PATHINFO_EXTENSION))) ?>"
                                    data-download="<?= sanitize(site_url('download.php?ep=' . $playlistEpisode['id'])) ?>"
                                    data-stream-key="ep=<?= (int)$playlistEpisode['id'] ?>"
                                    data-qualities="<?= sanitize(implode(',', array_keys($episodeRenditions['qualities']))) ?>"
                                    data-has-hls="<?= $episodeRenditions['master'] !== '' ? '1' : '0' ?>"
                                    data-title="<?= sanitize($media['title'] . ' — ' . $playlistTitle) ?>"
                                    aria-current="<?= $isCurrentEpisode ? 'true' : 'false' ?>">
                                    <span><?= sanitize(sprintf('E%02d', $playlistEpisode['episode_number'])) ?></span>
                                    <span><?= sanitize($playlistEpisode['title'] ?: tr('حلقة بدون عنوان', 'Untitled episode')) ?></span>
                                </button>
                            <?php endforeach; ?>
                        </section>
                    <?php endforeach; ?>
                <?php endif; ?>
            </aside>
        <?php endif; ?>
    </div>

    <?php if (!empty($media['plot']) || !empty($media['tagline']) || !empty($media['genres']) || !empty($media['release_date']) || !empty($media['production_countries']) || !empty($media['rating'])): ?>
        <section class="media-detail-panel watch-info-panel">
            <h2><?= sanitize(tr('Overview — نظرة عامة', 'Overview')) ?></h2>
            <?php if (!empty($media['tagline'])): ?><p class="media-tagline"><?= sanitize($media['tagline']) ?></p><?php endif; ?>
            <?php if (!empty($media['plot'])): ?><p class="media-description"><?= nl2br(sanitize($media['plot'])) ?></p><?php endif; ?>
            <div class="media-facts">
                <?php if (!empty($media['genres'])): ?><span><?= sanitize($media['genres']) ?></span><?php endif; ?>
                <?php if (!empty($media['runtime'])): ?><span><?= (int)$media['runtime'] ?> <?= sanitize($media['type'] === 'tv' ? tr('دقيقة للحلقة', 'min per episode') : tr('دقيقة', 'min')) ?></span><?php endif; ?>
                <?php if (!empty($media['release_date'])): ?><span><?= sanitize($media['release_date']) ?></span><?php endif; ?>
                <?php if (!empty($media['production_countries'])): ?><span><?= sanitize($media['production_countries']) ?></span><?php endif; ?>
                <?php if (!empty($media['rating'])): ?><span>★ <?= sanitize((string)$media['rating']) ?>/10</span><?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($cast): ?>
        <section class="media-detail-panel watch-info-panel">
            <h2><?= sanitize(tr('طاقم التمثيل', 'Cast')) ?></h2>
            <div class="cast-grid">
                <?php foreach ($cast as $index => $actor): ?>
                    <article class="cast-card" data-expandable="<?= $index >= 12 ? 'true' : 'false' ?>" <?= $index >= 12 ? 'hidden' : '' ?>>
                        <?php if (!empty($actor['profile_path'])): ?>
                            <img src="<?= sanitize(site_url($actor['profile_path'])) ?>" alt="<?= sanitize($actor['name']) ?>" loading="lazy">
                        <?php else: ?>
                            <div class="cast-placeholder" aria-hidden="true"></div>
                        <?php endif; ?>
                        <strong><?= sanitize($actor['name']) ?></strong>
                        <?php if (!empty($actor['character_name'])): ?><small><?= sanitize($actor['character_name']) ?></small><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if (count($cast) > 12): ?>
                <button type="button" class="btn cast-more-button" id="cast-more" aria-expanded="false"><?= sanitize(tr('إظهار المزيد', 'Show more')) ?></button>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($crew): ?>
        <section class="media-detail-panel watch-info-panel">
            <h2><?= sanitize(tr('فريق العمل', 'Crew')) ?></h2>
            <div class="crew-grid">
                <?php foreach ($crew as $member): ?>
                    <article class="crew-card">
                        <?php if (!empty($member['profile_path'])): ?>
                            <img src="<?= sanitize(site_url($member['profile_path'])) ?>" alt="<?= sanitize($member['name']) ?>" loading="lazy">
                        <?php else: ?>
                            <div class="crew-placeholder" aria-hidden="true"></div>
                        <?php endif; ?>
                        <div><strong><?= sanitize($member['name']) ?></strong><span><?= sanitize($member['job']) ?></span></div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</div>
<script>
(function () {
    var frame = document.getElementById('video-frame');
    var player = document.getElementById('media-player');
    var playerTemplate = player.cloneNode(true);
    var playerStatus = document.getElementById('player-status');
    var currentTitle = document.getElementById('watch-title');
    var downloadLink = document.getElementById('download-link');
    var qualitySelect = document.getElementById('video-quality');
    var engineButtons = document.querySelectorAll('.player-engine-button');
    var activeEngine = 'native';
    var engineInstance = null;
    var engineVersion = 0;
    var selectedQuality = qualitySelect.options[qualitySelect.selectedIndex];
    var streamUrl = selectedQuality.getAttribute('data-url');
    var mimeType = selectedQuality.getAttribute('data-mime');
    var streamFormat = selectedQuality.getAttribute('data-format');
    var streamEndpoint = <?= json_encode($streamEndpoint) ?>;
    var downloadEndpoint = <?= json_encode(site_url('download.php')) ?>;
    var assetBase = <?= json_encode(site_url('assets/vendor/')) ?>;
    var scriptLoads = {};
    var styleLoads = {};

    function addEvent(element, name, callback) {
        if (element.addEventListener) {
            element.addEventListener(name, callback, false);
        } else if (element.attachEvent) {
            element.attachEvent('on' + name, callback);
        }
    }

    function showStatus(text) {
        if (typeof playerStatus.textContent !== 'undefined') {
            playerStatus.textContent = text;
        } else {
            playerStatus.innerText = text;
        }
    }

    function setActiveButton(engine) {
        for (var i = 0; i < engineButtons.length; i++) {
            var isActive = engineButtons[i].getAttribute('data-engine') === engine;
            engineButtons[i].className = engineButtons[i].className
                .replace(/\bis-active\b/g, '')
                .replace(/\s+/g, ' ')
                .replace(/^\s|\s$/g, '');
            if (isActive) {
                engineButtons[i].className += ' is-active';
                engineButtons[i].setAttribute('aria-pressed', 'true');
            } else {
                engineButtons[i].setAttribute('aria-pressed', 'false');
            }
        }
    }

    function loadStyle(id, url) {
        if (styleLoads[id]) {
            return styleLoads[id];
        }
        styleLoads[id] = new Promise(function (resolve, reject) {
            var existing = document.getElementById(id);
            if (existing) {
                resolve();
                return;
            }
            var link = document.createElement('link');
            link.id = id;
            link.rel = 'stylesheet';
            link.href = url;
            link.onload = resolve;
            link.onerror = function () {
                delete styleLoads[id];
                link.parentNode.removeChild(link);
                reject(new Error('Could not load local player stylesheet: ' + url));
            };
            document.getElementsByTagName('head')[0].appendChild(link);
        });
        return styleLoads[id];
    }

    function loadScript(id, url) {
        if (scriptLoads[id]) {
            return scriptLoads[id];
        }
        scriptLoads[id] = new Promise(function (resolve, reject) {
            var existing = document.getElementById(id);
            if (existing) {
                resolve();
                return;
            }
            var script = document.createElement('script');
            script.id = id;
            script.src = url;
            script.onload = resolve;
            script.onerror = function () {
                delete scriptLoads[id];
                script.parentNode.removeChild(script);
                reject(new Error('Could not load local player asset: ' + url));
            };
            document.getElementsByTagName('head')[0].appendChild(script);
        });
        return scriptLoads[id];
    }

    function createVideo() {
        var video = playerTemplate.cloneNode(true);
        video.id = 'media-player';
        video.controls = true;
        var source = video.getElementsByTagName('source')[0];
        if (source) {
            source.setAttribute('src', streamUrl);
            source.setAttribute('type', mimeType);
        }
        video.setAttribute('data-format', streamFormat);
        while (frame.firstChild) {
            frame.removeChild(frame.firstChild);
        }
        frame.appendChild(video);
        player = video;
        return video;
    }

    function destroyEngine() {
        if (!engineInstance) {
            return Promise.resolve();
        }
        var instance = engineInstance;
        engineInstance = null;
        try {
            if (activeEngine === 'videojs' && instance.dispose) {
                instance.dispose();
            } else if (activeEngine === 'plyr' && instance.destroy) {
                instance.destroy();
            } else if (activeEngine === 'mediaelement' && instance.remove) {
                instance.remove();
            } else if (activeEngine === 'clappr' && instance.destroy) {
                instance.destroy();
            } else if (activeEngine === 'fluidplayer' && instance.destroy) {
                instance.destroy();
            } else if (activeEngine === 'shaka' && instance.destroy) {
                return instance.destroy();
            }
        } catch (error) {
            showStatus(<?= json_encode(tr('تعذر إغلاق المشغل السابق بشكل طبيعي.', 'The previous player could not be disposed cleanly.'), JSON_UNESCAPED_UNICODE) ?>);
        }
        return Promise.resolve();
    }

    function playWithNative(video) {
        var result = video.play();
        if (result && result.catch) {
            result.catch(function () {});
        }
    }

    function handlePlayerError(video, engineName) {
        addEvent(video, 'error', function () {
            if (activeEngine === engineName) {
                showStatus(<?= json_encode(tr('حدث خطأ أثناء التشغيل. تحقق من صيغة الفيديو أو جرّب مشغلاً آخر.', 'Playback failed. Check the video format or try another player.'), JSON_UNESCAPED_UNICODE) ?>);
            }
        });
    }

    function initializeEngine(engine, video, version) {
        if (engine === 'native') {
            video.controls = true;
            handlePlayerError(video, 'native');
            video.load();
            showStatus(<?= json_encode(tr('يعمل الآن مشغل المتصفح.', 'Browser player is active.'), JSON_UNESCAPED_UNICODE) ?>);
            return Promise.resolve();
        }
        if (streamFormat === 'm3u8' && ['videojs', 'clappr', 'shaka'].indexOf(engine) === -1) {
            return Promise.reject(new Error(<?= json_encode(tr('البث المتكيف HLS يعمل مع مشغل المتصفح المتوافق أو Video.js أو Clappr أو Shaka.', 'Adaptive HLS requires a compatible browser player, Video.js, Clappr, or Shaka.'), JSON_UNESCAPED_UNICODE) ?>));
        }
        video.controls = false;
        handlePlayerError(video, engine);

        var script = '';
        var style = '';
        if (engine === 'plyr') {
            script = 'plyr/plyr.min.js';
            style = 'plyr/plyr.css';
        } else if (engine === 'videojs') {
            script = 'videojs/video.min.js';
            style = 'videojs/video-js.min.css';
            video.className += ' video-js vjs-big-play-centered';
        } else if (engine === 'mediaelement') {
            script = 'mediaelement/mediaelement-and-player.min.js';
            style = 'mediaelement/mediaelementplayer.min.css';
        } else if (engine === 'vidstack') {
            script = 'vidstack/vidstack.js';
        } else if (engine === 'clappr') {
            script = 'clappr/clappr.min.js';
        } else if (engine === 'fluidplayer') {
            script = 'fluidplayer/fluid-player.js';
            style = 'fluidplayer/fluid-player.css';
        }
        showStatus(<?= json_encode(tr('جارٍ تحميل المشغل المحلي...', 'Loading local player...'), JSON_UNESCAPED_UNICODE) ?>);

        var assetLoads = [loadScript('player-script-' + engine, assetBase + script)];
        if (style) {
            assetLoads.push(loadStyle('player-style-' + engine, assetBase + style));
        }
        return Promise.all(assetLoads).then(function () {
            if (version !== engineVersion) {
                return;
            }
            activeEngine = engine;
            if (engine === 'plyr') {
                if (!window.Plyr) throw new Error('Plyr did not initialize.');
                engineInstance = new window.Plyr(video, { autoplay: false, hideControls: false });
            } else if (engine === 'videojs') {
                if (!window.videojs) throw new Error('Video.js did not initialize.');
                engineInstance = window.videojs(video, {
                    controls: true,
                    fluid: true,
                    responsive: true,
                    preload: 'metadata',
                    autoplay: false,
                    sources: [{ src: streamUrl, type: mimeType }]
                });
            } else if (engine === 'mediaelement') {
                var MediaElementPlayer = window.MediaElementPlayer || (window.mejs && window.mejs.MediaElementPlayer);
                if (!MediaElementPlayer) throw new Error('MediaElement.js did not initialize.');
                engineInstance = new MediaElementPlayer(video, {
                    pluginPath: assetBase + 'mediaelement/',
                    iconSprite: assetBase + 'mediaelement/mejs-controls.svg',
                    features: ['playpause', 'current', 'progress', 'duration', 'volume', 'fullscreen'],
                    stretching: 'responsive',
                    success: function () {}
                });
            } else if (engine === 'vidstack') {
                if (!window.customElements || !window.CinemaVidstackReady) {
                    throw new Error('Vidstack requires a browser with Web Components support.');
                }
                var vidstackPlayer = document.createElement('vds-video-player');
                vidstackPlayer.setAttribute('src', streamUrl);
                vidstackPlayer.setAttribute('playsinline', '');
                vidstackPlayer.setAttribute('controls', '');
                frame.replaceChild(vidstackPlayer, video);
                player = vidstackPlayer;
                engineInstance = vidstackPlayer;
                addEvent(vidstackPlayer, 'error', function () {
                    if (activeEngine === 'vidstack') {
                        showStatus(<?= json_encode(tr('حدث خطأ أثناء تشغيل الفيديو في Vidstack.', 'Vidstack encountered an error while playing this video.'), JSON_UNESCAPED_UNICODE) ?>);
                    }
                });
                return window.customElements.whenDefined('vds-video-player').then(function () {
                    if (version === engineVersion && vidstackPlayer.mediaElement) {
                        vidstackPlayer.mediaElement.controls = true;
                    }
                });
            } else if (engine === 'clappr') {
                if (!window.Clappr || !window.Clappr.Player) throw new Error('Clappr did not initialize.');
                var clapprContainer = document.createElement('div');
                clapprContainer.id = 'clappr-player';
                clapprContainer.className = 'clappr-player-container';
                frame.replaceChild(clapprContainer, video);
                engineInstance = new window.Clappr.Player({
                    source: streamUrl,
                    mimeType: mimeType,
                    parentId: '#clappr-player',
                    width: '100%',
                    height: '100%',
                    autoPlay: false
                });
                player = clapprContainer;
                if (engineInstance.on && window.Clappr.Events && window.Clappr.Events.PLAYER_ERROR) {
                    engineInstance.on(window.Clappr.Events.PLAYER_ERROR, function () {
                        if (activeEngine === 'clappr') {
                            showStatus(<?= json_encode(tr('حدث خطأ أثناء تشغيل الفيديو في Clappr.', 'Clappr encountered an error while playing this video.'), JSON_UNESCAPED_UNICODE) ?>);
                        }
                    });
                }
            } else if (engine === 'fluidplayer') {
                if (!window.fluidPlayer) throw new Error('Fluid Player did not initialize.');
                engineInstance = window.fluidPlayer(video.id, {
                    layoutControls: {
                        fillToContainer: true,
                        autoPlay: false,
                        allowDownload: false,
                        controlBar: { autoHide: false }
                    }
                });
            } else if (engine === 'shaka') {
                throw new Error('Shaka Player only supports adaptive DASH/HLS streams. This item is a ' + streamFormat.toUpperCase() + ' file.');
            }
            if (version === engineVersion) {
                activeEngine = engine;
                setActiveButton(engine);
                if (engine !== 'vidstack' && engine !== 'clappr') {
                    player = video;
                }
                showStatus(<?= json_encode(tr('المشغل المحدد جاهز.', 'Selected player is ready.'), JSON_UNESCAPED_UNICODE) ?>);
            }
        });
    }

    function activateEngine(engine, preservePosition) {
        var resumeAt = 0;
        var resumePlayback = false;
        if (preservePosition) {
            var playbackElement = player;
            if (activeEngine === 'clappr' && engineInstance && engineInstance.getCurrentTime) {
                resumeAt = engineInstance.getCurrentTime() || 0;
                resumePlayback = engineInstance.isPlaying ? engineInstance.isPlaying() : true;
            } else if (playbackElement && typeof playbackElement.currentTime === 'number') {
                resumeAt = playbackElement.currentTime || 0;
                resumePlayback = playbackElement.paused === false;
            }
        }
        if (!window.Promise) {
            if (engine !== 'native') {
                showStatus(<?= json_encode(tr('هذا المتصفح لا يدعم تحميل المشغلات الإضافية؛ استخدم مشغل المتصفح.', 'This browser cannot load additional players; use the browser player.'), JSON_UNESCAPED_UNICODE) ?>);
                return;
            }
            activeEngine = 'native';
            var legacyVideo = createVideo();
            restorePosition(legacyVideo, resumeAt, resumePlayback);
            if (player.load) player.load();
            setActiveButton('native');
            showStatus(<?= json_encode(tr('يعمل الآن مشغل المتصفح.', 'Browser player is active.'), JSON_UNESCAPED_UNICODE) ?>);
            return;
        }
        var version = ++engineVersion;
        var cleanup = destroyEngine();
        cleanup.then(function () {
            if (version !== engineVersion) return;
            activeEngine = 'native';
            var video = createVideo();
            if (preservePosition && resumeAt > 0) {
                restorePosition(video, resumeAt, resumePlayback);
            }
            if (video.load) video.load();
            if (engine === 'shaka') {
                if (streamFormat !== 'mpd' && streamFormat !== 'm3u8') {
                    setActiveButton(activeEngine);
                    showStatus(<?= json_encode(tr('Shaka مخصص لبث DASH/HLS؛ هذا الملف لا يستخدم صيغة بث تكيفي.', 'Shaka is for DASH/HLS streams; this file is not an adaptive stream.'), JSON_UNESCAPED_UNICODE) ?>);
                    return;
                }
                loadScript('player-script-shaka', assetBase + 'shaka/shaka-player.compiled.js')
                    .then(function () {
                        if (version !== engineVersion) return;
                        if (!window.shaka || !window.shaka.Player.isBrowserSupported()) throw new Error('Shaka Player is not supported in this browser.');
                        engineInstance = new window.shaka.Player(video);
                        activeEngine = 'shaka';
                        return engineInstance.load(streamUrl);
                    })
                    .then(function () {
                        if (version !== engineVersion) return;
                        activeEngine = 'shaka';
                        setActiveButton(engine);
                        showStatus('Shaka Player');
                    })
                    .catch(function (error) {
                        if (version === engineVersion) {
                            destroyEngine().then(function () {
                                if (version !== engineVersion) return;
                                activeEngine = 'native';
                                setActiveButton('native');
                                if (streamFormat === 'm3u8') {
                                    restoreSourceQuality();
                                }
                                showStatus(error.message || <?= json_encode(tr('تعذر تشغيل Shaka Player.', 'Shaka Player could not play this stream.'), JSON_UNESCAPED_UNICODE) ?>);
                            });
                        }
                    });
                return;
            }
            initializeEngine(engine, video, version).catch(function (error) {
                if (version !== engineVersion) return;
                destroyEngine().then(function () {
                    if (version !== engineVersion) return;
                    activeEngine = 'native';
                    setActiveButton('native');
                    if (streamFormat === 'm3u8') {
                        restoreSourceQuality();
                    }
                    createVideo().load();
                    showStatus(error.message || <?= json_encode(tr('تعذر تحميل المشغل المطلوب.', 'Could not load the requested player.'), JSON_UNESCAPED_UNICODE) ?>);
                });
            });
        }).catch(function (error) {
            if (version === engineVersion) {
                showStatus(error.message || <?= json_encode(tr('تعذر إغلاق المشغل السابق.', 'Could not close the previous player.'), JSON_UNESCAPED_UNICODE) ?>);
            }
        });
    }

    function restorePosition(video, position, shouldPlay) {
        if (position <= 0) return;
        addEvent(video, 'loadedmetadata', function () {
            try {
                video.currentTime = position;
            } catch (error) {
                showStatus(<?= json_encode(tr('تعذر استعادة موضع التشغيل بعد تغيير الجودة.', 'Could not restore playback position after changing quality.'), JSON_UNESCAPED_UNICODE) ?>);
            }
            if (shouldPlay) playWithNative(video);
        });
    }

    function restoreSourceQuality() {
        var sourceOption = qualitySelect.querySelector('option[value="source"]');
        if (!sourceOption) return;
        qualitySelect.value = 'source';
        streamUrl = sourceOption.getAttribute('data-url');
        mimeType = sourceOption.getAttribute('data-mime');
        streamFormat = sourceOption.getAttribute('data-format');
        downloadLink.setAttribute('href', sourceOption.getAttribute('data-download'));
    }

    function appendQualityOption(value, text, url, type, format, download, selected) {
        var option = document.createElement('option');
        option.value = value;
        option.text = text;
        option.setAttribute('data-url', url);
        option.setAttribute('data-mime', type);
        option.setAttribute('data-format', format);
        option.setAttribute('data-download', download);
        if (selected) option.selected = true;
        qualitySelect.appendChild(option);
    }

    function populateEpisodeQualities(choice) {
        while (qualitySelect.firstChild) {
            qualitySelect.removeChild(qualitySelect.firstChild);
        }
        var key = choice.getAttribute('data-stream-key');
        var baseStream = streamEndpoint + '?' + key;
        var baseDownload = downloadEndpoint + '?' + key;
        var hasHls = choice.getAttribute('data-has-hls') === '1';
        var qualities = (choice.getAttribute('data-qualities') || '').split(',');
        var lastQuality = '';
        for (var qualityIndex = 0; qualityIndex < qualities.length; qualityIndex++) {
            if (/^[0-9]{3,4}p$/.test(qualities[qualityIndex])) {
                lastQuality = qualities[qualityIndex];
            }
        }
        if (hasHls) {
            appendQualityOption('auto', <?= json_encode(tr('تلقائي - بث متكيف', 'Auto - adaptive streaming'), JSON_UNESCAPED_UNICODE) ?>,
                baseStream + '&hls=master', 'application/vnd.apple.mpegurl', 'm3u8',
                lastQuality ? baseDownload + '&quality=' + encodeURIComponent(lastQuality) : baseDownload, false);
        }
        for (var index = 0; index < qualities.length; index++) {
            var quality = qualities[index];
            if (!/^[0-9]{3,4}p$/.test(quality)) continue;
            appendQualityOption(quality, quality, baseStream + '&stream=1&quality=' + encodeURIComponent(quality),
                'video/mp4', 'mp4', baseDownload + '&quality=' + encodeURIComponent(quality), false);
        }
        appendQualityOption('source', <?= json_encode(tr('الأصل / التوافقية', 'Original / compatibility copy'), JSON_UNESCAPED_UNICODE) ?>,
            choice.getAttribute('data-stream'), choice.getAttribute('data-mime'), choice.getAttribute('data-format'),
            choice.getAttribute('data-download'), true);
        updateSelectedQuality(false);
    }

    function updateSelectedQuality(preservePosition) {
        var option = qualitySelect.options[qualitySelect.selectedIndex];
        if (!option) return;
        streamUrl = option.getAttribute('data-url');
        mimeType = option.getAttribute('data-mime');
        streamFormat = option.getAttribute('data-format');
        downloadLink.setAttribute('href', option.getAttribute('data-download'));
        activateEngine(activeEngine, preservePosition);
    }

    addEvent(qualitySelect, 'change', function () {
        updateSelectedQuality(true);
    });

    for (var i = 0; i < engineButtons.length; i++) {
        (function (button) {
            addEvent(button, 'click', function () {
                activateEngine(button.getAttribute('data-engine'));
            });
        })(engineButtons[i]);
    }

    var episodeChoices = document.querySelectorAll('.episode-choice');
    for (var j = 0; j < episodeChoices.length; j++) {
        (function (choice) {
            addEvent(choice, 'click', function () {
                streamUrl = choice.getAttribute('data-stream');
                mimeType = choice.getAttribute('data-mime');
                streamFormat = choice.getAttribute('data-format');
                populateEpisodeQualities(choice);
                if (typeof currentTitle.textContent !== 'undefined') {
                    currentTitle.textContent = choice.getAttribute('data-title');
                } else {
                    currentTitle.innerText = choice.getAttribute('data-title');
                }
                var choices = document.querySelectorAll('.episode-choice');
                for (var k = 0; k < choices.length; k++) {
                    choices[k].className = choices[k].className.replace(/\bis-current\b/g, '').replace(/\s+/g, ' ').replace(/^\s|\s$/g, '');
                    choices[k].setAttribute('aria-current', 'false');
                }
                choice.className += ' is-current';
                choice.setAttribute('aria-current', 'true');
            });
        })(episodeChoices[j]);
    }

    var castButton = document.getElementById('cast-more');
    if (castButton) {
        addEvent(castButton, 'click', function () {
            var expanded = castButton.getAttribute('aria-expanded') === 'true';
            var hiddenActors = document.querySelectorAll('.cast-card[data-expandable="true"]');
            for (var actorIndex = 0; actorIndex < hiddenActors.length; actorIndex++) {
                hiddenActors[actorIndex].hidden = expanded;
            }
            castButton.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            castButton.innerHTML = expanded
                ? <?= json_encode(tr('إظهار المزيد', 'Show more'), JSON_UNESCAPED_UNICODE) ?>
                : <?= json_encode(tr('إظهار أقل', 'Show less'), JSON_UNESCAPED_UNICODE) ?>;
        });
    }
})();
</script>

<?php
render_footer();

function stream_video_file($filePath) {
    $fileSize = filesize($filePath);
    $file = fopen($filePath, 'rb');
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    $mimeTypes = [
        'mp4' => 'video/mp4',
        'mkv' => 'video/x-matroska',
        'avi' => 'video/x-msvideo',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime'
    ];
    $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';

    $length = $fileSize;
    $start = 0;
    $end = $fileSize - 1;

    header("Content-Type: " . $contentType);
    header("Accept-Ranges: bytes");

    if (isset($_SERVER['HTTP_RANGE'])) {
        $c_start = $start;
        $c_end = $end;

        list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
        if (strpos($range, ',') !== false) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            header("Content-Range: bytes $start-$end/$fileSize");
            exit;
        }

        if ($range === '-') {
            $c_start = $fileSize - substr($range, 1);
        } else {
            $range = explode('-', $range);
            $c_start = $range[0];
            $c_end = (isset($range[1]) && is_numeric($range[1])) ? $range[1] : $fileSize - 1;
        }

        $c_end = ($c_end > $fileSize - 1) ? $fileSize - 1 : $c_end;
        if ($c_start > $c_end || $c_start > $fileSize - 1 || $c_end >= $fileSize) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            header("Content-Range: bytes $start-$end/$fileSize");
            exit;
        }

        $start = $c_start;
        $end = $c_end;
        $length = $end - $start + 1;

        fseek($file, $start);
        header('HTTP/1.1 206 Partial Content');
        header("Content-Length: " . $length);
        header("Content-Range: bytes $start-$end/$fileSize");
    } else {
        header("Content-Length: " . $fileSize);
    }

    $buffer = 1024 * 64; // 64KB
    while (!feof($file) && ($p = ftell($file)) <= $end) {
        if ($p + $buffer > $end) {
            $buffer = $end - $p + 1;
        }
        set_time_limit(0);
        echo fread($file, $buffer);
        flush();
    }
    fclose($file);
    exit;
}
