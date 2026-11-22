const todoModal = document.getElementById('todoModal');
const todoForm = document.getElementById('todoForm');
const todoInput = document.getElementById('todoInput');
const todoList = document.getElementById('todoList');
const todoEmpty = document.getElementById('todoEmpty');
const todoStatus = document.getElementById('todoStatus');
const todoError = document.getElementById('todoError');
const todoKey = 'xampp-dashboard.todos.v1';
let tasks = [];

function showTodoError(message) {
    todoError.textContent = message;
    todoError.hidden = !message;
}

function updateTodoCount() {
    todoEmpty.hidden = tasks.length !== 0;
    todoStatus.hidden = tasks.length === 0;
    const remaining = tasks.filter(task => !task.done).length;
    todoStatus.textContent = remaining + ' of ' + tasks.length + ' remaining';
}

function saveTasks(update) {
    try {
        const changed = update(readTasks());
        localStorage.setItem(todoKey, JSON.stringify(changed));
        tasks = changed;
        showTodoError('');
        return true;
    } catch {
        showTodoError('Could not save tasks in this browser.');
        return false;
    }
}

function readTasks() {
    const stored = JSON.parse(localStorage.getItem(todoKey) ?? '[]');
    if (!Array.isArray(stored)) return [];
    return stored.filter(task => task && typeof task.id === 'string'
        && typeof task.text === 'string' && typeof task.done === 'boolean');
}

function renderTasks() {
    todoList.replaceChildren();
    for (const task of tasks) {
        const item = document.createElement('li');
        item.className = 'list-group-item d-flex align-items-center gap-2';

        const taskLabel = document.createElement('label');
        taskLabel.className = 'd-flex align-items-start gap-2 flex-grow-1 text-break';
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.className = 'form-check-input flex-shrink-0';
        checkbox.checked = task.done;
        const text = document.createElement('span');
        text.textContent = task.text;
        text.classList.toggle('text-decoration-line-through', task.done);
        taskLabel.append(checkbox, text);
        checkbox.addEventListener('change', () => {
            const done = checkbox.checked;
            if (!saveTasks(current => current.map(entry => entry.id === task.id ? { ...entry, done } : entry))) {
                checkbox.checked = !done;
                return;
            }
            text.classList.toggle('text-decoration-line-through', done);
            updateTodoCount();
        });

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn btn-sm btn-outline-danger';
        remove.textContent = 'Delete';
        remove.setAttribute('aria-label', 'Delete task: ' + task.text);
        remove.addEventListener('click', () => {
            if (!saveTasks(current => current.filter(entry => entry.id !== task.id))) return;
            renderTasks();
            todoInput.focus();
        });

        item.append(taskLabel, remove);
        todoList.append(item);
    }
    updateTodoCount();
}

function loadTasks() {
    try {
        tasks = readTasks();
        showTodoError('');
    } catch {
        showTodoError('Could not read tasks from browser storage.');
    }
    renderTasks();
}

todoForm.addEventListener('submit', event => {
    event.preventDefault();
    const text = todoInput.value.trim();
    if (!text || text.length > 200) return;
    const id = crypto.randomUUID();
    if (!saveTasks(current => [...current, { id, text, done: false }])) return;
    todoInput.value = '';
    renderTasks();
    todoInput.focus();
});

todoModal.addEventListener('show.bs.modal', loadTasks);
todoModal.addEventListener('shown.bs.modal', () => todoInput.focus());
window.addEventListener('storage', event => {
    if (event.key === todoKey || event.key === null) loadTasks();
});
todoForm.querySelector('button').disabled = false;
