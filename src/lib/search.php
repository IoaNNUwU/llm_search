<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ollama.php';

const RAG_SECTION_LIMIT = 5;
const RAG_CONTENT_CHARS = 1800;

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
 * Semantic search over indexed sections for a project.
 *
 * @return list<array{
 *   id: int,
 *   title: string,
 *   description: string,
 *   content: string,
 *   link: string,
 *   article_title: string,
 *   article_link: string,
 *   distance: float
 * }>
 */
function search_project_sections(PDO $pdo, int $projectId, string $query, int $limit = RAG_SECTION_LIMIT): array
{
    $query = trim($query);
    if ($projectId < 1 || $query === '') {
        return [];
    }

    $embeddingSql = embedding_to_sql(ollama_embed($query));
    $limit = max(1, min(20, $limit));

    $sql = <<<'SQL'
        WITH q AS (
            SELECT CAST(:embedding AS vector) AS v
        )
        SELECT
            s.id,
            COALESCE(NULLIF(TRIM(s.title), ''), a.title) AS title,
            COALESCE(s.description, '') AS description,
            s.content,
            s.link,
            a.title AS article_title,
            a.link AS article_link,
            (s.embedding <=> q.v) AS distance
        FROM articles_sections s
        INNER JOIN articles a ON a.id = s.article_id
        CROSS JOIN q
        WHERE a.project_id = :project_id
        ORDER BY s.embedding <=> q.v
        LIMIT :lim
    SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue('embedding', $embeddingSql, PDO::PARAM_STR);
    $stmt->bindValue('project_id', $projectId, PDO::PARAM_INT);
    $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
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
            'distance' => (float) $row['distance'],
        ];
    }
    return $out;
}

/**
 * Build citation list for the UI, grouped by article (section links deduped).
 *
 * @param list<array<string, mixed>> $hits
 * @return list<array{
 *   article_title: string,
 *   article_link: string,
 *   sections: list<array{title: string, link: string}>
 * }>
 */
function references_from_hits(array $hits): array
{
    $groups = [];
    $groupOrder = [];
    $seenLinks = [];

    foreach ($hits as $hit) {
        $link = trim((string) ($hit['link'] ?? ''));
        if ($link === '' || isset($seenLinks[$link])) {
            continue;
        }
        $seenLinks[$link] = true;

        $articleTitle = trim((string) ($hit['article_title'] ?? ''));
        $articleLink = trim((string) ($hit['article_link'] ?? ''));
        $groupKey = $articleLink !== '' ? $articleLink : ($articleTitle !== '' ? $articleTitle : $link);

        if (!isset($groups[$groupKey])) {
            $groups[$groupKey] = [
                'article_title' => $articleTitle !== '' ? $articleTitle : (string) ($hit['title'] ?? 'Reference'),
                'article_link' => $articleLink,
                'sections' => [],
            ];
            $groupOrder[] = $groupKey;
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

        $groups[$groupKey]['sections'][] = [
            'title' => $sectionTitle,
            'link' => $link,
        ];
    }

    $refs = [];
    foreach ($groupOrder as $key) {
        $group = $groups[$key];
        if ($group['sections'] === [] && $group['article_link'] === '') {
            continue;
        }
        $refs[] = $group;
    }

    return $refs;
}

/**
 * System prompt that injects retrieved project documentation for the model.
 *
 * @param list<array<string, mixed>> $hits
 */
function build_rag_system_prompt(string $projectName, array $hits): string
{
    $parts = [];
    $parts[] = 'You are a documentation assistant for the project "' . $projectName . '".';
    $parts[] = 'Use the documentation excerpts below to answer the user when they are relevant.';
    $parts[] = 'When you use an excerpt, mention it and include its link so the user can open the source.';
    $parts[] = 'If the excerpts do not cover the question, say so briefly and answer from general knowledge.';
    $parts[] = '';
    $parts[] = '## Project documentation excerpts';

    foreach ($hits as $i => $hit) {
        $n = $i + 1;
        $title = trim((string) ($hit['title'] ?? '')) ?: 'Untitled';
        $link = (string) ($hit['link'] ?? '');
        $article = trim((string) ($hit['article_title'] ?? ''));
        $content = (string) ($hit['content'] ?? '');
        if (mb_strlen($content, 'UTF-8') > RAG_CONTENT_CHARS) {
            $content = mb_substr($content, 0, RAG_CONTENT_CHARS, 'UTF-8') . '…';
        }
        $header = "### [{$n}] {$title}";
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
 * Messages for Ollama: optional RAG system prompt + session history (role/content only).
 *
 * @param list<array{role: string, content: string, references?: mixed}> $sessionMessages
 * @param list<array<string, mixed>> $hits
 * @return list<array{role: string, content: string}>
 */
function build_ollama_chat_messages(array $sessionMessages, ?string $projectName, array $hits): array
{
    $out = [];
    if ($projectName !== null && $hits !== []) {
        $out[] = [
            'role' => 'system',
            'content' => build_rag_system_prompt($projectName, $hits),
        ];
    }
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
