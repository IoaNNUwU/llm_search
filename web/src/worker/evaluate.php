<?php

declare(strict_types=1);

/**
 * Background project evaluator.
 * Usage: php evaluate.php <project_id>
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$projectId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($projectId < 1) {
    fwrite(STDERR, "Usage: php evaluate.php <project_id>\n");
    exit(1);
}

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/ollama.php';
require_once __DIR__ . '/../lib/markdown.php';

final class EvaluationCancelledException extends RuntimeException
{
}

const INGEST_BATCH_MAX_FILES = 50;
const INGEST_BATCH_MAX_BYTES = 16 * 1024 * 1024;

$pdo = db();

$project = $pdo->prepare('SELECT * FROM projects WHERE id = :id');
$project->execute(['id' => $projectId]);
$row = $project->fetch();
if (!$row) {
    fwrite(STDERR, "Project {$projectId} not found\n");
    exit(1);
}

$eval = $pdo->prepare(
    'SELECT * FROM project_evaluations WHERE project_id = :id ORDER BY id DESC LIMIT 1'
);
$eval->execute(['id' => $projectId]);
$evaluation = $eval->fetch();
if (!$evaluation) {
    fwrite(STDERR, "No evaluation row for project {$projectId}\n");
    exit(1);
}

$evaluationId = (int) $evaluation['id'];
$storageDir = projects_path() . '/' . $projectId;
$projectType = project_type((string) $row['project_type']);

function update_evaluation(PDO $pdo, int $id, array $fields): void
{
    $sets = [];
    $params = ['id' => $id];
    foreach ($fields as $key => $value) {
        $sets[] = "{$key} = :{$key}";
        $params[$key] = $value;
    }
    $sets[] = 'updated_at = NOW()';
    $sql = 'UPDATE project_evaluations SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function assert_not_cancelled(PDO $pdo, int $evaluationId): void
{
    $stmt = $pdo->prepare('SELECT status FROM project_evaluations WHERE id = :id');
    $stmt->execute(['id' => $evaluationId]);
    $status = $stmt->fetchColumn();
    if ($status === 'cancelled') {
        throw new EvaluationCancelledException('Evaluation cancelled');
    }
}

function detail_preview(string $text, int $max = 4000): string
{
    $text = trim($text);
    if (mb_strlen($text, 'UTF-8') <= $max) {
        return $text;
    }
    return mb_substr($text, 0, $max, 'UTF-8') . "\n…";
}

/**
 * Keep transactions bounded by both file count and source size.
 *
 * @param list<array{rel_path: string, file_index: int}> $entries
 * @return list<list<array{rel_path: string, file_index: int}>>
 */
function build_ingest_batches(array $entries, string $storageDir): array
{
    $batches = [];
    $batch = [];
    $batchBytes = 0;

    foreach ($entries as $entry) {
        $size = filesize($storageDir . '/' . $entry['rel_path']);
        $size = $size === false ? 0 : max(0, (int) $size);
        $wouldOverflow = $batch !== [] && (
            count($batch) >= INGEST_BATCH_MAX_FILES
            || $batchBytes + $size > INGEST_BATCH_MAX_BYTES
        );
        if ($wouldOverflow) {
            $batches[] = $batch;
            $batch = [];
            $batchBytes = 0;
        }

        $batch[] = $entry;
        $batchBytes += $size;
    }

    if ($batch !== []) {
        $batches[] = $batch;
    }
    return $batches;
}

function article_embed_text(string $relPath, string $title, string $description, string $content): string
{
    return implode("\n", [
        basename(str_replace('\\', '/', $relPath)),
        $title,
        $description,
        mb_substr($content, 0, 4000, 'UTF-8'),
    ]);
}

function section_embed_text(
    string $relPath,
    ?string $heading,
    string $title,
    string $description,
    string $content
): string {
    return implode("\n", array_filter([
        $heading !== null && $heading !== '' ? $heading : null,
        basename(str_replace('\\', '/', $relPath)),
        $title,
        $description,
        $content,
    ], static fn ($part): bool => $part !== null && $part !== ''));
}

function ensure_embeddings_for_article(
    PDO $pdo,
    int $articleId,
    string $relPath,
    string $model,
    callable $assertNotCancelled
): void {
    $assertNotCancelled();

    $articleStmt = $pdo->prepare('SELECT title, description, link FROM articles WHERE id = :id');
    $articleStmt->execute(['id' => $articleId]);
    $article = $articleStmt->fetch();
    if (!$article) {
        return;
    }

    $sections = $pdo->prepare(
        'SELECT id, title, description, content FROM articles_sections WHERE article_id = :id ORDER BY id'
    );
    $sections->execute(['id' => $articleId]);
    $sectionRows = $sections->fetchAll();

    $pending = [];
    $hasArticleEmbed = $pdo->prepare(
        'SELECT 1 FROM article_embeddings WHERE article_id = :id AND model = :model'
    );
    $hasArticleEmbed->execute(['id' => $articleId, 'model' => $model]);
    if (!$hasArticleEmbed->fetchColumn()) {
        $content = '';
        foreach ($sectionRows as $part) {
            $content .= (string) $part['content'] . "\n\n";
        }
        $pending[] = [
            'kind' => 'article',
            'id' => $articleId,
            'text' => article_embed_text(
                $relPath,
                (string) $article['title'],
                (string) $article['description'],
                $content
            ),
        ];
    }

    $hasSectionEmbed = $pdo->prepare(
        'SELECT 1 FROM article_section_embeddings WHERE section_id = :id AND model = :model'
    );
    foreach ($sectionRows as $section) {
        $sectionId = (int) $section['id'];
        $hasSectionEmbed->execute(['id' => $sectionId, 'model' => $model]);
        if ($hasSectionEmbed->fetchColumn()) {
            continue;
        }
        $heading = trim((string) ($section['title'] ?? ''));
        $pending[] = [
            'kind' => 'section',
            'id' => $sectionId,
            'text' => section_embed_text(
                $relPath,
                $heading !== '' ? $heading : null,
                (string) ($section['title'] ?? ''),
                (string) ($section['description'] ?? ''),
                (string) $section['content']
            ),
        ];
    }

    if ($pending === []) {
        return;
    }

    $assertNotCancelled();
    $embeddings = ollama_embed_batch(array_column($pending, 'text'));
    $insertArticle = $pdo->prepare(
        'INSERT INTO article_embeddings (article_id, model, embedding)
         VALUES (:article_id, :model, CAST(:embedding AS vector))
         ON CONFLICT (article_id, model) DO NOTHING'
    );
    $insertSection = $pdo->prepare(
        'INSERT INTO article_section_embeddings (section_id, model, embedding)
         VALUES (:section_id, :model, CAST(:embedding AS vector))
         ON CONFLICT (section_id, model) DO NOTHING'
    );

    foreach ($pending as $i => $item) {
        $assertNotCancelled();
        $vector = embedding_to_sql($embeddings[$i]);
        if ($item['kind'] === 'article') {
            $insertArticle->execute([
                'article_id' => $item['id'],
                'model' => $model,
                'embedding' => $vector,
            ]);
            continue;
        }
        $insertSection->execute([
            'section_id' => $item['id'],
            'model' => $model,
            'embedding' => $vector,
        ]);
    }
}

function find_article_id_by_link(PDO $pdo, int $projectId, string $link): ?int
{
    $stmt = $pdo->prepare('SELECT id FROM articles WHERE project_id = :project_id AND link = :link');
    $stmt->execute(['project_id' => $projectId, 'link' => $link]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

/**
 * @return array<string, true>
 */
function fetch_fully_indexed_article_links(PDO $pdo, int $projectId, string $model): array
{
    $stmt = $pdo->prepare(
        'SELECT a.link
         FROM articles a
         WHERE a.project_id = :project_id
           AND EXISTS (
               SELECT 1
               FROM article_embeddings ae
               WHERE ae.article_id = a.id AND ae.model = :article_model
           )
           AND NOT EXISTS (
               SELECT 1
               FROM articles_sections s
               LEFT JOIN article_section_embeddings se
                 ON se.section_id = s.id AND se.model = :section_model
               WHERE s.article_id = a.id AND se.id IS NULL
           )'
    );
    $stmt->execute([
        'project_id' => $projectId,
        'article_model' => $model,
        'section_model' => $model,
    ]);

    $links = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $link) {
        $links[(string) $link] = true;
    }
    return $links;
}

try {
    if (($evaluation['status'] ?? '') === 'cancelled') {
        throw new EvaluationCancelledException('Evaluation cancelled');
    }

    if (!is_dir($storageDir)) {
        throw new RuntimeException("Project storage missing: {$storageDir}");
    }

    $files = collect_markdown_files($storageDir, $projectType);
    $baseUrl = (string) $row['base_url'];
    $fileTotal = count($files);
    $embedModel = ollama_embed_model();
    $searchableLinks = fetch_indexed_article_links($pdo, $projectId);
    $fullyIndexedLinks = fetch_fully_indexed_article_links($pdo, $projectId, $embedModel);
    $searchable = 0;
    $processed = 0;
    foreach ($files as $relPath) {
        $link = $projectType->articleLink($baseUrl, $relPath);
        if (isset($searchableLinks[$link])) {
            $searchable++;
        }
        if (isset($fullyIndexedLinks[$link])) {
            $processed++;
        }
    }

    if ($searchable > 0 || $processed > 0) {
        append_evaluation_event($projectId, [
            'phase' => 'resume',
            'message' => 'Resuming project indexing',
            'text' => null,
        ]);
    } else {
        reset_evaluation_events($projectId);
    }

    update_evaluation($pdo, $evaluationId, [
        'status' => 'processing',
        'total_files' => $fileTotal,
        'searchable_files' => $searchable,
        'processed_files' => $processed,
        'current_file' => null,
        'current_phase' => 'ingest',
        'current_section' => null,
        'total_sections' => null,
        'current_detail' => 'Preparing files for full-text search',
        'error' => null,
    ]);

    $insertArticle = $pdo->prepare(
        'INSERT INTO articles (project_id, title, description, link)
         VALUES (:project_id, :title, :description, :link)
         RETURNING id'
    );
    $insertSection = $pdo->prepare(
        'INSERT INTO articles_sections (article_id, title, description, content, link)
         VALUES (:article_id, :title, :description, :content, :link)
         RETURNING id'
    );
    $insertEnrichmentJob = $pdo->prepare(
        'INSERT INTO article_enrichment_jobs (article_id)
         VALUES (:article_id)
         ON CONFLICT (article_id) DO NOTHING'
    );

    // Phase 1: prepare Markdown outside a transaction, then commit bounded
    // batches. Every committed batch becomes immediately available to FTS.
    $pendingIngest = [];
    foreach ($files as $fileIndex => $relPath) {
        $link = $projectType->articleLink($baseUrl, $relPath);
        if (isset($searchableLinks[$link])) {
            continue;
        }
        $pendingIngest[] = [
            'rel_path' => $relPath,
            'file_index' => $fileIndex,
        ];
    }

    $ingestBatches = build_ingest_batches($pendingIngest, $storageDir);
    $ingestBatchTotal = count($ingestBatches);
    foreach ($ingestBatches as $batchIndex => $batch) {
        assert_not_cancelled($pdo, $evaluationId);

        $firstEntry = $batch[0];
        $lastEntry = $batch[count($batch) - 1];
        $firstFileNumber = $firstEntry['file_index'] + 1;
        $lastFileNumber = $lastEntry['file_index'] + 1;
        $batchNumber = $batchIndex + 1;
        $batchLabel = "Batch {$batchNumber}/{$ingestBatchTotal}: files {$firstFileNumber}–{$lastFileNumber}";
        update_evaluation($pdo, $evaluationId, [
            'current_file' => $firstEntry['rel_path'],
            'current_phase' => 'ingest',
            'current_section' => null,
            'total_sections' => null,
            'current_detail' => $batchLabel,
        ]);
        append_evaluation_event($projectId, [
            'phase' => 'ingest',
            'file' => $firstEntry['rel_path'],
            'file_index' => $firstFileNumber,
            'file_total' => $fileTotal,
            'batch_index' => $batchNumber,
            'batch_total' => $ingestBatchTotal,
            'batch_files' => count($batch),
            'message' => "Adding {$firstFileNumber}–{$lastFileNumber} to full-text search",
            'text' => null,
        ]);

        $preparedFiles = [];
        $batchSectionTotal = 0;
        foreach ($batch as $entry) {
            assert_not_cancelled($pdo, $evaluationId);
            $relPath = $entry['rel_path'];
            $content = file_get_contents($storageDir . '/' . $relPath);
            if ($content === false) {
                throw new RuntimeException("Cannot read {$relPath}");
            }

            $sections = split_markdown_sections($content);
            $preferredTitle = $sections[0]['heading'] ?? pathinfo($relPath, PATHINFO_FILENAME);
            $sectionMetas = [];
            foreach ($sections as $section) {
                $sectionMetas[] = heuristic_title_description($section['content'], $section['heading']);
            }
            $batchSectionTotal += count($sections);
            $preparedFiles[] = [
                'rel_path' => $relPath,
                'link' => $projectType->articleLink($baseUrl, $relPath),
                'meta' => heuristic_title_description($content, $preferredTitle),
                'sections' => $sections,
                'section_metas' => $sectionMetas,
            ];
        }

        assert_not_cancelled($pdo, $evaluationId);
        $pdo->beginTransaction();
        try {
            foreach ($preparedFiles as $prepared) {
                $insertArticle->execute([
                    'project_id' => $projectId,
                    'title' => $prepared['meta']['title'],
                    'description' => $prepared['meta']['description'],
                    'link' => $prepared['link'],
                ]);
                $articleId = (int) $insertArticle->fetchColumn();
                $insertEnrichmentJob->execute(['article_id' => $articleId]);

                $lastAnchor = null;
                foreach ($prepared['sections'] as $sectionIndex => $section) {
                    if ($section['anchor'] !== null) {
                        $lastAnchor = $section['anchor'];
                    }
                    $anchor = $section['anchor'] ?? $lastAnchor;
                    $sectionMeta = $prepared['section_metas'][$sectionIndex];
                    $insertSection->execute([
                        'article_id' => $articleId,
                        'title' => $sectionMeta['title'],
                        'description' => $sectionMeta['description'],
                        'content' => $section['content'],
                        'link' => section_link($prepared['link'], $anchor),
                    ]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $searchable += count($preparedFiles);
        foreach ($preparedFiles as $prepared) {
            $searchableLinks[$prepared['link']] = true;
        }
        update_evaluation($pdo, $evaluationId, [
            'searchable_files' => $searchable,
            'current_file' => $lastEntry['rel_path'],
            'total_sections' => $batchSectionTotal,
            'current_detail' => "{$batchLabel} committed",
        ]);
    }

    // Phase 2: enrich searchable files with embeddings. Full-text search remains
    // available while this slower phase is running.
    foreach ($files as $fileIndex => $relPath) {
        assert_not_cancelled($pdo, $evaluationId);

        $link = $projectType->articleLink($baseUrl, $relPath);
        if (isset($fullyIndexedLinks[$link])) {
            continue;
        }

        $articleId = find_article_id_by_link($pdo, $projectId, $link);
        if ($articleId === null) {
            continue;
        }

        update_evaluation($pdo, $evaluationId, [
            'current_file' => $relPath,
            'current_phase' => 'embed',
            'current_section' => null,
            'total_sections' => null,
            'current_detail' => "Creating embeddings for model {$embedModel}",
        ]);
        append_evaluation_event($projectId, [
            'phase' => 'embed',
            'file' => $relPath,
            'file_index' => $fileIndex + 1,
            'file_total' => $fileTotal,
            'message' => 'Creating final vector index',
            'text' => null,
        ]);

        ensure_embeddings_for_article(
            $pdo,
            $articleId,
            $relPath,
            $embedModel,
            static fn () => assert_not_cancelled($pdo, $evaluationId)
        );

        $processed++;
        $fullyIndexedLinks[$link] = true;
        update_evaluation($pdo, $evaluationId, [
            'processed_files' => $processed,
        ]);
    }

    assert_not_cancelled($pdo, $evaluationId);

    update_evaluation($pdo, $evaluationId, [
        'status' => 'completed',
        'current_file' => null,
        'current_phase' => null,
        'current_section' => null,
        'total_sections' => null,
        'current_detail' => null,
        'searchable_files' => $searchable,
        'processed_files' => $processed,
    ]);
    append_evaluation_event($projectId, [
        'phase' => 'done',
        'message' => "Evaluation completed ({$processed} files)",
        'text' => null,
    ]);

    @unlink(evaluation_pid_path($projectId));
    fwrite(STDOUT, "Evaluation completed for project {$projectId} ({$processed} files)\n");
    exit(0);
} catch (EvaluationCancelledException $e) {
    @unlink(evaluation_pid_path($projectId));
    append_evaluation_event($projectId, [
        'phase' => 'cancelled',
        'message' => 'Evaluation cancelled',
        'text' => null,
    ]);
    fwrite(STDOUT, "Evaluation cancelled for project {$projectId}\n");
    exit(0);
} catch (Throwable $e) {
    $statusStmt = $pdo->prepare('SELECT status FROM project_evaluations WHERE id = :id');
    $statusStmt->execute(['id' => $evaluationId]);
    if ($statusStmt->fetchColumn() !== 'cancelled') {
        update_evaluation($pdo, $evaluationId, [
            'status' => 'failed',
            'error' => $e->getMessage(),
            'current_phase' => null,
            'current_detail' => null,
        ]);
        append_evaluation_event($projectId, [
            'phase' => 'failed',
            'message' => $e->getMessage(),
            'text' => null,
        ]);
    }
    @unlink(evaluation_pid_path($projectId));
    fwrite(STDERR, 'Evaluation failed: ' . $e->getMessage() . "\n");
    exit(1);
}
