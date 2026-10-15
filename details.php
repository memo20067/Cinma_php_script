<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
ensure_media_details_schema();

$mediaId = (int)($_GET['id'] ?? 0);
$media = DB::fetch(
    "SELECT * FROM {prefix}media WHERE id = ? AND type IN ('software', 'game')",
    [$mediaId]
);
if (!$media) {
    http_response_code(404);
    render_header(tr('المحتوى غير موجود', 'Media not found'));
    echo '<div class="alert alert-info">' . sanitize(tr('البرنامج أو اللعبة المطلوبة غير موجودة.', 'The requested software or game was not found.')) . '</div>';
    render_footer();
    exit;
}
if (!check_permission($media['access_level'])) {
    http_response_code(403);
    render_header(tr('المحتوى مقيد', 'Restricted media'));
    echo '<div class="alert alert-info">' . sanitize(tr('يلزم تسجيل الدخول أو ترقية صلاحية الحساب للوصول إلى هذا المحتوى.', 'Sign in or upgrade your account to access this media.')) . '</div>';
    render_footer();
    exit;
}

$files = DB::fetchAll(
    "SELECT id, file_path, file_size, access_level FROM {prefix}media_files WHERE media_id = ? ORDER BY id",
    [$mediaId]
);
$gallery = json_decode($media['gallery_paths'] ?? '[]', true);
$gallery = is_array($gallery) ? $gallery : [];
$platforms = array_filter(explode(',', $media['platforms'] ?? ''));

$embedUrl = '';
$trailerHost = '';
if (!empty($media['trailer_url'])) {
    $parts = parse_url($media['trailer_url']);
    $host = strtolower($parts['host'] ?? '');
    $path = $parts['path'] ?? '';
    $query = [];
    parse_str($parts['query'] ?? '', $query);
    if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)
        && !empty($query['v']) && preg_match('/^[A-Za-z0-9_-]{6,20}$/', $query['v'])) {
        $embedUrl = 'https://www.youtube-nocookie.com/embed/' . $query['v'];
        $trailerHost = 'YouTube';
    } elseif (in_array($host, ['youtu.be', 'www.youtu.be'], true)
        && preg_match('~^/([A-Za-z0-9_-]{6,20})$~', $path, $match)) {
        $embedUrl = 'https://www.youtube-nocookie.com/embed/' . $match[1];
        $trailerHost = 'YouTube';
    } elseif (in_array($host, ['vimeo.com', 'www.vimeo.com'], true)
        && preg_match('~^/([0-9]{5,12})/?$~', $path, $match)) {
        $embedUrl = 'https://player.vimeo.com/video/' . $match[1];
        $trailerHost = 'Vimeo';
    } elseif (in_array($host, ['dailymotion.com', 'www.dailymotion.com'], true)
        && preg_match('~^/video/([A-Za-z0-9]+)~', $path, $match)) {
        $embedUrl = 'https://www.dailymotion.com/embed/video/' . $match[1];
        $trailerHost = 'Dailymotion';
    }
}

render_header($media['title']);
?>

<article class="media-detail-page">
    <header class="media-detail-hero">
        <?php if (!empty($media['poster_path'])): ?>
            <img class="media-detail-poster" src="<?= sanitize(site_url($media['poster_path'])) ?>" alt="<?= sanitize($media['title']) ?>">
        <?php endif; ?>
        <div class="media-detail-summary">
            <p class="eyebrow"><?= sanitize($media['type'] === 'game' ? tr('لعبة', 'GAME') : tr('برنامج', 'SOFTWARE')) ?></p>
            <h1><?= sanitize($media['title']) ?></h1>
            <?php if (!empty($media['release_year'])): ?><p><?= (int)$media['release_year'] ?><?php if (!empty($media['version_info'])): ?> · <?= sanitize($media['version_info']) ?><?php endif; ?></p><?php endif; ?>
            <?php if (!empty($media['content_category'])): ?><p><strong><?= sanitize(tr('التصنيف:', 'Category:')) ?></strong> <?= sanitize($media['content_category']) ?></p><?php endif; ?>
            <?php if ($platforms): ?><p><strong><?= sanitize(tr('المنصات:', 'Platforms:')) ?></strong> <?= sanitize(implode(', ', $platforms)) ?></p><?php endif; ?>
            <div class="media-detail-description"><?= nl2br(sanitize($media['plot'] ?? '')) ?></div>
        </div>
    </header>

    <?php if (!empty($media['license_info'])): ?>
        <section class="media-detail-panel">
            <h2><?= sanitize(tr('معلومات التفعيل والترخيص', 'Activation and license information')) ?></h2>
            <div><?= nl2br(sanitize($media['license_info'])) ?></div>
        </section>
    <?php endif; ?>
    <?php if (!empty($media['system_requirements'])): ?>
        <section class="media-detail-panel">
            <h2><?= sanitize(tr('متطلبات التشغيل', 'System requirements')) ?></h2>
            <div><?= nl2br(sanitize($media['system_requirements'])) ?></div>
        </section>
    <?php endif; ?>

    <?php if (!empty($media['trailer_path']) || $embedUrl !== '' || !empty($media['trailer_url'])): ?>
        <section class="media-detail-panel">
            <h2><?= sanitize(tr('فيديو الشرح أو المقطع الدعائي', 'Tutorial or trailer')) ?></h2>
            <?php if (!empty($media['trailer_path'])): ?>
                <video class="media-detail-video" controls preload="metadata" src="<?= sanitize(site_url($media['trailer_path'])) ?>"></video>
            <?php elseif ($embedUrl !== ''): ?>
                <div class="responsive-video">
                    <iframe src="<?= sanitize($embedUrl) ?>" title="<?= sanitize($media['title'] . ' ' . $trailerHost) ?>" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
                </div>
            <?php elseif (!empty($media['trailer_url'])): ?>
                <a class="btn btn-primary" href="<?= sanitize($media['trailer_url']) ?>" target="_blank" rel="noopener noreferrer"><?= sanitize(tr('فتح الفيديو في الموقع الأصلي', 'Open video on its original website')) ?></a>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($gallery): ?>
        <section class="media-detail-panel">
            <h2><?= sanitize(tr('الصور', 'Screenshots')) ?></h2>
            <div class="media-gallery">
                <?php foreach ($gallery as $image): ?>
                    <img src="<?= sanitize(site_url($image)) ?>" alt="<?= sanitize($media['title']) ?>" loading="lazy">
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="media-detail-panel">
        <h2><?= sanitize(tr('ملفات التنزيل', 'Download files')) ?></h2>
        <?php if (!$files): ?>
            <p><?= sanitize(tr('لم يتم رفع ملفات لهذا المحتوى بعد.', 'No files have been uploaded for this item yet.')) ?></p>
        <?php else: ?>
            <ul class="download-file-list">
                <?php foreach ($files as $index => $file): ?>
                    <li>
                        <span><?= sanitize(basename($file['file_path'])) ?> · <?= sanitize(format_size((int)$file['file_size'])) ?></span>
                        <a class="btn btn-sm btn-primary" href="<?= sanitize(site_url('download.php?file_id=' . (int)$file['id'])) ?>"><?= sanitize(tr('تحميل الملف', 'Download file')) ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</article>

<?php render_footer(); ?>
