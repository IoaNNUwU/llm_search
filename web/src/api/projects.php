<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = db();
    $sql = <<<'SQL'
        SELECT
            p.id,
            p.name,
            p.description,
            p.base_url,
            e.status AS eval_status,
            e.total_files,
            e.processed_files,
            e.current_file,
            e.current_phase,
            e.current_section,
            e.total_sections,
            e.error AS eval_error,
            e.updated_at AS eval_updated_at
        FROM projects p
        LEFT JOIN LATERAL (
            SELECT *
            FROM project_evaluations
            WHERE project_id = p.id
            ORDER BY id DESC
            LIMIT 1
        ) e ON TRUE
        ORDER BY p.id DESC
    SQL;

    $rows = $pdo->query($sql)->fetchAll();
    $projects = array_map(static function (array $row): array {
        $total = (int) ($row['total_files'] ?? 0);
        $done = (int) ($row['processed_files'] ?? 0);
        $percent = $total > 0 ? (int) round(($done / $total) * 100) : ($row['eval_status'] === 'completed' ? 100 : 0);

        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'description' => $row['description'],
            'base_url' => $row['base_url'],
            'evaluation' => [
                'status' => $row['eval_status'] ?? 'unknown',
                'total_files' => $total,
                'processed_files' => $done,
                'current_file' => $row['current_file'],
                'current_phase' => $row['current_phase'],
                'current_section' => $row['current_section'] !== null ? (int) $row['current_section'] : null,
                'total_sections' => $row['total_sections'] !== null ? (int) $row['total_sections'] : null,
                'error' => $row['eval_error'],
                'percent' => $percent,
                'updated_at' => $row['eval_updated_at'],
            ],
        ];
    }, $rows);

    json_response(['projects' => $projects]);
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 500);
}
