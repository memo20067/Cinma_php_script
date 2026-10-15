<?php
// Admin uploads scanner and media linker

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scanner.php';

require_permission('moderator');
ensure_video_compatibility_schema();

$message = '';
$error = '';
$scanToken = $_SESSION['scan_files_csrf'] ?? '';
if ($scanToken === '') {
    $scanToken = bin2hex(random_bytes(32));
    $_SESSION['scan_files_csrf'] = $scanToken;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    $operation = $_POST['operation'] ?? '';
    $selectedFiles = $_POST['selected_files'] ?? [];
    if (!is_string($postedToken) || !hash_equals($scanToken, $postedToken)) {
        $error = tr('انتهت صلاحية الطلب. حدّث الصفحة وحاول مجدداً.', 'The request expired. Refresh the page and try again.');
    } elseif (!in_array($operation, ['link', 'delete'], true) || !is_array($selectedFiles) || !$selectedFiles) {
        $error = tr('اختر ملفات صالحة وعملية ربط أو حذف.', 'Select files and choose a valid link or delete action.');
    } elseif ($operation === 'delete') {
        $deleted = 0;
        try {
            foreach ($selectedFiles as $filePath) {
                if (!is_string($filePath) || !is_managed_upload_file($filePath)) {
                    throw new RuntimeException(tr('الملف المحدد غير موجود داخل uploads أو لم يعد صالحاً.', 'The selected file is outside uploads or is no longer available.'));
                }
                $realPath = realpath($filePath);
                DB::delete('media_files', 'file_path = ?', [$realPath]);
                DB::delete('episodes', 'file_path = ?', [$realPath]);
                if (!unlink($realPath)) {
                    throw new RuntimeException(tr('تعذر حذف أحد الملفات المحددة.', 'Could not delete one of the selected files.'));
                }
                $deleted++;
            }
            $message = tr('تم حذف الملفات المحددة وروابطها: ', 'Deleted selected files and their links: ') . $deleted;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    } else {
        $mediaId = (int)($_POST['media_id'] ?? 0);
        $target = DB::fetch("SELECT id, title, type, access_level FROM {prefix}media WHERE id = ?", [$mediaId]);
        if (!$target || !in_array($target['type'], ['movie', 'tv', 'software', 'game'], true)) {
            $error = tr('اختر محتوى موجوداً وصالحاً لربط الملفات به.', 'Choose a valid existing media item to link files to.');
        } else {
            $linked = 0;
            $linkErrors = [];
            foreach ($selectedFiles as $filePath) {
                try {
                    if (!is_string($filePath) || !is_managed_upload_file($filePath)) {
                        throw new RuntimeException(tr('الملف غير موجود داخل uploads.', 'The file is not available inside uploads.'));
                    }
                    $sourcePath = realpath($filePath);
                    $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
                    $videoExtensions = ['mp4', 'm4v', 'mkv', 'avi', 'mov', 'wmv', 'flv', 'webm'];
                    $binaryExtensions = ['zip', '7z', 'rar', 'tar', 'gz', 'exe', 'msi', 'apk', 'dmg', 'deb', 'rpm', 'pkg', 'iso', 'pdf'];
                    $isVideoTarget = in_array($target['type'], ['movie', 'tv'], true);
                    if ($isVideoTarget !== in_array($extension, $videoExtensions, true)
                        || (!$isVideoTarget && !in_array($extension, $binaryExtensions, true))) {
                        throw new RuntimeException(tr('نوع الملف لا يطابق نوع الفيلم أو البرنامج أو اللعبة المحدد.', 'The file type does not match the selected movie, software, or game.'));
                    }
                    $alreadyLinked = DB::fetch(
                        "SELECT id FROM {prefix}media_files WHERE file_path = ? LIMIT 1",
                        [$sourcePath]
                    );
                    if ($alreadyLinked) {
                        throw new RuntimeException(tr('هذا الملف مرتبط بالفعل بمحتوى آخر.', 'This file is already linked to media.'));
                    }
                    $hash = md5($filePath);
                    $seasonNumber = (int)($_POST['season_' . $hash] ?? 1);
                    $episodeNumber = (int)($_POST['episode_' . $hash] ?? 1);
                    if ($target['type'] === 'tv' && ($seasonNumber < 1 || $episodeNumber < 1)) {
                        throw new RuntimeException(tr('أدخل رقماً صالحاً للموسم والحلقة.', 'Enter a valid season and episode number.'));
                    }
                    if ($target['type'] === 'tv' && DB::fetch(
                        "SELECT id FROM {prefix}episodes WHERE media_id = ? AND season_number = ? AND episode_number = ?",
                        [$mediaId, $seasonNumber, $episodeNumber]
                    )) {
                        throw new RuntimeException(tr('رقم الحلقة موجود بالفعل في هذا المسلسل.', 'That episode number already exists in this series.'));
                    }

                    $contentDirectory = media_upload_directory($mediaId, $target['type'], $target['title']);
                    $subfolder = $isVideoTarget ? 'videos' : 'files';
                    $destinationDirectory = $contentDirectory . DIRECTORY_SEPARATOR . $subfolder;
                    if (!is_dir($destinationDirectory) && !mkdir($destinationDirectory, 0775, true) && !is_dir($destinationDirectory)) {
                        throw new RuntimeException(tr('تعذر إنشاء مجلد المحتوى.', 'Could not create the media directory.'));
                    }
                    $destination = $destinationDirectory . DIRECTORY_SEPARATOR
                        . bin2hex(random_bytes(8)) . '-' . basename($sourcePath);
                    if (!copy($sourcePath, $destination)) {
                        throw new RuntimeException(tr('تعذر نقل نسخة الملف إلى مجلد المحتوى.', 'Could not copy the file into the content folder.'));
                    }
                    $pdo = DB::getInstance()->getPDO();
                    try {
                        $pdo->beginTransaction();
                        if ($target['type'] === 'tv') {
                            DB::insert('episodes', [
                                'media_id' => $mediaId,
                                'season_number' => $seasonNumber,
                                'episode_number' => $episodeNumber,
                                'title' => trim($_POST['title_' . $hash] ?? '') ?: tr('الحلقة ', 'Episode ') . $episodeNumber,
                                'file_path' => $destination,
                                'duration' => 0
                            ]);
                        }
                        DB::insert('media_files', [
                            'media_id' => $mediaId,
                            'file_path' => $destination,
                            'file_size' => filesize($destination),
                            'file_type' => $isVideoTarget ? 'video' : 'binary',
                            'access_level' => $target['access_level']
                        ]);
                        $pdo->commit();
                    } catch (Throwable $exception) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        if (is_file($destination)) {
                            unlink($destination);
                        }
                        throw $exception;
                    }
                    $linked++;
                    if (!unlink($sourcePath)) {
                        $linkErrors[] = basename((string)$filePath) . ': '
                            . tr('تم ربطه لكن بقيت النسخة الأصلية داخل مجلد الفحص.', 'linked, but the original file remains in the scan directory.');
                    }
                } catch (Throwable $exception) {
                    $linkErrors[] = basename((string)$filePath) . ': ' . $exception->getMessage();
                }
            }
            $message = tr('تم ربط الملفات بنجاح: ', 'Files linked successfully: ') . $linked;
            if ($linkErrors) {
                $error = implode(' ', $linkErrors);
            }
        }
    }
}

$scanPath = $_GET['scan_dir'] ?? __DIR__ . '/../uploads';
$scannedFiles = is_dir($scanPath) ? MediaScanner::scanDirectory($scanPath) : [];
$mediaTargets = DB::fetchAll("SELECT id, title, type FROM {prefix}media ORDER BY title ASC");

render_header(tr('فحص الملفات وربط المحتوى', 'File scanner and media linking'));
?>

<div style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center;">
    <h1><?= sanitize(tr('فحص الملفات وربط الوسائط', 'Scan files and link media')) ?></h1>
    <a href="<?= sanitize(site_url('admin/index.php')) ?>" class="btn">&larr; <?= sanitize(tr('العودة إلى لوحة الإدارة', 'Back to Admin Dashboard')) ?></a>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= sanitize($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><?= sanitize($error) ?></div>
<?php endif; ?>

<div style="background: #1e293b; padding: 1.5rem; border-radius: 8px; border: 1px solid #334155; margin-bottom: 2rem;">
    <form method="GET" style="display: flex; gap: 1rem;">
        <input type="text" name="scan_dir" class="form-control" value="<?= sanitize($scanPath) ?>" placeholder="<?= sanitize(tr('مسار المجلد على الخادم…', 'Directory path on server...')) ?>" style="flex: 1;">
        <button type="submit" class="btn btn-primary"><?= sanitize(tr('فحص مجلد الخادم', 'Scan Server Directory')) ?></button>
    </form>
    <div style="font-size: 0.85rem; color: #94a3b8; margin-top: 0.5rem;">
        <?= sanitize(tr('يفحص مجلد uploads فقط بحثاً عن الفيديوهات وملفات البرامج والألعاب. عند الربط تُنقل نسخة إلى مجلد المحتوى الخاص.', 'Scans only the uploads folder for videos and software/game files. Linking copies each file into its content-specific folder.')) ?>
    </div>
</div>

<?php if (empty($scannedFiles)): ?>
    <div class="alert alert-info"><?= sanitize(tr('لم يتم العثور على ملفات وسائط مدعومة في المسار المحدد.', 'No supported media files were found in the specified path.')) ?></div>
<?php else: ?>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= sanitize($scanToken) ?>">
        <div style="background: #1e293b; padding: 1.5rem; border-radius: 8px; border: 1px solid #334155; margin-bottom: 1.5rem;">
            <h2 style="font-size: 1.2rem; margin-bottom: 1rem; color: #38bdf8;"><?= sanitize(tr('ربط الملفات أو حذفها', 'Link or delete scanned files')) ?></h2>
            <div class="settings-grid">
                <div class="form-group">
                    <label for="scan-operation"><?= sanitize(tr('العملية', 'Action')) ?></label>
                    <select id="scan-operation" name="operation" class="form-control">
                        <option value="link"><?= sanitize(tr('ربط بالمحتوى المحدد', 'Link to selected media')) ?></option>
                        <option value="delete"><?= sanitize(tr('حذف الملفات المحددة نهائياً', 'Permanently delete selected files')) ?></option>
                    </select>
                </div>
                <div class="form-group" id="scan-target-group">
                    <label for="scan-media-id"><?= sanitize(tr('الفيلم أو المسلسل أو البرنامج أو اللعبة', 'Movie, series, software, or game')) ?></label>
                    <select id="scan-media-id" name="media_id" class="form-control" required>
                        <option value=""><?= sanitize(tr('-- اختر المحتوى --', '-- Choose media --')) ?></option>
                        <?php foreach ($mediaTargets as $target): ?>
                        <option value="<?= (int)$target['id'] ?>" data-media-type="<?= sanitize($target['type']) ?>"><?= sanitize([
                            'movie' => tr('فيلم', 'Movie'),
                            'tv' => tr('مسلسل', 'TV series'),
                            'software' => tr('برنامج', 'Software'),
                            'game' => tr('لعبة', 'Game')
                        ][$target['type']] ?? $target['type']) ?> · <?= sanitize($target['title']) ?></option>
                    <?php endforeach; ?>
                </select>
                </div>
            </div>
        </div>

        <table style="width: 100%; border-collapse: collapse; background: #1e293b; border-radius: 8px; overflow: hidden;">
            <thead>
                <tr style="background: #334155; text-align: left;">
                    <th style="padding: 0.75rem; width: 40px;"><input type="checkbox" onclick="toggleAll(this)"></th>
                    <th style="padding: 0.75rem;"><?= sanitize(tr('اسم الملف / المسار', 'File Name / Path')) ?></th>
                    <th style="padding: 0.75rem;"><?= sanitize(tr('العنوان المكتشف', 'Detected Title')) ?></th>
                    <th style="padding: 0.75rem; width: 90px;"><?= sanitize(tr('الموسم', 'Season')) ?></th>
                    <th style="padding: 0.75rem; width: 90px;"><?= sanitize(tr('الحلقة', 'Episode')) ?></th>
                    <th style="padding: 0.75rem;"><?= sanitize(tr('الحجم', 'Size')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($scannedFiles as $file):
                    $hash = md5($file['file_path']);
                ?>
                    <tr style="border-bottom: 1px solid #334155;">
                        <td style="padding: 0.75rem;">
                            <input type="checkbox" name="selected_files[]" value="<?= sanitize($file['file_path']) ?>">
                        </td>
                        <td style="padding: 0.75rem;">
                            <strong style="color: #f8fafc;"><?= sanitize($file['file_name']) ?></strong><br>
                            <span style="font-size: 0.8rem; color: #64748b;"><?= sanitize($file['file_path']) ?></span>
                        </td>
                        <td style="padding: 0.75rem;">
                            <input type="text" name="title_<?= $hash ?>" class="form-control" value="<?= sanitize($file['parsed_title']) ?>">
                        </td>
                        <td style="padding: 0.75rem;">
                            <input type="number" name="season_<?= $hash ?>" class="form-control" value="<?= $file['season'] ?? 1 ?>" min="1">
                        </td>
                        <td style="padding: 0.75rem;">
                            <input type="number" name="episode_<?= $hash ?>" class="form-control" value="<?= $file['episode'] ?? 1 ?>" min="1">
                        </td>
                        <td style="padding: 0.75rem; font-size: 0.85rem; color: #94a3b8;">
                            <?= format_size($file['file_size']) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top: 1.5rem; text-align: right;">
            <button id="scan-submit" type="submit" class="btn btn-success" style="padding: 0.75rem 1.5rem; font-size: 1rem;">
                <?= sanitize(tr('تنفيذ العملية على الملفات المحددة', 'Apply action to selected files')) ?>
            </button>
        </div>
    </form>
<?php endif; ?>

<script>
function toggleAll(master) {
    const checkboxes = document.querySelectorAll('input[name="selected_files[]"]');
    checkboxes.forEach(cb => cb.checked = master.checked);
}
const scanOperation = document.getElementById('scan-operation');
if (scanOperation) {
    scanOperation.addEventListener('change', function () {
        const deleting = this.value === 'delete';
        const target = document.getElementById('scan-media-id');
        document.getElementById('scan-target-group').hidden = deleting;
        target.required = !deleting;
        target.disabled = deleting;
        document.getElementById('scan-submit').classList.toggle('btn-danger', deleting);
        document.getElementById('scan-submit').onclick = deleting
            ? () => confirm(<?= json_encode(tr('سيتم حذف الملفات المختارة وروابطها نهائياً. هل تريد المتابعة؟', 'Selected files and their media links will be permanently deleted. Continue?'), JSON_UNESCAPED_UNICODE) ?>)
            : null;
    });
}
</script>

<?php
render_footer();
