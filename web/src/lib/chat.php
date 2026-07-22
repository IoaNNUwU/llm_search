<?php

declare(strict_types=1);

require_once __DIR__ . '/enrichment.php';

const MAX_HISTORY = 20;

/**
 * Migrate legacy single-thread history and ensure at least one window exists.
 */
function chat_ensure_windows(): void
{
    if (!isset($_SESSION['chat_windows']) || !is_array($_SESSION['chat_windows'])) {
        $_SESSION['chat_windows'] = [];
    }

    if (isset($_SESSION['messages']) && is_array($_SESSION['messages'])) {
        if ($_SESSION['chat_windows'] === []) {
            $id = chat_generate_window_id();
            $_SESSION['chat_windows'][$id] = ['messages' => array_values($_SESSION['messages'])];
            $_SESSION['active_window'] = $id;
        }
        unset($_SESSION['messages']);
    }

    if ($_SESSION['chat_windows'] === []) {
        $id = chat_generate_window_id();
        $_SESSION['chat_windows'][$id] = ['messages' => []];
        $_SESSION['active_window'] = $id;
    }
}

function chat_generate_window_id(): string
{
    return bin2hex(random_bytes(8));
}

function chat_is_window_id(mixed $id): bool
{
    return is_string($id)
        && preg_match('/^[a-f0-9]{16}$/', $id) === 1
        && isset($_SESSION['chat_windows'][$id]);
}

/**
 * Resolve the active chat window for this request.
 */
function chat_window_id(): string
{
    chat_ensure_windows();

    $requested = $_POST['w'] ?? $_GET['w'] ?? null;
    if (chat_is_window_id($requested)) {
        $_SESSION['active_window'] = $requested;

        return $requested;
    }

    $active = $_SESSION['active_window'] ?? null;
    if (chat_is_window_id($active)) {
        return $active;
    }

    $id = (string) array_key_first($_SESSION['chat_windows']);
    $_SESSION['active_window'] = $id;

    return $id;
}

function chat_redirect(string $windowId): void
{
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/';
    header('Location: ' . $path . '?w=' . rawurlencode($windowId));
    exit;
}

/**
 * @return list<array<string, mixed>>
 */
function chat_messages(): array
{
    $id = chat_window_id();
    if (!isset($_SESSION['chat_windows'][$id]['messages']) || !is_array($_SESSION['chat_windows'][$id]['messages'])) {
        $_SESSION['chat_windows'][$id]['messages'] = [];
    }

    /** @var list<array<string, mixed>> $messages */
    $messages = $_SESSION['chat_windows'][$id]['messages'];

    return $messages;
}

/**
 * @param list<array<string, mixed>> $messages
 */
function chat_set_messages(array $messages): void
{
    $_SESSION['chat_windows'][chat_window_id()]['messages'] = array_values($messages);
}

function chat_trim_messages(array $messages): array
{
    if (count($messages) > MAX_HISTORY) {
        return array_values(array_slice($messages, -MAX_HISTORY));
    }

    return array_values($messages);
}

function chat_clear(): void
{
    chat_set_messages([]);
}

function chat_create_window(): string
{
    chat_ensure_windows();
    $id = chat_generate_window_id();
    $_SESSION['chat_windows'][$id] = ['messages' => []];
    $_SESSION['active_window'] = $id;

    return $id;
}

/**
 * Handle POST chat / clear / new-window actions.
 *
 * @return array{error: ?string, prompt: ?string}
 */
function chat_handle_post(string $ollamaModel): array
{
    $result = ['error' => null, 'prompt' => null];

    // Keep ?w= in the URL so each browser tab stays on its own dialogue.
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        chat_ensure_windows();
        $requested = $_GET['w'] ?? null;
        if (!chat_is_window_id($requested)) {
            chat_redirect(chat_window_id());
        }
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return $result;
    }

    if (str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/')) {
        return $result;
    }

    if (isset($_POST['new_window'])) {
        chat_redirect(chat_create_window());
    }

    if (isset($_POST['clear'])) {
        chat_clear();
        chat_redirect(chat_window_id());
    }

    $prompt = trim((string) ($_POST['prompt'] ?? ''));
    $projectIds = parse_project_ids($_POST['project_ids'] ?? ($_POST['project_id'] ?? ''));
    $language = normalize_chat_language($_POST['language'] ?? null);
    $useAgent = !in_array(strtolower(trim((string) ($_POST['use_agent'] ?? '1'))), ['0', 'false', 'off', 'no'], true);
    $_SESSION['chat_language'] = $language;
    $_SESSION['use_agent'] = $useAgent;
    $result['prompt'] = $prompt;

    if ($prompt === '') {
        $result['error'] = 'Enter a message.';
        return $result;
    }

    $messages = chat_messages();
    $messages[] = ['role' => 'user', 'content' => $prompt];
    $messages = chat_trim_messages($messages);
    chat_set_messages($messages);

    try {
        $hits = [];
        $ragHits = [];
        $projectNames = [];
        if ($projectIds !== []) {
            $pdo = db();
            $projects = get_projects($pdo, $projectIds);
            $projectNames = array_map(static fn (array $p): string => $p['name'], $projects);
            $resolvedIds = array_map(static fn (array $p): int => $p['id'], $projects);
            if ($resolvedIds !== []) {
                $hits = filter_hits_by_top_distance(
                    search_project_sections($pdo, $resolvedIds, $prompt, RAG_REFERENCE_LIMIT)
                );
                $ragLimit = min(count($hits), RAG_SECTION_LIMIT * max(1, count($resolvedIds)));
                $ragHits = array_slice($hits, 0, $ragLimit);
                try {
                    record_article_search_hits($pdo, $ragHits);
                } catch (Throwable) {
                    // Popularity tracking must never break the user request.
                }
            }
        }

        if (!$useAgent) {
            $refs = references_from_hits($hits);
            $assistant = [
                'role' => 'assistant',
                'content' => '',
                'links_only' => true,
            ];
            if ($refs !== []) {
                $assistant['references'] = $refs;
                if ($projectNames !== []) {
                    $assistant['project_names'] = $projectNames;
                }
            } else {
                $assistant['content'] = $language === 'en'
                    ? 'No relevant links found in the selected sources.'
                    : 'В выбранных источниках релевантных ссылок не найдено.';
            }
            $messages[] = $assistant;
            chat_set_messages(chat_trim_messages($messages));
            chat_redirect(chat_window_id());
        }

        $payload = [
            'model' => $ollamaModel,
            'messages' => build_ollama_chat_messages($messages, $projectNames, $ragHits, $language),
            'stream' => false,
        ];

        $assistantContent = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $data = ollama_request('/api/chat', $payload);
            if (!isset($data['message']['content']) || !is_string($data['message']['content'])) {
                throw new RuntimeException('Unexpected response from Ollama.');
            }

            $assistantContent = $data['message']['content'];
            if (!contains_chinese_characters($assistantContent)) {
                break;
            }

            $correction = $language === 'en'
                ? 'Your previous response contained Chinese characters. Regenerate the entire answer using English only.'
                : 'Предыдущий ответ содержал китайские символы. Сгенерируй весь ответ заново, используя только русский язык.';
            $payload['messages'][0]['content'] .= "\n\n" . $correction;
        }

        if ($assistantContent === null || contains_chinese_characters($assistantContent)) {
            throw new RuntimeException('Ollama failed to generate a response in the selected language.');
        }

        $assistant = [
            'role' => 'assistant',
            'content' => $assistantContent,
        ];
        $refs = references_from_hits($hits);
        if ($refs !== []) {
            $assistant['references'] = $refs;
            $citations = citations_from_hits($ragHits);
            if ($citations !== []) {
                $assistant['citations'] = $citations;
            }
            if ($projectNames !== []) {
                $assistant['project_names'] = $projectNames;
            }
        }
        $messages[] = $assistant;
        chat_set_messages(chat_trim_messages($messages));
        chat_redirect(chat_window_id());
    } catch (Throwable $e) {
        array_pop($messages);
        chat_set_messages($messages);
        $result['error'] = $e->getMessage();
        return $result;
    }
}
