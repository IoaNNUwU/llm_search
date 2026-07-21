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

try {
    if (($evaluation['status'] ?? '') === 'cancelled') {
        throw new EvaluationCancelledException('Evaluation cancelled');
    }

    if (!is_dir($storageDir)) {
        throw new RuntimeException("Project storage missing: {$storageDir}");
    }

    $articleCountStmt = $pdo->prepare('SELECT COUNT(*) FROM articles WHERE project_id = :id');
    $articleCountStmt->execute(['id' => $projectId]);
    $articleCount = (int) $articleCountStmt->fetchColumn();
    $resume = evaluation_is_resumable($evaluation, $articleCount);

    $files = collect_markdown_files($storageDir);
    $baseUrl = (string) $row['base_url'];
    $fileTotal = count($files);
    $embedModel = ollama_embed_model();

    if ($resume) {
        $currentFile = trim((string) ($evaluation['current_file'] ?? ''));
        if ($currentFile !== '') {
            delete_articles_for_file($pdo, $projectId, $baseUrl, $currentFile);
        }
        append_evaluation_event($projectId, [
            'phase' => 'resume',
            'message' => 'Resuming interrupted evaluation',
            'text' => null,
        ]);
    } else {
        reset_evaluation_events($projectId);
    }

    $indexedLinks = fetch_indexed_article_links($pdo, $projectId);

    $processed = 0;
    foreach ($files as $relPath) {
        if (is_file_indexed($indexedLinks, $baseUrl, $relPath)) {
            $processed++;
        }
    }

    update_evaluation($pdo, $evaluationId, [
        'status' => 'processing',
        'total_files' => $fileTotal,
        'processed_files' => $processed,
        'current_file' => null,
        'current_phase' => null,
        'current_section' => null,
        'total_sections' => null,
        'current_detail' => null,
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
    $insertArticleEmbed = $pdo->prepare(
        'INSERT INTO article_embeddings (article_id, model, embedding)
         VALUES (:article_id, :model, CAST(:embedding AS vector))
         ON CONFLICT (article_id, model) DO NOTHING'
    );
    $insertSectionEmbed = $pdo->prepare(
        'INSERT INTO article_section_embeddings (section_id, model, embedding)
         VALUES (:section_id, :model, CAST(:embedding AS vector))
         ON CONFLICT (section_id, model) DO NOTHING'
    );

    foreach ($files as $fileIndex => $relPath) {
        assert_not_cancelled($pdo, $evaluationId);

        $link = project_file_link($baseUrl, $relPath);
        $existingArticleId = find_article_id_by_link($pdo, $projectId, $link);
        if ($existingArticleId !== null) {
            update_evaluation($pdo, $evaluationId, [
                'current_file' => $relPath,
                'current_phase' => 'embed',
                'current_section' => null,
                'total_sections' => null,
                'current_detail' => "Ensuring embeddings for model {$embedModel}",
            ]);
            append_evaluation_event($projectId, [
                'phase' => 'file',
                'file' => $relPath,
                'file_index' => $fileIndex + 1,
                'file_total' => $fileTotal,
                'message' => 'Already indexed — ensuring embeddings',
                'text' => null,
            ]);
            ensure_embeddings_for_article(
                $pdo,
                $existingArticleId,
                $relPath,
                $embedModel,
                static fn () => assert_not_cancelled($pdo, $evaluationId)
            );
            update_evaluation($pdo, $evaluationId, [
                'processed_files' => $processed,
            ]);
            continue;
        }

        $fullPath = $storageDir . '/' . $relPath;
        $content = file_get_contents($fullPath);
        if ($content === false) {
            throw new RuntimeException("Cannot read {$relPath}");
        }

        $filePreview = detail_preview($content);
        update_evaluation($pdo, $evaluationId, [
            'current_file' => $relPath,
            'current_phase' => 'file',
            'current_section' => null,
            'total_sections' => null,
            'current_detail' => $filePreview,
        ]);
        append_evaluation_event($projectId, [
            'phase' => 'file',
            'file' => $relPath,
            'file_index' => $fileIndex + 1,
            'file_total' => $fileTotal,
            'message' => 'Evaluating whole file',
            'text' => $filePreview,
        ]);

        $meta = llm_title_description($content, "markdown file {$relPath}");
        assert_not_cancelled($pdo, $evaluationId);

        $sections = split_markdown_sections($content);
        $sectionTotal = count($sections);
        $sectionMetas = [];
        $embedTexts = [
            article_embed_text($relPath, $meta['title'], $meta['description'], $content),
        ];
        foreach ($sections as $section) {
            $sectionMeta = heuristic_title_description($section['content'], $section['heading']);
            $sectionMetas[] = $sectionMeta;
            $embedTexts[] = section_embed_text(
                $relPath,
                $section['heading'],
                $sectionMeta['title'],
                $sectionMeta['description'],
                $section['content']
            );
        }

        update_evaluation($pdo, $evaluationId, [
            'current_file' => $relPath,
            'current_phase' => 'embed',
            'current_section' => null,
            'total_sections' => $sectionTotal,
            'current_detail' => "Embedding {$sectionTotal} section(s) in batch",
        ]);
        append_evaluation_event($projectId, [
            'phase' => 'embed',
            'file' => $relPath,
            'file_index' => $fileIndex + 1,
            'file_total' => $fileTotal,
            'message' => "Batch embedding file + {$sectionTotal} section(s)",
            'text' => null,
        ]);

        assert_not_cancelled($pdo, $evaluationId);
        $embeddings = ollama_embed_batch($embedTexts);
        $articleEmbedding = $embeddings[0];
        $sectionEmbeddings = array_slice($embeddings, 1);

        $insertArticle->execute([
            'project_id' => $projectId,
            'title' => $meta['title'],
            'description' => $meta['description'],
            'link' => $link,
        ]);
        $articleId = (int) $insertArticle->fetchColumn();
        $insertArticleEmbed->execute([
            'article_id' => $articleId,
            'model' => $embedModel,
            'embedding' => embedding_to_sql($articleEmbedding),
        ]);

        $lastAnchor = null;
        foreach ($sections as $sectionIndex => $section) {
            assert_not_cancelled($pdo, $evaluationId);

            if ($section['anchor'] !== null) {
                $lastAnchor = $section['anchor'];
            }
            $anchor = $section['anchor'] ?? $lastAnchor;
            $sectionText = detail_preview($section['content']);
            $heading = $section['heading'];
            $sectionMeta = $sectionMetas[$sectionIndex];

            update_evaluation($pdo, $evaluationId, [
                'current_file' => $relPath,
                'current_phase' => 'section',
                'current_section' => $sectionIndex + 1,
                'total_sections' => $sectionTotal,
                'current_detail' => $sectionText,
            ]);
            append_evaluation_event($projectId, [
                'phase' => 'section',
                'file' => $relPath,
                'file_index' => $fileIndex + 1,
                'file_total' => $fileTotal,
                'section_index' => $sectionIndex + 1,
                'section_total' => $sectionTotal,
                'heading' => $heading,
                'message' => $heading
                    ? "Saving section «{$heading}»"
                    : 'Saving section',
                'text' => $sectionText,
            ]);

            $insertSection->execute([
                'article_id' => $articleId,
                'title' => $sectionMeta['title'],
                'description' => $sectionMeta['description'],
                'content' => $section['content'],
                'link' => section_link($link, $anchor),
            ]);
            $sectionId = (int) $insertSection->fetchColumn();
            $insertSectionEmbed->execute([
                'section_id' => $sectionId,
                'model' => $embedModel,
                'embedding' => embedding_to_sql($sectionEmbeddings[$sectionIndex]),
            ]);
        }

        $processed++;
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
