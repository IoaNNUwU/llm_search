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
    delete_project($pdo, $projectId);
    json_response(['ok' => true, 'project_id' => $projectId]);
} catch (Throwable $e) {
    $code = $e->getMessage() === 'Project not found' ? 404 : 500;
    json_response(['error' => $e->getMessage()], $code);
}
