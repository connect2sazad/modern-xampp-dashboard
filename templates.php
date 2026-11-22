<?php
require_once __DIR__ . '/template_lib.php';

$error = '';
$settings = dashJson('settings');
$templates = $settings['custom_templates'] ?? [];
$editing = $_GET['edit'] ?? '';
$form = is_string($editing) ? ($templates[$editing] ?? []) : [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        dashCsrf();
        $id = $_POST['id'] ?? '';
        if ($id !== '' && (!is_string($id) || !isset($templates[$id]))) throw new RuntimeException('Template not found.');
        if (($_POST['action'] ?? '') === 'remove' && $id !== '') {
            unset($templates[$id]);
            dashNotice('Custom template removed.');
        } elseif (($_POST['action'] ?? '') === 'save') {
            $form = array_intersect_key($_POST, array_flip(['label', 'category', 'method', 'source', 'version', 'directory', 'install_dependencies']));
            $editing = $id;
            $templates[$id !== '' ? $id : 'custom-' . bin2hex(random_bytes(8))] = dashTemplateFields($form);
            dashNotice('Custom template saved.');
        } else {
            throw new RuntimeException('Unknown template action.');
        }
        $settings['custom_templates'] = $templates;
        dashSave('settings', $settings);
        dashRedirect('templates.php');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
function h($value): string {
    return htmlspecialchars(is_string($value) ? $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="en" <?= dashThemeAttributes($settings) ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Project Templates</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="themes.css">
</head>
<body>
<main class="container py-4">
  <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Custom project templates</h1>
    <div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="settings.php">Settings</a><a class="btn btn-outline-secondary" href="index.php">Dashboard</a></div>
  </div>
  <?php if ($error): ?><p class="alert alert-danger" role="alert"><?= h($error) ?></p><?php endif; ?>
  <?php if (isset($_SESSION['dashboard_flash_success'])): ?>
    <p class="alert alert-success" role="status"><?= h($_SESSION['dashboard_flash_success']) ?></p>
    <?php unset($_SESSION['dashboard_flash_success']); ?>
  <?php endif; ?>
  <section class="card mb-4">
    <div class="card-body">
      <h2 class="h5"><?= $editing && $form ? 'Edit template' : 'Add a CMS or framework' ?></h2>
      <form method="post" class="row g-3">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['dashboard_csrf']) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= h($editing && $form ? $editing : '') ?>">
        <div class="col-md-6"><label for="label" class="form-label">Name</label><input id="label" name="label" class="form-control" maxlength="80" value="<?= h($form['label'] ?? '') ?>" required></div>
        <div class="col-md-3"><label for="category" class="form-label">Type</label><select id="category" name="category" class="form-select"><option value="cms">CMS</option><option value="framework" <?= ($form['category'] ?? '') === 'framework' ? 'selected' : '' ?>>Framework</option></select></div>
        <div class="col-md-3"><label for="method" class="form-label">Install method</label><select id="method" name="method" class="form-select"><option value="git">Git clone</option><option value="composer" <?= ($form['method'] ?? '') === 'composer' ? 'selected' : '' ?>>Composer</option></select></div>
        <div class="col-md-6"><label for="source" class="form-label">Source</label><input id="source" name="source" class="form-control" value="<?= h($form['source'] ?? '') ?>" required><div class="form-text">Use an HTTPS Git repository or a Composer package such as vendor/project.</div></div>
        <div class="col-md-3"><label for="version" class="form-label">Branch, tag, or version</label><input id="version" name="version" class="form-control" value="<?= h($form['version'] ?? '') ?>"><div class="form-text">Optional. Git uses its default branch. Composer resolves a compatible release.</div></div>
        <div class="col-md-3"><label for="directory" class="form-label">Public folder</label><input id="directory" name="directory" class="form-control" value="<?= h($form['directory'] ?? '') ?>" placeholder="public"><div class="form-text">Leave blank for the project root.</div></div>
        <div class="col-12"><label class="form-check"><input type="checkbox" name="install_dependencies" value="1" class="form-check-input"
            <?= !empty($form['installDependencies']) || !empty($form['install_dependencies']) ? 'checked' : '' ?>> Install Composer dependencies after Git clone</label></div>
        <div><button class="btn btn-primary">Save template</button><?php if ($editing && $form): ?> <a class="btn btn-outline-secondary" href="templates.php">Cancel edit</a><?php endif; ?></div>
      </form>
    </div>
  </section>
  <?php if ($templates): ?>
  <div class="table-responsive">
  <table class="table align-middle">
    <thead><tr><th>Name</th><th>Type</th><th>Source</th><th>Public folder</th><th>Actions</th></tr></thead>
    <tbody>
      <?php foreach ($templates as $id => $template): ?>
      <tr>
        <th scope="row"><?= h($template['label']) ?></th>
        <td><?= h(ucfirst($template['category'])) ?></td>
        <td class="text-break"><?= h(ucfirst($template['method']) . ' · ' . $template['source']) ?></td>
        <td><?= h($template['directory'] ?: 'Project root') ?></td>
        <td>
          <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-primary" href="?edit=<?= rawurlencode($id) ?>">Edit</a>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= h($_SESSION['dashboard_csrf']) ?>">
              <input type="hidden" name="id" value="<?= h($id) ?>">
              <button class="btn btn-sm btn-outline-danger" name="action" value="remove">Remove template</button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</main>
</body>
</html>
