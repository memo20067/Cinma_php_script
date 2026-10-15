<?php

require_once __DIR__ . '/includes/iptv.php';
ensure_iptv_schema();

$sourceId = isset($_GET['source']) && !is_array($_GET['source']) ? (int)$_GET['source'] : 0;
$source = DB::fetch(
    'SELECT id, name, source_type, last_synced_at FROM {prefix}iptv_sources WHERE id = ? AND enabled = 1',
    [$sourceId]
);
if (!$source) {
    http_response_code(404);
    render_header(tr('المصدر غير موجود', 'Source not found'));
    echo '<div class="alert alert-info">' . sanitize(tr('مصدر البث غير موجود أو غير مفعل.', 'The streaming source was not found or is disabled.')) . '</div>';
    render_footer();
    exit;
}

$refreshError = '';
try {
    iptv_ensure_fresh($sourceId);
} catch (Throwable $exception) {
    $refreshError = $exception->getMessage();
}

$itemId = (int)($_GET['item'] ?? 0);
$item = $itemId ? iptv_fetch_item($sourceId, $itemId) : false;
$pageTitle = $source['name'];
$guide = [];
$guideError = '';
$itemDetails = [];
$detailsError = '';
$episodes = [];
$liveChannels = [];
$seriesForEpisode = false;
if ($item) {
    $pageTitle = $item['title'];
    if ($item['item_type'] === 'series') {
        try {
            $itemDetails = iptv_get_item_details($item);
            $episodes = iptv_load_series_episodes($item);
        } catch (Throwable $exception) {
            $detailsError = $exception->getMessage();
        }
    } elseif ($item['item_type'] === 'episode') {
        $seriesForEpisode = DB::fetch(
            'SELECT * FROM {prefix}iptv_items
             WHERE source_id = ? AND item_type = \'series\' AND external_id = ? LIMIT 1',
            [$sourceId, $item['parent_external_id']]
        );
        if ($seriesForEpisode) {
            $seriesForEpisode = iptv_fetch_item($sourceId, (int)$seriesForEpisode['id']);
            try {
                $itemDetails = iptv_get_item_details($seriesForEpisode);
                $episodes = iptv_load_series_episodes($seriesForEpisode);
            } catch (Throwable $exception) {
                $detailsError = $exception->getMessage();
            }
        } else {
            $itemDetails = [
                'description' => (string)$item['description'],
                'cast' => [],
                'crew' => [],
                'genres' => '',
                'runtime' => 0,
                'release_date' => '',
                'rating' => ''
            ];
        }
    } elseif ($item['item_type'] === 'movie') {
        try {
            $itemDetails = iptv_get_item_details($item);
        } catch (Throwable $exception) {
            $detailsError = $exception->getMessage();
        }
    } elseif ($item['item_type'] === 'live') {
        $liveChannels = DB::fetchAll(
            'SELECT id, title, poster_url, category_name FROM {prefix}iptv_items
             WHERE source_id = ? AND item_type = \'live\' AND category_name = ?
             ORDER BY CASE WHEN id = ? THEN 0 ELSE 1 END, title LIMIT 100',
            [$sourceId, (string)$item['category_name'], (int)$item['id']]
        );
        try {
            $guide = iptv_get_program_guide($item);
        } catch (Throwable $exception) {
            $guideError = $exception->getMessage();
        }
    }
}

render_header($pageTitle);
?>

<?php if ($refreshError !== ''): ?>
    <div class="alert alert-error"><?= sanitize($refreshError) ?></div>
<?php endif; ?>
<?php if ($detailsError !== ''): ?>
    <div class="alert alert-error"><?= sanitize($detailsError) ?></div>
<?php endif; ?>

<?php if (!$item): ?>
    <?php
    $type = isset($_GET['type']) && !is_array($_GET['type']) ? $_GET['type'] : '';
    $validTypes = ['live', 'movie', 'series'];
    if (!in_array($type, $validTypes, true)) {
        $type = 'live';
    }
    $category = isset($_GET['category']) && is_string($_GET['category']) ? trim($_GET['category']) : '';
    $perPage = 20;
    $categories = iptv_fetch_categories($sourceId, $type);
    $params = [$sourceId, $type];
    $filter = ' FROM {prefix}iptv_items WHERE source_id = ? AND item_type = ?';
    if ($category !== '') {
        $filter .= ' AND category_name = ?';
        $params[] = $category;
    }
    $items = DB::fetchAll(
        'SELECT *' . $filter . ' ORDER BY category_name, title LIMIT ' . $perPage,
        $params
    );
    $typeLabels = [
        'live' => tr('البث المباشر', 'Live TV'),
        'movie' => tr('الأفلام', 'Movies'),
        'series' => tr('المسلسلات', 'Series')
    ];
    ?>
    <div class="iptv-browse-toolbar">
        <nav class="iptv-type-tabs" aria-label="<?= sanitize(tr('أنواع المحتوى', 'Content types')) ?>">
            <?php foreach ($typeLabels as $key => $label): ?>
                <a class="btn <?= $type === $key ? 'btn-primary' : '' ?>" href="<?= sanitize(site_url('iptv.php?source=' . $sourceId . '&type=' . $key)) ?>"><?= sanitize($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="iptv-browse-tools">
            <label class="iptv-category-select">
                <span class="sr-only"><?= sanitize(tr('اختر التصنيف', 'Choose a category')) ?></span>
                <select id="iptv-category" aria-label="<?= sanitize(tr('التصنيف', 'Category')) ?>">
                    <option value=""><?= sanitize(tr('كل التصنيفات', 'All categories')) ?></option>
                    <?php foreach ($categories as $entry): ?>
                        <option value="<?= sanitize($entry['category_name']) ?>" <?= $category === $entry['category_name'] ? 'selected' : '' ?>>
                            <?= sanitize($entry['category_name'] !== '' ? $entry['category_name'] : tr('غير مصنف', 'Uncategorized')) ?>
                            (<?= (int)$entry['item_count'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="iptv-inline-search" id="iptv-search-control">
                <button class="iptv-search-toggle" id="iptv-search-toggle" type="button" aria-label="<?= sanitize(tr('فتح البحث', 'Open search')) ?>" aria-expanded="false">
                    <span aria-hidden="true"></span>
                </button>
                <input id="iptv-search" type="search" placeholder="<?= sanitize(tr('ابحث في المعروض', 'Search displayed items')) ?>" aria-label="<?= sanitize(tr('ابحث في المحتوى', 'Search content')) ?>" autocomplete="off">
            </div>
        </div>
    </div>
    <div class="grid iptv-grid" id="iptv-grid" aria-live="polite">
        <?php foreach ($items as $entry): ?>
            <?php $titleLength = function_exists('mb_strlen') ? mb_strlen($entry['title'], 'UTF-8') : strlen($entry['title']); ?>
            <a class="iptv-card <?= in_array($type, ['movie', 'series'], true) ? 'iptv-card--poster' : '' ?>" href="<?= sanitize(site_url('iptv.php?source=' . $sourceId . '&item=' . (int)$entry['id'])) ?>">
                <span class="iptv-card-artwork">
                    <?php if ($entry['poster_url'] !== ''): ?>
                        <img src="<?= sanitize(site_url('iptv_image.php?source=' . $sourceId . '&item=' . (int)$entry['id'])) ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <span class="iptv-card-placeholder" aria-hidden="true"><?= $type === 'live' ? '&#9654;' : '&#127916;' ?></span>
                    <?php endif; ?>
                    <span class="iptv-card-overlay <?= $titleLength > 38 ? 'title-long' : ($titleLength > 20 ? 'title-medium' : 'title-short') ?>"><?= sanitize($entry['title']) ?></span>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
    <div class="iptv-load-status" id="iptv-load-status" role="status" aria-live="polite"><?= !$items ? sanitize(tr('لا توجد عناصر مخزنة لهذا القسم بعد.', 'There are no cached items in this section yet.')) : '' ?></div>
    <div class="iptv-load-sentinel" id="iptv-load-sentinel" aria-hidden="true"></div>
    <script>
    (function () {
        var grid = document.getElementById('iptv-grid');
        var category = document.getElementById('iptv-category');
        var search = document.getElementById('iptv-search');
        var searchControl = document.getElementById('iptv-search-control');
        var searchToggle = document.getElementById('iptv-search-toggle');
        var status = document.getElementById('iptv-load-status');
        var sentinel = document.getElementById('iptv-load-sentinel');
        var sourceId = <?= $sourceId ?>;
        var type = <?= json_encode($type) ?>;
        var page = 1;
        var loading = false;
        var hasMore = <?= count($items) === $perPage ? 'true' : 'false' ?>;
        var requestId = 0;
        var debounce;

        function appendCard(entry) {
            var card = document.createElement('a');
            card.className = 'iptv-card' + (type === 'movie' || type === 'series' ? ' iptv-card--poster' : '');
            card.href = '<?= sanitize(site_url('iptv.php')) ?>?source=' + sourceId + '&item=' + encodeURIComponent(entry.id);
            var artwork = document.createElement('span');
            artwork.className = 'iptv-card-artwork';
            if (entry.poster_url) {
                var image = document.createElement('img');
                image.src = '<?= sanitize(site_url('iptv_image.php')) ?>?source=' + sourceId + '&item=' + encodeURIComponent(entry.id);
                image.alt = '';
                image.loading = 'lazy';
                artwork.appendChild(image);
            } else {
                var placeholder = document.createElement('span');
                placeholder.className = 'iptv-card-placeholder';
                placeholder.setAttribute('aria-hidden', 'true');
                placeholder.textContent = type === 'live' ? '▶' : '🎬';
                artwork.appendChild(placeholder);
            }
            var title = document.createElement('span');
            var titleLength = Array.from(entry.title).length;
            title.className = 'iptv-card-overlay ' + (titleLength > 38 ? 'title-long' : (titleLength > 20 ? 'title-medium' : 'title-short'));
            title.textContent = entry.title;
            artwork.appendChild(title);
            card.appendChild(artwork);
            grid.appendChild(card);
        }

        function loadPage(reset) {
            if ((loading && !reset) || (!hasMore && !reset)) {
                return;
            }
            if (reset) {
                loading = false;
            }
            loading = true;
            var currentRequest = ++requestId;
            if (reset) {
                page = 0;
                hasMore = true;
                grid.replaceChildren();
            }
            status.textContent = '<?= sanitize(tr('جارٍ تحميل المحتوى…', 'Loading content…')) ?>';
            var query = new URLSearchParams({
                source: String(sourceId),
                type: type,
                category: category.value,
                q: search.value.trim(),
                page: String(page + 1)
            });
            fetch('<?= sanitize(site_url('iptv_items.php')) ?>?' + query.toString(), {
                headers: { Accept: 'application/json' }
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            }).then(function (data) {
                if (currentRequest !== requestId) {
                    return;
                }
                data.items.forEach(appendCard);
                page = data.page;
                hasMore = data.has_more;
                status.textContent = data.items.length === 0 && page === 1
                    ? '<?= sanitize(tr('لا توجد نتائج مطابقة.', 'No matching items.')) ?>'
                    : (hasMore ? '' : '<?= sanitize(tr('تم عرض جميع العناصر.', 'All items are displayed.')) ?>');
            }).catch(function () {
                if (currentRequest === requestId) {
                    status.textContent = '<?= sanitize(tr('تعذر تحميل المحتوى. أعد المحاولة بالتمرير لأسفل.', 'Could not load content. Scroll down to try again.')) ?>';
                    hasMore = true;
                }
            }).finally(function () {
                if (currentRequest === requestId) {
                    loading = false;
                }
            });
        }

        searchToggle.addEventListener('click', function () {
            var expanded = searchControl.classList.toggle('is-open');
            searchToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            searchToggle.setAttribute('aria-label', expanded
                ? '<?= sanitize(tr('إغلاق البحث', 'Close search')) ?>'
                : '<?= sanitize(tr('فتح البحث', 'Open search')) ?>');
            if (expanded) {
                search.focus();
            } else if (search.value) {
                search.value = '';
                loadPage(true);
            }
        });
        category.addEventListener('change', function () {
            loadPage(true);
        });
        search.addEventListener('input', function () {
            window.clearTimeout(debounce);
            debounce = window.setTimeout(function () {
                loadPage(true);
            }, 250);
        });
        if ('IntersectionObserver' in window) {
            var observer = new IntersectionObserver(function (entries) {
                if (entries[0].isIntersecting) {
                    loadPage(false);
                }
            }, { rootMargin: '500px 0px' });
            observer.observe(sentinel);
        } else {
            window.addEventListener('scroll', function () {
                if (sentinel.getBoundingClientRect().top < window.innerHeight + 300) {
                    loadPage(false);
                }
            });
        }
    }());
    </script>
<?php else: ?>
    <?php if ($item['item_type'] === 'series'): ?>
        <header class="iptv-heading">
            <div>
                <p class="eyebrow"><?= sanitize($source['name']) ?> · <?= sanitize($item['category_name']) ?></p>
                <h1><?= sanitize($item['title']) ?></h1>
            </div>
            <a class="btn" href="<?= sanitize(site_url('iptv.php?source=' . $sourceId . '&type=series')) ?>"><?= sanitize(tr('العودة للمسلسلات', 'Back to series')) ?></a>
        </header>
        <?php if ($item['poster_url'] !== ''): ?><img class="iptv-series-poster" src="<?= sanitize(site_url('iptv_image.php?source=' . $sourceId . '&item=' . (int)$item['id'])) ?>" alt="" loading="lazy"><?php endif; ?>
        <?php
        $displayDescription = $itemDetails['description'] ?? $item['description'];
        $hasWorkDetails = in_array($item['item_type'], ['movie', 'series', 'episode'], true)
            && (!empty($displayDescription) || !empty($itemDetails['tagline']) || !empty($itemDetails['genres'])
                || !empty($itemDetails['runtime']) || !empty($itemDetails['release_date'])
                || !empty($itemDetails['production_countries']) || !empty($itemDetails['rating']));
        ?>
        <?php if ($item['item_type'] === 'live' && $item['description'] !== ''): ?><p class="iptv-description"><?= sanitize($item['description']) ?></p><?php endif; ?>
        <?php if ($hasWorkDetails): ?>
            <section class="media-detail-panel iptv-details-panel">
                <h2><?= sanitize(tr('تفاصيل العمل', 'Title details')) ?></h2>
                <?php if (!empty($itemDetails['tagline'])): ?><p class="media-tagline"><?= sanitize($itemDetails['tagline']) ?></p><?php endif; ?>
                <?php if (!empty($displayDescription)): ?><p class="media-description"><?= nl2br(sanitize($displayDescription)) ?></p><?php endif; ?>
                <div class="media-facts">
                    <?php if (!empty($itemDetails['genres'])): ?><span><?= sanitize($itemDetails['genres']) ?></span><?php endif; ?>
                    <?php if (!empty($itemDetails['runtime'])): ?><span><?= (int)$itemDetails['runtime'] ?> <?= sanitize(tr('دقيقة', 'min')) ?></span><?php endif; ?>
                    <?php if (!empty($itemDetails['release_date'])): ?><span><?= sanitize($itemDetails['release_date']) ?></span><?php endif; ?>
                    <?php if (!empty($itemDetails['production_countries'])): ?><span><?= sanitize($itemDetails['production_countries']) ?></span><?php endif; ?>
                    <?php if (!empty($itemDetails['rating'])): ?><span>★ <?= sanitize((string)$itemDetails['rating']) ?></span><?php endif; ?>
                </div>
                <?php if (!empty($itemDetails['backdrop_path'])): ?>
                    <img class="iptv-details-backdrop" src="<?= sanitize(site_url($itemDetails['backdrop_path'])) ?>" alt="" loading="lazy">
                <?php endif; ?>
                <?php if (!empty($itemDetails['trailer']) && preg_match('/^[A-Za-z0-9_-]{6,20}$/', $itemDetails['trailer'])): ?>
                    <a class="btn iptv-trailer-link" href="<?= sanitize('https://www.youtube-nocookie.com/watch?v=' . rawurlencode($itemDetails['trailer'])) ?>" target="_blank" rel="noopener noreferrer"><?= sanitize(tr('مشاهدة الإعلان الرسمي', 'Watch official trailer')) ?></a>
                <?php endif; ?>
                <?php if (!empty($itemDetails['metadata_source'])): ?>
                    <small class="iptv-metadata-source"><?= sanitize(tr('مصدر البيانات: ', 'Metadata source: ') . strtoupper($itemDetails['metadata_source'])) ?></small>
                <?php elseif ($item['source_type'] === 'm3u' && !get_setting('tmdb_api_key', '')): ?>
                    <p class="form-help"><?= sanitize(tr('ملف M3U لا يتضمن عادة وصفًا وطاقم عمل. أضف مفتاح TMDB من إعدادات الموقع لجلب التفاصيل والصور تلقائيًا.', 'M3U playlists usually do not include plot or cast. Add a TMDB API key in site settings to fetch details and portraits automatically.')) ?></p>
                <?php endif; ?>
            </section>
        <?php elseif (in_array($item['item_type'], ['movie', 'series', 'episode'], true) && $detailsError === ''): ?>
            <section class="media-detail-panel iptv-details-panel">
                <h2><?= sanitize(tr('تفاصيل العمل', 'Title details')) ?></h2>
                <p class="form-help"><?= sanitize($item['source_type'] === 'm3u' && !get_setting('tmdb_api_key', '')
                    ? tr('لا يحتوي مصدر M3U على هذه البيانات. أضف مفتاح TMDB من إعدادات الموقع لاستخدام العنوان في البحث وجلب الوصف والطاقم والصور.', 'This M3U source does not provide these details. Add a TMDB API key in site settings to look up the title and fetch its plot, cast, and images.')
                    : tr('لم يوفر المصدر تفاصيل إضافية لهذا العمل.', 'The source did not provide additional details for this title.')) ?></p>
            </section>
        <?php endif; ?>
        <?php if (!empty($itemDetails['cast'])): ?>
            <section class="media-detail-panel iptv-details-panel">
                <h2><?= sanitize(tr('طاقم التمثيل', 'Cast')) ?></h2>
                <div class="cast-grid">
                    <?php foreach ($itemDetails['cast'] as $index => $person): ?>
                        <article class="cast-card" data-expandable="<?= $index >= 12 ? 'true' : 'false' ?>" <?= $index >= 12 ? 'hidden' : '' ?>>
                            <?php if (!empty($person['profile'])): ?>
                                <img src="<?= sanitize(site_url($person['profile'])) ?>" alt="<?= sanitize($person['name']) ?>" loading="lazy">
                            <?php else: ?>
                                <div class="cast-placeholder" aria-hidden="true"></div>
                            <?php endif; ?>
                            <strong><?= sanitize($person['name']) ?></strong>
                            <?php if (!empty($person['character'])): ?><small><?= sanitize($person['character']) ?></small><?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php if (count($itemDetails['cast']) > 12): ?>
                    <button type="button" class="btn cast-more-button" id="iptv-cast-more" aria-expanded="false"><?= sanitize(tr('إظهار المزيد', 'Show more')) ?></button>
                <?php endif; ?>
            </section>
        <?php endif; ?>
        <?php if (!empty($itemDetails['crew'])): ?>
            <section class="media-detail-panel iptv-details-panel">
                <h2><?= sanitize(tr('فريق العمل', 'Crew')) ?></h2>
                <div class="crew-grid">
                    <?php foreach ($itemDetails['crew'] as $person): ?>
                        <article class="crew-card">
                            <?php if (!empty($person['profile'])): ?>
                                <img src="<?= sanitize(site_url($person['profile'])) ?>" alt="<?= sanitize($person['name']) ?>" loading="lazy">
                            <?php else: ?>
                                <div class="crew-placeholder" aria-hidden="true"></div>
                            <?php endif; ?>
                            <div><strong><?= sanitize($person['name']) ?></strong><span><?= sanitize($person['job']) ?></span></div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
        <?php if (!$episodes): ?>
            <div class="alert alert-info"><?= sanitize(tr('لا توجد حلقات متاحة لهذا المسلسل.', 'No episodes are available for this series.')) ?></div>
        <?php else: ?>
            <section class="iptv-episode-list iptv-series-episodes">
                <h2><?= sanitize(tr('الحلقات', 'Episodes')) ?></h2>
                <?php foreach ($episodes as $episode): ?>
                    <a class="iptv-episode" href="<?= sanitize(site_url('iptv.php?source=' . $sourceId . '&item=' . (int)$episode['id'])) ?>">
                        <span><?= sanitize(tr('الموسم', 'Season')) ?> <?= (int)$episode['season_number'] ?> · <?= sanitize(tr('الحلقة', 'Episode')) ?> <?= (int)$episode['episode_number'] ?></span>
                        <strong><?= sanitize($episode['title']) ?></strong>
                    </a>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    <?php else: ?>
        <?php
        $transcodeAvailable = $item['item_type'] === 'live' && iptv_ffmpeg_available();
        $transcoding = $item['item_type'] === 'live'
            && isset($_GET['transcode'])
            && !is_array($_GET['transcode'])
            && $_GET['transcode'] === '1'
            && $transcodeAvailable;
        $playUrl = iptv_proxy_url($sourceId, (int)$item['id'])
            . ($transcoding ? '&transcode=1' : '');
        $downloadUrl = iptv_proxy_url($sourceId, (int)$item['id'], true);
        $extension = strtolower(pathinfo((string)parse_url($item['stream_url'], PHP_URL_PATH), PATHINFO_EXTENSION));
        $mimeType = $extension === 'm3u8'
            ? 'application/vnd.apple.mpegurl'
            : ($extension === 'mp4' ? 'video/mp4' : 'video/mp2t');
        ?>
        <?php if (isset($_GET['transcode']) && $_GET['transcode'] === '1' && !$transcodeAvailable): ?>
            <div class="alert alert-error"><?= sanitize(tr('تحويل الصوت غير متاح لأن FFmpeg غير مثبت أو غير مضبوط في إعدادات الإدارة.', 'Audio conversion is unavailable because FFmpeg is not installed or configured in Admin settings.')) ?></div>
        <?php endif; ?>
        <div class="iptv-watch-layout <?= $item['item_type'] === 'episode' && $episodes ? 'has-episodes' : '' ?><?= $item['item_type'] === 'live' && $liveChannels ? ' has-live-channels' : '' ?>">
            <section class="iptv-watch-main">
                <?php if ($item['item_type'] === 'live'): ?>
                    <div class="iptv-player-stage">
                        <div class="iptv-player-switcher" id="iptv-player-switcher" role="group" aria-label="<?= sanitize(tr('اختر مشغل الفيديو', 'Choose a video player')) ?>">
                            <button type="button" class="player-engine-button <?= $extension === 'm3u8' || $transcoding ? '' : 'is-active' ?>" data-engine="native" aria-pressed="<?= $extension === 'm3u8' || $transcoding ? 'false' : 'true' ?>"><?= sanitize(tr('مشغل المتصفح', 'Browser')) ?></button>
                            <button type="button" class="player-engine-button <?= $extension === 'm3u8' || $transcoding ? 'is-active' : '' ?>" data-engine="videojs" aria-pressed="<?= $extension === 'm3u8' || $transcoding ? 'true' : 'false' ?>"><?= sanitize(tr('Video.js', 'Video.js')) ?></button>
                            <button type="button" class="player-engine-button" data-engine="clappr" aria-pressed="false"><?= sanitize(tr('Clappr', 'Clappr')) ?></button>
                            <button type="button" class="player-engine-button" data-engine="shaka" aria-pressed="false"><?= sanitize(tr('Shaka Player', 'Shaka Player')) ?></button>
                        </div>
                        <div class="iptv-video-frame" id="iptv-video-frame">
                <?php else: ?>
                    <div class="iptv-video-frame">
                <?php endif; ?>
                    <video id="iptv-player" controls playsinline preload="metadata" autoplay>
                        <source src="<?= sanitize($playUrl) ?>" type="<?= sanitize($mimeType) ?>">
                        <?= sanitize(tr('متصفحك لا يدعم تشغيل الفيديو.', 'Your browser does not support video playback.')) ?>
                    </video>
                </div>
                <?php if ($item['item_type'] === 'live'): ?></div><?php endif; ?>
                <p class="iptv-playback-error alert alert-error" id="iptv-playback-error" role="alert" hidden></p>
                <script>
                (function () {
                    var video = document.getElementById('iptv-player');
                    var error = document.getElementById('iptv-playback-error');
                    var transcodeLink = document.getElementById('iptv-transcode-link');
                    video.addEventListener('error', function () {
                        if (!video.error) {
                            return;
                        }
                        error.textContent = video.error.code === 4
                            ? '<?= sanitize($transcodeAvailable ? tr('صيغة الصوت أو الفيديو في هذا البث غير مدعومة في المتصفح. جرّب تحويل الصوت أو اختر قناة أخرى من القائمة بجانب المشغل.', 'This stream uses an audio or video format that this browser cannot decode. Try audio conversion or choose another channel from the list beside the player.') : tr('صيغة الصوت أو الفيديو في هذا البث غير مدعومة في المتصفح. جرّب قناة أخرى؛ تحويل الصوت يتطلب تثبيت FFmpeg وضبطه في إعدادات الإدارة.', 'This stream uses an audio or video format that this browser cannot decode. Try another channel; audio conversion requires FFmpeg to be installed and configured in Admin settings.')) ?>'
                            : '<?= sanitize(tr('تعذر تحميل البث. تحقق من الاتصال أو جرّب قناة أخرى من القائمة بجانب المشغل.', 'The stream could not be loaded. Check your connection or try another channel from the list beside the player.')) ?>';
                        error.hidden = false;
                        if (transcodeLink && video.error.code === 4) {
                            transcodeLink.hidden = false;
                        }
                    });
                }());
                </script>
                <?php if ($transcodeAvailable && !$transcoding): ?>
                    <a class="btn iptv-transcode-button" id="iptv-transcode-link" href="<?= sanitize(site_url('iptv.php?source=' . $sourceId . '&item=' . (int)$item['id'] . '&transcode=1')) ?>" hidden>
                        <?= sanitize(tr('تحويل الصوت للتشغيل', 'Convert audio for playback')) ?>
                    </a>
                <?php endif; ?>
                <div class="iptv-item-info">
                    <div class="iptv-item-brand">
                        <?php $brandPosterId = $item['poster_url'] !== '' ? (int)$item['id'] : (int)($seriesForEpisode['id'] ?? 0); ?>
                        <?php if ($brandPosterId): ?><img src="<?= sanitize(site_url('iptv_image.php?source=' . $sourceId . '&item=' . $brandPosterId)) ?>" alt="" loading="lazy"><?php endif; ?>
                        <div>
                            <h1><?= sanitize($seriesForEpisode ? $seriesForEpisode['title'] : $item['title']) ?></h1>
                            <span>
                                <?php if ($seriesForEpisode): ?>
                                    <?= sanitize(tr('الموسم', 'Season')) ?> <?= (int)$item['season_number'] ?> ·
                                    <?= sanitize(tr('الحلقة', 'Episode')) ?> <?= (int)$item['episode_number'] ?> — <?= sanitize($item['title']) ?>
                                <?php else: ?>
                                    <?= sanitize($item['category_name']) ?>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                    <a class="btn btn-primary" href="<?= sanitize($downloadUrl) ?>"><?= sanitize(tr('تحميل', 'Download')) ?></a>
                </div>
                <?php if ($item['description'] !== ''): ?><p class="iptv-description"><?= sanitize($item['description']) ?></p><?php endif; ?>
                <?php if (in_array($item['item_type'], ['movie', 'episode'], true)): ?>
                    <?php
                    $displayDescription = $itemDetails['description'] ?? $item['description'];
                    $hasWorkDetails = !empty($displayDescription) || !empty($itemDetails['tagline'])
                        || !empty($itemDetails['genres']) || !empty($itemDetails['runtime'])
                        || !empty($itemDetails['release_date']) || !empty($itemDetails['production_countries'])
                        || !empty($itemDetails['rating']);
                    ?>
                    <?php if ($hasWorkDetails || $detailsError === ''): ?>
                        <section class="media-detail-panel iptv-details-panel">
                            <h2><?= sanitize(tr('تفاصيل العمل', 'Title details')) ?></h2>
                            <?php if ($hasWorkDetails): ?>
                                <?php if (!empty($itemDetails['tagline'])): ?><p class="media-tagline"><?= sanitize($itemDetails['tagline']) ?></p><?php endif; ?>
                                <?php if (!empty($displayDescription)): ?><p class="media-description"><?= nl2br(sanitize($displayDescription)) ?></p><?php endif; ?>
                                <div class="media-facts">
                                    <?php if (!empty($itemDetails['genres'])): ?><span><?= sanitize($itemDetails['genres']) ?></span><?php endif; ?>
                                    <?php if (!empty($itemDetails['runtime'])): ?><span><?= (int)$itemDetails['runtime'] ?> <?= sanitize(tr('دقيقة', 'min')) ?></span><?php endif; ?>
                                    <?php if (!empty($itemDetails['release_date'])): ?><span><?= sanitize($itemDetails['release_date']) ?></span><?php endif; ?>
                                    <?php if (!empty($itemDetails['production_countries'])): ?><span><?= sanitize($itemDetails['production_countries']) ?></span><?php endif; ?>
                                    <?php if (!empty($itemDetails['rating'])): ?><span>★ <?= sanitize((string)$itemDetails['rating']) ?></span><?php endif; ?>
                                </div>
                                <?php if (!empty($itemDetails['backdrop_path'])): ?>
                                    <img class="iptv-details-backdrop" src="<?= sanitize(site_url($itemDetails['backdrop_path'])) ?>" alt="" loading="lazy">
                                <?php endif; ?>
                                <?php if (!empty($itemDetails['trailer']) && preg_match('/^[A-Za-z0-9_-]{6,20}$/', $itemDetails['trailer'])): ?>
                                    <a class="btn iptv-trailer-link" href="<?= sanitize('https://www.youtube-nocookie.com/watch?v=' . rawurlencode($itemDetails['trailer'])) ?>" target="_blank" rel="noopener noreferrer"><?= sanitize(tr('مشاهدة الإعلان الرسمي', 'Watch official trailer')) ?></a>
                                <?php endif; ?>
                                <?php if (!empty($itemDetails['metadata_source'])): ?>
                                    <small class="iptv-metadata-source"><?= sanitize(tr('مصدر البيانات: ', 'Metadata source: ') . strtoupper($itemDetails['metadata_source'])) ?></small>
                                <?php elseif ($item['source_type'] === 'm3u' && !get_setting('tmdb_api_key', '')): ?>
                                    <p class="form-help"><?= sanitize(tr('ملف M3U لا يتضمن عادة وصفًا وطاقم عمل. أضف مفتاح TMDB من إعدادات الموقع لجلب التفاصيل والصور تلقائيًا.', 'M3U playlists usually do not include plot or cast. Add a TMDB API key in site settings to fetch details and portraits automatically.')) ?></p>
                                <?php endif; ?>
                            <?php else: ?>
                                <p class="form-help"><?= sanitize($item['source_type'] === 'm3u' && !get_setting('tmdb_api_key', '')
                                    ? tr('لا يحتوي مصدر M3U على هذه البيانات. أضف مفتاح TMDB من إعدادات الموقع لاستخدام العنوان في البحث وجلب الوصف والطاقم والصور.', 'This M3U source does not provide these details. Add a TMDB API key in site settings to look up the title and fetch its plot, cast, and portraits.')
                                    : tr('لم يوفر المصدر تفاصيل إضافية لهذا العمل.', 'The source did not provide additional details for this title.')) ?></p>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>
                    <?php if (!empty($itemDetails['cast'])): ?>
                        <section class="media-detail-panel iptv-details-panel">
                            <h2><?= sanitize(tr('طاقم التمثيل', 'Cast')) ?></h2>
                            <div class="cast-grid">
                                <?php foreach ($itemDetails['cast'] as $person): ?>
                                    <article class="cast-card">
                                        <?php if (!empty($person['profile'])): ?>
                                            <img src="<?= sanitize(site_url($person['profile'])) ?>" alt="<?= sanitize($person['name']) ?>" loading="lazy">
                                        <?php else: ?>
                                            <div class="cast-placeholder" aria-hidden="true"></div>
                                        <?php endif; ?>
                                        <strong><?= sanitize($person['name']) ?></strong>
                                        <?php if (!empty($person['character'])): ?><small><?= sanitize($person['character']) ?></small><?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>
                    <?php if (!empty($itemDetails['crew'])): ?>
                        <section class="media-detail-panel iptv-details-panel">
                            <h2><?= sanitize(tr('فريق العمل', 'Crew')) ?></h2>
                            <div class="crew-grid">
                                <?php foreach ($itemDetails['crew'] as $person): ?>
                                    <article class="crew-card">
                                        <?php if (!empty($person['profile'])): ?>
                                            <img src="<?= sanitize(site_url($person['profile'])) ?>" alt="<?= sanitize($person['name']) ?>" loading="lazy">
                                        <?php else: ?>
                                            <div class="crew-placeholder" aria-hidden="true"></div>
                                        <?php endif; ?>
                                        <div><strong><?= sanitize($person['name']) ?></strong><span><?= sanitize($person['job']) ?></span></div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($item['item_type'] === 'live'): ?>
                    <section class="iptv-guide">
                        <h2><?= sanitize(tr('دليل البرامج (EPG)', 'Program guide (EPG)')) ?></h2>
                        <?php if ($guideError !== ''): ?>
                            <p class="form-help"><?= sanitize($guideError) ?></p>
                        <?php elseif (!$guide): ?>
                            <p class="form-help"><?= sanitize(tr('لا يتوفر دليل برامج لهذا البث.', 'No program guide is available for this stream.')) ?></p>
                        <?php else: ?>
                            <div class="iptv-guide-list">
                                <?php foreach ($guide as $program): ?>
                                    <article>
                                        <strong><?= sanitize($program['title']) ?></strong>
                                        <?php if ($program['start'] !== ''): ?><time><?= sanitize($program['start']) ?><?= $program['end'] !== '' ? ' – ' . sanitize($program['end']) : '' ?></time><?php endif; ?>
                                        <?php if ($program['description'] !== ''): ?><p><?= sanitize($program['description']) ?></p><?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
            </section>
            <?php if ($item['item_type'] === 'episode' && $episodes): ?>
                <aside class="iptv-episode-list iptv-watch-episodes">
                    <h2><?= sanitize(tr('الحلقات', 'Episodes')) ?></h2>
                    <?php foreach ($episodes as $episode): ?>
                        <a class="iptv-episode <?= (int)$episode['id'] === (int)$item['id'] ? 'is-current' : '' ?>" href="<?= sanitize(site_url('iptv.php?source=' . $sourceId . '&item=' . (int)$episode['id'])) ?>">
                            <span><?= sanitize(tr('الموسم', 'Season')) ?> <?= (int)$episode['season_number'] ?> · <?= sanitize(tr('الحلقة', 'Episode')) ?> <?= (int)$episode['episode_number'] ?></span>
                            <strong><?= sanitize($episode['title']) ?></strong>
                        </a>
                    <?php endforeach; ?>
                </aside>
            <?php endif; ?>
            <?php if ($item['item_type'] === 'live' && $liveChannels): ?>
                <aside class="iptv-episode-list iptv-watch-channels" aria-label="<?= sanitize(tr('قنوات للتبديل', 'Channels to switch to')) ?>">
                    <div class="iptv-channel-heading">
                        <h2><?= sanitize(tr('قنوات القسم', 'Category channels')) ?></h2>
                        <div class="iptv-inline-search iptv-channel-search" id="iptv-channel-search-control">
                            <button class="iptv-search-toggle" id="iptv-channel-search-toggle" type="button" aria-label="<?= sanitize(tr('البحث في قنوات القسم', 'Search category channels')) ?>" aria-expanded="false" aria-controls="iptv-channel-search">
                                <span aria-hidden="true"></span>
                            </button>
                            <input id="iptv-channel-search" type="search" placeholder="<?= sanitize(tr('ابحث في القنوات', 'Search channels')) ?>" aria-label="<?= sanitize(tr('ابحث في قنوات القسم', 'Search category channels')) ?>" autocomplete="off">
                        </div>
                    </div>
                    <?php foreach ($liveChannels as $channel): ?>
                        <a class="iptv-channel <?= (int)$channel['id'] === (int)$item['id'] ? 'is-current' : '' ?>" data-channel-name="<?= sanitize($channel['title']) ?>" href="<?= sanitize(site_url('iptv.php?source=' . $sourceId . '&item=' . (int)$channel['id'])) ?>" <?= (int)$channel['id'] === (int)$item['id'] ? 'aria-current="page"' : '' ?>>
                            <?php if ($channel['poster_url'] !== ''): ?>
                                <img src="<?= sanitize(site_url('iptv_image.php?source=' . $sourceId . '&item=' . (int)$channel['id'])) ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <span class="iptv-channel-placeholder" aria-hidden="true">&#9654;</span>
                            <?php endif; ?>
                            <strong><?= sanitize($channel['title']) ?></strong>
                        </a>
                    <?php endforeach; ?>
                    <p class="iptv-channel-search-empty" id="iptv-channel-search-empty" hidden><?= sanitize(tr('لا توجد قنوات مطابقة للبحث.', 'No channels match your search.')) ?></p>
                    <script>
                    (function () {
                        var input = document.getElementById('iptv-channel-search');
                        var control = document.getElementById('iptv-channel-search-control');
                        var toggle = document.getElementById('iptv-channel-search-toggle');
                        var empty = document.getElementById('iptv-channel-search-empty');
                        var channels = document.querySelectorAll('.iptv-channel');

                        function filterChannels() {
                            var query = input.value.trim().toLocaleLowerCase();
                            var visibleCount = 0;
                            for (var index = 0; index < channels.length; index++) {
                                var name = channels[index].getAttribute('data-channel-name').toLocaleLowerCase();
                                var visible = name.indexOf(query) !== -1;
                                channels[index].hidden = !visible;
                                if (visible) visibleCount++;
                            }
                            empty.hidden = visibleCount !== 0;
                        }

                        toggle.addEventListener('click', function () {
                            var open = control.classList.toggle('is-open');
                            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                            if (open) {
                                input.focus();
                            } else {
                                input.value = '';
                                filterChannels();
                            }
                        });
                        input.addEventListener('input', filterChannels);
                        input.addEventListener('keydown', function (event) {
                            if (event.key === 'Escape') {
                                input.value = '';
                                filterChannels();
                                control.classList.remove('is-open');
                                toggle.setAttribute('aria-expanded', 'false');
                                toggle.focus();
                            }
                        });
                    }());
                    </script>
                </aside>
            <?php endif; ?>
        </div>
        <?php if ($item['item_type'] === 'live' || $extension === 'm3u8' || $transcoding): ?>
            <?php if ($item['item_type'] !== 'live'): ?>
                <link rel="stylesheet" href="<?= sanitize(site_url('assets/vendor/videojs/video-js.min.css')) ?>">
                <script src="<?= sanitize(site_url('assets/vendor/videojs/video.min.js')) ?>"></script>
            <?php endif; ?>
            <script>
            (function () {
                var video = document.getElementById('iptv-player');
                var switcher = document.getElementById('iptv-player-switcher');
                var error = document.getElementById('iptv-playback-error');
                var transcodeLink = document.getElementById('iptv-transcode-link');
                if (!switcher && window.videojs) {
                    window.videojs(video, {
                        fluid: true,
                        liveui: true,
                        html5: {
                            vhs: { overrideNative: true },
                            nativeAudioTracks: false,
                            nativeVideoTracks: false
                        }
                    });
                }
                if (!switcher) {
                    return;
                }

                var frame = document.getElementById('iptv-video-frame');
                var template = video.cloneNode(true);
                var buttons = switcher.querySelectorAll('[data-engine]');
                var activeEngine = 'native';
                var engineInstance = null;
                var engineVersion = 0;
                var assetBase = <?= json_encode(site_url('assets/vendor/')) ?>;
                var streamUrl = <?= json_encode($playUrl) ?>;
                var mimeType = <?= json_encode($mimeType) ?>;
                var unsupportedMessage = <?= json_encode($transcodeAvailable ? tr('صيغة الصوت أو الفيديو في هذا البث غير مدعومة في المتصفح. جرّب تحويل الصوت أو اختر قناة أخرى من القائمة بجانب المشغل.', 'This stream uses an audio or video format that this browser cannot decode. Try audio conversion or choose another channel from the list beside the player.') : tr('صيغة الصوت أو الفيديو في هذا البث غير مدعومة في المتصفح. جرّب قناة أخرى؛ تحويل الصوت يتطلب تثبيت FFmpeg وضبطه في إعدادات الإدارة.', 'This stream uses an audio or video format that this browser cannot decode. Try another channel; audio conversion requires FFmpeg to be installed and configured in Admin settings.'), JSON_UNESCAPED_UNICODE) ?>;
                var playbackErrorMessage = <?= json_encode(tr('تعذر تحميل البث. تحقق من الاتصال أو جرّب قناة أخرى من القائمة بجانب المشغل.', 'The stream could not be loaded. Check your connection or try another channel from the list beside the player.'), JSON_UNESCAPED_UNICODE) ?>;

                function showStatus(message, isError) {
                    error.textContent = message;
                    error.hidden = !isError;
                }

                function setActiveButton(engine) {
                    for (var index = 0; index < buttons.length; index++) {
                        var active = buttons[index].getAttribute('data-engine') === engine;
                        buttons[index].classList.toggle('is-active', active);
                        buttons[index].setAttribute('aria-pressed', active ? 'true' : 'false');
                    }
                }

                function loadAsset(id, url, tagName) {
                    return new Promise(function (resolve, reject) {
                        var existing = document.getElementById(id);
                        if (existing) {
                            resolve();
                            return;
                        }
                        var asset = document.createElement(tagName);
                        asset.id = id;
                        if (tagName === 'link') {
                            asset.rel = 'stylesheet';
                            asset.href = url;
                        } else {
                            asset.src = url;
                        }
                        asset.onload = resolve;
                        asset.onerror = function () {
                            asset.remove();
                            reject(new Error('Could not load player asset: ' + url));
                        };
                        document.head.appendChild(asset);
                    });
                }

                function destroyEngine() {
                    var instance = engineInstance;
                    engineInstance = null;
                    if (!instance) {
                        return Promise.resolve();
                    }
                    if (activeEngine === 'videojs' && instance.dispose) {
                        instance.dispose();
                    } else if (activeEngine === 'clappr' && instance.destroy) {
                        instance.destroy();
                    } else if (activeEngine === 'shaka' && instance.destroy) {
                        return instance.destroy();
                    }
                    return Promise.resolve();
                }

                function createVideo() {
                    frame.replaceChildren();
                    var freshVideo = template.cloneNode(true);
                    freshVideo.id = 'iptv-player';
                    freshVideo.addEventListener('error', function () {
                        if (!freshVideo.error) {
                            return;
                        }
                        showStatus(freshVideo.error.code === 4 ? unsupportedMessage : playbackErrorMessage, true);
                        if (transcodeLink && freshVideo.error.code === 4) {
                            transcodeLink.hidden = false;
                        }
                    });
                    frame.appendChild(freshVideo);
                    return freshVideo;
                }

                function activateEngine(engine) {
                    if (engine !== 'native' && mimeType !== 'application/vnd.apple.mpegurl') {
                        showStatus(<?= json_encode(tr('المشغلات الإضافية متاحة لقنوات HLS فقط؛ تم الإبقاء على مشغل المتصفح.', 'Additional players are available for HLS channels only; the browser player remains active.'), JSON_UNESCAPED_UNICODE) ?>, true);
                        return;
                    }
                    if (engine === 'native' && mimeType === 'application/vnd.apple.mpegurl' && !video.canPlayType(mimeType)) {
                        showStatus(<?= json_encode(tr('هذا المتصفح لا يدعم HLS مباشرة. اختر مشغلاً آخر.', 'This browser cannot play HLS natively. Choose another player.'), JSON_UNESCAPED_UNICODE) ?>, true);
                        return;
                    }
                    var version = ++engineVersion;
                    destroyEngine().then(function () {
                        if (version !== engineVersion) {
                            return;
                        }
                        activeEngine = 'native';
                        var freshVideo = createVideo();
                        freshVideo.autoplay = true;
                        if (engine === 'native') {
                            freshVideo.load();
                            setActiveButton(engine);
                            showStatus('', false);
                            var playback = freshVideo.play();
                            if (playback && playback.catch) {
                                playback.catch(function () {
                                    showStatus(<?= json_encode(tr('اضغط تشغيل لبدء البث.', 'Press play to start the stream.'), JSON_UNESCAPED_UNICODE) ?>, false);
                                });
                            }
                            return;
                        }
                        activeEngine = engine;
                        var setup;
                        if (engine === 'videojs') {
                            setup = Promise.all([
                                loadAsset('iptv-videojs-style', assetBase + 'videojs/video-js.min.css', 'link'),
                                loadAsset('iptv-videojs-script', assetBase + 'videojs/video.min.js', 'script')
                            ]).then(function () {
                                if (!window.videojs) throw new Error('Video.js did not initialize.');
                                freshVideo.className += ' video-js vjs-big-play-centered';
                                engineInstance = window.videojs(freshVideo, {
                                    controls: true,
                                    fluid: true,
                                    liveui: true,
                                    autoplay: true,
                                    sources: [{ src: streamUrl, type: mimeType }],
                                    html5: {
                                        vhs: { overrideNative: true },
                                        nativeAudioTracks: false,
                                        nativeVideoTracks: false
                                    }
                                });
                            });
                        } else if (engine === 'clappr') {
                            setup = loadAsset('iptv-clappr-script', assetBase + 'clappr/clappr.min.js', 'script').then(function () {
                                if (!window.Clappr || !window.Clappr.Player) throw new Error('Clappr did not initialize.');
                                var container = document.createElement('div');
                                container.id = 'iptv-clappr-player';
                                container.className = 'clappr-player-container';
                                frame.replaceChild(container, freshVideo);
                                engineInstance = new window.Clappr.Player({
                                    source: streamUrl,
                                    mimeType: mimeType,
                                    parentId: '#iptv-clappr-player',
                                    width: '100%',
                                    height: '100%',
                                    autoPlay: true
                                });
                                if (engineInstance.on && window.Clappr.Events && window.Clappr.Events.PLAYER_ERROR) {
                                    engineInstance.on(window.Clappr.Events.PLAYER_ERROR, function () {
                                        if (activeEngine === 'clappr') {
                                            showStatus(playbackErrorMessage, true);
                                        }
                                    });
                                }
                            });
                        } else if (engine === 'shaka') {
                            setup = loadAsset('iptv-shaka-script', assetBase + 'shaka/shaka-player.compiled.js', 'script').then(function () {
                                if (!window.shaka || !window.shaka.Player.isBrowserSupported()) throw new Error('Shaka Player is not supported in this browser.');
                                engineInstance = new window.shaka.Player(freshVideo);
                                return engineInstance.load(streamUrl);
                            });
                        } else {
                            setup = Promise.reject(new Error('Unknown player selected.'));
                        }

                        setup.then(function () {
                            if (version !== engineVersion) {
                                return;
                            }
                            activeEngine = engine;
                            setActiveButton(engine);
                            showStatus('', false);
                        }).catch(function (playerError) {
                            if (version !== engineVersion) {
                                return;
                            }
                            destroyEngine().then(function () {
                                if (version !== engineVersion) {
                                    return;
                                }
                                activeEngine = 'native';
                                var fallbackVideo = createVideo();
                                fallbackVideo.load();
                                setActiveButton('native');
                                showStatus(playerError.message || <?= json_encode(tr('تعذر تحميل المشغل المحدد.', 'The selected player could not be loaded.'), JSON_UNESCAPED_UNICODE) ?>, true);
                            });
                        });
                    }).catch(function (playerError) {
                        if (version === engineVersion) {
                            showStatus(playerError.message || <?= json_encode(tr('تعذر إغلاق المشغل الحالي.', 'The current player could not be closed.'), JSON_UNESCAPED_UNICODE) ?>, true);
                        }
                    });
                }

                for (var index = 0; index < buttons.length; index++) {
                    buttons[index].addEventListener('click', function () {
                        activateEngine(this.getAttribute('data-engine'));
                    });
                }

                if (<?= $extension === 'm3u8' || $transcoding ? 'true' : 'false' ?>) {
                    activateEngine('videojs');
                }
            }());
            </script>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<?php render_footer(); ?>
