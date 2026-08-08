<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Subscription;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WebNomads\WnAiBridge\Subscription\SubscriptionKeyCodec;
use WebNomads\WnAiBridge\Subscription\SubscriptionOnlineCheck;
use WebNomads\WnAiBridge\Subscription\SubscriptionService;
use WebNomads\WnAiBridge\Subscription\TamperReporter;

/**
 * The order in which the backend asks for the subscription status.
 *
 * The module guard runs on BeforeModuleCreationEvent, which fires while the
 * module list is built — inside a middleware, before
 * Backend\Http\RequestHandler puts the request into $GLOBALS. The status check
 * cannot run there. Since the service caches its answer for the request, that
 * empty verdict used to be what the modules displayed: no server state at all,
 * however wrong the configured address was.
 */
final class StatusResolutionOrderTest extends TestCase
{
    /** Requests aimed at the issuing server's validate endpoint. */
    private int $checkAttempts = 0;

    private string $publicKeyHex = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->checkAttempts = 0;

        Environment::initialize(
            new ApplicationContext('Testing'),
            // Not CLI — otherwise the request would not be consulted at all.
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
        unset($GLOBALS['TYPO3_REQUEST'], $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge']);
        GeneralUtility::purgeInstances();

        parent::tearDown();
    }

    #[Test]
    public function theGuardCannotCheckYetButTheControllerCan(): void
    {
        $service = $this->service();

        // 1. The module guard, from a middleware: no request, so no check.
        $fromGuard = $service->getStatus();
        self::assertTrue($fromGuard->valid, 'the key itself is fine');
        self::assertFalse($fromGuard->hasOnlineCheckFailed());
        self::assertSame(0, $this->checkAttempts, 'nothing may be asked without a request');

        // 2. The module controller, once the request handler has run.
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://example.com/typo3/module/x'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        $fromController = $service->getStatus();

        self::assertSame(1, $this->checkAttempts, 'the status has to be resolved again, not served frozen');
        self::assertTrue($fromController->hasOnlineCheckFailed(), 'the failure is what the module shows');
        self::assertSame('https://unreachable.invalid', $fromController->onlineCheck?->serverUrl);
    }

    #[Test]
    public function theSecondLookDoesNotTurnIntoARequestPerCall(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://example.com/typo3/module/x'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        $service = $this->service();
        for ($i = 0; $i < 5; $i++) {
            $service->getStatus();
        }

        self::assertSame(1, $this->checkAttempts);
    }

    private function service(): SubscriptionService
    {
        $this->registerEmptyCache();

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] = [
            'subscriptionKey' => $this->buildKey(),
            'subscriptionPublicKey' => $this->publicKeyHex,
            'subscriptionServerUrl' => 'https://unreachable.invalid',
        ];

        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willReturnCallback(
            function (string $url): ResponseInterface {
                // Tamper reports go elsewhere and must not be counted here.
                if (str_contains($url, '/validate')) {
                    $this->checkAttempts++;
                }
                throw new \RuntimeException('unreachable');
            }
        );

        return new SubscriptionService(
            new SubscriptionOnlineCheck($factory),
            new TamperReporter($factory),
        );
    }

    /**
     * A key this test can sign itself: the cipher key is a shipped constant, and
     * the matching public key goes into subscriptionPublicKey.
     */
    private function buildKey(): string
    {
        $pair = sodium_crypto_sign_keypair();
        $this->publicKeyHex = bin2hex(sodium_crypto_sign_publickey($pair));

        $payload = (string)json_encode([
            'id' => 'sub_test',
            'customer' => 'Acme AG',
            'email' => 'info@example.com',
            'domains' => ['example.com'],
            'iat' => time() - 86400,
            'exp' => time() + (365 * 86400),
            'features' => [],
            'chk' => '',
        ]);

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipherText = sodium_crypto_secretbox($payload, $nonce, (string)hex2bin(SubscriptionKeyCodec::CIPHER_KEY));
        $envelope = $nonce . $cipherText;
        $signature = sodium_crypto_sign_detached($envelope, sodium_crypto_sign_secretkey($pair));

        return SubscriptionKeyCodec::PREFIX . '.' . $this->base64Url($envelope) . '.' . $this->base64Url($signature);
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

        GeneralUtility::setSingletonInstance(CacheManager::class, $cacheManager);
    }
}
