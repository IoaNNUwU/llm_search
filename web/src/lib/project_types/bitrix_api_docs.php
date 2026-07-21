<?php

declare(strict_types=1);

final class BitrixApiDocsProjectType extends ProjectType
{
    private const BASE_URL = 'https://apidocs.bitrix24.ru';

    public function key(): string
    {
        return 'bitrix_api_docs';
    }

    public function baseUrl(string $providedBaseUrl): string
    {
        return self::BASE_URL;
    }

    public function acceptsFile(string $relativePath): bool
    {
        if (!parent::acceptsFile($relativePath)) {
            return false;
        }

        foreach (explode('/', str_replace('\\', '/', $relativePath)) as $segment) {
            if ($segment !== '' && str_starts_with($segment, '_')) {
                return false;
            }
        }

        return true;
    }

    public function articleLink(string $baseUrl, string $relativePath): string
    {
        return rtrim(self::BASE_URL, '/')
            . '/'
            . $this->pathWithoutMarkdownSuffix($relativePath)
            . '.html';
    }
}
