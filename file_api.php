<?php
require_once __DIR__ . '/dashboard_lib.php';
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$lock = null;
try {
    $method = $_SERVER['REQUEST_METHOD'];
    $data = $method === 'POST' ? json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR) : $_GET;
    if (!is_array($data)) throw new RuntimeException('Invalid request.');
    $action = $data['action'] ?? '';
    $allowed = $method === 'GET' ? ['list', 'browse', 'search', 'read'] : ($method === 'POST' ? ['save', 'delete', 'rename', 'create_file', 'create_folder'] : []);
    if (!in_array($action, $allowed, true)) {
        http_response_code(405);
        exit('Unsupported action or method.');
    }
    if ($method === 'POST') dashCsrf($data['csrf'] ?? '');
    $root = dashProject($data['project'] ?? '');
    session_write_close();
    if ($action === 'browse') {
        $folder = $data['folder'] ?? '';
        $directory = $folder === '' ? $root : dashFilePath($root, $folder);
        if (!is_dir($directory)) throw new RuntimeException('Folder does not exist.');
        $items = [];
        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            $relative = substr($entry->getPathname(), strlen($root) + 1);
            try {
                dashFilePath($root, $relative);
                $items[] = ['name' => $entry->getFilename(), 'path' => str_replace('\\', '/', $relative), 'folder' => $entry->isDir()];
            } catch (RuntimeException $e) {
                continue;
            }
        }
        usort($items, static fn($a, $b) => ($b['folder'] <=> $a['folder']) ?: strnatcasecmp($a['name'], $b['name']));
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($items, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    if ($action === 'search') {
        $query = trim($data['q'] ?? '');
        $files = [];
        if ($query !== '') {
            $tree = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
            $safeTree = new RecursiveCallbackFilterIterator($tree, static function ($entry) use ($root) {
                if ($entry->isDir() && in_array(strtolower($entry->getFilename()), ['vendor', 'node_modules'], true)) return false;
                try {
                    dashFilePath($root, substr($entry->getPathname(), strlen($root) + 1));
                    return true;
                } catch (RuntimeException $e) {
                    return false;
                }
            });
            foreach (new RecursiveIteratorIterator($safeTree, RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD) as $entry) {
                $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
                if ($entry->isFile() && stripos($relative, $query) !== false) $files[] = $relative;
                if (count($files) >= 80) break;
            }
            natcasesort($files);
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_values($files), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
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
    if ($method === 'POST') {
        $lock = @fopen(sys_get_temp_dir() . '/xampp-dashboard-editor-' . hash('sha256', strtolower($root)) . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Could not lock project files for writing.');
    }
    if (in_array($action, ['create_file', 'create_folder'], true)) {
        $folder = $action === 'create_folder';
        dashCreateProjectEntry($root, $relative, $folder);
        echo $folder ? 'Folder created.' : 'File created.';
        return;
    }
    $path = dashFilePath($root, $relative);
    switch ($action) {
        case 'read':
            if (!is_file($path) || ($contents = @file_get_contents($path, false, null, 0, 2097153)) === false) throw new RuntimeException('Could not read file.');
            if (strlen($contents) > 2097152 || strpos($contents, "\0") !== false || !preg_match('//u', $contents)) throw new RuntimeException('The editor supports UTF-8 text files up to 2 MB.');
            header('X-File-Version: ' . hash('sha256', $contents));
            echo $contents;
            break;
        case 'save':
            $contents = $data['content'] ?? null;
            if (!is_file($path) || !is_string($contents) || strlen($contents) > 2097152) throw new RuntimeException('Invalid file or contents.');
            if (isset($data['version']) && $data['version'] !== hash_file('sha256', $path)) {
                http_response_code(409);
                echo 'This file changed on disk. Copy your edits, reopen the file, and merge the changes before saving.';
                return;
            }
            dashWrite($path, $contents);
            header('X-File-Version: ' . hash('sha256', $contents));
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
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo $e->getMessage();
} finally {
    if (is_resource($lock)) fclose($lock);
}
