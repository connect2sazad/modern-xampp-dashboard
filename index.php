<?php

if (file_exists('./.installer')) {
    header("location: ./install/");
    exit(0);
}

require_once __DIR__ . '/mxdp_lib.php';
require_once __DIR__ . '/template_lib.php';
require_once __DIR__ . '/project_preferences.php';
$dashboardSettings = dashJson('settings');
$installTemplates = dashTemplates();
try {
    $htdocsDir = dashProjectsRoot();
} catch (RuntimeException $e) {
    http_response_code(500);
    exit($e->getMessage());
}
if (empty($_SESSION['dashboard_csrf'])) {
    $_SESSION['dashboard_csrf'] = bin2hex(random_bytes(32));
}
function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
// Delete only direct child projects of htdocs. Never follow symlinks or junctions.
function removeProjectTree(string $directory, array &$failures): bool
{
    if (is_link($directory)) {
        $failures[] = 'Project folder is a link';
        return false;
    }
    try {
        $tree = new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS);
        $safeTree = new RecursiveCallbackFilterIterator($tree, static function ($entry) use ($directory, &$failures) {
            $file = $entry->getPathname();
            $resolved = realpath($file);
            if ($entry->isLink() || $resolved === false || strcasecmp($resolved, $file) !== 0) {
                $failures[] = 'Linked or inaccessible entry: ' . substr($file, strlen($directory) + 1);
                return false;
            }
            return true;
        });
        $iterator = new RecursiveIteratorIterator($safeTree, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $file = $entry->getPathname();
            // Do not follow links, including links nested within the project.
            if ($entry->isLink() || realpath($file) === false || strcasecmp(realpath($file), $file) !== 0) {
                $failures[] = 'Linked entry: ' . substr($file, strlen($directory) + 1);
                continue;
            }
            if ($entry->isDir()) {
                if (!@rmdir($file)) {
                    @chmod($file, 0777);
                    if (!@rmdir($file)) $failures[] = substr($file, strlen($directory) + 1);
                }
            } else {
                if (!@unlink($file)) {
                    // Windows read-only attribute may prevent deletion.
                    @chmod($file, 0666);
                    if (!@unlink($file)) $failures[] = substr($file, strlen($directory) + 1);
                }
            }
            if (count($failures) >= 10) break;
        }
        if ($failures) return false;
        if (@rmdir($directory)) return true;
        @chmod($directory, 0777);
        if (@rmdir($directory)) return true;
        $failures[] = 'Project root folder';
    } catch (Throwable $e) {
        $failures[] = 'Directory traversal failed: ' . $e->getMessage();
    }
    return false;
}

// Per-project settings: change the actual directory name, Git origin, and JSON metadata.
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_project_settings') {
 try {
  dashCsrf(); $old=(string)($_POST['original_project']??'');$new=trim((string)($_POST['new_project_name']??''));
  $source=dashProject($old,true); if(!dashName($new))throw new RuntimeException('Invalid new project name');
  $changeOrigin = ($_POST['change_origin'] ?? '') === '1';
  $remote = trim((string)($_POST['project_remote'] ?? ''));
  if ($changeOrigin && $remote !== '' && !dashRemote($remote)) {
      throw new RuntimeException('Enter an HTTPS Git origin without credentials.');
  }
  $group = trim((string)($_POST['project_group'] ?? ''));
  if (strlen($group) > 60 || preg_match('/[\x00-\x1f]/', $group)) throw new RuntimeException('Use a group name up to 60 characters.');
  $groupColor = dashProjectColor($_POST['group_color'] ?? '', '#4566b0');
  $cardColor = isset($_POST['use_card_color']) ? dashProjectColor($_POST['card_color'] ?? '') : '';
  $dbs=trim((string)($_POST['project_databases']??''));$names=array_values(array_filter(array_map('trim',explode(',',$dbs))));
  if(count($names)>20)throw new RuntimeException('Maximum 20 database names');
  foreach($names as $db)if(!preg_match('/^[A-Za-z0-9_$-]{1,64}$/',$db))throw new RuntimeException('Invalid database name: '.$db);
  $notes=substr(trim((string)($_POST['project_notes']??'')),0,3000);
  $isGit=is_dir($source.DIRECTORY_SEPARATOR.'.git') || is_file($source.DIRECTORY_SEPARATOR.'.git');
  $destination = $new !== $old ? dashProject($new, false) : $source;
  $prefix = 'git -C ' . escapeshellarg($source) . ' ';
  if (!$isGit && isset($_POST['initialize_git'])) {
      dashInitializeProjectGit($source, $dashboardSettings['default_branch'] ?? 'main');
      $isGit = true;
  }
  if ($changeOrigin && !$isGit) throw new RuntimeException('Enable Git initialization before adding an origin.');
  if ($changeOrigin && $isGit) {
      $currentOrigin = dashGitOrigin($source);
      if ($remote === '' && $currentOrigin !== '') dashExec($prefix . 'remote remove origin');
      if ($remote !== '' && $remote !== $currentOrigin) {
          dashExec($prefix . 'remote ' . ($currentOrigin === '' ? 'add' : 'set-url') . ' origin ' . escapeshellarg($remote));
      }
  }
  if ($new !== $old && !@rename($source, $destination)) {
      throw new RuntimeException('Could not rename project folder. Close programs using it.');
  }
  $meta = dashJson('projects');
  $oldInfo = $meta[$old] ?? [];
  unset($meta[$old]);
  if ($group !== '') {
      foreach ($meta as &$other) {
          if (strcasecmp($other['group'] ?? '', $group) === 0) {
              $other['group'] = $group;
              $other['group_color'] = $groupColor;
          }
      }
      unset($other);
  }
  $meta[$new] = array_merge($oldInfo, [
      'databases' => implode(', ', $names), 'notes' => $notes,
      'group' => $group, 'group_color' => $groupColor, 'card_color' => $cardColor,
      'updated_at' => date(DATE_ATOM),
  ]);
  if ($changeOrigin) $meta[$new]['remote'] = $remote;
  dashSave('projects', $meta);
  dashNotice('Project settings saved: '.$new);
 }catch(Throwable $e){dashNotice($e->getMessage(),true);}dashRedirect();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_project') {
    $removed = false;
    $dropped = [];
    try {
        dashCsrf();
        $name = $_POST['remove_project'] ?? '';
        if ($name !== ($_POST['confirm_project'] ?? null)) throw new RuntimeException('Type the project name to confirm removal.');
        $target = dashProject($name);
        $name = basename($target);
        $projectData = dashJson('projects');
        $databases = dashDbNames($projectData[$name] ?? []);
        $deleteDatabases = isset($_POST['delete_databases']);
        $fullBackup = isset($_POST['backup_full']);
        $databaseBackup = isset($_POST['backup_database']);
        if ($fullBackup && $databaseBackup) throw new RuntimeException('Choose one backup type.');
        if (($deleteDatabases || $databaseBackup) && !$databases) throw new RuntimeException('This project has no linked databases.');
        if ($deleteDatabases) {
            $pdo = dashDatabase(dashDatabaseConfig());
            foreach ($databases as $database) {
                dashDatabaseName($database);
                foreach ($projectData as $other => $info) {
                    if (strcasecmp($other, $name) !== 0 && in_array(strtolower($database), array_map('strtolower', dashDbNames($info)), true)) {
                        throw new RuntimeException('Database ' . $database . ' is also linked to ' . $other . '.');
                    }
                }
            }
        }
        if ($fullBackup || $databaseBackup) {
            $password = dashConfirmedPassword($_POST['backup_password'] ?? null, $_POST['backup_password_confirm'] ?? null);
            $backup = dashCreateMxdp($name, $fullBackup ? $target : null, $databases, $password);
            $_SESSION['dashboard_backups'][$backup['id']] = $backup;
        }
        $failures = [];
        if (!removeProjectTree($target, $failures)) {
            throw new RuntimeException('Project removal incomplete. Could not remove: ' . implode(', ', array_map('basename', $failures)));
        }
        $removed = true;
        if ($deleteDatabases) {
            foreach ($databases as $database) {
                $pdo->exec('DROP DATABASE IF EXISTS `' . $database . '`');
                $dropped[] = $database;
            }
        }
        $projectData = dashJson('projects');
        unset($projectData[$name]);
        dashSave('projects', $projectData);
        dashNotice('Project removed: ' . $name . ($dropped ? '. Database removal completed for: ' . implode(', ', $dropped) . '.' : '.'));
    } catch (Throwable $e) {
        $message = $removed ? 'Project files were removed. ' : '';
        if ($dropped) $message .= 'Database removal completed for: ' . implode(', ', $dropped) . '. ';
        dashNotice($message . $e->getMessage(), true);
    }
    dashRedirect();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_project_star') {
    try {
        dashCsrf();
        $project = basename(dashProject($_POST['project'] ?? ''));
        $star = $_POST['starred'] ?? '';
        if (!in_array($star, ['0', '1'], true)) throw new RuntimeException('Invalid starred status.');
        dashMeta($project, ['starred' => $star === '1']);
    } catch (Throwable $e) {
        dashNotice($e->getMessage(), true);
    }
    dashRedirect(dashPageUrl((int)($_GET['page'] ?? 1), (string)($_GET['q'] ?? ''),
        (string)($_GET['letter'] ?? ''), ($_GET['starred'] ?? '') === '1'));
}

$error = '';
$oldName = '';
$oldCode = "<?php\n\n// Welcome to your new PHP project!\n\necho \"Hello World!\";\n";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array(($_POST['action'] ?? ''), ['remove_project'], true)) {
    $oldName = trim((string)($_POST['project_name'] ?? ''));
    $oldCode = (string)($_POST['project_code'] ?? $oldCode);
    if (!hash_equals($_SESSION['dashboard_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $error = 'Session expired. Refresh and try again.';
    } elseif (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,79}\z/D', $oldName)) {
        $error = 'Use 1–80 letters, numbers, underscores or hyphens. Start with a letter or number.';
    } elseif (!dashName($oldName)) {
        $error = 'This folder name is reserved.';
    } elseif (!empty($_POST['git_init']) && !in_array(($_POST['DefaultBranch'] ?? ''), ['main', 'master'], true)) {
        $error = 'Invalid Git default branch.';
    } elseif (
        !empty($_POST['git_init']) && trim((string)($_POST['remoteRepoURL'] ?? '')) !== ''
        && (!filter_var(trim((string)$_POST['remoteRepoURL']), FILTER_VALIDATE_URL)
            || !in_array(strtolower((string)parse_url(trim((string)$_POST['remoteRepoURL']), PHP_URL_SCHEME)), ['https'], true))
    ) {
        $error = 'Use a valid HTTPS remote repository URL.';
    } elseif (!empty($_POST['git_init']) && !empty($_POST['git_push']) && trim((string)($_POST['remoteRepoURL'] ?? '')) === '') {
        $error = 'A remote repository URL is required to push the initial code.';
    } elseif (!empty($_POST['git_init']) && trim((string)($_POST['remoteRepoURL'] ?? '')) !== '' && parse_url(trim((string)$_POST['remoteRepoURL']), PHP_URL_USER) !== null) {
        $error = 'Do not embed usernames or access tokens in the repository URL. Configure Git authentication separately.';
    } elseif (strlen($oldCode) > 2 * 1024 * 1024) {
        $error = 'Starter code must be smaller than 2 MB.';
    } else {
        $path = $htdocsDir . DIRECTORY_SEPARATOR . $oldName;
        if (file_exists($path)) {
            $error = "Project '$oldName' already exists!";
        } elseif (!@mkdir($path, 0755)) {
            $error = 'Could not create the project directory. Check folder permissions.';
        } elseif (file_put_contents($path . DIRECTORY_SEPARATOR . 'index.php', $oldCode, LOCK_EX) === false) {
            @rmdir($path);
            $error = 'Could not write index.php. Check folder permissions.';
        } else {
            // Optional project files are generated from editable local stubs.
            foreach (['README.md' => 'include_readme', '.gitignore' => 'include_gitignore', 'LICENSE' => 'include_license', 'CHANGELOG.md' => 'include_changelog', 'SECURITY.md' => 'include_security'] as $file => $field) {
                if (!empty($_POST[$field]) && file_put_contents($path . DIRECTORY_SEPARATOR . $file, dashStub($file, $oldName), LOCK_EX) === false) {
                    $error = 'Project created, but could not write ' . $file;
                    break;
                }
            }
            if ($error === '') dashMeta($oldName, ['created_at' => date(DATE_ATOM), 'kind' => !empty($_POST['git_init']) ? 'git' : 'blank', 'remote' => !empty($_POST['git_init']) ? trim((string)($_POST['remoteRepoURL'] ?? '')) : '']);
            if ($error === '' && !empty($_POST['git_init'])) {
                $branch = $_POST['DefaultBranch'];
                $remote = trim((string)($_POST['remoteRepoURL'] ?? ''));
                // Git is an optional local executable; never pass unescaped user input to a shell.
                $gitAvailable = function_exists('exec') && function_exists('escapeshellarg');
                if ($gitAvailable) {
                    $gitCheckOutput = [];
                    $gitCheckStatus = 1;
                    @exec('git --version 2>&1', $gitCheckOutput, $gitCheckStatus);
                    $gitAvailable = ($gitCheckStatus === 0);
                }
                if (!$gitAvailable) {
                    $error = 'Project created, but Git was not found in PATH. Install Git for Windows.';
                } else {
                    $command = 'git -C ' . escapeshellarg($path) . ' init -b ' . escapeshellarg($branch) . ' 2>&1';
                    $output = [];
                    $exitCode = 1;
                    @exec($command, $output, $exitCode);
                    if ($exitCode !== 0) {
                        $error = 'Project created, but Git initialization failed: ' . implode(' ', array_slice($output, 0, 2));
                    } else {
                        if ($remote !== '') {
                            $output = [];
                            @exec('git -C ' . escapeshellarg($path) . ' remote add origin ' . escapeshellarg($remote) . ' 2>&1', $output, $exitCode);
                            if ($exitCode !== 0) $error = 'Project created and Git initialized, but adding origin failed: ' . implode(' ', array_slice($output, 0, 2));
                        }
                        if ($error === '' && !empty($_POST['git_push'])) {
                            // The server's Git identity and credential helper must already be configured.
                            $gitPrefix = 'git -C ' . escapeshellarg($path) . ' ';
                            $steps = [
                                ['add', $gitPrefix . 'add --all 2>&1'],
                                ['commit', $gitPrefix . 'commit -m ' . escapeshellarg('Initial commit') . ' 2>&1'],
                                ['push', 'set GIT_TERMINAL_PROMPT=0&& set GCM_INTERACTIVE=never&& ' . $gitPrefix . 'push -u origin ' . escapeshellarg($branch) . ' 2>&1']
                            ];
                            foreach ($steps as $step) {
                                $output = [];
                                $exitCode = 1;
                                @exec($step[1], $output, $exitCode);
                                if ($exitCode !== 0) {
                                    $details = implode(' ', array_slice($output, 0, 5));
                                    // Avoid echoing potentially sensitive remote details into the browser.
                                    $error = 'Project created, but Git ' . $step[0] . ' failed. Check Git user.name/user.email, credentials, remote permissions and whether the remote has existing commits.';
                                    break;
                                }
                            }
                        }
                    }
                }
            }
            if ($error === '') {
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'), true, 303);
                exit;
            }
        }
    }
}
// Fast index: enumerate folder names only; defer disk and database scans to AJAX.
$allProjects = array_values(array_filter(glob($htdocsDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [], static function ($path) {
    try {
        dashProject(basename($path));
        return true;
    } catch (RuntimeException $e) {
        return false;
    }
}));
$projectMeta = dashJson('projects');
$projectGroups = [];
foreach ($allProjects as $path) {
    $group = $projectMeta[basename($path)]['group'] ?? '';
    if ($group !== '') $projectGroups[$group] = dashProjectColor($projectMeta[basename($path)]['group_color'] ?? '', '#4566b0');
}
uksort($projectGroups, 'strnatcasecmp');
usort($allProjects, static function ($a, $b) use ($projectMeta) {
    $groupA = $projectMeta[basename($a)]['group'] ?? '';
    $groupB = $projectMeta[basename($b)]['group'] ?? '';
    return ($groupA === '') <=> ($groupB === '') ?: strnatcasecmp($groupA, $groupB) ?: strnatcasecmp(basename($a), basename($b));
});
$searchTerm = trim((string)($_GET['q'] ?? ''));
$searchTerm = substr($searchTerm, 0, 100);
$letter = strtoupper(trim((string)($_GET['letter'] ?? '')));
if (!preg_match('/^(?:[A-Z]|#)$/', $letter)) $letter = '';
$starredOnly = ($_GET['starred'] ?? '') === '1';
$lettersAvailable = [];
foreach ($allProjects as $path) {
    if ($starredOnly && empty($projectMeta[basename($path)]['starred'])) continue;
    $first = strtoupper(substr(basename($path), 0, 1));
    $lettersAvailable[preg_match('/^[A-Z]$/', $first) ? $first : '#'] = true;
}
$projects = array_values(array_filter($allProjects, static function ($path) use ($searchTerm, $letter, $starredOnly, $projectMeta) {
    $name = basename($path);
    if ($starredOnly && empty($projectMeta[$name]['starred'])) return false;
    if ($searchTerm !== '' && stripos($name, $searchTerm) === false) return false;
    if ($letter !== '') {
        $first = strtoupper(substr($name, 0, 1));
        $bucket = preg_match('/^[A-Z]$/', $first) ? $first : '#';
        if ($bucket !== $letter) return false;
    }
    return true;
}));
$perPage = 12;
$totalProjects = count($projects);
$pageCount = max(1, (int)ceil($totalProjects / $perPage));
$page = max(1, min($pageCount, (int)($_GET['page'] ?? 1)));
$projects = array_slice($projects, ($page - 1) * $perPage, $perPage);
function dashPageUrl(int $page, string $search, string $letter, bool $starred = false): string {
    return 'index.php?' . http_build_query(array_filter([
        'page' => $page, 'q' => $search, 'letter' => $letter,
        'starred' => $starred ? '1' : '',
    ], static fn($v) => $v !== ''));
}
?>
<!DOCTYPE html>
<html lang="en" <?= dashThemeAttributes($dashboardSettings) ?>>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>XAMPP Project Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="themes.css">
    <link rel="stylesheet" href="style.css">
    <link rel="shortcut icon" href="./storage/logo.png" type="image/png">
</head>

<body>
    <nav class="alphabet-nav" aria-label="Project alphabetical navigation">
      <a href="<?= h(dashPageUrl(1, $searchTerm, '', $starredOnly)) ?>" title="All letters" class="<?= $letter === '' ? 'active' : '' ?>">All</a>
      <?php foreach (array_merge(['#'], range('A','Z')) as $ch): ?>
        <a href="<?= h(dashPageUrl(1, $searchTerm, $ch, $starredOnly)) ?>" class="<?= $letter === $ch ? 'active' : '' ?> <?= !isset($lettersAvailable[$ch]) ? 'empty-letter' : '' ?>" title="Projects starting with <?= h($ch) ?>"><?= h($ch) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="container py-4 dashboard-main">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <a href="https://connect2sazad.github.io/" target="_blank"><img src="./storage/logo.png" alt="Logo" class="logo"></a>
            <div class="d-flex flex-wrap gap-2">
                <div class="dropdown">
                    <button class="btn btn-outline-success dropdown-toggle" type="button" data-bs-toggle="dropdown">➕ New Project</button>
                    <ul class="dropdown-menu">
                        <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#blankProject">Blank Project</button></li>
                        <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#blankProjectGit">Blank Git Project</button></li>
                        <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#cloneGitModal">Clone a Git Project</button></li>
                        <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#frameworkModal">Blank Framework Project</button></li>
                        <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#cmsTemplateModal">Install CMS or Custom Template</button></li>
                        <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#restoreMxdpModal" data-archive-kind="mxdp">Restore an MXDP backup</button></li>
                        <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#restoreMxdpModal" data-archive-kind="zip">Import a ZIP project</button></li>
                    </ul>
                </div>
                <a href="<?= h(dashPhpMyAdminUrl()) ?>" class="btn btn-outline-primary" target="_blank" rel="noopener">🛢️ phpMyAdmin</a>
                <a href="./phpinfo.php" class="btn btn-outline-info" target="_blank" rel="noopener">📘 PHP Info</a>
                <button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#todoModal" title="To-do list" aria-label="Open to-do list">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect x="3" y="3" width="18" height="18" rx="3"></rect>
                        <path d="m7.5 12 3 3 6-6"></path>
                    </svg>
                </button>
                <a href="backups.php" class="btn btn-outline-secondary">💾 Backups</a>
                <a href="docs.php" class="btn btn-outline-secondary">Docs</a>
                <a href="settings.php" class="btn btn-outline-dark">⚙️ Settings</a>

            </div>
        </div>
        <?php if (!empty($_SESSION['dashboard_flash_error'])): ?>
            <div class="alert alert-danger" role="alert"><?= h($_SESSION['dashboard_flash_error']) ?></div>
            <?php unset($_SESSION['dashboard_flash_error']); ?>
        <?php endif; ?>
        <?php if (!empty($_SESSION['dashboard_flash_success'])): ?>
            <div class="alert alert-success" role="status"><?= h($_SESSION['dashboard_flash_success']) ?></div>
            <?php unset($_SESSION['dashboard_flash_success']); ?>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert"><?= h($error) ?></div>
        <?php endif; ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">Your XAMPP Projects <span class="badge bg-secondary fs-6"><?= $totalProjects ?></span></h4>
            <div class="d-flex flex-wrap justify-content-end gap-2">
                <a class="btn btn-sm <?= $starredOnly ? 'btn-warning' : 'btn-outline-secondary' ?>"
                   href="<?= h(dashPageUrl(1, $searchTerm, '', !$starredOnly)) ?>"><?= $starredOnly ? 'Show all projects' : '★ Starred projects' ?></a>
                <div class="btn-group btn-group-sm" role="group" aria-label="Project layout">
                    <button type="button" class="btn btn-outline-secondary project-view-toggle" data-view="list" aria-pressed="false">List</button>
                    <button type="button" class="btn btn-outline-secondary project-view-toggle" data-view="tiles" aria-pressed="true">Tiles</button>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="showSearch">🔍 Search</button>
            </div>
        </div>
        <div id="searchBoxWrapper" class="mb-3" <?= $searchTerm !== '' ? '' : 'hidden' ?>>
            <input type="search" id="projectSearch" class="form-control" value="<?= h($searchTerm) ?>" placeholder="Search projects... (Ctrl + F)" autocomplete="off">
        </div>
        <div id="projectList" data-view="tiles">
            <?php $previousGroup = null; foreach ($projects as $projectPath):
                $projectName = basename($projectPath);
                $info = $projectMeta[$projectName] ?? [];
                $isGit = file_exists($projectPath . '/.git');
                $names = dashDbNames($info);
                [, $appUrl] = dashProjectLaunch($projectPath, $info);
                $group = $info['group'] ?? '';
                $groupColor = dashProjectColor($info['group_color'] ?? '', '#4566b0');
                $cardColor = dashProjectColor($info['card_color'] ?? '');
                $accent = $cardColor ?: ($group !== '' ? $groupColor : 'var(--bs-primary)');
                $starred = !empty($info['starred']);
                if ($projectGroups && $group !== $previousGroup):
            ?>
                <h5 class="project-group-heading" style="--group-color:<?= h($groupColor) ?>"><?= h($group !== '' ? $group : 'Ungrouped') ?></h5>
            <?php endif; $previousGroup = $group; ?>
                <article class="project-card" data-name="<?= h(strtolower($projectName)) ?>">
                    <div class="card project-surface" style="--project-accent:<?= h($accent) ?>">
                        <div class="card-body">
                            <div class="project-card-heading">
                                <div class="project-icon" aria-hidden="true"><?= $isGit ? '⇆' : '▱' ?></div>
                                <div class="project-title">
                                    <h5 class="text-break mb-1"><?= h($projectName) ?></h5>
                                    <?php if ($isGit): ?><span class="project-label">Git</span><?php endif; ?>
                                    <?php if ($group !== ''): ?><span class="project-label"><?= h($group) ?></span><?php endif; ?>
                                </div>
                                <form method="post" action="<?= h(dashPageUrl($page, $searchTerm, $letter, $starredOnly)) ?>">
                                    <input type="hidden" name="csrf" value="<?= h($_SESSION['dashboard_csrf']) ?>">
                                    <input type="hidden" name="action" value="set_project_star">
                                    <input type="hidden" name="project" value="<?= h($projectName) ?>">
                                    <input type="hidden" name="starred" value="<?= $starred ? '0' : '1' ?>">
                                    <button class="btn btn-sm <?= $starred ? 'btn-warning' : 'btn-outline-secondary' ?>" type="submit"
                                        aria-label="Star <?= h($projectName) ?>" aria-pressed="<?= $starred ? 'true' : 'false' ?>"
                                        title="<?= $starred ? 'Remove star' : 'Star project' ?>"><?= $starred ? '★' : '☆' ?></button>
                                </form>
                            </div>
                            <div class="project-details">
                                <code class="project-path" title="<?= h($projectPath) ?>"><?= h($projectPath) ?></code>
                                <div class="project-stats text-body-secondary">
                                    <span class="project-file-size" data-project="<?= h($projectName) ?>">Files: Loading…</span>
                                    <span class="project-db-size" data-project="<?= h($projectName) ?>">Database: <?= $names ? 'Loading…' : 'Not linked' ?></span>
                                </div>
                                <?php if ($names): ?><small class="text-body-secondary text-break"><?= h(implode(', ', $names)) ?></small><?php endif; ?>
                            </div>
                            <div class="project-actions">
                                <?php if ($appUrl !== null): ?>
                                    <a href="<?= h($appUrl) ?>" class="btn btn-sm btn-primary" target="_blank" rel="noopener">Open ↗</a>
                                <?php endif; ?>
                                <a href="editor.php?project=<?= rawurlencode($projectName) ?>" class="btn btn-sm btn-outline-secondary">Editor</a>
                                <a href="developer.php?project=<?= rawurlencode($projectName) ?>" class="btn btn-sm btn-outline-secondary">Developer</a>
                                <button type="button" class="btn btn-sm btn-outline-secondary project-settings-btn"
                                    data-bs-toggle="modal" data-bs-target="#projectSettingsModal" data-project="<?= h($projectName) ?>"
                                    data-git="<?= $isGit ? '1' : '0' ?>" data-databases="<?= h(implode(', ', $names)) ?>"
                                    data-notes="<?= h($info['notes'] ?? '') ?>" data-group="<?= h($group) ?>"
                                    data-group-color="<?= h($groupColor) ?>" data-card-color="<?= h($cardColor) ?>"
                                    title="Project settings" aria-label="Settings for <?= h($projectName) ?>">⚙</button>
                                <div class="dropdown">
                                    <button type="button" class="btn btn-sm btn-outline-secondary project-more" data-bs-toggle="dropdown"
                                        aria-expanded="false" aria-label="More actions for <?= h($projectName) ?>">···</button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow">
                                        <li><button type="button" class="dropdown-item launch-helper" data-helper="explorer.php" data-project="<?= h($projectName) ?>">Open project folder</button></li>
                                        <li><button type="button" class="dropdown-item launch-helper" data-helper="vscode.php" data-project="<?= h($projectName) ?>">Open in VS Code</button></li>
                                        <?php if ($names): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><h6 class="dropdown-header">Open in phpMyAdmin</h6></li>
                                            <?php foreach ($names as $database): ?>
                                                <li><a class="dropdown-item" target="_blank" rel="noopener" href="<?= h(dashPhpMyAdminUrl($database)) ?>"><?= h($database) ?></a></li>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item" href="backup.php?folder=<?= rawurlencode($projectName) ?>">Create MXDP backup</a></li>
                                        <li><form action="project_zip.php" method="post">
                                            <input type="hidden" name="csrf" value="<?= h($_SESSION['dashboard_csrf']) ?>">
                                            <input type="hidden" name="project" value="<?= h($projectName) ?>">
                                            <button class="dropdown-item">Download ZIP + DB</button>
                                        </form></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><button type="button" class="dropdown-item text-danger remove-project-btn" data-project="<?= h($projectName) ?>" data-databases="<?= h(implode(', ', $names)) ?>" data-bs-toggle="modal" data-bs-target="#removeProjectModal">Remove project</button></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <nav aria-label="Project pages" class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-3 mb-4">
          <small class="text-muted">Showing <?= $totalProjects ? (($page - 1) * $perPage + 1) : 0 ?>–<?= min($page * $perPage, $totalProjects) ?> of <?= $totalProjects ?> projects</small>
          <ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= h(dashPageUrl(max(1, $page - 1), $searchTerm, $letter, $starredOnly)) ?>">Previous</a></li>
            <?php for ($n = max(1, $page - 2); $n <= min($pageCount, $page + 2); $n++): ?>
              <li class="page-item <?= $n === $page ? 'active' : '' ?>"><a class="page-link" href="<?= h(dashPageUrl($n, $searchTerm, $letter, $starredOnly)) ?>"><?= $n ?></a></li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $pageCount ? 'disabled' : '' ?>"><a class="page-link" href="<?= h(dashPageUrl(min($pageCount, $page + 1), $searchTerm, $letter, $starredOnly)) ?>">Next</a></li>
          </ul>
        </nav>
        <div id="noResultsCard" class="card empty-search text-center mt-3" hidden>
            <div class="card-body">
                <h5 id="noResultsTitle">No matching projects</h5>
                <p class="text-muted small mb-0">Click here to create this project.</p>
            </div>
        </div>
        <?php if (!$projects): ?><p class="text-muted"><?= $starredOnly ? 'No starred projects match this view.' : 'No projects match this view.' ?></p><?php endif; ?>
    </div>

    <div class="modal fade" id="blankProject" tabindex="-1" aria-labelledby="createTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <form method="post" id="createForm" class="modal-content">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['dashboard_csrf']) ?>">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="createTitle">Create a Blank Project</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="projectName" class="form-label">Project Name*</label>
                        <input type="text" id="projectName" name="project_name" class="form-control" value="<?= h($oldName) ?>" pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,79}" maxlength="80" required autocomplete="off" placeholder="Example: modern-xampp-dashboard">
                        <div class="form-text">Letters, numbers, underscores and hyphens only.</div>
                    </div>
                    <div class="mb-3 d-flex flex-wrap gap-3">
  <label class="form-check-label"><input class="form-check-input me-1" type="checkbox" name="include_readme" value="1" checked> README.md</label>
  <label class="form-check-label"><input class="form-check-input me-1" type="checkbox" name="include_gitignore" value="1" checked> .gitignore</label>
  <label class="form-check-label"><input class="form-check-input me-1" type="checkbox" name="include_license" value="1"> LICENSE (MIT stub)</label>
 </div>
 <div class="mb-2 d-flex flex-wrap gap-3">
<label class="form-check-label"><input class="form-check-input me-1" type="checkbox" name="include_changelog" value="1"> CHANGELOG.md</label>
<label class="form-check-label"><input class="form-check-input me-1" type="checkbox" name="include_security" value="1"> SECURITY.md</label>
</div>
<label class="form-label" for="editor">index.php (optional)</label>
                    <div class="editor-shell">
                        <div class="editor-toolbar" role="toolbar" aria-label="PHP editor tools">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="formatBtn" title="Reindent and remove trailing whitespace">✨ Format</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="indentBtn">→ Indent</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="outdentBtn">← Outdent</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="undoBtn">↶ Undo</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="redoBtn">↷ Redo</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="findBtn">🔎 Find</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="wrapBtn" aria-pressed="true">↩ Wrap: On</button>
                            <label class="visually-hidden" for="fontSize">Font size</label>
                            <select id="fontSize" class="form-select form-select-sm w-auto" title="Editor font size">
                                <option value="12">12px</option>
                                <option value="14" selected>14px</option>
                                <option value="16">16px</option>
                                <option value="18">18px</option>
                            </select>
                        </div>
                        <div id="editor" class="editor" aria-label="PHP source code editor"></div>
                        <div class="editor-status"><span id="editorStatus">PHP · UTF-8 · Spaces: 4</span><span id="editorPosition">Ln 1, Col 1</span></div>
                    </div>
                    <textarea name="project_code" id="codeOutput" hidden><?= h($oldCode) ?></textarea>
                    <textarea class="editor-fallback form-control mt-2" id="fallbackEditor" aria-label="PHP starter code fallback"><?= h($oldCode) ?></textarea>
                    <div class="form-text">Format performs basic reindentation and whitespace cleanup, not full PSR-12 formatting. Ctrl+F searches code while editing.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success" id="createBtn">✅ Create Project</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="blankProjectGit" tabindex="-1" aria-labelledby="createTitleGit" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <form method="post" id="createFormGit" class="modal-content">
                <input type="hidden" name="git_init" value="1">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['dashboard_csrf']) ?>">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="createTitleGit">Create a Blank Project with Git init</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="projectNameGit" class="form-label">Project Name*</label>
                        <input type="text" id="projectNameGit" name="project_name" class="form-control" value="<?= h($oldName) ?>" pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,79}" maxlength="80" required autocomplete="off" placeholder="Example: modern-xampp-dashboard">
                        <div class="form-text">Letters, numbers, underscores and hyphens only.</div>
                    </div>
                    <div class="mb-3">
                        <label for="remoteRepoURL" class="form-label">Remote Repository URL (optional)</label>
                        <input type="url" id="remoteRepoURL" name="remoteRepoURL" class="form-control" value="<?= h(!empty($_POST['git_init']) ? ($_POST['remoteRepoURL'] ?? '') : '') ?>" autocomplete="off" placeholder="Example: https://github.com/connect2sazad/modern-xampp-dashboard.git">
                    </div>
                    <div class="mb-3">
                        <label for="DefaultBranch" class="form-label">Default Branch*</label>
                        <select name="DefaultBranch" id="DefaultBranch" class="form-control">
                            <option value="master" <?= ($_POST['DefaultBranch'] ?? ($dashboardSettings['default_branch'] ?? 'main')) === 'master' ? 'selected' : '' ?>>Master - master branch</option>
                            <option value="main" <?= ($_POST['DefaultBranch'] ?? ($dashboardSettings['default_branch'] ?? 'main')) === 'main' ? 'selected' : '' ?>>Main - main branch</option>
                        </select>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="git_push" value="1" id="gitPush" <?= !empty($_POST['git_push']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="gitPush">Commit and push initial index.php to origin</label>
                    </div>
                    <div class="form-text mb-3">Requires a remote URL, configured Git author identity and existing Git authentication. The remote repository should be empty.</div>
                    <div class="mb-3 d-flex flex-wrap gap-3">
  <label class="form-check-label"><input class="form-check-input me-1" type="checkbox" name="include_readme" value="1" checked> README.md</label>
  <label class="form-check-label"><input class="form-check-input me-1" type="checkbox" name="include_gitignore" value="1" checked> .gitignore</label>
  <label class="form-check-label"><input class="form-check-input me-1" type="checkbox" name="include_license" value="1"> LICENSE (MIT stub)</label>
 </div>
 <div class="mb-2 d-flex flex-wrap gap-3">
<label class="form-check-label"><input class="form-check-input me-1" type="checkbox" name="include_changelog" value="1"> CHANGELOG.md</label>
<label class="form-check-label"><input class="form-check-input me-1" type="checkbox" name="include_security" value="1"> SECURITY.md</label>
</div>
<label class="form-label" for="editorGit">index.php (optional)</label>
                    <div class="editor-shell">
                        <div class="editor-toolbar" role="toolbar" aria-label="PHP editor tools">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="formatBtnGit" title="Reindent and remove trailing whitespace">✨ Format</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="indentBtnGit">→ Indent</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="outdentBtnGit">← Outdent</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="undoBtnGit">↶ Undo</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="redoBtnGit">↷ Redo</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="findBtnGit">🔎 Find</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="wrapBtnGit" aria-pressed="true">↩ Wrap: On</button>
                            <label class="visually-hidden" for="fontSizeGit">Font size</label>
                            <select id="fontSizeGit" class="form-select form-select-sm w-auto" title="Editor font size">
                                <option value="12">12px</option>
                                <option value="14" selected>14px</option>
                                <option value="16">16px</option>
                                <option value="18">18px</option>
                            </select>
                        </div>
                        <div id="editorGit" class="editor" aria-label="PHP source code editor"></div>
                        <div class="editor-status"><span id="editorStatusGit">PHP · UTF-8 · Spaces: 4</span><span id="editorPositionGit">Ln 1, Col 1</span></div>
                    </div>
                    <textarea name="project_code" id="codeOutputGit" hidden><?= h($oldCode) ?></textarea>
                    <textarea class="editor-fallback form-control mt-2" id="fallbackEditorGit" aria-label="PHP starter code fallback"><?= h($oldCode) ?></textarea>
                    <div class="form-text">Format performs basic reindentation and whitespace cleanup, not full PSR-12 formatting. Ctrl+F searches code while editing.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success" id="createBtnGit">✅ Create Project</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="projectSettingsModal" tabindex="-1" aria-labelledby="projectSettingsTitle" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content" method="post">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['dashboard_csrf']) ?>">
          <input type="hidden" name="action" value="save_project_settings">
          <input type="hidden" name="original_project" id="settingsOriginal">
          <input type="hidden" name="change_origin" id="changeOrigin" value="0">
          <div class="modal-header"><h5 class="modal-title" id="projectSettingsTitle">Project Settings</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
          <div class="modal-body">
            <label for="settingsName" class="form-label">Project folder name</label>
            <input class="form-control mb-3" id="settingsName" name="new_project_name" required maxlength="100">
            <label class="form-check mb-2" id="initializeGitOption">
              <input type="checkbox" class="form-check-input" id="initializeGit" name="initialize_git" value="1">
              Initialize Git for this project
            </label>
            <label for="settingsRemote" class="form-label">Git origin</label>
            <input class="form-control" id="settingsRemote" name="project_remote" placeholder="https://github.com/owner/repo.git" aria-describedby="settingsOriginStatus">
            <p class="form-text mb-3" id="settingsOriginStatus" aria-live="polite"></p>
            <label for="settingsDatabases" class="form-label">Connected MySQL databases</label>
            <input class="form-control mb-3" id="settingsDatabases" name="project_databases" placeholder="app_db, reporting_db">
            <div class="row g-2 mb-3">
              <div class="col">
                <label for="settingsGroup" class="form-label">Project group</label>
                <input class="form-control" id="settingsGroup" name="project_group" list="projectGroupOptions" maxlength="60" placeholder="Ungrouped">
                <datalist id="projectGroupOptions"><?php foreach ($projectGroups as $name => $color): ?><option value="<?= h($name) ?>" data-color="<?= h($color) ?>"><?php endforeach; ?></datalist>
              </div>
              <div class="col-auto">
                <label for="settingsGroupColor" class="form-label">Group color</label>
                <input type="color" class="form-control form-control-color" id="settingsGroupColor" name="group_color" value="#4566b0">
              </div>
            </div>
            <div class="d-flex gap-2 align-items-center mb-3">
              <label class="form-check mb-0"><input class="form-check-input" type="checkbox" id="useCardColor" name="use_card_color" value="1"> Use custom card color</label>
              <input type="color" class="form-control form-control-color" id="settingsCardColor" name="card_color" aria-label="Card color" value="#4566b0">
            </div>
            <label for="settingsNotes" class="form-label">Notes</label>
            <textarea class="form-control" id="settingsNotes" name="project_notes" rows="3"></textarea>
          </div>
          <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save Changes</button></div>
        </form>
      </div>
    </div>
    <div class="modal fade" id="cloneGitModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><form class="modal-content async-create-form" data-kind="clone">
      <input type="hidden" name="csrf" value="<?= h($_SESSION['dashboard_csrf']) ?>"><input type="hidden" name="action" value="clone">
      <div class="modal-header"><h5 class="modal-title">🔀 Clone Git Repository</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><label class="form-label">Project folder name</label><input name="project" class="form-control mb-3" pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,79}" required>
      <label class="form-label">Remote HTTPS repository URL</label><input name="remote" type="url" class="form-control mb-3" required placeholder="https://github.com/owner/repo.git">
      <label class="form-label">Branch (optional)</label><input name="branch" class="form-control mb-2" placeholder="Default branch">
      <div class="form-text">Git authentication must be configured on the Apache account.</div><div class="operation-progress mt-3" hidden><div class="progress"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width:100%">Working…</div></div><pre class="small bg-body-tertiary p-2 mt-2 operation-log" style="max-height:160px;overflow:auto"></pre></div></div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-primary">Clone Repository</button></div>
    </form></div></div>
    <div class="modal fade" id="frameworkModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><form class="modal-content async-create-form" data-kind="framework">
      <input type="hidden" name="csrf" value="<?= h($_SESSION['dashboard_csrf']) ?>"><input type="hidden" name="action" value="framework">
      <div class="modal-header"><h5 class="modal-title">🏗️ Create Framework Project</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><div class="row g-3"><div class="col-md-6"><label class="form-label">Project folder</label><input name="project" class="form-control" pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,79}" required></div><div class="col-md-6"><label class="form-label">Framework</label><select name="framework" class="form-select"><option value="laravel">Laravel</option><option value="yii">Yii 2 Basic</option><option value="cakephp">CakePHP</option><option value="symfony">Symfony</option><option value="codeigniter">CodeIgniter 4</option></select></div>
      <div class="col-md-6"><label class="form-label">Default Git branch</label><select name="branch" class="form-select"><option value="main">main</option><option value="master">master</option></select></div><div class="col-md-6"><label class="form-label">Remote Git URL (optional)</label><input name="remote" type="url" class="form-control" placeholder="https://github.com/owner/repo.git"></div></div>
      <div class="form-check mt-3"><input class="form-check-input" type="checkbox" name="git_init" value="1" id="fwGit" checked><label class="form-check-label" for="fwGit">Initialize Git repository</label></div>
      <div class="form-check"><input class="form-check-input" type="checkbox" name="git_push" value="1" id="fwPush"><label class="form-check-label" for="fwPush">Commit and push initial code to origin</label></div>
      <div class="form-text">Composer and Git must be installed. Initial push requires an empty remote and configured credentials.</div>
      <div class="operation-progress mt-3" hidden><div class="progress"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width:100%">Installing…</div></div><div class="small mt-1 operation-stage">Waiting</div><pre class="small bg-body-tertiary p-2 mt-2 operation-log" style="max-height:220px;overflow:auto;white-space:pre-wrap"></pre></div></div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-primary">Create Framework</button></div>
    </form></div></div>
    <div class="modal fade" id="cmsTemplateModal" tabindex="-1" aria-labelledby="cmsTemplateTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <form class="modal-content async-create-form" data-kind="template">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['dashboard_csrf']) ?>">
                <input type="hidden" name="action" value="template">
                <div class="modal-header">
                    <h5 class="modal-title" id="cmsTemplateTitle">Install CMS or Custom Template</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <label for="cmsProjectName" class="form-label">Project folder name</label>
                    <input id="cmsProjectName" name="project" class="form-control mb-3" pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,79}" required>
                    <label for="cmsTemplate" class="form-label">Template</label>
                    <select id="cmsTemplate" name="template" class="form-select" required>
                        <?php foreach (['cms' => 'CMS', 'framework' => 'Custom frameworks'] as $category => $group): ?>
                        <?php $options = array_filter($installTemplates, static fn($template) => $template['category'] === $category); ?>
                        <?php if ($options): ?>
                        <optgroup label="<?= h($group) ?>">
                            <?php foreach ($options as $key => $template):
                                $source = match ($template['method']) {
                                    'zip', 'release' => 'Release ZIP',
                                    'git' => 'Git',
                                    default => 'Composer',
                                };
                            ?>
                            <option value="<?= h($key) ?>" data-source="<?= h($source . ' · ' . $template['source']) ?>"><?= h($template['label']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-text text-break" id="cmsTemplateSource"></p>
                    <p class="small">Complete database or account setup in the CMS installer after the download.</p>
                    <a href="templates.php">Manage custom templates</a>
                    <div class="operation-progress mt-3" hidden>
                        <div class="progress"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width:100%">Installing…</div></div>
                        <div class="small mt-1 operation-stage"></div>
                        <pre class="small bg-body-tertiary p-2 mt-2 operation-log" style="max-height:220px;overflow:auto;white-space:pre-wrap"></pre>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button class="btn btn-primary" type="submit">Install template</button>
                </div>
            </form>
        </div>
    </div>

    <?php
    $restoreError = $_SESSION['dashboard_open_restore'] ?? '';
    $restoreKind = $_SESSION['dashboard_restore_kind'] ?? 'mxdp';
    $restoreBackup = null;
    $restoreId = $_SESSION['dashboard_restore_id'] ?? $_GET['restore_backup'] ?? '';
    unset($_SESSION['dashboard_open_restore'], $_SESSION['dashboard_restore_kind'], $_SESSION['dashboard_restore_id']);
    if ($restoreId !== '') {
        try { $restoreBackup = dashBackupRecord($restoreId); }
        catch (Throwable $e) { $restoreError = $e->getMessage(); }
    }
    ?>
    <div id="archiveDropOverlay" class="position-fixed top-0 start-0 w-100 h-100 bg-primary bg-opacity-75 text-white" style="z-index: 1100; pointer-events: none;" hidden>
        <div class="d-flex h-100 justify-content-center align-items-center"><p class="h3">Drop a ZIP or MXDP file to import</p></div>
    </div>
    <div class="modal fade" id="restoreMxdpModal" tabindex="-1" aria-labelledby="restoreMxdpTitle" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <form method="post" action="restore.php" enctype="multipart/form-data" class="modal-content" id="archiveRestoreForm">
          <input type="hidden" name="backup_id" id="restoreBackupId" value="<?= h($restoreBackup['id'] ?? '') ?>">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['dashboard_csrf']) ?>">
          <div class="modal-header"><h5 class="modal-title" id="restoreMxdpTitle">Restore an MXDP backup</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
          <div class="modal-body">
            <?php if ($restoreError !== ''): ?>
              <div class="alert alert-danger" role="alert"><?= h($restoreError) ?></div>
            <?php endif; ?>
            <label for="restoreProject" class="form-label">Project folder name</label>
            <input id="restoreProject" name="project" class="form-control" maxlength="100" value="<?= h($restoreBackup['project'] ?? '') ?>" required>
            <div class="form-text mb-3">A backup with files requires a new folder. A database-only backup can use an existing project.</div>
            <p id="restoreSavedName" class="form-text" <?= $restoreBackup ? '' : 'hidden' ?>><?= h($restoreBackup['filename'] ?? '') ?></p>
            <div id="restoreUploadFields" <?= $restoreBackup ? 'hidden' : '' ?>>
            <label for="restoreArchive" class="form-label" id="restoreArchiveLabel">MXDP file</label>
            <input id="restoreArchive" type="file" name="archive" accept=".mxdp" class="form-control mb-3" <?= $restoreBackup ? 'disabled' : 'required' ?>>
            </div>
            <div id="restorePasswordFields">
            <label for="restorePassword" class="form-label">Backup password</label>
            <input id="restorePassword" type="password" name="password" class="form-control" autocomplete="current-password" required>
            <label class="form-check mt-2"><input class="form-check-input" type="checkbox" data-password-toggle="restorePassword"> Show password</label>
            </div>
          </div>
          <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Open backup</button></div>
        </form>
      </div>
    </div>

    <?php if ($setup = $_SESSION['dashboard_db_setup'] ?? null): ?>
    <div class="modal fade" id="databaseSetupModal" tabindex="-1" aria-labelledby="databaseSetupTitle" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form method="post" action="database_setup.php" class="modal-content" id="databaseSetupForm">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['dashboard_csrf']) ?>">
          <input type="hidden" name="project" value="<?= h($setup['project']) ?>">
          <div class="modal-header"><h5 class="modal-title" id="databaseSetupTitle">Set up databases for <?= h($setup['project']) ?>?</h5></div>
          <div class="modal-body">
          <?php if (!empty($setup['notice'])): ?>
            <div class="alert alert-warning" role="alert"><?= h($setup['notice']) ?></div>
          <?php endif; ?>
          <?php if ($setup['files']): ?>
            <p>Select the SQL files to import. Each selected file creates a new MySQL database and links it to the project.</p>
            <?php foreach ($setup['files'] as $index => $file): ?>
            <div class="row g-2 align-items-center mb-2">
              <div class="col-md-7 form-check ps-4 text-break">
                <input type="checkbox" class="form-check-input sql-file-choice" id="sqlFile<?= $index ?>" name="sql_files[]" value="<?= $index ?>">
                <label for="sqlFile<?= $index ?>" class="form-check-label"><?= h($file['label']) ?></label>
              </div>
              <div class="col-md-5">
                <label for="sqlDatabase<?= $index ?>" class="visually-hidden">Database name for <?= h($file['label']) ?></label>
                <input class="form-control" id="sqlDatabase<?= $index ?>" name="database_names[<?= $index ?>]" maxlength="64" value="<?= h($file['name']) ?>" disabled>
              </div>
            </div>
            <?php endforeach; ?>
          <?php else: ?>
            <p class="mb-0">No SQL files were found.</p>
          <?php endif; ?>
          </div>
          <div class="modal-footer">
            <button name="decision" value="skip" class="btn btn-secondary" formnovalidate><?= $setup['files'] ? 'Skip database setup' : 'Done' ?></button>
            <?php if ($setup['files']): ?><button name="decision" value="import" class="btn btn-primary" id="importDatabases" disabled>Import and link databases</button><?php endif; ?>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <div class="modal fade" id="todoModal" tabindex="-1" aria-labelledby="todoTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="todoTitle">To-do list</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="todoForm" class="d-flex gap-2 mb-3">
                        <label for="todoInput" class="visually-hidden">New task</label>
                        <input id="todoInput" class="form-control" placeholder="Add a task" maxlength="200" autocomplete="off" required>
                        <button class="btn btn-primary" type="submit" disabled>Add</button>
                    </form>
                    <p id="todoError" class="alert alert-danger py-2" role="alert" hidden></p>
                    <p id="todoEmpty" class="text-body-secondary mb-0">No tasks yet.</p>
                    <ul id="todoList" class="list-group"></ul>
                    <p id="todoStatus" class="small text-body-secondary mt-3 mb-0" aria-live="polite" hidden></p>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="removeProjectModal" tabindex="-1" aria-labelledby="removeProjectTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="post" class="modal-content" id="removeProjectForm">
                <input type="hidden" name="action" value="remove_project">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['dashboard_csrf']) ?>">
                <input type="hidden" name="remove_project" id="removeProjectName">
                <input type="hidden" name="confirm_project" id="removeProjectConfirmed">
                <div class="modal-header">
                    <h5 class="modal-title" id="removeProjectTitle">Remove project?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>This will permanently delete <strong id="removeProjectDisplay"></strong> and all its files, including local Git history.</p>
                    <p id="removeLinkedDatabases" class="small text-muted"></p>
                    <div class="form-check"><input class="form-check-input" id="removeDatabases" name="delete_databases" type="checkbox"><label class="form-check-label" for="removeDatabases">Delete linked databases</label></div>
                    <div class="form-check"><input class="form-check-input" id="removeBackupFull" name="backup_full" type="checkbox"><label class="form-check-label" for="removeBackupFull">Save an MXDP backup of the project and linked databases</label></div>
                    <div class="form-check mb-2"><input class="form-check-input" id="removeBackupDatabase" name="backup_database" type="checkbox"><label class="form-check-label" for="removeBackupDatabase">Save an MXDP backup of linked databases only</label></div>
                    <div id="removePasswordGroup" class="mb-3" hidden>
                      <label for="removeBackupPassword" class="form-label">Backup password</label>
                      <input id="removeBackupPassword" name="backup_password" type="password" class="form-control mb-2" minlength="8" maxlength="1024" autocomplete="new-password" disabled>
                      <label for="removeBackupConfirm" class="form-label">Confirm backup password</label>
                      <input id="removeBackupConfirm" name="backup_password_confirm" type="password" class="form-control" minlength="8" maxlength="1024" autocomplete="new-password" disabled>
                      <label class="form-check mt-2"><input id="showRemovePasswords" class="form-check-input" type="checkbox" data-password-toggle="removeBackupPassword removeBackupConfirm"> Show passwords</label>
                    </div>
                    <label for="removeProjectConfirm" class="form-label">Type the project name to confirm:</label>
                    <input type="text" class="form-control" id="removeProjectConfirm" autocomplete="off" required>
                    <div class="form-text">This action cannot be undone. Back up your project first.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" id="removeProjectSubmit" disabled>Delete permanently</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs/loader.js"></script>
    <script>
        'use strict';
        // A confirmation is required before any destructive POST.
        const removeModal = document.getElementById('removeProjectModal');
        const removeName = document.getElementById('removeProjectName');
        const removeDisplay = document.getElementById('removeProjectDisplay');
        const removeConfirm = document.getElementById('removeProjectConfirm');
        const removeSubmit = document.getElementById('removeProjectSubmit');
        const removeDatabases = document.getElementById('removeDatabases');
        const fullBackup = document.getElementById('removeBackupFull');
        const databaseBackup = document.getElementById('removeBackupDatabase');
        const passwordGroup = document.getElementById('removePasswordGroup');
        const backupPassword = document.getElementById('removeBackupPassword');
        const backupConfirm = document.getElementById('removeBackupConfirm');
        function updateBackupPassword() {
            const needsPassword = fullBackup.checked || databaseBackup.checked;
            passwordGroup.hidden = !needsPassword;
            for (const field of [backupPassword, backupConfirm]) {
                field.required = needsPassword;
                field.disabled = !needsPassword;
                if (!needsPassword) {
                    field.value = '';
                    field.type = 'password';
                }
            }
            if (!needsPassword) document.getElementById('showRemovePasswords').checked = false;
        }
        fullBackup.addEventListener('change', () => {
            if (fullBackup.checked) databaseBackup.checked = false;
            updateBackupPassword();
        });
        databaseBackup.addEventListener('change', () => {
            if (databaseBackup.checked) fullBackup.checked = false;
            updateBackupPassword();
        });
        const databaseSetup = document.getElementById('databaseSetupModal');
        if (databaseSetup) {
            const choices = [...databaseSetup.querySelectorAll('.sql-file-choice')];
            for (const choice of choices) {
                choice.addEventListener('change', () => {
                    const name = document.getElementById('sqlDatabase' + choice.value);
                    name.disabled = !choice.checked;
                    name.required = choice.checked;
                    document.getElementById('importDatabases').disabled = !choices.some(input => input.checked);
                });
            }
            bootstrap.Modal.getOrCreateInstance(databaseSetup, { backdrop: 'static', keyboard: false }).show();
        }
        <?php if ($restoreError !== '' || $restoreBackup || ($_GET['restore'] ?? '') === '1'): ?>
        if (!databaseSetup) {
            const modal = document.getElementById('restoreMxdpModal');
            modal.dataset.openKind = <?= json_encode($restoreKind) ?>;
            window.addEventListener('DOMContentLoaded', () => bootstrap.Modal.getOrCreateInstance(modal).show());
        }
        <?php endif; ?>
        removeModal.addEventListener('show.bs.modal', event => {
            const button = event.relatedTarget;
            const name = button ? button.dataset.project : '';
            const databases = event.relatedTarget?.dataset.databases || '';
            document.getElementById('removeLinkedDatabases').textContent = databases
                ? 'Linked databases: ' + databases : 'No databases linked to this project.';
            for (const option of [removeDatabases, fullBackup, databaseBackup]) option.checked = false;
            databaseBackup.disabled = removeDatabases.disabled = !databases;
            updateBackupPassword();
            removeName.value = name;
            removeDisplay.textContent = name;
            removeConfirm.value = '';
            removeSubmit.disabled = true;
            document.getElementById('removeProjectConfirmed').value = '';
        });
        removeModal.addEventListener('shown.bs.modal', () => removeConfirm.focus());
        removeConfirm.addEventListener('input', () => {
            removeSubmit.disabled = removeConfirm.value !== removeName.value;
            document.getElementById('removeProjectConfirmed').value = removeConfirm.value;
        });
        document.getElementById('removeProjectForm').addEventListener('submit', event => {
            if (removeConfirm.value !== removeName.value) event.preventDefault();
            else removeSubmit.disabled = true;
        });
        document.querySelectorAll('.async-create-form').forEach(form=>form.addEventListener('submit',async event=>{
            event.preventDefault();const button=form.querySelector('[type="submit"]');const area=form.querySelector('.operation-progress');const bar=area.querySelector('.progress-bar');const log=area.querySelector('.operation-log');const stage=area.querySelector('.operation-stage');
            button.disabled=true;area.hidden=false;log.textContent='';bar.classList.add('progress-bar-animated');bar.classList.remove('bg-success','bg-danger');bar.style.width='100%';
            if(stage)stage.textContent='Starting…';
            try {
                const response=await fetch('project_job.php',{method:'POST',body:new FormData(form),headers:{'Accept':'application/x-ndjson'}});
                if(!response.ok||!response.body)throw new Error('Server HTTP '+response.status);
                const reader=response.body.getReader();const decoder=new TextDecoder();let buffer='';let completed=false;
                while(true){const {value,done}=await reader.read();if(done)break;buffer+=decoder.decode(value,{stream:true});let at;
                    while((at=buffer.indexOf('\n'))!==-1){const line=buffer.slice(0,at);buffer=buffer.slice(at+1);if(!line.trim())continue;
                        let message;try{message=JSON.parse(line)}catch(e){continue}
                        if(message.stage && stage)stage.textContent=message.stage;
                        if(message.message){log.textContent+=message.message+'\n';log.scrollTop=log.scrollHeight;}
                        if(message.type==='done'){completed=true;bar.classList.remove('progress-bar-animated');bar.classList.add('bg-success');bar.textContent='Completed';}
                        if(message.type==='error'){completed=true;bar.classList.remove('progress-bar-animated');bar.classList.add('bg-danger');bar.textContent='Failed';}
                    }
                }
                if(!completed)throw new Error('Installation stream ended unexpectedly');
                if (bar.classList.contains('bg-success')) {
                    setTimeout(() => {
                        if (['framework', 'template'].includes(form.dataset.kind)) {
                            const dashboard = new URL('index.php', window.location.href);
                            dashboard.searchParams.set('q', form.elements.project.value.trim());
                            window.location.assign(dashboard);
                        } else {
                            window.location.reload();
                        }
                    }, 1100);
                }
            }catch(e){log.textContent+='Error: '+e.message+'\n';bar.classList.add('bg-danger');bar.classList.remove('progress-bar-animated');bar.textContent='Failed';}
            finally{button.disabled=false;}
        }));
        const cmsTemplate = document.getElementById('cmsTemplate');
        function updateTemplateSource() {
            document.getElementById('cmsTemplateSource').textContent = cmsTemplate.selectedOptions[0]?.dataset.source ?? '';
        }
        cmsTemplate.addEventListener('change', updateTemplateSource);
        updateTemplateSource();
        const instances = {};
        const starterModals = [{
                modal: 'blankProject',
                suffix: ''
            },
            {
                modal: 'blankProjectGit',
                suffix: 'Git'
            }
        ];

        function initializeEditor(config) {
            const suffix = config.suffix;
            const root = document.getElementById(config.modal);
            const output = document.getElementById('codeOutput' + suffix);
            const status = document.getElementById('editorStatus' + suffix);
            const position = document.getElementById('editorPosition' + suffix);
            const editor = monaco.editor.create(document.getElementById('editor' + suffix), {
                value: output.value,
                language: 'php',
                theme: document.documentElement.dataset.bsTheme === 'dark' ? 'vs-dark' : 'vs',
                automaticLayout: true,
                fontSize: 14,
                fontFamily: 'Consolas, monospace',
                lineHeight: 22,
                minimap: {
                    enabled: false
                },
                lineNumbers: 'on',
                wordWrap: 'on',
                autoIndent: 'full',
                autoClosingBrackets: 'always',
                autoClosingQuotes: 'always',
                bracketPairColorization: {
                    enabled: true
                },
                guides: {
                    indentation: true
                },
                folding: true,
                renderWhitespace: 'selection',
                scrollBeyondLastLine: false,
                tabSize: 4,
                insertSpaces: true,
                quickSuggestions: true
            });
            instances[suffix] = editor;
            if (root.classList.contains('show')) requestAnimationFrame(() => editor.layout());
            editor.onDidChangeCursorPosition(e => {
                position.textContent = `Ln ${e.position.lineNumber}, Col ${e.position.column}`;
            });
            editor.onDidChangeModelContent(() => output.value = editor.getValue());
            editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, () => {
                output.value = editor.getValue();
                status.textContent = 'Code ready · Create Project to save';
            });

            function action(id) {
                const item = editor.getAction(id);
                if (item) item.run();
                editor.focus();
            }
            document.getElementById('formatBtn' + suffix).addEventListener('click', async () => {
                const model = editor.getModel();
                const cleaned = model.getValue().split('\n')
                    .map(line => line.replace(/[ \t]+$/g, '')).join('\n');
                if (cleaned !== model.getValue()) {
                    editor.pushUndoStop();
                    editor.executeEdits('trim-whitespace', [{
                        range: model.getFullModelRange(),
                        text: cleaned
                    }]);
                    editor.pushUndoStop();
                }
                // Monaco has no built-in full PHP formatter.
                const reindent = editor.getAction('editor.action.reindentlines');
                if (reindent) {
                    editor.setSelection(model.getFullModelRange());
                    await reindent.run();
                }
                status.textContent = 'Basic whitespace/indent formatting applied';
                editor.focus();
            });
            document.getElementById('indentBtn' + suffix).addEventListener('click', () => action('editor.action.indentLines'));
            document.getElementById('outdentBtn' + suffix).addEventListener('click', () => action('editor.action.outdentLines'));
            document.getElementById('undoBtn' + suffix).addEventListener('click', () => editor.trigger('toolbar', 'undo', null));
            document.getElementById('redoBtn' + suffix).addEventListener('click', () => editor.trigger('toolbar', 'redo', null));
            document.getElementById('findBtn' + suffix).addEventListener('click', () => action('actions.find'));
            let wrap = true;
            document.getElementById('wrapBtn' + suffix).addEventListener('click', function() {
                wrap = !wrap;
                editor.updateOptions({
                    wordWrap: wrap ? 'on' : 'off'
                });
                this.textContent = '↩ Wrap: ' + (wrap ? 'On' : 'Off');
                this.setAttribute('aria-pressed', String(wrap));
                editor.focus();
            });
            document.getElementById('fontSize' + suffix).addEventListener('change', function() {
                editor.updateOptions({
                    fontSize: Number(this.value)
                });
            });
            root.addEventListener('shown.bs.modal', () => {
                requestAnimationFrame(() => {
                    editor.layout();
                    editor.focus();
                });
            });
        }
        if (typeof require === 'function' && typeof require.config === 'function') {
            require.config({
                paths: {
                    vs: 'https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs'
                }
            });
            require(['vs/editor/editor.main'], () => starterModals.forEach(initializeEditor),
                () => starterModals.forEach(config => showFallback(config)));
        }

        function showFallback(config) {
            const suffix = config.suffix;
            document.getElementById('editor' + suffix).style.display = 'none';
            const fallback = document.getElementById('fallbackEditor' + suffix);
            fallback.style.display = 'block';
            document.getElementById('editorStatus' + suffix).textContent = 'Plain PHP editor · Monaco unavailable';
            document.getElementById('codeOutput' + suffix).value = fallback.value;
            fallback.addEventListener('input', () => {
                document.getElementById('codeOutput' + suffix).value = fallback.value;
            });
        }
        if (typeof require !== 'function' || typeof require.config !== 'function') {
            starterModals.forEach(showFallback);
        }
        starterModals.forEach(config => {
            const suffix = config.suffix;
            const form = document.getElementById('createForm' + suffix);
            const output = document.getElementById('codeOutput' + suffix);
            form.addEventListener('submit', () => {
                if (instances[suffix]) output.value = instances[suffix].getValue();
                else output.value = document.getElementById('fallbackEditor' + suffix).value;
                document.getElementById('createBtn' + suffix).disabled = true;
            });
        });
        <?php if ($error !== ''): ?>
            window.addEventListener('load', () => bootstrap.Modal.getOrCreateInstance(
                document.getElementById(<?= json_encode(!empty($_POST['git_init']) ? 'blankProjectGit' : 'blankProject') ?>)
            ).show());
        <?php endif; ?>

        const searchBox = document.getElementById('searchBoxWrapper');
        const searchInput = document.getElementById('projectSearch');
        const projectCards = [...document.querySelectorAll('.project-card')];
        const noResults = document.getElementById('noResultsCard');

        function openSearch() {
            searchBox.hidden = false;
            searchInput.focus();
        }

        function filterProjects() {
            const query = searchInput.value.trim();
            const url = new URL(window.location.href);
            url.searchParams.delete('page');
            url.searchParams.delete('letter');
            if (query) url.searchParams.set('q', query);
            else url.searchParams.delete('q');
            window.location.assign(url.toString());
        }
        let searchTimer;
        document.getElementById('showSearch').addEventListener('click', openSearch);
        searchInput.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(filterProjects, 420); });
        noResults.addEventListener('click', () => {
            document.getElementById('projectName').value = searchInput.value.trim().replace(/[^a-zA-Z0-9_-]/g, '');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('blankProject')).show();
        });
        document.addEventListener('keydown', e => {
            const modalOpen = starterModals.some(config => document.getElementById(config.modal).classList.contains('show'));
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'f' && !modalOpen) {
                e.preventDefault();
                openSearch();
            }
            if (e.key === 'Escape' && !modalOpen && !searchBox.hidden) {
                searchInput.value = '';
                searchBox.hidden = true;
                filterProjects();
            }
        });
        // Measure only visible cards, asynchronously, after the page has rendered.
        async function loadProjectSizes() {
            const names = [...document.querySelectorAll('.project-file-size')].map(el => el.dataset.project);
            if (!names.length) return;
            try {
                const response = await fetch('project_stats.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ projects: names }),
                    cache: 'no-store'
                });
                if (!response.ok) throw new Error('Statistics unavailable');
                const stats = await response.json();
                for (const name of names) {
                    const file = document.querySelectorAll('.project-file-size');
                    const db = document.querySelectorAll('.project-db-size');
                    const f = [...file].find(el => el.dataset.project === name);
                    const d = [...db].find(el => el.dataset.project === name);
                    if (f) f.textContent = '📁 Files: ' + (stats[name]?.files ?? 'Unavailable');
                    if (d) d.textContent = '🗄️ Database: ' + (stats[name]?.database ?? 'Unavailable');
                }
            } catch (e) {
                document.querySelectorAll('.project-file-size').forEach(el => el.textContent = '📁 Files: Unavailable');
                document.querySelectorAll('.project-db-size').forEach(el => { if (el.textContent.includes('Loading')) el.textContent = '🗄️ Database: Unavailable'; });
            }
        }
        window.addEventListener('load', () => setTimeout(loadProjectSizes, 0));
        document.querySelectorAll('.launch-helper').forEach(button => {
            button.addEventListener('click', async () => {
                try {
                    const response = await fetch(button.dataset.helper, {
                        method: 'POST',
                        body: new URLSearchParams({
                            project: button.dataset.project,
                            csrf: <?= json_encode($_SESSION['dashboard_csrf']) ?>
                        })
                    });
                    if (!response.ok) throw new Error(await response.text());
                } catch (error) {
                    alert('Could not launch helper: ' + error.message);
                }
            });
        });
    </script>
<script src="archive-ui.js" defer></script>
<script src="project-cards.js" defer></script>
<script src="todo.js" defer></script>
</body>

</html>
