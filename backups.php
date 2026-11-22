<?php
require_once __DIR__ . '/mxdp_lib.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        dashCsrf();
        $backup = dashBackupRecord($_POST['backup_id'] ?? '');
        if ($backup['filename'] !== ($_POST['confirm_name'] ?? null)) {
            throw new RuntimeException('Type the full backup filename to confirm deletion.');
        }
        if (!@unlink($backup['path'])) throw new RuntimeException('Could not delete the backup file.');
        @unlink($backup['path'] . '.json');
        unset($_SESSION['dashboard_backups'][$backup['id']]);
        if (($_SESSION['dashboard_latest_backup']['id'] ?? null) === $backup['id']) {
            unset($_SESSION['dashboard_latest_backup']);
        }
        dashNotice('Deleted ' . $backup['filename'] . '.');
    } catch (Throwable $e) {
        dashNotice($e->getMessage(), true);
    }
    dashRedirect('backups.php');
}

$backups = [];
foreach (glob(DASH_DATA . '/backup_*.mxdp') ?: [] as $path) {
    if (!preg_match('/\Abackup_([a-f0-9]{32})\.mxdp\z/', basename($path), $match)) continue;
    try {
        $backups[] = dashBackupRecord($match[1]);
    } catch (RuntimeException $e) {
        continue;
    }
}
usort($backups, static fn($first, $second) => $second['created'] <=> $first['created']);
$settings = dashJson('settings');
function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html>
<html lang="en" <?= dashThemeAttributes($settings) ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Backups</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"><link rel="stylesheet" href="themes.css">
</head>
<body><main class="container py-4">
  <div class="d-flex gap-3 justify-content-between flex-wrap align-items-center mb-4">
    <h1 class="h3 mb-0">Backups</h1>
    <nav class="d-flex gap-2">
      <a href="index.php?restore=1" class="btn btn-primary">Restore an MXDP file</a>
      <a href="tools.php" class="btn btn-outline-secondary">Project Tools</a>
      <a href="index.php" class="btn btn-outline-secondary">Dashboard</a>
    </nav>
  </div>
  <?php foreach (['error' => 'danger', 'success' => 'success'] as $key => $level):
      $message = $_SESSION['dashboard_flash_' . $key] ?? '';
      unset($_SESSION['dashboard_flash_' . $key]);
      if ($message !== ''): ?>
    <div class="alert alert-<?= $level ?>" role="status"><?= h($message) ?></div>
  <?php endif; endforeach; ?>
  <?php if (!$backups): ?>
    <p class="text-body-secondary">No saved backups. Use the Backup button on a project card to create one.</p>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th scope="col">Backup</th><th scope="col">Created</th><th scope="col">Size</th><th scope="col">Database included</th><th scope="col">Password protected</th><th scope="col">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($backups as $backup): ?>
        <tr>
          <th scope="row" class="text-break"><?= h($backup['filename']) ?></th>
          <td><time datetime="<?= date(DATE_ATOM, $backup['created']) ?>"><?= date('M j, Y H:i T', $backup['created']) ?></time></td>
          <td><?= h(dashBytes($backup['size'])) ?></td>
          <td><?= $backup['databases'] === null ? 'Unknown' : ($backup['databases'] ? 'Yes (' . (int)$backup['databases'] . ')' : 'No') ?></td>
          <td>Yes</td>
          <td><div class="d-flex flex-wrap gap-2">
            <a class="btn btn-sm btn-outline-primary" href="index.php?restore_backup=<?= h($backup['id']) ?>">Restore</a>
            <a class="btn btn-sm btn-outline-secondary" href="backup.php?download=<?= h($backup['id']) ?>">Download</a>
            <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteBackupModal" data-backup-id="<?= h($backup['id']) ?>" data-backup-name="<?= h($backup['filename']) ?>">Delete</button>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <div class="modal fade" id="deleteBackupModal" tabindex="-1" aria-labelledby="deleteBackupTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="post">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['dashboard_csrf']) ?>">
        <input type="hidden" name="backup_id" id="deleteBackupId">
        <div class="modal-header"><h2 class="modal-title h5" id="deleteBackupTitle">Delete backup?</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <p>This will permanently delete <strong id="deleteBackupName" class="text-break"></strong>.</p>
          <label class="form-label" for="deleteBackupConfirm">Type the full backup filename</label>
          <input class="form-control" id="deleteBackupConfirm" name="confirm_name" autocomplete="off" required>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger" id="deleteBackupSubmit" disabled>Delete backup</button></div>
      </form>
    </div>
  </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const deleteModal = document.getElementById('deleteBackupModal');
const backupName = document.getElementById('deleteBackupName');
const confirmation = document.getElementById('deleteBackupConfirm');
const submit = document.getElementById('deleteBackupSubmit');
deleteModal.addEventListener('show.bs.modal', event => {
    document.getElementById('deleteBackupId').value = event.relatedTarget.dataset.backupId;
    backupName.textContent = event.relatedTarget.dataset.backupName;
    confirmation.value = '';
    submit.disabled = true;
});
deleteModal.addEventListener('shown.bs.modal', () => confirmation.focus());
confirmation.addEventListener('input', () => {
    submit.disabled = confirmation.value !== backupName.textContent;
});
</script>
</body></html>
