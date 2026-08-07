<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Subscription;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebNomads\WnAiBridge\Subscription\SubscriptionToken;

final class SubscriptionTokenTest extends TestCase
{
    /**
     * @param list<string> $domains
     */
    #[Test]
    #[DataProvider('hostProvider')]
    public function matchesHostHandlesPortsWildcardsAndCasing(array $domains, string $host, bool $expected): void
    {
        $token = new SubscriptionToken('sub_1', 'Acme', '', $domains, 0, 0, []);

        self::assertSame($expected, $token->matchesHost($host));
    }

    /**
     * @return array<string, array{0: list<string>, 1: string, 2: bool}>
     */
    public static function hostProvider(): array
    {
        return [
            'exact match' => [['example.com'], 'example.com', true],
            'case insensitive' => [['Example.com'], 'EXAMPLE.COM', true],
            'port is ignored' => [['example.com'], 'example.com:8080', true],
            'trailing dot is ignored' => [['example.com'], 'example.com.', true],
            'different domain' => [['example.com'], 'evil.com', false],
            'subdomain without wildcard' => [['example.com'], 'www.example.com', false],
            'wildcard matches subdomain' => [['*.example.com'], 'www.example.com', true],
            'wildcard matches deep subdomain' => [['*.example.com'], 'a.b.example.com', true],
            'wildcard does not match apex' => [['*.example.com'], 'example.com', false],
            'wildcard does not match suffix squatting' => [['*.example.com'], 'notexample.com', false],
            'second entry matches' => [['a.com', 'b.com'], 'b.com', true],
            'no domains at all' => [[], 'example.com', false],
            'empty host' => [['example.com'], '', false],
        ];
    }

    #[Test]
    public function expiryIsInclusiveOfTheStoredTimestamp(): void
    {
        $token = new SubscriptionToken('sub_1', 'Acme', '', ['example.com'], 0, 1_000, []);

        self::assertFalse($token->isExpired(1_000));
        self::assertTrue($token->isExpired(1_001));
    }

    #[Test]
    public function aKeyWithoutExpiryNeverExpires(): void
    {
        $token = new SubscriptionToken('sub_1', 'Acme', '', ['example.com'], 0, 0, []);

        self::assertFalse($token->isExpired(PHP_INT_MAX));
        self::assertNull($token->getExpiresAt());
    }

    #[Test]
    public function anEmptyFeatureListUnlocksEverything(): void
    {
        $token = new SubscriptionToken('sub_1', 'Acme', '', ['example.com'], 0, 0, []);

        self::assertTrue($token->hasFeature('chatbot'));
        self::assertTrue($token->hasFeature('something-added-later'));
    }

    #[Test]
    public function anExplicitFeatureListIsExclusive(): void
    {
        $token = new SubscriptionToken('sub_1', 'Acme', '', ['example.com'], 0, 0, ['chatbot']);

        self::assertTrue($token->hasFeature('chatbot'));
        self::assertFalse($token->hasFeature('log'));
    }
}
