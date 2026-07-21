import { initSidebar, initComposer } from './sidebar.js';
import { initEvalLog } from './eval-log.js';
import { initProjects } from './projects.js';
import { initUpload } from './upload.js';
import { hydrateChatMarkdown } from './markdown.js';

const LANG_KEY = 'chatLanguage';
const PROJECT_IDS_KEY = 'selectedProjectIds';
const AGENT_KEY = 'useAgent';

const messages = document.getElementById('messages');
function scrollMessagesToBottom() {
    if (!messages) return;
    messages.scrollTop = messages.scrollHeight;
}

hydrateChatMarkdown();
scrollMessagesToBottom();
requestAnimationFrame(scrollMessagesToBottom);

if (messages) {
    messages.addEventListener('click', (event) => {
        const btn = event.target.closest('.refs-toggle');
        if (!btn || !messages.contains(btn)) return;

        const refs = btn.closest('.refs');
        if (!refs) return;

        const expanded = refs.dataset.expanded === '1';
        const next = !expanded;
        refs.dataset.expanded = next ? '1' : '0';
        btn.setAttribute('aria-expanded', next ? 'true' : 'false');
        const more = Number(btn.dataset.more || 0);
        btn.textContent = next
            ? 'Show fewer'
            : `Show all (${more} more)`;
    });
}

function loadSelectedProjectIds() {
    try {
        const raw = localStorage.getItem(PROJECT_IDS_KEY);
        if (raw) {
            const parsed = JSON.parse(raw);
            if (Array.isArray(parsed)) {
                return [...new Set(parsed.map(Number).filter((id) => id > 0))];
            }
        }
    } catch {
        // fall through to legacy key
    }
    const legacy = Number(localStorage.getItem('selectedProjectId') || 0);
    if (legacy > 0) {
        const ids = [legacy];
        localStorage.setItem(PROJECT_IDS_KEY, JSON.stringify(ids));
        localStorage.removeItem('selectedProjectId');
        return ids;
    }
    return [];
}

let selectedProjectIds = loadSelectedProjectIds();
let projectsCache = [];

const projectIdsInput = document.getElementById('project-ids-input');
const useAgentInput = document.getElementById('use-agent-input');
const agentToggle = document.getElementById('agent-toggle');
const contextBar = document.getElementById('context-bar');
const contextProjectName = document.getElementById('context-project-name');
const languageSelect = document.getElementById('chat-language');

function persistSelectedProjectIds(ids) {
    selectedProjectIds = [...new Set(ids.map(Number).filter((id) => id > 0))];
    localStorage.setItem(PROJECT_IDS_KEY, JSON.stringify(selectedProjectIds));
}

function normalizeLanguage(code) {
    return code === 'en' ? 'en' : 'ru';
}

function isAgentEnabled() {
    return localStorage.getItem(AGENT_KEY) !== '0';
}

function setAgentEnabled(enabled) {
    localStorage.setItem(AGENT_KEY, enabled ? '1' : '0');
    if (agentToggle) agentToggle.checked = enabled;
    if (useAgentInput) useAgentInput.value = enabled ? '1' : '0';
}

function initAgentToggle() {
    setAgentEnabled(isAgentEnabled());
    if (!agentToggle) return;
    agentToggle.addEventListener('change', () => {
        setAgentEnabled(agentToggle.checked);
    });
}

function initLanguageSelect() {
    if (!languageSelect) return;

    const stored = localStorage.getItem(LANG_KEY);
    if (stored === 'en' || stored === 'ru') {
        languageSelect.value = stored;
    } else {
        localStorage.setItem(LANG_KEY, normalizeLanguage(languageSelect.value));
    }
    document.documentElement.lang = normalizeLanguage(languageSelect.value);

    languageSelect.addEventListener('change', () => {
        const lang = normalizeLanguage(languageSelect.value);
        languageSelect.value = lang;
        localStorage.setItem(LANG_KEY, lang);
        document.documentElement.lang = lang;
    });
}

function updateChatContext() {
    const selected = projectsCache.filter((p) => selectedProjectIds.includes(p.id));
    projectIdsInput.value = selected.map((p) => p.id).join(',');
    if (useAgentInput) useAgentInput.value = isAgentEnabled() ? '1' : '0';
    contextBar.dataset.active = selected.length ? '1' : '0';
    if (!selected.length) {
        contextProjectName.textContent = 'no projects selected';
        return;
    }
    contextProjectName.textContent = selected
        .map((p) => {
            const ready = p.evaluation?.status === 'completed';
            return ready ? p.name : `${p.name} (indexing…)`;
        })
        .join(', ');
}

initLanguageSelect();
initAgentToggle();

initSidebar();
initComposer(updateChatContext, isAgentEnabled);

const { openEvalLog } = initEvalLog();
const { loadProjects, startPolling } = initProjects({
    openEvalLog,
    getSelectedProjectIds: () => selectedProjectIds,
    setSelectedProjectIds: persistSelectedProjectIds,
    updateChatContext,
    setProjectsCache: (projects) => { projectsCache = projects; },
});

initUpload({
    addSelectedProjectId: (id) => {
        if (!selectedProjectIds.includes(id)) {
            persistSelectedProjectIds([...selectedProjectIds, id]);
        }
    },
    loadProjects,
    startPolling,
});

loadProjects();
