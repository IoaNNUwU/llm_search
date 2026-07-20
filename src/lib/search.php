<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ollama.php';

const RAG_SECTION_LIMIT = 5;
const RAG_REFERENCE_LIMIT = 20;
const RAG_CONTENT_CHARS = 1800;

/**
 * Keep a hit only if its cosine distance is within this multiple of the best hit.
 * pgvector <=> is cosine distance (lower = more similar).
 */
const RAG_DISTANCE_MAX_RATIO = 1.35;

/**
 * Absolute slack above the best distance so near-zero best hits still allow close neighbors.
 */
const RAG_DISTANCE_ABS_SLACK = 0.05;

/**
 * Load a project row by id, or null if missing.
 *
 * @return array{id:int, name:string, description:string, base_url:string}|null
 */
function get_project(PDO $pdo, int $projectId): ?array
{
    if ($projectId < 1) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, name, description, base_url FROM projects WHERE id = :id');
    $stmt->execute(['id' => $projectId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'description' => (string) $row['description'],
        'base_url' => (string) $row['base_url'],
    ];
}

/**
 * @param list<int> $projectIds
 * @return list<array{id:int, name:string, description:string, base_url:string}>
 */
function get_projects(PDO $pdo, array $projectIds): array
{
    $ids = array_values(array_unique(array_filter(
        array_map(static fn ($id): int => (int) $id, $projectIds),
        static fn (int $id): bool => $id > 0
    )));
    if ($ids === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, name, description, base_url FROM projects WHERE id IN ({$placeholders})");
    $stmt->execute($ids);
    $byId = [];
    foreach ($stmt->fetchAll() as $row) {
        $byId[(int) $row['id']] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'description' => (string) $row['description'],
            'base_url' => (string) $row['base_url'],
        ];
    }

    $out = [];
    foreach ($ids as $id) {
        if (isset($byId[$id])) {
            $out[] = $byId[$id];
        }
    }
    return $out;
}

/**
 * Parse project ids from a comma-separated POST value (or legacy single id).
 *
 * @return list<int>
 */
function parse_project_ids(mixed $raw): array
{
    if (is_array($raw)) {
        $parts = $raw;
    } else {
        $parts = preg_split('/\s*,\s*/', trim((string) $raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
    $ids = [];
    foreach ($parts as $part) {
        $id = (int) $part;
        if ($id > 0 && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }
    return $ids;
}

/**
 * Semantic search over indexed sections for one or more projects.
 *
 * @param list<int> $projectIds
 * @return list<array{
 *   id: int,
 *   title: string,
 *   description: string,
 *   content: string,
 *   link: string,
 *   article_title: string,
 *   article_link: string,
 *   project_id: int,
 *   project_name: string,
 *   distance: float
 * }>
 */
function search_project_sections(PDO $pdo, array|int $projectIds, string $query, int $limit = RAG_SECTION_LIMIT): array
{
    $query = trim($query);
    $ids = is_array($projectIds)
        ? array_values(array_unique(array_filter(array_map('intval', $projectIds), static fn (int $id): bool => $id > 0)))
        : (((int) $projectIds) > 0 ? [(int) $projectIds] : []);

    if ($ids === [] || $query === '') {
        return [];
    }

    $embeddingSql = embedding_to_sql(ollama_embed($query));
    $limit = max(1, min(20, $limit));

    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $sql = <<<SQL
        WITH q AS (
            SELECT CAST(? AS vector) AS v
        )
        SELECT
            s.id,
            COALESCE(NULLIF(TRIM(s.title), ''), a.title) AS title,
            COALESCE(s.description, '') AS description,
            s.content,
            s.link,
            a.title AS article_title,
            a.link AS article_link,
            a.project_id,
            p.name AS project_name,
            (s.embedding <=> q.v) AS distance
        FROM articles_sections s
        INNER JOIN articles a ON a.id = s.article_id
        INNER JOIN projects p ON p.id = a.project_id
        CROSS JOIN q
        WHERE a.project_id IN ({$placeholders})
        ORDER BY s.embedding <=> q.v
        LIMIT ?
    SQL;

    $stmt = $pdo->prepare($sql);
    $bind = array_merge([$embeddingSql], $ids, [$limit]);
    foreach ($bind as $i => $value) {
        $param = $i + 1;
        if ($i === count($bind) - 1) {
            $stmt->bindValue($param, (int) $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($param, $value);
        }
    }
    $stmt->execute();

    $rows = $stmt->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'description' => (string) $row['description'],
            'content' => (string) $row['content'],
            'link' => (string) $row['link'],
            'article_title' => (string) $row['article_title'],
            'article_link' => (string) $row['article_link'],
            'project_id' => (int) $row['project_id'],
            'project_name' => (string) $row['project_name'],
            'distance' => (float) $row['distance'],
        ];
    }
    return $out;
}

/**
 * Drop hits that are much worse than the nearest match.
 * Expects hits already ordered by ascending cosine distance.
 *
 * @param list<array<string, mixed>> $hits
 * @return list<array<string, mixed>>
 */
function filter_hits_by_top_distance(array $hits): array
{
    if ($hits === []) {
        return [];
    }

    $best = isset($hits[0]['distance']) ? (float) $hits[0]['distance'] : 0.0;
    if ($best < 0) {
        $best = 0.0;
    }

    $cutoff = max($best * RAG_DISTANCE_MAX_RATIO, $best + RAG_DISTANCE_ABS_SLACK);

    $out = [];
    foreach ($hits as $hit) {
        $distance = isset($hit['distance']) ? (float) $hit['distance'] : PHP_FLOAT_MAX;
        if ($distance > $cutoff) {
            break;
        }
        $out[] = $hit;
    }

    return $out;
}

/**
 * Build citation list for the UI, grouped by project then by article (file).
 * Items are ordered by semantic distance (most relevant first).
 *
 * @param list<array<string, mixed>> $hits
 * @return list<array{
 *   project_name: string,
 *   articles: list<array{
 *     article_title: string,
 *     article_link: string,
 *     sections: list<array{title: string, link: string}>
 *   }>
 * }>
 */
function references_from_hits(array $hits): array
{
    $projects = [];
    $seenLinks = [];

    foreach ($hits as $hit) {
        $link = trim((string) ($hit['link'] ?? ''));
        if ($link === '' || isset($seenLinks[$link])) {
            continue;
        }
        $seenLinks[$link] = true;

        $distance = isset($hit['distance']) ? (float) $hit['distance'] : PHP_FLOAT_MAX;
        $projectName = trim((string) ($hit['project_name'] ?? ''));
        $projectId = (int) ($hit['project_id'] ?? 0);
        $projectKey = $projectId > 0
            ? 'id:' . $projectId
            : ($projectName !== '' ? 'name:' . $projectName : 'unknown');

        if (!isset($projects[$projectKey])) {
            $projects[$projectKey] = [
                'project_name' => $projectName !== '' ? $projectName : 'Project',
                'distance' => $distance,
                'articles' => [],
            ];
        } else {
            $projects[$projectKey]['distance'] = min($projects[$projectKey]['distance'], $distance);
        }

        $articleTitle = trim((string) ($hit['article_title'] ?? ''));
        $articleLink = trim((string) ($hit['article_link'] ?? ''));
        $articleKey = $articleLink !== '' ? $articleLink : ($articleTitle !== '' ? $articleTitle : $link);

        if (!isset($projects[$projectKey]['articles'][$articleKey])) {
            $projects[$projectKey]['articles'][$articleKey] = [
                'article_title' => $articleTitle !== '' ? $articleTitle : (string) ($hit['title'] ?? 'Reference'),
                'article_link' => $articleLink,
                'distance' => $distance,
                'sections' => [],
            ];
        } else {
            $projects[$projectKey]['articles'][$articleKey]['distance'] = min(
                $projects[$projectKey]['articles'][$articleKey]['distance'],
                $distance
            );
        }

        $sectionTitle = (string) ($hit['title'] ?? 'Reference');
        // Skip a redundant section row when it is the same as the article itself.
        if (
            $articleLink !== ''
            && $link === $articleLink
            && ($articleTitle === '' || $sectionTitle === $articleTitle)
        ) {
            continue;
        }

        $projects[$projectKey]['articles'][$articleKey]['sections'][] = [
            'title' => $sectionTitle,
            'link' => $link,
            'distance' => $distance,
        ];
    }

    $byDistance = static fn (array $a, array $b): int => $a['distance'] <=> $b['distance'];

    uasort($projects, $byDistance);

    $refs = [];
    foreach ($projects as $project) {
        $articles = array_values($project['articles']);
        usort($articles, $byDistance);

        $outArticles = [];
        foreach ($articles as $article) {
            usort($article['sections'], $byDistance);
            $sections = [];
            foreach ($article['sections'] as $section) {
                $sections[] = [
                    'title' => $section['title'],
                    'link' => $section['link'],
                ];
            }
            if ($sections === [] && $article['article_link'] === '') {
                continue;
            }
            $outArticles[] = [
                'article_title' => $article['article_title'],
                'article_link' => $article['article_link'],
                'sections' => $sections,
            ];
        }
        if ($outArticles === []) {
            continue;
        }
        $refs[] = [
            'project_name' => $project['project_name'],
            'articles' => $outArticles,
        ];
    }

    return $refs;
}

/**
 * Normalize chat reply language. Default is Russian.
 */
function normalize_chat_language(?string $code): string
{
    return strtolower(trim((string) $code)) === 'en' ? 'en' : 'ru';
}

/**
 * System instruction forcing replies in the selected language (avoids Chinese defaults on Qwen).
 */
function language_system_instruction(string $language): string
{
    if ($language === 'en') {
        return 'Always answer in English. Never reply in Chinese or any other language unless the user explicitly asks for that language.';
    }

    return 'Always answer in Russian (русский язык). Never reply in Chinese or any other language unless the user explicitly asks for that language.';
}

/**
 * System prompt that injects retrieved project documentation for the model.
 *
 * @param list<string> $projectNames
 * @param list<array<string, mixed>> $hits
 */
function build_rag_system_prompt(array $projectNames, array $hits): string
{
    $parts = [];
    $label = $projectNames === []
        ? 'the selected projects'
        : '"' . implode('", "', $projectNames) . '"';
    $parts[] = 'You are a documentation assistant for ' . $label . '.';
    $parts[] = 'Use the documentation excerpts below to answer the user when they are relevant.';
    $parts[] = 'When you use information from an excerpt, cite it inline with its number in square brackets right after the claim, e.g. [1] or [1][3].';
    $parts[] = 'Only cite excerpts you actually used. Never invent citation numbers. Do not paste raw URLs in the answer.';
    $parts[] = 'If the excerpts do not cover the question, say so briefly and answer from general knowledge without citations.';
    $parts[] = '';
    $parts[] = '## Project documentation excerpts';

    foreach ($hits as $i => $hit) {
        $n = $i + 1;
        $title = trim((string) ($hit['title'] ?? '')) ?: 'Untitled';
        $link = (string) ($hit['link'] ?? '');
        $article = trim((string) ($hit['article_title'] ?? ''));
        $project = trim((string) ($hit['project_name'] ?? ''));
        $content = (string) ($hit['content'] ?? '');
        if (mb_strlen($content, 'UTF-8') > RAG_CONTENT_CHARS) {
            $content = mb_substr($content, 0, RAG_CONTENT_CHARS, 'UTF-8') . '…';
        }
        $header = "### [{$n}] {$title}";
        if ($project !== '') {
            $header .= " [{$project}]";
        }
        if ($article !== '' && $article !== $title) {
            $header .= " (from: {$article})";
        }
        $parts[] = $header;
        if ($link !== '') {
            $parts[] = "Link: {$link}";
        }
        $parts[] = $content;
        $parts[] = '';
    }

    return implode("\n", $parts);
}

/**
 * Flat numbered citations matching RAG excerpt order (for inline [n] markers).
 *
 * @param list<array<string, mixed>> $hits
 * @return list<array{
 *   n: int,
 *   title: string,
 *   link: string,
 *   project_name: string,
 *   article_title: string
 * }>
 */
function citations_from_hits(array $hits): array
{
    $citations = [];
    foreach ($hits as $i => $hit) {
        $title = trim((string) ($hit['title'] ?? ''));
        $articleTitle = trim((string) ($hit['article_title'] ?? ''));
        if ($title === '') {
            $title = $articleTitle !== '' ? $articleTitle : 'Reference';
        }
        $citations[] = [
            'n' => $i + 1,
            'title' => $title,
            'link' => trim((string) ($hit['link'] ?? '')),
            'project_name' => trim((string) ($hit['project_name'] ?? '')),
            'article_title' => $articleTitle,
        ];
    }

    return $citations;
}

/**
 * Messages for Ollama: language (+ optional RAG) system prompt + session history.
 *
 * @param list<array{role: string, content: string, references?: mixed}> $sessionMessages
 * @param list<string> $projectNames
 * @param list<array<string, mixed>> $hits
 * @return list<array{role: string, content: string}>
 */
function build_ollama_chat_messages(
    array $sessionMessages,
    array $projectNames,
    array $hits,
    string $language = 'ru'
): array {
    $language = normalize_chat_language($language);
    $system = language_system_instruction($language);
    if ($projectNames !== [] && $hits !== []) {
        $system .= "\n\n" . build_rag_system_prompt($projectNames, $hits);
    }

    $out = [
        ['role' => 'system', 'content' => $system],
    ];
    foreach ($sessionMessages as $message) {
        $role = (string) ($message['role'] ?? '');
        $content = (string) ($message['content'] ?? '');
        if ($role === '' || $content === '') {
            continue;
        }
        $out[] = ['role' => $role, 'content' => $content];
    }
    return $out;
}
