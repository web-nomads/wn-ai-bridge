<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Dto;

/**
 * Normalised search hit returned by every search provider.
 *
 * It is intentionally provider-agnostic so the assistant, the JSON response and
 * the unit tests can treat ke_search, indexed_search and the plain page/content
 * search identically.
 */
final class SearchResultItem implements \JsonSerializable
{
    public function __construct(
        public readonly string $title,
        public readonly string $url,
        public readonly string $snippet,
        public readonly float $score,
        public readonly string $source,
        public readonly int $pageId = 0,
    ) {}

    /**
     * Create an item, trimming and length-limiting the free-text fields so the
     * data handed to the LLM and the browser stays compact.
     */
    public static function create(
        string $title,
        string $url,
        string $snippet,
        float $score,
        string $source,
        int $pageId = 0,
    ): self {
        return new self(
            trim($title) !== '' ? trim($title) : $url,
            trim($url),
            self::normaliseSnippet($snippet),
            $score,
            $source,
            $pageId,
        );
    }

    private static function normaliseSnippet(string $snippet, int $maxLength = 400): string
    {
        // Collapse whitespace, strip any leftover markup and cut to a sane length.
        $clean = trim(preg_replace('/\s+/u', ' ', strip_tags($snippet)) ?? '');
        if (mb_strlen($clean) > $maxLength) {
            $clean = mb_substr($clean, 0, $maxLength - 1) . '…';
        }
        return $clean;
    }

    /**
     * @return array{title: string, url: string, snippet: string, source: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'title' => $this->title,
            'url' => $this->url,
            'snippet' => $this->snippet,
            'source' => $this->source,
        ];
    }
}
