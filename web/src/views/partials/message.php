<?php

declare(strict_types=1);

/** @var array<string, mixed> $message */

$refs = $message['references'] ?? null;
$citationPayload = [];
$citationSource = $message['citations'] ?? null;
if ($message['role'] === 'assistant' && is_array($citationSource)) {
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

?>
<div class="bubble <?= h((string) $message['role']) ?>">
    <span class="role"><?= h((string) $message['role']) ?></span>
    <div class="content"<?= $citationsAttr ?>><?= h((string) $message['content']) ?></div>
    <?php
    if ($message['role'] === 'assistant' && is_array($refs) && $refs !== []):
        $projNames = $message['project_names'] ?? null;
        if (!is_array($projNames) || $projNames === []) {
            $legacy = trim((string) ($message['project_name'] ?? ''));
            $projNames = $legacy !== '' ? [$legacy] : [];
        }
        $projLabel = implode(', ', array_map('strval', $projNames));
        $refsHiddenCount = 0;
        $refsPreviewPerProject = 2;
        ob_start();
        foreach ($refs as $refListIndex => $ref) {
            require __DIR__ . '/reference.php';
        }
        $refsHtml = ob_get_clean();
    ?>
        <div class="refs" data-expanded="0">
            <div class="refs-label">
                References<?= $projLabel !== '' ? ' · ' . h($projLabel) : '' ?>
            </div>
            <ul class="refs-list">
                <?= $refsHtml ?>
            </ul>
            <?php if ($refsHiddenCount > 0): ?>
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
