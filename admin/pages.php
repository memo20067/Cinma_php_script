<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

require_permission('admin');
ensure_pages_schema();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_page') {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $content = trim($_POST['content_html'] ?? '');
        $pageCss = trim($_POST['page_css'] ?? '');
        $pageJs = trim($_POST['page_js'] ?? '');
        $status = $_POST['status'] ?? 'published';
        $existing = $id ? DB::fetch("SELECT id FROM {prefix}pages WHERE id = ?", [$id]) : false;

        if ($id && !$existing) {
            $error = tr('الصفحة المطلوب تعديلها غير موجودة.', 'The page to edit was not found.');
        } elseif ($title === '' || $content === '') {
            $error = tr('عنوان الصفحة ومحتوى HTML مطلوبان.', 'A page title and HTML content are required.');
        } elseif (strlen($content) > 100000 || strlen($pageCss) > 100000 || strlen($pageJs) > 100000) {
            $error = tr('يجب ألا يتجاوز كل محرر 100 ألف حرف.', 'Each editor is limited to 100,000 characters.');
        } elseif ($slug === '' || !preg_match('/^[\pL\pN]+(?:-[\pL\pN]+)*$/u', $slug)) {
            $error = tr('استخدم في الرابط حروفاً وأرقاماً وشرطات فقط.', 'Use only letters, numbers, and hyphens in the page URL.');
        } elseif (!in_array($status, ['published', 'draft'], true)) {
            $error = tr('حالة الصفحة غير صالحة.', 'The page status is invalid.');
        } else {
            $duplicate = DB::fetch(
                "SELECT id FROM {prefix}pages WHERE slug = ? AND id != ?",
                [$slug, $id]
            );
            if ($duplicate) {
                $error = tr('هذا الرابط مستخدم بالفعل لصفحة أخرى.', 'This URL is already used by another page.');
            } else {
                $data = [
                    'title' => $title,
                    'slug' => $slug,
                    'content_html' => $content,
                    'page_css' => $pageCss,
                    'page_js' => $pageJs,
                    'status' => $status
                ];
                if ($existing) {
                    DB::update('pages', $data + ['updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
                } else {
                    DB::insert('pages', $data);
                }
                $message = tr('تم حفظ الصفحة.', 'Page saved.');
            }
        }
    } elseif ($action === 'delete_page') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            DB::delete('pages', 'id = ?', [$id]);
            $message = tr('تم حذف الصفحة.', 'Page deleted.');
        }
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$editing = $editId ? DB::fetch("SELECT * FROM {prefix}pages WHERE id = ?", [$editId]) : false;
$pages = DB::fetchAll("SELECT * FROM {prefix}pages ORDER BY updated_at DESC, id DESC");

render_header(tr('صفحات الموقع', 'Site pages'));
?>

<header class="admin-page-heading">
    <div>
        <p class="eyebrow"><?= sanitize(tr('محتوى مخصص', 'CUSTOM CONTENT')) ?></p>
        <h1><?= sanitize(tr('إدارة صفحات الموقع', 'Manage site pages')) ?></h1>
    </div>
</header>

<?php if ($message !== ''): ?><div class="alert alert-success"><?= sanitize($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= sanitize($error) ?></div><?php endif; ?>

<section class="admin-panel">
    <h2><?= sanitize($editing ? tr('تعديل الصفحة', 'Edit page') : tr('إضافة صفحة HTML', 'Add an HTML page')) ?></h2>
    <p class="form-help"><?= sanitize(tr('تظهر الصفحة فوق خلفية الموقع الرئيسية، ويمكنك تغيير خلفية ومظهر المحتوى الداخلي عبر CSS. HTML وJavaScript ينفذان كما كُتبا، لذلك امنح صلاحية إدارة الصفحات لحسابات موثوقة فقط.', 'The site background remains in place; use CSS to customize the inner page. HTML and JavaScript run as authored, so only trusted accounts should manage pages.')) ?></p>
    <form method="POST">
        <input type="hidden" name="action" value="save_page">
        <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
        <div class="settings-grid">
            <div class="form-group">
                <label for="page-title"><?= sanitize(tr('عنوان الصفحة', 'Page title')) ?></label>
                <input id="page-title" class="form-control" type="text" name="title" required value="<?= sanitize($editing['title'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="page-slug"><?= sanitize(tr('الرابط (slug)', 'URL slug')) ?></label>
                <input id="page-slug" class="form-control" type="text" name="slug" required value="<?= sanitize($editing['slug'] ?? '') ?>" placeholder="<?= sanitize(tr('مثال: من-نحن', 'e.g. about-us')) ?>">
            </div>
        </div>
        <div class="form-group">
            <label for="page-content"><?= sanitize(tr('محتوى الصفحة (HTML)', 'Page content (HTML)')) ?></label>
            <textarea id="page-content" class="form-control code-editor" name="content_html" rows="18" required><?= sanitize($editing['content_html'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
            <label for="page-css"><?= sanitize(tr('تنسيقات CSS الخاصة بالصفحة', 'Page-specific CSS')) ?></label>
            <textarea id="page-css" class="form-control code-editor" name="page_css" rows="12"><?= sanitize($editing['page_css'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
            <label for="page-js"><?= sanitize(tr('JavaScript (اختياري)', 'JavaScript (optional)')) ?></label>
            <textarea id="page-js" class="form-control code-editor" name="page_js" rows="12"><?= sanitize($editing['page_js'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
            <label for="page-status"><?= sanitize(tr('حالة الصفحة', 'Page status')) ?></label>
            <select id="page-status" class="form-control" name="status">
                <option value="published" <?= ($editing['status'] ?? 'published') === 'published' ? 'selected' : '' ?>><?= sanitize(tr('منشورة', 'Published')) ?></option>
                <option value="draft" <?= ($editing['status'] ?? '') === 'draft' ? 'selected' : '' ?>><?= sanitize(tr('مسودة', 'Draft')) ?></option>
            </select>
        </div>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= sanitize(tr('حفظ الصفحة', 'Save page')) ?></button>
            <?php if ($editing): ?><a class="btn" href="<?= sanitize(site_url('admin/pages.php')) ?>"><?= sanitize(tr('إلغاء', 'Cancel')) ?></a><?php endif; ?>
        </div>
    </form>
</section>

<section class="admin-panel">
    <h2><?= sanitize(tr('الصفحات الحالية', 'Current pages')) ?></h2>
    <div class="table-wrap">
        <table class="admin-table">
            <thead><tr>
                <th><?= sanitize(tr('العنوان', 'Title')) ?></th>
                <th><?= sanitize(tr('الرابط', 'URL')) ?></th>
                <th><?= sanitize(tr('الحالة', 'Status')) ?></th>
                <th><?= sanitize(tr('الإجراءات', 'Actions')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($pages as $page): ?>
                <tr>
                    <td><?= sanitize($page['title']) ?></td>
                    <td><code><?= sanitize($page['slug']) ?></code></td>
                    <td><?= sanitize($page['status'] === 'published' ? tr('منشورة', 'Published') : tr('مسودة', 'Draft')) ?></td>
                    <td class="admin-actions">
                        <?php if ($page['status'] === 'published'): ?><a class="btn btn-sm" href="<?= sanitize(site_url('page.php?slug=' . rawurlencode($page['slug']))) ?>" target="_blank" rel="noopener"><?= sanitize(tr('عرض', 'View')) ?></a><?php endif; ?>
                        <a class="btn btn-sm btn-primary" href="<?= sanitize(site_url('admin/pages.php?edit=' . $page['id'])) ?>"><?= sanitize(tr('تعديل', 'Edit')) ?></a>
                        <form method="POST" onsubmit="return confirm('<?= sanitize(tr('حذف الصفحة؟', 'Delete this page?')) ?>');">
                            <input type="hidden" name="action" value="delete_page">
                            <input type="hidden" name="id" value="<?= (int)$page['id'] ?>">
                            <button class="btn btn-sm btn-danger" type="submit"><?= sanitize(tr('حذف', 'Delete')) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php render_footer(); ?>
