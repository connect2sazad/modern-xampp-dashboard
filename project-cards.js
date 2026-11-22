const projectList = document.getElementById('projectList');
const viewButtons = [...document.querySelectorAll('.project-view-toggle')];
const layoutKey = 'xampp-dashboard.project-view';

function showProjectView(view) {
    if (view !== 'list' && view !== 'tiles') view = 'tiles';
    projectList.dataset.view = view;
    for (const button of viewButtons) {
        const selected = button.dataset.view === view;
        button.classList.toggle('active', selected);
        button.setAttribute('aria-pressed', String(selected));
    }
}

try {
    showProjectView(localStorage.getItem(layoutKey));
} catch {
    showProjectView('tiles');
}
for (const button of viewButtons) {
    button.addEventListener('click', () => {
        showProjectView(button.dataset.view);
        try { localStorage.setItem(layoutKey, button.dataset.view); } catch {}
    });
}

const settingsModal = document.getElementById('projectSettingsModal');
const field = id => document.getElementById(id);
const origin = field('settingsRemote');
const init = field('initializeGit');
const originStatus = field('settingsOriginStatus');
const changeOrigin = field('changeOrigin');
let originRequest;
let originalOrigin = '';

origin.addEventListener('input', () => {
    changeOrigin.value = origin.value.trim() === originalOrigin ? '0' : '1';
});
init.addEventListener('change', () => {
    origin.disabled = !init.checked;
    if (!init.checked) {
        changeOrigin.value = '0';
        origin.value = '';
    }
    originStatus.textContent = init.checked ? 'Optional HTTPS origin for the new repository.' : 'Enable Git to add an origin.';
});
field('settingsGroup').addEventListener('input', event => {
    const value = event.target.value.trim().toLowerCase();
    const group = [...field('projectGroupOptions').options].find(option => option.value.toLowerCase() === value);
    if (group) field('settingsGroupColor').value = group.dataset.color;
});
field('useCardColor').addEventListener('change', event => {
    field('settingsCardColor').disabled = !event.target.checked;
});
settingsModal.addEventListener('hide.bs.modal', () => originRequest?.abort());
settingsModal.addEventListener('show.bs.modal', async event => {
    const project = event.relatedTarget?.dataset;
    if (!project) return;
    originRequest?.abort();
    field('settingsOriginal').value = field('settingsName').value = project.project;
    field('settingsDatabases').value = project.databases;
    field('settingsNotes').value = project.notes;
    field('settingsGroup').value = project.group;
    field('settingsGroupColor').value = project.groupColor;
    field('useCardColor').checked = Boolean(project.cardColor);
    field('settingsCardColor').disabled = !project.cardColor;
    field('settingsCardColor').value = project.cardColor || project.groupColor;

    const hasGit = project.git === '1';
    field('initializeGitOption').hidden = hasGit;
    init.checked = false;
    init.disabled = hasGit;
    changeOrigin.value = '0';
    origin.value = originalOrigin = '';
    origin.disabled = true;
    originStatus.textContent = hasGit ? 'Reading the repository origin…' : 'Enable Git to add an origin.';
    if (!hasGit) return;
    const controller = new AbortController();
    originRequest = controller;
    try {
        const response = await fetch('project_origin.php?' + new URLSearchParams({ project: project.project }), { signal: controller.signal });
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || 'Could not read the Git origin.');
        if (controller.signal.aborted) return;
        origin.value = originalOrigin = data.origin;
        origin.disabled = false;
        originStatus.textContent = data.origin
            ? 'Enter a new HTTPS URL to change origin, or clear the field to remove it.'
            : 'No origin configured. Enter an HTTPS URL to add one.';
    } catch (error) {
        if (!controller.signal.aborted) originStatus.textContent = error.message;
    }
});
