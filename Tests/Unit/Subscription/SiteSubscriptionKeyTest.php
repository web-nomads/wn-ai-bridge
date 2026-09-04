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
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WebNomads\WnAiBridge\Subscription\SubscriptionKeyCodec;
use WebNomads\WnAiBridge\Subscription\SubscriptionOnlineCheck;
use WebNomads\WnAiBridge\Subscription\SubscriptionService;
use WebNomads\WnAiBridge\Subscription\SubscriptionStatus;
use WebNomads\WnAiBridge\Subscription\TamperReporter;

/**
 * Which subscription key a request goes by.
 *
 * A licence covers domains, and a domain belongs to a site, so the key is
 * maintained per site: two websites in one TYPO3 can be licensed separately,
 * each with the key it was sold. The installation-wide key in the extension
 * configuration still applies where a site names none.
 *
 * The case that needs the most care is the backend. A backend request belongs to
 * no site, so without the last fallback an installation that keeps its keys on
 * the sites would look unlicensed the moment anyone opened the backend — and the
 * two subscription modules would simply be gone.
 */
final class SiteSubscriptionKeyTest extends TestCase
{
    private string $publicKeyHex = '';

    private string $secretKey = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('sodium_crypto_sign_keypair')) {
            self::markTestSkipped('The sodium extension is required for the subscription key.');
        }

        $pair = sodium_crypto_sign_keypair();
        $this->publicKeyHex = bin2hex(sodium_crypto_sign_publickey($pair));
        $this->secretKey = sodium_crypto_sign_secretkey($pair);

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
    public function eachSiteRunsOnItsOwnKey(): void
    {
        $sites = [
            'ours' => $this->site('ours', 1, 'https://a.example/', $this->key('sub_a', ['a.example'])),
            'theirs' => $this->site('theirs', 2, 'https://b.example/', $this->key('sub_b', ['b.example'])),
        ];

        $onA = $this->resolveStatus('a.example', $sites, $sites['ours']);
        self::assertTrue($onA->valid, $onA->getMessage());
        self::assertSame('sub_a', $onA->token?->id);

        $onB = $this->resolveStatus('b.example', $sites, $sites['theirs']);
        self::assertTrue($onB->valid, $onB->getMessage());
        self::assertSame('sub_b', $onB->token?->id);
    }

    /**
     * A site without a key of its own keeps running on the installation-wide
     * one, so nothing changes for an installation that has always had just the
     * one key.
     */
    #[Test]
    public function aSiteWithoutAKeyUsesTheInstallationOne(): void
    {
        $sites = ['ours' => $this->site('ours', 1, 'https://a.example/', '')];

        $status = $this->resolveStatus(
            'a.example',
            $sites,
            $sites['ours'],
            ['subscriptionKey' => $this->key('sub_installation', ['a.example'])],
        );

        self::assertTrue($status->valid, $status->getMessage());
        self::assertSame('sub_installation', $status->token?->id);
    }

    /**
     * The backend belongs to no site, so the question it asks is a different
     * one: does this installation hold a valid licence at all?
     *
     * Tying that to the address the backend happens to be open on was wrong. An
     * editor reaching it through a staging domain, a dedicated admin domain, or
     * simply the second of two websites would find the two subscription modules
     * gone, although the installation is perfectly licensed.
     */
    #[Test]
    public function theBackendIsLicensedWhenAnySiteIs(): void
    {
        $sites = [
            'ours' => $this->site('ours', 1, 'https://a.example/', $this->key('sub_a', ['a.example'])),
            'theirs' => $this->site('theirs', 2, 'https://b.example/', ''),
        ];

        // Opened on an address no licence names at all.
        $status = $this->resolveStatus('typo3.admin.example', $sites, null);

        self::assertTrue($status->valid, $status->getMessage());
        self::assertTrue($status->hasFeature(SubscriptionService::FEATURE_LOG));
        self::assertTrue($status->hasFeature(SubscriptionService::FEATURE_CORRECTIONS));
    }

    #[Test]
    public function theBackendIsUnlicensedOnlyWhenNoSiteIs(): void
    {
        $sites = [
            'ours' => $this->site('ours', 1, 'https://a.example/', $this->key('sub_a', ['a.example'], expired: true)),
            'theirs' => $this->site('theirs', 2, 'https://b.example/', ''),
        ];

        $status = $this->resolveStatus('typo3.admin.example', $sites, null);

        self::assertFalse($status->valid);
        self::assertSame(SubscriptionStatus::REASON_EXPIRED, $status->reason);
    }

    /**
     * A visitor request is the opposite case: it speaks for one website, and no
     * other site's licence can speak for it. Otherwise one licence in a
     * multi-site installation would quietly cover every website in it.
     */
    #[Test]
    public function aVisitorRequestCannotBorrowAnotherSitesLicence(): void
    {
        $sites = [
            'ours' => $this->site('ours', 1, 'https://a.example/', $this->key('sub_a', ['a.example'])),
            'theirs' => $this->site('theirs', 2, 'https://b.example/', ''),
        ];

        $status = $this->resolveStatus('b.example', $sites, $sites['theirs']);

        self::assertFalse($status->valid);
        self::assertSame(SubscriptionStatus::REASON_DOMAIN, $status->reason);
        self::assertStringContainsString('a.example', $status->getMessage());
    }

    #[Test]
    public function withNoKeyAnywhereTheStatusSaysSo(): void
    {
        $status = $this->resolveStatus('a.example', ['ours' => $this->site('ours', 1, 'https://a.example/', '')], null);

        self::assertFalse($status->valid);
        self::assertSame(SubscriptionStatus::REASON_MISSING, $status->reason);
    }

    /**
     * @param array<string, Site> $sites
     * @param array<string, string> $extensionConfiguration
     */
    private function resolveStatus(
        string $host,
        array $sites,
        ?Site $requestSite,
        array $extensionConfiguration = [],
    ): SubscriptionStatus {
        $_SERVER['HTTP_HOST'] = $host;
        GeneralUtility::flushInternalRuntimeCaches();

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] = $extensionConfiguration + [
            'subscriptionPublicKey' => $this->publicKeyHex,
            'subscriptionServerUrl' => 'https://licences.example.com',
        ];

        $request = new ServerRequest('https://' . $host . '/');
        $GLOBALS['TYPO3_REQUEST'] = $requestSite instanceof Site
            ? $request->withAttribute('site', $requestSite)
            : $request;

        $this->registerEmptyCache();

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn($sites);

        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willThrowException(new \RuntimeException('unreachable'));

        return (new SubscriptionService(
            new SubscriptionOnlineCheck($factory),
            new TamperReporter($factory),
            $siteFinder,
        ))->getStatus();
    }

    private function site(string $identifier, int $rootPageId, string $base, string $key): Site
    {
        return new Site($identifier, $rootPageId, [
            'base' => $base,
            'aiAssistantSubscriptionKey' => $key,
        ]);
    }

    /**
     * @param list<string> $domains
     */
    private function key(string $id, array $domains, bool $expired = false): string
    {
        $payload = (string)json_encode([
            'id' => $id,
            'customer' => 'Acme AG',
            'email' => 'info@example.com',
            'domains' => $domains,
            'iat' => time() - (400 * 86400),
            'exp' => $expired ? time() - 86400 : time() + (365 * 86400),
            'features' => [],
            'chk' => '',
        ]);

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $envelope = $nonce . sodium_crypto_secretbox($payload, $nonce, (string)hex2bin(SubscriptionKeyCodec::CIPHER_KEY));
        $signature = sodium_crypto_sign_detached($envelope, $this->secretKey);

        return SubscriptionKeyCodec::PREFIX . '.'
            . $this->base64Url($envelope) . '.'
            . $this->base64Url($signature);
    }

    private function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function registerEmptyCache(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn(false);

        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->willReturn($cache);

        GeneralUtility::purgeInstances();
        GeneralUtility::setSingletonInstance(CacheManager::class, $cacheManager);
    }
}
