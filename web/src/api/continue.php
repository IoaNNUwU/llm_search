<?php

declare(strict_types=1);

ini_set('display_errors', '0');

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$projectId = read_project_id_from_request();
if ($projectId < 1) {
    json_response(['error' => 'Missing project id'], 400);
}

try {
    $pdo = db();
    $exists = $pdo->prepare('SELECT id FROM projects WHERE id = :id');
    $exists->execute(['id' => $projectId]);
    if (!$exists->fetch()) {
        json_response(['error' => 'Project not found'], 404);
    }

    if (!continue_project_evaluation($pdo, $projectId)) {
        json_response(['error' => 'No cancelled or failed evaluation to continue'], 409);
    }

    json_response(['ok' => true, 'project_id' => $projectId, 'status' => 'pending']);
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 500);
}
