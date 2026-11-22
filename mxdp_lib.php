<?php
require_once __DIR__ . '/dashboard_lib.php';

const MXDP_MAGIC = "MXDP\x01";
const MXDP_LIMIT = 50 * 1024 * 1024;

function dashDatabaseName(string $name): string {
    if (!preg_match('/\A[A-Za-z0-9_$-]{1,64}\z/', $name)
        || in_array(strtolower($name), ['mysql', 'sys', 'performance_schema', 'information_schema'], true)) {
        throw new RuntimeException('Invalid or reserved database name: ' . $name);
    }
    return $name;
}

function dashDatabaseConfig(): array {
    $settings = dashJson('settings');
    $host = (string)($settings['db_host'] ?? '127.0.0.1');
    $port = (int)($settings['db_port'] ?? 3306);
    if (!preg_match('/\A[A-Za-z0-9.:-]+\z/', $host) || $port < 1 || $port > 65535) {
        throw new RuntimeException('Invalid database host or port.');
    }
    return [
        'host' => $host, 'port' => $port,
        'user' => (string)($settings['db_user'] ?? 'root'),
        'password' => (string)($settings['db_password'] ?? ''),
    ];
}

function dashDatabase(array $config): PDO {
    return new PDO(
        'mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';charset=utf8mb4',
        $config['user'], $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );
}

function dashDatabaseExists(PDO $pdo, string $name): bool {
    $query = $pdo->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $query->execute([$name]);
    return (bool)$query->fetchColumn();
}

function dashDatabaseClient(string $program, array $config, array $arguments, ?string $input, ?string $output): void {
    $exe = dirname(__DIR__, 2) . '/mysql/bin/' . $program . '.exe';
    if (!is_file($exe)) $exe = $program;
    $options = tempnam(DASH_DATA, 'mysql_');
    if ($options === false) throw new RuntimeException('Could not create database client settings.');
    $log = tmpfile();
    try {
        if ($log === false) throw new RuntimeException('Could not create database client log.');
        $contents = "[client]\n";
        foreach ($config as $key => $value) {
            $contents .= $key . '="' . addcslashes((string)$value, "\\\"\r\n") . "\"\n";
        }
        if (file_put_contents($options, $contents) !== strlen($contents)) {
            throw new RuntimeException('Could not write database client settings.');
        }
        $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $process = @proc_open(
            array_merge([$exe, '--defaults-file=' . $options, '--protocol=tcp'], $arguments),
            [0 => ['file', $input ?? $null, 'r'], 1 => ['file', $output ?? $null, 'w'], 2 => $log],
            $pipes
        );
        if (!is_resource($process)) throw new RuntimeException('Could not start ' . $program . '.');
        if (proc_close($process) !== 0) {
            rewind($log);
            throw new RuntimeException($program . ' failed. ' . trim((string)fread($log, 1600)));
        }
    } finally {
        if (is_resource($log)) fclose($log);
        @unlink($options);
    }
}

function dashBackupWorkDir(): string {
    foreach (glob(DASH_DATA . '/mxdp_*', GLOB_ONLYDIR) ?: [] as $old) {
        $modified = @filemtime($old);
        if ($modified !== false && $modified < time() - 86400) dashCleanBackupWorkDir($old);
    }
    $path = DASH_DATA . '/mxdp_' . bin2hex(random_bytes(16));
    if (!mkdir($path, 0700)) throw new RuntimeException('Could not create backup workspace.');
    return $path;
}

function dashCleanBackupWorkDir(string $path): void {
    if (is_link($path) || !preg_match('/\Amxdp_[a-f0-9]{32}\z/', basename($path)) || realpath(dirname($path)) !== realpath(DASH_DATA)) return;
    foreach (glob($path . '/*', GLOB_NOSORT) ?: [] as $file) {
        if (is_file($file)) @unlink($file);
    }
    @rmdir($path);
}

function dashMxdpKey(string $password, string $salt): string {
    if (strlen($password) < 8 || strlen($password) > 1024) {
        throw new RuntimeException('The backup password must be between 8 and 1024 bytes.');
    }
    return hash_pbkdf2('sha256', $password, $salt, 600000, 32, true);
}

function dashWriteBackupZip(string $bundle, string $work, string $project, ?string $root, array $databases): void {
    if (!class_exists('ZipArchive')) throw new RuntimeException('Enable PHP ZIP to create backups.');
    if (count($databases) > 20) throw new RuntimeException('A backup can contain up to 20 databases.');
    $zip = new ZipArchive();
    if ($zip->open($bundle, ZipArchive::CREATE) !== true) throw new RuntimeException('Could not create the backup ZIP.');
    $manifest = ['format' => 'dashboard-backup-zip', 'project' => $project, 'files' => $root !== null, 'databases' => array_values($databases)];
    if (!$zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR))) {
        throw new RuntimeException('Could not add backup manifest.');
    }
    if ($root !== null) {
        dashArchive($root, $work . '/project.zip', 200 * 1024 * 1024);
        if (!$zip->addFile($work . '/project.zip', 'project.zip')) throw new RuntimeException('Could not add project files.');
    }
    $total = $root === null ? 0 : filesize($work . '/project.zip');
    $config = $databases ? dashDatabaseConfig() : [];
    foreach ($manifest['databases'] as $index => $name) {
        dashDatabaseName($name);
        $filename = $index . '.sql';
        dashDatabaseClient('mysqldump', $config, ['--single-transaction', '--skip-lock-tables',
            '--hex-blob', '--routines', '--events', '--triggers', '--', $name], null, $work . '/' . $filename);
        $size = filesize($work . '/' . $filename);
        if ($size === false || ($total += $size) > 250 * 1024 * 1024) {
            throw new RuntimeException('Project archive and SQL dumps exceed the 250 MB extraction limit.');
        }
        if (!$zip->addFile($work . '/' . $filename, $filename)) throw new RuntimeException('Could not add database dump.');
    }

    if (!$zip->close()) throw new RuntimeException('Could not finish the backup ZIP.');
}

function dashCreateMxdp(string $project, ?string $root, array $databases, string $password): array {
    if (!class_exists('ZipArchive') || !extension_loaded('openssl')) {
        throw new RuntimeException('Enable PHP ZIP and OpenSSL to create MXDP backups.');
    }
    if (count($databases) > 20 || ($root === null && !$databases)) throw new RuntimeException('Select 1–20 databases for a database-only backup.');
    $salt = random_bytes(16);
    $iv = random_bytes(12);
    $key = dashMxdpKey($password, $salt);
    $work = dashBackupWorkDir();
    $id = bin2hex(random_bytes(16));
    $target = DASH_DATA . '/backup_' . $id . '.mxdp';
    try {
        $bundle = $work . '/bundle.zip';
        dashWriteBackupZip($bundle, $work, $project, $root, $databases);
        if (filesize($bundle) > MXDP_LIMIT - 49) {
            throw new RuntimeException('The compressed backup exceeds 50 MB.');
        }
        $header = MXDP_MAGIC . $salt . $iv;
        $plain = file_get_contents($bundle);
        if ($plain === false) throw new RuntimeException('Could not read the backup ZIP.');
        $encrypted = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $header);
        unset($plain);
        if ($encrypted === false) throw new RuntimeException('Could not encrypt the backup.');
        $output = fopen($target, 'xb');
        if ($output === false) throw new RuntimeException('Could not write the encrypted backup.');
        try {
            if (fwrite($output, $header . $tag) !== 49 || fwrite($output, $encrypted) !== strlen($encrypted)) {
                throw new RuntimeException('Could not save the complete encrypted backup.');
            }
        } finally {
            fclose($output);
        }
        $backup = [
            'id' => $id,
            'filename' => $project . '-' . date('Ymd-His') . '-' . substr($id, 0, 6) . '.mxdp',
            'project' => $project,
            'created' => time(),
            'databases' => count($databases),
        ];
        dashWrite($target . '.json', json_encode($backup, JSON_THROW_ON_ERROR));
        return $backup;
    } catch (Throwable $e) {
        if (is_file($target)) @unlink($target);
        throw $e;
    } finally {
        dashCleanBackupWorkDir($work);
    }
}

function dashOpenMxdp(string $path, string $password, string $work): array {
    if (!class_exists('ZipArchive') || !extension_loaded('openssl')) {
        throw new RuntimeException('Enable PHP ZIP and OpenSSL to restore MXDP backups.');
    }
    $size = filesize($path);
    if ($size <= 49 || $size > MXDP_LIMIT) throw new RuntimeException('Invalid MXDP size. The limit is 50 MB.');
    $data = file_get_contents($path);
    if ($data === false || !str_starts_with($data, MXDP_MAGIC)) throw new RuntimeException('Invalid MXDP format.');
    $key = dashMxdpKey($password, substr($data, 5, 16));
    $plain = openssl_decrypt(substr($data, 49), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
        substr($data, 21, 12), substr($data, 33, 16), substr($data, 0, 33));
    unset($data);
    if ($plain === false) throw new RuntimeException('Incorrect password or damaged MXDP backup.');
    $bundle = $work . '/bundle.zip';
    if (file_put_contents($bundle, $plain) !== strlen($plain)) throw new RuntimeException('Could not stage MXDP backup.');
    unset($plain);
    try {
        return dashOpenBackupZip($bundle, $work) ?? throw new RuntimeException('Invalid MXDP backup manifest.');
    } finally {
        @unlink($bundle);
    }
}

function dashOpenBackupZip(string $bundle, string $work, bool $legacy = true): ?array {
    $zip = new ZipArchive();
    if ($zip->open($bundle) !== true) throw new RuntimeException('Invalid backup ZIP.');
    try {
        $manifest = json_decode((string)$zip->getFromName('manifest.json', 8192), true, 16);
        if (($manifest['format'] ?? null) !== 'dashboard-backup-zip'
            && (!$legacy || ($manifest['format'] ?? null) !== null)) return null;
        if (!isset($manifest['project'], $manifest['files'], $manifest['databases'])) return null;
        if (!is_array($manifest) || !is_bool($manifest['files'] ?? null)
            || !is_array($manifest['databases'] ?? null) || !array_is_list($manifest['databases'])
            || count($manifest['databases']) > 20) throw new RuntimeException('Invalid backup manifest.');
        $entries = $manifest['files'] ? ['project.zip'] : [];
        foreach ($manifest['databases'] as $i => $name) {
            dashDatabaseName($name);
            $entries[] = $i . '.sql';
        }
        $total = 0;
        foreach ($entries as $entry) {
            $info = $zip->statName($entry);
            if ($info === false || ($total += $info['size']) > 250 * 1024 * 1024) {
                throw new RuntimeException('MXDP entries are missing or exceed the extraction limit.');
            }
            $in = $zip->getStream($entry);
            if ($in === false) throw new RuntimeException('Could not read ' . $entry);
            try {
                $out = fopen($work . '/' . $entry, 'xb');
                if ($out === false) throw new RuntimeException('Could not stage ' . $entry);
                try {
                    if (stream_copy_to_stream($in, $out, $info['size'] + 1) !== $info['size']) {
                        throw new RuntimeException('Incomplete MXDP entry.');
                    }
                } finally {
                    fclose($out);
                }
            } finally {
                fclose($in);
            }
        }
        return $manifest;
    } finally {
        $zip->close();
    }
}
function dashSqlCandidates(string $root): array {
    $tree = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
    $safeTree = new RecursiveCallbackFilterIterator($tree, static function ($item) {
        $path = $item->getPathname();
        $resolved = realpath($path);
        return !$item->isLink() && $resolved !== false && strcasecmp($path, $resolved) === 0
            && strtolower($item->getFilename()) !== '.git';
    });
    $files = [];
    foreach (new RecursiveIteratorIterator($safeTree, RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD) as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'sql') continue;
        $label = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $name = substr((string)preg_replace('/[^A-Za-z0-9_]/', '_', $file->getBasename('.' . $file->getExtension())), 0, 64);
        if ($name === '' || in_array(strtolower($name), ['mysql', 'sys', 'performance_schema', 'information_schema'], true)) {
            $name = substr(str_replace(['.', '-'], '_', basename($root)) . '_db', 0, 64);
        }
        $files[$label] = ['label' => $label, 'name' => $name, 'source' => 'project'];
    }
    ksort($files, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($files);
}

function dashQueueDatabaseSetup(string $project, array $dumps = [], ?string $work = null, bool $scanProject = true): void {
    $candidates = [];
    foreach ($dumps as $index => $name) {
        $candidates[] = ['label' => 'Backup / ' . $name . '.sql', 'name' => $name, 'source' => 'backup', 'index' => $index];
    }
    if ($scanProject) {
        $candidates = array_merge($candidates, dashSqlCandidates(dashProject($project)));
    }
    $previousWork = $_SESSION['dashboard_db_setup']['work'] ?? null;
    if (is_string($previousWork)) dashCleanBackupWorkDir($previousWork);
    $_SESSION['dashboard_db_setup'] = ['project' => $project, 'files' => $candidates, 'work' => $work];
}

function dashImportDatabase(PDO $pdo, array $config, string $name, string $file): void {
    if (!is_file($file)) throw new RuntimeException('The selected SQL file is unavailable.');
    if (dashDatabaseExists($pdo, $name)) throw new RuntimeException('Database already exists: ' . $name);
    $user = 'mxdp_' . bin2hex(random_bytes(8));
    $password = bin2hex(random_bytes(24));
    $account = $pdo->quote($user) . "@'%'";
    $database = '`' . $name . '`';
    // MySQL interprets underscores as wildcards in database-level grants.
    $grant = '`' . str_replace('_', '\\_', $name) . '`';
    $pdo->exec('CREATE DATABASE ' . $database . ' CHARACTER SET utf8mb4');
    $createdUser = false;
    try {
        $pdo->exec('CREATE USER ' . $account . ' IDENTIFIED BY ' . $pdo->quote($password));
        $createdUser = true;
        $pdo->exec('GRANT ALL PRIVILEGES ON ' . $grant . '.* TO ' . $account);
        $importConfig = array_replace($config, ['user' => $user, 'password' => $password]);
        dashDatabaseClient('mysql', $importConfig, ['--binary-mode', '--local-infile=0', '--database=' . $name], $file, null);
    } catch (Throwable $e) {
        $pdo->exec('DROP DATABASE IF EXISTS ' . $database);
        throw $e;
    } finally {
        if ($createdUser) $pdo->exec('DROP USER ' . $account);
    }
}
function dashBackupRecord(string $id): array {
    if (!preg_match('/\A[a-f0-9]{32}\z/', $id)) throw new RuntimeException('Invalid backup identifier.');
    $path = DASH_DATA . '/backup_' . $id . '.mxdp';
    if (!is_file($path) || is_link($path)) throw new RuntimeException('Backup file is missing.');
    $meta = json_decode((string)@file_get_contents($path . '.json'), true);
    $legacy = $_SESSION['dashboard_backups'][$id] ?? [];
    $project = $meta['project'] ?? '';
    $filename = $meta['filename'] ?? $legacy['filename'] ?? basename($path);
    return [
        'id' => $id, 'path' => $path,
        'filename' => preg_match('/\A[A-Za-z0-9_.-]+\.mxdp\z/', $filename) ? $filename : basename($path),
        'project' => dashName($project) ? $project : '',
        'created' => (int)($meta['created'] ?? filemtime($path)),
        'size' => filesize($path),
        'databases' => $meta['databases'] ?? null,
    ];
}

function dashConfirmedPassword($password, $confirmation): string {
    if (!is_string($password) || !is_string($confirmation) || $password !== $confirmation) {
        throw new RuntimeException('The backup passwords must match.');
    }
    return $password;
}
