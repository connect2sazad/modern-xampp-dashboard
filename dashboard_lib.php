<?php
// Dashboard data is stored in JSON. No database connection is required.
if (PHP_SAPI !== 'cli' && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true)) {
    http_response_code(403);
    exit('The dashboard is available locally only.');
}
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
const DASH_DATA = __DIR__ . '/storage/data';
const DASH_STUBS = __DIR__ . '/storage/broilers';
function dashInit(): void {
    foreach ([DASH_DATA, DASH_STUBS, DASH_DATA . '/snapshots'] as $d) if (!is_dir($d) && !mkdir($d, 0755, true) && !is_dir($d)) throw new RuntimeException('Cannot create ' . $d);
    foreach (['settings.json'=>'{"theme":"light","default_branch":"main","provider":"github"}', 'projects.json'=>'{}'] as $f=>$v) if (!is_file(DASH_DATA.'/'.$f)) file_put_contents(DASH_DATA.'/'.$f,$v,LOCK_EX);
    $defaults=['README.md.stub'=>"# {{PROJECT_NAME}}\n\nA PHP project created with XAMPP Dashboard.\n",'.gitignore.stub'=>"/vendor/\n/node_modules/\n.env\n.idea/\n.vscode/\n",'CHANGELOG.md.stub'=>"# Changelog\n\n## 0.1.0 - {{YEAR}}\n- Initial release of {{PROJECT_NAME}}\n",'SECURITY.md.stub'=>"# Security Policy\n\nReport vulnerabilities privately to the project maintainer. Do not post secrets in public issues.\n",'LICENSE.stub'=>"MIT License\n\nCopyright (c) {{YEAR}} {{PROJECT_NAME}}\n\nPermission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the \"Software\"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:\n\nThe above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.\n\nTHE SOFTWARE IS PROVIDED \"AS IS\", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.\n"];
    foreach ($defaults as $f=>$v) if (!is_file(DASH_STUBS.'/'.$f) || filesize(DASH_STUBS.'/'.$f) === 0) file_put_contents(DASH_STUBS.'/'.$f,$v,LOCK_EX);
    if (empty($_SESSION['dashboard_csrf'])) $_SESSION['dashboard_csrf']=bin2hex(random_bytes(32));
}
function dashJson(string $name): array { if (!in_array($name,['settings','projects'],true)) throw new InvalidArgumentException('Bad store'); $v=json_decode((string)@file_get_contents(DASH_DATA.'/'.$name.'.json'),true); return is_array($v)?$v:[]; }
function dashSave(string $name,array $data): void { if (!in_array($name,['settings','projects'],true)) throw new InvalidArgumentException('Bad store'); $path=DASH_DATA.'/'.$name.'.json'; $tmp=tempnam(DASH_DATA,'json_'); if ($tmp===false) throw new RuntimeException('Cannot write JSON'); if (file_put_contents($tmp,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),LOCK_EX)===false || !rename($tmp,$path)) { @unlink($tmp); throw new RuntimeException('Cannot save JSON'); } }
function dashCsrf($token = null): void {
    $token = $token ?? ($_POST['csrf'] ?? null);
    if (!is_string($token) || !hash_equals($_SESSION['dashboard_csrf'], $token)) {
        throw new RuntimeException('Session expired. Refresh the page.');
    }
}
function dashName(string $name): bool {
    return (bool)preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,99}\z/', $name)
        && substr($name, -1) !== '.'
        && !preg_match('/\A(?:con|prn|aux|nul|com[0-9]|lpt[0-9])(?:\.|$)/i', $name)
        && !in_array(strtolower($name), ['dashboard', 'phpmyadmin', 'xampp', 'webalizer'], true);
}
function dashProjectsRoot(): string {
    $configured = getenv('DASHBOARD_PROJECTS_ROOT');
    $root = realpath($configured !== false && $configured !== '' ? $configured : dirname(__DIR__));
    if ($root === false || !is_dir($root)) throw new RuntimeException('Projects folder not found. Check DASHBOARD_PROJECTS_ROOT.');
    return $root;
}

function dashPathsMatch(string $left, string $right): bool {
    return PHP_OS_FAMILY === 'Windows' ? strcasecmp($left, $right) === 0 : $left === $right;
}

function dashPhpCli(): string {
    $configured = getenv('DASHBOARD_PHP_CLI');
    if ($configured !== false && $configured !== '') return $configured;
    if (PHP_SAPI === 'cli') return PHP_BINARY;
    $binary = PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php';
    $xampp = dirname(__DIR__, 2);
    foreach ([$xampp . '/php/' . $binary, $xampp . '/bin/' . $binary, PHP_BINDIR . '/' . $binary] as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) return $candidate;
    }
    return $binary;
}

function dashMysqlExecutable(string $program): string {
    $binary = $program . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    $xampp = dirname(__DIR__, 2);
    $alias = $program === 'mysqldump' ? 'mariadb-dump' : 'mariadb';
    $names = [$binary, $alias . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '')];
    $directories = array_merge([$xampp . '/mysql/bin', $xampp . '/bin'], explode(PATH_SEPARATOR, (string)getenv('PATH')));
    foreach ($directories as $directory) {
        if ($directory === '') continue;
        foreach ($names as $name) {
            $candidate = trim($directory, '"') . DIRECTORY_SEPARATOR . $name;
            if (is_file($candidate) && is_executable($candidate)) return $candidate;
        }
    }
    return $binary;
}

function dashPhpMyAdminUrl(?string $database = null): string {
    $url = getenv('DASHBOARD_PHPMYADMIN_URL') ?: '../phpmyadmin/';
    if ($database === null) return $url;
    return rtrim($url, '/') . '/index.php?' . http_build_query(['route' => '/database/structure', 'db' => $database]);
}

function dashProject(string $name, bool $existing = true): string {
    $base = dashProjectsRoot();
    if (!dashName($name)) throw new RuntimeException('Invalid project name');
    $candidate = $base . DIRECTORY_SEPARATOR . $name;
    if (!$existing) {
        if (file_exists($candidate) || is_link($candidate)) throw new RuntimeException('Project already exists');
        return $candidate;
    }
    $resolved = realpath($candidate);
    if (!$resolved || !dashPathsMatch($resolved, $candidate) || is_link($candidate) || !is_dir($resolved)) {
        throw new RuntimeException('Project is missing or outside the projects folder');
    }
    return $resolved;
}
function dashPathParts(string $relative, bool $allowApacheConfig = false): array {
    $parts = explode('/', str_replace('\\', '/', $relative));
    foreach ($parts as $part) {
        if ($part === '' || preg_match('/[. ]$/D', $part)
            || preg_match('/[\x00-\x1f:<>|?*"]/', $part)
            || preg_match('/\A(?:con|prn|aux|nul|com[0-9¹²³]|lpt[0-9¹²³]) *(?:\.|$)/iu', $part)
            || strtolower($part) === '.git' || (!$allowApacheConfig && strtolower($part) === '.htaccess')) {
            throw new RuntimeException('Invalid relative path.');
        }
    }
    return $parts;
}
function dashFilePath(string $root, string $relative, bool $allowMissing = false): string {
    $parts = dashPathParts($relative);
    $path = $root;
    foreach ($parts as $index => $part) {
        $path .= DIRECTORY_SEPARATOR . $part;
        $resolved = realpath($path);
        if (is_link($path) || ($resolved !== false && !dashPathsMatch($resolved, $path))) {
            throw new RuntimeException('Linked paths are unavailable.');
        }
        if ($resolved === false && (!$allowMissing || $index !== count($parts) - 1)) {
            throw new RuntimeException('Path does not exist.');
        }
    }
    return $path;
}

function dashCreateProjectEntry(string $root, string $relative, bool $folder): void {
    $parts = dashPathParts($relative);
    $name = $folder ? null : array_pop($parts);
    $created = [];
    try {
        $prefix = '';
        foreach ($parts as $index => $part) {
            $prefix .= ($prefix === '' ? '' : '/') . $part;
            $target = dashFilePath($root, $prefix, true);
            $last = $folder && $index === array_key_last($parts);
            if (is_dir($target) && !$last) continue;
            if (!@mkdir($target, 0755)) {
                throw new RuntimeException('Could not create folder ' . $prefix . '. Check that the name is available.');
            }
            $created[] = $target;
        }
        if ($name !== null) {
            $file = dashFilePath($root, implode('/', [...$parts, $name]), true);
            $handle = @fopen($file, 'xb');
            if ($handle === false) throw new RuntimeException('Could not create file. Check that the name is available.');
            fclose($handle);
        }
    } catch (Throwable $e) {
        foreach (array_reverse($created) as $directory) @rmdir($directory);
        throw $e;
    }
}
function dashWrite(string $path, string $contents): void {
    $tmp = tempnam(dirname($path), 'dash_');
    if ($tmp === false) throw new RuntimeException('Could not create temporary file.');
    try {
        if (@file_put_contents($tmp, $contents) !== strlen($contents) || !@rename($tmp, $path)) {
            throw new RuntimeException('Could not save file.');
        }
    } finally {
        if (is_file($tmp)) @unlink($tmp);
    }
}
const DASH_FRAMEWORKS = [
    'laravel' => ['label' => 'Laravel', 'package' => 'laravel/laravel', 'directory' => 'public'],
    'yii' => ['label' => 'Yii 2', 'package' => 'yiisoft/yii2-app-basic', 'directory' => 'web'],
    'cakephp' => ['label' => 'CakePHP', 'package' => 'cakephp/app', 'directory' => 'webroot'],
    'symfony' => ['label' => 'Symfony', 'package' => 'symfony/skeleton', 'directory' => 'public'],
    'codeigniter' => ['label' => 'CodeIgniter 4', 'package' => 'codeigniter4/appstarter', 'directory' => 'public'],
];

function dashWebEntry(string $path, string $framework = '', ?string $directory = null): ?string {
    $preferred = $directory ?? (DASH_FRAMEWORKS[$framework]['directory'] ?? null);
    $directories = $preferred === null ? ['', 'public', 'web', 'webroot'] : [$preferred];
    foreach ($directories as $directory) {
        $prefix = $directory === '' ? '' : $directory . '/';
        foreach (['index.php', 'index.html', 'index.htm'] as $entry) {
            try {
                if (is_file(dashFilePath($path, $prefix . $entry))) return $prefix . $entry;
            } catch (RuntimeException $e) {
                continue;
            }
        }
    }
    return null;
}

function dashAppUrl(string $project, ?string $entry): ?string {
    if ($entry === null) return null;
    $directory = substr($entry, 0, strrpos('/' . $entry, '/'));
    $target = basename($entry) === 'index.htm' ? $entry : $directory;
    return '../' . rawurlencode($project) . '/' . $target;
}

function dashProjectFile(string $root, string $relative, array $extensions): string {
    $file = implode('/', dashPathParts($relative));
    if (!in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $extensions, true)
        || !is_file(dashFilePath($root, $file))) {
        throw new RuntimeException('Choose an existing ' . implode(', ', $extensions) . ' file inside this project.');
    }
    return $file;
}

function dashProjectLaunch(string $path, array $info): array {
    $project = basename($path);
    $framework = $info['framework'] ?? '';
    $saved = $info['launch_file'] ?? '';
    if (!isset($info['template']) && !isset(DASH_FRAMEWORKS[$framework]) && is_string($saved) && $saved !== '') {
        try {
            $entry = dashProjectFile($path, $saved, ['php', 'html', 'htm']);
            return [$entry, '../' . rawurlencode($project) . '/' . implode('/', array_map('rawurlencode', explode('/', $entry)))];
        } catch (RuntimeException $e) {
            // A file may have been moved since saving this preference.
        }
    }
    $entry = dashWebEntry($path, $framework, $info['template']['directory'] ?? null);
    return [$entry, dashAppUrl($project, $entry)];
}

function dashConfigureFrameworkWebRoot(string $path, string $framework): bool {
    return dashConfigurePublicDirectory($path, DASH_FRAMEWORKS[$framework]['directory']);
}

function dashConfigurePublicDirectory(string $path, string $directory): bool {
    if ($directory === '' || dashWebEntry($path, '', $directory) === null) return false;
    $config = $path . '/.htaccess';
    if (file_exists($config) || is_link($config)) return false;
    $file = @fopen($config, 'xb');
    if ($file === false) throw new RuntimeException('Could not create the Apache project configuration.');
    $rules = "# Created by XAMPP Dashboard\nOptions -Indexes\nRewriteEngine On\n"
        . "RewriteRule ^$ %{REQUEST_URI}" . $directory . "/ [R=302,L,NE]\n"
        . "RewriteRule ^(?!" . $directory . "(?:/|$)) - [F,L]\n";
    $written = fwrite($file, $rules);
    fclose($file);
    if ($written !== strlen($rules)) {
        @unlink($config);
        throw new RuntimeException('Could not finish the Apache project configuration.');
    }
    return true;
}

function dashRemote(string $url): bool { $p=parse_url($url); return is_array($p) && ($p['scheme']??'')==='https' && !empty($p['host']) && empty($p['user']) && empty($p['pass']) && !preg_match('/[\r\n]/',$url); }
function dashExec(string $command): string { if (!function_exists('exec')) throw new RuntimeException('Command execution is disabled in PHP'); $out=[]; $status=1; @exec($command.' 2>&1',$out,$status); if ($status!==0) throw new RuntimeException('Command failed (exit '.$status.'). Check Git/Composer installation, credentials, or project state.'); return implode("\n",array_slice($out,0,15)); }
function dashStub(string $name,string $project): string { $allowed=['README.md','LICENSE','.gitignore','CHANGELOG.md','SECURITY.md']; if (!in_array($name,$allowed,true)) throw new RuntimeException('Invalid stub'); return str_replace(['{{PROJECT_NAME}}','{{YEAR}}'],[$project,date('Y')],(string)file_get_contents(DASH_STUBS.'/'.$name.'.stub')); }
function dashMeta(string $project,array $changes): void { $data=dashJson('projects'); $data[$project]=array_merge($data[$project]??[], $changes, ['updated_at'=>date(DATE_ATOM)]); dashSave('projects',$data); }
function dashNotice(string $message,bool $error=false): void { $_SESSION[$error?'dashboard_flash_error':'dashboard_flash_success']=$message; }
function dashRedirect(string $page='index.php'): void { header('Location: '.$page,true,303); exit; }

const DASH_THEMES = [
    'light' => ['label' => 'Light', 'mode' => 'light'],
    'dark' => ['label' => 'Dark', 'mode' => 'dark'],
    'xp' => ['label' => 'Windows XP', 'mode' => 'light'],
    'blood-red' => ['label' => 'Blood Red', 'mode' => 'dark'],
    'forest' => ['label' => 'Forest', 'mode' => 'dark'],
    'ocean' => ['label' => 'Deep Ocean', 'mode' => 'dark'],
    'sunrise' => ['label' => 'Sunrise', 'mode' => 'light'],
    'lavender' => ['label' => 'Lavender', 'mode' => 'light'],
    'blueprint' => ['label' => 'Blueprint', 'mode' => 'dark'],
    'vista' => ['label' => 'Vista Glass', 'mode' => 'dark'],
    'ios' => ['label' => 'iOS Frost', 'mode' => 'light'],
    'cartoon' => ['label' => 'Cartoon', 'mode' => 'light'],
    'terminal' => ['label' => 'Green Terminal', 'mode' => 'dark'],
    'paper' => ['label' => 'Warm Paper', 'mode' => 'light'],
];

function dashTheme(string $name): string {
    return isset(DASH_THEMES[$name]) ? $name : 'light';
}

function dashThemeAttributes(?array $settings = null): string {
    $theme = dashTheme(($settings ?? dashJson('settings'))['theme'] ?? '');
    return 'data-dashboard-theme="' . $theme . '" data-bs-theme="' . DASH_THEMES[$theme]['mode'] . '"';
}

function dashBytes(int $bytes): string { $units=['B','KiB','MiB','GiB','TiB']; $i=0; $n=(float)$bytes; while($n>=1024 && $i<count($units)-1){$n/=1024;$i++;} return number_format($n,$i?1:0).' '.$units[$i]; }
function dashFolderBytes(string $root): ?int {
 try { $sum=0; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)); foreach($it as $file){if($file->isLink())continue;if($file->isFile())$sum+=$file->getSize();}return $sum; } catch(Throwable $e){return null;}
}
function dashDbSizes(array $names): array {
 $out=[]; if(!$names || !extension_loaded('pdo_mysql'))return $out;
 $cfg=dashJson('settings');
 try { $pdo=new PDO('mysql:host='.($cfg['db_host']??'127.0.0.1').';port='.(int)($cfg['db_port']??3306).';charset=utf8mb4', (string)($cfg['db_user']??'root'),(string)($cfg['db_password']??''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>2]);
 $st=$pdo->prepare('SELECT COALESCE(SUM(data_length + index_length),0) FROM information_schema.tables WHERE table_schema = ?');
 foreach($names as $n){if(!preg_match('/^[A-Za-z0-9_$-]{1,64}$/',$n))continue;$st->execute([$n]);$out[$n]=(int)$st->fetchColumn();}
 }catch(Throwable $e){} return $out;
}
function dashDbNames(array $info): array { $value=(string)($info['databases']??$info['database']??'');return array_values(array_unique(array_filter(array_map('trim',explode(',',$value))))); }

function dashCommandAvailable(string $program): bool {
    if (!function_exists('exec')) return false;
    @exec(escapeshellarg($program) . ' --version 2>&1', $output, $status);
    return $status === 0;
}

function dashArchive(string $root, string $target, int $limit = PHP_INT_MAX): void {
    if (!class_exists('ZipArchive')) throw new RuntimeException('Enable the PHP ZIP extension');
    $zip = new ZipArchive();
    if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot open ZIP archive.');
    }
    try {
        $tree = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
        $safeTree = new RecursiveCallbackFilterIterator($tree, static function ($item) {
            $path = $item->getPathname();
            $resolved = realpath($path);
            return !$item->isLink() && $resolved !== false && strcasecmp($path, $resolved) === 0
                && strtolower($item->getFilename()) !== '.git';
        });
        $total = 0;
        $count = 0;
        foreach (new RecursiveIteratorIterator($safeTree, RecursiveIteratorIterator::SELF_FIRST) as $item) {
            if (++$count > 10000 || (!$item->isDir() && ($total += $item->getSize()) > $limit)) {
                throw new RuntimeException('Project exceeds the backup file count or size limit.');
            }
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
            $added = $item->isDir() ? $zip->addEmptyDir($relative) : $zip->addFile($item->getPathname(), $relative);
            if (!$added) throw new RuntimeException('Could not add an entry to the archive.');
        }
        if (defined('ZipArchive::AFL_CREATE_OR_KEEP_FILE_FOR_EMPTY_ARCHIVE')) {
            $zip->setArchiveFlag(ZipArchive::AFL_CREATE_OR_KEEP_FILE_FOR_EMPTY_ARCHIVE, true);
        }
        if (!$zip->close()) throw new RuntimeException('Could not finish the archive.');
    } catch (Throwable $e) {
        @unlink($target);
        throw $e;
    }
}

function dashRestore(string $source, string $destination, string $wrapper = ''): void {
    $zip = new ZipArchive();
    if ($zip->open($source) !== true) throw new RuntimeException('Invalid ZIP archive.');
    $created = [];
    $entries = [];
    $total = 0;
    try {
        if ($zip->numFiles > 10000) throw new RuntimeException('Too many archive entries.');
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) throw new RuntimeException('Invalid archive entry.');
            $entry = str_replace('\\', '/', $stat['name']);
            if ($wrapper !== '') {
                if ($entry === $wrapper . '/') continue;
                if (!str_starts_with($entry, $wrapper . '/')) {
                    throw new RuntimeException('The template ZIP has an unexpected directory layout.');
                }
                $entry = substr($entry, strlen($wrapper) + 1);
            }
            $directory = substr($entry, -1) === '/';
            $relative = $directory ? substr($entry, 0, -1) : $entry;
            $parts = dashPathParts($relative, true);
            $key = strtolower($relative);
            $total += $stat['size'];
            if (isset($entries[$key]) || $total > 200 * 1024 * 1024
                || !$zip->getExternalAttributesIndex($i, $system, $attributes)
                || ($system === ZipArchive::OPSYS_UNIX && !in_array(($attributes >> 16) & 0170000, [0, 0100000, 0040000], true))) {
                throw new RuntimeException('Unsafe or oversized ZIP archive.');
            }
            $entries[$key] = [$i, $parts, $directory, $stat['size']];
        }
        if (!@mkdir($destination, 0755)) throw new RuntimeException('Cannot create project directory.');
        $created[$destination] = true;
        foreach ($entries as [$index, $parts, $directory, $size]) {
            $target = $destination;
            $filename = $directory ? null : array_pop($parts);
            foreach ($parts as $part) {
                $target .= DIRECTORY_SEPARATOR . $part;
                if (!is_dir($target)) {
                    if (!@mkdir($target, 0755)) throw new RuntimeException('Cannot create archive directory.');
                    $created[$target] = true;
                }
            }
            if ($filename === null) continue;
            $target .= DIRECTORY_SEPARATOR . $filename;
            $input = $zip->getStreamIndex($index);
            if ($input === false) throw new RuntimeException('Cannot read archive entry.');
            try {
                $output = @fopen($target, 'xb');
                if ($output === false) throw new RuntimeException('Cannot create restored file.');
                $created[$target] = false;
                try {
                    if (stream_copy_to_stream($input, $output, $size + 1) !== $size) {
                        throw new RuntimeException('Could not restore complete file.');
                    }
                } finally {
                    fclose($output);
                }
            } finally {
                fclose($input);
            }
        }
    } catch (Throwable $e) {
        foreach (array_reverse($created, true) as $path => $directory) {
            $directory ? @rmdir($path) : @unlink($path);
        }
        throw $e;
    } finally {
        $zip->close();
    }
}

function dashLaunch(string $program): void {
    header('Content-Type: text/plain; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('POST required.');
    }
    try {
        dashCsrf();
        $path = dashProject($_POST['project'] ?? '');
        session_write_close();
        if (!function_exists('exec')) throw new RuntimeException('Command execution is disabled in PHP.');
        if (PHP_OS_FAMILY === 'Windows') {
            if ($program === 'code') {
                @exec('where.exe code 2>NUL', $matches, $status);
                if ($status !== 0) throw new RuntimeException('VS Code was not found in Apache’s PATH.');
            }
            $launcher = $program === 'explorer' ? 'explorer.exe ' : '/b code -- ';
            @exec('start "" ' . $launcher . escapeshellarg($path) . ' >NUL 2>&1', $output, $status);
        } else {
            $mac = PHP_OS_FAMILY === 'Darwin';
            $launcher = $program === 'code' ? 'code' : ($mac ? 'open' : 'xdg-open');
            @exec('command -v ' . escapeshellarg($launcher), $matches, $available);
            if ($available !== 0 && $mac && $program === 'code') {
                $launcher = 'open -a ' . escapeshellarg('Visual Studio Code');
            } elseif ($available !== 0) {
                throw new RuntimeException($launcher . ' was not found on the web server’s PATH.');
            }
            @exec($launcher . ' ' . escapeshellarg($path) . ' </dev/null >/dev/null 2>&1 &', $output, $status);
        }
        if ($status !== 0) throw new RuntimeException('Could not launch ' . $program);
        http_response_code(204);
    } catch (Throwable $e) {
        http_response_code(400);
        echo $e->getMessage();
    }
}

dashInit();
