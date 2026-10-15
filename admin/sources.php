<?php

require_once __DIR__ . '/../includes/iptv.php';
require_permission('admin');
ensure_iptv_schema();

$message = '';
$error = '';
$csrfToken = $_SESSION['iptv_sources_csrf'] ?? '';
if ($csrfToken === '') {
    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['iptv_sources_csrf'] = $csrfToken;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($postedToken) || !hash_equals($csrfToken, $postedToken)) {
        $error = tr('انتهت صلاحية الطلب. حدّث الصفحة وحاول مرة أخرى.', 'The request expired. Refresh the page and try again.');
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'add_source') {
                $name = trim($_POST['name'] ?? '');
                $type = $_POST['source_type'] ?? '';
                $baseUrl = rtrim(trim($_POST['base_url'] ?? ''), '/');
                $username = trim($_POST['username'] ?? '');
                $password = trim($_POST['password'] ?? '');
                $playlistUrl = trim($_POST['playlist_url'] ?? '');

                $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
                if ($name === '' || $nameLength > (function_exists('mb_strlen') ? 160 : 640)) {
                    throw new InvalidArgumentException(tr('أدخل اسمًا للمصدر لا يتجاوز 160 حرفًا.', 'Enter a source name no longer than 160 characters.'));
                }
                if ($type === 'xtream') {
                    if (!iptv_valid_http_url($baseUrl) || $username === '' || $password === '') {
                        throw new InvalidArgumentException(tr('أدخل رابط Xtream واسم المستخدم وكلمة المرور بشكل صحيح.', 'Enter a valid Xtream URL, username, and password.'));
                    }
                    DB::insert('iptv_sources', [
                        'name' => $name,
                        'source_type' => 'xtream',
                        'base_url' => $baseUrl,
                        'username' => $username,
                        'password' => $password,
                        'playlist_url' => '',
                        'enabled' => 1
                    ]);
                } elseif ($type === 'm3u') {
                    if (!iptv_valid_http_url($playlistUrl)) {
                        throw new InvalidArgumentException(tr('أدخل رابط M3U أو M3U8 صالحًا.', 'Enter a valid M3U or M3U8 URL.'));
                    }
                    DB::insert('iptv_sources', [
                        'name' => $name,
                        'source_type' => 'm3u',
                        'base_url' => '',
                        'username' => '',
                        'password' => '',
                        'playlist_url' => $playlistUrl,
                        'enabled' => 1
                    ]);
                } else {
                    throw new InvalidArgumentException(tr('نوع المصدر المحدد غير صالح.', 'The selected source type is invalid.'));
                }
                $message = tr('تمت إضافة المصدر. استخدم زر التحديث لجلب محتواه.', 'The source was added. Use Refresh to import its catalog.');
            } elseif ($action === 'refresh_source') {
                iptv_refresh_source((int)($_POST['source_id'] ?? 0), true);
                $message = tr('تم تحديث محتوى المصدر بنجاح.', 'The source catalog was refreshed successfully.');
            } elseif ($action === 'refresh_type') {
                $cacheType = $_POST['cache_type'] ?? '';
                iptv_refresh_source((int)($_POST['source_id'] ?? 0), true, $cacheType);
                $message = tr('تم تحديث القسم المحدد من المصدر.', 'The selected source section was refreshed.');
            } elseif ($action === 'clear_cache') {
                $cacheType = $_POST['cache_type'] ?? '';
                iptv_clear_source_cache((int)($_POST['source_id'] ?? 0), $cacheType);
                $message = tr('تم حذف الكاش المحدد بنجاح.', 'The selected cache was cleared successfully.');
            } elseif ($action === 'toggle_source') {
                $sourceId = (int)($_POST['source_id'] ?? 0);
                $source = DB::fetch('SELECT enabled FROM {prefix}iptv_sources WHERE id = ?', [$sourceId]);
                if (!$source) {
                    throw new RuntimeException(tr('المصدر غير موجود.', 'The source was not found.'));
                }
                DB::update('iptv_sources', ['enabled' => (int)!$source['enabled']], 'id = ?', [$sourceId]);
                $message = tr('تم تحديث حالة المصدر.', 'The source status was updated.');
            } elseif ($action === 'delete_source') {
                $sourceId = (int)($_POST['source_id'] ?? 0);
                if (!DB::fetch('SELECT id FROM {prefix}iptv_sources WHERE id = ?', [$sourceId])) {
                    throw new RuntimeException(tr('المصدر غير موجود.', 'The source was not found.'));
                }
                $pdo = DB::getInstance()->getPDO();
                $pdo->beginTransaction();
                try {
                    DB::delete('iptv_items', 'source_id = ?', [$sourceId]);
                    DB::delete('iptv_resources', 'source_id = ?', [$sourceId]);
                    DB::delete('iptv_epg', 'source_id = ?', [$sourceId]);
                    DB::delete('iptv_category_cache', 'source_id = ?', [$sourceId]);
                    DB::delete('iptv_image_cache', 'source_id = ?', [$sourceId]);
                    DB::delete('iptv_sources', 'id = ?', [$sourceId]);
                    $pdo->commit();
                } catch (Throwable $exception) {
                    $pdo->rollBack();
                    throw $exception;
                }
                iptv_delete_cached_images($sourceId);
                $message = tr('تم حذف المصدر ومحتواه المخزن.', 'The source and its cached catalog were deleted.');
            } else {
                throw new InvalidArgumentException(tr('إجراء غير صالح.', 'Invalid action.'));
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

$sources = DB::fetchAll('SELECT s.id, s.name, s.source_type, s.enabled, s.last_synced_at,
    (SELECT COUNT(*) FROM {prefix}iptv_items i WHERE i.source_id = s.id) AS item_count,
    (SELECT COUNT(*) FROM {prefix}iptv_items i WHERE i.source_id = s.id AND i.item_type = \'live\') AS live_count,
    (SELECT COUNT(*) FROM {prefix}iptv_items i WHERE i.source_id = s.id AND i.item_type = \'movie\') AS movie_count,
    (SELECT COUNT(*) FROM {prefix}iptv_items i WHERE i.source_id = s.id AND i.item_type = \'series\') AS series_count,
    (SELECT COUNT(*) FROM {prefix}iptv_resources r WHERE r.source_id = s.id) AS resource_count
    FROM {prefix}iptv_sources s ORDER BY s.id DESC');
render_header(tr('مصادر البث', 'Streaming sources'));
?>

<header class="admin-page-heading">
    <div>
        <p class="eyebrow"><?= sanitize(tr('البث الخارجي', 'EXTERNAL STREAMING')) ?></p>
        <h1><?= sanitize(tr('مصادر Xtream وM3U', 'Xtream and M3U sources')) ?></h1>
    </div>
    <a href="<?= sanitize(site_url('admin/index.php')) ?>" class="btn"><?= sanitize(tr('لوحة الإدارة', 'Admin dashboard')) ?></a>
</header>

<?php if ($message !== ''): ?><div class="alert alert-success"><?= sanitize($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= sanitize($error) ?></div><?php endif; ?>

<section class="admin-panel">
    <h2><?= sanitize(tr('إضافة مصدر', 'Add a source')) ?></h2>
    <p class="form-help"><?= sanitize(tr('تُخزّن بيانات المصدر في قاعدة البيانات ولا تُعرض للزوار. تُحدّث القوائم تلقائيًا كل 12 ساعة أو يدويًا.', 'Source credentials are stored in the database and are not shown to visitors. Catalogs refresh every 12 hours or on demand.')) ?></p>
    <form method="post" class="source-form">
        <input type="hidden" name="csrf_token" value="<?= sanitize($csrfToken) ?>">
        <input type="hidden" name="action" value="add_source">
        <div class="settings-grid">
            <div class="form-group">
                <label for="source-name"><?= sanitize(tr('اسم المصدر', 'Source name')) ?></label>
                <input id="source-name" name="name" class="form-control" maxlength="160" required>
            </div>
            <div class="form-group">
                <label for="source-type"><?= sanitize(tr('نوع المصدر', 'Source type')) ?></label>
                <select id="source-type" name="source_type" class="form-control">
                    <option value="xtream"><?= sanitize(tr('Xtream', 'Xtream Codes')) ?></option>
                    <option value="m3u">M3U / M3U8</option>
                </select>
            </div>
            <div class="form-group source-xtream-field">
                <label for="source-base-url"><?= sanitize(tr('رابط الخادم', 'Server URL')) ?></label>
                <input id="source-base-url" name="base_url" class="form-control" type="url" placeholder="https://provider.example:8080" dir="ltr">
            </div>
            <div class="form-group source-xtream-field">
                <label for="source-username"><?= sanitize(tr('اسم المستخدم', 'Username')) ?></label>
                <input id="source-username" name="username" class="form-control" autocomplete="off">
            </div>
            <div class="form-group source-xtream-field">
                <label for="source-password"><?= sanitize(tr('كلمة المرور', 'Password')) ?></label>
                <input id="source-password" name="password" class="form-control" type="password" autocomplete="new-password">
            </div>
            <div class="form-group source-m3u-field" hidden>
                <label for="source-playlist-url"><?= sanitize(tr('رابط قائمة M3U/M3U8', 'M3U/M3U8 playlist URL')) ?></label>
                <input id="source-playlist-url" name="playlist_url" class="form-control" type="url" placeholder="https://provider.example/list.m3u" dir="ltr">
            </div>
        </div>
        <button type="submit" class="btn btn-primary"><?= sanitize(tr('إضافة المصدر', 'Add source')) ?></button>
    </form>
</section>

<section class="admin-panel">
    <h2><?= sanitize(tr('المصادر المحفوظة', 'Saved sources')) ?></h2>
    <?php if (!$sources): ?>
        <p class="form-help"><?= sanitize(tr('لم تتم إضافة مصادر بعد.', 'No sources have been added yet.')) ?></p>
    <?php else: ?>
        <div class="source-list">
            <?php foreach ($sources as $source): ?>
                <article class="source-row">
                    <div>
                        <strong><?= sanitize($source['name']) ?></strong>
                        <p class="form-help">
                            <?= $source['source_type'] === 'xtream' ? sanitize(tr('Xtream', 'Xtream Codes')) : 'M3U / M3U8' ?>
                            · <?= (int)$source['item_count'] ?> <?= sanitize(tr('عنصر مخزّن', 'cached items')) ?>
                            · <?= $source['last_synced_at'] ? sanitize($source['last_synced_at']) : sanitize(tr('لم يتم التحديث', 'Not refreshed yet')) ?>
                            · <?= (int)$source['enabled'] ? sanitize(tr('مفعل', 'Enabled')) : sanitize(tr('متوقف', 'Disabled')) ?>
                        </p>
                    </div>
                    <div class="source-actions">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= sanitize($csrfToken) ?>">
                            <input type="hidden" name="source_id" value="<?= (int)$source['id'] ?>">
                            <input type="hidden" name="action" value="refresh_source">
                            <button class="btn btn-sm btn-primary" type="submit"><?= sanitize(tr('تحديث المصدر', 'Refresh source')) ?></button>
                        </form>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= sanitize($csrfToken) ?>">
                            <input type="hidden" name="source_id" value="<?= (int)$source['id'] ?>">
                            <input type="hidden" name="action" value="toggle_source">
                            <button class="btn btn-sm" type="submit"><?= (int)$source['enabled'] ? sanitize(tr('إيقاف', 'Disable')) : sanitize(tr('تفعيل', 'Enable')) ?></button>
                        </form>
                        <form method="post" onsubmit="return confirm('<?= sanitize(tr('حذف المصدر ومحتواه المخزن؟', 'Delete this source and its cached catalog?')) ?>')">
                            <input type="hidden" name="csrf_token" value="<?= sanitize($csrfToken) ?>">
                            <input type="hidden" name="source_id" value="<?= (int)$source['id'] ?>">
                            <input type="hidden" name="action" value="delete_source">
                            <button class="btn btn-sm btn-danger" type="submit"><?= sanitize(tr('حذف', 'Delete')) ?></button>
                        </form>
                    </div>
                    <div class="source-cache-controls">
                        <?php foreach ([
                            'live' => ['label' => tr('البث المباشر', 'Live TV'), 'count' => (int)$source['live_count']],
                            'movie' => ['label' => tr('الأفلام', 'Movies'), 'count' => (int)$source['movie_count']],
                            'series' => ['label' => tr('المسلسلات', 'Series'), 'count' => (int)$source['series_count']],
                            'resources' => ['label' => tr('روابط التشغيل', 'Playback links'), 'count' => (int)$source['resource_count']]
                        ] as $cacheType => $cacheSection): ?>
                            <div class="source-cache-section">
                                <strong><?= sanitize($cacheSection['label']) ?></strong>
                                <span class="form-help"><?= $cacheSection['count'] ?> <?= sanitize(tr('عنصر مخزّن', 'cached')) ?></span>
                                <?php if ($cacheType !== 'resources'): ?>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= sanitize($csrfToken) ?>">
                                        <input type="hidden" name="source_id" value="<?= (int)$source['id'] ?>">
                                        <input type="hidden" name="cache_type" value="<?= sanitize($cacheType) ?>">
                                        <input type="hidden" name="action" value="refresh_type">
                                        <button class="btn btn-sm btn-primary" type="submit"><?= sanitize(tr('تحديث القسم', 'Refresh section')) ?></button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" onsubmit="return confirm('<?= sanitize(tr('حذف كاش هذا القسم من المصدر؟', 'Clear this section cache for this source?')) ?>')">
                                    <input type="hidden" name="csrf_token" value="<?= sanitize($csrfToken) ?>">
                                    <input type="hidden" name="source_id" value="<?= (int)$source['id'] ?>">
                                    <input type="hidden" name="cache_type" value="<?= sanitize($cacheType) ?>">
                                    <input type="hidden" name="action" value="clear_cache">
                                    <button class="btn btn-sm btn-danger" type="submit"><?= sanitize(tr('حذف الكاش', 'Clear cache')) ?></button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                        <form method="post" class="source-cache-clear-all" onsubmit="return confirm('<?= sanitize(tr('حذف كل كاش هذا المصدر بما فيه القنوات والأفلام والمسلسلات والصور وروابط التشغيل؟', 'Clear all this source cache, including channels, movies, series, images, and playback links?')) ?>')">
                            <input type="hidden" name="csrf_token" value="<?= sanitize($csrfToken) ?>">
                            <input type="hidden" name="source_id" value="<?= (int)$source['id'] ?>">
                            <input type="hidden" name="cache_type" value="all">
                            <input type="hidden" name="action" value="clear_cache">
                            <button class="btn btn-sm btn-danger" type="submit"><?= sanitize(tr('حذف كاش المصدر بالكامل', 'Clear entire source cache')) ?></button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<script>
document.getElementById('source-type').addEventListener('change', function () {
    var isM3u = this.value === 'm3u';
    document.querySelectorAll('.source-xtream-field').forEach(function (field) {
        field.hidden = isM3u;
    });
    document.querySelectorAll('.source-m3u-field').forEach(function (field) {
        field.hidden = !isM3u;
    });
});
</script>
<?php render_footer(); ?>
