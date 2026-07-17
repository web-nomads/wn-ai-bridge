<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Dto;

/**
 * The assistant's answer to one question: a natural-language answer plus the
 * source hits it is based on, and the mode that produced it ("llm" or "search").
 */
final class AssistantResponse implements \JsonSerializable
{
    /**
     * @param list<SearchResultItem> $sources
     */
    public function __construct(
        public readonly string $answer,
        public readonly array $sources,
        public readonly string $mode,
    ) {}

    /**
     * @return array{answer: string, sources: list<SearchResultItem>, mode: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'answer' => $this->answer,
            'sources' => $this->sources,
            'mode' => $this->mode,
        ];
    }
}
