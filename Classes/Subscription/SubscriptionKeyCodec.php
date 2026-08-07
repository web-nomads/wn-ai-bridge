<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Subscription;

/**
 * Decodes and verifies a subscription key issued by the "AI Bridge Server"
 * extension.
 *
 * Key format (one line, no whitespace):
 *
 *     WNAI1.<base64url(nonce ‖ ciphertext)>.<base64url(signature)>
 *
 * - The payload is a JSON object (customer, domains, expiry date, features) that
 *   is encrypted with XSalsa20-Poly1305 (sodium secretbox) using a shared cipher
 *   key, so the key is opaque and cannot be read or edited by hand.
 * - The encrypted part is signed with the issuer's Ed25519 private key. Only the
 *   matching public key is shipped here, so a key can be verified but not forged
 *   — the encryption alone would not prevent that, the signature does.
 *
 * This class is deliberately free of TYPO3 dependencies so it can be unit tested
 * in isolation.
 */
final class SubscriptionKeyCodec
{
    public const PREFIX = 'WNAI1';

    /**
     * Ed25519 public key (hex) of the WebNomads issuing server. Verification only
     * — the matching private key never leaves the server installation. Can be
     * overridden per installation via the "subscriptionPublicKey" extension
     * setting when a new key pair is rolled out.
     */
    public const DEFAULT_PUBLIC_KEY = '83ba4006067ffcfb548bd6457afcde6d72a9004998ac91244cc965fa6149f0a0';

    /**
     * Shared secret (hex) used to encrypt the payload. This is obfuscation, not a
     * security boundary: tamper resistance comes from the Ed25519 signature
     * above, which is why shipping this constant is safe.
     */
    public const CIPHER_KEY = '2712b00a9f5ea4c49fc982f4ddf6582c43b9dd5c1ba96921bb47a63620312322';

    /**
     * Decrypt and verify a key.
     *
     * @param string $publicKeyHex Overrides {@see DEFAULT_PUBLIC_KEY} when not empty.
     * @throws SubscriptionKeyException with one of the SubscriptionStatus::REASON_* codes
     */
    public static function decode(string $key, string $publicKeyHex = ''): SubscriptionToken
    {
        $key = self::normalise($key);
        if ($key === '') {
            throw new SubscriptionKeyException(SubscriptionStatus::REASON_MISSING, 1770000001);
        }
        if (!self::isSupported()) {
            throw new SubscriptionKeyException(SubscriptionStatus::REASON_UNSUPPORTED, 1770000002);
        }

        $parts = explode('.', $key);
        if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
            throw new SubscriptionKeyException(SubscriptionStatus::REASON_MALFORMED, 1770000003);
        }

        $envelope = self::base64UrlDecode($parts[1]);
        $signature = self::base64UrlDecode($parts[2]);
        if ($envelope === null || $signature === null
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || strlen($envelope) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
        ) {
            throw new SubscriptionKeyException(SubscriptionStatus::REASON_MALFORMED, 1770000004);
        }

        $publicKey = self::hexToBin($publicKeyHex !== '' ? $publicKeyHex : self::DEFAULT_PUBLIC_KEY);
        if ($publicKey === null || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new SubscriptionKeyException(SubscriptionStatus::REASON_UNSUPPORTED, 1770000005);
        }

        if (!sodium_crypto_sign_verify_detached($signature, $envelope, $publicKey)) {
            throw new SubscriptionKeyException(SubscriptionStatus::REASON_SIGNATURE, 1770000006);
        }

        $cipherKey = self::hexToBin(self::CIPHER_KEY);
        if ($cipherKey === null || strlen($cipherKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new SubscriptionKeyException(SubscriptionStatus::REASON_UNSUPPORTED, 1770000007);
        }

        $nonce = substr($envelope, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipherText = substr($envelope, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $json = sodium_crypto_secretbox_open($cipherText, $nonce, $cipherKey);
        if ($json === false) {
            throw new SubscriptionKeyException(SubscriptionStatus::REASON_PAYLOAD, 1770000008);
        }

        try {
            $payload = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SubscriptionKeyException(SubscriptionStatus::REASON_PAYLOAD, 1770000009);
        }
        if (!is_array($payload)) {
            throw new SubscriptionKeyException(SubscriptionStatus::REASON_PAYLOAD, 1770000010);
        }

        return SubscriptionToken::fromPayload($payload);
    }

    /**
     * Whether the PHP installation provides the sodium functions this needs.
     */
    public static function isSupported(): bool
    {
        return function_exists('sodium_crypto_sign_verify_detached')
            && function_exists('sodium_crypto_secretbox_open');
    }

    /**
     * Strip whitespace and line breaks so a key pasted from an e-mail works.
     */
    public static function normalise(string $key): string
    {
        return (string)preg_replace('/\s+/', '', $key);
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }

    private static function hexToBin(string $hex): ?string
    {
        $hex = trim($hex);
        if ($hex === '' || preg_match('/^[0-9a-fA-F]+$/', $hex) !== 1 || strlen($hex) % 2 !== 0) {
            return null;
        }
        $bin = hex2bin($hex);
        return $bin === false ? null : $bin;
    }
}
