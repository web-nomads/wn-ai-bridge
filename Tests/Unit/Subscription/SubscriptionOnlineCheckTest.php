<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Subscription;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WebNomads\WnAiBridge\Subscription\OnlineCheckProtocol;
use WebNomads\WnAiBridge\Subscription\OnlineCheckResult;
use WebNomads\WnAiBridge\Subscription\SubscriptionOnlineCheck;
use WebNomads\WnAiBridge\Subscription\SubscriptionToken;

/**
 * The daily status check as it behaves around its cache.
 *
 * The point of these tests is the absence of an off switch: the check used to be
 * skippable through the extension configuration, which produced installations
 * whose CLI reported "confirmed by the server" while nothing ever asked it.
 */
final class SubscriptionOnlineCheckTest extends TestCase
{
    private const NOW = 1_800_000_000;

    /** Ed25519 key pair for this test run; the subject only ever sees the public half. */
    private string $publicKeyHex = '';

    private string $secretKey = '';

    protected function setUp(): void
    {
        parent::setUp();

        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        $this->publicKeyHex = bin2hex(sodium_crypto_sign_publickey($pair));

        // The subject asks Environment::isCli() before making a request, and that
        // returns null on an uninitialised Environment.
        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            true,
            __DIR__,
            __DIR__,
            __DIR__,
            __DIR__,
            'phpunit',
            'Linux',
        );

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge']);
        GeneralUtility::purgeInstances();

        parent::tearDown();
    }

    #[Test]
    public function theRemovedOffSwitchNoLongerSuppressesTheCheck(): void
    {
        // The setting is gone, but a stale value survives in settings.php until
        // the extension configuration is saved again. It must not be honoured.
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge']['subscriptionOnlineCheck'] = '0';

        $this->registerCache($this->emptyCache());
        $subject = $this->subject($this->respondingFactory('active', self::NOW + 86400));

        $verdict = $subject->verdict($this->token(), $this->publicKeyHex, 'example.com');

        self::assertTrue($verdict->isVerified());
        self::assertSame(OnlineCheckResult::STATUS_ACTIVE, $verdict->status);
    }

    #[Test]
    public function aSignedRevocationIsReported(): void
    {
        $this->registerCache($this->emptyCache());
        $subject = $this->subject($this->respondingFactory('revoked', 0));

        self::assertTrue($subject->verdict($this->token(), $this->publicKeyHex, 'example.com')->isRevoked());
    }

    #[Test]
    public function aCachedVerdictIsUsedInsteadOfAskingAgain(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn(OnlineCheckResult::active(self::NOW + 86400, self::NOW)->toArray());
        $this->registerCache($cache);

        $factory = $this->createMock(RequestFactory::class);
        $factory->expects(self::never())->method('request');

        self::assertTrue(
            $this->subject($factory)->verdict($this->token(), $this->publicKeyHex, 'example.com')->isVerified()
        );
    }

    #[Test]
    public function anUnreachableServerYieldsUnknownRatherThanRevoked(): void
    {
        $this->registerCache($this->emptyCache());

        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willThrowException(new \RuntimeException('connection refused'));

        $verdict = $this->subject($factory)->verdict($this->token(), $this->publicKeyHex, 'example.com');

        self::assertFalse($verdict->isRevoked());
        self::assertFalse($verdict->isVerified());
        self::assertTrue($verdict->hasFailed());
        self::assertSame(OnlineCheckResult::FAILURE_UNREACHABLE, $verdict->failureReason);
    }

    #[Test]
    public function anHttpErrorIsReportedAsSuch(): void
    {
        $this->registerCache($this->emptyCache());

        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willReturn($this->response('Internal Server Error', 500));

        $verdict = $this->subject($factory)->verdict($this->token(), $this->publicKeyHex, 'example.com');

        self::assertSame(OnlineCheckResult::FAILURE_HTTP, $verdict->failureReason);
        self::assertNotSame('', $verdict->getFailureMessage());
    }

    #[Test]
    public function anUnverifiableAnswerIsReportedAsSuch(): void
    {
        // 200, but the body is not a signed answer for this subscription.
        $this->registerCache($this->emptyCache());

        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willReturn($this->response('{"status":"active"}'));

        $verdict = $this->subject($factory)->verdict($this->token(), $this->publicKeyHex, 'example.com');

        self::assertSame(OnlineCheckResult::FAILURE_INVALID, $verdict->failureReason);
    }

    #[Test]
    public function aSuccessfulCheckReportsNoFailure(): void
    {
        $this->registerCache($this->emptyCache());
        $subject = $this->subject($this->respondingFactory('active', self::NOW + 86400));

        $verdict = $subject->verdict($this->token(), $this->publicKeyHex, 'example.com');

        self::assertFalse($verdict->hasFailed());
        self::assertSame('', $verdict->getFailureMessage());
    }

    #[Test]
    public function theFailureSurvivesTheCache(): void
    {
        // The reason is what the backend module shows, so it has to come back
        // out of the cache with the verdict.
        $stored = OnlineCheckResult::unknown(self::NOW, OnlineCheckResult::FAILURE_HTTP)->toArray();

        $restored = OnlineCheckResult::fromArray($stored);

        self::assertTrue($restored->hasFailed());
        self::assertSame(OnlineCheckResult::FAILURE_HTTP, $restored->failureReason);
    }

    #[Test]
    public function neverHavingAskedIsNotAFailure(): void
    {
        self::assertFalse(OnlineCheckResult::unknown(self::NOW)->hasFailed());
    }

    #[Test]
    public function changingTheServerAddressDiscardsTheCachedVerdict(): void
    {
        // Otherwise a wrong address entered in the extension configuration stays
        // invisible until a day-old verdict for the previous one expires.
        $store = [];
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('set')->willReturnCallback(
            function (string $id, $data) use (&$store): void {
                $store[$id] = $data;
            }
        );
        $cache->method('get')->willReturnCallback(
            // Not an arrow function: that would bind $store by value.
            function (string $id) use (&$store) {
                return $store[$id] ?? false;
            }
        );
        $this->registerCache($cache);

        $calls = 0;
        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willReturnCallback(
            function () use (&$calls): ResponseInterface {
                $calls++;
                return $this->response('{}');
            }
        );

        $subject = $this->subject($factory);
        $token = $this->token();

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge']['subscriptionServerUrl'] = 'https://old.example.com';
        $subject->verdict($token, $this->publicKeyHex, 'example.com');
        $subject->verdict($token, $this->publicKeyHex, 'example.com');
        self::assertSame(1, $calls, 'The second call for the same address must come from the cache.');

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge']['subscriptionServerUrl'] = 'https://new.example.com';
        $subject->verdict($token, $this->publicKeyHex, 'example.com');
        self::assertSame(2, $calls, 'A new address has to be asked, not answered from the old verdict.');
    }

    #[Test]
    public function anAnswerForAnotherSubscriptionIsDiscarded(): void
    {
        $this->registerCache($this->emptyCache());

        // Correct signature, wrong subscription id — a replayed answer from a
        // different installation must not count.
        $subject = $this->subject($this->respondingFactory('active', self::NOW + 86400, 'sub_someone_else'));

        self::assertFalse(
            $subject->verdict($this->token(), $this->publicKeyHex, 'example.com')->isVerified()
        );
    }

    #[Test]
    public function aKeyWithoutAnIssuingServerFallsBackToTheShippedOne(): void
    {
        // Without this the check had no one to ask and silently did nothing.
        $this->registerCache($this->emptyCache());

        $seen = '';
        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willReturnCallback(
            function (string $url) use (&$seen): ResponseInterface {
                $seen = $url;
                return $this->response('{}');
            }
        );

        $tokenWithoutServer = new SubscriptionToken(
            'sub_abc',
            'Acme AG',
            'info@example.com',
            ['example.com'],
            self::NOW,
            self::NOW + 86400,
            [],
            '',
        );

        $this->subject($factory)->verdict($tokenWithoutServer, $this->publicKeyHex, 'example.com');

        self::assertStringStartsWith(
            SubscriptionOnlineCheck::DEFAULT_SERVER_URL . OnlineCheckProtocol::ENDPOINT_PATH,
            $seen
        );
    }

    #[Test]
    #[DataProvider('serverUrlPrecedence')]
    public function theServerAddressResolvesInOrder(string $configured, string $fromKey, string $expected): void
    {
        self::assertSame($expected, SubscriptionOnlineCheck::resolveServerUrl($configured, $fromKey));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function serverUrlPrecedence(): array
    {
        $default = SubscriptionOnlineCheck::DEFAULT_SERVER_URL;

        return [
            'configuration wins' => ['https://staging.example.com', 'https://issuer.example.com', 'https://staging.example.com'],
            'key when unconfigured' => ['', 'https://issuer.example.com', 'https://issuer.example.com'],
            'default when neither' => ['', '', $default],
            'whitespace counts as empty' => ['   ', '', $default],
            'a non-url configuration is skipped' => ['issuer.example.com', '', $default],
            'a non-url in the key is skipped' => ['', 'not a url', $default],
            'http is accepted' => ['http://localhost:8080', '', 'http://localhost:8080'],
        ];
    }

    #[Test]
    public function theConfiguredServerOverridesTheOneInsideTheKey(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge']['subscriptionServerUrl'] = 'https://staging.example.com';
        $this->registerCache($this->emptyCache());

        $seen = '';
        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willReturnCallback(
            function (string $url) use (&$seen): ResponseInterface {
                $seen = $url;
                return $this->response('{}');
            }
        );

        $this->subject($factory)->verdict($this->token(), $this->publicKeyHex, 'example.com');

        self::assertStringStartsWith('https://staging.example.com' . OnlineCheckProtocol::ENDPOINT_PATH, $seen);
    }

    private function subject(RequestFactory $factory): SubscriptionOnlineCheck
    {
        return new SubscriptionOnlineCheck($factory);
    }

    /**
     * A factory answering with a correctly signed response for the nonce the
     * subject just generated, read back out of the request URL.
     */
    private function respondingFactory(string $status, int $validUntil, ?string $subscriptionId = null): RequestFactory
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willReturnCallback(
            function (string $url) use ($status, $validUntil, $subscriptionId): ResponseInterface {
                parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
                $id = $subscriptionId ?? (string)($query['id'] ?? '');
                $nonce = (string)($query['nonce'] ?? '');
                $issuedAt = time();

                $message = OnlineCheckProtocol::canonical($id, $status, $validUntil, $issuedAt, $nonce);

                return $this->response((string)json_encode([
                    'id' => $id,
                    'status' => $status,
                    'validUntil' => $validUntil,
                    'issuedAt' => $issuedAt,
                    'nonce' => $nonce,
                    'signature' => rtrim(strtr(base64_encode(
                        sodium_crypto_sign_detached($message, $this->secretKey)
                    ), '+/', '-_'), '='),
                ]));
            }
        );

        return $factory;
    }

    private function response(string $body, int $statusCode = 200): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }

    private function emptyCache(): FrontendInterface
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn(false);

        return $cache;
    }

    private function registerCache(FrontendInterface $cache): void
    {
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->willReturn($cache);

        GeneralUtility::setSingletonInstance(CacheManager::class, $cacheManager);
    }

    private function token(): SubscriptionToken
    {
        return new SubscriptionToken(
            'sub_abc',
            'Acme AG',
            'info@example.com',
            ['example.com'],
            self::NOW,
            self::NOW + (365 * 86400),
            [],
            'https://issuer.example.com',
        );
    }
}
