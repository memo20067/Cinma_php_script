<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$contactEmail = trim(get_setting('contact_email', ''));
$contactPhone = trim(get_setting('contact_phone', ''));
$contactWhatsapp = preg_replace('/\D+/', '', get_setting('contact_whatsapp', ''));
$hasContactOptions = $contactEmail !== '' || $contactPhone !== '' || $contactWhatsapp !== '';

render_header(tr('اتصل بنا', 'Contact'));
?>

<section class="page-heading">
    <p class="eyebrow"><?= sanitize(tr('نحن هنا لمساعدتك', "We're here to help")) ?></p>
    <h1><?= sanitize(tr('اتصل بنا', 'Contact us')) ?></h1>
    <p><?= sanitize(tr('اختر وسيلة التواصل المناسبة لك.', 'Choose the contact method that works best for you.')) ?></p>
</section>

<?php if ($hasContactOptions): ?>
    <section class="contact-grid" aria-label="<?= sanitize(tr('وسائل التواصل', 'Contact methods')) ?>">
        <?php if ($contactEmail !== ''): ?>
            <article class="contact-card">
                <span class="contact-label"><?= sanitize(tr('البريد الإلكتروني', 'Email')) ?></span>
                <h2><?= sanitize(tr('أرسل لنا بريدًا إلكترونيًا', 'Send us an email')) ?></h2>
                <p><?= sanitize(tr('للاستفسارات أو الدعم، تواصل معنا عبر:', 'For questions or support, reach us at:')) ?></p>
                <a class="btn btn-primary" href="mailto:<?= sanitize($contactEmail) ?>"><?= sanitize($contactEmail) ?></a>
            </article>
        <?php endif; ?>

        <?php if ($contactPhone !== ''): ?>
            <article class="contact-card">
                <span class="contact-label"><?= sanitize(tr('الهاتف', 'Phone')) ?></span>
                <h2><?= sanitize(tr('اتصل بنا', 'Call us')) ?></h2>
                <p><?= sanitize(tr('تواصل معنا هاتفيًا:', 'Speak with us by phone:')) ?></p>
                <a class="btn btn-primary" href="tel:<?= sanitize($contactPhone) ?>"><?= sanitize($contactPhone) ?></a>
            </article>
        <?php endif; ?>

        <?php if ($contactWhatsapp !== ''): ?>
            <article class="contact-card">
                <span class="contact-label"><?= sanitize(tr('واتساب', 'WhatsApp')) ?></span>
                <h2><?= sanitize(tr('راسلنا', 'Message us')) ?></h2>
                <p><?= sanitize(tr('ابدأ محادثة عبر واتساب:', 'Start a WhatsApp conversation:')) ?></p>
                <a class="btn btn-success" href="https://wa.me/<?= sanitize($contactWhatsapp) ?>" target="_blank" rel="noopener noreferrer"><?= sanitize(tr('فتح واتساب', 'Open WhatsApp')) ?></a>
            </article>
        <?php endif; ?>
    </section>
<?php else: ?>
    <div class="alert alert-info">
        <?= sanitize(tr('لم تتم إضافة بيانات التواصل بعد. يمكن لمسؤول الموقع إضافة البريد الإلكتروني أو رقم الهاتف أو رقم واتساب من لوحة الإدارة ثم الصفحة الرئيسية والإعدادات.', 'Contact details have not been added yet. The site administrator can add an email address, phone number, or WhatsApp number in Admin Panel &gt; Overview &amp; Settings.')) ?>
    </div>
<?php endif; ?>

<?php render_footer(); ?>
