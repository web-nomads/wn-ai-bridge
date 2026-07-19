<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Domain\Model;

/**
 * Immutable set of filter criteria for the bot access log backend module.
 */
final class BotAccessFilter
{
    public function __construct(
        public readonly ?int $dateFrom = null,
        public readonly ?int $dateTo = null,
        public readonly string $requestType = '',
        public readonly string $botName = '',
        public readonly string $ip = '',
        public readonly string $search = '',
        public readonly bool $aiOnly = false,
        public readonly int $page = 1,
        public readonly int $perPage = 50,
    ) {}

    /**
     * @param array<string, mixed> $params
     */
    public static function fromQueryParams(array $params): self
    {
        return new self(
            self::parseDate((string)($params['dateFrom'] ?? ''), false),
            self::parseDate((string)($params['dateTo'] ?? ''), true),
            trim((string)($params['requestType'] ?? '')),
            trim((string)($params['botName'] ?? '')),
            trim((string)($params['ip'] ?? '')),
            trim((string)($params['search'] ?? '')),
            !empty($params['aiOnly']),
            max(1, (int)($params['page'] ?? 1)),
            50,
        );
    }

    private static function parseDate(string $value, bool $endOfDay): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false) {
            return null;
        }
        if ($endOfDay) {
            $date = $date->setTime(23, 59, 59);
        }
        return $date->getTimestamp();
    }

    public function getOffset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /**
     * @return array<string, string>
     */
    public function toFormValues(): array
    {
        return [
            'dateFrom' => $this->dateFrom !== null ? date('Y-m-d', $this->dateFrom) : '',
            'dateTo' => $this->dateTo !== null ? date('Y-m-d', $this->dateTo) : '',
            'requestType' => $this->requestType,
            'botName' => $this->botName,
            'ip' => $this->ip,
            'search' => $this->search,
            'aiOnly' => $this->aiOnly ? '1' : '',
        ];
    }
}
