<?php
require_once __DIR__ . '/dashboard_lib.php';
require_once __DIR__ . '/job_runner.php';

startJobStream();
$lock = null;
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('POST required.');
    }
    dashCsrf();
    $path = dashProject($_POST['project'] ?? '');
    if (!is_file(dashFilePath($path, 'composer.json'))) {
        throw new RuntimeException('This project has no composer.json.');
    }
    $commands = [
        'install' => ['install', '--prefer-dist', '--no-progress'],
        'update' => ['update', '--prefer-dist', '--no-progress'],
        'validate' => ['validate', '--no-check-publish'],
        'platform' => ['check-platform-reqs'],
        'outdated' => ['outdated', '--direct'],
        'audit' => ['audit', '--locked'],
    ];
    $action = $_POST['action'] ?? '';
    if (!is_string($action) || !isset($commands[$action])) throw new RuntimeException('Unknown Composer command.');
    if ($action === 'platform' && is_file($path . '/composer.lock')) $commands[$action][] = '--lock';

    $lockPath = sys_get_temp_dir() . '/xampp-dashboard-composer-' . hash('sha256', strtolower($path)) . '.lock';
    $lock = @fopen($lockPath, 'c');
    if ($lock === false) throw new RuntimeException('Could not open the project job lock.');
    if (!flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('A Composer command is already running for this project.');
    session_write_close();
    $stage = 'Composer ' . $commands[$action][0];
    runJob(array_merge(['composer', '--no-interaction', '--no-ansi'], $commands[$action]), $stage, $path);
    clearstatcache();
    emitJob('project', $stage, json_encode([
        'dependencies' => is_file($path . '/vendor/autoload.php'),
        'lockfile' => is_file($path . '/composer.lock'),
    ], JSON_THROW_ON_ERROR));
    emitJob('done', $stage, $stage . ' completed.');
} catch (Throwable $e) {
    emitJob('error', 'Failed', $e->getMessage());
} finally {
    if (is_resource($lock)) fclose($lock);
}
