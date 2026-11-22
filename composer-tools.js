const composerForm = document.getElementById('composerTools');
const composerResult = document.getElementById('composerResult');
const composerStatus = document.getElementById('composerStatus');
const composerOutput = document.getElementById('composerOutput');
const copyComposerOutput = document.getElementById('copyComposerOutput');

composerForm.addEventListener('submit', async event => {
    event.preventDefault();
    const action = event.submitter?.value;
    if (!action) return;
    if (action === 'update' && !confirm('Run Composer update? This can change dependency versions and composer.lock.')) return;

    const buttons = [...composerForm.querySelectorAll('button')];
    const request = new FormData(composerForm);
    request.set('action', action);
    buttons.forEach(button => { button.disabled = true; });
    composerResult.hidden = false;
    composerStatus.textContent = 'Starting Composer…';
    composerStatus.classList.remove('text-danger', 'text-success');
    composerOutput.textContent = '';
    copyComposerOutput.disabled = true;

    let completed = false;
    function handleLine(line) {
        if (!line.trim()) return;
        const event = JSON.parse(line);
        if (event.type === 'output') {
            composerOutput.append(document.createTextNode(event.message + '\n'));
            composerOutput.scrollTop = composerOutput.scrollHeight;
        } else if (event.type === 'project') {
            const project = JSON.parse(event.message);
            document.getElementById('composerDependencyStatus').textContent = project.dependencies ? 'Found' : 'Missing. Run Composer Install.';
            document.getElementById('composerLockStatus').textContent = project.lockfile ? 'Found' : 'Missing';
        } else if (event.type === 'error') {
            throw new Error(event.message);
        } else {
            composerStatus.textContent = event.message;
            completed = event.type === 'done';
        }
    }

    try {
        const response = await fetch(composerForm.action, {
            method: 'POST', body: request, headers: { Accept: 'application/x-ndjson' }
        });
        if (!response.ok || !response.body) throw new Error('Composer endpoint returned HTTP ' + response.status + '.');
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let pending = '';
        try {
            while (true) {
                const { value, done } = await reader.read();
                pending += decoder.decode(value, { stream: !done });
                const lines = pending.split('\n');
                pending = lines.pop();
                lines.forEach(handleLine);
                if (done) {
                    handleLine(pending);
                    break;
                }
            }
        } finally {
            reader.releaseLock();
        }
        if (!completed) throw new Error('Composer finished without reporting a result.');
        composerStatus.classList.add('text-success');
    } catch (error) {
        composerStatus.textContent = error.message;
        composerStatus.classList.add('text-danger');
    } finally {
        copyComposerOutput.disabled = !composerOutput.textContent;
        buttons.forEach(button => { button.disabled = false; });
    }
});
