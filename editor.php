<?php
require_once __DIR__ . '/dashboard_lib.php';
try {
    $project = basename(dashProject($_GET['project'] ?? ''));
} catch (Throwable $e) {
    http_response_code(404);
    exit('Project not found.');
}
function editorEscape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="en" <?= dashThemeAttributes() ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= editorEscape($project) ?> · Workspace Editor</title>
    <link rel="stylesheet" href="editor.css">
    <link rel="stylesheet" href="themes.css">
</head>
<body>
<div class="workspace" id="workspace" data-project="<?= editorEscape($project) ?>" data-csrf="<?= editorEscape($_SESSION['dashboard_csrf']) ?>">
    <header class="workspace-header">
        <a class="workspace-back" href="index.php?q=<?= rawurlencode($project) ?>" title="Back to Dashboard" aria-label="Back to Dashboard">←</a>
        <div class="workspace-title"><span>Workspace</span><strong><?= editorEscape($project) ?></strong></div>
        <button class="quick-trigger" type="button" data-command="quick">Go to file <kbd>Ctrl+P</kbd></button>
        <div class="workspace-tools">
            <button type="button" data-command="toggle-pane" aria-controls="explorer" aria-expanded="true">Files</button>
            <button type="button" data-command="save-all" disabled>Save all</button>
        </div>
    </header>
    <aside class="workspace-explorer" id="explorer" aria-label="Project files">
        <div class="explorer-header">
            <h1>Explorer</h1>
            <button type="button" data-command="refresh" title="Refresh files" aria-label="Refresh files">↻</button>
        </div>
        <div class="explorer-actions">
            <button type="button" data-command="new-file">＋ File</button>
            <button type="button" data-command="new-folder">＋ Folder</button>
        </div>
        <p id="treeStatus" class="tree-status" role="status">Loading files…</p>
        <ul id="fileTree" class="file-tree" aria-label="Project folder tree"></ul>
    </aside>
    <main class="workspace-main">
        <div class="editor-tabs" id="editorTabs" aria-label="Open files"></div>
        <div class="editor-toolbar">
            <span class="editor-path" id="activePath">No file open</span>
            <div class="editor-actions">
                <button type="button" data-command="find" disabled title="Find in file">Find</button>
                <button type="button" data-command="wrap" aria-pressed="false">Wrap</button>
                <button type="button" data-command="preview" disabled>Preview ↗</button>
                <button type="button" data-command="rename" disabled>Rename</button>
                <button type="button" data-command="delete" disabled class="editor-danger">Delete</button>
                <button type="button" data-command="save" disabled class="editor-primary" title="Save file (Ctrl+S)">Save</button>
            </div>
        </div>
        <div class="editor-notice" id="editorNotice" role="status" hidden></div>
        <div class="editor-stage">
            <div class="editor-empty" id="editorEmpty">
                <div class="editor-mark" aria-hidden="true">&lt;/&gt;</div>
                <h2>Open a file to get started</h2>
                <p>Choose a file in Explorer or jump to it by name.</p>
                <button type="button" data-command="quick">Go to file <kbd>Ctrl+P</kbd></button>
            </div>
            <div id="monacoEditor" class="editor-canvas" hidden></div>
            <textarea id="textEditor" class="editor-canvas text-editor" aria-label="File contents" spellcheck="false" wrap="off" hidden></textarea>
        </div>
    </main>
    <footer class="workspace-status">
        <span id="editorMessage" role="status">Ready</span>
        <div><span id="engineStatus">Text editor</span><span id="cursorStatus"></span><span id="languageStatus">UTF-8</span></div>
    </footer>
</div>
<dialog class="quick-dialog" id="quickDialog" aria-labelledby="quickTitle">
    <form method="dialog" class="quick-heading">
        <h2 id="quickTitle">Go to file</h2><button aria-label="Close quick open">×</button>
    </form>
    <input id="quickInput" type="search" placeholder="Search by file name or path" aria-label="Search project files" autocomplete="off">
    <p id="quickStatus" role="status">Search skips dependency folders. You can browse them in Explorer.</p>
    <ul id="quickResults" class="quick-results" aria-label="Search results"></ul>
</dialog>
<dialog class="entry-dialog" id="entryDialog" aria-labelledby="entryTitle">
    <form id="entryForm">
        <header class="entry-heading">
            <span class="entry-icon" id="entryIcon" aria-hidden="true"></span>
            <div>
                <h2 id="entryTitle"></h2>
                <p id="entryContext"></p>
            </div>
        </header>
        <div class="entry-fields">
            <label for="entryInput" id="entryLabel"></label>
            <input id="entryInput" type="text" autocomplete="off" spellcheck="false" aria-describedby="entryError" required>
            <p class="entry-error" id="entryError" role="alert" hidden></p>
        </div>
        <footer class="entry-footer">
            <button type="button" id="entryCancel">Cancel</button>
            <button type="submit" id="entrySubmit" class="entry-primary"></button>
        </footer>
    </form>
</dialog>
<script src="editor.js" defer></script>
</body>
</html>
