<?php
require_once __DIR__ . '/mxdp_lib.php';

$error = '';
$project = $_POST['folder'] ?? $_GET['folder'] ?? '';
try {
    if (isset($_GET['download'])) {
        $backup = dashBackupRecord($_GET['download']);
    } else {
        $root = dashProject($project);
        $databases = dashDbNames(dashJson('projects')[$project] ?? []);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            dashCsrf();
            $scope = $_POST['scope'] ?? '';
            if (!in_array($scope, ['full', 'database'], true) || ($scope === 'database' && !$databases)) {
                throw new RuntimeException('Select a backup type. A database-only backup requires linked databases.');
            }
            $password = dashConfirmedPassword($_POST['password'] ?? null, $_POST['password_confirm'] ?? null);
            $backup = dashCreateMxdp($project, $scope === 'full' ? $root : null, $databases, $password);
            dashRedirect('backup.php?download=' . $backup['id']);
        }
    }
    if (isset($backup)) {
        $file = $backup['path'];
        if (!is_file($file)) throw new RuntimeException('Backup file is missing.');
        session_write_close();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $backup['filename'] . '"');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: no-store');
        readfile($file);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(400);
    $error = $e->getMessage();
}
function backupEscape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html>
<html lang="en" <?= dashThemeAttributes() ?>><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>MXDP Backup</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"><link rel="stylesheet" href="themes.css"></head>
<body><main class="container py-4">
<a href="backups.php">Backups</a> · <a href="index.php">Dashboard</a>
<h1 class="h3 mt-3">Create an MXDP backup</h1>
<?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= backupEscape($error) ?></div><?php endif; ?>
<?php if (isset($root)): ?>
<p><?= backupEscape($project) ?><?= $databases ? ' · ' . backupEscape(implode(', ', $databases)) : '' ?></p>
<form method="post" action="backup.php" class="col-md-6">
<input type="hidden" name="folder" value="<?= backupEscape($project) ?>">
<input type="hidden" name="csrf" value="<?= backupEscape($_SESSION['dashboard_csrf']) ?>">
<label class="form-label" for="scope">Backup contents</label>
<select class="form-select mb-3" name="scope" id="scope">
<option value="full">Project files and linked databases</option>
<?php if ($databases): ?><option value="database">Linked databases only</option><?php endif; ?>
</select>
<label class="form-label" for="password">Backup password</label>
<input class="form-control mb-3" type="password" name="password" id="password" minlength="8" maxlength="1024" autocomplete="new-password" required>
<label class="form-label" for="passwordConfirm">Confirm backup password</label>
<input class="form-control mb-2" type="password" name="password_confirm" id="passwordConfirm" minlength="8" maxlength="1024" autocomplete="new-password" required>
<label class="form-check mb-3"><input class="form-check-input" type="checkbox" data-password-toggle="password passwordConfirm"> Show passwords</label>
<button class="btn btn-primary">Download MXDP</button>
</form>
<?php endif; ?>
</main><script src="archive-ui.js" defer></script></body></html>
