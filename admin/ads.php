<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

require_permission('superuser');

$slots = ['header', 'content_top', 'content_bottom', 'footer'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_ads') {
    foreach ($slots as $slot) {
        $code = trim($_POST['ad_slot_' . $slot] ?? '');
        if (strlen($code) > 50000) {
            $error = tr('يجب ألا يتجاوز كود الإعلان 50 ألف حرف لكل موضع.', 'Ad code must not exceed 50,000 characters per placement.');
            break;
        }
    }
    if ($error === '') {
        foreach ($slots as $slot) {
            update_setting('ad_slot_' . $slot, trim($_POST['ad_slot_' . $slot] ?? ''));
        }
        $message = tr('تم حفظ إعدادات الإعلانات.', 'Ad settings saved.');
    }
}

render_header(tr('إعدادات الإعلانات', 'Ad settings'));
?>

<header class="admin-page-heading">
    <div>
        <p class="eyebrow"><?= sanitize(tr('مدير أعلى', 'SUPERUSER')) ?></p>
        <h1><?= sanitize(tr('مواضع الإعلانات', 'Ad placements')) ?></h1>
    </div>
</header>

<?php if ($message !== ''): ?><div class="alert alert-success"><?= sanitize($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= sanitize($error) ?></div><?php endif; ?>

<section class="admin-panel">
    <p class="form-help"><?= sanitize(tr(
        'ألصق كود AdSense أو HTML/JavaScript من منصة إعلانية موثوقة. يظهر الكود كما هو في الموضع المحدد؛ لا تسمح لأي حساب غير موثوق بتعديل هذه الإعدادات.',
        'Paste AdSense or trusted advertising-platform HTML/JavaScript. Code is rendered as-is in the selected placement; do not grant untrusted accounts access to these settings.'
    )) ?></p>
    <form method="POST">
        <input type="hidden" name="action" value="save_ads">
        <?php foreach ($slots as $slot): ?>
            <?php
            $setting = 'ad_slot_' . $slot;
            $constant = 'AD_SLOT_' . strtoupper($slot);
            $defaultCode = defined($constant) ? constant($constant) : '';
            ?>
            <div class="form-group">
                <label for="<?= sanitize($setting) ?>"><?= sanitize(tr(
                    ['header' => 'أسفل الترويسة', 'content_top' => 'أعلى المحتوى', 'content_bottom' => 'أسفل المحتوى', 'footer' => 'أعلى الفوتر'][$slot],
                    ['header' => 'Below header', 'content_top' => 'Top of content', 'content_bottom' => 'Bottom of content', 'footer' => 'Above footer'][$slot]
                )) ?></label>
                <textarea id="<?= sanitize($setting) ?>" class="form-control code-editor" name="<?= sanitize($setting) ?>" rows="6" maxlength="50000"><?= sanitize(get_setting($setting, $defaultCode)) ?></textarea>
            </div>
        <?php endforeach; ?>
        <button class="btn btn-primary" type="submit"><?= sanitize(tr('حفظ الإعلانات', 'Save ad placements')) ?></button>
    </form>
</section>

<?php render_footer(); ?>
