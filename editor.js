'use strict';

const byId = id => document.getElementById(id);
const workspace = byId('workspace');
const { project, csrf } = workspace.dataset;
const tree = byId('fileTree');
const tabsElement = byId('editorTabs');
const textEditor = byId('textEditor');
const monacoElement = byId('monacoEditor');
const quick = byId('quickDialog');
const quickInput = byId('quickInput');
const quickResults = byId('quickResults');
const entryDialog = byId('entryDialog');
const entryForm = byId('entryForm');
const entryInput = byId('entryInput');
const entryError = byId('entryError');
const entryCancel = byId('entryCancel');
const entrySubmit = byId('entrySubmit');
const tabs = new Map();
const folders = new Map();
const expanded = new Set();
const loadingFolders = new Set();
let active = null;
let selectedFolder = '';
let busy = false;
let wrap = false;
let monacoEditor = null;
let modelId = 0;
let entryAction = null;

async function request(action, fields = {}, write = false, signal) {
    const data = { action, project, ...fields };
    const response = write
        ? await fetch('file_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ...data, csrf })
        })
        : await fetch('file_api.php?' + new URLSearchParams(data), { signal });
    if (!response.ok) throw new Error(await response.text());
    return response;
}

function message(text, isError = false) {
    const notice = byId('editorNotice');
    notice.hidden = !isError;
    notice.dataset.state = isError ? 'error' : '';
    notice.textContent = isError ? text : '';
    byId('editorMessage').textContent = isError ? 'Action failed' : text;
}

function dirty(tab) {
    return tab.text !== tab.savedText;
}

function commandButton(name) {
    return document.querySelector('button[data-command="' + name + '"]');
}

function updateToolbar() {
    commandButton('save').disabled = busy || !active || !dirty(active);
    commandButton('save-all').disabled = busy || ![...tabs.values()].some(dirty);
    for (const name of ['find', 'rename', 'delete']) commandButton(name).disabled = busy || !active;
    commandButton('preview').disabled = busy || !active || !/\.(?:php|html?)$/i.test(active.path);
    for (const name of ['new-file', 'new-folder', 'refresh']) commandButton(name).disabled = busy;
    byId('activePath').textContent = active?.path ?? 'No file open';
    byId('languageStatus').textContent = active
        ? (active.model?.getLanguageId() ?? extension(active.path)) + ' · ' + (active.eol === '\r\n' ? 'CRLF' : 'LF') + ' · UTF-8'
        : 'UTF-8';
}

async function run(operation) {
    if (busy) return;
    busy = true;
    textEditor.readOnly = true;
    monacoEditor?.updateOptions({ readOnly: true });
    updateToolbar();
    try {
        await operation();
    } catch (error) {
        message(error.message, true);
    } finally {
        busy = false;
        textEditor.readOnly = false;
        monacoEditor?.updateOptions({ readOnly: false });
        updateToolbar();
    }
}

function extension(path) {
    const name = path.split('/').pop();
    return name.includes('.') ? name.split('.').pop().toLowerCase() : 'text';
}

function basename(path) {
    return path.split('/').pop();
}

function parent(path) {
    return path.includes('/') ? path.slice(0, path.lastIndexOf('/')) : '';
}

function treeButton(label, kind, action) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'tree-row';
    const icon = document.createElement('span');
    icon.className = 'tree-kind';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = kind;
    const text = document.createElement('span');
    text.className = 'tree-name';
    text.textContent = label;
    button.append(icon, text);
    button.addEventListener('click', action);
    return button;
}

async function readFolder(path) {
    const response = await request('browse', { folder: path });
    folders.set(path, await response.json());
}

function renderTree() {
    function branch(path, container) {
        const entries = folders.get(path) ?? [];
        if (!entries.length && path !== '') {
            const note = document.createElement('li');
            note.className = 'tree-status';
            note.textContent = 'Empty folder';
            container.append(note);
        }
        for (const entry of entries) {
            const row = document.createElement('li');
            const open = expanded.has(entry.path);
            const button = treeButton(entry.name, entry.folder ? (open ? '▾' : '▸') : extension(entry.path).slice(0, 4),
                () => entry.folder ? toggleFolder(entry.path) : run(() => openFile(entry.path)));
            button.dataset.path = entry.path;
            button.title = entry.path;
            if (entry.folder) button.setAttribute('aria-expanded', String(open));
            if (entry.path === active?.path || (entry.folder && entry.path === selectedFolder)) {
                button.setAttribute('aria-current', 'true');
            }
            row.append(button);
            if (entry.folder && open) {
                const nested = document.createElement('ul');
                branch(entry.path, nested);
                row.append(nested);
            }
            container.append(row);
        }
    }
    tree.replaceChildren();
    branch('', tree);
    byId('treeStatus').textContent = folders.get('')?.length ? '' : 'No files or folders in this project.';
}

async function toggleFolder(path) {
    if (loadingFolders.has(path)) return;
    selectedFolder = path;
    if (expanded.has(path)) {
        expanded.delete(path);
    } else {
        loadingFolders.add(path);
        try {
            if (!folders.has(path)) await readFolder(path);
            expanded.add(path);
        } catch (error) {
            message(error.message, true);
            return;
        } finally {
            loadingFolders.delete(path);
        }
    }
    renderTree();
    [...tree.querySelectorAll('[data-path]')].find(row => row.dataset.path === path)?.focus();
}

async function refreshTree() {
    await readFolder('');
    for (const path of [...expanded]) {
        try { await readFolder(path); } catch {
            expanded.delete(path);
            folders.delete(path);
        }
    }
    renderTree();
    message('File tree refreshed');
}

async function revealParent(path) {
    const chain = parent(path).split('/').filter(Boolean);
    let folder = '';
    await readFolder(folder);
    for (const part of chain) {
        folder = folder ? folder + '/' + part : part;
        await readFolder(folder);
        expanded.add(folder);
    }
    selectedFolder = folder;
    renderTree();
}

function attachModel(tab) {
    if (!monacoEditor || tab.model) return;
    const model = monaco.editor.createModel(tab.bom + tab.text.replaceAll('\n', tab.eol), undefined,
        monaco.Uri.from({ scheme: 'inmemory', path: '/' + (++modelId) + '/' + tab.path }));
    tab.model = model;
    tab.listener = model.onDidChangeContent(() => {
        tab.text = model.getValue().replace(/\r\n?/g, '\n');
        tab.eol = model.getEOL();
        updateTab(tab);
    });
}

function renderTabs() {
    tabsElement.replaceChildren();
    for (const tab of tabs.values()) {
        const wrapper = document.createElement('div');
        wrapper.className = 'editor-tab';
        wrapper.classList.toggle('is-active', tab === active);
        const open = document.createElement('button');
        open.type = 'button';
        open.className = 'tab-switch';
        open.title = tab.path;
        open.setAttribute('aria-pressed', String(tab === active));
        const marker = document.createElement('span');
        marker.className = 'tab-dirty';
        marker.setAttribute('aria-label', 'Unsaved changes');
        marker.textContent = '●';
        marker.hidden = !dirty(tab);
        tab.marker = marker;
        const name = document.createElement('span');
        name.className = 'tab-name';
        name.textContent = basename(tab.path);
        open.append(marker, name);
        open.addEventListener('click', () => { if (!busy) activate(tab); });
        const close = document.createElement('button');
        close.type = 'button';
        close.title = 'Close ' + tab.path;
        close.setAttribute('aria-label', close.title);
        close.textContent = '×';
        close.addEventListener('click', () => closeTab(tab));
        wrapper.append(open, close);
        tabsElement.append(wrapper);
    }
    updateToolbar();
}

function updateTab(tab) {
    if (tab.marker) tab.marker.hidden = !dirty(tab);
    updateToolbar();
}

function updateCursor() {
    const cursor = monacoEditor?.getPosition();
    const before = textEditor.value.slice(0, textEditor.selectionStart);
    const line = cursor?.lineNumber ?? before.split('\n').length;
    const column = cursor?.column ?? before.length - before.lastIndexOf('\n');
    byId('cursorStatus').textContent = active ? 'Ln ' + line + ', Col ' + column : '';
}

function activate(tab, focus = true) {
    if (active && monacoEditor) active.view = monacoEditor.saveViewState();
    if (active && !monacoEditor) active.selection = [textEditor.selectionStart, textEditor.selectionEnd];
    active = tab;
    if (tab) selectedFolder = parent(tab.path);
    byId('editorEmpty').hidden = Boolean(tab);
    monacoElement.hidden = !tab || !monacoEditor;
    textEditor.hidden = !tab || Boolean(monacoEditor);
    if (monacoEditor) {
        if (tab) attachModel(tab);
        monacoEditor.setModel(tab?.model ?? null);
        if (tab?.view) monacoEditor.restoreViewState(tab.view);
        if (tab && focus) monacoEditor.focus();
    } else if (tab) {
        textEditor.value = tab.text;
        textEditor.setSelectionRange(...(tab.selection ?? [0, 0]));
        if (focus) textEditor.focus();
    }
    for (const row of tree.querySelectorAll('[data-path]')) {
        if (row.dataset.path === tab?.path) row.setAttribute('aria-current', 'true');
        else row.removeAttribute('aria-current');
    }
    renderTabs();
    updateCursor();
}

async function openFile(path) {
    let tab = tabs.get(path);
    if (!tab) {
        const response = await request('read', { file: path });
        // Decode without stripping a UTF-8 BOM so it can be preserved on save.
        const contents = new TextDecoder('utf-8', { ignoreBOM: true }).decode(await response.arrayBuffer());
        const bom = contents.startsWith('\ufeff') ? '\ufeff' : '';
        const text = contents.slice(bom.length).replace(/\r\n?/g, '\n');
        tab = { path, text, savedText: text, bom, eol: contents.includes('\r\n') ? '\r\n' : '\n',
            version: response.headers.get('X-File-Version') };
        tabs.set(path, tab);
    }
    activate(tab);
    message('Opened ' + path);
}

function closeTab(tab, checkDirty = true) {
    if ((busy && checkDirty) || (checkDirty && dirty(tab) && !confirm('Discard unsaved changes to ' + tab.path + '?'))) return;
    const siblings = [...tabs.values()];
    const index = siblings.indexOf(tab);
    tabs.delete(tab.path);
    if (active === tab) activate(siblings[index + 1] ?? siblings[index - 1] ?? null);
    tab.listener?.dispose();
    tab.model?.dispose();
    renderTabs();
}

async function saveTab(tab) {
    if (!tab || !dirty(tab)) return;
    const content = tab.bom + tab.text.replaceAll('\n', tab.eol);
    const response = await request('save', { file: tab.path, content, version: tab.version }, true);
    tab.version = response.headers.get('X-File-Version');
    tab.savedText = tab.text;
    updateTab(tab);
    message('Saved ' + tab.path);
}

async function saveAll() {
    for (const tab of tabs.values()) {
        try { await saveTab(tab); } catch (error) {
            throw new Error(tab.path + ': ' + error.message);
        }
    }
}

function setEntryError(text) {
    entryError.textContent = text;
    entryError.hidden = !text;
    if (text) entryInput.setAttribute('aria-invalid', 'true');
    else entryInput.removeAttribute('aria-invalid');
}

function openEntryDialog(options) {
    if (busy || entryDialog.open || quick.open) return;
    entryAction = options.commit;
    byId('entryTitle').textContent = options.title;
    byId('entryIcon').textContent = options.icon;
    byId('entryContext').textContent = options.context;
    byId('entryLabel').textContent = options.label;
    entrySubmit.textContent = options.submit;
    entryInput.value = options.value;
    setEntryError('');
    entryDialog.showModal();
    entryInput.focus();
    entryInput.setSelectionRange(options.start ?? options.value.length, options.value.length);
}

entryInput.addEventListener('input', () => setEntryError(''));
entryCancel.addEventListener('click', () => entryDialog.close());
entryDialog.addEventListener('cancel', event => {
    if (busy) event.preventDefault();
});
entryDialog.addEventListener('close', () => { entryAction = null; });
entryForm.addEventListener('submit', event => {
    event.preventDefault();
    const commit = entryAction;
    if (!commit || busy) return;
    const value = entryInput.value.trim();
    if (!value) {
        setEntryError('Enter a value to continue.');
        return;
    }
    run(async () => {
        let afterClose;
        entrySubmit.disabled = entryCancel.disabled = true;
        entryInput.readOnly = true;
        setEntryError('');
        try {
            afterClose = await commit(value);
        } catch (error) {
            setEntryError(error.message);
            entryInput.focus();
            return;
        } finally {
            entrySubmit.disabled = entryCancel.disabled = false;
            entryInput.readOnly = false;
        }
        entryDialog.close();
        if (afterClose) await afterClose();
    });
});

function createEntry(folder) {
    const value = selectedFolder ? selectedFolder + '/' : '';
    openEntryDialog({
        title: folder ? 'New folder' : 'New file',
        icon: folder ? 'DIR' : '+',
        context: 'In ' + project + '/' + value + ' · Missing parent folders are created automatically.',
        label: folder ? 'Folder path' : 'File path',
        submit: folder ? 'Create folder' : 'Create file',
        value,
        commit: async requested => {
            const path = requested.replaceAll('\\', '/').replace(/\/$/, '');
            if (!path || path === value.replace(/\/$/, '')) throw new Error('Enter a name after the folder path.');
            await request(folder ? 'create_folder' : 'create_file', folder ? { folder: path } : { file: path }, true);
            return async () => {
                if (folder) {
                    await revealParent(path + '/');
                    message('Created folder ' + path);
                } else {
                    await revealParent(path);
                    await openFile(path);
                }
            };
        },
    });
}

function renameActive() {
    if (!active) return;
    const tab = active;
    openEntryDialog({
        title: 'Rename file', icon: '↗', context: tab.path, label: 'New file path',
        submit: 'Rename', value: tab.path, start: tab.path.lastIndexOf('/') + 1,
        commit: async requested => {
            const path = requested.replaceAll('\\', '/');
            if (path === tab.path) return;
            if (tabs.has(path)) throw new Error('Close the destination tab before renaming.');
            await request('rename', { old: tab.path, new: path }, true);
            tabs.delete(tab.path);
            tab.path = path;
            tabs.set(path, tab);
            if (tab.model) {
                const type = monaco.languages.getLanguages().find(language => language.extensions?.includes('.' + extension(path)));
                monaco.editor.setModelLanguage(tab.model, type?.id ?? 'plaintext');
            }
            return async () => {
                renderTabs();
                await revealParent(path);
                message('Renamed to ' + path);
            };
        },
    });
}

function deleteActive() {
    if (!active || !confirm('Delete ' + active.path + '? This deletes the file from the project.')) return;
    const tab = active;
    run(async () => {
        await request('delete', { file: tab.path }, true);
        closeTab(tab, false);
        await revealParent(tab.path);
        message('Deleted ' + tab.path);
    });
}

function findInFile() {
    if (!active) return;
    if (monacoEditor) {
        monacoEditor.getAction('actions.find')?.run();
        return;
    }
    openEntryDialog({
        title: 'Find in file', icon: '⌕', context: active.path, label: 'Search text', submit: 'Find', value: '',
        commit: query => {
            const text = textEditor.value.toLowerCase();
            const match = text.indexOf(query.toLowerCase(), textEditor.selectionEnd);
            const position = match === -1 ? text.indexOf(query.toLowerCase()) : match;
            if (position === -1) throw new Error('No matches in this file.');
            return () => {
                textEditor.focus();
                textEditor.setSelectionRange(position, position + query.length);
                message('Match found');
            };
        },
    });
}

function previewFile() {
    if (!active || !/\.(?:php|html?)$/i.test(active.path)) return;
    const url = '../' + [project, ...active.path.split('/')].map(encodeURIComponent).join('/');
    window.open(url, '_blank', 'noopener');
    message(dirty(active) ? 'Preview opened the saved version' : 'Preview opened in a new tab');
}

let searchController;
let searchTimer;
function chooseResult(path) {
    quick.close();
    run(() => openFile(path));
}

async function searchFiles() {
    searchController?.abort();
    const controller = new AbortController();
    searchController = controller;
    const query = quickInput.value.trim();
    quickResults.replaceChildren();
    byId('quickStatus').textContent = query ? 'Searching…' : 'Search skips dependency folders. You can browse them in Explorer.';
    if (!query) return;
    try {
        const response = await request('search', { q: query }, false, controller.signal);
        const results = await response.json();
        if (controller.signal.aborted) return;
        for (const path of results) {
            const item = document.createElement('li');
            const button = treeButton(path, extension(path).slice(0, 4), () => chooseResult(path));
            button.title = path;
            item.append(button);
            quickResults.append(item);
        }
        byId('quickStatus').textContent = results.length
            ? (results.length === 80 ? 'Showing the first 80 matches' : results.length + ' matches')
            : 'No matching files';
    } catch (error) {
        if (!controller.signal.aborted) byId('quickStatus').textContent = error.message;
    }
}

quickInput.addEventListener('input', () => {
    searchController?.abort();
    clearTimeout(searchTimer);
    searchTimer = setTimeout(searchFiles, 180);
});
quickInput.addEventListener('keydown', event => {
    const first = quickResults.querySelector('button');
    if (!first) return;
    if (event.key === 'ArrowDown') { event.preventDefault(); first.focus(); }
    if (event.key === 'Enter') { event.preventDefault(); first.click(); }
});
quickResults.addEventListener('keydown', event => {
    if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return;
    event.preventDefault();
    const item = event.target.closest('li');
    const next = event.key === 'ArrowDown' ? item?.nextElementSibling : item?.previousElementSibling;
    (next?.querySelector('button') ?? quickInput).focus();
});
quick.addEventListener('close', () => { searchController?.abort(); clearTimeout(searchTimer); });

function openQuick() {
    if (!quick.open) quick.showModal();
    quickInput.focus();
    quickInput.select();
    searchFiles();
}

const commands = {
    quick: openQuick,
    'toggle-pane': () => {
        const hidden = workspace.classList.toggle('pane-hidden');
        commandButton('toggle-pane').setAttribute('aria-expanded', String(!hidden));
    },
    refresh: () => run(refreshTree),
    'new-file': () => createEntry(false),
    'new-folder': () => createEntry(true),
    save: () => run(() => saveTab(active)),
    'save-all': () => run(saveAll),
    rename: renameActive,
    delete: deleteActive,
    preview: previewFile,
    find: findInFile,
    wrap: () => {
        wrap = !wrap;
        textEditor.wrap = wrap ? 'soft' : 'off';
        monacoEditor?.updateOptions({ wordWrap: wrap ? 'on' : 'off' });
        commandButton('wrap').setAttribute('aria-pressed', String(wrap));
    },
};
document.querySelectorAll('[data-command]').forEach(button => {
    button.addEventListener('click', () => commands[button.dataset.command]());
});
document.addEventListener('keydown', event => {
    if (!event.ctrlKey && !event.metaKey) return;
    if (entryDialog.open) return;
    const key = event.key.toLowerCase();
    if (key === 's') { event.preventDefault(); commands[event.shiftKey ? 'save-all' : 'save'](); }
    if (key === 'p') { event.preventDefault(); openQuick(); }
    if (key === 'f' && active && !quick.open) { event.preventDefault(); findInFile(); }
}, true);
textEditor.addEventListener('input', () => {
    if (active) {
        active.text = textEditor.value;
        updateTab(active);
        updateCursor();
    }
});
for (const event of ['keyup', 'click', 'select']) textEditor.addEventListener(event, updateCursor);
window.addEventListener('beforeunload', event => {
    if ([...tabs.values()].some(dirty)) {
        event.preventDefault();
        event.returnValue = '';
    }
});

async function start() {
    try {
        await readFolder('');
        renderTree();
        const file = new URLSearchParams(location.search).get('file');
        if (file) await run(() => openFile(file));
    } catch (error) {
        byId('treeStatus').textContent = 'Could not load project files. Use Refresh to retry.';
        message(error.message, true);
    }
}

function loadMonaco() {
    const cdn = 'https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs';
    const loader = document.createElement('script');
    loader.src = cdn + '/loader.js';
    loader.addEventListener('load', () => {
        if (typeof require !== 'function') return;
        require.config({ paths: { vs: cdn } });
        require(['vs/editor/editor.main'], () => {
            const focus = document.activeElement === textEditor;
            const start = textEditor.selectionStart;
            const end = textEditor.selectionEnd;
            monacoEditor = monaco.editor.create(monacoElement, {
                theme: document.documentElement.dataset.bsTheme === 'dark' ? 'vs-dark' : 'vs',
                model: null, readOnly: busy, automaticLayout: true, wordWrap: wrap ? 'on' : 'off',
                minimap: { enabled: false }, fontLigatures: true, bracketPairColorization: { enabled: true },
                padding: { top: 10 }, scrollBeyondLastLine: false
            });
            monacoEditor.onDidChangeCursorPosition(updateCursor);
            byId('engineStatus').textContent = 'Monaco';
            if (active) {
                activate(active, focus);
                const model = active.model;
                // The fallback textarea uses LF offsets even for a CRLF file.
                const positionAt = offset => {
                    const lines = active.text.slice(0, offset).split('\n');
                    return new monaco.Position(lines.length, lines.at(-1).length + 1);
                };
                const first = model.validatePosition(positionAt(start));
                const last = model.validatePosition(positionAt(end));
                monacoEditor.setSelection(new monaco.Range(first.lineNumber, first.column, last.lineNumber, last.column));
            }
        }, () => { byId('engineStatus').textContent = 'Text editor'; });
    });
    document.head.append(loader);
}
start();
loadMonaco();
