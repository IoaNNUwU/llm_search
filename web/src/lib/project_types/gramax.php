<?php

declare(strict_types=1);

final class GramaxProjectType extends ProjectType
{
    public function key(): string
    {
        return 'gramax';
    }

    public function baseUrl(string $providedBaseUrl): string
    {
        $baseUrl = rtrim(trim($providedBaseUrl), '/');
        if ($baseUrl === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('A valid base URL is required for Gramax projects');
        }

        return $baseUrl;
    }

    public function articleLink(string $baseUrl, string $relativePath): string
    {
        $path = $this->pathWithoutMarkdownSuffix($relativePath);
        if (basename($path) === '_index') {
            $path = dirname($path);
            if ($path === '.') {
                $path = '';
            }
        }

        return rtrim($baseUrl, '/') . ($path !== '' ? '/' . $path : '');
    }
}
