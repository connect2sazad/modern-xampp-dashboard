<?php
require_once __DIR__ . '/dashboard_lib.php';
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $data = $method === 'POST' ? json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR) : $_GET;
    if (!is_array($data)) throw new RuntimeException('Invalid request.');
    $action = $data['action'] ?? '';
    $allowed = $method === 'GET' ? ['list', 'read'] : ($method === 'POST' ? ['save', 'delete', 'rename', 'create_file', 'create_folder'] : []);
    if (!in_array($action, $allowed, true)) {
        http_response_code(405);
        exit('Unsupported action or method.');
    }
    if ($method === 'POST') dashCsrf($data['csrf'] ?? '');
    $root = dashProject($data['project'] ?? '');
    session_write_close();
    if ($action === 'list') {
        $files = [];
        $entries = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
        $safeEntries = new RecursiveCallbackFilterIterator($entries, static function ($entry) use ($root) {
            try {
                $relative = substr($entry->getPathname(), strlen($root) + 1);
                dashFilePath($root, $relative);
                return true;
            } catch (RuntimeException $e) {
                return false;
            }
        });
        foreach (new RecursiveIteratorIterator($safeEntries) as $entry) {
            if ($entry->isFile()) $files[] = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
        }
        natcasesort($files);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_values($files), JSON_THROW_ON_ERROR);
        exit;
    }
    $relative = $data[$action === 'rename' ? 'old' : ($action === 'create_folder' ? 'folder' : 'file')] ?? '';
    $create = in_array($action, ['create_file', 'create_folder'], true);
    $path = dashFilePath($root, $relative, $create);
    switch ($action) {
        case 'read':
            if (!is_file($path) || ($contents = @file_get_contents($path, false, null, 0, 2097153)) === false) throw new RuntimeException('Could not read file.');
            if (strlen($contents) > 2097152 || strpos($contents, "\0") !== false || !preg_match('//u', $contents)) throw new RuntimeException('The editor supports UTF-8 text files up to 2 MB.');
            echo $contents;
            break;
        case 'save':
            $contents = $data['content'] ?? null;
            if (!is_file($path) || !is_string($contents) || strlen($contents) > 2097152) throw new RuntimeException('Invalid file or contents.');
            dashWrite($path, $contents);
            echo 'File saved.';
            break;
        case 'delete':
            if (!(is_dir($path) ? @rmdir($path) : @unlink($path))) throw new RuntimeException('Could not delete entry.');
            echo 'Deleted.';
            break;
        case 'rename':
            $destination = dashFilePath($root, $data['new'] ?? '', true);
            if (file_exists($destination) || !@rename($path, $destination)) throw new RuntimeException('Could not rename entry. Check that the destination is available.');
            echo 'Renamed.';
            break;
        case 'create_file':
            $handle = @fopen($path, 'xb');
            if ($handle === false) throw new RuntimeException('Could not create file. Check that the name is available.');
            fclose($handle);
            echo 'File created.';
            break;
        case 'create_folder':
            if (!@mkdir($path, 0755)) throw new RuntimeException('Could not create folder. Check that the name is available.');
            echo 'Folder created.';
            break;
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo $e->getMessage();
}
