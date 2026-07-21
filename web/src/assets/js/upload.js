import { isMarkdownPath, parseJsonResponse } from '/assets/js/utils.js';

function postFormData(url, formData, onProgress) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) onProgress(e.loaded, e.total);
        });
        xhr.addEventListener('load', () => {
            resolve({
                ok: xhr.status >= 200 && xhr.status < 300,
                status: xhr.status,
                text: () => Promise.resolve(xhr.responseText),
            });
        });
        xhr.addEventListener('error', () => reject(new Error('Network error during upload')));
        xhr.addEventListener('abort', () => reject(new Error('Upload aborted')));
        xhr.send(formData);
    });
}

export function initUpload({ addSelectedProjectId, loadProjects, startPolling }) {
    const modal = document.getElementById('upload-modal');
    const dropzone = document.getElementById('dropzone');
    const folderInput = document.getElementById('folder-input');
    const dzFiles = document.getElementById('dz-files');
    const uploadError = document.getElementById('upload-error');
    const progressWrap = document.getElementById('upload-progress');
    const progressFill = document.getElementById('upload-progress-fill');
    const progressLabel = document.getElementById('upload-progress-label');
    const progressBar = progressWrap?.querySelector('.log-progress-bar');
    const baseUrlField = document.getElementById('proj-base-field');
    const baseUrlInput = document.getElementById('proj-base');
    const projectTypeInputs = Array.from(document.querySelectorAll('input[name="project_type"]'));
    let selectedFiles = [];
    let pendingFiles = [];

    function setProgress(pct, label) {
        const clamped = Math.max(0, Math.min(100, pct));
        progressFill.style.width = `${clamped}%`;
        if (progressBar) progressBar.setAttribute('aria-valuenow', String(Math.round(clamped)));
        progressLabel.textContent = label;
    }

    function showProgress() {
        progressWrap.hidden = false;
        setProgress(0, 'Uploading… 0%');
    }

    function hideProgress() {
        progressWrap.hidden = true;
        setProgress(0, 'Uploading…');
    }

    function selectedProjectType() {
        return projectTypeInputs.find((input) => input.checked)?.value || 'bitrix_api_docs';
    }

    function refreshPendingFiles() {
        const type = selectedProjectType();
        pendingFiles = selectedFiles.filter((item) => {
            const path = item.path || item.file?.name || '';
            if (!isMarkdownPath(path)) return false;
            if (type !== 'bitrix_api_docs') return true;
            return !path.replaceAll('\\', '/').split('/').some((segment) => segment.startsWith('_'));
        });
        dzFiles.textContent = pendingFiles.length
            ? `${pendingFiles.length} markdown file(s) selected`
            : 'No .md files found in that folder';
    }

    function setPendingFiles(files) {
        selectedFiles = files;
        refreshPendingFiles();
    }

    function updateProjectTypeFields() {
        const isGramax = selectedProjectType() === 'gramax';
        baseUrlField.hidden = !isGramax;
        baseUrlInput.required = isGramax;
        refreshPendingFiles();
    }

    function openModal() {
        uploadError.classList.remove('show');
        uploadError.textContent = '';
        hideProgress();
        document.getElementById('upload-form').reset();
        selectedFiles = [];
        pendingFiles = [];
        dzFiles.textContent = '';
        updateProjectTypeFields();
        modal.classList.add('open');
        document.getElementById('proj-name').focus();
    }

    function closeModal() {
        hideProgress();
        modal.classList.remove('open');
    }

    document.getElementById('btn-new-project').addEventListener('click', openModal);
    document.getElementById('upload-cancel').addEventListener('click', closeModal);
    projectTypeInputs.forEach((input) => input.addEventListener('change', updateProjectTypeFields));
    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });

    dropzone.addEventListener('click', () => folderInput.click());
    dropzone.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            folderInput.click();
        }
    });

    folderInput.addEventListener('change', () => {
        setPendingFiles(Array.from(folderInput.files || []).map((f) => ({
            file: f,
            path: f.webkitRelativePath || f.name,
        })));
    });

    ;['dragenter', 'dragover'].forEach((ev) => {
        dropzone.addEventListener(ev, (e) => {
            e.preventDefault();
            dropzone.classList.add('dragover');
        });
    });
    ;['dragleave', 'drop'].forEach((ev) => {
        dropzone.addEventListener(ev, (e) => {
            e.preventDefault();
            dropzone.classList.remove('dragover');
        });
    });

    dropzone.addEventListener('drop', async (e) => {
        const items = e.dataTransfer?.items;
        if (!items?.length) return;

        const files = [];

        const readAllEntries = (reader) => new Promise((resolve, reject) => {
            const acc = [];
            const pump = () => {
                reader.readEntries((batch) => {
                    if (!batch.length) {
                        resolve(acc);
                        return;
                    }
                    acc.push(...batch);
                    pump();
                }, reject);
            };
            pump();
        });

        const walkEntry = async (entry, prefix) => {
            if (entry.isFile) {
                const file = await new Promise((resolve, reject) => entry.file(resolve, reject));
                files.push({ file, path: prefix + file.name });
                return;
            }
            if (entry.isDirectory) {
                const reader = entry.createReader();
                const children = await readAllEntries(reader);
                for (const child of children) {
                    await walkEntry(child, prefix + entry.name + '/');
                }
            }
        };

        const jobs = [];
        for (const item of items) {
            const entry = item.webkitGetAsEntry?.();
            if (entry) jobs.push(walkEntry(entry, ''));
        }
        await Promise.all(jobs);

        if (files.length) {
            setPendingFiles(files);
        }
    });

    document.getElementById('upload-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        uploadError.classList.remove('show');

        if (!pendingFiles.length) {
            uploadError.textContent = 'Select or drop a project folder with .md files first.';
            uploadError.classList.add('show');
            return;
        }

        const submitBtn = document.getElementById('upload-submit');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Uploading…';
        showProgress();

        const formData = new FormData();
        formData.append('name', document.getElementById('proj-name').value.trim());
        formData.append('description', document.getElementById('proj-desc').value.trim());
        formData.append('project_type', selectedProjectType());
        formData.append('base_url', document.getElementById('proj-base').value.trim());

        pendingFiles.forEach((item, i) => {
            formData.append('files[]', item.file, item.file.name);
            formData.append(`paths[${i}]`, item.path);
        });

        try {
            const res = await postFormData('api/upload.php', formData, (loaded, total) => {
                const pct = total ? (loaded / total) * 100 : 0;
                setProgress(pct, `Uploading… ${Math.round(pct)}%`);
            });
            setProgress(100, 'Processing…');
            const data = await parseJsonResponse(res);
            if (!res.ok) throw new Error(data.error || 'Upload failed');
            addSelectedProjectId(data.project_id);
            closeModal();
            await loadProjects();
            startPolling();
        } catch (err) {
            hideProgress();
            uploadError.textContent = err.message;
            uploadError.classList.add('show');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Upload & evaluate';
        }
    });
}
