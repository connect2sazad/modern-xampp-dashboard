<?php
require_once __DIR__ . '/project_preferences.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    $path = dashProject($_GET['project'] ?? '');
    session_write_close();
    if (!file_exists($path . '/.git')) throw new RuntimeException('This project has no Git repository.');
    echo json_encode(['origin' => dashDisplayOrigin(dashGitOrigin($path))], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
