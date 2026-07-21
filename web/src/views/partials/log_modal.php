<div class="modal-backdrop" id="log-modal" role="dialog" aria-modal="true" aria-labelledby="log-title">
    <div class="modal modal-wide modal-log">
        <div class="log-header">
            <div>
                <h3 id="log-title">Evaluation log</h3>
                <p class="log-meta" id="log-meta">—</p>
            </div>
            <button type="button" class="secondary" id="log-close">Close</button>
        </div>
        <div class="log-status-bar" id="log-status-bar" hidden>
            <div class="log-status-row">
                <span class="log-live-indicator" id="log-live-indicator" hidden>
                    <span class="log-live-dot" aria-hidden="true"></span>
                    In progress
                </span>
                <span class="log-elapsed" id="log-elapsed"></span>
            </div>
            <div class="log-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100">
                <span id="log-progress-fill"></span>
            </div>
            <div class="log-progress-label" id="log-progress-label"></div>
        </div>
        <div class="log-columns">
            <div class="log-col log-col-main">
                <div class="log-current" id="log-current" hidden>
                    <div class="log-current-top">
                        <div class="label" id="log-current-label">Now evaluating</div>
                        <span class="log-step-elapsed" id="log-step-elapsed"></span>
                    </div>
                    <div class="log-current-badges" id="log-current-badges"></div>
                    <div class="path" id="log-current-path"></div>
                    <pre id="log-current-text"></pre>
                </div>
                <div class="log-recent-sections" id="log-recent-sections" hidden>
                    <div class="log-recent-title">Последние проиндексированные</div>
                    <ul class="log-recent-list" id="log-recent-list"></ul>
                </div>
            </div>
            <div class="log-col log-col-queue">
                <div class="log-body">
                    <div class="log-queue-title">Очередь неиндексированных</div>
                    <div class="log-events" id="log-events">
                        <p class="log-empty">Loading…</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
