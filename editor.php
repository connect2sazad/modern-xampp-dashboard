<?php
require_once __DIR__ . '/dashboard_lib.php';
$project = $_GET['project'] ?? '';
try {
    dashProject($project);
} catch (Throwable $e) {
    http_response_code(400);
    exit('Invalid project.');
}
?>
<!DOCTYPE html>
<html lang="en" <?= dashThemeAttributes() ?>>
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Edit Project: <?= htmlspecialchars($project) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="themes.css">
  <style>
    #fileTree { height: 500px; overflow-y: auto; border: 1px solid var(--bs-border-color); }
    #editor { height: 500px; border: 1px solid var(--bs-border-color); }
    .tab { padding: 4px 8px; cursor: pointer; background: var(--bs-tertiary-bg); border: 1px solid var(--bs-border-color); margin-right: 4px; display: inline-block; }
    .tab.active { background: var(--bs-secondary-bg); font-weight: bold; }
  </style>
</head>
<body>
<div class="container-fluid py-3">
  <a href="index.php">Back to Dashboard</a><h3 class="mb-3">📝 Editing: <?= htmlspecialchars($project) ?></h3>
  <div class="row">
    <div class="col-md-3">
      <h5>📁 Files</h5>
      <div class="mb-2">
        <button class="btn btn-sm btn-outline-primary" onclick="newFile()">➕ File</button>
        <button class="btn btn-sm btn-outline-secondary" onclick="newFolder()">📁 Folder</button>
      </div>
      <ul id="fileTree" class="list-group"></ul>
    </div>
    <div class="col-md-9">
      <div id="tabs" class="mb-2"></div>
      <div id="editor">// Open a file to start editing</div>
      <div class="mt-2">
        <button class="btn btn-success" onclick="saveFile()">💾 Save</button>
        <button class="btn btn-danger" onclick="deleteFile()">🗑️ Delete</button>
        <button class="btn btn-warning" onclick="renameFile()">✏️ Rename</button>
      </div>
    </div>
  </div>
</div>

<!-- Monaco -->
<script src="https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs/loader.js"></script>
<script>
let editor, currentFile = '', busy = false, nextModelId = 0;
const tabs = new Map();
const project = <?= json_encode($project) ?>;
const csrf = <?= json_encode($_SESSION['dashboard_csrf']) ?>;

async function api(action, fields = {}, write = false) {
  const data = { action, project, ...fields };
  const response = write
    ? await fetch('file_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...data, csrf })
      })
    : await fetch('file_api.php?' + new URLSearchParams(data));
  if (!response.ok) throw new Error(await response.text());
  return action === 'list' ? response.json() : response.text();
}

async function run(operation) {
  if (!editor || busy) return;
  busy = true;
  editor.updateOptions({ readOnly: true });
  try {
    await operation();
  } catch (error) {
    alert(error.message);
  } finally {
    busy = false;
    editor.updateOptions({ readOnly: !currentFile });
  }
}

if (typeof require === 'function') {
  require.config({ paths: { vs: 'https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs' } });
  require(['vs/editor/editor.main'], () => {
    editor = monaco.editor.create(document.getElementById('editor'), {
      theme: document.documentElement.dataset.bsTheme === 'dark' ? 'vs-dark' : 'vs', automaticLayout: true, readOnly: true
    });
    run(loadTree);
  }, () => { document.getElementById('editor').textContent = 'Could not load Monaco. Refresh to retry.'; });
} else {
  document.getElementById('editor').textContent = 'Could not load Monaco. Refresh to retry.';
}

async function loadTree() {
  const files = await api('list');
  const tree = document.getElementById('fileTree');
  tree.replaceChildren();
  for (const file of files) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'list-group-item list-group-item-action text-break';
    button.textContent = file;
    button.onclick = () => run(() => openTab(file));
    tree.appendChild(button);
  }
}

async function openTab(file) {
  if (!tabs.has(file)) {
    const content = await api('read', { file });
    const model = monaco.editor.createModel(content, undefined, monaco.Uri.from({
      scheme: 'inmemory', path: '/' + (++nextModelId) + '/' + file
    }));
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'tab';
    button.title = file;
    button.textContent = file.split('/').pop();
    button.onclick = () => run(() => openTab(button.dataset.file));
    button.dataset.file = file;
    document.getElementById('tabs').appendChild(button);
    tabs.set(file, { model, button, savedVersion: model.getAlternativeVersionId() });
  }
  activate(file);
}

function activate(file) {
  tabs.get(currentFile)?.button.classList.remove('active');
  currentFile = file;
  const tab = tabs.get(file);
  tab?.button.classList.add('active');
  editor.setModel(tab?.model ?? null);
}

function saveFile() {
  if (!currentFile) return;
  run(async () => {
    const tab = tabs.get(currentFile);
    // The model has no edits when its Monaco version matches the last save.
    if (tab.savedVersion === tab.model.getAlternativeVersionId()) return;
    alert(await api('save', {
      file: currentFile, content: tab.model.getValue(monaco.editor.EndOfLinePreference.TextDefined, true)
    }, true));
    tab.savedVersion = tab.model.getAlternativeVersionId();
  });
}

function deleteFile() {
  if (!currentFile || !confirm('Delete ' + currentFile + '? Unsaved edits to this file will be lost.')) return;
  run(async () => {
    await api('delete', { file: currentFile }, true);
    const tab = tabs.get(currentFile);
    tabs.delete(currentFile);
    activate(tabs.keys().next().value ?? '');
    tab.button.remove();
    tab.model.dispose();
    await loadTree();
  });
}

function renameFile() {
  if (!currentFile) return;
  const name = prompt('New file path (relative):', currentFile)?.replaceAll('\\', '/');
  if (!name || name === currentFile) return;
  run(async () => {
    await api('rename', { old: currentFile, new: name }, true);
    const tab = tabs.get(currentFile);
    tabs.delete(currentFile);
    tabs.set(name, tab);
    tab.button.title = name;
    tab.button.dataset.file = name;
    tab.button.textContent = name.split('/').pop();
    currentFile = name;
    await loadTree();
  });
}

function newFile() {
  const file = prompt('Enter new file path:');
  if (file) run(async () => {
    await api('create_file', { file }, true);
    await loadTree();
    await openTab(file.replaceAll('\\', '/'));
  });
}

function newFolder() {
  const folder = prompt('Enter new folder path:');
  if (folder) run(() => api('create_folder', { folder }, true));
}

window.addEventListener('beforeunload', event => {
  if ([...tabs.values()].some(tab => tab.savedVersion !== tab.model.getAlternativeVersionId())) {
    event.preventDefault();
    event.returnValue = '';
  }
});
</script>
</body>
</html>
