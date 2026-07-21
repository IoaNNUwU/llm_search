<?php

declare(strict_types=1);

/** @var string $ollamaModel */
/** @var list<array<string, mixed>> $messages */
/** @var string|null $error */
/** @var string|null $prompt */
/** @var string $chatLanguage */
/** @var string $windowId */

$chatLanguage = normalize_chat_language($chatLanguage ?? 'ru');
$htmlLang = $chatLanguage === 'en' ? 'en' : 'ru';
$windowId = isset($windowId) ? (string) $windowId : chat_window_id();

?>
<!DOCTYPE html>
<html lang="<?= h($htmlLang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LLM Search Engine</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= h(asset_url('css/app.css')) ?>">
    <?php
    $jsScope = [];
    foreach (glob(__DIR__ . '/../assets/js/*.js') as $jsFile) {
        $name = basename($jsFile);
        $jsScope['./' . $name] = asset_url('js/' . $name);
    }
    ?>
    <script type="importmap">
    <?= json_encode(['scopes' => ['/assets/js/' => $jsScope]], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
    </script>
</head>
<body>
    <?php require __DIR__ . '/partials/sidebar.php'; ?>

    <div class="app">
        <header>
            <div class="header-left">
                <button type="button" class="icon-btn" id="btn-open-sidebar" title="Show projects" aria-label="Show projects">☰</button>
                <h1>LLM Search Engine</h1>
            </div>
            <div class="header-right">
                <label class="agent-toggle" title="When off, answers are only relevant links from selected sources">
                    <span class="agent-toggle-label">Agent</span>
                    <input type="checkbox" id="agent-toggle" checked aria-label="Use chat agent">
                    <span class="agent-toggle-track" aria-hidden="true"><span class="agent-toggle-thumb"></span></span>
                </label>
                <button
                    class="secondary btn-new-window"
                    type="submit"
                    name="new_window"
                    value="1"
                    form="composer"
                    formtarget="_blank"
                    formnovalidate
                    title="Open a new window with empty LLM dialogue context"
                >New window</button>
                <label class="lang-select" title="Reply language">
                    <span class="lang-label">Lang</span>
                    <select id="chat-language" name="language" form="composer" aria-label="Reply language">
                        <option value="ru"<?= $chatLanguage === 'ru' ? ' selected' : '' ?>>RU</option>
                        <option value="en"<?= $chatLanguage === 'en' ? ' selected' : '' ?>>EN</option>
                    </select>
                </label>
                <span class="model"><?= h($ollamaModel) ?></span>
            </div>
        </header>

        <main>
            <?php require __DIR__ . '/partials/chat.php'; ?>
        </main>
    </div>

    <?php require __DIR__ . '/partials/upload_modal.php'; ?>
    <?php require __DIR__ . '/partials/log_modal.php'; ?>

    <script type="module" src="<?= h(asset_url('js/app.js')) ?>"></script>
</body>
</html>
