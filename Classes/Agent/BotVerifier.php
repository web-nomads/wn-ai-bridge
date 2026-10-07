<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Agent;

/**
 * Forward-confirmed reverse DNS for crawlers whose operators publish host names
 */
final class BotVerifier
{
    public const UNKNOWN = 0;
    public const NOT_VERIFIED = 1;
    public const VERIFIED = 2;

    private const HOSTS = [
        'Googlebot' => ['googlebot.com', 'google.com', 'googleusercontent.com'],
        'GoogleOther' => ['googlebot.com', 'google.com'],
        'Bingbot' => ['search.msn.com'],
        'Applebot' => ['applebot.apple.com'],
        'Amazonbot' => ['crawl.amazonbot.amazon'],
        'DuckAssistBot' => ['duckduckgo.com'],
        'PetalBot' => ['petalsearch.com'],
    ];

    /** @var \Closure(string): string */
    private \Closure $reverse;

    /** @var \Closure(string): list<string> */
    private \Closure $forward;

    /**
     * @param (\Closure(string): string)|null $reverse IP => host name
     * @param (\Closure(string): list<string>)|null $forward host name => IPs
     */
    public function __construct(?\Closure $reverse = null, ?\Closure $forward = null)
    {
        $this->reverse = $reverse ?? static fn(string $ip): string => (string)@gethostbyaddr($ip);
        $this->forward = $forward ?? static function (string $host): array {
            $ips = [];
            foreach ([DNS_A, DNS_AAAA] as $type) {
                foreach (@dns_get_record($host, $type) ?: [] as $record) {
                    $ip = $record['ip'] ?? $record['ipv6'] ?? '';
                    if (is_string($ip) && $ip !== '') {
                        $ips[] = $ip;
                    }
                }
            }

            return $ips;
        };
    }

    public function canVerify(string $token): bool
    {
        return isset(self::HOSTS[$token]);
    }

    public function verify(string $token, string $ip): int
    {
        if (!$this->canVerify($token) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return self::UNKNOWN;
        }
        $host = strtolower(rtrim(($this->reverse)($ip), '.'));
        if ($host === '' || $host === $ip) {
            return self::NOT_VERIFIED;
        }
        $matches = false;
        foreach (self::HOSTS[$token] as $domain) {
            if (str_ends_with($host, '.' . $domain)) {
                $matches = true;
                break;
            }
        }
        if (!$matches) {
            return self::NOT_VERIFIED;
        }

        return in_array(inet_pton($ip), array_map(static fn(string $address): string|false => @inet_pton($address), ($this->forward)($host)), true)
            ? self::VERIFIED
            : self::NOT_VERIFIED;
    }
}
