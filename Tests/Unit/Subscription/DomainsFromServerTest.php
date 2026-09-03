<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Subscription;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WebNomads\WnAiBridge\Subscription\OnlineCheckResult;
use WebNomads\WnAiBridge\Subscription\SubscriptionKeyCodec;
use WebNomads\WnAiBridge\Subscription\SubscriptionOnlineCheck;
use WebNomads\WnAiBridge\Subscription\SubscriptionService;
use WebNomads\WnAiBridge\Subscription\SubscriptionStatus;
use WebNomads\WnAiBridge\Subscription\TamperReporter;

/**
 * What the domain list published by the issuing server does to the subscription
 * state of a multi-domain installation.
 *
 * The case this was written for: one TYPO3 serving two sites, a licence covering
 * both, and a key that was issued when there was only one. The second site used
 * to be told its key was for another domain — the domains were frozen inside the
 * key and nothing short of pasting a new one could change that.
 */
final class DomainsFromServerTest extends TestCase
{
    private string $publicKeyHex = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('sodium_crypto_sign_keypair')) {
            self::markTestSkipped('The sodium extension is required for the subscription key.');
        }

        Environment::initialize(
            new ApplicationContext('Testing'),
            false,
            true,
            __DIR__,
            __DIR__,
            __DIR__,
            __DIR__,
            'index.php',
            'Linux',
        );

        unset($GLOBALS['TYPO3_REQUEST']);
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['TYPO3_REQUEST'],
            $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'],
            $_SERVER['HTTP_HOST'],
        );
        GeneralUtility::purgeInstances();
        GeneralUtility::flushInternalRuntimeCaches();

        parent::tearDown();
    }

    #[Test]
    public function theSecondSiteOfALicenceWorksOnceTheServerNamesIt(): void
    {
        $status = $this->statusFor(
            'www.zweitseite.ch',
            ['*.kunde.ch'],
            ['*.kunde.ch', '*.zweitseite.ch'],
        );

        self::assertTrue($status->valid, $status->getMessage());
        self::assertSame(SubscriptionStatus::REASON_OK, $status->reason);
        self::assertTrue($status->hasServerConfirmedDomains());
        self::assertSame('*.kunde.ch, *.zweitseite.ch', $status->getDomainList());
    }

    #[Test]
    public function theDomainOfTheKeyKeepsWorkingAlongsideIt(): void
    {
        $status = $this->statusFor(
            'www.kunde.ch',
            ['*.kunde.ch'],
            ['*.kunde.ch', '*.zweitseite.ch'],
        );

        self::assertTrue($status->valid, $status->getMessage());
    }

    /**
     * A domain taken off a licence has to stop working, or the list would only
     * ever be able to grow.
     */
    #[Test]
    public function aDomainRemovedFromTheLicenceStopsWorking(): void
    {
        $status = $this->statusFor(
            'www.kunde.ch',
            ['*.kunde.ch', '*.zweitseite.ch'],
            ['*.zweitseite.ch'],
        );

        self::assertFalse($status->valid);
        self::assertSame(SubscriptionStatus::REASON_DOMAIN, $status->reason);
        self::assertStringContainsString('*.zweitseite.ch', $status->getMessage());
    }

    /**
     * Without an answer from the server the key stays the only word on it —
     * exactly as it was before any of this existed.
     */
    #[Test]
    public function withoutAnAnswerTheKeyDecidesAlone(): void
    {
        $status = $this->statusFor('www.zweitseite.ch', ['*.kunde.ch'], null);

        self::assertFalse($status->valid);
        self::assertSame(SubscriptionStatus::REASON_DOMAIN, $status->reason);
        self::assertFalse($status->hasServerConfirmedDomains());
        self::assertSame('*.kunde.ch', $status->getDomainList());
    }

    /**
     * A revoked subscription is off whatever domains it names.
     */
    #[Test]
    public function aRevokedSubscriptionIsStillRevoked(): void
    {
        $status = $this->statusFor(
            'www.kunde.ch',
            ['*.kunde.ch'],
            null,
            OnlineCheckResult::revoked(time()),
        );

        self::assertFalse($status->valid);
        self::assertSame(SubscriptionStatus::REASON_REVOKED, $status->reason);
    }

    /**
     * @param list<string> $keyDomains The domains baked into the key
     * @param list<string>|null $publishedDomains What the server says, null for "it named none"
     */
    private function statusFor(
        string $host,
        array $keyDomains,
        ?array $publishedDomains,
        ?OnlineCheckResult $verdict = null,
    ): SubscriptionStatus {
        $_SERVER['HTTP_HOST'] = $host;
        GeneralUtility::flushInternalRuntimeCaches();

        $verdict ??= OnlineCheckResult::active(time() + (200 * 86400), time(), $publishedDomains ?? []);
        $this->registerCacheHolding($verdict);

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] = [
            'subscriptionKey' => $this->buildKey($keyDomains),
            'subscriptionPublicKey' => $this->publicKeyHex,
            'subscriptionServerUrl' => 'https://licences.example.com',
        ];

        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willThrowException(
            new \RuntimeException('No request may be made — the verdict is cached.')
        );

        $service = new SubscriptionService(
            new SubscriptionOnlineCheck($factory),
            new TamperReporter($factory),
        );

        return $service->getStatus();
    }

    private function registerCacheHolding(OnlineCheckResult $verdict): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn($verdict->toArray());

        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->willReturn($cache);

        GeneralUtility::purgeInstances();
        GeneralUtility::setSingletonInstance(CacheManager::class, $cacheManager);
    }

    /**
     * @param list<string> $domains
     */
    private function buildKey(array $domains): string
    {
        $pair = sodium_crypto_sign_keypair();
        $this->publicKeyHex = bin2hex(sodium_crypto_sign_publickey($pair));

        $payload = (string)json_encode([
            'id' => 'sub_test',
            'customer' => 'Acme AG',
            'email' => 'info@example.com',
            'domains' => $domains,
            'iat' => time() - 86400,
            'exp' => time() + (365 * 86400),
            'features' => [],
            'chk' => '',
        ]);

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $envelope = $nonce . sodium_crypto_secretbox($payload, $nonce, (string)hex2bin(SubscriptionKeyCodec::CIPHER_KEY));
        $signature = sodium_crypto_sign_detached($envelope, sodium_crypto_sign_secretkey($pair));

        return SubscriptionKeyCodec::PREFIX . '.'
            . $this->base64Url($envelope) . '.'
            . $this->base64Url($signature);
    }

    private function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
