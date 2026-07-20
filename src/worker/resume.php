<?php

declare(strict_types=1);

/**
 * Resume interrupted project evaluations (e.g. after container restart).
 * Usage: php resume.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../lib/db.php';

try {
    $spawned = resume_interrupted_evaluations(db());
    fwrite(STDOUT, "Resumed {$spawned} evaluation(s)\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Resume failed: ' . $e->getMessage() . "\n");
    exit(1);
}
