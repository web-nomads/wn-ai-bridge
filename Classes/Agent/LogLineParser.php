<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Agent;

/**
 * One line of an Apache/Nginx access log in the combined format, optionally with the virtual host in front ("%v:%p ...")
 */
final class LogLineParser
{
    private const PATTERN = '/^(?:(?<vhost>[^\s:]+)(?::\d+)?\s+)?(?<ip>[0-9a-f.:]+)\s+\S+\s+\S+\s+\[(?<time>[^\]]+)\]\s+"(?<method>[A-Z]+)\s+(?<path>\S+)(?:\s+HTTP\/[\d.]+)?"\s+(?<status>\d{3})\s+\S+(?:\s+"(?<referrer>[^"]*)"\s+"(?<agent>[^"]*)")?/i';

    /**
     * @return array{host: string, ip: string, time: int, method: string, path: string, status: int, referrer: string, userAgent: string}|null
     */
    public function parse(string $line): ?array
    {
        if (preg_match(self::PATTERN, trim($line), $match) !== 1) {
            return null;
        }
        $time = \DateTimeImmutable::createFromFormat('d/M/Y:H:i:s O', $match['time']);
        if ($time === false) {
            return null;
        }

        return [
            'host' => strtolower($match['vhost']),
            'ip' => $match['ip'],
            'time' => $time->getTimestamp(),
            'method' => strtoupper($match['method']),
            'path' => $match['path'],
            'status' => (int)$match['status'],
            'referrer' => ($match['referrer'] ?? '') === '-' ? '' : (string)($match['referrer'] ?? ''),
            'userAgent' => ($match['agent'] ?? '') === '-' ? '' : (string)($match['agent'] ?? ''),
        ];
    }

    /**
     * Identifies a line for deduplication
     *
     * @param array{host: string, ip: string, time: int, path: string, status: int, userAgent: string} $record
     */
    public static function hash(array $record): string
    {
        return sha1($record['time'] . '|' . $record['host'] . '|' . $record['path'] . '|' . $record['status'] . '|' . $record['userAgent'] . '|' . VisitLogger::hashIp($record['ip']));
    }
}
