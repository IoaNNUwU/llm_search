<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $messages */
/** @var string|null $error */
/** @var string|null $prompt */
/** @var string $windowId */

?>
<div class="messages" id="messages">
    <?php if ($messages === []): ?>
        <p class="empty">Ask anything. Select projects in the sidebar for sources. Turn Agent off in the top panel to get only relevant links.</p>
    <?php else: ?>
        <?php foreach ($messages as $message): ?>
            <?php require __DIR__ . '/message.php'; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if ($error !== null): ?>
    <div class="error"><?= h($error) ?></div>
<?php endif; ?>

<form class="composer" method="post" id="composer">
    <input type="hidden" name="w" id="window-id-input" value="<?= h($windowId) ?>">
    <input type="hidden" name="project_ids" id="project-ids-input" value="">
    <input type="hidden" name="use_agent" id="use-agent-input" value="1">
    <div class="context-bar" id="context-bar" data-active="0">
        Context: <strong id="context-project-name">no projects selected</strong>
    </div>
    <div class="composer-row">
        <textarea
            name="prompt"
            rows="2"
            placeholder="Message…"
            autofocus
        ><?= isset($prompt) && $error !== null ? h((string) $prompt) : '' ?></textarea>
        <button type="submit" id="send">Send</button>
    </div>
</form>
