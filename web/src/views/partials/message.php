<?php

declare(strict_types=1);

/** @var array<string, mixed> $message */

$refs = $message['references'] ?? null;
$linksOnly = !empty($message['links_only']);
$citationPayload = [];
$citationSource = $message['citations'] ?? null;
if ($message['role'] === 'assistant' && !$linksOnly && is_array($citationSource)) {
    foreach ($citationSource as $citation) {
        if (!is_array($citation) || !isset($citation['n'])) {
            continue;
        }
        $n = (int) $citation['n'];
        $link = trim((string) ($citation['link'] ?? ''));
        if ($n < 1 || $link === '') {
            continue;
        }
        $citationPayload[] = [
            'n' => $n,
            'link' => $link,
            'title' => (string) ($citation['title'] ?? 'Reference'),
        ];
    }
}
$citationsAttr = $citationPayload !== []
    ? ' data-citations="' . h(json_encode($citationPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]') . '"'
    : '';

$content = (string) $message['content'];

?>
<div class="bubble <?= h((string) $message['role']) ?><?= $linksOnly ? ' links-only' : '' ?>">
    <span class="role"><?= $linksOnly ? 'links' : h((string) $message['role']) ?></span>
    <?php if ($content !== ''): ?>
        <div class="content"<?= $citationsAttr ?>><?= h($content) ?></div>
    <?php endif; ?>
    <?php
    if ($message['role'] === 'assistant' && is_array($refs) && $refs !== []):
        $projNames = $message['project_names'] ?? null;
        if (!is_array($projNames) || $projNames === []) {
            $legacy = trim((string) ($message['project_name'] ?? ''));
            $projNames = $legacy !== '' ? [$legacy] : [];
        }
        $projLabel = implode(', ', array_map('strval', $projNames));
        $refsHiddenCount = 0;
        $refsPreviewPerProject = $linksOnly ? PHP_INT_MAX : 2;
        ob_start();
        foreach ($refs as $refListIndex => $ref) {
            require __DIR__ . '/reference.php';
        }
        $refsHtml = ob_get_clean();
    ?>
        <div class="refs" data-expanded="<?= $linksOnly ? '1' : '0' ?>">
            <div class="refs-label">
                <?= $linksOnly ? 'Relevant links' : 'References' ?><?= $projLabel !== '' ? ' · ' . h($projLabel) : '' ?>
            </div>
            <ul class="refs-list">
                <?= $refsHtml ?>
            </ul>
            <?php if (!$linksOnly && $refsHiddenCount > 0): ?>
                <button
                    type="button"
                    class="refs-toggle"
                    aria-expanded="false"
                    data-more="<?= (int) $refsHiddenCount ?>"
                >
                    Show all (<?= (int) $refsHiddenCount ?> more)
                </button>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
