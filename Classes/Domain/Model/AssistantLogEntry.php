<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Domain\Model;

/**
 * Read-only representation of one row of the assistant log, used for display in
 * the backend module.
 */
final class AssistantLogEntry
{
    public function __construct(
        public readonly int $uid,
        public readonly int $crdate,
        public readonly string $conversationId,
        public readonly string $question,
        public readonly string $answer,
        public readonly string $mode,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $totalTokens,
        public readonly int $sourceCount,
        public readonly string $ipAddress,
        public readonly string $userAgent,
        public readonly int $languageUid,
        public readonly string $siteIdentifier,
        public readonly int $pageId,
        /** @var list<array{title: string, url: string}> */
        public readonly array $sources = [],
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int)($row['uid'] ?? 0),
            (int)($row['crdate'] ?? 0),
            (string)($row['conversation_id'] ?? ''),
            (string)($row['question'] ?? ''),
            (string)($row['answer'] ?? ''),
            (string)($row['mode'] ?? ''),
            (string)($row['provider'] ?? ''),
            (string)($row['model'] ?? ''),
            (int)($row['input_tokens'] ?? 0),
            (int)($row['output_tokens'] ?? 0),
            (int)($row['total_tokens'] ?? 0),
            (int)($row['source_count'] ?? 0),
            (string)($row['ip_address'] ?? ''),
            (string)($row['user_agent'] ?? ''),
            (int)($row['language_uid'] ?? 0),
            (string)($row['site_identifier'] ?? ''),
            (int)($row['page_id'] ?? 0),
            self::decodeSources($row['sources'] ?? null),
        );
    }

    /**
     * @return list<array{title: string, url: string}>
     */
    private static function decodeSources(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }

        $sources = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = trim((string)($item['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $title = trim((string)($item['title'] ?? ''));
            $sources[] = ['title' => $title !== '' ? $title : $url, 'url' => $url];
        }

        return $sources;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->setTimestamp($this->crdate);
    }

    public function isLlm(): bool
    {
        return $this->mode === 'llm';
    }
}
