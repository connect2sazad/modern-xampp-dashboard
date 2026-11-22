<?php
require_once __DIR__ . '/mxdp_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

try {
    dashCsrf();
    $pending = $_SESSION['dashboard_db_setup'] ?? null;
    if (!$pending || ($pending['project'] !== ($_POST['project'] ?? null))) {
        throw new RuntimeException('The database setup session has expired.');
    }
    if (($_POST['decision'] ?? '') === 'skip') {
        if ($pending['work']) dashCleanBackupWorkDir($pending['work']);
        unset($_SESSION['dashboard_db_setup']);
        dashRedirect();
    }
    if ($pending['work'] && !is_dir($pending['work'])) {
        unset($_SESSION['dashboard_db_setup']);
        throw new RuntimeException('The staged backup has expired. Open the MXDP file again to import its databases.');
    }
    $root = dashProject($pending['project']);
    $selected = $_POST['sql_files'] ?? [];
    $names = $_POST['database_names'] ?? [];
    if (!is_array($selected) || !is_array($names) || count($selected) < 1 || count($selected) > 20) {
        throw new RuntimeException('Select between 1 and 20 SQL files.');
    }
    $config = dashDatabaseConfig();
    $pdo = dashDatabase($config);
    $imports = [];
    foreach (array_unique($selected) as $index) {
        if (!is_string($index) || !ctype_digit($index) || !isset($pending['files'][$index])) {
            throw new RuntimeException('Invalid SQL selection.');
        }
        $file = $pending['files'][$index];
        $name = dashDatabaseName(trim($names[$index] ?? ''));
        if (dashDatabaseExists($pdo, $name)) throw new RuntimeException('Database already exists: ' . $name);
        $key = strtolower($name);
        if (isset($imports[$key])) throw new RuntimeException('Choose a distinct database name for each selected SQL file.');
        $path = $file['source'] === 'backup'
            ? $pending['work'] . '/' . $file['index'] . '.sql'
            : dashFilePath($root, $file['label']);
        $imports[$key] = [$index, $name, $path];
    }
    $existing = dashDbNames(dashJson('projects')[$pending['project']] ?? []);
    foreach ($imports as [$index, $name, $path]) {
        dashImportDatabase($pdo, $config, $name, $path);
        $existing[] = $name;
        try {
            dashMeta($pending['project'], ['databases' => implode(', ', array_unique($existing))]);
        } catch (Throwable $e) {
            $pdo->exec('DROP DATABASE `' . $name . '`');
            throw $e;
        }
        unset($_SESSION['dashboard_db_setup']['files'][$index]);
        if ($pending['files'][$index]['source'] === 'backup') @unlink($path);
    }
    if ($pending['work']) dashCleanBackupWorkDir($pending['work']);
    unset($_SESSION['dashboard_db_setup']);
    dashNotice('Imported and linked ' . count($imports) . ' database(s).');
} catch (Throwable $e) {
    if (isset($_SESSION['dashboard_db_setup'])) {
        $_SESSION['dashboard_db_setup']['notice'] = $e->getMessage();
    } else {
        dashNotice($e->getMessage(), true);
    }
}
dashRedirect();
