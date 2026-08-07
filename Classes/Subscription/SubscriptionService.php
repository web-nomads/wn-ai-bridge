<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Subscription;

use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Single point of truth for "is this installation licensed, and for what?".
 *
 * Reads the subscription key from the extension configuration, verifies it
 * against the current host and caches the outcome for the request. Everything is
 * fail-closed: any unexpected problem yields an invalid status rather than an
 * exception, so a broken key can never take a site down — it only switches the
 * subscription features off.
 */
final class SubscriptionService implements SingletonInterface
{
    /** The chat widget and its frontend endpoint. */
    public const FEATURE_CHATBOT = 'chatbot';
    /** The "Enquiries" backend module. */
    public const FEATURE_LOG = 'log';
    /** The "Answers" backend module and the local answer store. */
    public const FEATURE_CORRECTIONS = 'corrections';

    /**
     * How close to its end date a key has to be before the status check is also
     * performed from a frontend request. Chosen to cover the grace period the
     * issuing server adds to renewable keys, so a renewal is picked up before
     * the old key runs out even on a site with no scheduler and no backend use.
     */
    public const FRONTEND_REFRESH_WINDOW = 2592000; // 30 days

    private ?SubscriptionStatus $status = null;

    public function __construct(
        private readonly SubscriptionOnlineCheck $onlineCheck,
        private readonly TamperReporter $tamperReporter,
    ) {}

    public function getStatus(): SubscriptionStatus
    {
        if ($this->status === null) {
            $this->status = $this->resolve();
            $this->reportIfSuspicious($this->status);
        }

        return $this->status;
    }

    /**
     * Let the issuing server know when this installation looks manipulated.
     *
     * Only findings that cannot be an honest state are sent — a missing or
     * expired key is not one of them. Everything here is best-effort and silent:
     * a licence check must never fail over its own reporting, and no visitor
     * request may wait for it.
     */
    private function reportIfSuspicious(SubscriptionStatus $status): void
    {
        try {
            $reason = TamperDetection::fromStatus($status);

            if ($reason === null && !TamperDetection::isExtensionIntact()) {
                $reason = TamperDetection::REASON_ALTERED_EXTENSION;
            }
            if ($reason === null
                && TamperDetection::usesForeignVerificationKey($this->configValue('subscriptionPublicKey'))
            ) {
                $reason = TamperDetection::REASON_FOREIGN_VERIFICATION_KEY;
            }
            if ($reason === null) {
                return;
            }

            // A forged key may not decode at all, so the server address can fall
            // back to the configured one.
            $baseUrl = $status->token?->checkUrl ?: $this->configValue('subscriptionServerUrl');

            $this->tamperReporter->report(
                $reason,
                $status->token?->id ?? '',
                $status->host,
                $baseUrl,
            );
        } catch (\Throwable $e) {
            // Deliberately silent.
        }
    }

    public function isValid(): bool
    {
        return $this->getStatus()->valid;
    }

    public function hasFeature(string $feature): bool
    {
        return $this->getStatus()->hasFeature($feature);
    }

    /**
     * Drop the cached result — only needed after the extension configuration was
     * changed within the same request (e.g. in the install tool).
     */
    public function reset(): void
    {
        $this->status = null;
    }

    private function resolve(): SubscriptionStatus
    {
        $host = $this->currentHost();

        try {
            $token = SubscriptionKeyCodec::decode(
                $this->configValue('subscriptionKey'),
                $this->configValue('subscriptionPublicKey'),
            );
        } catch (SubscriptionKeyException $e) {
            return SubscriptionStatus::invalid($e->getReason(), null, $host);
        } catch (\Throwable $e) {
            return SubscriptionStatus::invalid(SubscriptionStatus::REASON_MALFORMED, null, $host);
        }

        // The domain is checked first: it is decided by the key alone, so a key
        // belonging to someone else never causes a request to the server.
        // Without a resolvable host (CLI: scheduler, upgrade wizards, commands)
        // the domain cannot be checked. Skipping it there keeps maintenance tasks
        // working; every web request is checked.
        if ($host !== '' && !$token->matchesHost($host)) {
            return SubscriptionStatus::invalid(SubscriptionStatus::REASON_DOMAIN, $token, $host);
        }

        // The daily status check with the issuing server. It runs before the
        // expiry decision, because a renewal moves the end date on the server
        // while the key in the configuration keeps the old one.
        $verdict = $this->onlineCheck->verdict(
            $token,
            $this->getVerificationKey(),
            $host,
            $token->isExpiringWithin(self::FRONTEND_REFRESH_WINDOW),
        );

        if ($verdict->isRevoked()) {
            return SubscriptionStatus::invalid(SubscriptionStatus::REASON_REVOKED, $token, $host);
        }

        // A verified answer is authoritative for the end date — that is how a
        // renewal reaches this installation without anyone pasting a new key.
        // Without one, the date inside the key stands: an unreachable server can
        // never extend a subscription, only the signed answer of a reachable one.
        $validUntil = self::effectiveValidUntil($token, $verdict);

        if (self::isExpired($token, $verdict)) {
            return SubscriptionStatus::invalid(SubscriptionStatus::REASON_EXPIRED, $token, $host, $validUntil);
        }

        return SubscriptionStatus::valid($token, $host, $validUntil);
    }

    /**
     * The end date this installation goes by: the issuing server's when it
     * answered and the answer verified, otherwise the one baked into the key.
     *
     * This is the whole mechanism behind a renewal arriving here — the key in the
     * configuration keeps its original date, the server's answer carries the new
     * one. It cuts both ways: a subscription that lapsed on the server is
     * reported with a date in the past and switches off, even if its key would
     * still be good for a while.
     *
     * Public and static so the rule can be exercised without a network.
     */
    public static function effectiveValidUntil(SubscriptionToken $token, OnlineCheckResult $verdict): int
    {
        return $verdict->isVerified() ? $verdict->validUntil : $token->expiresAt;
    }

    public static function isExpired(SubscriptionToken $token, OnlineCheckResult $verdict, ?int $now = null): bool
    {
        $validUntil = self::effectiveValidUntil($token, $verdict);

        return $validUntil > 0 && ($now ?? time()) > $validUntil;
    }

    /**
     * The decoded token regardless of validity — used by the CLI check command,
     * which needs the subscription id even when the key is currently rejected.
     */
    public function getToken(): ?SubscriptionToken
    {
        return $this->getStatus()->token;
    }

    public function getOnlineCheck(): SubscriptionOnlineCheck
    {
        return $this->onlineCheck;
    }

    public function getVerificationKey(): string
    {
        return $this->configValue('subscriptionPublicKey') ?: SubscriptionKeyCodec::DEFAULT_PUBLIC_KEY;
    }

    public function getCurrentHost(): string
    {
        return $this->currentHost();
    }

    private function currentHost(): string
    {
        try {
            return (string)GeneralUtility::getIndpEnv('HTTP_HOST');
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function configValue(string $key): string
    {
        $extensionConfiguration = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? [];
        return trim((string)($extensionConfiguration[$key] ?? ''));
    }
}
