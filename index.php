<?php
// Homepage - Main Portal

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
ensure_media_details_schema();

// Fetch Latest Movies
$movies = DB::fetchAll(
    "SELECT m.*,
        (SELECT mf.id FROM {prefix}media_files mf WHERE mf.media_id = m.id ORDER BY mf.id LIMIT 1) AS download_file_id
     FROM {prefix}media m WHERE m.type = 'movie' ORDER BY m.id DESC LIMIT 6"
);

// Fetch Latest TV Shows
$tvShows = DB::fetchAll(
    "SELECT m.*,
        (SELECT COUNT(*) FROM {prefix}episodes e WHERE e.media_id = m.id) AS ep_count,
        (SELECT e.id FROM {prefix}episodes e WHERE e.media_id = m.id ORDER BY e.season_number, e.episode_number LIMIT 1) AS download_episode_id
     FROM {prefix}media m WHERE m.type = 'tv' ORDER BY m.id DESC LIMIT 6"
);

// Fetch Latest Software & Games
$appsGames = DB::fetchAll(
    "SELECT m.*,
        (SELECT mf.id FROM {prefix}media_files mf WHERE mf.media_id = m.id ORDER BY mf.id LIMIT 1) AS download_file_id
     FROM {prefix}media m WHERE m.type IN ('software', 'game') ORDER BY m.id DESC LIMIT 6"
);

$sliderItems = [];
$sliderCategories = [
    'movie' => tr('فيلم', 'Movie'),
    'tv' => tr('مسلسل', 'Series'),
    'software' => tr('برنامج', 'Software'),
    'game' => tr('لعبة', 'Game')
];

foreach ($sliderCategories as $type => $category) {
    $items = DB::fetchAll(
        "SELECT id, title, type, poster_path, release_year, plot, genres, rating, content_category FROM {prefix}media
         WHERE type = ? ORDER BY id DESC LIMIT 5",
        [$type]
    );
    foreach ($items as $item) {
        $item['category'] = $category;
        $item['url'] = $type === 'movie'
            ? site_url('watch.php?id=' . $item['id'])
            : ($type === 'tv'
                ? site_url('show.php?id=' . $item['id'])
                : site_url('details.php?id=' . $item['id']));
        $sliderItems[] = $item;
    }
}

$sliderCopies = $sliderItems
    ? max(1, (int)ceil(1600 / (count($sliderItems) * 184)))
    : 1;
$sliderDuration = max(24, count($sliderItems) * $sliderCopies * 195 / 34);
$sliderLogo = get_setting('site_logo', '');
$sliderLogoFile = $sliderLogo !== '' ? realpath(__DIR__ . DIRECTORY_SEPARATOR . $sliderLogo) : false;
$hasSliderLogo = $sliderLogoFile !== false && is_file($sliderLogoFile);

render_header();
?>

<!-- Recently added media slider -->
<section class="hero-panel home-slider" aria-label="<?= sanitize(tr('أحدث الإضافات', 'Recently added')) ?>">
    <?php if ($hasSliderLogo): ?>
        <div class="home-slider-brand">
            <img src="<?= sanitize(site_url($sliderLogo)) ?>" alt="<?= sanitize(get_setting('site_name', 'CinemaCMS')) ?>" class="home-slider-logo">
        </div>
    <?php endif; ?>
    <?php if (!$sliderItems): ?>
        <p class="home-slider-empty"><?= sanitize(tr('لا توجد عناصر مضافة بعد.', 'No media has been added yet.')) ?></p>
    <?php else: ?>
        <div class="home-slider-window">
            <div class="home-slider-track" style="--home-slider-duration: <?= number_format($sliderDuration, 2, '.', '') ?>s">
                <?php for ($copy = 0; $copy < 2; $copy++): ?>
                    <div class="home-slider-group"<?= $copy === 1 ? ' aria-hidden="true"' : '' ?>>
                        <?php for ($repeat = 0; $repeat < $sliderCopies; $repeat++): ?>
                            <?php foreach ($sliderItems as $item): ?>
                                <a class="home-slider-card" href="<?= sanitize($item['url']) ?>"<?= $copy === 1 ? ' tabindex="-1"' : '' ?>>
                                    <span class="home-slider-poster">
                                        <?php if (!empty($item['poster_path'])): ?>
                                            <img src="<?= sanitize(site_url($item['poster_path'])) ?>" alt="<?= $copy === 1 ? '' : sanitize($item['title']) ?>" loading="lazy">
                                        <?php else: ?>
                                            <span class="home-slider-no-poster"><?= sanitize(tr('لا توجد صورة', 'No poster')) ?></span>
                                        <?php endif; ?>
                                        <span class="home-slider-category"><?= sanitize($item['category']) ?></span>
                                        <span class="home-slider-overlay">
                                            <span class="home-slider-overlay-title"><?= sanitize($item['title']) ?></span>
                                        </span>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        <?php endfor; ?>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
    <?php endif; ?>
</section>

<!-- Movies Section -->
<div class="home-section">
    <div class="section-heading-row">
        <h2 class="section-title"><?= sanitize(tr('أحدث الأفلام', 'Latest Movies')) ?></h2>
        <a href="<?= sanitize(site_url('movies.php')) ?>" class="section-link"><?= sanitize(tr('عرض كل الأفلام', 'View All Movies')) ?> &rarr;</a>
    </div>
    <?php if (empty($movies)): ?>
        <div class="alert alert-info"><?= sanitize(tr('لا توجد أفلام متاحة بعد.', 'No movies available yet.')) ?></div>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($movies as $movie): ?>
                <?php render_media_card($movie); ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- TV Series Section -->
<div class="home-section">
    <div class="section-heading-row">
        <h2 class="section-title section-title-green"><?= sanitize(tr('أشهر المسلسلات', 'Popular TV Series')) ?></h2>
        <a href="<?= sanitize(site_url('tvshows.php')) ?>" class="section-link"><?= sanitize(tr('عرض كل المسلسلات', 'View All TV Shows')) ?> &rarr;</a>
    </div>
    <?php if (empty($tvShows)): ?>
        <div class="alert alert-info"><?= sanitize(tr('لا توجد مسلسلات متاحة بعد.', 'No TV series available yet.')) ?></div>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($tvShows as $show): ?>
                <?php render_media_card($show); ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Software & Games Section -->
<div class="home-section">
    <div class="section-heading-row">
        <h2 class="section-title section-title-amber"><?= sanitize(tr('البرامج والتطبيقات والألعاب', 'Software, Apps & Games')) ?></h2>
        <a href="<?= sanitize(site_url('software.php')) ?>" class="section-link"><?= sanitize(tr('عرض كل التنزيلات', 'View All Downloads')) ?> &rarr;</a>
    </div>
    <?php if (empty($appsGames)): ?>
        <div class="alert alert-info"><?= sanitize(tr('لا توجد برامج أو ألعاب متاحة بعد.', 'No software or games available yet.')) ?></div>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($appsGames as $app): ?>
                <?php render_media_card($app); ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php
render_footer();
