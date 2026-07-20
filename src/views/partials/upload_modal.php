<div class="modal-backdrop" id="upload-modal" role="dialog" aria-modal="true" aria-labelledby="upload-title">
    <div class="modal">
        <h3 id="upload-title">Upload project</h3>
        <p class="hint">Name and description are stored in the database. Drop a folder (or pick one) — only <code>.md</code> files are uploaded and indexed.</p>
        <div class="form-error" id="upload-error"></div>
        <form id="upload-form">
            <div class="field">
                <label for="proj-name">Name</label>
                <input type="text" id="proj-name" name="name" required maxlength="200" autocomplete="off">
            </div>
            <div class="field">
                <label for="proj-desc">Description</label>
                <textarea id="proj-desc" name="description" rows="3" required maxlength="2000"></textarea>
            </div>
            <div class="field">
                <label for="proj-base">Base URL <span style="opacity:.7">(optional — used for article links)</span></label>
                <input type="url" id="proj-base" name="base_url" placeholder="https://docs.example.com">
            </div>
            <div class="dropzone" id="dropzone" tabindex="0">
                Drag &amp; drop a folder here<br>
                or click to select from your PC
                <div class="dz-files" id="dz-files"></div>
            </div>
            <input type="file" id="folder-input" webkitdirectory multiple hidden>
            <div class="modal-actions">
                <button type="button" class="secondary" id="upload-cancel">Cancel</button>
                <button type="submit" class="primary" id="upload-submit">Upload &amp; evaluate</button>
            </div>
        </form>
    </div>
</div>
