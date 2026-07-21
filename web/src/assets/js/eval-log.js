import { parseJsonResponse } from './utils.js';

function formatDuration(totalSeconds) {
    const seconds = Math.max(0, Math.floor(totalSeconds));
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    if (h > 0) {
        return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
    }
    return `${m}:${String(s).padStart(2, '0')}`;
}

function formatClockTime(iso) {
    if (!iso) return '';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

function parseIso(iso) {
    if (!iso) return null;
    const date = new Date(iso);
    return Number.isNaN(date.getTime()) ? null : date;
}

function findCurrentHeading(data, events) {
    if (data.current_phase !== 'section') return null;
    for (let i = events.length - 1; i >= 0; i -= 1) {
        const ev = events[i];
        if (
            ev.phase === 'section'
            && ev.file === data.current_file
            && ev.section_index === data.current_section
        ) {
            return ev.heading || null;
        }
    }
    return null;
}

function isCurrentEvent(ev, data) {
    if (!data?.current_file || !ev.file || ev.file !== data.current_file) {
        return false;
    }
    if (data.current_phase === 'section') {
        return ev.phase === 'section' && ev.section_index === data.current_section;
    }
    if (data.current_phase === 'file') {
        return ev.phase === 'file';
    }
    return false;
}

function getIndexedDurationSec(events, index, data) {
    const ev = events[index];
    if (!ev || (ev.phase !== 'section' && ev.phase !== 'file') || !ev.ts) {
        return null;
    }
    if (data && isCurrentEvent(ev, data)) {
        return null;
    }

    const start = parseIso(ev.ts);
    if (!start) {
        return null;
    }

    for (let j = index + 1; j < events.length; j += 1) {
        if (events[j].ts) {
            const end = parseIso(events[j].ts);
            if (!end) {
                return null;
            }
            return (end.getTime() - start.getTime()) / 1000;
        }
    }

    return null;
}

function makeBadge(text, className = '') {
    const el = document.createElement('span');
    el.className = 'log-badge' + (className ? ` ${className}` : '');
    el.textContent = text;
    return el;
}

const RECENT_INDEXED_LIMIT = 2;

function getRecentCompletedIndexed(events, data, limit = RECENT_INDEXED_LIMIT) {
    const completed = [];

    for (let i = 0; i < events.length; i += 1) {
        const durationSec = getIndexedDurationSec(events, i, data);
        if (durationSec == null) {
            continue;
        }

        const ev = events[i];
        completed.push({
            kind: ev.phase,
            heading: ev.heading || null,
            message: ev.message || '',
            file: ev.file || '',
            section_index: ev.section_index,
            section_total: ev.section_total,
            file_index: ev.file_index,
            file_total: ev.file_total,
            durationSec,
        });
    }

    return completed.slice(-limit);
}

function sectionSummary(entry) {
    const sectionNo = entry.section_index && entry.section_total
        ? `Секция ${entry.section_index}/${entry.section_total}`
        : 'Секция';
    if (entry.heading) {
        return `${sectionNo} «${entry.heading}»`;
    }
    if (entry.message) {
        return `${sectionNo} — ${entry.message}`;
    }
    return sectionNo;
}

function fileSummary(entry) {
    const fileNo = entry.file_index && entry.file_total
        ? `Файл ${entry.file_index}/${entry.file_total}`
        : 'Файл';
    if (entry.message) {
        return `${fileNo} — ${entry.message}`;
    }
    return fileNo;
}

function indexedSummary(entry) {
    return entry.kind === 'file' ? fileSummary(entry) : sectionSummary(entry);
}

function findCurrentFileMeta(events, data) {
    for (let i = events.length - 1; i >= 0; i -= 1) {
        const ev = events[i];
        if (ev.file && ev.file === data.current_file && ev.file_index) {
            return {
                file_index: ev.file_index,
                file_total: ev.file_total || data.total_files || null,
            };
        }
    }
    const processed = Number(data.processed_files || 0);
    const total = Number(data.total_files || 0);
    if (total > 0) {
        return {
            file_index: Math.min(processed + 1, total),
            file_total: total,
        };
    }
    return { file_index: null, file_total: null };
}

/**
 * Build a compact queue of not-yet-indexed work for the right column.
 * @returns {list<{kind:string,status:string,title:string,file?:string,section_index?:number,section_total?:number,file_index?:number,file_total?:number,heading?:string|null}>}
 */
function buildUnindexedQueue(data, events) {
    const isActive = data.status === 'processing' || data.status === 'pending';
    if (!isActive) {
        return [];
    }

    const queue = [];
    const fileMeta = findCurrentFileMeta(events, data);
    const heading = findCurrentHeading(data, events);
    const phase = data.current_phase;
    const currentFile = data.current_file || '';

    if (phase === 'file' || phase === 'section') {
        queue.push({
            kind: phase,
            status: 'current',
            title: phase === 'file'
                ? 'Индексация файла'
                : (heading ? `Секция «${heading}»` : 'Индексация секции'),
            file: currentFile,
            heading,
            section_index: data.current_section || null,
            section_total: data.total_sections || null,
            file_index: fileMeta.file_index,
            file_total: fileMeta.file_total,
        });
    }

    if (phase === 'section' && data.current_section && data.total_sections) {
        for (let i = data.current_section + 1; i <= data.total_sections; i += 1) {
            queue.push({
                kind: 'section',
                status: 'pending',
                title: `Секция ${i}/${data.total_sections}`,
                file: currentFile,
                section_index: i,
                section_total: data.total_sections,
                file_index: fileMeta.file_index,
                file_total: fileMeta.file_total,
            });
        }
    }

    const fileTotal = fileMeta.file_total || Number(data.total_files || 0);
    const fileIndex = fileMeta.file_index || Number(data.processed_files || 0) + 1;
    if (fileTotal > 0 && fileIndex > 0) {
        const remainingFiles = fileTotal - fileIndex;
        if (remainingFiles > 0) {
            const show = Math.min(remainingFiles, 8);
            for (let i = 1; i <= show; i += 1) {
                const nextIndex = fileIndex + i;
                queue.push({
                    kind: 'file',
                    status: 'pending',
                    title: `Файл ${nextIndex}/${fileTotal}`,
                    file_index: nextIndex,
                    file_total: fileTotal,
                });
            }
            if (remainingFiles > show) {
                queue.push({
                    kind: 'more',
                    status: 'pending',
                    title: `…и ещё ${remainingFiles - show} файл(ов)`,
                });
            }
        }
    }

    return queue;
}

function renderQueueCard(item) {
    const card = document.createElement('div');
    card.className = 'log-event'
        + (item.status === 'current' ? ' log-event-current' : '')
        + (item.status === 'pending' ? ' log-event-pending' : '');

    const head = document.createElement('div');
    head.className = 'ev-head';

    const phaseEl = document.createElement('span');
    phaseEl.className = 'ev-phase';
    phaseEl.textContent = item.kind === 'more' ? 'queue' : (item.kind || 'item');

    const msg = document.createElement('span');
    msg.className = 'ev-msg';
    msg.textContent = item.title || '';

    head.append(phaseEl, msg);

    if (item.status === 'current') {
        const nowEl = document.createElement('span');
        nowEl.className = 'ev-now';
        nowEl.textContent = 'сейчас';
        head.append(nowEl);
    } else if (item.status === 'pending') {
        const pendingEl = document.createElement('span');
        pendingEl.className = 'ev-pending';
        pendingEl.textContent = 'ожидает';
        head.append(pendingEl);
    }

    card.append(head);

    const metaRow = document.createElement('div');
    metaRow.className = 'ev-meta-row';
    if (item.section_index && item.section_total) {
        metaRow.append(makeBadge(`Секция ${item.section_index}/${item.section_total}`, 'log-badge-section'));
    }
    if (item.file_index && item.file_total) {
        metaRow.append(makeBadge(`Файл ${item.file_index}/${item.file_total}`, 'log-badge-file'));
    }
    if (item.status === 'pending' && item.kind !== 'more') {
        metaRow.append(makeBadge('не индексировано', 'log-badge-pending'));
    }
    if (metaRow.childElementCount) {
        card.append(metaRow);
    }

    if (item.file) {
        const fileEl = document.createElement('div');
        fileEl.className = 'ev-file';
        fileEl.textContent = item.file;
        card.append(fileEl);
    }

    return card;
}

export function initEvalLog() {
    let logPollTimer = null;
    let logTickTimer = null;
    let logProjectId = 0;
    let lastLogData = null;

    const logModal = document.getElementById('log-modal');
    const logEventsEl = document.getElementById('log-events');
    const logCurrentEl = document.getElementById('log-current');
    const logMetaEl = document.getElementById('log-meta');
    const logStatusBar = document.getElementById('log-status-bar');
    const logLiveIndicator = document.getElementById('log-live-indicator');
    const logElapsedEl = document.getElementById('log-elapsed');
    const logProgressFill = document.getElementById('log-progress-fill');
    const logProgressLabel = document.getElementById('log-progress-label');
    const logProgressBar = logStatusBar?.querySelector('.log-progress-bar');
    const logStepElapsed = document.getElementById('log-step-elapsed');
    const logCurrentBadges = document.getElementById('log-current-badges');
    const logRecentSections = document.getElementById('log-recent-sections');
    const logRecentList = document.getElementById('log-recent-list');

    function stopTimers() {
        if (logPollTimer) {
            clearInterval(logPollTimer);
            logPollTimer = null;
        }
        if (logTickTimer) {
            clearInterval(logTickTimer);
            logTickTimer = null;
        }
    }

    function closeEvalLog() {
        logModal?.classList.remove('open');
        logProjectId = 0;
        lastLogData = null;
        stopTimers();
    }

    function updateElapsedDisplays() {
        if (!lastLogData) return;

        const startedAt = parseIso(lastLogData.created_at);
        const stepStartedAt = parseIso(lastLogData.updated_at);
        const now = Date.now();

        if (logElapsedEl) {
            if (startedAt) {
                const totalSec = (now - startedAt.getTime()) / 1000;
                logElapsedEl.textContent = `Идёт ${formatDuration(totalSec)} · старт ${formatClockTime(lastLogData.created_at)}`;
            } else {
                logElapsedEl.textContent = '';
            }
        }

        const isActive = lastLogData.status === 'processing' || lastLogData.status === 'pending';
        if (logStepElapsed) {
            if (isActive && stepStartedAt && (lastLogData.current_phase === 'file' || lastLogData.current_phase === 'section')) {
                const stepSec = (now - stepStartedAt.getTime()) / 1000;
                const target = lastLogData.current_phase === 'section' ? 'секция' : 'файл';
                logStepElapsed.textContent = `Текущий ${target}: ${formatDuration(stepSec)}`;
            } else {
                logStepElapsed.textContent = '';
            }
        }
    }

    function renderCurrentTarget(data, events) {
        if (!logCurrentEl) return;

        const phase = data.current_phase;
        const isActive = data.status === 'processing' && (phase === 'file' || phase === 'section');

        if (isActive) {
            logCurrentEl.hidden = false;
            logCurrentEl.classList.add('is-active');
        } else {
            logCurrentEl.hidden = true;
            logCurrentEl.classList.remove('is-active');
            return;
        }

        const label = document.getElementById('log-current-label');
        const path = document.getElementById('log-current-path');
        const text = document.getElementById('log-current-text');
        const heading = findCurrentHeading(data, events);

        logCurrentBadges?.replaceChildren();

        if (phase === 'file') {
            if (label) label.textContent = 'Сейчас индексируется файл';
            const fileEv = events.findLast((ev) => ev.phase === 'file' && ev.file === data.current_file);
            if (fileEv?.file_index && fileEv.file_total) {
                logCurrentBadges?.append(makeBadge(`Файл ${fileEv.file_index}/${fileEv.file_total}`, 'log-badge-file'));
            } else if (data.processed_files != null && data.total_files) {
                logCurrentBadges?.append(makeBadge(`Файл ~${data.processed_files + 1}/${data.total_files}`, 'log-badge-file'));
            }
            if (path) path.textContent = data.current_file || '—';
        } else {
            if (label) {
                label.textContent = heading ? `Сейчас индексируется «${heading}»` : 'Сейчас индексируется секция';
            }
            if (data.current_section && data.total_sections) {
                logCurrentBadges?.append(
                    makeBadge(`Секция ${data.current_section}/${data.total_sections}`, 'log-badge-section'),
                );
            }
            const fileEv = events.findLast((ev) => ev.phase === 'file' && ev.file === data.current_file);
            if (fileEv?.file_index && fileEv.file_total) {
                logCurrentBadges?.append(makeBadge(`Файл ${fileEv.file_index}/${fileEv.file_total}`, 'log-badge-file'));
            }
            if (path) path.textContent = data.current_file || '—';
        }

        if (text) text.textContent = data.current_detail || '(нет текста)';
        updateElapsedDisplays();
    }

    function renderRecentSections(data, events) {
        if (!logRecentSections || !logRecentList) return;

        const recent = getRecentCompletedIndexed(events, data);
        const titleEl = logRecentSections.querySelector('.log-recent-title');

        if (!recent.length) {
            logRecentSections.hidden = true;
            logRecentList.replaceChildren();
            return;
        }

        if (titleEl) {
            const hasFiles = recent.some((entry) => entry.kind === 'file');
            const hasSections = recent.some((entry) => entry.kind === 'section');
            if (hasFiles && hasSections) {
                titleEl.textContent = 'Последние проиндексированные';
            } else if (hasFiles) {
                titleEl.textContent = 'Последние проиндексированные файлы';
            } else {
                titleEl.textContent = 'Последние проиндексированные секции';
            }
        }

        logRecentSections.hidden = false;
        logRecentList.replaceChildren();

        recent.forEach((entry) => {
            const item = document.createElement('li');
            item.className = 'log-recent-item';

            const main = document.createElement('div');
            main.className = 'log-recent-main';
            main.textContent = indexedSummary(entry);

            const meta = document.createElement('div');
            meta.className = 'log-recent-meta';

            const fileEl = document.createElement('span');
            fileEl.className = 'log-recent-file';
            fileEl.textContent = entry.file || '—';

            const durationEl = document.createElement('span');
            durationEl.className = 'log-recent-duration';
            durationEl.textContent = `Проиндексировано за ${formatDuration(entry.durationSec)}`;

            meta.append(fileEl, durationEl);
            item.append(main, meta);
            logRecentList.append(item);
        });
    }

    function renderEvalLog(data) {
        lastLogData = data;
        const status = data.status || '—';
        const pct = data.percent ?? 0;
        const isActive = status === 'processing' || status === 'pending';

        if (logMetaEl) {
            logMetaEl.textContent = `${data.project_name || 'Project'} · ${status} · ${data.processed_files || 0}/${data.total_files || 0} markdown files`;
        }

        if (logStatusBar) logStatusBar.hidden = false;
        if (logLiveIndicator) logLiveIndicator.hidden = !isActive;
        if (logProgressFill) logProgressFill.style.width = `${pct}%`;
        if (logProgressBar) logProgressBar.setAttribute('aria-valuenow', String(pct));
        if (logProgressLabel) {
            logProgressLabel.textContent = `${pct}% · проиндексировано ${data.processed_files || 0} из ${data.total_files || 0} файлов`;
        }

        const events = data.events || [];
        renderCurrentTarget(data, events);
        renderQueue(data, events);
        renderRecentSections(data, events);
        updateElapsedDisplays();
    }

    function renderQueue(data, events) {
        if (!logEventsEl) return;

        const queue = buildUnindexedQueue(data, events);
        logEventsEl.replaceChildren();

        if (!queue.length) {
            const empty = document.createElement('p');
            empty.className = 'log-empty';
            empty.textContent = data.status === 'completed'
                ? 'Очередь пуста — индексация завершена'
                : 'Очередь пока пуста…';
            logEventsEl.append(empty);
            return;
        }

        queue.forEach((item) => {
            logEventsEl.append(renderQueueCard(item));
        });
    }

    async function refreshEvalLog() {
        if (!logProjectId) return;
        try {
            const res = await fetch('api/eval_log.php?id=' + logProjectId);
            const data = await parseJsonResponse(res);
            if (!res.ok) throw new Error(data.error || 'Failed to load log');
            renderEvalLog(data);
            if (data.status !== 'processing' && data.status !== 'pending') {
                if (logPollTimer) {
                    clearInterval(logPollTimer);
                    logPollTimer = null;
                }
                if (logTickTimer) {
                    clearInterval(logTickTimer);
                    logTickTimer = null;
                }
            }
        } catch (err) {
            if (logEventsEl) {
                logEventsEl.innerHTML = `<p class="log-empty">${err.message}</p>`;
            }
        }
    }

    function openEvalLog(projectId, projectName) {
        logProjectId = projectId;
        lastLogData = null;
        const logTitle = document.getElementById('log-title');
        if (logTitle) logTitle.textContent = 'Evaluation log';
        if (logMetaEl) logMetaEl.textContent = (projectName || 'Project') + ' · загрузка…';
        if (logCurrentEl) logCurrentEl.hidden = true;
        if (logStatusBar) logStatusBar.hidden = true;
        if (logRecentSections) logRecentSections.hidden = true;
        if (logEventsEl) logEventsEl.innerHTML = '<p class="log-empty">Loading…</p>';
        logModal?.classList.add('open');
        stopTimers();
        refreshEvalLog();
        logPollTimer = setInterval(refreshEvalLog, 1500);
        logTickTimer = setInterval(updateElapsedDisplays, 1000);
    }

    document.getElementById('log-close')?.addEventListener('click', closeEvalLog);
    logModal?.addEventListener('click', (e) => {
        if (e.target === logModal) closeEvalLog();
    });

    return { openEvalLog };
}
