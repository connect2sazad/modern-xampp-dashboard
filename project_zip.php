<?php
require_once __DIR__ . '/mxdp_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
$work = null;
try {
    dashCsrf();
    $project = $_POST['project'] ?? '';
    $root = dashProject($project);
    $project = basename($root);
    $databases = dashDbNames(dashJson('projects')[$project] ?? []);
    session_write_close();
    $work = dashBackupWorkDir();
    $file = $work . '/export.zip';
    dashWriteBackupZip($file, $work, $project, $root, $databases);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $project . '.zip"');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: no-store');
    readfile($file);
} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
} finally {
    if ($work !== null) dashCleanBackupWorkDir($work);
}
