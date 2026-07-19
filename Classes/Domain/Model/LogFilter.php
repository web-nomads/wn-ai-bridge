<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Domain\Model;

/**
 * Immutable set of filter criteria for the assistant log backend module.
 */
final class LogFilter
{
    public function __construct(
        public readonly ?int $dateFrom = null,
        public readonly ?int $dateTo = null,
        public readonly string $ip = '',
        public readonly string $provider = '',
        public readonly string $mode = '',
        public readonly string $search = '',
        public readonly int $page = 1,
        public readonly int $perPage = 25,
    ) {}

    /**
     * Build a filter from backend module request query parameters.
     *
     * @param array<string, mixed> $params
     */
    public static function fromQueryParams(array $params): self
    {
        $dateFrom = self::parseDate((string)($params['dateFrom'] ?? ''), false);
        $dateTo = self::parseDate((string)($params['dateTo'] ?? ''), true);

        return new self(
            $dateFrom,
            $dateTo,
            trim((string)($params['ip'] ?? '')),
            trim((string)($params['provider'] ?? '')),
            trim((string)($params['mode'] ?? '')),
            trim((string)($params['search'] ?? '')),
            max(1, (int)($params['page'] ?? 1)),
            25,
        );
    }

    /**
     * Parse a "Y-m-d" date input into a unix timestamp. For the end date the
     * time is pushed to the end of the day so the range is inclusive.
     */
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
     * Filter values echoed back into the form, as strings.
     *
     * @return array<string, string>
     */
    public function toFormValues(): array
    {
        return [
            'dateFrom' => $this->dateFrom !== null ? date('Y-m-d', $this->dateFrom) : '',
            'dateTo' => $this->dateTo !== null ? date('Y-m-d', $this->dateTo) : '',
            'ip' => $this->ip,
            'provider' => $this->provider,
            'mode' => $this->mode,
            'search' => $this->search,
        ];
    }
}
