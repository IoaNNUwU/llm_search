<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/markdown.php';

header('Content-Type: application/json; charset=utf-8');

$projectId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($projectId < 1) {
    json_response(['error' => 'Missing project id'], 400);
}

try {
    $pdo = db();

    $project = $pdo->prepare('SELECT id, name, base_url FROM projects WHERE id = :id');
    $project->execute(['id' => $projectId]);
    $proj = $project->fetch();
    if (!$proj) {
        json_response(['error' => 'Project not found'], 404);
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM project_evaluations WHERE project_id = :id ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute(['id' => $projectId]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(['error' => 'Evaluation not found'], 404);
    }

    $total = (int) $row['total_files'];
    $done = (int) $row['processed_files'];
    $percent = $total > 0
        ? (int) round(($done / $total) * 100)
        : ($row['status'] === 'completed' ? 100 : 0);

    $files = collect_markdown_files(projects_path() . '/' . $projectId);

    json_response([
        'project_id' => $projectId,
        'project_name' => $proj['name'],
        'status' => $row['status'],
        'total_files' => $total,
        'processed_files' => $done,
        'percent' => $percent,
        'current_file' => $row['current_file'],
        'current_phase' => $row['current_phase'],
        'current_section' => $row['current_section'] !== null ? (int) $row['current_section'] : null,
        'total_sections' => $row['total_sections'] !== null ? (int) $row['total_sections'] : null,
        'current_detail' => $row['current_detail'],
        'error' => $row['error'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
        'files' => $files,
        'recent_indexed' => fetch_recent_indexed_sections(
            $pdo,
            $projectId,
            (string) $proj['base_url'],
            2
        ),
        'events' => read_evaluation_events($projectId),
    ]);
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 500);
}
