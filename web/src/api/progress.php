<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/enrichment.php';

header('Content-Type: application/json; charset=utf-8');

$projectId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($projectId < 1) {
    json_response(['error' => 'Missing project id'], 400);
}

try {
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT * FROM project_evaluations WHERE project_id = :id ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute(['id' => $projectId]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(['error' => 'Evaluation not found'], 404);
    }

    $total = (int) $row['total_files'];
    $searchable = (int) $row['searchable_files'];
    $done = (int) $row['processed_files'];
    $percent = $total > 0
        ? (int) round(($done / $total) * 100)
        : ($row['status'] === 'completed' ? 100 : 0);
    $searchablePercent = $total > 0
        ? (int) round(($searchable / $total) * 100)
        : ($row['status'] === 'completed' ? 100 : 0);
    $enrichment = project_enrichment_progress($pdo, $projectId, $total);

    json_response([
        'project_id' => $projectId,
        'status' => $row['status'],
        'total_files' => $total,
        'searchable_files' => $searchable,
        'processed_files' => $done,
        'current_file' => $row['current_file'],
        'current_phase' => $row['current_phase'],
        'current_section' => $row['current_section'] !== null ? (int) $row['current_section'] : null,
        'total_sections' => $row['total_sections'] !== null ? (int) $row['total_sections'] : null,
        'current_detail' => $row['current_detail'],
        'error' => $row['error'],
        'percent' => $percent,
        'searchable_percent' => $searchablePercent,
        'updated_at' => $row['updated_at'],
    ] + $enrichment);
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 500);
}
