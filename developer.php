<?php
require_once __DIR__ . '/dashboard_lib.php';

try {
    $path = dashProject($_GET['project'] ?? '');
} catch (Throwable $e) {
    http_response_code(404);
    exit('Project not found.');
}
$project = basename($path);
$info = dashJson('projects')[$project] ?? [];
$framework = $info['template'] ?? DASH_FRAMEWORKS[$info['framework'] ?? ''] ?? null;
$plainError = '';
$launchSaved = false;
$lintFile = '';
$lintResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        dashCsrf();
        if ($framework !== null) throw new RuntimeException('These tools are for projects without a framework preset.');
        switch ($_POST['action'] ?? '') {
            case 'launch':
                $file = trim($_POST['launch_file'] ?? '');
                if ($file !== '') $file = dashProjectFile($path, $file, ['php', 'html', 'htm']);
                dashMeta($project, ['launch_file' => $file]);
                $info['launch_file'] = $file;
                $launchSaved = true;
                break;
            case 'lint':
                $lintFile = trim($_POST['lint_file'] ?? '');
                $file = dashProjectFile($path, $lintFile, ['php']);
                $lintResult = lintProjectPhp($path, $file);
                break;
            default:
                throw new RuntimeException('Unknown project action.');
        }
    } catch (Throwable $e) {
        $plainError = $e->getMessage();
    }
}
[$entry, $url] = dashProjectLaunch($path, $info);
$rootFiles = [];
if ($framework === null) {
    foreach (scandir($path) ?: [] as $filename) {
        if ($filename[0] === '.') continue;
        try {
            $rootFiles[] = dashProjectFile($path, $filename, ['php', 'html', 'htm']);
        } catch (RuntimeException $e) {
            continue;
        }
    }
}
$usesComposer = is_file($path . '/composer.json');
$hasDependencies = is_file($path . '/vendor/autoload.php');
$composer = $usesComposer ? json_decode((string)@file_get_contents($path . '/composer.json'), true) : null;
$requires = is_array($composer['require'] ?? null) ? $composer['require'] : [];
$requiredPhp = is_string($requires['php'] ?? null) ? $requires['php'] : '';
$missingExtensions = [];
foreach (array_keys($requires) as $requirement) {
    if (str_starts_with($requirement, 'ext-')) {
        $extension = substr($requirement, 4);
        if (!extension_loaded($extension)) $missingExtensions[] = $extension;
    }
}
$hasEnvTemplate = is_file($path . '/.env.example');
$hasEnv = is_file($path . '/.env');
$hasGit = file_exists($path . '/.git');
$commands = ['Set-Location -LiteralPath ' . "'" . str_replace("'", "''", $path) . "'"];
if ($usesComposer) $commands[] = 'composer install';
if ($hasDependencies) $commands[] = 'composer check-platform-reqs';
if ($hasGit) $commands[] = 'git status';

function lintProjectPhp(string $path, string $file): array {
    if (!function_exists('proc_open')) throw new RuntimeException('PHP process execution is disabled.');
    $php = DIRECTORY_SEPARATOR === '\\' ? dirname(__DIR__, 2) . '/php/php.exe' : PHP_BINDIR . '/php';
    if (!is_file($php)) $php = 'php';
    $log = tmpfile();
    if ($log === false) throw new RuntimeException('Could not open a lint log.');
    try {
        $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $process = @proc_open([$php, '-l', dashFilePath($path, $file)],
            [0 => ['file', $null, 'r'], 1 => $log, 2 => ['redirect', 1]], $pipes, $path);
        if (!is_resource($process)) throw new RuntimeException('Could not start the PHP syntax checker.');
        $code = proc_close($process);
        rewind($log);
        return ['passed' => $code === 0, 'output' => trim((string)fread($log, 8192))];
    } finally {
        fclose($log);
    }
}

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="en" <?= dashThemeAttributes() ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($project) ?> · Developer</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="themes.css">
</head>
<body>
<main class="container py-4" style="max-width: 52rem">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
        <h1 class="h3 mb-0"><?= h($project) ?></h1>
        <a href="index.php?q=<?= rawurlencode($project) ?>" class="btn btn-outline-secondary">Back to Dashboard</a>
    </div>
    <div class="d-flex flex-wrap gap-2 mb-3">
        <?php if ($url !== null): ?>
        <a id="appUrl" class="btn btn-primary" href="<?= h($url) ?>" target="_blank" rel="noopener">Open app</a>
        <button type="button" class="btn btn-outline-primary" data-copy="appUrl">Copy app URL</button>
        <?php endif; ?>
        <button type="button" class="btn btn-outline-primary" data-copy="projectPath">Copy project path</button>
    </div>
    <dl class="row">
        <dt class="col-sm-4">Project folder</dt><dd class="col-sm-8 text-break"><code id="projectPath"><?= h($path) ?></code></dd>
        <?php if ($framework): ?><dt class="col-sm-4">Template</dt><dd class="col-sm-8"><?= h($framework['label']) ?></dd><?php endif; ?>
        <?php if (isset($info['template']['source'])): ?>
            <dt class="col-sm-4">Install source</dt><dd class="col-sm-8 text-break"><code><?= h($info['template']['source']) ?></code></dd>
        <?php endif; ?>
        <dt class="col-sm-4">Web entry point</dt><dd class="col-sm-8"><?= $entry ? '<code>' . h($entry) . '</code>' : 'No entry point found' ?></dd>
        <dt class="col-sm-4">PHP serving the dashboard</dt><dd class="col-sm-8"><?= h(PHP_VERSION) ?></dd>
        <?php if ($usesComposer): ?>
        <dt class="col-sm-4">Composer autoloader</dt>
        <dd class="col-sm-8" id="composerDependencyStatus"><?= $hasDependencies ? 'Found' : 'Missing. Run Composer Install.' ?></dd>
        <dt class="col-sm-4">Composer lockfile</dt>
        <dd class="col-sm-8" id="composerLockStatus"><?= is_file($path . '/composer.lock') ? 'Found' : 'Missing' ?></dd>
        <?php if (!is_array($composer)): ?>
        <dt class="col-sm-4">Composer manifest</dt><dd class="col-sm-8">Could not read composer.json as JSON.</dd>
        <?php endif; ?>
        <?php if ($requiredPhp !== ''): ?>
        <dt class="col-sm-4">Declared PHP requirement</dt><dd class="col-sm-8"><code><?= h($requiredPhp) ?></code></dd>
        <?php endif; ?>
        <?php if ($missingExtensions): ?>
        <dt class="col-sm-4">Missing Apache PHP extensions</dt>
        <dd class="col-sm-8"><code><?= h(implode(', ', $missingExtensions)) ?></code></dd>
        <?php endif; ?>
        <?php endif; ?>
        <?php if ($hasEnvTemplate): ?>
        <dt class="col-sm-4">Local environment file</dt>
        <dd class="col-sm-8"><?= $hasEnv ? '.env found' : '.env missing. Use .env.example to set up local values.' ?></dd>
        <?php endif; ?>
    </dl>
    <?php if ($entry === null && $framework): ?>
        <p class="alert alert-info">This project has no web entry point in <code><?= h($framework['directory']) ?>/</code>. Complete the application setup before opening it in Apache.</p>
    <?php endif; ?>
    <?php if ($framework === null): ?>
    <section class="card mb-4" aria-labelledby="plainProjectToolsTitle">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <h2 class="h5" id="plainProjectToolsTitle">PHP and HTML tools</h2>
                <a href="editor.php?project=<?= rawurlencode($project) ?>" class="btn btn-sm btn-outline-secondary">Browse files</a>
            </div>
            <?php if ($plainError !== ''): ?>
                <p class="alert alert-danger" role="alert"><?= h($plainError) ?></p>
            <?php elseif ($launchSaved): ?>
                <p class="alert alert-success" role="status">Launch file preference saved.</p>
            <?php endif; ?>
            <form method="post" class="mb-3">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['dashboard_csrf']) ?>">
                <input type="hidden" name="action" value="launch">
                <label class="form-label" for="launchFile">Launch file</label>
                <div class="input-group">
                    <input id="launchFile" name="launch_file" class="form-control" list="launchFileOptions"
                           value="<?= h($info['launch_file'] ?? '') ?>" placeholder="e.g. pages/home.php" maxlength="260" aria-describedby="launchHelp">
                    <button class="btn btn-primary">Save launch file</button>
                </div>
                <datalist id="launchFileOptions">
                    <?php foreach ($rootFiles as $file): ?><option value="<?= h($file) ?>"><?php endforeach; ?>
                </datalist>
                <p class="form-text" id="launchHelp">The Open button uses this PHP or HTML file. Leave blank for automatic index detection.</p>
            </form>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['dashboard_csrf']) ?>">
                <input type="hidden" name="action" value="lint">
                <label class="form-label" for="lintFile">PHP syntax check</label>
                <div class="input-group">
                    <input id="lintFile" name="lint_file" class="form-control" list="lintFileOptions"
                           value="<?= h($lintFile) ?>" placeholder="e.g. includes/config.php" maxlength="260" required>
                    <button class="btn btn-outline-primary">Check syntax</button>
                </div>
                <datalist id="lintFileOptions">
                    <?php foreach ($rootFiles as $file): if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php') continue; ?>
                        <option value="<?= h($file) ?>">
                    <?php endforeach; ?>
                </datalist>
            </form>
            <?php if ($lintResult !== null): ?>
                <div class="alert <?= $lintResult['passed'] ? 'alert-success' : 'alert-danger' ?> mt-3 mb-0" role="status">
                    <strong><?= $lintResult['passed'] ? 'PHP syntax check passed.' : 'PHP syntax check failed.' ?></strong>
                    <?php if ($lintResult['output'] !== ''): ?><pre class="text-wrap mb-0"><?= h($lintResult['output']) ?></pre><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>
    <?php if ($usesComposer): ?>
    <section class="card mb-4" aria-labelledby="composerToolsTitle">
        <div class="card-body">
            <h2 class="h5" id="composerToolsTitle">Composer</h2>
            <form id="composerTools" action="composer_job.php" method="post">
                <input type="hidden" name="project" value="<?= h($project) ?>">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['dashboard_csrf']) ?>">
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-primary" name="action" value="install">Install</button>
                    <button class="btn btn-outline-primary" name="action" value="update">Update</button>
                    <button class="btn btn-outline-secondary" name="action" value="validate">Validate</button>
                    <button class="btn btn-outline-secondary" name="action" value="platform">Check platform</button>
                    <button class="btn btn-outline-secondary" name="action" value="outdated">Outdated packages</button>
                    <button class="btn btn-outline-secondary" name="action" value="audit">Audit dependencies</button>
                </div>
                <p class="form-text mb-0">Install uses the lockfile when available. Update resolves versions again and writes composer.lock.</p>
            </form>
            <div class="mt-3" id="composerResult" hidden>
                <p id="composerStatus" class="fw-semibold mb-2" role="status"></p>
                <pre id="composerOutput" class="bg-body-tertiary border rounded p-3 mb-2 overflow-auto text-wrap" style="max-height: 24rem" tabindex="0" aria-label="Composer output"></pre>
                <button type="button" id="copyComposerOutput" class="btn btn-sm btn-outline-secondary" data-copy="composerOutput" disabled>Copy output</button>
            </div>
        </div>
    </section>
    <?php endif; ?>
    <h2 class="h5">PowerShell commands</h2>
    <ul class="list-group">
        <?php foreach ($commands as $index => $command): ?>
        <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
            <code id="devCommand<?= $index ?>" class="text-break"><?= h($command) ?></code>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-copy="devCommand<?= $index ?>" aria-label="Copy <?= h($command) ?>">Copy</button>
        </li>
        <?php endforeach; ?>
    </ul>
    <p id="copyStatus" class="mt-3 text-body-secondary" role="status"></p>
</main>
<script>
const copyStatus = document.getElementById('copyStatus');
document.querySelectorAll('[data-copy]').forEach(button => {
    button.addEventListener('click', async () => {
        const item = document.getElementById(button.dataset.copy);
        try {
            await navigator.clipboard.writeText(item.href ?? item.textContent);
            copyStatus.textContent = 'Copied.';
        } catch {
            copyStatus.textContent = 'Clipboard unavailable. Select and copy the text manually.';
        }
    });
});
</script>
<?php if ($usesComposer): ?><script src="composer-tools.js" defer></script><?php endif; ?>
</body>
</html>
