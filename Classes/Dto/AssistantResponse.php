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
        public readonly ?string $provider = null,
        public readonly ?string $model = null,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?float $cost = null,
        public readonly ?string $costCurrency = null,
    ) {}

    /**
     * Only answer/sources/mode are exposed to the frontend — provider/model,
     * token usage and cost are internal (used for logging) and never sent to the
     * browser.
     *
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
