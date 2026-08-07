<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Subscription;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebNomads\WnAiBridge\Subscription\OnlineCheckProtocol;
use WebNomads\WnAiBridge\Subscription\OnlineCheckResult;

/**
 * The daily status check decides whether a revoked subscription actually stops
 * working, so its verification is covered from both ends: a genuine answer must
 * be accepted, and every way of faking one must be refused.
 */
final class OnlineCheckProtocolTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private string $publicKeyHex;

    private string $secretKey;

    protected function setUp(): void
    {
        if (!function_exists('sodium_crypto_sign_seed_keypair')) {
            self::markTestSkipped('The sodium extension is required for the online check.');
        }

        $keyPair = sodium_crypto_sign_seed_keypair(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES));
        $this->publicKeyHex = bin2hex(sodium_crypto_sign_publickey($keyPair));
        $this->secretKey = sodium_crypto_sign_secretkey($keyPair);
    }

    #[Test]
    public function theUrlCarriesIdHostAndNonce(): void
    {
        $url = OnlineCheckProtocol::buildUrl('https://example.com/', 'sub_abc', 'kunde.ch', 'n1');

        self::assertStringStartsWith('https://example.com/wn-ai-bridge-server/validate?', $url);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame(['id' => 'sub_abc', 'host' => 'kunde.ch', 'nonce' => 'n1'], $query);
    }

    #[Test]
    public function everyNonceIsDifferent(): void
    {
        self::assertNotSame(OnlineCheckProtocol::createNonce(), OnlineCheckProtocol::createNonce());
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', OnlineCheckProtocol::createNonce());
    }

    #[Test]
    public function aValidActiveAnswerIsAccepted(): void
    {
        $result = OnlineCheckProtocol::parseResponse(
            $this->body('active', self::NOW + 86400),
            'sub_abc',
            'n1',
            $this->publicKeyHex,
            self::NOW,
        );

        self::assertNotNull($result);
        self::assertSame(OnlineCheckResult::STATUS_ACTIVE, $result->status);
        self::assertSame(self::NOW + 86400, $result->validUntil);
    }

    #[Test]
    public function aValidRevokedAnswerIsAccepted(): void
    {
        $result = OnlineCheckProtocol::parseResponse(
            $this->body('revoked', 0),
            'sub_abc',
            'n1',
            $this->publicKeyHex,
            self::NOW,
        );

        self::assertNotNull($result);
        self::assertTrue($result->isRevoked());
    }

    #[Test]
    public function anUnsignedAnswerIsRejected(): void
    {
        $body = (string)json_encode([
            'id' => 'sub_abc',
            'status' => 'active',
            'validUntil' => self::NOW + 86400,
            'issuedAt' => self::NOW,
            'nonce' => 'n1',
        ]);

        self::assertNull($this->parse($body));
    }

    #[Test]
    public function anAnswerFromAnotherIssuerIsRejected(): void
    {
        $foreign = sodium_crypto_sign_keypair();

        self::assertNull($this->parse(
            $this->body('active', self::NOW + 86400, 'n1', sodium_crypto_sign_secretkey($foreign))
        ));
    }

    #[Test]
    public function aMismatchedNonceIsRejected(): void
    {
        self::assertNull($this->parse($this->body('active', self::NOW + 86400, 'other-nonce')));
    }

    #[Test]
    public function anAnswerAboutAnotherSubscriptionIsRejected(): void
    {
        $body = (string)json_encode($this->payload('sub_other', 'active', 0, 'n1', self::NOW));

        self::assertNull($this->parse($body));
    }

    #[Test]
    public function anAnswerFromTooFarInThePastIsRejected(): void
    {
        $body = (string)json_encode($this->payload(
            'sub_abc',
            'active',
            self::NOW + 86400,
            'n1',
            self::NOW - OnlineCheckProtocol::MAX_CLOCK_SKEW - 1,
        ));

        self::assertNull($this->parse($body));
    }

    #[Test]
    public function aModestClockDifferenceIsTolerated(): void
    {
        $body = (string)json_encode($this->payload(
            'sub_abc',
            'active',
            self::NOW + 86400,
            'n1',
            self::NOW + 600,
        ));

        self::assertNotNull($this->parse($body));
    }

    #[Test]
    public function anUnknownStatusIsRejected(): void
    {
        self::assertNull($this->parse($this->body('unknown', 0)));
        self::assertNull($this->parse($this->body('', 0)));
    }

    #[Test]
    public function unusableBodiesAreRejected(): void
    {
        self::assertNull($this->parse(''));
        self::assertNull($this->parse('null'));
        self::assertNull($this->parse('<html>502</html>'));
        self::assertNull($this->parse('{"id":"sub_abc"}'));
    }

    #[Test]
    public function anUnusablePublicKeyRejectsEverything(): void
    {
        $body = $this->body('active', self::NOW + 86400);

        self::assertNull(OnlineCheckProtocol::parseResponse($body, 'sub_abc', 'n1', '', self::NOW));
        self::assertNull(OnlineCheckProtocol::parseResponse($body, 'sub_abc', 'n1', 'zz', self::NOW));
    }

    #[Test]
    public function theResultSurvivesTheCacheRoundTrip(): void
    {
        $result = OnlineCheckResult::active(self::NOW + 86400, self::NOW);
        $restored = OnlineCheckResult::fromArray($result->toArray());

        self::assertSame($result->status, $restored->status);
        self::assertSame($result->validUntil, $restored->validUntil);
        self::assertSame($result->checkedAt, $restored->checkedAt);
    }

    #[Test]
    public function anUnrecognisedCachedStatusBecomesUnknown(): void
    {
        $restored = OnlineCheckResult::fromArray(['status' => 'whatever']);

        self::assertSame(OnlineCheckResult::STATUS_UNKNOWN, $restored->status);
        self::assertFalse($restored->isRevoked());
    }

    private function parse(string $body): ?OnlineCheckResult
    {
        return OnlineCheckProtocol::parseResponse($body, 'sub_abc', 'n1', $this->publicKeyHex, self::NOW);
    }

    private function body(string $status, int $validUntil, string $nonce = 'n1', ?string $secretKey = null): string
    {
        return (string)json_encode(
            $this->payload('sub_abc', $status, $validUntil, $nonce, self::NOW, $secretKey)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        string $id,
        string $status,
        int $validUntil,
        string $nonce,
        int $issuedAt,
        ?string $secretKey = null,
    ): array {
        $signature = sodium_crypto_sign_detached(
            OnlineCheckProtocol::canonical($id, $status, $validUntil, $issuedAt, $nonce),
            $secretKey ?? $this->secretKey,
        );

        return [
            'id' => $id,
            'status' => $status,
            'validUntil' => $validUntil,
            'issuedAt' => $issuedAt,
            'nonce' => $nonce,
            'signature' => rtrim(strtr(base64_encode($signature), '+/', '-_'), '='),
        ];
    }
}
