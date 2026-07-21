<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Split markdown into sections by headers (# ## ### …) or double newlines.
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

    $hasHeaders = (bool) preg_match('/^#{1,6}\s+\S/m', $markdown);

    if ($hasHeaders) {
        return split_by_headers($markdown);
    }

    return split_by_blank_lines($markdown);
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

    // Further split oversized header-less preamble / body chunks on blank lines.
    $expanded = [];
    foreach ($sections as $section) {
        if ($section['heading'] !== null || substr_count($section['content'], "\n\n") === 0) {
            $expanded[] = $section;
            continue;
        }
        foreach (split_by_blank_lines($section['content'], $section['anchor']) as $part) {
            $expanded[] = $part;
        }
    }

    return $expanded;
}

/**
 * @return list<array{heading: ?string, content: string, anchor: ?string}>
 */
function split_by_blank_lines(string $markdown, ?string $inheritedAnchor = null): array
{
    $parts = preg_split('/\n\s*\n+/', trim($markdown)) ?: [];
    $sections = [];
    $lastAnchor = $inheritedAnchor;

    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $heading = null;
        $anchor = $lastAnchor;
        if (preg_match('/^(#{1,6})\s+(.+)$/um', $part, $m)) {
            $heading = trim($m[2]);
            $anchor = slugify($heading);
            $lastAnchor = $anchor;
        }
        $sections[] = [
            'heading' => $heading,
            'content' => $part,
            'anchor' => $anchor,
        ];
    }

    return $sections;
}

/**
 * Collect .md files under $root, returning paths relative to $root using forward slashes.
 *
 * @return list<string>
 */
function collect_markdown_files(string $root): array
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
        if (strtolower($file->getExtension()) !== 'md') {
            continue;
        }
        $full = $file->getPathname();
        $rel = substr($full, strlen($root) + 1);
        $rel = str_replace('\\', '/', $rel);
        $files[] = $rel;
    }

    sort($files, SORT_STRING);
    return $files;
}

function project_file_link_legacy(string $baseUrl, string $relativePath): string
{
    $base = rtrim($baseUrl, '/');
    $path = ltrim(str_replace('\\', '/', $relativePath), '/');

    return $base . '/' . $path;
}

function project_file_link(string $baseUrl, string $relativePath): string
{
    $base = rtrim($baseUrl, '/');
    $path = ltrim(str_replace('\\', '/', $relativePath), '/');

    // Uploaded folders include their root name (e.g. "docs/inzhenery/file.md") — omit it from links.
    if (str_contains($path, '/')) {
        $path = substr($path, strpos($path, '/') + 1);
    }

    if (str_ends_with(strtolower($path), '.md')) {
        $path = substr($path, 0, -3);
    }

    return $base . '/' . $path;
}

/**
 * @return list<string>
 */
function project_file_link_variants(string $baseUrl, string $relativePath): array
{
    return array_values(array_unique([
        project_file_link($baseUrl, $relativePath),
        project_file_link_legacy($baseUrl, $relativePath),
    ]));
}

function is_file_indexed(array $indexedLinks, string $baseUrl, string $relativePath): bool
{
    foreach (project_file_link_variants($baseUrl, $relativePath) as $link) {
        if (isset($indexedLinks[$link])) {
            return true;
        }
    }
    return false;
}

function delete_articles_for_file(PDO $pdo, int $projectId, string $baseUrl, string $relativePath): void
{
    foreach (project_file_link_variants($baseUrl, $relativePath) as $link) {
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
