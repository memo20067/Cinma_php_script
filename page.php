<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

ensure_pages_schema();
$slug = trim($_GET['slug'] ?? '');
$page = DB::fetch("SELECT * FROM {prefix}pages WHERE slug = ? AND status = 'published'", [$slug]);

if (!$page) {
    http_response_code(404);
    render_header(tr('الصفحة غير موجودة', 'Page not found'));
    echo '<div class="alert alert-info">' . sanitize(tr('الصفحة المطلوبة غير موجودة أو غير منشورة.', 'The requested page does not exist or is not published.')) . '</div>';
    render_footer();
    exit;
}

render_header($page['title']);
?>
<?php if (!empty($page['page_css'])): ?>
    <style><?= $page['page_css'] ?></style>
<?php endif; ?>
<article class="custom-page">
    <div class="custom-page-content"><?= $page['content_html'] ?></div>
</article>
<?php if (!empty($page['page_js'])): ?>
    <script><?= $page['page_js'] ?></script>
<?php endif; ?>
<?php render_footer(); ?>
