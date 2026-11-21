<?php
/** Async statistics for current page only. Cached in JSON; never blocks dashboard HTML. */
require_once __DIR__ . '/dashboard_lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{}'; exit; }
$payload = json_decode((string)file_get_contents('php://input'), true);
$names = $payload['projects'] ?? [];
if (!is_array($names) || count($names) > 12) { http_response_code(400); echo '{}'; exit; }
$meta = dashJson('projects');
$cachePath = DASH_DATA . '/project_stats_cache.json';
$cache = json_decode((string)@file_get_contents($cachePath), true);
if (!is_array($cache)) $cache = [];
$results = []; $now = time(); $dbToCheck = []; $paths = [];
foreach ($names as $name) {
    if (!is_string($name) || !dashName($name)) continue;
    try { $paths[$name] = dashProject($name); } catch (Throwable $e) { continue; }
    $databases = dashDbNames($meta[$name] ?? []);
    $signature = implode(',', $databases);
    if (isset($cache[$name]) && ($cache[$name]['time'] ?? 0) > $now - 300 && ($cache[$name]['db_names'] ?? '') === $signature) {
        $results[$name] = $cache[$name]['result'];
        continue;
    }
    foreach ($databases as $db) $dbToCheck[$db] = true;
}
$dbSizes = $dbToCheck ? dashDbSizes(array_keys($dbToCheck)) : [];
foreach ($paths as $name => $path) {
    if (isset($results[$name])) continue;
    $databases = dashDbNames($meta[$name] ?? []);
    $bytes = dashFolderBytes($path);
    $dbTotal = 0; $known = true;
    foreach ($databases as $db) {
        if (!array_key_exists($db, $dbSizes)) { $known = false; break; }
        $dbTotal += $dbSizes[$db];
    }
    $result = [
        'files' => $bytes === null ? 'Unavailable' : dashBytes($bytes),
        'database' => !$databases ? 'Not linked' : ($known ? dashBytes($dbTotal) : 'Unavailable')
    ];
    $results[$name] = $result;
    $cache[$name] = ['time' => $now, 'db_names' => implode(',', $databases), 'result' => $result];
}
// Keep cache bounded and atomic. Cache is an optimization, not the source of truth.
$cache = array_filter($cache, static fn($entry) => ($entry['time'] ?? 0) > $now - 86400);
if (count($cache) > 500) $cache = array_slice($cache, -500, null, true);
$tmp = @tempnam(DASH_DATA, 'stats_');
if ($tmp !== false) {
    if (@file_put_contents($tmp, json_encode($cache, JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX) !== false) @rename($tmp, $cachePath);
    else @unlink($tmp);
}
echo json_encode($results, JSON_INVALID_UTF8_SUBSTITUTE);
