<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Subscription;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebNomads\WnAiBridge\Subscription\SubscriptionKeyCodec;
use WebNomads\WnAiBridge\Subscription\SubscriptionKeyException;
use WebNomads\WnAiBridge\Subscription\SubscriptionStatus;

/**
 * Verifies the client side of the key format against keys built exactly the way
 * the issuing server builds them.
 */
final class SubscriptionKeyCodecTest extends TestCase
{
    private string $publicKeyHex;

    private string $secretKey;

    protected function setUp(): void
    {
        if (!SubscriptionKeyCodec::isSupported()) {
            self::markTestSkipped('The sodium extension is required to verify subscription keys.');
        }

        $keyPair = sodium_crypto_sign_seed_keypair(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES));
        $this->publicKeyHex = bin2hex(sodium_crypto_sign_publickey($keyPair));
        $this->secretKey = sodium_crypto_sign_secretkey($keyPair);
    }

    /**
     * The bundled keys are what every installation without an explicit override
     * verifies with, so a truncated or mistyped constant would silently reject
     * every key issued.
     */
    #[Test]
    public function theBundledKeysAreWellFormed(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', SubscriptionKeyCodec::DEFAULT_PUBLIC_KEY);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', SubscriptionKeyCodec::CIPHER_KEY);
    }

    #[Test]
    public function decodesAKeyItWasGivenTheMatchingPublicKeyFor(): void
    {
        $key = $this->buildKey([
            'v' => 1,
            'id' => 'sub_abc',
            'customer' => 'Acme AG',
            'email' => 'info@example.com',
            'domains' => ['example.com', '*.example.com'],
            'iat' => 1_000,
            'exp' => 2_000,
            'features' => ['chatbot', 'log'],
            'chk' => 'https://www.example.com',
        ]);

        $token = SubscriptionKeyCodec::decode($key, $this->publicKeyHex);

        self::assertSame('sub_abc', $token->id);
        self::assertSame('Acme AG', $token->customer);
        self::assertSame(['example.com', '*.example.com'], $token->domains);
        self::assertSame(2_000, $token->expiresAt);
        self::assertTrue($token->hasFeature('chatbot'));
        self::assertFalse($token->hasFeature('corrections'));
        // The address of the daily online check travels inside the signed
        // payload, so it cannot be pointed at another server.
        self::assertSame('https://www.example.com', $token->checkUrl);
    }

    #[Test]
    public function aKeyWithoutACheckUrlSimplySkipsTheOnlineCheck(): void
    {
        $key = $this->buildKey(['id' => 'sub_abc', 'domains' => ['example.com'], 'exp' => 2_000]);

        self::assertSame('', SubscriptionKeyCodec::decode($key, $this->publicKeyHex)->checkUrl);
    }

    #[Test]
    public function acceptsAKeyWithWhitespaceFromAnEmail(): void
    {
        $key = $this->buildKey(['id' => 'sub_abc', 'domains' => ['example.com'], 'exp' => 2_000]);

        $token = SubscriptionKeyCodec::decode(" \n" . wordwrap($key, 40, "\n", true) . " \n", $this->publicKeyHex);

        self::assertSame('sub_abc', $token->id);
    }

    #[Test]
    public function rejectsAKeySignedWithAnotherPrivateKey(): void
    {
        $key = $this->buildKey(['id' => 'sub_abc', 'domains' => ['example.com']]);
        $foreignPublicKey = bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));

        $this->expectExceptionReason($key, $foreignPublicKey, SubscriptionStatus::REASON_SIGNATURE);
    }

    #[Test]
    public function rejectsAKeyWhosePayloadWasTamperedWith(): void
    {
        $key = $this->buildKey(['id' => 'sub_abc', 'domains' => ['example.com'], 'exp' => 2_000]);

        // Flip a character in the encrypted part; the signature no longer matches.
        [$prefix, $envelope, $signature] = explode('.', $key);
        $envelope[5] = $envelope[5] === 'A' ? 'B' : 'A';

        $this->expectExceptionReason(
            implode('.', [$prefix, $envelope, $signature]),
            $this->publicKeyHex,
            SubscriptionStatus::REASON_SIGNATURE
        );
    }

    #[Test]
    public function rejectsAnEmptyKey(): void
    {
        $this->expectExceptionReason('   ', $this->publicKeyHex, SubscriptionStatus::REASON_MISSING);
    }

    #[Test]
    public function rejectsAKeyWithAForeignPrefix(): void
    {
        $key = $this->buildKey(['id' => 'sub_abc']);
        $key = 'WNAI9' . substr($key, 5);

        $this->expectExceptionReason($key, $this->publicKeyHex, SubscriptionStatus::REASON_MALFORMED);
    }

    #[Test]
    public function rejectsAKeyWithTooFewParts(): void
    {
        $this->expectExceptionReason('WNAI1.onlyonepart', $this->publicKeyHex, SubscriptionStatus::REASON_MALFORMED);
    }

    private function expectExceptionReason(string $key, string $publicKeyHex, string $reason): void
    {
        try {
            SubscriptionKeyCodec::decode($key, $publicKeyHex);
        } catch (SubscriptionKeyException $e) {
            self::assertSame($reason, $e->getReason());
            return;
        }

        self::fail(sprintf('Expected the key to be rejected with reason "%s".', $reason));
    }

    /**
     * Builds a key the same way the issuing server does.
     *
     * @param array<string, mixed> $payload
     */
    private function buildKey(array $payload): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipherKey = (string)hex2bin(SubscriptionKeyCodec::CIPHER_KEY);

        $envelope = $nonce . sodium_crypto_secretbox(
            (string)json_encode($payload, JSON_THROW_ON_ERROR),
            $nonce,
            $cipherKey
        );

        return SubscriptionKeyCodec::PREFIX
            . '.' . $this->base64UrlEncode($envelope)
            . '.' . $this->base64UrlEncode(sodium_crypto_sign_detached($envelope, $this->secretKey));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
