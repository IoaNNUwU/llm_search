<?php

declare(strict_types=1);

/** @var array<string, mixed> $ref */
/** @var int $refsHiddenCount */
/** @var int $refListIndex */
/** @var int $refsPreviewPerProject */

$previewLimit = $refsPreviewPerProject ?? 2;

// Project → articles → sections (preferred), article → sections, or legacy flat citations.
$isProjectGrouped = isset($ref['articles']) && is_array($ref['articles']);
$isArticleGrouped = !$isProjectGrouped && isset($ref['sections']) && is_array($ref['sections']);

if ($isProjectGrouped):
    $projectName = trim((string) ($ref['project_name'] ?? 'Project'));
    $articles = $ref['articles'];
    // Articles/sections arrive sorted most-relevant-first; preview the top paragraphs.
    $shown = 0;
?>
    <li class="ref-project">
        <div class="ref-project-name"><?= h($projectName !== '' ? $projectName : 'Project') ?></div>
        <ul class="ref-articles">
            <?php foreach ($articles as $article): ?>
                <?php
                $articleTitle = trim((string) ($article['article_title'] ?? 'Source'));
                $articleLink = trim((string) ($article['article_link'] ?? ''));
                $sections = is_array($article['sections'] ?? null) ? $article['sections'] : [];

                if ($sections === []) {
                    $isExtra = $shown >= $previewLimit;
                    if ($isExtra) {
                        $refsHiddenCount++;
                    } else {
                        $shown++;
                    }
                    ?>
                    <li class="ref-group<?= $isExtra ? ' ref-extra' : '' ?>">
                        <div class="ref-article-title">
                            <?php if ($articleLink !== ''): ?>
                                <a href="<?= h($articleLink) ?>" target="_blank" rel="noopener noreferrer">
                                    <?= h($articleTitle !== '' ? $articleTitle : 'Source') ?>
                                </a>
                            <?php else: ?>
                                <?= h($articleTitle !== '' ? $articleTitle : 'Source') ?>
                            <?php endif; ?>
                        </div>
                    </li>
                    <?php
                    continue;
                }

                $articleIsExtra = $shown >= $previewLimit;
                ?>
                <li class="ref-group<?= $articleIsExtra ? ' ref-extra' : '' ?>">
                    <div class="ref-article-title">
                        <?php if ($articleLink !== ''): ?>
                            <a href="<?= h($articleLink) ?>" target="_blank" rel="noopener noreferrer">
                                <?= h($articleTitle !== '' ? $articleTitle : 'Source') ?>
                            </a>
                        <?php else: ?>
                            <?= h($articleTitle !== '' ? $articleTitle : 'Source') ?>
                        <?php endif; ?>
                    </div>
                    <ul class="ref-sections">
                        <?php foreach ($sections as $section): ?>
                            <?php
                            $sectionIsExtra = $shown >= $previewLimit;
                            if ($sectionIsExtra) {
                                $refsHiddenCount++;
                            } else {
                                $shown++;
                            }
                            ?>
                            <li class="<?= $sectionIsExtra ? 'ref-extra' : '' ?>">
                                <a href="<?= h((string) ($section['link'] ?? '#')) ?>" target="_blank" rel="noopener noreferrer">
                                    <?= h((string) ($section['title'] ?? 'Source')) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </li>
            <?php endforeach; ?>
        </ul>
    </li>
<?php elseif ($isArticleGrouped):
    $articleTitle = trim((string) ($ref['article_title'] ?? 'Source'));
    $articleLink = trim((string) ($ref['article_link'] ?? ''));
    $sections = $ref['sections'];
    $legacyIndex = (int) ($refListIndex ?? 0);
    $extraClass = $legacyIndex >= $previewLimit ? ' ref-extra' : '';
    if ($extraClass !== '') {
        $refsHiddenCount++;
    }
?>
    <li class="ref-group<?= $extraClass ?>">
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
<?php else:
    $legacyIndex = (int) ($refListIndex ?? 0);
    $extraClass = $legacyIndex >= $previewLimit ? ' ref-extra' : '';
    if ($extraClass !== '') {
        $refsHiddenCount++;
    }
?>
    <li class="<?= trim($extraClass) ?>">
        <a href="<?= h((string) ($ref['link'] ?? '#')) ?>" target="_blank" rel="noopener noreferrer">
            <?= h((string) ($ref['title'] ?? 'Source')) ?>
        </a>
    </li>
<?php endif; ?>
