<?php

declare(strict_types=1);

ini_set('display_errors', '0');

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/ollama.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/project_types.php';

set_exception_handler(static function (Throwable $e): void {
    json_response(['error' => $e->getMessage()], 500);
});

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
$postMax = ini_get('post_max_size');
if ($contentLength > 0 && empty($_POST) && empty($_FILES)) {
    json_response([
        'error' => "Upload rejected (empty POST). Likely exceeded post_max_size ({$postMax}) or max_file_uploads (" . ini_get('max_file_uploads') . '). Upload markdown (.md) files only, or raise PHP limits.',
    ], 413);
}

$name = trim((string) ($_POST['name'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));
try {
    $projectType = project_type(trim((string) ($_POST['project_type'] ?? '')));
    $baseUrl = $projectType->baseUrl((string) ($_POST['base_url'] ?? ''));
} catch (InvalidArgumentException $e) {
    json_response(['error' => $e->getMessage()], 400);
}

if ($name === '' || $description === '') {
    json_response(['error' => 'Name and description are required'], 400);
}

if (!isset($_FILES['files']) || !is_array($_FILES['files']['name'])) {
    json_response(['error' => 'No files uploaded. Drop or select a folder.'], 400);
}

$names = $_FILES['files']['name'];
$tmps = $_FILES['files']['tmp_name'];
$errors = $_FILES['files']['error'];
$relativePaths = $_POST['paths'] ?? [];

if (!is_array($names)) {
    $names = [$names];
    $tmps = [$tmps];
    $errors = [$errors];
}

if (!is_array($relativePaths)) {
    $relativePaths = [];
}

$fileCount = count($names);
if ($fileCount === 0) {
    json_response(['error' => 'Folder is empty'], 400);
}

$entries = [];
for ($i = 0; $i < $fileCount; $i++) {
    if ((int) $errors[$i] !== UPLOAD_ERR_OK) {
        continue;
    }
    $original = (string) $names[$i];
    $tmp = (string) $tmps[$i];
    $rel = isset($relativePaths[$i]) ? (string) $relativePaths[$i] : $original;
    $rel = str_replace('\\', '/', $rel);
    $rel = ltrim($rel, '/');
    // Strip leading folder name from webkitdirectory paths (keep nested structure).
    // Browser sends "RootFolder/sub/file.md" — keep as-is under project dir.
    if ($rel === '' || str_contains($rel, '..') || !$projectType->acceptsFile($rel)) {
        continue;
    }
    $entries[] = ['tmp' => $tmp, 'rel' => $rel];
}

if ($entries === []) {
    json_response(['error' => 'No valid files in upload'], 400);
}

try {
    $pdo = db();
    $embedText = $name . "\n" . $description;
    $embedding = ollama_embed($embedText);
    $embedModel = ollama_embed_model();

    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'INSERT INTO projects (base_url, name, description, project_type)
         VALUES (:base_url, :name, :description, :project_type)
         RETURNING id'
    );
    $stmt->execute([
        'base_url' => $baseUrl,
        'name' => $name,
        'description' => $description,
        'project_type' => $projectType->key(),
    ]);
    $projectId = (int) $stmt->fetchColumn();

    $embedStmt = $pdo->prepare(
        'INSERT INTO project_embeddings (project_id, model, embedding)
         VALUES (:project_id, :model, CAST(:embedding AS vector))'
    );
    $embedStmt->execute([
        'project_id' => $projectId,
        'model' => $embedModel,
        'embedding' => embedding_to_sql($embedding),
    ]);

    $projectDir = projects_path() . '/' . $projectId;
    if (!mkdir($projectDir, 0775, true) && !is_dir($projectDir)) {
        throw new RuntimeException('Could not create project storage directory');
    }

    $saved = 0;
    foreach ($entries as $entry) {
        $dest = $projectDir . '/' . $entry['rel'];
        $dir = dirname($dest);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create directory for ' . $entry['rel']);
        }
        if (!move_uploaded_file($entry['tmp'], $dest)) {
            // Fallback for some environments
            if (!rename($entry['tmp'], $dest) && !copy($entry['tmp'], $dest)) {
                throw new RuntimeException('Failed to store ' . $entry['rel']);
            }
        }
        $saved++;
    }

    if ($saved === 0) {
        throw new RuntimeException('No files were saved');
    }

    $eval = $pdo->prepare(
        'INSERT INTO project_evaluations (project_id, status, total_files, processed_files)
         VALUES (:project_id, :status, :total_files, 0)
         RETURNING id'
    );
    $mdCount = count($entries);
    $eval->execute([
        'project_id' => $projectId,
        'status' => 'pending',
        'total_files' => $mdCount,
    ]);

    $pdo->commit();

    spawn_evaluator($projectId);

    json_response([
        'ok' => true,
        'project_id' => $projectId,
        'files_saved' => $saved,
        'markdown_files' => $mdCount,
    ], 201);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(['error' => $e->getMessage()], 500);
}
