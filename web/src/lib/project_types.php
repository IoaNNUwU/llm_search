<?php

declare(strict_types=1);

abstract class ProjectType
{
    abstract public function key(): string;

    abstract public function baseUrl(string $providedBaseUrl): string;

    abstract public function articleLink(string $baseUrl, string $relativePath): string;

    public function acceptsFile(string $relativePath): bool
    {
        return str_ends_with(strtolower($relativePath), '.md');
    }

    /**
     * @return list<string>
     */
    public function articleLinkVariants(string $baseUrl, string $relativePath): array
    {
        $legacyPath = ltrim(str_replace('\\', '/', $relativePath), '/');
        $previousPath = $this->pathWithoutMarkdownSuffix($relativePath);

        return array_values(array_unique([
            $this->articleLink($baseUrl, $relativePath),
            rtrim($baseUrl, '/') . '/' . $previousPath,
            rtrim($baseUrl, '/') . '/' . $legacyPath,
        ]));
    }

    protected function pathWithoutRoot(string $relativePath): string
    {
        $path = ltrim(str_replace('\\', '/', $relativePath), '/');
        if (str_contains($path, '/')) {
            $path = substr($path, strpos($path, '/') + 1);
        }

        return $path;
    }

    protected function pathWithoutMarkdownSuffix(string $relativePath): string
    {
        $path = $this->pathWithoutRoot($relativePath);
        if (str_ends_with(strtolower($path), '.md')) {
            $path = substr($path, 0, -3);
        }

        return $path;
    }
}

require_once __DIR__ . '/project_types/bitrix_api_docs.php';
require_once __DIR__ . '/project_types/gramax.php';

function project_type(string $key): ProjectType
{
    return match ($key) {
        'bitrix_api_docs' => new BitrixApiDocsProjectType(),
        'gramax' => new GramaxProjectType(),
        default => throw new InvalidArgumentException('Unsupported project type'),
    };
}
