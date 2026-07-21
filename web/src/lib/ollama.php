<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function ollama_host(): string
{
    return rtrim(env('OLLAMA_HOST', 'http://ollama:11434'), '/');
}

function ollama_model(): string
{
    return env('OLLAMA_MODEL', 'qwen2.5:7b');
}

function ollama_embed_model(): string
{
    return env('OLLAMA_EMBED_MODEL', 'nomic-embed-text');
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function ollama_request(string $path, array $payload, int $timeout = 300): array
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
    ]);

    $raw = @file_get_contents(ollama_host() . $path, false, $context);
    if ($raw === false) {
        throw new RuntimeException('Could not reach Ollama at ' . ollama_host());
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $status = $http_response_header[0] ?? '';
        throw new RuntimeException('Invalid Ollama response' . ($status !== '' ? " ({$status})" : ''));
    }

    return $data;
}

function ollama_chat(string $prompt, string $system = ''): string
{
    $messages = [];
    if ($system !== '') {
        $messages[] = ['role' => 'system', 'content' => $system];
    }
    $messages[] = ['role' => 'user', 'content' => $prompt];

    $data = ollama_request('/api/chat', [
        'model' => ollama_model(),
        'messages' => $messages,
        'stream' => false,
        'format' => 'json',
    ]);

    $content = $data['message']['content'] ?? null;
    if (!is_string($content) || $content === '') {
        throw new RuntimeException('Ollama chat returned empty content');
    }

    return $content;
}

function ollama_embed_batch_size(): int
{
    $size = (int) env('OLLAMA_EMBED_BATCH_SIZE', '32');
    return max(1, $size);
}

/**
 * @param list<string> $texts
 * @return list<list<float|int>>
 */
function ollama_embed_batch(array $texts): array
{
    if ($texts === []) {
        return [];
    }

    $normalized = [];
    foreach ($texts as $text) {
        $text = trim((string) $text);
        $normalized[] = $text === '' ? ' ' : $text;
    }

    $out = [];
    $batchSize = ollama_embed_batch_size();
    $chunks = array_chunk($normalized, $batchSize);

    foreach ($chunks as $chunk) {
        try {
            $data = ollama_request('/api/embed', [
                'model' => ollama_embed_model(),
                'input' => $chunk,
            ], 300);
            $embeddings = $data['embeddings'] ?? null;
            if (!is_array($embeddings) || count($embeddings) !== count($chunk)) {
                throw new RuntimeException('Ollama embed batch size mismatch');
            }
            foreach ($embeddings as $embedding) {
                if (!is_array($embedding)) {
                    throw new RuntimeException('Ollama embed batch returned invalid vector');
                }
                $out[] = $embedding;
            }
            continue;
        } catch (Throwable) {
            // fall through to single-request path
        }

        foreach ($chunk as $text) {
            $data = ollama_request('/api/embeddings', [
                'model' => ollama_embed_model(),
                'prompt' => $text,
            ], 120);
            if (!isset($data['embedding']) || !is_array($data['embedding'])) {
                throw new RuntimeException('Ollama embed returned no vector');
            }
            $out[] = $data['embedding'];
        }
    }

    return $out;
}

/**
 * @return list<float|int>
 */
function ollama_embed(string $text): array
{
    return ollama_embed_batch([$text])[0];
}

/**
 * Title/description from text without calling the LLM.
 * When $preferredTitle is set (e.g. markdown heading), it is used as the title.
 *
 * @return array{title: string, description: string}
 */
function heuristic_title_description(string $text, ?string $preferredTitle = null, int $descriptionMax = 240): array
{
    $excerpt = mb_substr($text, 0, 6000, 'UTF-8');
    $title = $preferredTitle !== null ? trim($preferredTitle) : '';
    if ($title === '') {
        $firstLine = trim(preg_split('/\R/', $excerpt)[0] ?? 'Untitled');
        $firstLine = preg_replace('/^#{1,6}\s+/', '', $firstLine) ?? $firstLine;
        $title = $firstLine !== '' ? $firstLine : 'Untitled';
    }

    $description = preg_replace('/\s+/', ' ', $excerpt) ?? $excerpt;
    $description = trim($description);

    return [
        'title' => mb_substr($title, 0, 80, 'UTF-8'),
        'description' => mb_substr($description, 0, max(1, $descriptionMax), 'UTF-8'),
    ];
}

/**
 * @return array{title: string, description: string}
 */
function llm_title_description(string $text, string $contextLabel): array
{
    $excerpt = mb_substr($text, 0, 6000, 'UTF-8');
    $system = 'You generate concise metadata for documentation. '
        . 'Respond with JSON only: {"title":"...","description":"..."}. '
        . 'Title: short (max 80 chars). Description: 1-2 sentences.';

    $prompt = "Context: {$contextLabel}\n\nContent:\n{$excerpt}";

    try {
        $raw = ollama_chat($prompt, $system);
        $parsed = json_decode($raw, true);
        if (is_array($parsed)) {
            $title = trim((string) ($parsed['title'] ?? ''));
            $description = trim((string) ($parsed['description'] ?? ''));
            if ($title !== '' && $description !== '') {
                return [
                    'title' => mb_substr($title, 0, 200, 'UTF-8'),
                    'description' => mb_substr($description, 0, 1000, 'UTF-8'),
                ];
            }
        }
    } catch (Throwable) {
        // fall through to heuristic
    }

    return heuristic_title_description($text);
}
