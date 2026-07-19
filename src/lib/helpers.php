<?php

declare(strict_types=1);

function env(string $key, ?string $default = null): string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        if ($default === null) {
            throw new RuntimeException("Missing required env: {$key}");
        }
        return $default;
    }
    return $value;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_project_id_from_request(): int
{
    if (isset($_POST['id'])) {
        return (int) $_POST['id'];
    }
    if (isset($_GET['id'])) {
        return (int) $_GET['id'];
    }
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $body = json_decode($raw, true);
        if (is_array($body) && isset($body['id'])) {
            return (int) $body['id'];
        }
    }
    return 0;
}

function projects_path(): string
{
    return rtrim(env('PROJECTS_PATH', '/var/www/projects'), '/');
}

function embedding_dimensions(): int
{
    return 1536;
}

/**
 * Pad or truncate a vector to the schema dimension (VECTOR(1536)).
 * Zero-padding preserves cosine similarity among equally padded vectors.
 *
 * @param list<float|int> $vector
 * @return list<float>
 */
function normalize_embedding(array $vector): array
{
    $dim = embedding_dimensions();
    $out = [];
    for ($i = 0; $i < $dim; $i++) {
        $out[] = isset($vector[$i]) ? (float) $vector[$i] : 0.0;
    }
    return $out;
}

function embedding_to_sql(array $vector): string
{
    $normalized = normalize_embedding($vector);
    return '[' . implode(',', array_map(
        static fn (float $v): string => sprintf('%.8F', $v),
        $normalized
    )) . ']';
}

function slugify(string $text): string
{
    $text = mb_strtolower(trim($text), 'UTF-8');
    $text = preg_replace('/[^\p{L}\p{N}\s_-]+/u', '', $text) ?? '';
    $text = preg_replace('/[\s_]+/u', '-', $text) ?? '';
    $text = trim($text, '-');
    return $text !== '' ? $text : 'section';
}

function evaluation_pid_path(int $projectId): string
{
    return projects_path() . '/_logs/project_' . $projectId . '.pid';
}

function evaluation_log_path(int $projectId): string
{
    return projects_path() . '/_logs/project_' . $projectId . '.log';
}

function evaluation_events_path(int $projectId): string
{
    return projects_path() . '/_logs/project_' . $projectId . '.events.jsonl';
}

/**
 * Reset and start a fresh evaluation event log for a project.
 */
function reset_evaluation_events(int $projectId): void
{
    $dir = projects_path() . '/_logs';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    file_put_contents(evaluation_events_path($projectId), '');
}

/**
 * Append one evaluation event (file / section) to the JSONL log.
 *
 * @param array<string, mixed> $event
 */
function append_evaluation_event(int $projectId, array $event): void
{
    $event['ts'] = $event['ts'] ?? gmdate('c');
    $line = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        return;
    }
    file_put_contents(evaluation_events_path($projectId), $line . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * @return list<array<string, mixed>>
 */
function read_evaluation_events(int $projectId, int $limit = 500): array
{
    $path = evaluation_events_path($projectId);
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return [];
    }
    $lines = preg_split('/\R/', trim($raw)) ?: [];
    if (count($lines) > $limit) {
        $lines = array_slice($lines, -$limit);
    }
    $events = [];
    foreach ($lines as $line) {
        if ($line === '') {
            continue;
        }
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $events[] = $decoded;
        }
    }
    return $events;
}

function spawn_evaluator(int $projectId): void
{
    $script = __DIR__ . '/../worker/evaluate.php';
    $logDir = projects_path() . '/_logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0775, true);
    }
    $logFile = evaluation_log_path($projectId);
    $pidFile = evaluation_pid_path($projectId);
    $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';

    // Start detached; write PID so cancel can stop the worker.
    $cmd = sprintf(
        'nohup %s %s %d >> %s 2>&1 < /dev/null & echo $! > %s',
        escapeshellarg($php),
        escapeshellarg($script),
        $projectId,
        escapeshellarg($logFile),
        escapeshellarg($pidFile)
    );
    exec($cmd);
}

function kill_evaluator(int $projectId): bool
{
    $pidFile = evaluation_pid_path($projectId);
    if (!is_file($pidFile)) {
        return false;
    }
    $pid = (int) trim((string) file_get_contents($pidFile));
    @unlink($pidFile);
    if ($pid < 1) {
        return false;
    }
    if (function_exists('posix_kill')) {
        @posix_kill($pid, 15);
        usleep(200000);
        @posix_kill($pid, 9);
    } else {
        exec('kill -TERM ' . $pid . ' 2>/dev/null');
        usleep(200000);
        exec('kill -KILL ' . $pid . ' 2>/dev/null');
    }
    return true;
}

function delete_project_storage(int $projectId): void
{
    $dir = projects_path() . '/' . $projectId;
    if (is_dir($dir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
    @unlink(evaluation_pid_path($projectId));
    @unlink(evaluation_log_path($projectId));
    @unlink(evaluation_events_path($projectId));
}

/**
 * Mark evaluation cancelled and stop the worker if running.
 */
function cancel_project_evaluation(PDO $pdo, int $projectId): bool
{
    $stmt = $pdo->prepare(
        'SELECT id, status FROM project_evaluations WHERE project_id = :id ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute(['id' => $projectId]);
    $row = $stmt->fetch();
    if (!$row) {
        return false;
    }
    if (!in_array($row['status'], ['pending', 'processing'], true)) {
        return false;
    }

    $update = $pdo->prepare(
        "UPDATE project_evaluations
         SET status = 'cancelled',
             current_file = NULL,
             current_phase = NULL,
             current_section = NULL,
             total_sections = NULL,
             current_detail = NULL,
             error = NULL,
             updated_at = NOW()
         WHERE id = :id"
    );
    $update->execute(['id' => (int) $row['id']]);
    kill_evaluator($projectId);
    return true;
}

function delete_project(PDO $pdo, int $projectId): void
{
    cancel_project_evaluation($pdo, $projectId);
    $stmt = $pdo->prepare('DELETE FROM projects WHERE id = :id');
    $stmt->execute(['id' => $projectId]);
    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('Project not found');
    }
    delete_project_storage($projectId);
}
