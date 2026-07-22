<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/ollama.php';
require_once __DIR__ . '/../lib/markdown.php';

const ENRICHMENT_LOCK_ID = 731942001;
const ENRICHMENT_TEXT_LIMIT = 24000;

/** @var array<int, array<string, string>> */
$fileMapsByProject = [];

/**
 * @return array{file:?string, project_type:string, base_url:string}
 */
function resolve_enrichment_file(PDO $pdo, array $article): array
{
    global $fileMapsByProject;

    $projectId = (int) $article['project_id'];
    $projectStmt = $pdo->prepare('SELECT base_url, project_type FROM projects WHERE id = :id');
    $projectStmt->execute(['id' => $projectId]);
    $project = $projectStmt->fetch();
    if (!$project) {
        return ['file' => null, 'project_type' => '', 'base_url' => ''];
    }

    $projectType = project_type((string) $project['project_type']);
    $baseUrl = (string) $project['base_url'];
    if (!isset($fileMapsByProject[$projectId])) {
        $files = collect_markdown_files(projects_path() . '/' . $projectId, $projectType);
        $map = [];
        foreach ($files as $relPath) {
            foreach ($projectType->articleLinkVariants($baseUrl, $relPath) as $link) {
                $map[$link] = $relPath;
            }
        }
        $fileMapsByProject[$projectId] = $map;
    }

    $link = (string) $article['link'];
    return [
        'file' => $fileMapsByProject[$projectId][$link] ?? null,
        'project_type' => $projectType->key(),
        'base_url' => $baseUrl,
    ];
}

function log_enrichment_event(int $projectId, array $event): void
{
    append_evaluation_event($projectId, $event);
    $file = (string) ($event['file'] ?? '');
    $message = (string) ($event['message'] ?? '');
    $priority = (int) ($event['priority_score'] ?? 0);
    $line = sprintf(
        '[project %d] %s%s%s',
        $projectId,
        $message,
        $file !== '' ? " · {$file}" : '',
        $priority > 0 ? " · priority={$priority}" : ''
    );
    fwrite(STDOUT, $line . "\n");
}

/**
 * ingest: Qwen is forbidden; embed: priority jobs only; idle: all jobs.
 */
function enrichment_pipeline_mode(PDO $pdo): string
{
    $rows = $pdo->query(
        'SELECT pe.status, pe.current_phase
         FROM project_evaluations pe
         INNER JOIN (
             SELECT project_id, MAX(id) AS id
             FROM project_evaluations
             GROUP BY project_id
         ) latest ON latest.id = pe.id
         WHERE pe.status IN (\'pending\', \'processing\')'
    )->fetchAll();

    $hasEmbed = false;
    foreach ($rows as $row) {
        $phase = (string) ($row['current_phase'] ?? '');
        if ($row['status'] === 'pending' || $phase === '' || $phase === 'ingest') {
            return 'ingest';
        }
        if ($phase === 'embed') {
            $hasEmbed = true;
        }
    }
    return $hasEmbed ? 'embed' : 'idle';
}

function recover_stale_enrichment_jobs(PDO $pdo): void
{
    $pdo->exec(
        "UPDATE article_enrichment_jobs
         SET status = 'pending', locked_at = NULL, updated_at = NOW()
         WHERE status = 'processing'"
    );
}

/**
 * @return array<string, mixed>|null
 */
function dequeue_enrichment_job(PDO $pdo, bool $priorityOnly): ?array
{
    $pdo->beginTransaction();
    try {
        $priorityClause = $priorityOnly ? 'AND priority_score > 0' : '';
        $sql = <<<SQL
            WITH candidate AS (
                SELECT article_id
                FROM article_enrichment_jobs
                WHERE status = 'pending'
                  AND run_after <= NOW()
                  {$priorityClause}
                ORDER BY
                    CASE WHEN priority_score > 0 THEN 0 ELSE 1 END,
                    priority_score DESC,
                    article_id
                FOR UPDATE SKIP LOCKED
                LIMIT 1
            )
            UPDATE article_enrichment_jobs j
            SET status = 'processing',
                attempts = attempts + 1,
                locked_at = NOW(),
                last_error = NULL,
                updated_at = NOW()
            FROM candidate
            WHERE j.article_id = candidate.article_id
            RETURNING j.*
        SQL;
        $job = $pdo->query($sql)->fetch();
        $pdo->commit();
        return $job ?: null;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * @return array{article:array<string,mixed>, source:string, content_hash:string}
 */
function load_enrichment_source(PDO $pdo, int $articleId): array
{
    $articleStmt = $pdo->prepare(
        'SELECT a.id, a.title, a.description, a.link, a.project_id, p.name AS project_name
         FROM articles a
         INNER JOIN projects p ON p.id = a.project_id
         WHERE a.id = :id'
    );
    $articleStmt->execute(['id' => $articleId]);
    $article = $articleStmt->fetch();
    if (!$article) {
        throw new RuntimeException("Article {$articleId} no longer exists");
    }

    $sectionsStmt = $pdo->prepare(
        'SELECT title, description, content
         FROM articles_sections
         WHERE article_id = :id
         ORDER BY id'
    );
    $sectionsStmt->execute(['id' => $articleId]);
    $sections = $sectionsStmt->fetchAll();

    $hash = hash_init('sha256');
    $header = "Project: {$article['project_name']}\n"
        . "Article: {$article['title']}\n"
        . "Description: {$article['description']}\n"
        . "Link: {$article['link']}\n";
    hash_update($hash, $header);
    foreach ($sections as $section) {
        hash_update($hash, (string) $section['content']);
    }

    $remaining = max(1000, ENRICHMENT_TEXT_LIMIT - mb_strlen($header, 'UTF-8'));
    $perSection = max(300, intdiv($remaining, max(1, count($sections))));
    $parts = [$header];
    foreach ($sections as $index => $section) {
        $heading = trim((string) ($section['title'] ?? ''));
        $content = trim((string) $section['content']);
        $parts[] = sprintf(
            "\n[Section %d: %s]\n%s",
            $index + 1,
            $heading !== '' ? $heading : 'Untitled',
            mb_substr($content, 0, $perSection, 'UTF-8')
        );
    }

    return [
        'article' => $article,
        'source' => mb_substr(implode('', $parts), 0, ENRICHMENT_TEXT_LIMIT, 'UTF-8'),
        'content_hash' => hash_final($hash),
    ];
}

/**
 * @return array{summary:string,topics:list<string>,keywords:list<string>,questions:list<string>,entities:list<string>}
 */
function generate_article_enrichment(string $source): array
{
    $system = <<<'PROMPT'
You analyze technical documentation for search enrichment.
Return valid JSON only with this schema:
{"summary":"grounded concise summary","topics":["..."],"keywords":["..."],"questions":["..."],"entities":["..."]}
Use only facts present in the source. Preserve important identifiers, API names and technical terms.
Generate likely search questions answered by the source. Do not use markdown.
PROMPT;
    $raw = ollama_chat("Analyze this article:\n\n{$source}", $system);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Qwen enrichment returned invalid JSON');
    }

    $toText = static function (mixed $value): string {
        if (is_array($value)) {
            $parts = [];
            array_walk_recursive($value, static function (mixed $part) use (&$parts): void {
                if (is_scalar($part)) {
                    $parts[] = trim((string) $part);
                }
            });
            return trim(implode(' ', array_filter($parts)));
        }
        return is_scalar($value) ? trim((string) $value) : '';
    };

    $summary = $toText($data['summary'] ?? $data['overview'] ?? $data['description'] ?? '');
    if ($summary === '') {
        throw new RuntimeException('Qwen enrichment returned no summary');
    }
    $normalize = static function (mixed $items, int $max) use ($toText): array {
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            $value = $toText($item);
            if ($value !== '' && !in_array($value, $out, true)) {
                $out[] = mb_substr($value, 0, 300, 'UTF-8');
            }
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    };

    return [
        'summary' => mb_substr($summary, 0, 2000, 'UTF-8'),
        'topics' => $normalize($data['topics'] ?? $data['tags'] ?? [], 20),
        'keywords' => $normalize($data['keywords'] ?? [], 30),
        'questions' => $normalize($data['questions'] ?? $data['queries'] ?? [], 20),
        'entities' => $normalize($data['entities'] ?? [], 30),
    ];
}

function enrichment_search_text(array $article, array $payload): string
{
    return implode("\n", array_filter([
        (string) $article['title'],
        (string) $article['description'],
        $payload['summary'],
        implode(' ', $payload['topics']),
        implode(' ', $payload['keywords']),
        implode("\n", $payload['questions']),
        implode(' ', $payload['entities']),
    ]));
}

function complete_enrichment_job(
    PDO $pdo,
    int $articleId,
    string $contentHash,
    array $payload,
    string $searchText,
    array $embedding
): void {
    $pdo->beginTransaction();
    try {
        $save = $pdo->prepare(
            'INSERT INTO article_enrichments
                (article_id, model, embed_model, content_hash, payload, search_text, embedding)
             VALUES
                (:article_id, :model, :embed_model, :content_hash, CAST(:payload AS jsonb),
                 :search_text, CAST(:embedding AS vector))
             ON CONFLICT (article_id) DO UPDATE SET
                model = EXCLUDED.model,
                embed_model = EXCLUDED.embed_model,
                content_hash = EXCLUDED.content_hash,
                payload = EXCLUDED.payload,
                search_text = EXCLUDED.search_text,
                embedding = EXCLUDED.embedding,
                updated_at = NOW()'
        );
        $save->execute([
            'article_id' => $articleId,
            'model' => ollama_model(),
            'embed_model' => ollama_embed_model(),
            'content_hash' => $contentHash,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'search_text' => $searchText,
            'embedding' => embedding_to_sql($embedding),
        ]);
        $done = $pdo->prepare(
            "UPDATE article_enrichment_jobs
             SET status = 'completed', locked_at = NULL, last_error = NULL, updated_at = NOW()
             WHERE article_id = :article_id"
        );
        $done->execute(['article_id' => $articleId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function fail_enrichment_job(PDO $pdo, array $job, Throwable $error): void
{
    $attempts = (int) $job['attempts'];
    $maxAttempts = (int) $job['max_attempts'];
    $status = $attempts >= $maxAttempts ? 'failed' : 'pending';
    $delay = min(900, 30 * (2 ** max(0, $attempts - 1)));
    $stmt = $pdo->prepare(
        "UPDATE article_enrichment_jobs
         SET status = :status,
             run_after = NOW() + CAST(:delay AS integer) * INTERVAL '1 second',
             locked_at = NULL,
             last_error = :error,
             updated_at = NOW()
         WHERE article_id = :article_id"
    );
    $stmt->execute([
        'status' => $status,
        'delay' => $delay,
        'error' => mb_substr($error->getMessage(), 0, 2000, 'UTF-8'),
        'article_id' => (int) $job['article_id'],
    ]);
}

$pdo = db();
$locked = (bool) $pdo->query('SELECT pg_try_advisory_lock(' . ENRICHMENT_LOCK_ID . ')')->fetchColumn();
if (!$locked) {
    fwrite(STDOUT, "Another enrichment worker is already running\n");
    exit(0);
}

recover_stale_enrichment_jobs($pdo);
fwrite(STDOUT, "Enrichment worker started\n");

while (true) {
    try {
        $mode = enrichment_pipeline_mode($pdo);
        if ($mode === 'ingest') {
            sleep(1);
            continue;
        }

        $job = dequeue_enrichment_job($pdo, $mode === 'embed');
        if ($job === null) {
            sleep($mode === 'embed' ? 1 : 3);
            continue;
        }

        $articleId = (int) $job['article_id'];
        $source = load_enrichment_source($pdo, $articleId);
        $resolved = resolve_enrichment_file($pdo, $source['article']);
        $projectId = (int) $source['article']['project_id'];
        $priority = (int) ($job['priority_score'] ?? 0);
        $fileLabel = $resolved['file'] ?? (string) $source['article']['link'];

        log_enrichment_event($projectId, [
            'phase' => 'enrich',
            'file' => $resolved['file'],
            'article_id' => $articleId,
            'title' => (string) $source['article']['title'],
            'priority_score' => $priority,
            'message' => $priority > 0
                ? 'Qwen enrichment (priority)'
                : 'Qwen enrichment',
            'text' => mb_substr((string) $source['article']['description'], 0, 400, 'UTF-8'),
        ]);

        $existing = $pdo->prepare(
            'SELECT 1 FROM article_enrichments
             WHERE article_id = :article_id AND content_hash = :content_hash'
        );
        $existing->execute([
            'article_id' => $articleId,
            'content_hash' => $source['content_hash'],
        ]);
        if ($existing->fetchColumn()) {
            $pdo->prepare(
                "UPDATE article_enrichment_jobs
                 SET status = 'completed', locked_at = NULL, updated_at = NOW()
                 WHERE article_id = :article_id"
            )->execute(['article_id' => $articleId]);
            log_enrichment_event($projectId, [
                'phase' => 'enrich_done',
                'file' => $resolved['file'],
                'article_id' => $articleId,
                'title' => (string) $source['article']['title'],
                'priority_score' => $priority,
                'message' => 'Qwen enrichment already up to date',
                'text' => null,
            ]);
            continue;
        }

        // Re-check immediately before the expensive call: ingest always wins.
        if (enrichment_pipeline_mode($pdo) === 'ingest') {
            $pdo->prepare(
                "UPDATE article_enrichment_jobs
                 SET status = 'pending', locked_at = NULL, updated_at = NOW()
                 WHERE article_id = :article_id"
            )->execute(['article_id' => $articleId]);
            sleep(1);
            continue;
        }

        $payload = generate_article_enrichment($source['source']);
        $searchText = enrichment_search_text($source['article'], $payload);
        $embedding = ollama_embed($searchText);
        complete_enrichment_job(
            $pdo,
            $articleId,
            $source['content_hash'],
            $payload,
            $searchText,
            $embedding
        );
        log_enrichment_event($projectId, [
            'phase' => 'enrich_done',
            'file' => $resolved['file'],
            'article_id' => $articleId,
            'title' => (string) $source['article']['title'],
            'priority_score' => $priority,
            'message' => 'Qwen enrichment completed',
            'text' => mb_substr((string) ($payload['summary'] ?? ''), 0, 400, 'UTF-8'),
        ]);
        fwrite(STDOUT, "Enriched article {$articleId} · {$fileLabel}\n");
    } catch (Throwable $e) {
        if (isset($job) && is_array($job)) {
            try {
                fail_enrichment_job($pdo, $job, $e);
                if (isset($projectId, $source, $resolved) && is_array($source) && is_array($resolved)) {
                    log_enrichment_event((int) $projectId, [
                        'phase' => 'enrich_failed',
                        'file' => $resolved['file'] ?? null,
                        'article_id' => (int) $job['article_id'],
                        'title' => (string) ($source['article']['title'] ?? ''),
                        'priority_score' => (int) ($job['priority_score'] ?? 0),
                        'message' => 'Qwen enrichment failed: ' . $e->getMessage(),
                        'text' => null,
                    ]);
                }
            } catch (Throwable $nested) {
                fwrite(STDERR, "Could not update failed job: {$nested->getMessage()}\n");
            }
        }
        fwrite(STDERR, "Enrichment error: {$e->getMessage()}\n");
        sleep(2);
    } finally {
        $job = null;
        unset($projectId, $source, $resolved);
    }
}
