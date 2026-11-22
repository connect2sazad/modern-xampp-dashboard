document.querySelectorAll('[data-password-toggle]').forEach(toggle => {
    toggle.addEventListener('change', () => {
        for (const id of toggle.dataset.passwordToggle.split(' ')) {
            document.getElementById(id).type = toggle.checked ? 'text' : 'password';
        }
    });
});

const archiveModal = document.getElementById('restoreMxdpModal');
if (archiveModal) {
    const archive = document.getElementById('restoreArchive');
    const project = document.getElementById('restoreProject');
    const password = document.getElementById('restorePassword');
    const backup = document.getElementById('restoreBackupId');
    const overlay = document.getElementById('archiveDropOverlay');
    let dragDepth = 0;

    function configureArchive(kind, saved = false) {
        const zip = kind === 'zip';
        if (!saved) backup.value = '';
        document.getElementById('restoreSavedName').hidden = !saved;
        document.getElementById('restoreUploadFields').hidden = saved;
        document.getElementById('restorePasswordFields').hidden = zip;
        document.getElementById('restoreMxdpTitle').textContent = zip ? 'Import a ZIP project' : 'Restore an MXDP backup';
        document.getElementById('restoreArchiveLabel').textContent = zip ? 'ZIP file' : 'MXDP file';
        archive.accept = zip ? '.zip' : '.mxdp';
        archive.required = !saved;
        archive.disabled = saved;
        password.required = !zip;
        password.disabled = zip;
        if (zip) password.value = '';
    }

    archiveModal.addEventListener('show.bs.modal', event => {
        if (event.relatedTarget?.dataset.archiveKind) {
            archive.value = '';
            project.value = '';
            configureArchive(event.relatedTarget.dataset.archiveKind);
        } else if (archiveModal.dataset.openKind) {
            configureArchive(archiveModal.dataset.openKind, Boolean(backup.value));
            delete archiveModal.dataset.openKind;
        }
    });

    function isFileDrag(event) {
        return Array.from(event.dataTransfer?.types ?? []).includes('Files');
    }

    window.addEventListener('dragenter', event => {
        if (!isFileDrag(event)) return;
        event.preventDefault();
        dragDepth++;
        overlay.hidden = false;
    });
    window.addEventListener('dragover', event => {
        if (!isFileDrag(event)) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'copy';
    });
    window.addEventListener('dragleave', event => {
        if (!isFileDrag(event)) return;
        dragDepth = Math.max(0, dragDepth - 1);
        if (dragDepth === 0) overlay.hidden = true;
    });
    window.addEventListener('drop', event => {
        if (!isFileDrag(event)) return;
        event.preventDefault();
        dragDepth = 0;
        overlay.hidden = true;
        const [file] = event.dataTransfer.files;
        const kind = file?.name.match(/\.(zip|mxdp)$/i)?.[1].toLowerCase();
        if (!kind || event.dataTransfer.files.length !== 1) {
            alert('Drop one ZIP or MXDP archive.');
            return;
        }
        const transfer = new DataTransfer();
        transfer.items.add(file);
        configureArchive(kind);
        archive.files = transfer.files;
        project.value = file.name.replace(/\.(zip|mxdp)$/i, '')
            .replace(/[^a-z0-9_.-]/gi, '-').replace(/^[^a-z0-9]+|\.+$/gi, '').slice(0, 100);
        const active = document.querySelector('.modal.show');
        if (active && active !== archiveModal) {
            active.addEventListener('hidden.bs.modal', () => bootstrap.Modal.getOrCreateInstance(archiveModal).show(), { once: true });
            bootstrap.Modal.getOrCreateInstance(active).hide();
        } else {
            bootstrap.Modal.getOrCreateInstance(archiveModal).show();
        }
    });
}
