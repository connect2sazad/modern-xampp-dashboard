<?php
require_once __DIR__ . '/mxdp_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
$work = null;
$kind = 'mxdp';
$savedId = '';
try {
    dashCsrf();
    $name = trim($_POST['project'] ?? '');
    if (!dashName($name)) throw new RuntimeException('Invalid project name.');
    $savedId = $_POST['backup_id'] ?? '';
    if ($savedId !== '') {
        $path = dashBackupRecord($savedId)['path'];
    } else {
        $upload = $_FILES['archive'] ?? [];
        $kind = strtolower(pathinfo($upload['name'] ?? '', PATHINFO_EXTENSION));
        if (($upload['error'] ?? null) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])
            || !in_array($kind, ['zip', 'mxdp'], true)) {
            throw new RuntimeException('Upload a ZIP or MXDP file within the PHP upload limit.');
        }
        $path = $upload['tmp_name'];
    }
    $work = dashBackupWorkDir();
    $manifest = $kind === 'mxdp'
        ? dashOpenMxdp($path, $_POST['password'] ?? '', $work)
        : dashOpenBackupZip($path, $work, false);
    $hasFiles = $manifest['files'] ?? true;
    if ($hasFiles) {
        dashRestore($manifest === null ? $path : $work . '/project.zip', dashProject($name, false));
        if ($manifest !== null) unlink($work . '/project.zip');
        dashMeta($name, ['created_at' => date(DATE_ATOM), 'kind' => 'restored']);
    } elseif (!file_exists(dashProjectsRoot() . DIRECTORY_SEPARATOR . $name)) {
        if (!mkdir(dashProject($name, false), 0755)) throw new RuntimeException('Could not create project directory.');
        dashMeta($name, ['created_at' => date(DATE_ATOM), 'kind' => 'restored']);
    } else {
        $name = basename(dashProject($name));
    }
    dashQueueDatabaseSetup($name, $manifest['databases'] ?? [], $work, $hasFiles);
    $work = null;
    dashNotice($hasFiles ? 'Restored project ' . $name . '.' : 'Database backup opened for ' . $name . '.');
} catch (Throwable $e) {
    $_SESSION['dashboard_open_restore'] = $e->getMessage();
    $_SESSION['dashboard_restore_kind'] = $kind === 'zip' ? 'zip' : 'mxdp';
    if (is_string($savedId) && preg_match('/\A[a-f0-9]{32}\z/', $savedId)) {
        $_SESSION['dashboard_restore_id'] = $savedId;
    }
} finally {
    if ($work !== null) dashCleanBackupWorkDir($work);
}
dashRedirect();
