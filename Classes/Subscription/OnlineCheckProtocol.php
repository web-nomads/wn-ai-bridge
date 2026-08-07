<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Subscription;

/**
 * The wire format of the daily status check against the issuing server.
 *
 * Request:  GET <base>/wn-ai-bridge-server/validate?id=<sub-id>&host=<host>&nonce=<nonce>
 * Response: {"id":…,"status":"active|revoked|unknown","validUntil":…,"issuedAt":…,"nonce":…,"signature":…}
 *
 * The response is signed with the same Ed25519 key that signs the subscription
 * keys, over the canonical string below. The client-generated nonce is part of
 * that string, so an old "active" answer cannot be replayed after a revocation.
 *
 * Everything here is pure so both the format and its verification are unit
 * testable; the counterpart lives in the "wn_ai_bridgeserver" extension.
 */
final class OnlineCheckProtocol
{
    public const ENDPOINT_PATH = '/wn-ai-bridge-server/validate';

    /**
     * How far the server's clock may differ from ours before its answer is
     * discarded. Generous enough for unsynchronised clocks, short enough that a
     * captured answer cannot be replayed for long.
     */
    public const MAX_CLOCK_SKEW = 172800; // 2 days

    public static function createNonce(): string
    {
        return bin2hex(random_bytes(8));
    }

    public static function buildUrl(string $baseUrl, string $subscriptionId, string $host, string $nonce): string
    {
        return rtrim(trim($baseUrl), '/') . self::ENDPOINT_PATH . '?' . http_build_query([
            'id' => $subscriptionId,
            'host' => $host,
            'nonce' => $nonce,
        ]);
    }

    /**
     * The exact string that is signed. Field order is fixed on purpose — it is
     * part of the protocol, not an implementation detail.
     */
    public static function canonical(string $subscriptionId, string $status, int $validUntil, int $issuedAt, string $nonce): string
    {
        return implode('|', [$subscriptionId, $status, (string)$validUntil, (string)$issuedAt, $nonce]);
    }

    /**
     * Verify and interpret a server answer.
     *
     * Returns null when the answer is unusable for any reason (malformed, wrong
     * subscription, replayed nonce, stale timestamp, bad signature). Callers
     * treat null as "unknown" and change nothing.
     */
    public static function parseResponse(
        string $body,
        string $expectedSubscriptionId,
        string $expectedNonce,
        string $publicKeyHex,
        ?int $now = null,
    ): ?OnlineCheckResult {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return null;
        }

        try {
            $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }

        $id = (string)($data['id'] ?? '');
        $status = (string)($data['status'] ?? '');
        $validUntil = (int)($data['validUntil'] ?? 0);
        $issuedAt = (int)($data['issuedAt'] ?? 0);
        $nonce = (string)($data['nonce'] ?? '');
        $signature = self::base64UrlDecode((string)($data['signature'] ?? ''));

        if ($id !== $expectedSubscriptionId || !hash_equals($expectedNonce, $nonce)) {
            return null;
        }
        if (!in_array($status, [OnlineCheckResult::STATUS_ACTIVE, OnlineCheckResult::STATUS_REVOKED], true)) {
            return null;
        }
        if ($signature === null || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return null;
        }

        $now ??= time();
        if ($issuedAt <= 0 || abs($now - $issuedAt) > self::MAX_CLOCK_SKEW) {
            return null;
        }

        $publicKey = self::hexToBin($publicKeyHex);
        if ($publicKey === null || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return null;
        }

        $message = self::canonical($id, $status, $validUntil, $issuedAt, $nonce);
        if (!sodium_crypto_sign_verify_detached($signature, $message, $publicKey)) {
            return null;
        }

        return $status === OnlineCheckResult::STATUS_REVOKED
            ? OnlineCheckResult::revoked($now)
            : OnlineCheckResult::active($validUntil, $now);
    }

    private static function base64UrlDecode(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
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
