<div class="modal-backdrop" id="upload-modal" role="dialog" aria-modal="true" aria-labelledby="upload-title">
    <div class="modal">
        <h3 id="upload-title">Upload project</h3>
        <p class="hint">Name and description are stored in the database. Drop a folder (or pick one) — only <code>.md</code> files are uploaded and indexed.</p>
        <div class="form-error" id="upload-error"></div>
        <form id="upload-form">
            <fieldset class="project-type-field">
                <legend>Project type</legend>
                <div class="project-type-options">
                    <label class="project-type-option">
                        <input type="radio" name="project_type" value="bitrix_api_docs" checked>
                        <span class="project-type-card">
                            <strong>Bitrix API docs</strong>
                            <small>apidocs.bitrix24.ru</small>
                        </span>
                    </label>
                    <label class="project-type-option">
                        <input type="radio" name="project_type" value="gramax">
                        <span class="project-type-card">
                            <strong>Gramax</strong>
                            <small>Custom documentation URL</small>
                        </span>
                    </label>
                </div>
            </fieldset>
            <div class="field">
                <label for="proj-name">Name</label>
                <input type="text" id="proj-name" name="name" required maxlength="200" autocomplete="off">
            </div>
            <div class="field">
                <label for="proj-desc">Description</label>
                <textarea id="proj-desc" name="description" rows="3" required maxlength="2000"></textarea>
            </div>
            <div class="field" id="proj-base-field" hidden>
                <label for="proj-base">Gramax project URL</label>
                <input type="url" id="proj-base" name="base_url" placeholder="https://docs.example.com">
            </div>
            <div class="dropzone" id="dropzone" tabindex="0">
                Drag &amp; drop a folder here<br>
                or click to select from your PC
                <div class="dz-files" id="dz-files"></div>
            </div>
            <input type="file" id="folder-input" webkitdirectory multiple hidden>
            <div class="upload-progress" id="upload-progress" hidden>
                <div class="log-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                    <span id="upload-progress-fill"></span>
                </div>
                <div class="log-progress-label" id="upload-progress-label">Uploading…</div>
            </div>
            <div class="modal-actions">
                <button type="button" class="secondary" id="upload-cancel">Cancel</button>
                <button type="submit" class="primary" id="upload-submit">Upload &amp; evaluate</button>
            </div>
        </form>
    </div>
</div>
