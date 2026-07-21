import { renderMarkdown } from './markdown.js';

const SIDEBAR_KEY = 'sidebarCollapsed';

export function initSidebar() {
    function setSidebarCollapsed(collapsed) {
        document.body.classList.toggle('sidebar-collapsed', collapsed);
        localStorage.setItem(SIDEBAR_KEY, collapsed ? '1' : '0');
        document.getElementById('btn-open-sidebar').setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        document.getElementById('btn-close-sidebar').setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    }

    setSidebarCollapsed(localStorage.getItem(SIDEBAR_KEY) === '1');
    document.getElementById('btn-close-sidebar').addEventListener('click', () => setSidebarCollapsed(true));
    document.getElementById('btn-open-sidebar').addEventListener('click', () => setSidebarCollapsed(false));
}

function showThinkingState(prompt) {
    const messages = document.getElementById('messages');
    if (!messages) return;

    messages.querySelector('.empty')?.remove();

    const userBubble = document.createElement('div');
    userBubble.className = 'bubble user';
    userBubble.innerHTML = '<span class="role">user</span><div class="content" data-md="1"></div>';
    userBubble.querySelector('.content').innerHTML = renderMarkdown(prompt);
    messages.appendChild(userBubble);

    const thinking = document.createElement('div');
    thinking.className = 'bubble assistant thinking';
    thinking.setAttribute('aria-live', 'polite');
    thinking.setAttribute('aria-busy', 'true');
    thinking.innerHTML = `
        <span class="role">assistant</span>
        <div class="thinking-label">Thinking<span class="thinking-dots" aria-hidden="true"><span></span><span></span><span></span></span></div>
        <div class="thinking-progress" role="progressbar" aria-label="Waiting for model" aria-valuemin="0" aria-valuemax="100">
            <div class="thinking-progress-bar"></div>
        </div>
    `;
    messages.appendChild(thinking);
    messages.scrollTop = messages.scrollHeight;
    requestAnimationFrame(() => {
        messages.scrollTop = messages.scrollHeight;
    });
}

export function initComposer(updateChatContext) {
    document.getElementById('composer').addEventListener('submit', (event) => {
        const submitter = event.submitter;
        if (submitter && (submitter.name === 'clear' || submitter.name === 'new_window')) {
            return;
        }

        const textarea = document.querySelector('.composer textarea');
        const prompt = (textarea?.value || '').trim();
        if (prompt === '') {
            return;
        }

        updateChatContext();
        showThinkingState(prompt);

        const send = document.getElementById('send');
        send.disabled = true;
        send.textContent = 'Thinking…';
        if (textarea) textarea.readOnly = true;
    });

    document.querySelector('.composer textarea')?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            document.getElementById('composer').requestSubmit(document.getElementById('send'));
        }
    });
}
