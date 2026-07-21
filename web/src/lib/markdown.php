<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/project_types.php';

/**
 * Split markdown into sections by headers (# ## ### …).
 * Files without headers become a single section.
 *
 * @return list<array{heading: ?string, content: string, anchor: ?string}>
 */
function split_markdown_sections(string $markdown): array
{
    $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
    $markdown = trim($markdown);
    if ($markdown === '') {
        return [];
    }

    return split_by_headers($markdown);
}

/**
 * @return list<array{heading: ?string, content: string, anchor: ?string}>
 */
function split_by_headers(string $markdown): array
{
    $lines = explode("\n", $markdown);
    $sections = [];
    $heading = null;
    $anchor = null;
    $buf = [];

    $flush = static function () use (&$sections, &$heading, &$anchor, &$buf): void {
        $content = trim(implode("\n", $buf));
        if ($content === '' && $heading === null) {
            $buf = [];
            return;
        }
        if ($content === '' && $heading !== null) {
            $content = $heading;
        }
        $sections[] = [
            'heading' => $heading,
            'content' => $content,
            'anchor' => $anchor,
        ];
        $buf = [];
    };

    foreach ($lines as $line) {
        if (preg_match('/^(#{1,6})\s+(.+?)\s*$/u', $line, $m)) {
            $flush();
            $heading = trim($m[2]);
            $anchor = slugify($heading);
            $buf = [$line];
            continue;
        }
        $buf[] = $line;
    }
    $flush();

    return $sections;
}

/**
 * Collect .md files under $root, returning paths relative to $root using forward slashes.
 *
 * @return list<string>
 */
function collect_markdown_files(string $root, ?ProjectType $projectType = null): array
{
    $root = rtrim($root, '/\\');
    if (!is_dir($root)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $full = $file->getPathname();
        $rel = substr($full, strlen($root) + 1);
        $rel = str_replace('\\', '/', $rel);
        if (
            strtolower($file->getExtension()) !== 'md'
            || ($projectType !== null && !$projectType->acceptsFile($rel))
        ) {
            continue;
        }
        $files[] = $rel;
    }

    sort($files, SORT_STRING);
    return $files;
}

function is_file_indexed(
    array $indexedLinks,
    ProjectType $projectType,
    string $baseUrl,
    string $relativePath
): bool
{
    foreach ($projectType->articleLinkVariants($baseUrl, $relativePath) as $link) {
        if (isset($indexedLinks[$link])) {
            return true;
        }
    }
    return false;
}

function delete_articles_for_file(
    PDO $pdo,
    int $projectId,
    ProjectType $projectType,
    string $baseUrl,
    string $relativePath
): void
{
    foreach ($projectType->articleLinkVariants($baseUrl, $relativePath) as $link) {
        $stmt = $pdo->prepare('SELECT id FROM articles WHERE project_id = :project_id AND link = :link');
        $stmt->execute(['project_id' => $projectId, 'link' => $link]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            continue;
        }
        $articleId = (int) $id;
        $pdo->prepare('DELETE FROM articles_sections WHERE article_id = :id')->execute(['id' => $articleId]);
        $pdo->prepare('DELETE FROM articles WHERE id = :id')->execute(['id' => $articleId]);
    }
}

function section_link(string $articleLink, ?string $anchor): string
{
    if ($anchor === null || $anchor === '') {
        return $articleLink;
    }
    return $articleLink . '#' . $anchor;
}
