<?php

declare(strict_types=1);

require_once __DIR__ . '/project_types.php';

/**
 * Promote articles that reached the final RAG result set.
 *
 * @param list<array<string, mixed>> $hits
 */
function record_article_search_hits(PDO $pdo, array $hits): void
{
    $articles = [];
    foreach ($hits as $hit) {
        $articleId = (int) ($hit['article_id'] ?? 0);
        if ($articleId < 1) {
            continue;
        }
        $sources = array_fill_keys((array) ($hit['search_sources'] ?? []), true);
        if (!isset($articles[$articleId])) {
            $articles[$articleId] = ['fulltext' => false, 'vector' => false];
        }
        $articles[$articleId]['fulltext'] = $articles[$articleId]['fulltext'] || isset($sources['fulltext']);
        $articles[$articleId]['vector'] = $articles[$articleId]['vector'] || isset($sources['vector']);
    }
    if ($articles === []) {
        return;
    }

    $stats = $pdo->prepare(
        'INSERT INTO article_search_stats
            (article_id, total_hits, fulltext_hits, vector_hits, last_hit_at)
         VALUES (:article_id, 1, :fulltext_hits, :vector_hits, NOW())
         ON CONFLICT (article_id) DO UPDATE SET
            total_hits = article_search_stats.total_hits + 1,
            fulltext_hits = article_search_stats.fulltext_hits + EXCLUDED.fulltext_hits,
            vector_hits = article_search_stats.vector_hits + EXCLUDED.vector_hits,
            last_hit_at = NOW(),
            updated_at = NOW()'
    );
    $promote = $pdo->prepare(
        'INSERT INTO article_enrichment_jobs (article_id, priority_score)
         VALUES (:article_id, 1)
         ON CONFLICT (article_id) DO UPDATE SET
            priority_score = article_enrichment_jobs.priority_score + 1,
            status = CASE
                WHEN article_enrichment_jobs.status = \'failed\'
                     AND article_enrichment_jobs.attempts < article_enrichment_jobs.max_attempts
                THEN \'pending\'
                ELSE article_enrichment_jobs.status
            END,
            run_after = CASE
                WHEN article_enrichment_jobs.status = \'failed\'
                THEN NOW()
                ELSE article_enrichment_jobs.run_after
            END,
            updated_at = NOW()
         WHERE article_enrichment_jobs.status IN (\'pending\', \'failed\')'
    );

    foreach ($articles as $articleId => $source) {
        $stats->execute([
            'article_id' => $articleId,
            'fulltext_hits' => $source['fulltext'] ? 1 : 0,
            'vector_hits' => $source['vector'] ? 1 : 0,
        ]);
        $promote->execute(['article_id' => $articleId]);
    }
}

/**
 * @return array{
 *   enriched_files:int,
 *   enrichment_pending_files:int,
 *   enrichment_percent:int,
 *   enrichment_slots:list<array{article_id:int,slot:int,status:string}>
 * }
 */
function project_enrichment_progress(PDO $pdo, int $projectId, int $totalFiles): array
{
    $counts = $pdo->prepare(
        'SELECT
            COUNT(a.id) AS total,
            COUNT(e.article_id) AS enriched,
            COUNT(*) FILTER (
                WHERE j.status IN (\'pending\', \'processing\')
                  AND e.article_id IS NULL
            ) AS pending
         FROM articles a
         LEFT JOIN article_enrichments e ON e.article_id = a.id
         LEFT JOIN article_enrichment_jobs j ON j.article_id = a.id
         WHERE a.project_id = :project_id'
    );
    $counts->execute(['project_id' => $projectId]);
    $row = $counts->fetch() ?: [];
    $total = max($totalFiles, (int) ($row['total'] ?? 0));
    $enriched = (int) ($row['enriched'] ?? 0);
    $pending = (int) ($row['pending'] ?? 0);

    $slotsStmt = $pdo->prepare(
        'WITH ranked AS (
            SELECT
                a.id AS article_id,
                ROW_NUMBER() OVER (
                    ORDER BY MD5(a.project_id::text || \':\' || a.id::text)
                ) AS slot,
                j.status AS job_status,
                j.priority_score,
                e.article_id AS enriched_id
            FROM articles a
            LEFT JOIN article_enrichment_jobs j ON j.article_id = a.id
            LEFT JOIN article_enrichments e ON e.article_id = a.id
            WHERE a.project_id = :project_id
         )
         SELECT
            article_id,
            slot,
            CASE
                WHEN enriched_id IS NOT NULL THEN \'completed\'
                WHEN job_status = \'processing\' THEN \'processing\'
                ELSE \'queued\'
            END AS status
         FROM ranked
         WHERE enriched_id IS NOT NULL
            OR job_status = \'processing\'
            OR (job_status = \'pending\' AND priority_score > 0)
         ORDER BY slot'
    );
    $slotsStmt->execute(['project_id' => $projectId]);
    $slots = array_map(
        static fn (array $slot): array => [
            'article_id' => (int) $slot['article_id'],
            'slot' => (int) $slot['slot'],
            'status' => (string) $slot['status'],
        ],
        $slotsStmt->fetchAll()
    );

    return [
        'enriched_files' => $enriched,
        'enrichment_pending_files' => $pending,
        'enrichment_percent' => $total > 0 ? (int) round(($enriched / $total) * 100) : 0,
        'enrichment_slots' => $slots,
    ];
}

/**
 * Map article links to relative markdown paths for the given project files.
 *
 * @param list<string> $files
 * @return array<string, string>
 */
function article_link_file_map(ProjectType $projectType, string $baseUrl, array $files): array
{
    $map = [];
    foreach ($files as $relPath) {
        foreach ($projectType->articleLinkVariants($baseUrl, $relPath) as $link) {
            $map[$link] = $relPath;
        }
    }
    return $map;
}

/**
 * Current Qwen enrichment job and upcoming queue for the eval-log UI.
 *
 * @param list<string> $files
 * @return array{
 *   enrichment_current: ?array{
 *     article_id:int,
 *     title:string,
 *     link:string,
 *     file:?string,
 *     priority_score:int,
 *     status:string,
 *     locked_at:?string
 *   },
 *   enrichment_queue: list<array{
 *     article_id:int,
 *     title:string,
 *     link:string,
 *     file:?string,
 *     priority_score:int,
 *     status:string
 *   }>
 * }
 */
function project_enrichment_activity(
    PDO $pdo,
    int $projectId,
    ProjectType $projectType,
    string $baseUrl,
    array $files,
    int $queueLimit = 12
): array {
    $linkMap = article_link_file_map($projectType, $baseUrl, $files);
    $limit = max(1, min(40, $queueLimit));

    $stmt = $pdo->prepare(
        'SELECT
            a.id AS article_id,
            a.title,
            a.link,
            j.status,
            j.priority_score,
            j.locked_at
         FROM article_enrichment_jobs j
         INNER JOIN articles a ON a.id = j.article_id
         LEFT JOIN article_enrichments e ON e.article_id = a.id
         WHERE a.project_id = :project_id
           AND e.article_id IS NULL
           AND (
                j.status = \'processing\'
                OR (j.status = \'pending\' AND j.run_after <= NOW())
           )
         ORDER BY
            CASE WHEN j.status = \'processing\' THEN 0 ELSE 1 END,
            CASE WHEN j.priority_score > 0 THEN 0 ELSE 1 END,
            j.priority_score DESC,
            a.id
         LIMIT :limit'
    );
    $stmt->bindValue('project_id', $projectId, PDO::PARAM_INT);
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $current = null;
    $queue = [];
    foreach ($stmt->fetchAll() as $row) {
        $item = [
            'article_id' => (int) $row['article_id'],
            'title' => (string) $row['title'],
            'link' => (string) $row['link'],
            'file' => $linkMap[(string) $row['link']] ?? null,
            'priority_score' => (int) $row['priority_score'],
            'status' => (string) $row['status'],
        ];
        if ($item['status'] === 'processing' && $current === null) {
            $item['locked_at'] = $row['locked_at'] !== null ? (string) $row['locked_at'] : null;
            $current = $item;
            continue;
        }
        $queue[] = $item;
    }

    return [
        'enrichment_current' => $current,
        'enrichment_queue' => $queue,
    ];
}
