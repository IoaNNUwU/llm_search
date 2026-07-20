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

try {
    if (($evaluation['status'] ?? '') === 'cancelled') {
        throw new EvaluationCancelledException('Evaluation cancelled');
    }

    if (!is_dir($storageDir)) {
        throw new RuntimeException("Project storage missing: {$storageDir}");
    }

    reset_evaluation_events($projectId);

    $files = collect_markdown_files($storageDir);
    update_evaluation($pdo, $evaluationId, [
        'status' => 'processing',
        'total_files' => count($files),
        'processed_files' => 0,
        'current_file' => null,
        'current_phase' => null,
        'current_section' => null,
        'total_sections' => null,
        'current_detail' => null,
        'error' => null,
    ]);

    // Clear previous articles for re-runs.
    $articleIds = $pdo->prepare('SELECT id FROM articles WHERE project_id = :id');
    $articleIds->execute(['id' => $projectId]);
    $ids = $articleIds->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $pdo->exec("DELETE FROM articles_sections WHERE article_id IN ({$in})");
        $pdo->exec("DELETE FROM articles WHERE project_id = {$projectId}");
    }

    $insertArticle = $pdo->prepare(
        'INSERT INTO articles (project_id, title, description, link, embedding)
         VALUES (:project_id, :title, :description, :link, CAST(:embedding AS vector))
         RETURNING id'
    );
    $insertSection = $pdo->prepare(
        'INSERT INTO articles_sections (article_id, title, description, content, link, embedding)
         VALUES (:article_id, :title, :description, :content, :link, CAST(:embedding AS vector))'
    );

    $baseUrl = (string) $row['base_url'];
    $processed = 0;
    $fileTotal = count($files);

    foreach ($files as $fileIndex => $relPath) {
        assert_not_cancelled($pdo, $evaluationId);

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
        $embedding = ollama_embed(implode("\n", [
            basename(str_replace('\\', '/', $relPath)),
            $meta['title'],
            $meta['description'],
            mb_substr($content, 0, 4000, 'UTF-8'),
        ]));
        $link = project_file_link($baseUrl, $relPath);

        $insertArticle->execute([
            'project_id' => $projectId,
            'title' => $meta['title'],
            'description' => $meta['description'],
            'link' => $link,
            'embedding' => embedding_to_sql($embedding),
        ]);
        $articleId = (int) $insertArticle->fetchColumn();

        $sections = split_markdown_sections($content);
        $sectionTotal = count($sections);
        $lastAnchor = null;
        foreach ($sections as $sectionIndex => $section) {
            assert_not_cancelled($pdo, $evaluationId);

            if ($section['anchor'] !== null) {
                $lastAnchor = $section['anchor'];
            }
            $anchor = $section['anchor'] ?? $lastAnchor;
            $sectionText = detail_preview($section['content']);
            $heading = $section['heading'];

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
                    ? "Evaluating section «{$heading}»"
                    : 'Evaluating paragraph/section',
                'text' => $sectionText,
            ]);

            $sectionMeta = llm_title_description(
                $section['content'],
                'section' . ($heading ? " «{$heading}»" : '') . " in {$relPath}"
            );
            assert_not_cancelled($pdo, $evaluationId);
            $sectionEmbed = ollama_embed(implode("\n", array_filter([
                $heading !== null && $heading !== '' ? $heading : null,
                basename(str_replace('\\', '/', $relPath)),
                $sectionMeta['title'],
                $sectionMeta['description'],
                $section['content'],
            ], static fn ($part): bool => $part !== null && $part !== '')));

            $insertSection->execute([
                'article_id' => $articleId,
                'title' => $sectionMeta['title'],
                'description' => $sectionMeta['description'],
                'content' => $section['content'],
                'link' => section_link($link, $anchor),
                'embedding' => embedding_to_sql($sectionEmbed),
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
