import { postProjectAction, statusLabel } from '/assets/js/utils.js';

export function initProjects({ openEvalLog, getSelectedProjectIds, setSelectedProjectIds, updateChatContext, setProjectsCache }) {
    const projectList = document.getElementById('project-list');
    let pollTimer = null;

    function renderProjects(projects) {
        setProjectsCache(projects);
        const knownIds = new Set(projects.map((p) => p.id));
        const pruned = getSelectedProjectIds().filter((id) => knownIds.has(id));
        if (pruned.length !== getSelectedProjectIds().length) {
            setSelectedProjectIds(pruned);
        }
        updateChatContext();

        const selectedIds = new Set(getSelectedProjectIds());

        if (!projects.length) {
            projectList.innerHTML = '<p class="sidebar-empty">No projects yet. Click <strong>+ New</strong> to upload a folder.</p>';
            return;
        }

        projectList.innerHTML = '';
        for (const p of projects) {
            const ev = p.evaluation || {};
            const active = selectedIds.has(p.id);
            const item = document.createElement('div');
            item.className = 'project-item' + (active ? ' active' : '');
            item.dataset.id = String(p.id);
            item.setAttribute('role', 'button');
            item.setAttribute('aria-pressed', active ? 'true' : 'false');
            item.tabIndex = 0;
            item.title = active ? 'Click to deselect' : 'Click to include in search';

            const name = document.createElement('div');
            name.className = 'pname';
            name.textContent = p.name;

            const badge = document.createElement('div');
            badge.className = 'eval-badge ' + (ev.status || '');
            badge.textContent = statusLabel(ev);

            const desc = document.createElement('div');
            desc.className = 'pdesc';
            desc.textContent = p.description || '';

            item.append(name, badge, desc);

            if (ev.status === 'processing' || ev.status === 'pending') {
                const bar = document.createElement('div');
                bar.className = 'eval-bar';
                bar.title = `Полнотекстовый поиск: ${ev.searchable_files || 0}/${ev.total_files || 0}; финальный индекс: ${ev.processed_files || 0}/${ev.total_files || 0}`;
                const searchableFill = document.createElement('span');
                searchableFill.className = 'eval-bar-searchable';
                searchableFill.style.width = (ev.searchable_percent || 0) + '%';
                const indexedFill = document.createElement('span');
                indexedFill.className = 'eval-bar-indexed';
                indexedFill.style.width = (ev.percent || 0) + '%';
                bar.append(searchableFill, indexedFill);
                item.append(bar);

                if (ev.current_file) {
                    const detail = document.createElement('div');
                    detail.className = 'eval-detail';
                    detail.textContent = ev.current_file;
                    item.append(detail);
                } else if (ev.status === 'pending') {
                    const detail = document.createElement('div');
                    detail.className = 'eval-detail';
                    detail.textContent = 'Waiting to start…';
                    item.append(detail);
                }
            } else if (ev.status === 'failed' && ev.error) {
                const detail = document.createElement('div');
                detail.className = 'eval-detail';
                detail.style.color = 'var(--danger)';
                detail.textContent = ev.error;
                item.append(detail);
            } else if (ev.status === 'completed') {
                const detail = document.createElement('div');
                detail.className = 'eval-detail';
                detail.textContent = `${ev.processed_files || 0} files indexed`;
                item.append(detail);
            } else if (ev.status === 'cancelled') {
                const detail = document.createElement('div');
                detail.className = 'eval-detail';
                detail.textContent = 'Evaluation cancelled';
                item.append(detail);
            }

            const actions = document.createElement('div');
            actions.className = 'project-actions';

            if (ev.status === 'processing' || ev.status === 'pending') {
                const cancelBtn = document.createElement('button');
                cancelBtn.type = 'button';
                cancelBtn.textContent = 'Cancel evaluation';
                cancelBtn.addEventListener('click', async (event) => {
                    event.stopPropagation();
                    cancelBtn.disabled = true;
                    try {
                        await postProjectAction('api/cancel.php', p.id);
                        await loadProjects();
                    } catch (err) {
                        alert(err.message);
                        cancelBtn.disabled = false;
                    }
                });
                actions.append(cancelBtn);

                const logBtn = document.createElement('button');
                logBtn.type = 'button';
                logBtn.textContent = 'View log';
                logBtn.addEventListener('click', (event) => {
                    event.stopPropagation();
                    openEvalLog(p.id, p.name);
                });
                actions.append(logBtn);
            } else if (ev.status === 'completed' || ev.status === 'failed' || ev.status === 'cancelled') {
                if (ev.status === 'cancelled' || ev.status === 'failed') {
                    const continueBtn = document.createElement('button');
                    continueBtn.type = 'button';
                    continueBtn.textContent = 'Continue evaluation';
                    continueBtn.addEventListener('click', async (event) => {
                        event.stopPropagation();
                        continueBtn.disabled = true;
                        try {
                            await postProjectAction('api/continue.php', p.id);
                            await loadProjects();
                            startPolling();
                        } catch (err) {
                            alert(err.message);
                            continueBtn.disabled = false;
                        }
                    });
                    actions.append(continueBtn);
                }

                const logBtn = document.createElement('button');
                logBtn.type = 'button';
                logBtn.textContent = 'View log';
                logBtn.addEventListener('click', (event) => {
                    event.stopPropagation();
                    openEvalLog(p.id, p.name);
                });
                actions.append(logBtn);
            }

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'danger';
            removeBtn.textContent = 'Remove';
            removeBtn.addEventListener('click', async (event) => {
                event.stopPropagation();
                if (!confirm(`Remove project “${p.name}”? This deletes its files and indexed data.`)) {
                    return;
                }
                removeBtn.disabled = true;
                try {
                    await postProjectAction('api/delete.php', p.id);
                    setSelectedProjectIds(getSelectedProjectIds().filter((id) => id !== p.id));
                    await loadProjects();
                } catch (err) {
                    alert(err.message);
                    removeBtn.disabled = false;
                }
            });
            actions.append(removeBtn);
            item.append(actions);

            const toggleProject = () => {
                const current = getSelectedProjectIds();
                const next = current.includes(p.id)
                    ? current.filter((id) => id !== p.id)
                    : [...current, p.id];
                setSelectedProjectIds(next);
                const isOn = next.includes(p.id);
                item.classList.toggle('active', isOn);
                item.setAttribute('aria-pressed', isOn ? 'true' : 'false');
                item.title = isOn ? 'Click to deselect' : 'Click to include in search';
                updateChatContext();
            };
            item.addEventListener('click', toggleProject);
            item.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    toggleProject();
                }
            });

            projectList.append(item);
        }
    }

    async function loadProjects() {
        try {
            const res = await fetch('api/projects.php');
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || 'Failed to load projects');
            renderProjects(data.projects || []);

            const busy = (data.projects || []).some((p) =>
                p.evaluation && ['processing', 'pending', 'failed'].includes(p.evaluation.status)
            );
            if (busy && !pollTimer) {
                pollTimer = setInterval(loadProjects, 2000);
            } else if (!busy && pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
        } catch (err) {
            projectList.innerHTML = `<p class="sidebar-error">${err.message}</p>`;
        }
    }

    function startPolling() {
        if (!pollTimer) pollTimer = setInterval(loadProjects, 2000);
    }

    return { loadProjects, startPolling };
}
