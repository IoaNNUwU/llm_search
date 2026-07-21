import { isMarkdownPath, parseJsonResponse } from '/assets/js/utils.js';

export function initUpload({ addSelectedProjectId, loadProjects, startPolling }) {
    const modal = document.getElementById('upload-modal');
    const dropzone = document.getElementById('dropzone');
    const folderInput = document.getElementById('folder-input');
    const dzFiles = document.getElementById('dz-files');
    const uploadError = document.getElementById('upload-error');
    let pendingFiles = [];

    function setPendingFiles(files) {
        pendingFiles = files.filter((item) => isMarkdownPath(item.path || item.file?.name));
        dzFiles.textContent = pendingFiles.length
            ? `${pendingFiles.length} markdown file(s) selected`
            : 'No .md files found in that folder';
    }

    function openModal() {
        uploadError.classList.remove('show');
        uploadError.textContent = '';
        document.getElementById('upload-form').reset();
        pendingFiles = [];
        dzFiles.textContent = '';
        modal.classList.add('open');
        document.getElementById('proj-name').focus();
    }

    function closeModal() {
        modal.classList.remove('open');
    }

    document.getElementById('btn-new-project').addEventListener('click', openModal);
    document.getElementById('upload-cancel').addEventListener('click', closeModal);
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

        const formData = new FormData();
        formData.append('name', document.getElementById('proj-name').value.trim());
        formData.append('description', document.getElementById('proj-desc').value.trim());
        formData.append('base_url', document.getElementById('proj-base').value.trim());

        pendingFiles.forEach((item, i) => {
            formData.append('files[]', item.file, item.file.name);
            formData.append(`paths[${i}]`, item.path);
        });

        try {
            const res = await fetch('api/upload.php', { method: 'POST', body: formData });
            const data = await parseJsonResponse(res);
            if (!res.ok) throw new Error(data.error || 'Upload failed');
            addSelectedProjectId(data.project_id);
            closeModal();
            await loadProjects();
            startPolling();
        } catch (err) {
            uploadError.textContent = err.message;
            uploadError.classList.add('show');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Upload & evaluate';
        }
    });
}
