import { parseJsonResponse } from './utils.js';

export function initEvalLog() {
    let logPollTimer = null;
    let logProjectId = 0;
    const logModal = document.getElementById('log-modal');
    const logEventsEl = document.getElementById('log-events');
    const logCurrentEl = document.getElementById('log-current');
    const logMetaEl = document.getElementById('log-meta');

    function closeEvalLog() {
        logModal.classList.remove('open');
        logProjectId = 0;
        if (logPollTimer) {
            clearInterval(logPollTimer);
            logPollTimer = null;
        }
    }

    function renderEvalLog(data) {
        const status = data.status || '—';
        const pct = data.percent ?? 0;
        logMetaEl.textContent = `${data.project_name || 'Project'} · ${status} · ${data.processed_files || 0}/${data.total_files || 0} files (${pct}%)`;

        const phase = data.current_phase;
        if (phase === 'file' || phase === 'section') {
            logCurrentEl.hidden = false;
            const label = document.getElementById('log-current-label');
            const path = document.getElementById('log-current-path');
            const text = document.getElementById('log-current-text');
            if (phase === 'file') {
                label.textContent = 'Now evaluating file';
                path.textContent = data.current_file || '—';
            } else {
                const sec = data.current_section && data.total_sections
                    ? ` · section ${data.current_section}/${data.total_sections}`
                    : '';
                label.textContent = 'Now evaluating paragraph/section';
                path.textContent = (data.current_file || '—') + sec;
            }
            text.textContent = data.current_detail || '(no text)';
        } else {
            logCurrentEl.hidden = true;
        }

        const events = data.events || [];
        if (!events.length) {
            logEventsEl.innerHTML = '<p class="log-empty">No log entries yet…</p>';
            return;
        }

        const stickToBottom = logEventsEl.scrollTop + logEventsEl.clientHeight >= logEventsEl.scrollHeight - 40;
        logEventsEl.innerHTML = '';
        for (const ev of events) {
            const card = document.createElement('div');
            card.className = 'log-event';

            const head = document.createElement('div');
            head.className = 'ev-head';

            const phaseEl = document.createElement('span');
            phaseEl.className = 'ev-phase ' + (ev.phase || '');
            phaseEl.textContent = ev.phase || 'event';

            const msg = document.createElement('span');
            msg.className = 'ev-msg';
            msg.textContent = ev.message || '';

            head.append(phaseEl, msg);
            card.append(head);

            if (ev.file) {
                const fileEl = document.createElement('div');
                fileEl.className = 'ev-file';
                let fileLine = ev.file;
                if (ev.phase === 'section' && ev.section_index) {
                    fileLine += ` · section ${ev.section_index}/${ev.section_total || '?'}`;
                } else if (ev.phase === 'file' && ev.file_index) {
                    fileLine += ` · file ${ev.file_index}/${ev.file_total || '?'}`;
                }
                fileEl.textContent = fileLine;
                card.append(fileEl);
            }

            if (ev.text) {
                const pre = document.createElement('pre');
                pre.textContent = ev.text;
                card.append(pre);
            }

            logEventsEl.append(card);
        }
        if (stickToBottom) {
            logEventsEl.scrollTop = logEventsEl.scrollHeight;
        }
    }

    async function refreshEvalLog() {
        if (!logProjectId) return;
        try {
            const res = await fetch('api/eval_log.php?id=' + logProjectId);
            const data = await parseJsonResponse(res);
            if (!res.ok) throw new Error(data.error || 'Failed to load log');
            renderEvalLog(data);
            if (data.status !== 'processing' && data.status !== 'pending' && logPollTimer) {
                clearInterval(logPollTimer);
                logPollTimer = null;
            }
        } catch (err) {
            logEventsEl.innerHTML = `<p class="log-empty">${err.message}</p>`;
        }
    }

    function openEvalLog(projectId, projectName) {
        logProjectId = projectId;
        document.getElementById('log-title').textContent = 'Evaluation log';
        logMetaEl.textContent = (projectName || 'Project') + ' · loading…';
        logCurrentEl.hidden = true;
        logEventsEl.innerHTML = '<p class="log-empty">Loading…</p>';
        logModal.classList.add('open');
        refreshEvalLog();
        if (logPollTimer) clearInterval(logPollTimer);
        logPollTimer = setInterval(refreshEvalLog, 1500);
    }

    document.getElementById('log-close').addEventListener('click', closeEvalLog);
    logModal.addEventListener('click', (e) => {
        if (e.target === logModal) closeEvalLog();
    });

    return { openEvalLog };
}
