<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/ollama.php';
require_once __DIR__ . '/lib/search.php';

const MAX_HISTORY = 20;

$ollamaModel = ollama_model();

if (!isset($_SESSION['messages']) || !is_array($_SESSION['messages'])) {
    $_SESSION['messages'] = [];
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/')) {
    if (isset($_POST['clear'])) {
        $_SESSION['messages'] = [];
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }

    $prompt = trim((string) ($_POST['prompt'] ?? ''));
    $projectId = (int) ($_POST['project_id'] ?? 0);

    if ($prompt === '') {
        $error = 'Enter a message.';
    } else {
        $_SESSION['messages'][] = ['role' => 'user', 'content' => $prompt];

        if (count($_SESSION['messages']) > MAX_HISTORY) {
            $_SESSION['messages'] = array_slice($_SESSION['messages'], -MAX_HISTORY);
        }

        try {
            $hits = [];
            $projectName = null;
            if ($projectId > 0) {
                $pdo = db();
                $project = get_project($pdo, $projectId);
                if ($project !== null) {
                    $projectName = $project['name'];
                    $hits = search_project_sections($pdo, $projectId, $prompt);
                }
            }

            $payload = [
                'model' => $ollamaModel,
                'messages' => build_ollama_chat_messages($_SESSION['messages'], $projectName, $hits),
                'stream' => false,
            ];
            $data = ollama_request('/api/chat', $payload);
            if (!isset($data['message']['content'])) {
                throw new RuntimeException('Unexpected response from Ollama.');
            }
            $assistant = [
                'role' => 'assistant',
                'content' => (string) $data['message']['content'],
            ];
            $refs = references_from_hits($hits);
            if ($refs !== []) {
                $assistant['references'] = $refs;
                if ($projectName !== null) {
                    $assistant['project_name'] = $projectName;
                }
            }
            $_SESSION['messages'][] = $assistant;
            if (count($_SESSION['messages']) > MAX_HISTORY) {
                $_SESSION['messages'] = array_slice($_SESSION['messages'], -MAX_HISTORY);
            }
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        } catch (Throwable $e) {
            array_pop($_SESSION['messages']);
            $error = $e->getMessage();
        }
    }
}

$messages = $_SESSION['messages'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LLM Search Engine</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0f1419;
            --bg-elevated: #1a222c;
            --bg-sidebar: #12181f;
            --bg-user: #243044;
            --bg-assistant: #161d26;
            --border: #2a3544;
            --text: #e8eef4;
            --muted: #8b9aab;
            --accent: #3d9a7a;
            --accent-hover: #4db892;
            --danger: #c45c5c;
            --warn: #c9a227;
            --radius: 10px;
            --font: "DM Sans", system-ui, sans-serif;
            --mono: "IBM Plex Mono", ui-monospace, monospace;
            --sidebar-width: 300px;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: var(--font);
            color: var(--text);
            background:
                radial-gradient(ellipse 80% 50% at 50% -20%, #1a3a32 0%, transparent 55%),
                radial-gradient(ellipse 60% 40% at 100% 100%, #1a2838 0%, transparent 50%),
                var(--bg);
            display: flex;
        }

        .sidebar {
            width: var(--sidebar-width);
            flex-shrink: 0;
            background: color-mix(in srgb, var(--bg-sidebar) 92%, transparent);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            backdrop-filter: blur(8px);
            overflow: hidden;
            transition: width 0.22s ease, border-color 0.22s ease, opacity 0.22s ease;
        }

        body.sidebar-collapsed .sidebar {
            width: 0;
            border-right-color: transparent;
            opacity: 0;
            pointer-events: none;
        }

        .sidebar-header {
            padding: 1.1rem 1rem 0.75rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            min-width: var(--sidebar-width);
        }

        .sidebar-header h2 {
            margin: 0;
            font-size: 0.85rem;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: var(--muted);
        }

        .sidebar-header-actions {
            display: flex;
            align-items: center;
            gap: 0.35rem;
        }

        .sidebar-header .btn-new {
            font-family: inherit;
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            border-radius: 8px;
            border: 1px solid transparent;
            padding: 0.4rem 0.7rem;
            background: var(--accent);
            color: #061510;
        }

        .sidebar-header .btn-new:hover { background: var(--accent-hover); }

        .icon-btn {
            font-family: inherit;
            font-size: 1rem;
            line-height: 1;
            width: 2rem;
            height: 2rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: transparent;
            color: var(--muted);
            padding: 0;
            flex-shrink: 0;
        }

        .icon-btn:hover {
            color: var(--text);
            border-color: var(--muted);
        }

        header .header-left {
            display: flex;
            align-items: center;
            gap: 0.65rem;
        }

        body:not(.sidebar-collapsed) #btn-open-sidebar {
            display: none;
        }

        .project-list {
            flex: 1;
            overflow-y: auto;
            padding: 0.6rem;
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
            min-width: var(--sidebar-width);
        }

        .project-item {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 0.35rem 0.6rem;
            align-items: start;
            padding: 0.7rem 0.75rem;
            border-radius: 8px;
            border: 1px solid transparent;
            background: transparent;
            color: inherit;
            text-align: left;
            cursor: pointer;
            font-family: inherit;
            width: 100%;
        }

        .project-item:hover {
            background: color-mix(in srgb, var(--bg-elevated) 80%, transparent);
            border-color: var(--border);
        }

        .project-item.active {
            background: var(--bg-elevated);
            border-color: color-mix(in srgb, var(--accent) 45%, var(--border));
        }

        .project-item .pname {
            font-size: 0.9rem;
            font-weight: 500;
            line-height: 1.3;
        }

        .project-item .pdesc {
            grid-column: 1 / -1;
            font-size: 0.75rem;
            color: var(--muted);
            line-height: 1.35;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .eval-badge {
            font-family: var(--mono);
            font-size: 0.65rem;
            white-space: nowrap;
            color: var(--muted);
            align-self: center;
        }

        .eval-badge.processing { color: var(--warn); }
        .eval-badge.completed { color: var(--accent); }
        .eval-badge.failed,
        .eval-badge.cancelled { color: var(--danger); }

        .project-actions {
            grid-column: 1 / -1;
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
            margin-top: 0.15rem;
        }

        .project-actions button {
            font-family: inherit;
            font-size: 0.7rem;
            font-weight: 500;
            padding: 0.25rem 0.55rem;
            border-radius: 6px;
            border: 1px solid var(--border);
            background: transparent;
            color: var(--muted);
            cursor: pointer;
        }

        .project-actions button:hover {
            color: var(--text);
            border-color: var(--muted);
        }

        .project-actions button.danger:hover {
            color: #f0c4c4;
            border-color: color-mix(in srgb, var(--danger) 55%, var(--border));
        }

        .project-actions button:disabled {
            opacity: 0.5;
            cursor: wait;
        }

        .eval-bar {
            grid-column: 1 / -1;
            height: 3px;
            background: var(--border);
            border-radius: 2px;
            overflow: hidden;
        }

        .eval-bar > span {
            display: block;
            height: 100%;
            background: var(--accent);
            width: 0%;
            transition: width 0.3s ease;
        }

        .eval-detail {
            grid-column: 1 / -1;
            font-family: var(--mono);
            font-size: 0.65rem;
            color: var(--muted);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .sidebar-empty, .sidebar-error {
            margin: 1.5rem 0.75rem;
            text-align: center;
            color: var(--muted);
            font-size: 0.85rem;
            line-height: 1.45;
        }

        .sidebar-error { color: #f0c4c4; }

        .app {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
            min-height: 100vh;
        }

        header {
            padding: 1.25rem 1.5rem 0.75rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }

        header h1 {
            margin: 0;
            font-size: 1.15rem;
            font-weight: 600;
            letter-spacing: -0.02em;
        }

        header .model {
            font-family: var(--mono);
            font-size: 0.75rem;
            color: var(--muted);
        }

        main {
            flex: 1;
            display: flex;
            flex-direction: column;
            max-width: 720px;
            width: 100%;
            margin: 0 auto;
            padding: 1rem 1.25rem 0;
        }

        .messages {
            flex: 1;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 0.85rem;
            padding-bottom: 1rem;
            min-height: 12rem;
        }

        .empty {
            margin: auto;
            text-align: center;
            color: var(--muted);
            font-size: 0.95rem;
            line-height: 1.5;
            max-width: 22rem;
        }

        .bubble {
            padding: 0.85rem 1rem;
            border-radius: var(--radius);
            border: 1px solid var(--border);
            line-height: 1.55;
            word-break: break-word;
            font-size: 0.95rem;
        }

        .bubble.user {
            align-self: flex-end;
            background: var(--bg-user);
            max-width: 85%;
        }

        .bubble.assistant {
            align-self: stretch;
            background: var(--bg-assistant);
        }

        .bubble .role {
            display: block;
            font-family: var(--mono);
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--muted);
            margin-bottom: 0.4rem;
        }

        .bubble .content {
            white-space: pre-wrap;
        }

        .refs {
            margin-top: 0.75rem;
            padding-top: 0.65rem;
            border-top: 1px solid var(--border);
        }

        .refs-label {
            font-family: var(--mono);
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--muted);
            margin-bottom: 0.4rem;
        }

        .refs-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 0.55rem;
        }

        .refs-list a {
            color: var(--accent-hover);
            text-decoration: none;
            font-size: 0.85rem;
            word-break: break-all;
        }

        .refs-list a:hover {
            text-decoration: underline;
        }

        .ref-article-title {
            font-size: 0.8rem;
            font-weight: 550;
            color: var(--text);
            margin-bottom: 0.2rem;
        }

        .ref-article-title a {
            color: var(--text);
            font-size: inherit;
        }

        .ref-article-title a:hover {
            color: var(--accent-hover);
        }

        .ref-sections {
            list-style: none;
            margin: 0;
            padding: 0 0 0 0.75rem;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            border-left: 1px solid var(--border);
        }

        .context-bar {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            min-height: 1.4rem;
            font-size: 0.8rem;
            color: var(--muted);
        }

        .context-bar strong {
            color: var(--accent-hover);
            font-weight: 550;
        }

        .context-bar[data-active="0"] strong {
            color: var(--muted);
            font-weight: 400;
        }

        .error {
            margin-bottom: 0.75rem;
            padding: 0.75rem 1rem;
            border-radius: var(--radius);
            border: 1px solid color-mix(in srgb, var(--danger) 40%, transparent);
            background: color-mix(in srgb, var(--danger) 12%, transparent);
            color: #f0c4c4;
            font-size: 0.9rem;
        }

        form.composer {
            position: sticky;
            bottom: 0;
            padding: 0.75rem 0 1.25rem;
            background: linear-gradient(to top, var(--bg) 70%, transparent);
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
        }

        .composer-row {
            display: flex;
            gap: 0.5rem;
            align-items: flex-end;
        }

        textarea, input[type="text"], input[type="url"] {
            width: 100%;
            padding: 0.75rem 0.9rem;
            border-radius: var(--radius);
            border: 1px solid var(--border);
            background: var(--bg-elevated);
            color: var(--text);
            font-family: inherit;
            font-size: 0.95rem;
            line-height: 1.45;
        }

        textarea:focus, input[type="text"]:focus, input[type="url"]:focus {
            outline: 2px solid color-mix(in srgb, var(--accent) 55%, transparent);
            outline-offset: 1px;
            border-color: var(--accent);
        }

        .composer textarea {
            flex: 1;
            resize: vertical;
            min-height: 3.2rem;
            max-height: 10rem;
            width: auto;
        }

        button {
            font-family: inherit;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            border-radius: var(--radius);
            border: 1px solid transparent;
            padding: 0.7rem 1.1rem;
            transition: background 0.15s ease, border-color 0.15s ease;
        }

        button[type="submit"]:not(.secondary), button.primary {
            background: var(--accent);
            color: #061510;
        }

        button[type="submit"]:not(.secondary):hover, button.primary:hover {
            background: var(--accent-hover);
        }

        button.secondary {
            background: transparent;
            color: var(--muted);
            border-color: var(--border);
            padding: 0.4rem 0.75rem;
            font-size: 0.8rem;
        }

        button.secondary:hover {
            color: var(--text);
            border-color: var(--muted);
        }

        button:disabled {
            opacity: 0.55;
            cursor: wait;
        }

        /* Upload modal */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(6, 10, 14, 0.72);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 40;
            padding: 1rem;
        }

        .modal-backdrop.open { display: flex; }

        .modal {
            width: min(440px, 100%);
            background: var(--bg-elevated);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.25rem;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.45);
        }

        .modal h3 {
            margin: 0 0 0.35rem;
            font-size: 1.05rem;
        }

        .modal .hint {
            margin: 0 0 1rem;
            color: var(--muted);
            font-size: 0.85rem;
            line-height: 1.4;
        }

        .field {
            margin-bottom: 0.75rem;
        }

        .field label {
            display: block;
            font-size: 0.75rem;
            color: var(--muted);
            margin-bottom: 0.3rem;
            letter-spacing: 0.02em;
        }

        .dropzone {
            border: 1.5px dashed var(--border);
            border-radius: var(--radius);
            padding: 1.25rem 1rem;
            text-align: center;
            color: var(--muted);
            font-size: 0.85rem;
            line-height: 1.45;
            cursor: pointer;
            transition: border-color 0.15s ease, background 0.15s ease;
            margin-bottom: 0.75rem;
        }

        .dropzone.dragover {
            border-color: var(--accent);
            background: color-mix(in srgb, var(--accent) 10%, transparent);
            color: var(--text);
        }

        .dropzone .dz-files {
            margin-top: 0.5rem;
            font-family: var(--mono);
            font-size: 0.7rem;
            color: var(--accent);
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .modal .form-error {
            color: #f0c4c4;
            font-size: 0.85rem;
            margin-bottom: 0.5rem;
            display: none;
        }

        .modal .form-error.show { display: block; }

        .modal.modal-wide {
            width: min(720px, 100%);
            max-height: min(88vh, 900px);
            display: flex;
            flex-direction: column;
            padding: 1.1rem 1.2rem 1rem;
        }

        .log-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }

        .log-header h3 {
            margin: 0;
        }

        .log-meta {
            font-family: var(--mono);
            font-size: 0.72rem;
            color: var(--muted);
            margin: 0.25rem 0 0;
        }

        .log-current {
            border: 1px solid color-mix(in srgb, var(--accent) 35%, var(--border));
            background: color-mix(in srgb, var(--accent) 8%, var(--bg));
            border-radius: 8px;
            padding: 0.75rem 0.85rem;
            margin-bottom: 0.75rem;
        }

        .log-current .label {
            font-family: var(--mono);
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--accent);
            margin-bottom: 0.35rem;
        }

        .log-current .path {
            font-family: var(--mono);
            font-size: 0.78rem;
            color: var(--text);
            margin-bottom: 0.45rem;
            word-break: break-all;
        }

        .log-current pre,
        .log-event pre {
            margin: 0;
            white-space: pre-wrap;
            word-break: break-word;
            font-family: var(--mono);
            font-size: 0.75rem;
            line-height: 1.45;
            color: var(--text);
            max-height: 12rem;
            overflow-y: auto;
        }

        .log-events {
            flex: 1;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 0.55rem;
            min-height: 12rem;
            padding-right: 0.15rem;
        }

        .log-event {
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.65rem 0.75rem;
            background: color-mix(in srgb, var(--bg) 55%, var(--bg-elevated));
        }

        .log-event .ev-head {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem 0.65rem;
            align-items: baseline;
            margin-bottom: 0.35rem;
        }

        .log-event .ev-phase {
            font-family: var(--mono);
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--warn);
        }

        .log-event .ev-phase.file { color: var(--accent); }
        .log-event .ev-phase.section { color: var(--warn); }
        .log-event .ev-phase.done { color: var(--accent); }
        .log-event .ev-phase.failed,
        .log-event .ev-phase.cancelled { color: var(--danger); }

        .log-event .ev-msg {
            font-size: 0.82rem;
            color: var(--text);
        }

        .log-event .ev-file {
            font-family: var(--mono);
            font-size: 0.7rem;
            color: var(--muted);
            word-break: break-all;
        }

        .log-empty {
            color: var(--muted);
            font-size: 0.85rem;
            text-align: center;
            margin: auto;
            padding: 1.5rem;
        }

        @media (max-width: 720px) {
            body { flex-direction: column; }
            .sidebar {
                width: 100%;
                min-height: auto;
                max-height: 40vh;
                border-right: none;
                border-bottom: 1px solid var(--border);
            }
            body.sidebar-collapsed .sidebar {
                width: 100%;
                max-height: 0;
                min-height: 0;
                border-bottom-color: transparent;
            }
            .sidebar-header,
            .project-list {
                min-width: 0;
            }
        }
    </style>
</head>
<body>
    <aside class="sidebar" id="sidebar" aria-label="Projects">
        <div class="sidebar-header">
            <h2>Projects</h2>
            <div class="sidebar-header-actions">
                <button type="button" class="btn-new" id="btn-new-project" title="Upload a new project">+ New</button>
                <button type="button" class="icon-btn" id="btn-close-sidebar" title="Hide sidebar" aria-label="Hide sidebar">‹</button>
            </div>
        </div>
        <div class="project-list" id="project-list">
            <p class="sidebar-empty">Loading projects…</p>
        </div>
    </aside>

    <div class="app">
        <header>
            <div class="header-left">
                <button type="button" class="icon-btn" id="btn-open-sidebar" title="Show projects" aria-label="Show projects">☰</button>
                <h1>LLM Search Engine</h1>
            </div>
            <span class="model"><?= h($ollamaModel) ?></span>
        </header>

        <main>
            <div class="messages" id="messages">
                <?php if ($messages === []): ?>
                    <p class="empty">Ask anything. Select a project in the sidebar to ground answers in its docs and show reference links.</p>
                <?php else: ?>
                    <?php foreach ($messages as $message): ?>
                        <div class="bubble <?= h($message['role']) ?>">
                            <span class="role"><?= h($message['role']) ?></span>
                            <div class="content"><?= h($message['content']) ?></div>
                            <?php
                            $refs = $message['references'] ?? null;
                            if ($message['role'] === 'assistant' && is_array($refs) && $refs !== []):
                                $projLabel = trim((string) ($message['project_name'] ?? ''));
                            ?>
                                <div class="refs">
                                    <div class="refs-label">
                                        References<?= $projLabel !== '' ? ' · ' . h($projLabel) : '' ?>
                                    </div>
                                    <ul class="refs-list">
                                        <?php foreach ($refs as $ref): ?>
                                            <?php
                                            // Grouped shape (preferred) or legacy flat citations from older sessions.
                                            $isGrouped = isset($ref['sections']) && is_array($ref['sections']);
                                            if ($isGrouped):
                                                $articleTitle = trim((string) ($ref['article_title'] ?? 'Source'));
                                                $articleLink = trim((string) ($ref['article_link'] ?? ''));
                                                $sections = $ref['sections'];
                                            ?>
                                                <li class="ref-group">
                                                    <div class="ref-article-title">
                                                        <?php if ($articleLink !== ''): ?>
                                                            <a href="<?= h($articleLink) ?>" target="_blank" rel="noopener noreferrer">
                                                                <?= h($articleTitle !== '' ? $articleTitle : 'Source') ?>
                                                            </a>
                                                        <?php else: ?>
                                                            <?= h($articleTitle !== '' ? $articleTitle : 'Source') ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($sections !== []): ?>
                                                        <ul class="ref-sections">
                                                            <?php foreach ($sections as $section): ?>
                                                                <li>
                                                                    <a href="<?= h((string) ($section['link'] ?? '#')) ?>" target="_blank" rel="noopener noreferrer">
                                                                        <?= h((string) ($section['title'] ?? 'Source')) ?>
                                                                    </a>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php endif; ?>
                                                </li>
                                            <?php else: ?>
                                                <li>
                                                    <a href="<?= h((string) ($ref['link'] ?? '#')) ?>" target="_blank" rel="noopener noreferrer">
                                                        <?= h((string) ($ref['title'] ?? 'Source')) ?>
                                                    </a>
                                                </li>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ($error !== null): ?>
                <div class="error"><?= h($error) ?></div>
            <?php endif; ?>

            <form class="composer" method="post" id="composer">
                <input type="hidden" name="project_id" id="project-id-input" value="0">
                <div class="context-bar" id="context-bar" data-active="0">
                    Context: <strong id="context-project-name">no project selected</strong>
                </div>
                <?php if ($messages !== []): ?>
                    <button class="secondary" type="submit" name="clear" value="1" formnovalidate>Clear chat</button>
                <?php endif; ?>
                <div class="composer-row">
                    <textarea
                        name="prompt"
                        rows="2"
                        placeholder="Message…"
                        autofocus
                    ><?= isset($prompt) && $error !== null ? h($prompt) : '' ?></textarea>
                    <button type="submit" id="send">Send</button>
                </div>
            </form>
        </main>
    </div>

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

    <div class="modal-backdrop" id="log-modal" role="dialog" aria-modal="true" aria-labelledby="log-title">
        <div class="modal modal-wide">
            <div class="log-header">
                <div>
                    <h3 id="log-title">Evaluation log</h3>
                    <p class="log-meta" id="log-meta">—</p>
                </div>
                <button type="button" class="secondary" id="log-close">Close</button>
            </div>
            <div class="log-current" id="log-current" hidden>
                <div class="label" id="log-current-label">Now evaluating</div>
                <div class="path" id="log-current-path"></div>
                <pre id="log-current-text"></pre>
            </div>
            <div class="log-events" id="log-events">
                <p class="log-empty">Loading…</p>
            </div>
        </div>
    </div>

    <script>
        const messages = document.getElementById('messages');
        messages.scrollTop = messages.scrollHeight;

        const SIDEBAR_KEY = 'sidebarCollapsed';
        function setSidebarCollapsed(collapsed) {
            document.body.classList.toggle('sidebar-collapsed', collapsed);
            localStorage.setItem(SIDEBAR_KEY, collapsed ? '1' : '0');
            document.getElementById('btn-open-sidebar').setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            document.getElementById('btn-close-sidebar').setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        }
        setSidebarCollapsed(localStorage.getItem(SIDEBAR_KEY) === '1');
        document.getElementById('btn-close-sidebar').addEventListener('click', () => setSidebarCollapsed(true));
        document.getElementById('btn-open-sidebar').addEventListener('click', () => setSidebarCollapsed(false));

        document.getElementById('composer').addEventListener('submit', (event) => {
            const submitter = event.submitter;
            if (submitter && submitter.name === 'clear') {
                return;
            }
            updateChatContext();
            document.getElementById('send').disabled = true;
            document.getElementById('send').textContent = '…';
        });

        document.querySelector('.composer textarea')?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                document.getElementById('composer').requestSubmit(document.getElementById('send'));
            }
        });

        const projectList = document.getElementById('project-list');
        const modal = document.getElementById('upload-modal');
        const dropzone = document.getElementById('dropzone');
        const folderInput = document.getElementById('folder-input');
        const dzFiles = document.getElementById('dz-files');
        const uploadError = document.getElementById('upload-error');

        let selectedProjectId = Number(localStorage.getItem('selectedProjectId') || 0);
        let pendingFiles = [];
        let projectsCache = [];

        const projectIdInput = document.getElementById('project-id-input');
        const contextBar = document.getElementById('context-bar');
        const contextProjectName = document.getElementById('context-project-name');

        function updateChatContext() {
            const project = projectsCache.find((p) => p.id === selectedProjectId);
            projectIdInput.value = project ? String(project.id) : '0';
            contextBar.dataset.active = project ? '1' : '0';
            if (project) {
                const ready = project.evaluation?.status === 'completed';
                contextProjectName.textContent = ready
                    ? project.name
                    : `${project.name} (indexing…)`;
            } else {
                contextProjectName.textContent = 'no project selected';
            }
        }
        function isMarkdownPath(path) {
            return /\.md$/i.test(path || '');
        }

        function setPendingFiles(files) {
            pendingFiles = files.filter((item) => isMarkdownPath(item.path || item.file?.name));
            dzFiles.textContent = pendingFiles.length
                ? `${pendingFiles.length} markdown file(s) selected`
                : 'No .md files found in that folder';
        }

        async function parseJsonResponse(res) {
            const text = await res.text();
            try {
                return JSON.parse(text);
            } catch {
                const snippet = text.replace(/\s+/g, ' ').trim().slice(0, 180);
                throw new Error(snippet || `Upload failed (HTTP ${res.status})`);
            }
        }

        function statusLabel(ev) {
            if (!ev) return '';
            switch (ev.status) {
                case 'pending': return 'queued';
                case 'processing': return `${ev.percent}%`;
                case 'completed': return 'done';
                case 'failed': return 'failed';
                case 'cancelled': return 'cancelled';
                default: return ev.status || '';
            }
        }

        async function postProjectAction(url, projectId) {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: projectId }),
            });
            const data = await parseJsonResponse(res);
            if (!res.ok) throw new Error(data.error || 'Request failed');
            return data;
        }

        function renderProjects(projects) {
            projectsCache = projects;
            updateChatContext();

            if (!projects.length) {
                projectList.innerHTML = '<p class="sidebar-empty">No projects yet. Click <strong>+ New</strong> to upload a folder.</p>';
                return;
            }

            projectList.innerHTML = '';
            for (const p of projects) {
                const ev = p.evaluation || {};
                const item = document.createElement('div');
                item.className = 'project-item' + (p.id === selectedProjectId ? ' active' : '');
                item.dataset.id = String(p.id);
                item.setAttribute('role', 'button');
                item.tabIndex = 0;

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
                    const fill = document.createElement('span');
                    fill.style.width = (ev.percent || 0) + '%';
                    bar.append(fill);
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
                        if (selectedProjectId === p.id) {
                            selectedProjectId = 0;
                            localStorage.removeItem('selectedProjectId');
                        }
                        await loadProjects();
                    } catch (err) {
                        alert(err.message);
                        removeBtn.disabled = false;
                    }
                });
                actions.append(removeBtn);
                item.append(actions);

                const selectProject = () => {
                    selectedProjectId = p.id;
                    localStorage.setItem('selectedProjectId', String(p.id));
                    document.querySelectorAll('.project-item').forEach((el) => {
                        el.classList.toggle('active', Number(el.dataset.id) === selectedProjectId);
                    });
                    updateChatContext();
                };
                item.addEventListener('click', selectProject);
                item.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        selectProject();
                    }
                });

                projectList.append(item);
            }
        }

        let pollTimer = null;
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

        async function loadProjects() {
            try {
                const res = await fetch('api/projects.php');
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'Failed to load projects');
                renderProjects(data.projects || []);

                const busy = (data.projects || []).some((p) =>
                    p.evaluation && (p.evaluation.status === 'processing' || p.evaluation.status === 'pending')
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
                selectedProjectId = data.project_id;
                localStorage.setItem('selectedProjectId', String(data.project_id));
                closeModal();
                await loadProjects();
                if (!pollTimer) pollTimer = setInterval(loadProjects, 2000);
            } catch (err) {
                uploadError.textContent = err.message;
                uploadError.classList.add('show');
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Upload & evaluate';
            }
        });

        loadProjects();
    </script>
</body>
</html>
