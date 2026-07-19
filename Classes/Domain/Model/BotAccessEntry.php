<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Domain\Model;

/**
 * Read-only representation of one bot access log row for the backend module.
 */
final class BotAccessEntry
{
    public function __construct(
        public readonly int $uid,
        public readonly int $crdate,
        public readonly string $siteIdentifier,
        public readonly int $languageUid,
        public readonly string $requestType,
        public readonly string $method,
        public readonly string $path,
        public readonly string $queryString,
        public readonly int $httpStatus,
        public readonly string $botName,
        public readonly bool $isAiBot,
        public readonly string $userAgent,
        public readonly string $ipAddress,
        public readonly string $referer,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int)($row['uid'] ?? 0),
            (int)($row['crdate'] ?? 0),
            (string)($row['site_identifier'] ?? ''),
            (int)($row['language_uid'] ?? 0),
            (string)($row['request_type'] ?? ''),
            (string)($row['method'] ?? ''),
            (string)($row['path'] ?? ''),
            (string)($row['query_string'] ?? ''),
            (int)($row['http_status'] ?? 0),
            (string)($row['bot_name'] ?? ''),
            (bool)($row['is_ai_bot'] ?? false),
            (string)($row['user_agent'] ?? ''),
            (string)($row['ip_address'] ?? ''),
            (string)($row['referer'] ?? ''),
        );
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->setTimestamp($this->crdate);
    }

    /**
     * Human-readable label for the request type.
     */
    public function getRequestTypeLabel(): string
    {
        return match ($this->requestType) {
            'llmstxt' => 'llms.txt',
            'markdown' => 'Markdown (.md)',
            'page' => 'Page',
            default => $this->requestType,
        };
    }
}
